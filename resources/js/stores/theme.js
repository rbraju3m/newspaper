import Alpine from '../bootstrap';

/**
 * Theme store — light / dark / system.
 * The initial class is applied by an inline script in <head> (see layouts/site)
 * so there is no flash of the wrong theme before Alpine boots.
 */
Alpine.store('theme', {
    mode: Alpine.$persist('system').as('np_theme'),

    init() {
        this.apply();
        window.matchMedia('(prefers-color-scheme: dark)')
            .addEventListener('change', () => this.mode === 'system' && this.apply());
    },

    get isDark() {
        return this.mode === 'dark'
            || (this.mode === 'system'
                && window.matchMedia('(prefers-color-scheme: dark)').matches);
    },

    set(mode) {
        this.mode = mode;
        this.apply();
    },

    toggle() {
        this.set(this.isDark ? 'light' : 'dark');
    },

    apply() {
        document.documentElement.classList.toggle('dark', this.isDark);
        document.documentElement.style.colorScheme = this.isDark ? 'dark' : 'light';
    },
});
