<template>
  <div class="pt-12 pb-24 max-w-[1600px] mx-auto px-4 sm:px-6 md:px-8 lg:px-12 bg-[var(--color-sand)] font-anuphan selection:bg-[var(--color-accent)] selection:text-white">
    <!-- Header -->
    <header class="mb-10 md:mb-12 max-w-3xl animate-fade-in relative z-10">
      <h1 class="text-4xl md:text-5xl font-extrabold text-[var(--color-text-dark)] tracking-tight mb-4 leading-[1.15]">
        กิจกรรมและ <span class="text-[var(--color-accent)]">ทริปทั้งหมด</span>
      </h1>
      <!--
        บอกว่าหน้านี้คืออะไรและกรองยังไง — ไม่ใช่ "คัดสรรมาเพื่อคุณ" (หน้านี้ไม่มี personalization)
        หรือ "สมบูรณ์แบบที่สุด" ซึ่งไม่มีอะไรรองรับ
      -->
      <p class="text-base md:text-lg text-[var(--color-text-muted)] leading-relaxed font-medium max-w-2xl mb-5">
        ทริปทั้งหมดที่เปิดรับจองอยู่ตอนนี้ ทั้งเดินป่า ดำน้ำตื้น และรถตู้เช่าพร้อมคนขับ กรองตามประเภทหรือระดับความยากได้จากแถบด้านซ้าย
      </p>

      <!-- ตัวเลขจากข้อมูลจริง + สิ่งที่ต้องรู้ก่อนอ่านราคา ไม่ใช่ตรารับรอง -->
      <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm font-semibold text-[var(--color-text-muted)]">
        <span class="inline-flex items-center gap-1.5">
          <span class="material-symbols-rounded text-[18px] text-[var(--color-accent)]">map</span>
          {{ totalTrips.toLocaleString() }} ทริปให้เลือก
        </span>
        <span v-if="totalConfirmedParticipants > 0" class="inline-flex items-center gap-1.5">
          <span class="material-symbols-rounded text-[18px] text-[var(--color-accent)]" style="font-variation-settings:'FILL' 1">group</span>
          {{ totalConfirmedParticipants.toLocaleString() }} คนร่วมเดินทางแล้ว
        </span>
        <span class="inline-flex items-center gap-1.5">
          <span class="material-symbols-rounded text-[18px] text-[var(--color-accent)]">sell</span>
          ราคาที่แสดงเป็นราคาเริ่มต้นต่อคน
        </span>
      </div>
    </header>

    <!-- ในประเทศ / ต่างประเทศ — แท็บหลัก อยู่นอกแถบตัวกรองด้านซ้ายเพราะเป็น
         การแบ่งที่ใหญ่กว่าประเภทกิจกรรม และต้องเห็นได้ทันทีบนมือถือ -->
    <nav class="mb-8 flex flex-wrap gap-2" aria-label="ปลายทาง">
      <button v-for="tab in destinationTabs" :key="tab.value" type="button"
        @click="selectDestination(tab.value)"
        :aria-pressed="tripsStore.filters.destination === tab.value"
        class="inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-bold transition-all duration-300 border"
        :class="tripsStore.filters.destination === tab.value
          ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white'
          : 'bg-white border-gray-200 text-[var(--color-text-mid)] hover:border-[var(--color-accent)]/50'">
        <span class="material-symbols-rounded text-[18px]">{{ tab.icon }}</span>
        {{ tab.label }}
      </button>
    </nav>

    <div class="flex flex-col lg:flex-row gap-10 lg:gap-14">
      <!-- Filter Sidebar -->
      <aside class="lg:w-80 shrink-0 relative z-20">
        <div class="lg:sticky lg:top-28 space-y-7 bg-white p-8 rounded-[2rem] border border-gray-100">

          <!-- Sidebar title -->
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-extrabold text-[var(--color-text-dark)] flex items-center gap-2.5">
              <span class="w-9 h-9 rounded-xl bg-[var(--color-accent)]/10 flex items-center justify-center">
                <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">tune</span>
              </span>
              ตัวกรอง
            </h2>
            <button v-if="hasFilters" @click="clearAndFetch"
              class="text-xs font-bold text-gray-400 hover:text-red-500 transition-colors flex items-center gap-1">
              <span class="material-symbols-rounded text-[16px]">restart_alt</span>
              ล้าง
            </button>
          </div>

          <hr class="border-gray-100" />

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
                    ? 'bg-[var(--color-accent)] border-[var(--color-accent)] shadow-md/30 scale-110'
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
                    ? 'bg-[var(--color-accent)] border-[var(--color-accent)] shadow-md/30 scale-110'
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
              class="w-full bg-[var(--color-primary)] text-white px-6 py-4 rounded-full text-base font-extrabold hover:bg-[var(--color-accent)] transition-all duration-300 hover:-translate-y-1 flex items-center justify-center gap-2 cursor-pointer">
              <span class="material-symbols-rounded text-[20px]">filter_list</span>
              ใช้ตัวกรอง
            </button>
          </div>
        </div>
      </aside>

      <!-- Activity Grid -->
      <div class="flex-1 min-w-0">
        <!-- Sorting & Count -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4 animate-fade-in" style="animation-delay: 0.15s">
          <div class="flex items-baseline gap-2.5">
            <span class="text-2xl font-extrabold text-[var(--color-text-dark)] tabular-nums">{{ tripsStore.meta?.total || tripsStore.trips.length }}</span>
            <span class="text-[var(--color-text-muted)] text-base font-medium">ทริปที่พบ</span>
          </div>
          <div class="flex gap-3 items-center">
            <span class="text-sm font-bold text-[var(--color-text-muted)]">เรียงโดย:</span>
            <div class="relative">
              <select
                v-model="tripsStore.filters.sort"
                @change="tripsStore.fetchTrips()"
                class="appearance-none bg-white pl-5 pr-10 py-2.5 rounded-full border border-gray-100 text-sm font-bold text-[var(--color-text-dark)] cursor-pointer hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-colors outline-none focus:ring-2 focus:ring-[var(--color-accent)]/20"
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
        <div v-if="tripsStore.loading" class="text-center py-32 bg-white rounded-[2rem] border border-gray-100">
          <div class="inline-block relative">
            <div class="w-16 h-16 border-4 border-[var(--color-sand)] border-t-[var(--color-accent)] rounded-full animate-spin"></div>
          </div>
          <p class="text-[var(--color-text-dark)] font-bold mt-6 text-lg tracking-wide">กำลังโหลดทริป...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="tripsStore.trips.length === 0" class="text-center py-32 bg-white rounded-[2rem] border border-gray-100">
          <div class="w-24 h-24 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-6">
            <span class="material-symbols-rounded text-gray-300 text-5xl">explore_off</span>
          </div>
          <h3 class="text-[var(--color-text-dark)] text-2xl font-extrabold mb-3">ไม่พบกิจกรรมที่ตรงกับเงื่อนไข</h3>
          <p class="text-[var(--color-text-muted)] text-base font-medium mb-8">ลองปรับตัวกรองหรือคำค้นหาของคุณอีกครั้ง</p>
          <button @click="clearAndFetch"
            class="inline-flex items-center gap-2 bg-[var(--color-primary)] text-white px-8 py-3.5 rounded-full text-base font-extrabold hover:bg-[var(--color-accent)] transition-all duration-300 hover:-translate-y-1 cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">refresh</span>
            ล้างตัวกรองและลองใหม่
          </button>
        </div>

        <!-- Results Grid -->
        <div v-else>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6 lg:gap-8">
            <TripCard
              v-for="(trip, index) in tripsStore.trips"
              :key="trip.id"
              :trip="trip"
              class="animate-fade-in-up"
              :style="{ animationDelay: `${index * 0.08}s` }" />
          </div>

          <!-- Pagination -->
          <div v-if="tripsStore.meta && tripsStore.meta.last_page > 1"
            class="mt-16 flex justify-center items-center gap-2 bg-white p-4 rounded-full w-max mx-auto border border-gray-100">
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
                    ? 'bg-[var(--color-accent)] text-white shadow-lg/30 transform scale-110'
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
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import TripCard from '../components/TripCard.vue';
import { useTripsStore } from '../stores/trips';
import { useCategoriesStore } from '../stores/categories';

const tripsStore = useTripsStore();
const categoriesStore = useCategoriesStore();
const route = useRoute();

const categories = computed(() => categoriesStore.categories.map(c => ({
  value: c.slug,
  label: c.name,
})));

const difficulties = [
  { value: 'easy', label: 'ระดับเริ่มต้น (Easy)' },
  { value: 'medium', label: 'ระดับปานกลาง (Medium)' },
  { value: 'hard', label: 'ระดับท้าทาย (Hard)' },
];

const hasFilters = computed(() =>
  tripsStore.filters.type || tripsStore.filters.difficulty || tripsStore.filters.search
);

const totalConfirmedParticipants = computed(() => {
  return tripsStore.trips.reduce((sum, trip) => sum + (trip.confirmed_passengers_count || 0), 0);
});

const totalTrips = computed(() => tripsStore.meta?.total || tripsStore.trips.length);

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

const destinationTabs = [
  { value: '', label: 'ทั้งหมด', icon: 'public' },
  { value: 'domestic', label: 'ในประเทศ', icon: 'landscape' },
  { value: 'international', label: 'ต่างประเทศ', icon: 'flight_takeoff' },
];

// แท็บปลายทางดึงข้อมูลใหม่ทันที ต่างจากตัวกรองด้านซ้ายที่รอกดค้นหา — เพราะมัน
// เปลี่ยนทั้งชุดผลลัพธ์ ไม่ใช่การกรองผลที่กำลังดูอยู่ให้แคบลง
function selectDestination(value) {
  if (tripsStore.filters.destination === value) return;
  tripsStore.filters.destination = value;
  tripsStore.fetchTrips();
}

function toggleType(value) {
  tripsStore.filters.type = tripsStore.filters.type === value ? '' : value;
  // auto fetch on toggle is optional, if removed user clicks "Search" button
}

function toggleDifficulty(value) {
  tripsStore.filters.difficulty = tripsStore.filters.difficulty === value ? '' : value;
}

function clearAndFetch() {
  tripsStore.clearFilters();
  tripsStore.fetchTrips();
}

onMounted(() => {
  categoriesStore.fetchCategories();
  if (route.query.type) tripsStore.filters.type = route.query.type;
  if (route.query.date) tripsStore.filters.date = route.query.date;
  if (['domestic', 'international'].includes(route.query.destination)) {
    tripsStore.filters.destination = route.query.destination;
  }
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
