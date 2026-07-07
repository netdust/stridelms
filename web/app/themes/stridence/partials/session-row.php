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
// Call sites (single-vad_edition.php) still pass 'not_chosen' — intentionally unrendered pending Phase 5.

if (!$session) {
    return;
}

// Seat availability (display-only; the server gate in SessionService is
// authoritative). The call site passes 'seat_state' precomputed via
// stridence_session_seat_state() so the own-seat exemption uses the current
// user's picks. Absent (attendance / read-only contexts) → no badge.
$seat_state = $args['seat_state'] ?? null;
$seat_badge = is_array($seat_state) ? stridence_session_seat_badge($seat_state) : '';

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
    SessionType::Online    => ['icon' => 'book-open',  'badge' => 'Online',      'badge_class' => 'bg-badge-online-bg text-badge-online-text'],
    SessionType::Assignment => ['icon' => 'file-text', 'badge' => 'Opdracht',    'badge_class' => 'bg-badge-few-bg text-badge-few-text'],
};

?>

<?php
// Past-session variant: no 'past' arg exists in the args contract — call sites do not pass it.
// The row styling therefore never dims. Noted per plan spec: "if no such arg exists, don't invent one".
?>

<?php if ($type->trackedByLMS()) : ?>
    <!-- Online / Assignment session -->
    <div class="bg-surface-card rounded-[12px] shadow-card border border-border-soft flex items-center gap-4 px-[18px] py-[14px]">
        <!-- Icon block (square, badge-online colours) -->
        <div class="w-[50px] h-[50px] rounded-[11px] bg-badge-online-bg text-badge-online-text flex flex-col items-center justify-center shrink-0">
            <?php echo stridence_icon($type_config['icon'], 'w-5 h-5'); ?>
        </div>
        <!-- Content -->
        <div class="flex-1 min-w-0">
            <div class="text-[14px] font-bold text-text truncate mb-0.5">
                <?php echo esc_html($lesson_name ?: $title ?: $type->label()); ?>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo esc_attr($type_config['badge_class']); ?> shrink-0">
                    <?php echo esc_html($type_config['badge']); ?>
                </span>
                <?php if ($formatted_date) : ?>
                    <span class="text-[13px] text-text-muted">
                        <?php /* translators: %s = Dutch formatted date */ ?>
                        <?php printf(esc_html__('Beschikbaar vanaf %s', 'stridence'), esc_html($formatted_date)); ?>
                    </span>
                <?php endif; ?>
                <?php if ($seat_badge !== '') : ?>
                    <?php echo $seat_badge; // already-escaped seat availability badge ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- Right: selected or attendance -->
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
    <div class="bg-surface-card rounded-[12px] shadow-card border border-border-soft px-[18px] py-[14px]"
         <?php if ($has_details) : ?>x-data="{ open: false }"<?php endif; ?>>
        <div class="flex items-center gap-4 <?php echo $has_details ? 'cursor-pointer' : ''; ?>"
             <?php if ($has_details) : ?>@click="open = !open"<?php endif; ?>>
            <!-- Date block: 50px square, badge-online colours -->
            <div class="w-[50px] h-[50px] rounded-[11px] bg-badge-online-bg text-badge-online-text flex flex-col items-center justify-center shrink-0">
                <span class="text-[17px] font-extrabold leading-none"><?php echo esc_html($day); ?></span>
                <span class="text-[10px] font-bold uppercase tracking-wide"><?php echo esc_html($month); ?></span>
            </div>
            <!-- Middle: title + sub line -->
            <div class="flex-1 min-w-0">
                <?php if ($title) : ?>
                    <div class="text-[14px] font-bold text-text truncate mb-0.5"><?php echo esc_html($title); ?></div>
                <?php endif; ?>
                <?php // Date is shown in the day/month block — no redundant formatted-date sub-line (review I-2).?>
                <?php if ($type === SessionType::Webinar) : ?>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo esc_attr($type_config['badge_class']); ?> shrink-0">
                            <?php echo esc_html($type_config['badge']); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Right: time + location -->
            <div class="text-right shrink-0">
                <?php if ($start_time && $end_time) : ?>
                    <div class="text-[13px] font-semibold tabular-nums"><?php echo esc_html($start_time); ?> – <?php echo esc_html($end_time); ?></div>
                <?php endif; ?>
                <?php if ($location) : ?>
                    <div class="text-[12px] text-text-faint"><?php echo esc_html($location); ?></div>
                <?php endif; ?>
                <?php if ($seat_badge !== '') : ?>
                    <div class="mt-1"><?php echo $seat_badge; // already-escaped seat availability badge ?></div>
                <?php endif; ?>
                <?php if ($selected) : ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary mt-1">
                        <?php echo stridence_icon('check', 'w-3 h-3'); ?>
                        <?php esc_html_e('Gekozen', 'stridence'); ?>
                    </span>
                <?php elseif ($att) : ?>
                    <div class="flex items-center justify-end gap-1 <?php echo esc_attr($att['class']); ?> mt-1" title="<?php echo esc_attr($att['label']); ?>">
                        <?php echo stridence_icon($att['icon'], 'w-5 h-5'); ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($has_details) : ?>
                <!-- Expand affordance: its own column, vertically centred in the
                     header row (flex items-center) — not stacked under time/
                     location. Chevron rotates to point up when open. -->
                <div class="shrink-0 text-text-muted transition-transform duration-fast" :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($has_details) : ?>
            <!-- Collapsible details -->
            <div x-show="open" x-collapse x-cloak class="mt-3 pl-[66px]">
                <?php if ($description) : ?>
                    <?php
                    // Rich text: render the safelisted formatting the admin typed
                    // (headings, bold/italic, lists, links). Sanitized on save,
                    // re-sanitized here as defense-in-depth. `prose-session`
                    // gives the block tags basic spacing/list styling.
                    ?>
                    <div class="prose-session text-sm text-text-muted">
                        <?php echo wp_kses($description, stride_session_description_allowed_html()); ?>
                    </div>
                <?php endif; ?>
                <?php if ($optional) : ?>
                    <p class="text-xs text-text-muted mt-2 flex items-center gap-1">
                        <?php echo stridence_icon('info', 'w-3 h-3'); ?>
                        <?php esc_html_e('Deze sessie is optioneel', 'stridence'); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
