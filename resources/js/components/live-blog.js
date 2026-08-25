import Alpine from '../bootstrap';

/**
 * Live-blog timeline that appends new entries without a reload.
 *
 * Polls rather than using SSE/WebSockets: a news site's live blog updates
 * every few minutes at best, and polling survives shared hosting, proxies and
 * mobile networks that quietly kill long-lived connections.
 */
Alpine.data('liveBlog', (endpoint, initialLatest = 0, intervalMs = 20000) => ({
    entries: [],
    latest: initialLatest,
    unseen: 0,
    paused: false,
    timer: null,
    failures: 0,

    init() {
        this.start();

        // Stop polling while the tab is hidden; resume with an immediate check
        // so returning readers see the current state at once.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stop();
            } else {
                this.poll();
                this.start();
            }
        });
    },

    destroy() { this.stop(); },

    start() {
        this.stop();
        this.timer = setInterval(() => this.poll(), this.currentInterval);
    },

    stop() {
        clearInterval(this.timer);
        this.timer = null;
    },

    /** Back off on repeated failure instead of hammering a struggling server. */
    get currentInterval() {
        return Math.min(intervalMs * 2 ** Math.min(this.failures, 4), 5 * 60 * 1000);
    },

    async poll() {
        if (document.hidden) return;

        try {
            const res = await fetch(`${endpoint}?since=${this.latest}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(res.status);

            const data = await res.json();

            if (Array.isArray(data.entries) && data.entries.length) {
                // Prepend newest-first, skipping anything already rendered.
                const known = new Set(this.entries.map((e) => e.id));
                const fresh = data.entries.filter((e) => !known.has(e.id));
                this.entries = [...fresh, ...this.entries];
                this.unseen += fresh.length;
            }

            this.latest = data.latest ?? this.latest;

            if (this.failures) {
                this.failures = 0;
                this.start();          // reset the backoff interval
            }
        } catch {
            this.failures++;
            this.start();
        }
    },

    /** Called when the reader clicks the "N new updates" pill. */
    reveal() {
        this.unseen = 0;
        this.$refs.timeline?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
}));
