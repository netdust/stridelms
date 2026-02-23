<?php
/**
 * Stridence Footer
 *
 * Minimal footer without Kadence's inner-wrap closing tag.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>

    </div><!-- #content -->

    <?php
    /**
     * Hook for footer output
     * Kadence uses this to output its footer
     */
    do_action('kadence_footer');
    ?>

</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>
