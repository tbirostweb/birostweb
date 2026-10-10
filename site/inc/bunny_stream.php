<?php

declare(strict_types=1);

/*
 * Intégration Bunny Stream (galerie — phase 2 vidéo).
 *
 * Modèle d'upload « médié par le serveur » (simple et fiable pour des clips
 * courts) : navigateur → admin PHP → Bunny Stream via l'API HTTP.
 *   1) create_video(title)        -> POST  /library/{LIB}/videos   -> renvoie le guid
 *   2) upload_video(guid, path)   -> PUT   /library/{LIB}/videos/{guid}  (binaire)
 *   3) delete_video(guid)         -> DELETE /library/{LIB}/videos/{guid}
 *
 * Auth : en-tête `AccessKey: {BUNNY_STREAM_KEY}` (clé API de la library).
 * Endpoints confirmés sur la doc Bunny Stream (video.bunnycdn.com) :
 *   - Create : POST   https://video.bunnycdn.com/library/{libraryId}/videos
 *              body JSON {"title": "..."}  ·  succès HTTP 200  ·  renvoie VideoModel.guid
 *   - Upload : PUT    https://video.bunnycdn.com/library/{libraryId}/videos/{videoId}
 *              corps = binaire brut (application/octet-stream)  ·  succès HTTP 200
 *   - Delete : DELETE https://video.bunnycdn.com/library/{libraryId}/videos/{videoId}
 *              succès HTTP 200 (404 toléré : déjà absent)
 *
 * Lecture publique (aucun appel serveur) :
 *   - Vignette : https://{BUNNY_STREAM_HOST}/{guid}/thumbnail.jpg
 *   - Lecteur  : https://iframe.mediadelivery.net/embed/{libraryId}/{guid}
 *
 * Sélection : les appels réseau ne sont tentés qu'en backend Bunny
 * (CONTENT_BACKEND=bunny) ET si la library + la clé sont configurées. Sinon on
 * reste en MODE DÉGRADÉ (local/dev) : create renvoie un guid factice, upload et
 * delete sont des no-op. On peut ainsi tester toute l'UI sans clés réelles.
 *
 * Ce fichier dépend de cs_env() (défini dans content_store.php). L'appelant a
 * déjà chargé content_store.php (admin/_bootstrap.php, galerie.php).
 */

if (!function_exists('cs_env')) {
    require_once __DIR__ . '/content_store.php';
}

/** Hôte de base de l'API Stream (jamais exposé au client). */
const BUNNY_STREAM_API = 'https://video.bunnycdn.com';

/** Hôte du lecteur embarqué (iframe) — autorisé par la CSP frame-src. */
const BUNNY_STREAM_EMBED_HOST = 'iframe.mediadelivery.net';

/** Échec d'une opération Bunny Stream : l'admin doit la remonter (fail-closed). */
class BunnyStreamException extends RuntimeException
{
}

/** Identifiant numérique de la library Stream (ex. 775452), ou '' si absent. */
function bunny_stream_library(): string
{
    return trim((string) (cs_env('BUNNY_STREAM_LIBRARY') ?? ''));
}

/** Clé API de la library (AccessKey), ou '' si absente. */
function bunny_stream_key(): string
{
    return (string) (cs_env('BUNNY_STREAM_KEY') ?? '');
}

/** Hostname de lecture/vignettes (ex. vz-xxxx.b-cdn.net), sans schéma ni slash. */
function bunny_stream_host(): string
{
    $h = trim((string) (cs_env('BUNNY_STREAM_HOST') ?? ''));
    $h = preg_replace('#^https?://#i', '', $h) ?? $h;
    return rtrim($h, '/');
}

/**
 * True si l'on doit réellement parler à Bunny Stream :
 * backend = bunny ET library + clé présentes. Sinon → mode dégradé.
 */
function bunny_stream_enabled(): bool
{
    $backend = strtolower((string) (cs_env('CONTENT_BACKEND', 'local') ?? 'local'));
    return $backend === 'bunny' && bunny_stream_library() !== '' && bunny_stream_key() !== '';
}

/** URL de la vignette par défaut d'une vidéo (lecture publique). '' si guid/hôte absent. */
function bunny_stream_thumbnail_url(string $guid): string
{
    $guid = trim($guid);
    $host = bunny_stream_host();
    if ($guid === '' || $host === '') {
        return '';
    }
    return 'https://' . $host . '/' . rawurlencode($guid) . '/thumbnail.jpg';
}

/**
 * URL du lecteur embarqué (iframe). Paramètres par défaut pensés pour une
 * « façade » : rien n'est chargé tant que l'utilisateur n'a pas cliqué, et au
 * clic la lecture démarre (autoplay=true), sans précharge en amont.
 *
 * @param array<string,string|int|bool> $params surcharge éventuelle des query params.
 */
function bunny_stream_embed_url(string $guid, array $params = []): string
{
    $guid = trim($guid);
    $lib  = bunny_stream_library();
    if ($guid === '' || $lib === '') {
        return '';
    }
    $defaults = [
        'autoplay' => 'true',
        'preload'  => 'false',
        'loop'     => 'false',
        'muted'    => 'false',
    ];
    foreach ($params as $k => $v) {
        $defaults[(string) $k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
    }
    $qs = http_build_query($defaults, '', '&', PHP_QUERY_RFC3986);
    return 'https://' . BUNNY_STREAM_EMBED_HOST . '/embed/' . rawurlencode($lib) . '/' . rawurlencode($guid)
        . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Exécute une requête cURL vers l'API Stream.
 * @param resource|null $infile  flux de fichier pour un PUT (upload binaire).
 * @return array{status:int, body:string, error:?string}
 */
function bunny_stream_request(string $method, string $path, ?string $jsonBody = null, $infile = null, int $infileSize = 0): array
{
    $url = BUNNY_STREAM_API . $path;
    $ch  = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
    }
    $headers = ['AccessKey: ' . bunny_stream_key(), 'Accept: application/json'];
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($infile !== null) {
        // Upload binaire (PUT) : flux en corps, gros timeout (encodage/transfert).
        $opts[CURLOPT_UPLOAD]     = true;
        $opts[CURLOPT_INFILE]     = $infile;
        $opts[CURLOPT_INFILESIZE] = $infileSize;
        $opts[CURLOPT_TIMEOUT]    = 280; // < max_execution_time (.htaccess admin = 300)
        $headers[]                = 'Content-Type: application/octet-stream';
    } else {
        $opts[CURLOPT_TIMEOUT] = 20;
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $jsonBody;
            $headers[]                = 'Content-Type: application/json';
        }
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

/**
 * Crée une entrée vidéo et renvoie son `guid`.
 * Mode dégradé (Bunny non actif) : renvoie un guid factice « local-… » sans réseau.
 */
function bunny_stream_create_video(string $title): string
{
    if (!bunny_stream_enabled()) {
        return 'local-' . bin2hex(random_bytes(8));
    }
    $title = $title !== '' ? $title : 'Vidéo';
    $body  = json_encode(['title' => mb_substr($title, 0, 200)], JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new BunnyStreamException('Encodage JSON du titre impossible.');
    }
    $res = bunny_stream_request('POST', '/library/' . rawurlencode(bunny_stream_library()) . '/videos', $body);
    if ($res['error'] !== null) {
        throw new BunnyStreamException('Création vidéo impossible (réseau) : ' . $res['error']);
    }
    if ($res['status'] !== 200 && $res['status'] !== 201) {
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new BunnyStreamException('Création vidéo refusée (HTTP ' . $res['status'] . ') : AccessKey invalide.');
        }
        throw new BunnyStreamException('Création vidéo échouée : HTTP ' . $res['status'] . '.');
    }
    $data = json_decode($res['body'], true);
    $guid = is_array($data) ? trim((string) ($data['guid'] ?? '')) : '';
    if ($guid === '') {
        throw new BunnyStreamException('Réponse Bunny sans guid exploitable.');
    }
    return $guid;
}

/**
 * Téléverse le fichier binaire local vers la vidéo $guid (PUT).
 * Mode dégradé : no-op (le guid factice n'existe pas côté Bunny).
 */
function bunny_stream_upload_video(string $guid, string $localPath): void
{
    if (!bunny_stream_enabled()) {
        return;
    }
    $guid = trim($guid);
    if ($guid === '') {
        throw new BunnyStreamException('guid vidéo manquant pour l\'upload.');
    }
    $size = @filesize($localPath);
    $fh   = @fopen($localPath, 'rb');
    if ($fh === false) {
        throw new BunnyStreamException('Fichier vidéo source illisible : ' . $localPath);
    }
    try {
        $res = bunny_stream_request(
            'PUT',
            '/library/' . rawurlencode(bunny_stream_library()) . '/videos/' . rawurlencode($guid),
            null,
            $fh,
            $size !== false ? $size : 0
        );
    } finally {
        fclose($fh);
    }
    if ($res['error'] !== null) {
        throw new BunnyStreamException('Upload vidéo impossible (réseau) : ' . $res['error']);
    }
    if ($res['status'] !== 200 && $res['status'] !== 201) {
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new BunnyStreamException('Upload vidéo refusé (HTTP ' . $res['status'] . ') : AccessKey invalide.');
        }
        throw new BunnyStreamException('Upload vidéo échoué : HTTP ' . $res['status'] . '.');
    }
}

/**
 * Supprime la vidéo $guid côté Bunny (DELETE). Tolère l'absence (404).
 * Mode dégradé ou guid factice (« local-… ») : no-op.
 */
function bunny_stream_delete_video(string $guid): void
{
    $guid = trim($guid);
    if ($guid === '' || str_starts_with($guid, 'local-') || !bunny_stream_enabled()) {
        return;
    }
    $res = bunny_stream_request(
        'DELETE',
        '/library/' . rawurlencode(bunny_stream_library()) . '/videos/' . rawurlencode($guid)
    );
    if ($res['error'] !== null) {
        throw new BunnyStreamException('Suppression vidéo impossible (réseau) : ' . $res['error']);
    }
    if ($res['status'] === 200 || $res['status'] === 201 || $res['status'] === 204 || $res['status'] === 404) {
        return; // supprimé, ou déjà absent
    }
    if ($res['status'] === 401 || $res['status'] === 403) {
        throw new BunnyStreamException('Suppression vidéo refusée (HTTP ' . $res['status'] . ') : AccessKey invalide.');
    }
    throw new BunnyStreamException('Suppression vidéo échouée : HTTP ' . $res['status'] . '.');
}

/** Nombre de frames candidates que Bunny génère par vidéo (thumbnail_1.jpg … thumbnail_5.jpg). */
const BUNNY_STREAM_FRAME_COUNT = 5;

/**
 * URLs publiques des frames candidates d'une vidéo, indexées de 1 à $count :
 * [1 => https://{host}/{guid}/thumbnail_1.jpg, …]. Tableau vide si guid/hôte absent
 * ou guid factice (« local-… »). Aucun appel réseau.
 *
 * @return array<int,string>
 */
function bunny_stream_frame_urls(string $guid, int $count = BUNNY_STREAM_FRAME_COUNT): array
{
    $guid = trim($guid);
    $host = bunny_stream_host();
    if ($guid === '' || $host === '' || str_starts_with($guid, 'local-')) {
        return [];
    }
    $out = [];
    $count = max(1, min(10, $count));
    for ($i = 1; $i <= $count; $i++) {
        $out[$i] = 'https://' . $host . '/' . rawurlencode($guid) . '/thumbnail_' . $i . '.jpg';
    }
    return $out;
}

/**
 * Statut d'encodage Bunny d'une vidéo (0 créée, 1 envoyée, 2 traitement,
 * 3 transcodage, 4 terminée, 5 erreur, 6 échec d'upload…). null = inconnu
 * (mode dégradé, guid factice, erreur réseau/API) : l'appelant ne doit jamais planter.
 */
function bunny_stream_video_status(string $guid): ?int
{
    $guid = trim($guid);
    if ($guid === '' || str_starts_with($guid, 'local-') || !bunny_stream_enabled()) {
        return null;
    }
    try {
        $res = bunny_stream_request(
            'GET',
            '/library/' . rawurlencode(bunny_stream_library()) . '/videos/' . rawurlencode($guid)
        );
    } catch (\Throwable $e) {
        return null;
    }
    if ($res['error'] !== null || $res['status'] !== 200) {
        return null;
    }
    $data = json_decode($res['body'], true);
    return is_array($data) && isset($data['status']) ? (int) $data['status'] : null;
}

/**
 * Synchronise la vignette officielle côté Bunny (« Set Thumbnail ») :
 * POST /library/{LIB}/videos/{guid}/thumbnail?thumbnailUrl=thumbnail_N.jpg
 * $thumbName : « thumbnail_3.jpg » (seuls thumbnail_1..10.jpg sont acceptés).
 * Lève BunnyStreamException en cas d'échec ; no-op en mode dégradé / guid factice.
 */
function bunny_stream_set_thumbnail(string $guid, string $thumbName): void
{
    $guid = trim($guid);
    if ($guid === '' || str_starts_with($guid, 'local-') || !bunny_stream_enabled()) {
        return;
    }
    if (!preg_match('/^thumbnail_(?:[1-9]|10)\.jpg$/', $thumbName)) {
        throw new BunnyStreamException('Nom de vignette invalide.');
    }
    $res = bunny_stream_request(
        'POST',
        '/library/' . rawurlencode(bunny_stream_library()) . '/videos/' . rawurlencode($guid)
            . '/thumbnail?thumbnailUrl=' . rawurlencode($thumbName),
        ''
    );
    if ($res['error'] !== null) {
        throw new BunnyStreamException('Synchronisation de la vignette impossible (réseau) : ' . $res['error']);
    }
    if ($res['status'] !== 200 && $res['status'] !== 201 && $res['status'] !== 204) {
        throw new BunnyStreamException('Synchronisation de la vignette échouée : HTTP ' . $res['status'] . '.');
    }
}
