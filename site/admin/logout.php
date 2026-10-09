<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Déconnexion via POST + CSRF (évite une déconnexion forcée par CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_csrf_check()) {
    admin_destroy_session();
}
header('Location: ' . admin_base() . '/');
exit;
