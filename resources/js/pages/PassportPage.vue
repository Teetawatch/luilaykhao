<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">สมุดสะสมการเดินทาง</h1>
        <p class="text-[#505E5E] text-sm">
          ทุกทริปที่คุณเดินจบแล้ว รวมกันเป็นตัวเลขของคุณเอง
        </p>
      </section>

      <!-- Loading -->
      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm">กำลังโหลดข้อมูล...</p>
      </div>

      <template v-else>
        <!-- ยังไม่มีทริปที่จบ -->
        <div v-if="!stats?.trips_count" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
          <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">hiking</span>
          <p class="text-[#1a1c1c] font-bold mt-3 mb-1">สมุดยังว่างอยู่</p>
          <p class="text-[#505E5E] text-sm mb-5">
            พอคุณเดินจบทริปแรก ตัวเลขและตราสะสมจะเริ่มบันทึกลงที่นี่เอง
          </p>
          <router-link
            to="/trips"
            class="inline-flex items-center gap-1.5 rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3"
          >
            <span class="material-symbols-rounded text-[18px]">explore</span>
            ดูทริปที่เปิดอยู่
          </router-link>
        </div>

        <template v-else>
          <!-- สรุปตัวเลขหลัก -->
          <div class="rounded-[24px] overflow-hidden mb-6 bg-[#0F3D3E] text-white">
            <div class="p-6 sm:p-8">
              <p class="text-white/70 text-[13px] font-bold mb-5">สถิติตลอดชีพ</p>

              <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-6 gap-x-4">
                <div>
                  <p class="text-3xl font-extrabold tracking-tight">{{ stats.trips_count }}</p>
                  <p class="text-white/70 text-[13px] font-bold mt-0.5">ทริปที่จบแล้ว</p>
                </div>
                <div>
                  <p class="text-3xl font-extrabold tracking-tight">{{ fmt(stats.total_distance_km) }}</p>
                  <p class="text-white/70 text-[13px] font-bold mt-0.5">กม. สะสม</p>
                </div>
                <div>
                  <p class="text-3xl font-extrabold tracking-tight">{{ fmt(stats.total_elevation_gain_m) }}</p>
                  <p class="text-white/70 text-[13px] font-bold mt-0.5">เมตรที่ไต่ขึ้น</p>
                </div>
                <div>
                  <p class="text-3xl font-extrabold tracking-tight">{{ stats.regions_count }}</p>
                  <p class="text-white/70 text-[13px] font-bold mt-0.5">ภูมิภาคที่ไป</p>
                </div>
              </div>

              <!-- เทียบกับดอยอินทนนท์ให้เห็นภาพ -->
              <div v-if="highlights?.inthanon_multiple > 0" class="mt-6 bg-white/10 rounded-[16px] p-4">
                <p class="text-sm font-medium text-white/90">
                  ความสูงที่คุณไต่สะสม เท่ากับปีนดอยอินทนนท์
                  <span class="font-extrabold">{{ highlights.inthanon_multiple }} รอบ</span>
                </p>
              </div>
            </div>
          </div>

          <!-- สถิติจาก GPS ที่บันทึกเอง -->
          <section v-if="recorded?.tracks_count" class="mb-6 bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
            <div class="flex items-baseline justify-between mb-4">
              <h2 class="text-[13px] font-bold text-[#505E5E]">ที่คุณบันทึกด้วย GPS เอง</h2>
              <span class="text-[12px] font-bold text-[#8A9A9A]">{{ recorded.tracks_count }} ครั้ง</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-4 gap-x-4">
              <div>
                <p class="text-xl font-extrabold text-[#1a1c1c]">{{ fmt(recorded.distance_km) }}</p>
                <p class="text-[#505E5E] text-[12px] font-bold">กม. ที่เดินจริง</p>
              </div>
              <div>
                <p class="text-xl font-extrabold text-[#1a1c1c]">{{ fmt(recorded.elevation_gain_m) }}</p>
                <p class="text-[#505E5E] text-[12px] font-bold">เมตรที่ไต่จริง</p>
              </div>
              <div v-if="recorded.average_pace_kmh">
                <p class="text-xl font-extrabold text-[#1a1c1c]">{{ recorded.average_pace_kmh }}</p>
                <p class="text-[#505E5E] text-[12px] font-bold">กม./ชม. เฉลี่ย</p>
              </div>
              <div v-if="recorded.highest_point_m">
                <p class="text-xl font-extrabold text-[#1a1c1c]">{{ fmt(recorded.highest_point_m) }}</p>
                <p class="text-[#505E5E] text-[12px] font-bold">จุดสูงสุดที่เคยยืน (ม.)</p>
              </div>
            </div>
            <router-link to="/my-tracks" class="mt-4 inline-flex items-center gap-1 text-[13px] font-bold text-[#006565]">
              ดูบันทึกการเดินทั้งหมด
              <span class="material-symbols-rounded text-[16px]">chevron_right</span>
            </router-link>
          </section>

          <!-- แผนที่พิชิต -->
          <section v-if="pins.length" class="mb-6 bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden">
            <div class="p-5 sm:p-6 pb-4">
              <h2 class="text-[13px] font-bold text-[#505E5E] mb-1">แผนที่พิชิต</h2>
              <p class="text-sm text-[#1a1c1c] font-medium">
                {{ mapSummary.trips_visited }} ที่ที่คุณเคยไป จาก {{ mapSummary.departures_count }} ครั้งการเดินทาง
              </p>
            </div>
            <div ref="mapEl" class="h-[340px] w-full bg-[#EDF2F2]"></div>

            <!-- หมุดที่เลือก -->
            <div v-if="selectedPin" class="p-4 sm:p-5 border-t border-[#E8EEEF] flex gap-3">
              <img
                v-if="selectedPin.thumbnail"
                :src="selectedPin.thumbnail"
                :alt="selectedPin.title"
                class="w-16 h-16 rounded-[12px] object-cover shrink-0"
              />
              <div class="min-w-0">
                <p class="font-bold text-[#1a1c1c] text-sm truncate">{{ selectedPin.title }}</p>
                <p class="text-[12px] text-[#505E5E] mt-0.5">
                  ไปมา {{ selectedPin.visits }} ครั้ง · ครั้งแรก {{ selectedPin.first_visit_label }}
                </p>
                <p v-if="selectedPin.elevation_gain_m" class="text-[12px] text-[#505E5E]">
                  ไต่ {{ fmt(selectedPin.elevation_gain_m) }} ม. · {{ fmt(selectedPin.distance_km) }} กม.
                </p>
              </div>
            </div>
          </section>

          <!-- ความลึกรายภาค -->
          <section v-if="visitedRegions.length" class="mb-6 bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
            <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">ภาคที่คุณไปแล้ว</h2>
            <ul class="space-y-3">
              <li
                v-for="region in visitedRegions"
                :key="region.key"
                class="flex items-start justify-between gap-4 pb-3 border-b border-[#F0F4F4] last:border-0 last:pb-0"
              >
                <div class="min-w-0">
                  <p class="font-bold text-[#1a1c1c] text-sm">{{ region.label }}</p>
                  <p class="text-[12px] text-[#505E5E] mt-0.5">
                    {{ region.trips_count }} ที่ · {{ region.departures_count }} ครั้ง ·
                    {{ region.first_visit_label }} ถึง {{ region.last_visit_label }}
                  </p>
                  <p v-if="region.highest_trip" class="text-[12px] text-[#505E5E]">
                    หนักสุด: {{ region.highest_trip.title }}
                  </p>
                </div>
                <div class="text-right shrink-0">
                  <p class="text-sm font-extrabold text-[#1a1c1c]">{{ fmt(region.distance_km) }} กม.</p>
                  <p class="text-[12px] text-[#505E5E]">{{ fmt(region.elevation_gain_m) }} ม.</p>
                </div>
              </li>
            </ul>
          </section>

          <!-- ตราสะสม -->
          <section class="mb-6 bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
            <div class="flex items-baseline justify-between mb-4">
              <h2 class="text-[13px] font-bold text-[#505E5E]">ตราสะสม</h2>
              <span class="text-[12px] font-bold text-[#8A9A9A]">
                {{ badgesEarned }} / {{ badgesTotal }}
              </span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
              <div
                v-for="badge in badges"
                :key="badge.key"
                class="rounded-[14px] border p-3.5"
                :class="badge.earned
                  ? 'border-[#006565]/25 bg-[#006565]/[0.04]'
                  : 'border-[#EDF1F1] bg-[#FAFBFB]'"
              >
                <p class="text-[26px] leading-none mb-2" :class="badge.earned ? '' : 'grayscale opacity-40'">
                  {{ badge.emoji }}
                </p>
                <p class="text-[13px] font-bold" :class="badge.earned ? 'text-[#1a1c1c]' : 'text-[#8A9A9A]'">
                  {{ badge.title }}
                </p>
                <p class="text-[11px] text-[#8A9A9A] mt-0.5 leading-snug">{{ badge.description }}</p>

                <p v-if="badge.earned && badge.earned_at" class="text-[11px] font-bold text-[#006565] mt-1.5">
                  ปลดล็อก {{ thaiShort(badge.earned_at) }}
                </p>

                <!-- ความคืบหน้าของตราที่ยังไม่ได้ -->
                <div v-else-if="badge.progress" class="mt-2">
                  <div class="h-1.5 bg-[#E8EEEF] rounded-full overflow-hidden">
                    <div
                      class="h-full bg-[#B4C4C4] rounded-full"
                      :style="{ width: badgeProgress(badge) + '%' }"
                    ></div>
                  </div>
                  <p class="text-[11px] text-[#8A9A9A] mt-1">
                    {{ fmt(badge.progress.current) }} / {{ fmt(badge.progress.target) }}
                  </p>
                </div>
              </div>
            </div>
          </section>

          <!-- ภาคที่ยังไม่เคยไป -->
          <section v-if="frontier.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
            <h2 class="text-[13px] font-bold text-[#505E5E] mb-1">ยังไม่เคยไป</h2>
            <p class="text-[12px] text-[#8A9A9A] mb-4">เหลืออีก {{ frontier.length }} ภาคที่สมุดเล่มนี้ยังว่าง</p>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="region in frontier"
                :key="region.key"
                class="rounded-full border border-[#E8EEEF] bg-[#FAFBFB] px-3.5 py-1.5 text-[13px] font-bold text-[#505E5E]"
              >
                {{ region.label }}
                <span v-if="region.open_trips_count" class="text-[#8A9A9A] font-medium">
                  · {{ region.open_trips_count }} ทริป
                </span>
              </span>
            </div>
          </section>
        </template>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import api from '../lib/axios';
import { loadLeaflet } from '../lib/leaflet';
import { thaiShort } from '../lib/thaiDate';

const THAILAND_CENTER = [13.5, 100.9];
const THAILAND_ZOOM = 5;

const loading = ref(true);
const stats = ref(null);
const recorded = ref(null);
const highlights = ref(null);
const badges = ref([]);
const badgesEarned = ref(0);
const badgesTotal = ref(0);

const mapSummary = ref({});
const regions = ref([]);
const pins = ref([]);
const frontier = ref([]);
const selectedPin = ref(null);

const mapEl = ref(null);
let L = null;
let map = null;
let markerLayer = null;

const visitedRegions = computed(() => regions.value.filter(r => r.visited));

function fmt(value) {
  const n = Number(value) || 0;
  return n.toLocaleString('th-TH');
}

function badgeProgress(badge) {
  const { current, target } = badge.progress;
  if (!target) return 0;
  return Math.min(100, Math.round((current / target) * 100));
}

/** หมุดเป็นเลขจำนวนครั้งที่ไป — เห็นทั้งตำแหน่งและความผูกพันกับที่นั้นในตัวเดียว */
function visitMarker(pin, isSelected) {
  return L.divIcon({
    className: '',
    html: `<span class="conquest-pin${isSelected ? ' conquest-pin--on' : ''}">${pin.visits}</span>`,
    iconSize: null,
  });
}

function renderPins() {
  if (!map || !markerLayer) return;

  markerLayer.clearLayers();

  pins.value.forEach((pin) => {
    L.marker([pin.latitude, pin.longitude], { icon: visitMarker(pin, selectedPin.value?.trip_id === pin.trip_id) })
      .addTo(markerLayer)
      .on('click', () => { selectedPin.value = pin; });
  });

  const points = pins.value.map(p => [p.latitude, p.longitude]);
  if (points.length) {
    map.fitBounds(L.latLngBounds(points), { padding: [50, 50], maxZoom: 9 });
  } else {
    map.setView(THAILAND_CENTER, THAILAND_ZOOM);
  }
}

async function initMap() {
  if (!pins.value.length) return;

  await loadLeaflet();
  await nextTick();

  if (!mapEl.value || map) return;

  L = window.L;
  map = L.map(mapEl.value, { zoomControl: false, attributionControl: true, scrollWheelZoom: false })
    .setView(THAILAND_CENTER, THAILAND_ZOOM);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  L.control.zoom({ position: 'bottomright' }).addTo(map);
  markerLayer = L.layerGroup().addTo(map);
  map.on('click', () => { selectedPin.value = null; });

  renderPins();
}

async function load() {
  try {
    // แผนที่พิชิตคำนวณจากชุดข้อมูลเดียวกับ passport — ยิงคู่กันไปเลยไม่ต้องรอทีละอัน
    const [passportRes, mapRes] = await Promise.all([
      api.get('/me/passport'),
      api.get('/me/passport/map'),
    ]);

    const passport = passportRes.data?.data || {};
    stats.value = passport.stats || null;
    recorded.value = passport.recorded || null;
    highlights.value = passport.highlights || null;
    badges.value = passport.badges || [];
    badgesEarned.value = passport.badges_earned_count || 0;
    badgesTotal.value = passport.badges_total || 0;

    const conquest = mapRes.data?.data || {};
    mapSummary.value = conquest.summary || {};
    regions.value = conquest.regions || [];
    pins.value = conquest.pins || [];
    frontier.value = conquest.frontier || [];
  } finally {
    loading.value = false;
  }

  await initMap();
}

watch(selectedPin, renderPins);

onMounted(load);

onBeforeUnmount(() => {
  map?.remove();
  map = null;
});
</script>

<style>
/* หมุดแผนที่พิชิต — ไม่ scoped เพราะ Leaflet สร้าง DOM นอก component */
.conquest-pin {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  margin: -15px 0 0 -15px;
  border-radius: 999px;
  background: #006565;
  border: 2px solid #fff;
  color: #fff;
  font-size: 12px;
  font-weight: 800;
  line-height: 1;
}

.conquest-pin--on {
  background: #0f3d3e;
  transform: scale(1.2);
}
</style>
