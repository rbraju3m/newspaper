import Alpine from '../bootstrap';

const SIZES = ['sm', 'md', 'lg', 'xl'];

/**
 * Reader preferences — article font size and the client-side bookmark cache.
 * Bookmarks are persisted server-side for logged-in users; this store keeps the
 * UI optimistic so the star flips instantly.
 */
Alpine.store('reader', {
    fontSize: Alpine.$persist('md').as('np_fs'),
    bookmarks: Alpine.$persist([]).as('np_bookmarks'),

    init() {
        this.applyFontSize();
    },

    applyFontSize() {
        document.documentElement.dataset.fs = this.fontSize;
    },

    bigger() {
        const i = SIZES.indexOf(this.fontSize);
        if (i < SIZES.length - 1) { this.fontSize = SIZES[i + 1]; this.applyFontSize(); }
    },

    smaller() {
        const i = SIZES.indexOf(this.fontSize);
        if (i > 0) { this.fontSize = SIZES[i - 1]; this.applyFontSize(); }
    },

    reset() {
        this.fontSize = 'md';
        this.applyFontSize();
    },

    get canGrow() { return this.fontSize !== SIZES[SIZES.length - 1]; },
    get canShrink() { return this.fontSize !== SIZES[0]; },

    has(id) { return this.bookmarks.includes(id); },

    /** Optimistically flip, then reconcile with the server. */
    async toggleBookmark(id, endpoint) {
        const had = this.has(id);
        this.bookmarks = had
            ? this.bookmarks.filter((b) => b !== id)
            : [...this.bookmarks, id];

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });

            if (res.status === 401) {
                // Not logged in — undo and send them to login.
                this.bookmarks = had ? [...this.bookmarks, id] : this.bookmarks.filter((b) => b !== id);
                window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname)}`;
                return;
            }

            const data = await res.json();
            this.bookmarks = data.bookmarked
                ? [...new Set([...this.bookmarks, id])]
                : this.bookmarks.filter((b) => b !== id);
        } catch {
            // Network failed — roll back so the UI does not lie.
            this.bookmarks = had ? [...new Set([...this.bookmarks, id])] : this.bookmarks.filter((b) => b !== id);
        }
    },
});
