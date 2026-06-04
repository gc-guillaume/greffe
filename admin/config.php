<?php
declare(strict_types=1);

/**
 * Configuration Greffe.
 * Définit les chemins et auto-détecte la base URL pour fonctionner
 * quel que soit l'emplacement de /admin (racine, sous-dossier, sous-domaine).
 */

// Empêche les doubles inclusions (content.php côté front peut aussi le charger).
if (defined('GREFFE_BOOTED')) {
    return;
}
define('GREFFE_BOOTED', true);

// --- Chemins disque ---
define('GREFFE_ADMIN_DIR', __DIR__);
define('GREFFE_DATA_DIR',  __DIR__ . '/data');
define('GREFFE_UPLOAD_DIR', __DIR__ . '/uploads');
define('GREFFE_DB_PATH',   GREFFE_DATA_DIR . '/content.sqlite');

// Crée les dossiers si absents (silencieusement).
foreach ([GREFFE_DATA_DIR, GREFFE_UPLOAD_DIR] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}

// --- Auto-détection de la base URL ---
// Renvoie p.ex. "/admin" ou "/monsite/admin" ou "" si à la racine du host.
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'));
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}
define('GREFFE_BASE_URL', rtrim($scriptDir, '/'));

// URL publique du dossier uploads (relative au host).
// On remonte d'un cran depuis /admin pour pointer vers /admin/uploads quand même.
define('GREFFE_UPLOAD_URL', GREFFE_BASE_URL . '/uploads');

// --- Limites uploads ---
define('GREFFE_UPLOAD_MAX', 10 * 1024 * 1024); // 10 Mo
// MIME images : utilisé par les champs media + gallery.
// SVG délibérément exclu (vecteur XSS stocké : <script> inline exécuté en origine
// du site quand l'image est ouverte). À réintégrer uniquement avec un sanitizer.
define('GREFFE_UPLOAD_IMAGE_MIME', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
]);

// Map MIME → extension du fichier sauvegardé.
// L'extension SAUVÉE est dérivée du MIME validé (finfo), JAMAIS du nom utilisateur,
// pour empêcher l'attaquant d'uploader un polyglotte (ex: GIF89a;<?php …) en .php.
define('GREFFE_UPLOAD_MIME_EXT', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/zip' => 'zip',
    'text/plain' => 'txt',
    'text/csv'   => 'csv',
]);
// MIME fichiers : utilisé par le champ file (PDF + docs courants).
define('GREFFE_UPLOAD_FILE_MIME', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip',
    'text/plain',
    'text/csv',
]);
// Union : utilisée comme défaut (rétrocompat) si on n'a pas précisé.
define('GREFFE_UPLOAD_MIME', array_merge(GREFFE_UPLOAD_IMAGE_MIME, GREFFE_UPLOAD_FILE_MIME));

// Fuseau par défaut.
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

// --- Overrides locaux (gitignored) ---
// Le client peut créer admin/config.local.php pour redéfinir des constantes
// (GREFFE_UPLOAD_MAX, GREFFE_GH_DEFAULT_*, GREFFE_KEEP_INSTALL, etc.) sans risquer
// de perdre ses customisations à chaque update.
// Format attendu : `if (!defined('FOO')) define('FOO', 'bar');`
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// --- Repo GitHub par défaut (pour les mises à jour) ---
// Modifie ici si tu déploies Greffe pour un autre projet.
define('GREFFE_GH_DEFAULT_OWNER',  'gc-guillaume');
define('GREFFE_GH_DEFAULT_REPO',   'greffe');
define('GREFFE_GH_DEFAULT_BRANCH', 'main');
// Repo public ? Si true → pas de token nécessaire (limite 60 requêtes/h non-authentifié,
// largement suffisant pour des checks d'update occasionnels).
// Si tu repasse le repo en privé, mets à false → l'UI redemandera un token.
define('GREFFE_GH_DEFAULT_PUBLIC', true);
