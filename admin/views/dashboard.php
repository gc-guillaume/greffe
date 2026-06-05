<?php
/**
 * Dashboard : grille de cartes navigables.
 *
 * @var array $singletons   collections kind=options (singletons)
 * @var array $pages        collections kind=pages (chaque record = une page)
 * @var array $lists        collections kind=list (records homogènes)
 * @var array $counts       map [slug => nombre de records] pour pages + lists
 */
?>

<?php
    $u = auth_user();
    $hello = trim((string) ($u['name'] ?? ''));
    if ($hello === '') $hello = 'toi';
?>
<section class="dash-hello" aria-label="Bienvenue">
    <h1>
        <span class="dash-hello-greet">Salut,</span>
        <span class="dash-hello-name">
            <strong><?= e($hello) ?></strong>
            <svg class="dash-hello-squiggle" viewBox="0 0 220 10" preserveAspectRatio="none" aria-hidden="true">
                <path d="M2 7 Q 110 1 218 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </span>
        !
    </h1>
    <p class="dash-hello-sub">On commence par quoi, aujourd'hui ?</p>
</section>

<?php if (!$singletons && !$pages && !$lists): ?>
    <div class="empty-card">
        <p class="muted">Aucune collection pour l'instant.</p>
        <a class="btn primary" href="<?= e(url('index.php?p=collection_new')) ?>"><?= icon('plus', 14) ?> Créer la première</a>
    </div>
<?php endif; ?>

<?php if ($singletons): ?>
<section class="dash-section">
    <header class="dash-section-head">
        <h2>Réglages</h2>
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
                    <span class="muted small">Réglage</span>
                </span>
                <span class="card-chevron"><?= icon('chevron-right', 16) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($pages): ?>
<section class="dash-section">
    <header class="dash-section-head">
        <h2>Pages</h2>
        <span class="muted small"><?= count($pages) ?></span>
    </header>
    <div class="dashboard-grid">
        <?php foreach ($pages as $col): $n = (int) ($counts[$col['slug']] ?? 0); ?>
            <a class="dashboard-card pages-card" href="<?= e(url('index.php?p=records&col=' . urlencode($col['slug']))) ?>">
                <span class="card-icon"><?= icon('layers', 18) ?></span>
                <span class="card-body">
                    <strong><?= e($col['label']) ?></strong>
                    <span class="muted small"><?= $n ?> page<?= $n > 1 ? 's' : '' ?></span>
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
        <h2>Contenu</h2>
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
