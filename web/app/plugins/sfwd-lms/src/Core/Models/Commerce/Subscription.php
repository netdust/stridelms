<?php
/**
 * Subscription model class.
 *
 * Represents a subscription transaction (child of Order) in the LearnDash payment system.
 *
 * @since 4.25.0
 *
 * @package LearnDash\Core
 */

namespace LearnDash\Core\Models\Commerce;

use DateInterval;
use DateTime;
use DateTimeZone;
use LearnDash\Core\Models\Product as Core_Product;
use LearnDash\Core\Repositories\Charge as Charge_Repository;
use LearnDash\Core\Utilities\Cast;
use StellarWP\Learndash\StellarWP\Arrays\Arr;

/**
 * Subscription model class.
 *
 * @since 4.25.0
 */
class Subscription extends Product {
	/**
	 * Subscription status 'Active'.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $status_active = 'active';

	/**
	 * Subscription status 'Canceled'.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $status_canceled = 'canceled';

	/**
	 * Subscription status 'Expired'.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $status_expired = 'expired';

	/**
	 * Subscription status 'Trial'.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $status_trial = 'trial';

	/**
	 * Meta key for the subscription payment token.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $meta_key_payment_token = 'payment_token';

	/**
	 * Meta key for the subscription payment method information.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $meta_key_payment_method_information = 'payment_method_information';

	/**
	 * Meta key for the subscription next payment date.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $meta_key_next_payment_date = 'next_payment_date';

	/**
	 * Meta key for the subscription expired date.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	public static $meta_key_expired_date = 'expired_date';

	/**
	 * Returns the subscription status based on the product.
	 *
	 * @since 4.25.0
	 *
	 * @param Core_Product $product The product.
	 *
	 * @return string The status. Empty string if the product is not a subscription.
	 */
	public function get_status_based_on_product( Core_Product $product ): string {
		if ( ! $product->is_price_type_subscribe() ) {
			return ''; // Not applicable.
		}

		return $product->has_trial() ?
		self::$status_trial :
		self::$status_active;
	}

	/**
	 * Returns the status label.
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	public function get_status_label(): string {
		switch ( $this->get_status() ) {
			case self::$status_active:
				return __( 'Active', 'learndash' );
			case self::$status_canceled:
				return __( 'Canceled', 'learndash' );
			case self::$status_expired:
				return __( 'Expired', 'learndash' );
			case self::$status_trial:
				return __( 'Trial', 'learndash' );
			default:
				return __( 'Unknown', 'learndash' );
		}
	}

	/**
	 * Returns the related product price.
	 *
	 * @since 4.25.0
	 *
	 * @return float
	 */
	public function get_price(): float {
		$product = $this->get_product();

		if ( ! $product ) {
			return 0;
		}

		// We use the regular price for subscriptions.
		return $product->get_price();
	}

	/**
	 * Returns the subscription ID in the payment gateway (the ID that is used to identify the subscription in the payment gateway).
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	public function get_gateway_subscription_id(): string {
		// We only support PayPal for now and PayPal does not use subscription IDs.
		return '---';
	}

	/**
	 * Cancels the subscription.
	 *
	 * @since 4.25.0
	 *
	 * @param string $reason             The reason for the cancellation.
	 * @param bool   $force_cancellation Whether to force the cancellation. Default false.
	 *
	 * @return bool True if the subscription was canceled. False otherwise.
	 */
	public function cancel( string $reason, bool $force_cancellation = false ): bool {
		if (
			! $force_cancellation
			&& ! $this->can_be_cancelled()
		) {
			return false;
		}

		$product = $this->get_product();

		if ( ! $product ) {
			return false; // We don't have a product, so we don't need to do anything.
		}

		$gateway                     = $this->get_gateway();
		$gateway_cancellation_result = true;

		if (
			! $force_cancellation
			&& $gateway->supports_transactions_management()
		) {
			$gateway_cancellation_result = $gateway->cancel_subscription( $this->get_gateway_subscription_id() );
		}

		if ( is_wp_error( $gateway_cancellation_result ) ) {
			return false;
		}

		// Process the cancellation.

		$product->unenroll( $this->get_user() );

		$this->set_status( self::$status_canceled );
		$this->set_meta( self::$meta_key_next_payment_date, 0 );
		$this->set_meta( self::$meta_key_cancellation_date, time() );
		$this->set_meta( self::$meta_key_cancellation_reason, $reason );

		return true;
	}

	/**
	 * Returns the timestamp of the next payment date.
	 *
	 * @since 4.25.0
	 *
	 * @return int
	 */
	public function get_next_payment_date(): int {
		return Cast::to_int( $this->getAttribute( self::$meta_key_next_payment_date ) );
	}

	/**
	 * Returns the timestamp of the expired date, or null if the subscription is not expired.
	 *
	 * @since 4.25.0
	 *
	 * @return int|null
	 */
	public function get_expired_date(): ?int {
		return Cast::to_int( $this->getAttribute( self::$meta_key_expired_date ) );
	}

	/**
	 * Returns the payment method description, or null if the subscription does not have a payment method attached.
	 *
	 * @since 4.25.0
	 *
	 * @return string|null
	 */
	public function get_payment_method_description(): ?string {
		return $this->get_payment_method_information()['description'] ?? null;
	}

	/**
	 * Gets the payment method display information for the subscription.
	 *
	 * @since 4.25.0
	 *
	 * @return array{
	 *    description: string,
	 *    icon: string,
	 * }|null The payment method information or null if not available.
	 */
	public function get_payment_method_information(): ?array {
		/**
		 * Fetches the payment method information from metadata.
		 *
		 * @var array{
		 *    description: string,
		 *    icon: string,
		 * }|null $saved_information The payment method information or null if not available.
		 */
		$saved_information = (array) $this->getAttribute( self::$meta_key_payment_method_information );

		if ( ! empty( $saved_information ) ) {
			return $saved_information;
		}

		$payment_token = $this->get_payment_token();

		if ( empty( $payment_token ) ) {
			return null;
		}

		/**
		 * Filters the payment method information for a subscription.
		 *
		 * @since 4.25.0
		 *
		 * @phpstan-param array{
		 *    description: string,
		 *    icon: string,
		 * } $information
		 *
		 * @param array<string,string> $information   The payment method information. Default empty array.
		 * @param array<string,string> $payment_token The payment token data.
		 * @param Subscription         $subscription  The subscription instance.
		 *
		 * @return array{
		 *    description: string,
		 *    icon: string,
		 * } The payment method information or empty array if not available.
		 */
		$information = apply_filters(
			'learndash_subscription_payment_method_information',
			[
				'description' => '',
				'icon'        => '',
			],
			$payment_token,
			$this,
		);

		if ( empty( $information['description'] ) ) {
			return null;
		}

		// Save the payment method information to metadata.
		$this->set_meta( self::$meta_key_payment_method_information, $information );

		return $information;
	}

	/**
	 * Returns the URL to the page where the user can re-enroll the subscription.
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	public function get_re_enroll_url(): string {
		$product = $this->get_product();

		if ( ! $product ) {
			return '';
		}

		return $product->get_permalink();
	}

	/**
	 * Returns whether the subscription can be canceled.
	 *
	 * @since 4.25.0
	 *
	 * @return bool
	 */
	public function can_be_cancelled(): bool {
		// Check the status.

		if ( ! in_array( $this->get_status(), [ self::$status_active, self::$status_trial ], true ) ) {
			return false; // Only active and trial subscriptions can be canceled.
		}

		// Check the user permissions.

		if ( learndash_is_admin_user() ) {
			return true; // Admins can cancel any subscription.
		}

		$current_user_id = get_current_user_id();

		// Normal users can only cancel their own subscriptions.
		return $current_user_id !== 0 && $this->get_user()->ID === $current_user_id;
	}

	/**
	 * Returns the URL to cancel the subscription.
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	public function get_cancel_url(): string {
		if ( ! $this->can_be_cancelled() ) {
			return '';
		}

		return add_query_arg(
			[
				'ld_action'          => 'cancel_subscription',
				'ld_subscription_id' => $this->get_id(),
				'ld_nonce'           => wp_create_nonce( 'ld_subscription_cancel_' . $this->get_id() ),
			],
		);
	}

	/**
	 * Returns the charges for the subscription.
	 *
	 * @since 4.25.0
	 *
	 * @param string|null $status The charge status (optional). Default null (all statuses).
	 * @param int         $limit  Limit (optional). Default 0 (no limit).
	 * @param int         $offset Offset (optional). Default 0 (no offset).
	 *
	 * @return Charge[]
	 */
	public function get_charges( ?string $status = null, int $limit = 0, int $offset = 0 ): array {
		return Charge_Repository::find_by_subscription_id( $this->get_id(), $status, $limit, $offset );
	}

	/**
	 * Returns the payment token.
	 *
	 * @since 4.25.0
	 *
	 * @return array<string,string>
	 */
	public function get_payment_token(): array {
		return array_filter(
			Arr::wrap(
				$this->getAttribute( self::$meta_key_payment_token )
			)
		);
	}

	/**
	 * Returns the number of charges for the subscription.
	 *
	 * @since 4.25.0
	 *
	 * @param string|null $status The charge status (optional). Default null (all statuses).
	 *
	 * @return int
	 */
	public function count_charges( ?string $status = null ): int {
		return Charge_Repository::count_by_subscription_id( $this->get_id(), $status );
	}

	/**
	 * Sets the payment token.
	 *
	 * @since 4.25.0
	 *
	 * @param array<string,string> $payment_token The payment token.
	 *
	 * @return void
	 */
	public function set_payment_token( array $payment_token ): void {
		$this->set_meta( self::$meta_key_payment_token, $payment_token );
	}

	/**
	 * Adds a charge for the subscription.
	 *
	 * @since 4.25.0
	 *
	 * @param float  $price    The price of the charge.
	 * @param string $status   The status of the charge.
	 * @param bool   $is_trial Whether the charge is a trial. Default false.
	 *
	 * @return void
	 */
	public function add_charge( float $price, string $status, bool $is_trial = false ): void {
		Charge::create( $this->get_id(), $this->get_user()->ID, $price, $status, $is_trial );
	}

	/**
	 * Returns whether the subscription is canceled.
	 *
	 * @since 4.25.0
	 *
	 * @return bool
	 */
	public function is_canceled(): bool {
		return $this->get_status() === self::$status_canceled;
	}

	/**
	 * Returns whether the subscription is expired.
	 *
	 * @since 4.25.0
	 *
	 * @return bool
	 */
	public function is_expired(): bool {
		return $this->get_status() === self::$status_expired;
	}

	/**
	 * Returns whether the subscription is active.
	 *
	 * @since 4.25.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->get_status() === self::$status_active;
	}

	/**
	 * Returns whether the subscription is trial.
	 *
	 * @since 4.25.0
	 *
	 * @return bool
	 */
	public function is_trial(): bool {
		return $this->get_status() === self::$status_trial;
	}

	/**
	 * Maybe expires the subscription, if recurring times is reached.
	 *
	 * @since 4.25.0
	 *
	 * @return void
	 */
	public function maybe_expire(): void {
		$product = $this->get_product();

		if ( ! $product ) {
			return; // We don't have a product, so we don't need to do anything.
		}

		$recurring_times = $product->get_pricing()->recurring_times;

		if ( $recurring_times <= 0 ) {
			return; // We don't have a limit of recurring times, so we don't need to do anything.
		}

		// If the product has trial, we need to include the trial charge in the recurring times.
		if ( $product->has_trial() ) {
			++$recurring_times;
		}

		// Count the success charges.

		$count_charges = $this->count_charges( Charge::$status_success );

		// Expire the subscription if the number of charges is greater than or equal to the recurring times.

		if ( $count_charges >= $recurring_times ) {
			$this->set_status( self::$status_expired );
			$this->set_meta( self::$meta_key_next_payment_date, 0 );
			$this->set_meta( self::$meta_key_expired_date, time() );
		}
	}

	/**
	 * Calculates the next payment date.
	 *
	 * @since 4.25.0
	 *
	 * @return void
	 */
	public function calculate_next_payment_date(): void {
		$product = $this->get_product();

		if ( ! $product ) {
			return; // We don't have a product, so we don't need to do anything.
		}

		$pricing                   = $product->get_pricing();
		$current_next_payment_date = $this->get_next_payment_date();

		// If there's a trial and no next payment date (first payment), the next payment date is after the trial period.
		if (
			$current_next_payment_date === 0
			&& ! empty( $pricing->trial_duration_value )
			&& ! empty( $pricing->trial_duration_length )
		) {
			$start_date = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

			$start_date->add( new DateInterval( "P{$pricing->trial_duration_value}{$pricing->trial_duration_length}" ) );

			$this->set_meta( self::$meta_key_next_payment_date, $start_date->getTimestamp() );

			return;
		}

		// For subscriptions without trial, the next payment date is after the billing cycle, considering the current next payment date (if any).
		if (
			! empty( $pricing->duration_value )
			&& ! empty( $pricing->duration_length )
		) {
			$start_date = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

			// If there's a current next payment date, set the start date to the current next payment date.

			if ( $current_next_payment_date > 0 ) {
				$start_date->setTimestamp( $current_next_payment_date );
			}

			$start_date->add( new DateInterval( "P{$pricing->duration_value}{$pricing->duration_length}" ) );

			$this->set_meta( self::$meta_key_next_payment_date, $start_date->getTimestamp() );

			return;
		}
	}
}
