import Alpine from '../bootstrap';

/**
 * Keyboard shortcuts for readers who live on the keyboard.
 * Every binding is inert while focus is in a field, so typing a Bangla
 * headline into the editor never triggers navigation.
 */
Alpine.data('shortcuts', (routes) => ({
    helpOpen: false,

    init() {
        window.addEventListener('keydown', (e) => this.handle(e));
    },

    inField(target) {
        return target.closest('input, textarea, select, [contenteditable="true"]') !== null;
    },

    handle(e) {
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        if (this.inField(e.target)) return;

        switch (e.key) {
            case '/':
                e.preventDefault();
                document.querySelector('#site-search')?.focus();
                this.$dispatch('open-search');
                break;
            case '?':
                e.preventDefault();
                this.helpOpen = !this.helpOpen;
                break;
            case 'Escape':
                this.helpOpen = false;
                break;
            case 'h':
                window.location.href = routes.home;
                break;
            case 'l':
                window.location.href = routes.latest;
                break;
            case 'b':
                window.location.href = routes.bookmarks;
                break;
            case 't':
                window.scrollTo({ top: 0, behavior: 'smooth' });
                break;
            case 'd':
                Alpine.store('theme').toggle();
                break;
            default:
                break;
        }
    },
}));

/** Back-to-top button that only appears once scrolling is worth undoing. */
Alpine.data('backToTop', () => ({
    visible: false,

    init() {
        const update = () => (this.visible = window.scrollY > window.innerHeight * 1.5);
        window.addEventListener('scroll', update, { passive: true });
        update();
    },

    go() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    },
}));
