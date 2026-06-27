<?php

$p = get_post(5913);
if ($p) {
    echo "Post 5913: type={$p->post_type} title={$p->post_title} status={$p->post_status}\n";
    $courseId = get_post_meta(5913, '_ntdst_course_id', true);
    echo "course_id meta: {$courseId}\n";
} else {
    echo "Post 5913 not found\n";
}
