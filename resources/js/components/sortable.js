import Alpine from '../bootstrap';

/**
 * Drag-to-reorder using the native HTML5 drag API — no library, and it degrades
 * to the visible up/down buttons where drag is unavailable (touch, keyboard).
 */
Alpine.data('sortable', (initial = []) => ({
    items: initial,
    draggingIndex: null,

    start(i) { this.draggingIndex = i; },

    over(i) {
        if (this.draggingIndex === null || this.draggingIndex === i) return;
        const moved = this.items.splice(this.draggingIndex, 1)[0];
        this.items.splice(i, 0, moved);
        this.draggingIndex = i;
    },

    end() { this.draggingIndex = null; },

    move(i, direction) {
        const target = i + direction;
        if (target < 0 || target >= this.items.length) return;
        const moved = this.items.splice(i, 1)[0];
        this.items.splice(target, 0, moved);
    },
}));
