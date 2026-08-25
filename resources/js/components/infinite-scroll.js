import Alpine from '../bootstrap';

/**
 * Infinite scroll for listing pages. Appends server-rendered HTML fragments so
 * the markup stays identical to the first page and stays crawlable — the
 * "load more" button remains a real link for users without JS and for bots.
 */
Alpine.data('infiniteScroll', (firstNextUrl = null) => ({
    nextUrl: firstNextUrl,
    loading: false,
    failed: false,

    get done() { return !this.nextUrl; },

    async load() {
        if (this.loading || !this.nextUrl) return;
        this.loading = true;
        this.failed = false;

        try {
            const res = await fetch(this.nextUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(res.status);

            const data = await res.json();
            this.$refs.list.insertAdjacentHTML('beforeend', data.html);
            this.nextUrl = data.next ?? null;

            // Let the rest of the page know new cards exist (lazy images, etc.)
            this.$dispatch('items-appended');
        } catch {
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },
}));
