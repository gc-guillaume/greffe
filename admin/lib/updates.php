<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/migrations.php';

/**
 * Mise à jour du code depuis GitHub + rollback via backups locaux.
 *
 * Réglages (stockés dans _meta) :
 *  - gh_owner    : "gc-guillaume"
 *  - gh_repo     : "greffe"
 *  - gh_branch   : "main"
 *  - gh_token    : Personal Access Token (scope `repo` pour les repos privés)
 *  - code_sha    : SHA actuellement déployé (mis à jour après chaque apply / rollback)
 */

function gh_settings(): array
{
    // Owner / repo / branch tombent sur les defaults de config.php si jamais surchargés.
    // Le token n'a PAS de default — c'est un secret à entrer par l'admin.
    return [
        'owner'  => (string) (migrations_get('gh_owner',  GREFFE_GH_DEFAULT_OWNER)),
        'repo'   => (string) (migrations_get('gh_repo',   GREFFE_GH_DEFAULT_REPO)),
        'branch' => (string) (migrations_get('gh_branch', GREFFE_GH_DEFAULT_BRANCH)),
        'token'  => (string) (migrations_get('gh_token',  '')),
    ];
}

function gh_token_save(string $token): void
{
    if ($token !== '') migrations_set('gh_token', $token);
}

/**
 * Override avancé : modifier owner / repo / branch (utile si tu déploies Greffe pour un autre projet).
 */
function gh_repo_save(string $owner, string $repo, string $branch): void
{
    if ($owner  !== '') migrations_set('gh_owner',  $owner);
    if ($repo   !== '') migrations_set('gh_repo',   $repo);
    if ($branch !== '') migrations_set('gh_branch', $branch);
}

function current_code_sha(): string
{
    return (string) migrations_get('code_sha', '');
}

/**
 * Renvoie le chemin d'un cacert.pem utilisable, en l'auto-téléchargeant
 * si nécessaire (cas Windows local où php.ini pointe vers un fichier absent).
 * Stocké dans admin/data/ (gitignored).
 */
function greffe_cacert_path(): string
{
    $path = GREFFE_DATA_DIR . '/cacert.pem';
    if (is_file($path) && filesize($path) > 100000) return $path;

    // Bootstrap : download depuis curl.se (source upstream officielle Mozilla).
    // verify_peer désactivé UNIQUEMENT pour ce bootstrap one-shot.
    $ctx = stream_context_create([
        'http' => [
            'timeout'    => 30,
            'user_agent' => 'Greffe',
        ],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents('https://curl.se/ca/cacert.pem', false, $ctx);
    if (is_string($body) && strlen($body) > 100000 && str_contains($body, '-----BEGIN CERTIFICATE-----')) {
        @file_put_contents($path, $body);
        return $path;
    }
    return ''; // fallback : on laissera curl utiliser le default système
}

/**
 * Appel HTTP simple vers l'API GitHub.
 */
function gh_api(string $path, bool $rawBody = false): mixed
{
    $s = gh_settings();
    if ($s['owner'] === '' || $s['repo'] === '') return null;
    $url = 'https://api.github.com/repos/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']) . $path;

    $headers = [
        'User-Agent: Greffe',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if ($s['token'] !== '') $headers[] = 'Authorization: Bearer ' . $s['token'];

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    $cacert = greffe_cacert_path();
    if ($cacert !== '') $opts[CURLOPT_CAINFO] = $cacert;
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        throw new RuntimeException(
            'GitHub API a renvoyé une erreur (' . ($code ?: 'no code') . ')'
            . ($err ? ' : ' . $err : '')
        );
    }
    return $rawBody ? $body : json_decode((string) $body, true);
}

/**
 * Renvoie le dernier commit sur la branche configurée (sha + date + message).
 */
function gh_latest_commit(): ?array
{
    $s = gh_settings();
    if ($s['owner'] === '' || $s['repo'] === '') return null;
    $r = gh_api('/commits/' . rawurlencode($s['branch']));
    if (!is_array($r) || empty($r['sha'])) return null;
    return [
        'sha'     => (string) $r['sha'],
        'short'   => substr((string) $r['sha'], 0, 7),
        'message' => (string) ($r['commit']['message'] ?? ''),
        'date'    => (string) ($r['commit']['author']['date'] ?? ''),
        'author'  => (string) ($r['commit']['author']['name'] ?? ''),
    ];
}

/**
 * Télécharge le tarball d'un SHA depuis GitHub et renvoie le chemin local du .tar.gz.
 */
function gh_download_tarball(string $sha): string
{
    $body = gh_api('/tarball/' . rawurlencode($sha), true);
    if (!is_string($body) || strlen($body) < 100) {
        throw new RuntimeException('Le tarball GitHub semble vide.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'greffe_dl_');
    if ($tmp === false) throw new RuntimeException('Impossible de créer un fichier temporaire.');
    @unlink($tmp);
    $tmp .= '.tar.gz';
    if (file_put_contents($tmp, $body) === false) {
        throw new RuntimeException('Écriture du tarball impossible.');
    }
    return $tmp;
}

/* ---------- Backups ---------- */

function backups_dir(): string
{
    $dir = GREFFE_DATA_DIR . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function backups_list(): array
{
    $dir = backups_dir();
    $files = glob($dir . '/*.zip') ?: [];
    $out = [];
    foreach ($files as $f) {
        $out[] = [
            'name'  => basename($f),
            'size'  => (int) filesize($f),
            'mtime' => (int) filemtime($f),
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/**
 * Zippe l'état actuel du projet (sauf data/, uploads/, .git/, backups eux-mêmes).
 * Renvoie le nom du fichier de backup.
 */
function backup_create(string $label): string
{
    $dir = backups_dir();
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $label) ?: 'backup';
    $name = date('Ymd-His') . '_' . substr($safe, 0, 16) . '.zip';
    $path = $dir . '/' . $name;

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension PHP zip indisponible — impossible de backuper.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Impossible de créer le fichier zip de backup.');
    }

    $root = realpath(GREFFE_ADMIN_DIR . '/..');
    if ($root === false) throw new RuntimeException('Racine projet introuvable.');
    $rootLen = strlen($root) + 1;

    $iter = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($file): bool {
                $p = str_replace('\\', '/', $file->getPathname());
                if (str_contains($p, '/admin/data')) return false;
                if (str_contains($p, '/admin/uploads')) return false;
                if (str_contains($p, '/.git/')) return false;
                if (str_contains($p, '/.git')) return false;
                return true;
            }
        )
    );

    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), $rootLen));
        $zip->addFile($file->getPathname(), $rel);
    }
    $zip->close();
    return $name;
}

/**
 * Restaure un backup zip par-dessus le code actuel (exclut data/uploads).
 */
function backup_restore(string $name): void
{
    if (!preg_match('/^[a-zA-Z0-9._\-]+\.zip$/', $name)) {
        throw new RuntimeException('Nom de backup invalide.');
    }
    $path = backups_dir() . '/' . $name;
    if (!is_file($path)) throw new RuntimeException('Backup introuvable.');

    backup_create('pre-rollback'); // safe-guard

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension PHP zip indisponible.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Backup zip illisible.');

    $root = realpath(GREFFE_ADMIN_DIR . '/..');
    if ($root === false) throw new RuntimeException('Racine projet introuvable.');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string) $zip->getNameIndex($i);
        if (str_starts_with($entry, 'admin/data/'))    continue;
        if (str_starts_with($entry, 'admin/uploads/')) continue;
        if (str_starts_with($entry, '.git/'))          continue;

        $dest = $root . '/' . $entry;
        $dir  = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $stream = $zip->getStream($entry);
        if ($stream !== false) {
            file_put_contents($dest, stream_get_contents($stream));
            fclose($stream);
        }
    }
    $zip->close();
}

/* ---------- Apply update ---------- */

/**
 * Télécharge un SHA, fait le backup courant, extrait par-dessus, joue les migrations.
 *
 * @return array{backup:string, new_sha:string}
 */
function apply_update(string $sha): array
{
    if (!preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
        throw new RuntimeException('SHA invalide.');
    }

    $backup = backup_create(substr($sha, 0, 7));

    $tarFile = gh_download_tarball($sha);
    try {
        $extractDir = sys_get_temp_dir() . '/greffe_extract_' . bin2hex(random_bytes(4));
        @mkdir($extractDir, 0775, true);

        $phar = new PharData($tarFile);
        $phar->extractTo($extractDir, null, true);

        // GitHub tarball : un seul dossier racine "owner-repo-shashort/"
        $inner = null;
        foreach (scandir($extractDir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            if (is_dir($extractDir . '/' . $e)) { $inner = $extractDir . '/' . $e; break; }
        }
        if (!$inner) throw new RuntimeException('Archive GitHub vide.');

        $root = realpath(GREFFE_ADMIN_DIR . '/..');
        if ($root === false) throw new RuntimeException('Racine projet introuvable.');
        copy_recursive($inner, $root, ['admin/data', 'admin/uploads', '.git']);

        delete_recursive($extractDir);
    } finally {
        @unlink($tarFile);
    }

    migrations_set('code_sha', $sha);
    migrations_run();

    return ['backup' => $backup, 'new_sha' => $sha];
}

/* ---------- Helpers fs ---------- */

function copy_recursive(string $src, string $dst, array $excludeRels = []): void
{
    $src = rtrim(str_replace('\\', '/', realpath($src) ?: $src), '/');
    $dst = rtrim(str_replace('\\', '/', $dst), '/');
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $srcLen = strlen($src) + 1;
    foreach ($iter as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), $srcLen));
        $skip = false;
        foreach ($excludeRels as $exc) {
            if ($rel === $exc || str_starts_with($rel, $exc . '/')) { $skip = true; break; }
        }
        if ($skip) continue;

        $target = $dst . '/' . $rel;
        if ($file->isDir()) {
            if (!is_dir($target)) @mkdir($target, 0775, true);
        } else {
            $tDir = dirname($target);
            if (!is_dir($tDir)) @mkdir($tDir, 0775, true);
            @copy($file->getPathname(), $target);
        }
    }
}

function delete_recursive(string $dir): void
{
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $file) {
        if ($file->isDir()) @rmdir($file->getPathname());
        else @unlink($file->getPathname());
    }
    @rmdir($dir);
}
