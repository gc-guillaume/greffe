<?php
/** @var ?array $collection */
/** @var array $fields */
/** @var array $collections */
$isNew = $collection === null;
$fieldTypes = ['text', 'longtext', 'wysiwyg', 'number', 'boolean', 'date', 'color', 'media', 'gallery', 'file', 'select', 'relation', 'group', 'repeater'];
$subFieldTypes = ['text', 'longtext', 'number', 'boolean', 'date', 'select'];

/**
 * Rend les blocs d'options dynamiques selon le type.
 * Le bloc visible est piloté par le data-field-type au-dessus.
 */
$renderOptionsBlocks = function (array $opts, array $collectionsAll, ?int $currentColId) use ($subFieldTypes): void {
    $target = (string) ($opts['target'] ?? '');
    $multi  = !empty($opts['multiple']);
    $choices = $opts['choices'] ?? [];
    $subfields = is_array($opts['subfields'] ?? null) ? $opts['subfields'] : [];
    ?>
    <div class="field-options-block" data-field-options-select>
        <label>Choix possibles <small class="muted">(une option par ligne)</small>
            <textarea name="options_choices" rows="3"><?= e(implode("\n", $choices)) ?></textarea>
        </label>
    </div>

    <div class="field-options-block" data-field-options-relation>
        <div class="grid-2">
            <label>Collection cible
                <select name="options_target">
                    <option value="">— choisir —</option>
                    <?php foreach ($collectionsAll as $cc): if ($currentColId !== null && (int) $cc['id'] === $currentColId) continue; ?>
                        <option value="<?= e($cc['slug']) ?>" <?= $target === $cc['slug'] ? 'selected' : '' ?>><?= e($cc['label']) ?> (<?= e($cc['slug']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="checkbox">
                <input type="checkbox" name="options_multiple" value="1" <?= $multi ? 'checked' : '' ?>>
                Multiple (plusieurs ids)
            </label>
        </div>
    </div>

    <div class="field-options-block" data-field-options-subfields>
        <p class="muted small">Définis les sous-champs. Glisse pour réordonner. Pas de groupe ni de répéteur imbriqué.</p>
        <div class="subfields-builder" data-subfields-builder>
            <div class="subfields-rows" data-sortable-subfields>
                <?php foreach ($subfields as $i => $sf):
                    $sfType = (string) ($sf['type'] ?? 'text');
                    $sfChoices = is_array($sf['options']['choices'] ?? null) ? $sf['options']['choices'] : [];
                ?>
                    <div class="subfield-row" data-row>
                        <span class="drag-handle" title="Glisser pour réordonner">≡</span>
                        <input type="text" name="options_subfields[<?= (int) $i ?>][key]" value="<?= e($sf['key'] ?? '') ?>" placeholder="cle" required pattern="[a-z0-9_]+">
                        <input type="text" name="options_subfields[<?= (int) $i ?>][label]" value="<?= e($sf['label'] ?? '') ?>" placeholder="Label">
                        <select name="options_subfields[<?= (int) $i ?>][type]" data-subfield-type>
                            <?php foreach ($subFieldTypes as $t): ?>
                                <option value="<?= e($t) ?>" <?= $t === $sfType ? 'selected' : '' ?>><?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="options_subfields[<?= (int) $i ?>][choices]" value="<?= e(implode(',', $sfChoices)) ?>" placeholder="opt1,opt2,opt3" data-subfield-choices <?= $sfType !== 'select' ? 'hidden' : '' ?>>
                        <button type="button" class="link danger row-rm" data-row-remove title="Supprimer">✕</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template data-subfield-template>
                <div class="subfield-row" data-row>
                    <span class="drag-handle" title="Glisser pour réordonner">≡</span>
                    <input type="text" name="options_subfields[__INDEX__][key]" placeholder="cle" required pattern="[a-z0-9_]+">
                    <input type="text" name="options_subfields[__INDEX__][label]" placeholder="Label">
                    <select name="options_subfields[__INDEX__][type]" data-subfield-type>
                        <?php foreach ($subFieldTypes as $t): ?>
                            <option value="<?= e($t) ?>"><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="options_subfields[__INDEX__][choices]" placeholder="opt1,opt2,opt3" data-subfield-choices hidden>
                    <button type="button" class="link danger row-rm" data-row-remove title="Supprimer">✕</button>
                </div>
            </template>
            <button type="button" class="btn" data-subfield-add>+ Sous-champ</button>
        </div>
    </div>
    <?php
};
?>

<div class="page-head">
    <h1><?= $isNew ? 'Nouvelle collection' : 'Schéma : ' . e($collection['label']) ?></h1>
    <a href="<?= e(url('index.php?p=collections')) ?>" class="btn">&larr; Toutes les collections</a>
</div>

<?php if ($isNew): ?>

    <form method="post" class="card">
        <?= csrf_field() ?>
        <label>Label
            <input type="text" name="label" required autofocus>
        </label>
        <label>Slug <small class="muted">(laisser vide pour générer depuis le label)</small>
            <input type="text" name="slug" pattern="[a-z0-9\-]+">
        </label>
        <label class="checkbox">
            <input type="checkbox" name="is_singleton" value="1">
            Singleton (un seul record — utile pour une page d'accueil, des réglages, un footer…)
        </label>
        <button type="submit" class="primary">Créer</button>
    </form>

<?php else: ?>

    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_collection">
        <div class="grid-2">
            <label>Label
                <input type="text" name="label" value="<?= e($collection['label']) ?>" required>
            </label>
            <label>Slug
                <input type="text" value="<?= e($collection['slug']) ?>" disabled>
            </label>
        </div>
        <label class="checkbox">
            <input type="checkbox" name="is_singleton" value="1" <?= $collection['is_singleton'] ? 'checked' : '' ?>>
            Singleton
        </label>
        <button type="submit">Enregistrer la collection</button>
    </form>

    <h2>Champs</h2>

    <?php if (!$fields): ?>
        <p class="muted">Aucun champ. Ajoute-en un ci-dessous.</p>
    <?php else: ?>
    <div class="schema-fields" data-sortable-fields data-collection-id="<?= (int) $collection['id'] ?>">
        <?php foreach ($fields as $f): $opts = json_decode_array($f['options']); ?>
            <article class="schema-field" data-id="<?= (int) $f['id'] ?>">
                <header class="schema-field-head">
                    <span class="drag-handle" title="Glisser pour réordonner">≡</span>
                    <code class="schema-field-key"><?= e($f['key']) ?></code>
                    <span class="muted small">type : <?= e($f['type']) ?></span>
                    <div class="schema-field-actions">
                        <form method="post" class="inline" data-confirm="Supprimer ce champ ? Les valeurs existantes seront orphelines.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_field">
                            <input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>">
                            <button type="submit" class="link danger">Supprimer</button>
                        </form>
                    </div>
                </header>
                <form method="post" class="schema-field-form" data-field-form data-no-hijax>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_field">
                    <input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>">
                    <div class="grid-2">
                        <label>Label
                            <input type="text" name="label" value="<?= e($f['label']) ?>" required>
                        </label>
                        <label>Type
                            <select name="type" data-field-type>
                                <?php foreach ($fieldTypes as $t): ?>
                                    <option value="<?= e($t) ?>" <?= $t === $f['type'] ? 'selected' : '' ?>><?= e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <?php $renderOptionsBlocks($opts, $collections, (int) $collection['id']); ?>
                    <button type="submit">Enregistrer</button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h3>Ajouter un champ</h3>
    <form method="post" class="card add-field" data-add-field data-field-form data-no-hijax>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_field">
        <div class="grid-3">
            <label>Clé <small class="muted">(slug, autorise <code>_</code>)</small>
                <input type="text" name="key" required pattern="[a-z0-9_\-]+">
            </label>
            <label>Label
                <input type="text" name="label" required>
            </label>
            <label>Type
                <select name="type" data-field-type>
                    <?php foreach ($fieldTypes as $t): ?>
                        <option value="<?= e($t) ?>"><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <?php $renderOptionsBlocks([], $collections, (int) $collection['id']); ?>
        <button type="submit" class="primary">Ajouter le champ</button>
    </form>

<?php endif; ?>
