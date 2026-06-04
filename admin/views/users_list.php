<?php
/** @var array $users */
/** @var ?array $me */
?>

<div class="page-head">
    <div>
        <a href="<?= e(url('index.php')) ?>" class="muted small back-link"><?= icon('arrow-left', 14) ?> Dashboard</a>
        <h1>Utilisateurs</h1>
        <span class="muted small"><?= count($users) ?> compte<?= count($users) > 1 ? 's' : '' ?></span>
    </div>
    <a class="btn primary" href="<?= e(url('index.php?p=user_new')) ?>"><?= icon('plus', 14) ?> Nouvel utilisateur</a>
</div>

<div class="table-card">
<table class="data-table">
    <thead>
        <tr>
            <th>Identifiant</th>
            <th>Email</th>
            <th>Rôle</th>
            <th class="muted small">Créé le</th>
            <th class="th-actions"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): $isMe = $me && (int) $u['id'] === (int) $me['id']; ?>
        <tr>
            <td class="td-title">
                <a href="<?= e(url('index.php?p=user_edit&id=' . $u['id'])) ?>"><?= e($u['username']) ?></a>
                <?php if ($isMe): ?><span class="muted small"> (toi)</span><?php endif; ?>
            </td>
            <td><code class="slug-code"><?= e($u['email']) ?></code></td>
            <td>
                <?php if ($u['role'] === 'admin'): ?>
                    <span class="status-pill type-singleton"><span class="status-dot"></span>Admin</span>
                <?php else: ?>
                    <span class="status-pill type-list"><span class="status-dot"></span>Modérateur</span>
                <?php endif; ?>
            </td>
            <td class="muted small"><?= e($u['created_at']) ?></td>
            <td class="td-actions">
                <?php if (can_edit_user($u)): ?>
                    <a class="icon-btn" href="<?= e(url('index.php?p=user_edit&id=' . $u['id'])) ?>" title="Éditer"><?= icon('pencil', 14) ?></a>
                <?php endif; ?>
                <?php if (can_delete_user($u)): ?>
                    <form method="post" action="<?= e(url('index.php?p=user_delete')) ?>" class="inline" data-confirm="Supprimer cet utilisateur ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="icon-btn danger" title="Supprimer"><?= icon('trash', 14) ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
