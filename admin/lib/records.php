<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/collections.php';

/**
 * CRUD records (lecture/écriture côté admin).
 * Le payload est stocké dans la colonne `data` au format JSON.
 */

function records_for(string $collectionSlug): array
{
    $stmt = db()->prepare('SELECT * FROM records WHERE collection = :c ORDER BY sort ASC, id ASC');
    $stmt->execute([':c' => $collectionSlug]);
    return $stmt->fetchAll();
}

function record_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM records WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function record_singleton(string $collectionSlug): ?array
{
    $stmt = db()->prepare('SELECT * FROM records WHERE collection = :c ORDER BY id ASC LIMIT 1');
    $stmt->execute([':c' => $collectionSlug]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Construit la valeur d'un champ à partir des données POST/FILES selon son type.
 */
function record_value_from_input(array $field, array $post, array $files, array $current = []): mixed
{
    $key  = $field['key'];
    $type = $field['type'];
    $opts = is_array($field['options'] ?? null) ? $field['options'] : json_decode_array($field['options'] ?? null);

    switch ($type) {
        case 'wysiwyg':
            // Sanitization stricte côté serveur — empêche un modérateur de planter
            // un XSS qui se déclenche quand un admin ouvre le record.
            return isset($post[$key]) ? greffe_sanitize_html((string) $post[$key]) : '';
        case 'text':
        case 'longtext':
        case 'date':
            return isset($post[$key]) ? (string) $post[$key] : '';
        case 'number':
            $v = $post[$key] ?? '';
            if ($v === '' || $v === null) return null;
            return is_numeric($v) ? (str_contains((string) $v, '.') ? (float) $v : (int) $v) : null;
        case 'boolean':
            return !empty($post[$key]);
        case 'select':
            return isset($post[$key]) ? (string) $post[$key] : '';
        case 'media':
            // Images uniquement.
            if (!empty($post[$key . '__clear'])) return '';
            if (isset($files[$key]) && is_array($files[$key]) && ($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                return upload_file($files[$key], GREFFE_UPLOAD_IMAGE_MIME);
            }
            return (string) ($current[$key] ?? '');

        case 'file':
            // PDF / docs / texte / archives.
            if (!empty($post[$key . '__clear'])) return '';
            if (isset($files[$key]) && is_array($files[$key]) && ($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                return upload_file($files[$key], GREFFE_UPLOAD_FILE_MIME);
            }
            return (string) ($current[$key] ?? '');

        case 'gallery':
            // Tableau d'objets {path, alt, title}. Gère ordre + alt/title + suppression + nouveaux uploads.
            $order    = (array) ($post[$key . '__order']     ?? []);
            $alts     = (array) ($post[$key . '__alt']       ?? []);
            $titles   = (array) ($post[$key . '__title']     ?? []);
            $removed  = (array) ($post[$key . '__remove']    ?? []);
            $newAlts  = (array) ($post[$key . '__new_alt']   ?? []);
            $newTitles = (array) ($post[$key . '__new_title'] ?? []);

            $kept = [];
            foreach ($order as $i => $path) {
                $path = (string) $path;
                if ($path === '' || in_array($path, $removed, true)) continue;
                $kept[] = [
                    'path'  => $path,
                    'alt'   => (string) ($alts[$i]   ?? ''),
                    'title' => (string) ($titles[$i] ?? ''),
                ];
            }
            // Nouveaux uploads : $_FILES['gallery']['name'] est un tableau parallèle.
            // L'index dans le tableau de fichiers correspond à l'index dans __new_alt/__new_title.
            if (isset($files[$key]) && is_array($files[$key]['name'] ?? null)) {
                $count = count($files[$key]['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (($files[$key]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    $sub = [
                        'name'     => $files[$key]['name'][$i],
                        'tmp_name' => $files[$key]['tmp_name'][$i],
                        'error'    => $files[$key]['error'][$i],
                        'size'     => $files[$key]['size'][$i],
                    ];
                    try {
                        $kept[] = [
                            'path'  => upload_file($sub, GREFFE_UPLOAD_IMAGE_MIME),
                            'alt'   => (string) ($newAlts[$i]   ?? ''),
                            'title' => (string) ($newTitles[$i] ?? ''),
                        ];
                    } catch (Throwable $e) { /* fichier invalide, on saute */ }
                }
            }
            return $kept;

        case 'color':
            $v = isset($post[$key]) ? trim((string) $post[$key]) : '';
            // Whitelist d'un hex strict (#rgb ou #rrggbb).
            if ($v !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) return '';
            return $v;
        case 'relation':
            $multi = !empty($opts['multiple']);
            $raw   = $post[$key] ?? ($multi ? [] : '');
            if ($multi) {
                $ids = is_array($raw) ? $raw : [];
                $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
                return $ids;
            }
            $id = (int) (is_array($raw) ? ($raw[0] ?? 0) : $raw);
            return $id > 0 ? $id : null;
        case 'group':
            $sub = is_array($opts['subfields'] ?? null) ? $opts['subfields'] : [];
            $raw = $post[$key] ?? [];
            if (!is_array($raw)) $raw = [];
            $out = [];
            foreach ($sub as $sf) {
                $out[$sf['key']] = subfield_value_from_input($sf, $raw);
            }
            return $out;
        case 'repeater':
            $sub = is_array($opts['subfields'] ?? null) ? $opts['subfields'] : [];
            $raw = $post[$key] ?? [];
            if (!is_array($raw)) return [];
            ksort($raw, SORT_NUMERIC);
            $out = [];
            foreach ($raw as $row) {
                if (!is_array($row)) continue;
                // Marqueur de présence : posé par un input hidden par ligne.
                // Évite la perte des lignes dont tous les champs sont vides ou des booleans décochés.
                if (empty($row['__present'])) continue;
                $rowOut = [];
                foreach ($sub as $sf) {
                    $rowOut[$sf['key']] = subfield_value_from_input($sf, $row);
                }
                $out[] = $rowOut;
            }
            return $out;
        default:
            return isset($post[$key]) ? (string) $post[$key] : '';
    }
}

/**
 * Lecture d'une valeur de sous-champ (à l'intérieur d'un group/repeater).
 * Types autorisés : text, longtext, number, boolean, date, select.
 */
function subfield_value_from_input(array $sf, array $rowPost): mixed
{
    $key  = $sf['key'];
    $type = $sf['type'];
    switch ($type) {
        case 'text':
        case 'longtext':
        case 'date':
            return isset($rowPost[$key]) ? (string) $rowPost[$key] : '';
        case 'number':
            $v = $rowPost[$key] ?? '';
            if ($v === '' || $v === null) return null;
            return is_numeric($v) ? (str_contains((string) $v, '.') ? (float) $v : (int) $v) : null;
        case 'boolean':
            return !empty($rowPost[$key]);
        case 'select':
            return isset($rowPost[$key]) ? (string) $rowPost[$key] : '';
        default:
            return isset($rowPost[$key]) ? (string) $rowPost[$key] : '';
    }
}

/**
 * Construit le data JSON à partir des champs définis.
 */
function record_build_data(array $fields, array $post, array $files, array $current = []): array
{
    $data = [];
    foreach ($fields as $f) {
        $f['options'] = is_string($f['options'] ?? null) ? json_decode_array($f['options']) : ($f['options'] ?? []);
        $data[$f['key']] = record_value_from_input($f, $post, $files, $current);
    }
    return $data;
}

function record_create(string $collectionSlug, array $data, string $slug, string $status, int $sort): int
{
    $slug = $slug !== '' ? slugify($slug) : null;
    if ($slug !== null) {
        // Évite les collisions à l'intérieur d'une collection.
        $slug = record_unique_slug($collectionSlug, $slug, null);
    }
    $stmt = db()->prepare(<<<SQL
        INSERT INTO records (collection, slug, data, status, sort, created_at, updated_at)
        VALUES (:c, :s, :d, :st, :so, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    SQL);
    $stmt->execute([
        ':c'  => $collectionSlug,
        ':s'  => $slug,
        ':d'  => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':st' => $status,
        ':so' => $sort,
    ]);
    return (int) db()->lastInsertId();
}

function record_update(int $id, array $data, string $slug, string $status, int $sort): void
{
    $cur = record_find($id);
    if (!$cur) return;

    // Snapshot l'état courant dans l'historique avant écrasement.
    record_snapshot($id, $cur);

    $slug = $slug !== '' ? slugify($slug) : null;
    if ($slug !== null) {
        $slug = record_unique_slug((string) $cur['collection'], $slug, $id);
    }
    $stmt = db()->prepare(<<<SQL
        UPDATE records
        SET slug = :s, data = :d, status = :st, sort = :so, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    SQL);
    $stmt->execute([
        ':s'  => $slug,
        ':d'  => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':st' => $status,
        ':so' => $sort,
        ':id' => $id,
    ]);
}

function record_delete(int $id): void
{
    // Cascade : supprime aussi l'historique.
    $stmt = db()->prepare('DELETE FROM record_versions WHERE record_id = :id');
    $stmt->execute([':id' => $id]);
    $stmt = db()->prepare('DELETE FROM records WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

/* ---------- Historique (5 versions max par record) ---------- */

/**
 * Snapshot l'état d'un record dans l'historique, puis purge au-delà de 5.
 */
function record_snapshot(int $recordId, array $cur): void
{
    $pdo = db();
    $stmt = $pdo->prepare(<<<SQL
        INSERT INTO record_versions (record_id, collection, slug, data, status, sort, saved_at)
        VALUES (:rid, :c, :s, :d, :st, :so, CURRENT_TIMESTAMP)
    SQL);
    $stmt->execute([
        ':rid' => $recordId,
        ':c'   => $cur['collection'],
        ':s'   => $cur['slug'],
        ':d'   => $cur['data'],
        ':st'  => $cur['status'],
        ':so'  => (int) $cur['sort'],
    ]);
    record_versions_purge($recordId, 10);
}

/**
 * Ne garde que les N versions les plus récentes pour un record.
 */
function record_versions_purge(int $recordId, int $keep): void
{
    $stmt = db()->prepare(<<<SQL
        DELETE FROM record_versions
        WHERE record_id = :rid
          AND id NOT IN (
              SELECT id FROM record_versions
              WHERE record_id = :rid
              ORDER BY id DESC LIMIT :n
          )
    SQL);
    $stmt->bindValue(':rid', $recordId, PDO::PARAM_INT);
    $stmt->bindValue(':n',   max(0, $keep), PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Renvoie les versions d'un record, des plus récentes aux plus anciennes.
 */
function record_versions_list(int $recordId): array
{
    $stmt = db()->prepare('SELECT * FROM record_versions WHERE record_id = :id ORDER BY id DESC');
    $stmt->execute([':id' => $recordId]);
    return $stmt->fetchAll();
}

/**
 * Restaure une version : snapshot de l'état courant, puis écrasement par la version.
 * Renvoie l'id du record affecté.
 */
function record_restore(int $versionId): ?int
{
    $stmt = db()->prepare('SELECT * FROM record_versions WHERE id = :id');
    $stmt->execute([':id' => $versionId]);
    $v = $stmt->fetch();
    if (!$v) return null;

    $recordId = (int) $v['record_id'];
    $cur = record_find($recordId);
    if (!$cur) return null;

    // L'état courant devient à son tour une version, pour rester "annulable".
    record_snapshot($recordId, $cur);

    $stmt = db()->prepare(<<<SQL
        UPDATE records
        SET slug = :s, data = :d, status = :st, sort = :so, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    SQL);
    $stmt->execute([
        ':s'  => $v['slug'],
        ':d'  => $v['data'],
        ':st' => $v['status'],
        ':so' => (int) $v['sort'],
        ':id' => $recordId,
    ]);

    record_versions_purge($recordId, 10);
    return $recordId;
}

/**
 * Garantit l'unicité d'un slug dans une collection en suffixant -2, -3...
 */
function record_unique_slug(string $collection, string $slug, ?int $ignoreId): string
{
    $base = $slug;
    $i = 1;
    while (true) {
        $stmt = db()->prepare('SELECT id FROM records WHERE collection = :c AND slug = :s' .
            ($ignoreId !== null ? ' AND id != :id' : ''));
        $params = [':c' => $collection, ':s' => $slug];
        if ($ignoreId !== null) $params[':id'] = $ignoreId;
        $stmt->execute($params);
        if (!$stmt->fetch()) return $slug;
        $i++;
        $slug = $base . '-' . $i;
    }
}
