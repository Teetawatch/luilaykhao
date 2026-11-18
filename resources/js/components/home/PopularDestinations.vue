<template>
  <!-- ปลายทางยอดนิยม — นับจากทริปที่เปิดอยู่จริง จัดเป็นโมเสก (ใบแรกใหญ่ 2x2)
       แตะแล้วไปหน้าทริปทั้งหมดที่ค้นด้วยชื่อจังหวัด (TripController ค้นคอลัมน์ location ด้วย) -->
  <section v-if="places.length >= 3" class="bg-white py-16 md:py-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <SectionTitle
        class="mb-10 md:mb-12"
        title="ปลายทางยอดนิยม"
        subtitle="จังหวัดที่มีทริปเปิดให้จองมากที่สุดตอนนี้"
      />

      <div class="dest-grid">
        <router-link
          v-for="(place, i) in places"
          :key="place.name"
          :to="{ path: '/trips', query: { search: place.name } }"
          class="dest-tile group zoom-media"
          :class="{ 'dest-tile--hero': i === 0 }"
        >
          <img v-if="place.image" :src="place.image" :alt="place.name" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
          <span class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/15 to-transparent"></span>
          <span class="absolute inset-x-0 bottom-0 p-4 md:p-5 flex items-end justify-between gap-3">
            <span class="min-w-0">
              <span class="block truncate font-bold text-white leading-tight" :class="i === 0 ? 'text-[26px] md:text-[32px]' : 'text-[19px] md:text-[21px]'">
                {{ place.name }}
              </span>
              <span class="mt-1 block text-[13px] font-medium text-white/85">{{ place.count }} ทริป · เริ่มต้น ฿{{ place.from }}</span>
            </span>
            <span class="dest-go" aria-hidden="true">
              <span class="material-symbols-rounded icon-20">arrow_forward</span>
            </span>
          </span>
        </router-link>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import SectionTitle from '../SectionTitle.vue';
import { tripCardImage } from '../../lib/tripImage';

const props = defineProps({
  trips: { type: Array, default: () => [] },
  limit: { type: Number, default: 9 },
});

// โมเสก 4 คอลัมน์: ใบแรกกิน 2x2 = 4 ช่อง แถวจะเต็มก็ต่อเมื่อใบที่เหลือเป็น 4 หรือ 8 ใบ
// (รวม 5 หรือ 9 ใบ) — ไม่งั้นแถวสุดท้ายเว้นที่ว่างโหว่
function fullRows(available) {
  const n = Math.min(available, props.limit);
  if (n >= 9) return 9;
  if (n >= 5) return 5;
  return n;
}

const places = computed(() => {
  const byName = new Map();

  for (const t of props.trips) {
    const name = (t.is_international ? t.country_label : t.location)?.trim();
    if (!name) continue;
    const entry = byName.get(name) || { name, count: 0, image: null, min: Infinity };
    entry.count += 1;
    // ภาพจากทริปที่คนจองเยอะที่สุดของจังหวัดนั้น (รายการมาเรียงตามความนิยมอยู่แล้ว)
    entry.image = entry.image || tripCardImage(t);
    const price = Number(t.min_price);
    if (price > 0) entry.min = Math.min(entry.min, price);
    byName.set(name, entry);
  }

  return [...byName.values()]
    .filter((p) => p.image)
    .sort((a, b) => b.count - a.count)
    .slice(0, fullRows(byName.size))
    .map((p) => ({ ...p, from: Number.isFinite(p.min) ? p.min.toLocaleString('th-TH') : '-' }));
});
</script>

<style scoped>
.dest-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  grid-auto-rows: 170px;
  gap: 0.75rem;
}
@media (min-width: 768px) {
  .dest-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    grid-auto-rows: 210px;
    gap: 1rem;
  }
}
.dest-tile {
  position: relative;
  display: block;
  overflow: hidden;
  border-radius: 0.875rem;
  background-color: var(--color-sand-dark);
}
.dest-tile--hero {
  grid-column: span 2;
  grid-row: span 2;
}
.dest-go {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  border-radius: 9999px;
  background-color: rgba(255, 255, 255, 0.95);
  color: var(--color-accent);
  transition: transform 0.2s ease;
}
.dest-tile:hover .dest-go {
  transform: translateX(3px);
}
</style>
