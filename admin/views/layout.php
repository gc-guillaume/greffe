<?php /** @var string $_title @var ?string $_view */ ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($_title ?? 'Greffe') ?> — Greffe</title>
<?php
$flash = function_exists('flash_consume') ? flash_consume() : null;
if ($flash):
?>
<meta name="greffe-flash" data-type="<?= e($flash['type']) ?>" data-message="<?= e($flash['message']) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(GREFFE_BASE_URL) ?>/assets/vendor/pell.min.css">
<link rel="stylesheet" href="<?= e(GREFFE_BASE_URL) ?>/assets/vendor/notyf.min.css">
<link rel="stylesheet" href="<?= e(GREFFE_BASE_URL) ?>/assets/admin.css">
</head>
<body class="<?= function_exists('auth_user') && auth_user() ? 'with-side' : 'is-auth' ?>">
<?php $_authUser = function_exists('auth_user') ? auth_user() : null; ?>
<?php if ($_authUser): ?>
<header class="topbar">
    <button type="button" class="side-toggle" aria-label="Menu" data-side-toggle><?= icon('menu', 18) ?></button>
    <a class="brand" href="<?= e(url('index.php')) ?>">Greffe</a>
    <div class="spacer"></div>
    <div class="user">
        <span class="user-name"><?= e($_authUser['name']) ?></span>
        <form method="post" action="<?= e(url('index.php?p=logout')) ?>" data-no-hijax class="topbar-logout">
            <?= csrf_field() ?>
            <button type="submit" class="icon-btn" title="Déconnexion"><?= icon('log-out') ?></button>
        </form>
    </div>
</header>

<?php
$nav_all = function_exists('collections_all') ? collections_all() : [];
// Rétro-compat : on retombe sur is_singleton si la colonne kind n'existe pas encore.
$nav_kind = function (array $c): string {
    $k = (string) ($c['kind'] ?? '');
    if ($k !== '') return $k;
    return !empty($c['is_singleton']) ? 'options' : 'list';
};
$nav_opts  = array_values(array_filter($nav_all, fn($c) => $nav_kind($c) === 'options'));
$nav_pages = array_values(array_filter($nav_all, fn($c) => $nav_kind($c) === 'pages'));
$nav_lists = array_values(array_filter($nav_all, fn($c) => $nav_kind($c) === 'list'));

// Détecte la collection en cours de consultation (pour highlight sidebar).
$nav_active_slug   = null;
$nav_active_recid  = null;
$nav_active_p      = (string) ($_GET['p'] ?? '');
if (isset($_GET['col'])) {
    $nav_active_slug = (string) $_GET['col'];
} elseif ($nav_active_p === 'record_edit' && isset($_GET['id']) && function_exists('record_find')) {
    $rec = record_find((int) $_GET['id']);
    if ($rec) {
        $nav_active_slug  = (string) $rec['collection'];
        $nav_active_recid = (int) $rec['id'];
    }
} elseif ($nav_active_p === 'collection_edit' && isset($_GET['id']) && function_exists('collection_find')) {
    $col = collection_find((int) $_GET['id']);
    if ($col) $nav_active_slug = (string) $col['slug'];
}
?>
<aside class="greffe-side">
    <nav>
        <a class="side-home" href="<?= e(url('index.php?p=dashboard')) ?>"><?= icon('home') ?><span>Dashboard</span></a>

        <?php if ($nav_opts): ?>
            <div class="side-section">Réglages</div>
            <ul class="side-list">
                <?php foreach ($nav_opts as $c):
                    $rec  = function_exists('record_singleton') ? record_singleton((string) $c['slug']) : null;
                    $href = $rec
                        ? url('index.php?p=record_edit&id=' . (int) $rec['id'])
                        : url('index.php?p=record_new&col=' . urlencode((string) $c['slug']));
                    $active = $nav_active_slug === $c['slug'] ? ' class="active"' : '';
                ?>
                    <li><a<?= $active ?> href="<?= e($href) ?>"><?= icon('file-text') ?><span><?= e($c['label']) ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($nav_pages): ?>
            <div class="side-section">Pages</div>
            <ul class="side-list">
                <?php foreach ($nav_pages as $c):
                    // Chaque page est listée comme entrée directe (slug/label du record),
                    // pas comme une "collection à dérouler". UX différente des listes :
                    // on accède direct au record_edit, pas à une table.
                    $records = function_exists('records_for') ? records_for((string) $c['slug']) : [];
                    $colHref = url('index.php?p=records&col=' . urlencode((string) $c['slug']));
                    $colActive = $nav_active_slug === $c['slug'] && $nav_active_recid === null ? ' class="active"' : '';
                ?>
                    <li><a<?= $colActive ?> href="<?= e($colHref) ?>"><?= icon('folder') ?><span><?= e($c['label']) ?></span></a></li>
                    <?php foreach ($records as $r):
                        $pageActive = $nav_active_recid === (int) $r['id'] ? ' class="active"' : '';
                        $pageLabel  = $r['slug'] !== null && $r['slug'] !== '' ? (string) $r['slug'] : ('#' . (int) $r['id']);
                    ?>
                        <li class="side-sub"><a<?= $pageActive ?> href="<?= e(url('index.php?p=record_edit&id=' . (int) $r['id'])) ?>"><?= icon('file-text') ?><span><?= e($pageLabel) ?></span></a></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($nav_lists): ?>
            <div class="side-section">Contenu</div>
            <ul class="side-list">
                <?php foreach ($nav_lists as $c):
                    $active = $nav_active_slug === $c['slug'] ? ' class="active"' : '';
                ?>
                    <li><a<?= $active ?> href="<?= e(url('index.php?p=records&col=' . urlencode($c['slug']))) ?>"><?= icon('folder') ?><span><?= e($c['label']) ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="side-section">Admin</div>
        <ul class="side-list">
            <li><a<?= $nav_active_p === 'media' ? ' class="active"' : '' ?> href="<?= e(url('index.php?p=media')) ?>"><?= icon('image') ?><span>Médiathèque</span></a></li>
            <li><a<?= in_array($nav_active_p, ['users','user_edit','user_new'], true) ? ' class="active"' : '' ?> href="<?= e(url('index.php?p=users')) ?>"><?= icon('layers') ?><span>Utilisateurs</span></a></li>
            <?php if (function_exists('user_is_admin') && user_is_admin()): ?>
                <li><a<?= in_array($nav_active_p, ['collections','collection_edit'], true) ? ' class="active"' : '' ?> href="<?= e(url('index.php?p=collections')) ?>"><?= icon('settings') ?><span>Schéma</span></a></li>
                <li><a href="<?= e(url('index.php?p=collection_new')) ?>"><?= icon('plus') ?><span>Nouvelle collection</span></a></li>
                <li><a<?= in_array($nav_active_p, ['updates','updates_settings'], true) ? ' class="active"' : '' ?> href="<?= e(url('index.php?p=updates')) ?>"><?= icon('chevron-down') ?><span>Mises à jour</span></a></li>
            <?php endif; ?>
        </ul>
    </nav>
</aside>
<?php endif; ?>

<main class="container">
<?php if (!empty($_view) && is_file($_view)) { require $_view; } ?>
</main>

<script src="<?= e(GREFFE_BASE_URL) ?>/assets/vendor/Sortable.min.js" defer></script>
<script src="<?= e(GREFFE_BASE_URL) ?>/assets/vendor/pell.min.js" defer></script>
<script src="<?= e(GREFFE_BASE_URL) ?>/assets/vendor/notyf.min.js" defer></script>
<script src="<?= e(GREFFE_BASE_URL) ?>/assets/admin.js" defer></script>
</body>
</html>
