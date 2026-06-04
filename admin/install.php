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

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email']    ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm']  ?? '');

        if ($password !== $confirm) {
            throw new RuntimeException('Les mots de passe ne correspondent pas.');
        }
        user_create($username, $email, $password, 'admin');
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

<?php if ($alreadyInstalled && !$ok): ?>
    <p>Installation déjà effectuée.</p>
    <p><strong>Supprimez le fichier <code>admin/install.php</code></strong> du serveur pour finaliser la sécurisation.</p>
    <p><a href="<?= e(GREFFE_BASE_URL) ?>/index.php">Accéder à l'administration</a></p>
<?php elseif ($ok): ?>
    <p>Compte administrateur créé avec succès.</p>
    <p><strong>Supprimez maintenant le fichier <code>admin/install.php</code></strong> avant de continuer.</p>
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
