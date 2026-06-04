<?php /** @var string $error */ ?>
<div class="auth-wrap">
    <div class="card">
        <h1>Connexion</h1>
        <?php if (!empty($error)): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off" data-no-hijax>
            <?= csrf_field() ?>
            <label>Identifiant
                <input type="text" name="username" required autofocus>
            </label>
            <label>Mot de passe
                <input type="password" name="password" required>
            </label>
            <button type="submit" class="primary">Se connecter</button>
        </form>
        <p style="margin-top:1rem;text-align:center">
            <a href="<?= e(url('index.php?p=forgot')) ?>" class="muted small">Mot de passe oublié ?</a>
        </p>
    </div>
</div>
