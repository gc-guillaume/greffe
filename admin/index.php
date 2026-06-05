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
        $u  = trim((string) ($_POST['username'] ?? ''));
        $p  = (string) ($_POST['password'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        $blocked = login_throttle_check($u, $ip);
        if ($blocked !== null) {
            $error = $blocked;
        } elseif (auth_attempt($u, $p)) {
            login_throttle_reset($u, $ip);
            redirect('index.php');
        } else {
            login_throttle_record_fail($u, $ip);
            $error = 'Identifiants invalides.';
        }
    }
    view('login', ['error' => $error], 'Connexion');
    exit;
}

if ($page === 'logout') {
    // Exige POST + CSRF pour éviter les déconnexions forcées via <img src=…?p=logout>.
    if ($method === 'POST') {
        csrf_check();
        auth_logout();
    }
    redirect('index.php?p=login');
}

// Mot de passe oublié (route publique).
if ($page === 'forgot') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    $sent = false;
    $error = '';
    if ($method === 'POST') {
        csrf_check();
        $email = trim((string) ($_POST['email'] ?? ''));
        $u = $email !== '' ? user_by_email($email) : null;
        if ($u) {
            // URL publique stockée à l'install / login admin — JAMAIS dérivée de HTTP_HOST ici
            // (sinon header injection → empoisonnement du lien de reset → takeover de compte).
            $publicUrl = greffe_public_url();
            if ($publicUrl !== '') {
                $token = reset_token_create((int) $u['id']);
                $link  = $publicUrl . GREFFE_BASE_URL . '/index.php?p=reset&token=' . $token;
                $body  = "Bonjour " . $u['username'] . ",\n\n"
                       . "Pour réinitialiser ton mot de passe, clique sur le lien :\n\n"
                       . $link . "\n\n"
                       . "Le lien expire dans 5 heures. Si tu n'es pas à l'origine de la demande, ignore ce mail.\n";
                greffe_mail($email, 'Réinitialisation de mot de passe', $body);
            } else {
                // Diagnostic log : aide à comprendre pourquoi aucun mail n'arrive.
                @file_put_contents(
                    GREFFE_DATA_DIR . '/mail.log',
                    sprintf(
                        "[%s] /forgot pour %s — public_url VIDE dans _meta, AUCUN mail envoyé.\n"
                        . "  → Connecte-toi une fois en tant qu'admin (capture auto via require_auth).\n----\n",
                        date('Y-m-d H:i:s'),
                        $email
                    ),
                    FILE_APPEND
                );
            }
        }
        // Toujours afficher "envoyé" pour éviter l'énumération d'emails.
        $sent = true;
    }
    view('forgot', ['sent' => $sent, 'error' => $error], 'Mot de passe oublié');
    exit;
}

// Réinitialisation via token (route publique).
if ($page === 'reset') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-LiteSpeed-Cache-Control: no-cache');

    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    // Filet : si l'URL contient &amp;token=... (entité HTML non décodée par certains
    // clients mail / proxies / copy-paste), PHP parse 'amp;token' comme clé. On répare
    // en relisant la query string brute sans entités.
    if ($token === '' && !empty($_GET['amp;token'])) {
        $token = (string) $_GET['amp;token'];
    }
    if ($token === '' && !empty($_SERVER['QUERY_STRING'])) {
        parse_str(str_replace('&amp;', '&', (string) $_SERVER['QUERY_STRING']), $qsRepair);
        if (!empty($qsRepair['token'])) $token = (string) $qsRepair['token'];
    }
    $u = reset_token_verify($token);
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
            $kindOf = fn(array $c) => ((string) ($c['kind'] ?? '')) !== ''
                ? (string) $c['kind']
                : (!empty($c['is_singleton']) ? 'options' : 'list');
            $singletons = array_values(array_filter($all, fn($c) => $kindOf($c) === 'options'));
            $pages      = array_values(array_filter($all, fn($c) => $kindOf($c) === 'pages'));
            $lists      = array_values(array_filter($all, fn($c) => $kindOf($c) === 'list'));
            // Compte les records par collection non-singleton (pages + lists).
            $counts = [];
            foreach (array_merge($pages, $lists) as $col) {
                $stmt = db()->prepare('SELECT COUNT(*) AS n FROM records WHERE collection = :c');
                $stmt->execute([':c' => $col['slug']]);
                $counts[$col['slug']] = (int) ($stmt->fetch()['n'] ?? 0);
            }
            view('dashboard', [
                'singletons'      => $singletons,
                'pages'           => $pages,
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
            $compare = null;
            if ($settings['owner'] !== '' && $settings['repo'] !== '') {
                try {
                    $latest  = gh_latest_commit();
                    $current = current_code_sha();
                    if ($latest && $current !== '' && $current !== $latest['sha']) {
                        $compare = gh_compare($current, $latest['sha']);
                    }
                } catch (Throwable $e) { $error = $e->getMessage(); }
            }
            view('updates', [
                'settings' => $settings,
                'latest'   => $latest,
                'current'  => current_code_sha(),
                'compare'  => $compare,
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
                try {
                    // Rétro-compat : si POST envoie encore is_singleton (vieux form), on dérive.
                    $kind = (string) ($_POST['kind'] ?? '');
                    if ($kind === '') {
                        $kind = !empty($_POST['is_singleton']) ? 'options' : 'list';
                    }
                    $id = collection_create(
                        (string) ($_POST['label'] ?? ''),
                        (string) ($_POST['slug']  ?? ''),
                        $kind
                    );
                    // Wizard envoie aussi fields[] = [{key, label, type}, ...]. On les crée en
                    // série après la collection. Échecs silencieux par champ (clé vide ou dupliquée
                    // = skip ce champ, on ne casse pas la création de la collection).
                    $rawFields = $_POST['fields'] ?? [];
                    if (is_array($rawFields)) {
                        foreach ($rawFields as $row) {
                            if (!is_array($row)) continue;
                            $fkey   = trim((string) ($row['key']   ?? ''));
                            $flabel = trim((string) ($row['label'] ?? ''));
                            $ftype  = (string) ($row['type'] ?? 'text');
                            if ($fkey === '' || $flabel === '') continue;
                            try {
                                field_create($id, $fkey, $flabel, $ftype, []);
                            } catch (Throwable $e) { /* skip ce champ */ }
                        }
                    }
                    flash_set('success', 'Collection créée.');
                    redirect('index.php?p=collection_edit&id=' . $id);
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                }
            }
            view('collection_wizard', [
                'existing_slugs' => array_map(fn($c) => (string) $c['slug'], collections_all()),
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

                try {
                    if ($action === 'update_collection') {
                        // On NE prend PAS le kind du POST : un changement de kind est destructif
                        // (list <-> options change la cardinalité, casse les records existants
                        // et le front qui les consomme). On force toujours le kind courant de la DB.
                        // Idem pour le slug : géré par collection_create uniquement.
                        $kindLocked = (string) ($col['kind'] ?? '');
                        if ($kindLocked === '') {
                            $kindLocked = !empty($col['is_singleton']) ? 'options' : 'list';
                        }
                        collection_update($id, (string) ($_POST['label'] ?? $col['label']), $kindLocked);
                        flash_set('success', 'Label mis à jour.');
                    } elseif ($action === 'add_field') {
                        $type = (string) ($_POST['type'] ?? 'text');
                        $opts = build_field_options($type, $_POST);
                        field_create($id, (string) ($_POST['key'] ?? ''), (string) ($_POST['label'] ?? ''), $type, $opts);
                        flash_set('success', 'Champ ajouté.');
                    } elseif ($action === 'update_field') {
                        $fid  = (int) ($_POST['field_id'] ?? 0);
                        $type = (string) ($_POST['type'] ?? 'text');
                        $opts = build_field_options($type, $_POST);
                        field_update($fid, (string) ($_POST['label'] ?? ''), $type, $opts);
                        flash_set('success', 'Champ enregistré.');
                    } elseif ($action === 'delete_field') {
                        field_delete((int) ($_POST['field_id'] ?? 0));
                        flash_set('success', 'Champ supprimé.');
                    } elseif ($action === 'reorder_fields') {
                        $order = (array) ($_POST['order'] ?? []);
                        foreach ($order as $i => $fid) {
                            field_set_sort((int) $fid, (int) $i);
                        }
                        if (!empty($_SERVER['HTTP_X_GREFFE_AJAX'])) {
                            header('Content-Type: text/plain'); echo 'OK'; exit;
                        }
                    }
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
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
                flash_set('success', 'Collection supprimée.');
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
                try {
                    $data = record_build_data($fields, $_POST, $_FILES);
                    $id = record_create(
                        $slug,
                        $data,
                        (string) ($_POST['_slug']   ?? ''),
                        (string) ($_POST['_status'] ?? 'draft'),
                        (int)    ($_POST['_sort']   ?? 0)
                    );
                    flash_set('success', 'Élément créé.');
                    redirect('index.php?p=record_edit&id=' . $id);
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                }
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
                try {
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
                    flash_set('success', 'Enregistré.');
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                }
                // Retour vers le dashboard (ou autre URL interne) si demandé.
                $return = (string) ($_POST['_return'] ?? '');
                // Whitelist stricte : index.php?p=<page>(&...|#...|fin). Évite tout open-redirect
                // ou redirection vers un GET inattendu si on en ajoutait un jour.
                if ($return !== '' && preg_match('#^index\.php\?p=[a-z_]+(&|\#|$)#', $return)) {
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
                    flash_set('success', 'Version restaurée.');
                    redirect('index.php?p=record_edit&id=' . $rid);
                }
                flash_set('error', 'Version introuvable.');
            }
            redirect('index.php?p=collections');

        case 'record_delete':
            if ($method === 'POST') {
                csrf_check();
                $id  = (int) ($_POST['id'] ?? 0);
                $rec = record_find($id);
                if ($rec && can_delete_record($rec)) {
                    record_delete($id);
                    flash_set('success', 'Élément supprimé.');
                    redirect('index.php?p=records&col=' . urlencode((string) $rec['collection']));
                }
                if ($rec && !can_delete_record($rec)) {
                    flash_set('error', 'Suppression refusée.');
                    redirect('index.php');
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
    if ($type === 'blocks') {
        // Schéma défini via un textarea YAML-ish (simple à éditer, pas de hiérarchie d'UI à dessiner).
        // Lignes commençant en colonne 0 : 'cle | Label' = nouveau type de bloc.
        // Lignes indentées : sous-champs au format 'cle|Label|type' (cf subfields_parse).
        $raw = (string) ($post['options_block_types_text'] ?? '');
        return ['block_types' => block_types_parse_text($raw)];
    }
    return [];
}

/**
 * Parse un textarea YAML-ish en structure block_types.
 *
 * Format :
 *   hero | Hero
 *     titre|Titre|text
 *     sous_titre|Sous-titre|longtext
 *   gallery | Galerie
 *     caption|Légende|text
 *
 * Lignes vides et commentaires '#' ignorés. L'indentation peut être spaces ou tabs.
 */
function block_types_parse_text(string $raw): array
{
    $out = [];
    $current = null;
    $bufferedSubLines = [];
    $flush = function () use (&$out, &$current, &$bufferedSubLines): void {
        if ($current === null) return;
        $current['subfields'] = $bufferedSubLines === [] ? [] : subfields_parse(implode("\n", $bufferedSubLines));
        $out[] = $current;
        $current = null;
        $bufferedSubLines = [];
    };
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    foreach ($lines as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) continue;
        $indented = ($line[0] === ' ' || $line[0] === "\t");
        $trimmed = trim($line);
        if (!$indented) {
            $flush();
            // Format: 'cle | Label' (séparateur '|'). Label optionnel.
            $parts = explode('|', $trimmed, 2);
            $key = keyify(trim($parts[0]));
            if ($key === '') continue;
            $label = isset($parts[1]) ? trim($parts[1]) : '';
            if ($label === '') $label = $key;
            $current = ['key' => $key, 'label' => $label, 'subfields' => []];
        } else {
            if ($current === null) continue; // ligne indentée sans block courant : skip
            $bufferedSubLines[] = $trimmed;
        }
    }
    $flush();
    return $out;
}

/**
 * Sérialise block_types vers le format textarea (pour réafficher dans l'éditeur).
 */
function block_types_serialize(array $blockTypes): string
{
    $out = [];
    foreach ($blockTypes as $bt) {
        if (!is_array($bt) || empty($bt['key'])) continue;
        $out[] = $bt['key'] . ' | ' . ($bt['label'] ?? $bt['key']);
        $sub = is_array($bt['subfields'] ?? null) ? $bt['subfields'] : [];
        foreach ($sub as $sf) {
            if (!is_array($sf) || empty($sf['key'])) continue;
            $line = '  ' . $sf['key'] . '|' . ($sf['label'] ?? $sf['key']) . '|' . ($sf['type'] ?? 'text');
            if (($sf['type'] ?? '') === 'select' && !empty($sf['options']['choices'])) {
                $line .= ':' . implode(',', (array) $sf['options']['choices']);
            }
            $out[] = $line;
        }
        $out[] = '';
    }
    return rtrim(implode("\n", $out));
}

/**
 * Convertit un tableau de lignes POST (options_subfields[i][key|label|type|choices])
 * en structure subfields normalisée.
 */
function subfields_from_array(array $rows): array
{
    $allowed = ['text', 'longtext', 'number', 'boolean', 'date', 'select'];
    // PAS de ksort : on respecte l'ordre d'insertion (= ordre du body POST = ordre DOM
    // après drag SortableJS). Les indices [0], [1], [1000]… peuvent être désordonnés
    // après reorder client-side, mais foreach itère en ordre d'insertion = visuel.
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
