/**
 * Counts an ad slot as seen, on the rule advertisers actually use.
 *
 * The server cannot do this. A creative is `loading="lazy"`, so a slot below
 * the fold is often never fetched — measuring the front page for CLS found
 * only one of its three slots had loaded by the end of the run — while every
 * crawler that fetches the HTML would have counted. Rendering a slot and
 * seeing a slot are different numbers, and the second is the one being sold.
 *
 * The IAB rule: at least half the creative in view for one continuous second.
 * `threshold: 0.5` gives the half; a timer that is cancelled when the slot
 * leaves gives the second. Scrolling straight past does not count.
 *
 * Everything qualifying on one page goes up in a single beacon, once, so this
 * costs one request per page rather than one per slot — and one query at the
 * other end.
 */

const VISIBLE_FRACTION = 0.5;
const DWELL_MS = 1000;

function start() {
    const endpoint = document.querySelector('meta[name="ads-impression-endpoint"]')?.content;

    if (!endpoint || !('IntersectionObserver' in window)) return;

    const slots = document.querySelectorAll('[data-ad-id]');

    if (!slots.length) return;

    // Counted once per page load, however often a slot re-enters the viewport.
    // A reader scrolling up and down past the same banner has seen it once.
    const seen = new Set();
    const pending = new Set();
    const timers = new Map();
    let sent = false;

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            const id = Number(entry.target.dataset.adId);

            if (!id || seen.has(id)) continue;

            if (entry.isIntersecting && entry.intersectionRatio >= VISIBLE_FRACTION) {
                if (timers.has(id)) continue;

                timers.set(id, setTimeout(() => {
                    timers.delete(id);
                    seen.add(id);
                    pending.add(id);
                    observer.unobserve(entry.target);
                }, DWELL_MS));

                continue;
            }

            // Left the viewport before the second was up: the dwell has to
            // restart, or a fast scroll through a long page counts everything.
            clearTimeout(timers.get(id));
            timers.delete(id);
        }
    }, { threshold: [VISIBLE_FRACTION] });

    slots.forEach((slot) => observer.observe(slot));

    function flush() {
        if (sent || !pending.size) return;

        sent = true;

        // sendBeacon cannot set headers, so the CSRF token rides in the body —
        // the same shape `reading-tracker.js` uses, and Laravel reads `_token`
        // out of the parsed JSON input.
        const body = JSON.stringify({
            ids: [...pending],
            _token: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        });

        pending.clear();

        navigator.sendBeacon?.(endpoint, new Blob([body], { type: 'application/json' }));
    }

    // `pagehide` rather than `unload`: it is the one that fires on iOS and
    // when a page enters the back/forward cache. `visibilitychange` catches a
    // reader who switches tabs and never comes back.
    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) flush();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
