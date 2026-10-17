<?php
// ============================================================
//  Endpoint de challenge Altcha (captcha auto-hébergé, sans tiers).
//  Le client résout une preuve de travail, puis send_mail.php vérifie
//  la solution + la signature serveur (HMAC avec CONTACT_FORM_SECRET).
// ============================================================
require __DIR__ . '/vendor/autoload.php';
try {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
} catch (\Throwable $e) {
    // Pas de .env en prod : les variables viennent de l'environnement.
}

$secret = $_ENV['CONTACT_FORM_SECRET'] ?? $_SERVER['CONTACT_FORM_SECRET'] ?? getenv('CONTACT_FORM_SECRET') ?: '';

if (strlen((string) $secret) < 32) {
    http_response_code(503);
    exit;
}

// Limite généreuse par IP (60 défis / heure) : borne l'abus du générateur sans gêner l'usage normal.
// Si le stockage d'état est indisponible, on ne bloque pas (send_mail.php reste fail-closed).
require __DIR__ . '/inc/contact_lib.php';
try {
    $rlFile = contact_state_dir('altcha_rl') . '/' . hash_hmac('sha256', contact_client_ip(), (string) $secret) . '.json';
    $rh = @fopen($rlFile, 'c+');
    if ($rh && flock($rh, LOCK_EX)) {
        $ts = json_decode(stream_get_contents($rh) ?: '[]', true);
        $now = time();
        $ts = array_values(array_filter(is_array($ts) ? $ts : [], static fn ($t) => is_int($t) && $t > $now - 3600));
        $over = count($ts) >= 60;
        if (!$over) {
            $ts[] = $now;
            ftruncate($rh, 0);
            rewind($rh);
            fwrite($rh, json_encode($ts));
            fflush($rh);
        }
        flock($rh, LOCK_UN);
        fclose($rh);
        if ($over) {
            http_response_code(429);
            header('Retry-After: 600');
            exit;
        }
    }
} catch (\Throwable $e) {
    // fail-open volontaire
}

$maxnumber = 100000;                                  // difficulté (résolu en une fraction de seconde)
$number    = random_int(0, $maxnumber);
$salt      = bin2hex(random_bytes(12)) . '?expires=' . (time() + 3600); // valable 1 h
$challenge = hash('sha256', $salt . $number);
$signature = hash_hmac('sha256', $challenge, (string) $secret);

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'algorithm' => 'SHA-256',
    'challenge' => $challenge,
    'maxnumber' => $maxnumber,
    'salt'      => $salt,
    'signature' => $signature,
]);
