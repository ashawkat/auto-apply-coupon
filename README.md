# Auto Apply Cart Coupon

Automatically apply store coupons to the cart when a customer adds a product — no coupon code required.

Built by [Betatech](https://betatech.co/).

---

## Features

- **Auto Apply Coupon** checkbox on each coupon edit screen
- **First Month Only** checkbox for subscription purchases
- Works with **WooCommerce Subscriptions** and **Sublium** (FunnelKit Subscribe & Save)
- Applies enabled coupons automatically when a product is added to the cart
- Avoids duplicate application if the coupon is already active
- Caches auto-apply coupon codes for performance
- HPOS (High-Performance Order Storage) compatible
- Settings link on the Plugins page that opens the coupons list
- Translation-ready (`auto-apply-cart-coupon` text domain)

---

## Requirements

- **WordPress** 6.0+
- **PHP** 7.4+
- **WooCommerce** 7.0+

### Optional (First Month Only)

- **WooCommerce Subscriptions**, and/or
- **Sublium** (Sublium Subscriptions for WooCommerce / FunnelKit)

---

## Installation

### Via WordPress Admin

1. Go to **Plugins → Add New**
2. Search for **Auto Apply Cart Coupon**
3. Click **Install Now**, then **Activate**
4. Open **Marketing → Coupons** and edit a coupon
5. Enable **Auto Apply Coupon** on the General tab

### Via Git / FTP

1. Clone or download this repository
2. Place the `auto-apply-cart-coupon` folder in `/wp-content/plugins/`
3. Activate the plugin from **Plugins**

---

## Usage

1. Create or edit a coupon in WooCommerce
2. Check **Auto Apply Coupon** (optional) to apply it automatically on add to cart
3. Check **First Month Only** if the coupon should discount only the first subscription payment (not renewals)
4. Save the coupon

When a shopper adds any product to their cart, auto-apply coupons are applied automatically (as long as WooCommerce coupon rules allow it).

### First Month Only

With **First Month Only** enabled:

- **WooCommerce Subscriptions** — the coupon discounts the initial payment only and is blocked on renewals.
- **Sublium** — free gifts / giveaways from the coupon stay on the initial order only. They are excluded from the subscription, from Sublium’s checkout **Renewal Price** (Subscribe & Save), and from future renewals.

You can also click **Settings** on the plugin row under **Plugins** to jump straight to the coupons list.

---

## Development

### Structure

```
auto-apply-cart-coupon/
├── auto-apply-cart-coupon.php
├── includes/
│   ├── class-auto-apply-cart-coupon.php
│   ├── class-auto-apply-cart-coupon-admin.php
│   ├── class-auto-apply-cart-coupon-cart.php
│   ├── class-auto-apply-cart-coupon-subscriptions.php
│   └── class-auto-apply-cart-coupon-sublium.php
├── uninstall.php
├── readme.txt
└── README.md
```

### Hooks Used

| Hook | Purpose |
|------|---------|
| `woocommerce_coupon_options` | Renders Auto Apply and First Month Only checkboxes |
| `woocommerce_coupon_options_save` | Saves checkbox values |
| `woocommerce_add_to_cart` | Syncs auto-apply coupons against usage restrictions |
| `woocommerce_after_cart_item_quantity_update` | Re-checks eligibility when quantities change |
| `woocommerce_cart_item_removed` / `restored` | Re-checks eligibility when cart items change |
| `woocommerce_cart_loaded_from_session` | Re-syncs coupons when the cart is loaded |
| `woocommerce_before_calculate_totals` | Removes first-month-only coupons from recurring totals |
| `woocommerce_coupon_is_valid` | Blocks first-month-only coupons on renewals |
| `woocommerce_subscription_payment_complete` | Removes first-month-only coupons after initial payment (WooCommerce Subscriptions) |
| `sublium_wcs_subscription_created` | Removes first-month free gifts from Sublium subscriptions after creation |
| `before_woocommerce_init` | Declares HPOS compatibility |

---

## FAQ

**Does it work without WooCommerce?**  
No. WooCommerce must be installed and active.

**Can multiple coupons auto-apply?**  
Yes. Every coupon with auto apply enabled will be attempted when a product is added to the cart.

**Is HPOS supported?**  
Yes. The plugin declares compatibility with WooCommerce custom order tables.

**What does First Month Only do?**  
It limits the coupon (and any free gifts / giveaways it adds) to the first subscription payment. Renewals are charged at full price. Supported with **WooCommerce Subscriptions** and **Sublium**.

**Does it work with Sublium / FunnelKit Subscribe & Save?**  
Yes. First Month Only keeps free gifts on the parent order only and keeps them out of Sublium’s recurring cart so the checkout renewal price stays correct.

---

## Changelog

### 1.2.8
- Fixed: Free gift coupons add a separate one-time giveaway line when the same product is already in the cart as Subscribe & Save; giveaways are excluded from Sublium plan assignment so they stay $0 / one-time

### 1.2.7
- Fixed: First-month free gifts / coupons are removed from Sublium subscriptions reliably (DB item reload + deferred cleanup; correct `get_subscription_items` / `delete_item` APIs)

### 1.2.6
- Fixed: Sublium integration is post-creation only; never strips plans or overrides recurring prices during checkout; never removes paid subscription items

### 1.2.5
- Fixed: Removed Sublium cart/checkout plan stripping and renewal-price overrides that could block subscription creation; gift cleanup runs after subscription exists only

### 1.2.4
- Fixed: Narrowed Sublium gift detection so real Subscribe & Save items keep their plan

### 1.2.3
- Fixed: Sublium checkout renewal price uses the cart Subscribe & Save line price instead of the raw variation price

### 1.2.2
- Fixed: Sublium checkout renewal price no longer includes first-month-only free gifts / giveaways

### 1.2.1
- Fixed: WebToffee Smart Coupons giveaways now add when Auto Apply runs via AJAX add-to-cart

### 1.2.0
- Added: Sublium Subscriptions support for First Month Only (free gifts stay on initial order only)

### 1.1.1
- Fixed: Auto-apply respects coupon usage restrictions (products, min/max spend, etc.)

### 1.1.0
- Added: First Month Only option for subscription purchases

### 1.0.1
- Fixed: Skip auto-apply when add-to-cart is triggered from wp-admin AJAX (e.g. order/subscription item editors)

### 1.0.0
- Initial release

---

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

---

## Author

**Betatech** — [https://betatech.co/](https://betatech.co/)
