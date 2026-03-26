<?php
/**
 * Session Row Partial
 *
 * Type-aware rendering of a single session:
 * - in_person/webinar: Date block + title + time + location, collapsible description
 * - online/assignment: Lesson icon + lesson name + availability date
 *
 * @param array $args {
 *     @type object $session    Session object (cast from array)
 *     @type string $attendance Attendance status: 'present', 'absent', 'pending', or null
 * }
 */

defined('ABSPATH') || exit;

use Stride\Domain\SessionType;

$session    = $args['session'] ?? null;
$attendance = $args['attendance'] ?? null;
$selected   = $args['selected'] ?? false;
$not_chosen = $args['not_chosen'] ?? false;

if (!$session) {
    return;
}

// Session data
$date       = $session->date ?? '';
$start_time = $session->start_time ?? '';
$end_time   = $session->end_time ?? '';
$location   = $session->location ?? '';
$title      = $session->title ?? '';
$description = $session->description ?? '';
$lesson_ids = $session->lesson_ids ?? [];
$optional   = $session->optional ?? false;
$type_value = $session->type ?? 'in_person';
$type       = SessionType::tryFrom($type_value) ?? SessionType::InPerson;

// Format date parts
$day   = $date ? stride_format_date($date, 'd') : '';
$month = $date ? strtoupper(substr(stride_format_date($date, 'F'), 0, 3)) : '';
$formatted_date = $date ? stride_format_date($date) : '';

// Resolve lesson name for online/assignment sessions
$lesson_name = '';
if (!empty($lesson_ids) && $type->trackedByLMS()) {
    $lesson_id = is_array($lesson_ids) ? (int) $lesson_ids[0] : (int) $lesson_ids;
    $lesson = $lesson_id ? get_post($lesson_id) : null;
    $lesson_name = $lesson ? $lesson->post_title : $title;
}

// Attendance configuration
$attendance_config = [
    'present' => ['icon' => 'check-circle', 'class' => 'text-success',    'label' => 'Aanwezig'],
    'absent'  => ['icon' => 'x-circle',     'class' => 'text-error',      'label' => 'Afwezig'],
    'pending' => ['icon' => 'clock',        'class' => 'text-text-muted', 'label' => 'Nog niet geregistreerd'],
];
$att = $attendance ? ($attendance_config[$attendance] ?? null) : null;

// Has collapsible content?
$has_details = !empty($description) || $optional;
$row_id = 'session-' . ($session->id ?? wp_unique_id());

// Type-specific icon and badge
$type_config = match ($type) {
    SessionType::InPerson  => ['icon' => 'map-pin',   'badge' => 'Fysiek',      'badge_class' => 'bg-blue-100 text-blue-700'],
    SessionType::Webinar   => ['icon' => 'wifi',       'badge' => 'Webinar',     'badge_class' => 'bg-purple-100 text-purple-700'],
    SessionType::Online    => ['icon' => 'book-open',  'badge' => 'Online',      'badge_class' => 'bg-status-success-subtle text-status-success'],
    SessionType::Assignment => ['icon' => 'file-text', 'badge' => 'Opdracht',    'badge_class' => 'bg-status-warning-subtle text-status-warning'],
};

?>

<?php if ($type->trackedByLMS()) : ?>
    <!-- Online / Assignment session -->
    <div class="flex items-center gap-4 py-4" style="padding-left:5px;padding-right:20px">
        <!-- Icon -->
        <div class="flex items-center justify-center rounded-lg bg-surface-alt shrink-0" style="width:60px;height:60px">
            <?php echo stridence_icon($type_config['icon'], 'w-6 h-6 text-text-muted'); ?>
        </div>
        <!-- Content -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
                <span class="font-medium text-text truncate">
                    <?php echo esc_html($lesson_name ?: $title ?: $type->label()); ?>
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo esc_attr($type_config['badge_class']); ?> shrink-0">
                    <?php echo esc_html($type_config['badge']); ?>
                </span>
            </div>
            <?php if ($formatted_date) : ?>
                <div class="text-sm text-text-muted flex items-center gap-1">
                    <?php echo stridence_icon('calendar', 'w-3 h-3'); ?>
                    Beschikbaar vanaf <?php echo esc_html($formatted_date); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($selected) : ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary shrink-0">
                <?php echo stridence_icon('check', 'w-3 h-3'); ?>
                <?php esc_html_e('Gekozen', 'stridence'); ?>
            </span>
        <?php elseif ($att) : ?>
            <div class="flex items-center gap-1 <?php echo esc_attr($att['class']); ?> shrink-0" title="<?php echo esc_attr($att['label']); ?>">
                <?php echo stridence_icon($att['icon'], 'w-5 h-5'); ?>
            </div>
        <?php endif; ?>
    </div>

<?php else : ?>
    <!-- In-person / Webinar session -->
    <div class="py-4" style="padding-left:5px;padding-right:20px" <?php if ($has_details) : ?>x-data="{ open: false }"<?php endif; ?>>
        <div class="flex items-center gap-4 <?php echo $has_details ? 'cursor-pointer' : ''; ?>"
             <?php if ($has_details) : ?>@click="open = !open"<?php endif; ?>>
            <!-- Date block -->
            <div class="text-center shrink-0" style="width:60px">
                <div class="text-lg font-semibold text-text"><?php echo esc_html($day); ?></div>
                <div class="text-xs text-text-muted uppercase"><?php echo esc_html($month); ?></div>
            </div>
            <!-- Content -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <?php if ($title) : ?>
                        <span class="font-medium text-text truncate"><?php echo esc_html($title); ?></span>
                    <?php endif; ?>
                    <?php if ($type === SessionType::Webinar) : ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo esc_attr($type_config['badge_class']); ?> shrink-0">
                            <?php echo esc_html($type_config['badge']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-text-muted">
                    <?php if ($start_time && $end_time) : ?>
                        <span class="flex items-center gap-1">
                            <?php echo stridence_icon('clock', 'w-3 h-3'); ?>
                            <?php echo esc_html($start_time); ?> - <?php echo esc_html($end_time); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($location) : ?>
                        <span class="flex items-center gap-1">
                            <?php echo stridence_icon('map-pin', 'w-3 h-3'); ?>
                            <?php echo esc_html($location); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Right side: selected badge, attendance, or expand icon -->
            <?php if ($selected) : ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary shrink-0">
                    <?php echo stridence_icon('check', 'w-3 h-3'); ?>
                    <?php esc_html_e('Gekozen', 'stridence'); ?>
                </span>
            <?php elseif ($att) : ?>
                <div class="flex items-center gap-1 <?php echo esc_attr($att['class']); ?> shrink-0" title="<?php echo esc_attr($att['label']); ?>">
                    <?php echo stridence_icon($att['icon'], 'w-5 h-5'); ?>
                </div>
            <?php elseif ($has_details) : ?>
                <div class="shrink-0 text-text-muted transition-transform" :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($has_details) : ?>
            <!-- Collapsible details -->
            <div x-show="open" x-collapse x-cloak class="mt-3" style="margin-left:76px">
                <?php if ($description) : ?>
                    <p class="text-sm text-text-muted"><?php echo nl2br(esc_html($description)); ?></p>
                <?php endif; ?>
                <?php if ($optional) : ?>
                    <p class="text-xs text-text-muted mt-2 flex items-center gap-1">
                        <?php echo stridence_icon('info', 'w-3 h-3'); ?>
                        Deze sessie is optioneel
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
