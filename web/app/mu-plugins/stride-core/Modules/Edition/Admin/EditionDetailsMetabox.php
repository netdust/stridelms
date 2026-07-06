<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\User\ProfiletypeRulesMetaboxFields;
use WP_Post;

/**
 * Edition Details Metabox.
 *
 * Renders tabbed form for edition details:
 * - Algemeen: Course selection, dates, venue
 * - Informatie: Speakers, description
 * - Prijzen: Member/non-member pricing
 */
final class EditionDetailsMetabox
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $repository,
    ) {}

    public function render(WP_Post $post): void
    {
        wp_nonce_field(EditionAdminController::NONCE_SAVE, EditionAdminController::NONCE_FIELD);

        $courseId = (int) $this->repository->getField($post->ID, 'course_id', 0);
        $startDate = $this->repository->getField($post->ID, 'start_date', '');
        $endDate = $this->repository->getField($post->ID, 'end_date', '');
        $capacity = (int) $this->repository->getField($post->ID, 'capacity', 0);
        $venue = $this->repository->getField($post->ID, 'venue', '');
        $price = (int) $this->repository->getField($post->ID, 'price', 0);
        $priceNonMember = (int) $this->repository->getField($post->ID, 'price_non_member', 0);
        // Get all courses for dropdown
        $courses = $this->getCourses();
        ?>
        <div class="stride-edition-admin">
            <div class="stride-edition-tabs">
                <div class="stride-tabs-nav">
                    <button type="button" class="stride-tab active" data-tab="algemeen">
                        <?php esc_html_e('Algemeen', 'stride'); ?>
                    </button>
                    <button type="button" class="stride-tab stride-classroom-only" data-tab="informatie">
                        <?php esc_html_e('Informatie', 'stride'); ?>
                    </button>
                    <button type="button" class="stride-tab" data-tab="prijzen">
                        <?php esc_html_e('Prijzen', 'stride'); ?>
                    </button>
                    <button type="button" class="stride-tab" data-tab="documenten">
                        <?php esc_html_e('Documenten', 'stride'); ?>
                    </button>
                    <button type="button" class="stride-tab" data-tab="cursusinstellingen" style="display:none;">
                        <?php esc_html_e('Cursusinstellingen', 'stride'); ?>
                    </button>
                    <button type="button" class="stride-tab" data-tab="toegang">
                        <?php esc_html_e('Toegang', 'stride'); ?>
                    </button>
                </div>

                <!-- Tab: Algemeen -->
                <div class="stride-tab-content active" data-tab="algemeen">
                    <?php $this->renderAlgemeenTab($courses, $courseId, $startDate, $endDate, $capacity, $venue, $post->ID); ?>
                </div>

                <!-- Tab: Informatie -->
                <div class="stride-tab-content" data-tab="informatie">
                    <?php $this->renderInformatieTab($post->ID); ?>
                </div>

                <!-- Tab: Prijzen -->
                <div class="stride-tab-content" data-tab="prijzen">
                    <?php $this->renderPrijzenTab($price, $priceNonMember); ?>
                </div>

                <!-- Tab: Documenten -->
                <div class="stride-tab-content" data-tab="documenten">
                    <?php $this->renderDocumentenTab($post->ID); ?>
                </div>

                <!-- Tab: Cursusinstellingen (online only, shown via tab system) -->
                <div class="stride-tab-content" data-tab="cursusinstellingen">
                    <?php $this->renderCursusinstellingenTab($courseId); ?>
                </div>

                <!-- Tab: Toegang (profile-type enroll rules + catalog flag) -->
                <div class="stride-tab-content" data-tab="toegang">
                    <?php
                    ProfiletypeRulesMetaboxFields::render(
                        $this->repository->getProfiletypeRules($post->ID),
                        $this->repository->getExcludeFromCatalog($post->ID),
                    );
        ?>
                </div>

            </div>
        </div>
        <?php
    }

    private function renderAlgemeenTab(array $courses, int $courseId, string $startDate, string $endDate, int $capacity, string $venue, int $editionId): void
    {
        $drift = $this->detectDateDrift($editionId, $startDate, $endDate);

        ?>
        <div class="stride-edition-columns">
            <div class="stride-edition-main">
                <h4><?php esc_html_e('Cursus', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field stride-course-field">
                        <label for="edition_course_id"><?php esc_html_e('Cursus', 'stride'); ?></label>
                        <select name="ntdst_fields[course_id]" id="edition_course_id" class="stride-select2-course">
                            <option value=""><?php esc_html_e('Selecteer cursus...', 'stride'); ?></option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo esc_attr($course['id']); ?>" <?php echo $courseId === $course['id'] ? 'selected' : ''; ?>>
                                    <?php echo esc_html($course['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <h4><?php esc_html_e('Datum & Tijd', 'stride'); ?></h4>
                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="edition_start_date"><?php esc_html_e('Startdatum', 'stride'); ?></label>
                            <input type="date" name="ntdst_fields[start_date]" id="edition_start_date"
                                   value="<?php echo esc_attr($startDate); ?>">
                        </div>
                        <div class="stride-field">
                            <label for="edition_end_date"><?php esc_html_e('Einddatum', 'stride'); ?></label>
                            <input type="date" name="ntdst_fields[end_date]" id="edition_end_date"
                                   value="<?php echo esc_attr($endDate); ?>">
                        </div>
                    </div>
                    <p class="description" style="margin-top: 6px; font-size: 11px;">
                        <?php esc_html_e('Optioneel. Laat leeg voor zelfgestuurde edities zonder vaste planning.', 'stride'); ?>
                    </p>
                    <?php if (!empty($drift)) : ?>
                        <div class="notice notice-warning inline" style="margin: 8px 0; padding: 8px 12px;">
                            <p style="margin: 0;">
                                <strong><?php esc_html_e('Let op:', 'stride'); ?></strong>
                                <?php echo esc_html($drift); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stride-edition-side">
                <div>
                    <h4><?php esc_html_e('Locatie', 'stride'); ?></h4>
                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="edition_venue"><?php esc_html_e('Locatie', 'stride'); ?></label>
                            <input type="text" name="ntdst_fields[venue]" id="edition_venue"
                                   value="<?php echo esc_attr($venue); ?>"
                                   placeholder="<?php esc_attr_e('bijv. Online / Kantoor Gent', 'stride'); ?>">
                        </div>
                    </div>
                </div>
                <h4><?php esc_html_e('Capaciteit', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="edition_capacity"><?php esc_html_e('Capaciteit', 'stride'); ?></label>
                        <input type="number" name="ntdst_fields[capacity]" id="edition_capacity"
                               value="<?php echo esc_attr($capacity ?: ''); ?>"
                               min="0" step="1">
                        <p class="description"><?php esc_html_e('Maximum aantal deelnemers. Laat leeg voor onbeperkt.', 'stride'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderInformatieTab(int $editionId): void
    {
        $speakers = $this->repository->getSpeakers($editionId);
        // Content fields, prefilled with defaults when empty (persist on first
        // save). Schema-registered fields always exist on the formatted read
        // (as ''), so getField()'s default never kicks in — fall back on the
        // empty string explicitly.
        $defaults = EditionCPT::getContentDefaults();
        $content = [];
        foreach ($defaults as $key => $default) {
            $value = trim((string) $this->repository->getField($editionId, $key, ''));
            $content[$key] = $value !== '' ? $value : $default;
        }
        ?>
        <div class="stride-edition-columns">
            <div class="stride-edition-main">
                <h4><?php esc_html_e('Sprekers / Docenten', 'stride'); ?></h4>
                <?php /* Presence marker: lets the save handler clear speakers
                         when every repeater row is removed (an empty array
                         posts nothing). */ ?>
                <input type="hidden" name="ntdst_fields[speakers_present]" value="1">
                <div id="stride-speakers-list" class="stride-speakers-list">
                    <?php foreach ($speakers as $i => $speaker) : ?>
                        <?php $this->renderSpeakerRow($i, $speaker['name'], $speaker['role']); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button" id="stride-add-speaker">
                    <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php esc_html_e('Spreker toevoegen', 'stride'); ?>
                </button>
                <script type="text/template" id="stride-speaker-row-template">
                    <?php $this->renderSpeakerRow('__INDEX__', '', ''); ?>
                </script>

                <h4 style="margin-top: 20px;"><?php esc_html_e('Doelpubliek', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <textarea name="ntdst_fields[target_audience]" id="edition_target_audience" rows="2"
                                  placeholder="<?php esc_attr_e('bijv. Intergemeentelijke- en lokale preventiewerkers', 'stride'); ?>"><?php echo esc_textarea($content['target_audience']); ?></textarea>
                        <p class="description"><?php esc_html_e('Getoond als "Voor wie?" op de editiepagina. Leeg = niet getoond.', 'stride'); ?></p>
                    </div>
                </div>

                <h4><?php esc_html_e('Voorkennis', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <input type="text" name="ntdst_fields[required_experience]" id="edition_required_experience"
                               value="<?php echo esc_attr($content['required_experience']); ?>">
                    </div>
                </div>

                <h4><?php esc_html_e('Inbegrepen', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <textarea name="ntdst_fields[included]" id="edition_included" rows="2"><?php echo esc_textarea($content['included']); ?></textarea>
                    </div>
                </div>

                <h4><?php esc_html_e('Annuleringsvoorwaarden', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <textarea name="ntdst_fields[cancellation_policy]" id="edition_cancellation_policy" rows="2"><?php echo esc_textarea($content['cancellation_policy']); ?></textarea>
                    </div>
                </div>

                <h4><?php esc_html_e('Inschrijvingsinfo', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <textarea name="ntdst_fields[enrollment_info]" id="edition_enrollment_info" rows="2"
                                  placeholder="<?php esc_attr_e('bijv. Uiterste inschrijvingsdatum: 24 augustus 2026. Na inschrijving ontvang je een mail met de bevestiging van je deelname.', 'stride'); ?>"><?php echo esc_textarea($content['enrollment_info']); ?></textarea>
                        <p class="description"><?php esc_html_e('Getoond onder de inschrijfknop.', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <div class="stride-edition-side">
                <h4><?php esc_html_e('Prijs inclusief', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <input type="text" name="ntdst_fields[price_includes]" id="edition_price_includes"
                               value="<?php echo esc_attr($content['price_includes']); ?>">
                        <p class="description"><?php esc_html_e('Korte regel onder de prijs in de zijbalk.', 'stride'); ?></p>
                    </div>
                </div>

                <h4><?php esc_html_e('Voordelen', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <textarea name="ntdst_fields[cta_benefits]" id="edition_cta_benefits" rows="3"><?php echo esc_textarea($content['cta_benefits']); ?></textarea>
                        <p class="description"><?php esc_html_e('Eén voordeel per regel; getoond als checklist in de zijbalk.', 'stride'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * One speakers-repeater row. $index is an int for stored rows or the
     * literal '__INDEX__' placeholder inside the JS row template.
     */
    private function renderSpeakerRow(int|string $index, string $name, string $role): void
    {
        ?>
        <div class="stride-speaker-row" style="display: flex; gap: 8px; margin-bottom: 6px;">
            <input type="text" name="ntdst_fields[speakers][<?php echo esc_attr((string) $index); ?>][name]"
                   value="<?php echo esc_attr($name); ?>"
                   placeholder="<?php esc_attr_e('Naam', 'stride'); ?>" style="flex: 1;">
            <input type="text" name="ntdst_fields[speakers][<?php echo esc_attr((string) $index); ?>][role]"
                   value="<?php echo esc_attr($role); ?>"
                   placeholder="<?php esc_attr_e('Rol (bijv. sportpedagoge)', 'stride'); ?>" style="flex: 1;">
            <button type="button" class="stride-speaker-remove button-link" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>" style="color: #d63638;">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <?php
    }

    private function renderPrijzenTab(int $price, int $priceNonMember): void
    {
        // Convert cents to euros for display
        $priceEur = $price > 0 ? number_format($price / 100, 2, '.', '') : '';
        $priceNonMemberEur = $priceNonMember > 0 ? number_format($priceNonMember / 100, 2, '.', '') : '';
        ?>
        <?php
        // v1 has no member feature — single price applies to everyone.
        // The displayed price is `price_non_member` (canonical for v1).
        // EditionAdminController writes the same value to both meta keys so
        // pricing logic that still routes via member/non-member meta stays
        // correct should membership be re-enabled later.
        ?>
        <div class="stride-edition-columns">
            <div class="stride-edition-main">
                <h4><?php esc_html_e('Prijs', 'stride'); ?></h4>
                <div class="stride-field">
                    <label for="edition_price"><?php esc_html_e('Prijs', 'stride'); ?></label>
                    <input type="number" name="ntdst_fields[price_non_member]" id="edition_price"
                           value="<?php echo esc_attr($priceNonMemberEur); ?>"
                           min="0" step="0.01" placeholder="0.00">
                    <p class="description"><?php esc_html_e('excl. BTW', 'stride'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderCursusinstellingenTab(int $courseId): void
    {
        if (!$courseId || !LearnDashHelper::isActive()) {
            ?>
            <p class="description"><?php esc_html_e('Selecteer eerst een cursus om de instellingen te zien.', 'stride'); ?></p>
            <?php
            return;
        }

        $accessMode = LearnDashHelper::getAccessMode($courseId);
        $price = LearnDashHelper::getCoursePrice($courseId);
        $points = LearnDashHelper::getCoursePoints($courseId);
        $hasExpiration = LearnDashHelper::hasExpiration($courseId);
        $expireDays = $hasExpiration ? (int) learndash_get_setting($courseId, 'expire_access_days') : 0;
        $hasPrereqs = LearnDashHelper::hasPrerequisites($courseId);
        $prereqs = $hasPrereqs ? LearnDashHelper::getPrerequisites($courseId) : [];
        $hasDrip = LearnDashHelper::hasDripFeed($courseId);
        $hasCert = LearnDashHelper::hasCertificate($courseId);
        $startDate = LearnDashHelper::getStartDate($courseId);
        $endDate = LearnDashHelper::getEndDate($courseId);
        $lessons = LearnDashHelper::getLessons($courseId);
        $materials = LearnDashHelper::getCourseMaterials($courseId);

        $modeLabels = [
            'open' => __('Open (iedereen)', 'stride'),
            'free' => __('Gratis (registratie vereist)', 'stride'),
            'paynow' => __('Betaald', 'stride'),
            'subscribe' => __('Abonnement', 'stride'),
            'closed' => __('Gesloten (custom)', 'stride'),
        ];

        $editUrl = get_edit_post_link($courseId);
        ?>
        <div class="stride-readonly-settings">
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e('Alleen-lezen overzicht van de LearnDash cursusinstellingen.', 'stride'); ?>
                <?php if ($editUrl): ?>
                    <a href="<?php echo esc_url($editUrl); ?>" target="_blank"><?php esc_html_e('Bewerk cursus', 'stride'); ?> &rarr;</a>
                <?php endif; ?>
            </p>

            <table class="widefat striped" style="border:0;">
                <tbody>
                    <tr>
                        <th style="width:180px;"><?php esc_html_e('Toegangsmodus', 'stride'); ?></th>
                        <td><?php echo esc_html($modeLabels[$accessMode] ?? $accessMode); ?></td>
                    </tr>
                    <?php if ($price['price']): ?>
                    <tr>
                        <th><?php esc_html_e('Prijs (LD)', 'stride'); ?></th>
                        <td><?php echo esc_html(LearnDashHelper::formatPrice($price)); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('Lessen', 'stride'); ?></th>
                        <td><?php echo esc_html(count($lessons)); ?></td>
                    </tr>
                    <?php if ($points > 0): ?>
                    <tr>
                        <th><?php esc_html_e('Punten', 'stride'); ?></th>
                        <td><?php echo esc_html($points); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($hasExpiration): ?>
                    <tr>
                        <th><?php esc_html_e('Toegang verloopt', 'stride'); ?></th>
                        <td><?php echo esc_html(sprintf(_n('%d dag', '%d dagen', $expireDays, 'stride'), $expireDays)); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($startDate || $endDate): ?>
                    <tr>
                        <th><?php esc_html_e('Beschikbaarheid', 'stride'); ?></th>
                        <td>
                            <?php if ($startDate): ?>
                                <?php echo esc_html(date_i18n('j M Y', $startDate)); ?>
                            <?php endif; ?>
                            <?php if ($startDate && $endDate): ?> – <?php endif; ?>
                            <?php if ($endDate): ?>
                                <?php echo esc_html(date_i18n('j M Y', $endDate)); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('Vereisten', 'stride'); ?></th>
                        <td>
                            <?php if ($hasPrereqs && !empty($prereqs)): ?>
                                <?php echo esc_html(implode(', ', array_column($prereqs, 'title'))); ?>
                            <?php else: ?>
                                <?php esc_html_e('Geen', 'stride'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Drip-feed', 'stride'); ?></th>
                        <td><?php echo $hasDrip ? esc_html__('Ja', 'stride') : esc_html__('Nee', 'stride'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Certificaat', 'stride'); ?></th>
                        <td><?php echo $hasCert ? esc_html__('Ja', 'stride') : esc_html__('Nee', 'stride'); ?></td>
                    </tr>
                    <?php if ($materials): ?>
                    <tr>
                        <th><?php esc_html_e('Materialen', 'stride'); ?></th>
                        <td><?php echo wp_kses_post($materials); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function getCourses(): array
    {
        $courses = [];

        $posts = get_posts([
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'posts_per_page' => 200, // Reasonable limit for course dropdown
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        foreach ($posts as $post) {
            $courses[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
            ];
        }

        return $courses;
    }

    /**
     * Return a human-readable warning when edition.start_date / end_date
     * doesn't bracket the actual session dates. Empty string when in sync.
     *
     * Editions store their own start/end (used for sorting + visibility);
     * sessions store per-day dates. Nothing auto-syncs them, so the admin
     * needs a nudge when they drift.
     */
    private function detectDateDrift(int $editionId, string $startDate, string $endDate): string
    {
        if (!$editionId) {
            return '';
        }
        $sessionService = ntdst_get(SessionService::class);
        $sessions = $sessionService->getSessionsForEdition($editionId);
        if (empty($sessions)) {
            return '';
        }

        $dates = [];
        foreach ($sessions as $s) {
            $d = (string) ($s['date'] ?? '');
            if ($d !== '') {
                $dates[] = $d;
            }
        }
        if (empty($dates)) {
            return '';
        }
        sort($dates);
        $sessionMin = $dates[0];
        $sessionMax = end($dates);

        $warnings = [];
        if ($startDate && $sessionMin !== $startDate) {
            $warnings[] = sprintf(
                /* translators: 1: stored start date, 2: first session date */
                __('Startdatum (%1$s) komt niet overeen met de eerste sessie (%2$s).', 'stride'),
                $startDate,
                $sessionMin,
            );
        }
        if ($endDate && $sessionMax !== $endDate) {
            $warnings[] = sprintf(
                /* translators: 1: stored end date, 2: last session date */
                __('Einddatum (%1$s) komt niet overeen met de laatste sessie (%2$s).', 'stride'),
                $endDate,
                $sessionMax,
            );
        }
        // Missing edition dates but sessions exist
        if (!$startDate) {
            $warnings[] = sprintf(
                /* translators: %s: first session date */
                __('Startdatum is leeg, terwijl de eerste sessie op %s gepland staat.', 'stride'),
                $sessionMin,
            );
        }
        if (!$endDate && $sessionMin !== $sessionMax) {
            $warnings[] = sprintf(
                /* translators: %s: last session date */
                __('Einddatum is leeg, terwijl de laatste sessie op %s gepland staat.', 'stride'),
                $sessionMax,
            );
        }

        return implode(' ', $warnings);
    }

    private function renderDocumentenTab(int $postId): void
    {
        $documents = $this->repository->getField($postId, 'documents', []);
        if (is_string($documents)) {
            $documents = json_decode($documents, true) ?: [];
        }
        $documents = array_filter(array_map('absint', (array) $documents));
        ?>
        <h4><?php esc_html_e('Cursus documenten', 'stride'); ?></h4>
        <p class="description"><?php esc_html_e('Upload documenten die bij deze editie horen (PDF, presentaties, etc.). Deze worden beschikbaar voor deelnemers.', 'stride'); ?></p>

        <div id="stride-documents-list" class="stride-documents-list" style="margin: 12px 0;">
            <?php foreach ($documents as $attachmentId): ?>
                <?php
                $url = wp_get_attachment_url($attachmentId);
                $filename = basename(get_attached_file($attachmentId) ?: '');
                $filetype = wp_check_filetype($filename);
                $filesize = size_format(filesize(get_attached_file($attachmentId) ?: '') ?: 0);
                if (!$url) {
                    continue;
                }
                ?>
                <div class="stride-document-item" data-id="<?php echo esc_attr($attachmentId); ?>" style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 6px;">
                    <span class="dashicons dashicons-media-document" style="color: #2271b1;"></span>
                    <a href="<?php echo esc_url($url); ?>" target="_blank" style="flex: 1; text-decoration: none;"><?php echo esc_html($filename); ?></a>
                    <span style="color: #888; font-size: 12px;"><?php echo esc_html(strtoupper($filetype['ext'] ?? '')); ?> &middot; <?php echo esc_html($filesize); ?></span>
                    <button type="button" class="stride-document-remove button-link" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>" style="color: #d63638; padding: 0;">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="button" id="stride-add-documents">
            <span class="dashicons dashicons-upload" style="vertical-align: middle; margin-right: 4px;"></span>
            <?php esc_html_e('Documenten toevoegen', 'stride'); ?>
        </button>

        <input type="hidden" id="stride_documents_data" name="ntdst_fields[documents]" value="<?php echo esc_attr(json_encode(array_values($documents))); ?>">
        <?php
    }
}
