import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createHead } from '@unhead/vue/client';
import router from './router';
import App from './App.vue';
import { initAnalytics } from './lib/analytics';

// Replays a consent decision made on an earlier visit. Consent Mode boots in
// its denied state on every page load, so without this a returning visitor who
// already accepted would silently stop being counted.
initAnalytics();

const app = createApp(App);
const head = createHead();
app.use(head);
app.use(createPinia());
app.use(router);
app.mount('#app');
