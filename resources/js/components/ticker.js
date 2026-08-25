import Alpine from '../bootstrap';

/**
 * Breaking-news ticker. Cycles headlines, pauses on hover/focus, and refreshes
 * from the server on an interval so a breaking story appears without a reload.
 */
Alpine.data('ticker', (initial = [], endpoint = null, refreshMs = 60000) => ({
    items: initial,
    index: 0,
    paused: false,
    timer: null,
    poller: null,

    init() {
        if (this.items.length > 1) this.start();
        if (endpoint) {
            this.poller = setInterval(() => this.refresh(), refreshMs);
        }
        // Stop timers when the tab is hidden — no point animating offscreen.
        document.addEventListener('visibilitychange', () => {
            document.hidden ? this.stop() : this.start();
        });
        this.$watch('paused', (p) => (p ? this.stop() : this.start()));
    },

    destroy() {
        this.stop();
        clearInterval(this.poller);
    },

    start() {
        this.stop();
        if (this.paused || this.items.length < 2) return;
        this.timer = setInterval(() => this.next(), 5000);
    },

    stop() {
        clearInterval(this.timer);
        this.timer = null;
    },

    next() { this.index = (this.index + 1) % this.items.length; },
    prev() { this.index = (this.index - 1 + this.items.length) % this.items.length; },

    async refresh() {
        try {
            const res = await fetch(endpoint, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;
            const data = await res.json();
            if (Array.isArray(data.items) && data.items.length) {
                this.items = data.items;
                if (this.index >= this.items.length) this.index = 0;
            }
        } catch {
            // Silent — a failed ticker refresh must never break the page.
        }
    },

    get current() { return this.items[this.index] ?? null; },
}));
