<?php
/**
 * Dashboard : grille de cartes navigables.
 *
 * @var array $singletons   collections is_singleton=1
 * @var array $lists        collections is_singleton=0
 * @var array $counts       map [slug => nombre de records]
 */
?>

<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <span class="muted small">Tout ce qu'il y a à éditer.</span>
    </div>
    <a class="btn ghost" href="<?= e(url('index.php?p=collections')) ?>"><?= icon('settings', 14) ?> Schéma</a>
</div>

<?php if (!$singletons && !$lists): ?>
    <div class="empty-card">
        <p class="muted">Aucune collection pour l'instant.</p>
        <a class="btn primary" href="<?= e(url('index.php?p=collection_new')) ?>"><?= icon('plus', 14) ?> Créer la première</a>
    </div>
<?php endif; ?>

<?php if ($singletons): ?>
<section class="dash-section">
    <header class="dash-section-head">
        <h2>Options</h2>
        <span class="muted small"><?= count($singletons) ?></span>
    </header>
    <div class="dashboard-grid">
        <?php foreach ($singletons as $col):
            $rec  = record_singleton((string) $col['slug']);
            $href = $rec
                ? url('index.php?p=record_edit&id=' . (int) $rec['id'])
                : url('index.php?p=record_new&col=' . urlencode((string) $col['slug']));
        ?>
            <a class="dashboard-card singleton-card" id="s-<?= e($col['slug']) ?>" href="<?= e($href) ?>">
                <span class="card-icon"><?= icon('file-text', 18) ?></span>
                <span class="card-body">
                    <strong><?= e($col['label']) ?></strong>
                    <span class="muted small">Option</span>
                </span>
                <span class="card-chevron"><?= icon('chevron-right', 16) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($lists): ?>
<section class="dash-section">
    <header class="dash-section-head">
        <h2>Collections</h2>
        <span class="muted small"><?= count($lists) ?></span>
    </header>
    <div class="dashboard-grid">
        <?php foreach ($lists as $col): $n = (int) ($counts[$col['slug']] ?? 0); ?>
            <a class="dashboard-card collection-card" href="<?= e(url('index.php?p=records&col=' . urlencode($col['slug']))) ?>">
                <span class="card-icon"><?= icon('folder', 18) ?></span>
                <span class="card-body">
                    <strong><?= e($col['label']) ?></strong>
                    <span class="muted small"><?= $n ?> item<?= $n > 1 ? 's' : '' ?></span>
                </span>
                <span class="card-chevron"><?= icon('chevron-right', 16) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
