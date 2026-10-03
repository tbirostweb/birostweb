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
