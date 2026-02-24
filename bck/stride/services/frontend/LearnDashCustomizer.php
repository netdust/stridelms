<?php
/**
 * LearnDash Customizer
 *
 * Applies Stride customizations to LearnDash templates:
 * - Loads UIkit-styled CSS overrides
 * - Injects editions/sessions for classroom courses
 *
 * @package stride
 */

namespace stride\services\frontend;

final class LearnDashCustomizer
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Enqueue LearnDash override styles
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyles'], 20);

        // Add tab switching JavaScript
        add_action('wp_footer', [$this, 'renderTabScript'], 20);

        // Inject editions section after course content list
        add_action('learndash-course-content-list-after', [$this, 'renderEditions'], 10, 2);

        // Add editions tab to course tabs
        add_filter('learndash_content_tabs', [$this, 'addEditionsTab'], 10, 4);
    }

    /**
     * Enqueue LearnDash CSS overrides.
     */
    public function enqueueStyles(): void
    {
        // Only load on LearnDash course pages
        if (!$this->isLearnDashPage()) {
            return;
        }

        wp_enqueue_style(
            'stride-learndash-overrides',
            get_template_directory_uri() . '/assets/css/learndash-overrides.css',
            ['learndash-front'],
            filemtime(get_template_directory() . '/assets/css/learndash-overrides.css')
        );
    }

    /**
     * Render tab switching JavaScript.
     *
     * LearnDash's tab system doesn't properly handle custom tabs or
     * toggle the course content accordion. This script fixes both issues.
     */
    public function renderTabScript(): void
    {
        // Only on course pages with editions tab
        if (!is_singular('sfwd-courses')) {
            return;
        }

        ?>
        <script>
        (function() {
            'use strict';

            var tabs = document.querySelectorAll('.ld-tab-bar__tab');
            var panels = document.querySelectorAll('.ld-tab-bar__panel');
            var accordion = document.querySelector('.ld-accordion');

            if (!tabs.length || !panels.length) return;

            function switchTab(selectedTab) {
                var targetPanelId = selectedTab.getAttribute('aria-controls');

                // Update tab states
                tabs.forEach(function(tab) {
                    var isSelected = tab === selectedTab;
                    tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                });

                // Update panel visibility
                panels.forEach(function(panel) {
                    var isTarget = panel.id === targetPanelId;
                    panel.setAttribute('aria-hidden', isTarget ? 'false' : 'true');
                });

                // Toggle accordion visibility based on which tab is active
                if (accordion) {
                    var isEditionsTab = selectedTab.id === 'ld-tab-editions';
                    accordion.style.display = isEditionsTab ? 'none' : '';
                }
            }

            // Add click handlers to tabs
            tabs.forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    switchTab(tab);
                });
            });

            // Initialize: ensure correct panel is shown based on aria-selected
            var selectedTab = document.querySelector('.ld-tab-bar__tab[aria-selected="true"]');
            if (selectedTab) {
                switchTab(selectedTab);
            }
        })();
        </script>
        <?php
    }

    /**
     * Add editions tab for classroom courses.
     */
    public function addEditionsTab(array $tabs, string $context, int $courseId, int $userId): array
    {
        // Only on course pages
        if ($context !== 'course') {
            return $tabs;
        }

        // Get editions for this course
        $editions = $this->getEditionsForCourse($courseId);
        if (empty($editions)) {
            return $tabs;
        }

        // Check if this is a classroom course (has in-person or webinar sessions)
        if (!$this->isClassroomCourse($courseId)) {
            return $tabs;
        }

        // Build editions content
        $content = $this->buildEditionsTabContent($editions, $courseId);

        // Add editions tab
        $tabs[] = [
            'id'        => 'editions',
            'icon'      => 'ld-icon-calendar',
            'label'     => __('Geplande sessies', 'stride'),
            'content'   => $content,
            'condition' => true,
        ];

        return $tabs;
    }

    /**
     * Render editions section after course content.
     */
    public function renderEditions(int $courseId, int $userId): void
    {
        // Only for classroom courses
        if (!$this->isClassroomCourse($courseId)) {
            return;
        }

        $editions = $this->getEditionsForCourse($courseId);
        if (empty($editions)) {
            return;
        }

        $this->renderEditionsSection($editions, $courseId);
    }

    /**
     * Get editions for a course.
     */
    private function getEditionsForCourse(int $courseId): array
    {
        if (!class_exists(\Stride\Modules\Edition\EditionService::class)) {
            return [];
        }

        try {
            $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
            return $editionService->getEditionsForCourse($courseId);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if course is classroom (has in-person or webinar sessions).
     */
    private function isClassroomCourse(int $courseId): bool
    {
        $editions = $this->getEditionsForCourse($courseId);
        if (empty($editions)) {
            return false;
        }

        if (!class_exists(\Stride\Modules\Edition\SessionService::class)) {
            return false;
        }

        try {
            $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);

            foreach ($editions as $edition) {
                $editionId = $edition['id'] ?? ($edition->ID ?? 0);
                $sessions = $sessionService->getSessionsForEdition($editionId);

                foreach ($sessions as $session) {
                    $type = $session['type'] ?? '';
                    if (in_array($type, ['in_person', 'webinar'], true)) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Build editions tab HTML content.
     */
    private function buildEditionsTabContent(array $editions, int $courseId): string
    {
        ob_start();
        $this->renderEditionsSection($editions, $courseId, false);
        return ob_get_clean();
    }

    /**
     * Render the editions section HTML.
     */
    private function renderEditionsSection(array $editions, int $courseId, bool $standalone = true): void
    {
        $sessionService = null;
        if (class_exists(\Stride\Modules\Edition\SessionService::class)) {
            try {
                $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
            } catch (\Exception $e) {
                // Service not available
            }
        }
        ?>
        <div class="stride-ld-editions<?php echo $standalone ? '' : ' stride-ld-editions--tab'; ?>">
            <?php if ($standalone): ?>
            <div class="stride-ld-editions__header">
                <h3><?php esc_html_e('Geplande sessies', 'stride'); ?></h3>
                <span class="stride-ld-editions__badge">
                    <span uk-icon="icon: calendar; ratio: 0.8"></span>
                    <?php printf(_n('%d editie', '%d edities', count($editions), 'stride'), count($editions)); ?>
                </span>
            </div>
            <?php endif; ?>

            <ul class="stride-ld-editions__list">
                <?php foreach ($editions as $edition):
                    $editionId = $edition['id'] ?? ($edition->ID ?? 0);
                    $editionTitle = $edition['title'] ?? get_the_title($editionId);
                    $startDate = $edition['start_date'] ?? '';
                    $endDate = $edition['end_date'] ?? '';
                    $location = $edition['location'] ?? '';
                    $spotsLeft = $edition['spots_left'] ?? null;

                    // Get sessions for this edition
                    $sessions = [];
                    if ($sessionService) {
                        try {
                            $sessions = $sessionService->getSessionsForEdition($editionId);
                        } catch (\Exception $e) {
                            // Ignore
                        }
                    }
                ?>
                    <li class="stride-ld-editions__item">
                        <div class="stride-ld-editions__info">
                            <span class="stride-ld-editions__title"><?php echo esc_html($editionTitle); ?></span>
                            <span class="stride-ld-editions__meta">
                                <?php if ($startDate): ?>
                                    <span>
                                        <span uk-icon="icon: calendar; ratio: 0.7"></span>
                                        <?php
                                        echo esc_html(date_i18n('j F Y', strtotime($startDate)));
                                        if ($endDate && $endDate !== $startDate) {
                                            echo ' - ' . esc_html(date_i18n('j F Y', strtotime($endDate)));
                                        }
                                        ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($location): ?>
                                    <span>
                                        <span uk-icon="icon: location; ratio: 0.7"></span>
                                        <?php echo esc_html($location); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($sessions)): ?>
                                    <span>
                                        <span uk-icon="icon: clock; ratio: 0.7"></span>
                                        <?php printf(_n('%d sessie', '%d sessies', count($sessions), 'stride'), count($sessions)); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($spotsLeft !== null && $spotsLeft > 0 && $spotsLeft <= 5): ?>
                                    <span class="stride-ld-editions__spots-warning">
                                        <span uk-icon="icon: warning; ratio: 0.7"></span>
                                        <?php printf(_n('Nog %d plaats', 'Nog %d plaatsen', $spotsLeft, 'stride'), $spotsLeft); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="stride-ld-editions__action">
                            <a href="<?php echo esc_url(get_permalink($editionId)); ?>" class="uk-button uk-button-primary uk-button-small">
                                <?php esc_html_e('Bekijk', 'stride'); ?>
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Check if current page is a LearnDash page.
     */
    private function isLearnDashPage(): bool
    {
        if (!function_exists('learndash_get_post_types')) {
            return false;
        }

        $postType = get_post_type();
        $ldPostTypes = learndash_get_post_types();

        return in_array($postType, $ldPostTypes, true);
    }
}
