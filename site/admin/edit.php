<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
admin_require_login();

$base = admin_base();

/** Champs texte éditables (clé => libellé, type). Rendus seulement s'ils existent sur l'offre. */
const ADMIN_TEXT_FIELDS = [
    'number'      => ['Numéro (ex. OFFRE 01)', 'text'],
    'tag'         => ['Étiquette (tag)', 'text'],
    'badge'       => ['Badge (ex. Le plus demandé)', 'text'],
    'title'       => ['Titre', 'text'],
    'description' => ['Description', 'textarea'],
    'inc'         => ['Inclus (texte)', 'textarea'],
    'price_label' => ['Libellé prix (ex. À partir de)', 'text'],
    'price'       => ['Prix (ex. 999 €)', 'text'],
    'price_unit'  => ['Unité de prix (ex.  / mois)', 'text'],
    'price_note'  => ['Note sous le prix', 'textarea'],
    'cta_label'   => ['Libellé du bouton', 'text'],
];

const ADMIN_MAXLEN = 600;

$id = (string) ($_GET['id'] ?? ($_POST['id'] ?? ''));

try {
    $content = admin_store()->get();
} catch (\Throwable $e) {
    $content = cs_default_content();
}
$offer = cs_find_offer($content, $id);

if ($offer === null) {
    http_response_code(404);
    admin_head('Introuvable');
    echo '<div class="card"><h1>Offre introuvable</h1><p><a href="' . e($base) . '/">Retour à la liste</a></p></div>';
    admin_foot();
    exit;
}

$err = '';

/* ---------- Enregistrement ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_check()) {
        http_response_code(400);
        admin_head('Erreur');
        echo '<div class="card"><h1>Requête invalide</h1><p class="muted">Jeton CSRF manquant ou invalide.</p>'
            . '<p><a href="' . e($base) . '/">Retour</a></p></div>';
        admin_foot();
        exit;
    }

    // Champs texte
    foreach (ADMIN_TEXT_FIELDS as $key => $_meta) {
        if (!array_key_exists($key, $offer)) {
            continue;
        }
        $val = (string) ($_POST[$key] ?? '');
        $val = trim($val);
        if (mb_strlen($val) > ADMIN_MAXLEN) {
            $val = mb_substr($val, 0, ADMIN_MAXLEN);
        }
        $offer[$key] = $val;
    }

    // Caractéristiques (une par ligne)
    if (array_key_exists('features', $offer)) {
        $raw = (string) ($_POST['features'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $features = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l !== '') {
                $features[] = mb_substr($l, 0, ADMIN_MAXLEN);
            }
        }
        $offer['features'] = $features;
    }

    // Packs (format "libellé | prix | prix unitaire" par ligne)
    if (array_key_exists('packs', $offer)) {
        $raw = (string) ($_POST['packs'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $packs = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l === '') {
                continue;
            }
            $cols = array_map('trim', explode('|', $l));
            $packs[] = [
                'a' => mb_substr($cols[0] ?? '', 0, 120),
                'b_main' => mb_substr($cols[1] ?? '', 0, 60),
                'b_small' => mb_substr($cols[2] ?? '', 0, 60),
            ];
        }
        $offer['packs'] = $packs;
    }

    // sort / active
    $offer['sort'] = (int) ($_POST['sort'] ?? ($offer['sort'] ?? 0));
    $offer['active'] = !empty($_POST['active']);
    $offer['feat'] = !empty($_POST['feat']);
    // Case « Indisponible » cochée => available = false
    $offer['available'] = empty($_POST['unavailable']);

    // Promo
    $until = trim((string) ($_POST['promo_until'] ?? ''));
    if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
        $err = 'La date de fin de promo doit être au format AAAA-MM-JJ.';
    }
    if ($err === '') {
        $offer['promo'] = [
            'active' => !empty($_POST['promo_active']),
            'price' => trim(mb_substr((string) ($_POST['promo_price'] ?? ''), 0, 60)),
            'label' => trim(mb_substr((string) ($_POST['promo_label'] ?? ''), 0, 40)),
            'until' => $until,
        ];

        // Réécrit l'offre dans la liste
        $offers = cs_offers($content);
        foreach ($offers as &$o) {
            if (($o['id'] ?? '') === $id) {
                $o = $offer;
                break;
            }
        }
        unset($o);
        $content['offers'] = $offers;

        try {
            admin_store()->save($content);
            header('Location: ' . $base . '/?saved=1');
            exit;
        } catch (\Throwable $e) {
            $err = 'Échec de l\'enregistrement : ' . $e->getMessage();
        }
    }
}

/* ---------- Formulaire ---------- */
$promo = $offer['promo'] ?? ['active' => false, 'price' => '', 'label' => '', 'until' => ''];

admin_head('Modifier · ' . (string) ($offer['title'] ?? ''));
echo '<div class="topbar"><span class="brand"><b>BW</b> Admin</span>'
    . '<a class="muted" href="' . e($base) . '/">← Toutes les offres</a></div>';
echo '<span class="eyebrow">Onglet ' . e((string) ($offer['tab'] ?? '')) . '</span>';
echo '<h1>' . e((string) ($offer['title'] ?? 'Offre')) . '</h1>';
echo '<p class="muted">Identifiant : <code>' . e($id) . '</code></p>';

if ($err !== '') {
    echo '<div class="alert">' . e($err) . '</div>';
}

echo '<form method="post" action="' . e($base) . '/edit.php?id=' . rawurlencode($id) . '">';
echo admin_csrf_field();
echo '<input type="hidden" name="id" value="' . e($id) . '">';

echo '<div class="card">';
foreach (ADMIN_TEXT_FIELDS as $key => $meta) {
    if (!array_key_exists($key, $offer)) {
        continue;
    }
    [$lbl, $type] = $meta;
    $cur = (string) ($offer[$key] ?? '');
    echo '<label for="f_' . e($key) . '">' . e($lbl) . '</label>';
    if ($type === 'textarea') {
        echo '<textarea id="f_' . e($key) . '" name="' . e($key) . '">' . e($cur) . '</textarea>';
    } else {
        echo '<input type="text" id="f_' . e($key) . '" name="' . e($key) . '" value="' . e($cur) . '">';
    }
}

if (array_key_exists('features', $offer)) {
    echo '<label for="f_features">Caractéristiques (une par ligne)</label>';
    echo '<textarea id="f_features" name="features" style="min-height:130px">'
        . e(implode("\n", array_map('strval', $offer['features'] ?? []))) . '</textarea>';
}

if (array_key_exists('packs', $offer)) {
    $packLines = [];
    foreach (($offer['packs'] ?? []) as $p) {
        $packLines[] = ($p['a'] ?? '') . ' | ' . ($p['b_main'] ?? '') . ' | ' . ($p['b_small'] ?? '');
    }
    echo '<label for="f_packs">Packs (format : libellé | prix | prix unitaire)</label>';
    echo '<textarea id="f_packs" name="packs">' . e(implode("\n", $packLines)) . '</textarea>';
}

echo '<div class="grid2">';
echo '<div><label for="f_sort">Ordre (sort)</label>'
    . '<input type="text" id="f_sort" name="sort" value="' . e((string) ((int) ($offer['sort'] ?? 0))) . '"></div>';
echo '<div style="display:flex;flex-direction:column;justify-content:flex-end;gap:10px">';
echo '<div class="check"><input type="checkbox" id="f_active" name="active" value="1"' . (!empty($offer['active']) ? ' checked' : '') . '><label for="f_active">Offre active (visible sur le site)</label></div>';
echo '<div class="check"><input type="checkbox" id="f_unavail" name="unavailable" value="1"' . ((array_key_exists('available', $offer) && empty($offer['available'])) ? ' checked' : '') . '><label for="f_unavail">Indisponible (carte grisée sur le site)</label></div>';
echo '<div class="check"><input type="checkbox" id="f_feat" name="feat" value="1"' . (!empty($offer['feat']) ? ' checked' : '') . '><label for="f_feat">Mise en avant (bordure accent)</label></div>';
echo '</div>';
echo '</div>';
echo '</div>'; // card

// Promo
echo '<fieldset class="fieldset"><legend>Promotion</legend>';
echo '<div class="check"><input type="checkbox" id="p_active" name="promo_active" value="1"' . (!empty($promo['active']) ? ' checked' : '') . '><label for="p_active">Activer la promo</label></div>';
echo '<div class="grid2">';
echo '<div><label for="p_price">Prix promo (ex. 799 €)</label>'
    . '<input type="text" id="p_price" name="promo_price" value="' . e((string) ($promo['price'] ?? '')) . '"></div>';
echo '<div><label for="p_label">Étiquette (ex. -20%)</label>'
    . '<input type="text" id="p_label" name="promo_label" value="' . e((string) ($promo['label'] ?? '')) . '"></div>';
echo '</div>';
echo '<label for="p_until">Fin de la promo (optionnel, AAAA-MM-JJ)</label>'
    . '<input type="date" id="p_until" name="promo_until" value="' . e((string) ($promo['until'] ?? '')) . '">';
echo '<p class="muted" style="margin-top:8px">Si une promo est active et non expirée, le site affiche le prix d\'origine barré, le prix promo et l\'étiquette.</p>';
echo '</fieldset>';

echo '<div style="margin-top:22px;display:flex;gap:10px">';
echo '<button class="btn" type="submit">Enregistrer</button>';
echo '<a class="btn btn--ghost" href="' . e($base) . '/">Annuler</a>';
echo '</div>';
echo '</form>';

admin_foot();
