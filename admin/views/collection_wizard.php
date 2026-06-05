<?php
/**
 * Wizard 3 étapes pour créer une collection :
 *   1. Choisir le kind (3 cards visuelles)
 *   2. Nommer (label + slug)
 *   3. Ajouter les premiers champs (key + label + type)
 *
 * Tout dans une seule page, JS pilote la navigation entre les steps.
 * Submit final = POST à ?p=collection_new (le handler crée collection + fields).
 *
 * Pour les types complexes (select, relation, group, repeater, blocks) qui ont
 * besoin d'options spécifiques, on les ajoute après dans la page d'édition. Ici on
 * propose uniquement des types "leaf" qui marchent sans options supplémentaires.
 */
$simpleTypes = ['text', 'longtext', 'wysiwyg', 'number', 'boolean', 'date', 'color', 'media', 'gallery', 'file'];
/** @var string[] $existing_slugs */
$existing_slugs = $existing_slugs ?? [];
?>
<div class="page-head">
    <div>
        <a href="<?= e(url('index.php?p=collections')) ?>" class="muted small back-link"><?= icon('arrow-left', 14) ?> Mes collections</a>
        <h1>Nouvelle collection</h1>
    </div>
</div>

<form method="post" class="wizard" data-wizard data-no-hijax
      data-existing-slugs="<?= e(json_encode(array_values($existing_slugs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <?= csrf_field() ?>

    <ol class="wizard-steps" aria-label="Progression">
        <li data-step-indicator="1" class="active">De quoi as-tu besoin ?</li>
        <li data-step-indicator="2">Nomme ta collection</li>
        <li data-step-indicator="3">Premiers champs</li>
    </ol>

    <!-- Étape 1 : choix du kind -->
    <section class="wizard-step" data-step="1">
        <p class="muted">Un seul choix par collection. Tu pourras créer d'autres collections après.</p>
        <div class="kind-cards">
            <label class="kind-card" data-kind-card>
                <input type="radio" name="kind" value="options" required>
                <span class="kind-card-icon"><?= icon('settings', 22) ?></span>
                <strong>Réglages</strong>
                <span class="muted small">Pour les infos du site qui n'ont pas de doublons : header, footer, horaires, contact, légal…</span>
                <span class="kind-card-hint muted small">1 fiche unique</span>
            </label>

            <label class="kind-card" data-kind-card>
                <input type="radio" name="kind" value="pages">
                <span class="kind-card-icon"><?= icon('layers', 22) ?></span>
                <strong>Pages</strong>
                <span class="muted small">Pour des pages éditables une par une : accueil, à propos, services, contact…</span>
                <span class="kind-card-hint muted small">N pages, chacune sa structure</span>
            </label>

            <label class="kind-card" data-kind-card>
                <input type="radio" name="kind" value="list">
                <span class="kind-card-icon"><?= icon('folder', 22) ?></span>
                <strong>Contenu</strong>
                <span class="muted small">Pour une liste d'éléments tous structurés pareil : blog, portfolio, équipe, produits…</span>
                <span class="kind-card-hint muted small">N éléments du même type</span>
            </label>
        </div>
        <div class="wizard-actions">
            <span></span>
            <button type="button" class="btn primary" data-wizard-next disabled>Suivant <?= icon('chevron-right', 14) ?></button>
        </div>
    </section>

    <!-- Étape 2 : naming -->
    <section class="wizard-step" data-step="2" hidden>
        <p class="muted">Le label est ce qui s'affiche dans l'admin. Le slug sert d'identifiant pour le front (URL-safe).</p>
        <label>Label
            <input type="text" name="label" required data-wizard-label placeholder="Ex : Blog, Pages, Réglages…" autocomplete="off">
        </label>
        <label>Slug
            <input type="text" name="slug" pattern="[a-z0-9\-]+" data-wizard-slug placeholder="Auto-rempli depuis le label" autocomplete="off">
            <span class="muted small" data-wizard-slug-feedback hidden></span>
        </label>
        <div class="wizard-actions">
            <button type="button" class="btn" data-wizard-prev><?= icon('chevron-left', 14) ?> Retour</button>
            <button type="button" class="btn primary" data-wizard-next disabled>Suivant <?= icon('chevron-right', 14) ?></button>
        </div>
    </section>

    <!-- Étape 3 : premiers champs -->
    <section class="wizard-step" data-step="3" hidden>
        <p class="muted">
            Empile quelques champs pour démarrer. Tu pourras en ajouter / supprimer / réordonner après,
            et ajouter des types plus avancés (select, relation, group, repeater, blocks) depuis la page d'édition.
        </p>

        <div class="wizard-fields" data-wizard-fields>
            <div class="wizard-field-row" data-row>
                <span class="drag-handle" title="Glisser">≡</span>
                <input type="text" name="fields[0][key]"   placeholder="cle (ex: titre)"   pattern="[a-z0-9_\-]+" autocomplete="off">
                <input type="text" name="fields[0][label]" placeholder="Label (ex: Titre)"                       autocomplete="off">
                <select name="fields[0][type]">
                    <?php foreach ($simpleTypes as $t): ?>
                        <option value="<?= e($t) ?>"><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="link danger" data-row-remove title="Supprimer">✕</button>
            </div>
        </div>

        <template data-wizard-field-template>
            <div class="wizard-field-row" data-row>
                <span class="drag-handle" title="Glisser">≡</span>
                <input type="text" name="fields[__INDEX__][key]"   placeholder="cle"   pattern="[a-z0-9_\-]+" autocomplete="off">
                <input type="text" name="fields[__INDEX__][label]" placeholder="Label"                       autocomplete="off">
                <select name="fields[__INDEX__][type]">
                    <?php foreach ($simpleTypes as $t): ?>
                        <option value="<?= e($t) ?>"><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="link danger" data-row-remove title="Supprimer">✕</button>
            </div>
        </template>

        <button type="button" class="btn" data-wizard-field-add>+ Ajouter un champ</button>

        <div class="wizard-actions">
            <button type="button" class="btn" data-wizard-prev><?= icon('chevron-left', 14) ?> Retour</button>
            <button type="submit" class="btn primary">Créer la collection</button>
        </div>
    </section>
</form>
