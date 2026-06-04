<?php
declare(strict_types=1);

/**
 * Front d'exemple Greffe — branché sur le seed standard.
 * Démontre la consommation des 7 collections/singletons depuis le front
 * (banniere, horaires, vacances, dispo_flag, tarifs, modales, invitation).
 */

require __DIR__ . '/admin/lib/content.php';

/* -------- Données -------- */
$banniere   = options('banniere');           // ['texte' => ...]
$horaires   = options('horaires');           // ['jours' => [[jour, ouvre, ferme, ouvert], ...]]
$vacances   = options('vacances');           // ['periodes' => [[libelle, texte], ...]]
$dispoFlag  = options('dispo_flag');         // ['actif', 'titre', 'note']
$tarifs     = options('tarifs');             // ['enfant' => [...], 'accompagnant' => [...]]
$modales    = options('modales');            // ['toast' => [...], 'fullpage' => [...]]
$invitation = options('invitation');         // ['pdf' => 'uploads/.../x.pdf']

/* -------- Helpers de rendu -------- */
function esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
/** Une longtext "points" -> tableau de puces (entités HTML préservées telles quelles). */
function points_to_list(?string $raw): array {
    if (!$raw) return [];
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Démo Greffe — front</title>
<style>
    :root { --accent:#2b6cb0; --bg:#f7f7f8; --fg:#1c1c1f; --muted:#6b7280; --line:#e5e7eb; }
    *,*::before,*::after { box-sizing:border-box; }
    body { margin:0; font:16px/1.55 system-ui, sans-serif; background:var(--bg); color:var(--fg); }
    a { color: var(--accent); }
    .container { max-width: 880px; margin: 0 auto; padding: 1.5rem 1rem 5rem; }
    h1,h2,h3 { line-height: 1.2; }
    .topbar { background:#111827; color:#fff; overflow:hidden; padding:.5rem 0; }
    .topbar-track { display:inline-block; white-space:nowrap; padding-left: 100%; animation: scroll 25s linear infinite; }
    @keyframes scroll { to { transform: translateX(-100%); } }
    .dispo-flag { background:#fef3c7; border:1px solid #f59e0b; padding:.8rem 1rem; border-radius:8px; margin-bottom:1.5rem; }
    .dispo-flag .titre { font-weight:700; color:#92400e; }
    .dispo-flag .note { color:#78350f; font-size:.9rem; }
    .grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); }
    .card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:1rem; }
    .horaires-row { display:flex; justify-content:space-between; align-items:center; padding:.4rem 0; border-bottom:1px dashed var(--line); }
    .horaires-row:last-child { border:0; }
    .horaires-row.ferme { color:var(--muted); }
    .horaires-row .horaires { font-variant-numeric: tabular-nums; }
    .tarif-card .prix { font-size:1.4rem; font-weight:700; color:var(--accent); }
    .tarif-card .points { padding-left:1.2rem; color:var(--muted); font-size:.9rem; }
    .toast {
        position: fixed; right: 1rem; bottom: 1rem; max-width: 320px;
        background:#111827; color:#fff; padding:.8rem 1rem; border-radius:8px;
        box-shadow: 0 8px 20px rgba(0,0,0,.18);
    }
    .toast .titre { font-weight:600; margin-bottom:.2rem; }
    .modal {
        position: fixed; inset:0; background: rgba(0,0,0,.45);
        display: flex; align-items:center; justify-content:center; padding:1rem; z-index: 100;
    }
    .modal-box { background:#fff; border-radius:10px; padding:1.5rem; max-width:480px; }
    footer { color:var(--muted); margin-top: 3rem; font-size:.9rem; text-align:center; }
</style>
</head>
<body>

<?php /* ===== Bannière du haut (texte qui défile) ===== */ ?>
<?php if (!empty($banniere['texte'])): ?>
<div class="topbar"><span class="topbar-track"><?= esc($banniere['texte']) ?>&nbsp;·&nbsp;<?= esc($banniere['texte']) ?></span></div>
<?php endif; ?>

<main class="container">

    <?php /* ===== Dispo-flag (anniversaire) ===== */ ?>
    <?php if (!empty($dispoFlag['actif']) && !empty($dispoFlag['titre'])): ?>
        <div class="dispo-flag">
            <div class="titre"><?= esc($dispoFlag['titre']) ?></div>
            <?php if (!empty($dispoFlag['note'])): ?>
                <div class="note"><?= nl2br(esc($dispoFlag['note'])) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h1>Démo Greffe</h1>
    <p>Cette page lit directement la SQLite via <code>content()</code> et <code>options()</code>. Pas d'API HTTP, pas de fetch — juste un <code>require</code>.</p>

    <?php /* ===== Horaires d'ouverture ===== */ ?>
    <h2>Horaires d'ouverture</h2>
    <?php if (empty($horaires['jours'])): ?>
        <p>Pas encore d'horaires. Configure-les dans l'admin (<code>options('horaires')</code> vide).</p>
    <?php else: ?>
        <div class="card">
            <?php foreach ($horaires['jours'] as $j): ?>
                <div class="horaires-row<?= empty($j['ouvert']) ? ' ferme' : '' ?>">
                    <span class="jour"><?= esc($j['jour']) ?></span>
                    <span class="horaires">
                        <?= !empty($j['ouvert'])
                            ? esc($j['ouvre']) . ' – ' . esc($j['ferme'])
                            : 'Fermé' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php /* ===== Vacances scolaires ===== */ ?>
    <?php if (!empty($vacances['periodes'])): ?>
        <h3>Pendant les vacances</h3>
        <div class="grid">
            <?php foreach ($vacances['periodes'] as $p): ?>
                <div class="card">
                    <strong><?= esc($p['libelle']) ?></strong>
                    <div><?= nl2br(esc($p['texte'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php /* ===== Tarifs ===== */ ?>
    <h2>Tarifs</h2>
    <?php
    // Tarifs = singleton avec 2 groups verrouillés. On itère sur les 2 dans l'ordre.
    $tarifBlocs = [];
    foreach (['enfant', 'accompagnant'] as $key) {
        if (!empty($tarifs[$key]) && is_array($tarifs[$key])) {
            $tarifBlocs[] = $tarifs[$key];
        }
    }
    ?>
    <?php if (!$tarifBlocs): ?>
        <p>Aucun tarif. Configure le singleton <code>tarifs</code> dans l'admin.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($tarifBlocs as $t): ?>
                <div class="card tarif-card">
                    <h3><?= esc($t['titre'] ?? '') ?></h3>
                    <?php if (!empty($t['sous_titre'])): ?>
                        <div style="color:var(--muted);font-size:.9rem;"><?= esc($t['sous_titre']) ?></div>
                    <?php endif; ?>
                    <div class="prix"><?= esc($t['prix_affiche'] ?? '') ?></div>
                    <?php if (!empty($t['note'])): ?>
                        <div style="color:var(--muted);font-size:.85rem;"><?= esc($t['note']) ?></div>
                    <?php endif; ?>
                    <?php $pts = points_to_list($t['points'] ?? null); if ($pts): ?>
                        <ul class="points">
                            <?php foreach ($pts as $p): ?>
                                <li><?= esc($p) /* "&lt;" préservé tel quel */ ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($invitation['pdf'])): ?>
        <p><a href="/invitation.pdf">Télécharger l'invitation (PDF)</a></p>
    <?php endif; ?>

    <h2>JSON-LD (extrait)</h2>
    <p style="color:var(--muted);font-size:.9rem;">Greffe ne génère rien — c'est ton front qui sérialise. Exemple à partir des données seedées :</p>
    <pre style="background:#fff;padding:1rem;border:1px solid var(--line);border-radius:8px;overflow:auto;font-size:.85rem;"><?php
        $offers = [];
        foreach ($tarifBlocs as $t) {
            if (!isset($t['prix'])) continue;
            $offers[] = [
                '@type' => 'Offer',
                'name'  => (string) ($t['titre'] ?? ''),
                'price' => (string) ($t['prix']),
                'priceCurrency' => 'EUR',
            ];
        }
        echo esc(json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    ?></pre>

</main>

<?php /* ===== Modale toast (bas droite) ===== */ ?>
<?php if (!empty($modales['toast']['actif'])):
    // Le `version` sert à invalider le cookie "déjà vu" côté visiteur.
    // L'admin bump la version via le bouton dédié dans Greffe.
    $tv = (int) ($modales['toast']['version'] ?? 1);
?>
    <div class="toast" data-version="<?= $tv ?>">
        <div class="titre"><?= esc($modales['toast']['titre'] ?? '') ?></div>
        <div><?= nl2br(esc($modales['toast']['message'] ?? '')) ?></div>
    </div>
<?php endif; ?>

<?php /* ===== Modale full-page ===== */ ?>
<?php if (!empty($modales['fullpage']['actif'])):
    $fv = (int) ($modales['fullpage']['version'] ?? 1);
?>
    <div class="modal" data-version="<?= $fv ?>">
        <div class="modal-box">
            <h2 style="margin-top:0"><?= esc($modales['fullpage']['titre'] ?? '') ?></h2>
            <p><?= nl2br(esc($modales['fullpage']['message'] ?? '')) ?></p>
            <button onclick="this.closest('.modal').remove()">J'ai compris</button>
        </div>
    </div>
<?php endif; ?>

<footer>
    Démo branchée sur le seed. <a href="/admin/">Administration</a>
</footer>

</body>
</html>
