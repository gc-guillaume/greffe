<?php
/** @var ?array $collection */
/** @var array $fields */
/** @var array $collections */
$isNew = $collection === null;
$fieldTypes = ['text', 'longtext', 'wysiwyg', 'number', 'boolean', 'date', 'color', 'media', 'gallery', 'file', 'select', 'relation', 'group', 'repeater', 'blocks'];
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
    $blockTypes = is_array($opts['block_types'] ?? null) ? $opts['block_types'] : [];
    $blockTypesText = block_types_serialize($blockTypes);
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

    <div class="field-options-block" data-field-options-blocks>
        <p class="muted small">
            Un type de bloc par section. Chaque type liste ses sous-champs (indentés).
            L'éditeur de record permet ensuite d'empiler N blocs de N types différents et de les réordonner.
        </p>
        <label>Types de blocs
            <textarea name="options_block_types_text" rows="10" spellcheck="false" style="font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;" placeholder="hero | Hero
  titre|Titre|text
  sous_titre|Sous-titre|longtext

gallery | Galerie
  caption|Légende|text"><?= e($blockTypesText) ?></textarea>
        </label>
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
        <fieldset class="kind-picker">
            <legend>Type de collection</legend>
            <label class="radio">
                <input type="radio" name="kind" value="list" checked>
                <strong>Liste</strong> <span class="muted small">— plusieurs records homogènes (blog, portfolio, équipe…)</span>
            </label>
            <label class="radio">
                <input type="radio" name="kind" value="pages">
                <strong>Pages</strong> <span class="muted small">— une liste de pages, chacune avec son contenu (accueil, à propos, contact…)</span>
            </label>
            <label class="radio">
                <input type="radio" name="kind" value="options">
                <strong>Réglages</strong> <span class="muted small">— un seul record (header, footer, infos site, légal…)</span>
            </label>
        </fieldset>
        <button type="submit" class="primary">Créer</button>
    </form>

<?php else: ?>

    <?php
        // Rétro-compat : si la migration n'a pas encore tourné, dérive depuis is_singleton.
        $currentKind = (string) ($collection['kind'] ?? '');
        if ($currentKind === '') {
            $currentKind = !empty($collection['is_singleton']) ? 'options' : 'list';
        }
        $kindLabels = ['options' => 'Réglages', 'pages' => 'Pages', 'list' => 'Contenu'];
    ?>
    <form method="post" class="card collection-meta-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_collection">
        <!-- kind reposté tel quel : empêche le serveur de retomber sur 'list' par défaut.
             L'utilisateur ne peut pas le changer (impact destructif si list <-> options). -->
        <input type="hidden" name="kind" value="<?= e($currentKind) ?>">

        <div class="collection-meta-readonly">
            <div>
                <span class="muted small">Type</span>
                <strong><?= e($kindLabels[$currentKind] ?? $currentKind) ?></strong>
            </div>
            <div>
                <span class="muted small">Slug</span>
                <code class="slug-code"><?= e($collection['slug']) ?></code>
            </div>
        </div>

        <label>Label <small class="muted">(affichage dans l'admin et la sidebar, peut être renommé librement)</small>
            <input type="text" name="label" value="<?= e($collection['label']) ?>" required>
        </label>

        <button type="submit">Enregistrer le label</button>
        <p class="muted small">
            Le <strong>slug</strong> et le <strong>type</strong> sont fixés à la création — le front en dépend.
            Pour les changer, crée une nouvelle collection et migre tes records.
        </p>
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
