# Recette Greffe — pour seeder un nouveau projet

Document pasteable dans Claude (ou autre LLM) pour démarrer un nouveau site avec un admin propre.
Donne le contexte de ce qu'est Greffe et les patterns à appliquer.

---

## Contexte (à lire avant de générer)

**Greffe** est un mini back-office headless en **PHP vanilla 8.1+ + SQLite**, sans build, sans framework, sans Composer.
Tout le schéma des collections est de la **donnée** (table `fields`), pas du SQL : on ne fait jamais de `CREATE TABLE` pour une collection client.

Le code est sur https://github.com/gc-guillaume/greffe (public, MIT-style).

**Architecture** :
- `admin/` : back-office complet (un seul dossier à déposer)
- `admin/data/content.sqlite` : la DB (gitignored)
- `admin/uploads/` : les fichiers (gitignored)
- Front PHP du client : `require __DIR__ . '/admin/lib/content.php'` puis `content('blog')->all()` / `options('general')`
- **Pas d'API HTTP**, **pas de fetch front** : le front lit directement la SQLite via PHP

**Une collection** est soit :
- **une liste** (ex. `blog`, `team`) — plusieurs records, `content('slug')->all()`, `->find($slug)`, `->get($id)`
- **un singleton** (ex. `general`, `home`, `footer`) — un seul record, `options('slug')`

---

## Types de champ supportés

| type       | usage                                                                            |
|------------|----------------------------------------------------------------------------------|
| `text`     | Ligne unique                                                                     |
| `longtext` | Bloc texte multi-lignes (plain text)                                             |
| `wysiwyg`  | HTML formaté (Pell : bold, italic, h2/h3/h4, quote, links, listes, image-upload) |
| `number`   | Entier ou décimal (prix, version, compteur)                                      |
| `boolean`  | Switch on/off (rendu en switch CSS)                                              |
| `date`     | Date `YYYY-MM-DD`                                                                |
| `color`    | Couleur hex (#rgb ou #rrggbb), input color natif + readout                       |
| `media`    | Upload image (jpg/png/webp/avif/gif — SVG exclu pour XSS)                        |
| `gallery`  | Tableau d'images `{path, alt, title}`, drop zone + drag réordonner               |
| `file`     | Upload PDF / doc / xls / zip / txt / csv                                         |
| `select`   | Liste figée d'options                                                            |
| `relation` | id ou liste d'ids vers une autre collection (avec `with()` pour résoudre)        |
| `group`    | Bloc non répété avec sous-champs (renvoie un objet)                              |
| `repeater` | Tableau de rows avec mêmes sous-champs (renvoie un array d'objets)               |

**Sous-champs** (dans `group`/`repeater`) autorisés : `text, longtext, number, boolean, date, select`.
**Pas** de nesting (pas de repeater dans repeater), pas de `media`/`gallery`/`file`/`relation` dans les sous-champs (utiliser une sous-collection liée par `relation multiple` à la place).

## Conventions à respecter

- **Slugs** en `kebab-case` pour les collections (`mon-bloc`), mais **`snake_case` pour les clés de champ** (`mon_champ` — underscore préservé par `keyify()`).
- **`is_singleton = true`** pour tout ce qui n'est PAS une liste (réglages, pages uniques, page d'accueil, header, footer, dispo-flag temporaire).
- Champ `number` nommé exactement **`version`** (à la racine OU dans un `group`) → l'admin affiche automatiquement un bouton **"Enregistrer + réafficher (version +1)"**. Pratique pour invalider les cookies "déjà vu" côté front (modales, bandeaux).
- **Fichier versionné** (PDF d'invitation, brochure) : un singleton avec un champ `media` ou `file`. L'historique 10 versions de Greffe garde les anciens chemins (les fichiers restent sur disque), donc un bouton **"Restaurer"** fait le rollback.
- **Galerie d'images avec alt/title** : champ `gallery`. Stockage `[{path, alt, title}, ...]`. UI avec drop zone pointillée + previews FileReader.
- **Bandeau / modale temporaire** avec versioning : `group` avec sous-champs `actif (boolean)`, `titre (text)`, `version (number)`, `message (longtext)`. Le bouton bump apparaît automatiquement.
- **Horaires** : `repeater` avec sous-champs `jour, ouvre, ferme, ouvert`.
- **Tarifs avec rangs verrouillés** (2-3 cas, pas une liste) : singleton avec un `group` par tarif (chaque group = une fiche).
- **Tarifs avec n cas** (catalogue) : collection (liste).
- **JSON-LD Google** : c'est le front qui le sérialise à partir des données. Greffe ne s'en occupe pas.

## Pattern de seed

Un seed Greffe crée les collections + leurs fields + des records par défaut, dans un script PHP `admin/seed.php` auto-verrouillant (refuse de tourner si des collections existent déjà).

Squelette minimal d'une fonction de seed :

```php
function seed_run(): void
{
    // Singleton avec un champ texte + une couleur
    $id = collection_create('Réglages', 'reglages', true);
    field_create($id, 'site_name', 'Nom du site', 'text', []);
    field_create($id, 'couleur_accent', 'Couleur d\'accent', 'color', []);

    // Singleton avec un repeater (horaires)
    $id = collection_create('Horaires', 'horaires', true);
    field_create($id, 'jours', 'Jours', 'repeater', [
        'subfields' => [
            ['key' => 'jour',   'label' => 'Jour',     'type' => 'text'],
            ['key' => 'ouvre',  'label' => 'Ouvre',    'type' => 'text'],
            ['key' => 'ferme',  'label' => 'Ferme',    'type' => 'text'],
            ['key' => 'ouvert', 'label' => 'Ouvert ?', 'type' => 'boolean'],
        ],
    ]);
    $defaults = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
    $rows = [];
    foreach ($defaults as $j) {
        $rows[] = ['jour' => $j, 'ouvre' => '09:00', 'ferme' => '18:00', 'ouvert' => true, '__present' => '1'];
    }
    $fields = fields_for_collection($id);
    record_create('horaires', record_build_data($fields, ['jours' => $rows], []), '', 'published', 0);

    // Singleton avec deux groups (modales : toast + fullpage, chacune versionnée)
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

    // Collection (liste) avec wysiwyg + galerie
    $id = collection_create('Blog', 'blog', false);
    field_create($id, 'titre',   'Titre',           'text',     []);
    field_create($id, 'extrait', 'Extrait',         'longtext', []);
    field_create($id, 'contenu', 'Contenu',         'wysiwyg',  []);
    field_create($id, 'image',   'Image de une',    'media',    []);
    field_create($id, 'galerie', 'Galerie',         'gallery',  []);
    field_create($id, 'date',    'Date',            'date',     []);
    $fields = fields_for_collection($id);
    record_create('blog', record_build_data($fields, [
        'titre'   => 'Premier article',
        'extrait' => 'Lancement officiel.',
        'contenu' => '<h2>Bienvenue</h2><p>Du <strong>HTML formaté</strong> via Pell.</p>',
        'date'    => date('Y-m-d'),
    ], []), 'premier-article', 'published', 1);

    // Singleton avec champ file (PDF / doc)
    $id = collection_create('Invitation', 'invitation', true);
    field_create($id, 'pdf', 'PDF actif', 'file', []);
    $fields = fields_for_collection($id);
    record_create('invitation', record_build_data($fields, ['pdf' => ''], []), '', 'published', 0);
}
```

## Consommation côté front

```php
require __DIR__ . '/admin/lib/content.php';

// Singleton
$site = options('reglages');
echo htmlspecialchars($site['site_name']);
echo '<style>:root { --accent: ' . htmlspecialchars($site['couleur_accent']) . ' }</style>';

// Singleton avec repeater
$h = options('horaires');
foreach ($h['jours'] as $j) {
    if (!$j['ouvert']) { echo "{$j['jour']} : fermé\n"; continue; }
    echo "{$j['jour']} : {$j['ouvre']} – {$j['ferme']}\n";
}

// Singleton avec groups
$m = options('modales');
if ($m['toast']['actif']) {
    echo "<div data-version='{$m['toast']['version']}'>{$m['toast']['message']}</div>";
}

// Collection (liste) — projection pour la perf
$posts = content('blog')->select(['titre', 'extrait', 'date'])->all([
    'order' => '-date',
    'limit' => 10,
]);
foreach ($posts as $p) { /* ... */ }

// Collection (un seul, par slug) — contenu complet
$post = content('blog')->find('premier-article');
echo $post['contenu']; // HTML wysiwyg (échappement côté admin trust model)
foreach ($post['galerie'] as $img) {
    echo '<img src="/admin/' . htmlspecialchars($img['path']) . '"'
       . ' alt="' . htmlspecialchars($img['alt']) . '"'
       . ' title="' . htmlspecialchars($img['title']) . '">';
}

// Avec relation résolue
$post = content('blog')->with('auteur')->find('premier-article');
echo $post['auteur']['nom'];
```

## Sécurité (rappel)

- La table `users` (auth admin) n'est **jamais** atteinte par `content()` ou `options()`. Strict cloisonnement front ↔ back.
- `admin/data/.htaccess` bloque tout accès web direct à la SQLite (Apache/LiteSpeed). Pour NGINX, mêmes règles à porter en config server block.
- Toutes les requêtes en prepared statements (PDO ERRMODE_EXCEPTION).
- Clés JSON-path whitelistées (`[a-zA-Z0-9_-]`) dans `where`/`order`/`select`.
- Champs `media` / `gallery` / `file` : whitelist MIME via finfo, extension dérivée du MIME validé (pas du nom utilisateur), renommage hex random. SVG **exclu** (XSS).
- WYSIWYG (Pell) stocke du HTML brut sans sanitization — admin trust model. Tes éditeurs sont supposés de confiance.
- Sessions : cookie HttpOnly + SameSite=Lax + Secure auto en HTTPS, régénération d'id au login.
- Reset password via lien email avec token 128 bits one-shot expirant en 1h, URL publique stockée dans `_meta` (jamais dérivée de HTTP_HOST → anti host header injection).
- CSRF token requis sur tous les POST (login, forgot, reset, user_*, collection_*, record_*, updates_*, logout inclus).
- Échapper avec `htmlspecialchars()` en sortie front.

## Features incluses gratuitement (activées par convention)

- **Historique 10 versions par record** (sidebar avec Restaurer)
- **Migrations versionnées** (`admin/lib/migrations.php`) — ajouter une nouvelle entrée dans `migrations_list()` quand le schéma évolue, joué automatiquement au boot
- **Mises à jour depuis GitHub** (`?p=updates` admin) — backup auto + apply + rollback. Repo public = aucun token requis
- **Drag-and-drop** pour réordonner records, champs, lignes de repeater, images de galerie (SortableJS)
- **Fancy select** custom + hijax AJAX sur tous les liens et formulaires
- **Drop zone galerie** style pointillé avec previews FileReader avant upload
- **Toasts Notyf** (white card Sonner-style) avec flash messages côté serveur
- **Bouton "Enregistrer + version +1"** auto sur les champs `version`
- **Icônes Lucide** inline SVG (20 icônes hand-picked, ~3 KB)
- **Font Inter** locale (4 graisses, ~96 KB)
- **Squircle radius** partout (`corner-shape: squircle` progressive)
- **Médiathèque** (`?p=media`) avec recherche live + copie de chemin
- **Multi-utilisateurs** avec rôles : admin (tout) et moderator (édit/delete sur collections, jamais sur singletons, jamais sur schéma)
- **Mot de passe oublié** via `mail()` PHP + log dans `admin/data/mail.log` pour debug local

## Templates prêts à l'emploi

### Site vitrine basique
- `general` (singleton) — `site_name`, `email_contact`, `telephone`
- `home` (singleton) — `hero_titre`, `hero_image (media)`, `intro (longtext)`
- `blog` (collection) — `titre`, `extrait`, `contenu (wysiwyg)`, `image (media)`, `galerie`, `date`, `auteur (relation)`
- `auteurs` (collection) — `nom`, `bio (longtext)`, `photo (media)`

### Salle / restaurant / lieu accueillant du public
- `banniere` (singleton) — `texte (longtext)`
- `horaires` (singleton) — `jours (repeater)` cf ci-dessus
- `vacances` (singleton) — `periodes (repeater)` avec `libelle, texte`
- `tarifs` (singleton) — 2 ou 3 `group` (un par tarif fixe), même sous-schéma
- `modales` (singleton) — `toast (group)`, `fullpage (group)` avec `version` versionnée
- `invitation` (singleton) — `pdf (file)` + route publique `/invitation.pdf`
- `dispo_flag` (singleton) — `actif (boolean)`, `titre`, `note`

### E-commerce léger / catalogue
- `general` (singleton) — réglages
- `produits` (collection) — `nom`, `description (wysiwyg)`, `prix`, `image (media)`, `galerie`, `categorie (relation)`, `caracteristiques (repeater: cle/valeur)`
- `categories` (collection) — `nom`, `slug`
- `commandes` (collection) — `numero`, `email`, `montant`, `statut (select)`, `lignes (repeater)`

Aucune de ces features ne nécessite de code supplémentaire dans le seed — elles s'activent automatiquement dès que la convention est respectée.
