# Greffe

Mini back-office **headless**, PHP vanilla + SQLite, livrable par simple copie de dossier.
Pas de Composer, pas de build, pas de framework. FTP-deployable.

Greffe se pose dans `/admin/` à côté d'un front PHP que vous avez déjà écrit.
Le front lit la base via un seul `require` (lecture seule). Greffe ne connaît rien du front.

---

## Installation

1. Copiez le dossier `admin/` à la racine (ou dans un sous-dossier) de votre projet PHP.
2. Vérifiez que `admin/data/` et `admin/uploads/` sont **accessibles en écriture** par PHP.
3. Créez `admin/config.local.php` avec une seule ligne pour autoriser l'install :
   ```php
   <?php if (!defined('GREFFE_INSTALL_ALLOWED')) define('GREFFE_INSTALL_ALLOWED', true);
   ```
4. Ouvrez `https://votresite.tld/admin/install.php`, créez votre compte admin.
5. `install.php` **s'auto-supprime** après création du compte. Vous pouvez retirer la ligne `GREFFE_INSTALL_ALLOWED` de `config.local.php`.
6. Connectez-vous via `https://votresite.tld/admin/`.

Pré-requis : PHP **8.1+** avec `pdo_sqlite`, `openssl`, `curl`, `phar`. Tout est livré sur n'importe quel hébergement mutualisé standard.

---

## Trois types de collection

Greffe distingue trois intentions, choisies à la création (`kind`) :

| Kind        | Cardinalité    | UX sidebar                            | Usage typique                                |
|-------------|----------------|---------------------------------------|----------------------------------------------|
| `options`   | 1 record       | Entrée directe sous "Réglages"        | Header, footer, infos site, légal, contact   |
| `pages`     | N records      | Liste de pages sous "Pages"           | Accueil, à propos, services, contact (page)  |
| `list`      | N records      | Entrée vers liste sous "Contenu"      | Blog, portfolio, équipe, produits            |

Côté front, l'API est la même pour les trois — voir [Câbler le front](#câbler-le-front).

---

## Créer une collection

1. **Schéma > + Nouvelle collection** — label « Articles », slug auto = `articles` (ou tapez `blog`).
2. Choisis le **type** (Réglages / Pages / Liste).
3. Sur la page d'édition, ajoute tes champs.

### Exemple : collection `blog` (kind `list`)

| clé        | label          | type      | options                              |
|------------|----------------|-----------|--------------------------------------|
| `titre`    | Titre          | text      | —                                    |
| `contenu`  | Contenu        | wysiwyg   | —                                    |
| `image`    | Image          | media     | —                                    |
| `date`     | Date           | date      | —                                    |
| `auteur`   | Auteur         | relation  | cible : `auteurs`, multiple : non    |

### Exemple : collection `pages` (kind `pages`)

Avec un champ `sections` de type **blocks** (flexible content) :

| clé         | label    | type    | options                                |
|-------------|----------|---------|----------------------------------------|
| `titre`     | Titre    | text    | —                                      |
| `seo`       | SEO      | group   | sous-champs `meta_title`, `meta_desc`  |
| `sections`  | Sections | blocks  | voir « Type `blocks` » plus bas        |

Chaque record est une page (slug `accueil`, `a-propos`, `contact`, …). La sidebar les liste directement comme entrées cliquables.

### Exemple : singleton `general` (kind `options`)

`site_name`, `email_contact`, `couleur_accent` (type `color`)…

---

## Câbler le front

Un seul `require` à faire depuis n'importe quel fichier PHP de votre front :

```php
require __DIR__ . '/admin/lib/content.php';
```

### Lister

```php
$posts = content('blog')->all([
    'status' => 'published',   // défaut : 'published'. Mettre '*' pour tout voir.
    'order'  => '-date',       // '-champ' = desc, 'champ' = asc
    'limit'  => 10,
    'offset' => 0,
    'where'  => ['categorie' => 'actus'], // filtre simple sur champ JSON ou colonne
]);
```

### Trouver par slug (page détail)

```php
$slug = $_GET['slug'] ?? '';
$post = content('blog')->find($slug);
if (!$post) { http_response_code(404); exit('Article introuvable.'); }
```

Par défaut `find()` et `get()` **ne renvoient que les records `published`**.
Pour autoriser drafts/archived (preview admin) : `content('blog')->withDrafts()->find($slug)`.

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

### Singleton (kind `options`)

```php
$site = options('general');
echo htmlspecialchars($site['site_name'] ?? '');
```

### Médias

Le champ `media` stocke un chemin relatif au dossier `admin/` (ex. `uploads/2026/06/ab12cd.jpg`).
Pour l'afficher côté front, préfixez par l'URL du dossier `admin/` :

```php
echo '<img src="/admin/' . htmlspecialchars($post['image']) . '" alt="">';
```

### Routing

**C'est le front qui gère son routing.** Greffe ne touche pas aux URLs publiques.

```php
require __DIR__ . '/admin/lib/content.php';

$slug = $_GET['slug'] ?? null;
if ($slug) {
    $post = content('blog')->with('auteur')->find($slug);
} else {
    $posts = content('blog')->all(['order' => '-date']);
}
```

---

## Types de champ supportés

| type       | stockage `data` JSON                                       | UI admin                          |
|------------|------------------------------------------------------------|-----------------------------------|
| `text`     | string                                                     | input text                        |
| `longtext` | string                                                     | textarea                          |
| `wysiwyg`  | string HTML (Pell, sanitizé serveur-side)                  | éditeur Pell                      |
| `number`   | int / float / null                                         | input number                      |
| `boolean`  | true / false                                               | switch                            |
| `date`     | "YYYY-MM-DD"                                               | input date                        |
| `color`    | string hex (`#rgb` ou `#rrggbb`)                           | input color natif + readout       |
| `media`    | chemin relatif (`uploads/...`) ou ""                       | input file + preview              |
| `gallery`  | tableau `[{path, alt, title}]`                             | drop zone + drag réordonner       |
| `file`     | chemin relatif (PDF, doc, zip…)                            | input file                        |
| `select`   | string (une des options définies)                          | `<select>` peuplé                 |
| `relation` | id (int) ou tableau d'ids                                  | `<select>` (multi si configuré)   |
| `group`    | objet `{cle: valeur, …}`                                   | bloc avec sous-champs             |
| `repeater` | tableau d'objets `[{cle: val}, {cle: val}]`                | lignes ajoutables / réordonnables |
| `blocks`   | tableau `[{type: 'hero', data: {…}}, …]` (flexible content)| pile de blocs typés réordonnables |

### Sous-champs (`group` / `repeater`)

Format texte une ligne par sous-champ : `cle|Label|type`. Pour un select : `cle|Label|select:opt1,opt2,opt3`.
Types autorisés : `text, longtext, number, boolean, date, select`. **Pas de nesting**.

Exemple — horaires :

```
jour|Jour|select:Lun,Mar,Mer,Jeu,Ven,Sam,Dim
ouvre|Ouvre à|text
ferme|Ferme à|text
ouvert|Ouvert ?|boolean
```

Côté front :

```php
foreach (options('general')['horaires'] as $h) {
    if (!$h['ouvert']) { echo $h['jour'] . " : fermé\n"; continue; }
    echo "{$h['jour']} : {$h['ouvre']} – {$h['ferme']}\n";
}
```

### Type `blocks` (flexible content)

Permet d'empiler dans un même champ N **types** de blocs différents, chacun avec sa propre structure.
Le schéma se définit dans un textarea YAML-ish :

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

Le premier mot avant `|` est la **clé** du type de bloc (slug, snake_case). Le second mot après `|` est le **label**.
Les lignes indentées (espaces ou tab) sont les **sous-champs** du type, au format `cle|Label|type`.

Stockage dans le record : `[{type: 'hero', data: {titre: '...', sous_titre: '...'}}, {type: 'cta', data: {...}}]`.

Côté front, ton code décide comment rendre chaque bloc. Greffe **n'impose aucune structure HTML/CSS** :

```php
$page = content('pages')->find('accueil');
foreach ($page['sections'] as $section) {
    $type = $section['type'];
    $data = $section['data'];
    if     ($type === 'hero')    render_hero($data);
    elseif ($type === 'gallery') render_gallery($data);
    elseif ($type === 'cta')     render_cta($data);
    // les types inconnus sont silencieusement ignorés
}
```

Tu peux ajouter, renommer, supprimer des types de blocs sans impact côté serveur — c'est ton front qui décide ce qu'il fait avec.

---

## Bouton « Enregistrer + version +1 »

Convention : tout champ `number` nommé **exactement** `version`, à la racine d'un record OU à l'intérieur d'un `group`, fait apparaître un second bouton **« Enregistrer + réafficher (version +1) »**. Le serveur enregistre puis incrémente le compteur ciblé. Pratique pour invalider les cookies "déjà vu" côté visiteur sans avoir à retaper le numéro.

---

## Historique des records

Chaque modification d'un record snapshot l'état précédent dans `record_versions`. Les **10 versions les plus récentes** sont conservées (purge auto).
Le panneau « Historique » dans la sidebar du formulaire d'édition liste les versions, avec un bouton **Restaurer** par entrée. La restauration est elle-même versionnée → on peut toujours revenir en arrière tant qu'on n'a pas dépassé 10 sauvegardes.

---

## Servir un fichier sous une URL stable

Si tu veux un PDF accessible sous `/invitation.pdf` qui sert toujours la version active du singleton `invitation` :

```php
<?php
require __DIR__ . '/admin/lib/content.php';

$inv  = options('invitation');
$path = trim((string) ($inv['pdf'] ?? ''));
if ($path === '') { http_response_code(404); exit; }
$path = ltrim(str_replace('\\', '/', $path), '/');
if (str_contains($path, '..')) { http_response_code(403); exit; }
$abs = __DIR__ . '/admin/' . $path;
if (!is_file($abs)) { http_response_code(404); exit; }

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="invitation.pdf"');
readfile($abs);
```

Route `/invitation.pdf` → ce script via `.htaccess` ou ton routeur. Greffe ne pose **aucun fichier à la racine** — tu gardes le contrôle complet.

---

## Personnaliser l'installation

### Overrides locaux (`admin/config.local.php`)

Crée `admin/config.local.php` (gitignored, non écrasé par les updates). Format :

```php
<?php
// Autoriser l'install (une seule fois, à retirer après)
if (!defined('GREFFE_INSTALL_ALLOWED')) define('GREFFE_INSTALL_ALLOWED', true);

// Mode dev : install.php n'est pas auto-supprimé
if (!defined('GREFFE_KEEP_INSTALL'))    define('GREFFE_KEEP_INSTALL', true);

// Augmenter la limite d'upload (15 Mo)
if (!defined('GREFFE_UPLOAD_MAX'))      define('GREFFE_UPLOAD_MAX', 15 * 1024 * 1024);

// Pointer les updates vers un autre fork
if (!defined('GREFFE_GH_DEFAULT_OWNER')) define('GREFFE_GH_DEFAULT_OWNER', 'ton-org');
if (!defined('GREFFE_GH_DEFAULT_REPO'))  define('GREFFE_GH_DEFAULT_REPO',  'ton-fork-greffe');
```

### Mises à jour depuis GitHub

Page **Schéma > Mises à jour** : tire le dernier commit du repo configuré (par défaut [gc-guillaume/greffe](https://github.com/gc-guillaume/greffe)). Backup auto + apply + bouton **Rollback**. Repo public → aucun token requis (limite 60 req/h non-authentifié, suffisant). Repo privé ou volume → fine-grained PAT scopé read-only au seul repo, collé dans **Updates settings**. **Le token est chiffré au repos** (AES-256-GCM, clé locale dans `admin/data/.appkey`) ; un dump SQLite seul ne le révèle pas.

---

## Sécurité

- **Mots de passe** : `password_hash` / `password_verify` (bcrypt), constant-time login (dummy hash).
- **Brute-force** : 5 tentatives échouées en 15 min sur la même paire IP+username → lockout 15 min.
- **Auto-suppression d'`install.php`** après création du premier admin + gate `GREFFE_INSTALL_ALLOWED` requis.
- **Sessions** : cookie `HttpOnly` + `SameSite=Lax` + `Secure` auto en HTTPS, `session_regenerate_id` au login.
- **CSRF** : token requis sur tous les POST (login, logout, forgot, reset, user_*, collection_*, record_*, updates_*).
- **Échappement** : `htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE)` sur toute valeur DB/POST rendue.
- **Uploads** : extension dérivée du MIME validé via `finfo` (jamais du nom utilisateur, anti-polyglotte), SVG exclu (anti-XSS), `.htaccess` interdit l'exécution PHP dans `admin/uploads/`.
- **WYSIWYG** : sanitization stricte serveur-side (DOMDocument, whitelist tags + attrs + protocoles d'URL).
- **API publique read-only** : `content('blog')->find()` / `->get()` ne retournent que les records `published` par défaut (anti-fuite de drafts). Opt-in explicite via `->withDrafts()`.
- **PAT GitHub chiffré** au repos (AES-256-GCM, OpenSSL, clé locale 32 bytes hors-DB).
- **Update tarball** : Zip-Slip checks (rejet `..`/absolus/drive letters, skip symlinks, containment `realpath`).
- **Reset password** : token 128 bits, validité 5h, comparaison en timestamp UNIX (immune aux TZ), email en multipart text+HTML (URL en href intacte, pas de wrap MTA), URL construite depuis `_meta.public_url` capturée à l'install/login admin (jamais depuis `HTTP_HOST`).
- **Rôles** : `admin` (tout) et `moderator` (édit/delete sur records de collections `list`, jamais sur `options`/`pages` réglages, jamais sur le schéma).
- **content.php** : `users` jamais atteignable depuis le front.

---

## Notes

- **SQLite** est parfaite pour des sites vitrine, blog, portfolios. Pour de la forte écriture concurrente, prévoir autre chose.
- **Sauvegarde** = copier `admin/data/content.sqlite` (et `admin/uploads/`). C'est tout.
- **Renommer `/admin/`** : faisable, l'auto-détection de base URL suit. Mais ça casse l'auto-update from GitHub (le tarball contient `admin/`) — désactive l'update auto et fais-le en FTP / git pull.
- **Déploiement FTP** : envoie le dossier, vérifie que `admin/data/` et `admin/uploads/` sont écrivables, ouvre `install.php`.

---

## Ce que Greffe ne fait pas (par choix)

- Pas d'API HTTP. Le front lit la SQLite via `content.php`, pas via `fetch`.
- Pas de migrations / DDL dynamique pour les collections clients : le schéma est de la donnée, jamais du SQL.
- Pas de plugins, pas de hooks, pas d'ORM.
- Pas de dépendances externes, pas de build step.
