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
		add_action( 'woocommerce_coupon_options', array( $this, 'render_auto_apply_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_auto_apply_field' ), 10, 2 );
	}

	/**
	 * Render the Auto Apply checkbox on the coupon General tab.
	 *
	 * @param int       $coupon_id Coupon post ID.
	 * @param WC_Coupon $coupon    Coupon object.
	 * @return void
	 */
	public function render_auto_apply_field( $coupon_id, $coupon ) {
		woocommerce_wp_checkbox(
			array(
				'id'          => Auto_Apply_Cart_Coupon::META_KEY,
				'label'       => __( 'Auto Apply Coupon', 'auto-apply-cart-coupon' ),
				'description' => __( 'Automatically apply this coupon when a customer adds an item to their cart.', 'auto-apply-cart-coupon' ),
				'value'       => wc_bool_to_string( 'yes' === Auto_Apply_Cart_Coupon::get_auto_apply_value( $coupon ) ),
			)
		);
	}

	/**
	 * Save the Auto Apply checkbox value.
	 *
	 * @param int       $post_id Coupon post ID.
	 * @param WC_Coupon $coupon  Coupon object.
	 * @return void
	 */
	public function save_auto_apply_field( $post_id, $coupon ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce coupon save.
		$auto_apply = isset( $_POST[ Auto_Apply_Cart_Coupon::META_KEY ] ) ? 'yes' : 'no';

		$coupon->update_meta_data( Auto_Apply_Cart_Coupon::META_KEY, $auto_apply );
		$coupon->delete_meta_data( Auto_Apply_Cart_Coupon::LEGACY_META_KEY );
		$coupon->save();

		Auto_Apply_Cart_Coupon::clear_cache();
	}
}
