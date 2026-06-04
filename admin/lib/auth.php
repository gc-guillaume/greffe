<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Tente de logger un utilisateur. Régénère l'id de session si OK.
 */
function auth_attempt(string $username, string $password): bool
{
    session_boot();
    $stmt = db()->prepare('SELECT id, username, pass_hash, role FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, (string) $u['pass_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid']  = (int) $u['id'];
    $_SESSION['name'] = $u['username'];
    $_SESSION['role'] = $u['role'];
    return true;
}

function auth_user(): ?array
{
    session_boot();
    if (empty($_SESSION['uid'])) return null;
    return [
        'id'   => (int) $_SESSION['uid'],
        'name' => (string) ($_SESSION['name'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? 'admin'),
    ];
}

function auth_logout(): void
{
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
    }
    session_destroy();
}

/**
 * Garde-fou : redirige vers le login si pas authentifié.
 */
function require_auth(): void
{
    if (!auth_user()) {
        redirect('index.php?p=login');
    }
}

/* ---------- Permissions (admin = tous droits, moderator = restreint) ---------- */

function user_is_admin(): bool
{
    $u = auth_user();
    return $u !== null && (string) $u['role'] === 'admin';
}

function user_is_moderator(): bool
{
    $u = auth_user();
    return $u !== null && (string) $u['role'] === 'moderator';
}

/** Schéma : créer/éditer/supprimer une collection ou un champ → admin only */
function can_edit_schema(): bool { return user_is_admin(); }

/** Supprimer un record : interdit aux modérateurs sur les singletons (options standalone) */
function can_delete_record(array $record): bool
{
    if (!auth_user()) return false;
    if (user_is_admin()) return true;
    // Modérateur : seulement les records des collections-liste, pas les singletons.
    require_once __DIR__ . '/collections.php';
    $col = collection_find_by_slug((string) $record['collection']);
    return $col && (int) $col['is_singleton'] === 0;
}

/** Gérer les utilisateurs : autorisé pour tous les rôles connectés (création + édition de comptes). */
function can_manage_users(): bool
{
    return auth_user() !== null;
}

/** Créer un utilisateur avec un rôle donné : un modérateur ne peut pas créer d'admin. */
function can_create_user_with_role(string $role): bool
{
    if (!auth_user()) return false;
    if (user_is_admin()) return true;
    // Modérateur : ne peut créer que des modérateurs.
    return $role === 'moderator';
}

/** Modifier un autre utilisateur : admin pour tout le monde, modérateur uniquement pour les modérateurs. */
function can_edit_user(array $target): bool
{
    $u = auth_user();
    if (!$u) return false;
    if ((int) $u['id'] === (int) $target['id']) return true; // self
    if (user_is_admin()) return true;
    return (string) $target['role'] === 'moderator';
}

/** Supprimer un utilisateur : admin only, et pas soi-même. */
function can_delete_user(array $target): bool
{
    $u = auth_user();
    if (!$u) return false;
    if (!user_is_admin()) return false;
    if ((int) $u['id'] === (int) $target['id']) return false; // pas soi-même
    return true;
}
