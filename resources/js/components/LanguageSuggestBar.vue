<template>
  <!-- แถบชวนเปิดหน้าภาษาอังกฤษ — ขึ้นเฉพาะหน้าไทย สำหรับคนที่เบราว์เซอร์ไม่มีภาษาไทย
       เลยสักภาษาและยังไม่เคยเลือกภาษาเอง กดอย่างใดอย่างหนึ่งแล้วไม่ขึ้นอีก
       (คนไทยที่ตั้งเบราว์เซอร์เป็นอังกฤษส่วนใหญ่ยังมี th อยู่ในรายการ จึงไม่เห็นแถบนี้) -->
  <Transition name="lang-bar">
    <div v-if="visible" class="lang-bar" role="region" aria-label="Language" lang="en">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5 flex items-center gap-3">
        <span class="material-symbols-rounded icon-20 shrink-0 text-accent" aria-hidden="true">language</span>
        <p class="flex-1 min-w-0 text-[14px] font-semibold text-text-dark leading-snug">
          This site is also available in English.
        </p>
        <button type="button" class="lang-bar-btn" @click="toEnglish">Switch to English</button>
        <button type="button" class="lang-bar-close" aria-label="Keep Thai" @click="dismiss">
          <span class="material-symbols-rounded icon-20" aria-hidden="true">close</span>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { ENGLISH, THAI, isEnglish, rememberLocale, savedLocale, switchLocale } from '@/i18n';

const visible = ref(false);

function browserPrefersThai() {
  const langs = navigator.languages?.length ? navigator.languages : [navigator.language || ''];

  return langs.some((lang) => String(lang).toLowerCase().startsWith('th'));
}

onMounted(() => {
  if (isEnglish || savedLocale()) return;
  if (browserPrefersThai()) return;
  visible.value = true;
});

function toEnglish() {
  switchLocale(ENGLISH);
}

function dismiss() {
  rememberLocale(THAI);
  visible.value = false;
}
</script>

<style scoped>
.lang-bar {
  background: var(--color-accent-tint, #eef5f0);
  border-bottom: 1px solid var(--color-sand-dark);
}
.lang-bar-btn {
  flex-shrink: 0;
  height: 2.25rem;
  padding: 0 0.875rem;
  border-radius: 9999px;
  background: var(--color-accent);
  color: #fff;
  font-size: 14px;
  font-weight: 700;
}
.lang-bar-btn:hover {
  background: var(--color-primary);
}
.lang-bar-close {
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  color: var(--color-text-muted);
}
.lang-bar-close:hover {
  background: rgba(0, 0, 0, 0.05);
}
.lang-bar-enter-active,
.lang-bar-leave-active {
  transition: opacity 0.25s ease;
}
.lang-bar-enter-from,
.lang-bar-leave-to {
  opacity: 0;
}
</style>
