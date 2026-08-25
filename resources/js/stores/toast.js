import Alpine from '../bootstrap';

/**
 * Transient notifications. Replaces the pattern of only ever showing feedback
 * as a server-rendered flash, which cannot report anything that happens after
 * the page loads (bookmark saved, poll vote, copy link, offline).
 */
let nextId = 1;

Alpine.store('toast', {
    items: [],

    push(message, type = 'info', timeout = 4000) {
        const id = nextId++;
        this.items.push({ id, message, type });

        if (timeout) {
            setTimeout(() => this.dismiss(id), timeout);
        }

        return id;
    },

    success(message, timeout) { return this.push(message, 'success', timeout); },
    error(message, timeout) { return this.push(message, 'error', timeout ?? 6000); },
    info(message, timeout) { return this.push(message, 'info', timeout); },

    dismiss(id) {
        this.items = this.items.filter((t) => t.id !== id);
    },
});
