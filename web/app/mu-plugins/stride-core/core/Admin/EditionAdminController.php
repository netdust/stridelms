<?php

namespace ntdst\Stride\core\Admin;

defined('ABSPATH') || exit;

use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\FieldRegistry;

/**
 * Edition Admin Controller
 *
 * Handles admin interface for editions with inline session management:
 * - Edition details metabox (course, dates, venue, pricing)
 * - Sessions inline metabox (AJAX add/edit/delete)
 * - Attendance metabox (per-session attendance marking)
 * - Actions sidebar (status, capacity, quick stats)
 *
 * This class is instantiated by EditionService in admin context.
 * Not a service - just a plain admin handler class.
 *
 * @package ntdst\Stride\core\Admin
 */
class EditionAdminController
{
    private ?EditionService $editionService = null;
    private ?SessionService $sessionService = null;
    private ?RegistrationRepository $registrationRepo = null;

    /**
     * Constructor - uses lazy loading to avoid circular dependencies
     */
    public function __construct()
    {
        // Register hooks
        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . EditionService::POST_TYPE, [$this, 'saveEditionMeta'], 10, 2);

        // AJAX endpoints for session management
        add_action('wp_ajax_stride_get_sessions', [$this, 'ajaxGetSessions']);
        add_action('wp_ajax_stride_add_session', [$this, 'ajaxAddSession']);
        add_action('wp_ajax_stride_update_session', [$this, 'ajaxUpdateSession']);
        add_action('wp_ajax_stride_delete_session', [$this, 'ajaxDeleteSession']);

        // AJAX endpoints for attendance
        add_action('wp_ajax_stride_get_attendance', [$this, 'ajaxGetAttendance']);
        add_action('wp_ajax_stride_mark_attendance', [$this, 'ajaxMarkAttendance']);
        add_action('wp_ajax_stride_bulk_attendance', [$this, 'ajaxBulkAttendance']);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Get EditionService (lazy loaded)
     */
    private function getEditionService(): EditionService
    {
        if ($this->editionService === null) {
            $this->editionService = $this->resolveService(EditionService::class);
        }
        return $this->editionService;
    }

    /**
     * Get SessionService (lazy loaded)
     */
    private function getSessionService(): SessionService
    {
        if ($this->sessionService === null) {
            $this->sessionService = $this->resolveService(SessionService::class);
        }
        return $this->sessionService;
    }

    /**
     * Get RegistrationRepository (lazy loaded)
     */
    private function getRegistrationRepo(): RegistrationRepository
    {
        if ($this->registrationRepo === null) {
            $this->registrationRepo = $this->resolveService(RegistrationRepository::class);
        }
        return $this->registrationRepo;
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through to create new instance
            }
        }
        return new $class();
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets(string $hook): void
    {
        global $post_type;

        if ($post_type !== EditionService::POST_TYPE) {
            return;
        }

        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'stride-edition-admin',
            get_template_directory_uri() . '/assets/css/admin/edition-admin.css',
            [],
            filemtime(get_template_directory() . '/assets/css/admin/edition-admin.css')
        );

        // Enqueue scripts
        wp_enqueue_script(
            'stride-edition-admin',
            get_template_directory_uri() . '/assets/js/admin/edition-admin.js',
            ['jquery', 'select2'],
            filemtime(get_template_directory() . '/assets/js/admin/edition-admin.js'),
            true
        );

        // Localize script
        wp_localize_script('stride-edition-admin', 'strideEditionAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stride_edition_admin'),
            'editionId' => get_the_ID(),
            'i18n' => [
                'addSession' => __('Sessie toevoegen', 'stride'),
                'editSession' => __('Sessie bewerken', 'stride'),
                'deleteSession' => __('Sessie verwijderen', 'stride'),
                'confirmDelete' => __('Weet je zeker dat je deze sessie wilt verwijderen?', 'stride'),
                'save' => __('Opslaan', 'stride'),
                'cancel' => __('Annuleren', 'stride'),
                'date' => __('Datum', 'stride'),
                'startTime' => __('Starttijd', 'stride'),
                'endTime' => __('Eindtijd', 'stride'),
                'location' => __('Locatie', 'stride'),
                'slot' => __('Slot', 'stride'),
                'noSlot' => __('Geen slot', 'stride'),
                'attendees' => __('Deelnemers', 'stride'),
                'present' => __('Aanwezig', 'stride'),
                'absent' => __('Afwezig', 'stride'),
                'excused' => __('Verontschuldigd', 'stride'),
                'markAllPresent' => __('Alles aanwezig', 'stride'),
                'noSessions' => __('Nog geen sessies toegevoegd.', 'stride'),
                'noRegistrations' => __('Geen bevestigde inschrijvingen.', 'stride'),
                'saveFirst' => __('Sla de editie eerst op om sessies toe te voegen.', 'stride'),
                'searchCourse' => __('Zoek cursus...', 'stride'),
                'error' => __('Er is een fout opgetreden.', 'stride'),
            ],
        ]);

        // Enqueue Select2
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery']);
    }

    /**
     * Register custom metaboxes for edition admin
     */
    public function registerMetaboxes(): void
    {
        // Remove DataManager auto-metabox
        remove_meta_box('ntdst_' . EditionService::POST_TYPE . '_fields', EditionService::POST_TYPE, 'normal');

        add_meta_box(
            'stride_edition_details',
            __('Editie Details', 'stride'),
            [$this, 'renderDetailsMetabox'],
            EditionService::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'stride_edition_sessions',
            __('Sessies', 'stride'),
            [$this, 'renderSessionsMetabox'],
            EditionService::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'stride_edition_attendance',
            __('Aanwezigheid', 'stride'),
            [$this, 'renderAttendanceMetabox'],
            EditionService::POST_TYPE,
            'normal',
            'low'
        );

        add_meta_box(
            'stride_edition_actions',
            __('Status & Capaciteit', 'stride'),
            [$this, 'renderActionsMetabox'],
            EditionService::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * Render the main edition details metabox
     */
    public function renderDetailsMetabox(\WP_Post $post): void
    {
        $edition = $this->getEditionService()->getEdition($post->ID);
        $isNew = !$edition;

        // Default values for new editions
        if ($isNew) {
            $edition = [
                'course_id' => 0,
                'start_date' => '',
                'end_date' => '',
                'venue' => '',
                'speakers' => '',
                'price' => '',
                'price_non_member' => '',
                'invoice_item' => '',
                'status' => FieldRegistry::EDITION_STATUS_OPEN,
                'target_group' => '',
                'prerequisites' => '',
                'trainers' => '',
                'accreditation' => '',
            ];
        }

        wp_nonce_field('stride_save_edition', 'stride_edition_nonce');

        // Get all LearnDash courses for dropdown
        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);
        ?>
        <div class="stride-edition-admin">
            <div class="stride-edition-tabs">
                <nav class="stride-tabs-nav">
                    <button type="button" class="stride-tab active" data-tab="algemeen"><?php esc_html_e('Algemeen', 'stride'); ?></button>
                    <button type="button" class="stride-tab" data-tab="informatie"><?php esc_html_e('Informatie', 'stride'); ?></button>
                    <button type="button" class="stride-tab" data-tab="prijzen"><?php esc_html_e('Prijzen', 'stride'); ?></button>
                </nav>

                <!-- Tab: Algemeen -->
                <div class="stride-tab-content active" data-tab="algemeen">
                    <div class="stride-field-row">
                        <div class="stride-field stride-course-field">
                            <label for="edition_course_id"><?php esc_html_e('Cursus', 'stride'); ?></label>
                            <select id="edition_course_id" name="ntdst_fields[course_id]" class="stride-select2-course">
                                <option value=""><?php esc_html_e('Selecteer cursus...', 'stride'); ?></option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo esc_attr($course->ID); ?>" <?php selected($edition['course_id'] ?? 0, $course->ID); ?>>
                                        <?php echo esc_html($course->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="edition_start_date"><?php esc_html_e('Startdatum', 'stride'); ?></label>
                            <input type="date" id="edition_start_date" name="ntdst_fields[start_date]"
                                   value="<?php echo esc_attr($edition['start_date'] ?? ''); ?>">
                        </div>
                        <div class="stride-field">
                            <label for="edition_end_date"><?php esc_html_e('Einddatum', 'stride'); ?></label>
                            <input type="date" id="edition_end_date" name="ntdst_fields[end_date]"
                                   value="<?php echo esc_attr($edition['end_date'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_venue"><?php esc_html_e('Locatie', 'stride'); ?></label>
                            <input type="text" id="edition_venue" name="ntdst_fields[venue]"
                                   value="<?php echo esc_attr($edition['venue'] ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('Bijv. Brussel, Online, ...', 'stride'); ?>">
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_speakers"><?php esc_html_e('Sprekers', 'stride'); ?></label>
                            <input type="text" id="edition_speakers" name="ntdst_fields[speakers]"
                                   value="<?php echo esc_attr($edition['speakers'] ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('Jan Peeters, trainer; An Claes, gastspreker', 'stride'); ?>">
                            <p class="description"><?php esc_html_e('Formaat: Naam, rol; Naam, rol; ...', 'stride'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Tab: Informatie -->
                <div class="stride-tab-content" data-tab="informatie">
                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_target_group"><?php esc_html_e('Doelgroep', 'stride'); ?></label>
                            <textarea id="edition_target_group" name="ntdst_fields[target_group]" rows="3"
                                      placeholder="<?php esc_attr_e('Voor wie is deze opleiding bedoeld?', 'stride'); ?>"><?php echo esc_textarea($edition['target_group'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_prerequisites"><?php esc_html_e('Vooropleidingen', 'stride'); ?></label>
                            <textarea id="edition_prerequisites" name="ntdst_fields[prerequisites]" rows="3"
                                      placeholder="<?php esc_attr_e('Welke voorkennis of diploma\'s zijn vereist?', 'stride'); ?>"><?php echo esc_textarea($edition['prerequisites'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_trainers"><?php esc_html_e('Begeleiders', 'stride'); ?></label>
                            <input type="text" id="edition_trainers" name="ntdst_fields[trainers]"
                                   value="<?php echo esc_attr($edition['trainers'] ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('Organisatiemedewerkers die deelnemers begeleiden', 'stride'); ?>">
                            <p class="description"><?php esc_html_e('Begeleiders zijn organisatiemedewerkers (geen sprekers/trainers).', 'stride'); ?></p>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_accreditation"><?php esc_html_e('Accreditering', 'stride'); ?></label>
                            <input type="text" id="edition_accreditation" name="ntdst_fields[accreditation]"
                                   value="<?php echo esc_attr($edition['accreditation'] ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('Bijv. VDAB erkend, FOD-punten, ...', 'stride'); ?>">
                        </div>
                    </div>
                </div>

                <!-- Tab: Prijzen -->
                <div class="stride-tab-content" data-tab="prijzen">
                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="edition_price"><?php esc_html_e('Prijs (leden)', 'stride'); ?></label>
                            <input type="number" id="edition_price" name="ntdst_fields[price]"
                                   value="<?php echo esc_attr($edition['price'] ?? ''); ?>"
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="stride-field">
                            <label for="edition_price_non_member"><?php esc_html_e('Prijs (niet-leden)', 'stride'); ?></label>
                            <input type="number" id="edition_price_non_member" name="ntdst_fields[price_non_member]"
                                   value="<?php echo esc_attr($edition['price_non_member'] ?? ''); ?>"
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_invoice_item"><?php esc_html_e('Facturatieitem', 'stride'); ?></label>
                            <input type="text" id="edition_invoice_item" name="ntdst_fields[invoice_item]"
                                   value="<?php echo esc_attr($edition['invoice_item'] ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('Exact Online item code', 'stride'); ?>">
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label>
                                <input type="checkbox" name="ntdst_fields[invoice_enabled]" value="1"
                                       <?php checked($edition['invoice_enabled'] ?? true); ?>>
                                <?php esc_html_e('Facturatie ingeschakeld', 'stride'); ?>
                            </label>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label>
                                <input type="checkbox" name="ntdst_fields[certificate_enabled]" value="1"
                                       <?php checked($edition['certificate_enabled'] ?? false); ?>>
                                <?php esc_html_e('Certificaat ingeschakeld', 'stride'); ?>
                            </label>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label>
                                <input type="checkbox" name="ntdst_fields[is_multi_year_training]" value="1"
                                       <?php checked($edition['is_multi_year_training'] ?? false); ?>>
                                <?php esc_html_e('Tweejarige opleiding', 'stride'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Lidmaatschapsvouchers zijn niet geldig.', 'stride'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the sessions metabox with inline management
     */
    public function renderSessionsMetabox(\WP_Post $post): void
    {
        $isNew = $post->post_status === 'auto-draft';
        $sessions = $isNew ? [] : $this->getSessionService()->getSessionsForEdition($post->ID);
        $registeredCount = $isNew ? 0 : $this->getRegistrationRepo()->countByEdition($post->ID, 'confirmed');
        $capacity = $this->getEditionService()->getCapacity($post->ID) ?? 0;
        ?>
        <div class="stride-sessions-admin">
            <?php if ($isNew): ?>
                <div class="stride-sessions-notice">
                    <span class="dashicons dashicons-info"></span>
                    <?php esc_html_e('Sla de editie eerst op om sessies toe te voegen.', 'stride'); ?>
                </div>
            <?php else: ?>
                <div class="stride-sessions-header">
                    <button type="button" class="button button-primary" id="stride-add-session-btn">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Sessie toevoegen', 'stride'); ?>
                    </button>
                </div>

                <?php $editionSlots = $this->getEditionService()->getSessionSlots($post->ID); ?>
                <table class="widefat striped stride-sessions-table" id="stride-sessions-table">
                    <thead>
                        <tr>
                            <th class="column-date"><?php esc_html_e('Datum', 'stride'); ?></th>
                            <th class="column-time"><?php esc_html_e('Tijd', 'stride'); ?></th>
                            <th class="column-location"><?php esc_html_e('Locatie', 'stride'); ?></th>
                            <?php if (!empty($editionSlots)): ?>
                                <th class="column-slot"><?php esc_html_e('Slot', 'stride'); ?></th>
                            <?php endif; ?>
                            <th class="column-attendees"><?php esc_html_e('Deelnemers', 'stride'); ?></th>
                            <th class="column-actions"><?php esc_html_e('Acties', 'stride'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="stride-sessions-body">
                        <?php if (empty($sessions)): ?>
                            <tr class="no-sessions-row">
                                <td colspan="5" class="no-sessions">
                                    <?php esc_html_e('Nog geen sessies toegevoegd.', 'stride'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $session): ?>
                                <?php echo $this->renderSessionRow($session, $registeredCount, $capacity, $editionSlots); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Inline edit form template (hidden) -->
                <template id="stride-session-form-template">
                    <tr class="stride-session-form-row">
                        <td colspan="<?php echo !empty($editionSlots) ? '7' : '6'; ?>">
                            <div class="stride-session-form">
                                <input type="hidden" name="session_id" value="">
                                <div class="stride-session-form-fields">
                                    <div class="stride-field">
                                        <label><?php esc_html_e('Datum', 'stride'); ?></label>
                                        <input type="date" name="session_date" value="" required>
                                    </div>
                                    <div class="stride-field">
                                        <label><?php esc_html_e('Starttijd', 'stride'); ?></label>
                                        <input type="time" name="session_start_time" value="" placeholder="09:00">
                                    </div>
                                    <div class="stride-field">
                                        <label><?php esc_html_e('Eindtijd', 'stride'); ?></label>
                                        <input type="time" name="session_end_time" value="" placeholder="17:00">
                                    </div>
                                    <div class="stride-field">
                                        <label><?php esc_html_e('Locatie', 'stride'); ?></label>
                                        <input type="text" name="session_location" value="" placeholder="<?php esc_attr_e('Optioneel', 'stride'); ?>">
                                    </div>
                                    <div class="stride-field stride-slot-field">
                                        <label><?php esc_html_e('Slot', 'stride'); ?></label>
                                        <select name="session_slot" id="stride-session-slot-select">
                                            <option value=""><?php esc_html_e('Geen slot', 'stride'); ?></option>
                                            <?php foreach ($editionSlots as $slot): ?>
                                                <option value="<?php echo esc_attr($slot['slot']); ?>">
                                                    <?php echo esc_html($slot['label'] ?? $slot['slot']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="stride-session-form-actions">
                                    <button type="button" class="button button-primary stride-session-save">
                                        <?php esc_html_e('Opslaan', 'stride'); ?>
                                    </button>
                                    <button type="button" class="button stride-session-cancel">
                                        <?php esc_html_e('Annuleren', 'stride'); ?>
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>

                <!-- Session Slots Configuration -->
                <?php
                $selectionDeadline = $this->getEditionService()->getSelectionDeadline($post->ID);
                ?>
                <div class="stride-session-slots-section">
                    <h4><?php esc_html_e('Sessiekeuze configuratie', 'stride'); ?></h4>
                    <p class="description"><?php esc_html_e('Configureer slots zodat deelnemers kunnen kiezen uit sessies (bijv. voormiddag/namiddag workshops).', 'stride'); ?></p>

                    <div class="stride-session-slots-wrapper" id="stride-session-slots-wrapper">
                        <div id="stride-session-slots-list">
                            <?php if (!empty($editionSlots)): ?>
                                <?php foreach ($editionSlots as $index => $slot): ?>
                                    <div class="stride-slot-row" data-slot-index="<?php echo esc_attr($index); ?>">
                                        <div class="stride-field-row four-col">
                                            <div class="stride-field">
                                                <label><?php esc_html_e('Slot ID', 'stride'); ?></label>
                                                <input type="text" name="ntdst_fields[session_slots][<?php echo esc_attr($index); ?>][slot]"
                                                       value="<?php echo esc_attr($slot['slot'] ?? ''); ?>"
                                                       placeholder="voormiddag" required>
                                            </div>
                                            <div class="stride-field">
                                                <label><?php esc_html_e('Label', 'stride'); ?></label>
                                                <input type="text" name="ntdst_fields[session_slots][<?php echo esc_attr($index); ?>][label]"
                                                       value="<?php echo esc_attr($slot['label'] ?? ''); ?>"
                                                       placeholder="Voormiddag" required>
                                            </div>
                                            <div class="stride-field">
                                                <label><?php esc_html_e('Kies aantal', 'stride'); ?></label>
                                                <input type="number" name="ntdst_fields[session_slots][<?php echo esc_attr($index); ?>][pick_count]"
                                                       value="<?php echo esc_attr($slot['pick_count'] ?? 1); ?>"
                                                       min="1" step="1">
                                            </div>
                                            <div class="stride-field stride-slot-actions">
                                                <label>
                                                    <input type="checkbox" name="ntdst_fields[session_slots][<?php echo esc_attr($index); ?>][required]" value="1"
                                                           <?php checked($slot['required'] ?? true); ?>>
                                                    <?php esc_html_e('Verplicht', 'stride'); ?>
                                                </label>
                                                <button type="button" class="button-link stride-remove-slot" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                                                    <span class="dashicons dashicons-trash"></span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button" id="stride-add-slot-btn">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e('Slot toevoegen', 'stride'); ?>
                        </button>
                    </div>

                    <div class="stride-field-row" style="margin-top: 16px;">
                        <div class="stride-field">
                            <label for="edition_selection_deadline"><?php esc_html_e('Keuze deadline', 'stride'); ?></label>
                            <input type="date" id="edition_selection_deadline" name="ntdst_fields[selection_deadline]"
                                   value="<?php echo esc_attr($selectionDeadline ?? ''); ?>">
                            <p class="description"><?php esc_html_e('Deelnemers kunnen hun sessiekeuze wijzigen tot deze datum.', 'stride'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Slot row template -->
                <template id="stride-slot-row-template">
                    <div class="stride-slot-row" data-slot-index="__INDEX__">
                        <div class="stride-field-row four-col">
                            <div class="stride-field">
                                <label><?php esc_html_e('Slot ID', 'stride'); ?></label>
                                <input type="text" name="ntdst_fields[session_slots][__INDEX__][slot]"
                                       value="" placeholder="voormiddag" required>
                            </div>
                            <div class="stride-field">
                                <label><?php esc_html_e('Label', 'stride'); ?></label>
                                <input type="text" name="ntdst_fields[session_slots][__INDEX__][label]"
                                       value="" placeholder="Voormiddag" required>
                            </div>
                            <div class="stride-field">
                                <label><?php esc_html_e('Kies aantal', 'stride'); ?></label>
                                <input type="number" name="ntdst_fields[session_slots][__INDEX__][pick_count]"
                                       value="1" min="1" step="1">
                            </div>
                            <div class="stride-field stride-slot-actions">
                                <label>
                                    <input type="checkbox" name="ntdst_fields[session_slots][__INDEX__][required]" value="1" checked>
                                    <?php esc_html_e('Verplicht', 'stride'); ?>
                                </label>
                                <button type="button" class="button-link stride-remove-slot" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single session row
     *
     * @param array $session Session data
     * @param int $registeredCount Number of registered users
     * @param int $capacity Edition capacity
     * @param array $editionSlots Optional edition slot configuration
     */
    private function renderSessionRow(array $session, int $registeredCount, int $capacity, array $editionSlots = []): string
    {
        $attendeeCount = count($this->getSessionService()->getAttendees($session['id']));
        $timeDisplay = '';

        if ($session['start_time'] && $session['end_time']) {
            $timeDisplay = sprintf('%s - %s', $session['start_time'], $session['end_time']);
        } elseif ($session['start_time']) {
            $timeDisplay = $session['start_time'];
        }

        $attendeeDisplay = $capacity > 0
            ? sprintf('%d / %d', $attendeeCount, $capacity)
            : (string) $attendeeCount;

        // Get slot label if session has a slot
        $slotLabel = '';
        if (!empty($session['slot']) && !empty($editionSlots)) {
            foreach ($editionSlots as $slot) {
                if ($slot['slot'] === $session['slot']) {
                    $slotLabel = $slot['label'] ?? $slot['slot'];
                    break;
                }
            }
            if (!$slotLabel) {
                $slotLabel = $session['slot'];
            }
        }

        $hasSlots = !empty($editionSlots);

        ob_start();
        ?>
        <tr class="session-row" data-session-id="<?php echo esc_attr($session['id']); ?>" data-session-slot="<?php echo esc_attr($session['slot'] ?? ''); ?>">
            <td class="column-date">
                <?php echo esc_html(date_i18n('d M Y', strtotime($session['date']))); ?>
            </td>
            <td class="column-time">
                <?php echo esc_html($timeDisplay ?: '-'); ?>
            </td>
            <td class="column-location">
                <?php echo esc_html($session['location'] ?: '-'); ?>
            </td>
            <?php if ($hasSlots): ?>
                <td class="column-slot">
                    <?php echo esc_html($slotLabel ?: '-'); ?>
                </td>
            <?php endif; ?>
            <td class="column-attendees">
                <span class="attendee-count"><?php echo esc_html($attendeeDisplay); ?></span>
            </td>
            <td class="column-actions">
                <button type="button" class="button-link stride-edit-session" title="<?php esc_attr_e('Bewerken', 'stride'); ?>">
                    <span class="dashicons dashicons-edit"></span>
                </button>
                <button type="button" class="button-link stride-delete-session" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the attendance metabox
     */
    public function renderAttendanceMetabox(\WP_Post $post): void
    {
        $isNew = $post->post_status === 'auto-draft';
        $sessions = $isNew ? [] : $this->getSessionService()->getSessionsForEdition($post->ID);
        $registrations = $isNew ? [] : $this->getRegistrationRepo()->getByEdition($post->ID, 'confirmed');

        if (empty($registrations)) {
            echo '<p class="description">' . esc_html__('Geen bevestigde inschrijvingen.', 'stride') . '</p>';
            return;
        }

        if (empty($sessions)) {
            echo '<p class="description">' . esc_html__('Voeg eerst sessies toe om aanwezigheid te kunnen bijhouden.', 'stride') . '</p>';
            return;
        }

        // Get user data for display (name, email, organisation)
        $userIds = array_column($registrations, 'user_id');
        $users = [];
        foreach ($userIds as $userId) {
            $user = get_userdata($userId);
            if ($user) {
                $users[$userId] = [
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'organisation' => get_user_meta($userId, 'stride_invoice_org', true) ?: '-',
                ];
            }
        }

        // Pre-calculate session stats
        $sessionStats = [];
        foreach ($sessions as $session) {
            $presentCount = count($this->getSessionService()->getAttendees($session['id']));
            $sessionStats[$session['id']] = [
                'present' => $presentCount,
                'total' => count($registrations),
            ];
        }
        ?>
        <div class="stride-attendance-admin">
            <div class="stride-attendance-table-wrapper">
                <table class="stride-attendance-table widefat striped">
                    <thead>
                        <tr>
                            <th class="column-name"><?php esc_html_e('Naam', 'stride'); ?></th>
                            <th class="column-email"><?php esc_html_e('E-mail', 'stride'); ?></th>
                            <th class="column-org"><?php esc_html_e('Organisatie', 'stride'); ?></th>
                            <?php foreach ($sessions as $session): ?>
                                <th class="column-session" data-session-id="<?php echo esc_attr($session['id']); ?>">
                                    <div class="session-header">
                                        <span class="session-date"><?php echo esc_html(date_i18n('d M', strtotime($session['date']))); ?></span>
                                        <?php if ($session['start_time']): ?>
                                            <span class="session-time"><?php echo esc_html($session['start_time']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="stride-mark-all-present" title="<?php esc_attr_e('Alles aanwezig', 'stride'); ?>">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                    </button>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $registration): ?>
                            <?php
                            $userId = $registration['user_id'];
                            $userData = $users[$userId] ?? [
                                'name' => sprintf(__('Gebruiker #%d', 'stride'), $userId),
                                'email' => '-',
                                'organisation' => '-',
                            ];
                            ?>
                            <tr class="attendance-row" data-user-id="<?php echo esc_attr($userId); ?>">
                                <td class="column-name"><?php echo esc_html($userData['name']); ?></td>
                                <td class="column-email"><?php echo esc_html($userData['email']); ?></td>
                                <td class="column-org"><?php echo esc_html($userData['organisation']); ?></td>
                                <?php foreach ($sessions as $session): ?>
                                    <?php
                                    $status = $this->getSessionService()->getAttendanceStatus($session['id'], $userId);
                                    $statusClass = $status ?? 'unmarked';
                                    ?>
                                    <td class="column-session">
                                        <button type="button"
                                                class="stride-attendance-toggle <?php echo esc_attr($statusClass); ?>"
                                                data-session-id="<?php echo esc_attr($session['id']); ?>"
                                                data-user-id="<?php echo esc_attr($userId); ?>"
                                                title="<?php esc_attr_e('Klik om te wijzigen', 'stride'); ?>">
                                            <span class="status-icon"></span>
                                        </button>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="attendance-totals">
                            <td colspan="3" class="totals-label"><?php esc_html_e('Aanwezig', 'stride'); ?></td>
                            <?php foreach ($sessions as $session): ?>
                                <td class="column-session totals-cell" data-session-id="<?php echo esc_attr($session['id']); ?>">
                                    <span class="attendance-count">
                                        <?php
                                        $stats = $sessionStats[$session['id']];
                                        echo esc_html(sprintf('%d/%d', $stats['present'], $stats['total']));
                                        ?>
                                    </span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="stride-attendance-legend">
                <span class="legend-item present">
                    <span class="status-icon"></span> <?php esc_html_e('Aanwezig', 'stride'); ?>
                </span>
                <span class="legend-item absent">
                    <span class="status-icon"></span> <?php esc_html_e('Afwezig', 'stride'); ?>
                </span>
                <span class="legend-item excused">
                    <span class="status-icon"></span> <?php esc_html_e('Verontschuldigd', 'stride'); ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Render sidebar actions metabox
     */
    public function renderActionsMetabox(\WP_Post $post): void
    {
        $edition = $this->getEditionService()->getEdition($post->ID);
        $isNew = !$edition;

        if ($isNew) {
            echo '<p class="description">' . esc_html__('Sla eerst op om info te zien.', 'stride') . '</p>';
            return;
        }

        $status = $edition['status'] ?? FieldRegistry::EDITION_STATUS_OPEN;
        $capacity = $edition['capacity'] ?? 0;
        $registeredCount = $this->getRegistrationRepo()->countByEdition($post->ID, 'confirmed');
        $sessions = $this->getSessionService()->getSessionsForEdition($post->ID);
        $totalHours = $this->getSessionService()->getTotalHours($post->ID);
        $percentage = ($capacity > 0) ? round(($registeredCount / $capacity) * 100) : 0;

        $statusLabels = [
            FieldRegistry::EDITION_STATUS_OPEN => __('Open', 'stride'),
            FieldRegistry::EDITION_STATUS_FULL => __('Volzet', 'stride'),
            FieldRegistry::EDITION_STATUS_CANCELLED => __('Geannuleerd', 'stride'),
            FieldRegistry::EDITION_STATUS_POSTPONED => __('Uitgesteld', 'stride'),
            FieldRegistry::EDITION_STATUS_ANNOUNCEMENT => __('Aankondiging', 'stride'),
            FieldRegistry::EDITION_STATUS_COMPLETED => __('Afgerond', 'stride'),
        ];

        $statusColors = [
            FieldRegistry::EDITION_STATUS_OPEN => '#00a32a',
            FieldRegistry::EDITION_STATUS_FULL => '#dba617',
            FieldRegistry::EDITION_STATUS_CANCELLED => '#d63638',
            FieldRegistry::EDITION_STATUS_POSTPONED => '#72aee6',
            FieldRegistry::EDITION_STATUS_ANNOUNCEMENT => '#646970',
            FieldRegistry::EDITION_STATUS_COMPLETED => '#2271b1',
        ];
        ?>
        <div class="stride-edition-sidebar">
            <!-- Status Section -->
            <div class="stride-sidebar-section">
                <label for="edition_status"><?php esc_html_e('Status', 'stride'); ?></label>
                <select id="edition_status" name="ntdst_fields[status]" class="stride-status-select">
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Capacity Section -->
            <div class="stride-sidebar-section">
                <label for="edition_capacity"><?php esc_html_e('Capaciteit', 'stride'); ?></label>
                <input type="number" id="edition_capacity" name="ntdst_fields[capacity]"
                       value="<?php echo esc_attr($capacity); ?>" min="0" step="1"
                       placeholder="<?php esc_attr_e('Onbeperkt', 'stride'); ?>">

                <?php if ($capacity > 0): ?>
                    <div class="stride-capacity-bar">
                        <div class="stride-capacity-fill" style="width: <?php echo min(100, $percentage); ?>%;"></div>
                    </div>
                    <div class="stride-capacity-text">
                        <?php echo esc_html(sprintf('%d / %d (%d%%)', $registeredCount, $capacity, $percentage)); ?>
                    </div>
                <?php elseif ($registeredCount > 0): ?>
                    <div class="stride-capacity-text">
                        <?php echo esc_html(sprintf('%d %s', $registeredCount, __('inschrijvingen', 'stride'))); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Stats -->
            <div class="stride-sidebar-section">
                <ul class="stride-sidebar-meta">
                    <li>
                        <span class="meta-label"><?php esc_html_e('Sessies', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html(count($sessions)); ?></span>
                    </li>
                    <li>
                        <span class="meta-label"><?php esc_html_e('Totaal uren', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html(number_format($totalHours, 1, ',', '.')); ?>u</span>
                    </li>
                </ul>
            </div>

            <!-- Completion Settings -->
            <div class="stride-sidebar-section">
                <h4><?php esc_html_e('Voltooiingsmodus', 'stride'); ?></h4>
                <select name="ntdst_fields[completion_mode]" class="stride-completion-mode">
                    <option value="attend_all" <?php selected($edition['completion_mode'] ?? 'attend_all', 'attend_all'); ?>>
                        <?php esc_html_e('Alle sessies volgen', 'stride'); ?>
                    </option>
                    <option value="attend_percentage" <?php selected($edition['completion_mode'] ?? '', 'attend_percentage'); ?>>
                        <?php esc_html_e('Percentage sessies', 'stride'); ?>
                    </option>
                    <option value="attend_count" <?php selected($edition['completion_mode'] ?? '', 'attend_count'); ?>>
                        <?php esc_html_e('Minimum aantal sessies', 'stride'); ?>
                    </option>
                </select>
                <div class="stride-completion-threshold" style="margin-top: 8px;">
                    <label>
                        <?php esc_html_e('Drempel', 'stride'); ?>
                        <input type="number" name="ntdst_fields[completion_threshold]"
                               value="<?php echo esc_attr($edition['completion_threshold'] ?? 100); ?>"
                               min="0" max="100" step="1" style="width: 60px;">
                        <span class="threshold-unit">%</span>
                    </label>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save edition meta on post save
     */
    public function saveEditionMeta(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['stride_edition_nonce']) ||
            !wp_verify_nonce($_POST['stride_edition_nonce'], 'stride_save_edition')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $model = $this->getModel();
        if (!$model) {
            return;
        }

        $fields = $_POST['ntdst_fields'] ?? [];
        if (empty($fields)) {
            return;
        }

        $updateData = [];

        // Course ID
        if (isset($fields['course_id'])) {
            $updateData[FieldRegistry::EDITION_COURSE_ID] = absint($fields['course_id']);
        }

        // Dates
        if (isset($fields['start_date'])) {
            $updateData[FieldRegistry::EDITION_START_DATE] = sanitize_text_field($fields['start_date']);
        }
        if (isset($fields['end_date'])) {
            $updateData[FieldRegistry::EDITION_END_DATE] = sanitize_text_field($fields['end_date']);
        }

        // Venue & Speakers
        if (isset($fields['venue'])) {
            $updateData[FieldRegistry::EDITION_VENUE] = sanitize_text_field($fields['venue']);
        }
        if (isset($fields['speakers'])) {
            $updateData[FieldRegistry::EDITION_SPEAKERS] = sanitize_text_field($fields['speakers']);
        }

        // Pricing
        if (isset($fields['price'])) {
            $updateData[FieldRegistry::EDITION_PRICE] = (float) $fields['price'];
        }
        if (isset($fields['price_non_member'])) {
            $updateData[FieldRegistry::EDITION_PRICE_NON_MEMBER] = (float) $fields['price_non_member'];
        }
        if (isset($fields['invoice_item'])) {
            $updateData[FieldRegistry::EDITION_INVOICE_ITEM] = sanitize_text_field($fields['invoice_item']);
        }

        // Booleans (checkboxes)
        $updateData[FieldRegistry::EDITION_INVOICE_ENABLED] = !empty($fields['invoice_enabled']);
        $updateData[FieldRegistry::EDITION_CERTIFICATE_ENABLED] = !empty($fields['certificate_enabled']);
        $updateData[FieldRegistry::EDITION_IS_MULTI_YEAR] = !empty($fields['is_multi_year_training']);

        // Informational fields
        if (isset($fields['target_group'])) {
            $updateData[FieldRegistry::EDITION_TARGET_GROUP] = sanitize_textarea_field($fields['target_group']);
        }
        if (isset($fields['prerequisites'])) {
            $updateData[FieldRegistry::EDITION_PREREQUISITES] = sanitize_textarea_field($fields['prerequisites']);
        }
        if (isset($fields['trainers'])) {
            $updateData[FieldRegistry::EDITION_TRAINERS] = sanitize_text_field($fields['trainers']);
        }
        if (isset($fields['accreditation'])) {
            $updateData[FieldRegistry::EDITION_ACCREDITATION] = sanitize_text_field($fields['accreditation']);
        }

        // Status & Capacity
        if (isset($fields['status'])) {
            $updateData[FieldRegistry::EDITION_STATUS] = sanitize_text_field($fields['status']);
        }
        if (isset($fields['capacity'])) {
            $updateData[FieldRegistry::EDITION_CAPACITY] = absint($fields['capacity']);
        }

        // Completion settings
        if (isset($fields['completion_mode'])) {
            $updateData[FieldRegistry::EDITION_COMPLETION_MODE] = sanitize_text_field($fields['completion_mode']);
        }
        if (isset($fields['completion_threshold'])) {
            $updateData[FieldRegistry::EDITION_COMPLETION_THRESHOLD] = absint($fields['completion_threshold']);
        }

        // Session slots configuration
        if (isset($fields['session_slots']) && is_array($fields['session_slots'])) {
            $sanitizedSlots = [];
            foreach ($fields['session_slots'] as $slot) {
                if (empty($slot['slot'])) {
                    continue; // Skip empty slots
                }
                $sanitizedSlots[] = [
                    'slot' => sanitize_key($slot['slot']),
                    'label' => sanitize_text_field($slot['label'] ?? $slot['slot']),
                    'pick_count' => max(1, absint($slot['pick_count'] ?? 1)),
                    'required' => !empty($slot['required']),
                ];
            }
            $updateData[FieldRegistry::EDITION_SESSION_SLOTS] = $sanitizedSlots;
        } elseif (isset($_POST['ntdst_fields']) && !isset($fields['session_slots'])) {
            // Form was submitted but no slots - clear the configuration
            $updateData[FieldRegistry::EDITION_SESSION_SLOTS] = [];
        }

        // Selection deadline
        if (isset($fields['selection_deadline'])) {
            $updateData[FieldRegistry::EDITION_SELECTION_DEADLINE] = sanitize_text_field($fields['selection_deadline']);
        }

        if (!empty($updateData)) {
            $model->update($postId, $updateData);

            // Update post title from course name + date if course is set
            if (!empty($updateData[FieldRegistry::EDITION_COURSE_ID])) {
                $courseTitle = get_the_title($updateData[FieldRegistry::EDITION_COURSE_ID]) ?: '';
                $startDate = $updateData[FieldRegistry::EDITION_START_DATE] ?? '';
                $newTitle = trim($courseTitle . ' - ' . $startDate);

                if ($newTitle && $newTitle !== $post->post_title) {
                    remove_action('save_post_' . EditionService::POST_TYPE, [$this, 'saveEditionMeta'], 10);
                    wp_update_post([
                        'ID' => $postId,
                        'post_title' => $newTitle,
                    ]);
                    add_action('save_post_' . EditionService::POST_TYPE, [$this, 'saveEditionMeta'], 10, 2);
                }
            }
        }
    }

    /**
     * Get the Data Model for editions
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(EditionService::POST_TYPE);
    }

    // ========================================
    // AJAX HANDLERS - SESSIONS
    // ========================================

    /**
     * AJAX: Get sessions for an edition
     */
    public function ajaxGetSessions(): void
    {
        check_ajax_referer('stride_edition_admin', 'nonce');

        $editionId = absint($_POST['edition_id'] ?? 0);
        if (!$editionId) {
            wp_send_json_error(['message' => __('Ongeldige editie.', 'stride')]);
        }

        $sessions = $this->getSessionService()->getSessionsForEdition($editionId);
        $registeredCount = $this->getRegistrationRepo()->countByEdition($editionId, 'confirmed');
        $capacity = $this->getEditionService()->getCapacity($editionId) ?? 0;
        $editionSlots = $this->getEditionService()->getSessionSlots($editionId);

        $html = '';
        foreach ($sessions as $session) {
            $html .= $this->renderSessionRow($session, $registeredCount, $capacity, $editionSlots);
        }

        wp_send_json_success(['html' => $html, 'count' => count($sessions)]);
    }

    /**
     * AJAX: Add a new session
     */
    public function ajaxAddSession(): void
    {
        check_ajax_referer('stride_edition_admin', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Geen rechten.', 'stride')]);
        }

        $editionId = absint($_POST['edition_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');
        $startTime = sanitize_text_field($_POST['start_time'] ?? '');
        $endTime = sanitize_text_field($_POST['end_time'] ?? '');
        $location = sanitize_text_field($_POST['location'] ?? '');
        $slot = sanitize_text_field($_POST['slot'] ?? '');

        if (!$editionId || !$date) {
            wp_send_json_error(['message' => __('Editie en datum zijn verplicht.', 'stride')]);
        }

        $sessionData = [
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => $date,
            FieldRegistry::SESSION_START_TIME => $startTime,
            FieldRegistry::SESSION_END_TIME => $endTime,
            FieldRegistry::SESSION_LOCATION => $location,
        ];

        if ($slot) {
            $sessionData[FieldRegistry::SESSION_SLOT] = $slot;
        }

        $result = $this->getSessionService()->createSession($sessionData);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Get updated session list
        $sessions = $this->getSessionService()->getSessionsForEdition($editionId);
        $registeredCount = $this->getRegistrationRepo()->countByEdition($editionId, 'confirmed');
        $capacity = $this->getEditionService()->getCapacity($editionId) ?? 0;
        $editionSlots = $this->getEditionService()->getSessionSlots($editionId);

        $html = '';
        foreach ($sessions as $session) {
            $html .= $this->renderSessionRow($session, $registeredCount, $capacity, $editionSlots);
        }

        wp_send_json_success([
            'html' => $html,
            'count' => count($sessions),
            'session_id' => $result,
        ]);
    }

    /**
     * AJAX: Update a session
     */
    public function ajaxUpdateSession(): void
    {
        check_ajax_referer('stride_edition_admin', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Geen rechten.', 'stride')]);
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');
        $startTime = sanitize_text_field($_POST['start_time'] ?? '');
        $endTime = sanitize_text_field($_POST['end_time'] ?? '');
        $location = sanitize_text_field($_POST['location'] ?? '');
        $slot = sanitize_text_field($_POST['slot'] ?? '');

        if (!$sessionId || !$date) {
            wp_send_json_error(['message' => __('Sessie en datum zijn verplicht.', 'stride')]);
        }

        $result = $this->getSessionService()->updateSession($sessionId, [
            FieldRegistry::SESSION_DATE => $date,
            FieldRegistry::SESSION_START_TIME => $startTime,
            FieldRegistry::SESSION_END_TIME => $endTime,
            FieldRegistry::SESSION_LOCATION => $location,
            FieldRegistry::SESSION_SLOT => $slot,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Get the session's edition for refreshing the list
        $session = $this->getSessionService()->getSession($sessionId);
        $editionId = $session['edition_id'] ?? 0;

        $sessions = $this->getSessionService()->getSessionsForEdition($editionId);
        $registeredCount = $this->getRegistrationRepo()->countByEdition($editionId, 'confirmed');
        $capacity = $this->getEditionService()->getCapacity($editionId) ?? 0;
        $editionSlots = $this->getEditionService()->getSessionSlots($editionId);

        $html = '';
        foreach ($sessions as $s) {
            $html .= $this->renderSessionRow($s, $registeredCount, $capacity, $editionSlots);
        }

        wp_send_json_success(['html' => $html, 'count' => count($sessions)]);
    }

    /**
     * AJAX: Delete a session
     */
    public function ajaxDeleteSession(): void
    {
        check_ajax_referer('stride_edition_admin', 'nonce');

        if (!current_user_can('delete_posts')) {
            wp_send_json_error(['message' => __('Geen rechten.', 'stride')]);
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        if (!$sessionId) {
            wp_send_json_error(['message' => __('Ongeldige sessie.', 'stride')]);
        }

        // Get edition ID before deleting
        $session = $this->getSessionService()->getSession($sessionId);
        $editionId = $session['edition_id'] ?? 0;

        // Delete the session post
        $result = wp_delete_post($sessionId, true);
        if (!$result) {
            wp_send_json_error(['message' => __('Kon sessie niet verwijderen.', 'stride')]);
        }

        // Invalidate session cache
        SessionService::invalidateCache($editionId);

        // Get updated session list
        $sessions = $this->getSessionService()->getSessionsForEdition($editionId);
        $registeredCount = $this->getRegistrationRepo()->countByEdition($editionId, 'confirmed');
        $capacity = $this->getEditionService()->getCapacity($editionId) ?? 0;
        $editionSlots = $this->getEditionService()->getSessionSlots($editionId);

        $html = '';
        foreach ($sessions as $s) {
            $html .= $this->renderSessionRow($s, $registeredCount, $capacity, $editionSlots);
        }

        wp_send_json_success(['html' => $html, 'count' => count($sessions)]);
    }

    // ========================================
    // AJAX HANDLERS - ATTENDANCE
    // ========================================

    /**
     * AJAX: Get attendance for a session
     */
    public function ajaxGetAttendance(): void
    {
        check_ajax_referer('stride_edition_admin', 'nonce');

        $sessionId = absint($_POST['session_id'] ?? 0);
        if (!$sessionId) {
            wp_send_json_error(['message' => __('Ongeldige sessie.', 'stride')]);
        }

        $attendance = $this->getSessionService()->getSessionAttendance($sessionId);
        wp_send_json_success(['attendance' => $attendance]);
    }

    /**
     * AJAX: Mark attendance for a user
     */
    public function ajaxMarkAttendance(): void
    {
        check_ajax_referer('stride_edition_admin', 'nonce');

        if (!$this->getSessionService()->canManageAttendance()) {
            wp_send_json_error(['message' => __('Geen rechten.', 'stride')]);
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        $userId = absint($_POST['user_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        if (!$sessionId || !$userId) {
            wp_send_json_error(['message' => __('Ongeldige gegevens.', 'stride')]);
        }

        $result = match ($status) {
            'present' => $this->getSessionService()->markPresent($sessionId, $userId),
            'absent' => $this->getSessionService()->markAbsent($sessionId, $userId),
            'excused' => $this->getSessionService()->markExcused($sessionId, $userId),
            default => new \WP_Error('invalid_status', __('Ongeldige status.', 'stride')),
        };

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Get updated stats
        $session = $this->getSessionService()->getSession($sessionId);
        $editionId = $session['edition_id'] ?? 0;
        $attendees = $this->getSessionService()->getAttendees($sessionId);
        $totalRegistrations = $this->getRegistrationRepo()->countByEdition($editionId, 'confirmed');
        $percentage = $totalRegistrations > 0 ? round((count($attendees) / $totalRegistrations) * 100) : 0;

        wp_send_json_success([
            'status' => $status,
            'presentCount' => count($attendees),
            'totalCount' => $totalRegistrations,
            'percentage' => $percentage,
        ]);
    }

    /**
     * AJAX: Mark all users as present for a session
     */
    public function ajaxBulkAttendance(): void
    {
        check_ajax_referer('stride_edition_admin', 'nonce');

        if (!$this->getSessionService()->canManageAttendance()) {
            wp_send_json_error(['message' => __('Geen rechten.', 'stride')]);
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        if (!$sessionId) {
            wp_send_json_error(['message' => __('Ongeldige sessie.', 'stride')]);
        }

        $session = $this->getSessionService()->getSession($sessionId);
        $editionId = $session['edition_id'] ?? 0;

        // Get all confirmed registrations
        $registrations = $this->getRegistrationRepo()->getByEdition($editionId, 'confirmed');
        $userStatuses = [];
        foreach ($registrations as $registration) {
            $userStatuses[$registration['user_id']] = 'present';
        }

        $count = $this->getSessionService()->batchMarkAttendance($sessionId, $userStatuses);

        wp_send_json_success([
            'count' => $count,
            'totalCount' => count($registrations),
            'percentage' => 100,
        ]);
    }
}
