<?php

declare(strict_types=1);

/*
 * Couche de stockage de contenu du site (offres, promos, …).
 *
 * Un document de contenu = un tableau PHP sérialisé en JSON.
 * ContentStore expose get(): array et save(array): void, avec un petit cache
 * mémoire par requête. Deux backends interchangeables via l'env CONTENT_BACKEND :
 *   - local  : fichier DATA_DIR/content.json (écriture atomique + sauvegardes horodatées).
 *              En production, DATA_DIR est un volume Docker persistant hors de la racine web.
 *              Tant que les clés Bunny Storage sont présentes, un contenu local absent est
 *              importé depuis Bunny (lecture seule) au lieu d'être réamorcé (migration).
 *   - bunny  : ancien stockage Bunny Storage (conservé pour la migration et le retour arrière).
 *
 * Ce fichier ne charge PAS Dotenv : l'appelant (index.php / admin) a déjà
 * peuplé l'environnement. On lit juste getenv()/$_ENV/$_SERVER.
 */

/** Lecture robuste d'une variable d'environnement (quel que soit variables_order). */
function cs_env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
}

/** Erreur d'écriture du contenu : l'admin doit la remonter (fail-closed côté écriture). */
class ContentStoreException extends RuntimeException
{
}

/* ============================================================
 *  Backends de stockage
 * ============================================================ */

interface ContentStoreBackend
{
    /** Renvoie le JSON brut, ou null s'il n'existe pas encore. */
    public function read(): ?string;

    /** Écrit le JSON brut (atomique). Lève ContentStoreException en cas d'échec. */
    public function write(string $json): void;
}

/**
 * Backend local : DATA_DIR/content.json.
 * Crée le dossier en 0700 et y dépose un .htaccess "Require all denied"
 * pour empêcher tout accès HTTP direct au contenu.
 */
final class LocalFileStore implements ContentStoreBackend
{
    /** Nombre de sauvegardes horodatées conservées dans DATA_DIR/backups. */
    public const BACKUP_KEEP = 30;

    private string $dir;
    /** True si le fichier existe mais n'a pas pu être lu : le contenu n'est PAS prouvé absent. */
    private bool $lastReadFailed = false;

    public function __construct(?string $dir = null)
    {
        $dir = $dir ?? cs_env('DATA_DIR');
        if ($dir === null || $dir === '') {
            // Défaut : site/data (ce fichier est dans site/inc/).
            $dir = dirname(__DIR__) . '/data';
        }
        $this->dir = rtrim($dir, '/');
    }

    public function path(): string
    {
        return $this->dir . '/content.json';
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function lastReadFailed(): bool
    {
        return $this->lastReadFailed;
    }

    /**
     * Copie horodatée d'un contenu dans DATA_DIR/backups (0600), puis ne garde que les
     * BACKUP_KEEP plus récentes. Best-effort : ne lève jamais.
     */
    public function backup(string $json, string $tag = 'save'): ?string
    {
        $dir = $this->dir . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        $tag = preg_replace('/[^a-z0-9-]+/', '', strtolower($tag)) ?: 'save';
        $file = $dir . '/content-' . gmdate('Ymd-His') . '-' . $tag . '-' . bin2hex(random_bytes(3)) . '.json';
        if (@file_put_contents($file, $json, LOCK_EX) === false) {
            return null;
        }
        @chmod($file, 0600);
        $all = glob($dir . '/content-*.json') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - self::BACKUP_KEEP)) as $old) {
            @unlink($old);
        }
        return $file;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                throw new ContentStoreException('DATA_DIR introuvable et non créable : ' . $this->dir);
            }
        }
        if (!is_writable($this->dir)) {
            throw new ContentStoreException('DATA_DIR non inscriptible : ' . $this->dir);
        }
        // Garde-fou : jamais servi par Apache.
        $ht = $this->dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Données applicatives : jamais servies directement.\nRequire all denied\n");
            @chmod($ht, 0600);
        }
    }

    public function read(): ?string
    {
        $this->lastReadFailed = false;
        $file = $this->path();
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            // Fichier présent mais illisible (droits, disque) : ne jamais le réamorcer.
            $this->lastReadFailed = true;
            return null;
        }
        return $raw;
    }

    public function write(string $json): void
    {
        $this->ensureDir();
        $file = $this->path();
        // Sauvegarde de la version précédente avant de la remplacer.
        if (is_file($file)) {
            $prev = @file_get_contents($file);
            if ($prev !== false && $prev !== '' && $prev !== $json) {
                $this->backup($prev, 'avant-enregistrement');
            }
        }
        // Écriture atomique : fichier temporaire dans le même dossier + rename().
        $tmp = $this->dir . '/.content.' . bin2hex(random_bytes(6)) . '.tmp';
        $h = @fopen($tmp, 'wb');
        if ($h === false) {
            throw new ContentStoreException('Écriture impossible (fopen) : ' . $tmp);
        }
        $ok = (fwrite($h, $json) !== false) && fflush($h);
        fclose($h);
        if (!$ok) {
            @unlink($tmp);
            throw new ContentStoreException('Écriture impossible (fwrite) : ' . $tmp);
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new ContentStoreException('Renommage atomique impossible vers : ' . $file);
        }
    }
}

/**
 * Backend Bunny Storage.
 *
 * Le contenu vit dans une Storage Zone Bunny (pas de volume Docker : le conteneur
 * est jetable). On lit/écrit un objet JSON (défaut `data/content.json`) via l'API
 * HTTP Bunny Storage :
 *   - Base  : https://{BUNNY_STORAGE_ENDPOINT}/{BUNNY_STORAGE_ZONE}/{path}
 *   - Auth  : en-tête  AccessKey: {BUNNY_STORAGE_KEY}
 *   - GET lit (200) / PUT écrit, corps = contenu (201) / 404 si absent / 401 si clé invalide.
 *
 * Deux mécanismes protègent le rendu public :
 *   - CACHE disque court (TTL) : évite de taper Bunny à chaque visite.
 *   - ANTI-PANNE en lecture : si Bunny est injoignable (réseau/401/5xx), on sert
 *     le dernier cache (même périmé), sinon null (le ContentStore amorcera le seed).
 *     read() ne lève JAMAIS — le site public ne doit pas casser.
 *
 * En écriture (admin), au contraire, un échec lève ContentStoreException : l'admin
 * doit savoir que sa sauvegarde n'a PAS été persistée.
 *
 * NB prod : BUNNY_STORAGE_ENDPOINT DOIT être un hôte https (ex. storage.bunnycdn.com).
 * Le schéma http n'est utilisé que pour un hôte de test local (127.0.0.1, localhost,
 * ::1, *.localhost, *.test) — jamais en prod.
 */
final class BunnyStore implements ContentStoreBackend
{
    private string $zone;
    private string $key;
    private string $endpoint;
    private string $object;
    private string $cacheFile;
    private int $cacheTtl;
    /** True si la dernière lecture a échoué (réseau/401/5xx) : le contenu n'est PAS prouvé absent. */
    private bool $lastReadFailed = false;

    public function __construct()
    {
        $this->zone     = cs_env('BUNNY_STORAGE_ZONE', '') ?? '';
        $this->key      = cs_env('BUNNY_STORAGE_KEY', '') ?? '';
        // Hôte de base de l'API Storage (région). Défaut : Main (Frankfurt).
        $this->endpoint = rtrim(cs_env('BUNNY_STORAGE_ENDPOINT', 'storage.bunnycdn.com') ?? 'storage.bunnycdn.com', '/');
        // Chemin de l'objet dans la zone, configurable. Défaut : data/content.json.
        $this->object   = ltrim(cs_env('BUNNY_CONTENT_PATH', 'data/content.json') ?? 'data/content.json', '/');

        $cacheDir = rtrim(cs_env('CONTENT_CACHE_DIR') ?? sys_get_temp_dir(), '/');
        // Clé de cache dérivée de la zone + chemin (évite les collisions entre zones).
        $tag = substr(sha1($this->endpoint . '|' . $this->zone . '|' . $this->object), 0, 12);
        $this->cacheFile = $cacheDir . '/bw_content_cache_' . $tag . '.json';
        $this->cacheTtl  = max(0, (int) (cs_env('CONTENT_CACHE_TTL', '60') ?? '60'));
    }

    /** True si l'hôte est un hôte de test local (http toléré) ; sinon prod → https obligatoire. */
    private function isLocalTestHost(string $endpoint): bool
    {
        $host = strtolower(explode(':', $endpoint, 2)[0]);
        return $host === '127.0.0.1'
            || $host === 'localhost'
            || $host === '::1'
            || substr($host, -10) === '.localhost'
            || substr($host, -5) === '.test';
    }

    /** URL complète de l'objet (https en prod, http uniquement pour un hôte de test local). */
    private function url(): string
    {
        $scheme = $this->isLocalTestHost($this->endpoint) ? 'http' : 'https';
        // On préserve les slashes du chemin (segments déjà « propres »).
        return $scheme . '://' . $this->endpoint . '/' . rawurlencode($this->zone) . '/' . $this->object;
    }

    /**
     * Exécute une requête cURL vers Bunny.
     * @return array{status:int, body:string, error:?string}
     */
    private function request(string $method, ?string $body = null): array
    {
        $ch = curl_init($this->url());
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        $headers = ['AccessKey: ' . $this->key, 'Accept: application/json'];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch) ?: 'curl error';
            curl_close($ch);
            return ['status' => 0, 'body' => '', 'error' => $err];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp, 'error' => null];
    }

    /** Journalise un warning (logger du formulaire si dispo, sinon error_log). */
    private function warn(string $message): void
    {
        if (function_exists('contact_log')) {
            // @phpstan-ignore-next-line — fonction définie dans contact_lib.php
            contact_log('content_store_bunny_warning', ['message' => $message]);
            return;
        }
        error_log('[BunnyStore] ' . $message);
    }

    /** Lit le cache disque brut, ou null s'il n'existe pas / illisible. */
    private function cacheRead(): ?string
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }
        $raw = @file_get_contents($this->cacheFile);
        return ($raw === false || $raw === '') ? null : $raw;
    }

    /** True si le cache existe et est plus récent que le TTL. */
    private function cacheFresh(): bool
    {
        if ($this->cacheTtl <= 0 || !is_file($this->cacheFile)) {
            return false;
        }
        $mtime = @filemtime($this->cacheFile);
        return $mtime !== false && ($mtime > time() - $this->cacheTtl);
    }

    /** Écrit le cache de façon atomique et tolérante (ne lève jamais). */
    private function cacheWrite(string $json): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = $dir . '/.bw_content.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return; // cache best-effort : on continue sans planter
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
        }
    }

    /**
     * Lecture résiliente :
     *   1) cache frais (< TTL)         -> servi sans appeler Bunny ;
     *   2) GET Bunny 200               -> rafraîchit le cache + renvoie le corps ;
     *   3) GET Bunny 404               -> null (le ContentStore amorcera le seed via write) ;
     *   4) réseau / 401 / 5xx / autre  -> cache périmé si présent, sinon null (seed).
     * Ne lève JAMAIS : le rendu public doit toujours aboutir.
     */
    public function lastReadFailed(): bool
    {
        return $this->lastReadFailed;
    }

    /** Clés Storage présentes. */
    public function configured(): bool
    {
        return $this->zone !== '' && $this->key !== '';
    }

    /**
     * Lecture directe chez Bunny, SANS cache (migration) :
     *   ok      : 200 et JSON valide avec `offers` ;
     *   absent  : 404 (rien chez Bunny) ;
     *   failed  : réseau, clé, 5xx ou JSON invalide (ne rien importer, ne rien réamorcer).
     * @return array{state:string, body:?string, detail:string}
     */
    public function fetchFresh(): array
    {
        if (!$this->configured()) {
            return ['state' => 'failed', 'body' => null, 'detail' => 'clés Bunny Storage absentes'];
        }
        $res = $this->request('GET');
        if ($res['status'] === 404) {
            return ['state' => 'absent', 'body' => null, 'detail' => 'HTTP 404'];
        }
        if ($res['status'] !== 200) {
            $detail = $res['error'] !== null ? ('réseau : ' . $res['error']) : ('HTTP ' . $res['status']);
            return ['state' => 'failed', 'body' => null, 'detail' => $detail];
        }
        $d = json_decode($res['body'], true);
        if (!is_array($d) || !isset($d['offers']) || !is_array($d['offers'])) {
            return ['state' => 'failed', 'body' => null, 'detail' => 'JSON Bunny invalide'];
        }
        return ['state' => 'ok', 'body' => $res['body'], 'detail' => 'HTTP 200'];
    }

    public function read(): ?string
    {
        $this->lastReadFailed = false;
        if ($this->cacheFresh()) {
            $cached = $this->cacheRead();
            if ($cached !== null) {
                return $cached;
            }
        }

        $res = $this->request('GET');
        $status = $res['status'];

        if ($status === 200) {
            $this->cacheWrite($res['body']);
            return $res['body'];
        }
        if ($status === 404) {
            // Fichier pas encore créé : le ContentStore écrira le seed (init).
            return null;
        }

        // Échec (réseau, 401, 5xx, …) : anti-panne. Le contenu existe peut-être : ne jamais le réamorcer.
        $this->lastReadFailed = true;
        $reason = $res['error'] !== null ? ('réseau: ' . $res['error']) : ('HTTP ' . $status);
        $stale = $this->cacheRead();
        if ($stale !== null) {
            $this->warn('lecture Bunny échouée (' . $reason . ') — service du cache périmé.');
            return $stale;
        }
        $this->warn('lecture Bunny échouée (' . $reason . ') — aucun cache, repli sur le seed.');
        return null;
    }

    /**
     * Écriture (admin) : PUT du JSON vers Bunny. En cas d'échec, lève
     * ContentStoreException (l'admin doit savoir que ça n'a pas persisté).
     * Après un PUT réussi, met immédiatement le cache local à jour (cohérence).
     */
    public function write(string $json): void
    {
        if ($this->zone === '' || $this->key === '') {
            throw new ContentStoreException('Bunny mal configuré : BUNNY_STORAGE_ZONE / BUNNY_STORAGE_KEY manquant.');
        }
        $res = $this->request('PUT', $json);
        $status = $res['status'];
        if ($status === 201 || $status === 200) {
            $this->cacheWrite($json);
            return;
        }
        if ($res['error'] !== null) {
            throw new ContentStoreException('Sauvegarde Bunny impossible (réseau) : ' . $res['error']);
        }
        if ($status === 401) {
            throw new ContentStoreException('Sauvegarde Bunny refusée (401) : clé AccessKey invalide.');
        }
        throw new ContentStoreException('Sauvegarde Bunny échouée : HTTP ' . $status . '.');
    }
}

/**
 * Stockage local avec import unique depuis Bunny (migration sans perte).
 *
 * - Contenu local présent : seul le fichier local est lu et écrit (Bunny n'est plus appelé).
 * - Contenu local absent : lecture DIRECTE de Bunny (sans cache), copie locale + sauvegarde.
 * - Bunny injoignable ou JSON invalide : lecture signalée en échec, donc le ContentStore
 *   sert le seed en mémoire SANS l'écrire (le vrai contenu n'est jamais remplacé).
 * Bunny n'est jamais modifié par ce backend.
 */
final class ImportingLocalStore implements ContentStoreBackend
{
    private bool $lastReadFailed = false;

    public function __construct(private LocalFileStore $local, private BunnyStore $remote)
    {
    }

    public function lastReadFailed(): bool
    {
        return $this->lastReadFailed;
    }

    public function read(): ?string
    {
        $this->lastReadFailed = false;
        $raw = $this->local->read();
        if ($raw !== null || $this->local->lastReadFailed()) {
            $this->lastReadFailed = $this->local->lastReadFailed();
            return $raw;
        }
        $r = $this->remote->fetchFresh();
        if ($r['state'] === 'ok') {
            try {
                $this->local->write((string) $r['body']);
                $this->local->backup((string) $r['body'], 'import-bunny');
            } catch (\Throwable $e) {
                // DATA_DIR non inscriptible : on sert quand même le contenu Bunny (l'admin signalera l'échec d'écriture).
                error_log('[ContentStore] import Bunny non copié localement : ' . $e->getMessage());
            }
            return (string) $r['body'];
        }
        if ($r['state'] === 'failed') {
            $this->lastReadFailed = true;
            error_log('[ContentStore] import Bunny impossible (' . $r['detail'] . ') : seed servi sans écriture.');
        }
        return null;
    }

    public function write(string $json): void
    {
        $this->local->write($json);
    }
}

/**
 * DATA_DIR (ou un de ses parents, hors « / ») est-il un point de montage (volume Docker) ?
 * null si /proc/self/mountinfo est indisponible (poste de développement).
 */
function cs_dir_is_mounted(string $dir): ?bool
{
    $info = @file_get_contents('/proc/self/mountinfo');
    if ($info === false) {
        return null;
    }
    $mounts = [];
    foreach (explode("\n", $info) as $line) {
        $f = explode(' ', $line);
        if (isset($f[4]) && $f[4] !== '/') {
            $mounts[str_replace('\\040', ' ', $f[4])] = true;
        }
    }
    $p = rtrim($dir, '/');
    while ($p !== '' && $p !== '/') {
        if (isset($mounts[$p])) {
            return true;
        }
        $p = dirname($p);
    }
    return false;
}

/* ============================================================
 *  Store de haut niveau
 * ============================================================ */

final class ContentStore
{
    private ContentStoreBackend $backend;
    private ?array $cache = null;
    /** True si get() a servi le seed faute de pouvoir lire le vrai contenu. */
    private bool $degraded = false;

    public function __construct(ContentStoreBackend $backend)
    {
        $this->backend = $backend;
    }

    /**
     * Renvoie le document de contenu. Si rien n'est stocké (ou JSON invalide),
     * amorce avec le seed par défaut et tente de l'écrire (échec d'écriture ignoré
     * en lecture : le site public ne doit jamais casser).
     */
    public function get(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $raw = null;
        $readFailed = false;
        try {
            $raw = $this->backend->read();
            $readFailed = method_exists($this->backend, 'lastReadFailed') && $this->backend->lastReadFailed();
        } catch (\Throwable $e) {
            $raw = null;
            $readFailed = true;
        }
        $data = null;
        $mayBeCorrupt = false;
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['offers']) && is_array($decoded['offers'])) {
                $data = $decoded;
            } else {
                $mayBeCorrupt = true; // contenu présent mais illisible : ne pas l'écraser
            }
        }
        if ($data === null) {
            $data = cs_default_content();
            // Seed de secours (vrai contenu illisible) : toute sauvegarde l'écraserait, on la bloque.
            $this->degraded = $readFailed || $mayBeCorrupt;
            // Le seed n'est persisté que si le contenu est PROUVÉ absent (404 / fichier inexistant).
            // Panne de lecture ou JSON illisible : seed en mémoire uniquement, aucune écriture.
            if (!$readFailed && !$mayBeCorrupt) {
                try {
                    $this->backend->write($this->encode($data));
                } catch (\Throwable $e) {
                    // Lecture résiliente : on sert le seed en mémoire même si l'écriture échoue.
                }
            }
        }
        $this->cache = $data;
        return $data;
    }

    /** Persiste le document ; met à jour le cache. Lève ContentStoreException si l'écriture échoue. */
    public function save(array $data): void
    {
        if ($this->degraded) {
            throw new ContentStoreException('Contenu actuel illisible : enregistrement bloqué pour ne pas le remplacer par le contenu par défaut. Réessayez plus tard.');
        }
        $this->backend->write($this->encode($data));
        $this->cache = $data;
    }

    private function encode(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new ContentStoreException('Encodage JSON impossible : ' . json_last_error_msg());
        }
        return $json;
    }
}

/** Fabrique le store selon CONTENT_BACKEND (local par défaut). */
function cs_make_store(): ContentStore
{
    $backend = strtolower(cs_env('CONTENT_BACKEND', 'local') ?? 'local');
    switch ($backend) {
        case 'bunny':
            return new ContentStore(new BunnyStore());
        case 'local':
        default:
            $local = new LocalFileStore();
            $bunny = new BunnyStore();
            // Migration : tant que les clés Bunny Storage existent, un contenu local absent est importé.
            return new ContentStore($bunny->configured() ? new ImportingLocalStore($local, $bunny) : $local);
    }
}

/* ============================================================
 *  Helpers métier Offres
 * ============================================================ */

/** Liste brute des offres. */
function cs_offers(array $content): array
{
    return isset($content['offers']) && is_array($content['offers']) ? $content['offers'] : [];
}

/** Retrouve une offre par id (ou null). */
function cs_find_offer(array $content, string $id): ?array
{
    foreach (cs_offers($content) as $o) {
        if (($o['id'] ?? null) === $id) {
            return $o;
        }
    }
    return null;
}

/**
 * Offres actives groupées par onglet et triées par `sort` croissant.
 * Renvoie ['crea' => [...], 'heb' => [...], 'seo' => [...], 'maint' => [...]].
 */
function cs_offers_by_tab(array $content, bool $activeOnly = true): array
{
    $tabs = ['crea' => [], 'heb' => [], 'seo' => [], 'maint' => []];
    foreach (cs_offers($content) as $o) {
        $tab = $o['tab'] ?? '';
        if (!isset($tabs[$tab])) {
            continue;
        }
        if ($activeOnly && empty($o['active'])) {
            continue;
        }
        $tabs[$tab][] = $o;
    }
    foreach ($tabs as &$list) {
        usort($list, static function ($a, $b) {
            return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        });
    }
    unset($list);
    return $tabs;
}

/** True si une promo est active ET non expirée (until vide ou dans le futur). */
function cs_promo_active(array $offer): bool
{
    $p = $offer['promo'] ?? null;
    if (!is_array($p) || empty($p['active'])) {
        return false;
    }
    $until = trim((string) ($p['until'] ?? ''));
    if ($until === '') {
        return true;
    }
    $ts = strtotime($until . ' 23:59:59');
    return $ts === false ? false : ($ts >= time());
}

/* ============================================================
 *  Bandeau d'annonce (défilant, tout en haut du site) — géré dans /admin
 * ============================================================ */

/** Bandeau stocké (clé `banner`), avec valeurs par défaut : désactivé tant que rien n'est saisi. */
function cs_banner(array $content): array
{
    $b = is_array($content['banner'] ?? null) ? $content['banner'] : [];
    return [
        'active' => !empty($b['active']),
        'text'   => trim((string) ($b['text'] ?? '')),
        'link'   => trim((string) ($b['link'] ?? '')),
        'until'  => trim((string) ($b['until'] ?? '')),
    ];
}

/** Actif, avec un texte, et non expiré (date de fin AAAA-MM-JJ incluse). */
function cs_banner_active(array $b): bool
{
    if (empty($b['active']) || (string) ($b['text'] ?? '') === '') {
        return false;
    }
    $until = (string) ($b['until'] ?? '');
    if ($until === '') {
        return true;
    }
    $ts = strtotime($until . ' 23:59:59');
    return $ts === false ? false : ($ts >= time());
}

/** Lien autorisé : ancre de la page, chemin du site ou https. Sinon ''. */
function cs_banner_link_ok(string $link): bool
{
    return (bool) preg_match('~^(#[A-Za-z0-9_-]*|/[^\s"\'<>]*|https://[^\s"\'<>]+)$~', $link);
}

/** HTML du bandeau défilant ('' si inactif). Le texte est répété pour remplir la largeur ; la 2e moitié est masquée aux lecteurs d'écran. */
function cs_banner_html(array $b): string
{
    if (!cs_banner_active($b)) {
        return '';
    }
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $items = str_repeat('<span class="promo-bar__item">' . $esc($b['text']) . '</span>', 4);
    $track = '<span class="promo-bar__track"><span class="promo-bar__grp">' . $items . '</span>'
        . '<span class="promo-bar__grp" aria-hidden="true">' . $items . '</span></span>';
    $link = (string) $b['link'];
    $inner = cs_banner_link_ok($link) ? '<a href="' . $esc($link) . '">' . $track . '</a>' : $track;
    return '<div class="promo-bar" role="region" aria-label="Annonce">' . $inner . '</div>' . "\n";
}

/**
 * Seed par défaut : reproduit EXACTEMENT les offres codées en dur dans index.php
 * (onglets Création / Hébergement / Maintenance de la section #offres).
 */
function cs_default_content(): array
{
    return [
        'version' => 1,
        'offers' => [
            /* ---------- Onglet Création ---------- */
            [
                'id' => 'crea-vitrine',
                'tab' => 'crea',
                'sort' => 10,
                'active' => true,
                'available' => true,
                'feat' => true,
                'badge' => 'Le plus demandé',
                'number' => 'OFFRE 01',
                'tag' => 'Site vitrine',
                'title' => 'Site vitrine',
                'description' => "Un site propre et rapide pour présenter votre activité et donner confiance. Codé sur-mesure ou sur un outil que vous pourrez mettre à jour vous-même : on choisit ensemble ce qui vous convient le mieux.",
                'inc' => "Design, intégration responsive, référencement de base et mise en ligne. Le périmètre exact est détaillé dans le devis.",
                'price_label' => 'Fourchette',
                'price' => '999 € – 1 899 €',
                'price_unit' => '',
                'price_note' => "Version multilingue ou à gérer soi-même : sur devis",
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'offre',
                    'data-label' => 'Site vitrine',
                    'data-oneoff' => '999',
                    'data-form' => 'Offre 1 — Site vitrine',
                    'data-next' => 'tab-heb',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            [
                'id' => 'crea-boutique',
                'tab' => 'crea',
                'sort' => 20,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => '',
                'number' => 'OFFRE 02',
                'tag' => 'Boutique en ligne',
                'title' => 'Boutique en ligne',
                'description' => "Une boutique claire et rapide, où vos clients trouvent et commandent sans se compliquer la vie. Sur une base e-commerce solide ou entièrement sur-mesure, selon vos besoins.",
                'inc' => "Catalogue, paiement sécurisé et parcours d'achat, avec un back-office simple à gérer. Le détail est calé dans le devis.",
                'price_label' => 'Fourchette',
                'price' => '2 499 € – 4 499 €',
                'price_unit' => '',
                'price_note' => "Version 100 % sur-mesure : sur devis",
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'offre',
                    'data-label' => 'Boutique en ligne',
                    'data-oneoff' => '2499',
                    'data-form' => 'Offre 2 — Boutique en ligne',
                    'data-next' => 'tab-heb',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            [
                'id' => 'crea-app',
                'tab' => 'crea',
                'sort' => 30,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => '',
                'number' => 'OFFRE 03',
                'tag' => 'Application · Full-stack',
                'title' => 'Application web',
                'description' => "Quand un simple site ne suffit plus : un outil construit autour de votre façon de travailler, pour vous faire gagner du temps au quotidien.",
                'inc' => "Cadrage du besoin, design, développement et mise en ligne. Le périmètre précis est défini ensemble dans le devis.",
                'price_label' => 'À partir de',
                'price' => '3 999 €',
                'price_unit' => '',
                'price_note' => "Chiffré précisément selon le périmètre",
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'offre',
                    'data-label' => 'Application web',
                    'data-oneoff' => '3999',
                    'data-form' => 'Offre 3 — Application web',
                    'data-next' => 'tab-heb',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],

            [
                'id' => 'crea-refonte',
                'tab' => 'crea',
                'sort' => 40,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => '',
                'number' => 'OFFRE 04',
                'tag' => 'Refonte',
                'title' => 'Refonte de site',
                'description' => "Votre site est vieillissant, lent ou pas adapté au mobile ? Je le repense entièrement : design moderne, rapide et pensé pour convertir.",
                'inc' => "Nouveau design, responsive, performances et SEO revus. Reprise de vos contenus existants.",
                'price_label' => 'Fourchette',
                'price' => '799 € – 1 899 €',
                'price_unit' => '',
                'price_note' => "Selon l'ampleur de la refonte",
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'offre',
                    'data-label' => 'Refonte de site',
                    'data-oneoff' => '799',
                    'data-form' => 'Offre 4 — Refonte de site',
                    'data-next' => 'tab-heb',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            [
                'id' => 'crea-landing',
                'tab' => 'crea',
                'sort' => 50,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => '',
                'number' => 'OFFRE 05',
                'tag' => 'Page unique',
                'title' => 'Landing page',
                'description' => "Une seule page, efficace, pour une offre, un événement ou une campagne. Rapide à mettre en ligne et pensée pour une action claire.",
                'inc' => "Page sur-mesure, responsive, formulaire de contact. Idéale pour tester ou lancer vite.",
                'price_label' => 'Fourchette',
                'price' => '499 € – 799 €',
                'price_unit' => '',
                'price_note' => "Mise en ligne rapide",
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'offre',
                    'data-label' => 'Landing page',
                    'data-oneoff' => '499',
                    'data-form' => 'Offre 5 — Landing page',
                    'data-next' => 'tab-heb',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            /* ---------- Onglet Hébergement ---------- */
            [
                'id' => 'heb-self',
                'tab' => 'heb',
                'sort' => 10,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => '',
                'title' => 'Votre hébergement',
                'description' => 'Vous payez directement votre hébergeur',
                'price' => 'Sans abonnement',
                'price_unit' => '',
                'features' => [
                    'Déployé sur votre VPS ou votre hébergeur',
                    'Vous en êtes 100 % propriétaire',
                    'Vous payez votre hébergeur directement (~5 à 15 €/mois)',
                    'Aucun abonnement chez moi',
                    'Maintenance en option (39 €/mois)',
                ],
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'heb',
                    'data-label' => 'Sur mon propre hébergement',
                    'data-mo' => '0',
                    'data-form' => 'Sur mon propre serveur / hébergeur',
                    'data-next' => 'tab-maint',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            [
                'id' => 'heb-essentiel',
                'tab' => 'heb',
                'sort' => 20,
                'active' => true,
                'available' => true,
                'feat' => true,
                'badge' => 'Le plus simple',
                'title' => 'Essentiel · VPS-1',
                'description' => 'Site vitrine · tout compris',
                'price' => '45 €',
                'price_unit' => ' / mois',
                'features' => [
                    'Serveur 2 vCœurs · 4 Go RAM · 40 Go SSD',
                    'Mise en ligne, configuration & HTTPS',
                    'Mises à jour & sécurité',
                    'Sauvegardes quotidiennes & supervision',
                    'Support par email',
                    "Tout compris — rien d'autre à payer",
                ],
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'heb',
                    'data-label' => 'Essentiel · VPS-1 (tout compris)',
                    'data-mo' => '45',
                    'data-form' => 'Essentiel · VPS-1 (45 €/mois)',
                    'data-included' => '1',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],

            /* ---------- Onglet Référencement ---------- */
            [
                'id' => 'seo-local',
                'tab' => 'seo',
                'sort' => 10,
                'active' => true,
                'available' => true,
                'feat' => true,
                'badge' => 'Mise en place',
                'title' => 'Référencement local (SEO)',
                'description' => 'Aider Google à comprendre votre activité et vos services',
                'price' => '399 €',
                'price_unit' => '',
                'features' => [
                    "Titres et descriptions optimisés",
                    "Données structurées (schema.org)",
                    "Plan du site (sitemap) et robots.txt",
                    "Mise en place de Google Search Console",
                    "Mise en place de Google Analytics",
                    "Mise en place unique, sans abonnement",
                ],
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'seo',
                    'data-label' => 'Référencement local (SEO)',
                    'data-oneoff' => '399',
                    'data-form' => 'seo-local',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            [
                'id' => 'seo-gbp',
                'tab' => 'seo',
                'sort' => 20,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => 'Option',
                'title' => 'Fiche Google Business',
                'description' => "Être trouvé sur Google Maps et en recherche locale",
                'price' => '149 €',
                'price_unit' => '',
                'features' => [
                    "Création ou mise à jour de la fiche",
                    "Catégories et horaires",
                    "Photos de votre activité",
                    "Lien vers votre site",
                    "Forfait unique, sans abonnement",
                ],
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-step' => 'seo',
                    'data-label' => 'Fiche Google Business',
                    'data-oneoff' => '149',
                    'data-form' => 'seo-gbp',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],

            /* ---------- Onglet Maintenance ---------- */
            [
                'id' => 'maint-suivi',
                'tab' => 'maint',
                'sort' => 10,
                'active' => true,
                'available' => true,
                'feat' => true,
                'badge' => 'Recommandé',
                'title' => 'Suivi mensuel',
                'description' => 'Un site à jour, sûr et toujours en ligne',
                'price' => '39 €',
                'price_unit' => ' / mois',
                'features' => [
                    'Mises à jour & sécurité',
                    'Sauvegardes automatiques',
                    'Surveillance de disponibilité',
                    'Petites corrections incluses',
                    'Support par email',
                ],
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-maint' => 'suivi',
                    'data-step' => 'maint',
                    'data-label' => 'Suivi mensuel (39 €/mois)',
                    'data-mo' => '39',
                    'data-form' => 'Suivi mensuel (39 €/mois)',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            [
                'id' => 'maint-heures',
                'tab' => 'maint',
                'sort' => 20,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => '',
                'title' => "Pack d'heures",
                'description' => 'Des modifs & évolutions quand vous voulez',
                'price' => '39 €',
                'price_unit' => ' / heure',
                'features' => [
                    'Modifications, contenu & nouvelles pages',
                    'Corrections et petits développements',
                    'Sans abonnement, à la carte',
                    '+ toutes les options du suivi mensuel',
                ],
                'packs' => [
                    ['a' => 'Pack 5 h', 'b_main' => '175 €', 'b_small' => '35 €/h'],
                    ['a' => 'Pack 10 h', 'b_main' => '320 €', 'b_small' => '32 €/h'],
                ],
                'cta_label' => 'Choisir cette offre',
                'cta_data' => [
                    'data-maint' => 'heures',
                    'data-step' => 'maint',
                    'data-label' => "Pack d'heures",
                    'data-mo' => '0',
                    'data-form' => "Pack d'heures",
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
        ],
    ];
}
