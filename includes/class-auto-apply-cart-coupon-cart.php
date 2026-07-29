<?php
/**
 * Cart functionality for auto-applying coupons.
 *
 * @package Auto_Apply_Cart_Coupon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Apply_Cart_Coupon_Cart
 */
class Auto_Apply_Cart_Coupon_Cart {

	/**
	 * Plugin instance.
	 *
	 * @var Auto_Apply_Cart_Coupon_Cart|null
	 */
	private static $instance = null;

	/**
	 * Whether a sync is already in progress (prevents recursion).
	 *
	 * @var bool
	 */
	private $is_syncing = false;

	/**
	 * Get plugin instance.
	 *
	 * @return Auto_Apply_Cart_Coupon_Cart
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
		add_action( 'woocommerce_add_to_cart', array( $this, 'sync_auto_coupons' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'sync_auto_coupons' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'sync_auto_coupons' ), 20 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'sync_auto_coupons' ), 20 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'sync_auto_coupons' ), 20 );
	}

	/**
	 * Sync auto-apply coupons with the current cart.
	 *
	 * Applies coupons only when WooCommerce usage restrictions pass
	 * (products, categories, min/max spend, sale items, etc.) and removes
	 * previously auto-applied coupons that are no longer valid.
	 *
	 * @return void
	 */
	public function sync_auto_coupons() {
		if ( $this->is_syncing || $this->should_skip_auto_apply() ) {
			return;
		}

		if ( ! WC()->cart ) {
			return;
		}

		$auto_coupons = $this->get_auto_apply_coupon_codes();

		if ( empty( $auto_coupons ) ) {
			return;
		}

		$this->is_syncing = true;

		$auto_codes_normalized = array_map( 'wc_format_coupon_code', $auto_coupons );

		// Remove auto-apply coupons that no longer meet usage restrictions.
		foreach ( WC()->cart->get_applied_coupons() as $applied_code ) {
			if ( ! in_array( wc_format_coupon_code( $applied_code ), $auto_codes_normalized, true ) ) {
				continue;
			}

			if ( WC()->cart->is_empty() || ! $this->coupon_is_valid_for_cart( $applied_code ) ) {
				$this->remove_coupon_quietly( $applied_code );
			}
		}

		// Apply eligible auto-apply coupons that pass all WooCommerce restrictions.
		if ( ! WC()->cart->is_empty() ) {
			foreach ( $auto_coupons as $code ) {
				if ( WC()->cart->has_discount( $code ) ) {
					continue;
				}

				if ( ! $this->coupon_is_valid_for_cart( $code ) ) {
					continue;
				}

				$this->apply_coupon_quietly( $code );
			}
		}

		$this->is_syncing = false;
	}

	/**
	 * Check whether a coupon is valid for the current cart.
	 *
	 * Uses WooCommerce's own coupon validation so product restrictions,
	 * minimum spend, maximum spend, excluded products/categories, sale
	 * item rules, usage limits, and expiry dates are all respected.
	 *
	 * @param string $code Coupon code.
	 * @return bool
	 */
	private function coupon_is_valid_for_cart( $code ) {
		$coupon = new WC_Coupon( $code );

		if ( ! $coupon->get_id() ) {
			return false;
		}

		return (bool) $coupon->is_valid();
	}

	/**
	 * Apply a coupon without showing storefront notices.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 */
	private function apply_coupon_quietly( $code ) {
		$this->with_suppressed_coupon_notices(
			function () use ( $code ) {
				WC()->cart->apply_coupon( $code );
			}
		);
	}

	/**
	 * Remove a coupon without showing storefront notices.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 */
	private function remove_coupon_quietly( $code ) {
		$this->with_suppressed_coupon_notices(
			function () use ( $code ) {
				WC()->cart->remove_coupon( $code );
			}
		);
	}

	/**
	 * Run a callback while suppressing coupon success/error notices.
	 *
	 * @param callable $callback Callback to run.
	 * @return void
	 */
	private function with_suppressed_coupon_notices( $callback ) {
		add_filter( 'woocommerce_coupon_message', array( $this, 'suppress_coupon_notice' ), 100 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'suppress_coupon_notice' ), 100 );

		try {
			$callback();
		} finally {
			remove_filter( 'woocommerce_coupon_message', array( $this, 'suppress_coupon_notice' ), 100 );
			remove_filter( 'woocommerce_coupon_error', array( $this, 'suppress_coupon_notice' ), 100 );
		}
	}

	/**
	 * Suppress coupon notices during quiet apply/remove.
	 *
	 * @return string
	 */
	public function suppress_coupon_notice() {
		return '';
	}

	/**
	 * Whether auto-apply should be skipped for the current request.
	 *
	 * Admin AJAX and storefront add-to-cart both set is_admin() + wp_doing_ajax().
	 * Requests that originated from wp-admin (e.g. order/subscription item editors)
	 * must never re-trigger auto-apply coupons into temporary cart rebuilds.
	 *
	 * @return bool
	 */
	private function should_skip_auto_apply() {
		// Skip non-AJAX admin screens.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}

		// Skip AJAX that was initiated from any wp-admin screen.
		if ( $this->request_originated_from_admin() ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect requests that started from wp-admin via HTTP referer.
	 *
	 * @return bool
	 */
	private function request_originated_from_admin() {
		$referer = wp_get_referer();

		if ( ! $referer ) {
			$referer = wp_get_raw_referer();
		}

		if ( ! $referer ) {
			return false;
		}

		$admin_url = admin_url();

		return 0 === strpos( $referer, $admin_url );
	}

	/**
	 * Get coupon codes marked for auto-apply.
	 *
	 * @return array<string>
	 */
	private function get_auto_apply_coupon_codes() {
		$cached = get_transient( Auto_Apply_Cart_Coupon::CACHE_KEY );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$coupon_ids = $this->get_auto_apply_coupon_ids();
		$codes      = array();

		foreach ( $coupon_ids as $coupon_id ) {
			$coupon = new WC_Coupon( $coupon_id );
			$code   = $coupon->get_code();

			if ( $code ) {
				$codes[] = $code;
			}
		}

		set_transient( Auto_Apply_Cart_Coupon::CACHE_KEY, $codes, DAY_IN_SECONDS );

		return $codes;
	}

	/**
	 * Query coupon IDs with auto-apply enabled, including legacy meta.
	 *
	 * @return array<int>
	 */
	private function get_auto_apply_coupon_ids() {
		$current_ids = get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'meta_key'       => Auto_Apply_Cart_Coupon::META_KEY,
				'meta_value'     => 'yes',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$legacy_ids = get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'meta_key'       => Auto_Apply_Cart_Coupon::LEGACY_META_KEY,
				'meta_value'     => 'yes',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_values( array_unique( array_merge( $current_ids, $legacy_ids ) ) );
	}
}
