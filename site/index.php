<?php
// Jeton anti-spam du formulaire : HMAC signé sur l'horodatage du rendu.
// send_mail.php vérifie la signature + un délai minimum avant d'accepter l'envoi.
require __DIR__ . '/vendor/autoload.php';
try {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    // Pas de .env : normal en prod, les variables viennent de l'environnement Dokploy.
}
$contactFormTs    = time();
$contactSecret    = $_ENV['CONTACT_FORM_SECRET'] ?? $_SERVER['CONTACT_FORM_SECRET'] ?? getenv('CONTACT_FORM_SECRET') ?: '';
$contactFormToken = hash_hmac('sha256', (string) $contactFormTs, $contactSecret);

// Cache-busting : ajoute ?v=<empreinte du contenu> aux fichiers statiques.
// Le .htaccess met ces URL versionnées en cache 1 an : dès qu'un fichier
// change, son empreinte (donc son URL) change et le navigateur le recharge.
function asset(string $path): string {
    $file = __DIR__ . '/' . ltrim($path, '/');
    return is_file($file) ? $path . '?v=' . substr(md5_file($file), 0, 8) : $path;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Théo Birost — Développeur web full-stack · Sites qui convertissent</title>
<meta name="description" content="Développeur web indépendant, en télétravail partout en France. Je crée des sites et applications sur-mesure, soignés et rapides, du premier échange à la mise en ligne. Devis gratuit sous 48h.">
<link rel="canonical" href="https://birostweb.fr/">
<meta name="robots" content="index, follow">
<meta name="author" content="Théo Birost">
<meta name="theme-color" content="#231F20">
<meta name="color-scheme" content="light">
<!-- Open Graph -->
<meta property="og:title" content="Théo Birost — Développeur web full-stack">
<meta property="og:description" content="Des sites web sur-mesure pensés pour vous rapporter des clients. Devis gratuit sous 48h.">
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_FR">
<meta property="og:url" content="https://birostweb.fr/">
<meta property="og:site_name" content="Théo Birost">
<meta property="og:image" content="https://birostweb.fr/og-image.png">
<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Théo Birost — Développeur web full-stack">
<meta name="twitter:description" content="Des sites web sur-mesure pensés pour vous rapporter des clients. Devis gratuit sous 48h.">
<meta name="twitter:image" content="https://birostweb.fr/og-image.png">
<!-- Favicon Birostweb (logo BW) -->
<link rel="icon" type="image/png" sizes="512x512" href="<?= asset('/favicon.png') ?>">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<!-- Polices auto-hébergées (RGPD : aucune requête vers Google) -->
<link rel="preload" href="/fonts/ibmplexsans-400-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/ibmplexsanscondensed-700-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('/fonts/fonts.css') ?>">
<link rel="manifest" href="<?= asset('/site.webmanifest') ?>">
<script async type="module" src="<?= asset('/js/altcha.js') ?>"></script>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"ProfessionalService","name":"Birostweb — Théo Birost","description":"Développeur web full-stack indépendant : sites vitrines, boutiques et applications sur-mesure, du design au déploiement.","url":"https://birostweb.fr","email":"contact@theo-birost.fr","areaServed":"FR","founder":{"@type":"Person","name":"Théo Birost"},"sameAs":["https://github.com/birostweb","https://www.linkedin.com/in/th%C3%A9o-birost-09b286429/","https://www.instagram.com/birost.web"],"priceRange":"€€"}</script>
<style>
/* ================= TOKENS ================= */
:root{
  --paper:#E5E2D6;        /* greige (fond) */
  --surface:#FBFAF6;      /* blanc chaud (cartes) */
  --ink:#231F20;          /* noir chaud */
  --accent:#F0451E;       /* vermillon — change juste cette valeur pour reskin */
  --accent-d:#CE3711;     /* accent survol */
  --gray:#6E6A5F;         /* texte secondaire */
  --line:#CFCABC;         /* filet sur greige */
  --line-2:#E9E5DB;       /* filet sur blanc */
  /* zones sombres */
  --d-bg:#231F20; --d-text:#F0EDE4; --d-dim:#AEA99C; --d-line:#3A3532;
  --fd:'IBM Plex Sans Condensed',system-ui,sans-serif;
  --fb:'IBM Plex Sans',system-ui,sans-serif;
  --fm:'IBM Plex Mono',ui-monospace,monospace;
  --max:1160px; --r:10px;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{background:var(--paper);color:var(--ink);font-family:var(--fb);font-size:17px;line-height:1.6;-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
::selection{background:var(--accent);color:#fff}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.wrap{width:100%;max-width:var(--max);margin:0 auto;padding:0 24px}
.section{padding:clamp(64px,9vw,120px) 0}
.section--paper{background:var(--paper)}
.section--dark{background:var(--d-bg);color:var(--d-text)}

/* ================= TYPO ================= */
.eyebrow{font-family:var(--fm);font-weight:500;font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:var(--accent);display:inline-flex;align-items:center;gap:11px}
.eyebrow::before{content:"";width:22px;height:2px;background:var(--accent)}
.section--dark .eyebrow{color:var(--accent)}
.h2{font-family:var(--fd);font-weight:700;line-height:1.02;font-size:clamp(30px,4.6vw,50px);letter-spacing:-.01em}
.lead{font-size:clamp(16.5px,1.7vw,19px);color:var(--gray);max-width:56ch;margin-top:14px}
.section--dark .lead{color:var(--d-dim)}
.sec-head{margin-bottom:clamp(34px,5vw,54px)}
.sec-head .eyebrow{margin-bottom:16px}
.accent{color:var(--accent)}

/* ================= BUTTONS ================= */
.btn{font-family:var(--fm);font-weight:500;font-size:13.5px;letter-spacing:.03em;padding:15px 26px;border-radius:var(--r);display:inline-flex;align-items:center;gap:9px;cursor:pointer;border:1.5px solid transparent;transition:.18s ease;white-space:nowrap}
.btn svg{width:15px;height:15px}
.btn-accent{background:var(--accent);color:#fff}
.btn-accent:hover{background:var(--accent-d)}
.btn-ghost{background:transparent;color:var(--ink);border-color:var(--ink)}
.btn-ghost:hover{background:var(--ink);color:var(--paper)}
.section--dark .btn-ghost{color:var(--d-text);border-color:var(--d-line)}
.section--dark .btn-ghost:hover{background:var(--d-text);color:var(--ink);border-color:var(--d-text)}
.btn-lg{padding:18px 32px;font-size:14.5px}

/* ================= NAV ================= */
.nav{position:sticky;top:0;z-index:60;background:rgba(229,226,214,.9);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.nav__in{display:flex;align-items:center;justify-content:space-between;height:66px}
.brand{font-family:var(--fd);font-weight:700;font-size:20px;letter-spacing:.01em;display:flex;align-items:center;gap:9px}
.brand b{width:9px;height:9px;background:var(--accent);border-radius:50%;display:inline-block}
.nav__links{display:flex;align-items:center;gap:28px}
.nav__links a:not(.btn){font-family:var(--fm);font-size:13px;color:var(--gray);transition:.15s}
.nav__links a:not(.btn):hover{color:var(--ink)}
.nav__toggle{display:none;background:none;border:1.5px solid var(--ink);border-radius:8px;width:44px;height:40px;cursor:pointer;flex-direction:column;gap:5px;align-items:center;justify-content:center}
.nav__toggle span{width:18px;height:1.5px;background:var(--ink)}

/* ================= HERO ================= */
.hero{padding:clamp(44px,6vw,80px) 0 clamp(56px,7vw,90px)}
.hero__grid{display:grid;grid-template-columns:1.35fr .95fr;gap:clamp(32px,5vw,64px);align-items:center}
.chip{display:inline-flex;align-items:center;gap:9px;font-family:var(--fm);font-size:12px;letter-spacing:.04em;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:100px;padding:8px 15px}
.chip b{width:8px;height:8px;border-radius:50%;background:var(--accent);display:inline-block}
.hero h1{font-family:var(--fd);font-weight:700;line-height:.98;font-size:clamp(42px,6.4vw,74px);letter-spacing:-.02em;margin:22px 0 20px}
.hero__sub{font-size:clamp(17px,1.9vw,20px);color:var(--gray);max-width:46ch}
.hero__cta{display:flex;flex-wrap:wrap;gap:14px;margin-top:32px}
.hero__note{font-family:var(--fm);font-size:12.5px;color:var(--gray);margin-top:18px;display:flex;align-items:center;gap:8px}
.hero__note svg{width:15px;height:15px;color:var(--accent)}
/* portrait */
.portrait{position:relative;aspect-ratio:4/5;border-radius:var(--r);background:var(--surface);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;overflow:hidden}
.portrait img{width:100%;height:100%;object-fit:cover}
.portrait__mono{font-family:var(--fd);font-weight:700;font-size:clamp(88px,12vw,140px);color:var(--ink);line-height:1;letter-spacing:-.04em}
.portrait__badge{position:absolute;left:14px;bottom:14px;right:14px;display:flex;justify-content:space-between;align-items:center;background:var(--ink);color:var(--paper);border-radius:8px;padding:11px 14px;font-family:var(--fm);font-size:11.5px;letter-spacing:.04em}
.portrait__badge .dot{color:var(--accent)}

/* ================= BENEFITS BAR ================= */
.bene{background:var(--ink);color:var(--d-text)}
.bene__grid{display:grid;grid-template-columns:repeat(4,1fr)}
.bcell{padding:clamp(22px,2.6vw,32px) clamp(18px,2vw,28px);border-left:1px solid var(--d-line)}
.bcell:first-child{border-left:0;padding-left:0}
.bcell .n{font-family:var(--fm);font-size:12px;color:var(--accent);letter-spacing:.1em}
.bcell .t{font-family:var(--fd);font-weight:600;font-size:clamp(16px,1.6vw,19px);line-height:1.2;margin-top:10px}

/* ================= REALISATIONS ================= */
.projects{display:flex;flex-direction:column;gap:clamp(20px,3vw,32px)}
.project{display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(24px,4vw,52px);align-items:center}
.project:nth-child(even) .shot{order:2}
.shot{border:1px solid var(--line);border-radius:var(--r);overflow:hidden;background:var(--surface);box-shadow:0 1px 0 rgba(35,31,32,.04)}
.shot__bar{display:flex;align-items:center;gap:6px;padding:11px 14px;border-bottom:1px solid var(--line-2);background:#F1EEE7}
.shot__bar i{width:10px;height:10px;border-radius:50%;background:var(--line)}
.shot__bar i:first-child{background:var(--accent)}
.shot__url{font-family:var(--fm);font-size:11px;color:var(--gray);margin-left:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.shot__img{aspect-ratio:16/10;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;background:repeating-linear-gradient(-45deg,#F3F1EA,#F3F1EA 12px,#EFECE3 12px,#EFECE3 24px);color:var(--gray)}
.shot__img img{width:100%;height:100%;object-fit:cover;object-position:top}
.shot__ph{font-family:var(--fd);font-weight:700;font-size:clamp(22px,3vw,30px);color:#C7C1B2}
.shot__phk{font-family:var(--fm);font-size:11px;letter-spacing:.14em;color:#C7C1B2;text-transform:uppercase}
.project__k{font-family:var(--fm);font-size:12px;letter-spacing:.1em;color:var(--accent);text-transform:uppercase}
.project h3{font-family:var(--fd);font-weight:700;font-size:clamp(24px,3vw,34px);line-height:1.03;margin:12px 0 6px}
.project__ctx{font-family:var(--fm);font-size:12.5px;color:var(--gray);text-transform:uppercase;letter-spacing:.02em}
.project p{margin:16px 0 18px;color:var(--ink);max-width:46ch}
.project .tags{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
.tag{font-family:var(--fm);font-size:11.5px;color:var(--gray);border:1px solid var(--line);border-radius:6px;padding:5px 10px;white-space:nowrap}
.plink{font-family:var(--fm);font-size:13px;color:var(--accent);display:inline-flex;align-items:center;gap:8px;font-weight:500}
.plink svg{width:14px;height:14px;transition:transform .2s}
.plink:hover svg{transform:translate(2px,-2px)}

/* ================= OFFRES ================= */
.offers{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.offer{position:relative;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:clamp(28px,3vw,38px)}
.offer--feat{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
.offer__badge{position:absolute;top:-12px;left:clamp(28px,3vw,38px);font-family:var(--fm);font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;background:var(--accent);color:#fff;padding:5px 12px;border-radius:6px}
.offer__top{display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin-bottom:14px}
.offer__n{font-family:var(--fm);font-size:12px;letter-spacing:.14em;color:var(--gray)}
.offer h3{font-family:var(--fd);font-weight:700;font-size:clamp(22px,2.3vw,27px);line-height:1.04}
.offer__promise{font-weight:500;font-size:15.5px;line-height:1.45;margin:10px 0 18px;padding-bottom:18px;border-bottom:1px solid var(--line-2)}
.offer__inc{font-size:14.5px;line-height:1.55;color:var(--gray)}
.offer__inc b{color:var(--ink);font-weight:600;font-family:var(--fm);font-size:11px;letter-spacing:.14em;text-transform:uppercase;display:block;margin-bottom:6px}
.offer__price{margin-top:auto;padding-top:18px}
.offer__price .lbl{font-family:var(--fm);font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--gray)}
.offer__price .amt{font-family:var(--fd);font-weight:700;font-size:clamp(26px,2.6vw,32px);line-height:1.1;margin-top:2px}
.offer__price .amt span{font-family:var(--fb);font-weight:500;font-size:14px;color:var(--gray)}
.offer__price .note{font-family:var(--fm);font-size:11.5px;color:var(--gray);margin-top:6px}
.offer .btn{margin-top:20px;justify-content:center}
.offer--feat .btn-ghost{background:var(--accent);color:#fff;border-color:var(--accent)}
.offer--feat .btn-ghost:hover{background:var(--accent-d);border-color:var(--accent-d)}
.addon{display:flex;align-items:center;flex-wrap:wrap;gap:10px 26px;margin-top:20px;padding:20px clamp(22px,2.4vw,30px);background:var(--surface);border:1px dashed var(--line);border-radius:var(--r)}
.addon .t{font-family:var(--fm);font-size:13px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink);white-space:nowrap}
.addon .t span{color:var(--accent)}
.addon .d{font-size:14px;color:var(--gray);flex:1 1 260px}
.addon .p{font-family:var(--fm);font-size:13px;color:var(--ink);white-space:nowrap}
/* --- Offre sur-mesure (bandeau sombre) --- */
.custom{margin-top:22px;display:grid;grid-template-columns:1fr auto;gap:clamp(20px,3vw,44px);align-items:center;background:var(--ink);color:var(--d-text);border-radius:var(--r);padding:clamp(28px,3.4vw,42px)}
.custom__k{font-family:var(--fm);font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);display:inline-flex;align-items:center;gap:10px}
.custom__k::before{content:"";width:20px;height:2px;background:var(--accent)}
.custom h3{font-family:var(--fd);font-weight:700;font-size:clamp(23px,2.6vw,31px);line-height:1.04;margin:12px 0 10px;color:var(--d-text)}
.custom p{font-size:15px;line-height:1.55;color:var(--d-dim);max-width:58ch}
.custom .btn-accent{background:var(--accent);color:#fff}
.custom .btn-accent:hover{background:var(--accent-d)}
/* --- Maintenance mensuelle --- */
.maint{margin-top:clamp(52px,7vw,84px)}
.maint__head{max-width:660px;margin-bottom:clamp(28px,4vw,40px)}
.maint__head .eyebrow{margin-bottom:14px}
.maint__head h3{font-family:var(--fd);font-weight:700;font-size:clamp(26px,3.4vw,38px);line-height:1.03;letter-spacing:-.01em}
.maint__head p{color:var(--gray);margin-top:12px;font-size:16px;line-height:1.55}
.plans{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;max-width:860px}
.plan{position:relative;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:clamp(26px,2.8vw,34px)}
.plan--feat{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
.plan__badge{position:absolute;top:-12px;left:clamp(26px,2.8vw,34px);font-family:var(--fm);font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;background:var(--accent);color:#fff;padding:5px 12px;border-radius:6px}
.plan__name{font-family:var(--fd);font-weight:700;font-size:clamp(20px,2vw,23px)}
.plan__price{margin:12px 0 3px;font-family:var(--fd);font-weight:700;font-size:clamp(30px,3.4vw,38px);line-height:1;letter-spacing:-.02em}
.plan__price span{font-family:var(--fb);font-weight:500;font-size:15px;color:var(--gray);letter-spacing:0}
.plan__for{font-family:var(--fm);font-size:12px;color:var(--gray);letter-spacing:.02em;padding-bottom:18px;border-bottom:1px solid var(--line-2)}
.plan__list{list-style:none;margin:18px 0 0;padding:0;display:flex;flex-direction:column;gap:11px;flex:1}
.plan__list li{position:relative;padding-left:26px;font-size:14.5px;line-height:1.45;color:var(--ink)}
.plan__list li::before{content:"";position:absolute;left:2px;top:3px;width:13px;height:8px;border-left:2px solid var(--accent);border-bottom:2px solid var(--accent);transform:rotate(-45deg)}
.plan__packs{margin-top:18px;border-top:1px solid var(--line-2);padding-top:16px;display:flex;flex-direction:column;gap:10px}
.plan__packs .pk-lbl{font-family:var(--fm);font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--gray)}
.plan__pack{display:flex;justify-content:space-between;align-items:baseline;gap:12px;font-family:var(--fm);font-size:14px}
.plan__pack .a{color:var(--ink)}
.plan__pack .b{color:var(--ink);font-weight:500;white-space:nowrap}
.plan__pack .b small{color:var(--accent);font-size:11.5px;margin-left:7px;font-weight:500}
.plan .btn{margin-top:22px;justify-content:center}
.plan--feat .btn-ghost{background:var(--accent);color:#fff;border-color:var(--accent)}
.plan--feat .btn-ghost:hover{background:var(--accent-d);border-color:var(--accent-d)}
.maint__note{font-family:var(--fm);font-size:12.5px;color:var(--gray);margin-top:24px;display:flex;align-items:center;gap:10px}
.maint__note::before{content:"";width:22px;height:1px;background:var(--accent);flex:none}

/* ================= APPROCHE ================= */
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:clamp(20px,3vw,30px)}
.step{border-top:2px solid var(--accent);padding-top:20px}
.step .n{font-family:var(--fm);font-size:13px;color:var(--accent);letter-spacing:.08em}
.step h3{font-family:var(--fd);font-weight:700;font-size:22px;margin:12px 0 10px;color:var(--d-text)}
.step p{font-size:15.5px;color:var(--d-dim);line-height:1.55}
.stack{display:flex;flex-wrap:wrap;gap:9px;margin-top:clamp(38px,5vw,56px)}
.section--dark .tag{color:var(--d-dim);border-color:var(--d-line)}

/* ================= TEMOIGNAGES ================= */
.quotes{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.quote{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:clamp(24px,2.6vw,30px);display:flex;flex-direction:column}
.quote__mark{font-family:var(--fd);font-weight:700;font-size:44px;line-height:.6;color:var(--accent)}
.quote p{margin:16px 0 20px;font-size:15.5px;line-height:1.55}
.quote__by{margin-top:auto;font-family:var(--fm);font-size:12px;color:var(--gray);letter-spacing:.02em}
.quote__by b{display:block;color:var(--ink);font-weight:600;font-size:13px;margin-bottom:2px}

/* ================= FAQ ================= */
.faq{max-width:820px}
.faq__item{border-bottom:1px solid var(--line)}
.faq__q{width:100%;text-align:left;background:none;border:0;cursor:pointer;padding:22px 0;display:flex;justify-content:space-between;align-items:center;gap:20px;font-family:var(--fd);font-weight:600;font-size:clamp(17px,1.8vw,20px);color:var(--ink)}
.faq__q .ic{flex:none;width:22px;height:22px;position:relative}
.faq__q .ic::before,.faq__q .ic::after{content:"";position:absolute;background:var(--accent);border-radius:2px;transition:.2s;top:50%;left:50%;transform:translate(-50%,-50%)}
.faq__q .ic::before{width:14px;height:2px}
.faq__q .ic::after{width:2px;height:14px}
.faq__item.open .ic::after{transform:translate(-50%,-50%) scaleY(0)}
.faq__a{max-height:0;overflow:hidden;transition:max-height .3s ease}
.faq__a p{padding:0 0 22px;color:var(--gray);font-size:15.5px;line-height:1.6;max-width:70ch}

/* ================= CONTACT / CTA ================= */
.cta__grid{display:grid;grid-template-columns:1fr .9fr;gap:clamp(34px,6vw,72px);align-items:start}
.cta h2{font-size:clamp(32px,4.6vw,54px);margin-bottom:16px}
.manifest{margin-top:28px;border-top:1px solid var(--d-line)}
.mrow{display:grid;grid-template-columns:110px 1fr;gap:16px;padding:13px 0;border-bottom:1px solid var(--d-line);align-items:baseline}
.mrow .mk{font-family:var(--fm);font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--accent)}
.mrow .mv{font-family:var(--fm);font-size:14.5px;color:var(--d-text);word-break:break-word}
.mrow .mv a:hover{color:var(--accent)}
.form{display:flex;flex-direction:column;gap:16px;background:rgba(255,255,255,.03);border:1px solid var(--d-line);border-radius:var(--r);padding:clamp(22px,3vw,30px)}
.field label{font-family:var(--fm);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--d-dim);display:block;margin-bottom:8px}
.field input,.field textarea{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--d-line);border-radius:8px;color:var(--d-text);font-family:var(--fb);font-size:16px;padding:12px 14px;transition:.2s}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--accent)}
.field textarea{resize:vertical;min-height:110px}

/* ================= FOOTER ================= */
.footer{background:var(--d-bg);color:var(--d-dim);padding:36px 0 30px;border-top:1px solid var(--d-line)}
.footer__in{display:flex;flex-wrap:wrap;gap:18px;justify-content:space-between;align-items:center}
.footer__b{font-family:var(--fd);font-weight:700;color:var(--d-text);font-size:16px}
.footer__l{display:flex;gap:20px;flex-wrap:wrap}
.footer__l a{font-family:var(--fm);font-size:12px;color:var(--d-dim)}
.footer__l a:hover{color:var(--accent)}
.footer__meta{width:100%;margin-top:22px;padding-top:20px;border-top:1px solid var(--d-line);font-family:var(--fm);font-size:11.5px;color:var(--d-dim);display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}

/* ================= REVEAL ================= */
.reveal{opacity:0;transform:translateY(14px);transition:opacity .6s ease,transform .6s ease}
.reveal.in{opacity:1;transform:none}

/* ================= RESPONSIVE ================= */
@media (max-width:920px){
  .hero__grid,.cta__grid{grid-template-columns:1fr;gap:36px}
  .hero__card{max-width:380px}
  .project,.project:nth-child(even) .shot{grid-template-columns:1fr;order:0}
  .project .shot{order:0!important}
  .offers,.quotes,.steps,.plans{grid-template-columns:1fr 1fr}
  .custom{grid-template-columns:1fr;gap:22px}
  .custom .btn{width:100%;justify-content:center}
  .bene__grid{grid-template-columns:repeat(2,1fr)}
  .bcell:nth-child(2n+1){border-left:0;padding-left:0}
  .bcell:nth-child(n+3){border-top:1px solid var(--d-line)}
  .steps{gap:24px}
}
@media (max-width:640px){
  .nav__links{position:absolute;top:66px;left:0;right:0;background:var(--paper);border-bottom:1px solid var(--line);flex-direction:column;align-items:flex-start;gap:0;padding:8px 24px 20px;display:none}
  .nav__links.open{display:flex}
  .nav__links a:not(.btn){width:100%;padding:13px 0;border-bottom:1px solid var(--line)}
  .nav__links .btn{margin-top:14px;width:100%;justify-content:center}
  .nav__toggle{display:flex}
  .offers,.quotes,.steps,.plans{grid-template-columns:1fr}
  .mrow{grid-template-columns:84px 1fr;gap:12px}
}
@media (prefers-reduced-motion:reduce){
  html{scroll-behavior:auto}
  .reveal{opacity:1!important;transform:none!important}
  *{transition:none!important}
}

/* ===== Projets : rôle + liens (repris du portfolio) ===== */
.project__role{font-size:14.5px;color:var(--gray);margin:-6px 0 16px;line-height:1.5}
.project__role b{color:var(--ink);font-family:var(--fm);font-size:11px;letter-spacing:.12em;text-transform:uppercase;margin-right:8px}
.project__links{display:flex;flex-wrap:wrap;gap:12px 22px}

/* ===== Autres projets (mini-grid) ===== */
.subhead{font-family:var(--fm);font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--gray);margin:clamp(38px,5vw,56px) 0 22px;display:flex;align-items:center;gap:14px}
.subhead::after{content:"";flex:1;height:1px;background:var(--line)}
.mini-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
.mini{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:clamp(22px,2.4vw,28px);display:flex;flex-direction:column;transition:border-color .2s}
.mini:hover{border-color:var(--ink)}
.mini__k{font-family:var(--fm);font-size:11px;letter-spacing:.12em;color:var(--accent);text-transform:uppercase}
.mini h3{font-family:var(--fd);font-weight:700;font-size:22px;line-height:1.05;margin:9px 0 8px}
.mini p{font-size:14.5px;color:var(--gray);line-height:1.5;margin-bottom:14px}
.mini .tags{margin-top:auto;margin-bottom:16px}

/* ===== Champs select du formulaire ===== */
.field select{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--d-line);border-radius:8px;color:var(--d-text);font-family:var(--fb);font-size:16px;padding:12px 40px 12px 14px;transition:.2s;appearance:none;-webkit-appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23AEA99C' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:18px}
.field select:focus{outline:none;border-color:var(--accent)}
.field select option{background:var(--d-bg);color:var(--d-text)}
.form__status{font-family:var(--fm);font-size:13px;line-height:1.5;padding:12px 14px;border-radius:8px;display:none}
.form__status.ok{display:block;background:rgba(240,69,30,.10);border:1px solid var(--accent);color:var(--d-text)}
.form__status.err{display:block;background:rgba(255,255,255,.04);border:1px solid var(--d-line);color:var(--d-dim)}

@media (max-width:640px){.mini-grid{grid-template-columns:1fr}}

/* ===== CTA sticky mobile ===== */
.sticky-cta{display:none}
@media (max-width:640px){
  body{padding-bottom:74px}
  .sticky-cta{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:70;padding:10px 16px calc(10px + env(safe-area-inset-bottom));background:rgba(229,226,214,.95);backdrop-filter:blur(8px);border-top:1px solid var(--line)}
  .sticky-cta .btn{flex:1;justify-content:center}
}

/* ===== Captcha Altcha (thème sombre du formulaire) ===== */
altcha-widget{display:block;margin:2px 0;--altcha-max-width:100%;--altcha-border-radius:8px;--altcha-border-width:1px;--altcha-color-base:rgba(255,255,255,.04);--altcha-color-border:var(--d-line);--altcha-color-border-focus:var(--accent);--altcha-color-text:var(--d-text);--altcha-color-footer-bg:transparent}

/* ===== Hébergement (3 cartes) ===== */
.hosting{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
@media (max-width:920px){.hosting{grid-template-columns:1fr}}

/* ===== Onglets des offres (CSS pur) ===== */
.tabs{position:relative}
.tabs>input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.tabs__nav{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:clamp(28px,4vw,44px)}
.tabs__nav label{font-family:var(--fm);font-size:13.5px;letter-spacing:.02em;padding:12px 20px;border:1.5px solid var(--line);border-radius:8px;cursor:pointer;color:var(--gray);background:var(--surface);transition:.15s;white-space:nowrap}
.tabs__nav label:hover{color:var(--ink);border-color:var(--ink)}
.tabs__panel{display:none}
#tab-crea:checked~.tabs__nav label[for="tab-crea"],#tab-heb:checked~.tabs__nav label[for="tab-heb"],#tab-maint:checked~.tabs__nav label[for="tab-maint"]{background:var(--accent);border-color:var(--accent);color:#fff}
#tab-crea:checked~#panel-crea,#tab-heb:checked~#panel-heb,#tab-maint:checked~#panel-maint{display:block}
.tabs__panel .reveal{opacity:1;transform:none}

/* ===== Estimation (configurateur d'offre) ===== */
.estimate{background:rgba(240,69,30,.10);border:1px solid var(--accent);border-radius:10px;padding:16px 18px;margin-bottom:4px}
.estimate__t{font-family:var(--fm);font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);margin-bottom:10px}
.estimate__row{display:flex;justify-content:space-between;gap:12px;font-size:13.5px;color:var(--d-dim);padding:3px 0}
.estimate__row b{color:var(--d-text);font-weight:600;text-align:right}
.estimate__total{margin-top:10px;padding-top:10px;border-top:1px solid var(--d-line);font-family:var(--fd);font-weight:700;font-size:18px;color:var(--d-text)}
.estimate__note{font-family:var(--fm);font-size:11px;color:var(--d-dim);margin-top:8px;line-height:1.5}
</style>
<link rel="stylesheet" href="<?= asset('/css/studio.css') ?>">
<!-- Animations : GSAP auto-hébergé (CSP 'self'), voir js/motion/ -->
<link rel="stylesheet" href="<?= asset('/css/motion.css') ?>">
<script src="<?= asset('/js/motion/init.js') ?>"></script>
<script defer src="<?= asset('/js/lib/gsap.min.js') ?>"></script>
<script defer src="<?= asset('/js/lib/ScrollTrigger.min.js') ?>"></script>
<script defer src="<?= asset('/js/lib/SplitText.min.js') ?>"></script>
<script defer src="<?= asset('/js/motion/core.js') ?>"></script>
<script defer src="<?= asset('/js/motion/site.js') ?>"></script>
</head>
<body>

<!-- ============ NAV ============ -->
<header class="nav">
  <div class="wrap nav__in">
    <a href="#top" class="brand"><b></b>Birostweb</a>
    <nav class="nav__links" id="menu">
      <a href="#realisations">Réalisations</a>
      <a href="#offres">Offres</a>
      <a href="#approche">Approche</a>
      <a href="#faq">FAQ</a>
      <a href="#contact" class="btn btn-accent">Devis gratuit</a>
    </nav>
    <button class="nav__toggle" id="toggle" aria-label="Menu" aria-expanded="false"><span></span><span></span><span></span></button>
  </div>
</header>

<main id="top">

<!-- ============ HERO ============ -->
<section class="hero">
  <div class="wrap hero__grid">
    <div>
      <span class="chip"><b></b>Disponible · Devis sous 48h</span>
      <h1>Un site web qui vous ressemble, et qui <span class="accent">vous ramène des clients</span>.</h1>
      <p class="hero__sub">Je suis développeur web indépendant, en télétravail partout en France. Je crée des sites sur-mesure, soignés et rapides, et je m'occupe de tout : du premier échange jusqu'à la mise en ligne. Sans jargon inutile, sans mauvaise surprise.</p>
      <div class="hero__cta">
        <a href="#contact" class="btn btn-accent btn-lg">Demander un devis gratuit
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
        <a href="#realisations" class="btn btn-ghost btn-lg">Voir mes réalisations</a>
      </div>
      <p class="hero__note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
        Dernier projet livré : le site du Forum Hydrogen Business for Climate.
      </p>
    </div>
    <aside class="hero__card">
      <!-- Remplace le bloc .portrait par : <div class="portrait"><img src="ta-photo.jpg" alt="Théo Birost"></div> -->
      <div class="portrait">
        <span class="portrait__mono">TB</span>
        <div class="portrait__badge"><span>Théo Birost</span><span class="dot">● France · Remote</span></div>
      </div>
    </aside>
  </div>
</section>

<!-- ============ BENEFITS ============ -->
<div class="bene">
  <div class="wrap bene__grid">
    <div class="bcell"><span class="n">01</span><div class="t">Un seul interlocuteur, du début à la fin</div></div>
    <div class="bcell"><span class="n">02</span><div class="t">Livré en 2 à 4 semaines</div></div>
    <div class="bcell"><span class="n">03</span><div class="t">Rapide, sur mobile comme sur Google</div></div>
    <div class="bcell"><span class="n">04</span><div class="t">Un prix clair, annoncé dès le départ</div></div>
  </div>
</div>

<!-- ============ REALISATIONS ============ -->
<section class="section section--paper" id="realisations">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Réalisations</span>
      <h2 class="h2 reveal">Des projets réels, en ligne pour de vrai.</h2>
      <p class="lead reveal">Du site client livré en production aux applis que je construis moi-même : un aperçu concret de ce que je sais faire.</p>
    </div>
    <div class="projects">

      <article class="project reveal">
        <div class="shot">
          <div class="shot__bar"><i></i><i></i><i></i><span class="shot__url">maisondubonheurstesavine.fr</span></div>
          <div class="shot__img"><img src="<?= asset('/img/maisondubonheur.webp') ?>" alt="Maison du Bonheur — site de réservation" width="1400" height="875" loading="lazy" decoding="async"></div>
        </div>
        <div>
          <span class="project__k">Projet client · Stage</span>
          <h3>Maison du Bonheur</h3>
          <span class="project__ctx">Site de réservation · Deux logements touristiques</span>
          <p>Le site vitrine de deux appartements meublés à Troyes et Sainte-Savine. J'ai mis en avant les logements, les avis des voyageurs et l'accueil de l'hôtesse, avec un parcours clair qui mène jusqu'à la réservation. Un site rapide et soigné, pensé pour donner envie de poser ses valises.</p>
          <p class="project__role"><b>Mon rôle</b>Seul développeur, de la conception à la mise en ligne, pendant mon stage à la SCI Birost.</p>
          <div class="tags"><span class="tag">Vue 3</span><span class="tag">Vite</span><span class="tag">JavaScript</span><span class="tag">SEO</span></div>
          <div class="project__links">
            <a class="plink" href="https://maisondubonheurstesavine.fr/" target="_blank" rel="noopener">Voir le site
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
          </div>
        </div>
      </article>

      <article class="project reveal">
        <div class="shot">
          <div class="shot__bar"><i></i><i></i><i></i><span class="shot__url">hydrogenbusinessforclimate.com</span></div>
          <div class="shot__img"><img src="<?= asset('/img/hydrogen_website.webp') ?>" alt="Site du Forum Hydrogen Business for Climate" width="1400" height="804" loading="lazy" decoding="async"></div>
        </div>
        <div>
          <span class="project__k">Projet client · Stage</span>
          <h3>Forum Hydrogen Business for Climate</h3>
          <span class="project__ctx">Réalisé pour AER BFC · Événement B2B international</span>
          <p>Refonte intégrale du site d'un forum international de la filière hydrogène : bascule bilingue FR/EN, blocs sur-mesure (intervenants, exposants, presse) et pages optimisées pour charger vite malgré un contenu dense.</p>
          <p class="project__role"><b>Mon rôle</b>Seul développeur sur le projet, de l'intégration au déploiement, pendant mon stage chez AER BFC.</p>
          <div class="tags"><span class="tag">WordPress</span><span class="tag">ACF</span><span class="tag">CPT UI</span><span class="tag">TranslatePress</span><span class="tag">PHP</span><span class="tag">SEO</span></div>
          <div class="project__links">
            <a class="plink" href="https://www.hydrogenbusinessforclimate.com" target="_blank" rel="noopener">Voir le site en ligne
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
          </div>
        </div>
      </article>

      <article class="project reveal">
        <div class="shot">
          <div class="shot__bar"><i></i><i></i><i></i><span class="shot__url">generique.theo-birost.fr</span></div>
          <div class="shot__img"><img src="<?= asset('/img/generique.webp') ?>" alt="Générique — index de cinéma" width="1400" height="875" loading="lazy" decoding="async"></div>
        </div>
        <div>
          <span class="project__k">Application web · Full-stack</span>
          <h3>Générique</h3>
          <span class="project__ctx">Index de cinéma · Recherche &amp; fiches détaillées</span>
          <p>Une application pour explorer un index de films, d'interprètes et de réalisateurs : recherche instantanée, fiches détaillées et données à jour. Une base full-stack complète, du front jusqu'à la logique serveur.</p>
          <p class="project__role"><b>Mon rôle</b>Conception et développement complet, du front à l'API.</p>
          <div class="tags"><span class="tag">Vue 3</span><span class="tag">Vite</span><span class="tag">JavaScript</span><span class="tag">API</span></div>
          <div class="project__links">
            <a class="plink" href="https://generique.theo-birost.fr" target="_blank" rel="noopener">Voir le projet
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
          </div>
        </div>
      </article>

      <article class="project reveal">
        <div class="shot">
          <div class="shot__bar"><i></i><i></i><i></i><span class="shot__url">clicker — jeu</span></div>
          <div class="shot__img"><img src="<?= asset('/img/clicker_img.webp') ?>" alt="Jeu du clicker" width="1400" height="741" loading="lazy" decoding="async"></div>
        </div>
        <div>
          <span class="project__k">Projet perso · Solo</span>
          <h3>Jeu du clicker</h3>
          <span class="project__ctx">Jeu de clic old-school · Système de progression</span>
          <p>Un petit jeu de clic old-school : on accumule des points, on débloque des améliorations et on grimpe au classement. Front, back et déploiement Docker faits maison.</p>
          <p class="project__role"><b>Mon rôle</b>Conception et développement complet, du front au back.</p>
          <div class="tags"><span class="tag">HTML</span><span class="tag">Tailwind</span><span class="tag">PHP</span><span class="tag">Docker</span></div>
          <div class="project__links">
            <a class="plink" href="https://portfolio.theo-birost.fr/clicker/" target="_blank" rel="noopener">Voir le projet
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
          </div>
        </div>
      </article>

    </div>

    <div class="subhead reveal">Autres projets — perso</div>
    <div class="mini-grid reveal">
      <article class="mini">
        <span class="mini__k">Défi perso · Intégration</span>
        <h3>One Page Challenge</h3>
        <p>Un défi que je me lance : concevoir et coder une landing page complète en une heure, from scratch. Quatre pages à ce jour — e-commerce, voyage, tech et SaaS.</p>
        <div class="tags"><span class="tag">Vue 3</span><span class="tag">Vite</span><span class="tag">Tailwind</span><span class="tag">HTML</span></div>
        <a class="plink" href="https://onepage.birostweb.fr" target="_blank" rel="noopener">Voir le projet <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
      </article>
      <article class="mini">
        <span class="mini__k">Jeu web · Solo</span>
        <h3>Empire Culturel</h3>
        <p>Jeu incrémental de collection de monuments : farm, boosters, arbre technologique et classement. Parties sauvegardées en local.</p>
        <div class="tags"><span class="tag">Vue 3</span><span class="tag">Tailwind</span><span class="tag">JavaScript</span></div>
        <a class="plink" href="https://carte.theo-birost.fr" target="_blank" rel="noopener">Voir le projet <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
      </article>
      <article class="mini">
        <span class="mini__k">Projet perso · Solo</span>
        <h3>Sidequest</h3>
        <p>Une collection de mini-jeux à découvrir dans le navigateur : dactylographie, lancer de dés, morpion et Snake.</p>
        <div class="tags"><span class="tag">Mini-jeux</span><span class="tag">Jeu web</span></div>
        <a class="plink" href="https://jeu.birostweb.fr/" target="_blank" rel="noopener">Voir le projet <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
      </article>
      <article class="mini">
        <span class="mini__k">Projet perso · Solo</span>
        <h3>Portfolio template</h3>
        <p>Un template de portfolio en Tailwind et PHP, multilingue FR/EN, pensé pour être facile à reprendre et à adapter.</p>
        <div class="tags"><span class="tag">Tailwind</span><span class="tag">PHP</span><span class="tag">JavaScript</span></div>
        <a class="plink" href="https://portfolio.theo-birost.fr" target="_blank" rel="noopener">Voir le projet <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
      </article>
    </div>
  </div>
</section>

<!-- Expériences professionnelles : missions et dates issues des conventions de stage. -->
<section class="section section--paper" id="parcours">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Parcours</span>
      <h2 class="h2 reveal">Deux expériences, des besoins concrets.</h2>
      <p class="lead reveal">Avant Birostweb, deux stages dans le cadre de mon BUT MMI m'ont permis de travailler sur des projets web et de communication.</p>
    </div>
    <div class="mini-grid reveal">
      <article class="mini">
        <span class="mini__k">22 juin – 15 août 2026 · 8 semaines</span>
        <h3>SCI BIROST</h3>
        <p>Stage en développement web et communication digitale. Mission : création d'un site de réservation pour deux logements touristiques, gestion de leur communication digitale et participation à leur promotion publicitaire.</p>
      </article>
      <article class="mini">
        <span class="mini__k">20 avril – 19 juin 2026 · 9 semaines</span>
        <h3>AER BFC</h3>
        <p>Stage à l'Agence économique régionale de Bourgogne-Franche-Comté, à Dijon. Mission : contribuer à la refonte d'un site internet, avec un travail sur le Forum Hydrogen Business for Climate présenté dans mes réalisations.</p>
      </article>
    </div>
  </div>
</section>

<!-- ============ OFFRES ============ -->
<section class="section section--paper" id="offres" style="background:#DEDACD">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Offres &amp; tarifs</span>
      <h2 class="h2 reveal">Des offres claires, un prix de départ affiché.</h2>
      <p class="lead reveal">Ce sont des prix de départ, juste pour vous donner un ordre d'idée. Le périmètre, la techno et le contenu se décident ensemble, et vous avez un devis précis avant de vous engager.</p>
    </div>

    <div class="tabs">
      <input type="radio" name="offtab" id="tab-crea" checked>
      <input type="radio" name="offtab" id="tab-heb">
      <input type="radio" name="offtab" id="tab-maint">
      <div class="tabs__nav">
        <label for="tab-crea">Création de site</label>
        <label for="tab-heb">Hébergement</label>
        <label for="tab-maint">Maintenance</label>
      </div>

      <div class="tabs__panel" id="panel-crea">
    <div class="offers">

      <article class="offer offer--feat">
        <span class="offer__badge">Le plus demandé</span>
        <div class="offer__top"><span class="offer__n">OFFRE 01</span><span class="tag">Site vitrine</span></div>
        <h3>Site vitrine</h3>
        <p class="offer__promise">Un site propre et rapide pour présenter votre activité et donner confiance. Codé sur-mesure ou sur un outil que vous pourrez mettre à jour vous-même : on choisit ensemble ce qui vous convient le mieux.</p>
        <div class="offer__inc"><b>Inclus</b>Design, intégration responsive, référencement de base et mise en ligne. Le périmètre exact est détaillé dans le devis.</div>
        <div class="offer__price"><div class="lbl">À partir de</div><div class="amt">999 €</div><div class="note">Version multilingue ou à gérer soi-même : sur devis</div></div>
        <a href="#contact" class="btn btn-ghost" data-step="offre" data-label="Site vitrine" data-oneoff="999" data-form="Offre 1 — Site vitrine" data-next="tab-heb">Choisir le site vitrine</a>
      </article>

      <article class="offer">
        <div class="offer__top"><span class="offer__n">OFFRE 02</span><span class="tag">Boutique en ligne</span></div>
        <h3>Boutique en ligne</h3>
        <p class="offer__promise">Une boutique claire et rapide, où vos clients trouvent et commandent sans se compliquer la vie. Sur une base e-commerce solide ou entièrement sur-mesure, selon vos besoins.</p>
        <div class="offer__inc"><b>Inclus</b>Catalogue, paiement sécurisé et parcours d'achat, avec un back-office simple à gérer. Le détail est calé dans le devis.</div>
        <div class="offer__price"><div class="lbl">À partir de</div><div class="amt">1 899 €</div><div class="note">Version 100 % sur-mesure : sur devis</div></div>
        <a href="#contact" class="btn btn-ghost" data-step="offre" data-label="Boutique en ligne" data-oneoff="1899" data-form="Offre 2 — Boutique en ligne" data-next="tab-heb">Choisir la boutique</a>
      </article>

      <article class="offer">
        <div class="offer__top"><span class="offer__n">OFFRE 03</span><span class="tag">Application · Full-stack</span></div>
        <h3>Application web</h3>
        <p class="offer__promise">Quand un simple site ne suffit plus : un outil construit autour de votre façon de travailler, pour vous faire gagner du temps au quotidien.</p>
        <div class="offer__inc"><b>Inclus</b>Cadrage du besoin, design, développement et mise en ligne. Le périmètre précis est défini ensemble dans le devis.</div>
        <div class="offer__price"><div class="lbl">À partir de</div><div class="amt">2 999 €</div><div class="note">Chiffré précisément selon le périmètre</div></div>
        <a href="#contact" class="btn btn-ghost" data-step="offre" data-label="Application web" data-oneoff="2999" data-form="Offre 3 — Application web" data-next="tab-heb">Choisir l'application</a>
      </article>

    </div>

    <!-- Offre sur-mesure / sur demande -->
    <div class="custom reveal">
      <div>
        <span class="custom__k">Projet sur-mesure</span>
        <h3>Un besoin qui ne rentre pas dans une case&nbsp;?</h3>
        <p>Refonte, migration, coup de main pour une agence, outil métier un peu particulier, ou idée encore floue à cadrer… Écrivez-moi ce que vous avez en tête : je regarde ce qui est possible et vous recevez un devis clair sous 48h, sans engagement.</p>
      </div>
      <a href="#contact" class="btn btn-accent btn-lg">Demander un devis gratuit
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </div>
      </div><!-- /panel Création -->

      <!-- Onglet Hébergement -->
      <div class="tabs__panel" id="panel-heb">
      <div class="maint__head">
        <h3>Où vit votre site&nbsp;?</h3>
        <p>Deux possibilités : vous gardez la main sur votre hébergement, ou je m'occupe de tout, serveur compris.</p>
      </div>
      <div class="hosting">

        <article class="plan">
          <div class="plan__name">Votre hébergement</div>
          <div class="plan__price">Gratuit</div>
          <div class="plan__for">Vous gardez la main</div>
          <ul class="plan__list">
            <li>Déployé sur votre VPS ou votre hébergeur</li>
            <li>Vous en êtes 100 % propriétaire</li>
            <li>Vous payez votre hébergeur directement (~5 à 15 €/mois)</li>
            <li>Aucun abonnement chez moi</li>
            <li>Maintenance en option (39 €/mois)</li>
          </ul>
          <a href="#contact" class="btn btn-ghost" data-step="heb" data-label="Sur mon propre hébergement" data-mo="0" data-form="Sur mon propre serveur / hébergeur" data-next="tab-maint">Choisir cette option</a>
        </article>

        <article class="plan plan--feat">
          <span class="plan__badge">Le plus simple</span>
          <div class="plan__name">Essentiel · VPS-1</div>
          <div class="plan__price">45 €<span> / mois</span></div>
          <div class="plan__for">Site vitrine · tout compris</div>
          <ul class="plan__list">
            <li>Serveur 2 vCœurs · 4 Go RAM · 40 Go SSD</li>
            <li>Mise en ligne, configuration &amp; HTTPS</li>
            <li>Mises à jour &amp; sécurité</li>
            <li>Sauvegardes quotidiennes &amp; supervision</li>
            <li>Support par email</li>
            <li>Tout compris — rien d'autre à payer</li>
          </ul>
          <a href="#contact" class="btn btn-ghost" data-step="heb" data-label="Essentiel · VPS-1 (tout compris)" data-mo="45" data-form="Essentiel · VPS-1 (45 €/mois)" data-included="1">Choisir Essentiel</a>
        </article>

        <article class="plan">
          <div class="plan__name">Pro · VPS-2</div>
          <div class="plan__price">69 €<span> / mois</span></div>
          <div class="plan__for">Boutique &amp; applications · tout compris</div>
          <ul class="plan__list">
            <li>Serveur 4 vCœurs · 8 Go RAM · 75 Go SSD</li>
            <li>Tout ce qui est inclus dans Essentiel</li>
            <li>Ressources pour un trafic plus élevé</li>
            <li>Support prioritaire</li>
          </ul>
          <a href="#contact" class="btn btn-ghost" data-step="heb" data-label="Pro · VPS-2 (tout compris)" data-mo="69" data-form="Pro · VPS-2 (69 €/mois)" data-included="1">Choisir Pro</a>
        </article>

      </div>
      <p class="maint__note"><span>Les offres <b>Essentiel</b> et <b>Pro</b> sont <b>tout compris</b> : hébergement, mises à jour, sécurité, sauvegardes et support, sans surcoût. Si vous préférez héberger vous-même, la maintenance reste en option (onglet Maintenance).</span></p>
    </div>

      <!-- Onglet Maintenance -->
      <div class="tabs__panel" id="panel-maint">
      <div class="maint__head">
        <h3>Maintenance mensuelle, sans y penser.</h3>
        <p>Un site vit : il faut le garder à jour, sauvegardé et sécurisé. Prenez le niveau de suivi qui vous va, et changez ou arrêtez quand vous voulez.</p>
      </div>
      <div class="plans">

        <article class="plan plan--feat">
          <span class="plan__badge">Recommandé</span>
          <div class="plan__name">Suivi mensuel</div>
          <div class="plan__price">39 €<span> / mois</span></div>
          <div class="plan__for">Un site à jour, sûr et toujours en ligne</div>
          <ul class="plan__list">
            <li>Mises à jour &amp; sécurité</li>
            <li>Sauvegardes automatiques</li>
            <li>Surveillance de disponibilité</li>
            <li>Petites corrections incluses</li>
            <li>Support par email</li>
          </ul>
          <a href="#contact" class="btn btn-ghost" data-maint="suivi" data-step="maint" data-label="Suivi mensuel (39 €/mois)" data-mo="39" data-form="Suivi mensuel (39 €/mois)">Choisir le suivi mensuel</a>
        </article>

        <article class="plan">
          <div class="plan__name">Pack d'heures</div>
          <div class="plan__price">39 €<span> / heure</span></div>
          <div class="plan__for">Des modifs &amp; évolutions quand vous voulez</div>
          <ul class="plan__list">
            <li>Modifications, contenu &amp; nouvelles pages</li>
            <li>Corrections et petits développements</li>
            <li>Sans abonnement, à la carte</li>
            <li>+ toutes les options du suivi mensuel</li>
          </ul>
          <div class="plan__packs">
            <span class="pk-lbl">Packs dégressifs</span>
            <div class="plan__pack"><span class="a">Pack 5 h</span><span class="b">175 €<small>35 €/h</small></span></div>
            <div class="plan__pack"><span class="a">Pack 10 h</span><span class="b">320 €<small>32 €/h</small></span></div>
          </div>
          <a href="#contact" class="btn btn-ghost" data-maint="heures" data-step="maint" data-label="Pack d'heures" data-mo="0" data-form="Pack d'heures">Choisir le pack d'heures</a>
        </article>

      </div>
      <p class="maint__note">Sans engagement — le suivi mensuel s'arrête quand vous voulez, et les heures d'un pack restent valables 24 mois.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============ APPROCHE ============ -->
<section class="section section--dark" id="approche">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Approche</span>
      <h2 class="h2 reveal">Simple, cadré, sans mauvaise surprise.</h2>
      <p class="lead reveal">Du premier échange à la mise en ligne, vous savez toujours où on en est.</p>
    </div>
    <div class="steps reveal">
      <div class="step"><span class="n">01</span><h3>On échange</h3><p>On prend le temps de parler de votre projet, de vos objectifs et de votre budget. Vous recevez un devis clair sous 48h, sans engagement.</p></div>
      <div class="step"><span class="n">02</span><h3>Design &amp; développement</h3><p>On valide les maquettes ensemble, puis je code proprement, sur-mesure. Rien n'avance sans votre feu vert.</p></div>
      <div class="step"><span class="n">03</span><h3>Mise en ligne &amp; suivi</h3><p>Mise en ligne, tests, et un petit tour du propriétaire pour que vous soyez à l'aise. Ensuite, je reste là pour faire évoluer votre site.</p></div>
    </div>
    <div class="stack reveal">
      <span class="tag">Vue 3</span><span class="tag">Vite</span><span class="tag">JavaScript</span><span class="tag">Tailwind</span><span class="tag">Node.js</span><span class="tag">MySQL</span><span class="tag">PHP</span><span class="tag">WordPress</span><span class="tag">Docker</span><span class="tag">Figma</span>
    </div>
  </div>
</section>

<!-- ============ TEMOIGNAGES ============ -->
<!-- Section masquée en attendant de vrais avis clients. Pour la réafficher : retirer style="display:none". -->
<section class="section section--paper" style="display:none">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Ils me font confiance</span>
      <h2 class="h2 reveal">Ce qu'en disent mes clients.</h2>
    </div>
    <!-- Remplace le texte de ces cartes par de vrais avis dès que tu en as (voir plan de com). -->
    <div class="quotes reveal">
      <div class="quote"><span class="quote__mark">“</span><p>Espace réservé à ton premier témoignage client — deux ou trois phrases sur le résultat et ta façon de travailler.</p><div class="quote__by"><b>Nom du client</b>Secteur d'activité</div></div>
      <div class="quote"><span class="quote__mark">“</span><p>Un deuxième avis ici renforce la confiance : délais tenus, communication, impact sur l'activité.</p><div class="quote__by"><b>Nom du client</b>Secteur d'activité</div></div>
      <div class="quote"><span class="quote__mark">“</span><p>Un troisième témoignage, idéalement d'un secteur différent, pour parler à un maximum de prospects.</p><div class="quote__by"><b>Nom du client</b>Secteur d'activité</div></div>
    </div>
  </div>
</section>

<!-- ============ FAQ ============ -->
<section class="section section--paper" id="faq" style="background:#DEDACD">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Questions fréquentes</span>
      <h2 class="h2 reveal">Vous vous demandez sûrement…</h2>
    </div>
    <div class="faq reveal">
      <div class="faq__item"><button class="faq__q">Combien de temps pour créer mon site ?<span class="ic"></span></button><div class="faq__a"><p>Comptez 2 à 4 semaines pour un site vitrine, selon le nombre de pages et le contenu. Pour une application ou une boutique, le délai est cadré précisément dans le devis.</p></div></div>
      <div class="faq__item"><button class="faq__q">Je pars de zéro, vous m'accompagnez ?<span class="ic"></span></button><div class="faq__a"><p>Oui, complètement. Je vous guide sur la structure du site, le contenu et le design. Pas besoin de vous y connaître : vous décidez, je m'occupe de la technique.</p></div></div>
      <div class="faq__item"><button class="faq__q">Le devis est-il vraiment gratuit ?<span class="ic"></span></button><div class="faq__a"><p>Totalement, et sans engagement. On échange sur votre projet, et vous recevez une proposition claire avec périmètre, prix et délai sous 48h.</p></div></div>
      <div class="faq__item"><button class="faq__q">À qui appartient le site une fois livré ?<span class="ic"></span></button><div class="faq__a"><p>Tout est à vous : le code, le contenu, le nom de domaine et l'hébergement, à votre nom. Vous n'êtes jamais coincé chez un prestataire.</p></div></div>
      <div class="faq__item"><button class="faq__q">Et après la mise en ligne ?<span class="ic"></span></button><div class="faq__a"><p>Vous pouvez gérer le site vous-même, ou souscrire à la maintenance (dès 39 €/mois) : mises à jour, sécurité, sauvegardes et évolutions. Je reste joignable dans tous les cas.</p></div></div>
    </div>
  </div>
</section>

<!-- ============ CONTACT / CTA ============ -->
<section class="section section--dark cta" id="contact">
  <div class="wrap">
    <div class="cta__grid">
      <div>
        <span class="eyebrow" style="margin-bottom:16px">Contact</span>
        <h2 class="h2">Parlons de votre projet.</h2>
        <p class="lead">Un site à créer ou à refondre, une appli à construire, ou juste une question ? Écrivez-moi, même si votre idée est encore floue. Je réponds vite : sous 48h, sans engagement.</p>
        <!-- ⚠️ À COMPLÉTER : remplace l'URL par ton vrai lien de réservation (Cal.com ou Calendly) -->
        <a href="https://cal.com/birostweb" target="_blank" rel="noopener" class="btn btn-ghost" style="margin:4px 0 4px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v3M16 2v3M3.5 9h17M5 5h14a1.5 1.5 0 011.5 1.5v13A1.5 1.5 0 0119 21H5a1.5 1.5 0 01-1.5-1.5v-13A1.5 1.5 0 015 5z"/></svg>
          Ou réservez un appel de 15 min
        </a>
        <div class="manifest">
          <div class="mrow"><span class="mk">Mail</span><span class="mv"><a href="mailto:contact@theo-birost.fr">contact@theo-birost.fr</a></span></div>
          <div class="mrow"><span class="mk">GitHub</span><span class="mv"><a href="https://github.com/birostweb" target="_blank" rel="noopener">github.com/birostweb</a></span></div>
          <div class="mrow"><span class="mk">LinkedIn</span><span class="mv"><a href="https://www.linkedin.com/in/th%C3%A9o-birost-09b286429/" target="_blank" rel="noopener">linkedin.com/in/théo-birost</a></span></div>
          <!-- Remplace le handle Instagram ci-dessous par ton vrai compte une fois créé -->
          <div class="mrow"><span class="mk">Instagram</span><span class="mv"><a href="https://www.instagram.com/birost.web" target="_blank" rel="noopener">@birost.web</a></span></div>
          <div class="mrow"><span class="mk">Zone</span><span class="mv">France entière · Full remote</span></div>
        </div>
      </div>
      <form class="form" id="cform" method="post" action="send_mail.php">
        <div class="estimate" id="estimate" hidden>
          <div class="estimate__t">Votre sélection</div>
          <div class="estimate__row"><span>Projet</span><b id="est-offre">—</b></div>
          <div class="estimate__row"><span>Hébergement</span><b id="est-heb">—</b></div>
          <div class="estimate__row"><span>Maintenance</span><b id="est-maint">—</b></div>
          <div class="estimate__total"><span id="est-oneoff">—</span> <span id="est-mo"></span></div>
          <div class="estimate__note">Estimation indicative — le prix exact dépend du périmètre et vous est confirmé dans le devis.</div>
        </div>
        <div class="field"><label for="cn">Nom</label><input id="cn" name="name" type="text" autocomplete="name" required maxlength="100"></div>
        <div class="field"><label for="ce">Email</label><input id="ce" name="email" type="email" autocomplete="email" required maxlength="254"></div>
        <!-- Champs remplis automatiquement par le configurateur (onglets Offres). Masqués pour garder le formulaire court. -->
        <div id="form-details" hidden>
        <div class="field"><label for="coffer">Offre qui vous intéresse</label>
          <select id="coffer" name="offre">
            <option value="">— Je ne sais pas encore —</option>
            <option value="Offre 1 — Site vitrine">Offre 1 · Site vitrine</option>
            <option value="Offre 2 — Boutique en ligne">Offre 2 · Boutique en ligne</option>
            <option value="Offre 3 — Application web">Offre 3 · Application web</option>
            <option value="Devis sur-mesure">Devis sur-mesure</option>
          </select>
        </div>
        <div class="field"><label for="cmaint">Maintenance</label>
          <select id="cmaint" name="maintenance">
            <option value="">— À définir —</option>
            <option value="Suivi mensuel (39 €/mois)">Suivi mensuel · 39 €/mois</option>
            <option value="Pack d'heures">Pack d'heures</option>
            <option value="On verra plus tard / besoin d'infos">On verra plus tard / besoin d'infos</option>
          </select>
        </div>
        <div class="field" id="pack-detail" style="display:none">
          <label for="cpack">Nombre d'heures</label>
          <select id="cpack" name="pack">
            <option value="5">Pack 5 h — 175 € (35 €/h)</option>
            <option value="10">Pack 10 h — 320 € (32 €/h)</option>
            <option value="autre">Autre — je précise</option>
          </select>
          <div id="pack-custom" style="display:none;margin-top:10px">
            <input id="chours" name="hours" type="number" min="1" max="500" inputmode="numeric" aria-label="Nombre d'heures souhaité" placeholder="Nombre d'heures (ex. 7)">
            <div id="chours-price" style="font-family:var(--fm);font-size:12.5px;color:var(--d-dim);margin-top:8px"></div>
          </div>
        </div>
        <div class="field"><label for="cheb">Hébergement</label>
          <select id="cheb" name="hebergement">
            <option value="">— À définir —</option>
            <option value="Sur mon propre serveur / hébergeur">Sur mon propre serveur / hébergeur</option>
            <option value="Essentiel · VPS-1 (45 €/mois)">Essentiel · VPS-1 — 45 €/mois (tout compris)</option>
            <option value="Pro · VPS-2 (69 €/mois)">Pro · VPS-2 — 69 €/mois (tout compris)</option>
          </select>
        </div>
        </div><!-- /form-details -->
        <div class="field"><label for="cm">Votre projet</label><textarea id="cm" name="message" required minlength="10" maxlength="5000"></textarea></div>
        <input type="hidden" name="ts" value="<?= htmlspecialchars((string) $contactFormTs, ENT_QUOTES) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($contactFormToken, ENT_QUOTES) ?>">
        <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
          <label for="website">Laisser ce champ vide</label>
          <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <altcha-widget challengeurl="/altcha.php" auto="onload"></altcha-widget>
        <div class="form__status" id="cstatus" role="status" aria-live="polite"></div>
        <p style="font-family:var(--fm);font-size:11.5px;color:var(--d-dim);line-height:1.5;margin-top:-4px">En envoyant ce formulaire, vous acceptez d'être recontacté au sujet de votre demande. Voir la <a href="/mentions-legales.html" style="color:var(--accent)">politique de confidentialité</a>.</p>
        <button type="submit" class="btn btn-accent btn-lg" style="justify-content:center">Envoyer ma demande
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>
    </div>
  </div>
</section>
</main>

<!-- CTA sticky mobile (visible uniquement sur petit écran) -->
<div class="sticky-cta"><a href="#contact" class="btn btn-accent">Demander un devis gratuit</a></div>

<!-- ============ FOOTER ============ -->
<footer class="footer">
  <div class="wrap">
    <div class="footer__in">
      <span class="footer__b">Théo Birost — Développeur web full-stack</span>
      <nav class="footer__l">
        <a href="#realisations">Réalisations</a><a href="#offres">Offres</a><a href="#approche">Approche</a><a href="#faq">FAQ</a><a href="#contact">Contact</a><a href="/mentions-legales.html">Mentions légales</a><a href="/cgv.html">CGV</a><a href="#top">↑ Haut</a>
      </nav>
    </div>
    <div class="footer__meta"><span>© 2026 Théo Birost · France · Full remote</span><span>Micro-entreprise · Développement web sur-mesure</span></div>
  </div>
</footer>

<script>
// Menu mobile
(function(){var t=document.getElementById('toggle'),m=document.getElementById('menu');
 t.addEventListener('click',function(){var o=m.classList.toggle('open');t.setAttribute('aria-expanded',String(o));});
 m.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){m.classList.remove('open');t.setAttribute('aria-expanded','false');});});})();
// FAQ
document.querySelectorAll('.faq__q').forEach(function(q){
 q.setAttribute('type','button');q.setAttribute('aria-expanded','false');
 q.addEventListener('click',function(){
 var it=q.parentElement,a=q.nextElementSibling,open=it.classList.toggle('open');
 q.setAttribute('aria-expanded',String(open));
 a.style.maxHeight=open?a.scrollHeight+'px':0;});});
// Formulaire -> envoi via send_mail.php (back-end PHP + SMTP)
(function(){var f=document.getElementById('cform');if(!f)return;
 var s=document.getElementById('cstatus'),b=f.querySelector('button[type=submit]');
 f.addEventListener('submit',function(e){e.preventDefault();b.disabled=true;
  fetch('send_mail.php',{method:'POST',body:new FormData(f)})
   .then(function(r){return r.text().then(function(t){return{ok:r.ok,t:t};});})
   .then(function(res){s.className='form__status '+(res.ok?'ok':'err');s.textContent=res.t;if(res.ok)f.reset();})
   .catch(function(){s.className='form__status err';s.textContent='Une erreur est survenue. Merci de réessayer.';})
   .finally(function(){b.disabled=false;});
 });})();
// Maintenance : détail "pack d'heures" + aperçu du prix (tarifs dégressifs)
(function(){
 var maint=document.getElementById('cmaint'),detail=document.getElementById('pack-detail'),
     pack=document.getElementById('cpack'),custom=document.getElementById('pack-custom'),
     hours=document.getElementById('chours'),price=document.getElementById('chours-price');
 if(!maint||!detail)return;
 function rate(h){return h<5?39:(h<=9?35:32);}
 function syncDetail(){detail.style.display=(maint.value==="Pack d'heures")?'block':'none';}
 function updatePrice(){
  if(pack.value!=='autre'){price.textContent='';return;}
  var h=parseInt(hours.value,10);
  if(!h||h<1){price.textContent="Indiquez un nombre d'heures.";return;}
  var r=rate(h);price.textContent='≈ '+(h*r)+' € ('+r+' €/h)';
 }
 function syncCustom(){custom.style.display=(pack.value==='autre')?'block':'none';updatePrice();}
 maint.addEventListener('change',syncDetail);
 pack.addEventListener('change',syncCustom);
 hours.addEventListener('input',updatePrice);
 syncDetail();syncCustom();
 document.querySelectorAll('[data-maint]').forEach(function(btn){
  btn.addEventListener('click',function(){
   var isHeures=btn.getAttribute('data-maint')==='heures';
   maint.value=isHeures?"Pack d'heures":"Suivi mensuel (39 €/mois)";
   syncDetail();
   if(isHeures){setTimeout(function(){pack.focus();},450);}
  });
 });
})();
// Configurateur d'offre : parcours guidé (onglet -> onglet) + estimation + pré-remplissage du formulaire
(function(){
 var box=document.getElementById('estimate'); if(!box) return;
 var el={offre:document.getElementById('est-offre'),heb:document.getElementById('est-heb'),maint:document.getElementById('est-maint'),oneoff:document.getElementById('est-oneoff'),mo:document.getElementById('est-mo')};
 var state={};
 function setSelect(id,val){var s=document.getElementById(id); if(!s||val==null) return; var ok=false; for(var i=0;i<s.options.length;i++){if(s.options[i].value===val){ok=true;break;}} if(!ok){s.add(new Option(val,val));} s.value=val;}
 function refresh(){
  box.hidden=false;
  el.offre.textContent=state.offre?state.offre.label:'—';
  el.heb.textContent=state.heb?state.heb.label:'—';
  el.maint.textContent=state.maint?state.maint.label:'—';
  var oneoff=state.offre?state.offre.oneoff:0;
  el.oneoff.textContent=(oneoff>0)?('À partir de '+oneoff.toLocaleString('fr-FR')+' €'):'Projet sur devis';
  var mo=(state.heb?state.heb.mo:0)+(state.maint?state.maint.mo:0);
  el.mo.textContent=(mo>0)?('+ '+mo+' €/mois'):'';
 }
 function toTab(id){var r=document.getElementById(id); if(r){r.checked=true; var n=document.querySelector('.tabs__nav'); if(n)n.scrollIntoView({behavior:'smooth',block:'start'});}}
 document.querySelectorAll('[data-step]').forEach(function(btn){
  btn.addEventListener('click',function(e){
   e.preventDefault();
   var d=btn.dataset;
   if(d.step==='offre'){state.offre={label:d.label,oneoff:parseInt(d.oneoff||'0',10)};setSelect('coffer',d.form);}
   else if(d.step==='heb'){state.heb={label:d.label,mo:parseInt(d.mo||'0',10)};setSelect('cheb',d.form);
    if(d.included){state.maint={label:"Incluse dans l'offre",mo:0};setSelect('cmaint','');}}
   else if(d.step==='maint'){state.maint={label:d.label,mo:parseInt(d.mo||'0',10)};setSelect('cmaint',d.form);}
   refresh();
   if(d.next){toTab(d.next);} else {var c=document.getElementById('contact'); if(c)c.scrollIntoView({behavior:'smooth'});}
  });
 });
})();
// Reveal
(function(){if(window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 var io=new IntersectionObserver(function(es){es.forEach(function(en){if(en.isIntersecting){en.target.classList.add('in');io.unobserve(en.target);}});},{threshold:.12});
 document.querySelectorAll('.reveal').forEach(function(el){io.observe(el);});})();
</script>
</body>
</html>
