# Auto Apply Cart Coupon

Automatically apply store coupons to the cart when a customer adds a product — no coupon code required.

Built by [Betatech](https://betatech.co/).

---

## Features

- **Auto Apply Coupon** checkbox on each coupon edit screen
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
2. Check **Auto Apply Coupon**
3. Save the coupon

When a shopper adds any product to their cart, the coupon is applied automatically (as long as WooCommerce coupon rules allow it).

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
│   └── class-auto-apply-cart-coupon-cart.php
├── uninstall.php
├── readme.txt
└── README.md
```

### Hooks Used

| Hook | Purpose |
|------|---------|
| `woocommerce_coupon_options` | Renders the Auto Apply checkbox |
| `woocommerce_coupon_options_save` | Saves the checkbox value |
| `woocommerce_add_to_cart` | Applies auto-apply coupons |
| `before_woocommerce_init` | Declares HPOS compatibility |

---

## FAQ

**Does it work without WooCommerce?**  
No. WooCommerce must be installed and active.

**Can multiple coupons auto-apply?**  
Yes. Every coupon with auto apply enabled will be attempted when a product is added to the cart.

**Is HPOS supported?**  
Yes. The plugin declares compatibility with WooCommerce custom order tables.

---

## Changelog

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
