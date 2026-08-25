import Alpine from '../bootstrap';

/**
 * Service-worker lifecycle, install prompt and connectivity state.
 *
 * The install prompt is deliberately not shown on first visit — a reader who
 * has not read anything yet has no reason to install. It appears once they
 * have opened a few pages, and a dismissal is remembered.
 */
Alpine.store('pwa', {
    installPrompt: null,
    canInstall: false,
    online: navigator.onLine,
    updateReady: false,
    visits: Alpine.$persist(0).as('np_visits'),
    installDismissed: Alpine.$persist(false).as('np_install_dismissed'),

    init() {
        this.visits = this.visits + 1;

        window.addEventListener('online', () => (this.online = true));
        window.addEventListener('offline', () => (this.online = false));

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.installPrompt = e;
            // Three pages in is roughly "this reader is actually using the site".
            this.canInstall = !this.installDismissed && this.visits >= 3;
        });

        window.addEventListener('appinstalled', () => {
            this.canInstall = false;
            this.installPrompt = null;
        });

        this.register();
    },

    async register() {
        if (!('serviceWorker' in navigator)) return;

        try {
            const scope = document.querySelector('meta[name="sw-scope"]')?.content ?? '/';
            const url = document.querySelector('meta[name="sw-url"]')?.content;
            if (!url) return;

            const registration = await navigator.serviceWorker.register(url, { scope });

            // A new worker is waiting: tell the reader rather than silently
            // serving them yesterday's shell until every tab closes.
            registration.addEventListener('updatefound', () => {
                const worker = registration.installing;
                if (!worker) return;

                worker.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        this.updateReady = true;
                    }
                });
            });
        } catch {
            // A failed registration must never break the page.
        }
    },

    async install() {
        if (!this.installPrompt) return;
        this.installPrompt.prompt();
        await this.installPrompt.userChoice;
        this.installPrompt = null;
        this.canInstall = false;
    },

    dismissInstall() {
        this.canInstall = false;
        this.installDismissed = true;
    },

    applyUpdate() {
        navigator.serviceWorker?.getRegistration().then((r) => {
            r?.waiting?.postMessage('skip-waiting');
            window.location.reload();
        });
    },
});
