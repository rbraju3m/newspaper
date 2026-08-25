import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import intersect from '@alpinejs/intersect';
import persist from '@alpinejs/persist';

/**
 * Plugins must be registered before any store module runs, because the stores
 * call Alpine.$persist() at definition time. ES imports are hoisted, so this
 * lives in its own module that every store imports first.
 */
Alpine.plugin(collapse);
Alpine.plugin(intersect);
Alpine.plugin(persist);

export default Alpine;
