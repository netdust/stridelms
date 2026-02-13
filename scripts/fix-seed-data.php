<?php
/**
 * Fix seed data - assign categories, add trajectory requirements
 */

use ntdst\Stride\FieldRegistry;

echo "Fixing seed data...\n\n";

// 1. Assign "In-person" category to courses with editions (except e-learning)
echo "=== Assigning In-person category ===\n";
$inPersonTerm = get_term_by('slug', 'in-person', 'ld_course_category');
if (!$inPersonTerm) {
    $result = wp_insert_term('In-person', 'ld_course_category', ['slug' => 'in-person']);
    $inPersonTermId = is_wp_error($result) ? 0 : $result['term_id'];
    echo "Created In-person category (ID: {$inPersonTermId})\n";
} else {
    $inPersonTermId = $inPersonTerm->term_id;
    echo "Found In-person category (ID: {$inPersonTermId})\n";
}

// Get courses that are NOT e-learning (based on title)
$courses = get_posts(['post_type' => 'sfwd-courses', 'posts_per_page' => -1, 'post_status' => 'publish']);
$inPersonCourses = [];
foreach ($courses as $course) {
    if (stripos($course->post_title, 'E-learning') === false) {
        wp_set_object_terms($course->ID, [$inPersonTermId], 'ld_course_category');
        echo "  Assigned In-person to: {$course->post_title}\n";
        $inPersonCourses[] = $course->ID;
    }
}

// 2. Fix trajectory - add requirements and content
echo "\n=== Fixing Trajectories ===\n";
$trajectories = get_posts(['post_type' => 'vad_trajectory', 'posts_per_page' => -1, 'post_status' => 'publish']);

foreach ($trajectories as $trajectory) {
    echo "Processing: {$trajectory->post_title}\n";

    // Add content if empty
    if (empty($trajectory->post_content)) {
        wp_update_post([
            'ID' => $trajectory->ID,
            'post_content' => '<p>Dit leertraject biedt een complete opleiding in veilig werken. Je leert de fundamentele principes en geavanceerde technieken die nodig zijn om veilig te werken in verschillende omgevingen.</p>

<h3>Wat leer je?</h3>
<ul>
<li>Fundamentele veiligheidsconcepten en -protocollen</li>
<li>Gevorderde veiligheidstechnieken</li>
<li>Praktische toepassing in werksituaties</li>
<li>Leidinggevende vaardigheden op het gebied van veiligheid</li>
</ul>

<h3>Voor wie?</h3>
<p>Dit traject is bedoeld voor professionals die een complete opleiding willen in werkpleikveiligheid, van basis tot leidinggevend niveau.</p>',
        ]);
        echo "  Added content\n";
    }

    // Check requirements
    $requirements = get_post_meta($trajectory->ID, FieldRegistry::TRAJECTORY_COURSES, true);
    if (empty($requirements) || !is_array($requirements)) {
        // Add requirements - use the in-person courses
        $requirements = [];

        // Find specific courses by title pattern
        $basisCourse = null;
        $gevorderdCourse = null;
        $leidinggevendCourse = null;

        foreach ($courses as $course) {
            if (stripos($course->post_title, 'Basisopleiding') !== false) {
                $basisCourse = $course->ID;
            } elseif (stripos($course->post_title, 'Gevorderde') !== false) {
                $gevorderdCourse = $course->ID;
            } elseif (stripos($course->post_title, 'Leidinggevende') !== false) {
                $leidinggevendCourse = $course->ID;
            }
        }

        // Add mandatory modules
        if ($basisCourse) {
            $requirements[] = [
                'course_id' => $basisCourse,
                'group' => 'mandatory',
                'order' => 1,
            ];
            echo "  Added mandatory: Basisopleiding (ID: {$basisCourse})\n";
        }

        if ($gevorderdCourse) {
            $requirements[] = [
                'course_id' => $gevorderdCourse,
                'group' => 'mandatory',
                'order' => 2,
            ];
            echo "  Added mandatory: Gevorderde (ID: {$gevorderdCourse})\n";
        }

        // Add elective modules
        if ($leidinggevendCourse) {
            $requirements[] = [
                'course_id' => $leidinggevendCourse,
                'group' => 'elective',
                'order' => 3,
            ];
            echo "  Added elective: Leidinggevende (ID: {$leidinggevendCourse})\n";
        }

        // Find e-learning courses for electives
        foreach ($courses as $course) {
            if (stripos($course->post_title, 'E-learning') !== false && count($requirements) < 6) {
                $requirements[] = [
                    'course_id' => $course->ID,
                    'group' => 'elective',
                    'order' => count($requirements) + 1,
                ];
                echo "  Added elective: {$course->post_title} (ID: {$course->ID})\n";
            }
        }

        update_post_meta($trajectory->ID, FieldRegistry::TRAJECTORY_COURSES, $requirements);
        echo "  Saved " . count($requirements) . " requirements\n";
    }

    // Set mode if not set
    $mode = get_post_meta($trajectory->ID, FieldRegistry::TRAJECTORY_MODE, true);
    if (empty($mode)) {
        update_post_meta($trajectory->ID, FieldRegistry::TRAJECTORY_MODE, 'self_paced');
        echo "  Set mode to: self_paced\n";
    }
}

// 3. Create a second trajectory if we only have one
if (count($trajectories) < 2) {
    echo "\n=== Creating Cohort Trajectory ===\n";

    $cohortId = wp_insert_post([
        'post_type' => 'vad_trajectory',
        'post_title' => 'Veiligheidscoördinator Traject',
        'post_status' => 'publish',
        'post_content' => '<p>Word gecertificeerd veiligheidscoördinator met dit intensieve cohorttraject. Je volgt samen met een groep collega\'s een gestructureerd programma.</p>

<h3>Programma</h3>
<p>Dit cohorttraject bestaat uit verplichte modules die je samen met je groep volgt, plus keuzemodules om je specialisatie te bepalen.</p>

<h3>Voordelen cohort</h3>
<ul>
<li>Netwerken met collega\'s uit de sector</li>
<li>Gestructureerde planning</li>
<li>Begeleiding door ervaren docenten</li>
<li>Groepsdiscussies en praktijkcases</li>
</ul>',
    ]);

    if ($cohortId && !is_wp_error($cohortId)) {
        echo "Created cohort trajectory (ID: {$cohortId})\n";

        // Set mode to cohort
        update_post_meta($cohortId, FieldRegistry::TRAJECTORY_MODE, 'cohort');

        // Set deadlines
        $enrollmentDeadline = date('Y-m-d', strtotime('+30 days'));
        $choiceDeadline = date('Y-m-d', strtotime('+45 days'));
        update_post_meta($cohortId, FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE, $enrollmentDeadline);
        update_post_meta($cohortId, FieldRegistry::TRAJECTORY_CHOICE_DEADLINE, $choiceDeadline);
        echo "  Set enrollment deadline: {$enrollmentDeadline}\n";
        echo "  Set choice deadline: {$choiceDeadline}\n";

        // Add requirements
        $requirements = [];
        foreach ($courses as $course) {
            if (stripos($course->post_title, 'Basisopleiding') !== false) {
                $requirements[] = ['course_id' => $course->ID, 'group' => 'mandatory', 'order' => 1];
            } elseif (stripos($course->post_title, 'Gevorderde') !== false) {
                $requirements[] = ['course_id' => $course->ID, 'group' => 'mandatory', 'order' => 2];
            } elseif (stripos($course->post_title, 'Leidinggevende') !== false) {
                $requirements[] = ['course_id' => $course->ID, 'group' => 'mandatory', 'order' => 3];
            }
        }

        update_post_meta($cohortId, FieldRegistry::TRAJECTORY_COURSES, $requirements);
        echo "  Added " . count($requirements) . " requirements\n";
    }
}

echo "\nDone!\n";
