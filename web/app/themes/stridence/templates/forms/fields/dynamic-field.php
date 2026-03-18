<?php
/**
 * Dynamic Field Template
 *
 * Renders a single dynamic enrollment field based on its type.
 * Used for course-specific extra enrollment fields.
 *
 * @var array $field Field definition with keys: label, name, type, options, description, required
 */

// Support both get_template_part ($args) and direct include ($field)
$field = $args['field'] ?? $field ?? null;

if (empty($field) || !is_array($field)) {
    return;
}

$label = $field['label'] ?? '';
$name = $field['name'] ?? '';
$type = $field['type'] ?? 'text';
$options = $field['options'] ?? '';
$description = $field['description'] ?? '';
$required = !empty($field['required']);

if (empty($name)) {
    return;
}

// Parse options for select fields
$selectOptions = [];
if ($type === 'select' && !empty($options)) {
    $selectOptions = array_map('trim', explode(',', $options));
}

$inputId = 'extra_field_' . esc_attr($name);
$modelBinding = "form.extra_fields['{$name}']";
?>

<div class="stride-dynamic-field">
    <?php if ($type === 'checkbox') : ?>
        <!-- Checkbox field -->
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox"
                   id="<?= esc_attr($inputId) ?>"
                   x-model="<?= esc_attr($modelBinding) ?>"
                   class="input-checkbox mt-0.5"
                   <?= $required ? 'required' : '' ?>>
            <span class="text-sm">
                <?= esc_html($label) ?>
                <?php if ($required) : ?><span class="text-error">*</span><?php endif; ?>
                <?php if (!empty($description)) : ?>
                    <span class="block text-text-muted text-xs mt-1"><?= esc_html($description) ?></span>
                <?php endif; ?>
            </span>
        </label>
    <?php else : ?>
        <!-- Label for other field types -->
        <label class="input-label" for="<?= esc_attr($inputId) ?>">
            <?= esc_html($label) ?>
            <?php if ($required) : ?><span class="text-error">*</span><?php endif; ?>
        </label>

        <?php if ($type === 'textarea') : ?>
            <!-- Textarea field -->
            <textarea id="<?= esc_attr($inputId) ?>"
                      x-model="<?= esc_attr($modelBinding) ?>"
                      class="input-text"
                      rows="3"
                      <?= $required ? 'required' : '' ?>></textarea>

        <?php elseif ($type === 'select') : ?>
            <!-- Select field -->
            <select id="<?= esc_attr($inputId) ?>"
                    x-model="<?= esc_attr($modelBinding) ?>"
                    class="input-text"
                    <?= $required ? 'required' : '' ?>>
                <option value=""><?= esc_html__('Selecteer...', 'stride') ?></option>
                <?php foreach ($selectOptions as $option) : ?>
                    <option value="<?= esc_attr($option) ?>"><?= esc_html($option) ?></option>
                <?php endforeach; ?>
            </select>

        <?php else : ?>
            <!-- Text field (default) -->
            <input type="text"
                   id="<?= esc_attr($inputId) ?>"
                   x-model="<?= esc_attr($modelBinding) ?>"
                   class="input-text"
                   <?= $required ? 'required' : '' ?>>
        <?php endif; ?>

        <?php if (!empty($description)) : ?>
            <p class="input-hint"><?= esc_html($description) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
