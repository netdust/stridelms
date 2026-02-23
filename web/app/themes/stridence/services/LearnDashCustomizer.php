<?php
/**
 * LearnDash Customizer for Stridence
 *
 * Adds editions/sessions tab for classroom courses and handles tab switching.
 *
 * @package stridence
 */

namespace Stridence\Services;

final class LearnDashCustomizer
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Add tab switching JavaScript
        add_action('wp_footer', [$this, 'renderTabScript'], 20);

        // Inject editions section after course content list
        add_action('learndash-course-content-list-after', [$this, 'renderEditions'], 10, 2);

        // Add editions tab to course tabs
        add_filter('learndash_content_tabs', [$this, 'addEditionsTab'], 10, 4);
    }

    /**
     * Render tab switching JavaScript.
     */
    public function renderTabScript(): void
    {
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

                tabs.forEach(function(tab) {
                    var isSelected = tab === selectedTab;
                    tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                });

                panels.forEach(function(panel) {
                    var isTarget = panel.id === targetPanelId;
                    panel.setAttribute('aria-hidden', isTarget ? 'false' : 'true');
                });

                if (accordion) {
                    var isEditionsTab = selectedTab.id === 'ld-tab-editions';
                    accordion.style.display = isEditionsTab ? 'none' : '';
                }
            }

            tabs.forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    switchTab(tab);
                });
            });

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
        if ($context !== 'course') {
            return $tabs;
        }

        $editions = $this->getEditionsForCourse($courseId);
        if (empty($editions)) {
            return $tabs;
        }

        if (!$this->isClassroomCourse($courseId)) {
            return $tabs;
        }

        $content = $this->buildEditionsTabContent($editions, $courseId);

        $tabs[] = [
            'id'        => 'editions',
            'icon'      => 'ld-icon-calendar',
            'label'     => __('Geplande sessies', 'stridence'),
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
        <div class="stridence-editions<?php echo $standalone ? '' : ' stridence-editions--tab'; ?>">
            <?php if ($standalone): ?>
            <div class="stridence-editions__header">
                <h3><?php esc_html_e('Geplande sessies', 'stridence'); ?></h3>
                <span class="stridence-editions__badge">
                    <?php printf(_n('%d editie', '%d edities', count($editions), 'stridence'), count($editions)); ?>
                </span>
            </div>
            <?php endif; ?>

            <ul class="stridence-editions__list">
                <?php foreach ($editions as $edition):
                    $editionId = $edition['id'] ?? ($edition->ID ?? 0);
                    $editionTitle = $edition['title'] ?? get_the_title($editionId);
                    $startDate = $edition['start_date'] ?? '';
                    $endDate = $edition['end_date'] ?? '';
                    $location = $edition['location'] ?? '';
                    $spotsLeft = $edition['spots_left'] ?? null;

                    $sessions = [];
                    if ($sessionService) {
                        try {
                            $sessions = $sessionService->getSessionsForEdition($editionId);
                        } catch (\Exception $e) {
                            // Ignore
                        }
                    }
                ?>
                    <li class="stridence-editions__item">
                        <div class="stridence-editions__info">
                            <span class="stridence-editions__title"><?php echo esc_html($editionTitle); ?></span>
                            <span class="stridence-editions__meta">
                                <?php if ($startDate): ?>
                                    <span class="stridence-editions__date">
                                        <?php
                                        echo esc_html(date_i18n('j F Y', strtotime($startDate)));
                                        if ($endDate && $endDate !== $startDate) {
                                            echo ' - ' . esc_html(date_i18n('j F Y', strtotime($endDate)));
                                        }
                                        ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($location): ?>
                                    <span class="stridence-editions__location"><?php echo esc_html($location); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($sessions)): ?>
                                    <span class="stridence-editions__sessions">
                                        <?php printf(_n('%d sessie', '%d sessies', count($sessions), 'stridence'), count($sessions)); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($spotsLeft !== null && $spotsLeft > 0 && $spotsLeft <= 5): ?>
                                    <span class="stridence-editions__spots-warning">
                                        <?php printf(_n('Nog %d plaats', 'Nog %d plaatsen', $spotsLeft, 'stridence'), $spotsLeft); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="stridence-editions__action">
                            <a href="<?php echo esc_url(get_permalink($editionId)); ?>" class="stridence-btn stridence-btn--primary">
                                <?php esc_html_e('Bekijk', 'stridence'); ?>
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}
