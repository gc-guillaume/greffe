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
- `admin/data/.appkey` : clé AES-256-GCM pour chiffrer les secrets dans `_meta` (gitignored)
- Front PHP du client : `require __DIR__ . '/admin/lib/content.php'` puis `content('blog')->all()` / `options('general')`
- **Pas d'API HTTP**, **pas de fetch front** : le front lit directement la SQLite via PHP

**Une collection** a un `kind` :
- **`options`** (singleton) — un seul record. Réglages site-wide : `general`, `header`, `footer`, `horaires`, `legal`. UI sidebar : entrée directe.
- **`pages`** (liste de pages) — N records, chacun est une page du site. UI sidebar : chaque page listée comme entrée directe sous "Pages".
- **`list`** (liste classique) — N records homogènes. Blog, portfolio, équipe, produits. UI sidebar : entrée vers une page de liste.

Le `kind` se choisit à la création de la collection. **Rétro-compat** : un seed qui passe encore `true` / `false` en 3ᵉ arg de `collection_create()` est mappé automatiquement (`true` → `options`, `false` → `list`).

---

## Types de champ supportés

| type       | usage                                                                            |
|------------|----------------------------------------------------------------------------------|
| `text`     | Ligne unique                                                                     |
| `longtext` | Bloc texte multi-lignes (plain text)                                             |
| `wysiwyg`  | HTML formaté (Pell : bold, italic, h2/h3/h4, quote, links, listes, image-upload) |
| `number`   | Entier ou décimal (prix, version, compteur)                                      |
| `boolean`  | Switch on/off                                                                    |
| `date`     | Date `YYYY-MM-DD`                                                                |
| `color`    | Couleur hex (#rgb ou #rrggbb)                                                    |
| `media`    | Upload image (jpg/png/webp/avif/gif — SVG exclu pour XSS)                        |
| `gallery`  | Tableau d'images `{path, alt, title}`, drop zone + drag réordonner               |
| `file`     | Upload PDF / doc / xls / zip / txt / csv                                         |
| `select`   | Liste figée d'options                                                            |
| `relation` | id ou liste d'ids vers une autre collection (avec `with()` pour résoudre)        |
| `group`    | Bloc non répété avec sous-champs (renvoie un objet)                              |
| `repeater` | Tableau de rows avec mêmes sous-champs (renvoie un array d'objets)               |
| `blocks`   | **Flexible content** : pile de blocs typés, chacun avec son propre schéma         |

**Sous-champs** (dans `group`/`repeater`/`blocks`) autorisés : `text, longtext, number, boolean, date, select`.
**Pas** de nesting (pas de repeater dans repeater), pas de `media`/`gallery`/`file`/`relation` dans les sous-champs (utiliser une sous-collection liée par `relation multiple` à la place).

### Spécifique au type `blocks`

Stockage : `[{type: 'hero', data: {…}}, {type: 'gallery', data: {…}}, …]`.

Schéma défini par un textarea YAML-ish, premier mot = clé du type de bloc, second mot (après `|`) = label, lignes indentées = sous-champs au format `cle|Label|type` :

```
hero | Hero
  titre|Titre|text
  sous_titre|Sous-titre|longtext

gallery | Galerie
  caption|Légende|text

cta | Call-to-action
  label|Label|text
  url|URL|text
```

**Côté front** : Greffe n'impose aucune structure HTML. Le front reçoit `[{type, data}]` et décide librement comment rendre chaque type. Tu peux ajouter/renommer/supprimer des types de blocs sans casser le front : un type inconnu est juste silencieusement ignoré côté serveur (validation au save) et le rendu front est sous ton contrôle complet.

```php
foreach ($page['sections'] as $s) {
    if ($s['type'] === 'hero')    render_hero($s['data']);
    if ($s['type'] === 'gallery') render_gallery($s['data']);
}
```

## Conventions à respecter

- **Slugs** en `kebab-case` pour les collections (`mon-bloc`), mais **`snake_case` pour les clés de champ** (`mon_champ` — underscore préservé par `keyify()`).
- **`kind`** choisi explicitement à la création :
  - `'options'` (ou `true` en rétro-compat) pour tout ce qui n'est PAS une liste — réglages site, header, footer, horaires, legal, dispo-flag.
  - `'pages'` pour une collection de pages du site — accueil, à propos, services, contact. UI sidebar liste chaque page comme entrée.
  - `'list'` (ou `false` en rétro-compat) pour des records homogènes — blog, portfolio, équipe, produits.
- Champ `number` nommé exactement **`version`** (à la racine OU dans un `group`) → l'admin affiche automatiquement un bouton **« Enregistrer + réafficher (version +1) »**. Pratique pour invalider les cookies "déjà vu" côté front (modales, bandeaux).
- **Fichier versionné** (PDF d'invitation, brochure) : un singleton (`kind='options'`) avec un champ `media` ou `file`. L'historique 10 versions de Greffe garde les anciens chemins, bouton **Restaurer** = rollback.
- **Galerie d'images avec alt/title** : champ `gallery`. Stockage `[{path, alt, title}, …]`.
- **Bandeau / modale versionné** : `group` avec sous-champs `actif (boolean)`, `titre (text)`, `version (number)`, `message (longtext)`.
- **Horaires** : `repeater` avec sous-champs `jour, ouvre, ferme, ouvert`.
- **Tarifs verrouillés (2-3 cas)** : singleton avec un `group` par tarif.
- **Tarifs catalogue (n cas)** : `kind='list'`.
- **Pages CMS avec sections** : `kind='pages'` + champ `sections` de type `blocks`.
- **JSON-LD Google** : c'est le front qui le sérialise. Greffe ne s'en occupe pas.

## Pattern de seed

Un seed Greffe crée les collections + leurs fields + des records par défaut, dans un script PHP `admin/seed.php` auto-verrouillant.

Squelette minimal :

```php
function seed_run(): void
{
    // Singleton (kind='options') : un champ texte + une couleur
    $id = collection_create('Réglages', 'reglages', 'options');
    field_create($id, 'site_name', 'Nom du site', 'text', []);
    field_create($id, 'couleur_accent', 'Couleur d\'accent', 'color', []);

    // Singleton avec repeater (horaires)
    $id = collection_create('Horaires', 'horaires', 'options');
    field_create($id, 'jours', 'Jours', 'repeater', [
        'subfields' => [
            ['key' => 'jour',   'label' => 'Jour',     'type' => 'text'],
            ['key' => 'ouvre',  'label' => 'Ouvre',    'type' => 'text'],
            ['key' => 'ferme',  'label' => 'Ferme',    'type' => 'text'],
            ['key' => 'ouvert', 'label' => 'Ouvert ?', 'type' => 'boolean'],
        ],
    ]);
    $rows = [];
    foreach (['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'] as $j) {
        $rows[] = ['jour' => $j, 'ouvre' => '09:00', 'ferme' => '18:00', 'ouvert' => true, '__present' => '1'];
    }
    $fields = fields_for_collection($id);
    record_create('horaires', record_build_data($fields, ['jours' => $rows], []), '', 'published', 0);

    // Singleton avec deux groups (modales : toast + fullpage, chacune versionnée)
    $id = collection_create('Modales', 'modales', 'options');
    $sub = [
        ['key' => 'actif',   'label' => 'Actif',   'type' => 'boolean'],
        ['key' => 'titre',   'label' => 'Titre',   'type' => 'text'],
        ['key' => 'version', 'label' => 'Version', 'type' => 'number'],
        ['key' => 'message', 'label' => 'Message', 'type' => 'longtext'],
    ];
    field_create($id, 'toast',    'Toast',    'group', ['subfields' => $sub]);
    field_create($id, 'fullpage', 'Fullpage', 'group', ['subfields' => $sub]);

    // Collection 'list' (blog) avec wysiwyg + galerie
    $id = collection_create('Blog', 'blog', 'list');
    field_create($id, 'titre',   'Titre',           'text',     []);
    field_create($id, 'extrait', 'Extrait',         'longtext', []);
    field_create($id, 'contenu', 'Contenu',         'wysiwyg',  []);
    field_create($id, 'image',   'Image de une',    'media',    []);
    field_create($id, 'galerie', 'Galerie',         'gallery',  []);
    field_create($id, 'date',    'Date',            'date',     []);

    // Collection 'pages' avec champ blocks (flexible content)
    $id = collection_create('Pages', 'pages', 'pages');
    field_create($id, 'titre', 'Titre', 'text', []);
    field_create($id, 'sections', 'Sections', 'blocks', [
        'block_types' => [
            [
                'key' => 'hero', 'label' => 'Hero',
                'subfields' => [
                    ['key' => 'titre',      'label' => 'Titre',      'type' => 'text'],
                    ['key' => 'sous_titre', 'label' => 'Sous-titre', 'type' => 'longtext'],
                ],
            ],
            [
                'key' => 'gallery', 'label' => 'Galerie',
                'subfields' => [
                    ['key' => 'caption', 'label' => 'Légende', 'type' => 'text'],
                ],
            ],
            [
                'key' => 'cta', 'label' => 'Call-to-action',
                'subfields' => [
                    ['key' => 'label', 'label' => 'Label', 'type' => 'text'],
                    ['key' => 'url',   'label' => 'URL',   'type' => 'text'],
                ],
            ],
        ],
    ]);
    $fields = fields_for_collection($id);
    record_create('pages', record_build_data($fields, ['titre' => 'Accueil'], []), 'accueil', 'published', 0);
    record_create('pages', record_build_data($fields, ['titre' => 'À propos'], []), 'a-propos', 'published', 1);
}
```

## Consommation côté front

```php
require __DIR__ . '/admin/lib/content.php';

// Singleton (kind=options)
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

// Collection (kind=list) — projection pour la perf
$posts = content('blog')->select(['titre', 'extrait', 'date'])->all([
    'order' => '-date',
    'limit' => 10,
]);

// Collection (un seul, par slug). Par défaut : status=published uniquement.
$post = content('blog')->find('premier-article');
echo $post['contenu']; // HTML wysiwyg (admin trust model)
foreach ($post['galerie'] as $img) {
    echo '<img src="/admin/' . htmlspecialchars($img['path']) . '"'
       . ' alt="' . htmlspecialchars($img['alt']) . '">';
}

// Preview / admin : autorise drafts
$post = content('blog')->withDrafts()->find($slug);

// Page CMS avec blocs flexibles
$page = content('pages')->find('accueil');
foreach ($page['sections'] as $s) {
    if ($s['type'] === 'hero') {
        echo '<section class="hero"><h1>' . htmlspecialchars($s['data']['titre']) . '</h1></section>';
    } elseif ($s['type'] === 'cta') {
        echo '<a class="cta" href="' . htmlspecialchars($s['data']['url']) . '">'
           . htmlspecialchars($s['data']['label']) . '</a>';
    }
    // les types inconnus côté front sont juste ignorés
}

// Avec relation résolue
$post = content('blog')->with('auteur')->find('premier-article');
echo $post['auteur']['nom'];
```

## Sécurité (rappel)

- La table `users` n'est **jamais** atteinte par `content()` ou `options()`. Strict cloisonnement front ↔ back.
- `admin/data/.htaccess` bloque l'accès web direct à la SQLite, mail.log, .appkey (Apache/LiteSpeed). Pour NGINX, mêmes règles à porter en config server block.
- Toutes les requêtes en prepared statements (PDO ERRMODE_EXCEPTION).
- Clés JSON-path whitelistées (`[a-zA-Z0-9_-]`) dans `where`/`order`/`select`.
- Champs `media` / `gallery` / `file` : whitelist MIME via `finfo`, extension dérivée du MIME validé (pas du nom utilisateur), renommage hex random. SVG **exclu** (XSS).
- WYSIWYG (Pell) sanitizé serveur-side (DOMDocument, whitelist tags + attrs + protocoles d'URL).
- `find()`/`get()` filtrent `status='published'` par défaut. `->withDrafts()` pour opt-in (preview admin).
- Sessions : cookie HttpOnly + SameSite=Lax + Secure auto en HTTPS, régénération d'id au login.
- Reset password via lien email multipart text+HTML, token 128 bits one-shot expirant en 5h, URL stockée dans `_meta` (jamais dérivée de `HTTP_HOST` → anti host header injection).
- CSRF token requis sur tous les POST (login, forgot, reset, user_*, collection_*, record_*, updates_*, logout inclus).
- GitHub PAT chiffré au repos (AES-256-GCM, clé locale 32 bytes).
- Update tarball : Zip-Slip checks (rejet `..` / abs / drive letters, skip symlinks, containment `realpath`).
- Échapper avec `htmlspecialchars()` en sortie front.

## Features incluses gratuitement (activées par convention)

- **Historique 10 versions par record** (sidebar avec Restaurer)
- **Migrations versionnées** (`admin/lib/migrations.php`) — ajouter une nouvelle entrée dans `migrations_list()`, joué automatiquement au boot
- **Mises à jour depuis GitHub** (`?p=updates` admin) — backup auto + apply + rollback. Repo public = aucun token requis.
- **Drag-and-drop** : records, champs, lignes de repeater, rows de blocks, images de galerie (SortableJS)
- **Blocks dynamiques** : appendage de nouveaux blocs typés via boutons « + Hero », « + Galerie », etc.
- **Fancy select** custom + hijax AJAX sur tous les liens et formulaires
- **Drop zone galerie** pointillée avec previews FileReader avant upload
- **Toasts Notyf** (white card Sonner-style) avec flash messages côté serveur
- **Bouton « Enregistrer + version +1 »** auto sur les champs `version`
- **Icônes Lucide** inline SVG (20 icônes hand-picked, ~3 KB)
- **Font Inter** locale (4 graisses, ~96 KB)
- **Squircle radius** partout (`corner-shape: squircle` progressive)
- **Médiathèque** (`?p=media`) avec recherche live + copie de chemin
- **Multi-utilisateurs** avec rôles : admin (tout) et moderator (édit/delete sur collections `list`, jamais sur `options`/`pages` reglages, jamais sur schéma)
- **Mot de passe oublié** via `mail()` PHP en multipart text+HTML, log dans `admin/data/mail.log` (token redacted)

## Templates prêts à l'emploi

### Site vitrine basique (avec pages dynamiques)
- `general` (`options`) — `site_name`, `email_contact`, `telephone`, `couleur_accent`
- `pages` (`pages`) — `titre`, `sections (blocks: hero / texte / gallery / cta)`
- `blog` (`list`) — `titre`, `extrait`, `contenu (wysiwyg)`, `image (media)`, `galerie`, `date`, `auteur (relation)`
- `auteurs` (`list`) — `nom`, `bio (longtext)`, `photo (media)`

### Salle / restaurant / lieu accueillant du public
- `banniere` (`options`) — `texte (longtext)`
- `horaires` (`options`) — `jours (repeater)` cf ci-dessus
- `vacances` (`options`) — `periodes (repeater)` avec `libelle, texte`
- `tarifs` (`options`) — 2 ou 3 `group` (un par tarif fixe), même sous-schéma
- `modales` (`options`) — `toast (group)`, `fullpage (group)` avec `version` versionnée
- `invitation` (`options`) — `pdf (file)` ; pour servir sous `/invitation.pdf`, code à écrire dans le front (voir README)
- `dispo_flag` (`options`) — `actif (boolean)`, `titre`, `note`

### E-commerce léger / catalogue
- `general` (`options`) — réglages
- `pages` (`pages`) — pages CMS (FAQ, livraison, retours…) avec `sections (blocks)`
- `produits` (`list`) — `nom`, `description (wysiwyg)`, `prix`, `image (media)`, `galerie`, `categorie (relation)`, `caracteristiques (repeater: cle/valeur)`
- `categories` (`list`) — `nom`, `slug`
- `commandes` (`list`) — `numero`, `email`, `montant`, `statut (select)`, `lignes (repeater)`

Aucune de ces features ne nécessite de code supplémentaire dans le seed — elles s'activent automatiquement dès que la convention est respectée.
