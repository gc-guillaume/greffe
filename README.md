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

## URL publique `/invitation.pdf`

Le singleton `invitation` (champ `pdf` de type `file`) peut être servi sous une URL "propre" `/invitation.pdf`.

Deux options selon ton setup :

**A. Tu veux l'URL `/invitation.pdf`** → ajoute UNE ligne à ton `.htaccess` racine :
```apache
RewriteRule ^invitation\.pdf$ invitation.php [L]
```
(à placer AVANT ton catch-all si tu en as un). Le snippet de base est dans `.htaccess.greffe-snippet`.

**B. Tu veux pas toucher ton `.htaccess`** → utilise directement `/invitation.php`. Le contenu est exactement le même (Content-Type PDF, stream inline). L'URL est juste moins jolie.

> ℹ️ Greffe ne pose plus de `.htaccess` à la racine par défaut (pour ne pas écraser celui de ton site).

Le lien public reste donc inchangé même quand l'admin uploade un nouveau PDF. Les anciennes versions restent sur disque dans `admin/uploads/`, et l'historique 5 versions de Greffe permet de **restaurer** un ancien PDF en un clic depuis la sidebar du record.

**Test local avec le serveur intégré PHP** : utilise le routeur fourni pour que `/invitation.pdf` fonctionne hors Apache (option A) :

```bash
php -S 127.0.0.1:7500 _router.php
```

## Historique des records

Chaque modification d'un record snapshot automatiquement l'état précédent dans la table `record_versions`.
Les **5 versions les plus récentes** sont conservées par record (purge auto).
Le panneau « Historique » dans la sidebar du formulaire d'édition liste les versions disponibles, avec un bouton **Restaurer** par version.
La restauration est elle-même versionnée : l'état courant est snapshoté avant d'être écrasé, donc on peut toujours revenir en arrière tant qu'on n'a pas dépassé 5 sauvegardes.

## Sécurité

- Mots de passe : `password_hash` / `password_verify`.
- Sessions : cookie `HttpOnly`, `SameSite=Lax`, id régénéré au login.
- Token CSRF requis sur tous les POST de l'admin.
- Échappement systématique en sortie (`htmlspecialchars`).
- Uploads : whitelist MIME, taille max (5 Mo par défaut, modifiable dans `admin/config.php`).
- `admin/data/` est bloqué par `.htaccess` (la base SQLite n'est jamais téléchargeable).
- `admin/uploads/` interdit l'exécution PHP.
- `content.php` est **strictement en lecture**.

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
