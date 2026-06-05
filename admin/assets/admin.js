// Greffe — JS admin.
//   Init idempotente · field-form type toggle · subfields builder
//   Repeater rows (add/remove/drag + animations légères)
//   Drag-drop records & schema (AJAX persistance + toasts)
//   Fancy select · Hijax

(function () {
    'use strict';

    var BASE = (function () {
        var p = window.location.pathname.split('/');
        for (var i = p.length - 1; i >= 0; i--) {
            if (p[i] === 'admin') return p.slice(0, i + 1).join('/');
        }
        return '';
    })();

    function csrfToken() {
        var el = document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    var Greffe = window.Greffe = window.Greffe || {};
    Greffe.base = BASE;

    /* ========== Init router (idempotente) ========== */
    function init(root) {
        root = root || document;
        initFieldFormTypeToggle(root);
        initSubfieldsBuilder(root);
        initRepeaters(root);
        initBlocks(root);
        initWizard(root);
        initSortableRecords(root);
        initSortableFields(root);
        initFancySelects(root);
        initGalleries(root);
        initColorReadout(root);
        initWysiwyg(root);
        initSideToggle(root);
        initMediaLibrary(root);
    }
    Greffe.init = init;

    /* ========== Médiathèque : filtre live + copie chemin ========== */
    function initMediaLibrary(root) {
        var search = root.querySelector('[data-media-search]');
        var grid   = root.querySelector('[data-media-grid]');
        if (search && grid && !search.hasAttribute('data-init')) {
            search.setAttribute('data-init', '1');
            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                grid.querySelectorAll('.media-card').forEach(function (card) {
                    var name = card.getAttribute('data-name') || '';
                    var path = card.getAttribute('data-path') || '';
                    var match = !q || name.indexOf(q) !== -1 || path.indexOf(q) !== -1;
                    card.style.display = match ? '' : 'none';
                });
            });
        }
        root.querySelectorAll('[data-copy]:not([data-init])').forEach(function (btn) {
            btn.setAttribute('data-init', '1');
            btn.addEventListener('click', function () {
                var p = btn.getAttribute('data-copy') || '';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(p).then(function () {
                        Greffe.toast('Chemin copié : ' + p, 'success');
                    }, function () {
                        Greffe.toast('Impossible de copier', 'error');
                    });
                } else {
                    // Fallback ancien
                    var ta = document.createElement('textarea');
                    ta.value = p;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); Greffe.toast('Chemin copié', 'success'); }
                    catch (e) { Greffe.toast('Copie échouée', 'error'); }
                    ta.remove();
                }
            });
        });
    }

    /* ========== Sidebar mobile : hamburger toggle ========== */
    function initSideToggle(root) {
        root.querySelectorAll('[data-side-toggle]:not([data-init])').forEach(function (btn) {
            btn.setAttribute('data-init', '1');
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                document.body.classList.toggle('side-open');
            });
        });
    }
    // Document-level : ferme le drawer quand on clique en dehors / quand on navigue / Esc.
    document.addEventListener('click', function (e) {
        if (!document.body.classList.contains('side-open')) return;
        var side  = document.querySelector('.greffe-side');
        var toggle = e.target.closest('[data-side-toggle]');
        if (toggle) return;
        if (side && side.contains(e.target)) {
            // Si c'est un lien dans la sidebar, on ferme le drawer.
            if (e.target.closest('a')) {
                document.body.classList.remove('side-open');
            }
            return;
        }
        document.body.classList.remove('side-open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.body.classList.remove('side-open');
    });

    /* ========== Galleries (drag-drop zone, previews, alt/title) ========== */
    function initGalleries(root) {
        root.querySelectorAll('[data-gallery]:not([data-init])').forEach(function (gallery) {
            gallery.setAttribute('data-init', '1');

            var grid         = gallery.querySelector('[data-sortable-gallery]');
            var input        = gallery.querySelector('[data-gallery-input]');
            var dropzone     = gallery.querySelector('[data-gallery-drop]');
            var pendingGrid  = gallery.querySelector('[data-gallery-pending-grid]');
            var tpl          = gallery.querySelector('template[data-gallery-pending-template]');

            // Sortable sur les images déjà uploadées.
            if (window.Sortable && grid) {
                window.Sortable.create(grid, {
                    animation: 150,
                    handle: '.gallery-handle',
                    ghostClass: 'sortable-ghost',
                });
            }

            // Marquer visuellement les tiles cochées pour suppression.
            if (grid) {
                grid.addEventListener('change', function (e) {
                    if (e.target && e.target.matches && e.target.matches('[name$="__remove[]"]')) {
                        var tile = e.target.closest('[data-row]');
                        if (tile) tile.classList.toggle('to-remove', e.target.checked);
                    }
                });
            }

            if (!input || !pendingGrid || !tpl || !dropzone) return;

            // Pile des nouveaux fichiers en attente (préservée entre les sélections).
            var pendingFiles = [];

            function syncInput() {
                try {
                    var dt = new DataTransfer();
                    pendingFiles.forEach(function (f) { dt.items.add(f); });
                    input.files = dt.files;
                } catch (e) { /* navigateur trop ancien : tant pis, on perdra l'append cross-sélection */ }
            }

            function reindexPending() {
                Array.from(pendingGrid.querySelectorAll('[data-pending]')).forEach(function (t, i) {
                    t.setAttribute('data-pending-idx', String(i));
                });
            }

            function addOneFile(file) {
                if (!file || !file.type || file.type.indexOf('image/') !== 0) return;
                pendingFiles.push(file);

                var frag = tpl.content.cloneNode(true);
                var tile = frag.querySelector('[data-pending]');
                tile.setAttribute('data-pending-idx', String(pendingFiles.length - 1));

                var img = tile.querySelector('img');
                var reader = new FileReader();
                reader.onload = function (e) { img.src = e.target.result; };
                reader.readAsDataURL(file);

                var altInp = tile.querySelector('[data-pending-alt]');
                if (altInp) altInp.value = file.name.replace(/\.[^.]+$/, '');

                tile.querySelector('[data-pending-remove]').addEventListener('click', function () {
                    var idx = parseInt(tile.getAttribute('data-pending-idx'), 10);
                    if (!isNaN(idx)) pendingFiles.splice(idx, 1);
                    tile.remove();
                    reindexPending();
                    syncInput();
                });

                pendingGrid.appendChild(frag);
            }

            function addFiles(fileList) {
                if (!fileList) return;
                // Évite les doublons par nom+taille.
                var seen = {};
                pendingFiles.forEach(function (f) { seen[f.name + ':' + f.size] = true; });
                for (var i = 0; i < fileList.length; i++) {
                    var f = fileList[i];
                    var k = f.name + ':' + f.size;
                    if (seen[k]) continue;
                    seen[k] = true;
                    addOneFile(f);
                }
                syncInput();
            }

            input.addEventListener('change', function () {
                if (input.files && input.files.length) addFiles(input.files);
            });

            ['dragenter', 'dragover'].forEach(function (ev) {
                dropzone.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('drag-over');
                });
            });
            ['dragleave', 'dragend'].forEach(function (ev) {
                dropzone.addEventListener(ev, function (e) {
                    if (!dropzone.contains(e.relatedTarget)) {
                        dropzone.classList.remove('drag-over');
                    }
                });
            });
            dropzone.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('drag-over');
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                    addFiles(e.dataTransfer.files);
                }
            });
        });
    }

    /* ========== Color picker : readout en live ========== */
    function initColorReadout(root) {
        root.querySelectorAll('.color-field:not([data-init])').forEach(function (wrap) {
            wrap.setAttribute('data-init', '1');
            var inp = wrap.querySelector('input[type=color]');
            var out = wrap.querySelector('[data-color-readout]');
            if (!inp || !out) return;
            function sync() { out.textContent = inp.value; }
            inp.addEventListener('input', sync);
            sync();
        });
    }

    /* ========== WYSIWYG (Pell) ========== */
    function initWysiwyg(root) {
        if (!window.pell) return;
        root.querySelectorAll('[data-wysiwyg]:not([data-init])').forEach(function (wrap) {
            wrap.setAttribute('data-init', '1');
            var holder = wrap.querySelector('.wysiwyg-editor');
            var hidden = wrap.querySelector('input[type=hidden].wysiwyg-value, textarea.wysiwyg-value');
            if (!holder || !hidden) return;

            var initialHtml = hidden.value || '';

            var editor = window.pell.init({
                element: holder,
                defaultParagraphSeparator: 'p',
                onChange: function (html) { hidden.value = html; },
                actions: [
                    'bold', 'italic', 'underline',
                    {
                        name: 'h2', icon: '<b>H2</b>', title: 'Titre 2',
                        result: function () { return window.pell.exec('formatBlock', '<h2>'); },
                    },
                    {
                        name: 'h3', icon: '<b>H3</b>', title: 'Titre 3',
                        result: function () { return window.pell.exec('formatBlock', '<h3>'); },
                    },
                    {
                        name: 'h4', icon: '<b>H4</b>', title: 'Titre 4',
                        result: function () { return window.pell.exec('formatBlock', '<h4>'); },
                    },
                    {
                        name: 'p', icon: '¶', title: 'Paragraphe',
                        result: function () { return window.pell.exec('formatBlock', '<p>'); },
                    },
                    'quote',
                    'olist', 'ulist',
                    'link',
                    {
                        name: 'image-upload', icon: '🖼', title: 'Image (upload)',
                        result: function () { wysiwygUploadImage(); },
                    },
                ],
            });

            editor.content.innerHTML = initialHtml;
        });
    }

    function wysiwygUploadImage() {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = function () {
            if (!input.files || !input.files[0]) return;
            var fd = new FormData();
            fd.append('file', input.files[0]);
            fd.append('_csrf', csrfToken());
            fetch(BASE + '/index.php?p=upload_inline', {
                method: 'POST', headers: { 'X-Greffe-AJAX': '1' },
                body: fd, credentials: 'same-origin',
            }).then(function (r) {
                if (!r.ok) throw new Error(String(r.status));
                return r.json();
            }).then(function (data) {
                if (data && data.url) {
                    window.pell.exec('insertImage', data.url);
                }
            }).catch(function () { Greffe.toast('Upload image échoué', 'error'); });
        };
        input.click();
    }

    /* ========== Pending reorders — guard pour éviter les races avec form submit ========== */
    var pendingReorders = [];
    function trackReorder(promise) {
        pendingReorders.push(promise);
        promise.then(function () { /* noop */ }, function () { /* noop */ }).then(function () {
            var i = pendingReorders.indexOf(promise);
            if (i >= 0) pendingReorders.splice(i, 1);
        });
    }
    // Si l'utilisateur drag + clique IMMÉDIATEMENT "Enregistrer" sur un form data-no-hijax,
    // on intercepte le submit pour attendre que les reorders en vol soient ack côté serveur.
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        var f = e.target;
        if (!(f instanceof HTMLFormElement)) return;
        if (!f.hasAttribute('data-no-hijax')) return; // les form hijax sont gérés ailleurs
        if (pendingReorders.length === 0) return;
        if (f.dataset._awaited === '1') { delete f.dataset._awaited; return; } // déjà attendu
        e.preventDefault();
        var submitter = e.submitter || null;
        Promise.all(pendingReorders.slice()).then(function () { /* noop */ }, function () { /* noop */ }).then(function () {
            f.dataset._awaited = '1';
            try { f.requestSubmit(submitter); } catch (e2) { f.submit(); }
        });
    }, true); // capture phase, run avant les autres handlers

    /* ========== Toasts — wrapper sur Notyf ========== */
    var _notyf = null;
    function notyfInstance() {
        if (_notyf) return _notyf;
        if (typeof window.Notyf === 'undefined') return null;
        _notyf = new window.Notyf({
            duration: 4000,
            position: { x: 'right', y: 'bottom' },
            dismissible: true,
            ripple: false,
            types: [
                { type: 'success', className: 'greffe-toast greffe-toast-success' },
                { type: 'error',   className: 'greffe-toast greffe-toast-error', duration: 6000 },
                { type: 'info',    className: 'greffe-toast greffe-toast-info' },
            ],
        });
        return _notyf;
    }
    // Notyf injecte le message via innerHTML (cf. notyf.min.js).
    // On HTML-escape AVANT de lui passer la string pour éviter toute XSS
    // si un jour un flash_set('error', $e->getMessage()) contient du HTML utilisateur.
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    Greffe.toast = function (message, type) {
        var n = notyfInstance();
        if (!n) {
            try { console.log('[toast]', type || 'info', message); } catch (e) {}
            return;
        }
        var safe = escapeHtml(message);
        type = type || 'info';
        if (type === 'success') n.success({ message: safe });
        else if (type === 'error') n.error({ message: safe });
        else n.open({ type: 'info', message: safe });
    };

    /* ========== Confirmations sur formulaires (data-confirm) ========== */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!(f instanceof HTMLFormElement)) return;
        var msg = f.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) e.preventDefault();
    });

    /* ========== Bascule des blocs d'options par type de champ ========== */
    function syncFieldOptions(form) {
        var sel = form.querySelector('[data-field-type]');
        if (!sel) return;
        var t = sel.value;
        var map = {
            'select':   form.querySelector('[data-field-options-select]'),
            'relation': form.querySelector('[data-field-options-relation]'),
            'group':    form.querySelector('[data-field-options-subfields]'),
            'repeater': form.querySelector('[data-field-options-subfields]'),
            'blocks':   form.querySelector('[data-field-options-blocks]'),
        };
        form.querySelectorAll('.field-options-block').forEach(function (b) { b.hidden = true; });
        if (map[t]) map[t].hidden = false;
    }
    function initFieldFormTypeToggle(root) {
        root.querySelectorAll('[data-field-form]:not([data-init-form])').forEach(function (form) {
            form.setAttribute('data-init-form', '1');
            var sel = form.querySelector('[data-field-type]');
            if (!sel) return;
            sel.addEventListener('change', function () { syncFieldOptions(form); });
            syncFieldOptions(form);
        });
    }

    /* ========== Subfields builder (group / repeater) ========== */
    function initSubfieldsBuilder(root) {
        root.querySelectorAll('[data-subfields-builder]:not([data-init])').forEach(function (builder) {
            builder.setAttribute('data-init', '1');
            var rows = builder.querySelector('.subfields-rows');
            var tpl  = builder.querySelector('template[data-subfield-template]');
            var add  = builder.querySelector('[data-subfield-add]');
            if (!rows || !tpl || !add) return;

            var counter = rows.querySelectorAll('[data-row]').length;
            if (counter < 1000) counter = Math.max(counter, 1000);

            add.addEventListener('click', function () {
                var frag = tpl.content.cloneNode(true);
                replaceIndex(frag, counter++);
                rows.appendChild(frag);
                var newRow = rows.lastElementChild;
                animateEnter(newRow);
                hookSubfieldChoiceToggle(newRow);
                initFancySelects(newRow);
            });

            builder.addEventListener('click', function (e) {
                var t = e.target;
                if (!t || !t.matches || !t.matches('[data-row-remove]')) return;
                var row = t.closest('[data-row]');
                if (row && window.confirm('Supprimer ce sous-champ ?')) animateLeave(row);
            });

            rows.querySelectorAll('[data-row]').forEach(hookSubfieldChoiceToggle);

            if (window.Sortable) {
                window.Sortable.create(rows, {
                    animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                });
            }
        });
    }
    function hookSubfieldChoiceToggle(row) {
        if (!row) return;
        var sel = row.querySelector('[data-subfield-type]');
        var inp = row.querySelector('[data-subfield-choices]');
        if (!sel || !inp) return;
        function sync() { inp.hidden = (sel.value !== 'select'); }
        sel.addEventListener('change', sync);
        sync();
    }

    /* ========== Wizard nouvelle collection (3 steps) ========== */
    function initWizard(root) {
        root.querySelectorAll('[data-wizard]:not([data-init])').forEach(function (wizard) {
            wizard.setAttribute('data-init', '1');
            var steps      = Array.prototype.slice.call(wizard.querySelectorAll('[data-step]'));
            var indicators = Array.prototype.slice.call(wizard.querySelectorAll('[data-step-indicator]'));
            var current    = 1;
            var maxStep    = steps.length;

            function show(step) {
                current = step;
                steps.forEach(function (s) { s.hidden = (parseInt(s.getAttribute('data-step'), 10) !== step); });
                indicators.forEach(function (li) {
                    var n = parseInt(li.getAttribute('data-step-indicator'), 10);
                    li.classList.toggle('active', n === step);
                    li.classList.toggle('done',   n <  step);
                });
                syncNextEnabled();
                var firstInput = steps[step - 1].querySelector('input:not([type=hidden]):not([type=radio]), select, textarea');
                if (firstInput) firstInput.focus({ preventScroll: true });
            }

            function isStepValid(step) {
                if (step === 1) {
                    return !!wizard.querySelector('input[name="kind"]:checked');
                }
                if (step === 2) {
                    var label = wizard.querySelector('[data-wizard-label]');
                    return label && label.value.trim() !== '';
                }
                return true;
            }
            function syncNextEnabled() {
                var btn = steps[current - 1].querySelector('[data-wizard-next]');
                if (btn) btn.disabled = !isStepValid(current);
            }

            wizard.addEventListener('click', function (e) {
                var t = e.target.closest && e.target.closest('button, [data-kind-card]');
                if (!t) return;
                if (t.matches && t.matches('[data-wizard-next]')) {
                    if (!isStepValid(current)) return;
                    if (current < maxStep) show(current + 1);
                    return;
                }
                if (t.matches && t.matches('[data-wizard-prev]')) {
                    if (current > 1) show(current - 1);
                    return;
                }
                if (t.matches && t.matches('[data-wizard-field-add]')) {
                    addFieldRow();
                    return;
                }
                if (t.matches && t.matches('[data-row-remove]')) {
                    var row = t.closest('[data-row]');
                    if (!row) return;
                    var rows = wizard.querySelectorAll('.wizard-field-row');
                    if (rows.length <= 1) {
                        // garde au moins une ligne, on la vide
                        row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                        return;
                    }
                    row.remove();
                }
            });

            // Sync au changement (radios / inputs) pour activer Next.
            wizard.addEventListener('input',  syncNextEnabled);
            wizard.addEventListener('change', function (e) {
                // Auto-advance quand on clique une card de kind à l'étape 1.
                if (e.target && e.target.matches && e.target.matches('input[name="kind"]') && current === 1) {
                    syncNextEnabled();
                }
                syncNextEnabled();
            });

            // Slug auto depuis label (étape 2).
            var labelInput = wizard.querySelector('[data-wizard-label]');
            var slugInput  = wizard.querySelector('[data-wizard-slug]');
            if (labelInput && slugInput) {
                var userTouchedSlug = false;
                slugInput.addEventListener('input', function () { userTouchedSlug = true; });
                labelInput.addEventListener('input', function () {
                    if (userTouchedSlug) return;
                    slugInput.value = slugify(labelInput.value);
                });
            }

            // Ajoute une row de champ.
            var fieldsWrap = wizard.querySelector('[data-wizard-fields]');
            var fieldTpl   = wizard.querySelector('template[data-wizard-field-template]');
            var fieldCounter = fieldsWrap ? fieldsWrap.querySelectorAll('[data-row]').length : 0;
            if (fieldCounter < 1000) fieldCounter = Math.max(fieldCounter, 1000);

            function addFieldRow() {
                if (!fieldsWrap || !fieldTpl) return;
                var frag = fieldTpl.content.cloneNode(true);
                replaceIndex(frag, fieldCounter++);
                fieldsWrap.appendChild(frag);
                var newRow = fieldsWrap.lastElementChild;
                animateEnter(newRow);
                var keyInput = newRow.querySelector('input');
                if (keyInput) keyInput.focus({ preventScroll: true });
            }

            // Drag-drop des rows de champ.
            if (window.Sortable && fieldsWrap) {
                window.Sortable.create(fieldsWrap, {
                    animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                });
            }

            show(1);
        });
    }

    /* Slugifie côté JS — duplique la logique PHP keyify() pour le slug auto au type. */
    function slugify(s) {
        return (s || '')
            .toString()
            .toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    /* ========== Blocks (flexible content) ========== */
    function initBlocks(root) {
        root.querySelectorAll('[data-blocks]:not([data-init])').forEach(function (blocks) {
            blocks.setAttribute('data-init', '1');
            var rows = blocks.querySelector('.blocks-rows');
            if (!rows) return;
            var counter = rows.querySelectorAll('[data-row]').length;
            if (counter < 1000) counter = Math.max(counter, 1000);

            blocks.addEventListener('click', function (e) {
                var t = e.target;
                if (!t || !t.matches) return;
                if (t.matches('[data-block-add]')) {
                    var btKey = t.getAttribute('data-block-add');
                    var tpl   = blocks.querySelector('template[data-block-template="' + btKey + '"]');
                    if (!tpl) return;
                    var frag  = tpl.content.cloneNode(true);
                    replaceIndex(frag, counter++);
                    rows.appendChild(frag);
                    var newRow = rows.lastElementChild;
                    animateEnter(newRow);
                    initFancySelects(newRow);
                }
                if (t.matches('[data-row-remove]')) {
                    var row = t.closest('[data-row]');
                    if (row && window.confirm('Supprimer ce bloc ?')) animateLeave(row);
                }
            });

            if (window.Sortable) {
                window.Sortable.create(rows, {
                    animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                });
            }
        });
    }

    /* ========== Repeater rows ========== */
    function initRepeaters(root) {
        root.querySelectorAll('[data-repeater]:not([data-init])').forEach(function (rep) {
            rep.setAttribute('data-init', '1');
            var tpl  = rep.querySelector('template[data-row-template]');
            var rows = rep.querySelector('.repeater-rows');
            var add  = rep.querySelector('[data-row-add]');
            if (!tpl || !rows || !add) return;

            var counter = rows.querySelectorAll('[data-row]').length;
            if (counter < 1000) counter = Math.max(counter, 1000);

            add.addEventListener('click', function () {
                var frag = tpl.content.cloneNode(true);
                replaceIndex(frag, counter++);
                rows.appendChild(frag);
                var newRow = rows.lastElementChild;
                animateEnter(newRow);
                initFancySelects(newRow);
            });

            rep.addEventListener('click', function (e) {
                var t = e.target;
                if (!t || !t.matches || !t.matches('[data-row-remove]')) return;
                var row = t.closest('[data-row]');
                if (row && window.confirm('Supprimer cette ligne ?')) animateLeave(row);
            });

            if (window.Sortable) {
                window.Sortable.create(rows, {
                    animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                });
            }
        });
    }

    /* ========== Drag-drop : records ========== */
    function initSortableRecords(root) {
        if (!window.Sortable) return;
        root.querySelectorAll('[data-sortable-records]:not([data-init])').forEach(function (tbody) {
            tbody.setAttribute('data-init', '1');
            var col = tbody.getAttribute('data-collection-slug') || '';
            window.Sortable.create(tbody, {
                animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                onEnd: function () {
                    var ids = Array.from(tbody.querySelectorAll('[data-id]')).map(function (tr) { return tr.getAttribute('data-id'); });
                    postOrder(BASE + '/index.php?p=records&col=' + encodeURIComponent(col), ids);
                },
            });
        });
    }
    function initSortableFields(root) {
        if (!window.Sortable) return;
        root.querySelectorAll('[data-sortable-fields]:not([data-init])').forEach(function (wrap) {
            wrap.setAttribute('data-init', '1');
            var colId = wrap.getAttribute('data-collection-id') || '';
            window.Sortable.create(wrap, {
                animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                onEnd: function () {
                    var ids = Array.from(wrap.children).filter(function (el) {
                        return el.hasAttribute && el.hasAttribute('data-id');
                    }).map(function (el) { return el.getAttribute('data-id'); });
                    var fd = new FormData();
                    fd.append('action', 'reorder_fields');
                    fd.append('_csrf', csrfToken());
                    ids.forEach(function (id) { fd.append('order[]', id); });
                    var p = fetch(BASE + '/index.php?p=collection_edit&id=' + encodeURIComponent(colId), {
                        method: 'POST', headers: { 'X-Greffe-AJAX': '1' },
                        body: fd, credentials: 'same-origin',
                        // keepalive : si l'utilisateur clique sur "Enregistrer" après le drop,
                        // garantit que la requête est envoyée même si la navigation démarre.
                        keepalive: true,
                    });
                    trackReorder(p);
                    p.then(function (resp) {
                        if (resp.ok) Greffe.toast('Ordre enregistré', 'success');
                        else Greffe.toast('Échec ordre (' + resp.status + ')', 'error');
                    }).catch(function () { Greffe.toast('Erreur réseau', 'error'); });
                },
            });
        });
    }
    function postOrder(url, ids) {
        var fd = new FormData();
        fd.append('_csrf', csrfToken());
        ids.forEach(function (id) { fd.append('order[]', id); });
        var p = fetch(url, {
            method: 'POST', headers: { 'X-Greffe-AJAX': '1' },
            body: fd, credentials: 'same-origin',
            keepalive: true,
        });
        trackReorder(p);
        p.then(function (resp) {
            if (resp.ok) Greffe.toast('Ordre enregistré', 'success');
            else Greffe.toast('Échec ordre (' + resp.status + ')', 'error');
        }).catch(function () { Greffe.toast('Erreur réseau', 'error'); });
    }

    /* ========== Fancy select ========== */
    function initFancySelects(root) {
        root.querySelectorAll('select:not([multiple]):not([data-no-fancy]):not([data-fancy-init])').forEach(function (sel) {
            if (sel.closest('template')) return;
            sel.setAttribute('data-fancy-init', '1');
            buildFancySelect(sel);
        });
    }
    function buildFancySelect(select) {
        var wrap = document.createElement('div');
        wrap.className = 'fancy-select';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fancy-select-button';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '<span class="fs-label"></span><span class="fs-arrow" aria-hidden="true">▾</span>';

        var dropdown = document.createElement('div');
        dropdown.className = 'fancy-select-dropdown';
        dropdown.setAttribute('role', 'listbox');
        dropdown.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(btn);
        wrap.appendChild(dropdown);
        select.classList.add('fancy-select-native');
        if (select.disabled) btn.disabled = true;

        function buildOptions() {
            dropdown.innerHTML = '';
            Array.from(select.options).forEach(function (opt, idx) {
                var item = document.createElement('div');
                item.className = 'fancy-select-option';
                item.setAttribute('role', 'option');
                item.setAttribute('data-index', String(idx));
                item.textContent = opt.textContent;
                if (idx === select.selectedIndex) {
                    item.classList.add('selected');
                    item.setAttribute('aria-selected', 'true');
                }
                if (opt.disabled) item.classList.add('disabled');
                item.addEventListener('click', function () {
                    if (opt.disabled) return;
                    select.selectedIndex = idx;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    close();
                });
                item.addEventListener('mousemove', function () {
                    dropdown.querySelectorAll('.hover').forEach(function (e) { e.classList.remove('hover'); });
                    item.classList.add('hover');
                });
                dropdown.appendChild(item);
            });
        }
        function syncLabel() {
            var lab = btn.querySelector('.fs-label');
            var s = select.options[select.selectedIndex];
            lab.textContent = s ? s.textContent : '';
        }
        function open() {
            buildOptions();
            dropdown.hidden = false;
            requestAnimationFrame(function () { dropdown.classList.add('open'); });
            btn.setAttribute('aria-expanded', 'true');
            var sel = dropdown.querySelector('.selected');
            if (sel) sel.scrollIntoView({ block: 'nearest' });
        }
        function close() {
            if (dropdown.hidden) return;
            dropdown.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
            setTimeout(function () { if (!dropdown.classList.contains('open')) dropdown.hidden = true; }, 140);
        }
        function isOpen() { return !dropdown.hidden && dropdown.classList.contains('open'); }

        btn.addEventListener('click', function (e) { e.stopPropagation(); isOpen() ? close() : open(); });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
        select.addEventListener('change', syncLabel);
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Down')  { e.preventDefault(); isOpen() ? moveHover(1)  : open(); }
            else if (e.key === 'ArrowUp' || e.key === 'Up') { e.preventDefault(); isOpen() ? moveHover(-1) : open(); }
            else if (e.key === 'Enter' || e.key === ' ')    { e.preventDefault(); if (!isOpen()) { open(); return; }
                var h = dropdown.querySelector('.hover') || dropdown.querySelector('.selected'); if (h) h.click(); }
            else if (e.key === 'Escape' || e.key === 'Esc') { close(); }
        });
        function moveHover(delta) {
            var items = Array.from(dropdown.querySelectorAll('.fancy-select-option:not(.disabled)'));
            if (!items.length) return;
            var cur = items.indexOf(dropdown.querySelector('.hover') || dropdown.querySelector('.selected'));
            var next = (cur + delta + items.length) % items.length;
            items.forEach(function (it) { it.classList.remove('hover'); });
            items[next].classList.add('hover');
            items[next].scrollIntoView({ block: 'nearest' });
        }
        syncLabel();
    }

    /* ========== Hijax ========== */
    function shouldHijackLink(a) {
        if (a.hasAttribute('data-no-hijax')) return false;
        if (a.target === '_blank') return false;
        if (a.hasAttribute('download')) return false;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') return false;
        if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;
        if (a.origin && a.origin !== window.location.origin) return false;
        var main = document.querySelector('main');
        var nav  = document.querySelector('.topbar nav');
        var side = document.querySelector('.greffe-side');
        if (main && main.contains(a)) return true;
        if (nav  && nav.contains(a))  return true;
        if (side && side.contains(a)) return true;
        return false;
    }

    function navigate(url, opts) {
        opts = opts || {};
        var linkUrl, curUrl;
        try {
            linkUrl = new URL(url, window.location.href);
            curUrl  = new URL(window.location.href);
        } catch (e) { /* mauvaise URL, fallback */ }

        // Cas même-page (mêmes path+search), hash différent : on laisse le
        // scroll natif faire (CSS smooth) et on push juste le hash.
        if (linkUrl && curUrl
            && linkUrl.pathname === curUrl.pathname
            && linkUrl.search   === curUrl.search
            && linkUrl.hash) {
            var anchor = document.getElementById(linkUrl.hash.slice(1));
            if (anchor) {
                anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (opts.push !== false) window.history.pushState({}, '', linkUrl.hash);
                return Promise.resolve();
            }
        }

        var hash = linkUrl ? linkUrl.hash : '';
        loading(true);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (resp) { return resp.text().then(function (html) { return { html: html, url: resp.url }; }); })
            .then(function (r) {
                if (!swapMain(r.html)) { window.location.assign(r.url + hash); return; }
                var finalUrl = r.url + hash;
                if (opts.push !== false) window.history.pushState({}, '', finalUrl);
                if (hash) {
                    var target = document.getElementById(hash.slice(1));
                    if (target) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
                }
                window.scrollTo(0, 0);
            })
            .catch(function () { window.location.assign(url); })
            .finally(function () { loading(false); });
    }

    function submitInMain(form, submitter) {
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        var url    = form.getAttribute('action') || window.location.href;
        var init   = { credentials: 'same-origin' };
        var fd     = buildFormData(form, submitter);
        if (method === 'post') {
            init.method = 'POST';
            init.body   = fd;
        } else {
            var qs = new URLSearchParams();
            fd.forEach(function (v, k) { qs.append(k, v); });
            url = url + (url.indexOf('?') === -1 ? '?' : '&') + qs.toString();
        }
        loading(true);
        fetch(url, init)
            .then(function (resp) { return resp.text().then(function (html) { return { html: html, url: resp.url }; }); })
            .then(function (r) {
                if (!swapMain(r.html)) { window.location.assign(r.url); return; }
                window.history.pushState({}, '', r.url);
                window.scrollTo(0, 0);
            })
            .catch(function () { form.submit(); })
            .finally(function () { loading(false); });
    }

    function swapMain(html) {
        var doc;
        try { doc = new DOMParser().parseFromString(html, 'text/html'); }
        catch (e) { return false; }
        var newMain = doc.querySelector('main');
        var curMain = document.querySelector('main');
        if (!newMain || !curMain) return false;
        curMain.replaceWith(newMain);

        var newBar = doc.querySelector('.topbar');
        var curBar = document.querySelector('.topbar');
        if (newBar && curBar) curBar.replaceWith(newBar);
        else if (newBar && !curBar) document.body.insertBefore(newBar, document.body.firstChild);
        else if (!newBar && curBar) curBar.remove();

        // Sidebar : l'état actif change avec la nouvelle URL → on remplace aussi.
        var newSide = doc.querySelector('.greffe-side');
        var curSide = document.querySelector('.greffe-side');
        if (newSide && curSide) curSide.replaceWith(newSide);
        else if (newSide && !curSide) document.body.appendChild(newSide);
        else if (!newSide && curSide) curSide.remove();

        // Body class (with-side) peut aussi avoir changé (login/logout).
        var newBody = doc.body;
        if (newBody) document.body.className = newBody.className;

        var newTitle = doc.querySelector('title');
        if (newTitle) document.title = newTitle.textContent;

        // Flash issu de la réponse serveur (POST → Redirect → GET côté hijax).
        var flashMeta = doc.querySelector('meta[name="greffe-flash"]');
        if (flashMeta) {
            var msg = flashMeta.getAttribute('data-message') || '';
            var typ = flashMeta.getAttribute('data-type') || 'info';
            if (msg) Greffe.toast(msg, typ);
        }

        init(document.querySelector('main') || document);
        return true;
    }

    function loading(on) {
        document.body.classList.toggle('greffe-loading', on);
    }

    function buildFormData(form, submitter) {
        var fd = new FormData(form);
        if (submitter && submitter.name && !fd.has(submitter.name)) {
            fd.append(submitter.name, submitter.value);
        }
        return fd;
    }

    function initHijax() {
        document.addEventListener('click', function (e) {
            if (e.defaultPrevented) return;
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            var a = e.target.closest && e.target.closest('a');
            if (!a) return;
            if (!shouldHijackLink(a)) return;
            e.preventDefault();
            navigate(a.href);
        });

        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.hasAttribute('data-no-hijax')) return;
            var submitter = e.submitter || null;
            var main = document.querySelector('main');
            if (main && main.contains(form)) {
                e.preventDefault();
                submitInMain(form, submitter);
            }
        });

        window.addEventListener('popstate', function () {
            navigate(window.location.href, { push: false });
        });
    }

    /* ========== Animations entrée/sortie des rows (CSS only) ========== */
    function animateEnter(el) {
        if (!el || !el.classList) return;
        el.classList.add('row-enter');
        requestAnimationFrame(function () {
            el.classList.add('row-enter-active');
            setTimeout(function () { el.classList.remove('row-enter', 'row-enter-active'); }, 240);
        });
    }
    function animateLeave(el) {
        if (!el || !el.classList) { if (el) el.remove(); return; }
        el.classList.add('row-leave-active');
        setTimeout(function () { el.remove(); }, 200);
    }

    /* ========== Helpers ========== */
    function replaceIndex(fragment, idx) {
        var walker = document.createTreeWalker(fragment, NodeFilter.SHOW_ELEMENT);
        while (walker.nextNode()) {
            var el = walker.currentNode;
            for (var i = 0; i < el.attributes.length; i++) {
                var a = el.attributes[i];
                if (a.value.indexOf('__INDEX__') !== -1) {
                    a.value = a.value.replace(/__INDEX__/g, String(idx));
                }
            }
        }
    }

    /* ========== Flash messages ========== */
    function consumeFlashFrom(scope) {
        var meta = (scope || document).querySelector('meta[name="greffe-flash"]');
        if (!meta) return;
        var msg = meta.getAttribute('data-message') || '';
        var typ = meta.getAttribute('data-type') || 'info';
        if (msg) Greffe.toast(msg, typ);
        meta.remove();
    }

    /* ========== Boot ========== */
    document.addEventListener('DOMContentLoaded', function () {
        init(document);
        initHijax();
        consumeFlashFrom(document);
    });
})();
