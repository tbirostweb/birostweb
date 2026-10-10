<?php

declare(strict_types=1);

/*
 * Socle commun du back-office (/admin).
 *
 * - Chargement de l'environnement (Dotenv si dispo, sinon getenv/$_ENV/$_SERVER).
 * - Session durcie (HttpOnly, Secure si HTTPS, SameSite=Strict, regenerate à la
 *   connexion, timeout d'inactivité).
 * - Auth par ADMIN_USER / ADMIN_PASSWORD_HASH (fail-closed si non configuré).
 * - CSRF (jeton en session, vérifié en temps constant).
 * - Anti-force-brute (réutilise le mécanisme flock + dossier d'état de contact_lib).
 * - En-têtes noindex + no-store.
 */

$ADMIN_ROOT = __DIR__;
$SITE_ROOT  = dirname(__DIR__); // site/

/* ---- Environnement : Dotenv si l'autoload Composer est présent ---- */
$autoload = $SITE_ROOT . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(\Dotenv\Dotenv::class)) {
        try {
            \Dotenv\Dotenv::createImmutable($SITE_ROOT)->load();
        } catch (\Throwable $e) {
            // Pas de .env : les variables viennent de l'environnement (Dokploy).
        }
    }
}

require_once $SITE_ROOT . '/inc/content_store.php';
// Réutilise le rate-limit existant (flock + dossier d'état) et contact_client_ip().
require_once $SITE_ROOT . '/inc/contact_lib.php';

/* ---- En-têtes : jamais indexé, jamais mis en cache ---- */
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: same-origin');

/* ---- Détection HTTPS (derrière Traefik : X-Forwarded-Proto) ---- */
function admin_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $xf = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return is_string($xf) && strtolower($xf) === 'https';
}

/* ---- Session durcie ---- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'httponly' => true,
        'secure' => admin_is_https(),
        'samesite' => 'Strict',
    ]);
    session_name('bwadmin');
    session_start();
}

/* ---- Timeout d'inactivité (30 min) ---- */
const ADMIN_IDLE_TIMEOUT = 1800;
if (isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true) {
    $last = (int) ($_SESSION['admin_last'] ?? 0);
    if ($last > 0 && (time() - $last) > ADMIN_IDLE_TIMEOUT) {
        admin_destroy_session();
    } else {
        $_SESSION['admin_last'] = time();
    }
}

/* ---- Config auth ---- */
function admin_user(): string
{
    return (string) (cs_env('ADMIN_USER') ?? '');
}
function admin_hash(): string
{
    return (string) (cs_env('ADMIN_PASSWORD_HASH') ?? '');
}
function admin_configured(): bool
{
    $u = admin_user();
    $h = admin_hash();
    return $u !== '' && $h !== '' && str_starts_with($h, '$');
}

/* ---- État d'authentification ---- */
function admin_is_authenticated(): bool
{
    return isset($_SESSION['admin_auth'])
        && $_SESSION['admin_auth'] === true
        && ($_SESSION['admin_user'] ?? null) === admin_user()
        && admin_user() !== '';
}

function admin_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'] ?: '/admin',
            'httponly' => true,
            'secure' => admin_is_https(),
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

/** Redirige vers la connexion si non authentifié. */
function admin_require_login(): void
{
    if (!admin_configured() || !admin_is_authenticated()) {
        header('Location: ' . admin_base() . '/');
        exit;
    }
}

/** Base d'URL de l'admin (ex. /admin). */
function admin_base(): string
{
    // _bootstrap.php est dans site/admin/, servi sous /admin.
    return '/admin';
}

/* ---- CSRF ---- */
function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function admin_csrf_check(): bool
{
    $sent = $_POST['csrf'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    return is_string($sent) && $sess !== '' && hash_equals($sess, $sent);
}

/* ---- Anti-force-brute (par IP, fenêtre glissante) ---- */
const ADMIN_LOGIN_MAX = 5;
const ADMIN_LOGIN_WINDOW = 900; // 15 min

/** Fichier d'état des tentatives pour une IP. */
function admin_login_rl_file(string $ip): string
{
    $dir = contact_state_dir('admin_login_rl'); // peut lever ContactStorageException
    $secret = cs_env('CONTACT_FORM_SECRET') ?? cs_env('ADMIN_PASSWORD_HASH') ?? 'bw-admin';
    return $dir . '/' . hash_hmac('sha256', $ip, $secret) . '.json';
}

/** True si l'IP a dépassé le quota de tentatives. Fail-closed (storage KO => true). */
function admin_login_limited(string $ip): bool
{
    try {
        $file = admin_login_rl_file($ip);
    } catch (\Throwable $e) {
        return true;
    }
    $h = @fopen($file, 'c+');
    if (!$h) {
        return true;
    }
    if (!flock($h, LOCK_SH)) {
        fclose($h);
        return true;
    }
    $times = json_decode(stream_get_contents($h) ?: '[]', true);
    flock($h, LOCK_UN);
    fclose($h);
    if (!is_array($times)) {
        $times = [];
    }
    $now = time();
    $times = array_filter($times, static fn ($t) => is_int($t) && $t > $now - ADMIN_LOGIN_WINDOW);
    return count($times) >= ADMIN_LOGIN_MAX;
}

/** Enregistre une tentative échouée. */
function admin_login_record_failure(string $ip): void
{
    try {
        $file = admin_login_rl_file($ip);
    } catch (\Throwable $e) {
        return;
    }
    $h = @fopen($file, 'c+');
    if (!$h) {
        return;
    }
    if (!flock($h, LOCK_EX)) {
        fclose($h);
        return;
    }
    $times = json_decode(stream_get_contents($h) ?: '[]', true);
    if (!is_array($times)) {
        $times = [];
    }
    $now = time();
    $times = array_values(array_filter($times, static fn ($t) => is_int($t) && $t > $now - ADMIN_LOGIN_WINDOW));
    $times[] = $now;
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($times));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
}

/** Réinitialise le compteur (connexion réussie). */
function admin_login_reset(string $ip): void
{
    try {
        $file = admin_login_rl_file($ip);
    } catch (\Throwable $e) {
        return;
    }
    @unlink($file);
}

/* ---- Helpers vue ---- */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Store partagé pour la requête. */
function admin_store(): ContentStore
{
    static $store = null;
    if ($store === null) {
        $store = cs_make_store();
    }
    return $store;
}

/** En-tête HTML commun des pages admin. */
function admin_head(string $title): void
{
    $t = e($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>$t · Admin birostweb</title>
<style>
:root{
  --paper:#E5E2D6;--surface:#FBFAF6;--ink:#231F20;--accent:#F0451E;--accent-d:#CE3711;
  --gray:#6E6A5F;--line:#CFCABC;--line-2:#E9E5DB;--r:3px;
  --fm:'IBM Plex Mono',ui-monospace,SFMono-Regular,Menlo,monospace;
  --fb:'IBM Plex Sans',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--paper);color:var(--ink);font-family:var(--fb);font-size:16px;line-height:1.55;-webkit-font-smoothing:antialiased}
a{color:var(--accent);text-decoration:none}
a:hover{color:var(--accent-d)}
.wrap{max-width:900px;margin:0 auto;padding:clamp(24px,4vw,48px) 20px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:28px}
.brand{font-family:var(--fm);font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:var(--ink)}
.brand b{color:var(--accent)}
.eyebrow{font-family:var(--fm);font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--accent)}
h1{font-size:clamp(26px,4vw,34px);letter-spacing:-.01em;margin:6px 0 4px;line-height:1.1}
h2{font-size:19px;letter-spacing:-.01em;margin:28px 0 12px}
.muted{color:var(--gray);font-size:14px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:22px}
.card+.card{margin-top:14px}
.row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
/* ---- Formulaires : labels, champs, aides ---- */
label{display:block;font-family:var(--fm);font-size:11px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--gray);margin:22px 0 8px}
.card>label:first-child,.grid2>div>label:first-child,.field:first-child>label{margin-top:0}
.field{margin-top:22px}
.field>label{margin-top:0}
.field--inline{display:flex;flex-direction:column;justify-content:flex-end}
input[type=text],input[type=date],input[type=password],input[type=number],select,textarea{width:100%;font-family:var(--fb);font-size:15px;line-height:1.4;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:11px 13px;transition:border-color .15s,box-shadow .15s;-webkit-appearance:none;appearance:none}
input[type=text]:hover,input[type=date]:hover,input[type=password]:hover,input[type=number]:hover,select:hover,textarea:hover{border-color:var(--gray)}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(240,69,30,.14)}
input::placeholder,textarea::placeholder{color:#a8a396}
textarea{min-height:96px;resize:vertical;line-height:1.55}
textarea.tall{min-height:140px}
select{padding-right:38px;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%236E6A5F' stroke-width='1.6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center}
input[type=date]{min-height:44px}
.hint{color:var(--gray);font-size:13px;line-height:1.5;margin-top:8px}
.hint code,.muted code{font-family:var(--fm);font-size:12px;background:var(--line-2);padding:1px 6px;border-radius:2px}
/* Case à cocher personnalisée */
.check{display:flex;align-items:center;gap:11px;margin:14px 0 0}
.check input{-webkit-appearance:none;appearance:none;flex:none;width:20px;height:20px;margin:0;border:1.5px solid var(--line);border-radius:var(--r);background:#fff;cursor:pointer;display:grid;place-content:center;transition:.15s}
.check input::after{content:"";width:10px;height:6px;border-left:2px solid #fff;border-bottom:2px solid #fff;transform:rotate(-45deg) translate(1px,-1px);opacity:0}
.check input:hover{border-color:var(--gray)}
.check input:checked{background:var(--accent);border-color:var(--accent)}
.check input:checked::after{opacity:1}
.check input:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(240,69,30,.2)}
.check label{margin:0;cursor:pointer;text-transform:none;font-family:var(--fb);font-size:14.5px;font-weight:400;color:var(--ink);letter-spacing:0}
/* Champ fichier */
.filefield{width:100%;font-family:var(--fb);font-size:14px;color:var(--gray);background:var(--paper);border:1px dashed var(--line);border-radius:var(--r);padding:10px;cursor:pointer;transition:border-color .15s,background .15s}
.filefield:hover{border-color:var(--accent);background:#fff}
.filefield:focus-visible{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(240,69,30,.14)}
input[type=file]{width:100%;font-family:var(--fb);font-size:14px;color:var(--gray);background:var(--paper);border:1px dashed var(--line);border-radius:var(--r);padding:10px;cursor:pointer;transition:border-color .15s,background .15s}
input[type=file]:hover{border-color:var(--accent);background:#fff}
input[type=file]:focus-visible{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(240,69,30,.14)}
input[type=file]::file-selector-button{font-family:var(--fm);font-size:12px;letter-spacing:.04em;color:var(--ink);background:var(--surface);border:1.5px solid var(--ink);border-radius:var(--r);padding:8px 14px;margin-right:14px;cursor:pointer;transition:.15s}
input[type=file]::file-selector-button:hover{background:var(--ink);color:var(--surface)}
/* Sélecteur de source de couverture */
.radio-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.radio-card{position:relative;display:block;margin:0;cursor:pointer;text-transform:none;letter-spacing:0;font-family:var(--fb);color:var(--ink)}
.radio-card input{position:absolute;z-index:1;opacity:0;inset:0;margin:0;cursor:pointer}
.radio-card .rc{display:block;height:100%;border:1px solid var(--line);border-radius:var(--r);background:#fff;padding:14px 16px 14px 44px;position:relative;transition:border-color .15s,box-shadow .15s,background .15s}
.radio-card .rc::before{content:"";position:absolute;left:15px;top:17px;width:16px;height:16px;border:1.5px solid var(--line);border-radius:50%;background:#fff;transition:.15s}
.radio-card .rc b{display:block;font-size:14.5px;font-weight:600;line-height:1.3}
.radio-card .rc small{display:block;margin-top:4px;font-size:12.5px;line-height:1.45;color:var(--gray);font-weight:400}
.radio-card:hover .rc{border-color:var(--gray)}
.radio-card input:checked+.rc{border-color:var(--accent);background:#fff;box-shadow:0 0 0 1px var(--accent)}
.radio-card input:checked+.rc::before{border-color:var(--accent);background:radial-gradient(circle,var(--accent) 0 4px,#fff 5px)}
.radio-card input:focus-visible+.rc{box-shadow:0 0 0 1px var(--accent),0 0 0 4px rgba(240,69,30,.2)}
.cover-panel{margin-top:16px;padding:16px 18px;border:1px solid var(--line-2);border-radius:var(--r);background:var(--paper)}
.cover-panel>:first-child{margin-top:0}
@supports selector(:has(*)){
  .cover-src .cover-panel{display:none}
  .cover-src:has(#src_auto:checked) .cover-panel--auto,
  .cover-src:has(#src_frame:checked) .cover-panel--frame,
  .cover-src:has(#src_upload:checked) .cover-panel--upload{display:block}
}
/* Aperçu de couverture */
.cover-preview{display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap}
.cover-preview figure{width:min(100%,260px);margin:0}
.cover-preview .thumb{aspect-ratio:16/9}
.cover-preview figcaption{font-family:var(--fm);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--gray);margin-top:8px}
/* Frames candidates */
.frames{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.frame{position:relative;display:block;margin:0;cursor:pointer;text-transform:none;letter-spacing:0}
.frame input{position:absolute;z-index:1;opacity:0;inset:0;margin:0;cursor:pointer}
.frame .thumb{aspect-ratio:16/9;border-width:2px;border-color:transparent;outline:1px solid var(--line);outline-offset:0;transition:border-color .15s,outline-color .15s}
.frame:hover .thumb{outline-color:var(--gray)}
.frame .n{position:absolute;left:8px;top:8px;font-family:var(--fm);font-size:11px;letter-spacing:.08em;background:var(--ink);color:var(--surface);padding:2px 7px;border-radius:2px}
.frame input:checked~.thumb{border-color:var(--accent);outline-color:var(--accent)}
.frame input:checked~.n{background:var(--accent);color:#fff}
.frame input:focus-visible~.thumb{box-shadow:0 0 0 4px rgba(240,69,30,.25)}
@media(max-width:900px){.frames{grid-template-columns:repeat(3,1fr)}}
@media(max-width:620px){.radio-cards{grid-template-columns:1fr}.frames{grid-template-columns:repeat(2,1fr)}}
.btn{font-family:var(--fm);font-size:13px;letter-spacing:.04em;padding:11px 22px;border-radius:var(--r);border:1.5px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:.15s;text-decoration:none}
.btn:hover{background:var(--accent-d);border-color:var(--accent-d);color:#fff}
.btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(240,69,30,.28)}
.btn--ghost{background:transparent;color:var(--ink);border-color:var(--ink)}
.btn--ghost:hover{background:transparent;border-color:var(--accent);color:var(--accent)}
.btn[disabled]{opacity:.4;cursor:not-allowed}
.formbar{margin-top:26px;display:flex;gap:10px;flex-wrap:wrap}
.btn--sm{padding:7px 12px;font-size:12px}
.pill{display:inline-block;font-family:var(--fm);font-size:11px;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:2px;border:1px solid var(--line);color:var(--gray)}
.pill--on{color:#15803d;border-color:#15803d}
.pill--off{color:var(--gray)}
.pill--indispo{color:#fff;background:var(--ink);border-color:var(--ink)}
.pill--promo{color:var(--accent);border-color:var(--accent)}
.offer-line{border:1px solid var(--line);border-radius:var(--r);background:var(--surface);padding:16px 18px;margin-top:12px}
.offer-line .t{font-weight:600}
.offer-line .meta{font-family:var(--fm);font-size:12px;color:var(--gray);margin-top:3px}
.actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.tabgroup{margin-top:26px}
.tabgroup>.lbl{font-family:var(--fm);font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);border-top:1px solid var(--line);padding-top:14px}
.alert{border:1px solid var(--accent);border-left:3px solid var(--accent);background:var(--surface);border-radius:var(--r);padding:12px 14px;margin-bottom:18px;font-size:14px}
.alert--ok{border-color:#15803d;border-left-color:#15803d}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.card>.grid2,.fieldset>.grid2{margin-top:22px}
.card>h2:first-child+.grid2,.card>.grid2:first-child{margin-top:0}
h1+p.muted{margin-bottom:20px}
.fieldset{border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;margin-top:18px}
.fieldset>legend{font-family:var(--fm);font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);padding:0 6px}
form.inline{display:inline}
.navlink{font-family:var(--fm);font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--gray);padding:5px 2px;border-bottom:1.5px solid transparent}
.navlink:hover{color:var(--ink)}
.navlink.is-active{color:var(--ink);border-bottom-color:var(--accent)}
.thumb{width:100%;aspect-ratio:4/3;object-fit:cover;background:var(--line-2);border:1px solid var(--line);border-radius:var(--r);display:block}
.imgcard{border:1px solid var(--line);border-radius:var(--r);background:var(--surface);padding:12px}
.imggrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;margin-top:12px}
.badge-type{display:inline-block;font-family:var(--fm);font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:3px 8px;border-radius:2px;border:1px solid var(--line);color:var(--gray)}
.badge-type--video{color:var(--accent);border-color:var(--accent)}
@media(max-width:620px){.grid2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
HTML;
}

/**
 * Barre supérieure commune : marque + navigation (Offres / Galerie) +
 * lien « Voir le site » + bouton de déconnexion. $current ∈ {'offers','gallery'}.
 */
function admin_topbar(string $current = ''): void
{
    $base = e(admin_base());
    echo '<div class="topbar">';
    echo '<span class="brand"><b>BW</b> Admin</span>';
    echo '<span class="actions">';
    echo '<a class="navlink' . ($current === 'offers' ? ' is-active' : '') . '" href="' . $base . '/">Offres</a> ';
    echo '<a class="navlink' . ($current === 'gallery' ? ' is-active' : '') . '" href="' . $base . '/gallery.php">Galerie</a> ';
    echo '<a class="muted" href="/" target="_blank" rel="noopener">Voir le site ↗</a> ';
    echo '<form class="inline" method="post" action="' . $base . '/logout.php">' . admin_csrf_field()
        . '<button class="btn btn--ghost btn--sm" type="submit">Déconnexion</button></form>';
    echo '</span></div>';
}

function admin_foot(): void
{
    echo "\n</div>\n</body>\n</html>";
}
