<?php
/**
 * Description Field Template — static text, no input
 *
 * @var array $field Field definition with key: label (used as content)
 */
$field = $args['field'] ?? $field ?? null;
if (empty($field)) {
    return;
}

$content = $field['label'] ?? '';
if (empty($content)) {
    return;
}
?>
<div class="stride-dynamic-field">
    <p class="text-sm text-text-muted leading-relaxed"><?= esc_html($content) ?></p>
</div>
