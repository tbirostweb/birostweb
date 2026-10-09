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
            if ($userOk && $passOk) {
                admin_login_reset($ip);
                session_regenerate_id(true);
                $_SESSION['admin_auth'] = true;
                $_SESSION['admin_user'] = admin_user();
                $_SESSION['admin_last'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: ' . admin_base() . '/');
                exit;
            }
            admin_login_record_failure($ip);
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
    echo '<input type="password" id="password" name="password" autocomplete="current-password" required '
        . 'style="width:100%;font-family:var(--fb);font-size:15px;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:10px 12px">';
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

$tabNames = ['crea' => 'Création de site', 'heb' => 'Hébergement', 'maint' => 'Maintenance'];

admin_head('Offres');
echo '<div class="topbar">';
echo '<span class="brand"><b>BW</b> Admin</span>';
echo '<span class="actions"><a class="muted" href="/" target="_blank" rel="noopener">Voir le site ↗</a> '
    . '<form class="inline" method="post" action="' . e(admin_base()) . '/logout.php">' . admin_csrf_field()
    . '<button class="btn btn--ghost btn--sm" type="submit">Déconnexion</button></form></span>';
echo '</div>';

echo '<span class="eyebrow">Back-office</span><h1>Offres &amp; promos</h1>';
echo '<p class="muted">Modifiez prix, descriptions, caractéristiques et promos. Les changements sont visibles immédiatement sur le site public.</p>';

if ($flash === '1') {
    echo '<div class="alert alert--ok" style="margin-top:18px">Offre enregistrée.</div>';
}

$base = e(admin_base());
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
        echo '</div></div></div>';
    }
    echo '</div>';
}

admin_foot();
