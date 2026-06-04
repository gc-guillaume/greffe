<?php
declare(strict_types=1);

/**
 * Front controller de l'admin Greffe.
 * Dispatch sur ?p=... avec une gate d'authentification globale.
 */

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/collections.php';
require_once __DIR__ . '/lib/records.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/updates.php';

schema_install();

// Si aucun utilisateur, on force le passage par install.php.
if (schema_needs_install()) {
    redirect('install.php');
}

session_boot();

$page   = (string) ($_GET['p'] ?? 'dashboard');
$method = $_SERVER['REQUEST_METHOD'];

// --- Routes publiques (auth) ---
if ($page === 'login') {
    $error = '';
    if ($method === 'POST') {
        csrf_check();
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        if (auth_attempt($u, $p)) {
            redirect('index.php');
        }
        $error = 'Identifiants invalides.';
    }
    view('login', ['error' => $error], 'Connexion');
    exit;
}

if ($page === 'logout') {
    auth_logout();
    redirect('index.php?p=login');
}

// Mot de passe oublié (route publique).
if ($page === 'forgot') {
    $sent = false;
    $error = '';
    if ($method === 'POST') {
        csrf_check();
        $email = trim((string) ($_POST['email'] ?? ''));
        $u = $email !== '' ? user_by_email($email) : null;
        if ($u) {
            $token = reset_token_create((int) $u['id']);
            // URL absolue : on prend l'URL relative + hôte courant.
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $link  = $proto . '://' . $host . GREFFE_BASE_URL . '/index.php?p=reset&token=' . $token;
            $body  = "Bonjour " . $u['username'] . ",\n\n"
                   . "Pour réinitialiser ton mot de passe sur " . $host . ", clique sur le lien :\n\n"
                   . $link . "\n\n"
                   . "Le lien expire dans 1 heure. Si tu n'es pas à l'origine de la demande, ignore ce mail.\n";
            greffe_mail($email, 'Réinitialisation de mot de passe', $body);
        }
        // Toujours afficher "envoyé" pour éviter l'énumération d'emails.
        $sent = true;
    }
    view('forgot', ['sent' => $sent, 'error' => $error], 'Mot de passe oublié');
    exit;
}

// Réinitialisation via token (route publique).
if ($page === 'reset') {
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    $u     = reset_token_verify($token);
    $error = '';
    $done  = false;
    if (!$u) {
        $error = 'Lien invalide ou expiré. Demande un nouveau lien.';
    } elseif ($method === 'POST') {
        csrf_check();
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['confirm']  ?? '');
        try {
            if ($p1 !== $p2) throw new RuntimeException('Les mots de passe ne correspondent pas.');
            user_update((int) $u['id'], null, null, $p1);
            reset_token_clear((int) $u['id']);
            $done = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    view('reset', ['user' => $u, 'token' => $token, 'error' => $error, 'done' => $done], 'Nouveau mot de passe');
    exit;
}

// --- Gate ---
require_auth();

try {
    switch ($page) {

        case 'dashboard':
            $all = collections_all();
            $singletons = array_values(array_filter($all, fn($c) => (int) $c['is_singleton'] === 1));
            $lists      = array_values(array_filter($all, fn($c) => (int) $c['is_singleton'] === 0));
            // Compte les records par collection non-singleton.
            $counts = [];
            foreach ($lists as $col) {
                $stmt = db()->prepare('SELECT COUNT(*) AS n FROM records WHERE collection = :c');
                $stmt->execute([':c' => $col['slug']]);
                $counts[$col['slug']] = (int) ($stmt->fetch()['n'] ?? 0);
            }
            view('dashboard', [
                'singletons'      => $singletons,
                'lists'           => $lists,
                'counts'          => $counts,
                'allCollections'  => $all,
            ], 'Dashboard');
            break;

        case 'collections':
            view('collections_list', [
                'collections' => collections_all(),
            ], 'Schéma');
            break;

        case 'media':
            view('media_library', [
                'files' => greffe_scan_uploads(),
            ], 'Médiathèque');
            break;

        case 'users':
            if (!can_manage_users()) { http_response_code(403); exit('Accès refusé.'); }
            view('users_list', ['users' => user_all(), 'me' => auth_user()], 'Utilisateurs');
            break;

        case 'user_new':
            if (!can_manage_users()) { http_response_code(403); exit('Accès refusé.'); }
            $error = '';
            if ($method === 'POST') {
                csrf_check();
                try {
                    $username = trim((string) ($_POST['username'] ?? ''));
                    $email    = trim((string) ($_POST['email']    ?? ''));
                    $password = (string) ($_POST['password'] ?? '');
                    $role     = (string) ($_POST['role'] ?? 'moderator');
                    if (!can_create_user_with_role($role)) {
                        throw new RuntimeException('Tu n\'as pas le droit de créer un utilisateur avec ce rôle.');
                    }
                    user_create($username, $email, $password, $role);
                    flash_set('success', 'Compte « ' . $username . ' » créé.');
                    redirect('index.php?p=users');
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    flash_set('error', $error);
                }
            }
            view('user_edit', ['target' => null, 'error' => $error, 'me' => auth_user()], 'Nouvel utilisateur');
            break;

        case 'user_edit':
            $tid = (int) ($_GET['id'] ?? 0);
            $target = user_by_id($tid);
            if (!$target) { http_response_code(404); exit('Utilisateur introuvable.'); }
            if (!can_edit_user($target)) { http_response_code(403); exit('Accès refusé.'); }
            $error = '';
            if ($method === 'POST') {
                csrf_check();
                try {
                    $email    = trim((string) ($_POST['email'] ?? $target['email']));
                    $password = (string) ($_POST['password'] ?? '');
                    $role     = (string) ($_POST['role'] ?? $target['role']);
                    if (!user_is_admin()) $role = (string) $target['role'];
                    $me = auth_user();
                    if ($me && (int) $me['id'] === $tid && $role !== 'admin' && user_admin_count() <= 1) {
                        throw new RuntimeException('Tu es le dernier admin — impossible de te rétrograder.');
                    }
                    user_update($tid, $email, $role, $password !== '' ? $password : null);
                    flash_set('success', 'Compte « ' . $target['username'] . ' » mis à jour.');
                    redirect('index.php?p=users');
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    flash_set('error', $error);
                }
            }
            view('user_edit', ['target' => $target, 'error' => $error, 'me' => auth_user()], 'Éditer : ' . $target['username']);
            break;

        case 'updates':
            if (!user_is_admin()) { http_response_code(403); exit('Accès refusé.'); }
            $settings = gh_settings();
            $error = '';
            $latest = null;
            if ($settings['owner'] !== '' && $settings['repo'] !== '') {
                try { $latest = gh_latest_commit(); }
                catch (Throwable $e) { $error = $e->getMessage(); }
            }
            view('updates', [
                'settings' => $settings,
                'latest'   => $latest,
                'current'  => current_code_sha(),
                'backups'  => backups_list(),
                'error'    => $error,
            ], 'Mises à jour');
            break;

        case 'updates_settings':
            if (!user_is_admin()) { http_response_code(403); exit('Accès refusé.'); }
            if ($method === 'POST') {
                csrf_check();
                try {
                    gh_token_save(trim((string) ($_POST['token'] ?? '')));
                    // Override repo facultatif (pour qui veut déployer sur un autre dépôt)
                    $o = trim((string) ($_POST['owner']  ?? ''));
                    $r = trim((string) ($_POST['repo']   ?? ''));
                    $b = trim((string) ($_POST['branch'] ?? ''));
                    if ($o !== '' || $r !== '' || $b !== '') gh_repo_save($o, $r, $b);
                    flash_set('success', 'Réglages enregistrés.');
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                }
            }
            redirect('index.php?p=updates');

        case 'updates_apply':
            if (!user_is_admin()) { http_response_code(403); exit('Accès refusé.'); }
            if ($method === 'POST') {
                csrf_check();
                try {
                    $sha = (string) ($_POST['sha'] ?? '');
                    $res = apply_update($sha);
                    flash_set('success', 'Mis à jour vers ' . substr($res['new_sha'], 0, 7) . ' (backup ' . $res['backup'] . ').');
                } catch (Throwable $e) {
                    flash_set('error', 'Échec de la mise à jour : ' . $e->getMessage());
                }
            }
            redirect('index.php?p=updates');

        case 'updates_rollback':
            if (!user_is_admin()) { http_response_code(403); exit('Accès refusé.'); }
            if ($method === 'POST') {
                csrf_check();
                try {
                    backup_restore((string) ($_POST['name'] ?? ''));
                    flash_set('success', 'Rollback effectué.');
                } catch (Throwable $e) {
                    flash_set('error', 'Échec du rollback : ' . $e->getMessage());
                }
            }
            redirect('index.php?p=updates');

        case 'user_delete':
            if ($method === 'POST') {
                csrf_check();
                $tid = (int) ($_POST['id'] ?? 0);
                $target = user_by_id($tid);
                if ($target && can_delete_user($target)) {
                    user_delete($tid);
                    flash_set('success', 'Compte supprimé.');
                } elseif ($target && !can_delete_user($target)) {
                    flash_set('error', 'Suppression refusée.');
                }
            }
            redirect('index.php?p=users');

        case 'collection_new':
            if (!can_edit_schema()) { http_response_code(403); exit('Accès refusé.'); }
            if ($method === 'POST') {
                csrf_check();
                $id = collection_create(
                    (string) ($_POST['label'] ?? ''),
                    (string) ($_POST['slug']  ?? ''),
                    !empty($_POST['is_singleton'])
                );
                redirect('index.php?p=collection_edit&id=' . $id);
            }
            view('collection_edit', [
                'collection' => null,
                'fields'     => [],
                'collections' => collections_all(),
            ], 'Nouvelle collection');
            break;

        case 'collection_edit':
            if (!can_edit_schema()) { http_response_code(403); exit('Accès refusé.'); }
            $id = (int) ($_GET['id'] ?? 0);
            $col = collection_find($id);
            if (!$col) { http_response_code(404); exit('Collection introuvable.'); }

            if ($method === 'POST') {
                csrf_check();
                $action = (string) ($_POST['action'] ?? '');

                if ($action === 'update_collection') {
                    collection_update($id, (string) ($_POST['label'] ?? $col['label']), !empty($_POST['is_singleton']));
                } elseif ($action === 'add_field') {
                    $type = (string) ($_POST['type'] ?? 'text');
                    $opts = build_field_options($type, $_POST);
                    field_create($id, (string) ($_POST['key'] ?? ''), (string) ($_POST['label'] ?? ''), $type, $opts);
                } elseif ($action === 'update_field') {
                    $fid  = (int) ($_POST['field_id'] ?? 0);
                    $type = (string) ($_POST['type'] ?? 'text');
                    $opts = build_field_options($type, $_POST);
                    field_update($fid, (string) ($_POST['label'] ?? ''), $type, $opts);
                } elseif ($action === 'delete_field') {
                    field_delete((int) ($_POST['field_id'] ?? 0));
                } elseif ($action === 'reorder_fields') {
                    $order = (array) ($_POST['order'] ?? []);
                    foreach ($order as $i => $fid) {
                        field_set_sort((int) $fid, (int) $i);
                    }
                    if (!empty($_SERVER['HTTP_X_GREFFE_AJAX'])) {
                        header('Content-Type: text/plain'); echo 'OK'; exit;
                    }
                }
                redirect('index.php?p=collection_edit&id=' . $id);
            }

            view('collection_edit', [
                'collection'  => $col,
                'fields'      => fields_for_collection($id),
                'collections' => collections_all(),
            ], 'Édition : ' . $col['label']);
            break;

        case 'collection_delete':
            if (!can_edit_schema()) { http_response_code(403); exit('Accès refusé.'); }
            if ($method === 'POST') {
                csrf_check();
                collection_delete((int) ($_POST['id'] ?? 0));
            }
            redirect('index.php?p=collections');

        case 'records':
            $slug = (string) ($_GET['col'] ?? '');
            $col  = collection_find_by_slug($slug);
            if (!$col) { http_response_code(404); exit('Collection introuvable.'); }
            // Singleton -> on saute la liste, on édite directement le record unique.
            if ((int) $col['is_singleton'] === 1) {
                $existing = record_singleton($slug);
                if ($existing) {
                    redirect('index.php?p=record_edit&id=' . $existing['id']);
                }
                redirect('index.php?p=record_new&col=' . $slug);
            }
            if ($method === 'POST') {
                csrf_check();
                $order = (array) ($_POST['order'] ?? []);
                foreach ($order as $i => $rid) {
                    $stmt = db()->prepare('UPDATE records SET sort = :s WHERE id = :id AND collection = :c');
                    $stmt->execute([':s' => (int) $i, ':id' => (int) $rid, ':c' => $slug]);
                }
                header('Content-Type: text/plain'); echo 'OK'; exit;
            }
            view('records_list', [
                'collection' => $col,
                'records'    => records_for($slug),
                'fields'     => fields_for_collection((int) $col['id']),
            ], $col['label']);
            break;

        case 'record_new':
            $slug = (string) ($_GET['col'] ?? '');
            $col  = collection_find_by_slug($slug);
            if (!$col) { http_response_code(404); exit('Collection introuvable.'); }
            $fields = fields_for_collection((int) $col['id']);

            if ($method === 'POST') {
                csrf_check();
                $data = record_build_data($fields, $_POST, $_FILES);
                $id = record_create(
                    $slug,
                    $data,
                    (string) ($_POST['_slug']   ?? ''),
                    (string) ($_POST['_status'] ?? 'draft'),
                    (int)    ($_POST['_sort']   ?? 0)
                );
                redirect('index.php?p=record_edit&id=' . $id);
            }

            view('record_edit', [
                'collection'  => $col,
                'fields'      => $fields,
                'record'      => null,
                'data'        => [],
                'collections' => collections_all(),
                'versions'    => [],
            ], 'Nouveau dans ' . $col['label']);
            break;

        case 'record_edit':
            $id  = (int) ($_GET['id'] ?? 0);
            $rec = record_find($id);
            if (!$rec) { http_response_code(404); exit('Record introuvable.'); }
            $col = collection_find_by_slug((string) $rec['collection']);
            if (!$col) { http_response_code(404); exit('Collection liée introuvable.'); }
            $fields = fields_for_collection((int) $col['id']);

            if ($method === 'POST') {
                csrf_check();
                $current = json_decode_array($rec['data']);
                $data = record_build_data($fields, $_POST, $_FILES, $current);
                // Bouton "Enregistrer + version +1" : incrémente un compteur ciblé après lecture du form.
                if (!empty($_POST['_bump'])) {
                    apply_bump($data, (string) $_POST['_bump']);
                }
                record_update(
                    $id,
                    $data,
                    (string) ($_POST['_slug']   ?? ($rec['slug'] ?? '')),
                    (string) ($_POST['_status'] ?? $rec['status']),
                    (int)    ($_POST['_sort']   ?? $rec['sort'])
                );
                // Retour vers le dashboard (ou autre URL interne) si demandé.
                $return = (string) ($_POST['_return'] ?? '');
                if ($return !== '' && preg_match('#^index\.php(\?|#|$)#', $return)) {
                    redirect($return);
                }
                redirect('index.php?p=record_edit&id=' . $id);
            }

            view('record_edit', [
                'collection'  => $col,
                'fields'      => $fields,
                'record'      => $rec,
                'data'        => json_decode_array($rec['data']),
                'collections' => collections_all(),
                'versions'    => record_versions_list($id),
            ], 'Édition : ' . ($rec['slug'] ?? '#' . $rec['id']));
            break;

        case 'upload_inline':
            // Endpoint AJAX pour uploader une image depuis le WYSIWYG.
            if ($method !== 'POST') { http_response_code(405); exit; }
            csrf_check();
            header('Content-Type: application/json');
            try {
                $path = upload_file($_FILES['file'] ?? [], GREFFE_UPLOAD_IMAGE_MIME);
                echo json_encode(['url' => GREFFE_BASE_URL . '/' . $path]);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;

        case 'record_restore':
            if ($method === 'POST') {
                csrf_check();
                $vid = (int) ($_POST['version_id'] ?? 0);
                $rid = record_restore($vid);
                if ($rid) {
                    redirect('index.php?p=record_edit&id=' . $rid);
                }
            }
            redirect('index.php?p=collections');

        case 'record_delete':
            if ($method === 'POST') {
                csrf_check();
                $id  = (int) ($_POST['id'] ?? 0);
                $rec = record_find($id);
                if ($rec && can_delete_record($rec)) {
                    record_delete($id);
                    redirect('index.php?p=records&col=' . urlencode((string) $rec['collection']));
                }
                if ($rec && !can_delete_record($rec)) {
                    http_response_code(403);
                    exit('Suppression refusée.');
                }
            }
            redirect('index.php?p=collections');

        default:
            http_response_code(404);
            exit('Page inconnue.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Erreur</title>';
    echo '<link rel="stylesheet" href="' . e(GREFFE_BASE_URL) . '/assets/admin.css">';
    echo '<main class="container"><div class="alert">' . e($e->getMessage()) . '</div>';
    echo '<p><a href="' . e(url('index.php')) . '">Retour</a></p></main>';
}

/**
 * Construit la map d'options selon le type du champ.
 */
function build_field_options(string $type, array $post): array
{
    if ($type === 'select') {
        $raw  = (string) ($post['options_choices'] ?? '');
        $vals = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
        return ['choices' => $vals];
    }
    if ($type === 'relation') {
        return [
            'target'   => (string) ($post['options_target'] ?? ''),
            'multiple' => !empty($post['options_multiple']),
        ];
    }
    if ($type === 'group' || $type === 'repeater') {
        $rows = $post['options_subfields'] ?? [];
        if (is_array($rows)) {
            return ['subfields' => subfields_from_array($rows)];
        }
        // Repli sur l'ancien format texte si quelqu'un l'utilise via API.
        return ['subfields' => subfields_parse((string) $rows)];
    }
    return [];
}

/**
 * Convertit un tableau de lignes POST (options_subfields[i][key|label|type|choices])
 * en structure subfields normalisée.
 */
function subfields_from_array(array $rows): array
{
    $allowed = ['text', 'longtext', 'number', 'boolean', 'date', 'select'];
    ksort($rows, SORT_NUMERIC);
    $sub = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $key = keyify(trim((string) ($row['key'] ?? '')));
        if ($key === '') continue;
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') $label = $key;
        $type = (string) ($row['type'] ?? 'text');
        if (!in_array($type, $allowed, true)) continue;
        $sf = ['key' => $key, 'label' => $label, 'type' => $type];
        if ($type === 'select') {
            $raw = (string) ($row['choices'] ?? '');
            $choices = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
            $sf['options'] = ['choices' => $choices];
        }
        $sub[] = $sf;
    }
    return $sub;
}
