<?php
/**
 * SEO Cloaking - Single PHP file
 *
 * Sert la page complete (200) UNIQUEMENT aux bots SEO Majestic / Ahrefs / Semrush.
 * Tout le reste (visiteurs, concurrents, autres bots) -> 403 imitant Cloudflare 1020.
 *
 * Usage:
 *   - Upload comme `index.php` dans un dossier dedie sur ton hebergeur PHP
 *   - Edite la fonction get_seo_html() pour mettre TON contenu
 *   - Change $PREVIEW_TOKEN avec un secret a toi
 *
 * License: MIT
 */

// =====================================================================
// CONFIG (a editer)
// =====================================================================

// Token secret pour bypasser le cloaking et previewer la page (toi seul)
// URL: ?preview=TON_TOKEN
$PREVIEW_TOKEN = 'change_this_to_a_long_random_string';

// User-Agents des bots SEO autorises (regex insensible a la casse)
$ALLOWED_BOT_UA = [
    '/MJ12bot/i',                  // Majestic
    '/AhrefsBot/i',                // Ahrefs principal
    '/AhrefsSiteAudit/i',          // Ahrefs site audit
    '/SemrushBot/i',               // Semrush principal
    '/SiteAuditBot/i',             // Semrush site audit
    '/SplitSignalBot/i',           // Semrush experiments
    '/SemrushBot-BA/i',
    '/SemrushBot-SA/i',
    '/SemrushBot-BM/i',
    '/SemrushBot-SEOAB/i',
    // Decommente si tu veux aussi laisser passer Google/Bing:
    // '/Googlebot/i',
    // '/bingbot/i',
];

// Verification reverse DNS (anti-spoof UA)
// Recommande: true. Si rDNS lent sur ton host, mets false.
$VERIFY_RDNS = true;

// Suffixes hostname autorises pour rDNS
$ALLOWED_RDNS_SUFFIXES = [
    '.ahrefs.com',
    '.majestic12.co.uk',
    '.semrush.com',
    '.botsemrush.com',
    // Ajoute si tu actives Google/Bing dans la whitelist:
    // '.googlebot.com', '.google.com',
    // '.search.msn.com',
];

// =====================================================================
// LOGIQUE (ne pas toucher sauf si tu sais ce que tu fais)
// =====================================================================

function get_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

function is_ua_allowed($ua, $patterns) {
    if (empty($ua)) return false;
    foreach ($patterns as $re) {
        if (preg_match($re, $ua)) return true;
    }
    return false;
}

function is_rdns_allowed($ip, $suffixes) {
    $host = @gethostbyaddr($ip);
    if (!$host || $host === $ip) return false;

    $matches_suffix = false;
    foreach ($suffixes as $suf) {
        if (str_ends_with(strtolower($host), strtolower($suf))) {
            $matches_suffix = true;
            break;
        }
    }
    if (!$matches_suffix) return false;

    // Forward-confirmed reverse DNS: le hostname doit pointer vers la meme IP
    $resolved = @gethostbyname($host);
    return $resolved === $ip;
}

function generate_ray_id() {
    return bin2hex(random_bytes(8)) . '-CDG';
}

function serve_403_cloudflare($host, $ip) {
    $ray = generate_ray_id();
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('CF-Mitigated: challenge');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en-US">
<head>
<title>Access denied | {$host} used Cloudflare to restrict access</title>
<meta charset="UTF-8" />
<meta http-equiv="X-UA-Compatible" content="IE=Edge" />
<meta name="robots" content="noindex, nofollow" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,"Open Sans","Helvetica Neue",sans-serif;color:#404040;background:#fff}.cf-wrapper{max-width:60rem;margin:0 auto;padding:0 15px}h1{font-size:32px;font-weight:700;margin:1rem 0;color:#222}h2{font-size:20px;font-weight:400;color:#555;margin:0 0 1rem}.cf-section{padding:30px 0;border-bottom:1px solid #e0e0e0}.cf-error-footer{font-size:12px;color:#999;text-align:center;padding:20px 0}.cf-status-display{font-size:18px;font-weight:700;color:#cf3a3a}.cf-id{font-family:Menlo,Monaco,Consolas,"Courier New",monospace;font-size:12px;color:#666}</style>
</head>
<body>
<div class="cf-wrapper">
<div class="cf-section cf-error-overview">
<h1><span class="cf-status-display">Error 1020</span></h1>
<h2 class="cf-subheadline">Access denied</h2>
</div>
<section class="cf-section">
<h2>What happened?</h2>
<p>This website is using a security service to protect itself from online attacks. The action you just performed triggered the security solution. There are several actions that could trigger this block including submitting a certain word or phrase, a SQL command or malformed data.</p>
</section>
<section class="cf-section">
<h2>What can I do to resolve this?</h2>
<p>You can email the site owner to let them know you were blocked. Please include what you were doing when this page came up and the Cloudflare Ray ID found at the bottom of this page.</p>
</section>
<div class="cf-error-footer cf-wrapper">
<p>
<span>Cloudflare Ray ID: <strong class="cf-id">{$ray}</strong></span>
&bull;
<span>Your IP: <strong>{$ip}</strong></span>
&bull;
<span>Performance &amp; security by <a href="https://www.cloudflare.com/5xx-error-landing" target="_blank" rel="noopener noreferrer">Cloudflare</a></span>
</p>
</div>
</div>
</body>
</html>
HTML;
    exit;
}

function serve_seo_page() {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: index, follow');
    echo get_seo_html();
    exit;
}

// =====================================================================
// PAGE HTML servie aux bots SEO (a personnaliser)
// =====================================================================
function get_seo_html() {
    return <<<'HTML'
<!doctype html>
<html lang="fr" dir="ltr">
<head>
<meta charset="utf-8">
<title>Exemple de Produit Premium | Boutique Exemple</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Description SEO de votre produit. Remplacez ce texte par votre vrai contenu optimise pour les moteurs de recherche.">
<meta property="og:title" content="Exemple de Produit Premium | Boutique Exemple" />
<meta property="og:description" content="Description Open Graph de votre produit." />
<meta property="og:image" content="https://example.com/images/product.jpg" />
<meta property="og:url" content="https://example.com/produit-exemple" />
<meta property="og:type" content="product" />
<meta property="og:site_name" content="Boutique Exemple" />
<meta property="og:price:amount" content="99" />
<meta property="og:price:currency" content="EUR" />
<meta property="og:availability" content="in stock" />
<link rel="canonical" href="https://example.com/produit-exemple">
<script type="application/ld+json">
{
  "@context": "http://schema.org",
  "@type": "Product",
  "name": "Exemple de Produit Premium",
  "description": "Description du produit.",
  "image": "https://example.com/images/product.jpg",
  "brand": {"@type": "Brand", "name": "Boutique Exemple"},
  "offers": {
    "@type": "Offer",
    "price": "99.00",
    "priceCurrency": "EUR",
    "availability": "https://schema.org/InStock"
  }
}
</script>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:1100px;margin:0 auto;padding:20px;color:#333;line-height:1.6}
header{border-bottom:1px solid #eee;padding:15px 0;margin-bottom:30px}
nav ul{list-style:none;padding:0;display:flex;gap:25px;flex-wrap:wrap}
nav a{color:#555;text-decoration:none;font-weight:500}
nav a.active{color:#000;border-bottom:2px solid #c8a97e}
h1{font-size:32px;color:#2a2a2a;margin:20px 0 10px}
h2{font-size:22px;color:#5a4a3a;font-weight:500}
.product-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin:30px 0}
.product-image{background:#f5f0e8;height:400px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa}
.product-price{font-size:28px;font-weight:600;color:#c8a97e;margin:20px 0}
.btn{display:inline-block;background:#5a4a3a;color:#fff;padding:14px 30px;text-decoration:none;border-radius:4px;font-weight:500}
footer{margin-top:60px;border-top:1px solid #eee;padding:30px 0;text-align:center;font-size:14px;color:#666}
@media(max-width:768px){.product-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<header>
  <nav aria-label="Menu principal">
    <ul>
      <li><a href="/">Accueil</a></li>
      <li><a href="/categorie-1">Categorie 1</a></li>
      <li><a href="/categorie-2">Categorie 2</a></li>
      <li><a class="active" href="/produit-exemple">Produit exemple</a></li>
      <li><a href="/contact">Contact</a></li>
    </ul>
  </nav>
</header>

<main role="main">
  <article>
    <div class="product-grid">
      <div class="product-image" role="img" aria-label="Photo du produit">
        [Photo du produit]
      </div>
      <div>
        <h1>Exemple de Produit Premium</h1>
        <h2>Sous-titre descriptif accrocheur</h2>
        <div class="product-price">99 EUR</div>
        <div class="product-description">
          <p>Premiere ligne de description courte et accrocheuse.</p>
          <p>Paragraphe complet expliquant le produit, ses avantages et son contexte.
             Remplacez ce texte par votre contenu reel optimise pour vos mots-cles cibles.</p>
          <p>Ajoutez des paragraphes additionnels pour enrichir le contenu et donner
             aux bots SEO matiere a indexer.</p>
        </div>
        <a href="/cart/add" class="btn">Acheter maintenant</a>
      </div>
    </div>
  </article>

  <section>
    <h2>Vous aimerez aussi</h2>
    <ul>
      <li><a href="/produit-2">Produit lie 1</a></li>
      <li><a href="/produit-3">Produit lie 2</a></li>
      <li><a href="/produit-4">Produit lie 3</a></li>
    </ul>
  </section>
</main>

<footer>
  <p><strong>Boutique Exemple</strong></p>
  <p>Adresse fictive, 75000 Paris | <a href="mailto:contact@example.com">contact@example.com</a></p>
  <p>
    <a href="/mentions-legales">Mentions legales</a> &middot;
    <a href="/contact">Contact</a> &middot;
    <a href="/politique-confidentialite">Confidentialite</a>
  </p>
</footer>

</body>
</html>
HTML;
}

// =====================================================================
// ROUTING
// =====================================================================

$ip = get_client_ip();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? 'example.com';

// 1. Bypass preview perso
if (isset($_GET['preview']) && hash_equals($PREVIEW_TOKEN, $_GET['preview'])) {
    serve_seo_page();
}

// 2. Detection bot SEO
if (is_ua_allowed($ua, $ALLOWED_BOT_UA)) {
    if ($VERIFY_RDNS) {
        if (is_rdns_allowed($ip, $ALLOWED_RDNS_SUFFIXES)) {
            serve_seo_page();
        }
        // UA matche mais rDNS pas conforme -> spoof, on bloque
        serve_403_cloudflare($host, $ip);
    }
    // rDNS desactive: UA seul suffit
    serve_seo_page();
}

// 3. Tout le reste -> 403 Cloudflare
serve_403_cloudflare($host, $ip);
