<?php

declare(strict_types=1);

/*
 * Couche de stockage de contenu du site (offres, promos, …).
 *
 * Un document de contenu = un tableau PHP sérialisé en JSON.
 * ContentStore expose get(): array et save(array): void, avec un petit cache
 * mémoire par requête. Deux backends interchangeables via l'env CONTENT_BACKEND :
 *   - local  : fichier DATA_DIR/content.json (implémenté, écriture atomique).
 *   - bunny  : squelette, à brancher sur Bunny Storage en prod (voir TODO).
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
    private string $dir;

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
        $file = $this->path();
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        return $raw === false ? null : $raw;
    }

    public function write(string $json): void
    {
        $this->ensureDir();
        $file = $this->path();
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
    public function read(): ?string
    {
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

        // Échec (réseau, 401, 5xx, …) : anti-panne.
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

/* ============================================================
 *  Store de haut niveau
 * ============================================================ */

final class ContentStore
{
    private ContentStoreBackend $backend;
    private ?array $cache = null;

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
        try {
            $raw = $this->backend->read();
        } catch (\Throwable $e) {
            $raw = null;
        }
        $data = null;
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['offers']) && is_array($decoded['offers'])) {
                $data = $decoded;
            }
        }
        if ($data === null) {
            $data = cs_default_content();
            try {
                $this->backend->write($this->encode($data));
            } catch (\Throwable $e) {
                // Lecture résiliente : on sert le seed en mémoire même si l'écriture échoue.
            }
        }
        $this->cache = $data;
        return $data;
    }

    /** Persiste le document ; met à jour le cache. Lève ContentStoreException si l'écriture échoue. */
    public function save(array $data): void
    {
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
            return new ContentStore(new LocalFileStore());
    }
}

/* ============================================================
 *  Abstraction média (galerie — upload d'images)
 * ============================================================ */

/** Échec d'opération média : l'admin doit le remonter (fail-closed). */
class MediaException extends RuntimeException
{
}

/**
 * Stockage des fichiers médias (images de la galerie).
 *
 * Un « destPath » est un chemin relatif propre dans le stockage, p.ex.
 *   gallery/{itemId}/{nomGenere}.webp
 * Les segments sont contrôlés côté serveur (jamais le nom fourni par le client).
 */
interface MediaUploader
{
    /**
     * Stocke le fichier local $localTmpPath à l'emplacement $destPath et
     * renvoie son URL publique (CDN en prod, chemin local servi en dev).
     * Lève MediaException en cas d'échec.
     */
    public function upload(string $localTmpPath, string $destPath, string $contentType): string;

    /** Supprime l'objet $destPath du stockage. Tolère l'absence. Lève MediaException sur erreur dure. */
    public function delete(string $destPath): void;

    /** URL publique d'un objet déjà stocké (sans accès réseau). */
    public function publicUrl(string $destPath): string;
}

/** Normalise/valide un destPath : segments [a-z0-9._-], pas de '..', pas de slash initial. */
function cs_media_safe_path(string $destPath): string
{
    $destPath = str_replace('\\', '/', $destPath);
    $destPath = ltrim($destPath, '/');
    $segments = [];
    foreach (explode('/', $destPath) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            throw new MediaException('Chemin média invalide : ' . $destPath);
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $seg)) {
            throw new MediaException('Segment de chemin média invalide : ' . $seg);
        }
        $segments[] = $seg;
    }
    if (!$segments) {
        throw new MediaException('Chemin média vide.');
    }
    return implode('/', $segments);
}

/**
 * Uploader local : écrit les fichiers sous MEDIA_DIR (défaut site/media) et
 * renvoie une URL locale servie par le site (base '/media/'). Permet de tester
 * toute l'UX galerie sans Bunny.
 */
final class LocalMediaUploader implements MediaUploader
{
    private string $dir;
    private string $base;

    public function __construct(?string $dir = null, ?string $baseUrl = null)
    {
        $dir = $dir ?? (cs_env('MEDIA_DIR') ?? (dirname(__DIR__) . '/media'));
        $this->dir  = rtrim($dir, '/');
        $base = $baseUrl ?? (cs_env('MEDIA_BASE_URL') ?? '/media/');
        $this->base = '/' . trim($base, '/') . '/';
    }

    public function upload(string $localTmpPath, string $destPath, string $contentType): string
    {
        $rel  = cs_media_safe_path($destPath);
        $full = $this->dir . '/' . $rel;
        $sub  = dirname($full);
        if (!is_dir($sub) && !@mkdir($sub, 0755, true) && !is_dir($sub)) {
            throw new MediaException('Dossier média non créable : ' . $sub);
        }
        if (!@copy($localTmpPath, $full)) {
            throw new MediaException('Copie média impossible : ' . $rel);
        }
        @chmod($full, 0644);
        return $this->publicUrl($rel);
    }

    public function delete(string $destPath): void
    {
        $rel  = cs_media_safe_path($destPath);
        $full = $this->dir . '/' . $rel;
        if (is_file($full) && !@unlink($full)) {
            throw new MediaException('Suppression média impossible : ' . $rel);
        }
        // Nettoyage best-effort du dossier de l'item s'il est vide.
        $sub = dirname($full);
        if (is_dir($sub) && $sub !== $this->dir) {
            @rmdir($sub);
        }
    }

    public function publicUrl(string $destPath): string
    {
        $rel = cs_media_safe_path($destPath);
        // On encode chaque segment mais on préserve les slashes.
        $parts = array_map('rawurlencode', explode('/', $rel));
        return $this->base . implode('/', $parts);
    }
}

/**
 * Uploader Bunny Storage.
 *
 * Même modèle que BunnyStore :
 *   - PUT   https://{BUNNY_STORAGE_ENDPOINT}/{BUNNY_STORAGE_ZONE}/{destPath}
 *           en-tête AccessKey: {BUNNY_STORAGE_KEY}, corps = fichier (201 = OK).
 *   - DELETE même URL (200/204 = OK, 404 toléré).
 * L'URL publique renvoyée pointe sur la Pull Zone CDN : https://{BUNNY_CDN_HOST}/{destPath}.
 *
 * NB prod : BUNNY_STORAGE_ENDPOINT DOIT être un hôte https. Le schéma http n'est
 * toléré que pour un hôte de test local (comme BunnyStore).
 */
final class BunnyMediaUploader implements MediaUploader
{
    private string $zone;
    private string $key;
    private string $endpoint;
    private string $cdnHost;

    public function __construct()
    {
        $this->zone     = cs_env('BUNNY_STORAGE_ZONE', '') ?? '';
        $this->key      = cs_env('BUNNY_STORAGE_KEY', '') ?? '';
        $this->endpoint = rtrim(cs_env('BUNNY_STORAGE_ENDPOINT', 'storage.bunnycdn.com') ?? 'storage.bunnycdn.com', '/');
        $this->cdnHost  = rtrim(cs_env('BUNNY_CDN_HOST', '') ?? '', '/');
    }

    private function isLocalTestHost(string $endpoint): bool
    {
        $host = strtolower(explode(':', $endpoint, 2)[0]);
        return $host === '127.0.0.1'
            || $host === 'localhost'
            || $host === '::1'
            || substr($host, -10) === '.localhost'
            || substr($host, -5) === '.test';
    }

    /** URL de l'objet sur l'API Storage. */
    private function storageUrl(string $rel): string
    {
        $scheme = $this->isLocalTestHost($this->endpoint) ? 'http' : 'https';
        $parts  = array_map('rawurlencode', explode('/', $rel));
        return $scheme . '://' . $this->endpoint . '/' . rawurlencode($this->zone) . '/' . implode('/', $parts);
    }

    public function publicUrl(string $destPath): string
    {
        $rel = cs_media_safe_path($destPath);
        if ($this->cdnHost === '') {
            throw new MediaException('BUNNY_CDN_HOST manquant : URL publique indisponible.');
        }
        $scheme = $this->isLocalTestHost($this->cdnHost) ? 'http' : 'https';
        $parts  = array_map('rawurlencode', explode('/', $rel));
        return $scheme . '://' . $this->cdnHost . '/' . implode('/', $parts);
    }

    public function upload(string $localTmpPath, string $destPath, string $contentType): string
    {
        if ($this->zone === '' || $this->key === '') {
            throw new MediaException('Bunny mal configuré : BUNNY_STORAGE_ZONE / BUNNY_STORAGE_KEY manquant.');
        }
        $rel = cs_media_safe_path($destPath);
        $fh  = @fopen($localTmpPath, 'rb');
        if ($fh === false) {
            throw new MediaException('Fichier source illisible : ' . $localTmpPath);
        }
        $size = @filesize($localTmpPath);
        $ch   = curl_init($this->storageUrl($rel));
        if ($ch === false) {
            fclose($fh);
            throw new MediaException('curl_init a échoué.');
        }
        $ct = $contentType !== '' ? $contentType : 'application/octet-stream';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $size !== false ? $size : 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'AccessKey: ' . $this->key,
                'Content-Type: ' . $ct,
            ],
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch) ?: 'curl error';
            curl_close($ch);
            fclose($fh);
            throw new MediaException('Upload Bunny impossible (réseau) : ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if ($status === 201 || $status === 200) {
            return $this->publicUrl($rel);
        }
        if ($status === 401) {
            throw new MediaException('Upload Bunny refusé (401) : AccessKey invalide.');
        }
        throw new MediaException('Upload Bunny échoué : HTTP ' . $status . '.');
    }

    public function delete(string $destPath): void
    {
        if ($this->zone === '' || $this->key === '') {
            throw new MediaException('Bunny mal configuré : BUNNY_STORAGE_ZONE / BUNNY_STORAGE_KEY manquant.');
        }
        $rel = cs_media_safe_path($destPath);
        $ch  = curl_init($this->storageUrl($rel));
        if ($ch === false) {
            throw new MediaException('curl_init a échoué.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['AccessKey: ' . $this->key],
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch) ?: 'curl error';
            curl_close($ch);
            throw new MediaException('Suppression Bunny impossible (réseau) : ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        // 200/204 = supprimé ; 404 = déjà absent (toléré).
        if ($status === 200 || $status === 204 || $status === 404) {
            return;
        }
        if ($status === 401) {
            throw new MediaException('Suppression Bunny refusée (401) : AccessKey invalide.');
        }
        throw new MediaException('Suppression Bunny échouée : HTTP ' . $status . '.');
    }
}

/** Fabrique l'uploader média selon CONTENT_BACKEND (local par défaut). */
function cs_make_media_uploader(): MediaUploader
{
    $backend = strtolower(cs_env('CONTENT_BACKEND', 'local') ?? 'local');
    switch ($backend) {
        case 'bunny':
            return new BunnyMediaUploader();
        case 'local':
        default:
            return new LocalMediaUploader();
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
 * Renvoie ['crea' => [...], 'heb' => [...], 'maint' => [...]].
 */
function cs_offers_by_tab(array $content, bool $activeOnly = true): array
{
    $tabs = ['crea' => [], 'heb' => [], 'maint' => []];
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
 *  Helpers métier Galerie
 * ============================================================ */

/** Types de galerie reconnus. La vidéo est posée (phase 2) mais non implémentée. */
const CS_GALLERY_TYPES = ['carousel', 'video'];

/** Liste brute des items de galerie (défaut vide si absent — rétro-compatible). */
function cs_gallery(array $content): array
{
    return isset($content['gallery']) && is_array($content['gallery']) ? $content['gallery'] : [];
}

/** Retrouve un item de galerie par id (ou null). */
function cs_find_gallery_item(array $content, string $id): ?array
{
    foreach (cs_gallery($content) as $it) {
        if (($it['id'] ?? null) === $id) {
            return $it;
        }
    }
    return null;
}

/**
 * Items de galerie, triés par `sort` croissant.
 * @param bool        $activeOnly  ne garder que les items actifs.
 * @param string|null $type        filtrer par type ('carousel'|'video'), ou null pour tous.
 */
function cs_gallery_items(array $content, bool $activeOnly = false, ?string $type = null): array
{
    $items = [];
    foreach (cs_gallery($content) as $it) {
        if (!is_array($it)) {
            continue;
        }
        if ($activeOnly && empty($it['active'])) {
            continue;
        }
        if ($type !== null && ($it['type'] ?? '') !== $type) {
            continue;
        }
        $items[] = $it;
    }
    usort($items, static function ($a, $b) {
        return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
    });
    return $items;
}

/** Images d'un item carousel, triées par `sort` croissant (liste vide sinon). */
function cs_gallery_item_images(array $item): array
{
    $imgs = (isset($item['images']) && is_array($item['images'])) ? $item['images'] : [];
    $imgs = array_values(array_filter($imgs, 'is_array'));
    usort($imgs, static function ($a, $b) {
        return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
    });
    return $imgs;
}

/**
 * Seed par défaut : reproduit EXACTEMENT les offres codées en dur dans index.php
 * (onglets Création / Hébergement / Maintenance de la section #offres).
 * `gallery` démarre vide (les items sont créés depuis l'admin).
 */
function cs_default_content(): array
{
    return [
        'version' => 1,
        'gallery' => [],
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
                'price_label' => 'À partir de',
                'price' => '999 €',
                'price_unit' => '',
                'price_note' => "Version multilingue ou à gérer soi-même : sur devis",
                'cta_label' => 'Choisir le site vitrine',
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
                'price_label' => 'À partir de',
                'price' => '1 899 €',
                'price_unit' => '',
                'price_note' => "Version 100 % sur-mesure : sur devis",
                'cta_label' => 'Choisir la boutique',
                'cta_data' => [
                    'data-step' => 'offre',
                    'data-label' => 'Boutique en ligne',
                    'data-oneoff' => '1899',
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
                'price' => '2 999 €',
                'price_unit' => '',
                'price_note' => "Chiffré précisément selon le périmètre",
                'cta_label' => "Choisir l'application",
                'cta_data' => [
                    'data-step' => 'offre',
                    'data-label' => 'Application web',
                    'data-oneoff' => '2999',
                    'data-form' => 'Offre 3 — Application web',
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
                'description' => 'Vous gardez la main',
                'price' => 'Gratuit',
                'price_unit' => '',
                'features' => [
                    'Déployé sur votre VPS ou votre hébergeur',
                    'Vous en êtes 100 % propriétaire',
                    'Vous payez votre hébergeur directement (~5 à 15 €/mois)',
                    'Aucun abonnement chez moi',
                    'Maintenance en option (39 €/mois)',
                ],
                'cta_label' => 'Choisir cette option',
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
                'cta_label' => 'Choisir Essentiel',
                'cta_data' => [
                    'data-step' => 'heb',
                    'data-label' => 'Essentiel · VPS-1 (tout compris)',
                    'data-mo' => '45',
                    'data-form' => 'Essentiel · VPS-1 (45 €/mois)',
                    'data-included' => '1',
                ],
                'promo' => ['active' => false, 'price' => '', 'label' => '', 'until' => ''],
            ],
            [
                'id' => 'heb-pro',
                'tab' => 'heb',
                'sort' => 30,
                'active' => true,
                'available' => true,
                'feat' => false,
                'badge' => '',
                'title' => 'Pro · VPS-2',
                'description' => 'Boutique & applications · tout compris',
                'price' => '69 €',
                'price_unit' => ' / mois',
                'features' => [
                    'Serveur 4 vCœurs · 8 Go RAM · 75 Go SSD',
                    'Tout ce qui est inclus dans Essentiel',
                    'Ressources pour un trafic plus élevé',
                    'Support prioritaire',
                ],
                'cta_label' => 'Choisir Pro',
                'cta_data' => [
                    'data-step' => 'heb',
                    'data-label' => 'Pro · VPS-2 (tout compris)',
                    'data-mo' => '69',
                    'data-form' => 'Pro · VPS-2 (69 €/mois)',
                    'data-included' => '1',
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
                'cta_label' => 'Choisir le suivi mensuel',
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
                'cta_label' => "Choisir le pack d'heures",
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
