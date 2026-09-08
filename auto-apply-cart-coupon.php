<?php
/**
 * Plugin Name:       Auto Apply Cart Coupon
 * Plugin URI:        https://betatech.co/
 * Description:       Adds an option to coupons to automatically apply them to the cart when an item is added.
 * Version:           1.2.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Betatech
 * Author URI:        https://betatech.co/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       auto-apply-cart-coupon
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.6
 *
 * @package Auto_Apply_Cart_Coupon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declare WooCommerce HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

define( 'AACC_VERSION', '1.2.4' );
define( 'AACC_FILE', __FILE__ );
define( 'AACC_PATH', plugin_dir_path( __FILE__ ) );
define( 'AACC_URL', plugin_dir_url( __FILE__ ) );
define( 'AACC_BASENAME', plugin_basename( __FILE__ ) );

require_once AACC_PATH . 'includes/class-auto-apply-cart-coupon.php';

/**
 * Returns the main plugin instance.
 *
 * @return Auto_Apply_Cart_Coupon
 */
function auto_apply_cart_coupon() {
	return Auto_Apply_Cart_Coupon::instance();
}

auto_apply_cart_coupon();
