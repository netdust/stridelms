<?php
/**
 * Dashboard Tab: Profiel (Profile)
 *
 * Profile editing forms for personal info, billing, and notifications.
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

// Get user data
$first_name = $user->first_name;
$last_name  = $user->last_name;
$email      = $user->user_email;
$phone      = get_user_meta($user_id, 'phone', true);

// Billing data
$billing = [
    'company'     => get_user_meta($user_id, 'invoice_organization_name', true) ?: get_user_meta($user_id, 'company', true),
    'vat'         => get_user_meta($user_id, 'vat_number', true),
    'address'     => get_user_meta($user_id, 'invoice_address', true) ?: get_user_meta($user_id, 'address_line_1', true),
    'postal_code' => get_user_meta($user_id, 'invoice_postal_code', true) ?: get_user_meta($user_id, 'postal_code', true),
    'city'        => get_user_meta($user_id, 'invoice_city', true) ?: get_user_meta($user_id, 'city', true),
    'email'       => get_user_meta($user_id, 'invoice_email', true),
    'gln'         => get_user_meta($user_id, 'gln_number', true),
];

// Notification preferences
$notifications = [
    'reminders'    => get_user_meta($user_id, 'stride_notify_reminders', true) !== 'no',
    'new_courses'  => get_user_meta($user_id, 'stride_notify_new_courses', true) !== 'no',
    'newsletter'   => get_user_meta($user_id, 'stride_notify_newsletter', true) === 'yes',
    'language'     => get_user_meta($user_id, 'stride_communication_language', true) ?: 'nl',
];
?>

<div class="space-y-6"
     x-data="profileForms()">

    <!-- Personal Information -->
    <section class="card">
        <div class="p-4 border-b border-border">
            <h2 class="font-heading text-lg font-bold text-text flex items-center gap-2">
                <?php echo stridence_icon('user', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Persoonlijke gegevens', 'stridence'); ?>
            </h2>
        </div>

        <form @submit.prevent="submitPersonal()" class="p-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="first_name" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('Voornaam', 'stridence'); ?>
                    </label>
                    <input type="text"
                           id="first_name"
                           name="first_name"
                           x-model="personal.first_name"
                           class="input-text"
                           required>
                </div>
                <div>
                    <label for="last_name" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('Achternaam', 'stridence'); ?>
                    </label>
                    <input type="text"
                           id="last_name"
                           name="last_name"
                           x-model="personal.last_name"
                           class="input-text"
                           required>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="email" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('E-mailadres', 'stridence'); ?>
                    </label>
                    <input type="email"
                           id="email"
                           value="<?php echo esc_attr($email); ?>"
                           class="input-text bg-surface-alt"
                           disabled>
                    <p class="mt-1 text-xs text-text-muted">
                        <?php esc_html_e('Neem contact op om je e-mailadres te wijzigen.', 'stridence'); ?>
                    </p>
                </div>
                <div>
                    <label for="phone" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('Telefoonnummer', 'stridence'); ?>
                    </label>
                    <input type="tel"
                           id="phone"
                           name="phone"
                           x-model="personal.phone"
                           class="input-text"
                           placeholder="+32 ...">
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <template x-if="messages.personal">
                    <p :class="messages.personal.type === 'success' ? 'text-green-600' : 'text-red-600'"
                       class="text-sm"
                       x-text="messages.personal.text"></p>
                </template>
                <button type="submit"
                        class="btn-primary text-sm ml-auto"
                        :disabled="loading.personal">
                    <template x-if="loading.personal">
                        <?php echo stridence_icon('loader', 'w-4 h-4 mr-2 animate-spin'); ?>
                    </template>
                    <?php esc_html_e('Opslaan', 'stridence'); ?>
                </button>
            </div>
        </form>
    </section>

    <!-- Billing Information -->
    <section class="card">
        <div class="p-4 border-b border-border">
            <h2 class="font-heading text-lg font-bold text-text flex items-center gap-2">
                <?php echo stridence_icon('file-text', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Facturatiegegevens', 'stridence'); ?>
            </h2>
            <p class="text-sm text-text-muted mt-1">
                <?php esc_html_e('Deze gegevens worden gebruikt voor offertes en facturen.', 'stridence'); ?>
            </p>
        </div>

        <form @submit.prevent="submitBilling()" class="p-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="billing_company" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('Organisatie', 'stridence'); ?>
                    </label>
                    <input type="text"
                           id="billing_company"
                           name="billing_company"
                           x-model="billing.company"
                           class="input-text">
                </div>
                <div>
                    <label for="billing_vat" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('BTW-nummer', 'stridence'); ?>
                    </label>
                    <input type="text"
                           id="billing_vat"
                           name="billing_vat"
                           x-model="billing.vat"
                           class="input-text"
                           placeholder="BE0123456789">
                </div>
            </div>

            <div>
                <label for="billing_address" class="block text-sm font-medium text-text mb-1">
                    <?php esc_html_e('Adres', 'stridence'); ?>
                </label>
                <input type="text"
                       id="billing_address"
                       name="billing_address"
                       x-model="billing.address"
                       class="input-text">
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="billing_postal_code" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('Postcode', 'stridence'); ?>
                    </label>
                    <input type="text"
                           id="billing_postal_code"
                           name="billing_postal_code"
                           x-model="billing.postal_code"
                           class="input-text">
                </div>
                <div class="sm:col-span-2">
                    <label for="billing_city" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('Plaats', 'stridence'); ?>
                    </label>
                    <input type="text"
                           id="billing_city"
                           name="billing_city"
                           x-model="billing.city"
                           class="input-text">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="billing_email" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('Facturatie e-mail', 'stridence'); ?>
                    </label>
                    <input type="email"
                           id="billing_email"
                           name="billing_email"
                           x-model="billing.email"
                           class="input-text"
                           placeholder="<?php echo esc_attr($email); ?>">
                    <p class="mt-1 text-xs text-text-muted">
                        <?php esc_html_e('Laat leeg om je hoofde-mailadres te gebruiken.', 'stridence'); ?>
                    </p>
                </div>
                <div>
                    <label for="billing_gln" class="block text-sm font-medium text-text mb-1">
                        <?php esc_html_e('GLN-nummer', 'stridence'); ?>
                        <span class="text-text-muted font-normal">(<?php esc_html_e('optioneel', 'stridence'); ?>)</span>
                    </label>
                    <input type="text"
                           id="billing_gln"
                           name="billing_gln"
                           x-model="billing.gln"
                           class="input-text">
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <template x-if="messages.billing">
                    <p :class="messages.billing.type === 'success' ? 'text-green-600' : 'text-red-600'"
                       class="text-sm"
                       x-text="messages.billing.text"></p>
                </template>
                <button type="submit"
                        class="btn-primary text-sm ml-auto"
                        :disabled="loading.billing">
                    <template x-if="loading.billing">
                        <?php echo stridence_icon('loader', 'w-4 h-4 mr-2 animate-spin'); ?>
                    </template>
                    <?php esc_html_e('Opslaan', 'stridence'); ?>
                </button>
            </div>
        </form>
    </section>

    <!-- Notification Preferences -->
    <section class="card">
        <div class="p-4 border-b border-border">
            <h2 class="font-heading text-lg font-bold text-text flex items-center gap-2">
                <?php echo stridence_icon('bell', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Meldingsvoorkeuren', 'stridence'); ?>
            </h2>
        </div>

        <form @submit.prevent="submitNotifications()" class="p-4 space-y-4">
            <div class="space-y-3">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox"
                           name="notify_reminders"
                           x-model="notifications.reminders"
                           class="input-checkbox mt-0.5">
                    <div>
                        <span class="text-sm font-medium text-text">
                            <?php esc_html_e('Herinneringen', 'stridence'); ?>
                        </span>
                        <p class="text-sm text-text-muted">
                            <?php esc_html_e('Ontvang herinneringen voor aankomende sessies en deadlines.', 'stridence'); ?>
                        </p>
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox"
                           name="notify_new_courses"
                           x-model="notifications.new_courses"
                           class="input-checkbox mt-0.5">
                    <div>
                        <span class="text-sm font-medium text-text">
                            <?php esc_html_e('Nieuwe opleidingen', 'stridence'); ?>
                        </span>
                        <p class="text-sm text-text-muted">
                            <?php esc_html_e('Word geïnformeerd over nieuwe opleidingen in jouw vakgebied.', 'stridence'); ?>
                        </p>
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox"
                           name="notify_newsletter"
                           x-model="notifications.newsletter"
                           class="input-checkbox mt-0.5">
                    <div>
                        <span class="text-sm font-medium text-text">
                            <?php esc_html_e('Nieuwsbrief', 'stridence'); ?>
                        </span>
                        <p class="text-sm text-text-muted">
                            <?php esc_html_e('Ontvang onze maandelijkse nieuwsbrief met tips en nieuws.', 'stridence'); ?>
                        </p>
                    </div>
                </label>
            </div>

            <div class="pt-2">
                <label for="communication_language" class="block text-sm font-medium text-text mb-1">
                    <?php esc_html_e('Communicatietaal', 'stridence'); ?>
                </label>
                <select id="communication_language"
                        name="communication_language"
                        x-model="notifications.language"
                        class="input-select w-full sm:w-auto">
                    <option value="nl"><?php esc_html_e('Nederlands', 'stridence'); ?></option>
                    <option value="fr"><?php esc_html_e('Frans', 'stridence'); ?></option>
                    <option value="en"><?php esc_html_e('Engels', 'stridence'); ?></option>
                </select>
            </div>

            <div class="flex items-center justify-between pt-2">
                <template x-if="messages.notifications">
                    <p :class="messages.notifications.type === 'success' ? 'text-green-600' : 'text-red-600'"
                       class="text-sm"
                       x-text="messages.notifications.text"></p>
                </template>
                <button type="submit"
                        class="btn-primary text-sm ml-auto"
                        :disabled="loading.notifications">
                    <template x-if="loading.notifications">
                        <?php echo stridence_icon('loader', 'w-4 h-4 mr-2 animate-spin'); ?>
                    </template>
                    <?php esc_html_e('Opslaan', 'stridence'); ?>
                </button>
            </div>
        </form>
    </section>

    <!-- Logout -->
    <section class="card p-4">
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
               class="btn-ghost text-sm text-red-600 hover:text-red-700 hover:bg-red-50">
                <?php echo stridence_icon('log-out', 'w-4 h-4 mr-2'); ?>
                <?php esc_html_e('Uitloggen', 'stridence'); ?>
            </a>
        </div>
    </section>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('profileForms', () => ({
        personal: {
            first_name: <?php echo wp_json_encode($first_name); ?>,
            last_name: <?php echo wp_json_encode($last_name); ?>,
            phone: <?php echo wp_json_encode($phone); ?>,
        },
        billing: {
            company: <?php echo wp_json_encode($billing['company']); ?>,
            vat: <?php echo wp_json_encode($billing['vat']); ?>,
            address: <?php echo wp_json_encode($billing['address']); ?>,
            postal_code: <?php echo wp_json_encode($billing['postal_code']); ?>,
            city: <?php echo wp_json_encode($billing['city']); ?>,
            email: <?php echo wp_json_encode($billing['email']); ?>,
            gln: <?php echo wp_json_encode($billing['gln']); ?>,
        },
        notifications: {
            reminders: <?php echo $notifications['reminders'] ? 'true' : 'false'; ?>,
            new_courses: <?php echo $notifications['new_courses'] ? 'true' : 'false'; ?>,
            newsletter: <?php echo $notifications['newsletter'] ? 'true' : 'false'; ?>,
            language: <?php echo wp_json_encode($notifications['language']); ?>,
        },
        loading: {
            personal: false,
            billing: false,
            notifications: false,
        },
        messages: {
            personal: null,
            billing: null,
            notifications: null,
        },

        async submitPersonal() {
            this.loading.personal = true;
            this.messages.personal = null;

            try {
                const result = await ntdstAPI.call('stride_update_profile', {
                    form_type: 'personal',
                    ...this.personal,
                });
                this.messages.personal = { type: 'success', text: result.message };
            } catch (error) {
                this.messages.personal = { type: 'error', text: error.message };
            } finally {
                this.loading.personal = false;
            }
        },

        async submitBilling() {
            this.loading.billing = true;
            this.messages.billing = null;

            try {
                const result = await ntdstAPI.call('stride_update_profile', {
                    form_type: 'billing',
                    billing_company: this.billing.company,
                    billing_vat: this.billing.vat,
                    billing_address: this.billing.address,
                    billing_postal_code: this.billing.postal_code,
                    billing_city: this.billing.city,
                    billing_email: this.billing.email,
                    billing_gln: this.billing.gln,
                });
                this.messages.billing = { type: 'success', text: result.message };
            } catch (error) {
                this.messages.billing = { type: 'error', text: error.message };
            } finally {
                this.loading.billing = false;
            }
        },

        async submitNotifications() {
            this.loading.notifications = true;
            this.messages.notifications = null;

            try {
                const result = await ntdstAPI.call('stride_update_profile', {
                    form_type: 'notifications',
                    notify_reminders: this.notifications.reminders,
                    notify_new_courses: this.notifications.new_courses,
                    notify_newsletter: this.notifications.newsletter,
                    communication_language: this.notifications.language,
                });
                this.messages.notifications = { type: 'success', text: result.message };
            } catch (error) {
                this.messages.notifications = { type: 'error', text: error.message };
            } finally {
                this.loading.notifications = false;
            }
        },
    }));
});
</script>
