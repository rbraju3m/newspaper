import Alpine from '../bootstrap';

/**
 * Breaking-news notifications.
 *
 * Three states worth distinguishing, because collapsing them produces a
 * control that lies:
 *
 *   unsupported — no Push API, no service worker, or no VAPID key in the page.
 *                 The toggle is not rendered at all.
 *   blocked     — the reader said no once. The browser will never prompt again
 *                 from script, so a toggle that "does nothing" is the wrong UI;
 *                 they have to be told to change it in site settings.
 *   on / off    — the only two the toggle actually switches between.
 *
 * The browser's subscription is the source of truth, not the server's row and
 * not localStorage. A reader who cleared site data, or revoked permission in
 * browser settings, is unsubscribed no matter what our table still holds — so
 * `sync()` reads `pushManager.getSubscription()` on every page load and the
 * toggle reflects that.
 */
Alpine.store('push', {
    supported: false,
    permission: 'default',
    subscribed: false,
    busy: false,

    get blocked() {
        return this.permission === 'denied';
    },

    /** Only worth showing a control when it can actually do something. */
    get available() {
        return this.supported && !this.blocked;
    },

    init() {
        this.supported = 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window
            && !!this.key();

        if (!this.supported) return;

        this.permission = Notification.permission;
        this.sync();
    },

    key() {
        return document.querySelector('meta[name="push-key"]')?.content ?? null;
    },

    async sync() {
        try {
            const registration = await navigator.serviceWorker.ready;
            this.subscribed = !!(await registration.pushManager.getSubscription());
        } catch {
            this.subscribed = false;
        }
    },

    async toggle() {
        if (this.busy || !this.available) return;

        this.busy = true;

        try {
            await (this.subscribed ? this.unsubscribe() : this.subscribe());
        } finally {
            this.busy = false;
        }
    },

    async subscribe() {
        // Asking is a one-shot: a reader who says no can only undo it in
        // browser settings, so `permission` is re-read either way.
        const permission = await Notification.requestPermission();
        this.permission = permission;

        if (permission !== 'granted') {
            if (permission === 'denied') {
                this.toast('ব্রাউজারের সেটিংস থেকে নোটিফিকেশন অনুমতি দিতে হবে।', 'error');
            }
            return;
        }

        const registration = await navigator.serviceWorker.ready;

        // An existing subscription is reused rather than replaced. Calling
        // subscribe() again with a different key throws, and re-subscribing
        // needlessly would issue a new endpoint and orphan the stored row.
        const subscription = await registration.pushManager.getSubscription()
            ?? await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.decodeKey(this.key()),
            });

        const response = await this.post('POST', subscription.toJSON());

        if (!response.ok) {
            // The server cannot honour it, so neither should the browser —
            // leaving a live subscription the server has no row for means a
            // reader who thinks alerts are on and never gets one.
            await subscription.unsubscribe().catch(() => {});
            this.toast('অ্যালার্ট চালু করা যায়নি।', 'error');
            return;
        }

        this.subscribed = true;
        this.toast('ব্রেকিং নিউজ অ্যালার্ট চালু হয়েছে।');
    },

    async unsubscribe() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            this.subscribed = false;
            return;
        }

        const { endpoint } = subscription;

        // Told the browser first: the endpoint is what identifies the row, and
        // dropping our row while the browser still holds a live subscription
        // would leave a reader receiving nothing with no way to turn it off.
        await subscription.unsubscribe();
        await this.post('DELETE', { endpoint });

        this.subscribed = false;
        this.toast('অ্যালার্ট বন্ধ হয়েছে।');
    },

    post(method, body) {
        return fetch('/push/subscribe', {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(body),
        }).catch(() => ({ ok: false }));
    },

    /**
     * `applicationServerKey` wants raw bytes; the key ships as base64url.
     * atob() only reads standard base64, hence the two character swaps and
     * the padding.
     */
    decodeKey(base64) {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const raw = atob(padded);
        const bytes = new Uint8Array(raw.length);

        for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);

        return bytes;
    },

    toast(message, type = 'success') {
        Alpine.store('toast')?.push?.(message, type);
    },
});
