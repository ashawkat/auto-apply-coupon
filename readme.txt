=== Auto Apply Cart Coupon ===
Contributors: betatech
Tags: coupon, auto apply, cart, discount, automatic coupon, sublium, subscriptions
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.4
WC requires at least: 7.0
WC tested up to: 9.6
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically apply store coupons to the cart when a customer adds a product.

== Description ==

Auto Apply Cart Coupon adds a simple **Auto Apply Coupon** checkbox to each coupon in your store. When enabled, the coupon is applied automatically as soon as a customer adds an item to their cart — no manual code entry required.

This plugin requires **WooCommerce** to be installed and active.

### Key Features

* Adds an **Auto Apply Coupon** option on the coupon edit screen
* Adds a **First Month Only** option for subscription purchases
* Compatible with **WooCommerce Subscriptions** and **Sublium** (FunnelKit Subscribe & Save)
* Applies selected coupons automatically on `add to cart`
* Skips coupons that are already applied to the cart
* Caches auto-apply coupon codes for better performance
* Fully compatible with **WooCommerce HPOS (High-Performance Order Storage)**
* Settings link on the Plugins page that opens the coupons list
* Translation-ready

### Use Cases

* Welcome discounts for new shoppers
* Site-wide promotions that should apply without friction
* Campaign coupons that must activate as soon as a product is added
* First-month-only subscription discounts and free gifts (WooCommerce Subscriptions or Sublium)

== Installation ==

### Install via WordPress Dashboard

1. Navigate to **Plugins → Add New**
2. Search for **Auto Apply Cart Coupon**
3. Click **Install Now**, then **Activate**
4. Go to **Marketing → Coupons** (or **Coupons** in older WooCommerce versions)
5. Edit a coupon and enable **Auto Apply Coupon**

### Install via FTP

1. Download the plugin ZIP
2. Unzip the package
3. Upload the `auto-apply-cart-coupon` folder into `/wp-content/plugins/`
4. Activate the plugin from the **Plugins** menu

== Frequently Asked Questions ==

= Does this plugin work without WooCommerce? =

No. WooCommerce must be installed and active.

= Where do I enable auto apply for a coupon? =

Edit any coupon and check **Auto Apply Coupon** on the General tab.

= Will the coupon apply more than once? =

No. The plugin checks whether the coupon is already applied before adding it again.

= Can I enable auto apply on multiple coupons? =

Yes. All coupons marked for auto apply will be applied when a product is added to the cart, subject to each coupon's own usage rules and restrictions.

= Is it compatible with High-Performance Order Storage (HPOS)? =

Yes. The plugin declares compatibility with WooCommerce custom order tables.

= What does First Month Only do? =

When enabled, the coupon (and any free gifts / giveaways it adds) discounts only the first payment of a subscription and will not apply to renewals. Works with **WooCommerce Subscriptions** and **Sublium**.

= Does it work with Sublium / FunnelKit Subscribe & Save? =

Yes. With First Month Only enabled, free gifts stay on the initial order only and are excluded from Sublium’s recurring subscription and checkout renewal price.

== Screenshots ==

1. Auto Apply Coupon checkbox on the coupon edit screen
2. Coupon applied automatically after adding a product to the cart

== Changelog ==

= 1.2.4 =
* Fixed: Narrowed Sublium gift detection so real Subscribe & Save items are not stripped of their plan (orders not counting as subscriptions)

= 1.2.3 =
* Fixed: Sublium checkout renewal price now uses the cart Subscribe & Save line price instead of the raw variation price

= 1.2.2 =
* Fixed: Sublium checkout "Renewal Price" no longer includes first-month-only free gifts / giveaways

= 1.2.1 =
* Fixed: WebToffee Smart Coupons giveaway products now add to cart when Auto Apply runs during AJAX add-to-cart

= 1.2.0 =
* Added: Sublium Subscriptions support for First Month Only — free gifts / giveaways stay on the initial order and are removed from the recurring subscription

= 1.1.1 =
* Fixed: Auto-apply now respects coupon usage restrictions (allowed products, minimum spend, maximum spend, and related rules) before applying or keeping a coupon on the cart

= 1.1.0 =
* Added: First Month Only option on coupons — limits the discount to the first subscription payment (not renewals)

= 1.0.1 =
* Fixed: Skip auto-apply when add-to-cart is triggered from wp-admin AJAX (e.g. order/subscription item editors), so backend cart rebuilds no longer re-apply coupons

= 1.0.0 =
* Initial release
* Auto Apply Coupon checkbox on coupon edit screen
* Automatic coupon application on add to cart
* HPOS compatibility declaration
* Settings link on the Plugins page

== Upgrade Notice ==

= 1.2.4 =
Important — prevents Subscribe & Save items from losing their Sublium plan due to over-aggressive gift detection.

= 1.2.3 =
Fixes Sublium renewal price showing variation price instead of the cart Subscribe & Save price.

= 1.2.2 =
Fixes Sublium Subscribe & Save renewal price including first-month free gifts.

= 1.2.1 =
Fixes free/giveaway products not being added when Auto Apply runs on AJAX add-to-cart.

= 1.2.0 =
Adds Sublium support for First Month Only free gifts / coupons.

= 1.1.1 =
Recommended update — auto-apply now honors product and spend restrictions on coupons.

= 1.1.0 =
Adds a First Month Only option for subscription coupon purchases.

= 1.0.1 =
Recommended update — prevents auto-apply coupons from firing during admin order/subscription item edits.

= 1.0.0 =
Initial release.
