<?php
/**
 * Settings tab: Algemeen (General / URL slugs)
 *
 * Alpine.js bindings — part of strideSettingsApp() component.
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<h2>URL Slugs</h2>

<p class="description">
    Configureer de URL slugs voor trajecten en vormingen. Wijzigingen worden direct toegepast op de URLs.
</p>

<table class="form-table" role="presentation">
    <tbody>
        <tr>
            <th scope="row">
                <label for="stride-trajectory-slug">Trajecten URL</label>
            </th>
            <td>
                <input type="text"
                       id="stride-trajectory-slug"
                       class="regular-text"
                       x-model="general.trajectory_slug"
                       @input="general.trajectory_slug = slugify($event.target.value)" />
                <p class="description">
                    URL: <span x-text="general.siteUrl"></span>/<strong x-text="general.trajectory_slug || 'trajecten'"></strong>/traject-naam/
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-edition-slug">Vormingen URL</label>
            </th>
            <td>
                <input type="text"
                       id="stride-edition-slug"
                       class="regular-text"
                       x-model="general.edition_slug"
                       @input="general.edition_slug = slugify($event.target.value)" />
                <p class="description">
                    URL: <span x-text="general.siteUrl"></span>/<strong x-text="general.edition_slug || 'vormingen'"></strong>/editie-naam/
                </p>
            </td>
        </tr>
    </tbody>
</table>

<p class="submit">
    <button type="button"
            class="button button-primary"
            :disabled="saving"
            @click="saveGeneral()">
        <span x-show="!saving">Opslaan</span>
        <span x-show="saving">Bezig met opslaan&hellip;</span>
    </button>

    <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>"
       class="button" style="margin-left: 8px;">
        Permalinks opnieuw opslaan
    </a>
</p>

<p class="description" style="margin-top: 4px;">
    <strong>Let op:</strong> Na wijzigen van URL slugs kan het nodig zijn om de
    <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>">permalinks opnieuw op te slaan</a>.
</p>
