# 🚀 Roadmap & Pistes d'ameliorations

> Ce document liste les pistes d'evolution du projet, classees par effort, impact et priorite. Il sert de **roadmap publique** pour les contributeurs et de **boussole strategique** pour les utilisateurs avances.

---

![Status](https://img.shields.io/badge/status-active-brightgreen)
![Contributions](https://img.shields.io/badge/contributions-welcome-blue)
![License](https://img.shields.io/badge/license-MIT-green)

---

## 📑 Table des matieres

- [🎯 Vision long terme](#-vision-long-terme)
- [⚡ Quick wins (< 1h chacun)](#-quick-wins--1h-chacun)
- [🛠️ Ameliorations techniques](#️-ameliorations-techniques)
- [📦 Distribution & ecosysteme](#-distribution--ecosysteme)
- [🔬 R&D / pistes avancees](#-rd--pistes-avancees)
- [📊 Matrice de priorisation](#-matrice-de-priorisation)
- [🤔 Questions ouvertes](#-questions-ouvertes)
- [🤝 Comment contribuer](#-comment-contribuer)

---

## 🎯 Vision long terme

L'objectif est de transformer ce projet d'un **simple snippet PHP** en une **boite a outils complete** pour la gestion de pages cloakees a destination des bots SEO.

Trois axes :

| Axe | Description |
|---|---|
| 🟢 **Robustesse** | Resister aux tentatives de detection (concurrents, Google, outils anti-cloaking) |
| 🟡 **Scale** | Gerer des dizaines/centaines de pages cloakees sans friction |
| 🔵 **Adoption** | Rendre l'outil utilisable par des non-developpeurs (plugins CMS, SaaS) |

> [!NOTE]
> Toute amelioration doit garder l'esprit du projet : **simple, sans dependance, deployable en 5 minutes**.

---

## ⚡ Quick wins (< 1h chacun)

Ameliorations rapides a fort ROI. Ideales pour une premiere PR.

### 1. 🎨 Headers HTTP plus credibles dans le faux 1020

**Probleme** : actuellement la reponse 403 manque de headers Cloudflare natifs (`cf-ray`, `cf-cache-status`, `server: cloudflare`).

**Solution** : ajouter dans `serve_403_cloudflare()` :

```php
header('Server: cloudflare');
header('CF-RAY: ' . $ray);
header('CF-Cache-Status: DYNAMIC');
header('NEL: {"report_to":"cf-nel","success_fraction":0.0,"max_age":604800}');
```

**Impact** : 🟢 Gros (page indistinguable d'un vrai blocage Cloudflare au curl -i).

### 2. 🔐 Generer un PREVIEW_TOKEN solide automatiquement

**Probleme** : les utilisateurs laissent souvent le token par defaut.

**Solution** : ajouter un mode CLI :

```bash
php cloak.php --generate-token
# Output: nouveau token a coller dans la config
```

**Impact** : 🟡 Moyen (UX, evite les fuites de pages).

### 3. 📋 Logger les decisions dans un fichier

**Probleme** : pas de visibilite sur qui essaie d'acceder.

**Solution** : ajouter un log JSON line par requete :

```php
file_put_contents(
    __DIR__ . '/cloak.log',
    json_encode(['ts' => time(), 'ip' => $ip, 'ua' => $ua, 'decision' => $decision]) . "\n",
    FILE_APPEND
);
```

**Impact** : 🟢 Gros (analyser qui scanne, affiner la whitelist UA).

### 4. ✅ robots.txt et sitemap.xml integres

**Probleme** : sans `robots.txt` qui autorise explicitement Ahrefs/Semrush, certains crawlers ralentissent.

**Solution** : si `?robots` dans l'URL ou `/robots.txt`, retourner :

```
User-agent: AhrefsBot
Allow: /

User-agent: SemrushBot
Allow: /

User-agent: MJ12bot
Allow: /

Sitemap: https://votre-site.com/sitemap.xml
```

**Impact** : 🟢 Gros (vitesse d'indexation).

---

## 🛠️ Ameliorations techniques

### 5. ⚡ Cache rDNS sur disque

**Probleme** : `gethostbyaddr()` peut prendre 500ms a 1500ms par lookup. Pour un bot qui visite 100 pages, ca devient enorme.

**Solution** : cache fichier ou APCu avec TTL :

```php
function cached_rdns($ip, $ttl = 3600) {
    $cache_file = sys_get_temp_dir() . '/rdns_' . md5($ip) . '.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        return json_decode(file_get_contents($cache_file), true);
    }
    $host = @gethostbyaddr($ip);
    file_put_contents($cache_file, json_encode($host));
    return $host;
}
```

**Effort** : 🕐 1h
**Impact** : 🟢 Tres gros (10x plus rapide pour bots recurrents)

### 6. 🌐 IP CIDR ranges officiels (backup rDNS)

**Probleme** : si le DNS du serveur est down ou bride, le rDNS echoue et on bloque les vrais bots.

**Solution** : verification par range IP officiels publies par chaque service.

```php
$ALLOWED_CIDR = [
    // Ahrefs (source: https://ahrefs.com/robot)
    '54.36.148.0/24',
    '54.36.149.0/24',
    '195.154.122.0/24',
    '195.154.123.0/24',
    // Semrush (source: https://www.semrush.com/bot/)
    '85.208.96.0/22',
    '185.191.171.0/24',
];

function ip_in_cidr($ip, $cidr) {
    [$subnet, $bits] = explode('/', $cidr);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask = -1 << (32 - (int)$bits);
    return ($ip_long & $mask) === ($subnet_long & $mask);
}
```

**Effort** : 🕐 2h
**Impact** : 🟢 Gros (resilience + rapidite)

### 7. 📁 Multi-page support (un fichier = tout un PBN)

**Probleme** : pour cloaker 50 URLs, il faut 50 copies du fichier.

**Solution** : router qui sert un HTML different selon l'URL.

```php
$pages = [
    '/produit-1' => __DIR__ . '/pages/produit-1.html',
    '/produit-2' => __DIR__ . '/pages/produit-2.html',
    '/categorie-massage' => __DIR__ . '/pages/categorie-massage.html',
];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$html_file = $pages[$path] ?? null;

if (!$html_file || !file_exists($html_file)) {
    serve_403_cloudflare($host, $ip);
}

// Sert le bon HTML
echo file_get_contents($html_file);
```

**Effort** : 🕐 3h
**Impact** : 🟢 Tres gros (game-changer pour PBN)

### 8. 🌍 rDNS dans le Cloudflare Worker (via DoH)

**Probleme** : actuellement le Worker fait UA-only (pas de rDNS), donc moins robuste contre le spoof.

**Solution** : utiliser DNS-over-HTTPS de Cloudflare :

```javascript
async function rdnsLookup(ip) {
  const reverse = ip.split('.').reverse().join('.') + '.in-addr.arpa';
  const res = await fetch(`https://1.1.1.1/dns-query?name=${reverse}&type=PTR`, {
    headers: { 'accept': 'application/dns-json' }
  });
  const data = await res.json();
  return data.Answer?.[0]?.data || null;
}
```

**Effort** : 🕐 4h
**Impact** : 🟡 Moyen (parite PHP/Worker)

### 9. 🚦 Rate limiting basique

**Probleme** : un attaquant determine peut faire 1000 requetes pour mapper la logique.

**Solution** : compteur en memoire (APCu, Redis ou fichier) :

```php
$key = 'rl_' . md5($ip);
$count = apcu_fetch($key) ?: 0;
if ($count > 100) {
    serve_403_cloudflare($host, $ip);  // bloque pour 1h
}
apcu_store($key, $count + 1, 3600);
```

**Effort** : 🕐 1h
**Impact** : 🟡 Moyen

### 10. 🔍 TLS fingerprint check (Worker)

**Probleme** : `curl`, `python requests`, `nodejs fetch` ont des fingerprints TLS (JA3) tres differents d'un vrai navigateur.

**Solution** : Cloudflare expose le JA3 via `cf-ja3-hash`. On peut verifier que les visiteurs lambdas ont un fingerprint de browser, pas d'outil ligne de commande.

**Effort** : 🕐 6h
**Impact** : 🟠 Niche (deja couvert par rDNS pour les bots, utile contre humains tech-savvy)

---

## 📦 Distribution & ecosysteme

### 11. 🔌 Plugin WordPress

**Idee** : packager `cloak.php` en plugin WordPress avec interface admin.

**Fonctionnalites** :
- Liste des pages cloakees gerable depuis wp-admin
- Editeur HTML/Gutenberg pour le contenu servi aux bots
- Statistiques (combien de fois servi en 200 vs 403)
- Configuration des bots autorises via UI

**Cible** : 40% du web. Potentiel viral.

**Effort** : 🕐 1-2 jours
**Impact** : 🟢 Tres gros

### 12. 🛒 Module PrestaShop / Magento / Shopify

**Idee** : meme concept mais integre dans les CMS e-commerce, avec gestion des produits cloakes.

**Effort** : 🕐 2 jours par CMS
**Impact** : 🟢 Gros (e-commerce SEO niche)

### 13. 📚 Templates HTML par secteur

**Idee** : fournir des templates HTML pre-faits, optimises SEO :

| Secteur | Schema.org | Specifs |
|---|---|---|
| 🛍️ E-commerce | Product + Offer | meta produit, prix, stock |
| 🏪 Local business | LocalBusiness | adresse, horaires, telephone |
| 📰 Blog | Article + Author | breadcrumb, date pub |
| 📋 Lead gen | Service | CTA, FAQ, temoignages |
| 🏨 Hotel/Resto | LodgingBusiness / Restaurant | etoiles, photos, menu |

**Effort** : 🕐 1 jour pour 5 templates
**Impact** : 🟢 Gros (UX install)

### 14. 🖥️ CLI `seo-cloak`

**Idee** : un binaire qui scaffold un projet en 30 secondes :

```bash
npx seo-cloak init
# ? Type de page : (e-commerce / blog / local biz / autre)
# ? Domaine cible : exemple.com
# ? Mot-cle principal : massage paris
# ? Token preview (auto-genere) : abc123...
# > Genere : index.php + page.html + .htaccess
```

**Effort** : 🕐 4h
**Impact** : 🟡 Moyen

### 15. ☁️ SaaS hosted

**Idee** : service ou tu uploades ton HTML, on s'occupe du cloaking.

**Modele** : freemium (3 pages gratuites, illimite a 5€/mois).

**Effort** : 🕐 1+ semaines (Cloudflare Worker + dashboard)
**Impact** : 🟢 Tres gros mais zone grise legale

> [!WARNING]
> Une offre SaaS de cloaking peut attirer l'attention juridique. A reserver pour quelqu'un avec un cadre clair (B2B securite uniquement, ou pivot legal).

---

## 🔬 R&D / pistes avancees

### 16. 🍯 Honeypot inverse

Servir un HTML special aux IPs detectees comme spoofers (UA bot mais rDNS invalide). Ce HTML contient un pixel tracker pour identifier les outils utilises (par scraping headers, fingerprint).

**Utilite** : etablir une **liste noire dynamique** des concurrents et outils anti-cloaking.

### 17. 🤖 Detection comportementale ML

Apres N visites, classifier les visiteurs en `bot_seo`, `bot_concurrent`, `humain`, `outil_anti_cloak` via patterns de navigation.

**Stack** : Python sidecar + endpoint REST consomme par le PHP/Worker.

### 18. 🔄 Rotation du faux 1020

Servir 3-4 versions differentes du faux 1020 (Error 1020, 1006, 1009, 1010) avec rotation aleatoire pour eviter les patterns detectables.

### 19. 🌐 Geo-fencing

Servir 200 aux bots ET aux visiteurs d'un pays specifique, 403 aux autres. Utile pour PBN locaux (un site FR qui ne veut etre vu que par les visiteurs FR + bots SEO).

```php
$cf_country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
if ($cf_country === 'FR') {
    serve_seo_page();
}
```

### 20. 🧪 Tests automatises (CI/CD)

GitHub Actions qui :
- Lance un serveur PHP local
- Curl avec differents UA
- Verifie HTTP code + presence de strings
- Echoue si une regression

---

## 📊 Matrice de priorisation

> Classement subjectif. A discuter via Issues GitHub.

| # | Amelioration | Impact | Effort | Score | Priorite |
|---|---|---|---|---|---|
| 1 | Headers HTTP credibles | 🟢🟢🟢 | 30min | 🔥🔥🔥 | **P0 — fait des ce soir** |
| 5 | Cache rDNS | 🟢🟢🟢 | 1h | 🔥🔥🔥 | **P0** |
| 7 | Multi-page | 🟢🟢🟢 | 3h | 🔥🔥 | **P1** |
| 6 | IP CIDR backup | 🟢🟢 | 2h | 🔥🔥 | **P1** |
| 4 | robots.txt integre | 🟢🟢 | 30min | 🔥🔥🔥 | **P0** |
| 11 | Plugin WordPress | 🟢🟢🟢 | 2j | 🔥🔥 | **P1** |
| 13 | Templates par secteur | 🟢🟢 | 1j | 🔥🔥 | **P2** |
| 8 | rDNS via DoH (Worker) | 🟡 | 4h | 🔥 | **P2** |
| 3 | Logging | 🟢🟢 | 1h | 🔥🔥 | **P1** |
| 9 | Rate limiting | 🟡 | 1h | 🔥 | **P3** |
| 16 | Honeypot inverse | 🟡 | 4h | 🔥 | **P3** |
| 17 | ML detection | 🟠 | 1 sem | 🔥 | **P4** |
| 15 | SaaS | 🟢🟢🟢 | 1+ sem | 🔥🔥 | **P? (legal)** |

**Legende** : 🟢 fort &nbsp;|&nbsp; 🟡 moyen &nbsp;|&nbsp; 🟠 niche &nbsp;|&nbsp; 🔴 faible

---

## 🤔 Questions ouvertes

> [!IMPORTANT]
> Ces questions doivent etre tranchees avant de pousser certaines features.

### 🎯 Positionnement

- Outil **interne** (snippet pour devs SEO) ou **produit grand public** (plugin / SaaS) ?
- Si plugin WordPress : version gratuite + premium ?

### ⚖️ Ethique & legal

- Doit-on inclure Googlebot / Bingbot dans la whitelist par defaut ?
- Faut-il un mode "ethique" qui ne sert que des metas (pas de contenu different) ?
- Faut-il afficher un **disclaimer obligatoire** dans le faux 1020 (mention "test" en commentaire HTML) ?

### 🔐 Securite

- Faut-il chiffrer le PREVIEW_TOKEN cote PHP (eviter qu'il fuite dans les logs serveur) ?
- Faut-il signer le token avec HMAC + timestamp pour eviter le replay ?

### 🚀 Performance

- Quel est le **TTFB cible** ? (actuellement 50-1500ms selon rDNS)
- Faut-il un mode **edge cache** pour servir le 200 depuis Cloudflare Cache une fois le bot identifie ?

---

## 🤝 Comment contribuer

1. 🍴 **Fork** le repo
2. 🌿 **Cree une branche** : `git checkout -b feature/cache-rdns`
3. 💻 **Code** ta feature en respectant le style existant (single file, zero deps si possible)
4. ✅ **Teste** localement (voir la section Tester du README principal)
5. 📝 **Documente** : ajoute un paragraphe dans le README + un test cURL
6. 🚀 **Push** + **ouvre une Pull Request** avec description claire

### Style guidelines

- 🟢 PHP 7.4+ minimum (compatibilite mutualises)
- 🟢 Pas de framework, pas de Composer
- 🟢 Worker : zero npm dep en runtime (juste wrangler en dev)
- 🟢 Commentaires en francais ou anglais (les deux acceptes)
- 🟢 Pas d'emoji dans le code source (uniquement dans la doc)

### Issues bienvenues

- 🐛 Bug reports avec reproduction minimale
- 💡 Idees de features (avant de coder, ouvrir une issue pour valider)
- 📚 Ameliorations de doc / traductions
- 🧪 Cas de test reels (UA exotiques, edge cases)

---

## 📅 Historique des versions

| Version | Date | Highlights |
|---|---|---|
| `v1.0.0` | 2026-05 | Release initiale : PHP + Worker + rDNS |
| `v1.1.0` | _planned_ | Quick wins (#1, #4, #5) |
| `v1.2.0` | _planned_ | Multi-page (#7) + CIDR (#6) |
| `v2.0.0` | _planned_ | Plugin WordPress (#11) |

---

> 💬 **Une question, une idee, un bug ?** [Ouvre une issue](https://github.com/lkmeldv/seo-cloak/issues) ou rejoins la discussion.

🌟 **Si ce projet t'aide, met une etoile sur le repo !**
