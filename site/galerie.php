<?php

declare(strict_types=1);

/*
 * Page publique « Galerie » : carrousels d'images (phase 1) + emplacements
 * vidéo « à venir » (phase 2). Pilotée par le ContentStore.
 *
 * - Filtre Tout / Vidéos / Carrousels : CSS pur (radios + sélecteurs frères),
 *   donc fonctionnel sans JS (tout affiché par défaut).
 * - Lightbox : CSS pur via :target (ouverture/fermeture + carrousel scroll-snap
 *   utilisable au doigt/trackpad sans JS). Le JS externe (/js/gallery.js) ajoute
 *   les flèches, le clavier et le menu mobile — rien d'inline (CSP script-src 'self').
 */

require __DIR__ . '/vendor/autoload.php';
try {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
} catch (\Throwable $e) {
    // Pas de .env : les variables viennent de l'environnement.
}

require __DIR__ . '/inc/content_store.php';

try {
    $content = cs_make_store()->get();
} catch (\Throwable $e) {
    $content = cs_default_content();
}
$items = cs_gallery_items($content, true); // actifs, triés

/** Cache-busting identique à index.php. */
function g_asset(string $path): string
{
    $file = __DIR__ . '/' . ltrim($path, '/');
    return is_file($file) ? $path . '?v=' . substr(md5_file($file), 0, 8) : $path;
}
function ge(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$hasVideo    = (bool) cs_gallery_items($content, true, 'video');
$hasCarousel = (bool) cs_gallery_items($content, true, 'carousel');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Galerie — Réalisations en images · Théo Birost</title>
<meta name="description" content="Galerie de réalisations en images : carrousels de projets web conçus par Théo Birost, développeur web full-stack.">
<link rel="canonical" href="https://birostweb.fr/galerie">
<meta name="robots" content="index, follow">
<meta name="author" content="Théo Birost">
<meta name="theme-color" content="#231F20">
<meta name="color-scheme" content="light">
<meta property="og:title" content="Galerie — Réalisations en images · Théo Birost">
<meta property="og:description" content="Carrousels de projets web conçus par Théo Birost, développeur web full-stack.">
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_FR">
<meta property="og:url" content="https://birostweb.fr/galerie">
<meta property="og:site_name" content="Théo Birost">
<meta property="og:image" content="https://birostweb.fr/og-image.png">
<link rel="icon" type="image/png" sizes="640x640" href="<?= g_asset('/favicon.png') ?>">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="preload" href="/fonts/ibmplexsans-400-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/ibmplexsanscondensed-700-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= g_asset('/fonts/fonts.css') ?>">
<style>
/* ===== Tokens (alignés sur index.php) ===== */
:root{
  --paper:#E5E2D6;--surface:#FBFAF6;--ink:#231F20;--accent:#F0451E;--accent-d:#CE3711;
  --gray:#6E6A5F;--line:#CFCABC;--line-2:#E9E5DB;
  --d-bg:#231F20;--d-text:#F0EDE4;--d-dim:#AEA99C;--d-line:#3A3532;
  --fd:'IBM Plex Sans Condensed',system-ui,sans-serif;
  --fb:'IBM Plex Sans',system-ui,sans-serif;
  --fm:'IBM Plex Mono',ui-monospace,monospace;
  --max:1160px;--r:3px;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;scroll-padding-top:88px}
body{background:var(--surface);color:var(--ink);font-family:var(--fb);font-size:17px;line-height:1.6;-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
::selection{background:var(--accent);color:#fff}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.wrap{width:100%;max-width:var(--max);margin:0 auto;padding:0 24px}
.section{padding:clamp(40px,6vw,80px) 0}
/* ===== Nav ===== */
.nav{position:sticky;top:0;z-index:60;background:rgba(251,250,246,.96);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.nav__in{display:flex;align-items:center;justify-content:space-between;height:66px}
.brand{font-family:var(--fd);font-weight:700;font-size:27px;letter-spacing:-.035em;display:flex;align-items:center;gap:10px}
.brand b{width:34px;height:34px;border-radius:2px;background:var(--accent);display:grid;place-items:center}
.brand b::before{content:'BW';color:var(--surface);font-size:18px;letter-spacing:-.1em;padding-right:2px}
.nav__links{display:flex;align-items:center;gap:28px}
.nav__links a:not(.btn){font-family:var(--fb);font-size:14px;color:var(--gray);transition:.15s}
.nav__links a:not(.btn):hover,.nav__links a[aria-current]{color:var(--ink)}
.nav__toggle{display:none;background:none;border:1.5px solid var(--ink);border-radius:8px;width:44px;height:40px;cursor:pointer;flex-direction:column;gap:5px;align-items:center;justify-content:center}
.nav__toggle span{width:18px;height:1.5px;background:var(--ink)}
.btn{font-family:var(--fb);font-weight:500;font-size:13.5px;letter-spacing:.03em;padding:13px 20px;border-radius:var(--r);display:inline-flex;align-items:center;gap:9px;cursor:pointer;border:1px solid transparent;transition:.18s ease;white-space:nowrap;min-height:44px}
.btn-accent{background:var(--accent);color:#fff}
.btn-accent:hover{background:var(--accent-d)}
.btn-ghost{background:transparent;color:var(--ink);border-color:var(--ink)}
.btn-ghost:hover{background:var(--ink);color:var(--surface)}
/* ===== En-tête de section ===== */
.eyebrow{font-family:var(--fm);font-weight:500;font-size:12px;letter-spacing:.065em;text-transform:uppercase;color:var(--accent);display:inline-flex;align-items:center;gap:11px}
.eyebrow::before{content:"";width:22px;height:2px;background:var(--accent)}
.h2{font-family:var(--fd);font-weight:700;line-height:1.02;font-size:clamp(30px,4.6vw,50px);letter-spacing:-.035em}
.lead{font-size:clamp(16.5px,1.7vw,19px);color:var(--gray);max-width:60ch;margin-top:14px}
.sec-head{border-top:1px solid var(--line);padding-top:24px;margin-bottom:clamp(26px,4vw,38px)}
.sec-head .eyebrow{margin-bottom:16px}
/* ===== Filtre (CSS pur) ===== */
.gal-filter-in{display:none}
.gal-filter{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:clamp(24px,3vw,36px)}
.gal-filter label{font-family:var(--fm);font-size:13.5px;letter-spacing:.02em;padding:11px 18px;border:1.5px solid var(--line);border-radius:var(--r);cursor:pointer;color:var(--gray);background:var(--surface);transition:.15s}
.gal-filter label:hover{color:var(--ink);border-color:var(--ink)}
#gf-all:checked~.gal-filter label[for="gf-all"],
#gf-video:checked~.gal-filter label[for="gf-video"],
#gf-carousel:checked~.gal-filter label[for="gf-carousel"]{background:var(--accent);border-color:var(--accent);color:#fff}
#gf-video:checked~.gal-grid .gal-card:not([data-type="video"]){display:none}
#gf-carousel:checked~.gal-grid .gal-card:not([data-type="carousel"]){display:none}
/* ===== Grille ===== */
.gal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.gal-card{display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.gal-card__media{position:relative;display:block;aspect-ratio:4/3;background:var(--line-2);overflow:hidden}
.gal-card__media img{width:100%;height:100%;object-fit:cover;transition:transform .3s ease}
a.gal-card__media:hover img{transform:scale(1.03)}
.gal-card__tag{position:absolute;top:10px;left:10px;font-family:var(--fm);font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;background:var(--ink);color:var(--surface);padding:4px 9px;border-radius:2px}
.gal-card__count{position:absolute;bottom:10px;right:10px;font-family:var(--fm);font-size:11px;background:rgba(35,31,32,.78);color:#fff;padding:4px 9px;border-radius:2px}
.gal-card__body{padding:16px 18px;display:flex;flex-direction:column;gap:6px}
.gal-card__body h3{font-family:var(--fd);font-weight:700;font-size:20px;line-height:1.1;letter-spacing:-.02em}
.gal-card__body p{font-size:14.5px;color:var(--gray);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.gal-card__hint{font-family:var(--fm);font-size:11.5px;color:var(--accent);margin-top:2px}
/* placeholder vidéo */
.gal-card--video .gal-card__media{display:flex;align-items:center;justify-content:center;background:var(--ink);color:var(--d-dim)}
.gal-video-ph{text-align:center;font-family:var(--fm);font-size:12px;letter-spacing:.1em;text-transform:uppercase}
.gal-video-ph svg{width:34px;height:34px;color:var(--accent);margin:0 auto 10px}
.gal-empty{border:1px dashed var(--line);border-radius:var(--r);padding:40px 24px;text-align:center;color:var(--gray);font-family:var(--fm);font-size:14px}
/* ===== Lightbox (:target) ===== */
.lightbox{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;padding:clamp(12px,3vw,40px)}
.lightbox:target{display:flex}
.lightbox__backdrop{position:absolute;inset:0;background:rgba(20,17,18,.86);backdrop-filter:blur(2px)}
.lightbox__panel{position:relative;z-index:1;width:min(1000px,100%);max-height:92vh;background:var(--surface);border-radius:var(--r);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.lightbox__close{position:absolute;top:10px;right:12px;z-index:3;width:40px;height:40px;display:grid;place-items:center;font-size:26px;line-height:1;color:#fff;background:rgba(35,31,32,.6);border-radius:50%}
.lightbox__close:hover{background:var(--accent)}
.lightbox__viewport{position:relative;background:#141112}
.lightbox__track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;-webkit-overflow-scrolling:touch}
.lightbox__track::-webkit-scrollbar{height:0}
.slide{flex:0 0 100%;scroll-snap-align:center;display:flex;align-items:center;justify-content:center;background:#141112}
.slide img{width:100%;height:auto;max-height:70vh;object-fit:contain}
.nav-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:46px;height:46px;border:none;border-radius:50%;background:rgba(251,250,246,.9);color:var(--ink);font-size:26px;line-height:1;cursor:pointer;display:grid;place-items:center;transition:.15s}
.nav-arrow:hover{background:var(--accent);color:#fff}
.nav-arrow.prev{left:12px}
.nav-arrow.next{right:12px}
.lightbox__desc{padding:18px 22px;overflow-y:auto}
.lightbox__desc h3{font-family:var(--fd);font-weight:700;font-size:22px;letter-spacing:-.02em;margin-bottom:6px}
.lightbox__desc p{font-size:15px;color:var(--gray);line-height:1.6;white-space:pre-line}
/* ===== Footer ===== */
.footer{background:var(--d-bg);color:var(--d-dim);padding:36px 0 30px;border-top:1px solid var(--d-line)}
.footer__in{display:flex;flex-wrap:wrap;gap:18px;justify-content:space-between;align-items:center}
.footer__b{font-family:var(--fd);font-weight:700;color:var(--d-text);font-size:16px}
.footer__l{display:flex;gap:20px;flex-wrap:wrap}
.footer__l a{font-family:var(--fm);font-size:12px;color:var(--d-dim)}
.footer__l a:hover{color:var(--accent)}
.footer__meta{width:100%;margin-top:22px;padding-top:20px;border-top:1px solid var(--d-line);font-family:var(--fm);font-size:11.5px;color:var(--d-dim);display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}
/* ===== Responsive ===== */
@media (max-width:920px){.gal-grid{grid-template-columns:1fr 1fr}}
@media (max-width:640px){
  .nav__links{position:absolute;top:66px;left:0;right:0;background:var(--paper);border-bottom:1px solid var(--line);flex-direction:column;align-items:flex-start;gap:0;padding:8px 24px 20px;display:none}
  .nav__links.open{display:flex}
  .nav__links a:not(.btn){width:100%;padding:13px 0;border-bottom:1px solid var(--line)}
  .nav__links .btn{margin-top:14px;width:100%;justify-content:center}
  .nav__toggle{display:flex}
  .gal-grid{grid-template-columns:1fr}
}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}.lightbox__track{scroll-behavior:auto}}
</style>
<link rel="stylesheet" href="<?= g_asset('/css/studio.css') ?>">
</head>
<body id="top">

<!-- ============ NAV ============ -->
<header class="nav">
  <div class="wrap nav__in">
    <a href="/" class="brand"><b></b>Birostweb</a>
    <nav class="nav__links" id="menu">
      <a href="/#realisations">Réalisations</a>
      <a href="/#offres">Offres</a>
      <a href="/galerie" aria-current="page">Galerie</a>
      <a href="/#approche">Approche</a>
      <a href="/#contact" class="btn btn-accent">Devis gratuit</a>
    </nav>
    <button class="nav__toggle" id="toggle" aria-label="Menu" aria-expanded="false"><span></span><span></span><span></span></button>
  </div>
</header>

<main class="section">
  <div class="wrap">
    <div class="sec-head" id="gal">
      <span class="eyebrow">Galerie</span>
      <h2 class="h2">Des projets en images.</h2>
      <p class="lead">Quelques réalisations en détail&nbsp;: parcourez les carrousels pour voir les écrans clés de chaque projet. Les vidéos arrivent bientôt.</p>
    </div>

<?php if (!$items): ?>
    <div class="gal-empty">La galerie se remplit bientôt. Revenez vite&nbsp;!</div>
<?php else: ?>
    <input class="gal-filter-in" type="radio" name="galfilter" id="gf-all" checked>
    <input class="gal-filter-in" type="radio" name="galfilter" id="gf-video">
    <input class="gal-filter-in" type="radio" name="galfilter" id="gf-carousel">
    <div class="gal-filter" role="tablist" aria-label="Filtrer la galerie">
      <label for="gf-all">Tout</label>
      <label for="gf-video">Vidéos</label>
      <label for="gf-carousel">Carrousels</label>
    </div>

    <div class="gal-grid">
<?php foreach ($items as $it):
    $iid   = (string) ($it['id'] ?? '');
    $itype = (string) ($it['type'] ?? 'carousel');
    $title = (string) ($it['title'] ?? '');
    if ($itype === 'video'): ?>
      <article class="gal-card gal-card--video" data-type="video">
        <div class="gal-card__media">
          <div class="gal-video-ph">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
            Vidéo — bientôt
          </div>
          <span class="gal-card__tag">Vidéo</span>
        </div>
        <div class="gal-card__body">
          <h3><?= ge($title) ?></h3>
          <?php if (($it['description'] ?? '') !== ''): ?><p><?= ge((string) $it['description']) ?></p><?php endif; ?>
          <span class="gal-card__hint">Lecture disponible prochainement</span>
        </div>
      </article>
<?php else:
        $imgs = cs_gallery_item_images($it);
        $cover = $imgs[0] ?? null;
        $count = count($imgs);
    ?>
      <article class="gal-card" data-type="carousel">
        <a class="gal-card__media" href="#lb-<?= ge($iid) ?>" aria-label="Ouvrir le carrousel : <?= ge($title) ?>">
          <?php if ($cover): ?>
            <img src="<?= ge((string) ($cover['url'] ?? '')) ?>" alt="<?= ge((string) ($cover['alt'] ?? $title)) ?>" loading="lazy" decoding="async">
          <?php endif; ?>
          <span class="gal-card__tag">Carrousel</span>
          <?php if ($count > 1): ?><span class="gal-card__count"><?= $count ?> images</span><?php endif; ?>
        </a>
        <div class="gal-card__body">
          <h3><?= ge($title) ?></h3>
          <?php if (($it['description'] ?? '') !== ''): ?><p><?= ge((string) $it['description']) ?></p><?php endif; ?>
          <span class="gal-card__hint">Voir le carrousel →</span>
        </div>
      </article>
<?php endif; endforeach; ?>
    </div>
<?php endif; ?>
  </div>
</main>

<?php
// Lightbox par carrousel (en fin de body pour :target).
foreach ($items as $it):
    if (($it['type'] ?? '') !== 'carousel') {
        continue;
    }
    $iid   = (string) ($it['id'] ?? '');
    $title = (string) ($it['title'] ?? '');
    $imgs  = cs_gallery_item_images($it);
    if (!$imgs) {
        continue;
    }
?>
<div class="lightbox" id="lb-<?= ge($iid) ?>" role="dialog" aria-modal="true" aria-label="<?= ge($title) ?>">
  <a class="lightbox__backdrop" href="#gal" aria-label="Fermer"></a>
  <div class="lightbox__panel">
    <a class="lightbox__close" href="#gal" aria-label="Fermer" role="button">×</a>
    <div class="lightbox__viewport" data-carousel>
      <div class="lightbox__track" tabindex="0">
<?php foreach ($imgs as $img): ?>
        <figure class="slide"><img src="<?= ge((string) ($img['url'] ?? '')) ?>" alt="<?= ge((string) ($img['alt'] ?? $title)) ?>" loading="lazy" decoding="async"></figure>
<?php endforeach; ?>
      </div>
<?php if (count($imgs) > 1): ?>
      <button type="button" class="nav-arrow prev" data-prev aria-label="Image précédente" hidden>‹</button>
      <button type="button" class="nav-arrow next" data-next aria-label="Image suivante" hidden>›</button>
<?php endif; ?>
    </div>
    <div class="lightbox__desc">
      <h3><?= ge($title) ?></h3>
<?php if (($it['description'] ?? '') !== ''): ?>      <p><?= ge((string) $it['description']) ?></p>
<?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ============ FOOTER ============ -->
<footer class="footer">
  <div class="wrap">
    <div class="footer__in">
      <span class="footer__b">Théo Birost — Développeur web full-stack</span>
      <nav class="footer__l">
        <a href="/#realisations">Réalisations</a><a href="/galerie">Galerie</a><a href="/#offres">Offres</a><a href="/#contact">Contact</a><a href="/mentions-legales.html">Mentions légales</a><a href="/cgv.html">CGV</a><a href="#top">↑ Haut</a>
      </nav>
    </div>
    <div class="footer__meta"><span>© 2026 Théo Birost · France · Full remote</span><span>Micro-entreprise · Développement web sur-mesure</span></div>
  </div>
</footer>

<script src="<?= g_asset('/js/gallery.js') ?>" defer></script>
</body>
</html>
