<?php
/**
 * @var array  $settings  ['owner', 'repo', 'branch', 'token']
 * @var ?array $latest    Dernier commit GitHub (sha, short, message, date, author) ou null
 * @var string $current   SHA actuellement déployé (peut être vide)
 * @var array  $backups   Liste de backups [['name','size','mtime'], …]
 * @var string $error
 */
$configured = $settings['owner'] !== '' && $settings['repo'] !== '';
$upToDate   = $latest && $current !== '' && $latest['sha'] === $current;
?>

<div class="page-head">
    <div>
        <a href="<?= e(url('index.php')) ?>" class="muted small back-link"><?= icon('arrow-left', 14) ?> Dashboard</a>
        <h1>Mises à jour</h1>
        <span class="muted small">Code synchronisé depuis ton repo GitHub.</span>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="card" style="margin-bottom:1rem;max-width:760px">
    <h2 style="margin-top:0">Réglages GitHub</h2>
    <p class="muted small">Pour un repo privé, génère un Personal Access Token avec le scope <code>repo</code> sur <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a>.</p>
    <form method="post" action="<?= e(url('index.php?p=updates_settings')) ?>">
        <?= csrf_field() ?>
        <div class="grid-2">
            <label>Owner (utilisateur ou orga)
                <input type="text" name="owner" value="<?= e($settings['owner']) ?>" placeholder="gc-guillaume" required>
            </label>
            <label>Repo
                <input type="text" name="repo" value="<?= e($settings['repo']) ?>" placeholder="greffe" required>
            </label>
        </div>
        <div class="grid-2">
            <label>Branche
                <input type="text" name="branch" value="<?= e($settings['branch']) ?>" placeholder="main">
            </label>
            <label>Token <small class="muted">(laisser vide pour ne pas changer)</small>
                <input type="password" name="token" value="" placeholder="<?= $settings['token'] !== '' ? '(défini)' : 'ghp_…' ?>" autocomplete="new-password">
            </label>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="primary">Enregistrer</button></div>
    </form>
</section>

<?php if ($configured): ?>
    <section class="card" style="margin-bottom:1rem;max-width:760px">
        <h2 style="margin-top:0">Statut</h2>
        <p>
            <span class="muted small">Déployé :</span>
            <code class="slug-code"><?= e($current !== '' ? substr($current, 0, 7) : 'inconnu') ?></code>
        </p>
        <?php if ($latest): ?>
            <p>
                <span class="muted small">Dernier sur <code><?= e($settings['branch']) ?></code> :</span>
                <code class="slug-code"><?= e($latest['short']) ?></code>
                <span class="muted small"> · <?= e(date('Y-m-d H:i', strtotime($latest['date']))) ?> · <?= e($latest['author']) ?></span>
            </p>
            <?php if (strlen($latest['message']) > 0): ?>
                <p class="muted small" style="margin-top:.5rem"><?= e(mb_strimwidth($latest['message'], 0, 200, '…')) ?></p>
            <?php endif; ?>

            <?php if ($upToDate): ?>
                <p style="margin-top:1rem">
                    <span class="status-pill status-published"><span class="status-dot"></span>À jour</span>
                </p>
            <?php else: ?>
                <form method="post" action="<?= e(url('index.php?p=updates_apply')) ?>" data-confirm="Lancer la mise à jour vers <?= e($latest['short']) ?> ? Un backup automatique sera créé.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="sha" value="<?= e($latest['sha']) ?>">
                    <button type="submit" class="primary" style="margin-top:1rem"><?= icon('chevron-right', 14) ?> Mettre à jour vers <?= e($latest['short']) ?></button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted small">Impossible de récupérer le dernier commit. Vérifie le owner/repo/token.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card" style="max-width:760px">
    <h2 style="margin-top:0">Backups (<?= count($backups) ?>)</h2>
    <?php if (!$backups): ?>
        <p class="muted small">Aucun backup. Un backup est créé automatiquement avant chaque mise à jour.</p>
    <?php else: ?>
        <div class="table-card">
            <table class="data-table">
                <thead><tr><th>Nom</th><th>Taille</th><th>Date</th><th class="th-actions"></th></tr></thead>
                <tbody>
                    <?php foreach ($backups as $b): ?>
                        <tr>
                            <td><code class="slug-code"><?= e($b['name']) ?></code></td>
                            <td class="muted small"><?= e(greffe_format_size($b['size'])) ?></td>
                            <td class="muted small"><?= e(date('Y-m-d H:i', $b['mtime'])) ?></td>
                            <td class="td-actions">
                                <form method="post" action="<?= e(url('index.php?p=updates_rollback')) ?>" class="inline" data-confirm="Restaurer ce backup ? L'état actuel sera lui-même sauvegardé avant.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                    <button type="submit" class="icon-btn" title="Rollback"><?= icon('arrow-left', 14) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
