=== Hurrry Checkout ===
Contributors: hurrry
Tags: woocommerce, checkout, conversion
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later

Replaces the WooCommerce checkout with the Hurrry hosted checkout, using the same payload as the Shopify integration, and guards the WooCommerce checkout on the server so it is never shown.

== Description ==

This plugin is the WooCommerce port of the Shopify `hurry.js` snippet. It:

* Intercepts every "Proceed to checkout" button, link and form (classic cart, cart/checkout blocks, mini-cart, side-cart plugins, `data-href` buttons, `form.submit()`, `history.pushState`) from a window-level capture listener that is active the instant the script parses.
* Preloads the hosted checkout URL in the background, debounced, whenever the cart changes, and remembers it per tab and in a cookie so a click is one redirect.
* Guards the checkout URL **on the server**: a request to the WooCommerce checkout page never renders it. The server redirects to the URL the browser preloaded, or creates the order itself from PHP (forwarding the shopper's IP and User-Agent), or serves a tiny interstitial whose plain ES5 script calls the endpoint from the browser, or shows an error page with "retry" and "back to cart". The WooCommerce checkout only appears if the store owner explicitly enabled the fallback and everything else failed.
* Removes every payment gateway from the storefront (admin and "pay for order" keep theirs), refuses the classic and Store API checkout submissions, and hides express payment buttons (Apple Pay, Google Pay, PayPal, Amazon Pay, Klarna…), so nothing can take a payment outside Hurrry.
* Excludes itself from JavaScript optimisers (WP Rocket, Autoptimize, LiteSpeed, Perfmatters, SiteGround, FlyingPress, WP-Optimize, Hummingbird, W3TC) and from Cloudflare Rocket Loader, and purges page caches when the settings change or the plugin is (de)activated.
* Shows a "taking you to the secure checkout" overlay on click (immediate feedback, no double taps), with a plain link after ten seconds in case something blocks the navigation.
* Collects the same tracking signals as the Shopify script (`_fbc` built from `fbclid`, `_fbp`, `ttclid`, `_ttp`, `gclid`, `_epik`, visitor id), passes them in the payload and as `hy_*` parameters on the hosted URL, optionally loads a Meta pixel and fires InitiateCheckout, and pings the backend so the install can be verified from the CRM.
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
      "shopUrl":   "shop.example.com",
      "sourceUrl": "https://shop.example.com/cart/",
      "fbp": "", "fbc": "", "ttc": "", "ttp": "", "gclid": "", "epik": "", "visitorId": "",
      "notes":     "WooCommerce Cart Inline"
    }

* Accepts `hostedUrl`, `redirectUrl`, `url`, `direct_link` or `checkoutUrl` in the response, appends `defaultLang`, and optionally decorates the URL with the Google Ads cross-domain linker.

Amounts travel in minor units (cents), like Shopify `line_price`. `unitAmount` is the line total divided by the quantity and rounded, exactly as in `hurry.js`. The server-side builder (`Hurrry_Payload`) produces the same `externalId` as the browser, so the backend's idempotency merges both paths into one order.

== Where the cart comes from ==

Shopify serves `/cart.js`; WordPress does not. The plugin serves the current session cart in the same shape over WooCommerce AJAX (`/?wc-ajax=hurrry_get_cart`, primary), the REST route `GET /wp-json/hurrry/v1/cart` and `admin-ajax.php` (fallbacks). Cart requests are always made against the origin the page is on, so a www/non-www or http/https mismatch cannot produce an empty cart.

Line prices include tax only when the store is configured to display prices including tax, so the shopper is charged what the cart showed.

== Installation ==

1. Copy the plugin folder into `wp-content/plugins/hurrry-checkout`.
2. Activate it in Plugins.
3. Go to WooCommerce > Hurrry Checkout and set the endpoint URL. The screen shows the exact `shopUrl` value the store must be registered with in Hurrry.

== Diagnostics ==

WooCommerce > Hurrry Checkout lists the last arrivals at the server guard (how the shopper got there, what the guard did, the device). A shopper that "saw the WooCommerce checkout" either appears there with an explanation, or never reached the server. With "Verbose logging" on, the same lines and every endpoint error go to WooCommerce > Status > Logs, source `hurrry-checkout`. "Test endpoint from this server" checks that PHP can reach the endpoint host (hosts that block outbound HTTP fall through to the interstitial).

Store managers can look at the native WooCommerce checkout with `/checkout/?hurrry_bypass=1` (ends with `hurrry_bypass=0`); shoppers are never affected.

In the browser console: `Hurrry.status()`, `Hurrry.signals()`, `Hurrry.go()`, `Hurrry.version`.

== Filters ==

* `hurrry_frontend_config` — the JS config array before it is printed.
* `hurrry_checkout_selectors` — the list of CSS selectors that get intercepted.
* `hurrry_checkout_paths` — the URL paths treated as the checkout page.
* `hurrry_cart_response` — the whole cart response.
* `hurrry_cart_item` — one mapped cart line.
* `hurrry_amount_multiplier` — minor-unit multiplier, 100 by default.
* `hurrry_order_payload` — the server-built order payload.
* `hurrry_gate_should_guard` — return false to let one request through the server guard.
* `hurrry_gate_lock_gateways` — return false to keep the gateways on one request.
* `hurrry_gate_texts` — the interstitial / overlay texts.
* `hurrry_express_selectors` — selectors of express payment buttons hidden by CSS.

Mark any element with `data-hurrry-checkout` to have it treated as a checkout button, or `data-hurrry-ignore` to leave it alone.

== Changelog ==

= 1.5.0 =
* Server-side guard on the checkout URL: the WooCommerce checkout is never rendered. Redirects to the preloaded URL (cookie), or creates the order from PHP, or serves an ES5 interstitial, or an error page with retry.
* Payment gateways removed from the storefront, classic and Store API checkout submissions refused, express payment buttons hidden. All configurable, all on by default.
* The browser never falls back to the WooCommerce checkout on an error; it hands over to the server guard instead.
* Window-level capture interceptor active from parse time; `form.submit()`, `data-href` buttons and `history.pushState` to the checkout caught; all language variants of the checkout URL recognised (WPML / Polylang); middle/modifier clicks left to the server guard.
* Overlay with feedback on click, double-click guard, "taking longer" link.
* Preloaded URL remembered per tab and in a cookie; re-published without an endpoint call when the cart comes back to the same contents.
* Cart requests forced to the page's origin; REST nonce only sent to the REST route.
* Exclusions for every common JS optimiser and Cloudflare Rocket Loader; page caches purged on settings change and (de)activation.
* Tracking signals (SIG), Meta pixel bootstrap, InitiateCheckout, install ping and redirect heartbeat ported from the Shopify script.
* Diagnostics log and endpoint reachability test in the settings screen; manager bypass cookie.
* Script syntax restricted to what older mobile browsers parse (no optional chaining).

= 1.1.0 =
* WooCommerce AJAX cart transport, inline cart snapshot, retries.

= 1.0.0 =
* First release: port of hurry.js to WooCommerce.
