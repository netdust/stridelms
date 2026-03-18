<?php
// Clean up test data
$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentFieldGroups::class);
$svc->saveGroups([]);
echo "Cleaned up\n";
