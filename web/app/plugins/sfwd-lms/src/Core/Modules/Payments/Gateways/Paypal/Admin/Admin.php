<?php
/**
 * Admin class for PayPal Checkout.
 *
 * @since 4.25.0
 *
 * @package LearnDash\Core
 */

namespace LearnDash\Core\Modules\Payments\Gateways\Paypal\Admin;

use StellarWP\Learndash\StellarWP\SuperGlobals\SuperGlobals;

/**
 * Class Admin.
 *
 * @since 4.25.0
 */
class Admin {
	/**
	 * Checks if the current page is the PayPal Checkout settings page and stop
	 * showing the Stripe Connect banner.
	 *
	 * @since 4.25.0
	 *
	 * @param bool $is_on_payments_setting_page Whether the current page is the payments settings page.
	 *
	 * @return bool
	 */
	public function hide_stripe_connect_banner( bool $is_on_payments_setting_page ): bool {
		return $is_on_payments_setting_page
			&& SuperGlobals::get_get_var( 'section-payment' ) !== 'settings_paypal_checkout';
	}
}
