import Alpine from '../bootstrap';

/**
 * Records how far a reader got through an article.
 *
 * Progress is reported on a debounce and again on page hide, using sendBeacon
 * so the final position survives the reader navigating away — a plain fetch is
 * routinely cancelled during unload.
 */
Alpine.data('readingTracker', (endpoint, enabled = false) => ({
    progress: 0,
    maxProgress: 0,
    startedAt: Date.now(),
    lastSent: 0,
    timer: null,

    init() {
        if (!enabled || !endpoint) return;

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) this.flush();
        });
        window.addEventListener('pagehide', () => this.flush());
    },

    /** Called from the scroll handler that already drives the progress bar. */
    report(percent) {
        this.progress = percent;
        this.maxProgress = Math.max(this.maxProgress, Math.round(percent));

        // Only worth a round trip every 25% of new ground.
        if (this.maxProgress - this.lastSent < 25) return;

        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.flush(), 1500);
    },

    flush() {
        if (!enabled || !endpoint) return;
        if (this.maxProgress <= this.lastSent) return;

        // sendBeacon cannot set headers, so the CSRF token rides in the JSON
        // body — Laravel's VerifyCsrfToken reads `_token` from the input.
        const body = JSON.stringify({
            progress: this.maxProgress,
            seconds: Math.min(36000, Math.round((Date.now() - this.startedAt) / 1000)),
            _token: this.csrf,
        });

        this.lastSent = this.maxProgress;
        this.startedAt = Date.now();

        navigator.sendBeacon?.(endpoint, new Blob([body], { type: 'application/json' }));
    },

    get csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    },
}));
