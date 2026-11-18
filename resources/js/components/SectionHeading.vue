<template>
  <!-- หัวข้อ section ในหน้าเนื้อหา — หน้าตาชุดเดียวกับ SectionTitle บนหน้าแรก
       (ชื่อหนา + ขีดเขียวสั้นใต้หัวข้อ) แต่ยังมี eyebrow บอกหมวดเล็ก ๆ ด้านบนได้ -->
  <div :class="center ? 'text-center mx-auto' : ''" class="max-w-3xl">
    <p
      v-if="eyebrow"
      class="text-[14px] font-bold"
      :class="tone === 'gold' ? 'text-[var(--color-gold-dark)]' : 'text-[var(--color-accent)]'"
    >{{ eyebrow }}</p>
    <h2 class="mt-2 text-[1.75rem] sm:text-3xl md:text-[2.4rem] font-bold text-[var(--color-text-dark)] leading-tight tracking-tight">
      <slot>{{ title }}</slot>
    </h2>
    <p v-if="lead" class="mt-3 text-base md:text-[1.1rem] text-[var(--color-text-muted)] leading-relaxed">{{ lead }}</p>
    <span
      aria-hidden="true"
      class="mt-4 block h-[3px] w-12 rounded-full"
      :class="[center ? 'mx-auto' : '', tone === 'gold' ? 'bg-[var(--color-gold)]' : 'bg-[var(--color-accent)]']"
    ></span>
  </div>
</template>

<script setup>
defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, default: '' },
  lead: { type: String, default: '' },
  center: { type: Boolean, default: false },
  tone: { type: String, default: 'accent' },
});
</script>
