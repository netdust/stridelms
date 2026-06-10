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

// Profile type data
$profileTypeService = ntdst_get(\Stride\Modules\User\ProfileTypeService::class);
$profileTypes = $profileTypeService->getTypes();
$currentProfileType = $profileTypeService->getUserType($user_id);

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

<div class="space-y-8">

    <!-- Personal Information -->
    <section x-data="inlineEditSection({
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
        <div class="flex items-center justify-between mb-3">
            <h3 class="dash-subheading flex items-center gap-2">
                <?php echo stridence_icon('user', 'w-4 h-4 text-primary'); ?>
                <?php esc_html_e('Persoonlijke gegevens', 'stridence'); ?>
            </h3>
            <template x-if="!editing">
                <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline">
                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                    <?php esc_html_e('Bewerken', 'stridence'); ?>
                </button>
            </template>
        </div>

        <div class="bg-surface-card rounded-xl border border-border shadow-sm p-4">
            <!-- Display mode -->
            <dl x-show="!editing" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Naam', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.first_name + ' ' + fields.last_name"></dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('E-mailadres', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text"><?php echo esc_html($email); ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Telefoonnummer', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.phone || '-'"></dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Organisatie', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.organisation || '-'"></dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Afdeling', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.department || '-'"></dd>
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
                        <span class="input-hint block"><?php esc_html_e('Neem contact op om je e-mailadres te wijzigen.', 'stridence'); ?></span>
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

    <?php if (!empty($profileTypes)): ?>
    <!-- Profile Type -->
    <section x-data="{
                 ...inlineEditSection({
                     action: 'stride_update_profile',
                     params: { form_type: 'profile_type' },
                     fields: <?php echo esc_attr(json_encode([
                         'profile_type' => $currentProfileType['slug'] ?? '',
                     ])); ?>
                 }),
                 profileTypes: <?php echo esc_attr(json_encode(
                     array_combine(
                         array_column($profileTypes, 'slug'),
                         array_map(fn($t) => ['label' => $t['label'], 'color' => $t['color']], $profileTypes),
                     ),
                 )); ?>,
                 get currentType() { return this.profileTypes[this.fields.profile_type] ?? null; },
             }">
        <div class="flex items-center justify-between mb-3">
            <h3 class="dash-subheading flex items-center gap-2">
                <?php echo stridence_icon('users', 'w-4 h-4 text-primary'); ?>
                <?php esc_html_e('Profieltype', 'stridence'); ?>
            </h3>
            <template x-if="!editing">
                <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline">
                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                    <?php esc_html_e('Bewerken', 'stridence'); ?>
                </button>
            </template>
        </div>

        <div class="bg-surface-card rounded-xl border border-border shadow-sm p-4">
            <!-- Display mode -->
            <dl x-show="!editing" class="grid grid-cols-1 gap-4">
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Profieltype', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text">
                        <template x-if="currentType">
                            <span>
                                <span class="inline-block w-2.5 h-2.5 rounded-full mr-1 align-middle"
                                      :style="'background-color: ' + currentType.color"></span>
                                <span x-text="currentType.label"></span>
                            </span>
                        </template>
                        <template x-if="!currentType">
                            <span class="text-text-muted"><?php esc_html_e('Niet ingesteld', 'stridence'); ?></span>
                        </template>
                    </dd>
                </div>
            </dl>

            <!-- Edit mode -->
            <div x-show="editing" x-transition class="space-y-4">
                <div>
                    <label class="input-label"><?php esc_html_e('Profieltype', 'stridence'); ?></label>
                    <select x-model="fields.profile_type" class="input-select">
                        <option value=""><?php esc_html_e('Selecteer je profieltype...', 'stridence'); ?></option>
                        <?php foreach ($profileTypes as $type): ?>
                            <option value="<?php echo esc_attr($type['slug']); ?>">
                                <?php echo esc_html($type['label']); ?>
                            </option>
                        <?php endforeach; ?>
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
    <?php endif; ?>

    <!-- Billing Information -->
    <section x-data="inlineEditSection({
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
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="dash-subheading flex items-center gap-2">
                    <?php echo stridence_icon('file-text', 'w-4 h-4 text-primary'); ?>
                    <?php esc_html_e('Facturatiegegevens', 'stridence'); ?>
                </h3>
                <span class="text-xs text-text-muted mt-1 block">
                    <?php esc_html_e('Deze gegevens worden gebruikt voor offertes en facturen.', 'stridence'); ?>
                </span>
            </div>
            <template x-if="!editing">
                <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline shrink-0">
                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                    <?php esc_html_e('Bewerken', 'stridence'); ?>
                </button>
            </template>
        </div>

        <div class="bg-surface-card rounded-xl border border-border shadow-sm p-4">
            <!-- Display mode -->
            <dl x-show="!editing" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Organisatie', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.company || '-'"></dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('BTW-nummer', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.vat_number || '-'"></dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Adres', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text">
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
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('Facturatie e-mail', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.invoice_email || '-'"></dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted mb-0.5"><?php esc_html_e('GLN-nummer', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text" x-text="fields.gln_number || '-'"></dd>
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
                        <span class="input-hint block"><?php esc_html_e('Laat leeg om je hoofde-mailadres te gebruiken.', 'stridence'); ?></span>
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

    <!-- Privacy & GDPR -->
    <section x-data="{ exportRequested: false, deleteRequested: false, deleteConfirm: false, error: '', saving: false }">
        <div class="mb-3">
            <h3 class="dash-subheading flex items-center gap-2">
                <?php echo stridence_icon('shield', 'w-4 h-4 text-primary'); ?>
                <?php esc_html_e('Privacy & GDPR', 'stridence'); ?>
            </h3>
            <span class="text-xs text-text-muted mt-1 block">
                <?php esc_html_e('Beheer je persoonsgegevens conform de AVG/GDPR-wetgeving.', 'stridence'); ?>
            </span>
        </div>

        <div class="bg-surface-card rounded-xl border border-border shadow-sm p-4 space-y-4">
            <!-- Export personal data -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-text m-0"><?php esc_html_e('Persoonsgegevens exporteren', 'stridence'); ?></p>
                    <p class="text-xs text-text-muted mt-0.5"><?php esc_html_e('Ontvang een kopie van al je opgeslagen gegevens per e-mail.', 'stridence'); ?></p>
                </div>
                <template x-if="!exportRequested">
                    <button type="button"
                            :disabled="saving"
                            @click="saving = true; error = '';
                                ntdstAPI.call('stride_gdpr_export').then(() => { exportRequested = true; }).catch(e => { error = e.message; }).finally(() => { saving = false; })"
                            class="btn-secondary btn-sm shrink-0">
                        <?php echo stridence_icon('download', 'w-3.5 h-3.5 inline mr-1'); ?>
                        <?php esc_html_e('Exporteren', 'stridence'); ?>
                    </button>
                </template>
                <template x-if="exportRequested">
                    <span class="text-xs text-success font-medium shrink-0 flex items-center gap-1">
                        <?php echo stridence_icon('check', 'w-3.5 h-3.5'); ?>
                        <?php esc_html_e('Aanvraag verstuurd', 'stridence'); ?>
                    </span>
                </template>
            </div>

            <hr class="border-border">

            <!-- Delete account -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-text m-0"><?php esc_html_e('Account verwijderen', 'stridence'); ?></p>
                    <p class="text-xs text-text-muted mt-0.5"><?php esc_html_e('Vraag verwijdering van je account en alle bijbehorende gegevens aan. Dit kan niet ongedaan worden gemaakt.', 'stridence'); ?></p>
                </div>
                <template x-if="!deleteRequested">
                    <div class="shrink-0">
                        <template x-if="!deleteConfirm">
                            <button type="button" @click="deleteConfirm = true" class="btn-secondary btn-sm text-error border-error/30 hover:bg-error/5">
                                <?php esc_html_e('Verwijderen', 'stridence'); ?>
                            </button>
                        </template>
                        <template x-if="deleteConfirm">
                            <div class="flex items-center gap-2">
                                <button type="button" @click="deleteConfirm = false" class="btn-secondary btn-sm">
                                    <?php esc_html_e('Annuleren', 'stridence'); ?>
                                </button>
                                <button type="button"
                                        :disabled="saving"
                                        @click="saving = true; error = '';
                                            ntdstAPI.call('stride_gdpr_erase').then(() => { deleteRequested = true; deleteConfirm = false; }).catch(e => { error = e.message; }).finally(() => { saving = false; })"
                                        class="btn-primary btn-sm !bg-error hover:!bg-error/90">
                                    <?php esc_html_e('Bevestig verwijdering', 'stridence'); ?>
                                </button>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="deleteRequested">
                    <span class="text-xs text-success font-medium shrink-0 flex items-center gap-1">
                        <?php echo stridence_icon('check', 'w-3.5 h-3.5'); ?>
                        <?php esc_html_e('Aanvraag verstuurd', 'stridence'); ?>
                    </span>
                </template>
            </div>

            <!-- Error -->
            <div x-show="error" class="p-2 bg-error/10 rounded text-sm text-error" x-text="error"></div>
        </div>
    </section>

    <!-- Notification Preferences -->
    <section x-data="inlineEditSection({
                 action: 'stride_update_profile',
                 params: { form_type: 'notifications' },
                 fields: <?php echo esc_attr(json_encode([
                     'notify_reminders'        => $notifications['reminders'],
                     'notify_new_courses'      => $notifications['new_courses'],
                     'notify_newsletter'       => $notifications['newsletter'],
                     'communication_language'  => $notifications['language'],
                 ])); ?>
             })">
        <div class="flex items-center justify-between mb-3">
            <h3 class="dash-subheading flex items-center gap-2">
                <?php echo stridence_icon('bell', 'w-4 h-4 text-primary'); ?>
                <?php esc_html_e('Meldingsvoorkeuren', 'stridence'); ?>
            </h3>
            <template x-if="!editing">
                <button type="button" @click="startEdit()" class="text-sm text-primary hover:underline">
                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5 inline mr-1'); ?>
                    <?php esc_html_e('Bewerken', 'stridence'); ?>
                </button>
            </template>
        </div>

        <div class="bg-surface-card rounded-xl border border-border shadow-sm p-4">
            <!-- Display mode -->
            <dl x-show="!editing" class="space-y-3">
                <div class="flex items-center justify-between">
                    <dt class="text-sm text-text"><?php esc_html_e('Herinneringen', 'stridence'); ?></dt>
                    <dd>
                        <span x-show="fields.notify_reminders" class="text-success text-xs font-medium"><?php esc_html_e('Aan', 'stridence'); ?></span>
                        <span x-show="!fields.notify_reminders" class="text-text-muted text-xs"><?php esc_html_e('Uit', 'stridence'); ?></span>
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-sm text-text"><?php esc_html_e('Nieuwe opleidingen', 'stridence'); ?></dt>
                    <dd>
                        <span x-show="fields.notify_new_courses" class="text-success text-xs font-medium"><?php esc_html_e('Aan', 'stridence'); ?></span>
                        <span x-show="!fields.notify_new_courses" class="text-text-muted text-xs"><?php esc_html_e('Uit', 'stridence'); ?></span>
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-sm text-text"><?php esc_html_e('Nieuwsbrief', 'stridence'); ?></dt>
                    <dd>
                        <span x-show="fields.notify_newsletter" class="text-success text-xs font-medium"><?php esc_html_e('Aan', 'stridence'); ?></span>
                        <span x-show="!fields.notify_newsletter" class="text-text-muted text-xs"><?php esc_html_e('Uit', 'stridence'); ?></span>
                    </dd>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-border">
                    <dt class="text-sm text-text"><?php esc_html_e('Communicatietaal', 'stridence'); ?></dt>
                    <dd class="text-sm font-medium text-text">
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
                            <span class="text-xs text-text-muted block"><?php esc_html_e('Ontvang herinneringen voor aankomende sessies en deadlines.', 'stridence'); ?></span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="fields.notify_new_courses" class="input-checkbox mt-0.5">
                        <div>
                            <span class="text-sm font-medium text-text"><?php esc_html_e('Nieuwe opleidingen', 'stridence'); ?></span>
                            <span class="text-xs text-text-muted block"><?php esc_html_e('Word geïnformeerd over nieuwe opleidingen in jouw vakgebied.', 'stridence'); ?></span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="fields.notify_newsletter" class="input-checkbox mt-0.5">
                        <div>
                            <span class="text-sm font-medium text-text"><?php esc_html_e('Nieuwsbrief', 'stridence'); ?></span>
                            <span class="text-xs text-text-muted block"><?php esc_html_e('Ontvang onze maandelijkse nieuwsbrief met tips en nieuws.', 'stridence'); ?></span>
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
</div>
