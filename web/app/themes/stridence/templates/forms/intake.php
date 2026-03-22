<?php
stridence_template_part('templates/forms/stage-form', null, [
    'edition_id' => $args['edition_id'] ?? 0,
    'stage' => 'intake',
    'title' => __('Intake vragenlijst', 'stridence'),
    'description' => __('Vul deze vragen in voor aanvang van de opleiding.', 'stridence'),
]);
