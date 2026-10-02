/**
 * Hurrry Checkout for WooCommerce.
 *
 * Port of the Shopify hurry.js integration. The payload sent to the endpoint is
 * byte-for-byte the same shape; only the cart source (the plugin REST route
 * instead of /cart.js) and the cart-change signals are WooCommerce specific.
 */
(function (window) {
  "use strict";

  const Hurrry = {};

  // ============================
  // DEFAULT CONFIG
  // ============================

  const DEFAULT_CFG = {
    endpoint: "",
    defaultLang: null,
    fallbackToStore: false,
    preloadDebounceMs: 200,
    fetchTimeoutMs: 8000,
    retries: 5,
    debug: false,
    iframe: false,
    googleAdsId: null,
    notes: "WooCommerce Cart Inline",
    cartUrl: "/?wc-ajax=hurrry_get_cart",
    cartUrls: null,
    restNonce: "",
    cartBootstrap: null,
    checkoutUrl: "/checkout/",
    cartPageUrl: "/cart/",
    shopUrl: "",
    selectors: [".checkout-button", ".wc-proceed-to-checkout a", ".wc-block-cart__submit-button", "a.button.checkout"],
  };

  let CFG = {};
  let preloadedUrl = null;
  // externalId of the cart the preloaded URL was built for.
  let preloadedKey = null;
  // Bumped on every cart change so an in-flight preload can tell that the cart
  // moved under it and refuse to publish a URL for the old contents.
  let cartGeneration = 0;
  let preloadInProgress = false;
  let preloadQueued = false;
  let preloadPromise = null;
  let preloadTimer = null;
  // Server-rendered cart, consumed once by the first read.
  let bootstrapCart = null;
  let cacheBustCounter = 0;

  const log = (...a) => {
    if (CFG.debug) console.log("[HURRRY]", ...a);
  };
  const warn = (...a) => {
    if (CFG.debug) console.warn("[HURRRY]", ...a);
  };
  const err = (...a) => {
    console.error("[HURRRY]", ...a);
  };

  // ============================
  // PUBLIC INIT
  // ============================

  Hurrry.init = function (userConfig) {
    CFG = Object.assign({}, DEFAULT_CFG, userConfig || {});

    if (!CFG.endpoint) {
      console.error("Hurrry: endpoint is required");
      return;
    }

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", init);
    } else {
      init();
    }
  };

  function init() {
    log("Hurrry initialized");
    if (CFG.cartBootstrap && Array.isArray(CFG.cartBootstrap.items)) {
      bootstrapCart = CFG.cartBootstrap;
    }
    wireCheckoutButtons();
    observeCartChanges();
    setupGtagLinker();
    installUltraShield();
    requestPreload();
  }

  // ============================
  // GOOGLE ADS CROSS-DOMAIN LINKER
  // ============================

  function setupGtagLinker() {
    if (!CFG.googleAdsId) return;
    if (typeof gtag !== "function") return;
    try {
      var checkoutHost = new URL(CFG.endpoint).hostname;
      if (!checkoutHost) return;
      gtag("config", CFG.googleAdsId, {
        linker: { domains: [checkoutHost] },
      });
      log("Google Ads linker configured for", checkoutHost);
    } catch (e) {
      warn("setupGtagLinker failed", e);
    }
  }

  function decorateUrlWithLinker(url, callback) {
    if (!CFG.googleAdsId || typeof gtag !== "function") {
      callback(url);
      return;
    }
    try {
      gtag("get", CFG.googleAdsId, "linker:passthrough", function (passthrough) {
        if (passthrough) {
          var sep = url.indexOf("?") !== -1 ? "&" : "?";
          callback(url + sep + "_gl=" + passthrough);
        } else {
          callback(url);
        }
      });
    } catch (e) {
      warn("decorateUrlWithLinker failed", e);
      callback(url);
    }
  }

  // ============================
  // PRELOAD CONTROL
  // ============================

  function requestPreload() {
    // The cart just changed, so any URL we are holding describes the old cart.
    // Dropping it here is what keeps a stale checkout out of the click path if
    // the refresh below fails or is still running when the shopper clicks.
    cartGeneration++;
    preloadedUrl = null;
    preloadedKey = null;

    clearTimeout(preloadTimer);
    preloadTimer = setTimeout(preloadCheckoutUrl, CFG.preloadDebounceMs);
  }

  /**
   * Refreshes the preloaded URL, coalescing concurrent calls.
   *
   * A preload can take seconds (timeout + retries), and cart updates arrive
   * while one is in flight. Dropping those calls left the URL pinned to the
   * cart contents of the first preload, so instead the change is remembered
   * and the loop runs again as soon as the current attempt settles.
   */
  function preloadCheckoutUrl() {
    if (preloadInProgress) {
      preloadQueued = true;
      return preloadPromise;
    }

    preloadInProgress = true;
    preloadPromise = (async function () {
      try {
        do {
          preloadQueued = false;
          await preloadOnce();
        } while (preloadQueued);
      } finally {
        preloadInProgress = false;
        preloadPromise = null;
      }
    })();

    return preloadPromise;
  }

  async function preloadOnce() {
    const generation = cartGeneration;

    try {
      const cart = await getCartSafe();
      if (!cart || !cart.items || cart.items.length === 0) {
        if (generation === cartGeneration) {
          preloadedUrl = null;
          preloadedKey = null;
        }
        return;
      }

      const key = cartExternalId(cart);
      if (preloadedUrl && preloadedKey === key && generation === cartGeneration) {
        log("Preloaded URL still matches the cart, skipping endpoint call");
        return;
      }

      const url = await createHurrryUrl(cart);

      if (generation !== cartGeneration) {
        log("Cart changed while preloading, discarding stale URL");
        return;
      }

      preloadedUrl = url;
      preloadedKey = key;
      log("Preloaded URL ready");
    } catch (e) {
      if (generation === cartGeneration) {
        preloadedUrl = null;
        preloadedKey = null;
      }
      warn("Preload failed", e);
    }
  }

  // ============================
  // BUTTON INTERCEPT
  // ============================

  function wireCheckoutButtons() {
    function attach() {
      CFG.selectors.forEach((sel) => {
        let nodes;
        try {
          nodes = document.querySelectorAll(sel);
        } catch (e) {
          warn("Invalid selector", sel, e);
          return;
        }
        nodes.forEach((btn) => {
          if (btn.dataset.hurrryBound) return;
          btn.dataset.hurrryBound = "1";

          btn.addEventListener(
            "click",
            function (e) {
              e.preventDefault();
              e.stopPropagation();
              onCheckoutClick();
            },
            { capture: true },
          );
        });
      });
    }

    attach();

    const obs = new MutationObserver(attach);
    obs.observe(document.documentElement, { childList: true, subtree: true });
  }

  async function onCheckoutClick() {
    // A cart update may still be refreshing the URL; waiting for it is both
    // faster and safer than building a second one for the same cart.
    if (!preloadedUrl && preloadInProgress) {
      try {
        await preloadPromise;
      } catch (e) {
        warn("Waiting for preload failed", e);
      }
    }

    if (preloadedUrl) {
      redirectTo(preloadedUrl);
      return;
    }

    if (CFG.fallbackToStore) {
      redirectTo(CFG.checkoutUrl);
      requestPreload();
      return;
    }

    try {
      const cart = await getCartSafe();
      if (!cart || !cart.items || cart.items.length === 0) {
        redirectTo(CFG.checkoutUrl);
        return;
      }
      const generation = cartGeneration;
      const url = await createHurrryUrl(cart);
      if (generation === cartGeneration) {
        preloadedUrl = url;
        preloadedKey = cartExternalId(cart);
      }
      redirectTo(url);
    } catch (e) {
      err("On-demand creation failed", e);
      redirectTo(CFG.checkoutUrl);
    }
  }

  function redirectTo(url) {
    decorateUrlWithLinker(url, function (decoratedUrl) {
      _doRedirect(decoratedUrl);
    });
  }

  function _doRedirect(url) {
    if (CFG.iframe) {
      try {
        document.documentElement.style.overflow = "hidden";
        document.body.style.overflow = "hidden";

        const iframe = document.createElement("iframe");
        iframe.src = url;
        iframe.style.position = "fixed";
        iframe.style.top = "0";
        iframe.style.left = "0";
        iframe.style.width = "100vw";
        iframe.style.height = "100vh";
        iframe.style.border = "none";
        iframe.style.margin = "0";
        iframe.style.padding = "0";
        iframe.style.zIndex = "999999999";
        iframe.style.background = "#fff";
        iframe.setAttribute("allow", "payment *; clipboard-write *");

        document.body.innerHTML = "";
        document.body.appendChild(iframe);
        log("Opened checkout in iframe");
      } catch (e) {
        err("Iframe load failed, falling back to redirect", e);
        window.location.href = url;
      }
    } else {
      try {
        window.location.assign(url);
      } catch (e) {
        window.location.href = url;
      }
    }
  }

  // ============================
  // CART CHANGE DETECTION
  // ============================

  function isCartMutationUrl(url) {
    if (!url) return false;

    // Our own cart reads go through the same transports we watch; treating one
    // as a cart change would make the plugin preload itself in a loop.
    if (url.indexOf("hurrry_get_cart") !== -1 || url.indexOf("_hurrry=") !== -1) return false;

    return (
      // Every WooCommerce AJAX action except our own, which is filtered out
      // above: themes rename and add cart actions freely, and a preload we did
      // not need is far cheaper than a checkout built from a stale cart.
      url.indexOf("wc-ajax=") !== -1 ||
      // Store API, used by the cart/checkout blocks and most headless themes.
      /\/wc\/store\/(v\d+\/)?(cart|batch)/.test(url) ||
      // WooCommerce still routes some cart actions through admin-ajax.
      /admin-ajax\.php/.test(url)
    );
  }

  function observeCartChanges() {
    // fetch(): Store API and modern themes.
    const origFetch = window.fetch;
    if (origFetch && !origFetch._hurrryHooked) {
      const wrapped = async function (...args) {
        const req = args[0];
        const url = typeof req === "string" ? req : req && req.url ? req.url : "";

        const resp = await origFetch.apply(this, args);

        if (isCartMutationUrl(url)) {
          requestPreload();
        }

        return resp;
      };

      wrapped._hurrryHooked = true;
      window.fetch = wrapped;
    }

    // XMLHttpRequest: WooCommerce core still uses jQuery.ajax for the classic
    // cart, add-to-cart buttons and fragment refreshes.
    const XHR = window.XMLHttpRequest;
    if (XHR && XHR.prototype && !XHR.prototype.open._hurrryHooked) {
      const origOpen = XHR.prototype.open;
      const patchedOpen = function (method, url) {
        this._hurrryUrl = url;
        return origOpen.apply(this, arguments);
      };
      patchedOpen._hurrryHooked = true;
      XHR.prototype.open = patchedOpen;

      const origSend = XHR.prototype.send;
      XHR.prototype.send = function () {
        try {
          this.addEventListener("load", function () {
            if (isCartMutationUrl(this._hurrryUrl || "")) {
              requestPreload();
            }
          });
        } catch (e) {
          warn("XHR hook failed", e);
        }
        return origSend.apply(this, arguments);
      };
    }

    // WooCommerce jQuery events, the most reliable signal when a theme
    // bypasses both transports above.
    if (window.jQuery) {
      window
        .jQuery(document.body)
        .on(
          "added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed wc_fragments_loaded updated_wc_div updated_shipping_method applied_coupon removed_coupon",
          function () {
            requestPreload();
          },
        );
    }

    // Classic add-to-cart forms and quantity updates are plain submits.
    document.addEventListener(
      "click",
      function (e) {
        const form = e.target?.closest?.("form.cart, form.woocommerce-cart-form");
        if (form) requestPreload();
      },
      { capture: true },
    );

    // The cart survives back/forward navigation caches.
    window.addEventListener("pageshow", function (e) {
      if (e.persisted) requestPreload();
    });
  }

  // ============================
  // API
  // ============================

  /**
   * Reads the cart, trying every configured transport in order.
   *
   * The WooCommerce AJAX endpoint comes first because it is an ordinary
   * front-end request: WooCommerce boots the shopper's own session and cart for
   * it, exactly as it does for add-to-cart. The REST route is a fallback, since
   * /wp-json/ requests lose the auth cookie when they carry no nonce and are a
   * favourite target of page and CDN caches.
   *
   * A transport that answers with an empty cart is not trusted while another
   * one is left to try: an empty answer is precisely what a broken transport
   * looks like.
   */
  async function getCartSafe() {
    if (bootstrapCart) {
      const snapshot = bootstrapCart;
      bootstrapCart = null;
      if (snapshot.items && snapshot.items.length > 0) {
        log("Using the server-rendered cart snapshot");
        return snapshot;
      }
    }

    const urls = (Array.isArray(CFG.cartUrls) && CFG.cartUrls.length ? CFG.cartUrls : [CFG.cartUrl]).filter(Boolean);

    let firstValid = null;

    for (let i = 0; i < urls.length; i++) {
      const cart = await fetchCart(urls[i]);
      if (!cart) continue;

      if (!firstValid) firstValid = cart;

      if (cart.items.length > 0) {
        if (i > 0) log("Cart read from fallback transport", urls[i]);
        return cart;
      }

      warn("Cart source returned an empty cart", urls[i], cart._diag || "");
    }

    return firstValid;
  }

  async function fetchCart(url) {
    try {
      const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
      if (CFG.restNonce) headers["X-WP-Nonce"] = CFG.restNonce;

      const r = await fetchWithTimeout(
        cacheBust(url),
        { method: "GET", credentials: "same-origin", cache: "no-store", headers },
        CFG.fetchTimeoutMs,
      );

      if (!r.ok) throw new Error("cart endpoint failed with " + r.status);

      const cart = await r.json();

      // A cache or a security plugin answering with something else entirely
      // must not be mistaken for an empty cart.
      if (!cart || typeof cart !== "object" || !Array.isArray(cart.items)) {
        throw new Error("cart endpoint returned an unexpected body");
      }

      return cart;
    } catch (e) {
      warn("Cart read failed", url, e);
      return null;
    }
  }

  /**
   * Cart responses must never come from a cache; the query parameter defeats
   * the intermediaries that ignore Cache-Control.
   */
  function cacheBust(url) {
    const sep = url.indexOf("?") !== -1 ? "&" : "?";
    return url + sep + "_hurrry=" + ++cacheBustCounter + "." + Math.floor(performance.now());
  }

  async function createHurrryUrl(cart) {
    const payload = buildPayload(cart);
    let lastErr = null;

    for (let attempt = 0; attempt <= CFG.retries; attempt++) {
      try {
        const resp = await fetchWithTimeout(
          CFG.endpoint,
          {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
          },
          CFG.fetchTimeoutMs,
        );

        if (!resp.ok) throw new Error("Endpoint error");

        const json = await resp.json();

        const rawUrl = json.hostedUrl || json.redirectUrl || json.url || json.direct_link;

        if (!rawUrl) throw new Error("No URL in response");

        return withQuery(rawUrl, {
          defaultLang: CFG.defaultLang,
        });
      } catch (e) {
        lastErr = e;
        warn("Retrying create URL", e);
      }
    }

    throw lastErr;
  }

  function fetchWithTimeout(url, options, timeoutMs) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeoutMs);
    const opts = Object.assign({}, options || {}, { signal: ctrl.signal });

    return fetch(url, opts).finally(() => clearTimeout(t));
  }

  function withQuery(url, params) {
    const u = new URL(url, window.location.origin);
    Object.keys(params || {}).forEach((k) => {
      if (params[k] !== undefined && params[k] !== null && params[k] !== "") {
        u.searchParams.set(k, String(params[k]));
      }
    });
    return u.toString();
  }

  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(";").shift();
    return null;
  }

  /**
   * Stable id for the current cart.
   *
   * The backend deduplicates by externalId, so a timestamp-based id would turn
   * every preload into a brand new order: a shopper who reloaded the page five
   * times would produce five orders and five payment rows stuck in "created".
   *
   * The cart token identifies the cart across reloads; the contents are hashed
   * in so that changing the cart does start a new order, which is what the
   * checkout needs. Carts of different shoppers never collide: the token comes
   * from the WooCommerce session, which is per browser.
   */
  function cartExternalId(cart) {
    const contents = (cart.items || []).map((li) => [li.id, li.quantity, li.line_price].join(":")).join("|");
    return "CART-" + cartToken(cart) + "-" + hash36(contents + "|" + (cart.currency || ""));
  }

  function cartToken(cart) {
    if (cart.token) return String(cart.token).slice(0, 32);
    // Sessions that have not been persisted yet still need reloads to converge
    // on one id, so fall back to a per-tab one.
    try {
      let fallback = sessionStorage.getItem("hurrry_cart_token");
      if (!fallback) {
        fallback = Math.random().toString(36).slice(2, 12);
        sessionStorage.setItem("hurrry_cart_token", fallback);
      }
      return fallback;
    } catch (e) {
      return "notoken";
    }
  }

  function hash36(str) {
    let h = 5381;
    for (let i = 0; i < str.length; i++) {
      h = ((h << 5) + h + str.charCodeAt(i)) >>> 0;
    }
    return h.toString(36);
  }

  function buildPayload(cart) {
    const currency = cart.currency || cart.items[0]?.currency || "USD";

    const items = (cart.items || []).map((li) => {
      const qty = Number(li.quantity) || 1;
      const unitAmount = qty > 0 ? Math.round(li.line_price / qty) : 0;
      const productId = li.sku && li.sku.length ? li.sku : String(li.product_id || li.id);

      return {
        productId,
        sku: li.sku || "",
        name: li.product_title + (li.variant_title ? " - " + li.variant_title : ""),
        imageUrl: li.image || (li.featured_image ? li.featured_image.url : "") || "",
        quantity: qty,
        unitAmount,
        taxAmount: 0,
      };
    });

    return {
      externalId: cartExternalId(cart),
      currency,
      showCurrency: currency,
      items,
      shopUrl: CFG.shopUrl || window.location.hostname || "",
      fbp: getCookie("_fbp") || "",
      fbc: getCookie("_fbc") || "",
      ttc: getCookie("ttclid") || "",
      ttp: getCookie("_ttp") || "",
      notes: CFG.notes || "WooCommerce Cart Inline",
    };
  }

  // ============================
  // ULTRA SHIELD
  // ============================

  function installUltraShield() {
    const checkoutPath = (function () {
      try {
        return new URL(CFG.checkoutUrl, window.location.origin).pathname.replace(/\/+$/, "");
      } catch (e) {
        return "/checkout";
      }
    })();

    function isCheckoutUrl(url) {
      if (!url) return false;
      try {
        const u = new URL(url, window.location.origin);
        if (u.host !== window.location.host) return false;
        return u.pathname.replace(/\/+$/, "") === checkoutPath;
      } catch {
        return false;
      }
    }

    function smartRedirect() {
      if (preloadedUrl) {
        redirectTo(preloadedUrl);
      } else {
        // No URL yet: onCheckoutClick waits for an in-flight preload and
        // applies the store fallback itself.
        onCheckoutClick();
      }
    }

    document.addEventListener(
      "click",
      function (e) {
        const link = e.target.closest?.("a");
        if (link && isCheckoutUrl(link.href)) {
          e.preventDefault();
          e.stopPropagation();
          smartRedirect();
        }
      },
      true,
    );

    document.addEventListener(
      "submit",
      function (e) {
        const form = e.target;
        if (form?.action && isCheckoutUrl(form.action)) {
          e.preventDefault();
          e.stopPropagation();
          smartRedirect();
        }
      },
      true,
    );
  }

  window.Hurrry = Hurrry;

  if (window.HurrryConfig) {
    Hurrry.init(window.HurrryConfig);
  }
})(window);
