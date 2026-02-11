<?php
/**
 * View: Order Subscription Details - Table Body - Field: Status.
 *
 * @since 4.25.0
 * @version 4.25.0
 *
 * @var Subscription $subscription Subscription object.
 *
 * @package LearnDash\Core
 */

use LearnDash\Core\Models\Commerce\Subscription;
?>
<div class="ld-order-items__item-data ld-order-items__item-data--last-child" role="cell">
	<div class="ld-order-subscription__details-value">
		<div class="ld-order-subscription__details-status">
			<?php echo esc_html( $subscription->get_status_label() ); ?>
		</div>

		<?php if ( $subscription->can_be_cancelled() ) : ?>
			<div class="ld-order-subscription__details-actions">
				<a
					class="ld-order-subscription__details-action ld-order-subscription__details-action--cancel"
					href="<?php echo esc_url( $subscription->get_cancel_url() ); ?>"
				>
					<?php esc_html_e( 'Cancel Subscription', 'learndash' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</div>
