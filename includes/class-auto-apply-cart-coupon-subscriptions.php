<?php
/**
 * Subscriptions integration for first-month-only coupons.
 *
 * @package Auto_Apply_Cart_Coupon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Apply_Cart_Coupon_Subscriptions
 */
class Auto_Apply_Cart_Coupon_Subscriptions {

	/**
	 * Plugin instance.
	 *
	 * @var Auto_Apply_Cart_Coupon_Subscriptions|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return Auto_Apply_Cart_Coupon_Subscriptions
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'remove_from_recurring_cart' ), 20 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_first_month_only' ), 20, 2 );
		add_action( 'woocommerce_subscription_payment_complete', array( $this, 'maybe_remove_after_first_payment' ) );
	}

	/**
	 * Remove first-month-only coupons from recurring cart totals when the
	 * initial cart already received the discount.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public function remove_from_recurring_cart( $cart ) {
		if ( ! class_exists( 'WC_Subscriptions_Cart' ) || ! WC()->cart ) {
			return;
		}

		if ( 'recurring_total' !== WC_Subscriptions_Cart::get_calculation_type() ) {
			return;
		}

		if ( empty( $cart->recurring_cart_key ) ) {
			return;
		}

		$applied_coupons = $cart->get_applied_coupons();

		if ( empty( $applied_coupons ) ) {
			return;
		}

		foreach ( $applied_coupons as $code ) {
			$coupon = new WC_Coupon( $code );

			if ( ! $this->is_first_month_only( $coupon ) ) {
				continue;
			}

			// If the initial cart already discounted, keep this off renewals.
			if ( 0 < WC()->cart->get_coupon_discount_amount( $code ) ) {
				$cart->remove_coupon( $code );
			}
		}
	}

	/**
	 * Block first-month-only coupons on subscription renewal carts.
	 *
	 * @param bool      $valid  Whether the coupon is valid.
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	public function validate_first_month_only( $valid, $coupon ) {
		if ( ! $valid || ! $this->is_first_month_only( $coupon ) ) {
			return $valid;
		}

		if ( function_exists( 'wcs_cart_contains_renewal' ) && wcs_cart_contains_renewal() ) {
			add_filter( 'woocommerce_coupon_error', array( $this, 'first_month_only_error' ), 10, 3 );
			return false;
		}

		return $valid;
	}

	/**
	 * Error message when a first-month-only coupon is used on a renewal.
	 *
	 * @param string    $error      Error message.
	 * @param int       $error_code Error code.
	 * @param WC_Coupon $coupon     Coupon object.
	 * @return string
	 */
	public function first_month_only_error( $error, $error_code, $coupon ) {
		unset( $error_code, $coupon );

		remove_filter( 'woocommerce_coupon_error', array( $this, 'first_month_only_error' ), 10 );

		return __( 'This coupon only applies to the first payment of a subscription.', 'auto-apply-cart-coupon' );
	}

	/**
	 * Remove first-month-only coupons from a subscription once they have been
	 * used on one paid order (the initial payment, or the first paid period
	 * after a free trial).
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return void
	 */
	public function maybe_remove_after_first_payment( $subscription ) {
		if ( ! is_a( $subscription, 'WC_Subscription' ) ) {
			return;
		}

		$codes = function_exists( 'wcs_get_used_coupon_codes' )
			? wcs_get_used_coupon_codes( $subscription )
			: $subscription->get_coupon_codes();

		if ( empty( $codes ) ) {
			return;
		}

		foreach ( $codes as $code ) {
			$coupon = new WC_Coupon( $code );

			if ( ! $this->is_first_month_only( $coupon ) ) {
				continue;
			}

			if ( $this->count_paid_coupon_usages( $subscription, $code ) >= 1 ) {
				$subscription->remove_coupon( $code );
				$subscription->add_order_note(
					sprintf(
						/* translators: %s: coupon code */
						__( 'First-month-only coupon "%s" removed after the initial payment.', 'auto-apply-cart-coupon' ),
						$code
					)
				);
			}
		}
	}

	/**
	 * Count paid related orders that used a given coupon.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param string          $code         Coupon code.
	 * @return int
	 */
	private function count_paid_coupon_usages( $subscription, $code ) {
		$count  = 0;
		$orders = $subscription->get_related_orders( 'all' );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order->needs_payment() ) {
				continue;
			}

			$refunded = $order->get_total_refunded();
			$total    = $order->get_total();

			// Fully refunded orders do not count as a usage.
			if ( $refunded && (float) $total === (float) $refunded ) {
				continue;
			}

			if ( ! $order->get_discount_total() ) {
				continue;
			}

			foreach ( $order->get_items( 'coupon' ) as $used_coupon ) {
				if ( $used_coupon->get_code() === $code && $used_coupon->get_discount() ) {
					++$count;
					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Whether a coupon is marked first-month-only.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	private function is_first_month_only( $coupon ) {
		return 'yes' === Auto_Apply_Cart_Coupon::get_first_month_only_value( $coupon );
	}
}
