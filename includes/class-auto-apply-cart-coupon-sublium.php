<?php
/**
 * Sublium Subscriptions integration for first-month-only coupons.
 *
 * Does NOT touch cart/checkout plan assignment, recurring groups, or renewal
 * pricing — those interventions blocked Subscribe & Save subscription creation.
 *
 * After a subscription exists, removes first-month free gifts / giveaways and
 * first-month-only coupon discount rows so they do not renew.
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
	 * Subscription IDs queued for shutdown cleanup.
	 *
	 * @var array<int>
	 */
	private $pending_cleanup_ids = array();

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
		add_action( 'sublium_wcs_subscription_created', array( $this, 'on_subscription_created' ), 50, 2 );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'cleanup_order_subscriptions' ), 50, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'cleanup_order_subscriptions' ), 50, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'cleanup_order_subscriptions' ), 50, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'cleanup_order_subscriptions' ), 20, 1 );

		add_action( 'sublium_wcs_subscription_renewal_payment_complete', array( $this, 'on_renewal_payment_complete' ), 20, 2 );

		add_action( 'shutdown', array( $this, 'run_pending_cleanup' ), 5 );
		add_action( 'aacc_cleanup_sublium_subscription', array( $this, 'cron_cleanup_subscription' ), 10, 1 );
	}

	/**
	 * Handle Sublium subscription created.
	 *
	 * @param mixed         $subscription Subscription object or ID.
	 * @param WC_Order|null $order        Parent order.
	 * @return void
	 */
	public function on_subscription_created( $subscription, $order = null ) {
		$this->safe_cleanup_subscription( $subscription, $order );
		$this->queue_deferred_cleanup( $subscription, $order );
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
			$this->safe_cleanup_subscription( $subscription_id, $order );
			$this->queue_deferred_cleanup( $subscription_id, $order );
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
		$this->safe_cleanup_subscription( $subscription, $order );

		if ( $order instanceof WC_Order ) {
			$this->remove_gifts_from_order( $order );
		}
	}

	/**
	 * Cron / scheduled cleanup by subscription ID.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function cron_cleanup_subscription( $subscription_id ) {
		$this->safe_cleanup_subscription( absint( $subscription_id ), null );
	}

	/**
	 * Run any cleanup queued during this request after Sublium finishes saving.
	 *
	 * @return void
	 */
	public function run_pending_cleanup() {
		if ( empty( $this->pending_cleanup_ids ) ) {
			return;
		}

		$ids = array_unique( array_filter( array_map( 'absint', $this->pending_cleanup_ids ) ) );
		$this->pending_cleanup_ids = array();

		foreach ( $ids as $subscription_id ) {
			$this->safe_cleanup_subscription( $subscription_id, null );
		}
	}

	/**
	 * Queue another cleanup pass after the request (and optionally via cron).
	 *
	 * Fresh Sublium objects often lack DB item IDs in memory until reload; a second
	 * pass after save/shutdown is required for reliable gift removal.
	 *
	 * @param mixed         $subscription Subscription object or ID.
	 * @param WC_Order|null $order        Optional order.
	 * @return void
	 */
	private function queue_deferred_cleanup( $subscription, $order = null ) {
		$id = 0;

		if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) {
			$id = (int) $subscription->get_id();
		} else {
			$id = absint( $subscription );
		}

		if ( ! $id ) {
			return;
		}

		$this->pending_cleanup_ids[] = $id;

		if ( ! wp_next_scheduled( 'aacc_cleanup_sublium_subscription', array( $id ) ) ) {
			wp_schedule_single_event( time() + 15, 'aacc_cleanup_sublium_subscription', array( $id ) );
		}

		unset( $order );
	}

	/**
	 * Safely clean gifts/coupons without breaking Sublium flows.
	 *
	 * @param mixed         $subscription Subscription object or ID.
	 * @param WC_Order|null $order        Optional parent/renewal order.
	 * @return void
	 */
	private function safe_cleanup_subscription( $subscription, $order = null ) {
		try {
			$this->cleanup_subscription( $subscription, $order );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never interrupt Sublium.
		} catch ( Error $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never interrupt Sublium.
		}
	}

	/**
	 * Remove first-month gifts and first-month-only coupon rows from a subscription.
	 *
	 * @param mixed         $subscription Subscription object or ID.
	 * @param WC_Order|null $order        Optional order for coupon/gift context.
	 * @return void
	 */
	private function cleanup_subscription( $subscription, $order = null ) {
		// Always reload from DB so item rows include real `id` values.
		// On `sublium_wcs_subscription_created`, in-memory items often lack IDs.
		$subscription = $this->normalize_subscription( $subscription, true );

		if ( ! $subscription ) {
			return;
		}

		$parent_order = $order instanceof WC_Order ? $order : $this->get_parent_order_from_subscription( $subscription );

		if ( ! $parent_order || ! $this->order_has_first_month_only_coupon( $parent_order ) ) {
			return;
		}

		$gift_product_ids  = $this->get_first_month_giveaway_product_ids_from_order( $parent_order );
		$first_month_codes = $this->get_first_month_only_codes_from_order( $parent_order );
		$items             = $this->get_subscription_line_rows( $subscription );

		$delete_ids            = array();
		$remaining_product_ids = array();
		$paid_product_count    = 0;

		foreach ( $items as $row ) {
			$item_id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$item_type = isset( $row['item_type'] ) ? (int) $row['item_type'] : 0;
			$item_data = $this->decode_item_data( isset( $row['item_data'] ) ? $row['item_data'] : array() );

			// Remove first-month-only coupon / discount rows (item_type 5).
			if ( 5 === $item_type && $this->discount_row_is_first_month_coupon( $item_data, $first_month_codes ) ) {
				if ( $item_id ) {
					$delete_ids[] = $item_id;
				}
				continue;
			}

			// Product line items only.
			if ( 1 !== $item_type ) {
				continue;
			}

			$product_id = 0;
			if ( isset( $item_data['product_id'] ) ) {
				$product_id = (int) $item_data['product_id'];
			} elseif ( isset( $row['product_id'] ) ) {
				$product_id = (int) $row['product_id'];
			}

			if ( $this->is_first_month_gift_row( $item_data, $gift_product_ids ) ) {
				if ( $item_id ) {
					$delete_ids[] = $item_id;
				}
				continue;
			}

			++$paid_product_count;
			if ( $product_id ) {
				$remaining_product_ids[] = $product_id;
			}
		}

		if ( empty( $delete_ids ) ) {
			return;
		}

		// Never empty the subscription of product lines.
		if ( $paid_product_count < 1 ) {
			return;
		}

		$removed = false;
		foreach ( array_unique( $delete_ids ) as $item_id ) {
			if ( $this->delete_subscription_item( $subscription, $item_id ) ) {
				$removed = true;
			}
		}

		if ( ! $removed ) {
			return;
		}

		if ( method_exists( $subscription, 'update_items' ) ) {
			$subscription->update_items( array_values( array_unique( $remaining_product_ids ) ) );
		}

		if ( method_exists( $subscription, 'calculate_items_totals' ) ) {
			$totals = $subscription->calculate_items_totals();
			if ( is_array( $totals ) && isset( $totals['total'] ) && method_exists( $subscription, 'update_totals' ) ) {
				$subscription->update_totals( $totals['total'] );
			}
		}

		if ( method_exists( $subscription, 'save' ) ) {
			$subscription->save();
		}
	}

	/**
	 * Remove gift lines from a renewal order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function remove_gifts_from_order( $order ) {
		$gift_product_ids = $this->get_first_month_giveaway_product_ids_from_order( $order );
		$removed          = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( (float) $item->get_total() > 0 ) {
				continue;
			}

			$as_data = array(
				'product_id'        => $item->get_product_id(),
				'variation_id'      => $item->get_variation_id(),
				'total'             => $item->get_total(),
				'name'              => $item->get_name(),
				'free_product'      => $item->get_meta( 'free_product', true ),
				'free_gift_coupon'  => $item->get_meta( 'free_gift_coupon', true ),
				'_fkcart_free_gift' => $item->get_meta( '_fkcart_free_gift', true ),
			);

			if ( ! $this->is_first_month_gift_row( $as_data, $gift_product_ids ) ) {
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
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function order_has_first_month_only_coupon( $order ) {
		return ! empty( $this->get_first_month_only_codes_from_order( $order ) );
	}

	/**
	 * First-month-only coupon codes on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string>
	 */
	private function get_first_month_only_codes_from_order( $order ) {
		$codes = array();

		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( 'yes' === Auto_Apply_Cart_Coupon::get_first_month_only_value( $coupon ) ) {
				$codes[] = wc_format_coupon_code( $code );
			}
		}

		return $codes;
	}

	/**
	 * Giveaway product IDs from first-month-only coupons + $0 gift lines on the order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int>
	 */
	private function get_first_month_giveaway_product_ids_from_order( $order ) {
		$ids = array();

		foreach ( $this->get_first_month_only_codes_from_order( $order ) as $code ) {
			$ids = array_merge( $ids, $this->get_coupon_giveaway_product_ids( new WC_Coupon( $code ) ) );
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$free_product = (string) $item->get_meta( 'free_product', true );
			$has_marker   = ( 'wt_give_away_product' === $free_product )
				|| $item->get_meta( 'free_gift_coupon', true )
				|| $item->get_meta( '_fkcart_free_gift', true )
				|| $item->get_meta( '_tikva_free_gift', true );

			$name_looks_like_gift = false !== stripos( $item->get_name(), 'free gift' );

			if ( ( $has_marker || $name_looks_like_gift ) && (float) $item->get_total() <= 0 ) {
				$ids[] = $item->get_product_id();
				if ( $item->get_variation_id() ) {
					$ids[] = $item->get_variation_id();
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Read WebToffee / Smart Coupons giveaway product IDs from a coupon.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array<int>
	 */
	private function get_coupon_giveaway_product_ids( $coupon ) {
		$ids  = array();
		$keys = array(
			'_wt_free_product_ids',
			'wt_free_product_ids',
			'wc_sc_add_product_details',
			'_wc_sc_add_product_details',
			'free_gift_ids',
			'_free_gift_ids',
		);

		if ( class_exists( 'Wt_Smart_Coupon_Giveaway_Product_Common' ) && is_callable( array( 'Wt_Smart_Coupon_Giveaway_Product_Common', 'get_giveaway_products' ) ) ) {
			$from_wt = Wt_Smart_Coupon_Giveaway_Product_Common::get_giveaway_products( $coupon->get_id() );
			if ( is_array( $from_wt ) ) {
				foreach ( $from_wt as $entry ) {
					if ( is_numeric( $entry ) ) {
						$ids[] = (int) $entry;
					} elseif ( is_array( $entry ) && isset( $entry['product_id'] ) ) {
						$ids[] = (int) $entry['product_id'];
					}
				}
			}
		}

		foreach ( $keys as $key ) {
			$value = $coupon->get_meta( $key, true );

			if ( empty( $value ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$maybe = maybe_unserialize( $value );
				$value = false !== $maybe ? $maybe : $value;
				if ( is_string( $value ) && false !== strpos( $value, ',' ) ) {
					$value = array_map( 'trim', explode( ',', $value ) );
				}
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
	 * Whether a subscription product row is a first-month free gift.
	 *
	 * Paid lines are never treated as gifts.
	 *
	 * @param array      $item_data        Decoded item_data.
	 * @param array<int> $gift_product_ids Known giveaway IDs.
	 * @return bool
	 */
	private function is_first_month_gift_row( $item_data, $gift_product_ids ) {
		$total = 0.0;
		if ( isset( $item_data['total'] ) ) {
			$total = (float) $item_data['total'];
		} elseif ( isset( $item_data['subtotal'] ) ) {
			$total = (float) $item_data['subtotal'];
		} elseif ( isset( $item_data['line_total'] ) ) {
			$total = (float) $item_data['line_total'];
		}

		if ( $total > 0 ) {
			return false;
		}

		if ( isset( $item_data['free_product'] ) && 'wt_give_away_product' === $item_data['free_product'] ) {
			return true;
		}

		if ( ! empty( $item_data['free_gift_coupon'] ) || ! empty( $item_data['_fkcart_free_gift'] ) || ! empty( $item_data['_tikva_free_gift'] ) ) {
			return true;
		}

		// Nested meta_data on item_data.
		if ( isset( $item_data['meta_data'] ) && is_array( $item_data['meta_data'] ) ) {
			foreach ( $item_data['meta_data'] as $meta ) {
				$key = is_array( $meta ) && isset( $meta['key'] ) ? $meta['key'] : '';
				$val = is_array( $meta ) && isset( $meta['value'] ) ? $meta['value'] : '';
				if ( 'free_product' === $key && 'wt_give_away_product' === $val ) {
					return true;
				}
				if ( in_array( $key, array( 'free_gift_coupon', '_fkcart_free_gift', '_tikva_free_gift' ), true ) && ! empty( $val ) ) {
					return true;
				}
			}
		}

		// Flattened WC meta objects sometimes stored under `meta`.
		if ( isset( $item_data['meta'] ) && is_array( $item_data['meta'] ) ) {
			foreach ( $item_data['meta'] as $meta ) {
				$key = '';
				$val = '';
				if ( is_object( $meta ) && method_exists( $meta, 'get_data' ) ) {
					$data = $meta->get_data();
					$key  = isset( $data['key'] ) ? $data['key'] : '';
					$val  = isset( $data['value'] ) ? $data['value'] : '';
				} elseif ( is_array( $meta ) ) {
					$key = isset( $meta['key'] ) ? $meta['key'] : '';
					$val = isset( $meta['value'] ) ? $meta['value'] : '';
				}
				if ( 'free_product' === $key && 'wt_give_away_product' === $val ) {
					return true;
				}
				if ( in_array( $key, array( 'free_gift_coupon', '_fkcart_free_gift', '_tikva_free_gift' ), true ) && ! empty( $val ) ) {
					return true;
				}
			}
		}

		$product_id   = isset( $item_data['product_id'] ) ? (int) $item_data['product_id'] : 0;
		$variation_id = isset( $item_data['variation_id'] ) ? (int) $item_data['variation_id'] : 0;

		if ( $gift_product_ids && ( in_array( $product_id, $gift_product_ids, true ) || ( $variation_id && in_array( $variation_id, $gift_product_ids, true ) ) ) ) {
			return true;
		}

		$name = '';
		if ( isset( $item_data['name'] ) ) {
			$name = (string) $item_data['name'];
		} elseif ( isset( $item_data['product_name'] ) ) {
			$name = (string) $item_data['product_name'];
		}

		if ( $name && false !== stripos( $name, 'free gift' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a discount row belongs to a first-month-only coupon.
	 *
	 * @param array         $item_data Discount item_data.
	 * @param array<string> $codes     First-month coupon codes.
	 * @return bool
	 */
	private function discount_row_is_first_month_coupon( $item_data, $codes ) {
		if ( empty( $codes ) ) {
			return false;
		}

		$candidates = array();
		foreach ( array( 'code', 'coupon_code', 'name', 'label' ) as $key ) {
			if ( ! empty( $item_data[ $key ] ) ) {
				$candidates[] = wc_format_coupon_code( (string) $item_data[ $key ] );
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $codes, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode subscription item_data JSON/array.
	 *
	 * @param mixed $item_data Raw item_data.
	 * @return array
	 */
	private function decode_item_data( $item_data ) {
		if ( is_array( $item_data ) ) {
			return $item_data;
		}

		if ( is_string( $item_data ) && '' !== $item_data ) {
			$decoded = json_decode( $item_data, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			$maybe = maybe_unserialize( $item_data );
			if ( is_array( $maybe ) ) {
				return $maybe;
			}
		}

		return array();
	}

	/**
	 * Get product/discount rows from a Sublium subscription.
	 *
	 * Important: Sublium's get_items() returns product ID list only — use
	 * get_subscription_items() for actual line rows with DB ids.
	 *
	 * @param object $subscription Subscription.
	 * @return array
	 */
	private function get_subscription_line_rows( $subscription ) {
		if ( method_exists( $subscription, 'reload_items' ) ) {
			$subscription->reload_items();
		}

		if ( method_exists( $subscription, 'get_subscription_items' ) ) {
			$items = $subscription->get_subscription_items();
			if ( is_array( $items ) && ! empty( $items ) ) {
				return $items;
			}
		}

		return array();
	}

	/**
	 * Delete a subscription item by DB id.
	 *
	 * @param object $subscription Subscription.
	 * @param int    $item_id      Item ID.
	 * @return bool
	 */
	private function delete_subscription_item( $subscription, $item_id ) {
		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return false;
		}

		if ( method_exists( $subscription, 'delete_item' ) ) {
			return (bool) $subscription->delete_item( $item_id );
		}

		return false;
	}

	/**
	 * Normalize a subscription reference to an object.
	 *
	 * @param mixed $subscription Subscription object or ID.
	 * @param bool  $force_reload Always re-instantiate from DB (recommended).
	 * @return object|null
	 */
	private function normalize_subscription( $subscription, $force_reload = false ) {
		$id = 0;

		if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) {
			$id = (int) $subscription->get_id();
			if ( ! $force_reload && $id ) {
				if ( method_exists( $subscription, 'reload_items' ) ) {
					$subscription->reload_items();
				}
				return $subscription;
			}
		} else {
			$id = absint( $subscription );
		}

		if ( ! $id ) {
			return is_object( $subscription ) ? $subscription : null;
		}

		if ( function_exists( 'sublium_get_subscription' ) ) {
			$object = sublium_get_subscription( $id );
			if ( $object ) {
				return $object;
			}
		}

		if ( class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			try {
				$object = new \Sublium_WCS\Includes\Controller\Subscriptions\Subscription( $id );
				if ( isset( $object->exists ) && ! $object->exists ) {
					return null;
				}
				return $object;
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through.
			}
		}

		return is_object( $subscription ) ? $subscription : null;
	}

	/**
	 * Get parent order for a Sublium subscription.
	 *
	 * @param object $subscription Subscription.
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
	 * @param WC_Order $order Order.
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

		// Fallback: query Sublium by parent_order_id.
		if ( empty( $ids ) && class_exists( '\Sublium_WCS\Includes\database\Subscriptions' ) ) {
			try {
				$db   = new \Sublium_WCS\Includes\database\Subscriptions();
				$rows = $db->read( array( 'parent_order_id' => $order->get_id() ), '' );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						if ( isset( $row['id'] ) ) {
							$ids[] = $row['id'];
						} elseif ( is_object( $row ) && isset( $row->id ) ) {
							$ids[] = $row->id;
						}
					}
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore.
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}
}
