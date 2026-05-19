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
                this.groups = seed.groups || [];
                this.fieldTypes = seed.fieldTypes || {};
                this.stages = seed.stages || {};
                this.assignments = seed.assignments || [];

                if (this.groups.length > 0) {
                    this.selectedGroupId = this.groups[0].id;
                }
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
            },

            selectField(id) {
                this.selectedFieldId = id;
            },
        };
    }

    document.addEventListener('alpine:init', () => {
        window.Alpine.data('questionnaireBuilder', questionnaireBuilder);
    });
})();
