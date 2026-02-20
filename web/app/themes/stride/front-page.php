<?php
/**
 * Front Page Template - Landing Page
 *
 * Combined homepage + course catalog inspired by CarEER design.
 * Uses Stride design system with UIkit 3.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Domain\SessionType;

// Services
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);

// Get upcoming editions for the course grid (limit 8 for homepage)
$editions = $editionService->getUpcomingEditions(8);

// Current filter (from query string)
$currentFilter = sanitize_text_field($_GET['type'] ?? 'all');

// Dutch month names
$dutchMonths = [
    1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
    7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec'
];

/**
 * Determine edition type based on sessions.
 */
function stride_landing_get_edition_type(SessionService $sessionService, int $editionId): string
{
    $sessions = $sessionService->getSessionsForEdition($editionId);
    foreach ($sessions as $session) {
        $type = $session['type'] ?? 'online';
        if (in_array($type, [SessionType::InPerson->value, SessionType::Webinar->value], true)) {
            return 'classroom';
        }
    }
    return 'online';
}

/**
 * Format date for display.
 */
function stride_landing_format_date(string $dateString, array $dutchMonths): string
{
    if (empty($dateString)) return '';
    $timestamp = strtotime($dateString);
    if (!$timestamp) return '';
    return date('j', $timestamp) . ' ' . ($dutchMonths[(int)date('n', $timestamp)] ?? '') . ' ' . date('Y', $timestamp);
}

// Enrich editions with computed data
$enrichedEditions = [];
foreach ($editions as $edition) {
    $editionId = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
    $meta = $edition['meta'] ?? [];
    if (!$editionId) continue;

    $courseId = $editionService->getCourseId($editionId);
    $course = $courseId ? get_post($courseId) : null;
    $courseTitle = $course ? $course->post_title : ($meta['_ntdst_post_title'] ?? $edition['title'] ?? __('Onbekende cursus', 'stride'));
    $thumbnail = $courseId ? get_the_post_thumbnail_url($courseId, 'stride_course_card') : null;
    $type = stride_landing_get_edition_type($sessionService, $editionId);
    $startDate = $meta['_ntdst_start_date'] ?? '';
    $price = $editionService->getPrice($editionId, true);
    $dayCount = $sessionService->getDayCount($editionId);
    $hasSpots = $editionService->hasAvailableSpots($editionId);
    $canEnroll = $editionService->canEnroll($editionId);

    $enrichedEditions[] = [
        'id' => $editionId,
        'url' => get_permalink($editionId),
        'title' => $courseTitle,
        'thumbnail' => $thumbnail,
        'type' => $type,
        'start_date_formatted' => stride_landing_format_date($startDate, $dutchMonths),
        'price' => $price,
        'day_count' => $dayCount,
        'has_spots' => $hasSpots,
        'can_enroll' => $canEnroll,
    ];
}

// Filter editions by type if requested
$filteredEditions = $enrichedEditions;
if ($currentFilter !== 'all') {
    $filteredEditions = array_filter($enrichedEditions, fn($e) => $e['type'] === $currentFilter);
}

// Stats for hero
$totalEditions = count($editionService->getUpcomingEditions(100));
$classroomCount = 0;
$onlineCount = 0;
foreach ($editionService->getUpcomingEditions(100) as $e) {
    $eId = (int) ($e['id'] ?? $e['ID'] ?? 0);
    if ($eId && stride_landing_get_edition_type($sessionService, $eId) === 'classroom') {
        $classroomCount++;
    } else {
        $onlineCount++;
    }
}

get_header();
?>

<!-- HERO SECTION -->
<section class="stride-hero">
    <div class="uk-container">
        <div class="stride-hero__inner">
            <div class="stride-hero__content">
                <span class="stride-hero__tagline">
                    <span uk-icon="icon: star; ratio: 0.8"></span>
                    <?php echo esc_html(get_bloginfo('name') . ' ' . __('Opleidingen', 'stride')); ?>
                </span>

                <h1 class="stride-hero__title">
                    <?php esc_html_e('Blijf groeien', 'stride'); ?>
                    <span class="stride-hero__title-accent"><?php esc_html_e('in je vak', 'stride'); ?></span>
                </h1>

                <p class="stride-hero__description">
                    <?php esc_html_e('Praktijkgerichte opleidingen ontwikkeld door experts uit het werkveld. Klassikaal of online, altijd toepasbaar in je dagelijkse praktijk.', 'stride'); ?>
                </p>

                <div class="stride-hero__actions">
                    <a href="#cursussen" class="uk-button uk-button-primary">
                        <?php esc_html_e('Bekijk cursussen', 'stride'); ?>
                        <span uk-icon="icon: arrow-down; ratio: 0.9"></span>
                    </a>
                    <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="uk-button uk-button-default">
                        <?php esc_html_e('Leertrajecten', 'stride'); ?>
                    </a>
                </div>

                <!-- Stats -->
                <div class="stride-hero__stats">
                    <div class="stride-hero__stat">
                        <div class="stride-hero__stat-value stride-hero__stat-value--primary">500+</div>
                        <div class="stride-hero__stat-label"><?php esc_html_e('Professionals', 'stride'); ?></div>
                    </div>
                    <div class="stride-hero__stat">
                        <div class="stride-hero__stat-value"><?php echo esc_html($totalEditions); ?>+</div>
                        <div class="stride-hero__stat-label"><?php esc_html_e('Cursussen', 'stride'); ?></div>
                    </div>
                    <div class="stride-hero__stat">
                        <div class="stride-hero__stat-value">25+</div>
                        <div class="stride-hero__stat-label"><?php esc_html_e('Jaar expertise', 'stride'); ?></div>
                    </div>
                </div>
            </div>

            <div class="stride-hero__visual">
                <div class="stride-hero__image">
                    <img src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/hero-training.jpg'); ?>"
                         alt="<?php esc_attr_e('Professionals in training', 'stride'); ?>"
                         width="600" height="400"
                         onerror="this.style.display='none'; this.parentNode.classList.add('stride-course-placeholder', 'stride-course-placeholder--orange');">
                </div>
                <!-- Floating category pills -->
                <div class="stride-hero__categories">
                    <span class="stride-hero__category-pill">
                        <span uk-icon="icon: users; ratio: 0.8"></span>
                        <?php esc_html_e('Klassikaal', 'stride'); ?>
                    </span>
                    <span class="stride-hero__category-pill">
                        <span uk-icon="icon: laptop; ratio: 0.8"></span>
                        <?php esc_html_e('E-learning', 'stride'); ?>
                    </span>
                    <span class="stride-hero__category-pill">
                        <span uk-icon="icon: play-circle; ratio: 0.8"></span>
                        <?php esc_html_e('Webinar', 'stride'); ?>
                    </span>
                    <span class="stride-hero__category-pill">
                        <span uk-icon="icon: file-text; ratio: 0.8"></span>
                        <?php esc_html_e('Certificaat', 'stride'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- COURSE CATALOG SECTION -->
<section id="cursussen" class="stride-section">
    <div class="uk-container">
        <div class="stride-section__header">
            <span class="stride-section__eyebrow"><?php esc_html_e('Cursusaanbod', 'stride'); ?></span>
            <h2 class="stride-section__title"><?php esc_html_e('Ontdek onze cursussen', 'stride'); ?></h2>
            <p class="stride-section__description">
                <?php esc_html_e('Kies uit ons brede aanbod van praktijkgerichte trainingen voor professionals in de verslavingszorg.', 'stride'); ?>
            </p>
        </div>

        <!-- Filter Pills -->
        <div class="stride-course-filters">
            <a href="<?php echo esc_url(remove_query_arg('type')); ?>"
               class="stride-course-filter <?php echo $currentFilter === 'all' ? 'stride-course-filter--active' : ''; ?>">
                <?php esc_html_e('Alle', 'stride'); ?>
                <span>(<?php echo count($enrichedEditions); ?>)</span>
            </a>
            <a href="<?php echo esc_url(add_query_arg('type', 'classroom')); ?>"
               class="stride-course-filter <?php echo $currentFilter === 'classroom' ? 'stride-course-filter--active' : ''; ?>">
                <span uk-icon="icon: users; ratio: 0.8"></span>
                <?php esc_html_e('Klassikaal', 'stride'); ?>
                <span>(<?php echo count(array_filter($enrichedEditions, fn($e) => $e['type'] === 'classroom')); ?>)</span>
            </a>
            <a href="<?php echo esc_url(add_query_arg('type', 'online')); ?>"
               class="stride-course-filter <?php echo $currentFilter === 'online' ? 'stride-course-filter--active' : ''; ?>">
                <span uk-icon="icon: laptop; ratio: 0.8"></span>
                <?php esc_html_e('Online', 'stride'); ?>
                <span>(<?php echo count(array_filter($enrichedEditions, fn($e) => $e['type'] === 'online')); ?>)</span>
            </a>
        </div>

        <!-- Course Grid -->
        <?php if (!empty($filteredEditions)) : ?>
            <div class="stride-course-grid">
                <?php foreach ($filteredEditions as $edition) : ?>
                    <div class="stride-course-card <?php echo !$edition['can_enroll'] ? 'stride-course-card--sold-out' : ''; ?>">
                        <!-- Card Image -->
                        <div class="stride-course-card__image">
                            <?php if ($edition['thumbnail']) : ?>
                                <img src="<?php echo esc_url($edition['thumbnail']); ?>"
                                     alt="<?php echo esc_attr($edition['title']); ?>"
                                     loading="lazy">
                            <?php else : ?>
                                <div class="stride-course-placeholder stride-course-placeholder--<?php echo $edition['type'] === 'online' ? 'blue' : 'orange'; ?>">
                                    <span uk-icon="icon: <?php echo $edition['type'] === 'online' ? 'laptop' : 'users'; ?>; ratio: 2"
                                          class="stride-course-placeholder__icon"></span>
                                </div>
                            <?php endif; ?>

                            <!-- Type Badge -->
                            <span class="stride-course-card__badge uk-label <?php echo $edition['type'] === 'online' ? 'stride-label-soft-secondary' : 'stride-label-soft-primary'; ?>">
                                <?php if ($edition['type'] === 'online') : ?>
                                    <span uk-icon="icon: laptop; ratio: 0.7"></span>
                                    <?php esc_html_e('Online', 'stride'); ?>
                                <?php else : ?>
                                    <span uk-icon="icon: users; ratio: 0.7"></span>
                                    <?php esc_html_e('Klassikaal', 'stride'); ?>
                                <?php endif; ?>
                            </span>

                            <?php if (!$edition['has_spots']) : ?>
                                <div class="stride-course-card__overlay">
                                    <span class="uk-label uk-label-danger"><?php esc_html_e('Volzet', 'stride'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body -->
                        <div class="stride-course-card__body">
                            <h3 class="stride-course-card__title">
                                <a href="<?php echo esc_url($edition['url']); ?>">
                                    <?php echo esc_html($edition['title']); ?>
                                </a>
                            </h3>

                            <div class="stride-course-card__meta">
                                <?php if ($edition['start_date_formatted']) : ?>
                                    <span class="stride-course-card__meta-item">
                                        <span uk-icon="icon: calendar; ratio: 0.8"></span>
                                        <?php echo esc_html($edition['start_date_formatted']); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($edition['day_count'] > 0) : ?>
                                    <span class="stride-course-card__meta-item">
                                        <span uk-icon="icon: clock; ratio: 0.8"></span>
                                        <?php printf(esc_html(_n('%d dag', '%d dagen', $edition['day_count'], 'stride')), $edition['day_count']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="stride-course-card__footer">
                            <div class="stride-course-card__price-wrap">
                                <?php if ($edition['price']->isZero()) : ?>
                                    <span class="stride-course-card__price stride-course-card__price--free">
                                        <?php esc_html_e('Gratis', 'stride'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="stride-course-card__price">
                                        <?php echo esc_html($edition['price']->format()); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Action -->
                        <div class="stride-course-card__action">
                            <a href="<?php echo esc_url($edition['url']); ?>" class="uk-button uk-button-primary uk-width-1-1">
                                <?php esc_html_e('Bekijk cursus', 'stride'); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- View All Button -->
            <div class="stride-course-grid__footer">
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-default uk-button-large">
                    <?php esc_html_e('Bekijk alle cursussen', 'stride'); ?>
                    <span uk-icon="icon: arrow-right; ratio: 0.9"></span>
                </a>
            </div>
        <?php else : ?>
            <div class="stride-empty-state">
                <div class="stride-empty-state__icon">
                    <span uk-icon="icon: search; ratio: 2"></span>
                </div>
                <h2 class="stride-empty-state__title"><?php esc_html_e('Geen cursussen gevonden', 'stride'); ?></h2>
                <p class="stride-empty-state__description">
                    <?php esc_html_e('Er zijn momenteel geen cursussen gepland. Kom later terug voor ons aanbod.', 'stride'); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- HOW IT WORKS SECTION -->
<section class="stride-section stride-section--muted">
    <div class="uk-container">
        <div class="stride-section__header">
            <span class="stride-section__eyebrow"><?php esc_html_e('Hoe werkt het', 'stride'); ?></span>
            <h2 class="stride-section__title"><?php esc_html_e('In 3 stappen aan de slag', 'stride'); ?></h2>
            <p class="stride-section__description">
                <?php esc_html_e('Van inschrijving tot certificaat - wij maken leren eenvoudig.', 'stride'); ?>
            </p>
        </div>

        <div class="stride-steps">
            <div class="stride-step">
                <span class="stride-step__number">1</span>
                <div class="stride-step__icon">
                    <span uk-icon="icon: search; ratio: 1.5"></span>
                </div>
                <h3 class="stride-step__title"><?php esc_html_e('Kies een cursus', 'stride'); ?></h3>
                <p class="stride-step__description">
                    <?php esc_html_e('Blader door ons aanbod en kies de cursus die past bij jouw ontwikkeldoelen.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-step">
                <span class="stride-step__number">2</span>
                <div class="stride-step__icon">
                    <span uk-icon="icon: play-circle; ratio: 1.5"></span>
                </div>
                <h3 class="stride-step__title"><?php esc_html_e('Leer in eigen tempo', 'stride'); ?></h3>
                <p class="stride-step__description">
                    <?php esc_html_e('Volg de lessen online of klassikaal, wanneer het jou uitkomt.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-step">
                <span class="stride-step__number">3</span>
                <div class="stride-step__icon">
                    <span uk-icon="icon: check; ratio: 1.5"></span>
                </div>
                <h3 class="stride-step__title"><?php esc_html_e('Ontvang certificaat', 'stride'); ?></h3>
                <p class="stride-step__description">
                    <?php esc_html_e('Rond de cursus af en ontvang een erkend VAD-certificaat.', 'stride'); ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- FEATURES SECTION -->
<section class="stride-section">
    <div class="uk-container">
        <div class="stride-section__header">
            <span class="stride-section__eyebrow"><?php esc_html_e('Waarom Stride', 'stride'); ?></span>
            <h2 class="stride-section__title"><?php esc_html_e('Waarom kiezen voor onze trainingen?', 'stride'); ?></h2>
            <p class="stride-section__description">
                <?php esc_html_e('Wij bieden hoogwaardige, praktijkgerichte trainingen ontwikkeld door experts in de verslavingszorg.', 'stride'); ?>
            </p>
        </div>

        <div class="stride-features">
            <div class="stride-feature">
                <div class="stride-feature__icon">
                    <span uk-icon="icon: video-camera; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Online & Klassikaal', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Kies de leervorm die bij jou past: thuis achter je laptop of live in de groep.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon stride-feature__icon--success">
                    <span uk-icon="icon: users; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Ervaren docenten', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Al onze trainers zijn professionals met jarenlange praktijkervaring.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon stride-feature__icon--secondary">
                    <span uk-icon="icon: clock; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Flexibel leren', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Leer wanneer het jou uitkomt, met toegang tot materialen op elk moment.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon stride-feature__icon--accent">
                    <span uk-icon="icon: file-text; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Erkende certificaten', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Ontvang na afloop een VAD-erkend certificaat voor je portfolio.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon">
                    <span uk-icon="icon: lifesaver; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Ondersteuning', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Heb je vragen? Ons team staat klaar om je te helpen.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon stride-feature__icon--success">
                    <span uk-icon="icon: bolt; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Direct starten', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Na inschrijving krijg je direct toegang tot de leeromgeving.', 'stride'); ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- TESTIMONIALS SECTION -->
<section class="stride-section stride-section--muted">
    <div class="uk-container">
        <div class="stride-section__header">
            <span class="stride-section__eyebrow"><?php esc_html_e('Ervaringen', 'stride'); ?></span>
            <h2 class="stride-section__title"><?php esc_html_e('Wat deelnemers zeggen', 'stride'); ?></h2>
            <p class="stride-section__description">
                <?php esc_html_e('Ontdek waarom honderden professionals kiezen voor onze trainingen.', 'stride'); ?>
            </p>
        </div>

        <div class="stride-testimonials">
            <div class="stride-testimonial">
                <div class="stride-testimonial__rating">
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                </div>
                <p class="stride-testimonial__content">
                    "<?php esc_html_e('De cursus Motiverende Gespreksvoering was precies wat ik zocht. Praktijkgericht en direct toepasbaar in mijn werk.', 'stride'); ?>"
                </p>
                <div class="stride-testimonial__author">
                    <div class="stride-testimonial__avatar">JV</div>
                    <div class="stride-testimonial__info">
                        <p class="stride-testimonial__name">Jan Vermeer</p>
                        <p class="stride-testimonial__role"><?php esc_html_e('Ambulant begeleider', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <div class="stride-testimonial">
                <div class="stride-testimonial__rating">
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                </div>
                <p class="stride-testimonial__content">
                    "<?php esc_html_e('Fijn dat ik de online cursus in mijn eigen tempo kon volgen. De docent was heel toegankelijk voor vragen.', 'stride'); ?>"
                </p>
                <div class="stride-testimonial__author">
                    <div class="stride-testimonial__avatar">MB</div>
                    <div class="stride-testimonial__info">
                        <p class="stride-testimonial__name">Maria Bakker</p>
                        <p class="stride-testimonial__role"><?php esc_html_e('Maatschappelijk werker', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <div class="stride-testimonial">
                <div class="stride-testimonial__rating">
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                    <span uk-icon="icon: star; ratio: 0.9"></span>
                </div>
                <p class="stride-testimonial__content">
                    "<?php esc_html_e('De klassikale training was intensief maar zeer waardevol. De uitwisseling met collega\'s uit andere organisaties was verrijkend.', 'stride'); ?>"
                </p>
                <div class="stride-testimonial__author">
                    <div class="stride-testimonial__avatar">PS</div>
                    <div class="stride-testimonial__info">
                        <p class="stride-testimonial__name">Peter Smits</p>
                        <p class="stride-testimonial__role"><?php esc_html_e('Teamleider verslavingszorg', 'stride'); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA SECTION -->
<section class="stride-section stride-section--primary">
    <div class="uk-container">
        <div class="stride-cta">
            <h2 class="stride-cta__title"><?php esc_html_e('Klaar om te starten?', 'stride'); ?></h2>
            <p class="stride-cta__description">
                <?php esc_html_e('Schrijf je vandaag nog in en zet de volgende stap in je professionele ontwikkeling.', 'stride'); ?>
            </p>
            <a href="#cursussen" class="uk-button stride-cta__button">
                <?php esc_html_e('Bekijk cursusaanbod', 'stride'); ?>
                <span uk-icon="icon: arrow-up; ratio: 0.9"></span>
            </a>
        </div>
    </div>
</section>

<!-- NEWSLETTER SECTION (Optional) -->
<section class="stride-section">
    <div class="uk-container uk-container-small">
        <div class="stride-newsletter">
            <h3 class="stride-newsletter__title"><?php esc_html_e('Blijf op de hoogte', 'stride'); ?></h3>
            <p class="stride-newsletter__description">
                <?php esc_html_e('Ontvang updates over nieuwe cursussen en opleidingsmogelijkheden.', 'stride'); ?>
            </p>
            <form class="stride-newsletter__form" action="#" method="post">
                <input type="email"
                       class="uk-input stride-newsletter__input"
                       placeholder="<?php esc_attr_e('Je e-mailadres', 'stride'); ?>"
                       required>
                <button type="submit" class="uk-button uk-button-primary">
                    <?php esc_html_e('Aanmelden', 'stride'); ?>
                </button>
            </form>
        </div>
    </div>
</section>

<?php get_footer(); ?>
