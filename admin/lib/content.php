<?php
declare(strict_types=1);

/**
 * Contrat public Greffe pour le FRONT (lecture seule).
 * Le front fait simplement :
 *     require __DIR__ . '/admin/lib/content.php';
 *     $posts = content('blog')->all();
 *     $post  = content('blog')->find($slug);
 *     $site  = options('general');
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

// Garantit que les tables existent (idempotent).
schema_install();

if (!class_exists('GreffeQuery')) {

    /**
     * Builder lecture seule pour une collection.
     */
    final class GreffeQuery
    {
        private string $collection;
        /** @var string[] */
        private array $with = [];
        /** @var string[] */
        private array $select = [];

        public function __construct(string $collection)
        {
            $this->collection = $collection;
        }

        /**
         * Demande la résolution d'un ou plusieurs champs relation.
         */
        public function with(string ...$fields): self
        {
            foreach ($fields as $f) {
                if ($f !== '' && !in_array($f, $this->with, true)) {
                    $this->with[] = $f;
                }
            }
            return $this;
        }

        /**
         * Ne lit que les champs JSON listés (via SQLite json_extract).
         * Économise le décodage du `data` complet pour les gros records.
         * Les colonnes natives (id, slug, status, sort, created_at, updated_at) sont toujours retournées.
         *
         * Exemple : content('blog')->select(['titre', 'date'])->all();
         */
        public function select(array $fields): self
        {
            foreach ($fields as $f) {
                if (!is_string($f)) continue;
                $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $f);
                if ($safe !== '' && !in_array($safe, $this->select, true)) {
                    $this->select[] = $safe;
                }
            }
            return $this;
        }

        /**
         * Liste les records de la collection.
         * Options : status, order ('-champ'/'champ'), limit, offset, where (array clé=>valeur).
         *
         * @return array<int,array<string,mixed>>
         */
        public function all(array $opts = []): array
        {
            $status = $opts['status'] ?? 'published';
            $limit  = isset($opts['limit'])  ? max(0, (int) $opts['limit'])  : 0;
            $offset = isset($opts['offset']) ? max(0, (int) $opts['offset']) : 0;
            $where  = is_array($opts['where'] ?? null) ? $opts['where'] : [];
            $order  = (string) ($opts['order'] ?? 'sort');

            $sql = 'SELECT ' . $this->compileSelect() . ' FROM records WHERE collection = :c';
            $params = [':c' => $this->collection];

            if ($status !== null && $status !== '*' && $status !== '') {
                $sql .= ' AND status = :s';
                $params[':s'] = $status;
            }

            $i = 0;
            foreach ($where as $key => $val) {
                if (!is_string($key) || $key === '') continue;
                $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
                if ($safe === '' || $safe === null) continue;
                $ph = ':w' . $i++;
                if (in_array($safe, ['id', 'slug', 'status'], true)) {
                    $sql .= " AND $safe = $ph";
                } else {
                    $sql .= " AND json_extract(data, '$.$safe') = $ph";
                }
                $params[$ph] = $val;
            }

            $sql .= ' ORDER BY ' . $this->compileOrder($order);
            if ($limit > 0) {
                $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
            }

            try {
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
            } catch (Throwable $e) {
                return [];
            }

            $out = [];
            foreach ($rows as $r) {
                $out[] = $this->hydrate($r);
            }
            $this->resolveRelations($out);
            return $out;
        }

        /**
         * Récupère un record par slug.
         */
        public function find(string $slug): ?array
        {
            try {
                $stmt = db()->prepare(
                    'SELECT ' . $this->compileSelect() . ' FROM records WHERE collection = :c AND slug = :s LIMIT 1'
                );
                $stmt->execute([':c' => $this->collection, ':s' => $slug]);
                $row = $stmt->fetch();
            } catch (Throwable $e) {
                return null;
            }
            if (!$row) return null;
            $rec = $this->hydrate($row);
            $arr = [$rec];
            $this->resolveRelations($arr);
            return $arr[0];
        }

        /**
         * Récupère un record par id.
         */
        public function get(int $id): ?array
        {
            try {
                $stmt = db()->prepare(
                    'SELECT ' . $this->compileSelect() . ' FROM records WHERE collection = :c AND id = :id LIMIT 1'
                );
                $stmt->execute([':c' => $this->collection, ':id' => $id]);
                $row = $stmt->fetch();
            } catch (Throwable $e) {
                return null;
            }
            if (!$row) return null;
            $rec = $this->hydrate($row);
            $arr = [$rec];
            $this->resolveRelations($arr);
            return $arr[0];
        }

        /**
         * Compile la liste de colonnes du SELECT en respectant le projection set par select().
         */
        private function compileSelect(): string
        {
            if (!$this->select) return '*';
            $core = ['id', 'slug', 'status', 'sort', 'created_at', 'updated_at'];
            $cols = $core;
            foreach ($this->select as $k) {
                // $k est déjà sanitizé par select() (whitelist [a-zA-Z0-9_-]).
                $cols[] = "json_extract(data, '$.$k') AS \"$k\"";
            }
            return implode(', ', $cols);
        }

        /**
         * Aplatit la ligne SQL (data JSON déplié à la racine).
         * En mode select(), on n'a plus la colonne `data` mais directement les champs extraits.
         */
        private function hydrate(array $row): array
        {
            if ($this->select) {
                // SQL a déjà projeté les champs demandés via json_extract.
                // SQLite renvoie cependant des chaînes JSON pour les valeurs imbriquées (array/object).
                // On les décode si elles ressemblent à du JSON pour rester cohérent côté front.
                $out = [
                    'id'         => (int) $row['id'],
                    'slug'       => $row['slug'],
                    'status'     => $row['status'],
                    'sort'       => (int) $row['sort'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
                foreach ($this->select as $k) {
                    $v = $row[$k] ?? null;
                    if (is_string($v) && $v !== '' && ($v[0] === '{' || $v[0] === '[')) {
                        $decoded = json_decode($v, true);
                        if (is_array($decoded)) $v = $decoded;
                    }
                    $out[$k] = $v;
                }
                return $out;
            }

            $data = [];
            if (!empty($row['data'])) {
                $decoded = json_decode((string) $row['data'], true);
                if (is_array($decoded)) $data = $decoded;
            }
            return array_merge($data, [
                'id'         => (int) $row['id'],
                'slug'       => $row['slug'],
                'status'     => $row['status'],
                'sort'       => (int) $row['sort'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }

        /**
         * Compile une clause ORDER BY sûre.
         * 'champ' -> ASC, '-champ' -> DESC. Les colonnes natives sont privilégiées,
         * sinon on tape dans le JSON.
         */
        private function compileOrder(string $order): string
        {
            $dir = 'ASC';
            if ($order !== '' && $order[0] === '-') {
                $dir = 'DESC';
                $order = substr($order, 1);
            }
            $order = trim($order);
            if ($order === '') $order = 'sort';
            $native = ['id', 'slug', 'status', 'sort', 'created_at', 'updated_at'];
            if (in_array($order, $native, true)) {
                return $order . ' ' . $dir;
            }
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $order);
            if ($safe === null || $safe === '') $safe = 'sort';
            return "json_extract(data, '$.$safe') $dir";
        }

        /**
         * Résout les relations demandées via ->with().
         * Charge en bloc les records cibles puis les attache.
         *
         * @param array<int,array<string,mixed>> $records
         */
        private function resolveRelations(array &$records): void
        {
            if (!$this->with || !$records) return;

            // Charge la définition de fields de cette collection.
            try {
                $stmt = db()->prepare(<<<SQL
                    SELECT f.key, f.type, f.options
                    FROM fields f
                    JOIN collections c ON c.id = f.collection_id
                    WHERE c.slug = :c
                SQL);
                $stmt->execute([':c' => $this->collection]);
                $fields = $stmt->fetchAll();
            } catch (Throwable $e) {
                return;
            }

            $relations = [];
            foreach ($fields as $f) {
                if ($f['type'] !== 'relation') continue;
                if (!in_array($f['key'], $this->with, true)) continue;
                $opts = json_decode((string) ($f['options'] ?? ''), true);
                $target = is_array($opts) ? ($opts['target'] ?? '') : '';
                $multi  = is_array($opts) ? !empty($opts['multiple']) : false;
                if (!is_string($target) || $target === '') continue;
                $relations[$f['key']] = ['target' => $target, 'multiple' => $multi];
            }
            if (!$relations) return;

            // Collecte tous les ids référencés par cible.
            $idsByTarget = [];
            foreach ($records as $rec) {
                foreach ($relations as $key => $info) {
                    if (!array_key_exists($key, $rec)) continue;
                    $v = $rec[$key];
                    $ids = is_array($v) ? $v : ($v !== null && $v !== '' ? [$v] : []);
                    foreach ($ids as $id) {
                        if ($id === '' || $id === null) continue;
                        $idsByTarget[$info['target']][] = (int) $id;
                    }
                }
            }

            // Charge tous les records cibles en une requête par cible.
            $cache = [];
            foreach ($idsByTarget as $target => $ids) {
                $ids = array_values(array_unique(array_filter($ids, fn($x) => $x > 0)));
                if (!$ids) continue;
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $sql = "SELECT * FROM records WHERE collection = ? AND id IN ($ph)";
                $params = array_merge([$target], $ids);
                try {
                    $stmt = db()->prepare($sql);
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll() as $row) {
                        $cache[$target][(int) $row['id']] = $this->hydrate($row);
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }

            // Attache.
            foreach ($records as &$rec) {
                foreach ($relations as $key => $info) {
                    if (!array_key_exists($key, $rec)) {
                        $rec[$key] = $info['multiple'] ? [] : null;
                        continue;
                    }
                    $v = $rec[$key];
                    if ($info['multiple']) {
                        $ids = is_array($v) ? $v : [];
                        $list = [];
                        foreach ($ids as $id) {
                            $id = (int) $id;
                            if (isset($cache[$info['target']][$id])) {
                                $list[] = $cache[$info['target']][$id];
                            }
                        }
                        $rec[$key] = $list;
                    } else {
                        $id = is_array($v) ? (int) ($v[0] ?? 0) : (int) $v;
                        $rec[$key] = $cache[$info['target']][$id] ?? null;
                    }
                }
            }
        }
    }
}

if (!function_exists('content')) {
    /**
     * Point d'entrée lecture pour une collection.
     */
    function content(string $collection): GreffeQuery
    {
        return new GreffeQuery($collection);
    }
}

if (!function_exists('options')) {
    /**
     * Récupère le contenu d'un singleton (page de réglages).
     * Renvoie un tableau (vide si singleton absent ou non rempli).
     *
     * @return array<string,mixed>
     */
    function options(string $singleton): array
    {
        try {
            $stmt = db()->prepare(<<<SQL
                SELECT r.*
                FROM records r
                JOIN collections c ON c.slug = r.collection
                WHERE c.slug = :s AND c.is_singleton = 1
                ORDER BY r.id ASC LIMIT 1
            SQL);
            $stmt->execute([':s' => $singleton]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return [];
        }
        if (!$row) return [];

        $data = json_decode((string) $row['data'], true);
        $data = is_array($data) ? $data : [];
        return array_merge($data, [
            'id'         => (int) $row['id'],
            'status'     => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }
}
