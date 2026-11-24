<?php

declare(strict_types=1);

/*
 * Migration du contenu (offres, promos, bandeau) entre Bunny Storage et le stockage local du VPS.
 * À lancer DANS le conteneur, en www-data (utilisateur par défaut de l'image) :
 *
 *   docker exec <conteneur> php /opt/birostweb/content-migrate.php status
 *   docker exec <conteneur> php /opt/birostweb/content-migrate.php pull            # Bunny -> local
 *   docker exec <conteneur> php /opt/birostweb/content-migrate.php pull --force    # remplace un local différent (après sauvegarde)
 *   docker exec <conteneur> php /opt/birostweb/content-migrate.php push --force    # local -> Bunny (retour arrière)
 *   docker exec <conteneur> php /opt/birostweb/content-migrate.php backup          # copie horodatée du local
 *
 * Garanties : rien n'est remplacé sans sauvegarde préalable ; un contenu invalide n'est jamais écrit ;
 * Bunny n'est modifié que par `push --force`. Aucune clé n'est affichée.
 */

foreach ([__DIR__ . '/../site/inc/content_store.php', '/var/www/html/inc/content_store.php'] as $lib) {
    if (is_file($lib)) {
        require $lib;
        break;
    }
}
if (!class_exists('ContentStore')) {
    fwrite(STDERR, "content_store.php introuvable.\n");
    exit(2);
}

$cmd   = $argv[1] ?? 'status';
$force = in_array('--force', $argv, true);
$local = new LocalFileStore();
$bunny = new BunnyStore();

/** Résumé d'un JSON de contenu (sans son contenu). */
function summary(?string $json): string
{
    if ($json === null) {
        return 'absent';
    }
    $d = json_decode($json, true);
    if (!is_array($d) || !isset($d['offers']) || !is_array($d['offers'])) {
        return 'INVALIDE (' . strlen($json) . ' octets)';
    }
    $promos = 0;
    foreach ($d['offers'] as $o) {
        $promos += !empty($o['promo']['active']) ? 1 : 0;
    }
    return sprintf('%d octets, sha256 %s, %d offres (%d promos actives), bandeau %s',
        strlen($json), substr(hash('sha256', $json), 0, 12), count($d['offers']), $promos,
        !empty($d['banner']['active']) ? 'actif' : 'inactif');
}

function valid(?string $json): bool
{
    $d = $json === null ? null : json_decode($json, true);
    return is_array($d) && isset($d['offers']) && is_array($d['offers']);
}

function out(string $s): void
{
    echo $s . "\n";
}

$localRaw = $local->read();
if ($local->lastReadFailed()) {
    out('ERREUR : ' . $local->path() . ' existe mais est illisible (droits ?).');
    exit(1);
}

switch ($cmd) {
    case 'status':
        $mounted = cs_dir_is_mounted($local->dir());
        out('CONTENT_BACKEND : ' . (cs_env('CONTENT_BACKEND', 'local') ?? 'local'));
        out('DATA_DIR        : ' . $local->dir() . ' (' . ($mounted === null ? 'montage inconnu' : ($mounted ? 'volume monté' : 'PAS de volume : éphémère'))
            . ', ' . (is_dir($local->dir()) && is_writable($local->dir()) ? 'inscriptible' : 'NON inscriptible') . ')');
        out('Local           : ' . summary($localRaw));
        $backups = glob($local->dir() . '/backups/content-*.json') ?: [];
        out('Sauvegardes     : ' . count($backups) . ($backups ? ' (dernière : ' . basename((string) end($backups)) . ')' : ''));
        if (!$bunny->configured()) {
            out('Bunny Storage   : clés absentes (non utilisé)');
            break;
        }
        $r = $bunny->fetchFresh();
        out('Bunny Storage   : ' . ($r['state'] === 'ok' ? summary($r['body']) : $r['state'] . ' (' . $r['detail'] . ')'));
        if ($r['state'] === 'ok' && $localRaw !== null) {
            out('Comparaison     : ' . (hash('sha256', (string) $r['body']) === hash('sha256', $localRaw) ? 'IDENTIQUES' : 'DIFFÉRENTS'));
        }
        break;

    case 'pull':
        $r = $bunny->fetchFresh();
        if ($r['state'] !== 'ok') {
            out('Abandon : lecture Bunny ' . $r['state'] . ' (' . $r['detail'] . '). Rien n\'a été modifié.');
            exit(1);
        }
        $remote = (string) $r['body'];
        if ($localRaw !== null && $localRaw === $remote) {
            out('Déjà à jour : le contenu local est identique à Bunny.');
            break;
        }
        if ($localRaw !== null && !$force) {
            out('Abandon : un contenu local DIFFÉRENT existe (' . summary($localRaw) . ').');
            out('Relancer avec --force pour le remplacer (il sera sauvegardé avant).');
            exit(1);
        }
        if ($localRaw !== null) {
            out('Sauvegarde du local : ' . ($local->backup($localRaw, 'avant-pull') ?? 'ÉCHEC'));
        }
        $local->write($remote);
        $local->backup($remote, 'import-bunny');
        $check = $local->read();
        out($check === $remote ? 'OK : Bunny copié en local (' . summary($check) . ').' : 'ERREUR : la relecture locale ne correspond pas.');
        exit($check === $remote ? 0 : 1);

    case 'push':
        if (!$force) {
            out('push remplace le contenu Bunny par le contenu local : relancer avec --force.');
            exit(1);
        }
        if (!valid($localRaw)) {
            out('Abandon : contenu local absent ou invalide. Rien n\'a été envoyé.');
            exit(1);
        }
        $r = $bunny->fetchFresh();
        if ($r['state'] === 'failed') {
            out('Abandon : Bunny injoignable (' . $r['detail'] . ').');
            exit(1);
        }
        if ($r['state'] === 'ok') {
            out('Sauvegarde de la version Bunny : ' . ($local->backup((string) $r['body'], 'bunny-avant-push') ?? 'ÉCHEC'));
        }
        $bunny->write((string) $localRaw);
        $after = $bunny->fetchFresh();
        $ok = $after['state'] === 'ok' && $after['body'] === $localRaw;
        out($ok ? 'OK : contenu local envoyé sur Bunny (' . summary($localRaw) . ').' : 'ERREUR : la relecture Bunny ne correspond pas.');
        exit($ok ? 0 : 1);

    case 'backup':
        if ($localRaw === null) {
            out('Rien à sauvegarder : pas de contenu local.');
            exit(1);
        }
        out('Sauvegarde : ' . ($local->backup($localRaw, 'manuelle') ?? 'ÉCHEC'));
        break;

    default:
        out('Usage : php content-migrate.php status | pull [--force] | push --force | backup');
        exit(2);
}
