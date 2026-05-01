import pageHTML from "./page.html";

// User-Agents autorises (Majestic, Ahrefs, Semrush + variantes connues)
const ALLOWED_BOT_UA = [
  /MJ12bot/i,                  // Majestic
  /AhrefsBot/i,                // Ahrefs crawler principal
  /AhrefsSiteAudit/i,          // Ahrefs site audit
  /SemrushBot/i,               // Semrush principal
  /SiteAuditBot/i,             // Semrush site audit
  /SplitSignalBot/i,           // Semrush experiments
  /SemrushBot-BA/i,
  /SemrushBot-SA/i,
  /SemrushBot-BM/i,
  /SemrushBot-SEOAB/i
];

// IP ranges officiels (defense en profondeur, optionnel)
// Source: docs publiques de chaque crawler
const ALLOWED_IPS = [
  // Ahrefs
  "54.36.148.0/24", "54.36.149.0/24", "195.154.122.0/24", "195.154.123.0/24",
  // Semrush
  "85.208.96.0/22", "185.191.171.0/24",
  // Majestic
  "static.majestic12.co.uk"
];

function isAllowedBot(request) {
  const ua = request.headers.get("user-agent") || "";
  return ALLOWED_BOT_UA.some((re) => re.test(ua));
}

function cloudflareError403(request) {
  const cfRay = request.headers.get("cf-ray") || generateRayId();
  const ip = request.headers.get("cf-connecting-ip") || "0.0.0.0";
  const html = `<!DOCTYPE html>
<html lang="en-US">
<head>
<title>Access denied | ${new URL(request.url).hostname} used Cloudflare to restrict access</title>
<meta charset="UTF-8" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta http-equiv="X-UA-Compatible" content="IE=Edge" />
<meta name="robots" content="noindex, nofollow" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<link rel="stylesheet" id="cf_styles-css" href="/cdn-cgi/styles/cf.errors.css" />
<style>body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,"Open Sans","Helvetica Neue",sans-serif;color:#404040;background:#fff}.cf-wrapper{max-width:60rem;margin:0 auto;padding:0 15px}h1{font-size:32px;font-weight:700;margin:1rem 0;color:#222}h2{font-size:20px;font-weight:400;color:#555;margin:0 0 1rem}.cf-error-overview p{font-size:15px;line-height:1.5;color:#404040}.cf-section{padding:30px 0;border-bottom:1px solid #e0e0e0}.cf-error-footer{font-size:12px;color:#999;text-align:center;padding:20px 0}.cf-status-display{font-size:18px;font-weight:700;color:#cf3a3a}.cf-icon-error{display:inline-block;width:18px;height:18px;background:#cf3a3a;border-radius:50%;color:#fff;text-align:center;line-height:18px;font-size:12px;margin-right:6px}.cf-id{font-family:Menlo,Monaco,Consolas,"Courier New",monospace;font-size:12px;color:#666}</style>
</head>
<body>
<div id="cf-wrapper" class="cf-wrapper">
<div class="cf-section cf-error-overview">
<h1><span class="cf-status-display">Error 1020</span></h1>
<h2 class="cf-subheadline">Access denied</h2>
</div>
<section class="cf-section">
<div id="what-happened-section">
<h2>What happened?</h2>
<p>This website is using a security service to protect itself from online attacks. The action you just performed triggered the security solution. There are several actions that could trigger this block including submitting a certain word or phrase, a SQL command or malformed data.</p>
</div>
</section>
<section class="cf-section">
<div id="what-can-i-do-section">
<h2>What can I do to resolve this?</h2>
<p>You can email the site owner to let them know you were blocked. Please include what you were doing when this page came up and the Cloudflare Ray ID found at the bottom of this page.</p>
</div>
</section>
<div class="cf-error-footer cf-wrapper">
<p>
<span class="cf-footer-item">Cloudflare Ray ID: <strong class="cf-id">${cfRay}</strong></span>
<span class="cf-footer-separator">&bull;</span>
<span class="cf-footer-item">Your IP: <strong>${ip}</strong></span>
<span class="cf-footer-separator">&bull;</span>
<span class="cf-footer-item">Performance &amp; security by <a rel="noopener noreferrer" href="https://www.cloudflare.com/5xx-error-landing" target="_blank">Cloudflare</a></span>
</p>
</div>
</div>
</body>
</html>`;

  return new Response(html, {
    status: 403,
    headers: {
      "content-type": "text/html; charset=utf-8",
      "cache-control": "private, max-age=0, no-store, no-cache, must-revalidate",
      "x-frame-options": "SAMEORIGIN",
      "referrer-policy": "same-origin",
      "cf-mitigated": "challenge"
    }
  });
}

function generateRayId() {
  const chars = "0123456789abcdef";
  let id = "";
  for (let i = 0; i < 16; i++) id += chars[Math.floor(Math.random() * 16)];
  return `${id}-CDG`;
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    // Bypass preview perso (toi seul, pour tester la page sans cloaking)
    if (env.PREVIEW_TOKEN && url.searchParams.get("preview") === env.PREVIEW_TOKEN) {
      return new Response(pageHTML, {
        status: 200,
        headers: {
          "content-type": "text/html; charset=utf-8",
          "cache-control": "private, no-store",
          "x-robots-tag": "noindex"
        }
      });
    }

    // Robots.txt: autorise les bots SEO ciblees
    if (url.pathname === "/robots.txt") {
      return new Response(
        `User-agent: AhrefsBot\nAllow: /\n\nUser-agent: MJ12bot\nAllow: /\n\nUser-agent: SemrushBot\nAllow: /\n\nUser-agent: *\nAllow: /\n\nSitemap: ${url.origin}/sitemap.xml\n`,
        { status: 200, headers: { "content-type": "text/plain; charset=utf-8" } }
      );
    }

    // Sitemap minimal pour aider a la decouverte
    if (url.pathname === "/sitemap.xml") {
      return new Response(
        `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<url><loc>${url.origin}/</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>
</urlset>`,
        { status: 200, headers: { "content-type": "application/xml; charset=utf-8" } }
      );
    }

    // Bot SEO autorise -> sert la page complete en 200
    if (isAllowedBot(request)) {
      return new Response(pageHTML, {
        status: 200,
        headers: {
          "content-type": "text/html; charset=utf-8",
          "cache-control": "public, max-age=3600",
          "x-robots-tag": "index, follow"
        }
      });
    }

    // Tout le reste -> erreur Cloudflare 1020 / 403
    return cloudflareError403(request);
  }
};
