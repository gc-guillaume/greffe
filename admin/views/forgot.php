<?php
/** @var bool $sent */
/** @var string $error */
?>
<div class="auth-wrap">
    <div class="card">
        <h1>Mot de passe oublié</h1>

        <?php if ($sent): ?>
            <p>Si un compte est associé à cet email, un lien de réinitialisation vient d'être envoyé. Le lien est valable <strong>1 heure</strong>.</p>
            <p class="muted small">Pense à vérifier les spams.</p>
            <p><a href="<?= e(url('index.php?p=login')) ?>">Retour à la connexion</a></p>
        <?php else: ?>
            <p class="muted small">Entre l'email associé à ton compte. On t'enverra un lien pour définir un nouveau mot de passe.</p>
            <form method="post" autocomplete="off" data-no-hijax>
                <?= csrf_field() ?>
                <label>Email
                    <input type="email" name="email" required autofocus>
                </label>
                <button type="submit" class="primary">Envoyer le lien</button>
            </form>
            <p style="margin-top:1rem"><a href="<?= e(url('index.php?p=login')) ?>" class="muted small">&larr; Retour à la connexion</a></p>
        <?php endif; ?>
    </div>
</div>
