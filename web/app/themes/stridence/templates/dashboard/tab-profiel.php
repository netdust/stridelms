<?php
/**
 * Dashboard Tab: Profiel (Profile)
 *
 * Profile editing with inline edit pattern - fields become editable on click.
 * Uses ntdstAPI for form submission to ProfileHandler.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Personal data
$first_name   = $user->first_name;
$last_name    = $user->last_name;
$email        = $user->user_email;
$phone        = get_user_meta($user_id, 'phone', true);
$organisation = get_user_meta($user_id, 'organisation', true);
$department   = get_user_meta($user_id, 'department', true);

// Billing data (meta keys match getUserMetaMapping)
$billing = [
    'company'     => get_user_meta($user_id, 'billing_company', true),
    'vat_number'  => get_user_meta($user_id, 'billing_vat', true),
    'address'     => get_user_meta($user_id, 'billing_address_1', true),
    'postal_code' => get_user_meta($user_id, 'billing_postcode', true),
    'city'        => get_user_meta($user_id, 'billing_city', true),
    'invoice_email' => get_user_meta($user_id, 'invoice_email', true),
    'gln_number'  => get_user_meta($user_id, 'gln_number', true),
];

// Notification preferences
$notifications = [
    'reminders'    => get_user_meta($user_id, 'stride_notify_reminders', true) !== 'no',
    'new_courses'  => get_user_meta($user_id, 'stride_notify_new_courses', true) !== 'no',
    'newsletter'   => get_user_meta($user_id, 'stride_notify_newsletter', true) === 'yes',
    'language'     => get_user_meta($user_id, 'stride_communication_language', true) ?: 'nl',
];
?>

<div class="space-y-6">

    <!-- Personal Information -->
    <section class="dash-card"
             x-data="inlineEditSection({
                 action: 'stride_update_profile',
                 params: { form_type: 'personal' },
                 fields: <?php echo esc_attr(json_encode([
                     'first_name'   => $first_name,
                     'last_name'    => $last_name,
                     'phone'        => $phone,
                     'organisation' => $organisation,
                     'department'   => $department,
                 ])); ?>
             })">
        <div class="p-4 border-b border-border flex items-center justify-between">
            <h2 class="font-heading text-lg font-bold text-text flex items-center gap-2">
                <?php echo stridence_icon('user', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Persoonlijke gegevens', 'stridence'); ?>
            </h2>
            <template x-if="!editing">
                <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline">
                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                    <?php esc_html_e('Bewerken', 'stridence'); ?>
                </button>
            </template>
        </div>

        <div class="p-4">
            <!-- Display mode -->
            <dl x-show="!editing" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('Naam', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.first_name + ' ' + fields.last_name"></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('E-mailadres', 'stridence'); ?></dt>
                    <dd class="font-medium"><?php echo esc_html($email); ?></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('Telefoonnummer', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.phone || '-'"></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('Organisatie', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.organisation || '-'"></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('Afdeling', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.department || '-'"></dd>
                </div>
            </dl>

            <!-- Edit mode -->
            <div x-show="editing" x-transition class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="input-label"><?php esc_html_e('Voornaam', 'stridence'); ?></label>
                        <input type="text" x-model="fields.first_name" class="input-text" required>
                    </div>
                    <div>
                        <label class="input-label"><?php esc_html_e('Achternaam', 'stridence'); ?></label>
                        <input type="text" x-model="fields.last_name" class="input-text" required>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="input-label"><?php esc_html_e('E-mailadres', 'stridence'); ?></label>
                        <input type="email" value="<?php echo esc_attr($email); ?>" class="input-text bg-surface-alt cursor-not-allowed" disabled>
                        <p class="input-hint"><?php esc_html_e('Neem contact op om je e-mailadres te wijzigen.', 'stridence'); ?></p>
                    </div>
                    <div>
                        <label class="input-label"><?php esc_html_e('Telefoonnummer', 'stridence'); ?></label>
                        <input type="tel" x-model="fields.phone" class="input-text" placeholder="+32 ...">
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="input-label"><?php esc_html_e('Organisatie', 'stridence'); ?></label>
                        <input type="text" x-model="fields.organisation" class="input-text" placeholder="<?php esc_attr_e('Naam van je werkgever', 'stridence'); ?>">
                    </div>
                    <div>
                        <label class="input-label"><?php esc_html_e('Afdeling', 'stridence'); ?></label>
                        <input type="text" x-model="fields.department" class="input-text">
                    </div>
                </div>

                <!-- Error -->
                <div x-show="error" class="p-2 bg-error/10 rounded text-sm text-error" x-text="error"></div>

                <!-- Actions -->
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="cancelEdit()" class="btn-secondary btn-sm">
                        <?php esc_html_e('Annuleren', 'stridence'); ?>
                    </button>
                    <button type="button" @click="saveEdit()" :disabled="saving" class="btn-primary btn-sm">
                        <span x-show="!saving"><?php esc_html_e('Opslaan', 'stridence'); ?></span>
                        <span x-show="saving" class="flex items-center gap-1">
                            <span class="spinner w-3 h-3"></span>
                            <?php esc_html_e('Opslaan...', 'stridence'); ?>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Billing Information -->
    <section class="dash-card"
             x-data="inlineEditSection({
                 action: 'stride_update_profile',
                 params: { form_type: 'billing' },
                 fields: <?php echo esc_attr(json_encode([
                     'company'       => $billing['company'],
                     'vat_number'    => $billing['vat_number'],
                     'address'       => $billing['address'],
                     'postal_code'   => $billing['postal_code'],
                     'city'          => $billing['city'],
                     'invoice_email' => $billing['invoice_email'],
                     'gln_number'    => $billing['gln_number'],
                 ])); ?>
             })">
        <div class="p-4 border-b border-border flex items-center justify-between">
            <div>
                <h2 class="font-heading text-lg font-bold text-text flex items-center gap-2">
                    <?php echo stridence_icon('file-text', 'w-5 h-5 text-primary'); ?>
                    <?php esc_html_e('Facturatiegegevens', 'stridence'); ?>
                </h2>
                <p class="text-sm text-text-muted mt-1">
                    <?php esc_html_e('Deze gegevens worden gebruikt voor offertes en facturen.', 'stridence'); ?>
                </p>
            </div>
            <template x-if="!editing">
                <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline shrink-0">
                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                    <?php esc_html_e('Bewerken', 'stridence'); ?>
                </button>
            </template>
        </div>

        <div class="p-4">
            <!-- Display mode -->
            <dl x-show="!editing" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('Organisatie', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.company || '-'"></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('BTW-nummer', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.vat_number || '-'"></dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-text-muted"><?php esc_html_e('Adres', 'stridence'); ?></dt>
                    <dd class="font-medium">
                        <template x-if="fields.address">
                            <span>
                                <span x-text="fields.address"></span>,
                                <span x-text="fields.postal_code"></span>
                                <span x-text="fields.city"></span>
                            </span>
                        </template>
                        <template x-if="!fields.address">
                            <span>-</span>
                        </template>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('Facturatie e-mail', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.invoice_email || '-'"></dd>
                </div>
                <div>
                    <dt class="text-sm text-text-muted"><?php esc_html_e('GLN-nummer', 'stridence'); ?></dt>
                    <dd class="font-medium" x-text="fields.gln_number || '-'"></dd>
                </div>
            </dl>

            <!-- Edit mode -->
            <div x-show="editing" x-transition class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="input-label"><?php esc_html_e('Organisatie', 'stridence'); ?></label>
                        <input type="text" x-model="fields.company" class="input-text">
                    </div>
                    <div>
                        <label class="input-label"><?php esc_html_e('BTW-nummer', 'stridence'); ?></label>
                        <input type="text" x-model="fields.vat_number" class="input-text" placeholder="BE0123456789">
                    </div>
                </div>

                <div>
                    <label class="input-label"><?php esc_html_e('Adres', 'stridence'); ?></label>
                    <input type="text" x-model="fields.address" class="input-text">
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="input-label"><?php esc_html_e('Postcode', 'stridence'); ?></label>
                        <input type="text" x-model="fields.postal_code" class="input-text">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="input-label"><?php esc_html_e('Gemeente', 'stridence'); ?></label>
                        <input type="text" x-model="fields.city" class="input-text">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="input-label"><?php esc_html_e('Facturatie e-mail', 'stridence'); ?></label>
                        <input type="email" x-model="fields.invoice_email" class="input-text" placeholder="<?php echo esc_attr($email); ?>">
                        <p class="input-hint"><?php esc_html_e('Laat leeg om je hoofde-mailadres te gebruiken.', 'stridence'); ?></p>
                    </div>
                    <div>
                        <label class="input-label">
                            <?php esc_html_e('GLN-nummer', 'stridence'); ?>
                            <span class="text-text-muted font-normal">(<?php esc_html_e('optioneel', 'stridence'); ?>)</span>
                        </label>
                        <input type="text" x-model="fields.gln_number" class="input-text">
                    </div>
                </div>

                <!-- Error -->
                <div x-show="error" class="p-2 bg-error/10 rounded text-sm text-error" x-text="error"></div>

                <!-- Actions -->
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="cancelEdit()" class="btn-secondary btn-sm">
                        <?php esc_html_e('Annuleren', 'stridence'); ?>
                    </button>
                    <button type="button" @click="saveEdit()" :disabled="saving" class="btn-primary btn-sm">
                        <span x-show="!saving"><?php esc_html_e('Opslaan', 'stridence'); ?></span>
                        <span x-show="saving" class="flex items-center gap-1">
                            <span class="spinner w-3 h-3"></span>
                            <?php esc_html_e('Opslaan...', 'stridence'); ?>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Notification Preferences -->
    <section class="dash-card"
             x-data="inlineEditSection({
                 action: 'stride_update_profile',
                 params: { form_type: 'notifications' },
                 fields: <?php echo esc_attr(json_encode([
                     'notify_reminders'        => $notifications['reminders'],
                     'notify_new_courses'      => $notifications['new_courses'],
                     'notify_newsletter'       => $notifications['newsletter'],
                     'communication_language'  => $notifications['language'],
                 ])); ?>
             })">
        <div class="p-4 border-b border-border flex items-center justify-between">
            <h2 class="font-heading text-lg font-bold text-text flex items-center gap-2">
                <?php echo stridence_icon('bell', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Meldingsvoorkeuren', 'stridence'); ?>
            </h2>
            <template x-if="!editing">
                <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline">
                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                    <?php esc_html_e('Bewerken', 'stridence'); ?>
                </button>
            </template>
        </div>

        <div class="p-4">
            <!-- Display mode -->
            <dl x-show="!editing" class="space-y-3">
                <div class="flex items-center justify-between">
                    <dt class="text-sm"><?php esc_html_e('Herinneringen', 'stridence'); ?></dt>
                    <dd>
                        <span x-show="fields.notify_reminders" class="text-green-600 text-sm"><?php esc_html_e('Aan', 'stridence'); ?></span>
                        <span x-show="!fields.notify_reminders" class="text-text-muted text-sm"><?php esc_html_e('Uit', 'stridence'); ?></span>
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-sm"><?php esc_html_e('Nieuwe opleidingen', 'stridence'); ?></dt>
                    <dd>
                        <span x-show="fields.notify_new_courses" class="text-green-600 text-sm"><?php esc_html_e('Aan', 'stridence'); ?></span>
                        <span x-show="!fields.notify_new_courses" class="text-text-muted text-sm"><?php esc_html_e('Uit', 'stridence'); ?></span>
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-sm"><?php esc_html_e('Nieuwsbrief', 'stridence'); ?></dt>
                    <dd>
                        <span x-show="fields.notify_newsletter" class="text-green-600 text-sm"><?php esc_html_e('Aan', 'stridence'); ?></span>
                        <span x-show="!fields.notify_newsletter" class="text-text-muted text-sm"><?php esc_html_e('Uit', 'stridence'); ?></span>
                    </dd>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-border">
                    <dt class="text-sm"><?php esc_html_e('Communicatietaal', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium">
                        <span x-show="fields.communication_language === 'nl'"><?php esc_html_e('Nederlands', 'stridence'); ?></span>
                        <span x-show="fields.communication_language === 'fr'"><?php esc_html_e('Frans', 'stridence'); ?></span>
                        <span x-show="fields.communication_language === 'en'"><?php esc_html_e('Engels', 'stridence'); ?></span>
                    </dd>
                </div>
            </dl>

            <!-- Edit mode -->
            <div x-show="editing" x-transition class="space-y-4">
                <div class="space-y-3">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="fields.notify_reminders" class="input-checkbox mt-0.5">
                        <div>
                            <span class="text-sm font-medium text-text"><?php esc_html_e('Herinneringen', 'stridence'); ?></span>
                            <p class="text-sm text-text-muted"><?php esc_html_e('Ontvang herinneringen voor aankomende sessies en deadlines.', 'stridence'); ?></p>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="fields.notify_new_courses" class="input-checkbox mt-0.5">
                        <div>
                            <span class="text-sm font-medium text-text"><?php esc_html_e('Nieuwe opleidingen', 'stridence'); ?></span>
                            <p class="text-sm text-text-muted"><?php esc_html_e('Word geïnformeerd over nieuwe opleidingen in jouw vakgebied.', 'stridence'); ?></p>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="fields.notify_newsletter" class="input-checkbox mt-0.5">
                        <div>
                            <span class="text-sm font-medium text-text"><?php esc_html_e('Nieuwsbrief', 'stridence'); ?></span>
                            <p class="text-sm text-text-muted"><?php esc_html_e('Ontvang onze maandelijkse nieuwsbrief met tips en nieuws.', 'stridence'); ?></p>
                        </div>
                    </label>
                </div>

                <div class="pt-2 border-t border-border">
                    <label class="input-label"><?php esc_html_e('Communicatietaal', 'stridence'); ?></label>
                    <select x-model="fields.communication_language" class="input-select w-full sm:w-auto">
                        <option value="nl"><?php esc_html_e('Nederlands', 'stridence'); ?></option>
                        <option value="fr"><?php esc_html_e('Frans', 'stridence'); ?></option>
                        <option value="en"><?php esc_html_e('Engels', 'stridence'); ?></option>
                    </select>
                </div>

                <!-- Error -->
                <div x-show="error" class="p-2 bg-error/10 rounded text-sm text-error" x-text="error"></div>

                <!-- Actions -->
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="cancelEdit()" class="btn-secondary btn-sm">
                        <?php esc_html_e('Annuleren', 'stridence'); ?>
                    </button>
                    <button type="button" @click="saveEdit()" :disabled="saving" class="btn-primary btn-sm">
                        <span x-show="!saving"><?php esc_html_e('Opslaan', 'stridence'); ?></span>
                        <span x-show="saving" class="flex items-center gap-1">
                            <span class="spinner w-3 h-3"></span>
                            <?php esc_html_e('Opslaan...', 'stridence'); ?>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Logout -->
    <section class="dash-card p-4">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="font-medium text-text">
                    <?php esc_html_e('Uitloggen', 'stridence'); ?>
                </h3>
                <p class="text-sm text-text-muted">
                    <?php esc_html_e('Beëindig je huidige sessie.', 'stridence'); ?>
                </p>
            </div>
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>"
               class="btn-ghost text-sm text-error hover:bg-error/5">
                <?php echo stridence_icon('log-out', 'w-4 h-4 mr-2'); ?>
                <?php esc_html_e('Uitloggen', 'stridence'); ?>
            </a>
        </div>
    </section>
</div>
