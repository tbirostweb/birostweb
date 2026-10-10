<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/inc/bunny_stream.php';
admin_require_login();

$base = admin_base();

/** Slug propre et unique pour un nouvel item (segments [a-z0-9-]). */
function admin_gallery_slug(string $title, array $existingIds): string
{
    $s = strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') {
        $s = 'item';
    }
    $s = substr($s, 0, 48);
    $base = $s;
    $n = 2;
    while (in_array($s, $existingIds, true)) {
        $s = $base . '-' . $n;
        $n++;
    }
    return $s;
}

/** Réordonne un item (échange son `sort` avec le voisin dans la direction demandée). */
function admin_gallery_move(array $items, string $id, string $dir): array
{
    $sorted = $items;
    usort($sorted, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $pos = null;
    foreach ($sorted as $i => $it) {
        if (($it['id'] ?? '') === $id) {
            $pos = $i;
            break;
        }
    }
    if ($pos === null) {
        return $items;
    }
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($sorted)) {
        return $items;
    }
    $idA = $sorted[$pos]['id'];
    $idB = $sorted[$swap]['id'];
    $sortA = (int) ($sorted[$pos]['sort'] ?? 0);
    $sortB = (int) ($sorted[$swap]['sort'] ?? 0);
    foreach ($items as &$it) {
        if (($it['id'] ?? '') === $idA) {
            $it['sort'] = $sortB;
        } elseif (($it['id'] ?? '') === $idB) {
            $it['sort'] = $sortA;
        }
    }
    unset($it);
    return $items;
}

$err = '';

/* ============================================================
 *  POST : create / delete / toggle / move (auth + CSRF requis)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_check()) {
        http_response_code(400);
        admin_head('Erreur');
        admin_topbar('gallery');
        echo '<div class="card"><h1>Requête invalide</h1><p class="muted">Jeton CSRF manquant ou invalide.</p>'
            . '<p><a href="' . e($base) . '/gallery.php">Retour</a></p></div>';
        admin_foot();
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['id'] ?? '');

    try {
        $content = admin_store()->get();
        $items = cs_gallery($content);

        if ($action === 'create') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $type = in_array(($_POST['type'] ?? ''), CS_GALLERY_TYPES, true) ? (string) $_POST['type'] : 'carousel';
            if ($title === '') {
                $err = 'Donnez un titre à l\'item.';
            } else {
                $ids = array_map(static fn ($it) => (string) ($it['id'] ?? ''), $items);
                $newId = admin_gallery_slug($title, $ids);
                $maxSort = 0;
                foreach ($items as $it) {
                    $maxSort = max($maxSort, (int) ($it['sort'] ?? 0));
                }
                $item = [
                    'id' => $newId,
                    'type' => $type,
                    'title' => mb_substr($title, 0, 160),
                    'description' => '',
                    'sort' => $maxSort + 10,
                    'active' => false,
                    'images' => [],
                ];
                if ($type === 'video') {
                    // Champs posés pour la phase 2 (non gérés ici).
                    $item['videoId'] = '';
                    $item['poster'] = '';
                }
                $items[] = $item;
                $content['gallery'] = $items;
                admin_store()->save($content);
                header('Location: ' . $base . '/gallery_edit.php?id=' . rawurlencode($newId));
                exit;
            }
        } elseif ($action === 'toggle') {
            foreach ($items as &$it) {
                if (($it['id'] ?? '') === $id) {
                    $it['active'] = empty($it['active']);
                    break;
                }
            }
            unset($it);
            $content['gallery'] = $items;
            admin_store()->save($content);
            header('Location: ' . $base . '/gallery.php');
            exit;
        } elseif ($action === 'move') {
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            $content['gallery'] = admin_gallery_move($items, $id, $dir);
            admin_store()->save($content);
            header('Location: ' . $base . '/gallery.php');
            exit;
        } elseif ($action === 'delete') {
            $target = null;
            foreach ($items as $it) {
                if (($it['id'] ?? '') === $id) {
                    $target = $it;
                    break;
                }
            }
            if ($target !== null) {
                // Supprime d'abord les fichiers média de l'item (best-effort).
                $uploader = cs_make_media_uploader();
                foreach (($target['images'] ?? []) as $img) {
                    $path = (string) ($img['path'] ?? '');
                    if ($path !== '') {
                        try {
                            $uploader->delete($path);
                        } catch (\Throwable $e) {
                            // On continue : la suppression de l'item prime.
                        }
                    }
                }
                // Item vidéo : supprime aussi la vidéo côté Bunny Stream (best-effort).
                if (($target['type'] ?? '') === 'video' && ($target['videoId'] ?? '') !== '') {
                    try {
                        bunny_stream_delete_video((string) $target['videoId']);
                    } catch (\Throwable $e) {
                        // On continue : la suppression de l'item prime.
                    }
                }
                $items = array_values(array_filter($items, static fn ($it) => ($it['id'] ?? '') !== $id));
                $content['gallery'] = $items;
                admin_store()->save($content);
            }
            header('Location: ' . $base . '/gallery.php');
            exit;
        }
    } catch (\Throwable $e) {
        $err = 'Erreur : ' . $e->getMessage();
    }
}

/* ============================================================
 *  Affichage de la liste
 * ============================================================ */
try {
    $content = admin_store()->get();
} catch (\Throwable $e) {
    $content = cs_default_content();
}
$items = cs_gallery_items($content); // triés par sort
$n = count($items);
$flash = (string) ($_GET['saved'] ?? '');

admin_head('Galerie');
admin_topbar('gallery');

echo '<span class="eyebrow">Back-office</span><h1>Galerie</h1>';
echo '<p class="muted">Carrousels d\'images (et, à venir, vidéos). Les items actifs apparaissent sur <a href="/galerie" target="_blank" rel="noopener">/galerie</a>.</p>';

if ($flash === '1') {
    echo '<div class="alert alert--ok" style="margin-top:18px">Item enregistré.</div>';
}
if ($err !== '') {
    echo '<div class="alert" style="margin-top:18px">' . e($err) . '</div>';
}

/* --- Formulaire de création --- */
echo '<div class="card" style="margin-top:18px">';
echo '<h2 style="margin-top:0">Nouvel item</h2>';
echo '<form method="post" action="' . e($base) . '/gallery.php">';
echo admin_csrf_field();
echo '<input type="hidden" name="action" value="create">';
echo '<div class="grid2">';
echo '<div><label for="g_title">Titre</label>'
    . '<input type="text" id="g_title" name="title" maxlength="160" required></div>';
echo '<div><label for="g_type">Type</label>'
    . '<select id="g_type" name="type" style="width:100%;font-family:var(--fb);font-size:15px;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:10px 12px">'
    . '<option value="carousel">Carrousel d\'images</option>'
    . '<option value="video">Vidéo (Bunny Stream)</option>'
    . '</select></div>';
echo '</div>';
echo '<div style="margin-top:16px"><button class="btn" type="submit">Créer l\'item</button></div>';
echo '</form>';
echo '</div>';

/* --- Liste --- */
echo '<h2>Items</h2>';
if (!$items) {
    echo '<p class="muted">Aucun item pour le moment.</p>';
}
$csrf = admin_csrf_field();
foreach ($items as $i => $it) {
    $id = (string) ($it['id'] ?? '');
    $type = (string) ($it['type'] ?? 'carousel');
    $imgCount = count($it['images'] ?? []);
    echo '<div class="offer-line"><div class="row">';
    echo '<div>';
    echo '<div class="t">' . e((string) ($it['title'] ?? '')) . ' ';
    echo '<span class="badge-type' . ($type === 'video' ? ' badge-type--video' : '') . '">' . e($type) . '</span></div>';
    echo '<div class="meta"><code>' . e($id) . '</code> · sort ' . (int) ($it['sort'] ?? 0);
    if ($type === 'carousel') {
        echo ' · ' . $imgCount . ' image' . ($imgCount > 1 ? 's' : '');
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="actions">';
    echo empty($it['active'])
        ? '<span class="pill pill--off">Inactif</span>'
        : '<span class="pill pill--on">Actif</span>';
    // Monter / descendre
    echo '<form class="inline" method="post" action="' . e($base) . '/gallery.php">' . $csrf
        . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<input type="hidden" name="dir" value="up">'
        . '<button class="btn btn--ghost btn--sm" type="submit"' . ($i === 0 ? ' disabled' : '') . '>↑</button></form>';
    echo '<form class="inline" method="post" action="' . e($base) . '/gallery.php">' . $csrf
        . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<input type="hidden" name="dir" value="down">'
        . '<button class="btn btn--ghost btn--sm" type="submit"' . ($i === $n - 1 ? ' disabled' : '') . '>↓</button></form>';
    // Toggle
    echo '<form class="inline" method="post" action="' . e($base) . '/gallery.php">' . $csrf
        . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="btn btn--ghost btn--sm" type="submit">' . (empty($it['active']) ? 'Activer' : 'Désactiver') . '</button></form>';
    // Éditer
    echo '<a class="btn btn--sm" href="' . e($base) . '/gallery_edit.php?id=' . rawurlencode($id) . '">Modifier</a>';
    // Supprimer
    echo '<form class="inline" method="post" action="' . e($base) . '/gallery.php" data-confirm="Supprimer cet item et ses images ?">' . $csrf
        . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="btn btn--ghost btn--sm" type="submit">Supprimer</button></form>';
    echo '</div></div></div>';
}

echo '<script src="/js/admin-gallery.js" defer></script>';
admin_foot();
