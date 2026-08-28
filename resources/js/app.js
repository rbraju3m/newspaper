import Alpine from './bootstrap';

import './stores/theme';
import './stores/reader';
import './stores/pwa';
import './stores/push';
import './stores/toast';
import './components/ticker';
import './components/share';
import './components/infinite-scroll';
import './components/reading-tracker';
import './components/article-editor';
import './components/sortable';
import './components/shortcuts';
import './components/live-blog';
import './components/ad-impressions';

window.Alpine = Alpine;
Alpine.start();
