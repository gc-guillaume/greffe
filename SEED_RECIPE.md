# Recette Greffe — pour seeder un nouveau projet

Document pasteable dans Claude (ou autre LLM) pour démarrer un nouveau site avec un admin propre.
Donne le contexte de ce qu'est Greffe et les patterns à appliquer.

---

## Contexte (à lire avant de générer)

Greffe est un mini back-office headless en **PHP vanilla + SQLite**, sans build, sans framework, sans Composer.
Tout le schéma des collections est de la **donnée** (table `fields`), pas du SQL. On ne fait jamais de `CREATE TABLE` pour une collection client.

Une collection est soit :
- **une liste** (ex. `blog`, `tarifs`, `team`) — plusieurs records, accessibles via `content('slug')->all()`, `->find($slug)`, `->get($id)`.
- **un singleton** (ex. `general`, `home`, `footer`) — un seul record, accessible via `options('slug')`.

Le front lit la SQLite via `require __DIR__ . '/admin/lib/content.php';` puis utilise `content()` / `options()`. Pas d'API HTTP. Lecture seule.

## Types de champ

| type       | usage                                                                 |
|------------|-----------------------------------------------------------------------|
| `text`     | Ligne unique (titre, sous-titre, label, slug, etc.)                    |
| `longtext` | Bloc texte multi-lignes (description, contenu, liste à puces en plain) |
| `number`   | Entier ou décimal (prix, version, compteur)                            |
| `boolean`  | Switch on/off (actif, ouvert, publié manuellement, etc.)               |
| `date`     | Date `YYYY-MM-DD`                                                      |
| `media`    | Upload (image, PDF) — chemin relatif stocké dans `data` JSON           |
| `select`   | Liste figée d'options (`['choices' => ['A', 'B']]`)                    |
| `relation` | id ou liste d'ids vers une autre collection (`['target' => 'slug', 'multiple' => true]`) |
| `group`    | Bloc non répété avec sous-champs (renvoie un objet)                    |
| `repeater` | Tableau de rows avec mêmes sous-champs (renvoie un array d'objets)     |

**Sous-champs** (dans `group`/`repeater`) autorisés : `text, longtext, number, boolean, date, select`.
**Pas** de nesting (pas de repeater dans repeater), pas de media/relation dans les sous-champs.

## Conventions à respecter

- **Slugs** en `kebab-case` pour les collections (`mon-bloc`), mais **`snake_case` pour les clés de champ** (`mon_champ` — underscore préservé par `keyify()`).
- **`is_singleton = true`** pour tout ce qui n'est PAS une liste (réglages, pages uniques, page d'accueil, header, footer, dispo-flag temporaire).
- Champ `number` nommé exactement **`version`** (à la racine OU dans un `group`) → l'admin affiche automatiquement un bouton **"Enregistrer + réafficher (version +1)"**. Pratique pour invalider les cookies "déjà vu" côté front (modales, bandeaux).
- **PDF / fichiers versionnés** : un singleton avec un champ `media` suffit. L'historique 5 versions de Greffe garde les anciens chemins (et les fichiers restent sur disque dans `admin/uploads/`), donc un bouton **"Restaurer"** dans la sidebar rollback automatiquement. Pour une URL publique stable (ex. `/invitation.pdf`), un handler PHP de 20 lignes lit `options('invitation')['pdf']` et stream le fichier.
- Pour un **bandeau / message temporaire** avec versioning (pour le faire réapparaître chez tous les visiteurs) : `group` avec sous-champs `actif (boolean)`, `titre (text)`, `version (number)`, `message (longtext)`.
- Pour des **horaires** : `repeater` avec sous-champs `jour, ouvre, ferme, ouvert`.
- Pour des **prix** affichés en cartes : une **collection** (non-singleton) avec un record par offre. Ça permet d'ajouter / réordonner.
- Pour le **JSON-LD Google** : c'est le front qui le sérialise à partir des données. Greffe ne s'en occupe pas.

## Pattern de seed

Un seed Greffe crée les collections + leurs fields + des records par défaut, dans un script PHP `admin/seed.php` auto-verrouillant (refuse de tourner si des collections existent déjà).

Squelette minimal d'une fonction de seed :

```php
function seed_run(): void
{
    // Singleton avec un champ texte
    $id = collection_create('Réglages', 'reglages', true);
    field_create($id, 'site_name', 'Nom du site', 'text', []);

    // Singleton avec un repeater
    $id = collection_create('Horaires', 'horaires', true);
    field_create($id, 'jours', 'Jours', 'repeater', [
        'subfields' => [
            ['key' => 'jour',   'label' => 'Jour',     'type' => 'text'],
            ['key' => 'ouvre',  'label' => 'Ouvre',    'type' => 'text'],
            ['key' => 'ferme',  'label' => 'Ferme',    'type' => 'text'],
            ['key' => 'ouvert', 'label' => 'Ouvert ?', 'type' => 'boolean'],
        ],
    ]);
    // Pré-remplir les 7 jours
    $defaults = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
    $rows = [];
    foreach ($defaults as $j) {
        $rows[] = ['jour' => $j, 'ouvre' => '09:00', 'ferme' => '18:00', 'ouvert' => true, '__present' => '1'];
    }
    $fields = fields_for_collection($id);
    $data = record_build_data($fields, ['jours' => $rows], []);
    record_create('horaires', $data, '', 'published', 0);

    // Singleton avec deux groups (toast + fullpage, chacun versionné)
    $id = collection_create('Modales', 'modales', true);
    $sub = [
        ['key' => 'actif',   'label' => 'Actif',   'type' => 'boolean'],
        ['key' => 'titre',   'label' => 'Titre',   'type' => 'text'],
        ['key' => 'version', 'label' => 'Version', 'type' => 'number'],
        ['key' => 'message', 'label' => 'Message', 'type' => 'longtext'],
    ];
    field_create($id, 'toast',    'Toast',    'group', ['subfields' => $sub]);
    field_create($id, 'fullpage', 'Fullpage', 'group', ['subfields' => $sub]);
    $fields = fields_for_collection($id);
    $data = record_build_data($fields, [
        'toast'    => ['actif' => '', 'titre' => '', 'version' => '1', 'message' => ''],
        'fullpage' => ['actif' => '', 'titre' => '', 'version' => '1', 'message' => ''],
    ], []);
    record_create('modales', $data, '', 'published', 0);

    // Collection (liste) avec records pré-remplis
    $id = collection_create('Tarifs', 'tarifs', false);
    field_create($id, 'titre',        'Titre',                'text',     []);
    field_create($id, 'prix',         'Prix (chiffre)',       'number',   []);
    field_create($id, 'prix_affiche', 'Prix affiché (texte)', 'text',     []);
    field_create($id, 'points',       'Points (1 par ligne)', 'longtext', []);
    $fields = fields_for_collection($id);
    record_create('tarifs', record_build_data($fields, [
        'titre' => 'Entrée enfant', 'prix' => '14', 'prix_affiche' => '14 €',
        'points' => "2 accompagnants gratuits par enfant payant\nBébé &lt; 1 an gratuit",
    ], []), 'enfant', 'published', 1);

    // Singleton avec champ media (PDF / image)
    $id = collection_create('Invitation', 'invitation', true);
    field_create($id, 'pdf', 'PDF actif', 'media', []);
    $fields = fields_for_collection($id);
    record_create('invitation', record_build_data($fields, ['pdf' => ''], []), '', 'published', 0);
}
```

## Consommation côté front

```php
require __DIR__ . '/admin/lib/content.php';

// Singleton
$site = options('reglages');
echo $site['site_name'];

// Singleton avec repeater
$h = options('horaires');
foreach ($h['jours'] as $j) {
    echo $j['ouvert'] ? "{$j['jour']} {$j['ouvre']}–{$j['ferme']}\n" : "{$j['jour']} fermé\n";
}

// Singleton avec groups
$m = options('modales');
if ($m['toast']['actif']) {
    echo "<div data-version='{$m['toast']['version']}'>{$m['toast']['message']}</div>";
}

// Collection (liste)
$tarifs = content('tarifs')->all(['order' => 'sort']);
foreach ($tarifs as $t) { /* ... */ }

// Collection (un seul, par slug)
$post = content('blog')->find('mon-slug');

// Avec relation résolue
$post = content('blog')->with('auteur')->find('mon-slug');
echo $post['auteur']['nom'];
```

## Sécurité (rappel)

- La table `users` (auth admin) n'est **jamais** atteinte par `content()` ou `options()`. Strict cloisonnement.
- `admin/data/.htaccess` bloque tout accès web direct à la SQLite.
- Toutes les requêtes en prepared statements (PDO ERRMODE_EXCEPTION).
- Échapper avec `htmlspecialchars()` en sortie front.
- Champs `media` : whitelist MIME + renommage côté serveur, exécution PHP bloquée dans `/uploads`.

## Templates de seed prêts à l'emploi

### Site vitrine basique
- `general` (singleton) — `site_name`, `email_contact`, `telephone`
- `home` (singleton) — `hero_titre`, `hero_image (media)`, `intro (longtext)`
- `blog` (collection) — `titre`, `slug`, `image (media)`, `date`, `contenu (longtext)`, `auteur (relation)`
- `auteurs` (collection) — `nom`, `bio (longtext)`, `photo (media)`

### Salle / restaurant / lieu accueillant du public
- `banniere` (singleton) — `texte (longtext)`
- `horaires` (singleton) — `jours (repeater)` cf ci-dessus
- `vacances` (singleton) — `periodes (repeater)` avec `libelle, texte`
- `tarifs` (collection) — `titre, sous_titre, prix, prix_affiche, note, points`
- `modales` (singleton) — `toast (group)`, `fullpage (group)` avec `version` versionnée
- `invitation` (singleton) — `pdf (media)` + route publique `/invitation.pdf`
- `dispo_flag` (singleton) — `actif (boolean)`, `titre`, `note`

### E-commerce léger / catalogue
- `general` (singleton) — réglages
- `produits` (collection) — `nom`, `description (longtext)`, `prix`, `image (media)`, `categorie (relation)`, `caracteristiques (repeater: cle/valeur)`
- `categories` (collection) — `nom`, `slug`
- `commandes` (collection) — `numero`, `email`, `montant`, `statut (select)`, `lignes (repeater)`

## Pour aller plus loin

Greffe inclut **gratuitement** :
- Historique 5 versions par record (sidebar de l'édit avec Restaurer)
- Drag-and-drop pour réordonner records + champs (SortableJS)
- Fancy select custom + hijax AJAX sur tous les liens et formulaires
- Toasts de feedback sur les actions de réordonnage
- Bouton "Enregistrer + version +1" auto sur les champs `version`

Aucune de ces features ne nécessite de code supplémentaire dans le seed — elles s'activent automatiquement dès que la convention est respectée.
