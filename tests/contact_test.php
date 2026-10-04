<?php
// Tests de régression unitaires : php tests/contact_test.php
require __DIR__ . '/../site/inc/contact_lib.php';

$fail = 0;
function check(string $name, bool $cond): void
{
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$cond) { $fail++; }
}
function expect_exception(string $name, callable $f): void
{
    try { $f(); check($name, false); } catch (ContactStorageException $e) { check($name, true); }
}

$secret = str_repeat('s', 40);
$tmp = sys_get_temp_dir() . '/contact_test_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
putenv("CONTACT_STATE_DIR=$tmp");
putenv("CONTACT_FORM_SECRET=$secret");

// --- IP / proxy de confiance ---
$px = ['10.0.0.0/8', '172.16.0.0/12'];
check('XFF forgé depuis un client direct ignoré', contact_client_ip_from('203.0.113.9', '1.2.3.4', $px) === '203.0.113.9');
check('XFF via proxy de confiance : IP client', contact_client_ip_from('172.18.0.2', '198.51.100.7', $px) === '198.51.100.7');
check('XFF falsifié à gauche : on retient l\'IP vue par le proxy', contact_client_ip_from('172.18.0.2', '9.9.9.9, 198.51.100.7', $px) === '198.51.100.7');
check('XFF malformé : REMOTE_ADDR', contact_client_ip_from('172.18.0.2', 'abc', $px) === '172.18.0.2');
check('CIDR IPv6', contact_ip_in_cidr('fd00::1', 'fc00::/7') && !contact_ip_in_cidr('2001:db8::1', 'fc00::/7'));
check('Pseudonymisation IPv4', contact_ip_pseudonymize('198.51.100.7') === '198.51.100.0');

// --- Quota : XFF falsifié = même quota ---
$ipA = contact_client_ip_from('203.0.113.9', '1.1.1.1', $px);
$ipB = contact_client_ip_from('203.0.113.9', '2.2.2.2', $px);
for ($i = 0; $i < 5; $i++) { contact_rate_limited($ipA, 5, 3600); }
check('Quota partagé malgré XFF changeant', $ipA === $ipB && contact_rate_limited($ipB, 5, 3600) === true);

// --- Stockage absent : fail closed ---
putenv('CONTACT_STATE_DIR=/dev/null/inexistant');
expect_exception('Quota IP : stockage absent => exception', fn () => contact_rate_limited('203.0.113.1'));
expect_exception('Plafond global : stockage absent => exception', fn () => contact_global_cap_reached(30));

// --- Altcha ---
function make_payload(string $secret, int $expires): string
{
    $salt = bin2hex(random_bytes(12)) . '?expires=' . $expires;
    $n = random_int(0, 1000);
    $challenge = hash('sha256', $salt . $n);
    return base64_encode(json_encode([
        'algorithm' => 'SHA-256', 'challenge' => $challenge, 'number' => $n, 'salt' => $salt,
        'signature' => hash_hmac('sha256', $challenge, $secret),
    ]));
}
expect_exception('Altcha : stockage absent => exception (aucune acceptation)', fn () => contact_altcha_check(make_payload($secret, time() + 600), $secret));
putenv("CONTACT_STATE_DIR=$tmp");
$p = make_payload($secret, time() + 600);
check('Altcha : 1re soumission acceptée', contact_altcha_check($p, $secret) === true);
check('Altcha : rejeu refusé', contact_altcha_check($p, $secret) === false);
check('Altcha : expiré refusé', contact_altcha_check(make_payload($secret, time() - 5), $secret) === false);
check('Altcha : signature altérée refusée', contact_altcha_check(make_payload('autre-secret-autre-secret-autre-secret', time() + 600), $secret) === false);
check('Altcha : secret absent/court refusé', contact_altcha_check($p, '') === false && contact_altcha_check(make_payload('court', time() + 600), 'court') === false);

// --- Concurrence : 8 processus, une seule acceptation ---
$p2 = make_payload($secret, time() + 600);
$code = '<?php require "' . __DIR__ . '/../site/inc/contact_lib.php"; putenv("CONTACT_STATE_DIR=' . $tmp . '"); echo contact_altcha_check($argv[1], $argv[2]) ? "1" : "0";';
file_put_contents("$tmp/w.php", $code);
$procs = [];
for ($i = 0; $i < 8; $i++) {
    $procs[] = proc_open([PHP_BINARY, "$tmp/w.php", $p2, $secret], [1 => ['pipe', 'w']], $pipes[$i]);
}
$ok = 0;
foreach ($procs as $i => $pr) { $ok += (int) stream_get_contents($pipes[$i][1]); proc_close($pr); }
check("Altcha : 8 soumissions concurrentes => 1 acceptation (obtenu $ok)", $ok === 1);

// --- Types / SMTP ---
check('POST tableau rejeté', contact_post_all_scalar(['name' => ['x']]) === false && contact_post_all_scalar(['name' => 'x']) === true);
check('SMTP : timeout ambigu => pas de secours', !contact_smtp_error_is_safe_to_retry('SMTP Error: Timeout'));
check('SMTP : connexion impossible => secours OK', contact_smtp_error_is_safe_to_retry('SMTP Error: Could not connect to SMTP host.'));
check('Code erreur stable, sans message brut', contact_smtp_error_code('SMTP Error: Could not authenticate. user@x') === 'smtp_auth');

// --- Logs : pas de query, IP pseudonymisée ---
putenv("CONTACT_LOG_DIR=$tmp/logs");
$_SERVER['REQUEST_URI'] = '/send_mail.php?token=SECRETFICTIF';
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
contact_log('test');
$log = (string) file_get_contents(glob("$tmp/logs/contact_form-*.log")[0]);
check('Log : token de query absent', strpos($log, 'SECRETFICTIF') === false && strpos($log, '/send_mail.php') !== false);
check('Log : IP pseudonymisée', strpos($log, '198.51.100.7') === false && strpos($log, '198.51.100.0') !== false);

exec('rm -rf ' . escapeshellarg($tmp));
echo $fail === 0 ? "OK\n" : "$fail ECHEC(S)\n";
exit($fail === 0 ? 0 : 1);
