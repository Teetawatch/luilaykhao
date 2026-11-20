import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createHead } from '@unhead/vue/client';
import router from './router';
import App from './App.vue';
import { initAnalytics } from './lib/analytics';
import { installMotion } from './lib/motion';
import { applySavedLocale, installI18n, loadMessages } from './i18n';

// Replays a consent decision made on an earlier visit. Consent Mode boots in
// its denied state on every page load, so without this a returning visitor who
// already accepted would silently stop being counted.
initAnalytics();

// คนที่เลือกภาษาอังกฤษไว้แล้วเปิดลิงก์ภาษาไทย ถูกพาไป /en/… ก่อน — ไม่ต้อง mount
// หน้าไทยให้เห็นวาบหนึ่ง ส่วนคำแปลโหลดก่อน mount เพื่อไม่ให้หน้าขึ้นไทยแล้วค่อยเปลี่ยน
if (!applySavedLocale()) {
    loadMessages().then(() => {
        const app = createApp(App);
        const head = createHead();
        installI18n(app);
        app.use(head);
        app.use(createPinia());
        app.use(router);
        installMotion(app, router);
        app.mount('#app');
    });
}
