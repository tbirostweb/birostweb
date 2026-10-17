<?php
// TOTP : vecteur RFC 6238 (SHA-1, secret "12345678901234567890", T=59 => 94287082 ; 6 chiffres 287082).
putenv('ADMIN_TOTP_SECRET=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
$d = sys_get_temp_dir() . '/bwtotp' . bin2hex(random_bytes(3));
putenv("CONTACT_STATE_DIR=$d");
$_SERVER['HTTPS'] = 'on';
define('X', 1);
require __DIR__ . '/../site/inc/content_store.php';
require __DIR__ . '/../site/inc/contact_lib.php';
// On charge uniquement les fonctions TOTP (le bootstrap complet démarre une session).
$src = file_get_contents(__DIR__ . '/../site/admin/_bootstrap.php');
preg_match('#/\* ---- 2FA TOTP.*?(?=/\* ---- Helpers vue)#s', $src, $m);
eval('?><?php ' . $m[0]);
$fail = 0;
$t = function ($l, $ok) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . "$l\n"; $fail += $ok ? 0 : 1; };
$t('RFC 6238 vecteur', admin_totp_code(admin_base32_decode(admin_totp_secret()), intdiv(59, 30)) === '287082');
$t('code valide accepté', admin_totp_verify('287082', 59));
$t('rejeu refusé', !admin_totp_verify('287082', 59));
$t('code faux refusé', !admin_totp_verify('000000', 1000000));
$t('format invalide refusé', !admin_totp_verify('12ab56', 59));
array_map('unlink', glob("$d/admin_totp/*")); @rmdir("$d/admin_totp"); @rmdir($d);
exit($fail ? 1 : 0);
