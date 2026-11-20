<template>
  <!-- ทริปตามประเภท — แท็บเดียวสลับหมวด แทนการวางหมวดละ section ยาวต่อกัน
       หมวดมาจากแอดมิน (/categories) และขึ้นเฉพาะหมวดที่มีทริปเปิดอยู่จริง -->
  <section class="bg-[var(--color-sand)] py-16 md:py-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <SectionTitle
        class="mb-8"
        :title="$t('แพ็กเกจทริปของเรา')"
        :subtitle="$t('เลือกตามประเภทที่ชอบ ทุกทริปบอกระยะทาง ความยาก และรอบถัดไปไว้ให้ดูก่อนจอง')"
      />

      <div
        v-if="tabs.length > 1"
        class="showcase-tabs mb-8 md:mb-10"
        role="tablist"
        :aria-label="$t('ประเภททริป')"
      >
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          role="tab"
          class="showcase-tab"
          :class="{ 'showcase-tab--active': tab.key === active }"
          :aria-selected="tab.key === active"
          @click="active = tab.key"
        >
          <span class="material-symbols-rounded icon-20">{{ tab.icon }}</span>
          {{ tab.label }}
          <span class="showcase-count">{{ tab.count }}</span>
        </button>
      </div>

      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div v-for="i in 8" :key="i" class="h-[360px] rounded-xl bg-white animate-pulse"></div>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 md:gap-6">
        <PackageCard v-for="trip in visibleTrips" :key="trip.id" :trip="trip" />
      </div>

      <div class="mt-10 text-center">
        <router-link :to="moreLink" class="showcase-more">
          {{ moreLabel }}
          <span class="material-symbols-rounded icon-22">chevron_right</span>
        </router-link>
      </div>
    </div>
  </section>
</template>

<script setup>
import { $t } from '@/i18n';
import { computed, ref } from 'vue';
import SectionTitle from '../SectionTitle.vue';
import PackageCard from '../PackageCard.vue';

const props = defineProps({
  trips: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  perTab: { type: Number, default: 8 },
});

const active = ref('all');

const tabs = computed(() => {
  const counts = {};
  for (const t of props.trips) counts[t.type] = (counts[t.type] || 0) + 1;

  const catTabs = props.categories
    .filter((c) => counts[c.slug])
    .map((c) => ({ key: c.slug, label: c.name, icon: c.icon || 'explore', count: counts[c.slug] }));

  return [{ key: 'all', label: $t('ทั้งหมด'), icon: 'apps', count: props.trips.length }, ...catTabs];
});

const visibleTrips = computed(() => {
  const list = active.value === 'all' ? props.trips : props.trips.filter((t) => t.type === active.value);
  return list.slice(0, props.perTab);
});

const activeTab = computed(() => tabs.value.find((t) => t.key === active.value));

const moreLink = computed(() => (active.value === 'all' ? '/trips' : { path: '/trips', query: { type: active.value } }));
const moreLabel = computed(() => (active.value === 'all' ? $t('ดูทริปทั้งหมด') : $t('ดูทริป{v}ทั้งหมด', { v: activeTab.value?.label || '' })));
</script>

<style scoped>
.showcase-tabs {
  display: flex;
  gap: 0.5rem;
  overflow-x: auto;
  scrollbar-width: none;
  justify-content: flex-start;
  padding-bottom: 2px;
}
.showcase-tabs::-webkit-scrollbar {
  display: none;
}
@media (min-width: 768px) {
  .showcase-tabs {
    justify-content: center;
    flex-wrap: wrap;
  }
}
.showcase-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  flex-shrink: 0;
  padding: 0.55rem 0.9rem 0.55rem 0.8rem;
  border-radius: 9999px;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 15px;
  font-weight: 700;
  color: var(--color-text-mid);
  white-space: nowrap;
  transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
}
.showcase-tab:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  color: var(--color-accent);
}
.showcase-tab--active,
.showcase-tab--active:hover {
  background-color: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
}
.showcase-count {
  min-width: 1.5rem;
  padding: 0 0.4rem;
  border-radius: 9999px;
  background-color: var(--color-sand);
  color: var(--color-text-muted);
  font-size: 12px;
  line-height: 1.4rem;
  text-align: center;
}
.showcase-tab--active .showcase-count {
  background-color: rgba(255, 255, 255, 0.2);
  color: #fff;
}
.showcase-more {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.8rem 1.4rem 0.8rem 2rem;
  border-radius: 9999px;
  background-color: var(--color-accent);
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  transition: background-color 0.2s ease;
}
.showcase-more:hover {
  background-color: var(--color-accent-mid);
}
</style>
