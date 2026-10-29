import { onBeforeUnmount, onMounted } from 'vue';

/**
 * ค่อย ๆ โผล่ขึ้นเมื่อเลื่อนมาถึง — ใส่คลาส `reveal` ให้ element ที่ต้องการ
 *
 * ดู DOM ด้วย MutationObserver ด้วย เพราะของที่วาดหลังโหลดข้อมูล (สถิติ / การ์ดจาก CMS)
 * เกิดหลัง onMounted — หน้าเดิมสั่ง observe ครั้งเดียวตอน mount แล้วของพวกนั้นค้างที่ opacity 0 ทั้งแถบ
 *
 * ซ่อนเฉพาะตอนที่ root มีคลาส `reveal-ready` (ใส่ให้เมื่อเปิดใช้แอนิเมชันได้เท่านั้น)
 * ถ้า JS พังหรือผู้ใช้ปิดแอนิเมชัน ทุกอย่างก็ยังมองเห็นตามปกติ
 *
 * @param {import('vue').Ref<HTMLElement|null>} rootRef
 */
export function useReveal(rootRef) {
  let io = null;
  let mo = null;

  function observeAll() {
    rootRef.value?.querySelectorAll('.reveal:not(.is-visible)').forEach((el) => io.observe(el));
  }

  onMounted(() => {
    const root = rootRef.value;
    if (!root || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;

    io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        });
      },
      { threshold: 0.1, rootMargin: '0px 0px -40px 0px' },
    );

    root.classList.add('reveal-ready');
    observeAll();

    mo = new MutationObserver(observeAll);
    mo.observe(root, { childList: true, subtree: true });
  });

  onBeforeUnmount(() => {
    io?.disconnect();
    mo?.disconnect();
  });
}
