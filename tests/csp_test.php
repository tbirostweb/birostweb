<?php
// Vérifie que les scripts inline exécutables de index.php sont couverts par la CSP de .htaccess.
$html = file_get_contents(__DIR__ . '/../site/index.php');
$htaccess = file_get_contents(__DIR__ . '/../site/.htaccess');
preg_match_all('#<script(?![^>]*\bsrc=)(?![^>]*type="application/ld\+json")[^>]*>(.*?)</script>#s', $html, $m);
$fail = 0;
foreach ($m[1] as $i => $code) {
    $h = base64_encode(hash('sha256', $code, true));
    $ok = strpos($htaccess, "'sha256-$h'") !== false;
    echo ($ok ? 'PASS' : 'FAIL') . " script inline #$i couvert par la CSP\n";
    $fail += $ok ? 0 : 1;
}
foreach (['og-image.png' => [1200, 630], 'apple-touch-icon.png' => [180, 180], 'favicon.png' => [640, 640]] as $f => $d) {
    $s = @getimagesize(__DIR__ . "/../site/$f");
    $ok = $s && $s[0] === $d[0] && $s[1] === $d[1] && $s['mime'] === 'image/png';
    echo ($ok ? 'PASS' : 'FAIL') . " $f PNG {$d[0]}x{$d[1]}\n";
    $fail += $ok ? 0 : 1;
}
exit($fail ? 1 : 0);
