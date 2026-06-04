<?php
/** @var array $collection */
/** @var array $records */
/** @var array $fields */
$titleKey = null;
foreach ($fields as $f) {
    if ($f['type'] === 'text') { $titleKey = $f['key']; break; }
}
?>

<div class="page-head">
    <div>
        <a href="<?= e(url('index.php')) ?>" class="muted small back-link"><?= icon('arrow-left', 14) ?> Dashboard</a>
        <h1><?= e($collection['label']) ?></h1>
        <span class="muted small"><?= count($records) ?> item<?= count($records) > 1 ? 's' : '' ?></span>
    </div>
    <div class="actions">
        <a class="btn ghost" href="<?= e(url('index.php?p=collection_edit&id=' . $collection['id'])) ?>"><?= icon('settings', 14) ?> Schéma</a>
        <a class="btn primary" href="<?= e(url('index.php?p=record_new&col=' . urlencode($collection['slug']))) ?>"><?= icon('plus', 14) ?> Nouveau</a>
    </div>
</div>

<?php if (!$records): ?>
    <div class="empty-card">
        <p class="muted">Aucun contenu dans cette collection pour l'instant.</p>
        <a class="btn primary" href="<?= e(url('index.php?p=record_new&col=' . urlencode($collection['slug']))) ?>"><?= icon('plus', 14) ?> Créer le premier</a>
    </div>
<?php else: ?>
<div class="table-card">
<table class="data-table">
    <thead>
        <tr>
            <th class="th-handle"></th>
            <th>Titre <?= icon('chevron-down', 12) ?></th>
            <th>Slug</th>
            <th>Statut</th>
            <th class="muted small">Mis à jour <?= icon('chevron-down', 12) ?></th>
            <th class="th-actions"></th>
        </tr>
    </thead>
    <tbody data-sortable-records data-collection-slug="<?= e($collection['slug']) ?>">
    <?php foreach ($records as $r): $d = json_decode_array($r['data']); ?>
        <tr data-id="<?= (int) $r['id'] ?>">
            <td class="drag-cell"><span class="drag-handle" title="Glisser pour réordonner"><?= icon('grip', 16) ?></span></td>
            <td class="td-title">
                <a href="<?= e(url('index.php?p=record_edit&id=' . $r['id'])) ?>">
                    <?php
                    $title = $titleKey ? (string) ($d[$titleKey] ?? '') : '';
                    if ($title === '') $title = (string) ($r['slug'] ?? ('#' . $r['id']));
                    echo e($title);
                    ?>
                </a>
            </td>
            <td><code class="slug-code"><?= e((string) ($r['slug'] ?? '')) ?></code></td>
            <td>
                <span class="status-pill status-<?= e($r['status']) ?>">
                    <span class="status-dot"></span><?= e($r['status']) ?>
                </span>
            </td>
            <td class="muted small"><?= e($r['updated_at']) ?></td>
            <td class="td-actions">
                <a class="icon-btn" href="<?= e(url('index.php?p=record_edit&id=' . $r['id'])) ?>" title="Éditer"><?= icon('pencil', 14) ?></a>
                <form method="post" action="<?= e(url('index.php?p=record_delete')) ?>" class="inline" data-confirm="Supprimer ce contenu ?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="icon-btn danger" title="Supprimer"><?= icon('trash', 14) ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
