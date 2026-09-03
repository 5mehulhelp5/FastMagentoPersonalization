/**
 * FastMagento Personalisation — storefront capture.
 *
 * THEME-AGNOSTIC AND SELF-CONTAINED. No jQuery, no RequireJS, no Alpine, and no dependency on
 * core FastMagento's fastmagento.js: the one helper this file shares with it (readConfig) is
 * duplicated here on purpose so that this bundle degrades on its own if only one package is
 * deployed. Loaded as a plain <script defer> by this module's view/frontend/layout/default.xml.
 *
 * Three capture paths, each reading its own JSON config block from the page and doing nothing
 * when it is absent:
 *   - initViewTracking   product view + dwell beacon
 *   - initGridTracking   listing impressions (fm-listing-products)
 *   - initFacetTracking  facet selections when the browser owns capture (fm-analytics-config)
 */
(function () {
    'use strict';

    function readConfig(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent || el.innerText || '{}');
        } catch (e) {
            return null;
        }
    }

    /**
     * Product view and dwell.
     *
     * This is the one signal the server genuinely cannot see: a product page is served from the
     * full-page cache without reaching PHP, so nothing server-side knows it was viewed. Searches
     * and facets are collected in PHP precisely because they DO reach it — sending those from here
     * would add a round trip to re-tell the server something it just did.
     *
     * Dwell is VISIBLE time, not elapsed time. A page left open in a background tab is not
     * attention, and measuring wall clock would let a forgotten tab outweigh a shopper's whole
     * purchase history. The timer accumulates only while the document is visible, and stops the
     * moment it is not.
     *
     * Reported once, on the way out, via sendBeacon — a request the browser will deliver after the
     * page is gone, and which cannot delay navigation. There is no polling and no per-second
     * chatter: one page, one row.
     */
    function initViewTracking() {
        var el = document.querySelector('[data-fm-product-id]');
        if (!el || !navigator.sendBeacon) {
            return;
        }

        var productId = parseInt(el.getAttribute('data-fm-product-id'), 10);
        if (!productId) {
            return;
        }

        var visibleMs = 0;
        var since = document.visibilityState === 'visible' ? Date.now() : null;
        var sent = false;

        function accumulate() {
            if (since !== null) {
                visibleMs += Date.now() - since;
                since = null;
            }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                if (since === null) {
                    since = Date.now();
                }
            } else {
                accumulate();
                // A shopper who switches away may never come back to fire pagehide, so the tab
                // going hidden is itself a reporting opportunity.
                report();
            }
        });

        function report() {
            accumulate();
            var seconds = Math.round(visibleMs / 1000);
            // The floor is enforced again server-side; this just avoids the request entirely for a
            // bounce, which is most of them.
            if (sent || seconds < 3) {
                return;
            }
            sent = true;
            try {
                navigator.sendBeacon(
                    fmBaseUrl() + 'fastmagento/event/collect',
                    new Blob(
                        [JSON.stringify({events: [{type: 'view', product_id: productId, dwell_seconds: seconds}]})],
                        {type: 'application/json'}
                    )
                );
            } catch (e) {
                // Analytics never surfaces to a shopper.
            }
        }

        window.addEventListener('pagehide', report);
    }

    /**
     * Grid impressions and hover.
     *
     * An impression is "this product was genuinely on screen", not "this product was in the HTML".
     * A card must be at least half visible for a full second before it counts — scrolling past
     * thirty products in two seconds is not thirty impressions, and counting it that way would make
     * the denominator meaningless in exactly the direction that flatters a busy page.
     *
     * All of it leaves in ONE beacon on unload, and impressions travel as a single row carrying a
     * list. A 36-product grid reporting one row per product would make impressions most of the
     * events index within a week, for the lowest-value signal here.
     *
     * Hover is desktop-only by nature. Its absence on a touch device means the signal does not
     * exist, never that the shopper was uninterested.
     */
    /**
     * Which DOM node is which product, without asking the theme.
     *
     * Two sources, unioned, because neither covers every case:
     *
     *   data-product-id     what Hyvä's listing markup already provides. Free and exact.
     *   the listing island  id + path published by the module, matched against the links on the
     *                       page. Luma renders no product id anywhere in `product/list.phtml`, so
     *                       Breeze renders none either, and this is the only thing left that every
     *                       theme has: a link to the product.
     *
     * The node observed for a link is its enclosing card where one can be identified, and the link
     * itself otherwise — a link half on screen for a second means the card was on screen, and a
     * cursor resting on a product link is hovering that product by any definition worth having.
     *
     * @return array of {id, node}
     */
    function resolveProductCards() {
        var found = {};
        var out = [];
        // The product page's own product is a VIEW, never an impression of itself. Luma (and so
        // Breeze) put data-product-id on the price box, which would otherwise count every product
        // page load as an impression of that product and inflate its denominator.
        var viewed = document.querySelector('[data-fm-product-id]');
        if (viewed) {
            found[parseInt(viewed.getAttribute('data-fm-product-id'), 10)] = true;
        }

        /**
         * Climb to the card, whatever the theme hung the id on.
         *
         * This matters more than it looks. Hyvä puts `data-product-id` on the product card. Luma
         * and Breeze put it on the PRICE BOX — a small element deep inside the card — so observing
         * the node as found would measure whether the price was on screen, and would mean hover
         * fired only for a cursor resting on the price, which is not how anyone browses. Climbing
         * to the enclosing list item gives the same node on every theme.
         */
        function cardFor(node) {
            return (node.closest && node.closest('li, article, .product-item, .item')) || node;
        }

        function add(id, node) {
            if (!id || !node || found[id]) {
                return;
            }
            found[id] = true;
            out.push({id: id, node: cardFor(node)});
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-product-id]'), function (el) {
            add(parseInt(el.getAttribute('data-product-id'), 10), el);
        });

        var listing = readConfig('fm-listing-products');
        if (listing && listing.length) {
            var byPath = {};
            listing.forEach(function (row) {
                if (row && row.u) {
                    byPath[row.u] = row.i;
                }
            });

            Array.prototype.forEach.call(document.querySelectorAll('a[href]'), function (link) {
                var id = byPath[link.pathname];
                if (!id) {
                    return;
                }
                add(id, link);
            });
        }

        return out;
    }

    /**
     * Grid impressions and hover.
     *
     * An impression is "this product was genuinely on screen", not "this product was in the HTML".
     * A card must be at least half visible for a full second before it counts — scrolling past
     * thirty products in two seconds is not thirty impressions, and counting it that way would make
     * the denominator meaningless in exactly the direction that flatters a busy page.
     *
     * All of it leaves in ONE beacon on unload, and impressions travel as a single row carrying a
     * list. A 36-product grid reporting one row per product would make impressions most of the
     * events index within a week, for the lowest-value signal here.
     *
     * Hover is desktop-only by nature. Its absence on a touch device means the signal does not
     * exist, never that the shopper was uninterested.
     *
     * ARMING IS REPEATABLE AND IDEMPOTENT, which is not a detail. The products on a page are not
     * all there at DOMContentLoaded: this module's own search grid renders after it and re-renders
     * on every filter and page change, and Breeze re-runs its component mounts on updated content.
     * Binding once at load meant the search grid — every product a shopper saw while searching —
     * was never observed at all.
     */
    function initGridTracking() {
        if (!navigator.sendBeacon) {
            return;
        }

        var seen = {};
        var hovered = {};
        var timers = {};
        var armed = {};
        var sent = false;

        var observer = window.IntersectionObserver
            ? new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var id = parseInt(entry.target.getAttribute('data-fm-observed-id'), 10);
                    if (!id) {
                        return;
                    }
                    if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
                        if (!timers[id] && !seen[id]) {
                            timers[id] = window.setTimeout(function () {
                                seen[id] = true;
                                delete timers[id];
                            }, 1000);
                        }
                    } else if (timers[id]) {
                        window.clearTimeout(timers[id]);
                        delete timers[id];
                    }
                });
            }, {threshold: [0, 0.5, 1]})
            : null;

        function arm() {
            resolveProductCards().forEach(function (card) {
                var node = card.node;
                if (node.getAttribute('data-fm-observed-id')) {
                    return;
                }
                node.setAttribute('data-fm-observed-id', String(card.id));
                armed[card.id] = true;

                if (observer) {
                    observer.observe(node);
                } else {
                    // No IntersectionObserver: the product is in the document and that is the most
                    // this browser can honestly say. Better a slightly generous denominator than
                    // none, and it under-counts nothing.
                    seen[card.id] = true;
                }

                var enteredAt = null;
                node.addEventListener('mouseenter', function () {
                    enteredAt = Date.now();
                });
                node.addEventListener('mouseleave', function () {
                    if (enteredAt === null) {
                        return;
                    }
                    var ms = Date.now() - enteredAt;
                    enteredAt = null;
                    if (ms >= 800) {
                        hovered[card.id] = (hovered[card.id] || 0) + ms;
                    }
                });
            });
        }

        function reportGrid() {
            if (sent) {
                return;
            }
            var events = [];
            var impressions = Object.keys(seen).map(Number);
            if (impressions.length) {
                events.push({type: 'impression', product_ids: impressions.slice(0, 60)});
            }
            Object.keys(hovered).slice(0, 15).forEach(function (id) {
                events.push({type: 'hover', product_id: Number(id), hover_ms: hovered[id]});
            });
            if (!events.length) {
                return;
            }
            sent = true;
            try {
                navigator.sendBeacon(
                    fmBaseUrl() + 'fastmagento/event/collect',
                    new Blob([JSON.stringify({events: events})], {type: 'application/json'})
                );
            } catch (e) {
                // Analytics never surfaces to a shopper.
            }
        }

        arm();
        onContentChanged(arm);

        window.addEventListener('pagehide', reportGrid);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') {
                reportGrid();
            }
        });
    }

    /**
     * Run a callback whenever the page's products may have changed, on any theme.
     *
     * Deliberately not a list of theme event names. Breeze announces updated content with
     * `breeze:mount`, this module's own grid re-renders on filter and paging changes, and the next
     * theme will do something else again — so the signal watched is the DOM itself, which is the one
     * thing all of them have to touch. A MutationObserver on the body, coalesced to one call per
     * frame, costs nothing on a page that is not changing and needs no theme detection at all.
     *
     * The theme events are still listened for where they exist, because they fire once and
     * precisely, and arming is idempotent so hearing the same change twice is free.
     */
    function onContentChanged(callback) {
        var pending = null;

        function schedule() {
            if (pending) {
                return;
            }
            pending = window.setTimeout(function () {
                pending = null;
                callback();
            }, 150);
        }

        ['breeze:mount', 'breeze:load', 'contentUpdated', 'fm:grid:rendered'].forEach(function (name) {
            document.addEventListener(name, schedule);
        });

        if (window.MutationObserver && document.body) {
            new MutationObserver(schedule).observe(document.body, {childList: true, subtree: true});
        }
    }

    /**
     * Report a facet selection the server never saw.
     *
     * A filtered listing URL is a request parameter, so PHP is holding it — on a cache MISS. On a
     * hit, which is what almost every request to a popular filter is, PHP does not run at all and
     * the single most direct statement of intent the storefront receives is thrown away. Measured
     * on a real store: the first request for a filtered category took 1.19s and recorded; the next
     * two took 0.04s and recorded nothing.
     *
     * Sent on LOAD rather than with the impressions beacon on unload. Impressions are the cheapest
     * signal here and can afford a delivery mechanism that occasionally misses; a facet click is
     * the most expensive one, and it has already happened by the time the page renders, so there is
     * nothing to wait for.
     *
     * The server owns the allowlist. `facetParams` is here only so an ordinary paginated view does
     * not fire a request for nothing.
     */
    function initFacetTracking() {
        var cfg = readConfig('fm-analytics-config');
        if (!cfg || !cfg.collect || !cfg.captureFacets || !navigator.sendBeacon) {
            return;
        }

        var query = window.location.search.replace(/^\?/, '');
        if (!query) {
            return;
        }

        var names = {};
        query.split('&').forEach(function (pair) {
            var name = decodeURIComponent(pair.split('=')[0] || '').replace(/\[\]$/, '');
            if (name) {
                names[name] = true;
            }
        });

        var params = cfg.facetParams || [];
        var stated = false;
        for (var i = 0; i < params.length; i++) {
            if (names[params[i]]) {
                stated = true;
                break;
            }
        }
        if (!stated) {
            return;
        }

        // A reload, or a back-navigation restoring the page from the bfcache, is not a second
        // statement of intent. Choosing the same filter again half an hour later IS one, so the
        // guard is a short window against the same URL rather than a permanent one.
        var key = window.location.pathname + '?' + query;
        try {
            var last = window.sessionStorage.getItem('fmFacet');
            if (last) {
                var parts = last.split('|');
                if (parts[0] === key && (Date.now() - Number(parts[1])) < 30000) {
                    return;
                }
            }
            window.sessionStorage.setItem('fmFacet', key + '|' + Date.now());
        } catch (e) {
            // Private mode, or storage disabled. Reporting twice is a smaller fault than not
            // reporting at all, so carry on.
        }

        try {
            navigator.sendBeacon(
                cfg.collectUrl || (fmBaseUrl() + 'fastmagento/event/collect'),
                new Blob([JSON.stringify({events: [{type: 'facet', query: query}]})], {type: 'application/json'})
            );
        } catch (e) {
            // Analytics never surfaces to a shopper.
        }
    }

    function fmBaseUrl() {
        var base = (window.BASE_URL || (document.querySelector('[data-fm-base-url]') || {}).getAttribute
            ? (document.querySelector('[data-fm-base-url]') || {getAttribute: function () { return null; }}).getAttribute('data-fm-base-url')
            : null) || '/';
        return base.charAt(base.length - 1) === '/' ? base : base + '/';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initViewTracking();
            initGridTracking();
            initFacetTracking();
        });
    } else {
        initViewTracking();
        initGridTracking();
        initFacetTracking();
    }
}());
