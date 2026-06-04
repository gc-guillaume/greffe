# Greffe

Mini back-office **headless**, PHP vanilla + SQLite, livrable par simple copie de dossier.
Pas de Composer, pas de build, pas de framework. FTP-deployable.

Greffe se pose dans `/admin/` à côté d'un front PHP que vous avez déjà écrit.
Le front lit la base via un seul `require` (lecture seule). Greffe ne connaît rien du front.

---

## Installation

1. Copiez le dossier `admin/` à la racine (ou dans un sous-dossier) de votre projet PHP.
2. Vérifiez que `admin/data/` et `admin/uploads/` sont **accessibles en écriture** par PHP.
3. Ouvrez `https://votresite.tld/admin/install.php` dans un navigateur.
4. Créez votre compte administrateur.
5. **Supprimez `admin/install.php`** (le script vous le rappelle, et se verrouille seul si un compte existe déjà).
6. Connectez-vous via `https://votresite.tld/admin/`.

Pré-requis : PHP **8.1+** avec `pdo_sqlite`. Aucune autre extension obligatoire.

---

## Créer une collection (exemple : `blog`)

1. **Collections > + Nouvelle collection** — label « Articles », slug auto = `articles` (ou tapez `blog`).
2. Sur la page d'édition, ajoutez vos champs :

   | clé        | label          | type      | options                              |
   |------------|----------------|-----------|--------------------------------------|
   | `titre`    | Titre          | text      | —                                    |
   | `contenu`  | Contenu        | longtext  | —                                    |
   | `image`    | Image          | media     | —                                    |
   | `date`     | Date           | date      | —                                    |
   | `auteur`   | Auteur         | relation  | cible : `auteurs`, multiple : non    |

3. Dans **Contenu**, créez vos articles, choisissez le statut **Publié** et donnez un slug.

### Singleton (réglages)

Créez une collection avec la case **Singleton** cochée — par exemple `general`.
Ajoutez les champs souhaités (`site_name`, `email_contact`, etc.).
Greffe n'autorisera qu'un seul record dans cette collection, accessible directement depuis le menu.

---

## Câbler le front

Un seul `require` à faire depuis n'importe quel fichier PHP de votre front :

```php
require __DIR__ . '/admin/lib/content.php';
```

### Lister

```php
$posts = content('blog')->all([
    'status' => 'published',   // défaut: 'published'. Mettre '*' pour tout voir.
    'order'  => '-date',       // '-champ' = desc, 'champ' = asc
    'limit'  => 10,
    'offset' => 0,
    'where'  => ['categorie' => 'actus'], // filtre simple
]);

foreach ($posts as $post) {
    echo '<h2>' . htmlspecialchars($post['titre']) . '</h2>';
    echo $post['contenu']; // déjà nettoyé en amont, ou faites votre propre filtrage
}
```

### Trouver par slug (page détail)

```php
$slug = $_GET['slug'] ?? '';
$post = content('blog')->find($slug);
if (!$post) { http_response_code(404); exit('Article introuvable.'); }
```

### Par id

```php
$item = content('rooms')->get(42);
```

### Résoudre une relation

```php
// Le champ 'auteur' (relation) est remplacé par le record complet de l'auteur.
$post = content('blog')->with('auteur')->find($slug);
echo $post['auteur']['nom'] ?? 'Anonyme';

// Plusieurs relations à la fois :
$posts = content('blog')->with('auteur', 'tags')->all();
```

### Singleton (réglages)

```php
$site = options('general');
echo htmlspecialchars($site['site_name'] ?? '');
```

### Médias

Le champ `media` stocke un chemin relatif au dossier `admin/` (ex. `uploads/2026/06/ab12cd.jpg`).
Pour l'afficher côté front, préfixez par l'URL du dossier `admin/` :

```php
$img = $post['image'] ?? '';
if ($img) {
    echo '<img src="/admin/' . htmlspecialchars($img) . '" alt="">';
}
```

### Routing

**C'est le front qui gère son routing.** Greffe ne touche pas aux URLs publiques.

```php
// Exemple minimal dans un index.php front
require __DIR__ . '/admin/lib/content.php';

$slug = $_GET['slug'] ?? null;
if ($slug) {
    $post = content('blog')->with('auteur')->find($slug);
    // ... render template
} else {
    $posts = content('blog')->all(['order' => '-date']);
    // ... render list
}
```

---

## Types de champ supportés

| type       | stockage `data` JSON                          | UI admin                        |
|------------|------------------------------------------------|---------------------------------|
| `text`     | string                                         | input text                      |
| `longtext` | string                                         | textarea                        |
| `number`   | int / float / null                             | input number                    |
| `boolean`  | true / false                                   | switch                          |
| `date`     | "YYYY-MM-DD"                                   | input date                      |
| `media`    | chemin relatif (`uploads/...`) ou ""           | input file + preview            |
| `select`   | string (une des options définies)              | `<select>` peuplé               |
| `relation` | id (int) ou tableau d'ids                      | `<select>` (multi si configuré) |
| `group`    | objet : `{cle: valeur, …}`                     | bloc avec sous-champs           |
| `repeater` | tableau d'objets : `[{cle: val}, {cle: val}]`  | lignes ajoutables / supprimables |

### Sous-champs (group / repeater)

Définis dans la zone **Sous-champs** au moment de créer ou d'éditer le champ.
Une ligne par sous-champ, au format `cle|Label|type`. Pour un select : `cle|Label|select:opt1,opt2,opt3`.

Types autorisés dans un sous-champ : `text`, `longtext`, `number`, `boolean`, `date`, `select`.
Pas de nesting (pas de repeater dans un repeater) et pas de `media` / `relation` dans les sous-champs en v1
— si tu en as besoin, modélise plutôt une sous-collection liée par une relation `multiple`.

**Exemple — horaires d'ouverture** : crée un champ `horaires` de type `repeater` avec :

```
jour|Jour|select:Lun,Mar,Mer,Jeu,Ven,Sam,Dim
ouvre|Ouvre à|text
ferme|Ferme à|text
ouvert|Ouvert ?|boolean
```

Côté front :

```php
$site = options('general');
foreach ($site['horaires'] as $h) {
    if (!$h['ouvert']) { echo $h['jour'] . " : fermé\n"; continue; }
    echo "{$h['jour']} : {$h['ouvre']} – {$h['ferme']}\n";
}
```

**Exemple — bloc SEO** (group, non répété) :

```
meta_title|Meta title|text
meta_desc|Meta description|longtext
```

Côté front :

```php
echo '<title>' . htmlspecialchars($page['seo']['meta_title'] ?? '') . '</title>';
```

---

## Seed initial (optionnel)

Un script `admin/seed.php` crée en un clic une configuration de démarrage type "site vitrine + anniversaire" :
**banniere** (texte qui défile), **horaires** (repeater Lun→Dim + jours fériés, pré-rempli 10h30–18h00), **vacances** (cartes libellé+texte), **dispo_flag** (toggle + titre + note), **tarifs** (collection avec records `enfant` et `accompagnant`), **modales** (toast + fullpage avec compteur de version), **invitation** (PDF actif).

Workflow :
1. Installe Greffe via `admin/install.php`, supprime ce dernier.
2. Ouvre `admin/seed.php` une fois → bouton « Lancer le seed ».
3. Supprime `admin/seed.php`.

Le seed refuse de tourner si une des 7 collections existe déjà (état partiel → exige un nettoyage manuel).

## Bouton "Enregistrer + réafficher (version +1)"

Convention : tout champ `number` nommé **exactement** `version`, qu'il soit à la racine d'un record ou à l'intérieur d'un `group`, fait apparaître un **second bouton de soumission** « Enregistrer + réafficher (version +1) ».
Le serveur enregistre normalement puis incrémente le compteur ciblé. Pratique pour invalider les cookies "déjà vu" côté visiteur sans avoir à retaper le numéro à la main.

## Servir un fichier (PDF, image, etc.) sous une URL stable

Si tu veux un PDF / fichier accessible sous une URL "propre" (ex. `/invitation.pdf` qui sert toujours la version active du singleton `invitation`), c'est **du code à ajouter dans ton front**.

Exemple : ajoute un fichier `invitation.php` à la racine de TON site (ou dans ta route Symfony/Laravel/etc.) :

```php
<?php
require __DIR__ . '/admin/lib/content.php';

$inv  = options('invitation');
$path = trim((string) ($inv['pdf'] ?? ''));

if ($path === '') { http_response_code(404); exit; }
// Whitelist anti-traversal
$path = ltrim(str_replace('\\', '/', $path), '/');
if (str_contains($path, '..')) { http_response_code(403); exit; }
$abs = __DIR__ . '/admin/' . $path;
if (!is_file($abs)) { http_response_code(404); exit; }

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="invitation.pdf"');
readfile($abs);
```

Ensuite tu route `/invitation.pdf` → ce script via ton `.htaccess` ou ton routeur.

Greffe ne pose **aucun fichier à la racine** — tu gardes le contrôle complet de ton front et de son routing.

Le lien public reste donc inchangé même quand l'admin uploade un nouveau PDF. Les anciennes versions restent sur disque dans `admin/uploads/`, et l'historique 10 versions de Greffe permet de **restaurer** un ancien fichier en un clic depuis la sidebar du record.

## Historique des records

Chaque modification d'un record snapshot automatiquement l'état précédent dans la table `record_versions`.
Les **10 versions les plus récentes** sont conservées par record (purge auto).
Le panneau « Historique » dans la sidebar du formulaire d'édition liste les versions disponibles, avec un bouton **Restaurer** par version.
La restauration est elle-même versionnée : l'état courant est snapshoté avant d'être écrasé, donc on peut toujours revenir en arrière tant qu'on n'a pas dépassé 10 sauvegardes.

## Personnaliser l'installation

### Overrides locaux (`admin/config.local.php`)

Pour personnaliser sans risquer de perdre tes modifs au prochain update, crée un fichier `admin/config.local.php` (gitignored). Greffe le charge automatiquement à la fin de `config.php`. Format :

```php
<?php
// Mode dev : install.php n'est pas auto-supprimé après création de l'admin
if (!defined('GREFFE_KEEP_INSTALL'))    define('GREFFE_KEEP_INSTALL', true);

// Augmenter la limite d'upload (15 Mo)
if (!defined('GREFFE_UPLOAD_MAX'))      define('GREFFE_UPLOAD_MAX', 15 * 1024 * 1024);

// Pointer les updates vers un autre fork
if (!defined('GREFFE_GH_DEFAULT_OWNER')) define('GREFFE_GH_DEFAULT_OWNER', 'ton-org');
if (!defined('GREFFE_GH_DEFAULT_REPO'))  define('GREFFE_GH_DEFAULT_REPO',  'ton-fork-greffe');
```

`admin/config.local.php` n'est PAS dans le repo, donc jamais écrasé par un update.

### Renommer `/admin/` (security through obscurity)

Faisable mais avec un coût : ça casse l'auto-update from GitHub (le tarball contient `admin/`).
Si tu y tiens :
- Renomme physiquement `admin/` → `backoffice/` (ou ce que tu veux)
- Update ton front : `require __DIR__ . '/backoffice/lib/content.php';`
- Les URLs `/backoffice/...` fonctionnent automatiquement (auto-détection de base URL)
- **Désactive l'auto-update** : tu feras les updates en FTP / git pull à la main

Honnêtement, un mot de passe fort + le rate-limiting (ci-dessous) protègent plus que renommer le dossier.

### Renommer le repo GitHub

Tu peux renommer ton fork plus tard (Settings → General → Repository name). **GitHub redirige automatiquement** l'URL ET l'API tarball. Greffe continuera de fonctionner avec les anciennes constantes en place. À toi de mettre à jour `GREFFE_GH_DEFAULT_REPO` quand tu veux nettoyer.

## Sécurité

- Mots de passe : `password_hash` / `password_verify` (bcrypt).
- **Brute-force protection** sur le login : 5 tentatives échouées en 15 min sur la même paire IP+username → lockout 15 min. Stocké en `_meta`, reset automatique au login réussi.
- **Auto-suppression d'`install.php`** après création du premier admin (réversible via `define('GREFFE_KEEP_INSTALL', true)` en mode dev).
- Sessions : cookie `HttpOnly`, `SameSite=Lax`, `Secure` auto en HTTPS, id régénéré au login.
- Token CSRF requis sur tous les POST de l'admin (login inclus, logout inclus).
- Échappement systématique en sortie (`htmlspecialchars` via `e()`).
- Uploads : whitelist MIME via `finfo`, extension dérivée du MIME validé (jamais du nom utilisateur, anti-polyglotte), SVG exclu (anti-XSS), taille max 10 Mo (modifiable).
- `admin/data/` est bloqué par `.htaccess` (la base SQLite n'est jamais téléchargeable sur Apache/LiteSpeed).
- `admin/uploads/` interdit l'exécution PHP.
- `content.php` est **strictement en lecture** — la table `users` n'est jamais accessible depuis le front.
- Reset password via token 128 bits one-shot expirant en 1h, URL construite depuis `_meta.public_url` (capturée à l'install / login admin, jamais depuis `HTTP_HOST` — anti host header injection).

---

## Notes

- **SQLite** est parfaite pour des sites vitrine, blog, portfolios. Pour un site à très forte écriture concurrente, prévoir autre chose.
- **Sauvegarde** = copier `admin/data/content.sqlite` (et `admin/uploads/`). C'est tout.
- **Renommer `/admin/`** : oui, vous pouvez. Renommez le dossier, c'est tout. L'auto-détection de base URL fait le reste. Mettez juste à jour vos `require` côté front.
- **Déploiement FTP** : envoyez le dossier. Vérifiez que `admin/data/` et `admin/uploads/` sont écrivables. Ouvrez `install.php`.

---

## Ce que Greffe ne fait pas (par choix)

- Pas d'API HTTP. Le front lit la SQLite via `content.php`, pas via `fetch`.
- Pas de migrations / DDL dynamique : le schéma des collections est de la donnée, jamais du SQL.
- Pas de gestion fine des rôles (un rôle `admin` suffit).
- Pas de plugins, pas de hooks, pas d'ORM.
- Pas de dépendances externes, pas de build step.
