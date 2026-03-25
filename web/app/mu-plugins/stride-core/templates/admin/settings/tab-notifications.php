<?php
/**
 * Settings tab: Meldingen (Action Queue Rules)
 *
 * Configures which alerts appear on the dashboard and their thresholds.
 * Data binding: notifications object from strideSettings.notifications
 */
defined('ABSPATH') || exit;
?>

<div class="stride-settings__section">
    <h2>Meldingen</h2>
    <p class="description">Configureer welke meldingen op het dashboard verschijnen en wanneer ze worden geactiveerd.</p>

    <table class="form-table" role="presentation">
        <!-- Editie bijna vol -->
        <tr>
            <th scope="row">
                <label>
                    <input type="checkbox" x-model="notifications.capacity_threshold.enabled">
                    Editie bijna vol
                </label>
            </th>
            <td>
                <input type="number" x-model.number="notifications.capacity_threshold.value"
                       class="small-text" min="1" max="100"
                       :disabled="!notifications.capacity_threshold.enabled"> %
            </td>
        </tr>
        <!-- Sessie nadert -->
        <tr>
            <th scope="row">
                <label>
                    <input type="checkbox" x-model="notifications.session_approaching.enabled">
                    Sessie nadert
                </label>
            </th>
            <td>
                <input type="number" x-model.number="notifications.session_approaching.value"
                       class="small-text" min="1" max="30"
                       :disabled="!notifications.session_approaching.enabled"> dag(en) voor aanvang
            </td>
        </tr>
        <!-- Offerte niet verzonden -->
        <tr>
            <th scope="row">
                <label>
                    <input type="checkbox" x-model="notifications.stale_quote.enabled">
                    Offerte niet verzonden
                </label>
            </th>
            <td>
                <input type="number" x-model.number="notifications.stale_quote.value"
                       class="small-text" min="1" max="90"
                       :disabled="!notifications.stale_quote.enabled"> dag(en) als concept
            </td>
        </tr>
        <!-- Goedkeuring nodig -->
        <tr>
            <th scope="row">
                <label>
                    <input type="checkbox" x-model="notifications.pending_approval.enabled">
                    Goedkeuring nodig
                </label>
            </th>
            <td>
                <span class="description">Altijd actief wanneer ingeschakeld</span>
            </td>
        </tr>
        <!-- Editie start binnenkort -->
        <tr>
            <th scope="row">
                <label>
                    <input type="checkbox" x-model="notifications.edition_starting.enabled">
                    Editie start binnenkort
                </label>
            </th>
            <td>
                <input type="number" x-model.number="notifications.edition_starting.value"
                       class="small-text" min="1" max="30"
                       :disabled="!notifications.edition_starting.enabled"> dag(en) voor start
            </td>
        </tr>
        <!-- Taken niet afgerond -->
        <tr>
            <th scope="row">
                <label>
                    <input type="checkbox" x-model="notifications.incomplete_tasks.enabled">
                    Taken niet afgerond
                </label>
            </th>
            <td>
                <input type="number" x-model.number="notifications.incomplete_tasks.value"
                       class="small-text" min="1" max="90"
                       :disabled="!notifications.incomplete_tasks.enabled"> dag(en) na laatste sessie
            </td>
        </tr>
    </table>

    <p class="submit">
        <button type="button" class="button button-primary"
                @click="saveTab('notifications')"
                :disabled="saving">
            <span x-show="!saving">Opslaan</span>
            <span x-show="saving">Opslaan...</span>
        </button>
    </p>
</div>
