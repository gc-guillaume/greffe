<?php
declare(strict_types=1);

/**
 * Sert le PDF d'invitation actif sous l'URL publique /invitation.pdf
 * (via la règle de rewrite définie à la racine).
 *
 * Le chemin du fichier actif est stocké dans le singleton 'invitation', champ 'pdf'.
 */

require __DIR__ . '/admin/lib/content.php';

$inv  = options('invitation');
$path = trim((string) ($inv['pdf'] ?? ''));

if ($path === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('PDF non configuré.');
}

// Défense en profondeur : pas de traversée hors du dossier admin.
$path = ltrim(str_replace('\\', '/', $path), '/');
if (str_contains($path, '..')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Chemin invalide.');
}

$abs = __DIR__ . '/admin/' . $path;
if (!is_file($abs)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Fichier introuvable.');
}

$mtime = (int) filemtime($abs);
$etag  = '"' . substr(hash_file('md5', $abs), 0, 12) . '"';

// Cache navigateur côté client (5 min). Le lien public reste stable.
header('Cache-Control: public, max-age=300, must-revalidate');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);

// 304 si rien n'a changé.
$ifNoneMatch     = $_SERVER['HTTP_IF_NONE_MATCH']     ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
if (
    ($ifNoneMatch !== '' && $ifNoneMatch === $etag) ||
    ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)
) {
    http_response_code(304);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="invitation.pdf"');
readfile($abs);
