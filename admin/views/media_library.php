<?php
/**
 * Médiathèque : grille de tous les fichiers du dossier uploads/.
 *
 * @var array $files
 */
$totalSize = array_sum(array_column($files, 'size'));
?>

<div class="page-head">
    <div>
        <a href="<?= e(url('index.php')) ?>" class="muted small back-link"><?= icon('arrow-left', 14) ?> Dashboard</a>
        <h1>Médiathèque</h1>
        <span class="muted small"><?= count($files) ?> fichier<?= count($files) > 1 ? 's' : '' ?> · <?= e(greffe_format_size($totalSize)) ?></span>
    </div>
    <input type="search" class="media-search" placeholder="Filtrer…" data-media-search>
</div>

<?php if (!$files): ?>
    <div class="empty-card">
        <p class="muted">Aucun fichier dans <code>admin/uploads/</code> pour l'instant.</p>
        <p class="muted small">Les uploads passent par les champs <code>media</code>, <code>gallery</code> ou <code>file</code> des records.</p>
    </div>
<?php else: ?>
<div class="media-grid" data-media-grid>
    <?php foreach ($files as $f): ?>
        <article class="media-card" data-name="<?= e(strtolower($f['name'])) ?>" data-path="<?= e(strtolower($f['path'])) ?>">
            <div class="media-thumb">
                <?php if ($f['is_image']): ?>
                    <img src="<?= e(GREFFE_BASE_URL . '/' . $f['path']) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="media-ext"><?= icon('file-text', 28) ?><span><?= e(strtoupper($f['ext'] ?: 'fichier')) ?></span></span>
                <?php endif; ?>
            </div>
            <div class="media-meta">
                <div class="media-name" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></div>
                <div class="media-info">
                    <span class="muted small"><?= e(greffe_format_size($f['size'])) ?></span>
                    <span class="muted small"><?= e(date('Y-m-d', $f['mtime'])) ?></span>
                </div>
                <div class="media-actions">
                    <button type="button" class="icon-btn" title="Copier le chemin" data-copy="<?= e($f['path']) ?>"><?= icon('file-text', 14) ?></button>
                    <a class="icon-btn" href="<?= e(GREFFE_BASE_URL . '/' . $f['path']) ?>" target="_blank" title="Ouvrir"><?= icon('eye', 14) ?></a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<p class="muted small media-foot">
    Astuce : clique sur <?= icon('file-text', 12) ?> pour copier le chemin dans le presse-papier
    (utilisable directement dans un champ media côté admin ou dans ton front via <code>/admin/&lt;chemin&gt;</code>).
</p>
<?php endif; ?>
