<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/* ---------- Brute-force protection sur le login ---------- */
// Lockout après 5 échecs en 15 min, sur la même paire IP+username.
const GREFFE_LOGIN_MAX_FAILS    = 5;
const GREFFE_LOGIN_WINDOW       = 900;  // 15 min
const GREFFE_LOGIN_LOCKOUT_TIME = 900;  // 15 min

function login_throttle_key(string $username, string $ip): string
{
    return 'login_fail_' . sha1(strtolower($username) . '|' . $ip);
}

/**
 * Renvoie un message d'erreur (string) si la paire IP+username est temporairement bloquée,
 * null sinon.
 */
function login_throttle_check(string $username, string $ip): ?string
{
    require_once __DIR__ . '/migrations.php';
    $raw = migrations_get(login_throttle_key($username, $ip), '');
    if ($raw === '' || $raw === null) return null;
    $d = json_decode((string) $raw, true);
    if (!is_array($d)) return null;
    $until = (int) ($d['until'] ?? 0);
    if ($until > time()) {
        $remain = $until - time();
        return 'Trop de tentatives. Réessaie dans ' . max(1, (int) ceil($remain / 60)) . ' min.';
    }
    return null;
}

function login_throttle_record_fail(string $username, string $ip): void
{
    require_once __DIR__ . '/migrations.php';
    $key  = login_throttle_key($username, $ip);
    $raw  = migrations_get($key, '');
    $d    = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $d    = is_array($d) ? $d : ['count' => 0, 'last' => 0, 'until' => 0];
    $now  = time();
    // Reset le compteur si la dernière tentative date d'avant la fenêtre.
    if (((int) ($d['last'] ?? 0)) < $now - GREFFE_LOGIN_WINDOW) $d['count'] = 0;
    $d['count']++;
    $d['last'] = $now;
    if ($d['count'] >= GREFFE_LOGIN_MAX_FAILS) {
        $d['until'] = $now + GREFFE_LOGIN_LOCKOUT_TIME;
    }
    migrations_set($key, json_encode($d));
}

function login_throttle_reset(string $username, string $ip): void
{
    require_once __DIR__ . '/migrations.php';
    // On supprime la clé proprement de _meta.
    db()->prepare('DELETE FROM _meta WHERE key = :k')
        ->execute([':k' => login_throttle_key($username, $ip)]);
}

/**
 * Tente de logger un utilisateur. Régénère l'id de session si OK.
 * Constant-time : password_verify est TOUJOURS appelé (contre un hash dummy
 * si l'utilisateur n'existe pas) pour éviter un oracle de timing qui révélerait
 * les usernames existants.
 */
function auth_attempt(string $username, string $password): bool
{
    session_boot();
    static $dummyHash = null;
    if ($dummyHash === null) {
        // Hash bcrypt valide — généré une fois par process, même cost que PASSWORD_DEFAULT.
        $dummyHash = password_hash('greffe_dummy_constant_time_placeholder', PASSWORD_DEFAULT);
    }

    $stmt = db()->prepare('SELECT id, username, pass_hash, role FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $u = $stmt->fetch();

    // Toujours appeler password_verify, même si user inexistant, pour égaliser le temps.
    $hashToCheck = $u ? (string) $u['pass_hash'] : $dummyHash;
    $passwordOk  = password_verify($password, $hashToCheck);

    if (!$u || !$passwordOk) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid']  = (int) $u['id'];
    $_SESSION['name'] = $u['username'];
    $_SESSION['role'] = $u['role'];
    // Capture l'URL publique seulement sur login admin réussi (contexte de confiance).
    // Évite l'empoisonnement via HTTP_HOST par un visiteur anonyme sur /forgot.
    if ((string) $u['role'] === 'admin') {
        greffe_public_url_capture();
    }
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
 * Capture aussi public_url à chaque requête admin authentifiée (rétro-compat
 * pour les installs antérieurs à la feature public_url).
 */
function require_auth(): void
{
    $u = auth_user();
    if (!$u) {
        redirect('index.php?p=login');
    }
    if ((string) $u['role'] === 'admin' && function_exists('greffe_public_url_capture')) {
        greffe_public_url_capture();
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

/** Modifier un autre utilisateur : self OU admin uniquement.
 *  Un modérateur NE peut PAS éditer un autre modérateur — sinon un mod compromis
 *  prend tous les autres mods (et de là, via XSS sur un wysiwyg, l'admin). */
function can_edit_user(array $target): bool
{
    $u = auth_user();
    if (!$u) return false;
    if ((int) $u['id'] === (int) $target['id']) return true; // self
    return user_is_admin();
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
