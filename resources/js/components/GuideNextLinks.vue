<template>
  <!-- ท้ายหน้ากลุ่ม "ข้อมูลก่อนไป" — โยงไปอีกสามหน้าในชุดเดียวกัน ให้อ่านต่อกันเป็นเรื่องเดียว -->
  <section class="guide-next" aria-labelledby="guide-next-title">
    <p class="inline-flex items-center gap-2 text-[13px] font-bold text-[var(--color-accent)]">
      <span class="w-6 h-[2px] rounded-full bg-[var(--color-accent)]"></span>
      ข้อมูลก่อนไป
    </p>
    <h2 id="guide-next-title" class="mt-2 text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight">
      อ่านต่อก่อนออกเดินทาง
    </h2>

    <div v-reveal.stagger class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
      <router-link v-for="item in others" :key="item.to" :to="item.to" class="guide-next__card group">
        <span class="guide-next__thumb zoom-media">
          <img :src="item.image" alt="" loading="lazy" />
          <span class="guide-next__icon material-symbols-rounded">{{ item.icon }}</span>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block font-extrabold text-[var(--color-text-dark)] leading-snug">{{ item.title }}</span>
          <span class="block mt-1 text-[13px] text-[var(--color-text-muted)] leading-snug">{{ item.desc }}</span>
        </span>
        <span class="guide-next__arrow material-symbols-rounded">arrow_forward</span>
      </router-link>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  /** path ของหน้าปัจจุบัน — ไม่ลิงก์หาตัวเอง */
  current: { type: String, required: true },
});

const ITEMS = [
  { to: '/places', icon: 'landscape', image: '/images/landscape.webp', title: 'สถานที่ธรรมชาติ', desc: 'ความสูง ระยะเดิน ช่วงที่ควรไปของแต่ละที่' },
  { to: '/seasons', icon: 'calendar_month', image: '/images/phusoidao.webp', title: 'เดือนไหนไปไหนดี', desc: 'ปฏิทินธรรมชาติของไทยทั้งปี' },
  { to: '/difficulty', icon: 'terrain', image: '/images/khaochangphueak.webp', title: 'ระดับความยาก', desc: 'สายชิล ปานกลาง สายโหด ต่างกันยังไง' },
  { to: '/checklist', icon: 'checklist', image: '/images/hiking.webp', title: 'เช็คลิสต์ของที่ต้องเตรียม', desc: 'ติ๊กจัดกระเป๋าได้ทันที พิมพ์ได้' },
];

const others = computed(() => ITEMS.filter((item) => item.to !== props.current));
</script>

<style scoped>
.guide-next__card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1.25rem 0.75rem 0.75rem;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  transition: border-color 0.25s ease, transform 0.25s var(--ease-out);
}
.guide-next__card:hover {
  border-color: color-mix(in srgb, var(--color-accent) 35%, transparent);
}
.guide-next__card:active { transform: scale(0.98); }
.guide-next__thumb {
  position: relative;
  width: 4.5rem;
  height: 4.5rem;
  flex-shrink: 0;
  border-radius: 1rem;
  background-color: var(--color-primary);
}
.guide-next__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0.6;
}
.guide-next__thumb .guide-next__icon {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 28px;
}
.guide-next__card .guide-next__arrow {
  font-size: 20px;
  color: var(--color-accent);
  transition: transform 0.3s var(--ease-out);
}
.guide-next__card:hover .guide-next__arrow { transform: translateX(4px); }
</style>
