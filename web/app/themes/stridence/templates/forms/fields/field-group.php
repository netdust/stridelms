<?php
/**
 * Field Group Partial
 *
 * Renders a titled section of dynamic enrollment fields.
 *
 * @var array $args {
 *     @type array $group {
 *         @type string $label  Group heading
 *         @type string $step   'personal' or 'billing'
 *         @type array  $fields Array of field definitions
 *     }
 * }
 */

$group = $args['group'] ?? [];
$fields = $group['fields'] ?? [];

if (empty($fields)) {
    return;
}
?>
<div class="space-y-4 pt-4 border-t border-border">
    <?php if (!empty($group['label'])) : ?>
        <h3 class="text-sm font-medium text-text-muted uppercase tracking-wider">
            <?= esc_html($group['label']) ?>
        </h3>
    <?php endif; ?>

    <?php foreach ($fields as $field) : ?>
        <?php
        stridence_template_part('templates/forms/fields/dynamic-field', null, [
            'field' => $field,
        ]);
        ?>
    <?php endforeach; ?>
</div>
