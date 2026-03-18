<?php
$service = ntdst_get(\Stride\Admin\FieldGroupSettingsPage::class);
echo "Settings page loaded: " . get_class($service) . "\n";

$fgService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentFieldGroups::class);
echo "FG Service loaded: " . get_class($fgService) . "\n";
echo "Groups count: " . count($fgService->getAllGroups()) . "\n";
