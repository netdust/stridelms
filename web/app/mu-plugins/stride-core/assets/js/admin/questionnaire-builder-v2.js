/**
 * Questionnaire Builder v2 — Alpine.js controller
 *
 * Single component owning all admin state. Hydrated from
 * window.strideQuestionnaireState (inlined in admin_head before Alpine
 * boots — see QuestionnaireSettingsPage::inlineHeadAssets()).
 *
 * Spec: docs/superpowers/specs/2026-05-19-questionnaire-builder-redesign-design.md
 */
(function () {
    'use strict';

    function questionnaireBuilder() {
        return {
            // ── State ─────────────────────────────────────────────
            groups: [],
            selectedGroupId: null,
            selectedFieldId: null,
            fieldTypes: {},
            stages: {},
            assignments: [],
            isDirty: false,

            // ── Lifecycle ─────────────────────────────────────────
            init() {
                const seed = window.strideQuestionnaireState || {};
                this.groups = (seed.groups || []).map(g => ({
                    ...g,
                    // Server stores post IDs as ints; <select multiple> binds
                    // string DOM values via x-model. Normalize on hydrate so
                    // existing selections render as selected. Sanitizer absint's
                    // on save, so round-tripping is safe.
                    assignments: (g.assignments || []).map(v => String(v)),
                    // Stored fields carry no `id` (the server persists
                    // label/name/type/help/description only). The template keys
                    // the field x-for on `field.id` and every field operation
                    // matches on it, so a missing id makes all rows share the
                    // `undefined` key → Alpine "Duplicate key" → the list never
                    // renders. Assign a client id on hydrate, same shape as
                    // addField()'s _newId('tmp_f'), preserving any existing one.
                    fields: (g.fields || []).map(f => ({
                        help: '',
                        ...f,
                        id: f.id || ('tmp_f_' + Math.random().toString(36).slice(2, 9)),
                    })),
                }));
                this.fieldTypes = seed.fieldTypes || {};
                this.stages = seed.stages || {};
                this.assignments = seed.assignments || [];

                if (this.groups.length > 0) {
                    this.selectedGroupId = this.groups[0].id;
                }

                this.$nextTick(() => this.initSortable());
            },

            // ── Computed ──────────────────────────────────────────
            get selectedGroup() {
                return this.groups.find(g => g.id === this.selectedGroupId) || null;
            },

            get selectedField() {
                if (!this.selectedGroup) return null;
                return this.selectedGroup.fields.find(f => f.id === this.selectedFieldId) || null;
            },

            // ── Selection / accordion ─────────────────────────────
            toggleGroup(id) {
                if (this.selectedGroupId === id) {
                    this.selectedGroupId = null;
                    this.selectedFieldId = null;
                    return;
                }
                this.selectedGroupId = id;
                this.selectedFieldId = null;
                this.$nextTick(() => this.initSortable());
            },

            selectField(id) {
                this.selectedFieldId = id;
            },

            focusName(groupId) {
                this.$nextTick(() => {
                    // Tell the card's title island to switch into edit mode
                    // and focus the input. The island listens on
                    // `qb-edit-title` and matches against its group.id.
                    window.dispatchEvent(new CustomEvent('qb-edit-title', { detail: groupId }));
                });
            },

            // ── ID generation ─────────────────────────────────────
            _newId(prefix) {
                return prefix + '_' + Math.random().toString(36).slice(2, 9);
            },

            // ── Group CRUD ────────────────────────────────────────
            addGroup() {
                const stageKeys = Object.keys(this.stages);
                const id = this._newId('tmp_g');
                this.groups.push({
                    id,
                    label: '',
                    stage: stageKeys[0] || '',
                    assignments: [],
                    fields: [],
                });
                this.selectedGroupId = id;
                this.selectedFieldId = null;
                this.isDirty = true;
                // New group → focus name input so the editor can type
                this.focusName(id);
            },

            deleteGroup(id) {
                const idx = this.groups.findIndex(g => g.id === id);
                if (idx === -1) return;
                this.groups.splice(idx, 1);
                if (this.selectedGroupId === id) {
                    this.selectedGroupId = this.groups[0]?.id || null;
                    this.selectedFieldId = null;
                }
                this.isDirty = true;
            },

            // ── Field CRUD ────────────────────────────────────────
            addField() {
                if (!this.selectedGroup) return;
                const id = this._newId('tmp_f');
                this.selectedGroup.fields.push({
                    id,
                    name: '',
                    label: '',
                    help: '',
                    type: 'text',
                    required: false,
                    options: '',
                    min: 1,
                    max: 5,
                });
                this.selectedFieldId = id;
                this.isDirty = true;
            },

            duplicateField(id) {
                if (!this.selectedGroup) return;
                const src = this.selectedGroup.fields.find(f => f.id === id);
                if (!src) return;
                const copy = { ...src, id: this._newId('tmp_f'), label: src.label + ' (kopie)' };
                const idx = this.selectedGroup.fields.findIndex(f => f.id === id);
                this.selectedGroup.fields.splice(idx + 1, 0, copy);
                this.selectedFieldId = copy.id;
                this.isDirty = true;
            },

            deleteField(id) {
                if (!this.selectedGroup) return;
                const idx = this.selectedGroup.fields.findIndex(f => f.id === id);
                if (idx === -1) return;
                this.selectedGroup.fields.splice(idx, 1);
                if (this.selectedFieldId === id) {
                    this.selectedFieldId = null;
                }
                this.isDirty = true;
            },

            // ── Read-only display helpers ─────────────────────────
            fieldMeta(field) {
                const typeLabel = this.fieldTypes[field.type]?.label || field.type;
                const reqLabel = field.required ? 'vereist' : 'optioneel';
                if (field.type === 'select' || field.type === 'radio') {
                    const count = (field.options || '').split(/\r?\n/).filter(Boolean).length;
                    return typeLabel + ' · ' + count + ' opties · ' + reqLabel;
                }
                if (field.type === 'description') {
                    return typeLabel;
                }
                return typeLabel + ' · ' + reqLabel;
            },

            groupMeta(group) {
                const fieldCount = (group.fields || []).length;
                return fieldCount === 1 ? '1 veld' : fieldCount + ' velden';
            },

            // ── Assignments helpers ───────────────────────────────
            // group.assignments holds strings (post IDs as strings + wildcard
            // strings like _all_editions) — matches the hydrate-normalized
            // shape from init(). isAssigned + toggleAssignment cast the
            // candidate to string before comparing so int/string mix doesn't
            // bite us.
            isAssigned(group, value) {
                const list = group.assignments || [];
                return list.indexOf(String(value)) !== -1;
            },

            toggleAssignment(group, value) {
                const str = String(value);
                if (!Array.isArray(group.assignments)) {
                    group.assignments = [];
                }
                const idx = group.assignments.indexOf(str);
                if (idx === -1) {
                    group.assignments.push(str);
                } else {
                    group.assignments.splice(idx, 1);
                }
                this.isDirty = true;
            },

            assignButtonLabel(group) {
                const sel = group.assignments || [];
                if (sel.length === 0) return 'Niet gekoppeld';

                // Resolve each selected value to its option label by walking
                // the optgroup tree. Server stores wildcards as strings
                // (_all_editions, _all_trajectories) and post IDs as ints;
                // hydrate normalized everything to strings, so option.value
                // string-compares cleanly.
                const labels = [];
                for (const value of sel) {
                    const str = String(value);
                    let found = null;
                    for (const grp of this.assignments || []) {
                        const match = (grp.options || []).find(o => String(o.value) === str);
                        if (match) { found = match.label; break; }
                    }
                    if (found) labels.push(found);
                }

                // 1 selection → show its label; 2 → "A + B"; 3+ → "A + N";
                // anything missing → count fallback.
                if (labels.length === 1) return labels[0];
                if (labels.length === 2) return labels[0] + ' + ' + labels[1];
                if (labels.length >= 3) return labels[0] + ' + ' + (labels.length - 1);
                return sel.length + ' toewijzingen';
            },

            // ── Drag-drop ─────────────────────────────────────────
            initSortable() {
                if (typeof jQuery === 'undefined' || !jQuery.ui || !jQuery.ui.sortable) {
                    return; // graceful fallback — drag disabled, no JS error
                }
                const $list = jQuery(this.$refs.fieldList);
                if (!$list.length) return;
                if ($list.data('uiSortable')) {
                    $list.sortable('destroy');
                }
                const self = this;
                $list.sortable({
                    items: '> li',
                    handle: '.qb-field-row__grab',
                    cursor: 'grabbing',
                    placeholder: 'qb-field-row qb-field-row--placeholder ui-sortable-placeholder',
                    forcePlaceholderSize: true,
                    update: function () {
                        if (!self.selectedGroup) return;
                        const newOrder = [];
                        $list.find('> li').each(function () {
                            const id = jQuery(this).data('fieldId');
                            const field = self.selectedGroup.fields.find(f => f.id === id);
                            if (field) newOrder.push(field);
                        });
                        self.selectedGroup.fields = newOrder;
                        self.isDirty = true;
                    },
                });
            },
        };
    }

    document.addEventListener('alpine:init', () => {
        window.Alpine.data('questionnaireBuilder', questionnaireBuilder);
    });
})();
