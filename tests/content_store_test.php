<?php
// Le seed ne doit être persisté que si le contenu est prouvé absent (jamais sur panne ou JSON illisible).
require __DIR__ . '/../site/inc/content_store.php';

final class FakeBackend implements ContentStoreBackend
{
    public int $writes = 0;
    public function __construct(private ?string $raw, private bool $failed = false) {}
    public function read(): ?string { return $this->raw; }
    public function lastReadFailed(): bool { return $this->failed; }
    public function write(string $json): void { $this->writes++; }
}

$fail = 0;
$check = function (string $label, bool $ok) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; $fail += $ok ? 0 : 1; };

$b = new FakeBackend(null, true);
$d = (new ContentStore($b))->get();
$check('panne de lecture : seed en mémoire, 0 écriture', $b->writes === 0 && isset($d['offers']));

$b = new FakeBackend('{pas du json');
(new ContentStore($b))->get();
$check('JSON illisible : 0 écriture', $b->writes === 0);

$b = new FakeBackend(null, false);
(new ContentStore($b))->get();
$check('contenu absent : seed amorcé (1 écriture)', $b->writes === 1);

$b = new FakeBackend('{"offers":[]}');
(new ContentStore($b))->get();
$check('contenu valide : 0 écriture', $b->writes === 0);
exit($fail ? 1 : 0);
