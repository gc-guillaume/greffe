<?php
/** @var array $collection */
/** @var array $fields */
/** @var ?array $record */
/** @var array $data */
/** @var array $collections */
/** @var array $versions */

$isNew     = $record === null;
$singleton = (int) $collection['is_singleton'] === 1;
?>

<div class="page-head">
    <div>
        <?php if (!$singleton): ?>
            <a href="<?= e(url('index.php?p=records&col=' . urlencode($collection['slug']))) ?>" class="muted">&larr; <?= e($collection['label']) ?></a>
        <?php else: ?>
            <a href="<?= e(url('index.php')) ?>" class="muted">&larr; Dashboard</a>
        <?php endif; ?>
        <h1><?= $isNew ? 'Nouveau' : 'Édition' ?> — <?= e($collection['label']) ?></h1>
    </div>
</div>

<form method="post" enctype="multipart/form-data" class="record-form">
    <?= csrf_field() ?>

    <div class="form-main">
        <?php foreach ($fields as $f):
            $val  = $data[$f['key']] ?? null;
            $opts = json_decode_array($f['options']);
            greffe_render_field($f, $val, $opts, [
                'collection'  => $collection,
                'collections' => $collections,
            ]);
        endforeach; ?>
    </div>

    <aside class="form-side">
        <h3>Publication</h3>
        <?php if (!$singleton): ?>
            <label>Slug
                <input type="text" name="_slug" value="<?= e((string) ($record['slug'] ?? '')) ?>" placeholder="auto si vide">
            </label>
        <?php endif; ?>
        <label>Statut
            <select name="_status">
                <?php $st = $record['status'] ?? 'draft'; ?>
                <option value="draft"     <?= $st === 'draft'     ? 'selected' : '' ?>>Brouillon</option>
                <option value="published" <?= $st === 'published' ? 'selected' : '' ?>>Publié</option>
            </select>
        </label>
        <?php if (!$singleton): ?>
            <label>Ordre
                <input type="number" name="_sort" value="<?= (int) ($record['sort'] ?? 0) ?>">
            </label>
        <?php endif; ?>
        <button type="submit" class="primary block">Enregistrer</button>

        <?php if (!$isNew && can_delete_record($record)): ?>
            <form method="post" action="<?= e(url('index.php?p=record_delete')) ?>" data-confirm="Supprimer ce contenu ?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                <button type="submit" class="link danger block">Supprimer</button>
            </form>

            <?php if (!empty($versions)): ?>
                <details class="versions" <?= count($versions) <= 2 ? 'open' : '' ?>>
                    <summary>Historique (<?= count($versions) ?>/10)</summary>
                    <ul class="versions-list">
                        <?php foreach ($versions as $v): ?>
                            <li>
                                <span class="time"><?= e($v['saved_at']) ?></span>
                                <span class="tag status-<?= e($v['status']) ?>"><?= e($v['status']) ?></span>
                                <form method="post" action="<?= e(url('index.php?p=record_restore')) ?>" data-confirm="Restaurer cette version ? L'état actuel sera ajouté à l'historique.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="version_id" value="<?= (int) $v['id'] ?>">
                                    <button type="submit" class="link">Restaurer</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </aside>
</form>
