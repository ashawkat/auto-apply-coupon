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
	 * Whether we are currently inserting a giveaway line.
	 *
	 * @var bool
	 */
	private $adding_giveaway = false;

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
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_coupon_applied' ), 20 );

		// Keep WebToffee giveaways one-time: never inherit a Sublium Subscribe & Save plan.
		add_filter( 'sublium_wcs_exclude_product_from_plan_assignment', array( $this, 'exclude_giveaway_from_sublium_plan' ), 10, 4 );
		add_filter( 'woocommerce_add_to_cart_sold_individually_found_in_cart', array( $this, 'allow_giveaway_when_sold_individually' ), 10, 5 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'normalize_giveaway_cart_items' ), 20 );
	}

	/**
	 * After any coupon is applied, ensure its giveaway products are separate cart lines.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 */
	public function on_coupon_applied( $code ) {
		if ( $this->is_syncing || $this->should_skip_auto_apply() ) {
			return;
		}

		$this->maybe_add_webtoffee_giveaways( $code );
		$this->normalize_giveaway_cart_items( WC()->cart );
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
		if ( $this->is_syncing || $this->should_skip_auto_apply() || $this->adding_giveaway ) {
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
					// Coupon may already be applied from a prior AJAX request where
					// WebToffee Smart Coupons skipped giveaway add (is_admin() on admin-ajax).
					$this->maybe_add_webtoffee_giveaways( $code );
					continue;
				}

				if ( ! $this->coupon_is_valid_for_cart( $code ) ) {
					continue;
				}

				$this->apply_coupon_quietly( $code );
				$this->maybe_add_webtoffee_giveaways( $code );
			}
		}

		$this->normalize_giveaway_cart_items( WC()->cart );

		$this->is_syncing = false;
	}

	/**
	 * Exclude WebToffee / AACC giveaway lines from Sublium plan assignment.
	 *
	 * Without this, a free Circulation giveaway inherits Subscribe & Save and looks
	 * like the paid monthly Circulation instead of a one-time free gift.
	 *
	 * @param bool  $exclude         Whether to exclude.
	 * @param array $cart_item_data  Cart item data being added.
	 * @param int   $product_id      Product ID.
	 * @param int   $variation_id    Variation ID.
	 * @return bool
	 */
	public function exclude_giveaway_from_sublium_plan( $exclude, $cart_item_data, $product_id = 0, $variation_id = 0 ) {
		unset( $product_id, $variation_id );

		if ( $exclude || $this->adding_giveaway ) {
			return true;
		}

		if ( ! is_array( $cart_item_data ) ) {
			return $exclude;
		}

		if ( ! empty( $cart_item_data['aacc_giveaway_uid'] ) ) {
			return true;
		}

		if ( isset( $cart_item_data['free_product'] ) && 'wt_give_away_product' === $cart_item_data['free_product'] ) {
			return true;
		}

		if ( ! empty( $cart_item_data['free_gift_coupon'] ) ) {
			return true;
		}

		return $exclude;
	}

	/**
	 * Allow a giveaway to be added even when the product is sold individually
	 * and already present as a paid cart line.
	 *
	 * @param bool  $found_in_cart  Whether WC thinks the product is already in cart.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @param array $cart_item_data Cart item data.
	 * @param int   $cart_id        Generated cart id.
	 * @return bool
	 */
	public function allow_giveaway_when_sold_individually( $found_in_cart, $product_id, $variation_id, $cart_item_data, $cart_id = '' ) {
		unset( $product_id, $variation_id, $cart_id );

		if ( $this->adding_giveaway ) {
			return false;
		}

		if ( is_array( $cart_item_data ) && (
			! empty( $cart_item_data['aacc_giveaway_uid'] )
			|| ( isset( $cart_item_data['free_product'] ) && 'wt_give_away_product' === $cart_item_data['free_product'] )
		) ) {
			return false;
		}

		return $found_in_cart;
	}

	/**
	 * Keep giveaways as $0 one-time lines (no Sublium plan).
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return void
	 */
	public function normalize_giveaway_cart_items( $cart = null ) {
		if ( ! $cart instanceof WC_Cart ) {
			$cart = WC()->cart;
		}

		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! $this->is_giveaway_cart_item( $cart_item ) ) {
				continue;
			}

			// Force one-time: strip any Sublium plan Sublium may have attached.
			unset( $cart->cart_contents[ $cart_item_key ]['sublium_wcs_plan'] );
			unset( $cart->cart_contents[ $cart_item_key ]['sublium_wcs_plan_locked'] );
			unset( $cart->cart_contents[ $cart_item_key ]['sublium_wcs_plan_selected'] );
			unset( $cart->cart_contents[ $cart_item_key ]['sublium_wcs_plan_summary'] );
			unset( $cart->cart_contents[ $cart_item_key ]['sublium_available_plans'] );

			if ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'set_price' ) ) {
				$cart_item['data']->set_price( 0 );
				if ( method_exists( $cart_item['data'], 'delete_meta_data' ) ) {
					$cart_item['data']->delete_meta_data( 'sublium_wcs_plan' );
					$cart_item['data']->delete_meta_data( 'is_sublium_wcs_discount' );
				}
				$cart->cart_contents[ $cart_item_key ]['data'] = $cart_item['data'];
			}
		}
	}

	/**
	 * Ensure WebToffee Smart Coupons giveaway products are in the cart
	 * as separate lines from any paid copy of the same product.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 */
	private function maybe_add_webtoffee_giveaways( $code ) {
		if ( ! WC()->cart || ! WC()->cart->has_discount( $code ) ) {
			return;
		}

		$coupon = new WC_Coupon( $code );

		if ( ! $coupon->get_id() ) {
			return;
		}

		$product_ids = $this->get_webtoffee_giveaway_product_ids( $coupon->get_id() );

		if ( empty( $product_ids ) ) {
			return;
		}

		foreach ( $product_ids as $item_id ) {
			if ( $this->cart_has_webtoffee_giveaway( $code, $item_id ) ) {
				continue;
			}

			$this->add_webtoffee_giveaway_to_cart( $item_id, $code );
		}
	}

	/**
	 * Read giveaway product IDs from WebToffee Smart Coupons meta.
	 *
	 * @param int $coupon_id Coupon ID.
	 * @return array<int>
	 */
	private function get_webtoffee_giveaway_product_ids( $coupon_id ) {
		if ( class_exists( 'Wt_Smart_Coupon_Giveaway_Product_Common' ) && is_callable( array( 'Wt_Smart_Coupon_Giveaway_Product_Common', 'get_giveaway_products' ) ) ) {
			$ids = Wt_Smart_Coupon_Giveaway_Product_Common::get_giveaway_products( $coupon_id );
			if ( is_array( $ids ) && ! empty( $ids ) ) {
				return array_values( array_filter( array_map( 'absint', $ids ) ) );
			}
		}

		if ( class_exists( 'Wt_Smart_Coupon_Giveaway_Product_Public' ) && is_callable( array( 'Wt_Smart_Coupon_Giveaway_Product_Public', 'get_giveaway_products' ) ) ) {
			$ids = Wt_Smart_Coupon_Giveaway_Product_Public::get_giveaway_products( $coupon_id );
			if ( is_array( $ids ) && ! empty( $ids ) ) {
				return array_values( array_filter( array_map( 'absint', $ids ) ) );
			}
		}

		$meta = get_post_meta( $coupon_id, '_wt_free_product_ids', true );

		if ( empty( $meta ) ) {
			return array();
		}

		if ( is_array( $meta ) ) {
			return array_values( array_filter( array_map( 'absint', $meta ) ) );
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', (string) $meta ) ) ) );
	}

	/**
	 * Whether the cart already has this WebToffee giveaway for the coupon.
	 *
	 * Only counts lines marked as giveaways — a paid Subscribe & Save Circulation
	 * does not satisfy the free gift.
	 *
	 * @param string $code    Coupon code.
	 * @param int    $item_id Product or variation ID.
	 * @return bool
	 */
	private function cart_has_webtoffee_giveaway( $code, $item_id ) {
		$code    = wc_format_coupon_code( $code );
		$item_id = absint( $item_id );

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! $this->is_giveaway_cart_item( $cart_item, $code ) ) {
				continue;
			}

			$cart_product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			$cart_variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

			if ( $item_id === $cart_product_id || $item_id === $cart_variation_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a cart item is a WebToffee / AACC giveaway line.
	 *
	 * @param array       $cart_item Cart item.
	 * @param string|null $code      Optional coupon code to match.
	 * @return bool
	 */
	private function is_giveaway_cart_item( $cart_item, $code = null ) {
		if ( empty( $cart_item['free_gift_coupon'] ) || empty( $cart_item['free_product'] ) ) {
			return false;
		}

		if ( 'wt_give_away_product' !== $cart_item['free_product'] ) {
			return false;
		}

		if ( null !== $code && wc_format_coupon_code( $cart_item['free_gift_coupon'] ) !== wc_format_coupon_code( $code ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add a WebToffee giveaway product with the cart markers WT expects.
	 *
	 * Always creates a distinct cart line from any paid copy of the same product.
	 *
	 * @param int    $item_id Product or variation ID.
	 * @param string $code    Coupon code.
	 * @return void
	 */
	private function add_webtoffee_giveaway_to_cart( $item_id, $code ) {
		$product = wc_get_product( $item_id );

		if ( ! $product || ! $product->is_purchasable() ) {
			return;
		}

		// Variable parents cannot be added; customer must pick a variation.
		if ( $product->is_type( 'variable' ) ) {
			return;
		}

		$quantity     = 1;
		$product_id   = $item_id;
		$variation_id = 0;
		$variation    = array();

		if ( $product->is_type( 'variation' ) ) {
			$variation_id = $item_id;
			$product_id   = $product->get_parent_id();
			$variation    = $product->get_variation_attributes();

			foreach ( $variation as $attribute_value ) {
				if ( '' === $attribute_value ) {
					return;
				}
			}
		}

		if ( ! $product->has_enough_stock( $quantity ) ) {
			$quantity = (int) $product->get_stock_quantity();
			if ( $quantity < 1 ) {
				return;
			}
		}

		$cart_item_data = array(
			'free_product'      => 'wt_give_away_product',
			'free_gift_coupon'  => wc_format_coupon_code( $code ),
			'free_category'     => '',
			// Unique key so WooCommerce never merges this with the paid Circulation line.
			'aacc_giveaway_uid' => uniqid( 'aacc_gw_', true ),
		);

		/**
		 * Allow other code (including WT itself) to alter giveaway cart item data.
		 *
		 * @param array $cart_item_data Cart item data.
		 * @param int   $product_id     Product ID.
		 * @param int   $variation_id   Variation ID.
		 * @param int   $quantity       Quantity.
		 */
		$cart_item_data = apply_filters( 'wt_sc_alter_giveaway_cart_item_data_before_add_to_cart', $cart_item_data, $product_id, $variation_id, $quantity );

		// Re-assert markers after filters.
		$cart_item_data['free_product']     = 'wt_give_away_product';
		$cart_item_data['free_gift_coupon'] = wc_format_coupon_code( $code );
		if ( empty( $cart_item_data['aacc_giveaway_uid'] ) ) {
			$cart_item_data['aacc_giveaway_uid'] = uniqid( 'aacc_gw_', true );
		}

		$this->adding_giveaway = true;

		try {
			WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation, $cart_item_data );
		} finally {
			$this->adding_giveaway = false;
		}
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
