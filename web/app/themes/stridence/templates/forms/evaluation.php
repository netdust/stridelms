<?php
stridence_template_part('templates/forms/stage-form', null, [
    'edition_id' => $args['edition_id'] ?? 0,
    'stage' => 'evaluation',
    'title' => __('Evaluatie', 'stridence'),
    'description' => __('Help ons verbeteren door deze evaluatie in te vullen.', 'stridence'),
]);
