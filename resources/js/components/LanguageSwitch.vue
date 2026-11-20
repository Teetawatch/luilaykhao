<template>
  <!-- ปุ่มสลับภาษา — มีสองภาษา จึงเป็นปุ่มเดียวที่พาไปอีกภาษา ไม่ต้องเปิดเมนู -->
  <a
    :href="targetHref"
    :hreflang="target"
    :lang="target"
    class="lang-switch focus-ring"
    :class="variant === 'row' ? 'lang-switch--row' : 'lang-switch--pill'"
    :aria-label="target === 'en' ? 'View this page in English' : 'ดูหน้านี้เป็นภาษาไทย'"
    @click.prevent="switchLocale(target)"
  >
    <span class="material-symbols-rounded" :class="variant === 'row' ? 'text-[22px]' : 'icon-18'" aria-hidden="true">language</span>
    <span v-if="variant === 'row'" class="flex-1 text-left">{{ target === 'en' ? 'English' : 'ภาษาไทย' }}</span>
    <span v-else>{{ target === 'en' ? 'EN' : 'ไทย' }}</span>
    <span v-if="variant === 'row'" class="text-[13px] font-semibold text-text-muted">{{ current === 'en' ? 'English' : 'ไทย' }} →</span>
  </a>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ENGLISH, LOCALE, THAI, localePath, switchLocale } from '@/i18n';

defineProps({
  // pill = ปุ่มเล็กบนแถบบนเดสก์ท็อป · row = แถวในเมนูมือถือ
  variant: { type: String, default: 'pill' },
});

const route = useRoute();
const current = LOCALE;
const target = LOCALE === ENGLISH ? THAI : ENGLISH;

// href จริงไว้ให้คลิกขวา/เปิดแท็บใหม่ และให้บ็อตเห็นว่าหน้านี้มีอีกภาษา
const targetHref = computed(() => localePath(route.fullPath, target));
</script>

<style scoped>
.lang-switch {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-weight: 700;
  color: var(--color-text-dark);
  transition: background-color 0.2s, border-color 0.2s, color 0.2s;
}
.lang-switch--pill {
  height: 2.5rem;
  padding: 0 0.875rem;
  border-radius: 9999px;
  border: 1px solid var(--color-sand-dark);
  background: #fff;
  font-size: 14px;
}
.lang-switch--pill:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
}
.lang-switch--row {
  width: 100%;
  gap: 0.875rem;
  padding: 0.875rem 1rem;
  border-radius: 1rem;
  border: 1px solid var(--color-sand-dark);
  background: #fff;
  font-size: 15px;
}
.lang-switch--row:active {
  transform: scale(0.98);
}
</style>
