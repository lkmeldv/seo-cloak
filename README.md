# SEO Cloaking | PHP + Cloudflare Worker

Un outil simple pour servir une page web differemment selon qui la visite :

- **Visiteurs et concurrents** : recoivent une page d'erreur **403** imitant Cloudflare Error 1020 (Access denied).
- **Bots SEO autorises** (Majestic `MJ12bot`, Ahrefs `AhrefsBot`, Semrush `SemrushBot`) : recoivent la page complete en **200 OK** avec tous les meta tags, schema.org et liens.

Deux implementations independantes au choix :

| Variante | Fichier | Usage |
|---|---|---|
| **PHP** (un seul fichier) | `cloak.php` | A poser sur n'importe quel hebergeur PHP (Apache, Nginx + PHP-FPM, OVH, CloudPanel...) |
| **Cloudflare Worker** | `src/worker.js` + `wrangler.toml` | Deploiement edge sans serveur, scale automatique |

---

## Avertissement

Ce projet est fourni a des fins educatives et pour des cas d'usage SEO en zone grise (PBN, monitoring, analyse de concurrents). Il imite une page d'erreur Cloudflare. **Tu es responsable de l'usage que tu en fais.** Verifie la legalite et la conformite avec les CGU des plateformes que tu cibles avant deploiement. Servir un contenu different aux moteurs de recherche peut etre considere comme du **cloaking** par Google et entrainer des penalites.

---

## Table des matieres

- [Comment ca marche](#comment-ca-marche)
- [Variante 1 : PHP](#variante-1--php-cloakphp)
- [Variante 2 : Cloudflare Worker](#variante-2--cloudflare-worker)
- [Anti-spoof rDNS](#anti-spoof-rdns)
- [Personnaliser la page servie](#personnaliser-la-page-servie)
- [Tester](#tester)
- [Bots supportes](#bots-supportes)
- [FAQ](#faq)

---

## Comment ca marche

Pour chaque requete entrante, le code execute cette logique :

```
1. Si l'URL contient ?preview=TON_TOKEN
   -> servir la page (mode preview admin)

2. Sinon, si le User-Agent matche un bot SEO autorise
   2a. Si rDNS active : verifier que l'IP appartient bien au crawler
       - rDNS valide -> servir la page (HTTP 200)
       - rDNS invalide -> servir 403 (anti-spoof)
   2b. Si rDNS desactive : servir la page (HTTP 200)

3. Sinon (visiteur normal, autre bot, concurrent)
   -> servir la fausse page Cloudflare 1020 (HTTP 403)
```

La cle de la securite, c'est le **rDNS forward-confirmed** : meme si un concurrent connait les User-Agents des bots SEO, il ne peut pas falsifier son IP pour avoir un rDNS qui pointe vers `*.ahrefs.com` puis qui re-resout vers la meme IP.

---

## Variante 1 : PHP (`cloak.php`)

C'est la solution la plus simple : un seul fichier, autonome, sans dependance.

### Etape 1 : Edite la config en haut du fichier

```php
$PREVIEW_TOKEN = 'change_this_to_a_long_random_string';
$VERIFY_RDNS = true;  // recommande
```

Genere un token solide (par exemple avec `openssl rand -hex 32`).

### Etape 2 : Edite la fonction `get_seo_html()`

Remplace le HTML d'exemple par ton vrai contenu. Conserve :
- `<title>` optimise
- `<meta name="description">`
- Open Graph (`og:title`, `og:description`, `og:image`...)
- Schema.org JSON-LD
- `<link rel="canonical">` pointant vers ton URL publique
- Liens internes vers tes autres pages

### Etape 3 : Upload le fichier sur ton hebergeur

Renomme `cloak.php` en `index.php` et place-le dans un dossier dedie :

```
/htdocs/ton-site.com/ma-page-cloakee/
                    `-- index.php
```

L'URL publique sera `https://ton-site.com/ma-page-cloakee/`.

### Etape 4 : Verifie que PHP est actif

Sur Nginx + PHP-FPM (ex. CloudPanel), si le site est configure en "static" il faut activer un handler PHP. Ajoute dans le vhost une location dediee :

```nginx
location ^~ /ma-page-cloakee/ {
  index index.php;
  try_files $uri $uri/ /ma-page-cloakee/index.php?$args;
  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    try_files $uri =404;
    fastcgi_pass 127.0.0.1:9000;  # adapte au socket PHP-FPM de ton site
  }
}
```

Sur un Apache standard avec PHP, aucune config supplementaire n'est requise.

### Etape 5 : Test

Voir la section [Tester](#tester).

---

## Variante 2 : Cloudflare Worker

Avantages : pas de serveur a gerer, latence faible, log natif via `wrangler tail`.

### Etape 1 : Pre-requis

- Compte Cloudflare (gratuit suffit)
- Node.js 18+
- `wrangler` (auto-installe via npm)

### Etape 2 : Installation

```bash
git clone https://github.com/<ton-user>/seo-cloak.git
cd seo-cloak
npm install
```

### Etape 3 : Configuration

Edite `wrangler.toml` :

```toml
name = "seo-cloak-worker"

[vars]
PREVIEW_TOKEN = "ton-token-secret"
```

Edite `src/page.html` avec ton contenu reel.

### Etape 4 : Deploy

```bash
# Login (premiere fois uniquement)
npx wrangler login

# Deploy
npx wrangler deploy
```

Le Worker est deploye sur `https://seo-cloak-worker.<ton-subdomain>.workers.dev/`.

### Etape 5 : Brancher sur ton domaine (optionnel)

Decommente le bloc `[[routes]]` dans `wrangler.toml` :

```toml
[[routes]]
pattern = "ton-domaine.com/ma-page-cloakee"
zone_name = "ton-domaine.com"
```

Ton domaine doit etre dans ta zone Cloudflare. Re-deploie :

```bash
npx wrangler deploy
```

---

## Anti-spoof rDNS

Le **reverse DNS forward-confirmed** est le mecanisme cle pour empecher un concurrent de falsifier son User-Agent.

### Comment ca marche

1. Le code detecte l'IP du visiteur (ex: `54.36.148.123`)
2. Reverse DNS lookup : `gethostbyaddr(54.36.148.123)` retourne `crawler-1.ahrefs.com`
3. Verification du suffixe : est-ce que ca finit par `.ahrefs.com` ? **Oui** -> on continue
4. Forward lookup : `gethostbyname(crawler-1.ahrefs.com)` retourne `54.36.148.123`
5. **L'IP forward correspond a l'IP de depart** -> bot legitime, on sert la page

Si un concurrent change son User-Agent pour `AhrefsBot` mais que son IP est `203.0.113.45` (sa freebox), le rDNS ne pointera jamais vers `*.ahrefs.com` -> il recoit le 403.

### Suffixes par defaut

```
.ahrefs.com
.majestic12.co.uk
.semrush.com
.botsemrush.com
```

### Si tu ajoutes Googlebot / Bingbot

Decommente dans `cloak.php` :

```php
$ALLOWED_BOT_UA = [
    // ...
    '/Googlebot/i',
    '/bingbot/i',
];

$ALLOWED_RDNS_SUFFIXES = [
    // ...
    '.googlebot.com', '.google.com',
    '.search.msn.com',
];
```

### Desactiver rDNS

Sur certains hebergeurs (notamment mutualises), `gethostbyaddr` peut etre lent (>1s). Dans ce cas mets :

```php
$VERIFY_RDNS = false;
```

Tu perds l'anti-spoof mais tu gagnes en rapidite. La detection se fait alors uniquement sur le User-Agent.

---

## Personnaliser la page servie

Le HTML servi aux bots est dans :
- **PHP** : fonction `get_seo_html()` dans `cloak.php`
- **Worker** : fichier `src/page.html`

Bonnes pratiques SEO :

- Title unique et descriptif (50-60 caracteres)
- Meta description engageante (150-160 caracteres)
- Open Graph complet (`og:title`, `og:description`, `og:image`, `og:url`)
- Schema.org JSON-LD adapte au type de contenu (Product, Article, LocalBusiness...)
- `<link rel="canonical">` pointant vers l'URL publique
- Liens internes contextuels vers d'autres pages de ton site
- Texte naturel, pas de keyword stuffing
- Images avec `alt` descriptif

---

## Tester

Une fois deploye sur `https://example.com/ma-page/`, verifie chaque scenario :

### 1. Visiteur normal -> doit voir le 403

```bash
curl -i \
  -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0.0.0" \
  https://example.com/ma-page/
```

Resultat attendu : `HTTP 403`, body contenant `Error 1020` et `Access denied`.

### 2. Bot Ahrefs (UA seul) -> doit voir la page

Note : depuis ton IP, avec `$VERIFY_RDNS = true`, ce test echouera (ton rDNS n'est pas `*.ahrefs.com`). C'est normal et c'est le but. Pour tester comme si tu etais Ahrefs :

```bash
# Active temporairement $VERIFY_RDNS = false dans cloak.php
curl -i \
  -A "Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)" \
  https://example.com/ma-page/
```

Resultat attendu : `HTTP 200`, body contenant ton titre et ta description.

### 3. Bot Semrush

```bash
curl -i \
  -A "Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)" \
  https://example.com/ma-page/
```

### 4. Bot Majestic

```bash
curl -i \
  -A "Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)" \
  https://example.com/ma-page/
```

### 5. Bypass admin

```bash
curl -i "https://example.com/ma-page/?preview=ton-token-secret"
```

Resultat attendu : `HTTP 200`, page complete.

### 6. Verifier qu'un vrai bot SEO peut t'indexer

Apres deploy, utilise l'outil "Inspect URL" / "Site Audit" de Ahrefs ou Semrush sur ton URL. Tu devrais voir la page complete avec un code 200 dans leur rapport.

---

## Bots supportes

### Detectes par defaut

| Service | User-Agent matched |
|---|---|
| Majestic | `MJ12bot` |
| Ahrefs | `AhrefsBot`, `AhrefsSiteAudit` |
| Semrush | `SemrushBot`, `SiteAuditBot`, `SplitSignalBot`, `SemrushBot-BA`, `SemrushBot-SA`, `SemrushBot-BM`, `SemrushBot-SEOAB` |

### Ajouter d'autres bots

Edite `$ALLOWED_BOT_UA` (PHP) ou `ALLOWED_BOT_UA` (Worker). Exemples courants :

```
/Googlebot/i        -> Google
/bingbot/i          -> Bing
/DuckDuckBot/i      -> DuckDuckGo
/Baiduspider/i      -> Baidu
/YandexBot/i        -> Yandex
/SeznamBot/i        -> Seznam
/Applebot/i         -> Apple
```

Pense a ajouter aussi le suffixe rDNS correspondant.

---

## FAQ

### Pourquoi imiter Cloudflare 1020 et pas un 404 ?

Un 1020 / 403 est plus credible aux yeux d'un visiteur curieux et ne fait pas penser a un cloaking. Un 404 declencherait potentiellement la desindexation de l'URL. Un 1020 dit "tu es bloque" sans signaler que la page n'existe pas.

### Est-ce detectable par Google ?

**Oui.** Google peut crawler depuis Googlebot puis recroiser via Chrome User-Agent. Si tu mets Googlebot dans la whitelist, le risque est moindre car les deux verront la page. Si tu exclus Googlebot, tu cloakes contre Google -> risque de **penalite manuelle** ou **algorithmique** (Spam Update).

### Le Ray ID est-il un vrai Ray Cloudflare ?

Non. Il est genere localement avec `random_bytes`. Visuellement identique au format Cloudflare (16 chars hex + suffixe colo) mais ne correspond a rien dans Cloudflare. C'est suffisant pour leurrer un visiteur lambda.

### Puis-je heberger plusieurs pages cloakees ?

Oui. Duplique `cloak.php` dans plusieurs sous-dossiers ou sers plusieurs Workers avec routes differentes. Pour mutualiser, transforme la fonction `get_seo_html()` pour servir un contenu different selon `$_SERVER['REQUEST_URI']`.

### Mon hebergeur bloque `gethostbyaddr` (DNS desactive)

Mets `$VERIFY_RDNS = false`. La protection se fait alors uniquement sur l'UA, ce qui reste suffisant contre 95 % des concurrents (peu se mettent a spoofer l'UA d'Ahrefs).

### Performance

- **PHP avec rDNS** : 1 a 3 lookups DNS par requete = 50ms a 1500ms selon le resolver
- **PHP sans rDNS** : <1ms
- **Worker** : <10ms (rDNS non implemente cote Worker, c'est UA-only)

Pour un Worker avec rDNS, il faudrait passer par une API DNS-over-HTTPS comme `1.1.1.1`. Non implemente ici par simplicite.

### Mise a jour du code

Surveille les changements de User-Agent des crawlers (rares mais possibles). Documents officiels :
- Ahrefs : https://ahrefs.com/robot
- Semrush : https://www.semrush.com/bot/
- Majestic : https://majestic.com/help/about-majestic

---

## Licence

MIT. Voir `LICENSE`.

---

## Contribution

Pull requests bienvenues pour :
- Support de nouveaux bots SEO
- Implementation rDNS dans le Worker (via DNS-over-HTTPS)
- Templates de pages SEO pour differents secteurs
- Tests automatises
