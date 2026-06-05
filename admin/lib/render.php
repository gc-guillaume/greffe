<?php
declare(strict_types=1);

/**
 * Rendu HTML d'un champ et d'un sous-champ.
 * Mutualisé entre record_edit.php (page d'édition d'un record) et dashboard.php
 * (plusieurs singletons rendus inline sur la même page).
 *
 * Le préfixe d'id ($ctx['id_prefix']) permet d'éviter les collisions <label for=...>
 * quand plusieurs formulaires coexistent sur la même page (dashboard).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/records.php';
require_once __DIR__ . '/collections.php';

/**
 * Rend un sous-champ (à l'intérieur d'un group / repeater).
 * $namePrefix est déjà calculé : ex "horaires[0]" ou "enfant".
 */
function greffe_render_subfield(string $namePrefix, array $sf, mixed $val): void
{
    $name = $namePrefix . '[' . $sf['key'] . ']';
    $type = $sf['type'];
    $opts = is_array($sf['options'] ?? null) ? $sf['options'] : [];
    ?>
    <label class="sub-field sub-field-<?= e($type) ?>">
        <span class="sub-field-label"><?= e($sf['label']) ?></span>
        <?php if ($type === 'text'): ?>
            <input type="text" name="<?= e($name) ?>" value="<?= e((string) $val) ?>">
        <?php elseif ($type === 'longtext'): ?>
            <textarea name="<?= e($name) ?>" rows="3"><?= e((string) $val) ?></textarea>
        <?php elseif ($type === 'number'): ?>
            <input type="number" step="any" name="<?= e($name) ?>" value="<?= e($val === null ? '' : (string) $val) ?>">
        <?php elseif ($type === 'date'): ?>
            <input type="date" name="<?= e($name) ?>" value="<?= e((string) $val) ?>">
        <?php elseif ($type === 'boolean'): ?>
            <span class="switch">
                <input type="checkbox" name="<?= e($name) ?>" value="1" <?= !empty($val) ? 'checked' : '' ?>>
                <span class="switch-track"></span>
            </span>
        <?php elseif ($type === 'select'): ?>
            <select name="<?= e($name) ?>">
                <option value="">—</option>
                <?php foreach (($opts['choices'] ?? []) as $c): ?>
                    <option value="<?= e($c) ?>" <?= ((string) $val) === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </label>
    <?php
}

/**
 * Rend un champ racine.
 * $ctx peut contenir :
 *  - 'collection'   : la collection courante (pour exclure dans le select target d'une relation)
 *  - 'collections'  : la liste de toutes les collections (relation)
 *  - 'id_prefix'    : préfixe pour les attributs id (évite collisions multi-forms)
 */
function greffe_render_field(array $f, mixed $val, array $opts, array $ctx = []): void
{
    $key      = $f['key'];
    $idPrefix = (string) ($ctx['id_prefix'] ?? '');
    $fieldId  = 'f_' . $idPrefix . $key;
    // group / repeater n'ont pas d'input cible : on rend un span pour éviter
    // le warning "label for doesn't match any element id".
    $isContainer = in_array($f['type'], ['group', 'repeater', 'blocks'], true);
    ?>
    <div class="field field-<?= e($f['type']) ?>">
        <?php if ($isContainer): ?>
            <span class="field-label"><?= e($f['label']) ?> <small class="muted">(<?= e($f['type']) ?>)</small></span>
        <?php else: ?>
            <label class="field-label" for="<?= e($fieldId) ?>"><?= e($f['label']) ?> <small class="muted">(<?= e($f['type']) ?>)</small></label>
        <?php endif; ?>

        <?php if ($f['type'] === 'text'): ?>
            <input type="text" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="<?= e((string) $val) ?>">

        <?php elseif ($f['type'] === 'longtext'): ?>
            <textarea id="<?= e($fieldId) ?>" name="<?= e($key) ?>" rows="6"><?= e((string) $val) ?></textarea>

        <?php elseif ($f['type'] === 'wysiwyg'): ?>
            <div class="wysiwyg" data-wysiwyg>
                <input type="hidden" name="<?= e($key) ?>" class="wysiwyg-value" value="<?= e((string) $val) ?>">
                <div class="wysiwyg-editor pell"></div>
            </div>

        <?php elseif ($f['type'] === 'number'): ?>
            <input type="number" step="any" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="<?= e($val === null ? '' : (string) $val) ?>">
            <?php if ($key === 'version'): ?>
                <button type="submit" name="_bump" value="<?= e($key) ?>" class="btn bump-btn">
                    Enregistrer + réafficher (version +1)
                </button>
            <?php endif; ?>

        <?php elseif ($f['type'] === 'boolean'): ?>
            <input class="switch-input" type="checkbox" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="1" <?= !empty($val) ? 'checked' : '' ?>>
            <label class="switch-track" for="<?= e($fieldId) ?>" aria-label="<?= e($f['label']) ?>"></label>

        <?php elseif ($f['type'] === 'date'): ?>
            <input type="date" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="<?= e((string) $val) ?>">

        <?php elseif ($f['type'] === 'select'): ?>
            <select id="<?= e($fieldId) ?>" name="<?= e($key) ?>">
                <option value="">—</option>
                <?php foreach (($opts['choices'] ?? []) as $c): ?>
                    <option value="<?= e($c) ?>" <?= ((string) $val) === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>

        <?php elseif ($f['type'] === 'media'): ?>
            <?php if ($val): ?>
                <div class="media-preview media-preview-image">
                    <img src="<?= e(GREFFE_BASE_URL . '/' . ltrim((string) $val, '/')) ?>" alt="">
                    <div class="media-preview-info">
                        <a href="<?= e(GREFFE_BASE_URL . '/' . ltrim((string) $val, '/')) ?>" target="_blank"><?= e((string) $val) ?></a>
                        <label class="checkbox small">
                            <input type="checkbox" name="<?= e($key) ?>__clear" value="1">
                            Supprimer
                        </label>
                    </div>
                </div>
            <?php endif; ?>
            <input type="file" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" accept="image/*">

        <?php elseif ($f['type'] === 'file'): ?>
            <?php if ($val): ?>
                <div class="media-preview">
                    <a href="<?= e(GREFFE_BASE_URL . '/' . ltrim((string) $val, '/')) ?>" target="_blank">📄 <?= e((string) $val) ?></a>
                    <label class="checkbox small">
                        <input type="checkbox" name="<?= e($key) ?>__clear" value="1">
                        Supprimer
                    </label>
                </div>
            <?php endif; ?>
            <input type="file" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.txt,.csv">

        <?php elseif ($f['type'] === 'gallery'):
            $items = greffe_gallery_normalize($val);
        ?>
            <div class="gallery" data-gallery>
                <?php if ($items): ?>
                    <div class="gallery-grid" data-sortable-gallery>
                        <?php foreach ($items as $item):
                            $url = GREFFE_BASE_URL . '/' . ltrim($item['path'], '/');
                        ?>
                            <div class="gallery-item" data-row>
                                <span class="drag-handle gallery-handle" title="Glisser pour réordonner">≡</span>
                                <img src="<?= e($url) ?>" alt="<?= e($item['alt']) ?>" loading="lazy">
                                <input type="hidden" name="<?= e($key) ?>__order[]" value="<?= e($item['path']) ?>">
                                <label class="gallery-rm" title="Supprimer">
                                    <input type="checkbox" name="<?= e($key) ?>__remove[]" value="<?= e($item['path']) ?>">
                                    <span>✕</span>
                                </label>
                                <div class="gallery-meta-row">
                                    <input type="text" class="gallery-meta" name="<?= e($key) ?>__alt[]"   value="<?= e($item['alt']) ?>"   placeholder="alt (SEO + accessibilité)">
                                    <input type="text" class="gallery-meta" name="<?= e($key) ?>__title[]" value="<?= e($item['title']) ?>" placeholder="title (infobulle)">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="gallery-grid empty" data-sortable-gallery><span class="muted small">Aucune image pour l'instant.</span></div>
                <?php endif; ?>

                <label class="gallery-drop" data-gallery-drop>
                    <input type="file" id="<?= e($fieldId) ?>" name="<?= e($key) ?>[]" multiple accept="image/*" data-gallery-input>
                    <span class="gallery-drop-icon" aria-hidden="true">⬆</span>
                    <span class="gallery-drop-text">
                        <span class="gallery-drop-main">Glisse des images ici, ou clique pour parcourir</span>
                        <span class="gallery-drop-sub">JPG · PNG · WEBP · AVIF · GIF · SVG</span>
                    </span>
                </label>

                <div class="gallery-pending-grid" data-gallery-pending-grid></div>

                <template data-gallery-pending-template>
                    <div class="gallery-item gallery-pending" data-row data-pending>
                        <span class="gallery-pending-badge">à uploader</span>
                        <img src="" alt="">
                        <button type="button" class="gallery-rm" data-pending-remove title="Retirer">✕</button>
                        <div class="gallery-meta-row">
                            <input type="text" class="gallery-meta" name="<?= e($key) ?>__new_alt[]"   data-pending-alt   placeholder="alt (SEO + accessibilité)">
                            <input type="text" class="gallery-meta" name="<?= e($key) ?>__new_title[]" data-pending-title placeholder="title (infobulle)">
                        </div>
                    </div>
                </template>
            </div>

        <?php elseif ($f['type'] === 'color'):
            $hex = is_string($val) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $val) ? $val : '';
        ?>
            <span class="color-field">
                <input type="color" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="<?= e($hex !== '' ? $hex : '#000000') ?>">
                <code class="color-val" data-color-readout><?= e($hex !== '' ? $hex : '#000000') ?></code>
            </span>

        <?php elseif ($f['type'] === 'relation'):
            $target  = (string) ($opts['target'] ?? '');
            $multi   = !empty($opts['multiple']);
            $choices = [];
            $titleKey = null;
            if ($target !== '') {
                $choices = records_for($target);
                $targetCol = collection_find_by_slug($target);
                if ($targetCol) {
                    foreach (fields_for_collection((int) $targetCol['id']) as $tf) {
                        if ($tf['type'] === 'text') { $titleKey = $tf['key']; break; }
                    }
                }
            }
            $selected = $multi ? (is_array($val) ? array_map('intval', $val) : []) : [(int) $val];
        ?>
            <?php if ($target === ''): ?>
                <p class="alert">Aucune cible configurée pour ce champ relation.</p>
            <?php else: ?>
                <select id="<?= e($fieldId) ?>" name="<?= e($key) ?><?= $multi ? '[]' : '' ?>" <?= $multi ? 'multiple size="6"' : '' ?>>
                    <?php if (!$multi): ?><option value="">—</option><?php endif; ?>
                    <?php foreach ($choices as $ch): $cd = json_decode_array($ch['data']);
                        $label = $titleKey ? (string) ($cd[$titleKey] ?? '') : '';
                        if ($label === '') $label = (string) ($ch['slug'] ?? '#' . $ch['id']);
                    ?>
                        <option value="<?= (int) $ch['id'] ?>" <?= in_array((int) $ch['id'], $selected, true) ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

        <?php elseif ($f['type'] === 'group'):
            $sub  = is_array($opts['subfields'] ?? null) ? $opts['subfields'] : [];
            $vals = is_array($val) ? $val : [];
            $bumpPath = null;
            foreach ($sub as $sf) {
                if ($sf['type'] === 'number' && $sf['key'] === 'version') {
                    $bumpPath = $key . '.' . $sf['key'];
                    break;
                }
            }
        ?>
            <?php if (!$sub): ?>
                <p class="alert">Aucun sous-champ défini pour ce groupe.</p>
            <?php else: ?>
                <div class="group">
                    <?php foreach ($sub as $sf): greffe_render_subfield($key, $sf, $vals[$sf['key']] ?? null); endforeach; ?>
                    <?php if ($bumpPath !== null): ?>
                        <button type="submit" name="_bump" value="<?= e($bumpPath) ?>" class="btn bump-btn">
                            Enregistrer + réafficher (version +1)
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($f['type'] === 'repeater'):
            $sub  = is_array($opts['subfields'] ?? null) ? $opts['subfields'] : [];
            $rows = is_array($val) ? array_values($val) : [];
        ?>
            <?php if (!$sub): ?>
                <p class="alert">Aucun sous-champ défini pour ce répéteur.</p>
            <?php else: ?>
                <div class="repeater" data-repeater>
                    <div class="repeater-rows" data-sortable-repeater>
                        <?php foreach ($rows as $i => $row): $rd = is_array($row) ? $row : []; ?>
                            <fieldset class="repeater-row" data-row>
                                <input type="hidden" name="<?= e($key) ?>[<?= (int) $i ?>][__present]" value="1">
                                <span class="drag-handle" title="Glisser pour réordonner">≡</span>
                                <div class="repeater-row-body">
                                    <?php foreach ($sub as $sf): greffe_render_subfield($key . '[' . (int) $i . ']', $sf, $rd[$sf['key']] ?? null); endforeach; ?>
                                </div>
                                <button type="button" class="link danger" data-row-remove>Supprimer cette ligne</button>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <template data-row-template>
                        <fieldset class="repeater-row" data-row>
                            <input type="hidden" name="<?= e($key) ?>[__INDEX__][__present]" value="1">
                            <span class="drag-handle" title="Glisser pour réordonner">≡</span>
                            <div class="repeater-row-body">
                                <?php foreach ($sub as $sf): greffe_render_subfield($key . '[__INDEX__]', $sf, null); endforeach; ?>
                            </div>
                            <button type="button" class="link danger" data-row-remove>Supprimer cette ligne</button>
                        </fieldset>
                    </template>
                    <button type="button" class="btn" data-row-add>+ Ajouter une ligne</button>
                </div>
            <?php endif; ?>

        <?php elseif ($f['type'] === 'blocks'):
            $blockTypes = is_array($opts['block_types'] ?? null) ? $opts['block_types'] : [];
            $btByKey = [];
            foreach ($blockTypes as $bt) {
                if (is_array($bt) && !empty($bt['key'])) $btByKey[$bt['key']] = $bt;
            }
            $rows = is_array($val) ? array_values($val) : [];
        ?>
            <?php if (!$blockTypes): ?>
                <p class="alert">Aucun type de bloc défini. Édite le schéma du champ pour en ajouter.</p>
            <?php else: ?>
                <div class="blocks" data-blocks>
                    <div class="blocks-rows" data-sortable-blocks>
                        <?php foreach ($rows as $i => $row):
                            $rType = is_array($row) ? (string) ($row['type'] ?? '') : '';
                            if (!isset($btByKey[$rType])) continue; // type orphelin (schéma modifié) : skip pour ne pas perdre silencieusement
                            $bt   = $btByKey[$rType];
                            $sub  = is_array($bt['subfields'] ?? null) ? $bt['subfields'] : [];
                            $rd   = is_array($row['data'] ?? null) ? $row['data'] : [];
                        ?>
                            <fieldset class="block-row" data-row data-block-type="<?= e($rType) ?>">
                                <input type="hidden" name="<?= e($key) ?>[<?= (int) $i ?>][__present]" value="1">
                                <input type="hidden" name="<?= e($key) ?>[<?= (int) $i ?>][__type]"    value="<?= e($rType) ?>">
                                <header class="block-row-head">
                                    <span class="drag-handle" title="Glisser pour réordonner">≡</span>
                                    <strong><?= e($bt['label'] ?? $rType) ?></strong>
                                    <code class="muted small"><?= e($rType) ?></code>
                                    <button type="button" class="link danger" data-row-remove>Supprimer</button>
                                </header>
                                <div class="block-row-body">
                                    <?php foreach ($sub as $sf): greffe_render_subfield($key . '[' . (int) $i . '][data]', $sf, $rd[$sf['key']] ?? null); endforeach; ?>
                                </div>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($blockTypes as $bt):
                        if (!is_array($bt) || empty($bt['key'])) continue;
                        $btKey = (string) $bt['key'];
                        $sub   = is_array($bt['subfields'] ?? null) ? $bt['subfields'] : [];
                    ?>
                        <template data-block-template="<?= e($btKey) ?>">
                            <fieldset class="block-row" data-row data-block-type="<?= e($btKey) ?>">
                                <input type="hidden" name="<?= e($key) ?>[__INDEX__][__present]" value="1">
                                <input type="hidden" name="<?= e($key) ?>[__INDEX__][__type]"    value="<?= e($btKey) ?>">
                                <header class="block-row-head">
                                    <span class="drag-handle" title="Glisser pour réordonner">≡</span>
                                    <strong><?= e($bt['label'] ?? $btKey) ?></strong>
                                    <code class="muted small"><?= e($btKey) ?></code>
                                    <button type="button" class="link danger" data-row-remove>Supprimer</button>
                                </header>
                                <div class="block-row-body">
                                    <?php foreach ($sub as $sf): greffe_render_subfield($key . '[__INDEX__][data]', $sf, null); endforeach; ?>
                                </div>
                            </fieldset>
                        </template>
                    <?php endforeach; ?>
                    <div class="blocks-add">
                        <span class="muted small">Ajouter un bloc :</span>
                        <?php foreach ($blockTypes as $bt):
                            if (!is_array($bt) || empty($bt['key'])) continue;
                        ?>
                            <button type="button" class="btn" data-block-add="<?= e((string) $bt['key']) ?>">+ <?= e($bt['label'] ?? $bt['key']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <input type="text" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="<?= e((string) $val) ?>">
        <?php endif; ?>
    </div>
    <?php
}
