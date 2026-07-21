<template>
  <div class="relative h-[calc(100vh-4rem)] w-full overflow-hidden bg-gray-100">

    <!-- แผนที่เต็มพื้นที่ ตัวกรองและการ์ดลอยทับอยู่ข้างบน -->
    <div ref="mapEl" class="absolute inset-0 z-0"></div>

    <!-- ตัวกรอง -->
    <div class="absolute top-0 inset-x-0 z-20 pointer-events-none">
      <div class="max-w-5xl mx-auto p-3 sm:p-4">
        <div class="pointer-events-auto bg-white/95 backdrop-blur rounded-2xl border border-gray-200 p-3">
          <div class="flex items-center justify-between gap-3 mb-3">
            <h1 class="text-base font-black text-[var(--color-text-dark)]">สำรวจทริปบนแผนที่</h1>
            <span class="text-xs font-bold text-[var(--color-text-muted)] shrink-0">
              {{ filteredTrips.length }} ทริป
            </span>
          </div>

          <div class="flex gap-2 overflow-x-auto scrollbar-none">
            <select v-model="filters.region" class="filter-select">
              <option value="">ทุกภูมิภาค</option>
              <option v-for="r in regionOptions" :key="r" :value="r">{{ regionLabel(r) }}</option>
            </select>

            <select v-model="filters.difficulty" class="filter-select">
              <option value="">ทุกความยาก</option>
              <option value="easy">ง่าย</option>
              <option value="medium">ปานกลาง</option>
              <option value="hard">ยาก</option>
            </select>

            <select v-model.number="filters.month" class="filter-select">
              <option :value="0">ทุกเดือน</option>
              <option v-for="(name, i) in monthNames" :key="i" :value="i + 1">{{ name }}</option>
            </select>

            <button
              v-if="hasFilters"
              type="button"
              @click="resetFilters"
              class="filter-select !text-[var(--color-primary)] font-bold shrink-0"
            >
              ล้าง
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- การ์ดทริปที่เลือก -->
    <div v-if="selected" class="absolute bottom-0 inset-x-0 z-20 p-3 sm:p-4">
      <div class="max-w-lg mx-auto bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <button
          type="button"
          @click="clearSelection"
          class="absolute top-5 right-5 sm:top-6 sm:right-6 z-10 w-8 h-8 rounded-full bg-white/90 border border-gray-200 flex items-center justify-center"
          aria-label="ปิด"
        >
          <span class="material-symbols-rounded text-[18px]">close</span>
        </button>

        <router-link :to="`/trips/${selected.slug}`" class="flex gap-3 p-3">
          <img
            v-if="selected.cover_image"
            :src="selected.cover_image"
            alt=""
            class="w-24 h-24 rounded-xl object-cover shrink-0 bg-gray-100"
          >
          <div class="min-w-0 flex-1">
            <h2 class="font-black text-[var(--color-text-dark)] leading-snug line-clamp-2">{{ selected.title }}</h2>
            <p class="text-sm text-[var(--color-text-muted)] mt-1 truncate">
              {{ selected.location }}<span v-if="selected.duration_days"> · {{ selected.duration_days }} วัน</span>
            </p>
            <p class="text-sm mt-1">
              <template v-if="selected.next_departure_label">
                <span class="font-bold text-[var(--color-primary)]">รอบถัดไป {{ selected.next_departure_label }}</span>
              </template>
              <template v-else>
                <span class="text-[var(--color-text-muted)]">ยังไม่เปิดรอบ</span>
              </template>
            </p>
            <p class="font-black text-[var(--color-text-dark)] mt-1">
              ฿{{ Number(selected.price_from).toLocaleString('th-TH') }}
              <span class="text-xs font-medium text-[var(--color-text-muted)]">/ คน</span>
            </p>
          </div>
        </router-link>
      </div>
    </div>

    <!-- สถานะกำลังโหลด / ไม่มีผลลัพธ์ -->
    <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-white/70">
      <p class="font-bold text-[var(--color-text-muted)]">กำลังโหลดแผนที่...</p>
    </div>
    <div
      v-else-if="!filteredTrips.length"
      class="absolute inset-x-0 top-1/2 z-10 -translate-y-1/2 px-6 text-center pointer-events-none"
    >
      <p class="inline-block bg-white/95 rounded-2xl border border-gray-200 px-5 py-4 font-bold text-[var(--color-text-muted)]">
        ไม่มีทริปที่ตรงกับตัวกรองนี้
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import api from '../lib/axios';

// กึ่งกลางประเทศไทย ใช้เป็นมุมกล้องตั้งต้นก่อนจะซูมให้พอดีกับหมุดที่มีจริง
const THAILAND_CENTER = [13.5, 100.9];
const THAILAND_ZOOM = 5;

const monthNames = [
  'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
  'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
];

const regionLabels = {
  north: 'ภาคเหนือ',
  northeast: 'ภาคอีสาน',
  central: 'ภาคกลาง',
  east: 'ภาคตะวันออก',
  west: 'ภาคตะวันตก',
  south: 'ภาคใต้',
};

const mapEl = ref(null);
const trips = ref([]);
const loading = ref(true);
const selected = ref(null);
const filters = ref({ region: '', difficulty: '', month: 0 });

let L = null;
let map = null;
let markerLayer = null;

const regionOptions = computed(() => {
  const seen = [...new Set(trips.value.map(t => t.region).filter(Boolean))];
  // เรียงตามลำดับที่คนไทยคุ้น (เหนือ→ใต้) แล้วค่อยต่อท้ายด้วยภาคที่ไม่รู้จัก
  const known = Object.keys(regionLabels).filter(r => seen.includes(r));
  return [...known, ...seen.filter(r => !(r in regionLabels))];
});

const hasFilters = computed(
  () => filters.value.region !== '' || filters.value.difficulty !== '' || filters.value.month !== 0,
);

const filteredTrips = computed(() => trips.value.filter((trip) => {
  if (filters.value.region && trip.region !== filters.value.region) return false;
  if (filters.value.difficulty && trip.difficulty !== filters.value.difficulty) return false;
  if (filters.value.month && !(trip.months || []).includes(filters.value.month)) return false;
  return true;
}));

function regionLabel(region) {
  return regionLabels[region] || region;
}

function resetFilters() {
  filters.value = { region: '', difficulty: '', month: 0 };
}

function clearSelection() {
  selected.value = null;
}

function loadLeaflet() {
  if (window.L) { L = window.L; return Promise.resolve(); }
  return new Promise((resolve) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(link);
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    script.onload = () => { L = window.L; resolve(); };
    document.head.appendChild(script);
  });
}

/** ป้ายราคาแทนหมุดกลม — บอกได้ทั้งตำแหน่งและราคาโดยไม่ต้องกดดู */
function priceMarker(trip, isSelected) {
  const price = Math.round(Number(trip.price_from) || 0).toLocaleString('th-TH');
  return L.divIcon({
    className: '',
    html: `<span class="map-pin${isSelected ? ' map-pin--on' : ''}">฿${price}</span>`,
    iconSize: null,
  });
}

function renderMarkers() {
  if (!map || !markerLayer) return;

  markerLayer.clearLayers();

  filteredTrips.value.forEach((trip) => {
    L.marker([trip.latitude, trip.longitude], { icon: priceMarker(trip, selected.value?.id === trip.id) })
      .addTo(markerLayer)
      .on('click', () => { selected.value = trip; });
  });

  fitToMarkers();
}

/** ซูมให้เห็นทุกหมุดที่ผ่านตัวกรอง — ไม่งั้นการกรองจะดูเหมือนหมุดหายไปเฉย ๆ */
function fitToMarkers() {
  const points = filteredTrips.value.map(t => [t.latitude, t.longitude]);

  if (!points.length) {
    map.setView(THAILAND_CENTER, THAILAND_ZOOM);
    return;
  }

  map.fitBounds(L.latLngBounds(points), { padding: [90, 90], maxZoom: 11 });
}

async function init() {
  await loadLeaflet();

  map = L.map(mapEl.value, { zoomControl: false, attributionControl: true })
    .setView(THAILAND_CENTER, THAILAND_ZOOM);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  L.control.zoom({ position: 'bottomright' }).addTo(map);

  markerLayer = L.layerGroup().addTo(map);

  // กดที่พื้นแผนที่ = ปิดการ์ด เหมือนแอปแผนที่ทั่วไป
  map.on('click', clearSelection);

  try {
    const res = await api.get('/trips/map');
    trips.value = res.data?.data || [];
  } finally {
    loading.value = false;
  }

  renderMarkers();
}

watch(filteredTrips, () => {
  // ทริปที่เลือกอยู่หลุดตัวกรองไปแล้ว ก็ไม่ควรค้างการ์ดไว้
  if (selected.value && !filteredTrips.value.some(t => t.id === selected.value.id)) {
    clearSelection();
  }
  renderMarkers();
});

watch(selected, renderMarkers);

onMounted(init);

onBeforeUnmount(() => {
  map?.remove();
  map = null;
});
</script>

<style scoped>
.filter-select {
  flex-shrink: 0;
  padding: 8px 16px;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  background: #fff;
  font-size: 14px;
  font-weight: 500;
  outline: none;
}

.scrollbar-none { scrollbar-width: none; }
.scrollbar-none::-webkit-scrollbar { display: none; }
</style>

<style>
/* หมุดถูกฉีดผ่าน divIcon ของ Leaflet จึงอยู่นอก scope ของคอมโพเนนต์ */
.map-pin {
  display: inline-block;
  transform: translate(-50%, -100%);
  background: #fff;
  border: 1px solid #d8dedc;
  color: #111827;
  font-weight: 800;
  font-size: 13px;
  padding: 5px 10px;
  border-radius: 999px;
  white-space: nowrap;
  cursor: pointer;
}

.map-pin--on {
  background: var(--color-primary, #087C68);
  border-color: var(--color-primary, #087C68);
  color: #fff;
}
</style>
