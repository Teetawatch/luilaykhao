<template>
  <!-- หัวหน้าเนื้อหา (problem / reviews / about / goal / faq / ...) ใช้ชุดเดียวกันหมด
       พื้นเขียวป่า รูปจาง ไล่เงาจากซ้าย ตัวอักษรขาวชิดซ้าย -->
  <section class="relative overflow-hidden bg-[var(--color-primary)]">
    <div class="absolute inset-0">
      <img :src="image" alt="" class="w-full h-full object-cover opacity-45" />
      <div class="absolute inset-0 bg-gradient-to-r from-[var(--color-primary)] via-[var(--color-primary)]/85 to-[var(--color-primary)]/30"></div>
    </div>

    <div class="page-hero-content relative z-10 max-w-6xl mx-auto px-4 sm:px-6 md:px-8" :class="compact ? 'pt-16 pb-12 md:pt-20 md:pb-16' : 'pt-20 pb-16 md:pt-28 md:pb-24'">
      <div class="max-w-2xl">
        <template v-if="title">
          <h1 class="text-4xl sm:text-5xl md:text-6xl font-extrabold text-white leading-[1.15] tracking-tight">
            <slot name="title">{{ title }}</slot>
          </h1>
          <p v-if="subtitle" class="mt-5 text-lg md:text-xl text-white/80 leading-relaxed whitespace-pre-line">
            {{ subtitle }}
          </p>
        </template>
        <div v-else-if="loading" class="space-y-4" aria-hidden="true">
          <div class="h-12 md:h-14 w-4/5 rounded-xl bg-white/10 animate-pulse"></div>
          <div class="h-6 w-3/5 rounded-lg bg-white/10 animate-pulse"></div>
        </div>

        <slot />

        <div v-if="$slots.actions" class="mt-9 flex flex-wrap items-center gap-3">
          <slot name="actions" />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  image: { type: String, default: '/images/about.jpg' },
  /** เนื้อหายังโหลดอยู่ (หัวข้อมาจาก CMS) — โชว์โครงแทนช่องว่าง */
  loading: { type: Boolean, default: false },
  /** หน้าเอกสารยาว (เงื่อนไข/นโยบาย) ใช้หัวเตี้ยลง ให้เนื้อหาโผล่เร็วขึ้น */
  compact: { type: Boolean, default: false },
});
</script>

<style>
/* ปุ่มบนหัวหน้า — global เพราะใช้ใน slot ของแต่ละหน้า */
.hero-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: 1rem;
  padding: 0.875rem 1.5rem;
  font-size: 15px;
  font-weight: 700;
  transition: background-color 0.2s ease;
}
.hero-btn:focus { outline: none; }
.hero-btn--solid { background-color: #fff; color: var(--color-primary); }
.hero-btn--solid:hover { background-color: var(--color-sand); }
.hero-btn--solid:focus-visible { box-shadow: 0 0 0 4px rgb(255 255 255 / 0.4); }
.hero-btn--outline { border: 1px solid rgb(255 255 255 / 0.3); color: #fff; }
.hero-btn--outline:hover { background-color: rgb(255 255 255 / 0.1); }
.hero-btn--outline:focus-visible { box-shadow: 0 0 0 4px rgb(255 255 255 / 0.3); }
</style>

<style scoped>
.page-hero-content {
  animation: pageHeroIn 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@keyframes pageHeroIn {
  from { opacity: 0; transform: translateY(24px); }
  to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .page-hero-content { animation: none; }
}
</style>
