<?php
/**
 * Uninstall routine.
 *
 * @package Auto_Apply_Cart_Coupon
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'aacc_auto_apply_coupons' );
delete_transient( 'wc_auto_apply_coupons' );
