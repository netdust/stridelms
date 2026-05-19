/**
 * Questionnaire Builder v2 — Alpine.js controller
 *
 * Single component owning all admin state. Hydrated from
 * window.strideQuestionnaireState seeded by wp_localize_script.
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
                    fields: (g.fields || []).map(f => ({ help: '', ...f })),
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

            // ── Selection ─────────────────────────────────────────
            selectGroup(id) {
                this.selectedGroupId = id;
                this.selectedFieldId = null;
                this.$nextTick(() => this.initSortable());
            },

            selectField(id) {
                this.selectedFieldId = id;
            },

            // ── ID generation ─────────────────────────────────────
            // New rows get a client-side `tmp_<random>` id. Server
            // assigns the real id on save; the existing sanitizeGroups()
            // accepts any string id, so this is safe.
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

            // ── Field-row meta hint ───────────────────────────────
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
                        // Read new order from DOM, reassign this.selectedGroup.fields
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
