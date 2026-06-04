<?php
declare(strict_types=1);

/**
 * Router pour le serveur PHP intégré (php -S).
 * Reproduit les règles du .htaccess racine en environnement de dev.
 *
 *   php -S 127.0.0.1:7500 _router.php
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = __DIR__ . $uri;

// /invitation.pdf -> invitation.php
if (rtrim($uri, '/') === '/invitation.pdf') {
    require __DIR__ . '/invitation.php';
    return true;
}

// Fichier réel -> que le serveur le serve.
if ($uri !== '/' && is_file($path)) {
    return false;
}

// Sinon, retombe sur index.php à la racine.
require __DIR__ . '/index.php';
return true;
