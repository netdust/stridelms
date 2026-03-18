<?php
$fg = ntdst_get(\Stride\Modules\Enrollment\EnrollmentFieldGroups::class);
$fields = $fg->getEnrollmentFieldsForPost(5782, 'vad_edition');
echo 'Fields for edition 5782: ' . count($fields) . PHP_EOL;
foreach ($fields as $f) echo '  ' . ($f['name'] ?? '') . ' (' . ($f['label'] ?? '') . ')' . PHP_EOL;

// Also check 5913 (the one we know has field groups)
$fields2 = $fg->getEnrollmentFieldsForPost(5913, 'vad_edition');
echo PHP_EOL . 'Fields for edition 5913: ' . count($fields2) . PHP_EOL;
foreach ($fields2 as $f) echo '  ' . ($f['name'] ?? '') . ' (' . ($f['label'] ?? '') . ')' . PHP_EOL;
