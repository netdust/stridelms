<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
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
        wp_nonce_field('stride_save_edition', 'stride_edition_nonce');

        $courseId = (int) $this->repository->getField($post->ID, 'course_id', 0);
        $startDate = $this->repository->getField($post->ID, 'start_date', '');
        $endDate = $this->repository->getField($post->ID, 'end_date', '');
        $capacity = (int) $this->repository->getField($post->ID, 'capacity', 0);
        $venue = $this->repository->getField($post->ID, 'venue', '');
        $speakers = $this->repository->getField($post->ID, 'speakers', '');
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
                    <button type="button" class="stride-tab" data-tab="informatie">
                        <?php esc_html_e('Informatie', 'stride'); ?>
                    </button>
                    <button type="button" class="stride-tab" data-tab="prijzen">
                        <?php esc_html_e('Prijzen', 'stride'); ?>
                    </button>
                </div>

                <!-- Tab: Algemeen -->
                <div class="stride-tab-content active" data-tab="algemeen">
                    <?php $this->renderAlgemeenTab($courses, $courseId, $startDate, $endDate, $capacity, $venue); ?>
                </div>

                <!-- Tab: Informatie -->
                <div class="stride-tab-content" data-tab="informatie">
                    <?php $this->renderInformatieTab($speakers); ?>
                </div>

                <!-- Tab: Prijzen -->
                <div class="stride-tab-content" data-tab="prijzen">
                    <?php $this->renderPrijzenTab($price, $priceNonMember); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderAlgemeenTab(array $courses, int $courseId, string $startDate, string $endDate, int $capacity, string $venue): void
    {
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
            </div>

            <div class="stride-edition-side">
                <h4><?php esc_html_e('Locatie & Capaciteit', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="edition_venue"><?php esc_html_e('Locatie', 'stride'); ?></label>
                        <input type="text" name="ntdst_fields[venue]" id="edition_venue"
                               value="<?php echo esc_attr($venue); ?>"
                               placeholder="<?php esc_attr_e('bijv. Online / Kantoor Gent', 'stride'); ?>">
                    </div>
                </div>
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

    private function renderInformatieTab(string $speakers): void
    {
        ?>
        <div class="stride-edition-columns">
            <div class="stride-edition-main">
                <h4><?php esc_html_e('Sprekers / Docenten', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <textarea name="ntdst_fields[speakers]" id="edition_speakers" rows="4"
                                  placeholder="<?php esc_attr_e('Voer namen van sprekers in, gescheiden door komma\'s of op aparte regels', 'stride'); ?>"><?php echo esc_textarea($speakers); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderPrijzenTab(int $price, int $priceNonMember): void
    {
        // Convert cents to euros for display
        $priceEur = $price > 0 ? number_format($price / 100, 2, '.', '') : '';
        $priceNonMemberEur = $priceNonMember > 0 ? number_format($priceNonMember / 100, 2, '.', '') : '';
        ?>
        <div class="stride-edition-columns">
            <div class="stride-edition-main">
                <h4><?php esc_html_e('Prijzen', 'stride'); ?></h4>
                <div class="stride-field-row two-col">
                    <div class="stride-field">
                        <label for="edition_price"><?php esc_html_e('Prijs leden', 'stride'); ?></label>
                        <input type="number" name="ntdst_fields[price]" id="edition_price"
                               value="<?php echo esc_attr($priceEur); ?>"
                               min="0" step="0.01" placeholder="0.00">
                        <p class="description"><?php esc_html_e('excl. BTW', 'stride'); ?></p>
                    </div>
                    <div class="stride-field">
                        <label for="edition_price_non_member"><?php esc_html_e('Prijs niet-leden', 'stride'); ?></label>
                        <input type="number" name="ntdst_fields[price_non_member]" id="edition_price_non_member"
                               value="<?php echo esc_attr($priceNonMemberEur); ?>"
                               min="0" step="0.01" placeholder="0.00">
                        <p class="description"><?php esc_html_e('excl. BTW', 'stride'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function getCourses(): array
    {
        $courses = [];

        $posts = get_posts([
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'posts_per_page' => -1,
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
}
