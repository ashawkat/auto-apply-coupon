<?php
/**
 * Sublium Subscriptions integration for first-month-only coupons.
 *
 * Does NOT touch cart/checkout plan assignment, recurring groups, or renewal
 * pricing — those interventions blocked Subscribe & Save subscription creation.
 *
 * After a subscription exists, removes only explicit free-gift / giveaway lines
 * (WebToffee / FunnelKit markers) that are $0, so paid plan items are never removed.
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
	 * Explicit free-gift cart/order meta keys (strict — no broad keys).
	 *
	 * @var array<string>
	 */
	private $gift_meta_keys = array(
		'_fkcart_free_gift',
		'_tikva_free_gift',
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
		// Post-creation only — never interfere with Sublium plan assignment or recurring carts.
		add_action( 'sublium_wcs_subscription_created', array( $this, 'on_subscription_created' ), 20, 1 );
		add_filter( 'sublium_wcs_subscription_created', array( $this, 'filter_subscription_created' ), 20, 1 );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'cleanup_order_subscriptions' ), 50, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'cleanup_order_subscriptions' ), 50, 1 );

		add_action( 'sublium_wcs_subscription_renewal_payment_complete', array( $this, 'on_renewal_payment_complete' ), 20, 2 );
	}

	/**
	 * Handle Sublium subscription created action.
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @return void
	 */
	public function on_subscription_created( $subscription ) {
		$this->safe_remove_gifts_from_subscription( $subscription );
	}

	/**
	 * Handle Sublium subscription created filter (always pass subscription through).
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @return mixed
	 */
	public function filter_subscription_created( $subscription ) {
		$this->safe_remove_gifts_from_subscription( $subscription );
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

		if ( ! $order || ! $this->order_has_first_month_only_coupon( $order ) ) {
			return;
		}

		foreach ( $this->get_subscription_ids_for_order( $order ) as $subscription_id ) {
			$this->safe_remove_gifts_from_subscription( $subscription_id );
		}
	}

	/**
	 * After a renewal payment, remove any first-month gifts that reappeared.
	 *
	 * @param mixed         $subscription Subscription object or ID.
	 * @param WC_Order|null $order        Renewal order.
	 * @return void
	 */
	public function on_renewal_payment_complete( $subscription, $order = null ) {
		$this->safe_remove_gifts_from_subscription( $subscription );

		if ( $order instanceof WC_Order && $this->order_has_first_month_only_coupon( $order ) ) {
			$this->remove_explicit_gifts_from_order( $order );
		}
	}

	/**
	 * Safely remove explicit $0 gifts from a subscription without breaking creation.
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @return void
	 */
	private function safe_remove_gifts_from_subscription( $subscription ) {
		try {
			$this->remove_first_month_gifts_from_subscription( $subscription );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never interrupt Sublium subscription creation/processing.
		} catch ( Error $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never interrupt Sublium subscription creation/processing.
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

		$items = $this->get_subscription_items( $subscription );

		if ( empty( $items ) ) {
			return;
		}

		$removed            = false;
		$remaining_item_ids = array();

		foreach ( $items as $item_id => $item ) {
			if ( $this->is_explicit_free_gift_item( $item ) ) {
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

		// Never leave the subscription with zero items.
		if ( empty( $remaining_item_ids ) ) {
			return;
		}

		if ( method_exists( $subscription, 'update_items' ) ) {
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
		}
	}

	/**
	 * Remove explicit $0 gift products from a renewal order.
	 *
	 * @param WC_Order $order Renewal order.
	 * @return void
	 */
	private function remove_explicit_gifts_from_order( $order ) {
		$removed = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $this->is_explicit_free_gift_order_item( $item ) ) {
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
	 * Whether an order has a first-month-only coupon.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function order_has_first_month_only_coupon( $order ) {
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( 'yes' === Auto_Apply_Cart_Coupon::get_first_month_only_value( $coupon ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Explicit giveaway only: WebToffee / FunnelKit markers, and must be $0.
	 * Never matches a paid Subscribe & Save line.
	 *
	 * @param mixed $item Subscription item.
	 * @return bool
	 */
	private function is_explicit_free_gift_item( $item ) {
		if ( $this->get_subscription_item_total( $item ) > 0 ) {
			return false;
		}

		if ( 'wt_give_away_product' === $this->get_item_meta_value( $item, 'free_product' ) ) {
			return true;
		}

		foreach ( $this->gift_meta_keys as $key ) {
			$value = $this->get_item_meta_value( $item, $key );
			if ( ! empty( $value ) && 'no' !== $value && '0' !== (string) $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Explicit giveaway on a WC order item: marker + $0.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	private function is_explicit_free_gift_order_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}

		if ( (float) $item->get_total() > 0 ) {
			return false;
		}

		if ( 'wt_give_away_product' === (string) $item->get_meta( 'free_product', true ) ) {
			return true;
		}

		foreach ( $this->gift_meta_keys as $key ) {
			$value = $item->get_meta( $key, true );
			if ( ! empty( $value ) && 'no' !== $value && '0' !== (string) $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read a meta value from a subscription item (array or object).
	 *
	 * @param mixed  $item Subscription item.
	 * @param string $key  Meta key.
	 * @return mixed
	 */
	private function get_item_meta_value( $item, $key ) {
		if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
			return $item->get_meta( $key, true );
		}

		if ( ! is_array( $item ) ) {
			return '';
		}

		if ( isset( $item[ $key ] ) ) {
			return $item[ $key ];
		}

		$meta_lists = array();
		if ( isset( $item['item_data']['meta_data'] ) && is_array( $item['item_data']['meta_data'] ) ) {
			$meta_lists[] = $item['item_data']['meta_data'];
		}
		if ( isset( $item['meta_data'] ) && is_array( $item['meta_data'] ) ) {
			$meta_lists[] = $item['meta_data'];
		}

		foreach ( $meta_lists as $meta ) {
			foreach ( $meta as $entry_key => $entry ) {
				$meta_key = is_array( $entry ) && isset( $entry['key'] ) ? $entry['key'] : $entry_key;
				$meta_val = is_array( $entry ) && isset( $entry['value'] ) ? $entry['value'] : $entry;
				if ( $meta_key === $key ) {
					return $meta_val;
				}
			}
		}

		return '';
	}

	/**
	 * @param mixed $item Subscription item.
	 * @return float
	 */
	private function get_subscription_item_total( $item ) {
		if ( is_object( $item ) && method_exists( $item, 'get_total' ) ) {
			return (float) $item->get_total();
		}

		if ( is_array( $item ) ) {
			if ( isset( $item['item_data']['total'] ) ) {
				return (float) $item['item_data']['total'];
			}
			if ( isset( $item['total'] ) ) {
				return (float) $item['total'];
			}
			if ( isset( $item['line_total'] ) ) {
				return (float) $item['line_total'];
			}
		}

		return 0.0;
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
}
