<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/inc/bunny_stream.php';
admin_require_login();

$base = admin_base();

/* --- Contraintes d'upload image --- */
const GALLERY_MAX_BYTES = 8 * 1024 * 1024;      // 8 Mo / image
const GALLERY_MAX_DIM   = 2000;                  // px : bord max après ré-encodage GD
const GALLERY_MAX_IMAGES = 40;                   // garde-fou par item
const GALLERY_ALLOWED = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/* --- Contraintes d'upload vidéo (cohérentes avec admin/.htaccess) --- */
const GALLERY_VIDEO_MAX_BYTES = 200 * 1024 * 1024; // 200 Mo / vidéo
const GALLERY_VIDEO_ALLOWED = [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
];

/**
 * Valide un fichier uploadé comme vidéo réelle (type MIME via finfo, jamais
 * l'extension du client). Renvoie ['mime'=>..., 'ext'=>...] ou lève MediaException.
 */
function admin_validate_video(array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        // Dépassement des limites PHP = message explicite.
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new MediaException('Vidéo trop volumineuse (limite serveur dépassée).');
        }
        throw new MediaException('Upload vidéo incomplet (code ' . $err . ').');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new MediaException('Fichier non reçu via un upload HTTP valide.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new MediaException('Fichier vidéo vide.');
    }
    if ($size > GALLERY_VIDEO_MAX_BYTES) {
        throw new MediaException('Vidéo trop volumineuse (max 200 Mo).');
    }
    // MIME réel via finfo (contenu, pas extension).
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    if (!isset(GALLERY_VIDEO_ALLOWED[$mime])) {
        throw new MediaException('Type non autorisé : ' . ($mime ?: 'inconnu') . ' (MP4, WebM ou MOV uniquement).');
    }
    return ['mime' => $mime, 'ext' => GALLERY_VIDEO_ALLOWED[$mime]];
}

/**
 * Valide un fichier uploadé comme image réelle et renvoie
 * ['mime'=>..., 'ext'=>...] ou lève MediaException.
 * Contrôle : upload PHP réel, taille, type MIME réel (finfo + getimagesize),
 * jamais l'extension fournie par le client.
 */
function admin_validate_image(array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new MediaException('Upload incomplet (code ' . $err . ').');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new MediaException('Fichier non reçu via un upload HTTP valide.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > GALLERY_MAX_BYTES) {
        throw new MediaException('Fichier trop volumineux ou vide (max 8 Mo).');
    }
    // MIME réel via finfo (contenu, pas extension).
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    if (!isset(GALLERY_ALLOWED[$mime])) {
        throw new MediaException('Type non autorisé : ' . ($mime ?: 'inconnu') . ' (JPEG, PNG ou WebP uniquement).');
    }
    // Double contrôle : getimagesize doit reconnaître l'image et correspondre au MIME.
    $info = @getimagesize($tmp);
    if ($info === false || !isset($info['mime']) || $info['mime'] !== $mime) {
        throw new MediaException('Le fichier n\'est pas une image valide.');
    }
    return ['mime' => $mime, 'ext' => GALLERY_ALLOWED[$mime]];
}

/**
 * Ré-encode l'image via GD si possible (strip metadata + downscale au besoin) vers
 * un fichier temporaire, renvoyé. Si GD est indisponible/échoue, renvoie le chemin
 * original (stockage tel quel). Renvoie [chemin, bool ré-encodé].
 */
function admin_reencode_image(string $srcTmp, string $mime): array
{
    if (!function_exists('imagecreatefromstring')) {
        return [$srcTmp, false];
    }
    $raw = @file_get_contents($srcTmp);
    if ($raw === false) {
        return [$srcTmp, false];
    }
    $img = @imagecreatefromstring($raw);
    if ($img === false) {
        return [$srcTmp, false];
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = 1.0;
    if ($w > GALLERY_MAX_DIM || $h > GALLERY_MAX_DIM) {
        $scale = GALLERY_MAX_DIM / max($w, $h);
    }
    if ($scale < 1.0) {
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // Préserve la transparence pour PNG/WebP.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }
    $out = tempnam(sys_get_temp_dir(), 'bwimg_');
    if ($out === false) {
        imagedestroy($img);
        return [$srcTmp, false];
    }
    $ok = false;
    if ($mime === 'image/jpeg') {
        $ok = imagejpeg($img, $out, 85);
    } elseif ($mime === 'image/png') {
        $ok = imagepng($img, $out, 6);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        $ok = imagewebp($img, $out, 82);
    }
    imagedestroy($img);
    if (!$ok) {
        @unlink($out);
        return [$srcTmp, false];
    }
    return [$out, true];
}

$id = (string) ($_GET['id'] ?? ($_POST['id'] ?? ''));

try {
    $content = admin_store()->get();
} catch (\Throwable $e) {
    $content = cs_default_content();
}
$item = cs_find_gallery_item($content, $id);

if ($item === null) {
    http_response_code(404);
    admin_head('Introuvable');
    admin_topbar('gallery');
    echo '<div class="card"><h1>Item introuvable</h1><p><a href="' . e($base) . '/gallery.php">Retour à la galerie</a></p></div>';
    admin_foot();
    exit;
}

$err = '';
$notices = [];

/* ============================================================
 *  Enregistrement
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

    // --- Champs scalaires ---
    $item['title'] = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160);
    $item['description'] = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 1200);
    $item['sort'] = (int) ($_POST['sort'] ?? ($item['sort'] ?? 0));
    $item['active'] = !empty($_POST['active']);
    $type = in_array(($_POST['type'] ?? ''), CS_GALLERY_TYPES, true) ? (string) $_POST['type'] : ($item['type'] ?? 'carousel');
    $item['type'] = $type;

    $uploader = cs_make_media_uploader();

    if ($type === 'video') {
        /* ============================================================
         *  Branche VIDÉO — upload médié par le serveur vers Bunny Stream.
         *  Un fichier fourni déclenche : create_video + upload_video, puis
         *  stockage de videoId (guid) et poster (URL vignette). Remplacement
         *  d'une vidéo existante : on supprime l'ancienne sur Bunny.
         * ============================================================ */
        $item['videoId'] = (string) ($item['videoId'] ?? '');
        $item['poster']  = (string) ($item['poster'] ?? '');
        // Un item vidéo n'utilise pas d'images de carrousel.
        $item['images']  = [];

        $newVideo = false;
        $hasUpload = !empty($_FILES['video']['tmp_name'])
            && (int) ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasUpload) {
            try {
                admin_validate_video($_FILES['video']);
                $tmp = (string) $_FILES['video']['tmp_name'];

                // Ancienne vidéo à remplacer : suppression best-effort côté Bunny.
                $oldGuid = (string) ($item['videoId'] ?? '');

                $guid = bunny_stream_create_video((string) ($item['title'] ?? 'Vidéo'));
                bunny_stream_upload_video($guid, $tmp);

                $item['videoId'] = $guid;
                $newVideo = true;
                if (($item['posterPath'] ?? '') === '') {
                    // Pas de couverture perso : on repart sur la vignette auto (poster vide ;
                    // une ancienne frame choisie appartenait à l'ancienne vidéo).
                    $item['poster'] = '';
                }

                if ($oldGuid !== '' && $oldGuid !== $guid) {
                    try {
                        bunny_stream_delete_video($oldGuid);
                    } catch (\Throwable $e) {
                        $notices[] = 'Ancienne vidéo non supprimée (' . e($oldGuid) . ') : ' . e($e->getMessage());
                    }
                }
                if (!bunny_stream_enabled()) {
                    $notices[] = 'Mode local (sans Bunny) : vidéo simulée, videoId factice enregistré.';
                }
            } catch (\Throwable $e) {
                $err = 'Vidéo refusée : ' . $e->getMessage();
            }
        }
        /* --- Source de la couverture (cover_source) ---
         *  auto   : poster + posterPath vides -> la façade publique prend thumbnail.jpg.
         *  frame  : poster = URL de thumbnail_N.jpg (frame Bunny), posterPath vide ;
         *           synchro best-effort de la vignette officielle Bunny.
         *  upload : image perso -> Bunny Storage (poster = URL, posterPath = chemin).
         * Dans tous les cas, quitter une image perso la supprime du stockage. */
        $item['posterPath'] = (string) ($item['posterPath'] ?? '');
        $source = (string) ($_POST['cover_source'] ?? '');
        if (!in_array($source, ['auto', 'frame', 'upload'], true)) {
            $source = '';
        }

        $dropCustom = function () use (&$item, $uploader, &$notices): void {
            $old = (string) $item['posterPath'];
            if ($old !== '') {
                try {
                    $uploader->delete($old);
                } catch (\Throwable $e) {
                    $notices[] = 'Ancienne couverture non supprimée (' . e($old) . ') : ' . e($e->getMessage());
                }
            }
            $item['posterPath'] = '';
        };

        if ($source === 'auto' && $err === '') {
            $dropCustom();
            $item['poster'] = '';
        } elseif ($source === 'frame' && $err === '' && !$newVideo) {
            $n = (int) ($_POST['cover_frame'] ?? 0);
            $frames = bunny_stream_frame_urls((string) $item['videoId']);
            if ($n >= 1 && isset($frames[$n])) {
                $dropCustom();
                $item['poster'] = $frames[$n];
                try {
                    bunny_stream_set_thumbnail((string) $item['videoId'], 'thumbnail_' . $n . '.jpg');
                } catch (\Throwable $e) {
                    // Non bloquant : la couverture publique utilise déjà `poster`.
                    $notices[] = 'Image choisie enregistrée, mais la vignette officielle Bunny n\'a pas pu être synchronisée : ' . e($e->getMessage());
                }
            }
        } elseif ($source === 'frame' && $newVideo) {
            $notices[] = 'Nouvelle vidéo envoyée : choisissez une image de la vidéo une fois l\'encodage terminé.';
        }

        $hasCover = $source === 'upload' && !empty($_FILES['cover']['tmp_name'])
            && (int) ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasCover && $err === '') {
            try {
                $meta = admin_validate_image($_FILES['cover']);
                [$uploadPath, $reencoded] = admin_reencode_image((string) $_FILES['cover']['tmp_name'], $meta['mime']);
                $destPath = 'gallery/' . $id . '/cover-' . bin2hex(random_bytes(6)) . '.' . $meta['ext'];
                try {
                    $url = $uploader->upload($uploadPath, $destPath, $meta['mime']);
                } finally {
                    if ($reencoded) {
                        @unlink($uploadPath);
                    }
                }
                $oldPath = $item['posterPath'];
                $item['posterPath'] = $destPath;
                $item['poster'] = $url;
                if ($oldPath !== '' && $oldPath !== $destPath) {
                    try {
                        $uploader->delete($oldPath);
                    } catch (\Throwable $e) {
                        $notices[] = 'Ancienne couverture non supprimée (' . e($oldPath) . ') : ' . e($e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                $err = 'Image de couverture refusée : ' . $e->getMessage();
            }
        }
    } else {

    // --- Images existantes : mise à jour alt/sort + suppressions ---
    $existing = cs_gallery_item_images($item);
    $keptByPath = [];
    $postPaths = isset($_POST['img_path']) && is_array($_POST['img_path']) ? $_POST['img_path'] : [];
    $postAlt   = isset($_POST['img_alt']) && is_array($_POST['img_alt']) ? $_POST['img_alt'] : [];
    $postSort  = isset($_POST['img_sort']) && is_array($_POST['img_sort']) ? $_POST['img_sort'] : [];
    $postDel   = isset($_POST['img_delete']) && is_array($_POST['img_delete']) ? $_POST['img_delete'] : [];

    // On indexe les champs postés PAR CHEMIN (le formulaire renvoie un champ par image).
    // Puis on itère sur les images RÉELLEMENT stockées (source d'autorité) : une image
    // n'est supprimée que si sa case « Supprimer » est cochée — jamais par simple absence
    // dans le POST (évite une perte de données / des fichiers orphelins sur un POST partiel).
    $delByPath = [];
    $altByPath = [];
    $sortByPath = [];
    foreach ($postPaths as $k => $p) {
        $p = (string) $p;
        if ($p === '') {
            continue;
        }
        if (!empty($postDel[$k])) {
            $delByPath[$p] = true;
        }
        if (isset($postAlt[$k])) {
            $altByPath[$p] = (string) $postAlt[$k];
        }
        if (isset($postSort[$k])) {
            $sortByPath[$p] = (int) $postSort[$k];
        }
    }
    $newImages = [];
    foreach ($existing as $img) {
        $p = (string) ($img['path'] ?? '');
        if ($p === '') {
            continue;
        }
        if (!empty($delByPath[$p])) {
            // Suppression explicite : on retire le fichier et on n'ajoute pas l'image.
            try {
                $uploader->delete($p);
            } catch (\Throwable $e) {
                $notices[] = 'Fichier non supprimé (' . e($p) . ') : ' . $e->getMessage();
            }
            continue;
        }
        if (array_key_exists($p, $altByPath)) {
            $img['alt'] = mb_substr(trim($altByPath[$p]), 0, 240);
        }
        if (array_key_exists($p, $sortByPath)) {
            $img['sort'] = $sortByPath[$p];
        }
        $newImages[] = $img;
    }

    // --- Nouveaux fichiers uploadés ---
    $maxSort = 0;
    foreach ($newImages as $img) {
        $maxSort = max($maxSort, (int) ($img['sort'] ?? 0));
    }
    if (!empty($_FILES['images']) && is_array($_FILES['images']['tmp_name'])) {
        $files = $_FILES['images'];
        $count = count($files['tmp_name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue; // champ vide
            }
            if (count($newImages) >= GALLERY_MAX_IMAGES) {
                $notices[] = 'Limite de ' . GALLERY_MAX_IMAGES . ' images atteinte : fichiers suivants ignorés.';
                break;
            }
            $one = [
                'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'size'     => $files['size'][$i] ?? 0,
            ];
            try {
                $meta = admin_validate_image($one);
            } catch (\Throwable $e) {
                $notices[] = 'Image refusée : ' . $e->getMessage();
                continue;
            }
            [$uploadPath, $reencoded] = admin_reencode_image((string) $one['tmp_name'], $meta['mime']);
            $name = bin2hex(random_bytes(8)) . '.' . $meta['ext'];
            $destPath = 'gallery/' . $id . '/' . $name;
            try {
                $url = $uploader->upload($uploadPath, $destPath, $meta['mime']);
            } catch (\Throwable $e) {
                if ($reencoded) {
                    @unlink($uploadPath);
                }
                $notices[] = 'Échec de l\'enregistrement d\'une image : ' . $e->getMessage();
                continue;
            }
            if ($reencoded) {
                @unlink($uploadPath);
            }
            $maxSort += 10;
            $newImages[] = [
                'path' => $destPath,
                'url'  => $url,
                'alt'  => mb_substr($item['title'] ?? '', 0, 240),
                'sort' => $maxSort,
            ];
        }
    }

    // Réindexe les sort pour rester propres (10, 20, …).
    usort($newImages, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $s = 0;
    foreach ($newImages as &$img) {
        $s += 10;
        $img['sort'] = $s;
    }
    unset($img);
    $item['images'] = $newImages;
    } // fin branche carrousel

    // --- Persiste ---
    $items = cs_gallery($content);
    foreach ($items as &$it) {
        if (($it['id'] ?? '') === $id) {
            $it = $item;
            break;
        }
    }
    unset($it);
    $content['gallery'] = $items;

    // On ne persiste pas si une erreur dure est survenue (ex. vidéo refusée).
    if ($err === '') {
        try {
            admin_store()->save($content);
            if (!$notices) {
                header('Location: ' . $base . '/gallery_edit.php?id=' . rawurlencode($id) . '&saved=1');
                exit;
            }
        } catch (\Throwable $e) {
            $err = 'Échec de l\'enregistrement : ' . $e->getMessage();
        }
    }
}

/* ============================================================
 *  Formulaire
 * ============================================================ */
$type = (string) ($item['type'] ?? 'carousel');
$images = cs_gallery_item_images($item);
$flash = (string) ($_GET['saved'] ?? '');

admin_head('Modifier · ' . (string) ($item['title'] ?? ''));
admin_topbar('gallery');

echo '<span class="eyebrow">Galerie · ' . e($type) . '</span>';
echo '<h1>' . e((string) ($item['title'] ?? 'Item')) . '</h1>';
echo '<p class="muted">Identifiant : <code>' . e($id) . '</code> · <a href="' . e($base) . '/gallery.php">← Retour à la galerie</a></p>';

if ($flash === '1') {
    echo '<div class="alert alert--ok" style="margin-top:18px">Item enregistré.</div>';
}
if ($err !== '') {
    echo '<div class="alert" style="margin-top:18px">' . e($err) . '</div>';
}
foreach ($notices as $nt) {
    echo '<div class="alert" style="margin-top:12px">' . $nt . '</div>';
}

echo '<form method="post" action="' . e($base) . '/gallery_edit.php?id=' . rawurlencode($id) . '" enctype="multipart/form-data">';
echo admin_csrf_field();
echo '<input type="hidden" name="id" value="' . e($id) . '">';

echo '<div class="card">';
echo '<label for="f_title">Titre</label>';
echo '<input type="text" id="f_title" name="title" maxlength="160" value="' . e((string) ($item['title'] ?? '')) . '">';
echo '<label for="f_desc">Description (affichée sous le carrousel)</label>';
echo '<textarea id="f_desc" class="tall" name="description">' . e((string) ($item['description'] ?? '')) . '</textarea>';
echo '<div class="grid2">';
echo '<div><label for="f_type">Type</label>'
    . '<select id="f_type" name="type">'
    . '<option value="carousel"' . ($type === 'carousel' ? ' selected' : '') . '>Carrousel d\'images</option>'
    . '<option value="video"' . ($type === 'video' ? ' selected' : '') . '>Vidéo (Bunny Stream)</option>'
    . '</select></div>';
echo '<div><label for="f_sort">Ordre (sort)</label>'
    . '<input type="text" id="f_sort" name="sort" value="' . e((string) ((int) ($item['sort'] ?? 0))) . '"></div>';
echo '</div>';
echo '<div class="check" style="margin-top:14px"><input type="checkbox" id="f_active" name="active" value="1"' . (!empty($item['active']) ? ' checked' : '') . '><label for="f_active">Actif (visible sur /galerie)</label></div>';
echo '</div>'; // card

if ($type === 'video') {
    $videoId = (string) ($item['videoId'] ?? '');
    $poster  = (string) ($item['poster'] ?? '');
    $enabled = bunny_stream_enabled();

    $posterPath = (string) ($item['posterPath'] ?? '');
    $custom = $posterPath !== '' && $poster !== '';
    $frames = bunny_stream_frame_urls($videoId);
    $curFrame = 0;
    foreach ($frames as $n => $u) {
        if (!$custom && $poster === $u) {
            $curFrame = $n;
        }
    }
    $source = $custom ? 'upload' : ($curFrame > 0 ? 'frame' : 'auto');
    $preview = $poster !== '' ? $poster : ($videoId !== '' ? bunny_stream_thumbnail_url($videoId) : '');
    $vstatus = $videoId !== '' ? bunny_stream_video_status($videoId) : null;
    // Prête = encodage terminé (4) ; statut inconnu (mode local) => on affiche les frames.
    $framesReady = $frames && ($vstatus === null || $vstatus === 4);

    echo '<div class="card"><h2 style="margin-top:0">Vidéo (Bunny Stream)</h2>';

    if ($videoId === '') {
        echo '<p class="muted">Aucune vidéo pour le moment. Téléversez un fichier ci-dessous : il est envoyé à Bunny Stream, puis lu sans marque sur la page publique.</p>';
    } else {
        echo '<p class="muted">Vidéo associée · identifiant <code>' . e($videoId) . '</code>.</p>';
    }
    if (!$enabled) {
        echo '<div class="alert" style="margin-top:14px">Mode local (CONTENT_BACKEND ≠ bunny ou clés absentes) : les vidéos sont simulées (aucun appel réseau). L\'upload réel se fera en production avec les clés Bunny.</div>';
    }

    echo '<h3 style="margin:26px 0 12px;font-family:var(--fm);font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);border-top:1px solid var(--line);padding-top:18px">Image de couverture</h3>';
    if ($preview !== '') {
        echo '<div class="cover-preview"><figure><img class="thumb" src="' . e($preview) . '" alt="Aperçu de la couverture" loading="lazy">'
            . '<figcaption>Couverture actuelle · ' . ($custom ? 'image importée' : ($curFrame > 0 ? 'image de la vidéo n°' . $curFrame : 'vignette automatique')) . '</figcaption></figure></div>';
    } else {
        echo '<p class="muted">Aucune couverture : la page publique affichera un fond neutre avec le bouton lecture.</p>';
    }

    echo '<div class="cover-src" style="margin-top:18px">';
    echo '<div class="radio-cards" role="radiogroup" aria-label="Source de la couverture">';
    $opts = [
        'auto'   => ['Vignette automatique', 'Bunny choisit une image de la vidéo.'],
        'frame'  => ['Image de la vidéo', 'Choisir parmi 5 images extraites.'],
        'upload' => ['Importer une image', 'JPEG, PNG ou WebP, jusqu\'à 8 Mo.'],
    ];
    foreach ($opts as $key => [$ttl, $sub]) {
        echo '<label class="radio-card"><input type="radio" id="src_' . $key . '" name="cover_source" value="' . $key . '"' . ($source === $key ? ' checked' : '') . '>'
            . '<span class="rc"><b>' . e($ttl) . '</b><small>' . e($sub) . '</small></span></label>';
    }
    echo '</div>';

    // -- Panneau 1 : automatique
    echo '<div class="cover-panel cover-panel--auto"><p class="hint" style="margin:0">La page publique utilise la vignette générée par Bunny'
        . ($custom ? ' ; l\'image importée actuelle sera supprimée à l\'enregistrement' : '') . '. Elle peut être vide pendant l\'encodage.</p></div>';

    // -- Panneau 2 : frames candidates
    echo '<div class="cover-panel cover-panel--frame">';
    if ($videoId === '') {
        echo '<p class="hint" style="margin:0">Téléversez d\'abord une vidéo : ses images apparaîtront après l\'encodage.</p>';
    } elseif (!$frames) {
        echo '<p class="hint" style="margin:0">Les images de la vidéo ne sont pas disponibles en mode local (vidéo simulée ou hôte Bunny non configuré).</p>';
    } elseif (!$framesReady) {
        echo '<p class="hint" style="margin:0">Les images de la vidéo apparaîtront après l\'encodage. Revenez dans quelques instants'
            . ($vstatus !== null ? ' (statut Bunny : ' . $vstatus . ')' : '') . '.</p>';
    } else {
        echo '<div class="frames">';
        foreach ($frames as $n => $u) {
            echo '<label class="frame"><input type="radio" name="cover_frame" value="' . $n . '"' . ($curFrame === $n ? ' checked' : '') . '>'
                . '<img class="thumb" src="' . e($u) . '" alt="Image ' . $n . ' de la vidéo" loading="lazy">'
                . '<span class="n">' . str_pad((string) $n, 2, '0', STR_PAD_LEFT) . '</span></label>';
        }
        echo '</div>';
        echo '<p class="hint">Sélectionnez une image puis enregistrez : elle sert de couverture et la vignette officielle Bunny est synchronisée.</p>';
    }
    echo '</div>';

    // -- Panneau 3 : import
    echo '<div class="cover-panel cover-panel--upload">';
    echo '<label for="f_cover" style="margin-top:0">' . ($custom ? 'Remplacer l\'image' : 'Image de couverture') . '</label>';
    echo '<input type="file" id="f_cover" class="filefield" name="cover" accept="image/jpeg,image/png,image/webp">';
    echo '<p class="hint">Format conseillé 16/9 ou 4/3 (ex. 1200×900) · max 8 Mo. Ré-encodée côté serveur puis stockée sur Bunny Storage.</p>';
    echo '</div>';
    echo '</div>'; // cover-src

    echo '<label for="f_video" style="margin-top:26px">' . ($videoId === '' ? 'Téléverser une vidéo' : 'Remplacer la vidéo') . ' (MP4, WebM ou MOV · max 200 Mo)</label>';
    echo '<input type="file" id="f_video" class="filefield" name="video" accept="video/mp4,video/webm,video/quicktime">';
    echo '<p class="hint">Le fichier transite par le serveur puis Bunny Stream. L\'encodage peut prendre quelques instants après l\'envoi ; la lecture publique reste disponible dès que Bunny a fini.</p>';
    echo '</div>'; // card
} else {
    // --- Images existantes ---
    echo '<div class="card"><h2 style="margin-top:0">Images</h2>';
    if (!$images) {
        echo '<p class="muted">Aucune image. Ajoutez-en ci-dessous.</p>';
    } else {
        echo '<p class="muted">Modifiez le texte alternatif, l\'ordre (ou les flèches), ou cochez « Supprimer ».</p>';
        echo '<div class="imggrid" id="imggrid">';
        foreach ($images as $k => $img) {
            $path = (string) ($img['path'] ?? '');
            $url = (string) ($img['url'] ?? '');
            echo '<div class="imgcard" data-row>';
            echo '<img class="thumb" src="' . e($url) . '" alt="" loading="lazy">';
            echo '<input type="hidden" name="img_path[' . $k . ']" value="' . e($path) . '">';
            echo '<label style="margin-top:10px">Texte alternatif</label>';
            echo '<input type="text" name="img_alt[' . $k . ']" value="' . e((string) ($img['alt'] ?? '')) . '" maxlength="240">';
            echo '<div style="display:flex;gap:8px;align-items:center;margin-top:8px">';
            echo '<div style="flex:1"><label style="margin:0 0 4px">Ordre</label>'
                . '<input type="text" name="img_sort[' . $k . ']" data-sort value="' . e((string) ((int) ($img['sort'] ?? 0))) . '" style="width:100%"></div>';
            echo '<button type="button" class="btn btn--ghost btn--sm" data-move="up" title="Monter">↑</button>';
            echo '<button type="button" class="btn btn--ghost btn--sm" data-move="down" title="Descendre">↓</button>';
            echo '</div>';
            echo '<div class="check" style="margin-top:8px"><input type="checkbox" id="del_' . $k . '" name="img_delete[' . $k . ']" value="1"><label for="del_' . $k . '">Supprimer</label></div>';
            echo '</div>';
        }
        echo '</div>';
    }

    // --- Ajout d'images ---
    echo '<label for="f_files" style="margin-top:20px">Ajouter des images (JPEG, PNG, WebP · max 8 Mo/image)</label>';
    echo '<input type="file" id="f_files" class="filefield" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>';
    echo '<p class="hint">Les fichiers sont renommés côté serveur et ré-encodés (métadonnées supprimées, redimensionnés à ' . GALLERY_MAX_DIM . ' px max).</p>';
    echo '</div>'; // card
}

echo '<div class="formbar">';
echo '<button class="btn" type="submit">Enregistrer</button>';
echo '<a class="btn btn--ghost" href="' . e($base) . '/gallery.php">Retour</a>';
echo '</div>';
echo '</form>';

echo '<script src="/js/admin-gallery.js" defer></script>';
admin_foot();
