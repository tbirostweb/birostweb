<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// En prod (Dokploy) les variables viennent de l'environnement. En local, .env.
try {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    // Pas de .env : normal en prod.
}

/** IP du visiteur (derrière Traefik on lit X-Forwarded-For). */
function contact_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Rate limit fichier par IP : true si la limite est dépassée. */
function contact_rate_limited(string $ip, int $maxRequests = 5, int $windowSeconds = 3600): bool
{
    $dir = sys_get_temp_dir() . '/contact_form_rl';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . hash('sha256', $ip) . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle) {
        return false;
    }
    flock($handle, LOCK_EX);
    $timestamps = json_decode(stream_get_contents($handle) ?: '[]', true);
    if (!is_array($timestamps)) {
        $timestamps = [];
    }
    $now = time();
    $timestamps = array_values(array_filter($timestamps, fn ($t) => $t > $now - $windowSeconds));
    $limited = count($timestamps) >= $maxRequests;
    if (!$limited) {
        $timestamps[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($timestamps));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $limited;
}

/** Corps HTML de l'email de notification reçu par le propriétaire du site. */
function contact_render_email_html(string $name, string $email, string $message, string $offre, string $maintenance, string $hebergement = ''): string
{
    $safeName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeEmail   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeOffre   = htmlspecialchars($offre !== '' ? $offre : 'Non précisée', ENT_QUOTES, 'UTF-8');
    $safeMaint   = htmlspecialchars($maintenance !== '' ? $maintenance : 'Non précisée', ENT_QUOTES, 'UTF-8');
    $safeHeberg  = htmlspecialchars($hebergement !== '' ? $hebergement : 'Non précisé', ENT_QUOTES, 'UTF-8');
    $date        = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y à H:i');

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Nouvelle demande</title></head>
<body style="margin:0;padding:0;background-color:#E5E2D6;font-family:'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#E5E2D6;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#FBFAF6;border-radius:12px;overflow:hidden;border:1px solid #CFCABC;">
        <tr><td style="background-color:#231F20;padding:28px 32px;">
          <table role="presentation" cellpadding="0" cellspacing="0"><tr>
            <td style="font-size:0;line-height:0;vertical-align:middle;"><span style="display:inline-block;width:9px;height:9px;background-color:#F0451E;border-radius:50%;"></span></td>
            <td style="padding-left:10px;color:#F0EDE4;font-size:13px;line-height:1;letter-spacing:.08em;text-transform:uppercase;font-weight:600;vertical-align:middle;">Demande de devis · birostweb.fr</td>
          </tr></table>
          <div style="color:#F0EDE4;font-size:22px;font-weight:700;margin-top:14px;">Nouvelle demande reçue</div>
          <div style="color:#AEA99C;font-size:13px;margin-top:6px;">{$date}</div>
        </td></tr>
        <tr><td style="padding:28px 32px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
            <tr>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;width:110px;color:#F0451E;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;vertical-align:top;">Nom</td>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#231F20;font-size:15px;">{$safeName}</td>
            </tr>
            <tr>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#F0451E;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;vertical-align:top;">Email</td>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#231F20;font-size:15px;"><a href="mailto:{$safeEmail}" style="color:#231F20;text-decoration:underline;">{$safeEmail}</a></td>
            </tr>
            <tr>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#F0451E;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;vertical-align:top;">Offre</td>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#231F20;font-size:15px;">{$safeOffre}</td>
            </tr>
            <tr>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#F0451E;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;vertical-align:top;">Maintenance</td>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#231F20;font-size:15px;">{$safeMaint}</td>
            </tr>
            <tr>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#F0451E;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;vertical-align:top;">Hébergement</td>
              <td style="padding:10px 0;border-bottom:1px solid #E9E5DB;color:#231F20;font-size:15px;">{$safeHeberg}</td>
            </tr>
          </table>
          <div style="color:#F0451E;font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;margin-bottom:10px;">Message</div>
          <div style="color:#231F20;font-size:15px;line-height:1.6;background-color:#E9E5DB;border-radius:8px;padding:16px 18px;">{$safeMessage}</div>
          <div style="margin-top:28px;text-align:center;">
            <a href="mailto:{$safeEmail}" style="display:inline-block;background-color:#F0451E;color:#FFFFFF;text-decoration:none;font-size:13px;font-weight:600;letter-spacing:.03em;padding:12px 26px;border-radius:6px;">Répondre à {$safeName}</a>
          </div>
        </td></tr>
        <tr><td style="padding:18px 32px;background-color:#E9E5DB;color:#6E6A5F;font-size:12px;text-align:center;">Envoyé automatiquement depuis le formulaire de birostweb.fr</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Évaluation anti-spam / anti-phishing du message.
 * Ne bloque pas (sauf cas extrême) : attribue un score et des raisons.
 *   - flag  : score élevé  => l'email est marqué (sujet + en-têtes X-Spam-*)
 *             pour être filtré automatiquement vers les indésirables.
 *   - block : spam évident => on jette silencieusement (réponse OK factice).
 *
 * @return array{score:int, reasons:array<int,string>, flag:bool, block:bool}
 */
function contact_spam_assessment(string $name, string $email, string $message): array
{
    $score = 0;
    $reasons = [];
    $add = function (int $pts, string $why) use (&$score, &$reasons) {
        $score += $pts;
        $reasons[] = $why;
    };
    $haystack = mb_strtolower($name . ' ' . $message);

    // 1) Nombre de liens
    $links = preg_match_all('#https?://|www\.#i', $message);
    if ($links >= 6)     { $add(5, "beaucoup de liens ($links)"); }
    elseif ($links >= 3) { $add(3, "plusieurs liens ($links)"); }

    // 2) Raccourcisseurs d'URL (typiques du phishing)
    if (preg_match('#\b(bit\.ly|tinyurl\.com|t\.co|goo\.gl|ow\.ly|is\.gd|buff\.ly|cutt\.ly|rebrand\.ly|shorturl\.at|adf\.ly)\b#i', $message)) {
        $add(4, "raccourcisseur d'URL");
    }

    // 3) Mots-clés arnaque / phishing / SEO-spam
    $spamWords = [
        'viagra', 'cialis', 'casino', 'porn', 'bitcoin', 'crypto', 'forex', 'loan', 'payday',
        'nigerian prince', 'wire transfer', 'inheritance', 'western union', 'gift card',
        'verify your account', 'confirm your password', 'suspended account', 'reset your password',
        'click here to claim', 'seo service', 'référencement garanti', 'backlink', 'guaranteed ranking',
        'first page of google', 'make money', 'work from home', 'free money', 'investment opportunity',
        'you have won', 'claim your prize', 'act now', 'limited time offer', 'increase your traffic',
    ];
    $hits = 0;
    foreach ($spamWords as $w) {
        if (mb_strpos($haystack, $w) !== false) { $hits++; }
    }
    if ($hits > 0) { $add(min(6, 2 * $hits), "mots suspects ($hits)"); }

    // 4) Écriture non latine (cyrillique / CJK) => quasi toujours du spam ici
    if (preg_match('/[\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}]/u', $message)) {
        $add(4, 'caractères non latins');
    }

    // 5) Balises HTML / BBCode dans le message
    if (preg_match('#<[a-z][\s\S]*>|\[url|\[link#i', $message)) {
        $add(3, 'balises HTML/BBCode');
    }

    // 6) Cri en majuscules
    $letters = preg_replace('/[^a-zA-ZÀ-ÿ]/u', '', $message);
    if (mb_strlen((string) $letters) > 20) {
        $upper = preg_replace('/[^A-ZÀ-Þ]/u', '', $message);
        if (mb_strlen((string) $upper) / max(1, mb_strlen((string) $letters)) > 0.6) {
            $add(2, 'majuscules excessives');
        }
    }

    // 7) Nom contenant une URL
    if (preg_match('#https?://|www\.#i', $name)) { $add(3, 'URL dans le nom'); }

    // 8) Domaine email : jetable, ou sans MX (adresse probablement fausse)
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    $disposable = ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'yopmail.com', 'tempmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'maildrop.cc', 'temp-mail.org'];
    if ($domain !== '' && in_array($domain, $disposable, true)) {
        $add(4, 'email jetable');
    } elseif ($domain !== '' && function_exists('checkdnsrr') && !checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
        $add(3, 'domaine email sans MX');
    }

    return [
        'score'   => $score,
        'reasons' => $reasons,
        'flag'    => $score >= 4,
        'block'   => $score >= 12,
    ];
}

/** Lecture robuste d'une variable d'environnement (quel que soit variables_order). */
function contact_env(string $key): ?string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? null : (string) $v;
}

/** Journalise un évènement du formulaire : IP réelle, User-Agent, endpoint, heure. */
function contact_log(string $event, array $extra = []): void
{
    $entry = [
        'ts'       => date('c'),
        'event'    => $event,
        'ip'       => contact_client_ip(),
        'ua'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 200),
        'endpoint' => (string) ($_SERVER['REQUEST_URI'] ?? '-'),
    ] + $extra;
    $dir = contact_env('CONTACT_LOG_DIR') ?? sys_get_temp_dir();
    @file_put_contents(
        rtrim($dir, '/') . '/contact_form.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/** Plafond global d'envois (toutes IP) sur 24 h glissantes. true si atteint. */
function contact_global_cap_reached(int $max): bool
{
    if ($max <= 0) {
        return false;
    }
    $file = sys_get_temp_dir() . '/contact_form_global.json';
    $h = fopen($file, 'c+');
    if (!$h) {
        return false;
    }
    flock($h, LOCK_EX);
    $times = json_decode(stream_get_contents($h) ?: '[]', true);
    if (!is_array($times)) {
        $times = [];
    }
    $now = time();
    $times = array_values(array_filter($times, static fn ($t) => $t > $now - 86400));
    $reached = count($times) >= $max;
    if (!$reached) {
        $times[] = $now;
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($times));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    return $reached;
}

/** Vérifie le payload Altcha : solution PoW + signature serveur + anti-rejeu. */
function contact_altcha_check(string $payload, string $secret): bool
{
    if ($payload === '' || $secret === '') {
        return false;
    }
    $data = json_decode(base64_decode($payload, true) ?: '', true);
    if (!is_array($data)) {
        return false;
    }
    $algorithm = $data['algorithm'] ?? '';
    $challenge = (string) ($data['challenge'] ?? '');
    $number    = $data['number'] ?? null;
    $salt      = (string) ($data['salt'] ?? '');
    $signature = (string) ($data['signature'] ?? '');

    if ($algorithm !== 'SHA-256' || $challenge === '' || $salt === '' || $signature === '' || !is_numeric($number)) {
        return false;
    }
    // Expiration (paramètre ?expires= dans le sel)
    if (preg_match('/[?&]expires=(\d+)/', $salt, $m) && (int) $m[1] < time()) {
        return false;
    }
    // La solution doit reconstituer le challenge…
    if (!hash_equals(hash('sha256', $salt . $number), $challenge)) {
        return false;
    }
    // …et le challenge doit bien avoir été signé par nous.
    if (!hash_equals(hash_hmac('sha256', $challenge, $secret), $signature)) {
        return false;
    }
    // Anti-rejeu : une même signature ne peut servir qu'une fois.
    $dir = sys_get_temp_dir() . '/altcha_used';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . hash('sha256', $signature) . '.used';
    if (is_file($file) && filemtime($file) > time() - 3600) {
        return false;
    }
    @touch($file);
    return true;
}

/**
 * Profils SMTP disponibles, dans l'ordre de priorité.
 * Primaire = SMTP_* ; secours = SMTP2_*. On garde ceux qui sont complets.
 * @return array<int,array{host:string,user:string,pass:string,secure:string,port:int}>
 */
function contact_smtp_profiles(): array
{
    $profiles = [];
    foreach (['', '2'] as $suffix) {
        $host = contact_env("SMTP{$suffix}_HOST");
        $user = contact_env("SMTP{$suffix}_USERNAME");
        $pass = contact_env("SMTP{$suffix}_PASSWORD");
        if ($host !== null && $user !== null && $pass !== null) {
            $profiles[] = [
                'host'   => $host,
                'user'   => $user,
                'pass'   => $pass,
                'secure' => contact_env("SMTP{$suffix}_SECURE") ?? 'tls',
                'port'   => (int) (contact_env("SMTP{$suffix}_PORT") ?? '587'),
            ];
        }
    }
    return $profiles;
}

/** Applique un profil SMTP à une instance PHPMailer. */
function contact_apply_smtp(PHPMailer $m, array $p): void
{
    $m->isSMTP();
    $m->Host       = $p['host'];
    $m->SMTPAuth   = true;
    $m->Username   = $p['user'];
    $m->Password   = $p['pass'];
    $m->SMTPSecure = $p['secure'];
    $m->Port       = $p['port'];
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(403);
    echo "Un problème est survenu, veuillez réessayer.";
    exit;
}

// --- Kill-switch : couper le formulaire sans redéploiement (ex. attaque en cours) ---
$disabled = strtolower((string) (contact_env('CONTACT_FORM_DISABLED') ?? ''));
if (in_array($disabled, ['1', 'true', 'yes', 'on'], true)) {
    contact_log('killswitch');
    http_response_code(503);
    echo "Le formulaire est momentanément indisponible. Merci d'écrire directement à contact@theo-birost.fr.";
    exit;
}

// --- Rate limit par IP ---
if (contact_rate_limited(contact_client_ip())) {
    contact_log('rate_limited');
    http_response_code(429);
    echo "Trop de tentatives. Merci de réessayer plus tard.";
    exit;
}

// --- Honeypot : on répond OK sans rien envoyer ---
if (!empty($_POST['website'])) {
    contact_log('honeypot');
    http_response_code(200);
    echo "Merci ! Votre demande a bien été envoyée.";
    exit;
}

// --- Jeton signé HMAC + délai minimum ---
$ts    = $_POST['ts'] ?? '';
$token = $_POST['token'] ?? '';
$expectedToken = hash_hmac('sha256', (string) $ts, contact_env('CONTACT_FORM_SECRET') ?? '');

if (!ctype_digit((string) $ts) || !hash_equals($expectedToken, (string) $token)) {
    http_response_code(400);
    echo "Requête invalide. Merci de recharger la page et réessayer.";
    exit;
}
$elapsed = time() - (int) $ts;
if ($elapsed > 3600) {
    http_response_code(400);
    echo "Formulaire expiré. Merci de recharger la page et réessayer.";
    exit;
}
if ($elapsed < 3) {
    http_response_code(200);
    echo "Merci ! Votre demande a bien été envoyée.";
    exit;
}

// --- Altcha : captcha auto-hébergé (preuve de travail + signature serveur) ---
if (!contact_altcha_check((string) ($_POST['altcha'] ?? ''), contact_env('CONTACT_FORM_SECRET') ?? '')) {
    contact_log('altcha_fail');
    http_response_code(400);
    echo "Vérification anti-robot échouée. Merci de recharger la page et réessayer.";
    exit;
}

// --- Validation des champs ---
$name    = str_replace(["\r", "\n"], '', strip_tags(trim($_POST["name"] ?? '')));
$email   = filter_var(trim($_POST["email"] ?? ''), FILTER_SANITIZE_EMAIL);
$message = trim($_POST["message"] ?? '');

// Champs à choix fermé : on n'accepte que des valeurs de la liste blanche.
$offreAllowed = ['Offre 1 — Site vitrine', 'Offre 2 — Boutique en ligne', 'Offre 3 — Application web', 'Devis sur-mesure'];
$maintAllowed = ['Suivi mensuel (39 €/mois)', "Pack d'heures", "On verra plus tard / besoin d'infos"];
$hebergAllowed = ['Sur mon propre serveur / hébergeur', 'Essentiel · VPS-1 (45 €/mois)', 'Pro · VPS-2 (69 €/mois)'];
$offre       = in_array($_POST["offre"] ?? '', $offreAllowed, true) ? $_POST["offre"] : '';
$maintenance = in_array($_POST["maintenance"] ?? '', $maintAllowed, true) ? $_POST["maintenance"] : '';
$hebergement = in_array($_POST["hebergement"] ?? '', $hebergAllowed, true) ? $_POST["hebergement"] : '';

// Détail du pack d'heures — prix recalculé côté serveur (tarifs dégressifs : <5h = 39, 5-9h = 35, 10h+ = 32).
if ($maintenance === "Pack d'heures") {
    $hourRate = function (int $h): int { return $h < 5 ? 39 : ($h <= 9 ? 35 : 32); };
    $pack = $_POST['pack'] ?? '';
    if ($pack === '5') {
        $maintenance = 'Pack 5 h (175 €)';
    } elseif ($pack === '10') {
        $maintenance = 'Pack 10 h (320 €)';
    } elseif ($pack === 'autre') {
        $h = (int) ($_POST['hours'] ?? 0);
        if ($h >= 1 && $h <= 500) {
            $r = $hourRate($h);
            $maintenance = $h . ' h (≈ ' . ($h * $r) . ' €, ' . $r . ' €/h)';
        }
    }
}

if (
    $name === '' || mb_strlen($name) > 100
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
    || $message === '' || mb_strlen($message) < 10 || mb_strlen($message) > 5000
) {
    http_response_code(400);
    echo "Veuillez remplir tous les champs correctement et réessayer.";
    exit;
}

// --- Anti-spam / anti-phishing : scoring ---
$spam = contact_spam_assessment($name, $email, $message);

// Spam évident : on répond OK (comme le honeypot) sans rien envoyer.
if ($spam['block']) {
    contact_log('spam_block', ['score' => $spam['score'], 'reasons' => $spam['reasons']]);
    http_response_code(200);
    echo "Merci ! Votre demande a bien été envoyée. Je vous réponds sous 48h.";
    exit;
}

// --- Plafond global d'e-mails / 24 h (garde-fou anti-abus, toutes IP confondues) ---
$dailyCap = (int) (contact_env('CONTACT_FORM_DAILY_CAP') ?? '30');
if (contact_global_cap_reached($dailyCap)) {
    contact_log('cap_reached', ['cap' => $dailyCap]);
    http_response_code(429);
    echo "Le formulaire a atteint sa limite d'envois pour aujourd'hui. Merci d'écrire directement à contact@theo-birost.fr.";
    exit;
}

$profiles = contact_smtp_profiles();
if (empty($profiles)) {
    contact_log('config_error', ['error' => 'aucun profil SMTP configuré']);
    http_response_code(500);
    echo "Le message n'a pas pu être envoyé. Merci de réessayer ou d'écrire directement à contact@theo-birost.fr.";
    exit;
}

// Destinataire des notifications (par défaut la boîte OVH ; surchargeable via CONTACT_TO).
$recipient = contact_env('CONTACT_TO') ?? 'contact@theo-birost.fr';

$subjectOffre = $offre !== '' ? " [$offre]" : '';
$bodyHtml = contact_render_email_html($name, $email, $message, $offre, $maintenance, $hebergement);
$bodyText = "Nouvelle demande depuis birostweb.fr\n\n"
    . "Nom : $name\n"
    . "Email : $email\n"
    . "Offre : " . ($offre !== '' ? $offre : 'Non précisée') . "\n"
    . "Maintenance : " . ($maintenance !== '' ? $maintenance : 'Non précisée') . "\n"
    . "Hébergement : " . ($hebergement !== '' ? $hebergement : 'Non précisé') . "\n\n"
    . "Message :\n$message";

// On tente chaque profil SMTP dans l'ordre : le 1er qui envoie l'emporte, sinon on bascule sur le suivant.
$sent = false;
$usedProfile = null;
foreach ($profiles as $i => $p) {
    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        contact_apply_smtp($mail, $p);

        // L'expéditeur DOIT être le compte authentifié (OVH comme Gmail l'exigent) ; le visiteur va en Reply-To.
        $mail->setFrom($p['user'], 'Formulaire birostweb.fr');
        $mail->addAddress($recipient, 'Théo Birost');
        $mail->addReplyTo($email, $name);
        $mail->addCustomHeader('X-Mail-Source', 'birostweb.fr');

        $mail->isHTML(true);
        $mail->Subject = "[birostweb.fr] Nouvelle demande de $name$subjectOffre";

        // Message jugé douteux : on le marque pour que la boîte le filtre en indésirables.
        if ($spam['flag']) {
            $mail->Subject = '[⚠ SPAM?] ' . $mail->Subject;
            $mail->addCustomHeader('X-Spam-Flag', 'YES');
            $mail->addCustomHeader('X-Spam-Score', (string) $spam['score']);
            $mail->addCustomHeader('X-Spam-Reasons', substr(implode('; ', $spam['reasons']), 0, 200));
            $mail->Priority = 5;
        }

        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;
        $mail->send();

        $sent = true;
        $usedProfile = $p;
        contact_log('sent', ['profile' => $i, 'host' => $p['host'], 'offre' => $offre, 'maintenance' => $maintenance, 'spam_flag' => $spam['flag']]);
        break;
    } catch (Exception $e) {
        contact_log('send_error', ['profile' => $i, 'host' => $p['host'], 'error' => substr($e->getMessage(), 0, 200)]);
        // Échec : on tente le profil de secours suivant.
    }
}

if (!$sent) {
    http_response_code(500);
    echo "Le message n'a pas pu être envoyé. Merci de réessayer ou d'écrire directement à contact@theo-birost.fr.";
    exit;
}

// --- Accusé de réception automatique au visiteur (best-effort : n'échoue pas la demande) ---
try {
    $ack = new PHPMailer(true);
    $ack->CharSet = 'UTF-8';
    contact_apply_smtp($ack, $usedProfile);
    $ack->setFrom($usedProfile['user'], 'Théo Birost — Birostweb');
    $ack->addAddress($email, $name);
    $ack->addReplyTo($recipient, 'Théo Birost');
    $ack->isHTML(true);
    $ack->Subject = 'Bien reçu — je reviens vers vous sous 48h';
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $ack->Body = <<<HTML
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="margin:0;background-color:#E5E2D6;font-family:'Helvetica Neue',Arial,sans-serif;padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background-color:#FBFAF6;border:1px solid #CFCABC;border-radius:12px;overflow:hidden;">
<tr><td style="background-color:#231F20;padding:24px 30px;">
<span style="display:inline-block;width:9px;height:9px;background-color:#F0451E;border-radius:50%;"></span>
<span style="color:#F0EDE4;font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:600;margin-left:8px;">Birostweb</span>
<div style="color:#F0EDE4;font-size:20px;font-weight:700;margin-top:12px;">Bien reçu, merci !</div>
</td></tr>
<tr><td style="padding:26px 30px;color:#231F20;font-size:15px;line-height:1.6;">
Bonjour {$safeName},<br><br>
Merci pour votre message, je l'ai bien reçu. Je reviens vers vous <b>sous 48h</b> (jours ouvrés) avec une première réponse ou un devis.<br><br>
Si vous avez un document ou une précision à ajouter, répondez simplement à cet email.<br><br>
À très vite,<br><b>Théo Birost</b><br>
<a href="https://birostweb.fr" style="color:#F0451E;text-decoration:none;">birostweb.fr</a>
</td></tr></table></td></tr></table>
</body></html>
HTML;
    $ack->AltBody = "Bonjour $name,\n\nMerci pour votre message, je l'ai bien reçu. Je reviens vers vous sous 48h (jours ouvrés).\n\nÀ très vite,\nThéo Birost — birostweb.fr";
    $ack->send();
} catch (Exception $e) {
    // Ignoré : la notification principale, elle, est bien partie.
}

http_response_code(200);
echo "Merci ! Votre demande a bien été envoyée. Je vous réponds sous 48h.";
