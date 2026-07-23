<?php
/**
 * Admin functionality for coupon auto-apply settings.
 *
 * @package Auto_Apply_Cart_Coupon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Apply_Cart_Coupon_Admin
 */
class Auto_Apply_Cart_Coupon_Admin {

	/**
	 * Plugin instance.
	 *
	 * @var Auto_Apply_Cart_Coupon_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return Auto_Apply_Cart_Coupon_Admin
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
		add_action( 'woocommerce_coupon_options', array( $this, 'render_coupon_fields' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_fields' ), 10, 2 );
	}

	/**
	 * Render coupon option checkboxes on the General tab.
	 *
	 * @param int       $coupon_id Coupon post ID.
	 * @param WC_Coupon $coupon    Coupon object.
	 * @return void
	 */
	public function render_coupon_fields( $coupon_id, $coupon ) {
		woocommerce_wp_checkbox(
			array(
				'id'          => Auto_Apply_Cart_Coupon::META_KEY,
				'label'       => __( 'Auto Apply Coupon', 'auto-apply-cart-coupon' ),
				'description' => __( 'Automatically apply this coupon when a customer adds an item to their cart.', 'auto-apply-cart-coupon' ),
				'value'       => wc_bool_to_string( 'yes' === Auto_Apply_Cart_Coupon::get_auto_apply_value( $coupon ) ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => Auto_Apply_Cart_Coupon::FIRST_MONTH_META_KEY,
				'label'       => __( 'First Month Only', 'auto-apply-cart-coupon' ),
				'description' => __( 'For subscription purchases, apply this coupon to the first payment only. It will not discount renewals.', 'auto-apply-cart-coupon' ),
				'value'       => wc_bool_to_string( 'yes' === Auto_Apply_Cart_Coupon::get_first_month_only_value( $coupon ) ),
			)
		);
	}

	/**
	 * Save coupon option checkbox values.
	 *
	 * @param int       $post_id Coupon post ID.
	 * @param WC_Coupon $coupon  Coupon object.
	 * @return void
	 */
	public function save_coupon_fields( $post_id, $coupon ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce coupon save.
		$auto_apply = isset( $_POST[ Auto_Apply_Cart_Coupon::META_KEY ] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce coupon save.
		$first_month_only = isset( $_POST[ Auto_Apply_Cart_Coupon::FIRST_MONTH_META_KEY ] ) ? 'yes' : 'no';

		$coupon->update_meta_data( Auto_Apply_Cart_Coupon::META_KEY, $auto_apply );
		$coupon->update_meta_data( Auto_Apply_Cart_Coupon::FIRST_MONTH_META_KEY, $first_month_only );
		$coupon->delete_meta_data( Auto_Apply_Cart_Coupon::LEGACY_META_KEY );
		$coupon->save();

		Auto_Apply_Cart_Coupon::clear_cache();
	}
}
