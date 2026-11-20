<template>
  <!-- หัวหน้าแบบเรียบสำหรับหน้าใช้งาน (ทริปทั้งหมด ฟีด ปฏิทิน หน้าบัญชี ฯลฯ)
       ชุดเดียวกับ SectionTitle บนหน้าแรก: เส้นทางนำทาง → ชื่อหน้า → คำอธิบาย → ขีดเขียวสั้น
       หน้าเนื้อหาที่มีภาพใหญ่ใช้ PageHero แทน
       `container` ต้องตรงกับกล่องเนื้อหาของหน้านั้น หัวกับเนื้อหาจะได้ชิดซ้ายแนวเดียวกัน -->
  <section class="page-header bg-white border-b border-[var(--color-sand-dark)]">
    <div class="mx-auto" :class="[container, flush ? 'pt-6 md:pt-8' : 'py-6 md:py-9']">
      <nav :aria-label="$t('เส้นทางนำทาง')" class="mb-3 flex flex-wrap items-center gap-1 text-[13px] font-medium text-[var(--color-text-muted)]">
        <router-link to="/" class="hover:text-[var(--color-accent)] transition-colors">{{ $t('หน้าแรก') }}</router-link>
        <template v-for="crumb in crumbs" :key="crumb.label">
          <span class="material-symbols-rounded icon-16 opacity-60" aria-hidden="true">chevron_right</span>
          <router-link :to="crumb.to" class="hover:text-[var(--color-accent)] transition-colors">{{ crumb.label }}</router-link>
        </template>
        <span class="material-symbols-rounded icon-16 opacity-60" aria-hidden="true">chevron_right</span>
        <span class="text-[var(--color-text-dark)] font-semibold truncate max-w-[16rem]">{{ crumbLabel || title }}</span>
      </nav>

      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0 max-w-3xl">
          <h1 class="text-[1.75rem] sm:text-3xl md:text-[2.4rem] font-bold leading-tight tracking-tight text-[var(--color-text-dark)]">
            <slot name="title">{{ title }}</slot>
          </h1>
          <p v-if="subtitle" class="mt-2 text-[15px] md:text-[1.1rem] leading-relaxed text-[var(--color-text-muted)]">{{ subtitle }}</p>
          <span aria-hidden="true" class="mt-4 block h-[3px] w-12 rounded-full bg-[var(--color-accent)]"></span>
        </div>
        <div v-if="$slots.actions" class="shrink-0 flex flex-wrap items-center gap-2">
          <slot name="actions" />
        </div>
      </div>

      <slot />
    </div>
  </section>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  /** ชื่อสั้นในเส้นทางนำทาง เมื่อชื่อหน้ายาวหรือเปลี่ยนตามตัวกรอง */
  crumbLabel: { type: String, default: '' },
  /** หน้าแม่ระหว่าง "หน้าแรก" กับหน้านี้ เช่น [{ to: '/profile', label: 'บัญชีของฉัน' }] */
  crumbs: { type: Array, default: () => [] },
  container: { type: String, default: 'max-w-7xl px-4 sm:px-6 lg:px-8' },
  /** ไม่เว้นขอบล่าง — ให้แท็บใน slot วางเส้นใต้ทับเส้นขอบของหัวพอดี */
  flush: { type: Boolean, default: false },
});
</script>

<style>
/* ปุ่มในช่อง actions ของ PageHeader — global เพราะส่งเข้ามาทาง slot */
.ph-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.6rem 1.1rem;
  border-radius: 9999px;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--color-text-dark);
  white-space: nowrap;
  transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}
.ph-btn:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  color: var(--color-accent);
}
.ph-btn--solid {
  border-color: var(--color-accent);
  background-color: var(--color-accent);
  color: #fff;
}
.ph-btn--solid:hover {
  background-color: var(--color-accent-mid);
  border-color: var(--color-accent-mid);
  color: #fff;
}
</style>
