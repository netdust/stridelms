<?php

/**
 * Rename Dutch field group field names to English meta key equivalents.
 * organisatie → organisation, afdeling → department
 */
$groups = get_option('stride_enrollment_field_groups', []);

$renames = [
    'organisatie' => 'organisation',
    'afdeling' => 'department',
];

$changed = 0;
foreach ($groups as &$group) {
    foreach ($group['fields'] as &$field) {
        $name = $field['name'] ?? '';
        if (isset($renames[$name])) {
            echo "Renaming '{$name}' → '{$renames[$name]}' in group '{$group['label']}'\n";
            $field['name'] = $renames[$name];
            $changed++;
        }
    }
}
unset($group, $field);

if ($changed) {
    update_option('stride_enrollment_field_groups', $groups, false);
    echo "Updated {$changed} field name(s).\n";
} else {
    echo "No fields to rename.\n";
}

echo "\nCurrent groups:\n";
echo json_encode($groups, JSON_PRETTY_PRINT) . PHP_EOL;
