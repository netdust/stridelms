<?php

declare(strict_types=1);

namespace stridence\services\frontend\shortcodes;

use Stride\Modules\Enrollment\EnrollmentFormResolver;
use WP_Post;

/**
 * Enrollment form shortcode.
 *
 * Renders the same enrollment form the router uses at
 * /edities|trajecten/<slug>/inschrijving/ — same props, same template, same
 * form-type gates. Editors can embed the form on any page; the shortcode
 * resolves the target (edition or trajectory) from attributes or URL params.
 *
 * Usage:
 *   [stride_enrollment edition="123"]         — pin to edition #123
 *   [stride_enrollment trajectory="42"]       — pin to trajectory #42
 *   [stride_enrollment edition="my-slug"]     — pin by slug
 *   [stride_enrollment]                       — read from ?editie= or ?traject= URL param
 *
 * `edition` and `trajectory` are mutually exclusive; setting both falls back
 * to whichever the URL provides.
 */
final class EnrollmentShortcode
{
    public function register(): void
    {
        add_shortcode('stride_enrollment', [$this, 'renderEnrollment']);
    }

    /**
     * @param array<string, string>|string $atts Raw shortcode attributes.
     */
    public function renderEnrollment($atts = []): string
    {
        $atts = shortcode_atts(
            ['edition' => '', 'trajectory' => ''],
            is_array($atts) ? $atts : [],
            'stride_enrollment',
        );

        [$item, $type] = $this->resolveTarget($atts);

        if (!$item) {
            return $this->errorState(
                __('Geen aanbod geselecteerd', 'stridence'),
                __('Selecteer eerst een editie of traject via de cursuspagina.', 'stridence'),
                __('Naar aanbod', 'stridence'),
                get_post_type_archive_link('sfwd-courses'),
            );
        }

        if (!is_user_logged_in()) {
            return $this->errorState(
                __('Aanmelden vereist', 'stridence'),
                __('Log in om je inschrijving te starten.', 'stridence'),
                __('Inloggen', 'stridence'),
                wp_login_url(get_permalink()),
                'lock',
            );
        }

        $resolver = ntdst_get(EnrollmentFormResolver::class);
        $args = $resolver->resolveTemplateArgs($item, $type);

        if ($args['state'] === 'already_enrolled') {
            return $this->errorState(
                __('Je bent al ingeschreven', 'stridence'),
                __('Je hebt al een actieve inschrijving voor dit aanbod.', 'stridence'),
                __('Naar mijn opleidingen', 'stridence'),
                home_url('/dashboard/opleidingen/'),
                'check-circle',
            );
        }

        if ($args['state'] === 'closed') {
            return $this->errorState(
                __('Inschrijving niet mogelijk', 'stridence'),
                __('Inschrijvingen voor dit aanbod zijn momenteel gesloten.', 'stridence'),
                __('Terug', 'stridence'),
                get_permalink($item->ID),
            );
        }

        // 'direct' state means the admin configured form-less immediate
        // enrollment. Embedding a direct-enroll edition via shortcode is an
        // unusual config — most callers use the canonical router URL which
        // dispatches the side-effect. Refuse here rather than silently render
        // an empty form so the admin notices and fixes the page.
        if ($args['state'] === 'direct') {
            return $this->errorState(
                __('Niet beschikbaar via shortcode', 'stridence'),
                __('Dit aanbod gebruikt directe inschrijving. Gebruik de standaard inschrijflink.', 'stridence'),
                __('Naar aanbod', 'stridence'),
                get_permalink($item->ID),
            );
        }

        return stridence_template_html('templates/forms/enrollment', null, [
            'item_id' => $args['item_id'],
            'item_type' => $args['item_type'],
            'item_data' => $args['item_data'],
            'enrollment_mode' => $args['enrollment_mode'],
            'enrollment_open' => $args['enrollment_open'],
            'is_online' => $args['is_online'],
            'form_type' => $args['form_type'],
        ]);
    }

    /**
     * Resolve which post the form should target, in priority order:
     *   1. Explicit shortcode attribute (`edition` or `trajectory`)
     *   2. URL query param (`?editie=` or `?traject=`)
     *
     * Each input accepts a numeric ID or a slug.
     *
     * @param array{edition: string, trajectory: string} $atts
     * @return array{0: WP_Post|null, 1: string} [post, type]
     */
    private function resolveTarget(array $atts): array
    {
        if ($atts['edition'] !== '') {
            $post = $this->locatePost($atts['edition'], 'vad_edition');
            if ($post) {
                return [$post, 'edition'];
            }
        }
        if ($atts['trajectory'] !== '') {
            $post = $this->locatePost($atts['trajectory'], 'vad_trajectory');
            if ($post) {
                return [$post, 'trajectory'];
            }
        }

        $editionParam = isset($_GET['editie']) ? (string) $_GET['editie'] : '';
        if ($editionParam !== '') {
            $post = $this->locatePost($editionParam, 'vad_edition');
            if ($post) {
                return [$post, 'edition'];
            }
        }

        $trajectoryParam = isset($_GET['traject']) ? (string) $_GET['traject'] : '';
        if ($trajectoryParam !== '') {
            $post = $this->locatePost($trajectoryParam, 'vad_trajectory');
            if ($post) {
                return [$post, 'trajectory'];
            }
        }

        return [null, ''];
    }

    /**
     * Locate a post by numeric ID or slug, restricted to the given type.
     */
    private function locatePost(string $idOrSlug, string $postType): ?WP_Post
    {
        if (is_numeric($idOrSlug)) {
            $post = get_post((int) $idOrSlug);
            return ($post && $post->post_type === $postType) ? $post : null;
        }

        $post = get_page_by_path(sanitize_title($idOrSlug), OBJECT, $postType);
        return $post instanceof WP_Post ? $post : null;
    }

    private function errorState(
        string $title,
        string $message,
        string $action,
        string $url,
        string $icon = 'alert-circle',
    ): string {
        return stridence_render_error_state($icon, $title, $message, $action, $url);
    }
}
