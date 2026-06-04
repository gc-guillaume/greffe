<?php
declare(strict_types=1);

/**
 * Premier login. Auto-verrouillant :
 *  - Si la table users est vide -> affiche le formulaire de création du compte admin.
 *  - Sinon -> message demandant de supprimer install.php.
 */

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/users.php';

schema_install();

$alreadyInstalled = !schema_needs_install();
$error = '';
$ok    = false;

// Gate anti-hijack : l'install est verrouillée tant que l'opérateur n'a pas créé
// admin/config.local.php avec `define('GREFFE_INSTALL_ALLOWED', true);`.
// Empêche un attaquant qui découvre l'URL avant l'admin légitime de créer le premier compte.
$installLocked = !$alreadyInstalled
    && !(defined('GREFFE_INSTALL_ALLOWED') && GREFFE_INSTALL_ALLOWED === true);

if (!$alreadyInstalled && !$installLocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email']    ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm']  ?? '');

        if ($password !== $confirm) {
            throw new RuntimeException('Les mots de passe ne correspondent pas.');
        }
        user_create($username, $email, $password, 'admin');
        // Capture l'URL publique au moment de l'install (admin connecté via le bon domaine).
        greffe_public_url_capture();

        // Auto-suppression d'install.php pour éviter qu'il traîne en prod.
        // En dev, définis GREFFE_KEEP_INSTALL = true dans admin/config.local.php pour le garder.
        $keep = (defined('GREFFE_KEEP_INSTALL') && GREFFE_KEEP_INSTALL === true);
        if (!$keep) {
            // Sur la plupart des OS, unlink réussit même pendant l'exécution du script
            // (l'inode reste valide jusqu'à fin du process, le fichier est juste retiré du dirent).
            $autoDeleted = @unlink(__FILE__);
        } else {
            $autoDeleted = false;
        }

        $ok = true;
        $alreadyInstalled = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Installation Greffe</title>
<link rel="stylesheet" href="<?= e(GREFFE_BASE_URL) ?>/assets/admin.css">
</head>
<body class="auth">
<main class="card">
<h1>Greffe — installation</h1>

<?php if ($installLocked): ?>
    <div class="alert">Installation verrouillée.</div>
    <p class="muted small">Pour activer l'installation, crée <code>admin/config.local.php</code> avec :</p>
    <pre style="background:#f1f5f9;padding:.8rem;border-radius:10px;font-size:.85rem;overflow:auto">&lt;?php
if (!defined('GREFFE_INSTALL_ALLOWED')) define('GREFFE_INSTALL_ALLOWED', true);</pre>
    <p class="muted small">Le fichier est gitignored (jamais committé). Tu peux ajouter cette ligne en FTP/SSH avant de lancer l'install, puis la supprimer après.</p>
    <p class="muted small">Cette protection empêche un attaquant qui découvrirait l'URL <code>install.php</code> avant toi de créer le premier compte admin.</p>

<?php elseif ($alreadyInstalled && !$ok): ?>
    <p>Installation déjà effectuée.</p>
    <p><strong>Supprimez le fichier <code>admin/install.php</code></strong> du serveur pour finaliser la sécurisation.</p>
    <p><a href="<?= e(GREFFE_BASE_URL) ?>/index.php">Accéder à l'administration</a></p>
<?php elseif ($ok): ?>
    <p>Compte administrateur créé avec succès.</p>
    <?php if (!empty($autoDeleted)): ?>
        <p class="muted small">Le fichier <code>admin/install.php</code> a été supprimé automatiquement (sécurité).</p>
    <?php elseif (empty($keep)): ?>
        <p><strong>Supprimez maintenant le fichier <code>admin/install.php</code></strong> (la suppression automatique a échoué — droits FS).</p>
    <?php else: ?>
        <p class="muted small">Mode dev (<code>GREFFE_KEEP_INSTALL = true</code>) — install.php est conservé pour permettre de retester.</p>
    <?php endif; ?>
    <p><a href="<?= e(GREFFE_BASE_URL) ?>/index.php?p=login">Se connecter</a></p>
<?php else: ?>
    <p class="muted">Aucun utilisateur n'existe encore. Créez le compte administrateur.</p>
    <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <label>Nom d'utilisateur
            <input type="text" name="username" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9_.\-]+" value="<?= e($_POST['username'] ?? '') ?>">
        </label>
        <label>Email
            <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@exemple.fr">
        </label>
        <label>Mot de passe
            <input type="password" name="password" required minlength="8">
        </label>
        <label>Confirmation
            <input type="password" name="confirm" required minlength="8">
        </label>
        <button type="submit" class="primary">Créer l'administrateur</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
