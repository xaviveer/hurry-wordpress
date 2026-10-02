=== Hurrry Checkout ===
Contributors: hurrry
Tags: woocommerce, checkout, conversion
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Replaces the WooCommerce checkout with the Hurrry hosted checkout, using the same payload as the Shopify integration.

== Description ==

This plugin is the WooCommerce port of the Shopify `hurry.js` snippet. It:

* Intercepts every "Proceed to checkout" button and link (classic cart, cart/checkout blocks and mini-cart).
* Preloads the hosted checkout URL in the background, debounced, whenever the cart changes.
* Posts the exact same JSON payload the Shopify integration posts:

    {
      "externalId":   "CART-<token>-<hash>",
      "currency":     "EUR",
      "showCurrency": "EUR",
      "items": [
        {
          "productId":  "SKU-or-product-id",
          "sku":        "SKU",
          "name":       "Product - Variant",
          "imageUrl":   "https://...",
          "quantity":   2,
          "unitAmount": 1999,
          "taxAmount":  0
        }
      ],
      "shopUrl": "shop.example.com",
      "fbp":     "",
      "fbc":     "",
      "ttc":     "",
      "ttp":     "",
      "notes":   "WooCommerce Cart Inline"
    }

* Accepts `hostedUrl`, `redirectUrl`, `url` or `direct_link` in the response, appends `defaultLang`, and optionally decorates the URL with the Google Ads cross-domain linker.
* Redirects, or opens the checkout in a full-screen iframe when that option is on.

Amounts travel in minor units (cents), like Shopify `line_price`. `unitAmount` is the line total divided by the quantity and rounded, exactly as in `hurry.js`.

== Where the cart comes from ==

Shopify serves `/cart.js`; WordPress does not. The plugin registers `GET /wp-json/hurrry/v1/cart`, which returns the current session cart in the same shape (`token`, `currency`, `items[]` with `id`, `quantity`, `line_price`, `product_title`, `variant_title`, `sku`, `image`). The payload builder in `assets/js/hurrry.js` is therefore a literal port.

Line prices include tax only when the store is configured to display prices including tax, so the shopper is charged what the cart showed.

== Installation ==

1. Copy the plugin folder into `wp-content/plugins/hurrry-checkout`.
2. Activate it in Plugins.
3. Go to WooCommerce > Hurrry Checkout and set the endpoint URL.

== Filters ==

* `hurrry_frontend_config` — the JS config array before it is printed.
* `hurrry_checkout_selectors` — the list of CSS selectors that get intercepted.
* `hurrry_cart_response` — the whole cart REST response.
* `hurrry_cart_item` — one mapped cart line.
* `hurrry_amount_multiplier` — minor-unit multiplier, 100 by default.

== Changelog ==

= 1.0.0 =
* First release: port of hurry.js to WooCommerce.
