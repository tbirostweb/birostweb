<?php
// Migration Bunny Storage -> stockage local : import sans perte, jamais de seed sur panne, sauvegardes,
// outil content-migrate (status / pull / push). Un faux Bunny Storage tourne sur 127.0.0.1 (http autorisé en test).
require __DIR__ . '/../site/inc/content_store.php';

$fail = 0;
$check = function (string $label, bool $ok) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; $fail += $ok ? 0 : 1; };

$tmp = sys_get_temp_dir() . '/bwmig' . bin2hex(random_bytes(3));
mkdir("$tmp/bunny", 0700, true);
$router = "$tmp/router.php";
file_put_contents($router, <<<'PHP'
<?php
$dir = __DIR__ . '/bunny';
$mode = @file_get_contents(__DIR__ . '/mode') ?: 'ok';
$file = $dir . '/' . md5($_SERVER['REQUEST_URI']);
if ($_SERVER['REQUEST_METHOD'] === 'PUT') { file_put_contents($file, file_get_contents('php://input')); http_response_code(201); exit; }
if ($mode === 'down') { http_response_code(503); exit; }
if (!is_file($file)) { http_response_code(404); exit; }
echo file_get_contents($file);
PHP);
$port = 18000 + random_int(0, 900);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);

putenv("BUNNY_STORAGE_ZONE=zone");
putenv("BUNNY_STORAGE_KEY=cle-de-test");
putenv("BUNNY_STORAGE_ENDPOINT=127.0.0.1:$port");
putenv("CONTENT_CACHE_DIR=$tmp/cache");
putenv("CONTENT_BACKEND=local");
$mode = fn (string $m) => file_put_contents("$tmp/mode", $m);
$remoteRaw = fn () => (($f = glob("$tmp/bunny/*")) ? file_get_contents($f[0]) : null);
$real = json_encode(['version' => 1, 'offers' => [['id' => 'vraie-offre', 'promo' => ['active' => true]]], 'banner' => ['active' => true]]);

// 1. Local absent + Bunny OK : import, copie locale, sauvegarde, Bunny intact.
(new BunnyStore())->write($real);
$dir1 = "$tmp/d1"; putenv("DATA_DIR=$dir1");
$c = cs_make_store()->get();
$check('import : le contenu Bunny est servi', ($c['offers'][0]['id'] ?? '') === 'vraie-offre');
$check('import : copie locale identique', @file_get_contents("$dir1/content.json") === $real);
$check('import : sauvegarde « import-bunny » créée', count(glob("$dir1/backups/*import-bunny*") ?: []) === 1);
$check('import : Bunny non modifié', $remoteRaw() === $real);

// 2. Local présent : Bunny n'est plus lu (même en panne).
$mode('down');
$c = cs_make_store()->get();
$check('local présent : servi même si Bunny est en panne', ($c['offers'][0]['id'] ?? '') === 'vraie-offre');

// 3. Local absent + Bunny en panne : seed servi en mémoire, RIEN écrit.
$dir3 = "$tmp/d3"; putenv("DATA_DIR=$dir3");
$c = cs_make_store()->get();
$check('Bunny en panne : seed en mémoire', isset($c['offers']) && ($c['offers'][0]['id'] ?? '') !== 'vraie-offre');
$check('Bunny en panne : aucun fichier local écrit', !is_file("$dir3/content.json"));

// 4. Local absent + Bunny JSON invalide : rien écrit.
$mode('ok');
(new BunnyStore())->write('{pas du json');
cs_make_store()->get();
$check('Bunny invalide : aucun fichier local écrit', !is_file("$dir3/content.json"));
(new BunnyStore())->write($real);

// 5. Sans clés Bunny, local absent : seed amorcé localement (contenu prouvé absent).
putenv('BUNNY_STORAGE_KEY');
$dir5 = "$tmp/d5"; putenv("DATA_DIR=$dir5");
cs_make_store()->get();
$check('sans Bunny : seed écrit localement', is_file("$dir5/content.json"));
putenv('BUNNY_STORAGE_KEY=cle-de-test');

// 6. Écriture locale : la version précédente est sauvegardée ; rotation à BACKUP_KEEP.
$ls = new LocalFileStore($dir1);
for ($i = 0; $i < LocalFileStore::BACKUP_KEEP + 5; $i++) {
    $ls->write(json_encode(['offers' => [], 'n' => $i]));
}
$check('sauvegardes plafonnées à ' . LocalFileStore::BACKUP_KEEP, count(glob("$dir1/backups/content-*.json") ?: []) === LocalFileStore::BACKUP_KEEP);
$check('dossier de données privé (0700)', (fileperms($dir1) & 0777) === 0700 && (fileperms("$dir1/content.json") & 0777) === 0600);

// 7. Fichier local illisible : jamais réamorcé ni écrasé.
$dir7 = "$tmp/d7"; mkdir($dir7, 0700); file_put_contents("$dir7/content.json", $real); chmod("$dir7/content.json", 0000);
putenv("DATA_DIR=$dir7");
if (is_readable("$dir7/content.json")) {
    echo "SKIP fichier illisible (exécuté en root)\n";
} else {
    cs_make_store()->get();
    chmod("$dir7/content.json", 0600);
    $check('local illisible : contenu conservé', file_get_contents("$dir7/content.json") === $real);
}

// 8. Outil content-migrate.
$tool = __DIR__ . '/../tools/content-migrate.php';
$run = function (string $args, string $dataDir) use ($tool) {
    putenv("DATA_DIR=$dataDir");
    exec('php ' . escapeshellarg($tool) . ' ' . $args . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$dir8 = "$tmp/d8";
[$code, $o] = $run('pull', $dir8);
$check('pull : copie Bunny -> local', $code === 0 && @file_get_contents("$dir8/content.json") === $real);
[$code, $o] = $run('status', $dir8);
$check('status : identiques, sans afficher la clé', str_contains($o, 'IDENTIQUES') && !str_contains($o, 'cle-de-test'));
(new LocalFileStore($dir8))->write(json_encode(['offers' => [['id' => 'modif-locale']]]));
[$code, $o] = $run('pull', $dir8);
$check('pull : refuse d\'écraser un local différent sans --force', $code === 1 && str_contains((string) file_get_contents("$dir8/content.json"), 'modif-locale'));
[$code, $o] = $run('push', $dir8);
$check('push : refusé sans --force', $code === 1 && $remoteRaw() === $real);
[$code, $o] = $run('push --force', $dir8);
$check('push --force : Bunny = local + ancienne version sauvegardée', $code === 0 && str_contains((string) $remoteRaw(), 'modif-locale')
    && count(glob("$dir8/backups/*bunny-avant-push*") ?: []) === 1);
$mode('down');
[$code, $o] = $run('pull --force', $dir8);
$check('pull : Bunny en panne, rien modifié', $code === 1 && str_contains((string) file_get_contents("$dir8/content.json"), 'modif-locale'));

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
