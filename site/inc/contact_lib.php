<?php
// Fonctions du formulaire de contact (séparées de send_mail.php pour être testables).

/** Erreur de stockage d'état (quotas, anti-rejeu) : le formulaire échoue en fermé (503). */
class ContactStorageException extends RuntimeException
{
}

/** Lecture robuste d'une variable d'environnement (quel que soit variables_order). */
function contact_env(string $key): ?string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? null : (string) $v;
}

/** Secret de signature : doit exister et faire au moins 32 caractères. */
function contact_secret_valid(?string $secret): bool
{
    return $secret !== null && strlen($secret) >= 32;
}

/** Dossier d'état (quotas, anti-rejeu), créé en 0700 ; ContactStorageException si inutilisable. */
function contact_state_dir(string $sub): string
{
    $base = rtrim(contact_env('CONTACT_STATE_DIR') ?? sys_get_temp_dir(), '/');
    $dir = $base . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new ContactStorageException("state dir unavailable: $sub");
    }
    if (!is_writable($dir)) {
        throw new ContactStorageException("state dir not writable: $sub");
    }
    return $dir;
}

/** true si $ip appartient au CIDR (ou est égale à l'IP seule). */
function contact_ip_in_cidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    $net = @inet_pton(trim($parts[0]));
    $addr = @inet_pton($ip);
    if ($net === false || $addr === false || strlen($net) !== strlen($addr)) {
        return false;
    }
    $bits = isset($parts[1]) ? (int) $parts[1] : strlen($net) * 8;
    $bits = max(0, min($bits, strlen($net) * 8));
    $full = intdiv($bits, 8);
    if ($full > 0 && substr($net, 0, $full) !== substr($addr, 0, $full)) {
        return false;
    }
    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($net[$full]) & $mask) === (ord($addr[$full]) & $mask);
}

/** Proxys de confiance (CIDR, séparés par des virgules) ; défaut : réseaux privés/loopback (Traefik via réseau Docker). */
function contact_trusted_proxies(): array
{
    $raw = contact_env('TRUSTED_PROXIES') ?? '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7';
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($c) => $c !== ''));
}

function contact_is_trusted(string $ip, array $proxies): bool
{
    foreach ($proxies as $cidr) {
        if (contact_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * IP du visiteur. X-Forwarded-For n'est lu que si la connexion vient d'un proxy de confiance ;
 * on remonte la chaîne depuis la droite et on retient la première IP non fiable
 * (les entrées de gauche sont forgeables par le client).
 */
function contact_client_ip_from(string $remote, ?string $xff, array $proxies): string
{
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return 'unknown';
    }
    if ($xff === null || $xff === '' || !contact_is_trusted($remote, $proxies)) {
        return $remote;
    }
    $chain = array_map('trim', explode(',', $xff));
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        $candidate = $chain[$i];
        if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $remote; // chaîne malformée : on ne fait pas confiance
        }
        if (!contact_is_trusted($candidate, $proxies)) {
            return $candidate;
        }
    }
    return $remote;
}

function contact_client_ip(): string
{
    return contact_client_ip_from(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : null,
        contact_trusted_proxies()
    );
}

/** IP pseudonymisée pour les logs (IPv4 : dernier octet masqué ; IPv6 : /48 conservé). */
function contact_ip_pseudonymize(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
    }
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) {
        return 'unknown';
    }
    return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) ?: 'unknown';
}

/** Rate limit fichier par IP : true si la limite est dépassée. Échec de stockage => exception (fail closed). */
function contact_rate_limited(string $ip, int $maxRequests = 5, int $windowSeconds = 3600): bool
{
    $dir = contact_state_dir('contact_form_rl');
    $key = hash_hmac('sha256', $ip, contact_env('CONTACT_FORM_SECRET') ?? '');
    $handle = @fopen($dir . '/' . $key . '.json', 'c+');
    if (!$handle) {
        throw new ContactStorageException('rate limit storage');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new ContactStorageException('rate limit lock');
    }
    $timestamps = json_decode(stream_get_contents($handle) ?: '[]', true);
    if (!is_array($timestamps)) {
        $timestamps = [];
    }
    $now = time();
    $timestamps = array_values(array_filter($timestamps, fn ($t) => $t > $now - $windowSeconds));
    $limited = count($timestamps) >= $maxRequests;
    if (!$limited) {
        $timestamps[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        if (fwrite($handle, json_encode($timestamps)) === false || !fflush($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new ContactStorageException('rate limit write');
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $limited;
}

/**
 * Plafond global d'envois (toutes IP) sur 24 h glissantes. true si atteint.
 * Réserve un créneau si non atteint ; échec de stockage => exception (fail closed).
 */
function contact_global_cap_reached(int $max): bool
{
    if ($max <= 0) {
        return false;
    }
    $h = @fopen(contact_state_dir('contact_form_global') . '/cap.json', 'c+');
    if (!$h) {
        throw new ContactStorageException('cap storage');
    }
    if (!flock($h, LOCK_EX)) {
        fclose($h);
        throw new ContactStorageException('cap lock');
    }
    $times = json_decode(stream_get_contents($h) ?: '[]', true);
    if (!is_array($times)) {
        $times = [];
    }
    $now = time();
    $times = array_values(array_filter($times, static fn ($t) => $t > $now - 86400));
    $reached = count($times) >= $max;
    if (!$reached) {
        $times[] = $now;
        ftruncate($h, 0);
        rewind($h);
        if (fwrite($h, json_encode($times)) === false || !fflush($h)) {
            flock($h, LOCK_UN);
            fclose($h);
            throw new ContactStorageException('cap write');
        }
    }
    flock($h, LOCK_UN);
    fclose($h);
    return $reached;
}

/** Libère le créneau réservé par contact_global_cap_reached() quand aucun email n'est parti. */
function contact_global_cap_release(): void
{
    $h = @fopen(contact_state_dir('contact_form_global') . '/cap.json', 'c+');
    if (!$h) {
        return;
    }
    if (flock($h, LOCK_EX)) {
        $times = json_decode(stream_get_contents($h) ?: '[]', true);
        if (is_array($times) && $times) {
            array_pop($times);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode(array_values($times)));
            fflush($h);
        }
        flock($h, LOCK_UN);
    }
    fclose($h);
}

/**
 * Vérifie le payload Altcha : solution PoW + signature serveur + anti-rejeu atomique.
 * Panne de stockage => ContactStorageException (aucune acceptation).
 */
function contact_altcha_check(string $payload, string $secret): bool
{
    if ($payload === '' || !contact_secret_valid($secret)) {
        return false;
    }
    $data = json_decode(base64_decode($payload, true) ?: '', true);
    if (!is_array($data)) {
        return false;
    }
    $algorithm = $data['algorithm'] ?? '';
    $challenge = (string) ($data['challenge'] ?? '');
    $number    = $data['number'] ?? null;
    $salt      = (string) ($data['salt'] ?? '');
    $signature = (string) ($data['signature'] ?? '');

    if ($algorithm !== 'SHA-256' || $challenge === '' || $salt === '' || $signature === '' || !is_numeric($number)) {
        return false;
    }
    // L'expiration est obligatoire (paramètre ?expires= dans le sel, couvert par la signature via le challenge).
    if (!preg_match('/[?&]expires=(\d+)/', $salt, $m) || (int) $m[1] < time()) {
        return false;
    }
    if (!hash_equals(hash('sha256', $salt . $number), $challenge)) {
        return false;
    }
    if (!hash_equals(hash_hmac('sha256', $challenge, $secret), $signature)) {
        return false;
    }
    // Anti-rejeu atomique : création exclusive ('x'), une seule soumission peut gagner.
    $dir = contact_state_dir('altcha_used');
    $file = $dir . '/' . hash('sha256', $signature) . '.used';
    if (is_file($file) && filemtime($file) <= time() - 3600) {
        @unlink($file); // purge d'une entrée expirée (le challenge est de toute façon expiré)
    }
    $h = @fopen($file, 'x');
    if ($h === false) {
        if (is_file($file)) {
            return false; // déjà utilisé
        }
        throw new ContactStorageException('altcha replay storage');
    }
    fclose($h);
    // Purge opportuniste des fichiers expirés.
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*.used') ?: [] as $f) {
            if (filemtime($f) <= time() - 3600) {
                @unlink($f);
            }
        }
    }
    return true;
}

/** Vrai si une entrée POST scalaire (string) ; les tableaux/objets sont rejetés. */
function contact_post_all_scalar(array $post): bool
{
    foreach ($post as $v) {
        if (!is_string($v)) {
            return false;
        }
    }
    return true;
}

/** Classe l'erreur SMTP : true si l'échec est certain (rien accepté) donc un renvoi ne créerait aucun doublon. */
function contact_smtp_error_is_safe_to_retry(string $message): bool
{
    foreach (['Could not connect', 'connect() failed', 'Could not authenticate', 'recipients failed', 'data not accepted', 'Invalid address', 'SMTP Error: AUTH'] as $needle) {
        if (stripos($message, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** Code d'erreur SMTP stable pour les logs (aucun message brut du serveur). */
function contact_smtp_error_code(string $message): string
{
    if (stripos($message, 'connect') !== false) {
        return 'smtp_connect';
    }
    if (stripos($message, 'authenticate') !== false || stripos($message, 'AUTH') !== false) {
        return 'smtp_auth';
    }
    if (stripos($message, 'recipient') !== false || stripos($message, 'address') !== false) {
        return 'smtp_recipient';
    }
    if (stripos($message, 'data') !== false) {
        return 'smtp_data';
    }
    return 'smtp_other';
}

/** Journalise un évènement : IP pseudonymisée, UA tronqué, chemin sans query ; rotation quotidienne, purge > N jours. */
function contact_log(string $event, array $extra = []): void
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '-');
    $path = parse_url($uri, PHP_URL_PATH);
    $entry = [
        'ts'       => date('c'),
        'event'    => $event,
        'ip'       => contact_ip_pseudonymize(contact_client_ip()),
        'ua'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 200),
        'endpoint' => is_string($path) ? $path : '-',
    ] + $extra;
    $dir = rtrim(contact_env('CONTACT_LOG_DIR') ?? sys_get_temp_dir(), '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $retention = max(1, (int) (contact_env('CONTACT_LOG_RETENTION_DAYS') ?? '30'));
    @file_put_contents(
        $dir . '/contact_form-' . date('Ymd') . '.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/contact_form-*.log') ?: [] as $f) {
            if (filemtime($f) < time() - $retention * 86400) {
                @unlink($f);
            }
        }
    }
}
