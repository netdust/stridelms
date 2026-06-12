<?php
/**
 * Settings tab: Bedrijf (Company details)
 *
 * Alpine.js bindings — part of strideSettingsApp() component.
 * Used for PDF quote generation and email footers.
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<h2>Bedrijfsgegevens</h2>

<p class="description">
    Deze gegevens worden gebruikt op offertes (PDF) en in e-mails.
</p>

<table class="form-table" role="presentation">
    <tbody>
        <tr>
            <th scope="row">
                <label>Logo</label>
            </th>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <template x-if="company.logo">
                        <div>
                            <img :src="company.logo" alt="Logo" style="max-height: 60px; max-width: 200px; border: 1px solid #ddd; border-radius: 4px; padding: 4px;">
                        </div>
                    </template>
                    <button type="button" class="button" @click="selectLogo()">
                        <span x-text="company.logo ? 'Wijzigen' : 'Logo kiezen'"></span>
                    </button>
                    <template x-if="company.logo">
                        <button type="button" class="button" @click="removeLogo()" style="color: #d63638;">Verwijderen</button>
                    </template>
                </div>
                <p class="description">Wordt getoond op offertes (PDF). Aanbevolen: transparante PNG, max 200px breed.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-name">Bedrijfsnaam <span class="required">*</span></label>
            </th>
            <td>
                <input type="text" id="stride-company-name" class="regular-text"
                       x-model="company.name" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-address">Adres</label>
            </th>
            <td>
                <input type="text" id="stride-company-address" class="regular-text"
                       x-model="company.address" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-postal">Postcode</label>
            </th>
            <td>
                <input type="text" id="stride-company-postal" class="small-text"
                       x-model="company.postal_code" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-city">Stad</label>
            </th>
            <td>
                <input type="text" id="stride-company-city" class="regular-text"
                       x-model="company.city" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-country">Land</label>
            </th>
            <td>
                <input type="text" id="stride-company-country" class="regular-text"
                       x-model="company.country" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-vat">BTW-nummer</label>
            </th>
            <td>
                <input type="text" id="stride-company-vat" class="regular-text"
                       x-model="company.vat"
                       placeholder="BE0123.456.789" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-email">E-mail</label>
            </th>
            <td>
                <input type="email" id="stride-company-email" class="regular-text"
                       x-model="company.email" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-phone">Telefoon</label>
            </th>
            <td>
                <input type="text" id="stride-company-phone" class="regular-text"
                       x-model="company.phone" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-bank">Bankrekening (IBAN)</label>
            </th>
            <td>
                <input type="text" id="stride-company-bank" class="regular-text"
                       x-model="company.bank_account"
                       placeholder="BE00 0000 0000 0000" />
            </td>
        </tr>
    </tbody>
</table>

<p class="submit">
    <button type="button"
            class="button button-primary"
            :disabled="saving || !company.name?.trim()"
            @click="saveCompany()">
        <span x-show="!saving">Opslaan</span>
        <span x-show="saving">Bezig met opslaan&hellip;</span>
    </button>
</p>
