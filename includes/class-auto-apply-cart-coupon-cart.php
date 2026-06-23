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
		add_action( 'woocommerce_add_to_cart', array( $this, 'apply_auto_coupons' ), 10, 0 );
	}

	/**
	 * Apply auto-apply coupons when an item is added to the cart.
	 *
	 * @return void
	 */
	public function apply_auto_coupons() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! WC()->cart ) {
			return;
		}

		$auto_coupons = $this->get_auto_apply_coupon_codes();

		if ( empty( $auto_coupons ) ) {
			return;
		}

		foreach ( $auto_coupons as $code ) {
			if ( ! WC()->cart->has_discount( $code ) ) {
				WC()->cart->apply_coupon( $code );
			}
		}
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
