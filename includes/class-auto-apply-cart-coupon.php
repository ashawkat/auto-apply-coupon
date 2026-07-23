<?php
/**
 * Main plugin class.
 *
 * @package Auto_Apply_Cart_Coupon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Apply_Cart_Coupon
 */
final class Auto_Apply_Cart_Coupon {

	/**
	 * Plugin instance.
	 *
	 * @var Auto_Apply_Cart_Coupon|null
	 */
	private static $instance = null;

	/**
	 * Transient key for cached auto-apply coupon codes.
	 */
	const CACHE_KEY = 'aacc_auto_apply_coupons';

	/**
	 * Coupon meta key for the auto-apply setting.
	 */
	const META_KEY = '_auto_apply_cart_coupon';

	/**
	 * Legacy meta key from the previous plugin slug.
	 */
	const LEGACY_META_KEY = '_wc_auto_apply_coupon';

	/**
	 * Coupon meta key for first-month-only (subscriptions) setting.
	 */
	const FIRST_MONTH_META_KEY = '_aacc_first_month_only';

	/**
	 * Get plugin instance.
	 *
	 * @return Auto_Apply_Cart_Coupon
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
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_filter( 'plugin_action_links_' . AACC_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Initialize plugin components after WooCommerce is available.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		load_plugin_textdomain( 'auto-apply-cart-coupon', false, dirname( AACC_BASENAME ) . '/languages' );

		require_once AACC_PATH . 'includes/class-auto-apply-cart-coupon-admin.php';
		require_once AACC_PATH . 'includes/class-auto-apply-cart-coupon-cart.php';

		Auto_Apply_Cart_Coupon_Admin::instance();
		Auto_Apply_Cart_Coupon_Cart::instance();

		if ( class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscriptions_Core_Plugin' ) ) {
			require_once AACC_PATH . 'includes/class-auto-apply-cart-coupon-subscriptions.php';
			Auto_Apply_Cart_Coupon_Subscriptions::instance();
		}
	}

	/**
	 * Add a settings link on the plugins page that opens the coupons list.
	 *
	 * @param array<string> $links Existing plugin action links.
	 * @return array<string>
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit.php?post_type=shop_coupon' ) ),
			esc_html__( 'Settings', 'auto-apply-cart-coupon' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Display admin notice when WooCommerce is not active.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Auto Apply Cart Coupon requires WooCommerce to be installed and active.', 'auto-apply-cart-coupon' )
		);
	}

	/**
	 * Clear the auto-apply coupons cache.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
		delete_transient( 'wc_auto_apply_coupons' );
	}

	/**
	 * Read the auto-apply setting, including legacy meta from the old plugin slug.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return string
	 */
	public static function get_auto_apply_value( $coupon ) {
		$value = $coupon->get_meta( self::META_KEY, true );

		if ( 'yes' === $value ) {
			return 'yes';
		}

		if ( 'yes' === $coupon->get_meta( self::LEGACY_META_KEY, true ) ) {
			return 'yes';
		}

		return 'no';
	}

	/**
	 * Read the first-month-only setting for subscription purchases.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return string
	 */
	public static function get_first_month_only_value( $coupon ) {
		return 'yes' === $coupon->get_meta( self::FIRST_MONTH_META_KEY, true ) ? 'yes' : 'no';
	}
}
