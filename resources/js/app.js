import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createHead } from '@unhead/vue/client';
import router from './router';
import App from './App.vue';

const app = createApp(App);
const head = createHead();
app.use(head);
app.use(createPinia());
app.use(router);
app.mount('#app');
