import Alpine from '../bootstrap';

/**
 * Share bar. Uses the native share sheet on mobile where available, falls back
 * to per-network intent URLs, and reports share events back for the counter.
 */
Alpine.data('shareBar', (url, title, reportEndpoint = null) => ({
    copied: false,
    absoluteUrl: new URL(url, window.location.origin).toString(),

    get canNative() { return typeof navigator.share === 'function'; },

    async native() {
        try {
            await navigator.share({ title, url: this.absoluteUrl });
            this.report('native');
        } catch {
            // User dismissed the sheet — not an error.
        }
    },

    open(network) {
        const u = encodeURIComponent(this.absoluteUrl);
        const t = encodeURIComponent(title);
        const targets = {
            facebook: `https://www.facebook.com/sharer/sharer.php?u=${u}`,
            twitter: `https://twitter.com/intent/tweet?url=${u}&text=${t}`,
            whatsapp: `https://api.whatsapp.com/send?text=${t}%20${u}`,
            telegram: `https://t.me/share/url?url=${u}&text=${t}`,
            linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${u}`,
            messenger: `https://www.facebook.com/dialog/send?link=${u}&app_id=0&redirect_uri=${u}`,
            email: `mailto:?subject=${t}&body=${u}`,
        };
        if (!targets[network]) return;
        window.open(targets[network], '_blank', 'noopener,noreferrer,width=640,height=560');
        this.report(network);
    },

    async copy() {
        try {
            await navigator.clipboard.writeText(this.absoluteUrl);
        } catch {
            // Clipboard API blocked (insecure context) — fall back to a temp input.
            const el = document.createElement('input');
            el.value = this.absoluteUrl;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            el.remove();
        }
        this.copied = true;
        setTimeout(() => (this.copied = false), 2000);
        this.report('copy');
    },

    print() { window.print(); },

    report(network) {
        if (!reportEndpoint) return;
        navigator.sendBeacon?.(reportEndpoint, new Blob([JSON.stringify({ network })], { type: 'application/json' }));
    },
}));
