<?php
/** @var ?array $target */
/** @var string $error */
/** @var ?array $me */
$isNew = $target === null;
$isSelf = !$isNew && $me && (int) $me['id'] === (int) $target['id'];
$canChangeRole = user_is_admin() && !$isSelf;
?>

<div class="page-head">
    <div>
        <a href="<?= e(url('index.php?p=users')) ?>" class="muted small back-link"><?= icon('arrow-left', 14) ?> Utilisateurs</a>
        <h1><?= $isNew ? 'Nouvel utilisateur' : 'Éditer : ' . e($target['username']) ?></h1>
    </div>
</div>

<form method="post" class="card" style="max-width:520px">
    <?= csrf_field() ?>

    <?php if ($isNew): ?>
        <label>Identifiant
            <input type="text" name="username" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9_.\-]+" value="<?= e($_POST['username'] ?? '') ?>" autofocus>
        </label>
    <?php else: ?>
        <label>Identifiant
            <input type="text" value="<?= e($target['username']) ?>" disabled>
        </label>
    <?php endif; ?>

    <label>Email
        <input type="email" name="email" required value="<?= e($isNew ? ($_POST['email'] ?? '') : $target['email']) ?>">
    </label>

    <label>Mot de passe <?= $isNew ? '' : '<small class="muted">(laisse vide pour ne pas changer)</small>' ?>
        <input type="password" name="password" <?= $isNew ? 'required' : '' ?> minlength="8" autocomplete="new-password">
    </label>

    <?php if ($canChangeRole): ?>
        <label>Rôle
            <select name="role">
                <?php $curRole = $isNew ? ($_POST['role'] ?? 'moderator') : $target['role']; ?>
                <?php if (can_create_user_with_role('admin')): ?>
                    <option value="admin"     <?= $curRole === 'admin'     ? 'selected' : '' ?>>Admin</option>
                <?php endif; ?>
                <option value="moderator" <?= $curRole === 'moderator' ? 'selected' : '' ?>>Modérateur</option>
            </select>
        </label>
    <?php elseif (!$isNew): ?>
        <p class="muted small">
            Rôle : <strong><?= $target['role'] === 'admin' ? 'Admin' : 'Modérateur' ?></strong>
            <?php if ($isSelf): ?> · tu ne peux pas modifier ton propre rôle.<?php endif; ?>
        </p>
    <?php elseif (!user_is_admin()): ?>
        <input type="hidden" name="role" value="moderator">
        <p class="muted small">Rôle attribué : <strong>Modérateur</strong> (les modérateurs ne peuvent pas créer d'admin).</p>
    <?php endif; ?>

    <div style="display:flex; gap:.5rem; margin-top:1rem;">
        <button type="submit" class="primary"><?= $isNew ? 'Créer' : 'Enregistrer' ?></button>
        <a class="btn ghost" href="<?= e(url('index.php?p=users')) ?>">Annuler</a>
    </div>
</form>
