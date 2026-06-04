<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Crée les tables si elles n'existent pas. Idempotent.
 * Aucune DDL n'est jouée pour les collections client : tout passe par records(data JSON).
 */
function schema_install(): void
{
    $pdo = db();

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS collections (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            slug        TEXT NOT NULL UNIQUE,
            label       TEXT NOT NULL,
            is_singleton INTEGER NOT NULL DEFAULT 0,
            sort        INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS fields (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            collection_id INTEGER NOT NULL,
            key           TEXT NOT NULL,
            label         TEXT NOT NULL,
            type          TEXT NOT NULL,
            options       TEXT,
            sort          INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
            UNIQUE (collection_id, key)
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS records (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            collection  TEXT NOT NULL,
            slug        TEXT,
            data        TEXT NOT NULL DEFAULT '{}',
            status      TEXT NOT NULL DEFAULT 'draft',
            sort        INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            email         TEXT NOT NULL UNIQUE,
            pass_hash     TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'admin',
            reset_token   TEXT,
            reset_expires TEXT,
            created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS record_versions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id   INTEGER NOT NULL,
            collection  TEXT NOT NULL,
            slug        TEXT,
            data        TEXT NOT NULL DEFAULT '{}',
            status      TEXT NOT NULL DEFAULT 'draft',
            sort        INTEGER NOT NULL DEFAULT 0,
            saved_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    SQL);

    // Index utiles côté lecture front.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_records_col_status ON records(collection, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_records_col_slug   ON records(collection, slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_records_col_sort   ON records(collection, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_versions_record    ON record_versions(record_id, id DESC)');
}

/**
 * Indique si l'installation est encore à faire (aucun utilisateur).
 */
function schema_needs_install(): bool
{
    schema_install();
    $row = db()->query('SELECT COUNT(*) AS n FROM users')->fetch();
    return ((int) ($row['n'] ?? 0)) === 0;
}
