<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24">
    <PageHero
      image="/images/landscape.webp"
      title="สถานที่ธรรมชาติในไทย"
      subtitle="ความสูง ระยะเดิน ช่วงที่ควรไป และช่วงที่ปิดของแต่ละที่ อ่านได้ไม่ว่าตอนนี้จะมีรอบเปิดหรือไม่"
    >
      <template #actions>
        <router-link to="/seasons" class="hero-btn hero-btn--solid">
          เดือนนี้ไปไหนดี
          <span class="material-symbols-rounded icon-20">arrow_forward</span>
        </router-link>
        <router-link to="/difficulty" class="hero-btn hero-btn--outline">ระดับความยากคืออะไร</router-link>
      </template>
    </PageHero>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
      <!-- ตัวกรอง — ลอยทับขอบล่างของหัวหน้า -->
      <div class="filter-bar relative z-10 -mt-7">
        <div class="flex gap-2 overflow-x-auto no-scrollbar">
          <label v-for="f in FILTERS" :key="f.key" class="filter-pill" :class="{ 'is-set': isSet(f.key) }">
            <span class="material-symbols-rounded filter-pill__icon">{{ f.icon }}</span>
            <select v-model="filters[f.key]" :aria-label="f.label">
              <option :value="f.key === 'month' ? 0 : ''">{{ f.all }}</option>
              <option v-for="o in optionsFor(f.key)" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <span class="material-symbols-rounded filter-pill__chevron">expand_more</span>
          </label>
          <button v-if="hasFilters" type="button" class="filter-clear press" @click="reset">
            <span class="material-symbols-rounded icon-18">close</span>
            ล้าง
          </button>
        </div>
      </div>

      <p v-if="!loading" class="mt-8 mb-4 text-sm font-bold text-[var(--color-text-muted)]">
        <template v-if="places.length">พบ <span class="text-[var(--color-text-dark)]">{{ places.length }}</span> ที่</template>
      </p>
      <div v-else class="mt-8 mb-4 h-5"></div>

      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" aria-hidden="true">
        <div v-for="i in 6" :key="i" class="rounded-3xl bg-white border border-[var(--color-sand-dark)] overflow-hidden">
          <div class="aspect-[4/3] shimmer"></div>
          <div class="p-5 space-y-3">
            <div class="h-4 w-1/2 rounded shimmer"></div>
            <div class="h-3 w-3/4 rounded shimmer"></div>
          </div>
        </div>
      </div>

      <div v-else-if="!places.length" class="rounded-3xl bg-white border border-[var(--color-sand-dark)] px-6 py-16 text-center">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--color-accent-tint)] text-[var(--color-accent)]">
          <span class="material-symbols-rounded icon-32">travel_explore</span>
        </span>
        <p class="mt-4 text-lg font-extrabold text-[var(--color-text-dark)]">ไม่พบสถานที่ตามที่กรอง</p>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">ลองล้างตัวกรองแล้วดูใหม่</p>
        <button v-if="hasFilters" type="button" class="mt-5 inline-flex items-center gap-1.5 rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-bold text-white press" @click="reset">
          ล้างตัวกรอง
        </button>
      </div>

      <div v-else v-reveal.stagger class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <router-link
          v-for="place in places"
          :key="place.id"
          :to="`/places/${place.slug}`"
          class="place-card group"
        >
          <div class="zoom-media relative aspect-[4/3] bg-[var(--color-primary)]">
            <img v-if="place.cover_image" :src="place.cover_image" :alt="place.name" loading="lazy" class="h-full w-full object-cover" />
            <span v-else class="absolute inset-0 flex items-center justify-center text-white/20">
              <span class="material-symbols-rounded icon-64">landscape</span>
            </span>
            <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-transparent to-transparent"></div>
            <div class="absolute left-3 top-3 flex flex-wrap gap-1.5">
              <span v-if="place.type_label" class="place-chip">{{ place.type_label }}</span>
              <span v-if="isBestNow(place)" class="place-chip place-chip--now">
                <span class="material-symbols-rounded icon-14">wb_sunny</span>
                ช่วงพีคเดือนนี้
              </span>
              <span v-else-if="isClosedNow(place)" class="place-chip place-chip--closed">ปิดเดือนนี้</span>
            </div>
            <p class="absolute bottom-3 left-4 right-4 flex items-center gap-1 text-[13px] font-bold text-white/90">
              <span class="material-symbols-rounded icon-16">location_on</span>
              {{ [place.province, place.region_label].filter(Boolean).join(' · ') }}
            </p>
          </div>

          <div class="flex flex-1 flex-col p-5">
            <h2 class="text-lg font-extrabold leading-snug text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors">
              {{ place.name }}
            </h2>
            <p v-if="place.summary" class="mt-1.5 text-[14px] leading-relaxed text-[var(--color-text-mid)] line-clamp-2">
              {{ place.summary }}
            </p>

            <div v-if="facts(place).length" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-[13px] font-bold text-[var(--color-text-mid)]">
              <span v-for="fact in facts(place)" :key="fact.icon" class="inline-flex items-center gap-1">
                <span class="material-symbols-rounded icon-18 text-[var(--color-accent)]">{{ fact.icon }}</span>
                {{ fact.text }}
              </span>
            </div>

            <div class="mt-auto pt-5">
              <MonthStrip :best="place.best_months || []" :closed="place.closed_months || []" />
              <p class="mt-2 text-[12px] font-bold" :class="bestMonthsLabel(place) ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-muted)]'">
                {{ bestMonthsLabel(place) ? `ช่วงที่ควรไป ${bestMonthsLabel(place)}` : 'ไปได้ทั้งปี' }}
              </p>
            </div>
          </div>
        </router-link>
      </div>

      <GuideNextLinks current="/places" class="mt-20" />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, watch, ref } from 'vue';
import api from '../lib/axios';
import { THAI_MONTHS_LONG, THAI_MONTHS_SHORT } from '../lib/thaiDate';
import PageHero from '../components/PageHero.vue';
import MonthStrip from '../components/MonthStrip.vue';
import GuideNextLinks from '../components/GuideNextLinks.vue';

const loading = ref(true);
const places = ref([]);
const filterOptions = ref({ regions: [], types: [], difficulties: [] });

const filters = reactive({ region: '', type: '', difficulty: '', month: 0 });

const FILTERS = [
  { key: 'region', icon: 'map', label: 'ภูมิภาค', all: 'ทุกภูมิภาค' },
  { key: 'type', icon: 'landscape', label: 'ประเภท', all: 'ทุกประเภท' },
  { key: 'difficulty', icon: 'hiking', label: 'ระดับ', all: 'ทุกระดับ' },
  { key: 'month', icon: 'calendar_month', label: 'เดือน', all: 'ไปเดือนไหนก็ได้' },
];

function optionsFor(key) {
  const toOption = (o) => ({ value: o.key, label: o.label });
  if (key === 'region') return filterOptions.value.regions.map(toOption);
  if (key === 'type') return filterOptions.value.types.map(toOption);
  if (key === 'difficulty') return filterOptions.value.difficulties.map(toOption);
  return THAI_MONTHS_LONG.map((name, i) => ({ value: i + 1, label: `ไปเดือน${name}` }));
}

const isSet = (key) => (key === 'month' ? filters.month !== 0 : filters[key] !== '');

const hasFilters = computed(
  () => filters.region !== '' || filters.type !== '' || filters.difficulty !== '' || filters.month !== 0,
);

// เดือนปัจจุบันตามเวลาไทย — ใช้ติดป้าย "ช่วงพีคเดือนนี้" / "ปิดเดือนนี้" บนการ์ด
const nowMonth = Number(new Date().toLocaleDateString('en-US', { timeZone: 'Asia/Bangkok', month: 'numeric' }));
const isBestNow = (place) => (place.best_months || []).includes(nowMonth) && !(place.closed_months || []).includes(nowMonth);
const isClosedNow = (place) => (place.closed_months || []).includes(nowMonth);

function facts(place) {
  const out = [];
  if (place.elevation_m) out.push({ icon: 'altitude', text: `${place.elevation_m.toLocaleString('th-TH')} ม.` });
  if (place.trail_distance_km) out.push({ icon: 'route', text: `${place.trail_distance_km} กม.` });
  if (place.difficulty_label) out.push({ icon: 'hiking', text: place.difficulty_label });
  return out;
}

/** ย่อช่วงเดือนที่ควรไปให้อ่านเร็ว — ต่อเนื่องกันแสดงเป็นช่วง ไม่ต่อเนื่องคั่นด้วยจุลภาค */
function bestMonthsLabel(place) {
  const months = [...(place.best_months || [])].sort((a, b) => a - b);
  if (!months.length || months.length === 12) return '';

  const groups = [];
  let start = months[0];
  let prev = months[0];

  months.slice(1).forEach((m) => {
    if (m !== prev + 1) {
      groups.push([start, prev]);
      start = m;
    }
    prev = m;
  });
  groups.push([start, prev]);

  return groups
    .map(([from, to]) => (from === to
      ? THAI_MONTHS_SHORT[from - 1]
      : `${THAI_MONTHS_SHORT[from - 1]}–${THAI_MONTHS_SHORT[to - 1]}`))
    .join(', ');
}

function reset() {
  filters.region = '';
  filters.type = '';
  filters.difficulty = '';
  filters.month = 0;
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/places', {
      params: {
        region: filters.region || undefined,
        type: filters.type || undefined,
        difficulty: filters.difficulty || undefined,
        month: filters.month || undefined,
      },
    });
    const data = res.data?.data || {};
    places.value = data.places || [];
    if (data.filters) filterOptions.value = data.filters;
  } finally {
    loading.value = false;
  }
}

watch(filters, load);

onMounted(load);
</script>

<style scoped>
.filter-bar {
  padding: 0.5rem;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
}
.filter-pill {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  border-radius: 999px;
  background-color: var(--color-sand);
  transition: background-color 0.2s ease, color 0.2s ease;
}
.filter-pill:hover { background-color: var(--color-sand-dark); }
.filter-pill select {
  appearance: none;
  background: transparent;
  border: 0;
  padding: 0.7rem 2.25rem 0.7rem 2.5rem;
  font-size: 14px;
  font-weight: 700;
  color: var(--color-text-dark);
  cursor: pointer;
}
.filter-pill select:focus { outline: none; }
.filter-pill:focus-within { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 30%, transparent); }
.filter-pill .filter-pill__icon,
.filter-pill .filter-pill__chevron {
  position: absolute;
  pointer-events: none;
  font-size: 18px;
  color: var(--color-text-muted);
}
.filter-pill .filter-pill__icon { left: 0.9rem; }
.filter-pill .filter-pill__chevron { right: 0.7rem; }
.filter-pill.is-set { background-color: var(--color-accent-tint); }
.filter-pill.is-set select { color: var(--color-accent); }
.filter-pill.is-set .filter-pill__icon,
.filter-pill.is-set .filter-pill__chevron { color: var(--color-accent); }
.filter-clear {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.25rem;
  padding: 0 1rem;
  border-radius: 999px;
  font-size: 14px;
  font-weight: 700;
  color: var(--color-text-mid);
}
.filter-clear:hover { color: #B42318; }

.place-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  transition: border-color 0.25s ease;
}
.place-card:hover { border-color: color-mix(in srgb, var(--color-accent) 35%, transparent); }
.place-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.3rem 0.7rem;
  border-radius: 999px;
  background-color: rgb(255 255 255 / 0.9);
  font-size: 12px;
  font-weight: 800;
  color: var(--color-text-dark);
}
.place-chip--now { background-color: var(--color-accent); color: #fff; }
.place-chip--closed { background-color: #FEE4E2; color: #B42318; }

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
