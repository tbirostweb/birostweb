<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/* ============================================================
 *  1) Admin non configuré -> fail-closed
 * ============================================================ */
if (!admin_configured()) {
    http_response_code(503);
    admin_head('Non configuré');
    echo '<div class="topbar"><span class="brand"><b>BW</b> Admin</span></div>';
    echo '<div class="card"><span class="eyebrow">Indisponible</span>'
        . '<h1>Back-office non configuré</h1>'
        . '<p class="muted">Les identifiants administrateur ne sont pas définis. '
        . 'Définissez les variables d\'environnement <code>ADMIN_USER</code> et '
        . '<code>ADMIN_PASSWORD_HASH</code> (hash <code>password_hash</code>) pour activer l\'accès.</p></div>';
    admin_foot();
    exit;
}

$err = '';
$ip  = contact_client_ip();

/* ============================================================
 *  2) POST : soit connexion, soit action sur une offre
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'login');

    if ($action === 'login') {
        if (!admin_csrf_check()) {
            $err = 'Session expirée, merci de réessayer.';
        } elseif (admin_login_limited($ip)) {
            $err = 'Trop de tentatives. Réessayez dans quelques minutes.';
        } else {
            $u = (string) ($_POST['user'] ?? '');
            $p = (string) ($_POST['password'] ?? '');
            $userOk = hash_equals(admin_user(), $u);
            $passOk = password_verify($p, admin_hash());
            // 2FA TOTP : exigée seulement si ADMIN_TOTP_SECRET est défini (sinon comportement inchangé).
            $totpOk = admin_totp_secret() === '' || ($userOk && $passOk && admin_totp_verify((string) ($_POST['totp'] ?? '')));
            if ($userOk && $passOk && $totpOk) {
                admin_login_reset($ip);
                session_regenerate_id(true);
                $_SESSION['admin_auth'] = true;
                $_SESSION['admin_user'] = admin_user();
                $_SESSION['admin_last'] = time();
                $_SESSION['admin_started'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: ' . admin_base() . '/');
                exit;
            }
            admin_login_record_failure($ip);
            usleep(300000); // ralentit légèrement le brute-force
            $err = 'Identifiants invalides.';
        }
    } else {
        // Actions protégées : exigent une session + CSRF.
        if (!admin_is_authenticated()) {
            header('Location: ' . admin_base() . '/');
            exit;
        }
        if (!admin_csrf_check()) {
            http_response_code(400);
            admin_head('Erreur');
            echo '<div class="card"><h1>Requête invalide</h1><p class="muted">Jeton CSRF manquant ou invalide.</p>'
                . '<p><a href="' . e(admin_base()) . '/">Retour</a></p></div>';
            admin_foot();
            exit;
        }
        $id = (string) ($_POST['id'] ?? '');
        try {
            $content = admin_store()->get();
            $offers  = cs_offers($content);
            if ($action === 'toggle') {
                foreach ($offers as &$o) {
                    if (($o['id'] ?? '') === $id) {
                        $o['active'] = empty($o['active']);
                        break;
                    }
                }
                unset($o);
            } elseif ($action === 'move') {
                $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
                $offers = admin_move_offer($offers, $id, $dir);
            } elseif ($action === 'create') {
                $tab   = (string) ($_POST['tab'] ?? '');
                $title = trim((string) ($_POST['title'] ?? ''));
                if (!in_array($tab, ['crea', 'heb', 'seo', 'maint'], true) || $title === '') {
                    header('Location: ' . admin_base() . '/?err=create');
                    exit;
                }
                $title = mb_substr($title, 0, 160);
                $newId = admin_offer_slug($tab, $title, array_map(static fn ($o) => (string) ($o['id'] ?? ''), $offers));
                $offers[] = admin_offer_template($tab, $newId, $title, $offers);
                $content['offers'] = $offers;
                admin_store()->save($content);
                header('Location: ' . admin_base() . '/edit.php?id=' . rawurlencode($newId));
                exit;
            } elseif ($action === 'seed_seo') {
                // Ajoute les offres SEO par défaut du code absentes du contenu enregistré (ne touche à rien d'autre).
                $have = array_map(static fn ($o) => (string) ($o['id'] ?? ''), $offers);
                foreach (cs_offers(cs_default_content()) as $d) {
                    if (($d['tab'] ?? '') === 'seo' && !in_array((string) ($d['id'] ?? ''), $have, true)) {
                        $offers[] = $d;
                    }
                }
            } elseif ($action === 'delete') {
                $offers = array_values(array_filter($offers, static fn ($o) => ($o['id'] ?? '') !== $id));
            }
            $content['offers'] = $offers;
            admin_store()->save($content);
        } catch (\Throwable $e) {
            // Ignore : on retombe sur l'affichage courant.
        }
        header('Location: ' . admin_base() . '/');
        exit;
    }
}

/** Slug unique « tab-titre » (segments [a-z0-9-]). */
function admin_offer_slug(string $tab, string $title, array $existingIds): string
{
    $t = strtolower(trim((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title)));
    $t = trim((string) preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    $base = substr($tab . '-' . ($t !== '' ? $t : 'offre'), 0, 48);
    $s = $base;
    $n = 2;
    while (in_array($s, $existingIds, true)) {
        $s = $base . '-' . $n++;
    }
    return $s;
}

/**
 * Offre par défaut, avec EXACTEMENT les clés du seed de l'onglet.
 * Créée inactive : elle n'apparaît sur le site qu'une fois complétée et activée.
 */
function admin_offer_template(string $tab, string $id, string $title, array $offers): array
{
    $maxSort = 0;
    $count = 0;
    foreach ($offers as $o) {
        if (($o['tab'] ?? '') === $tab) {
            $maxSort = max($maxSort, (int) ($o['sort'] ?? 0));
            $count++;
        }
    }
    $base = [
        'id' => $id, 'tab' => $tab, 'sort' => $maxSort + 10,
        'active' => false, 'available' => true, 'feat' => false, 'badge' => '',
    ];
    $promo = ['active' => false, 'price' => '', 'label' => '', 'until' => ''];
    if ($tab === 'crea') {
        return $base + [
            'number' => 'OFFRE ' . str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT),
            'tag' => $title,
            'title' => $title,
            'description' => 'Description de l\'offre.',
            'inc' => 'Ce qui est inclus.',
            'price_label' => 'Fourchette',
            'price' => '0 €',
            'price_unit' => '',
            'price_note' => '',
            'cta_label' => 'Demander un devis',
            'cta_data' => [
                'data-step' => 'offre',
                'data-label' => $title,
                'data-oneoff' => '0',
                'data-form' => 'Devis sur-mesure',
                'data-next' => 'tab-heb',
            ],
            'promo' => $promo,
        ];
    }
    return $base + [
        'title' => $title,
        'description' => 'Description de l\'offre',
        'price' => '0 €',
        'price_unit' => ' / mois',
        'features' => ['Caractéristique 1'],
        'cta_label' => 'Choisir cette option',
        'cta_data' => $tab === 'heb'
            ? ['data-step' => 'heb', 'data-label' => $title, 'data-mo' => '0', 'data-form' => $title]
            : ($tab === 'seo'
                ? ['data-step' => 'seo', 'data-label' => $title, 'data-oneoff' => '0', 'data-form' => $id]
                : ['data-step' => 'maint', 'data-label' => $title, 'data-mo' => '0', 'data-form' => $title]),
        'promo' => $promo,
    ];
}

/**
 * Réordonne : échange la valeur `sort` de l'offre avec sa voisine (même onglet)
 * dans la direction demandée.
 */
function admin_move_offer(array $offers, string $id, string $dir): array
{
    // Index des offres par onglet, triées par sort.
    $target = null;
    foreach ($offers as $o) {
        if (($o['id'] ?? '') === $id) {
            $target = $o;
            break;
        }
    }
    if ($target === null) {
        return $offers;
    }
    $tab = $target['tab'] ?? '';
    $same = array_values(array_filter($offers, static fn ($o) => ($o['tab'] ?? '') === $tab));
    usort($same, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $pos = null;
    foreach ($same as $i => $o) {
        if (($o['id'] ?? '') === $id) {
            $pos = $i;
            break;
        }
    }
    if ($pos === null) {
        return $offers;
    }
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($same)) {
        return $offers;
    }
    $idA = $same[$pos]['id'];
    $idB = $same[$swap]['id'];
    $sortA = (int) ($same[$pos]['sort'] ?? 0);
    $sortB = (int) ($same[$swap]['sort'] ?? 0);
    foreach ($offers as &$o) {
        if (($o['id'] ?? '') === $idA) {
            $o['sort'] = $sortB;
        } elseif (($o['id'] ?? '') === $idB) {
            $o['sort'] = $sortA;
        }
    }
    unset($o);
    return $offers;
}

/* ============================================================
 *  3) Non connecté -> page de connexion
 * ============================================================ */
if (!admin_is_authenticated()) {
    admin_head('Connexion');
    echo '<div class="topbar"><span class="brand"><b>BW</b> Admin</span></div>';
    echo '<div class="card" style="max-width:420px;margin:0 auto">';
    echo '<span class="eyebrow">Espace privé</span><h1>Connexion</h1>';
    if ($err !== '') {
        echo '<div class="alert">' . e($err) . '</div>';
    }
    echo '<form method="post" action="' . e(admin_base()) . '/">';
    echo admin_csrf_field();
    echo '<input type="hidden" name="action" value="login">';
    echo '<label for="user">Identifiant</label>';
    echo '<input type="text" id="user" name="user" autocomplete="username" autofocus required>';
    echo '<label for="password">Mot de passe</label>';
    echo '<input type="password" id="password" name="password" autocomplete="current-password" required>';
    if (admin_totp_secret() !== '') {
        echo '<label for="totp">Code de vérification (2FA)</label>';
        echo '<input type="text" id="totp" name="totp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required>';
    }
    echo '<div style="margin-top:20px"><button class="btn" type="submit">Se connecter</button></div>';
    echo '</form></div>';
    admin_foot();
    exit;
}

/* ============================================================
 *  4) Connecté -> tableau de bord (liste des offres)
 * ============================================================ */
$content = admin_store()->get();
$grouped = cs_offers_by_tab($content, false); // inclut les inactives
$flash   = (string) ($_GET['saved'] ?? '');

$tabNames = ['crea' => 'Création de site', 'heb' => 'Hébergement', 'seo' => 'SEO (onglet Référencement)', 'maint' => 'Maintenance'];

admin_head('Offres');
admin_topbar('offers');

echo '<span class="eyebrow">Back-office</span><h1>Offres &amp; promos</h1>';
echo '<p class="muted">Modifiez prix, descriptions, caractéristiques et promos. Les changements sont visibles immédiatement sur le site public.</p>';

if ($flash === '1') {
    echo '<div class="alert alert--ok" style="margin-top:18px">Offre enregistrée.</div>';
}

// Garde-fou : en production (HTTPS), un stockage local est éphémère -> les modifications seraient perdues au redéploiement.
if (admin_is_https() && strtolower((string) (cs_env('CONTENT_BACKEND', 'local') ?? 'local')) !== 'bunny') {
    echo '<div class="alert" style="margin-top:18px"><b>Attention :</b> <code>CONTENT_BACKEND</code> n\'est pas « bunny ». '
        . 'Les modifications sont enregistrées sur le disque du conteneur et seront perdues au prochain déploiement.</div>';
}

if (($_GET['err'] ?? '') === 'create') {
    echo '<div class="alert" style="margin-top:18px">Choisissez un onglet et donnez un titre à la nouvelle offre.</div>';
}

$base = e(admin_base());
$csrf = admin_csrf_field();

echo '<div class="card" style="margin-top:18px">';
echo '<h2 style="margin-top:0">+ Nouvelle offre</h2>';
echo '<form method="post" action="' . $base . '/">' . $csrf . '<input type="hidden" name="action" value="create">';
echo '<div class="grid2">';
echo '<div><label for="n_title">Titre</label><input type="text" id="n_title" name="title" maxlength="160" required></div>';
echo '<div><label for="n_tab">Onglet</label><select id="n_tab" name="tab">';
foreach ($tabNames as $tk => $tl) {
    echo '<option value="' . e($tk) . '">' . e($tl) . '</option>';
}
echo '</select></div></div>';
echo '<p class="hint">L\'offre est créée inactive : complétez-la puis activez-la pour l\'afficher sur le site.</p>';
echo '<div style="margin-top:16px"><button class="btn" type="submit">+ Nouvelle offre</button></div>';
echo '</form></div>';

$missingSeo = false;
$haveIds = array_map(static fn ($o) => (string) ($o['id'] ?? ''), cs_offers($content));
foreach (cs_offers(cs_default_content()) as $d) {
    if (($d['tab'] ?? '') === 'seo' && !in_array((string) ($d['id'] ?? ''), $haveIds, true)) {
        $missingSeo = true;
    }
}
if ($missingSeo) {
    echo '<div class="card" style="margin-top:18px"><b>Section SEO :</b> les offres SEO par défaut (Référencement local 399 €, Fiche Google Business 149 €) ne sont pas encore dans votre contenu enregistré. '
        . '<form method="post" action="' . $base . '/" style="margin-top:12px">' . $csrf . '<input type="hidden" name="action" value="seed_seo">'
        . '<button class="btn" type="submit">Ajouter les offres SEO par défaut</button></form></div>';
}

foreach ($tabNames as $tab => $label) {
    echo '<div class="tabgroup"><div class="lbl">' . e($label) . '</div>';
    $list = $grouped[$tab] ?? [];
    if (!$list) {
        echo '<p class="muted" style="margin-top:12px">Aucune offre.</p>';
    }
    $n = count($list);
    foreach ($list as $i => $o) {
        $id = (string) ($o['id'] ?? '');
        $promoOn = cs_promo_active($o);
        echo '<div class="offer-line"><div class="row">';
        echo '<div>';
        echo '<div class="t">' . e((string) ($o['title'] ?? '')) . '</div>';
        echo '<div class="meta">' . e((string) ($o['price'] ?? ''));
        if (($o['price_unit'] ?? '') !== '') {
            echo e((string) $o['price_unit']);
        }
        if ($promoOn) {
            echo ' → ' . e((string) ($o['promo']['price'] ?? ''));
        }
        echo ' · sort ' . (int) ($o['sort'] ?? 0) . '</div>';
        echo '</div>';
        echo '<div class="actions">';
        echo empty($o['active'])
            ? '<span class="pill pill--off">Inactive</span>'
            : '<span class="pill pill--on">Active</span>';
        if (array_key_exists('available', $o) && empty($o['available'])) {
            echo '<span class="pill pill--indispo">Indispo</span>';
        }
        if ($promoOn) {
            echo '<span class="pill pill--promo">Promo ' . e((string) ($o['promo']['label'] ?? '')) . '</span>';
        }
        // Monter / descendre
        echo '<form class="inline" method="post" action="' . $base . '/">' . admin_csrf_field()
            . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . e($id) . '">'
            . '<input type="hidden" name="dir" value="up">'
            . '<button class="btn btn--ghost btn--sm" type="submit"' . ($i === 0 ? ' disabled' : '') . '>↑</button></form>';
        echo '<form class="inline" method="post" action="' . $base . '/">' . admin_csrf_field()
            . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . e($id) . '">'
            . '<input type="hidden" name="dir" value="down">'
            . '<button class="btn btn--ghost btn--sm" type="submit"' . ($i === $n - 1 ? ' disabled' : '') . '>↓</button></form>';
        // Toggle actif
        echo '<form class="inline" method="post" action="' . $base . '/">' . admin_csrf_field()
            . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . e($id) . '">'
            . '<button class="btn btn--ghost btn--sm" type="submit">' . (empty($o['active']) ? 'Activer' : 'Désactiver') . '</button></form>';
        // Éditer
        echo '<a class="btn btn--sm" href="' . $base . '/edit.php?id=' . rawurlencode($id) . '">Modifier</a>';
        // Supprimer (confirmation via admin-gallery.js, CSP-safe)
        echo '<form class="inline" method="post" action="' . $base . '/" data-confirm="Supprimer cette offre ? Action définitive.">' . admin_csrf_field()
            . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($id) . '">'
            . '<button class="btn btn--ghost btn--sm" type="submit">Supprimer</button></form>';
        echo '</div></div></div>';
    }
    echo '</div>';
}

echo '<script src="/js/admin-gallery.js" defer></script>';
admin_foot();
