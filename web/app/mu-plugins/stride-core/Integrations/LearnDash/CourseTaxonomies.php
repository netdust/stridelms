<?php

declare(strict_types=1);

namespace Stride\Integrations\LearnDash;

/**
 * Custom taxonomies for LearnDash courses.
 *
 * Registers stride_audience, stride_theme, and stride_format
 * taxonomies on sfwd-courses.
 *
 * Plain class — owned by LearnDashService.
 */
final class CourseTaxonomies
{
    /**
     * Register all custom course taxonomies.
     */
    public function register(): void
    {
        $this->registerAudienceTaxonomy();
        $this->registerThemeTaxonomy();
        $this->registerFormatTaxonomy();
    }

    private function registerAudienceTaxonomy(): void
    {
        $labels = [
            'name'              => __('Doelgroepen', 'stride'),
            'singular_name'     => __('Doelgroep', 'stride'),
            'search_items'      => __('Doelgroepen zoeken', 'stride'),
            'all_items'         => __('Alle doelgroepen', 'stride'),
            'parent_item'       => __('Bovenliggende doelgroep', 'stride'),
            'parent_item_colon' => __('Bovenliggende doelgroep:', 'stride'),
            'edit_item'         => __('Doelgroep bewerken', 'stride'),
            'update_item'       => __('Doelgroep bijwerken', 'stride'),
            'add_new_item'      => __('Nieuwe doelgroep toevoegen', 'stride'),
            'new_item_name'     => __('Nieuwe doelgroep naam', 'stride'),
            'menu_name'         => __('Doelgroepen', 'stride'),
        ];

        register_taxonomy('stride_audience', ['sfwd-courses'], [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'audience', 'with_front' => false],
        ]);
    }

    private function registerThemeTaxonomy(): void
    {
        $labels = [
            'name'              => __("Thema's", 'stride'),
            'singular_name'     => __('Thema', 'stride'),
            'search_items'      => __("Thema's zoeken", 'stride'),
            'all_items'         => __("Alle thema's", 'stride'),
            'parent_item'       => __('Bovenliggend thema', 'stride'),
            'parent_item_colon' => __('Bovenliggend thema:', 'stride'),
            'edit_item'         => __('Thema bewerken', 'stride'),
            'update_item'       => __('Thema bijwerken', 'stride'),
            'add_new_item'      => __('Nieuw thema toevoegen', 'stride'),
            'new_item_name'     => __('Nieuwe thema naam', 'stride'),
            'menu_name'         => __("Thema's", 'stride'),
        ];

        register_taxonomy('stride_theme', ['sfwd-courses'], [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'theme', 'with_front' => false],
        ]);
    }

    private function registerFormatTaxonomy(): void
    {
        $labels = [
            'name'              => __('Formaten', 'stride'),
            'singular_name'     => __('Formaat', 'stride'),
            'search_items'      => __('Formaten zoeken', 'stride'),
            'all_items'         => __('Alle formaten', 'stride'),
            'parent_item'       => __('Bovenliggend formaat', 'stride'),
            'parent_item_colon' => __('Bovenliggend formaat:', 'stride'),
            'edit_item'         => __('Formaat bewerken', 'stride'),
            'update_item'       => __('Formaat bijwerken', 'stride'),
            'add_new_item'      => __('Nieuw formaat toevoegen', 'stride'),
            'new_item_name'     => __('Nieuwe formaat naam', 'stride'),
            'menu_name'         => __('Formaten', 'stride'),
        ];

        register_taxonomy('stride_format', ['sfwd-courses'], [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'format', 'with_front' => false],
        ]);
    }
}
