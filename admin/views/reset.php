<?php
/** @var ?array $user */
/** @var string $token */
/** @var string $error */
/** @var bool $done */
?>
<div class="auth-wrap">
    <div class="card">
        <h1>Nouveau mot de passe</h1>

        <?php if ($done): ?>
            <p>Mot de passe mis à jour.</p>
            <p><a href="<?= e(url('index.php?p=login')) ?>">Se connecter</a></p>

        <?php elseif (!$user): ?>
            <div class="alert"><?= e($error !== '' ? $error : 'Lien invalide ou expiré.') ?></div>
            <p><a href="<?= e(url('index.php?p=forgot')) ?>">Demander un nouveau lien</a></p>

        <?php else: ?>
            <p class="muted small">Choisis un nouveau mot de passe pour <strong><?= e($user['username']) ?></strong>.</p>
            <form method="post" autocomplete="off" data-no-hijax>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>Nouveau mot de passe
                    <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
                </label>
                <label>Confirmation
                    <input type="password" name="confirm" required minlength="8" autocomplete="new-password">
                </label>
                <button type="submit" class="primary">Définir le mot de passe</button>
            </form>
        <?php endif; ?>
    </div>
</div>
