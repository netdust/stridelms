<?php
/**
 * Radio Field Template
 *
 * @var array $field Field definition with keys: label, name, options, required
 */
$field = $args['field'] ?? $field ?? null;
if (empty($field) || empty($field['name'])) return;

$label = $field['label'] ?? '';
$name = $field['name'] ?? '';
$options = array_map('trim', explode(',', $field['options'] ?? ''));
$required = !empty($field['required']);
$inputId = 'extra_field_' . esc_attr($name);
$modelBinding = "form.extra_fields['{$name}']";
?>
<div class="stride-dynamic-field">
    <label class="input-label">
        <?= esc_html($label) ?>
        <?php if ($required) : ?><span class="text-error">*</span><?php endif; ?>
    </label>
    <div class="flex flex-col gap-2 mt-1">
        <?php foreach ($options as $i => $option) : ?>
            <label class="flex items-center gap-2 cursor-pointer text-sm">
                <input type="radio"
                       name="<?= esc_attr($inputId) ?>"
                       value="<?= esc_attr($option) ?>"
                       x-model="<?= esc_attr($modelBinding) ?>"
                       class="input-radio"
                       <?= ($required && $i === 0) ? 'required' : '' ?>>
                <span><?= esc_html($option) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
