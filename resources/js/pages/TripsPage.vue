<template>
  <div class="pt-12 pb-24 max-w-7xl mx-auto px-4 sm:px-6 md:px-8 bg-[var(--color-sand)] font-anuphan selection:bg-[var(--color-accent)] selection:text-white">
    <!-- Header Section -->
    <header class="mb-14 max-w-3xl animate-fade-in relative z-10">
      <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white border border-gray-100 text-[var(--color-accent)] text-sm font-bold mb-6 shadow-sm">
        <span class="material-symbols-rounded text-[18px]">explore</span>
        ค้นพบประสบการณ์ใหม่
      </div>
      <h1 class="text-5xl md:text-6xl font-extrabold text-[var(--color-text-dark)] tracking-tight mb-6 leading-[1.15]">
        กิจกรรมและ<br class="hidden md:block" />
        <span class="text-[var(--color-accent)]">ทริปทั้งหมด</span>
      </h1>
      <p class="text-lg md:text-xl text-[var(--color-text-muted)] leading-relaxed font-medium max-w-2xl">
        สำรวจทริปที่คัดสรรมาเพื่อคุณ ตั้งแต่ดำน้ำตื้น เดินป่า จนถึงบริการรถตู้ระดับพรีเมียม เพื่อประสบการณ์การเดินทางที่สมบูรณ์แบบที่สุด
      </p>
    </header>

    <div class="flex flex-col lg:flex-row gap-10 lg:gap-14">
      <!-- Filter Sidebar -->
      <aside class="lg:w-80 shrink-0 relative z-20">
        <div class="lg:sticky lg:top-28 space-y-8 bg-white p-8 rounded-[2rem] shadow-[0_10px_40px_rgba(0,0,0,0.04)] border border-gray-100">
          
          <!-- Search -->
          <section class="animate-fade-in" style="animation-delay: 0.1s">
            <h3 class="text-sm font-extrabold text-[var(--color-text-dark)] mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">search</span>
              ค้นหา
            </h3>
            <div class="relative group">
              <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-[20px] group-focus-within:text-[var(--color-accent)] transition-colors">search</span>
              <input v-model="tripsStore.filters.search" @keyup.enter="tripsStore.fetchTrips()"
                type="text" placeholder="ค้นหาทริป..."
                class="w-full bg-[var(--color-sand)] border border-transparent rounded-[1.25rem] pl-12 pr-4 py-3.5 text-base font-medium text-[var(--color-text-dark)] placeholder:text-gray-400 focus:bg-white focus:ring-2 focus:ring-[var(--color-accent)]/30 focus:border-[var(--color-accent)] outline-none transition-all duration-300" />
            </div>
          </section>

          <hr class="border-gray-100" />

          <!-- Categories -->
          <section class="animate-fade-in" style="animation-delay: 0.2s">
            <h3 class="text-sm font-extrabold text-[var(--color-text-dark)] mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">category</span>
              หมวดหมู่กิจกรรม
            </h3>
            <div class="space-y-2">
              <label v-for="cat in categories" :key="cat.value"
                class="flex items-center gap-4 group cursor-pointer p-3 rounded-[1.25rem] hover:bg-[var(--color-sand)] transition-all duration-300"
                :class="{'bg-[var(--color-sand)]': tripsStore.filters.type === cat.value}"
                @click.prevent="toggleType(cat.value)">
                <div class="w-6 h-6 rounded-md flex items-center justify-center transition-all duration-300 border-2"
                  :class="tripsStore.filters.type === cat.value
                    ? 'bg-[var(--color-accent)] border-[var(--color-accent)] shadow-md shadow-[var(--color-accent)]/30 scale-110'
                    : 'bg-white border-gray-300 group-hover:border-[var(--color-accent)]/50'">
                  <span v-if="tripsStore.filters.type === cat.value" class="material-symbols-rounded text-white text-[16px] font-bold">check</span>
                </div>
                <span class="transition-all duration-300 text-base"
                  :class="tripsStore.filters.type === cat.value
                    ? 'text-[var(--color-text-dark)] font-extrabold'
                    : 'text-[var(--color-text-mid)] font-medium group-hover:text-[var(--color-accent)]'">
                  {{ cat.label }}
                </span>
              </label>
            </div>
          </section>

          <template v-if="tripsStore.filters.type === 'trekking'">
          <hr class="border-gray-100" />

          <!-- Difficulty (trekking only) -->
          <section class="animate-fade-in" style="animation-delay: 0.3s">
            <h3 class="text-sm font-extrabold text-[var(--color-text-dark)] mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">terrain</span>
              ระดับความยาก
            </h3>
            <div class="space-y-2">
              <label v-for="diff in difficulties" :key="diff.value"
                class="flex items-center gap-4 group cursor-pointer p-3 rounded-[1.25rem] hover:bg-[var(--color-sand)] transition-all duration-300"
                :class="{'bg-[var(--color-sand)]': tripsStore.filters.difficulty === diff.value}"
                @click.prevent="toggleDifficulty(diff.value)">
                <div class="w-6 h-6 rounded-md flex items-center justify-center transition-all duration-300 border-2"
                  :class="tripsStore.filters.difficulty === diff.value
                    ? 'bg-[var(--color-accent)] border-[var(--color-accent)] shadow-md shadow-[var(--color-accent)]/30 scale-110'
                    : 'bg-white border-gray-300 group-hover:border-[var(--color-accent)]/50'">
                  <span v-if="tripsStore.filters.difficulty === diff.value" class="material-symbols-rounded text-white text-[16px] font-bold">check</span>
                </div>
                <span class="transition-all duration-300 text-base"
                  :class="tripsStore.filters.difficulty === diff.value
                    ? 'text-[var(--color-text-dark)] font-extrabold'
                    : 'text-[var(--color-text-mid)] font-medium group-hover:text-[var(--color-accent)]'">
                  {{ diff.label }}
                </span>
              </label>
            </div>
          </section>
          </template>

          <!-- Actions -->
          <div class="pt-4 flex flex-col gap-3 animate-fade-in" style="animation-delay: 0.4s">
            <button @click="tripsStore.fetchTrips()"
              class="w-full bg-[var(--color-primary)] text-white px-6 py-4 rounded-full text-base font-extrabold hover:bg-[var(--color-accent)] transition-all duration-300 shadow-[0_8px_20px_rgba(13,43,30,0.2)] hover:shadow-[0_12px_25px_rgba(45,122,79,0.3)] hover:-translate-y-1 flex items-center justify-center gap-2 cursor-pointer">
              <span class="material-symbols-rounded text-[20px]">filter_list</span>
              ใช้ตัวกรอง
            </button>
            
            <button v-if="hasFilters" @click="clearAndFetch"
              class="w-full text-gray-500 hover:text-red-500 bg-gray-50 hover:bg-red-50 px-6 py-3 rounded-full text-sm font-bold transition-all duration-300 flex items-center justify-center gap-2 cursor-pointer">
              <span class="material-symbols-rounded text-[18px]">close</span>
              ล้างตัวกรอง
            </button>
          </div>
        </div>
      </aside>

      <!-- Activity Grid -->
      <div class="flex-1 min-w-0">
        <!-- Sorting & Count -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4 animate-fade-in" style="animation-delay: 0.15s">
          <p class="text-[var(--color-text-muted)] text-base font-medium">
            พบทริปทั้งหมด <span class="font-extrabold text-[var(--color-text-dark)] text-lg bg-white px-3 py-1 rounded-lg shadow-sm ml-1">{{ tripsStore.meta?.total || tripsStore.trips.length }}</span>
          </p>
          <div class="flex gap-3 items-center">
            <span class="text-sm font-bold text-[var(--color-text-muted)]">เรียงโดย:</span>
            <div class="relative">
              <select
                v-model="sortOrder"
                class="appearance-none bg-white pl-5 pr-10 py-2.5 rounded-full shadow-sm border border-gray-100 text-sm font-bold text-[var(--color-text-dark)] cursor-pointer hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-colors outline-none focus:ring-2 focus:ring-[var(--color-accent)]/20"
              >
                <option value="popular">ทริปยอดนิยม</option>
                <option value="price_asc">ราคาจากน้อยไปมาก</option>
                <option value="price_desc">ราคาจากมากไปน้อย</option>
              </select>
              <span class="material-symbols-rounded text-[20px] text-gray-400 pointer-events-none absolute right-3 top-1/2 -translate-y-1/2">keyboard_arrow_down</span>
            </div>
          </div>
        </div>

        <!-- Loading -->
        <div v-if="tripsStore.loading" class="text-center py-32 bg-white rounded-[2rem] border border-gray-100 shadow-sm">
          <div class="inline-block relative">
            <div class="w-16 h-16 border-4 border-[var(--color-sand)] border-t-[var(--color-accent)] rounded-full animate-spin"></div>
          </div>
          <p class="text-[var(--color-text-dark)] font-bold mt-6 text-lg tracking-wide">กำลังค้นหาทริปที่ดีที่สุดให้คุณ...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="tripsStore.trips.length === 0" class="text-center py-32 bg-white rounded-[2rem] border border-gray-100 shadow-sm">
          <div class="w-24 h-24 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-6">
            <span class="material-symbols-rounded text-gray-300 text-5xl">explore_off</span>
          </div>
          <h3 class="text-[var(--color-text-dark)] text-2xl font-extrabold mb-3">ไม่พบกิจกรรมที่ตรงกับเงื่อนไข</h3>
          <p class="text-[var(--color-text-muted)] text-base font-medium mb-8">ลองปรับตัวกรองหรือคำค้นหาของคุณอีกครั้ง</p>
          <button @click="clearAndFetch"
            class="inline-flex items-center gap-2 bg-[var(--color-primary)] text-white px-8 py-3.5 rounded-full text-base font-extrabold hover:bg-[var(--color-accent)] transition-all duration-300 shadow-lg hover:-translate-y-1 cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">refresh</span>
            ล้างตัวกรองและลองใหม่
          </button>
        </div>

        <!-- Results Grid -->
        <div v-else>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 gap-6 lg:gap-8">
            <TripCard
              v-for="(trip, index) in sortedTrips"
              :key="trip.id"
              :trip="trip"
              class="animate-fade-in-up"
              :style="{ animationDelay: `${index * 0.08}s` }" />
          </div>

          <!-- Pagination -->
          <div v-if="tripsStore.meta && tripsStore.meta.last_page > 1"
            class="mt-16 flex justify-center items-center gap-2 bg-white p-4 rounded-full w-max mx-auto shadow-sm border border-gray-100">
            <!-- Previous -->
            <button
              @click="tripsStore.meta.current_page > 1 && tripsStore.fetchTrips(tripsStore.meta.current_page - 1)"
              :disabled="tripsStore.meta.current_page <= 1"
              class="w-12 h-12 rounded-full flex items-center justify-center transition-all cursor-pointer"
              :class="tripsStore.meta.current_page <= 1 ? 'text-gray-300 cursor-not-allowed' : 'text-[var(--color-text-dark)] hover:bg-[var(--color-sand)] hover:text-[var(--color-accent)]'">
              <span class="material-symbols-rounded">chevron_left</span>
            </button>

            <!-- Pages -->
            <div class="flex items-center gap-1 px-2">
              <template v-for="page in paginationPages" :key="page">
                <span v-if="page === '...'" class="text-gray-400 font-bold px-2">...</span>
                <button v-else
                  @click="tripsStore.fetchTrips(page)"
                  class="w-12 h-12 rounded-full flex items-center justify-center font-extrabold transition-all duration-300 text-base cursor-pointer"
                  :class="page === tripsStore.meta.current_page
                    ? 'bg-[var(--color-accent)] text-white shadow-lg shadow-[var(--color-accent)]/30 transform scale-110'
                    : 'text-[var(--color-text-dark)] hover:bg-[var(--color-sand)]'">
                  {{ page }}
                </button>
              </template>
            </div>

            <!-- Next -->
            <button
              @click="tripsStore.meta.current_page < tripsStore.meta.last_page && tripsStore.fetchTrips(tripsStore.meta.current_page + 1)"
              :disabled="tripsStore.meta.current_page >= tripsStore.meta.last_page"
              class="w-12 h-12 rounded-full flex items-center justify-center transition-all cursor-pointer"
              :class="tripsStore.meta.current_page >= tripsStore.meta.last_page ? 'text-gray-300 cursor-not-allowed' : 'text-[var(--color-text-dark)] hover:bg-[var(--color-sand)] hover:text-[var(--color-accent)]'">
              <span class="material-symbols-rounded">chevron_right</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import TripCard from '../components/TripCard.vue';
import { useTripsStore } from '../stores/trips';

const tripsStore = useTripsStore();
const route = useRoute();

const categories = [
  { value: 'snorkeling', label: 'ดำน้ำตื้น (Snorkeling)' },
  { value: 'trekking', label: 'เดินป่า (Trekking)' },
  { value: 'diving', label: 'ดำน้ำ (Diving)' },
  { value: 'climbing', label: 'บริการรถตู้ (Van Service)' },
];

const difficulties = [
  { value: 'easy', label: 'ระดับเริ่มต้น (Easy)' },
  { value: 'medium', label: 'ระดับปานกลาง (Medium)' },
  { value: 'hard', label: 'ระดับท้าทาย (Hard)' },
];

const sortOrder = ref('popular');

const hasFilters = computed(() =>
  tripsStore.filters.type || tripsStore.filters.difficulty || tripsStore.filters.search
);

const sortedTrips = computed(() => {
  const list = [...tripsStore.trips];
  if (sortOrder.value === 'price_asc') {
    return list.sort((a, b) => Number(a.price_per_person) - Number(b.price_per_person));
  }
  if (sortOrder.value === 'price_desc') {
    return list.sort((a, b) => Number(b.price_per_person) - Number(a.price_per_person));
  }
  return list;
});

const paginationPages = computed(() => {
  if (!tripsStore.meta) return [];
  const current = tripsStore.meta.current_page;
  const last = tripsStore.meta.last_page;
  const pages = [];

  if (last <= 7) {
    for (let i = 1; i <= last; i++) pages.push(i);
  } else {
    pages.push(1);
    if (current > 3) pages.push('...');
    for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) {
      pages.push(i);
    }
    if (current < last - 2) pages.push('...');
    pages.push(last);
  }
  return pages;
});

function toggleType(value) {
  tripsStore.filters.type = tripsStore.filters.type === value ? '' : value;
  // auto fetch on toggle is optional, if removed user clicks "Search" button
}

function toggleDifficulty(value) {
  tripsStore.filters.difficulty = tripsStore.filters.difficulty === value ? '' : value;
}

function clearAndFetch() {
  tripsStore.clearFilters();
  sortOrder.value = 'popular';
  tripsStore.fetchTrips();
}

onMounted(() => {
  if (route.query.type) tripsStore.filters.type = route.query.type;
  if (route.query.date) tripsStore.filters.date = route.query.date;
  tripsStore.fetchTrips();
});
</script>

<style scoped>
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(30px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.animate-fade-in {
  animation: fadeIn 0.8s ease-out forwards;
  opacity: 0;
}

.animate-fade-in-up {
  animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  opacity: 0;
}

/* Custom scrollbar for webkit */
::-webkit-scrollbar {
  width: 8px;
}
::-webkit-scrollbar-track {
  background: var(--color-sand);
}
::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

@media (prefers-reduced-motion: reduce) {
  .animate-fade-in,
  .animate-fade-in-up {
    animation: none;
    opacity: 1;
  }
}
</style>
