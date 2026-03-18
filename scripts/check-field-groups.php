<?php
$groups = get_option('stride_enrollment_field_groups', []);
echo json_encode($groups, JSON_PRETTY_PRINT) . PHP_EOL;
