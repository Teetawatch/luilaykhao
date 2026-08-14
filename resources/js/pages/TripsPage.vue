<template>
  <div class="pb-24 bg-[var(--color-sand)] font-anuphan selection:bg-[var(--color-accent)] selection:text-white">
    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 md:px-8 lg:px-12 pt-6 md:pt-10">

      <!-- ══════════════════════════════════════════
           HERO — พาดหัวและแท็บปลายทาง
           แท็บในประเทศ/ต่างประเทศอยู่ที่นี่ ไม่ใช่ในแถบตัวกรอง เพราะเป็นการแบ่งที่
           ใหญ่กว่าประเภทกิจกรรม และต้องเห็นได้ทันทีทั้งบนจอเล็กและจอใหญ่
      ══════════════════════════════════════════ -->
      <section class="relative overflow-hidden rounded-[1.75rem] md:rounded-[2.5rem] bg-[var(--color-primary)] px-6 py-9 md:px-12 md:py-12">
        <!-- แสงพื้นหลัง ไม่ใช่รูปภาพ จะได้ไม่ต้องโหลดอะไรเพิ่ม -->
        <div class="hero-glow" aria-hidden="true"></div>

        <div class="relative z-10">
          <p class="text-[var(--color-accent-light)] text-xs md:text-sm font-extrabold tracking-[0.18em] uppercase mb-3">
            {{ heroCopy.eyebrow }}
          </p>

          <h1 class="text-white text-[1.9rem] sm:text-4xl md:text-5xl font-extrabold tracking-tight leading-[1.2] mb-4 max-w-3xl">
            {{ heroCopy.title }}
          </h1>

          <p class="text-white/75 text-sm md:text-lg font-medium leading-relaxed max-w-2xl mb-8">
            {{ heroCopy.subtitle }}
          </p>

          <!-- แท็บปลายทาง + ช่องค้นหา -->
          <div class="flex flex-col xl:flex-row xl:items-center gap-4">
            <nav class="inline-flex bg-white/10 rounded-full p-1.5 w-full sm:w-max" aria-label="ปลายทาง">
              <button v-for="tab in destinationTabs" :key="tab.value" type="button"
                @click="selectDestination(tab.value)"
                :aria-pressed="tripsStore.filters.destination === tab.value"
                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 rounded-full px-4 sm:px-6 py-2.5 text-sm font-extrabold transition-colors duration-300 cursor-pointer"
                :class="tripsStore.filters.destination === tab.value
                  ? 'bg-white text-[var(--color-primary)]'
                  : 'text-white/70 hover:text-white'">
                <span class="material-symbols-rounded text-[19px]">{{ tab.icon }}</span>
                <span>{{ tab.label }}</span>
                <span v-if="tabCount(tab.value) !== null"
                  class="text-[11px] font-black tabular-nums px-1.5 py-0.5 rounded-full"
                  :class="tripsStore.filters.destination === tab.value
                    ? 'bg-[var(--color-primary)]/10 text-[var(--color-primary)]'
                    : 'bg-white/10 text-white/60'">
                  {{ tabCount(tab.value) }}
                </span>
              </button>
            </nav>

            <div class="relative flex-1 xl:max-w-md">
              <span class="material-symbols-rounded absolute left-5 top-1/2 -translate-y-1/2 text-gray-400 text-[20px]">search</span>
              <input v-model="searchTerm" type="text" placeholder="ค้นหาชื่อทริปหรือสถานที่..."
                aria-label="ค้นหาทริป"
                class="w-full bg-white rounded-full pl-[3.25rem] pr-11 py-3.5 text-base font-medium text-[var(--color-text-dark)] placeholder:text-gray-400 outline-none focus:ring-4 focus:ring-white/20 transition-all" />
              <button v-if="searchTerm" type="button" @click="clearSearch" aria-label="ล้างคำค้นหา"
                class="absolute right-4 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center cursor-pointer transition-colors">
                <span class="material-symbols-rounded text-[16px]">close</span>
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- ══════════════════════════════════════════
           แถบปลายทางย่อย — ในประเทศเลือกภาค ต่างประเทศเลือกประเทศ
           แสดงเฉพาะที่มีทริปอยู่จริง จะได้ไม่มีปุ่มที่กดแล้วเจอหน้าว่าง
      ══════════════════════════════════════════ -->
      <section v-if="subRail.items.length" class="mt-7">
        <h2 class="text-xs font-extrabold tracking-[0.14em] uppercase text-[var(--color-text-muted)] mb-3">
          {{ subRail.label }}
        </h2>
        <div class="flex gap-2.5 overflow-x-auto no-scrollbar -mx-4 px-4 sm:mx-0 sm:px-0 pb-1">
          <button v-for="item in subRail.items" :key="item.key" type="button"
            @click="selectSubDestination(item.key)"
            :aria-pressed="subRail.active === item.key"
            class="shrink-0 inline-flex items-center gap-2 rounded-full border px-4 py-2.5 text-sm font-bold transition-colors duration-300 cursor-pointer"
            :class="subRail.active === item.key
              ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white'
              : 'bg-white border-gray-200 text-[var(--color-text-mid)] hover:border-[var(--color-accent)]'">
            <span v-if="item.flag" class="text-base leading-none">{{ item.flag }}</span>
            <span v-else class="material-symbols-rounded text-[18px]">{{ item.icon }}</span>
            {{ item.label }}
            <span class="text-[11px] font-black tabular-nums opacity-60">{{ item.count }}</span>
          </button>
        </div>
      </section>

      <div class="mt-8 flex flex-col lg:flex-row gap-8 lg:gap-12">

        <!-- ══════════════════════════════════════════
             ตัวกรอง — คงที่ด้านซ้ายบนจอใหญ่ / เปิดเป็นแผ่นเลื่อนขึ้นบนมือถือ
        ══════════════════════════════════════════ -->
        <aside class="lg:w-72 xl:w-80 shrink-0">
          <div class="hidden lg:block lg:sticky lg:top-28">
            <div class="bg-white rounded-[1.75rem] border border-gray-100 p-7">
              <TripFilters
              :categories="categories"
              :difficulties="difficulties"
              :durations="durations"
              :type="tripsStore.filters.type"
              :difficulty="tripsStore.filters.difficulty"
              :duration="activeDuration"
              :active-count="activeFilters.length"
              @pick-type="toggleType"
              @pick-difficulty="toggleDifficulty"
              @pick-duration="selectDuration"
              @clear="clearAndFetch" />
            </div>
          </div>
        </aside>

        <!-- ══════════════════════════════════════════
             ผลลัพธ์
        ══════════════════════════════════════════ -->
        <div class="flex-1 min-w-0" ref="resultsTop">

          <!-- แถบเครื่องมือ: จำนวน / ปุ่มตัวกรองบนมือถือ / การเรียง -->
          <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
            <div class="flex items-baseline gap-2">
              <span class="text-2xl font-extrabold text-[var(--color-text-dark)] tabular-nums">{{ resultCount.toLocaleString() }}</span>
              <span class="text-[var(--color-text-muted)] text-base font-medium">ทริปที่พบ</span>
            </div>

            <div class="flex items-center gap-2.5">
              <button type="button" @click="filtersOpen = true"
                class="lg:hidden inline-flex items-center gap-2 bg-white border border-gray-200 rounded-full pl-4 pr-3 py-2.5 text-sm font-bold text-[var(--color-text-dark)] cursor-pointer">
                <span class="material-symbols-rounded text-[20px]">tune</span>
                ตัวกรอง
                <span v-if="activeFilters.length"
                  class="w-5 h-5 rounded-full bg-[var(--color-accent)] text-white text-[11px] font-black flex items-center justify-center">
                  {{ activeFilters.length }}
                </span>
              </button>

              <div class="relative">
                <select v-model="tripsStore.filters.sort" @change="applyFilters()"
                  aria-label="เรียงลำดับ"
                  class="appearance-none bg-white pl-5 pr-10 py-2.5 rounded-full border border-gray-200 text-sm font-bold text-[var(--color-text-dark)] cursor-pointer hover:border-[var(--color-accent)] transition-colors outline-none focus:ring-2 focus:ring-[var(--color-accent)]/20">
                  <option value="popular">ทริปยอดนิยม</option>
                  <option value="price_asc">ราคาจากน้อยไปมาก</option>
                  <option value="price_desc">ราคาจากมากไปน้อย</option>
                </select>
                <span class="material-symbols-rounded text-[20px] text-gray-400 pointer-events-none absolute right-3 top-1/2 -translate-y-1/2">keyboard_arrow_down</span>
              </div>
            </div>
          </div>

          <!-- ตัวกรองที่เปิดอยู่ กดกากบาทเพื่อเอาออกทีละอัน -->
          <div v-if="activeFilters.length" class="flex flex-wrap items-center gap-2 mb-6">
            <span v-for="chip in activeFilters" :key="chip.key"
              class="inline-flex items-center gap-1.5 bg-white border border-gray-200 rounded-full pl-3.5 pr-2 py-1.5 text-sm font-bold text-[var(--color-text-dark)]">
              {{ chip.label }}
              <button type="button" @click="chip.clear()" :aria-label="`เอา ${chip.label} ออก`"
                class="w-5 h-5 rounded-full bg-gray-100 hover:bg-red-100 hover:text-red-500 text-gray-500 flex items-center justify-center cursor-pointer transition-colors">
                <span class="material-symbols-rounded text-[14px]">close</span>
              </button>
            </span>
            <button type="button" @click="clearAndFetch"
              class="text-sm font-bold text-[var(--color-text-muted)] hover:text-red-500 transition-colors ml-1 cursor-pointer">
              ล้างทั้งหมด
            </button>
          </div>

          <!-- โครงการ์ดระหว่างโหลด บอกล่วงหน้าว่าผลลัพธ์จะมาในรูปแบบไหน -->
          <div v-if="tripsStore.loading" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6 lg:gap-8">
            <div v-for="n in 8" :key="n" class="bg-white rounded-[2rem] border border-gray-100 overflow-hidden">
              <div class="m-2 rounded-[1.5rem] aspect-[4/5] skeleton"></div>
              <div class="p-5 space-y-3">
                <div class="h-3.5 w-24 rounded-full skeleton"></div>
                <div class="h-4 w-full rounded-full skeleton"></div>
                <div class="h-4 w-2/3 rounded-full skeleton"></div>
                <div class="h-6 w-28 rounded-full skeleton mt-5"></div>
              </div>
            </div>
          </div>

          <!-- ไม่พบอะไรเลย -->
          <div v-else-if="tripsStore.trips.length === 0" class="text-center py-24 bg-white rounded-[2rem] border border-gray-100 px-6">
            <div class="w-20 h-20 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-6">
              <span class="material-symbols-rounded text-gray-300 text-5xl">explore_off</span>
            </div>
            <h3 class="text-[var(--color-text-dark)] text-xl md:text-2xl font-extrabold mb-3">ยังไม่มีทริปที่ตรงกับที่เลือกไว้</h3>
            <p class="text-[var(--color-text-muted)] text-base font-medium mb-8 max-w-md mx-auto">
              {{ emptyHint }}
            </p>
            <button @click="clearAndFetch"
              class="inline-flex items-center gap-2 bg-[var(--color-primary)] text-white px-7 py-3.5 rounded-full text-base font-extrabold hover:bg-[var(--color-accent)] transition-colors duration-300 cursor-pointer">
              <span class="material-symbols-rounded text-[20px]">refresh</span>
              ล้างตัวกรองและดูทั้งหมด
            </button>
          </div>

          <!-- ผลลัพธ์ -->
          <div v-else>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6 lg:gap-8">
              <TripCard
                v-for="(trip, index) in tripsStore.trips"
                :key="trip.id"
                :trip="trip"
                class="animate-fade-in-up"
                :style="{ animationDelay: `${Math.min(index, 8) * 0.06}s` }" />
            </div>

            <!-- แบ่งหน้า -->
            <div v-if="tripsStore.meta && tripsStore.meta.last_page > 1"
              class="mt-14 flex justify-center items-center gap-1 bg-white p-3 rounded-full w-max mx-auto border border-gray-100">
              <button
                @click="goToPage(tripsStore.meta.current_page - 1)"
                :disabled="tripsStore.meta.current_page <= 1"
                aria-label="หน้าก่อนหน้า"
                class="w-11 h-11 rounded-full flex items-center justify-center transition-colors"
                :class="tripsStore.meta.current_page <= 1 ? 'text-gray-300 cursor-not-allowed' : 'text-[var(--color-text-dark)] hover:bg-[var(--color-sand)] cursor-pointer'">
                <span class="material-symbols-rounded">chevron_left</span>
              </button>

              <div class="flex items-center gap-1 px-1">
                <template v-for="(page, i) in paginationPages" :key="`${page}-${i}`">
                  <span v-if="page === '...'" class="text-gray-400 font-bold px-1.5">···</span>
                  <button v-else
                    @click="goToPage(page)"
                    :aria-current="page === tripsStore.meta.current_page ? 'page' : undefined"
                    class="w-11 h-11 rounded-full flex items-center justify-center font-extrabold transition-colors duration-300 text-base cursor-pointer"
                    :class="page === tripsStore.meta.current_page
                      ? 'bg-[var(--color-accent)] text-white'
                      : 'text-[var(--color-text-dark)] hover:bg-[var(--color-sand)]'">
                    {{ page }}
                  </button>
                </template>
              </div>

              <button
                @click="goToPage(tripsStore.meta.current_page + 1)"
                :disabled="tripsStore.meta.current_page >= tripsStore.meta.last_page"
                aria-label="หน้าถัดไป"
                class="w-11 h-11 rounded-full flex items-center justify-center transition-colors"
                :class="tripsStore.meta.current_page >= tripsStore.meta.last_page ? 'text-gray-300 cursor-not-allowed' : 'text-[var(--color-text-dark)] hover:bg-[var(--color-sand)] cursor-pointer'">
                <span class="material-symbols-rounded">chevron_right</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════
         แผ่นตัวกรองบนมือถือ
    ══════════════════════════════════════════ -->
    <Teleport to="body">
      <!-- ไม่ผูกกับ lg:hidden เพราะถ้าย่อ/ขยายจอตอนแผ่นเปิดอยู่ แผ่นจะหายไป
           แต่ body ยังถูกล็อกไม่ให้เลื่อน ปล่อยให้ปิดเองได้ทุกขนาดจอ -->
      <div v-if="filtersOpen" class="fixed inset-0 z-50 flex items-end" role="dialog" aria-modal="true" aria-label="ตัวกรอง">
        <div class="absolute inset-0 bg-black/40" @click="filtersOpen = false"></div>
        <div class="relative w-full bg-white rounded-t-[1.75rem] max-h-[85vh] flex flex-col animate-sheet-up">
          <div class="flex items-center justify-between px-6 pt-5 pb-3 border-b border-gray-100 shrink-0">
            <h2 class="text-lg font-extrabold text-[var(--color-text-dark)]">ตัวกรอง</h2>
            <button type="button" @click="filtersOpen = false" aria-label="ปิด"
              class="w-9 h-9 rounded-full bg-[var(--color-sand)] flex items-center justify-center cursor-pointer">
              <span class="material-symbols-rounded text-[20px]">close</span>
            </button>
          </div>
          <div class="overflow-y-auto px-6 py-6">
            <TripFilters
              :categories="categories"
              :difficulties="difficulties"
              :durations="durations"
              :type="tripsStore.filters.type"
              :difficulty="tripsStore.filters.difficulty"
              :duration="activeDuration"
              :active-count="activeFilters.length"
              @pick-type="toggleType"
              @pick-difficulty="toggleDifficulty"
              @pick-duration="selectDuration"
              @clear="clearAndFetch" />
          </div>
          <div class="px-6 py-4 border-t border-gray-100 shrink-0">
            <button type="button" @click="filtersOpen = false"
              class="w-full bg-[var(--color-primary)] text-white py-4 rounded-full text-base font-extrabold cursor-pointer">
              ดู {{ resultCount.toLocaleString() }} ทริป
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import TripCard from '../components/TripCard.vue';
import TripFilters from '../components/TripFilters.vue';
import { useTripsStore } from '../stores/trips';
import { useCategoriesStore } from '../stores/categories';
import { licenceNo } from '../lib/licence';

const tripsStore = useTripsStore();
const categoriesStore = useCategoriesStore();
const route = useRoute();
const router = useRouter();

const filtersOpen = ref(false);
const resultsTop = ref(null);

/* ── ตัวเลือกคงที่ ────────────────────────────────────────── */

const destinationTabs = [
  { value: '', label: 'ทั้งหมด', icon: 'public' },
  { value: 'domestic', label: 'ในประเทศ', icon: 'landscape' },
  { value: 'international', label: 'ต่างประเทศ', icon: 'flight_takeoff' },
];

const difficulties = [
  { value: 'easy', label: 'ระดับเริ่มต้น' },
  { value: 'medium', label: 'ระดับปานกลาง' },
  { value: 'hard', label: 'ระดับท้าทาย' },
];

// ช่วงวันที่คนถามกันจริง ๆ ไม่ใช่ให้พิมพ์ตัวเลขเอง
const durations = [
  { key: '1', label: 'ไปเช้า–เย็นกลับ', min: 1, max: 1 },
  { key: '2-3', label: '2–3 วัน', min: 2, max: 3 },
  { key: '4-6', label: '4–6 วัน', min: 4, max: 6 },
  { key: '7', label: '7 วันขึ้นไป', min: 7, max: '' },
];

const categories = computed(() => categoriesStore.categories.map(c => ({
  value: c.slug,
  label: c.name,
})));

/* ── พาดหัวที่เปลี่ยนตามแท็บ ───────────────────────────────
   แต่ละปลายทางมีสิ่งที่ต้องรู้ก่อนต่างกัน (ต่างประเทศต้องมีพาสปอร์ต) จึงเขียน
   ไว้ตรงนี้เลย แทนที่จะให้ไปเจอตอนกดจองแล้วค่อยรู้ */
const heroCopy = computed(() => {
  const dest = tripsStore.filters.destination;
  if (dest === 'domestic') {
    return {
      eyebrow: 'ในประเทศ',
      title: 'ดอย ทะเล น้ำตก ทั่วไทย',
      subtitle: 'มีรถรับจากจุดนัดหมายในหลายภาค ทุกรอบบอกจุดขึ้นรถและเวลาออกไว้ล่วงหน้า',
    };
  }
  if (dest === 'international') {
    return {
      eyebrow: `ต่างประเทศ · ใบอนุญาต ${licenceNo()}`,
      title: 'ข้ามพรมแดนไปเดินเขากับเรา',
      subtitle: 'ทริปต่างประเทศต้องใช้พาสปอร์ตที่เหลืออายุอย่างน้อย 6 เดือนนับจากวันเดินทาง และนัดเจอกันที่สนามบินแทนจุดขึ้นรถ',
    };
  }
  return {
    eyebrow: 'ทริปทั้งหมด',
    title: 'ไปที่ไหนต่อดี',
    subtitle: 'ทริปทั้งหมดที่เปิดรับจองอยู่ตอนนี้ ทั้งในประเทศและต่างประเทศ เลือกปลายทางจากแถบด้านบน แล้วกรองต่อตามประเภทหรือระยะเวลาได้',
  };
});

/* ── จำนวนบนแท็บ มาจากข้อมูลจริง ไม่ใช่ผลลัพธ์หน้าที่กำลังดู ── */

function tabCount(value) {
  const facets = tripsStore.destinations;
  if (!facets) return null;
  if (value === 'domestic') return facets.domestic.count;
  if (value === 'international') return facets.international.count;
  return facets.total;
}

/* ── แถบปลายทางย่อย ─────────────────────────────────────── */

const regionIcons = {
  north: 'filter_hdr', northeast: 'grass', central: 'apartment',
  east: 'waves', west: 'forest', south: 'beach_access',
};

const subRail = computed(() => {
  const facets = tripsStore.destinations;
  if (!facets) return { items: [], label: '', active: '' };

  // แท็บ "ทั้งหมด" โชว์ประเทศ เพราะเป็นสิ่งที่เพิ่งมีและหาเจอยากที่สุด
  const showCountries = tripsStore.filters.destination !== 'domestic';

  if (showCountries) {
    return {
      label: tripsStore.filters.destination === 'international' ? 'เลือกประเทศ' : 'บินไปกับเรา',
      active: tripsStore.filters.country,
      items: facets.international.countries.map(c => ({
        key: c.code, label: c.name, flag: c.flag, count: c.count,
      })),
    };
  }

  return {
    label: 'เลือกภาค',
    active: tripsStore.filters.region,
    items: facets.domestic.regions.map(r => ({
      key: r.key, label: r.label, icon: regionIcons[r.key] || 'place', count: r.count,
    })),
  };
});

/* ── ตัวกรองที่เปิดอยู่ ───────────────────────────────────── */

const activeFilters = computed(() => {
  const chips = [];
  const f = tripsStore.filters;

  if (f.search) {
    chips.push({ key: 'search', label: `“${f.search}”`, clear: clearSearch });
  }
  if (f.country) {
    const country = tripsStore.destinations?.international.countries.find(c => c.code === f.country);
    chips.push({
      key: 'country',
      label: country ? `${country.flag} ${country.name}` : f.country,
      clear: () => { f.country = ''; applyFilters(); },
    });
  }
  if (f.region) {
    const region = tripsStore.destinations?.domestic.regions.find(r => r.key === f.region);
    chips.push({ key: 'region', label: region?.label || f.region, clear: () => { f.region = ''; applyFilters(); } });
  }
  if (f.type) {
    const cat = categories.value.find(c => c.value === f.type);
    chips.push({ key: 'type', label: cat?.label || f.type, clear: () => { f.type = ''; applyFilters(); } });
  }
  if (f.difficulty) {
    const diff = difficulties.find(d => d.value === f.difficulty);
    chips.push({ key: 'difficulty', label: diff?.label || f.difficulty, clear: () => { f.difficulty = ''; applyFilters(); } });
  }
  if (activeDuration.value) {
    const dur = durations.find(d => d.key === activeDuration.value);
    chips.push({ key: 'duration', label: dur.label, clear: () => selectDuration(dur.key) });
  }
  if (f.date) {
    chips.push({ key: 'date', label: `ออกวันที่ ${f.date}`, clear: () => { f.date = ''; applyFilters(); } });
  }

  return chips;
});

const activeDuration = computed(() => {
  const f = tripsStore.filters;
  if (!f.min_days && !f.max_days) return '';
  return durations.find(d => String(d.min) === String(f.min_days) && String(d.max) === String(f.max_days))?.key || '';
});

const resultCount = computed(() => tripsStore.meta?.total ?? tripsStore.trips.length);

const emptyHint = computed(() => {
  if (activeFilters.value.length > 1) return 'ลองเอาตัวกรองออกสักอย่างสองอย่าง แล้วดูใหม่อีกครั้ง';
  if (tripsStore.filters.search) return 'ลองค้นด้วยชื่อสถานที่หรือชื่อดอยแทนชื่อเต็มของทริป';
  return 'ปลายทางนี้ยังไม่มีรอบเปิดขายอยู่ตอนนี้ ลองดูปลายทางอื่นก่อนได้';
});

/* ── การกระทำ ───────────────────────────────────────────── */

// ค้นหาเป็นช่องพิมพ์ จึงหน่วงไว้ก่อนยิง ไม่งั้นยิงทุกตัวอักษร
const searchTerm = ref('');
let searchTimer = null;
watch(searchTerm, (value) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    if (tripsStore.filters.search === value) return;
    tripsStore.filters.search = value;
    applyFilters();
  }, 400);
});

// กดกากบาทคือสั่งชัดเจน ไม่ต้องรอหน่วงเหมือนตอนพิมพ์
function clearSearch() {
  clearTimeout(searchTimer);
  searchTerm.value = '';
  if (!tripsStore.filters.search) return;
  tripsStore.filters.search = '';
  applyFilters();
}

function applyFilters(page = 1) {
  syncUrl();
  return tripsStore.fetchTrips(page);
}

// เก็บตัวกรองไว้ใน URL เพื่อให้ส่งลิงก์ต่อได้และรีเฟรชแล้วไม่หาย ใช้ replace
// เพื่อไม่ให้ทุกครั้งที่ติ๊กตัวกรองกลายเป็นประวัติย้อนกลับหนึ่งขั้น
function syncUrl() {
  const f = tripsStore.filters;
  const query = {};
  for (const key of ['destination', 'region', 'country', 'type', 'difficulty', 'search', 'date', 'min_days', 'max_days']) {
    if (f[key]) query[key] = f[key];
  }
  if (f.sort && f.sort !== 'popular') query.sort = f.sort;
  router.replace({ query });
}

// แท็บปลายทางล้างตัวเลือกย่อยของอีกฝั่งเสมอ ไม่งั้นจะเหลือ "ในประเทศ + เนปาล"
// ค้างอยู่แล้วผลลัพธ์ว่างโดยไม่มีอะไรบอกว่าทำไม
function selectDestination(value) {
  if (tripsStore.filters.destination === value) return;
  tripsStore.filters.destination = value;
  tripsStore.filters.region = '';
  tripsStore.filters.country = '';
  applyFilters();
}

function selectSubDestination(key) {
  const f = tripsStore.filters;
  if (f.destination === 'domestic') {
    f.region = f.region === key ? '' : key;
  } else {
    f.country = f.country === key ? '' : key;
    // เลือกประเทศจากแท็บ "ทั้งหมด" = ตั้งใจดูทริปต่างประเทศ ย้ายแท็บให้ตรงกัน
    if (f.country) f.destination = 'international';
  }
  applyFilters();
}

function toggleType(value) {
  tripsStore.filters.type = tripsStore.filters.type === value ? '' : value;
  // ระดับความยากมีเฉพาะเดินป่า เปลี่ยนหมวดแล้วต้องไม่ค้างไว้
  if (tripsStore.filters.type !== 'trekking') tripsStore.filters.difficulty = '';
  applyFilters();
}

function toggleDifficulty(value) {
  tripsStore.filters.difficulty = tripsStore.filters.difficulty === value ? '' : value;
  applyFilters();
}

function selectDuration(key) {
  const f = tripsStore.filters;
  const next = durations.find(d => d.key === key);
  if (activeDuration.value === key) {
    f.min_days = '';
    f.max_days = '';
  } else {
    f.min_days = next.min;
    f.max_days = next.max;
  }
  applyFilters();
}

function clearAndFetch() {
  tripsStore.clearFilters();
  searchTerm.value = '';
  applyFilters();
}

function goToPage(page) {
  const meta = tripsStore.meta;
  if (!meta || page < 1 || page > meta.last_page || page === meta.current_page) return;
  tripsStore.fetchTrips(page).then(() => {
    resultsTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

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

/* ── เริ่มต้น ────────────────────────────────────────────── */

// แผ่นตัวกรองเปิดอยู่แล้วหน้าข้างหลังยังเลื่อนได้ = เลื่อนผิดชั้น ล็อกไว้ก่อน
watch(filtersOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : '';
});
onUnmounted(() => {
  clearTimeout(searchTimer);
  document.body.style.overflow = '';
});

onMounted(() => {
  categoriesStore.fetchCategories();
  tripsStore.fetchDestinations();

  const q = route.query;
  const f = tripsStore.filters;
  if (q.type) f.type = q.type;
  if (q.date) f.date = q.date;
  if (q.difficulty) f.difficulty = q.difficulty;
  if (q.search) f.search = q.search;
  if (q.region) f.region = q.region;
  if (q.country) f.country = String(q.country).toUpperCase();
  if (q.min_days) f.min_days = q.min_days;
  if (q.max_days) f.max_days = q.max_days;
  if (['price_asc', 'price_desc', 'popular'].includes(q.sort)) f.sort = q.sort;
  if (['domestic', 'international'].includes(q.destination)) f.destination = q.destination;

  searchTerm.value = f.search;
  tripsStore.fetchTrips();
});
</script>

<style scoped>
.hero-glow {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(60% 90% at 12% 0%, rgba(76, 175, 125, 0.35), transparent 60%),
    radial-gradient(50% 80% at 92% 110%, rgba(45, 122, 79, 0.45), transparent 65%);
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(24px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes sheetUp {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}

@keyframes shimmer {
  from { background-position: 200% 0; }
  to { background-position: -200% 0; }
}

.animate-fade-in-up {
  animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  opacity: 0;
}

.animate-sheet-up {
  animation: sheetUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.skeleton {
  background: linear-gradient(90deg, #f1f1f1 25%, #e6e6e6 37%, #f1f1f1 63%);
  background-size: 200% 100%;
  animation: shimmer 1.4s ease-in-out infinite;
}

/* แถบปลายทางเลื่อนแนวนอนได้ แต่ไม่ต้องมีสกรอลบาร์มาบังชิป */
.no-scrollbar {
  scrollbar-width: none;
}
.no-scrollbar::-webkit-scrollbar {
  display: none;
}

@media (prefers-reduced-motion: reduce) {
  .animate-fade-in-up,
  .animate-sheet-up,
  .skeleton {
    animation: none;
    opacity: 1;
  }
}
</style>
