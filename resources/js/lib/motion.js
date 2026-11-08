import { nextTick } from 'vue';

/**
 * ระบบการเคลื่อนไหวกลางของเว็บ — ทุกหน้าใช้ชุดนี้ชุดเดียว ไม่ต้องเขียน IntersectionObserver เองทีละหน้า
 *
 * - `v-reveal`            ค่อย ๆ โผล่ขึ้นเมื่อเลื่อนมาถึง (`v-reveal:fade` / `:scale` / `:left` / `:right`)
 * - `v-reveal="120"`      หน่วงเวลาเอง (ms) — ถ้าไม่ใส่ ของที่เข้าจอพร้อมกันจะไล่กันโผล่ทีละชิ้นเอง
 * - `v-reveal.stagger`    ใส่ที่กล่องแม่ ลูกทุกตัว (รวมที่วาดเพิ่มทีหลัง เช่น "ดูเพิ่มเติม") โผล่ไล่กัน
 * - `v-parallax="0.25"`   เลื่อนช้ากว่าหน้า (ใช้คุณสมบัติ `translate` จึงไม่ชนกับ animation ที่ใช้ `transform`)
 *                         `v-parallax.center` สำหรับภาพกลางหน้า — ภาพต้องสูงกว่ากรอบพอให้ขยับได้
 * - `installViewTransitions(router)`  เปลี่ยนหน้าแบบจางเข้า แทนการกระพริบ
 *
 * ทุกอย่างปิดตัวเองเมื่อผู้ใช้ตั้ง "ลดการเคลื่อนไหว" หรือเบราว์เซอร์ไม่รองรับ และซ่อนของได้
 * เฉพาะตอนที่ `<html>` มีคลาส `motion-ok` — ถ้า JS ส่วนนี้พัง เนื้อหายังมองเห็นครบ
 */

const BATCH_STEP_MS = 70;
const BATCH_MAX_MS = 420;
const REVEAL_MS = 800; // ต้องตรงกับ --reveal-duration ใน resources/css/motion.css

const prefersReducedMotion = () =>
  typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

export const motionEnabled = () =>
  typeof window !== 'undefined' && !prefersReducedMotion() && 'IntersectionObserver' in window;

let observer = null;

function revealNow(el) {
  el.classList.add('is-revealed');
  observer?.unobserve(el);
  // เลิกคุม transition หลังโผล่เสร็จ — ไม่งั้น hover (ยกการ์ด/ซูมรูป) ของ element เดียวกันจะช้าตาม reveal
  // ใช้เวลานับเอง ไม่ใช้ transitionend เพราะ transition ของลูกข้างใน bubble ขึ้นมาหลอกได้
  const delay = parseInt(el.style.getPropertyValue('--reveal-delay'), 10) || 0;
  setTimeout(() => {
    el.removeAttribute('data-reveal');
    el.classList.remove('is-revealed');
    el.style.removeProperty('--reveal-delay');
  }, delay + REVEAL_MS + 50);
}

function getObserver() {
  if (observer) return observer;
  observer = new IntersectionObserver(
    (entries) => {
      // ของที่เข้าจอในรอบเดียวกัน (แถวการ์ด, รายการรีวิว) ไล่กันโผล่ตามลำดับใน DOM
      let i = 0;
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const el = entry.target;
        if (!el.dataset.revealFixedDelay) {
          el.style.setProperty('--reveal-delay', `${Math.min(i * BATCH_STEP_MS, BATCH_MAX_MS)}ms`);
          i += 1;
        }
        revealNow(el);
      });
    },
    { threshold: 0.08, rootMargin: '0px 0px -6% 0px' },
  );
  return observer;
}

function prepare(el, variant, delay) {
  if (el.hasAttribute('data-reveal') || el.dataset.revealSeen) return;
  el.dataset.revealSeen = '1';
  el.setAttribute('data-reveal', variant || 'up');
  if (typeof delay === 'number') {
    el.dataset.revealFixedDelay = '1';
    el.style.setProperty('--reveal-delay', `${delay}ms`);
  }
  getObserver().observe(el);
}

function prepareChildren(el, variant) {
  Array.from(el.children).forEach((child) => prepare(child, variant));
}

export const vReveal = {
  mounted(el, binding) {
    if (!motionEnabled()) return;
    const variant = binding.arg;
    if (binding.modifiers.stagger) {
      prepareChildren(el, variant);
      // ลูกที่วาดเพิ่มทีหลัง (โหลดเพิ่ม / ข้อมูลมาช้า) ก็ต้องโผล่แบบเดียวกัน ไม่ใช่ค้างที่ opacity 0
      el._revealMo = new MutationObserver(() => prepareChildren(el, variant));
      el._revealMo.observe(el, { childList: true });
      return;
    }
    prepare(el, variant, typeof binding.value === 'number' ? binding.value : undefined);
  },
  beforeUnmount(el) {
    el._revealMo?.disconnect();
    observer?.unobserve(el);
    if (el.children) Array.from(el.children).forEach((c) => observer?.unobserve(c));
  },
};

/* ── Parallax ─────────────────────────────────────────────── */

const parallaxItems = new Set();
let parallaxTicking = false;

function updateParallax() {
  parallaxTicking = false;
  const vh = window.innerHeight;
  parallaxItems.forEach((item) => {
    // วัดจากกล่องแม่ที่ไม่ได้ขยับ — วัดตัวเองจะได้ค่าที่รวม translate รอบก่อนไปแล้ว ภาพจะสั่น
    const rect = (item.el.parentElement || item.el).getBoundingClientRect();
    if (rect.bottom < -200 || rect.top > vh + 200) return;
    // ปกติ (ภาพหัวหน้า): 0 ตอนอยู่บนสุดของหน้า แล้วเลื่อนตามระยะที่เลื่อนลงมา — ภาพต้องยื่นขึ้นไป
    //   เหนือกรอบพอ (ดู .hero-img) ไม่งั้นจะเห็นช่องว่างโผล่ใต้แถบเมนูตอนเริ่มเลื่อน
    // .center (ภาพกลางหน้า): 0 เมื่อกล่องแม่อยู่กลางจอ — ส่วนเกินของภาพแบ่งบน/ล่างเท่ากัน
    const offset = item.center
      ? (vh / 2 - (rect.top + rect.height / 2)) * item.speed
      : Math.max(0, window.scrollY) * item.speed;
    item.el.style.translate = `0 ${offset.toFixed(1)}px`;
  });
}

function onParallaxScroll() {
  if (parallaxTicking) return;
  parallaxTicking = true;
  requestAnimationFrame(updateParallax);
}

export const vParallax = {
  mounted(el, binding) {
    if (!motionEnabled()) return;
    const item = { el, speed: Number(binding.value) || 0.2, center: !!binding.modifiers.center };
    el._parallax = item;
    if (parallaxItems.size === 0) window.addEventListener('scroll', onParallaxScroll, { passive: true });
    parallaxItems.add(item);
    updateParallax();
  },
  beforeUnmount(el) {
    if (!el._parallax) return;
    parallaxItems.delete(el._parallax);
    el.style.translate = '';
    if (parallaxItems.size === 0) window.removeEventListener('scroll', onParallaxScroll);
  },
};

/* ── Page transitions ─────────────────────────────────────── */

/**
 * จางหน้าเก่าออก หน้าใหม่ลอยขึ้นมาแทน ด้วย View Transitions API ของเบราว์เซอร์
 * (ไม่รองรับ = เปลี่ยนหน้าแบบเดิม) — แถบเมนูมี view-transition-name ของตัวเองจึงนิ่งอยู่กับที่
 *
 * ข้าม: โหลดหน้าแรก, เปลี่ยนแค่ query ในหน้าเดิม, หลังบ้าน /admin (งานประจำ ต้องเร็วกว่าสวย)
 */
export function installViewTransitions(router) {
  if (typeof document === 'undefined' || !document.startViewTransition) return;

  let finish = null;

  router.beforeResolve((to, from) => {
    if (!from.matched.length || to.path === from.path) return;
    if (to.path.startsWith('/admin') || from.path.startsWith('/admin')) return;
    if (prefersReducedMotion() || document.visibilityState !== 'visible') return;

    return new Promise((resolve) => {
      try {
        document.startViewTransition(
          () =>
            new Promise((done) => {
              // กันค้าง: ถ้าการนำทางถูกยกเลิกกลางทาง afterEach ก็ยังถูกเรียก แต่เผื่อไว้อีกชั้น
              const timer = setTimeout(done, 1200);
              finish = () => {
                clearTimeout(timer);
                done();
              };
              resolve();
            }),
        );
      } catch {
        resolve();
      }
    });
  });

  router.afterEach(async () => {
    if (!finish) return;
    const f = finish;
    finish = null;
    await nextTick();
    requestAnimationFrame(() => f());
  });
}

export function installMotion(app, router) {
  app.directive('reveal', vReveal);
  app.directive('parallax', vParallax);
  if (motionEnabled()) document.documentElement.classList.add('motion-ok');
  if (router) installViewTransitions(router);
}
