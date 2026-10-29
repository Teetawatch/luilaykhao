<template>
  <nav v-if="items.length" aria-label="สารบัญ" class="lg:sticky lg:top-28">
    <!-- มือถือ: พับเก็บ -->
    <button
      type="button"
      class="lg:hidden w-full flex items-center justify-between gap-3 rounded-2xl border border-[var(--color-sand-dark)] bg-white px-4 py-3.5 text-sm font-bold text-[var(--color-text-dark)]"
      :aria-expanded="mobileOpen"
      @click="mobileOpen = !mobileOpen"
    >
      <span class="flex items-center gap-2">
        <span class="material-symbols-rounded text-[20px] text-[var(--color-accent)]">toc</span>
        สารบัญ
        <span class="text-[var(--color-text-muted)] font-semibold">· {{ items.length }} หัวข้อ</span>
      </span>
      <span class="material-symbols-rounded text-[20px] transition-transform" :class="{ 'rotate-180': mobileOpen }">expand_more</span>
    </button>

    <div :class="mobileOpen ? 'block' : 'hidden lg:block'" class="mt-2 lg:mt-0 rounded-2xl lg:rounded-none border lg:border-0 border-[var(--color-sand-dark)] bg-white lg:bg-transparent p-2 lg:p-0">
      <p class="hidden lg:block mb-3 text-[13px] font-bold text-[var(--color-text-muted)]">สารบัญ</p>
      <ol class="space-y-0.5 lg:border-l lg:border-[var(--color-sand-dark)]">
        <li v-for="item in items" :key="item.id">
          <a
            :href="`#${item.id}`"
            class="toc-link"
            :class="{ 'toc-link--active': active === item.id }"
            :aria-current="active === item.id ? 'location' : undefined"
            @click.prevent="go(item.id)"
          >{{ item.text }}</a>
        </li>
      </ol>
    </div>
  </nav>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * สารบัญอัตโนมัติจากหัวข้อ h2 ใน `target` — ใช้กับหน้าเงื่อนไข/นโยบาย
 * อ่านหัวข้อจาก DOM แทนการพิมพ์ซ้ำ เพื่อให้ข้อความกฎหมายมีที่เดียว แก้ที่หน้าแล้วสารบัญตามเอง
 */
const props = defineProps({
  target: { type: Object, default: null },
});

const items = ref([]);
const active = ref('');
const mobileOpen = ref(false);
let spy = null;

function slug(i) {
  return `section-${i + 1}`;
}

function collect(root) {
  const headings = [...root.querySelectorAll('h2')];
  items.value = headings.map((h, i) => {
    if (!h.id) h.id = slug(i);
    h.classList.add('scroll-mt-28');
    return { id: h.id, text: h.textContent.replace(/\s+/g, ' ').trim() };
  });
  active.value = items.value[0]?.id || '';

  spy = new IntersectionObserver(
    (entries) => {
      const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (visible[0]) active.value = visible[0].target.id;
    },
    { rootMargin: '-110px 0px -65% 0px' },
  );
  headings.forEach((h) => spy.observe(h));

  // เปิดจากลิงก์ที่มี #section-3 ติดมา
  const hash = decodeURIComponent(window.location.hash.slice(1));
  if (hash && items.value.some((it) => it.id === hash)) {
    document.getElementById(hash)?.scrollIntoView({ block: 'start' });
  }
}

function go(id) {
  active.value = id;
  mobileOpen.value = false;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.getElementById(id)?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
  history.replaceState(history.state, '', `#${id}`);
}

// ref ของบทความในหน้าแม่ยังเป็น null ตอนคอมโพเนนต์นี้ mount (มันอยู่ก่อนบทความ) — รอจนได้ element จริงแล้วค่อยอ่าน
watch(() => props.target, async (el) => {
  if (!el || items.value.length || !('IntersectionObserver' in window)) return;
  await nextTick();
  collect(el);
}, { immediate: true });

onBeforeUnmount(() => spy?.disconnect());
</script>

<style scoped>
.toc-link {
  display: block;
  padding: 0.5rem 0.75rem;
  border-radius: 0.625rem;
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 1.4;
  color: var(--color-text-mid);
  transition: color 0.2s ease, background-color 0.2s ease;
}
.toc-link:hover { color: var(--color-text-dark); background-color: var(--color-sand); }
@media (min-width: 1024px) {
  .toc-link {
    margin-left: -1px;
    padding: 0.375rem 0 0.375rem 1rem;
    border-left: 2px solid transparent;
    border-radius: 0;
  }
  .toc-link:hover { background-color: transparent; }
}
.toc-link--active {
  color: var(--color-accent);
  font-weight: 700;
}
@media (min-width: 1024px) {
  .toc-link--active { border-left-color: var(--color-accent); }
}
</style>
