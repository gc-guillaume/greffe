<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * CRUD méta : collections + fields. Aucune DDL n'est faite ici.
 */

function collections_all(): array
{
    return db()->query('SELECT * FROM collections ORDER BY sort ASC, label ASC')->fetchAll();
}

function collection_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM collections WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function collection_find_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM collections WHERE slug = :s');
    $stmt->execute([':s' => $slug]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function collection_create(string $label, string $slug, bool $singleton): int
{
    $slug = $slug !== '' ? slugify($slug) : slugify($label);
    if ($slug === '') {
        throw new RuntimeException('Slug invalide.');
    }
    if (collection_find_by_slug($slug)) {
        throw new RuntimeException('Ce slug existe déjà.');
    }
    $stmt = db()->prepare(
        'INSERT INTO collections (slug, label, is_singleton, sort) VALUES (:s, :l, :sg, :so)'
    );
    $sort = (int) (db()->query('SELECT COALESCE(MAX(sort),0)+1 AS n FROM collections')->fetch()['n'] ?? 1);
    $stmt->execute([
        ':s'  => $slug,
        ':l'  => $label,
        ':sg' => $singleton ? 1 : 0,
        ':so' => $sort,
    ]);
    return (int) db()->lastInsertId();
}

function collection_update(int $id, string $label, bool $singleton): void
{
    $stmt = db()->prepare('UPDATE collections SET label = :l, is_singleton = :sg WHERE id = :id');
    $stmt->execute([':l' => $label, ':sg' => $singleton ? 1 : 0, ':id' => $id]);
}

function collection_delete(int $id): void
{
    $c = collection_find($id);
    if (!$c) return;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Supprime les fields (cascade gère, mais on est explicites pour les anciennes versions SQLite).
        $stmt = $pdo->prepare('DELETE FROM fields WHERE collection_id = :id');
        $stmt->execute([':id' => $id]);
        // Supprime les records de cette collection.
        $stmt = $pdo->prepare('DELETE FROM records WHERE collection = :s');
        $stmt->execute([':s' => $c['slug']]);
        // Supprime la collection.
        $stmt = $pdo->prepare('DELETE FROM collections WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* -------- Fields -------- */

function fields_for_collection(int $collectionId): array
{
    $stmt = db()->prepare('SELECT * FROM fields WHERE collection_id = :id ORDER BY sort ASC, id ASC');
    $stmt->execute([':id' => $collectionId]);
    return $stmt->fetchAll();
}

function field_create(int $collectionId, string $key, string $label, string $type, array $options): int
{
    $key = keyify($key);
    if ($key === '') {
        throw new RuntimeException('Clé de champ invalide.');
    }
    $allowed = ['text', 'longtext', 'wysiwyg', 'number', 'boolean', 'date', 'color', 'media', 'gallery', 'file', 'select', 'relation', 'group', 'repeater'];
    if (!in_array($type, $allowed, true)) {
        throw new RuntimeException('Type de champ inconnu : ' . $type);
    }
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort),0)+1 AS n FROM fields WHERE collection_id = :id');
    $stmt->execute([':id' => $collectionId]);
    $sort = (int) ($stmt->fetch()['n'] ?? 1);

    $stmt = db()->prepare(
        'INSERT INTO fields (collection_id, key, label, type, options, sort) VALUES (:c, :k, :l, :t, :o, :s)'
    );
    $stmt->execute([
        ':c' => $collectionId,
        ':k' => $key,
        ':l' => $label,
        ':t' => $type,
        ':o' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':s' => $sort,
    ]);
    return (int) db()->lastInsertId();
}

function field_update(int $id, string $label, string $type, array $options): void
{
    $allowed = ['text', 'longtext', 'wysiwyg', 'number', 'boolean', 'date', 'color', 'media', 'gallery', 'file', 'select', 'relation', 'group', 'repeater'];
    if (!in_array($type, $allowed, true)) {
        throw new RuntimeException('Type de champ inconnu : ' . $type);
    }
    $stmt = db()->prepare('UPDATE fields SET label = :l, type = :t, options = :o WHERE id = :id');
    $stmt->execute([
        ':l'  => $label,
        ':t'  => $type,
        ':o'  => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id' => $id,
    ]);
}

function field_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM fields WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function field_set_sort(int $id, int $sort): void
{
    $stmt = db()->prepare('UPDATE fields SET sort = :s WHERE id = :id');
    $stmt->execute([':s' => $sort, ':id' => $id]);
}
