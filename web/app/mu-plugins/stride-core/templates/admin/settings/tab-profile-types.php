<?php
/**
 * Settings tab: Profieltypes (Profile Types CRUD)
 *
 * Alpine.js bindings — part of strideSettingsApp() component.
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<h2>Profieltypes</h2>

<p class="description">
    Beheer de profieltypes die gebruikers kunnen kiezen bij registratie en in hun profiel.
</p>

<!-- Types table (when types exist or editing) -->
<table class="stride-profile-types-table"
       x-show="types.length > 0 || isNew">
    <thead>
        <tr>
            <th style="width: 50px;">Kleur</th>
            <th>Naam</th>
            <th>Slug</th>
            <th>Omschrijving</th>
            <th>Icoon</th>
            <th style="width: 80px;">Gebruikers</th>
            <th style="width: 100px;">Acties</th>
        </tr>
    </thead>
    <tbody>

        <!-- View rows -->
        <template x-for="(type, index) in types" :key="type.slug || index">
            <tr x-show="editingIndex !== index">
                <td>
                    <span class="stride-type-color"
                          :style="'background-color: ' + (type.color || '#3B82F6')"></span>
                </td>
                <td x-text="type.label"></td>
                <td><code class="stride-type-slug" x-text="type.slug"></code></td>
                <td x-text="type.description || '—'"></td>
                <td>
                    <span class="dashicons"
                          :class="'dashicons-' + (type.icon || 'users')"></span>
                </td>
                <td x-text="type.userCount || 0"></td>
                <td>
                    <div class="stride-type-actions">
                        <button type="button"
                                title="Bewerken"
                                @click="startEdit(index)">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                        <button type="button"
                                class="is-destructive"
                                title="Verwijderen"
                                @click="requestDelete(index)">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </td>
            </tr>
        </template>

        <!-- Edit rows (existing types) -->
        <template x-for="(type, index) in types" :key="'edit-' + (type.slug || index)">
            <tr x-show="editingIndex === index && !isNew" class="stride-type-edit-row">
                <td colspan="7">
                    <div class="stride-edit-fields">
                        <div class="stride-edit-field">
                            <label>Naam</label>
                            <input type="text"
                                   class="regular-text"
                                   x-model="editForm.label"
                                   placeholder="Naam van het profieltype" />
                        </div>
                        <div class="stride-edit-field">
                            <label>Slug</label>
                            <input type="text"
                                   class="regular-text"
                                   x-model="editForm.slug"
                                   disabled
                                   readonly />
                        </div>
                        <div class="stride-edit-field">
                            <label>Omschrijving</label>
                            <input type="text"
                                   class="regular-text"
                                   x-model="editForm.description"
                                   placeholder="Korte omschrijving" />
                        </div>
                        <div class="stride-edit-field">
                            <label>Kleur</label>
                            <input type="color"
                                   x-model="editForm.color" />
                        </div>
                        <div class="stride-edit-field">
                            <label>Icoon</label>
                            <select x-model="editForm.icon">
                                <template x-for="icon in availableIcons" :key="icon">
                                    <option :value="icon" x-text="icon"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="stride-edit-actions">
                        <button type="button"
                                class="button button-primary"
                                @click="saveType()">
                            OK
                        </button>
                        <button type="button"
                                class="button"
                                @click="cancelEdit()">
                            Annuleren
                        </button>
                    </div>
                </td>
            </tr>
        </template>

        <!-- New type row -->
        <template x-if="isNew && editingIndex === types.length">
            <tr class="stride-type-edit-row">
                <td colspan="7">
                    <div class="stride-edit-fields">
                        <div class="stride-edit-field">
                            <label>Naam</label>
                            <input type="text"
                                   class="regular-text"
                                   x-model="editForm.label"
                                   @input="editForm.slug = slugify(editForm.label)"
                                   placeholder="Naam van het profieltype" />
                        </div>
                        <div class="stride-edit-field">
                            <label>Slug</label>
                            <input type="text"
                                   class="regular-text"
                                   x-model="editForm.slug"
                                   placeholder="wordt-automatisch-gegenereerd" />
                        </div>
                        <div class="stride-edit-field">
                            <label>Omschrijving</label>
                            <input type="text"
                                   class="regular-text"
                                   x-model="editForm.description"
                                   placeholder="Korte omschrijving" />
                        </div>
                        <div class="stride-edit-field">
                            <label>Kleur</label>
                            <input type="color"
                                   x-model="editForm.color" />
                        </div>
                        <div class="stride-edit-field">
                            <label>Icoon</label>
                            <select x-model="editForm.icon">
                                <template x-for="icon in availableIcons" :key="icon">
                                    <option :value="icon" x-text="icon"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="stride-edit-actions">
                        <button type="button"
                                class="button button-primary"
                                @click="saveType()">
                            Toevoegen
                        </button>
                        <button type="button"
                                class="button"
                                @click="cancelEdit()">
                            Annuleren
                        </button>
                    </div>
                </td>
            </tr>
        </template>

    </tbody>
</table>

<!-- Empty state -->
<div class="stride-empty-state"
     x-show="types.length === 0 && !isNew">
    <span class="dashicons dashicons-groups"></span>
    <p>Nog geen profieltypes aangemaakt.</p>
</div>

<!-- Action bar -->
<p class="submit">
    <button type="button"
            class="button"
            :disabled="editingIndex !== -1"
            @click="startAdd()">
        <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-right: 2px;"></span>
        Profieltype toevoegen
    </button>
    <button type="button"
            class="button button-primary"
            style="margin-left: 8px;"
            :disabled="saving || editingIndex !== -1"
            @click="saveProfileTypes()">
        <span x-show="!saving">Opslaan</span>
        <span x-show="saving">Opslaan&hellip;</span>
    </button>
</p>

<!-- Delete confirmation modal -->
<template x-if="confirmDelete !== null">
    <div class="stride-confirm-overlay" @click.self="cancelDelete()">
        <div class="stride-confirm-dialog">
            <h3>Profieltype verwijderen</h3>
            <p x-show="types[confirmDelete] && types[confirmDelete].userCount > 0">
                <span x-text="types[confirmDelete].userCount"></span>
                gebruikers hebben dit profieltype.
                Weet je zeker dat je dit wilt verwijderen?
            </p>
            <p x-show="!types[confirmDelete] || !types[confirmDelete].userCount">
                Weet je zeker dat je dit profieltype wilt verwijderen?
            </p>
            <div class="stride-confirm-actions">
                <button type="button"
                        class="button"
                        @click="cancelDelete()">
                    Annuleren
                </button>
                <button type="button"
                        class="button button-primary"
                        style="background: #d63638; border-color: #d63638;"
                        @click="confirmDeleteType()">
                    Verwijderen
                </button>
            </div>
        </div>
    </div>
</template>
