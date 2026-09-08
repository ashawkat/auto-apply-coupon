<?php
/**
 * Sublium Subscriptions integration for first-month-only coupons.
 *
 * Keeps Smart Coupons / free-gift line items on the parent order only,
 * and prevents them from becoming recurring Sublium subscription items.
 *
 * @package Auto_Apply_Cart_Coupon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Apply_Cart_Coupon_Sublium
 */
class Auto_Apply_Cart_Coupon_Sublium {

	/**
	 * Plugin instance.
	 *
	 * @var Auto_Apply_Cart_Coupon_Sublium|null
	 */
	private static $instance = null;

	/**
	 * Cart/order item meta keys that mark a free gift / giveaway product.
	 *
	 * @var array<string>
	 */
	private $gift_meta_keys = array(
		'_fkcart_free_gift',
		'_tikva_free_gift',
		'free_gift',
		'free_gift_coupon',
		'free_product',
		'wc_sc_product_source',
		'wc_sc_free_product',
		'_wc_sc_free_product',
		'discounted_price',
	);

	/**
	 * Get plugin instance.
	 *
	 * @return Auto_Apply_Cart_Coupon_Sublium
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
		// Keep giveaways off Sublium plans in the cart / order items.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'strip_plan_from_first_month_gifts' ), 99 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'strip_plan_from_order_line_item' ), 99, 4 );

		// Prevent Sublium from assigning a plan when giveaways are added to the cart.
		add_filter( 'sublium_wcs_exclude_product_from_plan_assignment', array( $this, 'exclude_gifts_from_plan_assignment' ), 10, 4 );

		// Keep first-month giveaways out of Sublium recurring carts (checkout "Renewal Price").
		add_filter( 'sublium_wcs_subscription_groups', array( $this, 'exclude_gifts_from_subscription_groups' ), 20 );

		// Use the cart line unit price for Sublium recurring totals (not raw variation price).
		add_filter( 'sublium_wcs_subscription_price', array( $this, 'sync_recurring_price_to_cart' ), 20, 4 );
		add_filter( 'sublium_wcs_woocommerce_cart_item_total', array( $this, 'filter_recurring_total_display' ), 20, 2 );

		// After Sublium creates a subscription, remove leftover free gifts.
		add_action( 'sublium_wcs_subscription_created', array( $this, 'on_subscription_created' ), 20, 1 );
		add_filter( 'sublium_wcs_subscription_created', array( $this, 'filter_subscription_created' ), 20, 1 );

		// Safety net once the parent order is fully processed.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'cleanup_order_subscriptions' ), 50, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'cleanup_order_subscriptions' ), 50, 1 );

		// If a renewal somehow still contains a first-month gift, strip it.
		add_action( 'sublium_wcs_subscription_renewal_payment_complete', array( $this, 'on_renewal_payment_complete' ), 20, 2 );
	}

	/**
	 * Remove Sublium plan data from free-gift cart items when a first-month-only
	 * coupon is applied, so Sublium does not attach them to the subscription.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public function strip_plan_from_first_month_gifts( $cart ) {
		if ( ! $cart || ! $this->cart_has_first_month_only_coupon() ) {
			return;
		}

		$gift_product_ids = $this->get_first_month_giveaway_product_ids_from_cart();
		$stripped         = false;

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! $this->is_first_month_gift_cart_item( $cart_item, $gift_product_ids ) ) {
				continue;
			}

			unset( WC()->cart->cart_contents[ $cart_item_key ]['sublium_wcs_plan'] );
			unset( WC()->cart->cart_contents[ $cart_item_key ]['_sublium_wcs_plan'] );
			unset( WC()->cart->cart_contents[ $cart_item_key ]['_sublium_data'] );
			unset( WC()->cart->cart_contents[ $cart_item_key ]['sublium_wcs_plan_summary'] );
			unset( WC()->cart->cart_contents[ $cart_item_key ]['sublium_wcs_plan_selected'] );

			if ( isset( WC()->cart->cart_contents[ $cart_item_key ]['variation'] ) && is_array( WC()->cart->cart_contents[ $cart_item_key ]['variation'] ) ) {
				unset( WC()->cart->cart_contents[ $cart_item_key ]['variation']['_sublium_data'] );
				unset( WC()->cart->cart_contents[ $cart_item_key ]['variation']['sublium_wcs_plan'] );
			}

			if ( isset( WC()->cart->cart_contents[ $cart_item_key ]['data'] ) && is_object( WC()->cart->cart_contents[ $cart_item_key ]['data'] ) ) {
				$product = WC()->cart->cart_contents[ $cart_item_key ]['data'];
				if ( method_exists( $product, 'delete_meta_data' ) ) {
					$product->delete_meta_data( 'sublium_wcs_plan' );
					$product->delete_meta_data( '_sublium_wcs_plan' );
					$product->delete_meta_data( '_sublium_data' );
				}
			}

			$stripped = true;
		}

		if ( $stripped ) {
			$this->clear_sublium_recurring_carts_cache();
		}
	}

	/**
	 * Exclude free-gift / giveaway products from Sublium plan assignment on add-to-cart.
	 *
	 * @param bool  $exclude        Whether to exclude.
	 * @param array $cart_item_data Cart item data being added.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return bool
	 */
	public function exclude_gifts_from_plan_assignment( $exclude, $cart_item_data, $product_id, $variation_id ) {
		if ( $exclude ) {
			return $exclude;
		}

		if ( ! is_array( $cart_item_data ) ) {
			$cart_item_data = array();
		}

		foreach ( $this->gift_meta_keys as $key ) {
			if ( ! empty( $cart_item_data[ $key ] ) ) {
				return true;
			}
		}

		if ( ! $this->cart_has_first_month_only_coupon() ) {
			return $exclude;
		}

		$gift_product_ids = $this->get_first_month_giveaway_product_ids_from_cart();

		if ( $this->product_id_in_list( (int) $product_id, (int) $variation_id, $gift_product_ids ) ) {
			return true;
		}

		return $exclude;
	}

	/**
	 * When Sublium builds recurring cart totals, reuse the main cart unit price.
	 *
	 * Sublium recalculates renewals from the product/variation price. That can ignore
	 * the Subscribe & Save / volume price already reflected on the cart line, so checkout
	 * shows the higher variation total. Prefer line_subtotal (before coupons) so
	 * first-month-only coupons still do not leak into renewals.
	 *
	 * @param float       $price             Calculated unit price.
	 * @param WC_Product  $product           Product object.
	 * @param mixed       $plan              Plan object (or calculation context).
	 * @param string      $calculation_type  Sublium calculation type.
	 * @return float
	 */
	public function sync_recurring_price_to_cart( $price, $product, $plan = null, $calculation_type = 'none' ) {
		unset( $plan );

		if ( 'recurring_total' !== $calculation_type ) {
			return $price;
		}

		if ( ! $product instanceof WC_Product || ! WC()->cart ) {
			return $price;
		}

		$cart_unit_price = $this->get_main_cart_unit_price_for_product( $product );

		if ( null === $cart_unit_price || $cart_unit_price < 0 ) {
			return $price;
		}

		return (float) $cart_unit_price;
	}

	/**
	 * Fallback: rewrite the checkout "Renewal Price" HTML from main-cart plan lines.
	 *
	 * @param string  $price_html     Formatted price HTML.
	 * @param WC_Cart $recurring_cart Recurring cart object.
	 * @return string
	 */
	public function filter_recurring_total_display( $price_html, $recurring_cart ) {
		if ( ! $recurring_cart || ! is_object( $recurring_cart ) || ! method_exists( $recurring_cart, 'get_cart' ) ) {
			return $price_html;
		}

		$total = 0.0;
		$found = false;

		foreach ( $recurring_cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			$unit = $this->get_main_cart_unit_price_for_product( $cart_item['data'] );

			if ( null === $unit ) {
				// Fall back to this recurring cart line if we cannot map it.
				if ( isset( $cart_item['line_subtotal'] ) ) {
					$total += (float) $cart_item['line_subtotal'];
					$found  = true;
				}
				continue;
			}

			$qty    = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;
			$total += $unit * $qty;
			$found  = true;
		}

		if ( ! $found ) {
			return $price_html;
		}

		return wc_price( $total );
	}

	/**
	 * Get the main cart's per-unit line_subtotal for a product (ex-tax, before coupons).
	 *
	 * @param WC_Product $product Product object.
	 * @return float|null
	 */
	private function get_main_cart_unit_price_for_product( $product ) {
		if ( ! WC()->cart || ! $product instanceof WC_Product ) {
			return null;
		}

		$product_id   = (int) $product->get_id();
		$parent_id    = (int) $product->get_parent_id();
		$match_ids    = array_filter( array( $product_id, $parent_id ) );

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['sublium_wcs_plan'] ) ) {
				continue;
			}

			if ( $this->is_first_month_gift_cart_item( $cart_item, $this->get_first_month_giveaway_product_ids_from_cart() ) ) {
				continue;
			}

			$item_product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$item_variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			$data_id           = ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) ? (int) $cart_item['data']->get_id() : 0;

			$ids = array_filter( array( $item_product_id, $item_variation_id, $data_id ) );

			if ( empty( array_intersect( $match_ids, $ids ) ) ) {
				continue;
			}

			$qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;
			if ( $qty <= 0 || ! isset( $cart_item['line_subtotal'] ) ) {
				continue;
			}

			return (float) $cart_item['line_subtotal'] / $qty;
		}

		return null;
	}

	/**
	 * Remove first-month free gifts from Sublium recurring cart groups.
	 *
	 * This is what drives the checkout "Subscribe & Save → Renewal Price" total.
	 *
	 * @param array $subscription_groups Cart item keys grouped by recurring key.
	 * @return array
	 */
	public function exclude_gifts_from_subscription_groups( $subscription_groups ) {
		if ( ! is_array( $subscription_groups ) || empty( $subscription_groups ) || ! WC()->cart ) {
			return $subscription_groups;
		}

		if ( ! $this->cart_has_first_month_only_coupon() ) {
			return $subscription_groups;
		}

		$gift_product_ids = $this->get_first_month_giveaway_product_ids_from_cart();
		$cart             = WC()->cart->get_cart();

		foreach ( $subscription_groups as $recurring_key => $cart_keys ) {
			if ( ! is_array( $cart_keys ) ) {
				continue;
			}

			$filtered = array();

			foreach ( $cart_keys as $cart_item_key ) {
				if ( ! isset( $cart[ $cart_item_key ] ) ) {
					continue;
				}

				if ( $this->is_first_month_gift_cart_item( $cart[ $cart_item_key ], $gift_product_ids ) ) {
					continue;
				}

				$filtered[] = $cart_item_key;
			}

			if ( empty( $filtered ) ) {
				unset( $subscription_groups[ $recurring_key ] );
			} else {
				$subscription_groups[ $recurring_key ] = $filtered;
			}
		}

		return $subscription_groups;
	}

	/**
	 * Clear Sublium's in-request recurring cart cache after we change plan data.
	 *
	 * @return void
	 */
	private function clear_sublium_recurring_carts_cache() {
		if ( class_exists( '\Sublium_WCS\Includes\Main\Cart' ) && is_callable( array( '\Sublium_WCS\Includes\Main\Cart', 'clear_recurring_carts_cache' ) ) ) {
			\Sublium_WCS\Includes\Main\Cart::clear_recurring_carts_cache();
		}
	}

	/**
	 * Strip Sublium plan meta from free-gift order line items at checkout.
	 *
	 * @param WC_Order_Item_Product $item          Order line item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order object.
	 * @return void
	 */
	public function strip_plan_from_order_line_item( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key );

		// Coupons may not be copied onto the order yet during line-item creation.
		if ( ! $this->cart_has_first_month_only_coupon() && ! ( $order instanceof WC_Order && $this->order_has_first_month_only_coupon( $order ) ) ) {
			return;
		}

		$gift_product_ids = $this->get_first_month_giveaway_product_ids_from_cart();

		if ( ! $this->is_first_month_gift_cart_item( $values, $gift_product_ids ) && ! $this->is_free_gift_order_item( $item ) ) {
			return;
		}

		$item->delete_meta_data( 'sublium_wcs_plan' );
		$item->delete_meta_data( '_sublium_wcs_plan' );
		$item->delete_meta_data( '_sublium_wcs_plan_summary' );
		$item->delete_meta_data( '_sublium_data' );
		$item->delete_meta_data( '_sublium_wcs_plan_data' );
	}

	/**
	 * Handle Sublium subscription created action.
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @return void
	 */
	public function on_subscription_created( $subscription ) {
		$this->remove_first_month_gifts_from_subscription( $subscription );
	}

	/**
	 * Handle Sublium subscription created filter (passes subscription through).
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @return mixed
	 */
	public function filter_subscription_created( $subscription ) {
		$this->remove_first_month_gifts_from_subscription( $subscription );
		return $subscription;
	}

	/**
	 * Cleanup subscriptions linked to a freshly placed order.
	 *
	 * @param int|WC_Order $order Order ID or object.
	 * @return void
	 */
	public function cleanup_order_subscriptions( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order ) {
			return;
		}

		if ( ! $this->order_has_first_month_only_coupon( $order ) ) {
			return;
		}

		foreach ( $this->get_subscription_ids_for_order( $order ) as $subscription_id ) {
			$this->remove_first_month_gifts_from_subscription( $subscription_id );
		}
	}

	/**
	 * After a renewal payment, remove any first-month gifts that reappeared.
	 *
	 * @param mixed        $subscription Subscription object or ID.
	 * @param WC_Order|null $order       Renewal order.
	 * @return void
	 */
	public function on_renewal_payment_complete( $subscription, $order = null ) {
		$this->remove_first_month_gifts_from_subscription( $subscription );

		if ( $order instanceof WC_Order && $this->order_has_first_month_only_coupon( $order ) ) {
			$this->remove_first_month_gifts_from_order( $order );
		}
	}

	/**
	 * Remove free-gift items from a Sublium subscription when they came from
	 * a first-month-only coupon on the parent order.
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @return void
	 */
	private function remove_first_month_gifts_from_subscription( $subscription ) {
		$subscription = $this->normalize_subscription( $subscription );

		if ( ! $subscription ) {
			return;
		}

		$parent_order = $this->get_parent_order_from_subscription( $subscription );

		if ( ! $parent_order || ! $this->order_has_first_month_only_coupon( $parent_order ) ) {
			return;
		}

		$gift_product_ids = $this->get_first_month_giveaway_product_ids_from_order( $parent_order );
		$removed          = false;

		$items = $this->get_subscription_items( $subscription );

		if ( empty( $items ) ) {
			return;
		}

		$remaining_item_ids = array();

		foreach ( $items as $item_id => $item ) {
			if ( $this->is_first_month_gift_subscription_item( $item, $gift_product_ids ) ) {
				if ( $this->remove_subscription_item( $subscription, $item_id, $item ) ) {
					$removed = true;
					continue;
				}
			}

			$product_id = $this->get_item_product_id( $item );
			if ( $product_id ) {
				$remaining_item_ids[] = $product_id;
			}
		}

		if ( ! $removed ) {
			return;
		}

		if ( method_exists( $subscription, 'update_items' ) && ! empty( $remaining_item_ids ) ) {
			$subscription->update_items( array_values( array_unique( $remaining_item_ids ) ) );
		}

		if ( method_exists( $subscription, 'calculate_totals' ) ) {
			$subscription->calculate_totals();
		}

		if ( method_exists( $subscription, 'save' ) ) {
			$subscription->save();
		}

		if ( method_exists( $subscription, 'add_note' ) ) {
			$subscription->add_note(
				__( 'First-month-only free gift removed from Sublium subscription (kept on initial order only).', 'auto-apply-cart-coupon' )
			);
		} elseif ( $parent_order ) {
			$parent_order->add_order_note(
				sprintf(
					/* translators: %d: subscription ID */
					__( 'First-month-only free gift removed from Sublium subscription #%d (kept on initial order only).', 'auto-apply-cart-coupon' ),
					method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0
				)
			);
		}
	}

	/**
	 * Remove first-month gift products from a renewal order.
	 *
	 * @param WC_Order $order Renewal order.
	 * @return void
	 */
	private function remove_first_month_gifts_from_order( $order ) {
		$gift_product_ids = $this->get_first_month_giveaway_product_ids_from_order( $order );
		$removed          = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $this->is_free_gift_order_item( $item ) && ! $this->product_id_in_list( $item->get_product_id(), $item->get_variation_id(), $gift_product_ids ) ) {
				continue;
			}

			// Only strip $0 gift lines so paid products are never removed.
			if ( (float) $item->get_total() > 0 ) {
				continue;
			}

			$order->remove_item( $item_id );
			$removed = true;
		}

		if ( $removed ) {
			$order->calculate_totals( false );
			$order->save();
		}
	}

	/**
	 * Whether the current cart has a first-month-only coupon applied.
	 *
	 * @return bool
	 */
	private function cart_has_first_month_only_coupon() {
		if ( ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( $this->is_first_month_only_code( $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an order has a first-month-only coupon.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function order_has_first_month_only_coupon( $order ) {
		foreach ( $order->get_coupon_codes() as $code ) {
			if ( $this->is_first_month_only_code( $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a coupon code is marked first-month-only.
	 *
	 * @param string $code Coupon code.
	 * @return bool
	 */
	private function is_first_month_only_code( $code ) {
		$coupon = new WC_Coupon( $code );
		return 'yes' === Auto_Apply_Cart_Coupon::get_first_month_only_value( $coupon );
	}

	/**
	 * Collect giveaway product IDs configured on first-month-only coupons in the cart.
	 *
	 * @return array<int>
	 */
	private function get_first_month_giveaway_product_ids_from_cart() {
		if ( ! WC()->cart ) {
			return array();
		}

		$ids = array();

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( ! $this->is_first_month_only_code( $code ) ) {
				continue;
			}
			$ids = array_merge( $ids, $this->get_coupon_giveaway_product_ids( new WC_Coupon( $code ) ) );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Collect giveaway product IDs from first-month-only coupons on an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int>
	 */
	private function get_first_month_giveaway_product_ids_from_order( $order ) {
		$ids = array();

		foreach ( $order->get_coupon_codes() as $code ) {
			if ( ! $this->is_first_month_only_code( $code ) ) {
				continue;
			}
			$ids = array_merge( $ids, $this->get_coupon_giveaway_product_ids( new WC_Coupon( $code ) ) );
		}

		// Also include $0 gift lines already on the parent order.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $this->is_free_gift_order_item( $item ) || ( (float) $item->get_total() <= 0 && $this->order_item_has_gift_meta( $item ) ) ) {
				$ids[] = $item->get_product_id();
				if ( $item->get_variation_id() ) {
					$ids[] = $item->get_variation_id();
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Read giveaway / free product IDs stored on a coupon by Smart Coupons and similar plugins.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return array<int>
	 */
	private function get_coupon_giveaway_product_ids( $coupon ) {
		$ids  = array();
		$keys = array(
			'wc_sc_add_product_details',
			'_wc_sc_add_product_details',
			'free_gift_ids',
			'_free_gift_ids',
			'gift_ids',
			'_wc_free_gift_coupon',
			'wc_free_products',
			'_wc_free_products',
			'_wt_free_product_ids',
			'wt_free_product_ids',
		);

		foreach ( $keys as $key ) {
			$value = $coupon->get_meta( $key, true );

			if ( empty( $value ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$maybe = maybe_unserialize( $value );
				$value = false !== $maybe ? $maybe : $value;
			}

			if ( is_numeric( $value ) ) {
				$ids[] = (int) $value;
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $entry ) {
				if ( is_numeric( $entry ) ) {
					$ids[] = (int) $entry;
				} elseif ( is_array( $entry ) ) {
					if ( isset( $entry['product_id'] ) ) {
						$ids[] = (int) $entry['product_id'];
					}
					if ( isset( $entry['id'] ) ) {
						$ids[] = (int) $entry['id'];
					}
					if ( isset( $entry['variation_id'] ) ) {
						$ids[] = (int) $entry['variation_id'];
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether a cart item is a first-month free gift.
	 *
	 * @param array     $cart_item        Cart item.
	 * @param array<int> $gift_product_ids Known giveaway product IDs.
	 * @return bool
	 */
	private function is_first_month_gift_cart_item( $cart_item, $gift_product_ids ) {
		// WebToffee giveaway marker (even when other gift keys are absent).
		if ( isset( $cart_item['free_product'] ) && 'wt_give_away_product' === $cart_item['free_product'] ) {
			return true;
		}

		foreach ( $this->gift_meta_keys as $key ) {
			if ( ! empty( $cart_item[ $key ] ) ) {
				return true;
			}
		}

		$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;

		if ( $this->product_id_in_list( $product_id, $variation_id, $gift_product_ids ) ) {
			return true;
		}

		// $0 line that already carries a Sublium plan is almost certainly a giveaway
		// incorrectly attached to the subscription.
		$line_total = 0;
		if ( isset( $cart_item['line_total'] ) ) {
			$line_total = (float) $cart_item['line_total'];
		} elseif ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_price' ) ) {
			$qty        = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;
			$line_total = (float) $cart_item['data']->get_price() * $qty;
		}

		$has_plan = ! empty( $cart_item['sublium_wcs_plan'] ) || ! empty( $cart_item['_sublium_wcs_plan'] ) || ! empty( $cart_item['_sublium_data'] );

		return $has_plan && $line_total <= 0;
	}

	/**
	 * Whether an order item looks like a free gift.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	private function is_free_gift_order_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}

		if ( $this->order_item_has_gift_meta( $item ) ) {
			return true;
		}

		return (float) $item->get_total() <= 0 && (
			$item->get_meta( 'sublium_wcs_plan', true ) ||
			$item->get_meta( '_sublium_wcs_plan', true )
		);
	}

	/**
	 * Whether an order item has gift meta.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	private function order_item_has_gift_meta( $item ) {
		foreach ( $this->gift_meta_keys as $key ) {
			$value = $item->get_meta( $key, true );
			if ( ! empty( $value ) && 'no' !== $value && '0' !== (string) $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a subscription item is a first-month free gift.
	 *
	 * @param mixed     $item             Subscription item (array or object).
	 * @param array<int> $gift_product_ids Known giveaway IDs.
	 * @return bool
	 */
	private function is_first_month_gift_subscription_item( $item, $gift_product_ids ) {
		$product_id   = $this->get_item_product_id( $item );
		$variation_id = $this->get_item_variation_id( $item );

		if ( $this->product_id_in_list( $product_id, $variation_id, $gift_product_ids ) ) {
			return true;
		}

		return $this->subscription_item_has_gift_meta( $item );
	}

	/**
	 * Whether a subscription item carries gift meta.
	 *
	 * @param mixed $item Subscription item.
	 * @return bool
	 */
	private function subscription_item_has_gift_meta( $item ) {
		$meta = array();

		if ( is_array( $item ) ) {
			if ( isset( $item['item_data']['meta_data'] ) && is_array( $item['item_data']['meta_data'] ) ) {
				$meta = $item['item_data']['meta_data'];
			} elseif ( isset( $item['meta_data'] ) && is_array( $item['meta_data'] ) ) {
				$meta = $item['meta_data'];
			} else {
				foreach ( $this->gift_meta_keys as $key ) {
					if ( ! empty( $item[ $key ] ) ) {
						return true;
					}
				}
			}
		} elseif ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
			foreach ( $this->gift_meta_keys as $key ) {
				$value = $item->get_meta( $key, true );
				if ( ! empty( $value ) && 'no' !== $value ) {
					return true;
				}
			}
			return false;
		}

		foreach ( $meta as $key => $value ) {
			$meta_key = is_array( $value ) && isset( $value['key'] ) ? $value['key'] : $key;
			$meta_val = is_array( $value ) && isset( $value['value'] ) ? $value['value'] : $value;
			if ( in_array( $meta_key, $this->gift_meta_keys, true ) && ! empty( $meta_val ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int       $product_id   Product ID.
	 * @param int       $variation_id Variation ID.
	 * @param array<int> $list         ID list.
	 * @return bool
	 */
	private function product_id_in_list( $product_id, $variation_id, $list ) {
		if ( empty( $list ) ) {
			return false;
		}

		return in_array( (int) $product_id, $list, true ) || ( $variation_id && in_array( (int) $variation_id, $list, true ) );
	}

	/**
	 * Normalize a subscription reference to an object.
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @return object|null
	 */
	private function normalize_subscription( $subscription ) {
		if ( is_object( $subscription ) ) {
			return $subscription;
		}

		$id = absint( $subscription );
		if ( ! $id ) {
			return null;
		}

		if ( function_exists( 'sublium_get_subscription' ) ) {
			$object = sublium_get_subscription( $id );
			if ( $object ) {
				return $object;
			}
		}

		if ( class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			try {
				return new \Sublium_WCS\Includes\Controller\Subscriptions\Subscription( $id );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through.
			}
		}

		return null;
	}

	/**
	 * Get parent order for a Sublium subscription.
	 *
	 * @param object $subscription Subscription object.
	 * @return WC_Order|null
	 */
	private function get_parent_order_from_subscription( $subscription ) {
		$parent_id = 0;

		if ( method_exists( $subscription, 'get_parent_order_id' ) ) {
			$parent_id = (int) $subscription->get_parent_order_id();
		} elseif ( method_exists( $subscription, 'get_parent_id' ) ) {
			$parent_id = (int) $subscription->get_parent_id();
		} elseif ( method_exists( $subscription, 'get_meta' ) ) {
			$parent_id = (int) $subscription->get_meta( 'parent_order_id' );
		} elseif ( is_array( $subscription ) && isset( $subscription['parent_order_id'] ) ) {
			$parent_id = (int) $subscription['parent_order_id'];
		}

		return $parent_id ? wc_get_order( $parent_id ) : null;
	}

	/**
	 * Get subscription IDs linked to an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int>
	 */
	private function get_subscription_ids_for_order( $order ) {
		$ids = array();

		$parent_meta = $order->get_meta( '_sublium_wcs_parent_order', true );
		if ( ! empty( $parent_meta ) ) {
			$decoded = is_array( $parent_meta ) ? $parent_meta : json_decode( $parent_meta, true );
			if ( is_array( $decoded ) ) {
				$ids = array_merge( $ids, $decoded );
			}
		}

		foreach ( $order->get_meta( '_sublium_wcs_subscription_id', false ) as $meta ) {
			if ( is_object( $meta ) && isset( $meta->value ) ) {
				$ids[] = $meta->value;
			} elseif ( is_numeric( $meta ) ) {
				$ids[] = $meta;
			}
		}

		$single = $order->get_meta( '_sublium_wcs_subscription_id', true );
		if ( $single ) {
			$ids[] = $single;
		}

		$legacy = $order->get_meta( '_sublium_subscription_id', true );
		if ( $legacy ) {
			$ids[] = $legacy;
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Get items from a Sublium subscription object.
	 *
	 * @param object $subscription Subscription object.
	 * @return array
	 */
	private function get_subscription_items( $subscription ) {
		if ( method_exists( $subscription, 'get_items' ) ) {
			$items = $subscription->get_items();
			return is_array( $items ) ? $items : array();
		}

		if ( method_exists( $subscription, 'get_line_items' ) ) {
			$items = $subscription->get_line_items();
			return is_array( $items ) ? $items : array();
		}

		return array();
	}

	/**
	 * Remove one item from a Sublium subscription.
	 *
	 * @param object $subscription Subscription object.
	 * @param mixed  $item_id      Item ID / key.
	 * @param mixed  $item         Item data.
	 * @return bool
	 */
	private function remove_subscription_item( $subscription, $item_id, $item ) {
		unset( $item );

		if ( method_exists( $subscription, 'remove_item' ) ) {
			$subscription->remove_item( $item_id );
			return true;
		}

		if ( method_exists( $subscription, 'delete_item' ) ) {
			$subscription->delete_item( $item_id );
			return true;
		}

		return false;
	}

	/**
	 * @param mixed $item Subscription item.
	 * @return int
	 */
	private function get_item_product_id( $item ) {
		if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
			return (int) $item->get_product_id();
		}

		if ( is_array( $item ) ) {
			if ( isset( $item['item_data']['product_id'] ) ) {
				return (int) $item['item_data']['product_id'];
			}
			if ( isset( $item['product_id'] ) ) {
				return (int) $item['product_id'];
			}
		}

		return 0;
	}

	/**
	 * @param mixed $item Subscription item.
	 * @return int
	 */
	private function get_item_variation_id( $item ) {
		if ( is_object( $item ) && method_exists( $item, 'get_variation_id' ) ) {
			return (int) $item->get_variation_id();
		}

		if ( is_array( $item ) ) {
			if ( isset( $item['item_data']['variation_id'] ) ) {
				return (int) $item['item_data']['variation_id'];
			}
			if ( isset( $item['variation_id'] ) ) {
				return (int) $item['variation_id'];
			}
		}

		return 0;
	}
}
