import Alpine from './bootstrap';

import './stores/theme';
import './stores/reader';
import './components/ticker';
import './components/share';
import './components/infinite-scroll';
import './components/reading-tracker';
import './components/article-editor';
import './components/sortable';

window.Alpine = Alpine;
Alpine.start();
