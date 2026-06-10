<?php
/**
 * Scale Field Template — numbered pill selector
 *
 * @var array $field Field definition with keys: label, name, min, max, required
 */
$field = $args['field'] ?? $field ?? null;
if (empty($field) || empty($field['name'])) {
    return;
}

$label = $field['label'] ?? '';
$name = $field['name'] ?? '';
$min = (int) ($field['min'] ?? 1);
$max = (int) ($field['max'] ?? 5);
$required = !empty($field['required']);
$modelBinding = "form.extra_fields['{$name}']";
?>
<div class="stride-dynamic-field">
    <label class="input-label">
        <?= esc_html($label) ?>
        <?php if ($required) : ?><span class="text-error">*</span><?php endif; ?>
    </label>
    <div class="flex gap-2 mt-1">
        <?php for ($i = $min; $i <= $max; $i++) : ?>
            <button type="button"
                    @click="<?= esc_attr($modelBinding) ?> = <?= $i ?>"
                    :class="<?= esc_attr($modelBinding) ?> === <?= $i ?> ? 'bg-primary text-text-inverse border-primary' : 'bg-white text-text border-border hover:border-primary'"
                    class="w-10 h-10 rounded-lg border-2 font-semibold text-sm transition-colors flex items-center justify-center">
                <?= $i ?>
            </button>
        <?php endfor; ?>
    </div>
    <?php if ($required) : ?>
        <input type="hidden" x-model="<?= esc_attr($modelBinding) ?>" required>
    <?php endif; ?>
</div>
