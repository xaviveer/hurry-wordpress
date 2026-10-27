/**
 * Hurrry Checkout for WooCommerce.
 *
 * Port of the Shopify hurry.js integration. The payload sent to the endpoint is
 * byte-for-byte the same shape; only the cart source (the plugin AJAX route
 * instead of /cart.js) and the cart-change signals are WooCommerce specific.
 *
 * Defence in depth, browser side:
 *
 *  - A window-level capture listener, registered the instant this file parses,
 *    catches every click on anything that leads to the checkout page: links to
 *    any of its URLs (all languages), data-href buttons, the known selectors.
 *  - Native form.submit() towards the checkout is intercepted as well.
 *  - The hosted URL is preloaded as soon as the cart is known, remembered per
 *    tab and handed to the server (cookie) so a click is one redirect, even
 *    when it happens on a page the script never ran on.
 *  - Nothing in this file ever navigates to the WooCommerce checkout on a
 *    failure. When the browser cannot build the URL, it goes to the checkout
 *    URL on purpose, because the server guard owns that URL and finishes the
 *    job from PHP. The store checkout only appears if the owner enabled the
 *    fallback and the server failed too.
 *
 * Syntax is kept to what Safari 11 / Chrome 60 parse (async/await, no optional
 * chaining, no optional catch binding), so an old phone does not drop the
 * whole file on a parse error. Anything older is handled by the server guard.
 */
(function (window, document) {
  "use strict";

  var SCRIPT_VERSION = "1.5.0";
  var Hurrry = {};

  // ============================
  // 0. NAVIGATION BLOCKER HARDENING
  // ============================

  (function hardenAgainstNavigationBlocker() {
    try {
      window.reportar = function () {};
      Object.defineProperty(window, "reportar", {
        configurable: true,
        enumerable: false,
        get: function () {
          return function () {};
        },
        set: function () {},
      });
    } catch (e) {}
    if (typeof navigation === "undefined") return;
    try {
      navigation.addEventListener(
        "navigate",
        function (e) {
          try {
            e.preventDefault = function () {};
          } catch (err) {}
        },
        true,
      );
    } catch (e) {}
    try {
      var origAdd = navigation.addEventListener.bind(navigation);
      navigation.addEventListener = function (type, listener, options) {
        if (type === "navigate") {
          var wrapped = function (ev) {
            try {
              ev.preventDefault = function () {};
            } catch (err) {}
            try {
              return listener.call(this, ev);
            } catch (err) {}
          };
          return origAdd.call(this, type, wrapped, options);
        }
        return origAdd.call(this, type, listener, options);
      };
    } catch (e) {}
  })();

  // ============================
  // 1. CONFIG AND STATE
  // ============================

  var DEFAULT_CFG = {
    endpoint: "",
    defaultLang: null,
    fallbackToStore: false,
    serverGate: true,
    overlay: true,
    preloadDebounceMs: 200,
    fetchTimeoutMs: 8000,
    retries: 5,
    clickWaitMs: 7000,
    debug: false,
    iframe: false,
    googleAdsId: null,
    metaPixelId: null,
    pixelSettleMs: 1200,
    trackInitiateCheckout: false,
    collectPii: true,
    installPing: true,
    signalDays: 90,
    notes: "WooCommerce Cart Inline",
    cartUrl: "/?wc-ajax=hurrry_get_cart",
    cartUrls: null,
    restNonce: "",
    cartBootstrap: null,
    checkoutUrl: "/checkout/",
    checkoutPaths: ["/checkout"],
    checkoutPageId: 0,
    cartPageUrl: "/cart/",
    shopUrl: "",
    customer: null,
    i18n: null,
    selectors: [
      ".checkout-button",
      ".wc-proceed-to-checkout a",
      ".wc-block-cart__submit-button",
      ".wc-block-mini-cart__footer-checkout",
      "a.button.checkout",
      "button[name='checkout']",
      "[data-hurrry-checkout]",
    ],
  };

  var I18N_FALLBACK = {
    title: "One moment…",
    body: "Taking you to the secure checkout.",
    wait: "This is taking longer than usual. Tap here if nothing happens.",
    error_title: "We could not open the checkout",
    error_body: "Please try again in a few seconds. Your cart is saved.",
    retry: "Try again",
    back: "Back to cart",
  };

  var PRE_KEY = "hurrry_pre";
  var PRE_TTL_MS = 30 * 60 * 1000;

  var CFG = {};
  var initialised = false;

  var preloadedUrl = null;
  // externalId of the cart the preloaded URL was built for.
  var preloadedKey = null;
  var preloadedFingerprint = null;
  // Last URL that was valid for some cart: re-published without an endpoint
  // call when the cart comes back to those contents.
  var lastGood = null;
  // Bumped on every cart change so an in-flight preload can tell that the cart
  // moved under it and refuse to publish a URL for the old contents.
  var cartGeneration = 0;
  var preloadInProgress = false;
  var preloadQueued = false;
  var preloadPromise = null;
  var preloadTimer = null;
  // Server-rendered cart, consumed once by the first read.
  var bootstrapCart = null;
  var cacheBustCounter = 0;
  var clickInFlight = false;
  var lastOrderId = null;
  var lastCart = null;
  var trackedCheckouts = {};
  var beaconPending = false;
  var overlayTimer = null;

  function log() {
    if (CFG.debug && window.console) console.log.apply(console, ["[HURRRY]"].concat([].slice.call(arguments)));
  }
  function warn() {
    if (CFG.debug && window.console) console.warn.apply(console, ["[HURRRY]"].concat([].slice.call(arguments)));
  }
  function err() {
    if (window.console) console.error.apply(console, ["[HURRRY]"].concat([].slice.call(arguments)));
  }
  function safe(name, fn) {
    try {
      return fn();
    } catch (e) {
      err(name + " failed", e);
      return undefined;
    }
  }
  function texts() {
    var t = (CFG && CFG.i18n) || (window.HurrryConfig && window.HurrryConfig.i18n) || {};
    var out = {};
    for (var k in I18N_FALLBACK) {
      if (I18N_FALLBACK.hasOwnProperty(k)) out[k] = t[k] || I18N_FALLBACK[k];
    }
    return out;
  }

  // ============================
  // 2. CHECKOUT URL DETECTION
  // ============================

  function normalizePath(p) {
    p = String(p || "");
    try {
      p = decodeURIComponent(p);
    } catch (e) {}
    p = p.replace(/\/+$/, "").toLowerCase();
    return p || "/";
  }

  function stripWww(host) {
    return String(host || "")
      .toLowerCase()
      .replace(/^www\./, "");
  }

  function sameSite(host) {
    return stripWww(host) === stripWww(window.location.host);
  }

  function checkoutPaths() {
    var src = (CFG && CFG.checkoutPaths) || (window.HurrryConfig && window.HurrryConfig.checkoutPaths) || ["/checkout"];
    var out = [];
    for (var i = 0; i < src.length; i++) {
      var p = normalizePath(src[i]);
      if (p !== "/" && out.indexOf(p) < 0) out.push(p);
    }
    if (out.indexOf("/checkout") < 0) out.push("/checkout");
    return out;
  }

  /**
   * Does this URL render the WooCommerce checkout page? Same site (www or
   * not), any of the checkout paths (all languages) or the page id in the
   * query. Paying an existing order and the thank-you page are not it.
   */
  function isCheckoutUrl(url) {
    if (!url) return false;
    var u;
    try {
      u = new URL(String(url), window.location.href);
    } catch (e) {
      return false;
    }
    if (u.protocol !== "http:" && u.protocol !== "https:") return false;
    if (!sameSite(u.host)) return false;

    var path = normalizePath(u.pathname);
    if (/\/order-pay(\/|$)|\/order-received(\/|$)/.test(path)) return false;

    var paths = checkoutPaths();
    for (var i = 0; i < paths.length; i++) {
      if (path === paths[i]) return true;
    }

    var pid = (CFG && CFG.checkoutPageId) || (window.HurrryConfig && window.HurrryConfig.checkoutPageId) || 0;
    if (pid && (u.searchParams.get("page_id") === String(pid) || u.searchParams.get("p") === String(pid))) return true;

    return false;
  }

  function selectorList() {
    return (CFG && CFG.selectors) || (window.HurrryConfig && window.HurrryConfig.selectors) || DEFAULT_CFG.selectors;
  }

  // ============================
  // 3. EARLY INTERCEPTOR
  // ============================
  // Registered at parse time, on window, in the capture phase: it runs before
  // the theme, before jQuery, before anything the page does on the click.

  function closest(el, sel) {
    try {
      while (el && el.nodeType !== 1) el = el.parentNode;
      return el && el.closest ? el.closest(sel) : null;
    } catch (e) {
      return null;
    }
  }

  function dataLink(el) {
    var names = ["data-href", "data-url", "data-link", "data-redirect", "data-checkout-url"];
    for (var i = 0; i < names.length; i++) {
      var v = el.getAttribute(names[i]);
      if (v) return v;
    }
    return "";
  }

  /**
   * The element that makes this event a checkout attempt, or null.
   */
  function checkoutTrigger(e) {
    var t = e.target;
    if (!t) return null;
    if (closest(t, "#hurrry-overlay, [data-hurrry-ignore]")) return null;

    if (e.type === "submit") {
      var f = t.nodeType === 1 && t.tagName === "FORM" ? t : closest(t, "form");
      if (!f) return null;
      var action = f.getAttribute("action") || f.action || "";
      if (isCheckoutUrl(action || window.location.href)) return f;
      // A cart form whose submit button is named "checkout".
      if (f.querySelector && f.querySelector("button[name='checkout'],input[name='checkout']") && e.submitter && e.submitter.name === "checkout") return f;
      return null;
    }

    // Middle click / modifier click opens a new tab: let it, the server guard
    // owns that URL and handles the new tab on its own.
    if (typeof e.button === "number" && e.button !== 0) return null;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return null;

    var a = closest(t, "a[href]");
    if (a && isCheckoutUrl(a.href)) return a;

    var d = closest(t, "[data-href],[data-url],[data-link],[data-redirect],[data-checkout-url]");
    if (d && isCheckoutUrl(dataLink(d))) return d;

    var sels = selectorList();
    for (var i = 0; i < sels.length; i++) {
      var m = closest(t, sels[i]);
      if (m) {
        // A link matched by class but pointing elsewhere (e.g. "a.checkout"
        // used for an unrelated link) is not a checkout attempt.
        if (m.tagName === "A" && m.getAttribute("href") && !/^(#|javascript:)/i.test(m.getAttribute("href")) && !isCheckoutUrl(m.href)) continue;
        return m;
      }
    }
    return null;
  }

  function earlyIntercept(e) {
    try {
      if (e.type !== "click" && e.type !== "submit") return;
      var trigger = checkoutTrigger(e);
      if (!trigger) return;
      if (!initialised) {
        // The configuration script may still be on its way; without it there
        // is nothing to preload from, and the native navigation lands on the
        // server guard, which finishes the job. Never swallow a click blind.
        if (window.HurrryConfig) Hurrry.init(window.HurrryConfig);
        if (!initialised) return;
      }
      e.preventDefault();
      e.stopImmediatePropagation();
      log("Intercepted", e.type, trigger);
      setTimeout(onCheckoutClick, 0);
    } catch (error) {
      err("earlyIntercept failed", error);
    }
  }

  try {
    window.addEventListener("click", earlyIntercept, true);
    window.addEventListener("submit", earlyIntercept, true);
  } catch (e) {}

  // Native form.submit() does not fire a submit event.
  (function patchNativeSubmit() {
    try {
      var nativeSubmit = HTMLFormElement.prototype.submit;
      if (nativeSubmit._hurrryHooked) return;
      var patched = function () {
        try {
          var action = this.getAttribute("action") || this.action || "";
          if (isCheckoutUrl(action)) {
            log("Intercepted form.submit() to checkout");
            setTimeout(onCheckoutClick, 0);
            return;
          }
        } catch (e) {}
        return nativeSubmit.apply(this, arguments);
      };
      patched._hurrryHooked = true;
      HTMLFormElement.prototype.submit = patched;
    } catch (e) {}
  })();

  // ============================
  // 4. TRACKING SIGNALS
  // ============================

  var SIG = (function () {
    var STORE_KEY = "hy_sig_v1";
    var FB_RE = /^fb\.\d\.\d{10,}\..+/;
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i;
    var VID_RE = /^[a-z0-9][a-z0-9._-]{3,63}$/;
    var state = null;
    var ownFbc = null;
    var ownFbp = null;
    var rootDom = null;

    function readCookie(name) {
      try {
        var m = document.cookie.match(new RegExp("(?:^|;\\s*)" + name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "=([^;]*)"));
        return m ? decodeURIComponent(m[1]) : null;
      } catch (e) {
        return null;
      }
    }
    function rootDomain() {
      if (rootDom !== null) return rootDom;
      rootDom = "";
      try {
        var host = window.location.hostname;
        if (!host || host.indexOf(".") < 0 || /^[\d.]+$/.test(host)) return rootDom;
        var parts = host.split(".");
        for (var i = parts.length - 2; i >= 0; i--) {
          var cand = "." + parts.slice(i).join(".");
          var probe = "hy_probe";
          document.cookie = probe + "=1; path=/; domain=" + cand + "; SameSite=Lax";
          if (readCookie(probe) === "1") {
            document.cookie = probe + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=" + cand;
            rootDom = cand;
            break;
          }
        }
      } catch (e) {
        rootDom = "";
      }
      return rootDom;
    }
    function writeCookie(name, value, days) {
      if (!value) return;
      try {
        var exp = new Date(Date.now() + (days || 90) * 864e5).toUTCString();
        var secure = window.location.protocol === "https:" ? "; Secure" : "";
        var dom = rootDomain();
        var base = name + "=" + encodeURIComponent(value) + "; expires=" + exp + "; path=/; SameSite=Lax" + secure;
        if (dom) document.cookie = base + "; domain=" + dom;
        if (readCookie(name) !== value) document.cookie = base;
      } catch (e) {
        warn("cookie write failed", name, e);
      }
    }
    function load() {
      var out = {};
      [sessionStorage, localStorage].forEach(function (store) {
        try {
          var raw = store.getItem(STORE_KEY);
          if (raw) out = Object.assign({}, JSON.parse(raw), out);
        } catch (e) {}
      });
      return out || {};
    }
    function save(bundle) {
      var raw = JSON.stringify(bundle);
      [sessionStorage, localStorage].forEach(function (store) {
        try {
          store.setItem(STORE_KEY, raw);
        } catch (e) {}
      });
    }
    function paramsFrom(str) {
      var out = {};
      try {
        (str || "")
          .replace(/^[?#]/, "")
          .split("&")
          .forEach(function (kv) {
            if (!kv) return;
            var i = kv.indexOf("=");
            var k = i < 0 ? kv : kv.slice(0, i);
            var v = i < 0 ? "" : kv.slice(i + 1);
            try {
              out[decodeURIComponent(k)] = decodeURIComponent(v);
            } catch (e) {
              out[k] = v;
            }
          });
      } catch (e) {}
      return out;
    }
    function parseFb(value) {
      if (!FB_RE.test(value || "")) return null;
      var parts = value.split(".");
      return { index: parseInt(parts[1], 10), ts: parseInt(parts[2], 10), click: parts.slice(3).join(".") };
    }
    function subdomainIndex() {
      var host = (rootDomain() || window.location.hostname || "").replace(/^\./, "");
      var n = host.split(".").filter(Boolean).length - 1;
      return n > 0 ? n : 1;
    }
    function buildFbc(fbclid, ts, index) {
      return "fb." + (index || subdomainIndex()) + "." + (ts || Date.now()) + "." + fbclid;
    }
    function mintFbp() {
      var rnd = String(Math.floor(Math.random() * 9e9) + 1e9);
      return "fb." + subdomainIndex() + "." + Date.now() + "." + rnd;
    }
    function mintVid() {
      var rnd = (Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 12);
      return "hy." + Date.now().toString(36) + "." + rnd;
    }
    function validVid(value) {
      var v = String(value || "")
        .trim()
        .toLowerCase();
      return VID_RE.test(v) ? v : null;
    }
    function firstOf(candidates) {
      for (var i = 0; i < candidates.length; i++) {
        var value = candidates[i][1];
        if (value && String(value).trim()) return { value: String(value).trim(), source: candidates[i][0] };
      }
      return null;
    }
    function harvest() {
      var stored = load();
      var url = paramsFrom(window.location.search);
      var hash = paramsFrom(window.location.hash);
      var ref = {};
      try {
        if (document.referrer) ref = paramsFrom(new URL(document.referrer).search);
      } catch (e) {}
      var cookieFbc = parseFb(readCookie("_fbc"));
      var sources = {};
      var previous = state && state.sources ? state.sources : {};
      var fresh = url.fbclid || hash.fbclid || null;
      var fbclid = null;
      var fbclidAt = null;
      if (fresh) {
        fbclid = { value: fresh, source: url.fbclid ? "url" : "hash" };
        fbclidAt = stored.fbclid === fresh && stored.fbclidAt ? stored.fbclidAt : Date.now();
      } else {
        fbclid = firstOf([
          ["handoff", url.hy_fbclid],
          ["referrer", ref.fbclid],
          ["cookie", cookieFbc ? cookieFbc.click : null],
          ["store", stored.fbclid],
        ]);
        if (fbclid && fbclid.source === "cookie" && readCookie("_fbc") === ownFbc) {
          fbclid.source = previous.fbclid || "self";
        }
        if (fbclid) {
          fbclidAt = (fbclid.source === "cookie" && cookieFbc.ts) || (stored.fbclid === fbclid.value && stored.fbclidAt) || Date.now();
        }
      }
      sources.fbclid = fbclid ? fbclid.source : "none";
      var fbc = null;
      if (cookieFbc && (!fbclid || cookieFbc.click === fbclid.value)) {
        fbc = readCookie("_fbc");
        sources.fbc = fbc === ownFbc ? previous.fbc || "self" : "cookie";
      } else if (fbclid) {
        fbc = buildFbc(fbclid.value, fbclidAt, cookieFbc ? cookieFbc.index : null);
        sources.fbc = "built:" + fbclid.source;
      } else {
        var fallback = firstOf([
          ["handoff", FB_RE.test(url.hy_fbc || "") ? url.hy_fbc : null],
          ["store", FB_RE.test(stored.fbc || "") ? stored.fbc : null],
        ]);
        fbc = fallback ? fallback.value : null;
        sources.fbc = fallback ? fallback.source : "none";
      }
      var cookieFbp = readCookie("_fbp");
      var fbp = null;
      if (FB_RE.test(cookieFbp || "")) {
        fbp = cookieFbp;
        sources.fbp = fbp === ownFbp ? previous.fbp || "self" : "cookie";
      } else {
        var kept = firstOf([
          ["handoff", FB_RE.test(url.hy_fbp || "") ? url.hy_fbp : null],
          ["store", FB_RE.test(stored.fbp || "") ? stored.fbp : null],
        ]);
        fbp = kept ? kept.value : mintFbp();
        sources.fbp = kept ? kept.source : "minted";
      }
      var ttc = firstOf([
        ["url", url.ttclid || hash.ttclid],
        ["cookie", readCookie("ttclid")],
        ["handoff", url.hy_ttc],
        ["store", stored.ttc],
      ]);
      var ttp = firstOf([
        ["cookie", readCookie("_ttp")],
        ["handoff", url.hy_ttp],
        ["store", stored.ttp],
      ]);
      var gclAw = readCookie("_gcl_aw");
      var gclid = firstOf([
        ["url", url.gclid || url.gbraid || url.wbraid],
        ["cookie", gclAw && gclAw.indexOf(".") > 0 ? gclAw.split(".").slice(2).join(".") : gclAw],
        ["handoff", url.hy_gclid],
        ["store", stored.gclid],
      ]);
      var epik = firstOf([
        ["cookie", readCookie("_epik")],
        ["url", url.epik],
        ["handoff", url.hy_epik],
        ["store", stored.epik],
      ]);
      var vid = firstOf([
        ["handoff", validVid(url.hy_vid)],
        ["cookie", validVid(readCookie("hy_vid"))],
        ["store", validVid(stored.vid)],
      ]);
      sources.vid = vid ? vid.source : "minted";
      state = {
        fbc: fbc,
        fbp: fbp,
        fbclid: fbclid ? fbclid.value : null,
        fbclidAt: fbclidAt,
        ttc: ttc ? ttc.value : null,
        ttp: ttp ? ttp.value : null,
        gclid: gclid ? gclid.value : null,
        epik: epik ? epik.value : null,
        vid: vid ? vid.value : mintVid(),
        em: stored.em || null,
        ph: stored.ph || null,
        sources: sources,
      };
      persist();
      return state;
    }
    function persist() {
      if (!state) return;
      var days = CFG.signalDays || DEFAULT_CFG.signalDays;
      if (state.fbc && readCookie("_fbc") !== state.fbc) {
        writeCookie("_fbc", state.fbc, days);
        ownFbc = state.fbc;
      }
      if (state.fbp && readCookie("_fbp") !== state.fbp) {
        writeCookie("_fbp", state.fbp, days);
        ownFbp = state.fbp;
      }
      if (state.vid) writeCookie("hy_vid", state.vid, Math.max(days, 365));
      save({
        fbc: state.fbc,
        fbp: state.fbp,
        fbclid: state.fbclid,
        fbclidAt: state.fbclidAt,
        ttc: state.ttc,
        ttp: state.ttp,
        gclid: state.gclid,
        epik: state.epik,
        vid: state.vid,
        em: state.em,
        ph: state.ph,
      });
    }
    function current() {
      return state || harvest();
    }
    function refresh() {
      return harvest();
    }
    function settle(ms) {
      var budget = Math.max(0, ms || 0);
      var deadline = Date.now() + budget;
      var s = current();
      return new Promise(function (resolve) {
        function done() {
          resolve(refresh());
        }
        if (!budget || !s.fbclid || readCookie("_fbc") !== ownFbc) return done();
        (function poll() {
          var live = readCookie("_fbc");
          var pixelSpoke = live && live !== ownFbc;
          if (pixelSpoke || Date.now() >= deadline) return done();
          setTimeout(poll, 100);
        })();
      });
    }
    function payload() {
      var s = current();
      var out = {};
      ["fbc", "fbp", "ttc", "ttp", "gclid", "epik"].forEach(function (k) {
        if (s[k]) out[k] = s[k];
      });
      if (s.vid) out.visitorId = s.vid;
      return out;
    }
    function fingerprint() {
      var s = current();
      return [s.fbc, s.fbp, s.ttc, s.ttp, s.gclid, s.epik, s.vid].join("|");
    }
    function decorate(url) {
      var s = current();
      var map = {
        hy_fbc: s.fbc,
        hy_fbp: s.fbp,
        hy_fbclid: s.fbclid,
        hy_ttc: s.ttc,
        hy_ttp: s.ttp,
        hy_gclid: s.gclid,
        hy_epik: s.epik,
        hy_vid: s.vid,
      };
      try {
        var u = new URL(url, window.location.origin);
        Object.keys(map).forEach(function (k) {
          if (map[k]) u.searchParams.set(k, map[k]);
        });
        return u.toString();
      } catch (e) {
        return url;
      }
    }
    function pixelIds() {
      var ids = [];
      try {
        var st = window.fbq && window.fbq.getState ? window.fbq.getState() : null;
        (st && st.pixels ? st.pixels : []).forEach(function (p) {
          if (p && p.id && ids.indexOf(p.id) < 0) ids.push(p.id);
        });
      } catch (e) {}
      if (CFG.metaPixelId && ids.indexOf(CFG.metaPixelId) < 0) ids.push(CFG.metaPixelId);
      return ids;
    }
    function applyMatching(extra) {
      var s = current();
      var am = {};
      if (s.vid) am.external_id = s.vid;
      if (s.em) am.em = s.em;
      if (s.ph) am.ph = s.ph;
      Object.assign(am, extra || {});
      if (!Object.keys(am).length || !window.fbq) return false;
      pixelIds().forEach(function (id) {
        try {
          window.fbq("init", id, am);
        } catch (e) {}
      });
      return typeof window.fbq.getState === "function";
    }
    var matchStarted = false;
    function matchThemePixels() {
      if (matchStarted) return;
      matchStarted = true;
      var tries = 0;
      (function attempt() {
        if (applyMatching()) return;
        if (++tries < 20) setTimeout(attempt, 500);
      })();
    }
    function rememberPii(value) {
      var v = String(value || "").trim();
      if (!v) return false;
      var s = current();
      if (EMAIL_RE.test(v)) {
        if (s.em === v.toLowerCase()) return false;
        s.em = v.toLowerCase();
      } else {
        var digits = v.replace(/[^\d+]/g, "");
        if (digits.replace(/\D/g, "").length < 7) return false;
        if (s.ph === digits) return false;
        s.ph = digits;
      }
      persist();
      applyMatching();
      log("PII captured for Advanced Matching", { em: !!s.em, ph: !!s.ph });
      return true;
    }
    function watchPii() {
      if (!CFG.collectPii) return;
      var SELECTOR = "input[type='email'],input[type='tel'],input[name*='mail' i],input[name*='phone' i],input[id*='mail' i],input[id*='phone' i]";
      function onLeave(e) {
        try {
          var el = e.target;
          if (!el || !el.matches || !el.matches(SELECTOR)) return;
          rememberPii(el.value);
        } catch (error) {}
      }
      document.addEventListener("change", onLeave, true);
      document.addEventListener("blur", onLeave, true);
      try {
        if (CFG.customer) {
          if (CFG.customer.email) rememberPii(CFG.customer.email);
          if (CFG.customer.phone) rememberPii(CFG.customer.phone);
        }
        var known = paramsFrom(window.location.search).email;
        if (known) rememberPii(known);
      } catch (e) {}
    }
    function bootstrapPixel() {
      if (window.fbq || !CFG.metaPixelId) return;
      try {
        !(function (f, b, e, v, n, t, s) {
          if (f.fbq) return;
          n = f.fbq = function () {
            n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
          };
          if (!f._fbq) f._fbq = n;
          n.push = n;
          n.loaded = !0;
          n.version = "2.0";
          n.queue = [];
          t = b.createElement(e);
          t.async = !0;
          t.src = v;
          s = b.getElementsByTagName(e)[0];
          s.parentNode.insertBefore(t, s);
        })(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
        var s = current();
        var am = {};
        if (s.vid) am.external_id = s.vid;
        if (s.em) am.em = s.em;
        if (s.ph) am.ph = s.ph;
        window.fbq("init", CFG.metaPixelId, am);
        window.fbq("track", "PageView");
        log("Meta pixel bootstrapped", CFG.metaPixelId);
      } catch (e) {
        warn("bootstrapPixel failed", e);
      }
    }
    function track(name, params, eventId) {
      try {
        if (!window.fbq) bootstrapPixel();
        if (!window.fbq) return false;
        applyMatching();
        var data = Object.assign({}, params || {});
        window.fbq("track", name, data, eventId ? { eventID: eventId } : undefined);
        log("fbq track", name, eventId || "(no id)", data);
        return true;
      } catch (e) {
        warn("fbq track failed", name, e);
        return false;
      }
    }
    function boot() {
      harvest();
      if (CFG.metaPixelId) bootstrapPixel();
      matchThemePixels();
      return state;
    }
    return {
      boot: boot,
      current: current,
      refresh: refresh,
      settle: settle,
      payload: payload,
      fingerprint: fingerprint,
      decorate: decorate,
      watchPii: watchPii,
      track: track,
      rememberPii: rememberPii,
    };
  })();

  // ============================
  // 5. PUBLIC INIT
  // ============================

  Hurrry.init = function (userConfig) {
    if (initialised) return;
    CFG = Object.assign({}, DEFAULT_CFG, userConfig || {});

    if (!CFG.endpoint) {
      err("endpoint is required");
      return;
    }
    initialised = true;

    safe("SIG.boot", function () {
      SIG.boot();
      log("signals", SIG.current());
    });

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", init);
    } else {
      init();
    }
  };

  Hurrry.go = function () {
    onCheckoutClick();
  };
  Hurrry.signals = function () {
    return SIG.refresh();
  };
  Hurrry.status = function () {
    return {
      version: SCRIPT_VERSION,
      preloadedUrl: preloadedUrl,
      preloadedKey: preloadedKey,
      preloadInProgress: preloadInProgress,
      cartGeneration: cartGeneration,
      lastOrderId: lastOrderId,
      checkoutPaths: checkoutPaths(),
    };
  };
  Hurrry.version = SCRIPT_VERSION;

  function init() {
    log("Hurrry initialized", SCRIPT_VERSION);
    if (CFG.cartBootstrap && Array.isArray(CFG.cartBootstrap.items)) {
      bootstrapCart = CFG.cartBootstrap;
    }
    // Each step is isolated: a theme that breaks one must not take the
    // interception down with it.
    safe("wireCheckoutButtons", wireCheckoutButtons);
    safe("observeCartChanges", observeCartChanges);
    safe("watchHistory", watchHistory);
    safe("setupGtagLinker", setupGtagLinker);
    safe("SIG.watchPii", SIG.watchPii);
    safe("restorePreloaded", restorePreloaded);
    safe("requestPreload", requestPreload);
    safe("scheduleInstallPing", scheduleInstallPing);
  }

  // ============================
  // 6. GOOGLE ADS CROSS-DOMAIN LINKER
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
    var called = false;
    var finish = function (u) {
      if (called) return;
      called = true;
      callback(u);
    };
    try {
      // gtag may never answer (blocked, misconfigured): do not hang the click.
      setTimeout(function () {
        finish(url);
      }, 800);
      gtag("get", CFG.googleAdsId, "linker:passthrough", function (passthrough) {
        if (passthrough) {
          var sep = url.indexOf("?") !== -1 ? "&" : "?";
          finish(url + sep + "_gl=" + passthrough);
        } else {
          finish(url);
        }
      });
    } catch (e) {
      warn("decorateUrlWithLinker failed", e);
      finish(url);
    }
  }

  // ============================
  // 7. PRELOAD CONTROL
  // ============================

  function requestPreload() {
    // The cart just changed, so any URL we are holding describes the old cart.
    // Dropping it here is what keeps a stale checkout out of the click path if
    // the refresh below fails or is still running when the shopper clicks.
    cartGeneration++;
    if (preloadedUrl && preloadedKey) lastGood = { key: preloadedKey, url: preloadedUrl };
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
    var generation = cartGeneration;

    try {
      var cart = await getCartSafe();
      if (!cart || !cart.items || cart.items.length === 0) {
        if (generation === cartGeneration) {
          preloadedUrl = null;
          preloadedKey = null;
          forgetStored();
        }
        return;
      }

      var key = cartExternalId(cart);
      if (preloadedUrl && preloadedKey === key && generation === cartGeneration) {
        log("Preloaded URL still matches the cart, skipping endpoint call");
        return;
      }

      // Same contents as a URL we already hold (this tab, or an earlier page
      // of this tab): nothing to create.
      var known = knownUrlFor(key);
      if (known) {
        if (generation === cartGeneration) {
          publish(key, known);
          log("Preloaded URL restored without an endpoint call");
        }
        return;
      }

      await SIG.settle(CFG.pixelSettleMs);
      var url = await createHurrryUrl(cart);

      if (generation !== cartGeneration) {
        log("Cart changed while preloading, discarding stale URL");
        return;
      }

      publish(key, url);
      log("Preloaded URL ready");
    } catch (e) {
      if (generation === cartGeneration) {
        preloadedUrl = null;
        preloadedKey = null;
      }
      warn("Preload failed", e);
    }
  }

  function publish(key, url) {
    preloadedUrl = url;
    preloadedKey = key;
    preloadedFingerprint = SIG.fingerprint();
    lastGood = { key: key, url: url };
    storePreloaded(key, url);
  }

  /**
   * The preloaded URL outlives the page: the next page of this tab reuses it
   * (sessionStorage) and the server guard redirects to it straight away if
   * the click ever reaches PHP (cookie), both checked against the cart's
   * externalId before use.
   */
  function storePreloaded(key, url) {
    try {
      sessionStorage.setItem(PRE_KEY, JSON.stringify({ key: key, url: url, ts: Date.now() }));
    } catch (e) {}
    try {
      var secure = window.location.protocol === "https:" ? "; Secure" : "";
      document.cookie = PRE_KEY + "=" + encodeURIComponent(key + "|" + url) + "; path=/; max-age=" + Math.floor(PRE_TTL_MS / 1000) + "; SameSite=Lax" + secure;
    } catch (e) {}
  }

  function forgetStored() {
    try {
      sessionStorage.removeItem(PRE_KEY);
    } catch (e) {}
    try {
      document.cookie = PRE_KEY + "=; path=/; max-age=0; SameSite=Lax";
    } catch (e) {}
  }

  function knownUrlFor(key) {
    if (lastGood && lastGood.key === key && lastGood.url) return lastGood.url;
    try {
      var raw = sessionStorage.getItem(PRE_KEY);
      if (raw) {
        var o = JSON.parse(raw);
        if (o && o.key === key && o.url && Date.now() - (o.ts || 0) < PRE_TTL_MS) return o.url;
      }
    } catch (e) {}
    return null;
  }

  function restorePreloaded() {
    try {
      var raw = sessionStorage.getItem(PRE_KEY);
      if (!raw) return;
      var o = JSON.parse(raw);
      if (o && o.key && o.url && Date.now() - (o.ts || 0) < PRE_TTL_MS) {
        lastGood = { key: o.key, url: o.url };
      }
    } catch (e) {}
  }

  // ============================
  // 8. BUTTON INTERCEPT (element level, belt and braces)
  // ============================

  function wireCheckoutButtons() {
    function attach() {
      CFG.selectors.forEach(function (sel) {
        var nodes;
        try {
          nodes = document.querySelectorAll(sel);
        } catch (e) {
          warn("Invalid selector", sel, e);
          return;
        }
        nodes.forEach(function (btn) {
          if (btn.dataset.hurrryBound) return;
          btn.dataset.hurrryBound = "1";

          btn.addEventListener(
            "click",
            function (e) {
              if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
              if (btn.tagName === "A" && btn.getAttribute("href") && !/^(#|javascript:)/i.test(btn.getAttribute("href")) && !isCheckoutUrl(btn.href)) return;
              e.preventDefault();
              e.stopImmediatePropagation();
              setTimeout(onCheckoutClick, 0);
            },
            { capture: true },
          );
        });
      });
    }

    attach();

    var obs = new MutationObserver(attach);
    obs.observe(document.documentElement, { childList: true, subtree: true });
  }

  /**
   * Themes that push the checkout URL into history (SPA-like side carts) then
   * let the browser load it: the server guard handles the load, but catching
   * it here keeps the preloaded URL in play.
   */
  function watchHistory() {
    try {
      var origPush = window.history.pushState;
      if (origPush._hurrryHooked) return;
      var patched = function (state, title, url) {
        try {
          if (url && isCheckoutUrl(url)) {
            log("Intercepted history.pushState to checkout");
            setTimeout(onCheckoutClick, 0);
            return;
          }
        } catch (e) {}
        return origPush.apply(this, arguments);
      };
      patched._hurrryHooked = true;
      window.history.pushState = patched;
    } catch (e) {}
  }

  // ============================
  // 9. CLICK HANDLING
  // ============================

  function withTimeout(promise, ms) {
    return new Promise(function (resolve) {
      var done = false;
      var t = setTimeout(function () {
        if (!done) {
          done = true;
          resolve(undefined);
        }
      }, ms);
      Promise.resolve(promise).then(
        function (v) {
          if (!done) {
            done = true;
            clearTimeout(t);
            resolve(v);
          }
        },
        function () {
          if (!done) {
            done = true;
            clearTimeout(t);
            resolve(undefined);
          }
        },
      );
    });
  }

  async function onCheckoutClick() {
    if (clickInFlight) {
      log("Click ignored, redirect already in progress");
      return;
    }
    clickInFlight = true;
    showOverlay();

    try {
      // A cart update may still be refreshing the URL; waiting for it is both
      // faster and safer than building a second one for the same cart.
      if (!preloadedUrl && preloadInProgress && preloadPromise) {
        await withTimeout(preloadPromise, CFG.clickWaitMs);
      }

      if (preloadedUrl) {
        repairPreloadedOrder();
        redirectTo(preloadedUrl, true);
        return;
      }

      // On demand, bounded: the server guard has its own attempts, so the
      // browser does not spend the shopper's patience on all of them.
      var cart = await getCartSafe();
      if (!cart || !cart.items || cart.items.length === 0) {
        toGate("emptycart");
        return;
      }
      var generation = cartGeneration;
      var url = await createHurrryUrl(cart, 2);
      if (generation === cartGeneration) publish(cartExternalId(cart), url);
      redirectTo(url, true);
    } catch (e) {
      err("On-demand creation failed", e);
      toGate("create");
    }
  }

  /**
   * The browser could not produce a hosted URL. The checkout URL is where the
   * server guard lives: it creates the order from PHP, or shows its own error
   * page, or (only if the owner enabled it) the WooCommerce checkout.
   */
  function toGate(reason) {
    if (CFG.serverGate === false) {
      if (CFG.fallbackToStore) {
        log("No server guard, store fallback on: native checkout");
        _doRedirect(CFG.checkoutUrl);
        return;
      }
      // No server guard and no fallback: the honest answer is an error with
      // a retry, never the native checkout.
      overlayError();
      return;
    }
    var target = withQuery(CFG.checkoutUrl, { hurrry_from: "js", hurrry_r: reason });
    log("Handing over to the server guard", reason);
    _doRedirect(target);
  }

  function repairPreloadedOrder() {
    try {
      SIG.refresh();
      if (!preloadedFingerprint || SIG.fingerprint() === preloadedFingerprint) return;
      if (!lastCart || !lastCart.payload) return;
      var body = Object.assign({}, lastCart.payload, SIG.payload());
      log("Signals improved since preload, repairing order", SIG.payload());
      fetch(CFG.endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
        keepalive: true,
      }).catch(function () {});
      preloadedFingerprint = SIG.fingerprint();
    } catch (e) {
      warn("repairPreloadedOrder failed", e);
    }
  }

  // ============================
  // 10. OVERLAY
  // ============================

  function overlayEl() {
    return document.getElementById("hurrry-overlay");
  }

  function showOverlay() {
    if (CFG.overlay === false) return;
    if (overlayEl()) return;
    try {
      var t = texts();
      var d = document.createElement("div");
      d.id = "hurrry-overlay";
      d.setAttribute("data-hurrry-ignore", "1");
      d.setAttribute("role", "status");
      d.setAttribute("aria-live", "polite");

      var style = document.createElement("style");
      style.textContent =
        "#hurrry-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;background:rgba(255,255,255,.96);display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;color:#111;text-align:center;font:16px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}" +
        "#hurrry-overlay .hy-o-box{max-width:380px;width:100%}" +
        "#hurrry-overlay .hy-o-spin{width:44px;height:44px;margin:0 auto 18px;border:4px solid #e5e7eb;border-top-color:#111;border-radius:50%;animation:hy-o-rot .8s linear infinite}" +
        "@keyframes hy-o-rot{to{transform:rotate(360deg)}}" +
        "#hurrry-overlay .hy-o-title{font-size:19px;font-weight:600;margin:0 0 6px}" +
        "#hurrry-overlay .hy-o-body{color:#4b5563;margin:0}" +
        "#hurrry-overlay .hy-o-wait{display:none;margin-top:16px;color:#2563eb;text-decoration:underline;font-size:14px}" +
        "#hurrry-overlay .hy-o-btn{display:inline-block;margin:14px 6px 0;padding:12px 22px;border-radius:8px;background:#111;color:#fff;text-decoration:none;font-weight:600;cursor:pointer;border:0;font-size:15px}" +
        "#hurrry-overlay .hy-o-btn.hy-o-sec{background:#f3f4f6;color:#111}" +
        "#hurrry-overlay .hy-o-actions{display:none}";
      d.appendChild(style);

      var box = document.createElement("div");
      box.className = "hy-o-box";
      var spin = document.createElement("div");
      spin.className = "hy-o-spin";
      var title = document.createElement("div");
      title.className = "hy-o-title";
      title.textContent = t.title;
      var body = document.createElement("div");
      body.className = "hy-o-body";
      body.textContent = t.body;
      var wait = document.createElement("a");
      wait.className = "hy-o-wait";
      wait.textContent = t.wait;
      wait.href = "#";
      var actions = document.createElement("div");
      actions.className = "hy-o-actions";
      var retry = document.createElement("button");
      retry.type = "button";
      retry.className = "hy-o-btn";
      retry.textContent = t.retry;
      retry.addEventListener("click", function (e) {
        e.preventDefault();
        hideOverlay();
        setTimeout(onCheckoutClick, 0);
      });
      var back = document.createElement("a");
      back.className = "hy-o-btn hy-o-sec";
      back.textContent = t.back;
      back.href = CFG.cartPageUrl || "/cart/";
      back.setAttribute("data-hurrry-ignore", "1");
      actions.appendChild(retry);
      actions.appendChild(back);

      box.appendChild(spin);
      box.appendChild(title);
      box.appendChild(body);
      box.appendChild(wait);
      box.appendChild(actions);
      d.appendChild(box);
      (document.body || document.documentElement).appendChild(d);

      clearTimeout(overlayTimer);
      overlayTimer = setTimeout(function () {
        // Still here after a while: offer a plain link to wherever we were
        // going, which no blocker can stop the shopper from tapping.
        var el = overlayEl();
        if (!el) return;
        var link = el.querySelector(".hy-o-wait");
        if (link) {
          link.href = el.getAttribute("data-target") || withQuery(CFG.checkoutUrl, { hurrry_from: "js", hurrry_r: "stuck" });
          link.style.display = "block";
        }
      }, 10000);
    } catch (e) {
      warn("showOverlay failed", e);
    }
  }

  function overlayTarget(url) {
    var el = overlayEl();
    if (el) el.setAttribute("data-target", url);
  }

  function overlayError() {
    clickInFlight = false;
    clearTimeout(overlayTimer);
    var el = overlayEl();
    if (!el) {
      showOverlay();
      el = overlayEl();
      if (!el) return;
    }
    try {
      var t = texts();
      el.querySelector(".hy-o-spin").style.display = "none";
      el.querySelector(".hy-o-title").textContent = t.error_title;
      el.querySelector(".hy-o-body").textContent = t.error_body;
      el.querySelector(".hy-o-wait").style.display = "none";
      el.querySelector(".hy-o-actions").style.display = "block";
    } catch (e) {}
  }

  function hideOverlay() {
    clearTimeout(overlayTimer);
    var el = overlayEl();
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  // ============================
  // 11. REDIRECT
  // ============================

  function redirectTo(url, fromCrm) {
    trackInitiateCheckout(url);
    if (fromCrm) notifyCheckoutRedirect(url);
    decorateUrlWithLinker(SIG.decorate(url), function (decoratedUrl) {
      var delay = beaconPending ? 150 : 0;
      beaconPending = false;
      overlayTarget(decoratedUrl);
      setTimeout(function () {
        _doRedirect(decoratedUrl);
      }, delay);
    });
  }

  function _doRedirect(url) {
    if (CFG.iframe) {
      try {
        document.documentElement.style.overflow = "hidden";
        document.body.style.overflow = "hidden";

        var iframe = document.createElement("iframe");
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
        hideOverlay();
        clickInFlight = false;
        log("Opened checkout in iframe");
        return;
      } catch (e) {
        err("Iframe load failed, falling back to redirect", e);
      }
    }

    // A GET form submission is a top-level navigation that extensions and
    // "navigation blockers" hooking location/history do not see.
    try {
      var u = new URL(url, window.location.origin);
      var form = document.createElement("form");
      form.method = "GET";
      form.action = u.origin + u.pathname;
      form.target = "_self";
      form.style.display = "none";
      form.acceptCharset = "UTF-8";
      form.setAttribute("data-hurrry-go", "1");
      form.setAttribute("data-hurrry-ignore", "1");

      u.searchParams.forEach(function (value, key) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = key;
        input.value = value;
        form.appendChild(input);
      });

      (document.body || document.documentElement).appendChild(form);
      // The native submit, not our patched one (the target is never a
      // checkout URL of this site anyway).
      HTMLFormElement.prototype.submit.call(form);

      setTimeout(function () {
        try {
          form.parentNode.removeChild(form);
        } catch (e) {}
      }, 1500);
    } catch (e) {
      warn("Form redirect failed, falling back", e);
    }

    // Whatever happened above, make sure the navigation goes out.
    setTimeout(function () {
      try {
        window.location.assign(url);
      } catch (e) {
        window.location.href = url;
      }
    }, 50);
    setTimeout(function () {
      try {
        window.location.href = url;
      } catch (e) {}
    }, 2500);
  }

  // ============================
  // 12. CART CHANGE DETECTION
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
    var origFetch = window.fetch;
    if (origFetch && !origFetch._hurrryHooked) {
      var wrapped = async function () {
        var args = [].slice.call(arguments);
        var req = args[0];
        var url = typeof req === "string" ? req : req && req.url ? req.url : "";

        var resp = await origFetch.apply(this, args);

        try {
          if (isCartMutationUrl(url)) requestPreload();
        } catch (e) {}

        return resp;
      };

      wrapped._hurrryHooked = true;
      window.fetch = wrapped;
    }

    // XMLHttpRequest: WooCommerce core still uses jQuery.ajax for the classic
    // cart, add-to-cart buttons and fragment refreshes.
    var XHR = window.XMLHttpRequest;
    if (XHR && XHR.prototype && !XHR.prototype.open._hurrryHooked) {
      var origOpen = XHR.prototype.open;
      var patchedOpen = function (method, url) {
        this._hurrryUrl = url;
        return origOpen.apply(this, arguments);
      };
      patchedOpen._hurrryHooked = true;
      XHR.prototype.open = patchedOpen;

      var origSend = XHR.prototype.send;
      XHR.prototype.send = function () {
        try {
          this.addEventListener("load", function () {
            if (isCartMutationUrl(String(this._hurrryUrl || ""))) {
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
          "added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed wc_fragments_loaded updated_wc_div updated_shipping_method applied_coupon removed_coupon wc_cart_emptied",
          function () {
            requestPreload();
          },
        );
    }

    // Classic add-to-cart forms and quantity updates are plain submits.
    document.addEventListener(
      "click",
      function (e) {
        var form = closest(e.target, "form.cart, form.woocommerce-cart-form");
        if (form) requestPreload();
      },
      { capture: true },
    );

    // The cart survives back/forward navigation caches; so would our overlay.
    window.addEventListener("pageshow", function (e) {
      if (e.persisted) {
        hideOverlay();
        clickInFlight = false;
        requestPreload();
      }
    });
  }

  // ============================
  // 13. CART READS
  // ============================

  /**
   * Cart URLs are built from home_url() on the server. A shopper browsing the
   * site under another spelling of the host (www vs bare, http vs https) would
   * otherwise send the cart request cross-origin, without cookies, and read an
   * empty cart: the request is always made against the origin the page is on.
   */
  function sameOriginUrl(url) {
    try {
      var u = new URL(String(url), window.location.href);
      if (u.host !== window.location.host && sameSite(u.host)) {
        u.protocol = window.location.protocol;
        u.host = window.location.host;
      }
      return u.toString();
    } catch (e) {
      return url;
    }
  }

  /**
   * Reads the cart, trying every configured transport in order.
   *
   * A transport that answers with an empty cart is not trusted while another
   * one is left to try: an empty answer is precisely what a broken transport
   * looks like.
   */
  async function getCartSafe() {
    if (bootstrapCart) {
      var snapshot = bootstrapCart;
      bootstrapCart = null;
      if (snapshot.items && snapshot.items.length > 0) {
        log("Using the server-rendered cart snapshot");
        return snapshot;
      }
    }

    var urls = (Array.isArray(CFG.cartUrls) && CFG.cartUrls.length ? CFG.cartUrls : [CFG.cartUrl]).filter(Boolean).map(sameOriginUrl);

    var firstValid = null;

    for (var i = 0; i < urls.length; i++) {
      var cart = await fetchCart(urls[i]);
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
      var headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
      // The nonce only means something to the REST API; elsewhere a stale one
      // (cached page) would be noise at best.
      if (CFG.restNonce && url.indexOf("/wp-json/") !== -1) headers["X-WP-Nonce"] = CFG.restNonce;

      var r = await fetchWithTimeout(cacheBust(url), { method: "GET", credentials: "same-origin", cache: "no-store", headers: headers }, CFG.fetchTimeoutMs);

      if (!r.ok) throw new Error("cart endpoint failed with " + r.status);

      var cart = await r.json();

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
    var sep = url.indexOf("?") !== -1 ? "&" : "?";
    return url + sep + "_hurrry=" + ++cacheBustCounter + "." + Date.now();
  }

  // ============================
  // 14. ENDPOINT
  // ============================

  async function createHurrryUrl(cart, maxAttempts) {
    var payload = buildPayload(cart);
    var attempts = maxAttempts || CFG.retries + 1;
    var lastErr = null;

    for (var attempt = 1; attempt <= attempts; attempt++) {
      try {
        var resp = await fetchWithTimeout(
          CFG.endpoint,
          {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify(payload),
          },
          CFG.fetchTimeoutMs,
        );

        if (!resp.ok) throw new Error("Endpoint error " + resp.status);

        var json = await resp.json();
        if (json && json.orderId) lastOrderId = json.orderId;

        var rawUrl = json && (json.hostedUrl || json.redirectUrl || json.url || json.direct_link || json.checkoutUrl);

        if (!rawUrl || !/^https?:\/\//i.test(String(rawUrl))) throw new Error("No URL in response");

        return withQuery(rawUrl, {
          defaultLang: CFG.defaultLang,
        });
      } catch (e) {
        lastErr = e;
        warn("Retrying create URL", attempt, e);
        if (attempt < attempts) await sleep(300 * attempt);
      }
    }

    throw lastErr;
  }

  function sleep(ms) {
    return new Promise(function (resolve) {
      setTimeout(resolve, ms);
    });
  }

  function fetchWithTimeout(url, options, timeoutMs) {
    var ctrl = typeof AbortController === "function" ? new AbortController() : null;
    var opts = Object.assign({}, options || {});
    if (ctrl) opts.signal = ctrl.signal;

    return new Promise(function (resolve, reject) {
      var done = false;
      var t = setTimeout(function () {
        if (done) return;
        done = true;
        if (ctrl) {
          try {
            ctrl.abort();
          } catch (e) {}
        }
        reject(new Error("timeout after " + timeoutMs + "ms"));
      }, timeoutMs);
      fetch(url, opts).then(
        function (r) {
          if (done) return;
          done = true;
          clearTimeout(t);
          resolve(r);
        },
        function (e) {
          if (done) return;
          done = true;
          clearTimeout(t);
          reject(e);
        },
      );
    });
  }

  function withQuery(url, params) {
    try {
      var u = new URL(url, window.location.origin);
      Object.keys(params || {}).forEach(function (k) {
        if (params[k] !== undefined && params[k] !== null && params[k] !== "") {
          u.searchParams.set(k, String(params[k]));
        }
      });
      return u.toString();
    } catch (e) {
      return url;
    }
  }

  /**
   * Stable id for the current cart.
   *
   * The backend deduplicates by externalId, so a timestamp-based id would turn
   * every preload into a brand new order. The cart token identifies the cart
   * across reloads; the contents are hashed in so that changing the cart does
   * start a new order. The server guard computes the very same id
   * (Hurrry_Payload::external_id), which is what lets it reuse this tab's URL.
   */
  function cartExternalId(cart) {
    var contents = (cart.items || [])
      .map(function (li) {
        return [li.id, li.quantity, li.line_price].join(":");
      })
      .join("|");
    return "CART-" + cartToken(cart) + "-" + hash36(contents + "|" + (cart.currency || ""));
  }

  function cartToken(cart) {
    if (cart.token) return String(cart.token).slice(0, 32);
    // Sessions that have not been persisted yet still need reloads to converge
    // on one id, so fall back to a per-tab one.
    try {
      var fallback = sessionStorage.getItem("hurrry_cart_token");
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
    var h = 5381;
    for (var i = 0; i < str.length; i++) {
      h = ((h << 5) + h + str.charCodeAt(i)) >>> 0;
    }
    return h.toString(36);
  }

  function pageUrl() {
    try {
      return String(window.location.href).split("#")[0].slice(0, 512);
    } catch (e) {
      return "";
    }
  }

  function buildPayload(cart) {
    var currency = cart.currency || (cart.items[0] && cart.items[0].currency) || "USD";

    var items = (cart.items || []).map(function (li) {
      var qty = Number(li.quantity) || 1;
      var unitAmount = qty > 0 ? Math.round(li.line_price / qty) : 0;
      var productId = li.sku && li.sku.length ? li.sku : String(li.product_id || li.id);

      return {
        productId: productId,
        sku: li.sku || "",
        name: li.product_title + (li.variant_title ? " - " + li.variant_title : ""),
        imageUrl: li.image || (li.featured_image ? li.featured_image.url : "") || "",
        quantity: qty,
        unitAmount: unitAmount,
        taxAmount: 0,
      };
    });

    var payload = Object.assign(
      {
        externalId: cartExternalId(cart),
        currency: currency,
        showCurrency: currency,
        items: items,
        shopUrl: CFG.shopUrl || window.location.hostname || "",
        sourceUrl: pageUrl(),
        notes: CFG.notes || "WooCommerce Cart Inline",
      },
      SIG.payload(),
    );

    lastCart = {
      payload: payload,
      externalId: payload.externalId,
      currency: currency,
      value:
        items.reduce(function (cents, i) {
          return cents + i.unitAmount * i.quantity;
        }, 0) / 100,
      numItems: items.reduce(function (sum, i) {
        return sum + i.quantity;
      }, 0),
      contentIds: items.map(function (i) {
        return i.productId;
      }),
      contents: items.map(function (i) {
        return { id: i.productId, quantity: i.quantity, item_price: i.unitAmount / 100 };
      }),
    };

    return payload;
  }

  // ============================
  // 15. BACKEND SIBLINGS: INSTALL PING, HEARTBEAT, PIXEL
  // ============================

  function siblingEndpoint(path) {
    try {
      var u = new URL(CFG.endpoint, window.location.origin);
      if (!/\/api\/orders\/?$/.test(u.pathname)) return null;
      u.pathname = u.pathname.replace(/\/api\/orders\/?$/, path);
      u.search = "";
      return u.toString();
    } catch (e) {
      return null;
    }
  }

  var PING_KEY = "hy_ping_ts";
  var PING_INTERVAL_MS = 60 * 1000;

  function scheduleInstallPing() {
    if (CFG.installPing === false) return;
    var fire = function () {
      setTimeout(pingInstall, 300);
    };
    if (document.readyState === "complete") fire();
    else window.addEventListener("load", fire, { once: true });
  }

  function pingInstall() {
    var endpoint = siblingEndpoint("/api/payments/script-ping");
    if (!endpoint) return;
    try {
      var forced = /[?&]hy_verify=/.test(window.location.search);
      if (!forced) {
        var last = Number(sessionStorage.getItem(PING_KEY) || 0);
        if (last && Date.now() - last < PING_INTERVAL_MS) return;
      }
      try {
        sessionStorage.setItem(PING_KEY, String(Date.now()));
      } catch (e) {}
      var body = {
        shop: CFG.shopUrl || window.location.hostname,
        host: window.location.hostname,
        version: "wp-" + SCRIPT_VERSION,
        logo: detectLogo(),
      };
      log("install ping", body);
      fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
        keepalive: true,
      }).catch(function () {});
    } catch (e) {
      warn("pingInstall failed", e);
    }
  }

  function detectLogo() {
    try {
      var sels = [
        ".custom-logo",
        ".site-logo img",
        ".wp-block-site-logo img",
        "header a[href='/'] img",
        "header img[class*='logo' i]",
        "header img[alt*='logo' i]",
        "[class*='logo' i] img",
        "img[class*='logo' i]",
        "img[alt*='logo' i]",
      ];
      for (var i = 0; i < sels.length; i++) {
        var nodes = document.querySelectorAll(sels[i]);
        for (var j = 0; j < nodes.length; j++) {
          var src = imageSrc(nodes[j]);
          if (src) return src;
        }
      }
      var og = document.querySelector("meta[property='og:image'], meta[name='og:image']");
      if (og && og.content) return absoluteUrl(og.content);
      var icon = document.querySelector("link[rel~='icon']");
      if (icon && icon.href) return absoluteUrl(icon.href);
    } catch (e) {
      warn("detectLogo failed", e);
    }
    return "";
  }

  function imageSrc(img) {
    if (!img || img.tagName !== "IMG") return "";
    var r = img.getBoundingClientRect ? img.getBoundingClientRect() : null;
    if (r && r.width > 0 && r.width < 16) return "";
    var src = img.currentSrc || img.src || img.getAttribute("data-src") || "";
    return src && !/^data:/i.test(src) ? absoluteUrl(src) : "";
  }

  function absoluteUrl(url) {
    try {
      return new URL(url, window.location.href).toString().slice(0, 1000);
    } catch (e) {
      return "";
    }
  }

  var ORDER_ID_RE = /^(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i;

  function orderIdFrom(url) {
    try {
      var u = new URL(url, window.location.origin);
      var fromQuery = u.searchParams.get("orderId") || u.searchParams.get("orderid");
      if (ORDER_ID_RE.test(fromQuery || "")) return fromQuery;
      var path = u.pathname.replace(/\/+$/, "");
      var last = path.slice(path.lastIndexOf("/") + 1);
      return ORDER_ID_RE.test(last) ? last : null;
    } catch (e) {
      return null;
    }
  }

  function notifyCheckoutRedirect(url) {
    if (!lastOrderId || orderIdFrom(url)) return;
    var endpoint = siblingEndpoint("/api/payments/heartbeat");
    if (!endpoint) return;
    try {
      fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ orderId: lastOrderId, stage: "redirect" }),
        keepalive: true,
      }).catch(function () {});
    } catch (e) {
      warn("notifyCheckoutRedirect failed", e);
    }
  }

  function trackInitiateCheckout(url) {
    if (!CFG.trackInitiateCheckout) return;
    var orderId = orderIdFrom(url) || lastOrderId;
    if (!orderId || trackedCheckouts[orderId]) return;
    trackedCheckouts[orderId] = true;
    var c = lastCart || {};
    var fired = SIG.track(
      "InitiateCheckout",
      {
        value: c.value,
        currency: c.currency,
        content_type: "product",
        content_ids: c.contentIds,
        contents: c.contents,
        num_items: c.numItems,
      },
      orderId + "_initiate_checkout",
    );
    beaconPending = fired;
  }

  // ============================
  // 16. BOOT
  // ============================

  window.Hurrry = Hurrry;

  // The configuration is an inline script printed right before this file. A
  // JS optimiser may still reorder or delay it; wait for it rather than give
  // up, the early interceptor above is already protecting the clicks.
  (function boot() {
    var tries = 0;
    (function tick() {
      if (window.HurrryConfig) {
        Hurrry.init(window.HurrryConfig);
        return;
      }
      if (++tries < 100) setTimeout(tick, 100);
      else err("HurrryConfig never arrived; clicks are handed to the server guard");
    })();
  })();
})(window, document);
