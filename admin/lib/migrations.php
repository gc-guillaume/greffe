<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Système de migrations versionnées.
 *
 * Règles :
 *  - Une migration = une fonction qui prend $pdo et fait les altérations nécessaires.
 *  - On ne MODIFIE jamais une migration déjà publiée → on ajoute une nouvelle à la fin.
 *  - Le numéro de la dernière migration appliquée est stocké dans `_meta`.
 *  - Toutes les migrations en attente sont jouées dans une transaction.
 *
 * Pour ajouter une migration : édite la fonction migrations_list() en bas et ajoute un cran.
 */

function migrations_meta_install(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS _meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
}

function migrations_get(string $key, ?string $default = null): ?string
{
    migrations_meta_install();
    $stmt = db()->prepare('SELECT value FROM _meta WHERE key = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    return $row ? (string) $row['value'] : $default;
}

function migrations_set(string $key, string $value): void
{
    migrations_meta_install();
    $stmt = db()->prepare(
        "INSERT INTO _meta (key, value) VALUES (:k, :v)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value"
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
}

function migrations_current_version(): int
{
    return (int) migrations_get('schema_version', '0');
}

/**
 * Joue toutes les migrations en attente. Idempotent. Safe à appeler à chaque hit
 * (fast-path : un SELECT si tout est à jour).
 */
function migrations_run(): void
{
    $migs    = migrations_list();
    if (!$migs) { migrations_meta_install(); return; }

    $max     = (int) max(array_keys($migs));
    $current = migrations_current_version();
    if ($current >= $max) return; // tout est à jour, on sort vite

    $pdo = db();
    ksort($migs, SORT_NUMERIC);
    foreach ($migs as $version => $fn) {
        if ((int) $version <= $current) continue;
        $pdo->beginTransaction();
        try {
            $fn($pdo);
            migrations_set('schema_version', (string) $version);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            // On log pour qu'un admin puisse diagnostiquer.
            @file_put_contents(
                GREFFE_DATA_DIR . '/migrations.log',
                sprintf("[%s] migration %d failed: %s\n", date('c'), $version, $e->getMessage()),
                FILE_APPEND
            );
            throw $e;
        }
    }
}

/**
 * Liste des migrations. NE PAS modifier celles déjà publiées : ajouter à la fin.
 * Chaque clé = numéro de version. Chaque valeur = closure(PDO).
 *
 * @return array<int, callable(PDO):void>
 */
function migrations_list(): array
{
    return [

        // 1 — Cleanup du cacert.pem legacy.
        // Avant le commit d51e7d4, greffe_cacert_path() faisait un bootstrap-download
        // vers admin/data/cacert.pem (vulnérable au MITM). Désormais le fichier est
        // vendoré dans admin/assets/vendor/cacert.pem et l'ancien est orphelin.
        1 => function (PDO $pdo): void {
            $legacy = GREFFE_DATA_DIR . '/cacert.pem';
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        },

        // 2 — Ajoute collections.kind ('options' | 'pages' | 'list') et le rétro-mappe
        // depuis is_singleton (1 -> options, 0 -> list). is_singleton est conservé en
        // dérivé (kind='options' <=> is_singleton=1) pour la rétro-compat des seeds existants.
        2 => function (PDO $pdo): void {
            $cols = $pdo->query("PRAGMA table_info(collections)")->fetchAll(PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) {
                if (($c['name'] ?? '') === 'kind') { $has = true; break; }
            }
            if (!$has) {
                $pdo->exec("ALTER TABLE collections ADD COLUMN kind TEXT NOT NULL DEFAULT 'list'");
                $pdo->exec("UPDATE collections SET kind = 'options' WHERE is_singleton = 1");
            }
        },

    ];
}
