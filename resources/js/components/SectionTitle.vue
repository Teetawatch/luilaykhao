<template>
  <!-- หัวข้อ section แบบทางการ: ชื่อกลางหน้า + คำอธิบายบรรทัดเดียว + ขีดสีแบรนด์สั้น ๆ
       ใช้ทุก section บนหน้าแรกและหน้าอื่น ๆ ให้หน้าตาเป็นชุดเดียวกันทั้งเว็บ
       `align="left"` สำหรับ section ที่มีปุ่ม "ดูทั้งหมด" อยู่ฝั่งขวา (ส่งผ่าน slot action) -->
  <div
    class="section-title flex flex-col gap-4"
    :class="[
      align === 'left' ? 'md:flex-row md:items-end md:justify-between' : 'items-center text-center',
      dark ? 'section-title--dark' : '',
    ]"
  >
    <div :class="align === 'left' ? 'max-w-2xl' : 'max-w-3xl mx-auto'">
      <component
        :is="tag"
        class="text-[1.75rem] sm:text-3xl md:text-[2.4rem] font-bold leading-tight tracking-tight"
        :class="dark ? 'text-white' : 'text-[var(--color-text-dark)]'"
      >
        <slot>{{ title }}</slot>
      </component>
      <p
        v-if="subtitle"
        class="mt-2 text-base md:text-[1.2rem] leading-relaxed"
        :class="dark ? 'text-white/70' : 'text-[var(--color-text-muted)]'"
      >{{ subtitle }}</p>
      <span
        aria-hidden="true"
        class="mt-4 block h-[3px] w-12 rounded-full bg-[var(--color-accent)]"
        :class="align === 'left' ? '' : 'mx-auto'"
      ></span>
    </div>
    <div v-if="$slots.action" class="shrink-0">
      <slot name="action" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  align: { type: String, default: 'center' },
  dark: { type: Boolean, default: false },
  tag: { type: String, default: 'h2' },
});
</script>

<style>
/* ลิงก์ "ดูทั้งหมด" ใต้/ข้างหัวข้อ — global เพราะส่งเข้ามาผ่าน slot */
.section-more {
  display: inline-flex;
  align-items: center;
  gap: 0.125rem;
  padding: 0.5rem 0.75rem 0.5rem 1.1rem;
  border-radius: 9999px;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--color-accent);
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.section-more:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  background-color: var(--color-accent-tint);
}
</style>
