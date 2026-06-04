<?php
declare(strict_types=1);

/**
 * Seed Greffe : crée les 7 collections/singletons demandés et les contenus initiaux.
 * À ouvrir une fois après l'installation, puis à supprimer.
 *
 * Liste créée :
 *   1. banniere       (singleton)
 *   2. horaires       (singleton)
 *   3. vacances       (singleton)
 *   4. dispo_flag     (singleton)
 *   5. tarifs         (singleton — 2 groups verrouillés : enfant + accompagnant)
 *   6. modales        (singleton)
 *   7. invitation     (singleton)
 *   8. blog           (collection — 2 articles démo)
 */

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/collections.php';
require_once __DIR__ . '/lib/records.php';

schema_install();

// Garde-fou : Greffe doit déjà être installé (un admin existe).
if (schema_needs_install()) {
    header('Location: install.php');
    exit;
}

$slugs = ['banniere', 'horaires', 'vacances', 'dispo_flag', 'tarifs', 'modales', 'invitation', 'blog'];
$existing = [];
foreach ($slugs as $s) {
    if (collection_find_by_slug($s)) $existing[] = $s;
}

$status = 'ready';
$message = '';
if (count($existing) === count($slugs)) {
    $status = 'done';
    $message = 'Toutes les collections existent déjà. Tu peux supprimer ce fichier.';
} elseif (count($existing) > 0) {
    $status = 'partial';
    $message = 'État partiel détecté. Présents : ' . implode(', ', $existing) . '. Supprime ces collections depuis l\'admin avant de relancer le seed.';
}

$ran = false;
$logs = [];

if ($status === 'ready' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        seed_run($logs);
        $ran = true;
        $status = 'done';
        $message = 'Seed effectué avec succès. Supprime maintenant le fichier admin/seed.php.';
    } catch (Throwable $e) {
        $status = 'error';
        $message = 'Erreur pendant le seed : ' . $e->getMessage();
    }
}

/* -------------------- définitions du seed -------------------- */

function seed_run(array &$logs): void
{
    // 1. Bannière
    $id = collection_create('Bannière du haut', 'banniere', true);
    field_create($id, 'texte', 'Texte', 'longtext', []);
    $logs[] = 'banniere : créé';

    // 2. Horaires d'ouverture (8 lignes pré-remplies)
    $id = collection_create('Horaires d\'ouverture', 'horaires', true);
    field_create($id, 'jours', 'Jours', 'repeater', [
        'subfields' => [
            ['key' => 'jour',   'label' => 'Jour',     'type' => 'text'],
            ['key' => 'ouvre',  'label' => 'Ouvre',    'type' => 'text'],
            ['key' => 'ferme',  'label' => 'Ferme',    'type' => 'text'],
            ['key' => 'ouvert', 'label' => 'Ouvert ?', 'type' => 'boolean'],
        ],
    ]);
    $defaultJours = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche','Jours fériés'];
    $jours = [];
    foreach ($defaultJours as $j) {
        $jours[] = ['jour' => $j, 'ouvre' => '10:30', 'ferme' => '18:00', 'ouvert' => true];
    }
    $fields = fields_for_collection($id);
    $data   = record_build_data($fields, ['jours' => array_map(fn($r) => $r + ['__present' => '1'], $jours)], []);
    record_create('horaires', $data, '', 'published', 0);
    $logs[] = 'horaires : créé avec 8 jours pré-remplis';

    // 3. Vacances scolaires
    $id = collection_create('Horaires des vacances scolaires', 'vacances', true);
    field_create($id, 'periodes', 'Périodes', 'repeater', [
        'subfields' => [
            ['key' => 'libelle', 'label' => 'Libellé', 'type' => 'text'],
            ['key' => 'texte',   'label' => 'Texte',   'type' => 'longtext'],
        ],
    ]);
    $fields = fields_for_collection($id);
    $data   = record_build_data($fields, ['periodes' => []], []);
    record_create('vacances', $data, '', 'published', 0);
    $logs[] = 'vacances : créé (vide)';

    // 4. Dispo-flag (page anniversaire)
    $id = collection_create('Dispo-flag (anniversaire)', 'dispo_flag', true);
    field_create($id, 'actif', 'Afficher le flag', 'boolean', []);
    field_create($id, 'titre', 'Titre',             'text',    []);
    field_create($id, 'note',  'Note (sous-titre)', 'longtext',[]);
    $fields = fields_for_collection($id);
    $data   = record_build_data($fields, ['actif' => '', 'titre' => '', 'note' => ''], []);
    record_create('dispo_flag', $data, '', 'published', 0);
    $logs[] = 'dispo_flag : créé (inactif)';

    // 5. Tarifs (singleton avec 2 groups verrouillés)
    $id = collection_create('Tarifs', 'tarifs', true);
    $tarifSub = [
        ['key' => 'titre',             'label' => 'Titre affiché',         'type' => 'text'],
        ['key' => 'sous_titre',        'label' => 'Sous-titre',            'type' => 'text'],
        ['key' => 'prix',              'label' => 'Prix (chiffre)',        'type' => 'number'],
        ['key' => 'prix_affiche',      'label' => 'Prix affiché (texte)',  'type' => 'text'],
        ['key' => 'note',              'label' => 'Note prix',             'type' => 'text'],
        ['key' => 'supplement_adulte', 'label' => 'Supplément adulte (€)', 'type' => 'number'],
        ['key' => 'points',            'label' => 'Points (1 par ligne)',  'type' => 'longtext'],
    ];
    field_create($id, 'enfant',       'Entrée enfant',         'group', ['subfields' => $tarifSub]);
    field_create($id, 'accompagnant', 'Entrée accompagnant',   'group', ['subfields' => $tarifSub]);

    $fields = fields_for_collection($id);
    $post = [
        'enfant' => [
            'titre'        => 'Entrée enfant',
            'sous_titre'   => 'De 0 à 18 ans',
            'prix'         => '14',
            'prix_affiche' => '14 €',
            'note'         => 'Toutes activités incluses',
            'points'       => "2 accompagnants gratuits par enfant payant (arrivée ensemble)\nBébé &lt; 1 an gratuit si accompagné d'un enfant payant\nBébé &lt; 1 an seul (avec 1 – 2 adultes) : 14 €",
        ],
        'accompagnant' => [
            'titre'             => 'Entrée accompagnant',
            'sous_titre'        => 'Adulte (18 ans et +)',
            'prix'              => '0',
            'prix_affiche'      => 'Gratuit',
            'note'              => '2 adultes inclus',
            'supplement_adulte' => '5',
            'points'            => "2 adultes accompagnants gratuits / enfant payant\nAdulte supplémentaire au-delà de 2 accompagnants : 5 €\nCondition : arriver en même temps que l'enfant",
        ],
    ];
    record_create('tarifs', record_build_data($fields, $post, []), '', 'published', 0);
    $logs[] = 'tarifs : créé en singleton avec 2 groups verrouillés (enfant + accompagnant)';

    // 6. Modales d'info (toast + fullpage, deux groups)
    $id = collection_create('Modales d\'info', 'modales', true);
    $modaleSub = [
        ['key' => 'actif',   'label' => 'Actif',            'type' => 'boolean'],
        ['key' => 'titre',   'label' => 'Titre',            'type' => 'text'],
        ['key' => 'version', 'label' => 'Version actuelle', 'type' => 'number'],
        ['key' => 'message', 'label' => 'Message',          'type' => 'longtext'],
    ];
    field_create($id, 'toast',    'Toast (bas droite)',           'group', ['subfields' => $modaleSub]);
    field_create($id, 'fullpage', 'Modale full-page (importante)','group', ['subfields' => $modaleSub]);
    $fields = fields_for_collection($id);
    $post = [
        'toast'    => ['actif' => '', 'titre' => '', 'version' => '1', 'message' => ''],
        'fullpage' => ['actif' => '', 'titre' => '', 'version' => '1', 'message' => ''],
    ];
    $data = record_build_data($fields, $post, []);
    record_create('modales', $data, '', 'published', 0);
    $logs[] = 'modales : créé (toast + fullpage, version=1)';

    // 7. PDF d'invitation
    $id = collection_create('PDF d\'invitation', 'invitation', true);
    field_create($id, 'pdf', 'PDF actif', 'file', []);
    $fields = fields_for_collection($id);
    $data   = record_build_data($fields, ['pdf' => ''], []);
    record_create('invitation', $data, '', 'published', 0);
    $logs[] = 'invitation : créé (aucun PDF)';

    // 8. Blog (collection)
    $id = collection_create('Blog', 'blog', false);
    field_create($id, 'titre',   'Titre',                       'text',     []);
    field_create($id, 'extrait', 'Extrait (chapeau / résumé)',  'longtext', []);
    field_create($id, 'contenu', 'Contenu (riche)',             'wysiwyg',  []);
    field_create($id, 'image',   'Image de une',                'media',    []);
    field_create($id, 'galerie', 'Galerie',                     'gallery',  []);
    field_create($id, 'date',    'Date',                        'date',     []);
    $fields = fields_for_collection($id);

    record_create('blog', record_build_data($fields, [
        'titre'   => 'Notre nouvelle salle de jeu ouvre demain',
        'extrait' => 'Trampolines, structures, piscine à balles : on a tout repensé pour les 0–12 ans.',
        'contenu' => '<p>Après plusieurs mois de travaux, on rouvre les portes.</p><p>Venez tester les <strong>nouvelles installations</strong> dès le premier jour.</p>',
        'date'    => date('Y-m-d'),
    ], []), 'nouvelle-salle', 'published', 1);

    record_create('blog', record_build_data($fields, [
        'titre'   => 'Trois conseils pour organiser un anniversaire réussi',
        'extrait' => 'Notre équipe partage ses tips après plus de 2000 fêtes accueillies.',
        'contenu' => '<h3>Nos trois règles d\'or</h3><ol><li>Réserver tôt : nos créneaux du samedi partent vite.</li><li>Prévoir un goûter simple.</li><li>Laisser les enfants explorer librement.</li></ol>',
        'date'    => date('Y-m-d', strtotime('-7 days')),
    ], []), 'trois-conseils-anniversaire', 'published', 2);

    $logs[] = 'blog : créé avec 2 articles démo (contenu wysiwyg + galerie)';
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Greffe — seed</title>
<link rel="stylesheet" href="<?= e(GREFFE_BASE_URL) ?>/assets/admin.css">
</head>
<body class="auth">
<main class="card" style="max-width:560px">
    <h1>Greffe — seed initial</h1>
    <p class="muted">Crée les 7 collections/singletons standard et leurs contenus de départ.</p>

    <?php if ($status === 'done'): ?>
        <div class="alert" style="background:#d1fae5;color:#065f46;border-color:#a7f3d0">
            <?= e($message) ?>
        </div>
        <?php if ($ran && $logs): ?>
            <h3>Détails</h3>
            <ul>
                <?php foreach ($logs as $l): ?><li><?= e($l) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><a href="<?= e(GREFFE_BASE_URL) ?>/index.php">Aller à l'administration</a></p>

    <?php elseif ($status === 'partial'): ?>
        <div class="alert"><?= e($message) ?></div>
        <p><a href="<?= e(GREFFE_BASE_URL) ?>/index.php?p=collections">Voir les collections</a></p>

    <?php elseif ($status === 'error'): ?>
        <div class="alert"><?= e($message) ?></div>

    <?php else: ?>
        <p>Voici ce qui sera créé :</p>
        <ul>
            <li><strong>banniere</strong> (singleton) — 1 champ texte</li>
            <li><strong>horaires</strong> (singleton) — repeater pré-rempli avec Lun→Dim + Jours fériés à 10h30–18h00</li>
            <li><strong>vacances</strong> (singleton) — repeater <code>libellé / texte</code></li>
            <li><strong>dispo_flag</strong> (singleton) — actif, titre, note</li>
            <li><strong>tarifs</strong> (collection) — 2 records pré-remplis (enfant, accompagnant)</li>
            <li><strong>modales</strong> (singleton) — toast + fullpage (chacun avec un compteur de version)</li>
            <li><strong>invitation</strong> (singleton) — champ <code>pdf</code> (media)</li>
        </ul>
        <form method="post">
            <button type="submit" class="primary">Lancer le seed</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
