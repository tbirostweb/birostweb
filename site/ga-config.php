<?php
// Expose l'identifiant de mesure GA4 (public, visible dans le code de toute page qui charge gtag.js)
// lu depuis l'environnement : une seule source de vérité (GA4_MEASUREMENT_ID dans Dokploy).
// Absent ou invalide => {"id":null} : ni bandeau de consentement ni chargement de Google Analytics.
$raw = $_ENV['GA4_MEASUREMENT_ID'] ?? $_SERVER['GA4_MEASUREMENT_ID'] ?? getenv('GA4_MEASUREMENT_ID') ?: '';
$id = is_string($raw) && preg_match('/^G-[A-Z0-9]{6,12}$/', trim($raw)) ? trim($raw) : null;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['id' => $id]);
