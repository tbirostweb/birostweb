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
label{display:block;font-family:var(--fm);font-size:12px;letter-spacing:.02em;text-transform:uppercase;color:var(--gray);margin:14px 0 5px}
input[type=text],input[type=date],textarea{width:100%;font-family:var(--fb);font-size:15px;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:10px 12px}
textarea{min-height:90px;resize:vertical}
input:focus-visible,textarea:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
.check{display:flex;align-items:center;gap:9px;margin:14px 0 0}
.check input{width:16px;height:16px;accent-color:var(--accent)}
.check label{margin:0;text-transform:none;font-family:var(--fb);font-size:14px;color:var(--ink);letter-spacing:0}
.btn{font-family:var(--fm);font-size:13px;letter-spacing:.03em;padding:11px 20px;border-radius:var(--r);border:1.5px solid var(--ink);background:var(--ink);color:var(--surface);cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s}
.btn:hover{background:var(--accent);border-color:var(--accent);color:#fff}
.btn--ghost{background:transparent;color:var(--ink)}
.btn--ghost:hover{background:transparent;border-color:var(--accent);color:var(--accent)}
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
.fieldset{border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;margin-top:18px}
.fieldset>legend{font-family:var(--fm);font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);padding:0 6px}
form.inline{display:inline}
@media(max-width:620px){.grid2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
HTML;
}

function admin_foot(): void
{
    echo "\n</div>\n</body>\n</html>";
}
