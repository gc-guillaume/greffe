<?php /** @var array $collections */ ?>
<div class="page-head">
    <div>
        <a href="<?= e(url('index.php')) ?>" class="muted small back-link"><?= icon('arrow-left', 14) ?> Dashboard</a>
        <h1>Mes collections</h1>
        <span class="muted small">Tout ce qui structure tes contenus : réglages, pages, listes.</span>
    </div>
    <a class="btn primary" href="<?= e(url('index.php?p=collection_new')) ?>"><?= icon('plus', 14) ?> Nouvelle collection</a>
</div>

<?php if (!$collections): ?>
    <div class="empty-card">
        <p class="muted">Aucune collection. Commence par en créer une.</p>
        <a class="btn primary" href="<?= e(url('index.php?p=collection_new')) ?>"><?= icon('plus', 14) ?> Créer la première</a>
    </div>
<?php else: ?>
<div class="table-card">
<table class="data-table">
    <thead>
        <tr>
            <th>Label</th>
            <th>Slug</th>
            <th>Type</th>
            <th class="th-actions"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($collections as $c): ?>
        <tr>
            <td class="td-title">
                <a href="<?= e(url('index.php?p=collection_edit&id=' . $c['id'])) ?>"><?= e($c['label']) ?></a>
            </td>
            <td><code class="slug-code"><?= e($c['slug']) ?></code></td>
            <td>
                <?php if ($c['is_singleton']): ?>
                    <span class="status-pill type-singleton"><span class="status-dot"></span>Option</span>
                <?php else: ?>
                    <span class="status-pill type-list"><span class="status-dot"></span>Collection</span>
                <?php endif; ?>
            </td>
            <td class="td-actions">
                <a class="icon-btn" href="<?= e(url('index.php?p=records&col=' . urlencode($c['slug']))) ?>" title="Voir le contenu"><?= icon('eye', 14) ?></a>
                <?php if (can_edit_schema()): ?>
                    <a class="icon-btn" href="<?= e(url('index.php?p=collection_edit&id=' . $c['id'])) ?>" title="Schéma"><?= icon('settings', 14) ?></a>
                    <form method="post" action="<?= e(url('index.php?p=collection_delete')) ?>" class="inline" data-confirm="Supprimer la collection « <?= e($c['label']) ?> » et tout son contenu ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="icon-btn danger" title="Supprimer"><?= icon('trash', 14) ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
