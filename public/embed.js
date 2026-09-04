/**
 * D-Store chat widget — single-file embed.
 *
 * Add ONE line to any page, just before </body>:
 *
 *   <script src="https://digitalis.ba/chatbot/public/embed.js"
 *           data-endpoint="https://digitalis.ba/chatbot/endpoint/chat.php" defer></script>
 *
 * No stylesheet, no dependencies, no build step.
 *
 * Everything renders inside a Shadow DOM. That is the important part for
 * dropping this onto an existing site: the host page's CSS cannot leak in and
 * break the widget, and the widget's CSS cannot leak out and break the site.
 * Bootstrap, jQuery themes, global `* { box-sizing }` rules — none of it
 * reaches inside.
 *
 * Optional attributes on the script tag:
 *   data-endpoint  URL of chat.php            (default /endpoint/chat.php)
 *   data-title     header text                (default "D-Store pomoć")
 *   data-greeting  first message
 *   data-color     accent colour              (default #f26529)
 *   data-position  "right" | "left"           (default right)
 *   data-currency  price suffix               (default KM)
 *   data-logo      URL of a logo image, used as the message avatar instead
 *                  of the built-in D-Store wordmark (default none)
 *   data-webshop   storefront key sent to the backend, e.g. "dstore"
 *   data-product-id   product id for product/detail pages
 *   data-product-name product name for product/detail pages
 *   data-product-url  product URL for product/detail pages
 *   data-contact-preset "dstore" enables the Dstore human-contact links
 *   data-whatsapp  WhatsApp contact link
 *   data-viber     Viber contact link
 *   data-messenger Messenger contact link
 *   data-auto-open-delay
 *                  milliseconds before showing a small desktop-only prompt;
 *                  0 disables it (example: 15000 shows it after 15 seconds)
 *   data-teaser    small desktop-only prompt text
 *
 * Per-visitor data (who is logged in, their name, a wholesale flag) cannot
 * live on the script tag — it is only known at runtime, sometimes only after
 * an async login check. The site pushes it through a small command queue,
 * the same pattern Intercom/GA use so it works no matter which of the two
 * scripts finishes loading first:
 *
 *   <script>
 *     window.DstoreChat = window.DstoreChat || function () {
 *       (window.DstoreChat.q = window.DstoreChat.q || []).push(arguments);
 *     };
 *   </script>
 *   <script src="https://digitalis.ba/chatbot/public/embed.js" data-endpoint="..." defer></script>
 *
 * Then, any time (on page load, or later after an async login event):
 *
 *   DstoreChat('identify', {
 *     customerId: '12345',            // optional, opaque string
 *     customerName: 'Ivan Ivić',      // optional
 *     isWholesaleCustomer: true,      // gates wholesale-only catalog items
 *     color: '#0064b4',               // optional, overrides data-color
 *     logo: 'https://.../logo.svg'    // optional, overrides data-logo
 *   });
 *
 * Product-page context, when the host site knows which item is open:
 *
 *   DstoreChat('product', {
 *     id: 62577,
 *     name: 'Samsung Galaxy Z Fold8 Ultra',
 *     url: location.href
 *   });
 */
(function () {
    'use strict';

    if (window.__dstoreChatLoaded) {
        return;   // guard against the script being included twice
    }
    window.__dstoreChatLoaded = true;

    var script = document.currentScript || (function () {
        var all = document.getElementsByTagName('script');
        return all[all.length - 1];
    })();

    function attr(name, fallback) {
        var v = script && script.getAttribute(name);
        return (v === null || v === undefined || v === '') ? fallback : v;
    }

    var contactPreset = attr('data-contact-preset', '');
    var CFG = {
        endpoint: attr('data-endpoint', '/endpoint/chat.php'),
        title:    attr('data-title', 'Dstore AI asistent'),
        greeting: attr('data-greeting',
            'Zdravo! Mogu pomoći oko proizvoda, cijena, dostave i garancije. Šta vas zanima?'),
        color:    attr('data-color', '#f26529'),
        position: attr('data-position', 'right') === 'left' ? 'left' : 'right',
        currency: attr('data-currency', 'KM'),
        logo:     attr('data-logo', ''),
        webshop:  attr('data-webshop', ''),
        whatsapp: attr('data-whatsapp', contactPreset === 'dstore' ? 'https://wa.me/38761094094' : ''),
        viber:    attr('data-viber', contactPreset === 'dstore' ? 'viber://contact?number=%2B38761095095' : ''),
        messenger: attr('data-messenger', contactPreset === 'dstore' ? 'https://m.me/1443840129227893' : ''),
        teaser:   attr('data-teaser', 'Kako Vam mogu pomoći?'),
        autoOpenDelay: Math.max(0, parseInt(attr('data-auto-open-delay', '10000'), 10) || 0)
    };

    // Per-visitor identity pushed by the site via DstoreChat('identify', {...})
    // - see the docblock above. Sent along with every request from then on.
    var visitorInfo = {
        customerId: '',
        customerName: '',
        isWholesaleCustomer: false
    };

    var productContext = {
        id: '',
        name: '',
        url: ''
    };

    // Root element reference for live style updates (identify() can arrive
    // after the widget is already built, e.g. an async login event) - set by
    // build(), read by applyIdentify().
    var shadowHost = null;

    function applyIdentify(payload) {
        payload = payload || {};
        if (payload.customer && typeof payload.customer === 'object') {
            payload = payload.customer;
        }

        var customerId = firstProductValue(payload, ['customerId', 'customer_id', 'id', 'ID', 'userId', 'user_id']);
        var customerName = firstProductValue(payload, ['customerName', 'customer_name', 'name', 'Name', 'fullName', 'full_name']);
        if (customerId) {
            visitorInfo.customerId = customerId;
        }
        if (customerName) {
            visitorInfo.customerName = customerName;
        }

        if (typeof payload.isWholesaleCustomer === 'boolean') {
            visitorInfo.isWholesaleCustomer = payload.isWholesaleCustomer;
        } else if (typeof payload.wholesale === 'boolean') {
            visitorInfo.isWholesaleCustomer = payload.wholesale;
        } else if (typeof payload.wholesale_hint === 'boolean') {
            visitorInfo.isWholesaleCustomer = payload.wholesale_hint;
        } else if (typeof payload.isLoggedIn === 'boolean' && typeof payload.isWholesaleCustomer === 'undefined') {
            visitorInfo.isWholesaleCustomer = payload.isLoggedIn;
        }
        if (payload.color) {
            CFG.color = payload.color;
            if (shadowHost) {
                shadowHost.style.setProperty('--accent', CFG.color);
            }
        }
        if (payload.logo) {
            // addMessage() reads CFG.logo fresh on every render, so this
            // takes effect on the next message with no further work.
            CFG.logo = payload.logo;
        }
    }

    function cleanProductValue(value) {
        if (value === undefined || value === null) {
            return '';
        }
        value = String(value).trim();
        if (!value || value === 'undefined' || value === 'null' || value === '[object Object]') {
            return '';
        }
        return value;
    }

    function firstProductValue(payload, names) {
        for (var i = 0; i < names.length; i++) {
            var value = cleanProductValue(payload[names[i]]);
            if (value) {
                return value;
            }
        }
        return '';
    }

    function productIdFromUrl(url) {
        url = cleanProductValue(url);
        if (!url) {
            return '';
        }

        var path = '';
        try {
            path = new URL(url, window.location.href).pathname || '';
        } catch (e) {
            path = url;
        }

        var match = path.match(/\/webshop\/proizvod\/(\d+)(?:\/|$)/i);
        if (match) {
            return match[1];
        }

        match = path.match(/^\/(\d{3,})(?:\/|$)/);
        if (match) {
            return match[1];
        }

        match = path.match(/-(\d{4,8})(?:\/)?$/);
        return match ? match[1] : '';
    }

    function productNameFromPage() {
        var name = '';
        var metaTitle = document.querySelector('meta[property="og:title"], meta[name="twitter:title"]');
        if (metaTitle) {
            name = cleanProductValue(metaTitle.getAttribute('content'));
        }
        if (!name) {
            var h1 = document.querySelector('h1');
            name = h1 ? cleanProductValue(h1.textContent) : '';
        }
        if (!name) {
            name = cleanProductValue(document.title);
        }
        return name;
    }

    function applyProduct(payload) {
        payload = payload || {};
        if (payload.product && typeof payload.product === 'object') {
            payload = payload.product;
        }

        var nextId = firstProductValue(payload, [
            'id', 'ID', 'product_id', 'productId', 'ProductID', 'productID', 'article_id', 'articleId'
        ]);
        var nextName = firstProductValue(payload, [
            'name', 'Name', 'product_name', 'productName', 'ProductName', 'title', 'Title', 'naziv'
        ]);
        var nextUrl = firstProductValue(payload, [
            'url', 'URL', 'product_url', 'productUrl', 'ProductURL', 'link', 'href'
        ]);

        if (!nextUrl && (window.location && window.location.href)) {
            nextUrl = window.location.href;
        }
        if (!nextId && nextUrl) {
            nextId = productIdFromUrl(nextUrl);
        }
        if (!nextName && nextId) {
            nextName = productNameFromPage();
        }

        if (!nextId && !nextName && !nextUrl) {
            return;
        }

        productContext.id = nextId;
        productContext.name = nextName;
        productContext.url = nextUrl;

        if (els && els.panel && !els.panel.hidden) {
            maybeShowProductPrompt();
        }
    }

    function detectProductFromPage() {
        if (productContext.id || productContext.name) {
            return;
        }

        var href = window.location.href;
        var id = productIdFromUrl(href);
        if (!id) {
            return;
        }

        applyProduct({
            id: id,
            name: productNameFromPage(),
            url: href
        });
    }

    applyProduct({
        id: attr('data-product-id', ''),
        name: attr('data-product-name', ''),
        url: attr('data-product-url', '')
    });
    detectProductFromPage();

    // Drain anything queued before this script finished loading, then
    // replace the stub with the real live function so later calls apply
    // immediately. See the docblock above for why a plain "call our
    // function" API isn't enough on its own (load-order race).
    (function bootDstoreChat() {
        var queued = (window.DstoreChat && window.DstoreChat.q) || [];
        window.DstoreChat = function (cmd, payload) {
            if (cmd === 'identify') {
                applyIdentify(payload || {});
            } else if (cmd === 'product' || cmd === 'setProduct') {
                applyProduct(payload || {});
            } else if (cmd === 'debug') {
                return {
                    webshop: CFG.webshop,
                    product: {
                        id: productContext.id,
                        name: productContext.name,
                        url: productContext.url
                    },
                    visitor: {
                        customerId: visitorInfo.customerId,
                        customerName: visitorInfo.customerName,
                        isWholesaleCustomer: visitorInfo.isWholesaleCustomer
                    },
                    contacts: {
                        whatsapp: CFG.whatsapp,
                        viber: CFG.viber,
                        messenger: CFG.messenger
                    }
                };
            }
        };
        for (var i = 0; i < queued.length; i++) {
            window.DstoreChat.apply(null, queued[i]);
        }
    })();

    window.addEventListener('dstorechat:product', function (e) {
        applyProduct(e.detail || {});
    });
    window.addEventListener('digitalischat:product', function (e) {
        applyProduct(e.detail || {});
    });
    window.addEventListener('falcomchat:product', function (e) {
        applyProduct(e.detail || {});
    });
    window.addEventListener('dstorechat:identify', function (e) {
        applyIdentify(e.detail || {});
    });

    // The real D-Store wordmark, used as a small avatar next to every bot
    // reply so it is obvious the message came from the store's assistant,
    // not the visitor. Static, developer-provided markup — same trust level
    // as the launcher icon below, never touches user input.
    var LOGO_SVG = '<svg viewBox="0 0 422 165" aria-hidden="true">'
        + '<path d="M360.04,74.53c.63-5.62,2.29-9.96,5.02-13.04,2.71-3.07,6.4-4.61,11.04-4.61,2.85,0,5.28.46,7.29,1.41,2,.94,3.67,2.21,5.02,3.81,1.33,1.6,2.34,3.48,3,5.61.67,2.15,1.09,4.42,1.28,6.82h-32.65ZM404.79,66.11c-1.2-4.28-3.03-8.02-5.48-11.24-2.45-3.22-5.54-5.76-9.24-7.63-3.7-1.87-8.04-2.81-13.03-2.81-4.55,0-8.72.8-12.51,2.41-3.8,1.59-7.04,3.94-9.71,7.02-2.68,3.07-4.77,6.89-6.29,11.44-1.52,4.55-2.27,9.81-2.27,15.78s.8,10.98,2.4,15.52c1.61,4.55,3.79,8.39,6.56,11.51,2.76,3.12,6,5.48,9.7,7.09,3.71,1.61,7.6,2.41,11.71,2.41,5.35,0,9.95-.8,13.79-2.41,3.83-1.61,7.31-3.88,10.43-6.82l-8.16-8.83c-2.32,1.78-4.71,3.17-7.16,4.15-2.45.98-5.15,1.47-8.09,1.47-2.14,0-4.19-.38-6.15-1.13-1.96-.77-3.75-1.92-5.36-3.48-1.6-1.56-2.93-3.55-4.01-5.95-1.07-2.42-1.79-5.31-2.14-8.7h46.42c.09-.71.18-1.63.27-2.74.09-1.11.14-2.3.14-3.55,0-4.73-.6-9.23-1.81-13.52ZM338.9,47.37c-1.43-.97-3.06-1.71-4.88-2.2-1.84-.49-4.17-.74-7.03-.74-3.48,0-6.63.78-9.43,2.34-2.81,1.57-5.02,3.5-6.62,5.82v-6.69h-13.92v70.24h13.92v-40.14c0-5.97,1.21-10.48,3.62-13.51s5.79-4.56,10.17-4.56c2.31,0,4.29.22,5.88.68,1.61.45,3.16,1.07,4.68,1.87l3.61-13.12ZM264.91,90.19c-.81,2.85-1.96,5.34-3.48,7.42-1.52,2.1-3.35,3.78-5.48,5.02-2.15,1.25-4.51,1.87-7.1,1.87-5.27,0-9.5-2.03-12.71-6.09-3.22-4.06-4.83-9.96-4.83-17.72,0-6.96,1.5-12.56,4.49-16.79,2.99-4.24,7.11-6.36,12.38-6.36,5.62,0,10.01,2.12,13.17,6.36,3.17,4.23,4.76,9.96,4.76,17.18,0,3.22-.41,6.25-1.21,9.1ZM277.68,65.77c-1.56-4.5-3.74-8.31-6.55-11.44-2.81-3.12-6.14-5.55-9.97-7.28-3.83-1.75-7.98-2.62-12.44-2.62s-8.59.82-12.37,2.47c-3.79,1.65-7.09,4.06-9.91,7.23-2.81,3.16-5.01,7.02-6.62,11.57-1.6,4.55-2.41,9.67-2.41,15.38s.78,10.53,2.34,14.99c1.56,4.46,3.72,8.3,6.49,11.5,2.77,3.22,6.02,5.7,9.77,7.43,3.74,1.74,7.84,2.62,12.3,2.62s8.74-.83,12.58-2.48c3.83-1.65,7.18-4.06,10.03-7.22,2.86-3.16,5.09-7.04,6.69-11.64,1.6-4.6,2.41-9.79,2.41-15.59,0-5.44-.78-10.41-2.35-14.92ZM204.44,102.23c-1.26.67-2.81,1.29-4.69,1.85-1.88.55-3.71.82-5.49.82-2.59,0-4.43-.57-5.55-1.73-1.12-1.15-1.67-3.36-1.67-6.65v-37.91h17.4v-12.71h-17.4v-27.3l-13.52,7.23v20.07h-10.03v12.71h10.03v40.45c0,6.31,1.41,10.99,4.22,14.01,2.8,3.03,6.66,4.55,11.57,4.55,2.77,0,5.29-.29,7.56-.87,2.27-.59,4.26-1.36,5.96-2.35l1.6-12.16ZM151.45,88.45c-.98-2.32-2.47-4.35-4.48-6.09-2-1.74-4.48-3.3-7.42-4.69-2.95-1.37-6.38-2.82-10.3-4.34-2.68-1.07-4.84-2.03-6.49-2.87-1.64-.85-2.95-1.68-3.94-2.47-.99-.81-1.66-1.59-2.01-2.35-.35-.75-.53-1.58-.53-2.47,0-1.88.76-3.42,2.27-4.62,1.51-1.21,3.97-1.8,7.36-1.8s6.55.49,9.5,1.48c2.94.98,5.84,2.49,8.69,4.54l6.02-10.57c-3.12-2.32-6.76-4.19-10.91-5.62-4.14-1.43-8.44-2.14-12.91-2.14-3.48,0-6.69.41-9.64,1.21-2.94.8-5.49,2.01-7.63,3.62-2.14,1.6-3.79,3.62-4.95,6.02-1.17,2.41-1.74,5.17-1.74,8.29,0,2.77.35,5.2,1.07,7.29.71,2.09,1.89,3.99,3.55,5.69,1.64,1.69,3.78,3.27,6.42,4.75,2.63,1.46,5.81,2.92,9.56,4.35,5.09,1.97,9.07,3.75,11.98,5.34,2.9,1.62,4.34,3.75,4.34,6.43,0,5.17-4.28,7.76-12.85,7.76-3.3,0-6.71-.57-10.23-1.74-3.52-1.16-6.94-2.9-10.23-5.22l-6.29,10.44c3.57,2.59,7.73,4.73,12.5,6.43,4.77,1.69,9.57,2.55,14.38,2.55,3.48,0,6.79-.36,9.97-1.06,3.17-.73,5.96-1.89,8.37-3.49,2.41-1.6,4.34-3.73,5.82-6.36,1.46-2.63,2.2-5.92,2.2-9.84,0-3.3-.49-6.1-1.48-8.42Z" fill="#f26529"/>'
        + '<path d="M60.89,96.34c-1.78,2.31-4.05,4.39-6.8,6.22-2.76,1.83-5.77,2.74-9.07,2.74-5.05,0-8.94-2.01-11.66-6.02-2.7-4.01-4.06-10.48-4.06-19.39,0-7.5,1.29-13.1,3.87-16.8,2.58-3.7,6.34-5.55,11.32-5.55,1.69,0,3.35.29,4.99.87,1.64.59,3.18,1.33,4.6,2.21,1.42.89,2.71,1.87,3.86,2.94,1.15,1.07,2.13,2.14,2.94,3.21v29.57ZM74.4,116.54V19l-13.65,7.09v26.76c-1.96-2.14-4.51-4.01-7.63-5.62-3.13-1.6-6.48-2.41-10.04-2.41s-7.16.72-10.51,2.14c-3.34,1.44-6.29,3.6-8.84,6.55-2.53,2.95-4.56,6.65-6.08,11.11-1.52,4.45-2.27,9.69-2.27,15.65,0,6.69.69,12.43,2.07,17.2,1.39,4.78,3.3,8.68,5.76,11.71,2.46,3.03,5.32,5.27,8.63,6.69,3.3,1.43,6.82,2.15,10.57,2.15s7.09-.79,10.03-2.35c2.94-1.56,5.71-3.5,8.3-5.81v6.69h13.65Z" fill="#f26529"/>'
        + '<polyline points="86.25 8.94 90.26 8.94 90.26 156.06 86.25 156.06 86.25 8.94" fill="#f26529" fill-rule="evenodd"/>'
        + '<g>'
        + '<path d="M108.08,153.81c-1.09,0-2.05-.13-2.89-.39-.84-.25-1.57-.66-2.19-1.21-.6-.54-1.08-1.21-1.4-2.02-.33-.81-.49-1.76-.49-2.84,0-1.61.26-3.12.76-4.51.51-1.4,1.21-2.62,2.14-3.68.9-1.03,2-1.84,3.31-2.44,1.32-.6,2.73-.9,4.25-.9,1,0,1.95.14,2.84.42.9.28,1.67.61,2.35.99l-.63,3.13h-.16c-.21-.18-.46-.4-.77-.64-.31-.25-.67-.48-1.09-.69-.42-.22-.89-.41-1.4-.56-.51-.15-1.1-.22-1.76-.22-1.99,0-3.61.84-4.87,2.52-1.26,1.68-1.88,3.72-1.88,6.13,0,1.44.37,2.55,1.12,3.33.74.78,1.8,1.17,3.17,1.17.65,0,1.29-.09,1.94-.27.65-.18,1.2-.38,1.65-.6.5-.23.97-.48,1.4-.75.44-.26.75-.46.94-.59h.16l-.61,3.16c-.89.4-1.84.75-2.83,1.04-1,.29-2.01.44-3.04.44Z" fill="#f26529"/>'
        + '<path d="M129.78,153.82c-2.38,0-4.25-.56-5.6-1.68-1.36-1.12-2.03-2.76-2.03-4.92,0-3.15.99-5.85,2.98-8.1,1.99-2.25,4.45-3.38,7.4-3.38,1.94,0,3.43.49,4.47,1.46,1.04.97,1.56,2.33,1.56,4.09,0,.3-.05.8-.14,1.46-.08.67-.23,1.44-.42,2.33h-12.68c-.06.3-.1.59-.13.88-.03.29-.04.55-.04.8,0,1.45.45,2.59,1.32,3.42.89.81,2.14,1.23,3.76,1.23,1.13,0,2.29-.22,3.49-.66,1.2-.44,2.2-.94,3.01-1.49h.17l-.63,3.12c-.51.19-.97.36-1.38.51-.42.15-.93.31-1.57.47-.64.15-1.2.27-1.69.35-.49.08-1.1.12-1.85.12ZM135.56,142.95c.05-.3.08-.55.11-.76.02-.2.03-.43.03-.68,0-1.1-.3-1.95-.9-2.57-.59-.62-1.56-.93-2.88-.93-1.46,0-2.76.45-3.88,1.35-1.13.91-1.9,2.1-2.3,3.58h9.83Z" fill="#f26529"/>'
        + '<path d="M163.07,140.15c0,.24-.02.59-.07,1-.04.42-.11.79-.19,1.11l-2.58,11.16h-2.88l2.25-9.78c.12-.55.21-1.02.28-1.43.07-.4.1-.8.1-1.21,0-.86-.21-1.51-.64-1.94-.43-.44-1.2-.65-2.31-.65-.78,0-1.63.21-2.58.64-.94.43-1.86.95-2.75,1.55l-2.97,12.83h-2.89l3.98-17.2h2.89l-.44,1.9c1.1-.77,2.13-1.36,3.08-1.77.96-.41,1.95-.62,2.96-.62,1.5,0,2.67.38,3.5,1.14.84.75,1.26,1.84,1.26,3.27Z" fill="#f26529"/>'
        + '<path d="M185.05,136.22l-.53,2.34h-5.98l-1.84,7.94c-.1.41-.2.87-.27,1.38-.08.52-.13.93-.13,1.24,0,.74.19,1.31.57,1.67.38.36,1.09.55,2.12.55.42,0,.92-.08,1.5-.21.58-.15.97-.26,1.17-.34h.15l-.54,2.48c-.57.15-1.19.26-1.84.35-.66.1-1.23.14-1.73.14-1.42,0-2.51-.3-3.29-.93-.78-.62-1.16-1.6-1.16-2.95,0-.33.02-.65.07-.98.05-.32.11-.69.19-1.1l2.14-9.23h-1.95l.53-2.34h1.97l1.15-4.95h2.91l-1.15,4.95h5.96Z" fill="#f26529"/>'
        + '<path d="M200.92,151.59c-.28.17-.65.4-1.13.7-.48.31-.98.56-1.46.78-.52.24-1.11.44-1.76.59-.65.16-1.4.24-2.24.24-1.36,0-2.47-.4-3.33-1.19-.85-.8-1.27-1.84-1.27-3.12,0-1.37.29-2.52.9-3.46.6-.94,1.49-1.71,2.67-2.3,1.16-.58,2.56-1.01,4.2-1.27,1.64-.27,3.5-.42,5.59-.49.06-.28.13-.53.16-.74.04-.21.06-.43.06-.66,0-.5-.1-.89-.29-1.2-.2-.3-.47-.55-.83-.74-.37-.18-.78-.3-1.26-.37-.48-.07-1.01-.1-1.58-.1-.87,0-1.87.15-2.99.43-1.12.28-2.03.57-2.73.84h-.15l.57-2.9c.58-.15,1.45-.33,2.57-.53,1.13-.19,2.21-.29,3.25-.29,2.14,0,3.73.34,4.8,1.01,1.07.66,1.61,1.72,1.61,3.17,0,.28-.03.57-.07.89-.05.31-.1.61-.17.88l-2.68,11.67h-2.88l.43-1.83ZM202.6,144.36c-1.59.06-2.99.18-4.22.36-1.22.18-2.24.44-3.04.78-.83.34-1.46.8-1.9,1.38-.43.58-.65,1.31-.65,2.16,0,.74.25,1.3.77,1.7.5.39,1.28.58,2.34.58.93,0,1.88-.21,2.85-.62.97-.41,1.87-.92,2.71-1.5l1.14-4.84Z" fill="#f26529"/>'
        + '<path d="M228.09,139.27h-.14c-.42-.1-.8-.18-1.15-.22-.35-.05-.78-.07-1.29-.07-.97,0-1.92.22-2.87.65-.95.44-1.86.97-2.73,1.57l-2.79,12.22h-2.93l3.93-17.2h2.92l-.58,2.54c1.37-.95,2.52-1.61,3.48-1.99.95-.37,1.87-.55,2.73-.55.5,0,.87.01,1.09.03.23.03.56.07.99.15l-.66,2.87Z" fill="#f26529"/>'
        + '<path d="M262.87,136.22l-.52,2.34h-5.98l-1.85,7.94c-.1.41-.19.87-.27,1.38-.08.52-.13.93-.13,1.24,0,.74.2,1.31.57,1.67.38.36,1.08.55,2.12.55.42,0,.92-.08,1.49-.21.59-.15.98-.26,1.18-.34h.16l-.55,2.48c-.57.15-1.19.26-1.84.35-.65.1-1.23.14-1.74.14-1.42,0-2.51-.3-3.29-.93-.78-.62-1.16-1.6-1.16-2.95,0-.33.02-.65.07-.98.05-.32.11-.69.2-1.1l2.14-9.23h-1.96l.53-2.34h1.96l1.15-4.95h2.91l-1.16,4.95h5.97Z" fill="#f26529"/>'
        + '<path d="M275.98,153.82c-2.37,0-4.24-.56-5.6-1.68-1.35-1.12-2.02-2.76-2.02-4.92,0-3.15,1-5.85,2.98-8.1,1.99-2.25,4.45-3.38,7.38-3.38,1.96,0,3.44.49,4.48,1.46,1.04.97,1.56,2.33,1.56,4.09,0,.3-.05.8-.13,1.46-.09.67-.23,1.44-.43,2.33h-12.68c-.06.3-.11.59-.13.88-.02.29-.04.55-.04.8,0,1.45.44,2.59,1.32,3.42.88.81,2.13,1.23,3.76,1.23,1.13,0,2.3-.22,3.49-.66,1.2-.44,2.2-.94,3.01-1.49h.17l-.63,3.12c-.51.19-.97.36-1.38.51-.41.15-.94.31-1.57.47-.64.15-1.2.27-1.7.35-.49.08-1.11.12-1.85.12ZM281.77,142.95c.06-.3.09-.55.11-.76.02-.2.03-.43.03-.68,0-1.1-.3-1.95-.9-2.57-.6-.62-1.57-.93-2.89-.93-1.45,0-2.75.45-3.89,1.35-1.13.91-1.88,2.1-2.29,3.58h9.82Z" fill="#f26529"/>'
        + '<path d="M309.24,140.15c0,.24-.02.59-.08,1-.03.42-.1.79-.18,1.11l-2.59,11.16h-2.88l2.26-9.78c.13-.55.22-1.02.28-1.43.06-.4.1-.8.1-1.21,0-.86-.21-1.51-.64-1.94-.42-.44-1.2-.65-2.31-.65-.78,0-1.64.21-2.59.64-.94.43-1.86.95-2.76,1.55l-2.96,12.82h-2.89l5.52-23.96h2.89l-2.01,8.67c1.1-.78,2.13-1.36,3.1-1.77.96-.41,1.95-.62,2.97-.62,1.5,0,2.67.38,3.5,1.14.84.75,1.26,1.84,1.26,3.27Z" fill="#f26529"/>'
        + '<path d="M334.51,140.15c0,.24-.02.59-.07,1-.04.42-.11.79-.19,1.11l-2.58,11.16h-2.88l2.25-9.78c.12-.55.21-1.02.28-1.43.07-.4.1-.8.1-1.21,0-.86-.21-1.51-.64-1.94-.43-.44-1.2-.65-2.31-.65-.78,0-1.64.21-2.59.64-.94.43-1.86.95-2.75,1.55l-2.96,12.83h-2.9l3.98-17.2h2.89l-.44,1.9c1.1-.77,2.13-1.36,3.09-1.77.95-.41,1.94-.62,2.96-.62,1.5,0,2.67.38,3.51,1.14.84.75,1.26,1.84,1.26,3.27Z" fill="#f26529"/>'
        + '<path d="M349.42,136.22l-3.98,17.2h-2.9l3.98-17.2h2.9ZM350.99,130.33l-.7,3h-3.28l.69-3h3.28Z" fill="#f26529"/>'
        + '<path d="M373.03,153.42h-3.5l-5.51-7.56-2.49,2.02-1.27,5.54h-2.92l5.54-23.96h2.93l-3.56,15.39,10.06-8.62h3.82l-9.81,8.13,6.71,9.06Z" fill="#f26529"/>'
        + '<path d="M388.52,153.82c-2.38,0-4.25-.56-5.6-1.68-1.35-1.12-2.03-2.76-2.03-4.92,0-3.15,1-5.85,2.99-8.1,1.99-2.25,4.45-3.38,7.38-3.38,1.95,0,3.44.49,4.49,1.46,1.03.97,1.55,2.33,1.55,4.09,0,.3-.04.8-.13,1.46-.08.67-.23,1.44-.42,2.33h-12.69c-.06.3-.1.59-.13.88-.02.29-.04.55-.04.8,0,1.45.44,2.59,1.33,3.42.88.81,2.13,1.23,3.76,1.23,1.12,0,2.29-.22,3.49-.66,1.2-.44,2.2-.94,3.01-1.49h.17l-.64,3.12c-.51.19-.97.36-1.38.51-.41.15-.93.31-1.57.47-.64.15-1.2.27-1.69.35-.49.08-1.11.12-1.85.12ZM394.31,142.95c.05-.3.09-.55.11-.76.02-.2.03-.43.03-.68,0-1.1-.3-1.95-.91-2.57-.6-.62-1.56-.93-2.89-.93-1.46,0-2.75.45-3.88,1.35-1.13.91-1.9,2.1-2.29,3.58h9.83Z" fill="#f26529"/>'
        + '</g>'
        + '</svg>';

    // Header icon buttons — plain geometric glyphs, static/trusted markup.
    // Speech-bubble-with-tail outline plus a "+" inside, for "new chat" -
    // reads the same silhouette as a chat/message icon while still signalling
    // "start a new one".
    var ICON_RESET = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        + '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'
        + '<path d="M11 8v5M8.5 10.5h5"/>'
        + '</svg>';
    var ICON_EXPAND = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm16 0v6h-6v-2h4v-4h2z"/></svg>';
    var ICON_COLLAPSE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h2v6H5V7h4V3zm6 0h2v4h4v2h-6V3zM5 15h6v6H9v-4H5v-2zm10 6v-6h6v2h-4v4h-2z"/></svg>';

    // Launcher icon swaps between the chat bubble (closed) and an X
    // (open) - the standard pattern for a chat widget toggle button.
    var ICON_CHAT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg>';
    var ICON_CLOSE_X = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.3 5.71 12 12.01l-6.3-6.3-1.41 1.41 6.3 6.3-6.3 6.3 1.41 1.41 6.3-6.3 6.3 6.3 1.41-1.41-6.3-6.3 6.3-6.3z"/></svg>';

    var TEXT = {
        open:         'Otvori chat',
        close:        'Zatvori chat',
        reset:        'Novi razgovor',
        resetAria:    'Započni novi razgovor',
        expandAria:   'Povećaj prozor chata',
        collapseAria: 'Smanji prozor chata',
        placeholder:  'Postavite pitanje…',
        send:         'Pošalji',
        typing:       'Asistent piše',
        netError:     'Ne mogu se povezati. Provjerite internet i pokušajte ponovo.',
        genError:     'Došlo je do greške. Pokušajte ponovo.',
        feedbackTitle: 'Kako biste ocijenili ovaj razgovor?',
        feedbackPlaceholder: 'Šta je bilo dobro ili koji problem ste primijetili?',
        feedbackSubmit: 'Pošalji ocjenu',
        feedbackSkip: 'Preskoči'
    };

    var MAX_LEN = 1500;

    /**
     * A stable id for this browser.
     *
     * Session cookies are unreliable when the widget is embedded on a
     * different host than the API, and browsers increasingly block third-party
     * cookies outright. A random id in localStorage keeps the conversation
     * together without depending on cookie policy at all.
     */
    function visitorId() {
        var KEY = 'dstore_chat_visitor';
        try {
            var existing = window.localStorage.getItem(KEY);
            if (existing) {
                return existing;
            }
            var id = '';
            var bytes = new Uint8Array(16);
            (window.crypto || window.msCrypto).getRandomValues(bytes);
            for (var i = 0; i < bytes.length; i++) {
                id += ('0' + bytes[i].toString(16)).slice(-2);
            }
            window.localStorage.setItem(KEY, id);
            return id;
        } catch (e) {
            // Private browsing, or storage disabled. The conversation simply
            // will not persist across reloads.
            return '';
        }
    }

    var CSS = [
        ':host {',
        '  all: initial;',
        '  --bg: #17181c; --bubble: #23252b; --surface: #1f2126; --surface2: #26282f;',
        '  --border: #2e3138; --text: #e9e9ec; --muted: #9a9da6;',
        '}',
        '*, *::before, *::after { box-sizing: border-box; }',
        '.wrap {',
        '  position: fixed; bottom: calc(20px + env(safe-area-inset-bottom)); z-index: 2147483000;',
        '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;',
        '  font-size: 15px; line-height: 1.5;',
        '}',
        '.wrap.right { right: 20px; } .wrap.left { left: 20px; }',
        '@keyframes launcherIn {',
        '  0% { transform: scale(0); opacity: 0; }',
        '  60% { transform: scale(1.08); opacity: 1; }',
        '  100% { transform: scale(1); opacity: 1; }',
        '}',
        '.launcher {',
        '  width: 56px; height: 56px; border-radius: 50%; border: none;',
        '  background: var(--accent); color: #fff; cursor: pointer;',
        '  box-shadow: 0 4px 16px rgba(0,0,0,.22);',
        '  display: flex; align-items: center; justify-content: center;',
        '  transition: transform .15s ease, box-shadow .15s ease;',
        '  animation: launcherIn .35s cubic-bezier(.34,1.56,.64,1);',
        '}',
        '.launcher:hover { transform: scale(1.06); box-shadow: 0 6px 20px rgba(0,0,0,.28); }',
        '.launcher:active { transform: scale(.96); }',
        '.launcher svg { width: 26px; height: 26px; fill: currentColor; animation: launcherIconIn .2s ease; }',
        '@keyframes launcherIconIn { from { opacity: 0; transform: scale(.5) rotate(-45deg); } to { opacity: 1; transform: scale(1) rotate(0); } }',
        '.teaser {',
        '  position: absolute; bottom: 70px; min-width: max-content; max-width: calc(100vw - 40px);',
        '  padding: 10px 15px; border: 1px solid var(--border); border-radius: 999px;',
        '  background: var(--bg); color: var(--text); box-shadow: 0 10px 30px rgba(0,0,0,.18);',
        '  font-family: inherit; font-size: 14px; line-height: 1.2; font-weight: 700; letter-spacing: 0;',
        '  white-space: nowrap; cursor: pointer;',
        '  opacity: 0; transform: translateY(8px) scale(.97); pointer-events: none;',
        '  transition: opacity .18s ease, transform .18s ease, border-color .15s ease, box-shadow .15s ease;',
        '}',
        '.wrap.right .teaser { right: 0; } .wrap.left .teaser { left: 0; }',
        '.teaser.show { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }',
        '.teaser:hover { border-color: var(--accent); box-shadow: 0 12px 34px rgba(0,0,0,.22); }',
        '.teaser::after {',
        '  content: ""; position: absolute; bottom: -5px; width: 10px; height: 10px;',
        '  background: var(--bg); border-right: 1px solid var(--border); border-bottom: 1px solid var(--border);',
        '  transition: border-color .15s ease;',
        '}',
        '.wrap.right .teaser::after { right: 23px; transform: rotate(45deg); }',
        '.wrap.left .teaser::after { left: 23px; transform: rotate(45deg); }',
        '.teaser:hover::after { border-color: var(--accent); }',
        '.panel {',
        '  position: absolute; bottom: 72px; width: 460px;',
        '  max-width: calc(100vw - 32px); height: 640px;',
        '  max-height: calc(100vh - 96px); background: var(--bg);',
        '  border: 1px solid var(--border); border-radius: 12px;',
        '  box-shadow: 0 12px 40px rgba(0,0,0,.18);',
        '  display: flex; flex-direction: column; overflow: hidden;',
        '  transition: width .18s ease, height .18s ease, opacity .18s ease, transform .2s cubic-bezier(.2,.9,.3,1.2);',
        '  transform-origin: bottom right;',
        '  opacity: 0; transform: translateY(10px) scale(.96); pointer-events: none;',
        '}',
        '.wrap.left .panel { transform-origin: bottom left; }',
        '.wrap.right .panel { right: 0; } .wrap.left .panel { left: 0; }',
        '.panel[hidden] { display: none; }',
        '.panel.open { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }',
        '.panel.expanded {',
        '  width: 680px; height: 760px;',
        '  max-width: calc(100vw - 32px); max-height: calc(100vh - 64px);',
        '}',
        '.head {',
        '  display: flex; align-items: center; justify-content: space-between;',
        '  gap: 10px; padding: 14px 16px 14px 20px; background: var(--accent); color: #fff;',
        '  flex-shrink: 0;',
        '}',
        '.head .t { font-weight: 600; font-size: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
        '.head .acts { display: flex; gap: 6px; flex-shrink: 0; }',
        '.ibtn {',
        '  background: transparent; border: none; color: inherit; cursor: pointer;',
        '  width: 36px; height: 36px; padding: 0; border-radius: 8px; opacity: .95;',
        '  font-family: inherit; font-size: 15px;',
        '  display: flex; align-items: center; justify-content: center; flex-shrink: 0;',
        '  transition: background .15s ease, opacity .15s ease, transform .15s ease;',
        '}',
        '.ibtn svg { width: 22px; height: 22px; fill: currentColor; }',
        '.ibtn:hover { background: rgba(255,255,255,.18); opacity: 1; }',
        '.ibtn:active { transform: scale(.9); }',
        '.msgs {',
        '  flex: 1; overflow-y: auto; overflow-x: hidden; padding: 18px; scroll-behavior: smooth;',
        '  display: flex; flex-direction: column; gap: 10px; background: var(--bg);',
        '  scrollbar-width: thin; scrollbar-color: var(--border) transparent;',
        '}',
        // Slim custom scrollbar (Chromium/Edge/Safari) with the native
        // up/down arrow buttons removed - those showed as an odd little
        // spinner on Windows and were never meant to be there.
        '.msgs::-webkit-scrollbar, textarea::-webkit-scrollbar { width: 8px; }',
        '.msgs::-webkit-scrollbar-track, textarea::-webkit-scrollbar-track { background: transparent; }',
        '.msgs::-webkit-scrollbar-thumb, textarea::-webkit-scrollbar-thumb {',
        '  background: var(--border); border-radius: 4px;',
        '}',
        '.msgs::-webkit-scrollbar-thumb:hover, textarea::-webkit-scrollbar-thumb:hover { background: var(--muted); }',
        '.msgs::-webkit-scrollbar-button, textarea::-webkit-scrollbar-button { display: none; width: 0; height: 0; }',
        '@keyframes msgIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }',
        '.mrow {',
        '  display: flex; align-items: flex-end; gap: 8px; max-width: 92%; min-width: 0;',
        '  animation: msgIn .28s cubic-bezier(.2,.7,.3,1) both;',
        '}',
        '.mrow.has-brands { width: 100%; max-width: 100%; }',
        '.mrow.product-detail-row, .mrow.cart-action-row { width: 92%; max-width: 92%; }',
        '.mrow.user { align-self: flex-end; max-width: 82%; }',
        '.mrow.bot, .mrow.err { align-self: flex-start; }',
        '.avatar {',
        '  width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;',
        '  background: var(--bg); border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,.3);',
        '  display: flex; align-items: center; justify-content: center; overflow: hidden;',
        '}',
        '.avatar svg { width: 20px; height: 20px; }',
        '.avatar img { width: 20px; height: 20px; object-fit: contain; }',
        '.m {',
        '  min-width: 0; max-width: 100%; flex: 0 1 auto; padding: 9px 13px; border-radius: 12px;',
        '  white-space: pre-wrap; word-wrap: break-word; overflow-wrap: anywhere;',
        '  color: var(--text);',
        '}',
        '.m.has-cards, .m.has-brands { flex: 1 1 auto; }',
        '.m.bot  { background: var(--bubble); border-bottom-left-radius: 4px; }',
        '.m.product-detail { width: 100%; }',
        '.m.user { background: var(--accent); color: #fff; border-bottom-right-radius: 4px; }',
        '.m.err  { background: #3a1f22; color: #f87171; border: 1px solid #5c2a2e; font-size: 14px; }',
        '.typing {',
        '  align-self: flex-start; background: var(--bubble); border-radius: 12px;',
        '  border-bottom-left-radius: 4px; padding: 12px 14px; display: flex; gap: 4px;',
        '  animation: msgIn .2s ease both;',
        '}',
        '.typing i {',
        '  width: 7px; height: 7px; border-radius: 50%; background: var(--muted);',
        '  animation: b 1.3s infinite ease-in-out;',
        '}',
        '.typing i:nth-child(2) { animation-delay: .16s; }',
        '.typing i:nth-child(3) { animation-delay: .32s; }',
        '@keyframes b { 0%,60%,100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-5px); opacity: 1; } }',
        '@media (prefers-reduced-motion: reduce) {',
        '  .typing i, .launcher, .mrow, .panel, .card, .ibtn, .send, .qreplies, .chip { animation: none !important; transition: none !important; }',
        '}',
        'form {',
        '  display: flex; gap: 8px; padding: 12px; border-top: 1px solid var(--border);',
        '  background: var(--bg); flex-shrink: 0;',
        '}',
        'textarea {',
        '  flex: 1; resize: none; border: 1px solid var(--border); border-radius: 9px;',
        // font-size fixed at 16px (not "inherit"'s 15px) so mobile Safari does
        // not auto-zoom the page when the input gets focus.
        '  padding: 9px 11px; font: inherit; font-size: 16px; color: var(--text); background: var(--surface);',
        '  max-height: 110px; min-height: 40px; transition: border-color .15s ease;',
        '  scrollbar-width: thin; scrollbar-color: var(--border) transparent;',
        '}',
        'textarea:focus { outline: 2px solid var(--accent); outline-offset: -1px; border-color: var(--accent); }',
        'textarea::placeholder { color: var(--muted); }',
        '.send {',
        '  border: none; background: var(--accent); color: #fff; border-radius: 9px;',
        '  padding: 0 16px; cursor: pointer; font: inherit; font-weight: 600;',
        '  transition: transform .1s ease, opacity .15s ease, box-shadow .15s ease;',
        '}',
        '.send:hover:not(:disabled) { box-shadow: 0 2px 10px rgba(0,0,0,.25); }',
        '.send:active:not(:disabled) { transform: scale(.95); }',
        '.send:disabled { opacity: .5; cursor: not-allowed; }',
        '.cards {',
        '  display: flex; flex-direction: row; gap: 9px; align-self: flex-start; width: 100%; max-width: 100%;',
        '  overflow-x: auto; overflow-y: hidden; padding: 2px 2px 8px;',
        '  scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; scrollbar-width: thin;',
        '  scrollbar-color: var(--border) transparent; cursor: grab;',
        '}',
        '.cards.dragging { cursor: grabbing; scroll-snap-type: none; user-select: none; }',
        '.cards.dragging a, .cards.dragging button { pointer-events: none; }',
        '.cards::-webkit-scrollbar { height: 7px; }',
        '.cards::-webkit-scrollbar-track { background: transparent; }',
        '.cards::-webkit-scrollbar-thumb { background: var(--border); border-radius: 999px; }',
        '.cards::-webkit-scrollbar-button { display: none; width: 0; height: 0; }',
        '.m .cards { width: 100%; max-width: 100%; margin-top: 10px; }',
        '.m .after-cards { margin-top: 8px; }',
        '.card-dots {',
        '  display: flex; justify-content: center; align-items: center; gap: 6px;',
        '  width: 100%; margin-top: 7px; padding-bottom: 2px;',
        '}',
        '.card-dot {',
        '  width: 7px; height: 7px; border: 0; border-radius: 999px; padding: 0;',
        '  background: var(--border); cursor: pointer; opacity: .75;',
        '  transition: width .18s ease, background .18s ease, opacity .18s ease, transform .1s ease;',
        '}',
        '.card-dot:hover { opacity: 1; transform: scale(1.18); }',
        '.card-dot.active { width: 18px; background: var(--accent); opacity: 1; }',
        '.more-link {',
        '  display: flex; align-items: center; gap: 4px; margin-top: 10px;',
        '  color: var(--accent); font-size: 13px; font-weight: 600; text-decoration: none;',
        '}',
        '.more-link:hover { text-decoration: underline; }',
        '.more-link::after { content: "\\2192"; }',
        '.qreplies {',
        '  display: flex; flex-wrap: wrap; gap: 6px; align-self: flex-start;',
        // Past the bot bubble's own left edge (30px avatar + 8px gap), plus
        // a bit more - sits visibly to the right of the bubble text rather
        // than flush with it, so it doesn't read as just another message.
        '  max-width: 96%; margin-top: -2px; margin-left: 52px;',
        '  animation: msgIn .25s cubic-bezier(.2,.7,.3,1) both;',
        '}',
        '.chip {',
        '  flex: 0 0 auto; border: 1px solid var(--accent); background: transparent; color: var(--accent);',
        '  border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 600;',
        '  font-family: inherit; cursor: pointer; white-space: nowrap;',
        '  transition: background .15s ease, color .15s ease, transform .1s ease;',
        '}',
        '.chip:hover:not(:disabled) { background: var(--accent); color: #fff; }',
        '.chip:active:not(:disabled) { transform: scale(.95); }',
        '.chip:disabled { opacity: .4; cursor: default; }',
        '.human-contact {',
        '  align-self: flex-start; width: calc(100% - 52px); max-width: calc(100% - 52px); margin: -2px 0 2px 52px;',
        '  display: flex; flex-wrap: wrap; gap: 7px; align-items: center;',
        '  animation: msgIn .25s cubic-bezier(.2,.7,.3,1) both;',
        '}',
        '.human-contact-title {',
        '  flex-basis: 100%; color: var(--muted); font-size: 12px; font-weight: 650; line-height: 1.2;',
        '}',
        '.human-link {',
        '  min-height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;',
        '  border: 1px solid var(--border); border-radius: 999px; padding: 7px 12px;',
        '  background: var(--surface); color: var(--text); text-decoration: none; font-size: 12.5px; font-weight: 700;',
        '  transition: border-color .15s ease, transform .1s ease, background .15s ease;',
        '}',
        '.human-link:hover { border-color: var(--accent); background: var(--surface2); }',
        '.human-link:active { transform: scale(.96); }',
        '.cart-action {',
        '  flex: 1 1 auto; min-width: 0; display: flex; align-items: center; gap: 8px;',
        '}',
        '.cart-action .buy-mini {',
        '  width: 100%; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 9px;',
        '  border: 1px solid color-mix(in srgb, var(--accent) 78%, white); border-radius: 10px;',
        '  background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 92%, white), var(--accent));',
        '  color: #fff; box-shadow: 0 8px 18px rgba(0,0,0,.22); cursor: pointer; padding: 10px 14px;',
        '  font-family: inherit; font-size: 13px; font-weight: 800; white-space: nowrap;',
        '  transition: transform .1s ease, filter .15s ease, box-shadow .15s ease;',
        '}',
        '.cart-action .buy-mini:hover:not(:disabled) { filter: brightness(1.08); box-shadow: 0 10px 22px rgba(0,0,0,.26); }',
        '.cart-action .buy-mini:active:not(:disabled) { transform: scale(.96); }',
        '.cart-action .buy-mini:disabled { opacity: .55; cursor: default; }',
        '.cart-action .buy-mini span { line-height: 1; }',
        '.cart-action .buy-mini svg { width: 19px; height: 19px; display: block; stroke: currentColor; }',
        '.mrow.cart-action-row .avatar { visibility: hidden; }',
        '.brand-choices {',
        '  display: flex; gap: 8px; align-items: stretch; align-self: flex-start; flex: 0 0 auto;',
        '  width: calc(100% - 52px); max-width: calc(100% - 52px); min-height: 98px; margin-left: 52px;',
        '  overflow-x: auto; overflow-y: hidden; padding: 2px 2px 10px;',
        '  scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch; scrollbar-width: thin;',
        '  scrollbar-color: var(--border) transparent;',
        '  position: relative; z-index: 1;',
        '  animation: msgIn .25s cubic-bezier(.2,.7,.3,1) both;',
        '}',
        '.brand-choices::-webkit-scrollbar { height: 7px; }',
        '.brand-choices::-webkit-scrollbar-track { background: transparent; }',
        '.brand-choices::-webkit-scrollbar-thumb { background: var(--border); border-radius: 999px; }',
        '.brand-choices::-webkit-scrollbar-thumb:hover { background: var(--muted); }',
        '.brand-choices::-webkit-scrollbar-button { display: none; width: 0; height: 0; }',
        '.m .brand-choices { width: 100%; max-width: 100%; margin: 10px 0 0; }',
        '.brand-card {',
        '  flex: 0 0 180px; width: 180px; min-width: 180px; min-height: 86px; border: 1px solid var(--border);',
        '  background: var(--surface); color: var(--text); border-radius: 8px; padding: 10px;',
        '  display: grid; grid-template-columns: 58px minmax(0, 1fr); grid-template-rows: auto auto; align-items: center; gap: 3px 10px;',
        '  font-family: inherit; cursor: pointer; scroll-snap-align: start;',
        '  transition: border-color .15s ease, transform .15s ease;',
        '}',
        '.brand-card:hover:not(:disabled) { border-color: var(--accent); transform: translateY(-1px); }',
        '.brand-card:disabled { opacity: .45; cursor: default; }',
        '.brand-card img { grid-row: 1 / span 2; max-width: 100%; width: 58px; height: 48px; object-fit: contain; }',
        '.brand-initial {',
        '  grid-row: 1 / span 2; width: 48px; height: 48px; border-radius: 8px; display: flex; align-items: center; justify-content: center;',
        '  background: var(--surface2); color: var(--accent); font-weight: 800; font-size: 21px;',
        '}',
        '.brand-label {',
        '  max-width: 100%; color: var(--text); font-size: 14px; font-weight: 750; text-align: left;',
        '  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;',
        '}',
        '.brand-count { color: var(--muted); font-size: 12px; text-align: left; }',
        '.card {',
        '  flex: 0 0 236px; display: flex; gap: 9px; padding: 9px; border: 1px solid var(--border);',
        '  border-radius: 10px; background: var(--surface); align-items: stretch;',
        '  animation: msgIn .3s cubic-bezier(.2,.7,.3,1) both;',
        '  transition: border-color .15s ease, transform .15s ease; scroll-snap-align: start;',
        '}',
        '.card:hover { border-color: var(--accent); transform: translateY(-1px); }',
        '.cards.single-card { cursor: default; overflow: visible; padding-right: 0; }',
        '.cards.single-card .card { flex: 1 1 100%; width: 100%; min-width: 0; gap: 11px; padding: 10px; }',
        '.cards.single-card .card img, .cards.single-card .card .ph { width: 76px; height: 76px; }',
        '.cards.single-card .card .ph { font-size: 10px; }',
        '.cards.single-card .card .nm { font-size: 13px; -webkit-line-clamp: 2; }',
        '.cards.single-card .card .row { gap: 8px; }',
        '.cards.single-card .card .buy { flex-basis: 44px; width: 44px; }',
        '.card img {',
        '  width: 66px; height: 66px; object-fit: contain; flex-shrink: 0;',
        '  background: var(--surface2); border: 1px solid var(--border); border-radius: 8px;',
        '}',
        '.card .ph {',
        '  width: 66px; height: 66px; flex-shrink: 0; border: 1px solid var(--border); border-radius: 8px;',
        '  background: linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,.015)); color: var(--muted);',
        '  display: flex; align-items: center; justify-content: center; text-align: center;',
        '  padding: 6px; font-size: 9.5px; line-height: 1.15; font-weight: 700;',
        '  box-shadow: inset 0 0 0 1px rgba(255,255,255,.025);',
        '}',
        '.card .img-link { flex-shrink: 0; }',
        '.card .info { flex: 1; min-width: 0; display: flex; flex-direction: column; }',
        '.card .nm {',
        '  font-size: 12.7px; line-height: 1.3; color: var(--text); margin-bottom: 4px;',
        '  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;',
        '  overflow: hidden; text-decoration: none;',
        '}',
        '.card a.nm:hover { text-decoration: underline; }',
        '.card .pr { font-weight: 700; font-size: 14px; color: var(--text); }',
        '.card .oldpr { font-size: 12px; color: var(--muted); text-decoration: line-through; }',
        '.card .badge { display: inline-block; margin: 2px 0 3px; padding: 1px 6px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 700; }',
        '.card .out { font-size: 11px; color: #f87171; }',
        '.card .row { display: flex; gap: 7px; margin-top: auto; padding-top: 8px; flex-wrap: nowrap; }',
        '.card .buy {',
        '  flex: 0 0 38px; width: 38px; border: none; background: var(--accent);',
        '  background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 92%, white), var(--accent));',
        '  color: #fff; box-shadow: 0 6px 14px rgba(0,0,0,.18); cursor: pointer;',
        '  font-family: inherit;',
        '}',
        '.card .buy svg { width: 18px; height: 18px; display: block; stroke: currentColor; }',
        '.card .buy, .card .view {',
        '  min-width: 0; min-height: 36px; display: flex; align-items: center;',
        '  justify-content: center; text-align: center; white-space: nowrap;',
        '  border-radius: 8px; padding: 7px 9px; font-size: 12px; font-weight: 650;',
        '  transition: transform .1s ease, filter .15s ease;',
        '}',
        '.card .buy:hover:not(:disabled), .card .view:hover { filter: brightness(1.1); }',
        '.card .buy:active:not(:disabled), .card .view:active { transform: scale(.96); }',
        '.card .buy:disabled { opacity: .55; cursor: default; }',
        '.card .view {',
        '  flex: 1 1 auto;',
        '  border: 1px solid var(--border); background: var(--surface); color: var(--text);',
        '  cursor: pointer; text-decoration: none;',
        '  font-family: inherit;',
        '}',
        '.feedback {',
        '  align-self: flex-start; width: calc(100% - 52px); max-width: calc(100% - 52px); margin-left: 52px;',
        '  padding: 12px; border: 1px solid var(--border); border-radius: 8px;',
        '  background: var(--surface); color: var(--text); animation: msgIn .25s cubic-bezier(.2,.7,.3,1) both;',
        '}',
        '.feedback-title { font-size: 13px; font-weight: 700; margin-bottom: 8px; }',
        '.feedback-stars { display: flex; gap: 6px; margin-bottom: 8px; }',
        '.feedback-star {',
        '  width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 8px;',
        '  background: var(--surface2); color: var(--muted); cursor: pointer; font-size: 19px; line-height: 1;',
        '}',
        '.feedback-star.active { border-color: var(--accent); background: var(--accent); color: #fff; }',
        '.feedback textarea {',
        '  width: 100%; min-height: 72px; resize: vertical; border: 1px solid var(--border); border-radius: 8px;',
        '  padding: 8px 9px; font: inherit; font-size: 13px; color: var(--text); background: var(--bg);',
        '}',
        '.feedback-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }',
        '.feedback-submit, .feedback-skip {',
        '  min-height: 34px; border-radius: 8px; padding: 7px 11px; font: inherit;',
        '  font-size: 13px; font-weight: 650; cursor: pointer;',
        '}',
        '.feedback-submit { border: none; background: var(--accent); color: #fff; }',
        '.feedback-submit:disabled { opacity: .5; cursor: not-allowed; }',
        '.feedback-skip { border: 1px solid var(--border); background: transparent; color: var(--text); }',
        '@media (max-width: 480px) {',
        '  .wrap { bottom: calc(12px + env(safe-area-inset-bottom)); }',
        '  .wrap.right { right: 12px; } .wrap.left { left: 12px; }',
        '  .teaser { display: none; }',
        '  .panel, .panel.expanded { width: calc(100vw - 24px); height: calc(100vh - 100px); max-height: calc(100vh - 100px); }',
        '  .head { padding: 12px 14px; }',
        '  .ibtn { width: 40px; height: 40px; }',
        '  .ibtn-expand { display: none; }',
        '  .msgs { padding: 14px; }',
        '  .mrow, .mrow.user { max-width: 96%; }',
        '  .mrow.product-detail-row, .mrow.cart-action-row { width: 96%; max-width: 96%; }',
        '  .brand-card { flex-basis: 82%; width: 82%; min-width: 82%; }',
        '  .card { gap: 9px; padding: 9px; }',
        '  .cards.single-card .card { gap: 9px; padding: 9px; }',
        '  .card img { width: 74px; height: 74px; }',
        '  .card .ph { width: 74px; height: 74px; }',
        '  .card .row { gap: 6px; }',
        '  .card .buy, .card .view { min-height: 38px; }',
        '}',
        // Mobile browsers with a collapsing address bar report a taller
        // `100vh` than what is actually visible, clipping the bottom of the
        // panel. `100dvh` tracks the real visible height; fall back to vh
        // above when the browser does not support it.
        '@supports (height: 100dvh) {',
        '  @media (max-width: 480px) {',
        '    .panel, .panel.expanded { height: calc(100dvh - 100px); max-height: calc(100dvh - 100px); }',
        '  }',
        '}'
    ].join('\n');

    var els = {};
    var busy = false;
    var userTurns = 0;
    var feedbackShown = false;
    var feedbackSent = false;
    var productPromptKey = '';
    var vid = visitorId();
    var transcriptSaveTimer = null;
    var restoringTranscript = false;

    function transcriptKey() {
        return 'dstore_chat_transcript:' + (CFG.webshop || 'default') + ':' + (vid || 'anon');
    }

    function saveTranscriptNow() {
        if (restoringTranscript || !els.msgs) {
            return;
        }
        try {
            window.localStorage.setItem(transcriptKey(), els.msgs.innerHTML);
        } catch (e) {
            // Storage can be disabled; the backend history still works.
        }
    }

    function saveTranscriptSoon() {
        if (restoringTranscript || !els.msgs) {
            return;
        }
        if (transcriptSaveTimer) {
            window.clearTimeout(transcriptSaveTimer);
        }
        transcriptSaveTimer = window.setTimeout(function () {
            saveTranscriptNow();
        }, 50);
    }

    function packData(value) {
        try {
            return encodeURIComponent(JSON.stringify(value || {}));
        } catch (e) {
            return '';
        }
    }

    function unpackData(value) {
        try {
            return JSON.parse(decodeURIComponent(String(value || '')));
        } catch (e) {
            return null;
        }
    }

    function setCartButtonAdded(btn) {
        btn.setAttribute('data-cart-added', '1');
        btn.disabled = true;
        btn.title = 'Dodano u korpu';
        btn.setAttribute('aria-label', 'Dodano u korpu');

        var label = btn.querySelector('span');
        if (label) {
            label.textContent = 'Dodano';
            return;
        }

        btn.textContent = '✓';
    }

    function hydrateTranscriptControls() {
        Array.prototype.forEach.call(els.msgs.querySelectorAll('.qreplies'), function (wrap) {
            Array.prototype.forEach.call(wrap.querySelectorAll('.chip'), function (chip) {
                if (chip.getAttribute('data-hydrated') === '1') {
                    return;
                }
                chip.setAttribute('data-hydrated', '1');
                chip.addEventListener('click', function () {
                    if (busy || chip.disabled) {
                        return;
                    }
                    Array.prototype.forEach.call(wrap.querySelectorAll('.chip'), function (b) {
                        b.disabled = true;
                    });
                    send(chip.getAttribute('data-query') || chip.textContent || '', {
                        product_action: chip.getAttribute('data-product-action') || ''
                    });
                });
            });
        });

        Array.prototype.forEach.call(els.msgs.querySelectorAll('.brand-choices'), function (wrap) {
            Array.prototype.forEach.call(wrap.querySelectorAll('.brand-card'), function (card) {
                if (card.getAttribute('data-hydrated') === '1') {
                    return;
                }
                card.setAttribute('data-hydrated', '1');
                card.addEventListener('click', function () {
                    if (busy || card.disabled) {
                        return;
                    }
                    Array.prototype.forEach.call(wrap.querySelectorAll('.brand-card'), function (b) {
                        b.disabled = true;
                    });
                    send(card.getAttribute('data-query') || card.textContent || '');
                });
            });
        });

        Array.prototype.forEach.call(els.msgs.querySelectorAll('.buy, .buy-mini'), function (btn) {
            if (btn.getAttribute('data-hydrated') === '1') {
                return;
            }
            btn.setAttribute('data-hydrated', '1');
            if (btn.getAttribute('data-cart-added') === '1') {
                setCartButtonAdded(btn);
                return;
            }
            btn.addEventListener('click', function () {
                var product = unpackData(btn.getAttribute('data-product'));
                if (product) {
                    addToCart(product, btn);
                }
            });
        });

        Array.prototype.forEach.call(els.msgs.querySelectorAll('.cards'), function (wrap) {
            if (wrap.getAttribute('data-slider-hydrated') === '1') {
                return;
            }
            var dots = wrap.nextElementSibling && wrap.nextElementSibling.classList.contains('card-dots')
                ? wrap.nextElementSibling
                : null;
            if (dots) {
                dots.textContent = '';
            }
            enhanceProductSlider(wrap, dots);
            wrap.setAttribute('data-slider-hydrated', '1');
        });
    }

    function hasStoredProductPrompt(key) {
        var found = false;
        Array.prototype.forEach.call(els.msgs.querySelectorAll('.product-context-prompt'), function (row) {
            var next = row.nextElementSibling;
            if (row.getAttribute('data-product-prompt-key') === key
                && next
                && next.classList.contains('qreplies')
                && next.querySelector('.chip')) {
                found = true;
            }
        });
        return found;
    }

    function restoreTranscript() {
        if (!els.msgs) {
            return false;
        }
        try {
            var html = window.localStorage.getItem(transcriptKey());
            if (!html) {
                return false;
            }
            restoringTranscript = true;
            els.msgs.innerHTML = html;
            restoringTranscript = false;
            hydrateTranscriptControls();
            userTurns = els.msgs.querySelectorAll('.mrow.user').length;
            var currentKey = productContext.id || productContext.name || '';
            productPromptKey = currentKey && hasStoredProductPrompt(currentKey) ? currentKey : '';
            saveTranscriptSoon();
            return true;
        } catch (e) {
            restoringTranscript = false;
            return false;
        }
    }

    function clearTranscript() {
        try {
            window.localStorage.removeItem(transcriptKey());
        } catch (e) {
            // Storage can be disabled.
        }
    }

    function build() {
        var host = document.createElement('div');
        host.setAttribute('data-dstore-chat', '');
        shadowHost = host;
        var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

        var style = document.createElement('style');
        style.textContent = ':host{--accent:' + CFG.color + ';}\n' + CSS;
        root.appendChild(style);

        var wrap = document.createElement('div');
        wrap.className = 'wrap ' + CFG.position;

        var panel = document.createElement('div');
        panel.className = 'panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', CFG.title);
        panel.hidden = true;

        var head = document.createElement('div');
        head.className = 'head';

        var t = document.createElement('div');
        t.className = 't';
        t.textContent = CFG.title;

        var acts = document.createElement('div');
        acts.className = 'acts';

        var expandBtn = document.createElement('button');
        expandBtn.type = 'button';
        expandBtn.className = 'ibtn ibtn-expand';
        expandBtn.innerHTML = ICON_EXPAND;
        expandBtn.title = TEXT.expandAria;
        expandBtn.setAttribute('aria-label', TEXT.expandAria);
        expandBtn.setAttribute('aria-pressed', 'false');

        var resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'ibtn';
        resetBtn.innerHTML = ICON_RESET;
        resetBtn.title = TEXT.reset;
        resetBtn.setAttribute('aria-label', TEXT.resetAria);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'ibtn';
        closeBtn.textContent = '✕';
        closeBtn.title = TEXT.close;
        closeBtn.setAttribute('aria-label', TEXT.close);

        acts.appendChild(expandBtn);
        acts.appendChild(resetBtn);
        acts.appendChild(closeBtn);
        head.appendChild(t);
        head.appendChild(acts);

        var msgs = document.createElement('div');
        msgs.className = 'msgs';
        msgs.setAttribute('role', 'log');
        msgs.setAttribute('aria-live', 'polite');

        var form = document.createElement('form');
        var input = document.createElement('textarea');
        input.rows = 1;
        input.placeholder = TEXT.placeholder;
        input.maxLength = MAX_LEN;
        input.setAttribute('aria-label', TEXT.placeholder);

        var send = document.createElement('button');
        send.type = 'submit';
        send.className = 'send';
        send.textContent = TEXT.send;

        form.appendChild(input);
        form.appendChild(send);

        panel.appendChild(head);
        panel.appendChild(msgs);
        panel.appendChild(form);

        var launcher = document.createElement('button');
        launcher.type = 'button';
        launcher.className = 'launcher';
        launcher.setAttribute('aria-label', TEXT.open);
        launcher.setAttribute('aria-expanded', 'false');
        launcher.innerHTML = ICON_CHAT;

        var teaser = document.createElement('button');
        teaser.type = 'button';
        teaser.className = 'teaser';
        teaser.textContent = CFG.teaser;

        wrap.appendChild(panel);
        wrap.appendChild(teaser);
        wrap.appendChild(launcher);
        root.appendChild(wrap);
        document.body.appendChild(host);

        els = {
            panel: panel, msgs: msgs, form: form, input: input,
            send: send, launcher: launcher, closeBtn: closeBtn, resetBtn: resetBtn,
            expandBtn: expandBtn, teaser: teaser
        };
    }

    function addMessage(text, kind) {
        var row = document.createElement('div');
        row.className = 'mrow ' + kind;

        if (kind !== 'user') {
            var avatar = document.createElement('div');
            avatar.className = 'avatar';
            if (CFG.logo) {
                var logoImg = document.createElement('img');
                logoImg.src = CFG.logo;
                logoImg.alt = '';
                avatar.appendChild(logoImg);
            } else {
                avatar.innerHTML = LOGO_SVG;
            }
            row.appendChild(avatar);
        }

        var el = document.createElement('div');
        el.className = 'm ' + kind;
        if (kind === 'bot' && /^Evo kratkih detalja za taj artikal:/i.test(String(text || ''))) {
            row.className += ' product-detail-row';
            el.className += ' product-detail';
        }
        renderMessageText(el, text);
        row.appendChild(el);

        els.msgs.appendChild(row);
        els.msgs.scrollTop = els.msgs.scrollHeight;
        saveTranscriptSoon();
        return el;
    }

    function lastBotMessageElement() {
        var rows = els.msgs.querySelectorAll('.mrow.bot');
        if (!rows.length) {
            return null;
        }
        var row = rows[rows.length - 1];
        var msg = row.querySelector('.m.bot');
        return msg ? { row: row, msg: msg } : null;
    }

    function contactTextMentionsChannels(text) {
        var s = String(text || '').toLowerCase();
        return /whats\s*app|whatsapp|viber|messenger|m\.me|wa\.me/.test(s);
    }

    function addHumanContactOptions(force) {
        var links = [
            { label: 'WhatsApp', href: CFG.whatsapp },
            { label: 'Viber', href: CFG.viber },
            { label: 'Messenger', href: CFG.messenger }
        ].filter(function (item) {
            return item.href;
        });

        if (!links.length || (!force && els.msgs.querySelector('.human-contact'))) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'human-contact';
        wrap.setAttribute('data-hydrated', '1');

        var title = document.createElement('div');
        title.className = 'human-contact-title';
        title.textContent = 'Kontakt sa osobom';
        wrap.appendChild(title);

        links.forEach(function (item) {
            var link = document.createElement('a');
            link.className = 'human-link';
            link.href = item.href;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = item.label;
            wrap.appendChild(link);
        });

        els.msgs.appendChild(wrap);
        els.msgs.scrollTop = els.msgs.scrollHeight;
        saveTranscriptSoon();
    }

    /**
     * Clickable option chips, shown under a bot reply that asks the customer
     * to pick from a short list ("Antene" has several real subtypes) — one
     * tap sends that option as the next message instead of retyping it.
     *
     * @param {{label:string,query:string}[]} options The chip's visible text
     *        (label) and the message actually sent when tapped (query) are
     *        not always the same string — a bare brand name ("Samsung")
     *        loses the product type it was answering for once it comes back
     *        as its own message, so the server bundles the two together.
     */
    function addQuickReplies(options) {
        if (!options || !options.length) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'qreplies';

        options.forEach(function (option) {
            var label = option && option.label ? String(option.label) : '';
            var query = option && option.query ? String(option.query) : label;
            if (!label) {
                return;
            }

            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chip';
            chip.textContent = label;
            chip.setAttribute('data-query', query);
            if (option && option.productAction) {
                chip.setAttribute('data-product-action', option.productAction);
            }
            chip.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                // All chips in this set are spent once one is picked - keeps
                // the customer from firing the same clarification twice.
                Array.prototype.forEach.call(wrap.querySelectorAll('.chip'), function (b) {
                    b.disabled = true;
                });
                send(query, option && option.productAction ? { product_action: option.productAction } : null);
            });
            wrap.appendChild(chip);
        });

        els.msgs.appendChild(wrap);
        els.msgs.scrollTop = els.msgs.scrollHeight;
        saveTranscriptSoon();
    }

    function addBrandChoices(options) {
        if (!options || !options.length) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'brand-choices';

        options.forEach(function (option) {
            var label = option && option.label ? String(option.label) : '';
            var query = option && option.query ? String(option.query) : label;
            if (!label) {
                return;
            }

            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'brand-card';
            card.setAttribute('data-query', query);

            var fallback = document.createElement('div');
            fallback.className = 'brand-initial';
            fallback.textContent = label.charAt(0).toUpperCase();

            if (option.image) {
                var img = document.createElement('img');
                img.src = String(option.image);
                img.alt = label;
                img.loading = 'lazy';
                img.addEventListener('load', function () {
                    els.msgs.scrollTop = els.msgs.scrollHeight;
                });
                img.addEventListener('error', function () {
                    img.replaceWith(fallback);
                    els.msgs.scrollTop = els.msgs.scrollHeight;
                });
                card.appendChild(img);
            } else {
                card.appendChild(fallback);
            }

            var name = document.createElement('div');
            name.className = 'brand-label';
            name.textContent = label;
            card.appendChild(name);

            if (option.products) {
                var count = document.createElement('div');
                count.className = 'brand-count';
                count.textContent = option.products + ' artikala';
                card.appendChild(count);
            }

            card.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                Array.prototype.forEach.call(wrap.querySelectorAll('.brand-card'), function (b) {
                    b.disabled = true;
                });
                send(query);
            });

            wrap.appendChild(card);
        });

        var last = lastBotMessageElement();
        if (last) {
            last.row.classList.add('has-brands');
            last.msg.classList.add('has-brands');
            last.msg.appendChild(wrap);
        } else {
            els.msgs.appendChild(wrap);
        }
        els.msgs.scrollTop = els.msgs.scrollHeight;
        window.setTimeout(function () {
            els.msgs.scrollTop = els.msgs.scrollHeight;
        }, 80);
        window.setTimeout(function () {
            els.msgs.scrollTop = els.msgs.scrollHeight;
        }, 250);
        saveTranscriptSoon();
    }

    function productReferenceText() {
        if (productContext.name) {
            return productContext.name;
        }
        if (productContext.id) {
            return 'ovaj artikal';
        }
        return '';
    }

    function maybeShowProductPrompt() {
        var ref = productReferenceText();
        if (!ref) {
            return;
        }

        var key = productContext.id || productContext.name;
        if (productPromptKey === key) {
            return;
        }
        productPromptKey = key;

        var label = productContext.name || 'ovaj artikal';
        var promptEl = addMessage('Vidim da gledate ' + label + '. Mogu odmah pomoći oko ovog artikla.', 'bot');
        if (promptEl && promptEl.parentNode) {
            promptEl.parentNode.classList.add('product-context-prompt');
            promptEl.parentNode.setAttribute('data-product-prompt-key', key);
        }
        addQuickReplies([
            { label: 'Da li je na stanju?', query: 'Da li je na stanju?', productAction: 'stock' },
            { label: 'Koja je cijena?', query: 'Koja je cijena?', productAction: 'price' },
            { label: 'Garancija', query: 'Koliko traje garancija?', productAction: 'warranty' }
        ]);
    }

    function compactText(lines) {
        return lines.join('\n').replace(/(?:\n\s*){3,}/g, '\n\n').trim();
    }

    function defaultProductIntro(products) {
        var hasAction = false;
        (products || []).forEach(function (p) {
            if (p && p.is_action) {
                hasAction = true;
            }
        });

        return hasAction
            ? 'Evo nekoliko akcijskih ponuda iz našeg asortimana koje bi vas mogle zanimati:'
            : 'Evo nekoliko prijedloga iz našeg asortimana:';
    }

    function splitReplyForCards(text, products) {
        var s = String(text || '');
        if (!products || !products.length) {
            return { before: s, after: '' };
        }

        var lines = s.split(/\r?\n/);
        var before = [];
        var after = [];
        var inClosing = false;

        lines.forEach(function (line) {
            var trimmed = line.trim();
            if (!inClosing && trimmed.indexOf('Preporučujem da pogledate detalje') === 0) {
                inClosing = true;
            }

            if (inClosing) {
                after.push(line);
                return;
            }

            if (/^(?:[•*-]|\d+[.)])\s+/.test(trimmed)) {
                return;
            }

            before.push(line);
        });

        var beforeText = compactText(before);
        var afterText = compactText(after);
        if (!beforeText && afterText) {
            beforeText = defaultProductIntro(products);
        }

        return { before: beforeText || s, after: afterText };
    }

    function addProductMessage(text, products, moreUrl) {
        var parts = splitReplyForCards(text, products);
        var el = addMessage(parts.before, 'bot');

        renderCards(products, el);

        if (parts.after) {
            var after = document.createElement('div');
            after.className = 'after-cards';
            renderMessageText(after, parts.after);
            el.appendChild(after);
        }

        if (moreUrl) {
            var more = document.createElement('a');
            more.className = 'more-link';
            more.href = moreUrl;
            more.target = '_blank';
            more.rel = 'noopener';
            more.textContent = 'Prikaži više';
            el.appendChild(more);
        }

        els.msgs.scrollTop = els.msgs.scrollHeight;

        return el;
    }

    function renderMessageText(el, text) {
        // Never use innerHTML — chat text is untrusted. URLs are converted to
        // anchors by splitting into text nodes plus safe <a> elements.
        var s = String(text || '');
        var re = /https?:\/\/[^\s<>()]+/g;
        var last = 0;
        var match;

        while ((match = re.exec(s)) !== null) {
            if (match.index > last) {
                el.appendChild(document.createTextNode(s.slice(last, match.index)));
            }

            var url = match[0];
            var trailing = '';
            while (/[.,!?;:]$/.test(url)) {
                trailing = url.slice(-1) + trailing;
                url = url.slice(0, -1);
            }

            var a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = url;
            el.appendChild(a);

            if (trailing) {
                el.appendChild(document.createTextNode(trailing));
            }

            last = match.index + match[0].length;
        }

        if (last < s.length) {
            el.appendChild(document.createTextNode(s.slice(last)));
        }
    }

    function money(v) {
        return v.toFixed(2).replace('.', ',') + ' ' + CFG.currency;
    }

    /**
     * Add the product to the shop's basket.
     *
     * The widget does not know each site's own cart implementation (or even
     * whether it has a global JS function for it at all) - guessing a
     * function name/signature was tried before and left unverified against
     * the real sites. Instead this fires a cancelable CustomEvent on
     * `window` with the product's own data and lets each site's own JS
     * decide what "add to cart" means there (call their own function, fire
     * an AJAX request, whatever their platform actually does).
     *
     * Contract for the site's own JS:
     *
     *   window.addEventListener('dstorechat:addtocart', function (e) {
     *     var p = e.detail.product;      // {id, name, model, ean, price, url}
     *     var qty = e.detail.qty;        // always 1 today
     *     ... add it to your own cart however your site does that ...
     *     e.preventDefault();            // tells the widget you handled it
     *   });
     *
     * If nothing calls preventDefault() - no listener registered at all, or
     * a listener that does not handle it - dispatchEvent() returns true and
     * the widget falls back to opening the product page in a new tab, the
     * same safe fallback as before.
     */
    function addToCart(product, btn) {
        var detail = {
            product: {
                id: product.id,
                name: product.name,
                model: product.model || null,
                ean: product.ean || null,
                price: product.price,
                url: product.url || null
            },
            qty: 1
        };

        var notCancelled = window.dispatchEvent(new CustomEvent('dstorechat:addtocart', {
            detail: detail,
            cancelable: true
        }));

        if (!notCancelled) {
            // A listener called event.preventDefault() - the site handled it.
            setCartButtonAdded(btn);
            saveTranscriptSoon();
            return;
        }

        if (product.url) {
            window.open(product.url, '_blank', 'noopener');
        }
    }

    function cartIcon() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');

        [
            'M6 6h15l-1.5 8.5a2 2 0 0 1-2 1.5H9a2 2 0 0 1-2-1.7L5.2 3H3',
            'M9 20h.01',
            'M18 20h.01'
        ].forEach(function (d) {
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        });

        return svg;
    }

    function addCartAction(product) {
        if (!product || !product.in_stock) {
            return;
        }

        var row = document.createElement('div');
        row.className = 'mrow bot cart-action-row';

        var avatar = document.createElement('div');
        avatar.className = 'avatar';
        if (CFG.logo) {
            var logoImg = document.createElement('img');
            logoImg.src = CFG.logo;
            logoImg.alt = '';
            avatar.appendChild(logoImg);
        } else {
            avatar.innerHTML = LOGO_SVG;
        }
        row.appendChild(avatar);

        var wrap = document.createElement('div');
        wrap.className = 'cart-action';

        var buy = document.createElement('button');
        buy.type = 'button';
        buy.className = 'buy-mini';
        buy.title = 'Dodaj u korpu';
        buy.setAttribute('aria-label', 'Dodaj u korpu');
        buy.setAttribute('data-product', packData(product));
        buy.appendChild(cartIcon());
        var label = document.createElement('span');
        label.textContent = 'Dodaj u korpu';
        buy.appendChild(label);
        buy.addEventListener('click', function () { addToCart(product, buy); });

        wrap.appendChild(buy);
        row.appendChild(wrap);
        els.msgs.appendChild(row);
        els.msgs.scrollTop = els.msgs.scrollHeight;
        saveTranscriptSoon();
    }

    function enhanceProductSlider(wrap, dots) {
        var cards = Array.prototype.slice.call(wrap.querySelectorAll('.card'));
        if (!cards.length || !dots) {
            return;
        }

        var dotButtons = cards.map(function (card, index) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'card-dot' + (index === 0 ? ' active' : '');
            dot.title = 'Prikaži artikal ' + (index + 1);
            dot.setAttribute('aria-label', 'Prikaži artikal ' + (index + 1));
            dot.addEventListener('click', function () {
                wrap.scrollTo({ left: card.offsetLeft - wrap.offsetLeft, behavior: 'smooth' });
            });
            dots.appendChild(dot);
            return dot;
        });

        var ticking = false;
        function updateDots() {
            ticking = false;
            var center = wrap.scrollLeft + (wrap.clientWidth / 2);
            var best = 0;
            var bestDistance = Infinity;

            cards.forEach(function (card, index) {
                var cardCenter = card.offsetLeft - wrap.offsetLeft + (card.offsetWidth / 2);
                var distance = Math.abs(cardCenter - center);
                if (distance < bestDistance) {
                    best = index;
                    bestDistance = distance;
                }
            });

            dotButtons.forEach(function (dot, index) {
                dot.classList.toggle('active', index === best);
            });
        }

        wrap.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(updateDots);
            }
        });

        var dragging = false;
        var moved = false;
        var suppressClick = false;
        var startX = 0;
        var startLeft = 0;

        wrap.addEventListener('pointerdown', function (e) {
            if (e.button !== undefined && e.button !== 0) {
                return;
            }
            if (e.target && e.target.closest && e.target.closest('a, button')) {
                return;
            }
            dragging = true;
            moved = false;
            startX = e.clientX;
            startLeft = wrap.scrollLeft;
            wrap.setPointerCapture(e.pointerId);
        });

        wrap.addEventListener('pointermove', function (e) {
            if (!dragging) {
                return;
            }
            var dx = e.clientX - startX;
            if (Math.abs(dx) > 5) {
                moved = true;
                suppressClick = true;
                wrap.classList.add('dragging');
                wrap.scrollLeft = startLeft - dx;
                e.preventDefault();
            }
        });

        function endDrag(e) {
            if (!dragging) {
                return;
            }
            dragging = false;
            wrap.classList.remove('dragging');
            if (wrap.hasPointerCapture && wrap.hasPointerCapture(e.pointerId)) {
                wrap.releasePointerCapture(e.pointerId);
            }
            if (moved) {
                window.setTimeout(function () {
                    suppressClick = false;
                }, 0);
            }
        }

        wrap.addEventListener('pointerup', endDrag);
        wrap.addEventListener('pointercancel', endDrag);
        wrap.addEventListener('click', function (e) {
            if (suppressClick) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);

        updateDots();
    }

    function renderCards(products, parent) {
        var wrap = document.createElement('div');
        wrap.className = 'cards';
        if (products.length === 1) {
            wrap.className += ' single-card';
        }

        products.forEach(function (p) {
            var card = document.createElement('div');
            card.className = 'card';

            var fallback = document.createElement('div');
            fallback.className = 'ph';
            fallback.textContent = 'Nema slike';

            if (p.image) {
                var img = document.createElement('img');
                img.src = p.image;
                img.alt = p.name;
                img.loading = 'lazy';
                // Not every product has a picture under its barcode.
                img.addEventListener('error', function () { img.replaceWith(fallback); });

                if (p.url) {
                    var imgLink = document.createElement('a');
                    imgLink.className = 'img-link';
                    imgLink.href = p.url;
                    imgLink.target = '_blank';
                    imgLink.rel = 'noopener';
                    imgLink.appendChild(img);
                    card.appendChild(imgLink);
                } else {
                    card.appendChild(img);
                }
            } else {
                card.appendChild(fallback);
            }

            var info = document.createElement('div');
            info.className = 'info';

            var nm = document.createElement(p.url ? 'a' : 'div');
            nm.className = 'nm';
            nm.textContent = p.name;
            if (p.url) {
                nm.href = p.url;
                nm.target = '_blank';
                nm.rel = 'noopener';
            }
            info.appendChild(nm);

            if (p.is_action) {
                var badge = document.createElement('div');
                badge.className = 'badge';
                badge.textContent = p.discount_percent
                    ? ('Akcija -' + Math.round(p.discount_percent) + '%')
                    : 'Akcija';
                info.appendChild(badge);
            }

            if (p.is_action && p.price_before) {
                var oldPr = document.createElement('div');
                oldPr.className = 'oldpr';
                oldPr.textContent = money(p.price_before);
                info.appendChild(oldPr);
            }

            var pr = document.createElement('div');
            pr.className = 'pr';
            pr.textContent = (p.price === null || p.price === undefined)
                ? 'Cijena na upit'
                : money(p.price);
            info.appendChild(pr);

            if (!p.in_stock) {
                var out = document.createElement('div');
                out.className = 'out';
                out.textContent = 'Trenutno nije na stanju';
                info.appendChild(out);
            }

            var row = document.createElement('div');
            row.className = 'row';

            if (p.in_stock) {
                var buy = document.createElement('button');
                buy.type = 'button';
                buy.className = 'buy';
                buy.title = 'Dodaj u korpu';
                buy.setAttribute('aria-label', 'Dodaj u korpu');
                buy.setAttribute('data-product', packData(p));
                buy.appendChild(cartIcon());
                buy.addEventListener('click', function () { addToCart(p, buy); });
                row.appendChild(buy);
            }

            if (p.url) {
                var view = document.createElement('a');
                view.className = 'view';
                view.href = p.url;
                view.target = '_blank';
                view.rel = 'noopener';
                view.textContent = 'Detalji';
                row.appendChild(view);
            }

            info.appendChild(row);
            card.appendChild(info);
            wrap.appendChild(card);
        });

        var dots = null;
        if (products.length > 1) {
            dots = document.createElement('div');
            dots.className = 'card-dots';
        }

        if (parent) {
            parent.className += ' has-cards';
            parent.appendChild(wrap);
            if (dots) {
                parent.appendChild(dots);
            }
        } else {
            els.msgs.appendChild(wrap);
            if (dots) {
                els.msgs.appendChild(dots);
            }
        }
        enhanceProductSlider(wrap, dots);
        els.msgs.scrollTop = els.msgs.scrollHeight;
        saveTranscriptSoon();
    }

    function showTyping() {
        var el = document.createElement('div');
        el.className = 'typing';
        el.setAttribute('aria-label', TEXT.typing);
        for (var i = 0; i < 3; i++) {
            el.appendChild(document.createElement('i'));
        }
        els.msgs.appendChild(el);
        els.msgs.scrollTop = els.msgs.scrollHeight;
        return el;
    }

    function shouldAskFeedback() {
        return userTurns > 0 && !feedbackShown && !feedbackSent;
    }

    function showFeedback(afterDone) {
        if (!shouldAskFeedback()) {
            if (afterDone) { afterDone(); }
            return;
        }

        feedbackShown = true;

        var box = document.createElement('div');
        box.className = 'feedback';

        var title = document.createElement('div');
        title.className = 'feedback-title';
        title.textContent = TEXT.feedbackTitle;
        box.appendChild(title);

        var rating = 0;
        var stars = document.createElement('div');
        stars.className = 'feedback-stars';

        function paintStars() {
            Array.prototype.forEach.call(stars.querySelectorAll('.feedback-star'), function (btn) {
                var value = parseInt(btn.getAttribute('data-rating'), 10);
                btn.classList.toggle('active', value <= rating);
            });
        }

        for (var i = 1; i <= 5; i++) {
            var star = document.createElement('button');
            star.type = 'button';
            star.className = 'feedback-star';
            star.textContent = '★';
            star.setAttribute('data-rating', String(i));
            star.setAttribute('aria-label', i + ' / 5');
            star.addEventListener('click', function () {
                rating = parseInt(this.getAttribute('data-rating'), 10) || 0;
                submit.disabled = rating < 1;
                paintStars();
            });
            stars.appendChild(star);
        }
        box.appendChild(stars);

        var note = document.createElement('textarea');
        note.maxLength = 1000;
        note.placeholder = TEXT.feedbackPlaceholder;
        box.appendChild(note);

        var actions = document.createElement('div');
        actions.className = 'feedback-actions';

        var skip = document.createElement('button');
        skip.type = 'button';
        skip.className = 'feedback-skip';
        skip.textContent = TEXT.feedbackSkip;

        var submit = document.createElement('button');
        submit.type = 'button';
        submit.className = 'feedback-submit';
        submit.textContent = TEXT.feedbackSubmit;
        submit.disabled = true;

        skip.addEventListener('click', function () {
            box.remove();
            if (afterDone) { afterDone(); }
        });

        submit.addEventListener('click', function () {
            if (rating < 1 || feedbackSent) {
                return;
            }
            submit.disabled = true;
            skip.disabled = true;
            post({
                feedback: true,
                rating: rating,
                comment: note.value.trim(),
                page_url: window.location.href,
                visitor_id: vid
            }).then(function () {
                feedbackSent = true;
                box.remove();
                if (afterDone) { afterDone(); }
            }).catch(function () {
                submit.disabled = false;
                skip.disabled = false;
                addMessage(TEXT.netError, 'err');
            });
        });

        actions.appendChild(skip);
        actions.appendChild(submit);
        box.appendChild(actions);

        els.msgs.appendChild(box);
        els.msgs.scrollTop = els.msgs.scrollHeight;
    }

    function post(body) {
        if (CFG.webshop) {
            body.webshop = CFG.webshop;
        }
        if (visitorInfo.customerId) {
            body.customer_id = visitorInfo.customerId;
        }
        if (visitorInfo.customerName) {
            body.customer_name = visitorInfo.customerName;
        }
        if (visitorInfo.isWholesaleCustomer) {
            body.wholesale_hint = true;
        }
        if (productContext.id) {
            body.product_id = productContext.id;
        }
        if (productContext.name) {
            body.product_name = productContext.name;
        }
        if (productContext.url) {
            body.product_url = productContext.url;
        }
        return fetch(CFG.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(body)
        });
    }

    function send(text, extraBody) {
        userTurns++;
        addMessage(text, 'user');
        saveTranscriptNow();
        busy = true;
        els.send.disabled = true;
        var typing = showTyping();

        var body = { message: text, visitor_id: vid };
        if (extraBody) {
            Object.keys(extraBody).forEach(function (key) {
                body[key] = extraBody[key];
            });
        }

        post(body)
            .then(function (res) {
                return res.json().catch(function () { return {}; });
            })
            .then(function (data) {
                typing.remove();
                // The server knows this deployment's real currency; prefer it
                // over the data-currency script attribute (which the
                // embedding page can simply forget to set).
                if (data && data.currency) {
                    CFG.currency = data.currency;
                }
                if (data && data.reply) {
                    if (data.products && data.products.length) {
                        addProductMessage(data.reply, data.products, data.more_url);
                    } else {
                        addMessage(data.reply, 'bot');
                    }
                    addCartAction(data.cart_product);
                    addQuickReplies(data.quick_replies);
                    addBrandChoices(data.brand_choices);
                    if (contactTextMentionsChannels(text) || contactTextMentionsChannels(data.reply)) {
                        addHumanContactOptions(true);
                    }
                    saveTranscriptNow();
                } else if (data && data.error) {
                    addMessage(data.error, 'err');
                    saveTranscriptNow();
                } else {
                    addMessage(TEXT.genError, 'err');
                    saveTranscriptNow();
                }
            })
            .catch(function () {
                typing.remove();
                addMessage(TEXT.netError, 'err');
                saveTranscriptNow();
            })
            .then(function () {
                busy = false;
                els.send.disabled = false;
                els.input.focus();
            });
    }

    var CLOSE_ANIM_MS = 200;
    var userTouchedPanel = false;

    function openPanel() {
        els.teaser.classList.remove('show');
        els.panel.hidden = false;
        // Force layout before adding the class so the panel actually
        // animates in from its closed (opacity:0/translated) state instead
        // of just appearing.
        void els.panel.offsetHeight;
        els.panel.classList.add('open');
        els.launcher.classList.add('is-open');
        els.launcher.innerHTML = ICON_CLOSE_X;
        els.launcher.setAttribute('aria-label', TEXT.close);
        els.launcher.setAttribute('aria-expanded', 'true');
        els.input.focus();
        els.msgs.scrollTop = els.msgs.scrollHeight;
        maybeShowProductPrompt();
    }

    function closePanel(force) {
        if (force !== true && shouldAskFeedback()) {
            showFeedback(function () { closePanel(true); });
            return;
        }
        els.panel.classList.remove('open');
        els.launcher.classList.remove('is-open');
        els.launcher.innerHTML = ICON_CHAT;
        els.launcher.setAttribute('aria-label', TEXT.open);
        els.launcher.setAttribute('aria-expanded', 'false');
        window.setTimeout(function () {
            if (!els.panel.classList.contains('open')) {
                els.panel.hidden = true;
            }
        }, CLOSE_ANIM_MS);
    }

    function init() {
        build();
        if (!restoreTranscript()) {
            addMessage(CFG.greeting, 'bot');
            addHumanContactOptions();
        }

        els.launcher.addEventListener('click', function () {
            userTouchedPanel = true;
            if (els.panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });
        els.teaser.addEventListener('click', function () {
            userTouchedPanel = true;
            openPanel();
        });

        els.closeBtn.addEventListener('click', function () {
            userTouchedPanel = true;
            closePanel();
            els.launcher.focus();
        });

        if (CFG.autoOpenDelay > 0) {
            window.setTimeout(function () {
                if (!userTouchedPanel && els.panel.hidden) {
                    els.teaser.classList.add('show');
                }
            }, CFG.autoOpenDelay);
        }

        els.resetBtn.addEventListener('click', function () {
            if (shouldAskFeedback()) {
                showFeedback(function () {
                    clearTranscript();
                    els.msgs.textContent = '';
                    userTurns = 0;
                    feedbackShown = false;
                    feedbackSent = false;
                    productPromptKey = '';
                    addMessage(CFG.greeting, 'bot');
                    addHumanContactOptions();
                    maybeShowProductPrompt();
                    post({ reset: true, visitor_id: vid }).catch(function () {});
                });
                return;
            }
            clearTranscript();
            els.msgs.textContent = '';
            userTurns = 0;
            feedbackShown = false;
            feedbackSent = false;
            productPromptKey = '';
            addMessage(CFG.greeting, 'bot');
            addHumanContactOptions();
            maybeShowProductPrompt();
            post({ reset: true, visitor_id: vid }).catch(function () {});
        });

        els.expandBtn.addEventListener('click', function () {
            var expanded = els.panel.classList.toggle('expanded');
            els.expandBtn.innerHTML = expanded ? ICON_COLLAPSE : ICON_EXPAND;
            var label = expanded ? TEXT.collapseAria : TEXT.expandAria;
            els.expandBtn.title = label;
            els.expandBtn.setAttribute('aria-label', label);
            els.expandBtn.setAttribute('aria-pressed', expanded ? 'true' : 'false');
            els.msgs.scrollTop = els.msgs.scrollHeight;
        });

        els.form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = els.input.value.trim();
            if (!text || busy) { return; }
            els.input.value = '';
            els.input.style.height = 'auto';
            send(text);
        });

        els.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                els.form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        els.input.addEventListener('input', function () {
            els.input.style.height = 'auto';
            els.input.style.height = Math.min(els.input.scrollHeight, 110) + 'px';
        });

        window.addEventListener('pagehide', saveTranscriptNow);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                saveTranscriptNow();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
