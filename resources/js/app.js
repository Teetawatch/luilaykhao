import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createUnhead } from '@unhead/vue';
import router from './router';
import App from './App.vue';

const app = createApp(App);
const head = createUnhead();
app.use(head);
app.use(createPinia());
app.use(router);
app.mount('#app');
