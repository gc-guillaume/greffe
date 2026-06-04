<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Échappe pour HTML.
 */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Slug ASCII pour URL : tirets, pas d'underscores.
 */
function slugify(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'item' : $s;
}

/**
 * Identifiant de clé (champ JSON) : autorise les underscores, idiomatique côté code.
 */
function keyify(string $s): string
{
    $s = strtolower(trim($s));
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = preg_replace('/[^a-z0-9_]+/', '_', $s) ?? '';
    $s = trim($s, '_');
    return $s === '' ? 'field' : $s;
}

/**
 * Renvoie l'URL publique du site (proto + host) telle qu'enregistrée à l'install / au login admin.
 * NE JAMAIS la calculer depuis $_SERVER['HTTP_HOST'] dans des contextes de sécurité (reset link).
 */
function greffe_public_url(): string
{
    if (function_exists('migrations_get')) {
        $u = (string) migrations_get('public_url', '');
        if ($u !== '') return rtrim($u, '/');
    }
    return '';
}

/**
 * Enregistre l'URL publique courante (proto + host) dans _meta.
 * Appelé depuis des contextes de CONFIANCE : install.php (premier admin) et login admin réussi.
 * Ailleurs (notamment forgot password, public), Host est attaquant-contrôlable → on ne stocke jamais.
 */
function greffe_public_url_capture(): void
{
    if (!function_exists('migrations_set')) return;
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return;
    migrations_set('public_url', $proto . '://' . $host);
}

/**
 * Démarre la session admin (cookie durci).
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => GREFFE_BASE_URL === '' ? '/' : GREFFE_BASE_URL,
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_name('greffe_sid');
    session_start();
}

/**
 * Jeton CSRF stable par session.
 */
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    session_boot();
    $sent = $_POST['_csrf'] ?? '';
    $ok = is_string($sent) && hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent);
    if (!$ok) {
        http_response_code(419);
        exit('CSRF invalide.');
    }
}

/**
 * Redirection interne dans l'admin.
 */
function redirect(string $path = ''): void
{
    $target = GREFFE_BASE_URL . '/' . ltrim($path, '/');
    header('Location: ' . $target);
    exit;
}

/**
 * URL d'une vue/action de l'admin.
 */
function url(string $path = ''): string
{
    return GREFFE_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Rend une vue dans le layout.
 */
function view(string $name, array $vars = [], ?string $title = null): void
{
    $vars['_title']  = $title ?? 'Greffe';
    $vars['_view']   = __DIR__ . '/../views/' . $name . '.php';
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../views/layout.php';
}

/**
 * Upload simple. Whitelist MIME + extension + taille max. Renomme.
 * Renvoie le chemin relatif stockable, ex: "uploads/2026/06/abc123.jpg".
 * $allowedMime permet de restreindre selon le contexte (images only, files only, etc.).
 */
function upload_file(array $file, ?array $allowedMime = null): string
{
    $allowed = $allowedMime ?? GREFFE_UPLOAD_MIME;
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload échoué (code ' . ($file['error'] ?? 'N/A') . ').');
    }
    if ($file['size'] > GREFFE_UPLOAD_MAX) {
        throw new RuntimeException('Fichier trop volumineux.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('Type non autorisé : ' . $mime);
    }

    // Extension dérivée du MIME validé — JAMAIS du nom utilisateur.
    // Sinon un polyglotte type GIF89a;<?php …  uploadé en `shell.php` deviendrait exécutable
    // sur les serveurs qui ignorent .htaccess (NGINX, IIS).
    $ext = GREFFE_UPLOAD_MIME_EXT[$mime] ?? null;
    if ($ext === null) {
        throw new RuntimeException('Extension non mappée pour le MIME ' . $mime);
    }

    $sub = date('Y/m');
    $dir = GREFFE_UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('Impossible de créer le dossier upload.');
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Échec de l\'écriture du fichier.');
    }
    // Chemin relatif au dossier admin/, lisible côté front comme côté admin.
    return 'uploads/' . $sub . '/' . $name;
}

/**
 * Icône Lucide inline (subset). Pas de dépendance JS, juste du SVG.
 * Voir https://lucide.dev/icons (licence ISC).
 */
function icon(string $name, int $size = 16): string
{
    static $icons = null;
    if ($icons === null) {
        $icons = [
            'menu'           => '<line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/>',
            'home'           => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'layers'         => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
            'file-text'      => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/>',
            'folder'         => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
            'settings'       => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            'plus'           => '<path d="M5 12h14"/><path d="M12 5v14"/>',
            'log-out'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'trash'          => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
            'pencil'         => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
            'grip'           => '<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
            'chevron-down'   => '<path d="m6 9 6 6 6-6"/>',
            'chevron-right'  => '<path d="m9 18 6-6-6-6"/>',
            'arrow-left'     => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
            'check'          => '<polyline points="20 6 9 17 4 12"/>',
            'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'more'           => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
            'circle-dot'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3" fill="currentColor"/>',
            'image'          => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
            'eye'            => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        ];
    }
    if (!isset($icons[$name])) return '';
    return '<svg class="i i-' . e($name) . '" width="' . (int) $size . '" height="' . (int) $size
         . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $icons[$name] . '</svg>';
}

/**
 * Convertit une PDOException en RuntimeException avec message humain (FR).
 */
function greffe_humanize_pdo(PDOException $e): RuntimeException
{
    $msg = $e->getMessage();
    if (str_contains($msg, 'UNIQUE') || str_contains($msg, '23000')) {
        if (str_contains($msg, 'users.email'))    return new RuntimeException('Cet email est déjà associé à un compte.');
        if (str_contains($msg, 'users.username')) return new RuntimeException('Cet identifiant est déjà pris.');
        if (str_contains($msg, 'collections.slug')) return new RuntimeException('Ce slug est déjà utilisé par une autre collection.');
        if (str_contains($msg, 'fields'))         return new RuntimeException('Cette clé de champ existe déjà pour cette collection.');
        if (str_contains($msg, 'records'))        return new RuntimeException('Un autre record utilise déjà ce slug dans cette collection.');
        return new RuntimeException('Cette valeur existe déjà — elle doit être unique.');
    }
    if (str_contains($msg, 'NOT NULL')) {
        return new RuntimeException('Un champ obligatoire est manquant.');
    }
    if (str_contains($msg, 'FOREIGN KEY')) {
        return new RuntimeException('Référence invalide : l\'élément lié n\'existe pas.');
    }
    // Fallback générique sans laisser fuiter du SQL.
    return new RuntimeException('Une erreur de base de données est survenue.');
}

/**
 * Message éphémère (flash) stocké en session, consommé au prochain rendu.
 * Utilisé pour afficher un toast après redirect (POST → Redirect → GET).
 *
 * $type ∈ 'success' | 'error' | 'info'
 */
function flash_set(string $type, string $message): void
{
    session_boot();
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Consomme le flash (et l'efface). Renvoie null si rien.
 *
 * @return ?array{type:string, message:string}
 */
function flash_consume(): ?array
{
    session_boot();
    if (empty($_SESSION['_flash'])) return null;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return is_array($f) ? $f : null;
}

/**
 * Décode du JSON en tableau, tolérant.
 */
function json_decode_array(?string $s): array
{
    if ($s === null || $s === '') return [];
    $v = json_decode($s, true);
    return is_array($v) ? $v : [];
}

/**
 * Scanne récursivement le dossier des uploads et renvoie la liste des fichiers
 * avec leurs métadonnées (type, taille, mtime, chemin relatif à admin/).
 *
 * @return array<int, array{name:string, path:string, size:int, mtime:int, is_image:bool, ext:string}>
 */
function greffe_scan_uploads(): array
{
    $dir = GREFFE_UPLOAD_DIR;
    if (!is_dir($dir)) return [];
    // Normalise les séparateurs (Windows utilise \, on veut /)
    $uploadDirNorm = rtrim(str_replace('\\', '/', $dir), '/');
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
    $out = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $name = $file->getFilename();
        if ($name[0] === '.') continue; // .htaccess et autres dotfiles
        $pathNorm = str_replace('\\', '/', $file->getPathname());
        // Strip le préfixe upload dir → garde "uploads/AAAA/MM/file.ext"
        if (str_starts_with($pathNorm, $uploadDirNorm . '/')) {
            $rel = 'uploads/' . substr($pathNorm, strlen($uploadDirNorm) + 1);
        } else {
            continue;
        }
        $ext = strtolower($file->getExtension());
        $out[] = [
            'name'     => $name,
            'path'     => $rel,
            'size'     => (int) $file->getSize(),
            'mtime'    => (int) $file->getMTime(),
            'is_image' => in_array($ext, $imageExts, true),
            'ext'      => $ext,
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/**
 * Formate une taille en octets en string lisible (KB, MB).
 */
function greffe_format_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/**
 * Normalise un champ gallery vers un tableau d'objets {path, alt, title}.
 * Accepte aussi l'ancien format (tableau de strings) pour rétrocompat.
 *
 * @param mixed $val
 * @return array<int, array{path:string, alt:string, title:string}>
 */
function greffe_gallery_normalize($val): array
{
    if (!is_array($val)) return [];
    $out = [];
    foreach ($val as $item) {
        if (is_string($item) && $item !== '') {
            $out[] = ['path' => $item, 'alt' => '', 'title' => ''];
        } elseif (is_array($item) && !empty($item['path'])) {
            $out[] = [
                'path'  => (string) $item['path'],
                'alt'   => (string) ($item['alt']   ?? ''),
                'title' => (string) ($item['title'] ?? ''),
            ];
        }
    }
    return $out;
}

/**
 * Parse une définition de sous-champs (group/repeater) au format texte.
 * Une ligne par sous-champ : "cle|Label|type" (avec "select:opt1,opt2" pour select).
 *
 * Types supportés dans un sous-champ : text, longtext, number, boolean, date, select.
 *
 * @return array<int,array{key:string,label:string,type:string,options?:array}>
 */
function subfields_parse(string $raw): array
{
    $allowed = ['text', 'longtext', 'number', 'boolean', 'date', 'select'];
    $out = [];
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('|', $line);
        if (count($parts) < 3) continue;
        $key = keyify(trim($parts[0]));
        if ($key === '') continue;
        $label = trim($parts[1]);
        $typeRaw = trim($parts[2]);
        $opts = [];
        if (str_contains($typeRaw, ':')) {
            [$type, $tail] = explode(':', $typeRaw, 2);
            $type = trim($type);
            if ($type === 'select') {
                $choices = array_values(array_filter(array_map('trim', explode(',', $tail)), fn($v) => $v !== ''));
                $opts['choices'] = $choices;
            }
        } else {
            $type = $typeRaw;
        }
        if (!in_array($type, $allowed, true)) continue;
        $sf = ['key' => $key, 'label' => $label !== '' ? $label : $key, 'type' => $type];
        if ($opts) $sf['options'] = $opts;
        $out[] = $sf;
    }
    return $out;
}

/**
 * Incrémente la valeur numérique située à un chemin "a.b.c" dans le tableau $data.
 * Si la valeur courante n'est pas numérique, repart de 0. Crée les niveaux manquants.
 * Le path est nettoyé (whitelist [a-zA-Z0-9_.]).
 */
function apply_bump(array &$data, string $path): void
{
    $path = preg_replace('/[^a-zA-Z0-9_.]/', '', $path) ?? '';
    if ($path === '') return;
    $segs = explode('.', $path);
    $last = array_pop($segs);
    $ref = &$data;
    foreach ($segs as $s) {
        if (!is_array($ref[$s] ?? null)) $ref[$s] = [];
        $ref = &$ref[$s];
    }
    $cur = $ref[$last] ?? 0;
    $ref[$last] = (is_numeric($cur) ? (int) $cur : 0) + 1;
}

/**
 * Sérialise les sous-champs vers le format texte attendu par subfields_parse().
 */
function subfields_serialize(array $subfields): string
{
    $lines = [];
    foreach ($subfields as $sf) {
        if (!is_array($sf) || empty($sf['key'])) continue;
        $line = $sf['key'] . '|' . ($sf['label'] ?? $sf['key']) . '|' . ($sf['type'] ?? 'text');
        if (($sf['type'] ?? '') === 'select' && !empty($sf['options']['choices'])) {
            $line .= ':' . implode(',', (array) $sf['options']['choices']);
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}
