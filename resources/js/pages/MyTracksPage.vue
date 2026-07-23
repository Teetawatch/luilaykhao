<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">บันทึกการเดินของฉัน</h1>
        <p class="text-[#505E5E] text-sm">
          ระยะและความสูงที่วัดจาก GPS ของคุณเอง ไม่ใช่ตัวเลขประมาณการของเส้นทาง
        </p>
      </section>

      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm">กำลังโหลดบันทึก...</p>
      </div>

      <div v-else-if="!tracks.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">route</span>
        <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ยังไม่มีบันทึกการเดิน</p>
        <p class="text-[#505E5E] text-sm">
          เปิดแอปลุยเลเขาแล้วกดบันทึกระหว่างเดิน เส้นทางกับสถิติจะมาโผล่ที่นี่
        </p>
      </div>

      <template v-else>
        <!-- สรุปรวม -->
        <div class="rounded-[20px] bg-[#0F3D3E] text-white p-5 sm:p-6 mb-5">
          <p class="text-white/70 text-[13px] font-bold mb-4">รวมทุกครั้งที่บันทึก</p>
          <div class="grid grid-cols-3 gap-4">
            <div>
              <p class="text-2xl font-extrabold">{{ tracks.length }}</p>
              <p class="text-white/70 text-[12px] font-bold">ครั้ง</p>
            </div>
            <div>
              <p class="text-2xl font-extrabold">{{ fmt(totalDistance) }}</p>
              <p class="text-white/70 text-[12px] font-bold">กม.</p>
            </div>
            <div>
              <p class="text-2xl font-extrabold">{{ fmt(totalElevation) }}</p>
              <p class="text-white/70 text-[12px] font-bold">ม. ที่ไต่</p>
            </div>
          </div>
        </div>

        <ul class="space-y-3">
          <li v-for="track in tracks" :key="track.id">
            <button
              type="button"
              class="w-full text-left bg-white rounded-[20px] border border-[#E8EEEF] p-4 sm:p-5"
              :class="{ 'border-[#006565]': openRef === track.booking_ref }"
              :disabled="!track.booking_ref"
              @click="toggle(track)"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-bold text-[#1a1c1c] text-sm truncate">{{ track.trip_title || 'บันทึกการเดิน' }}</p>
                  <p class="text-[12px] text-[#8A9A9A] mt-0.5">{{ track.started_at_label || '—' }}</p>
                </div>
                <span
                  v-if="track.booking_ref"
                  class="material-symbols-rounded text-[20px] text-[#B4C4C4] shrink-0 transition-transform"
                  :class="{ 'rotate-180': openRef === track.booking_ref }"
                >expand_more</span>
              </div>

              <div class="flex flex-wrap gap-x-5 gap-y-1 mt-3 text-[13px]">
                <span class="font-extrabold text-[#1a1c1c]">
                  {{ fmt(track.distance_km) }} <span class="font-medium text-[#8A9A9A]">กม.</span>
                </span>
                <span class="font-extrabold text-[#1a1c1c]">
                  {{ fmt(track.elevation_gain_m) }} <span class="font-medium text-[#8A9A9A]">ม. ไต่ขึ้น</span>
                </span>
                <span v-if="track.moving_seconds" class="font-extrabold text-[#1a1c1c]">
                  {{ duration(track.moving_seconds) }} <span class="font-medium text-[#8A9A9A]">เดินจริง</span>
                </span>
              </div>
            </button>

            <!-- รายละเอียด: เส้นทาง + กราฟความชัน -->
            <div v-if="openRef === track.booking_ref" class="mt-2 bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden">
              <div v-if="detailLoading" class="py-12 flex justify-center">
                <div class="w-8 h-8 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
              </div>

              <template v-else-if="detail">
                <div :ref="setMapEl" class="h-[260px] w-full bg-[#EDF2F2]"></div>

                <div class="p-5">
                  <!-- กราฟความชัน -->
                  <template v-if="profile.points.length > 1">
                    <h3 class="text-[13px] font-bold text-[#505E5E] mb-3">โปรไฟล์ความสูง</h3>
                    <svg :viewBox="`0 0 ${PROFILE_W} ${PROFILE_H}`" class="w-full h-32" preserveAspectRatio="none">
                      <polygon :points="profile.area" fill="#006565" fill-opacity="0.12" />
                      <polyline :points="profile.line" fill="none" stroke="#006565" stroke-width="2" vector-effect="non-scaling-stroke" />
                    </svg>
                    <div class="flex justify-between text-[11px] text-[#8A9A9A] mt-1">
                      <span>0 กม.</span>
                      <span>{{ fmt(detail.distance_km) }} กม.</span>
                    </div>
                    <div class="flex justify-between text-[11px] text-[#8A9A9A] mt-0.5">
                      <span>ต่ำสุด {{ fmt(profile.min) }} ม.</span>
                      <span>สูงสุด {{ fmt(profile.max) }} ม.</span>
                    </div>
                  </template>

                  <div class="grid grid-cols-2 gap-y-3 gap-x-4 mt-5">
                    <div v-if="detail.average_pace_kmh">
                      <p class="text-lg font-extrabold text-[#1a1c1c]">{{ detail.average_pace_kmh }}</p>
                      <p class="text-[12px] font-bold text-[#8A9A9A]">กม./ชม. ตอนกำลังเดิน</p>
                    </div>
                    <div v-if="detail.elevation_loss_m">
                      <p class="text-lg font-extrabold text-[#1a1c1c]">{{ fmt(detail.elevation_loss_m) }}</p>
                      <p class="text-[12px] font-bold text-[#8A9A9A]">ม. ที่ลงเขา</p>
                    </div>
                    <div v-if="detail.max_elevation_m">
                      <p class="text-lg font-extrabold text-[#1a1c1c]">{{ fmt(detail.max_elevation_m) }}</p>
                      <p class="text-[12px] font-bold text-[#8A9A9A]">ม. จุดสูงสุด</p>
                    </div>
                    <div v-if="detail.rank_by_distance && detail.peers_count > 1">
                      <p class="text-lg font-extrabold text-[#1a1c1c]">
                        {{ detail.rank_by_distance }} <span class="text-[13px] font-bold text-[#8A9A9A]">/ {{ detail.peers_count }}</span>
                      </p>
                      <p class="text-[12px] font-bold text-[#8A9A9A]">อันดับระยะทางในรอบนี้</p>
                    </div>
                  </div>
                </div>
              </template>

              <div v-else class="p-6 text-center text-sm text-[#8A9A9A]">
                โหลดรายละเอียดแทร็กไม่สำเร็จ
              </div>
            </div>
          </li>
        </ul>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../lib/axios';
import { loadLeaflet } from '../lib/leaflet';

const PROFILE_W = 600;
const PROFILE_H = 160;

const loading = ref(true);
const tracks = ref([]);

const openRef = ref(null);
const detail = ref(null);
const detailLoading = ref(false);

let map = null;

const totalDistance = computed(() => tracks.value.reduce((sum, t) => sum + (Number(t.distance_km) || 0), 0));
const totalElevation = computed(() => tracks.value.reduce((sum, t) => sum + (Number(t.elevation_gain_m) || 0), 0));

function fmt(value) {
  const n = Number(value) || 0;
  return Number.isInteger(n) ? n.toLocaleString('th-TH') : n.toFixed(1);
}

function duration(seconds) {
  const total = Number(seconds) || 0;
  const hours = Math.floor(total / 3600);
  const minutes = Math.round((total % 3600) / 60);
  return hours ? `${hours} ชม. ${minutes} นาที` : `${minutes} นาที`;
}

/**
 * กราฟความสูงจากลำดับจุด GPS — แกน X เป็นลำดับจุด (ไม่ใช่ระยะสะสม) เพราะจุดถูก
 * สุ่มเก็บถี่พอ ๆ กันตลอดเส้นทางอยู่แล้ว และทำให้ไม่ต้องคำนวณระยะซ้ำฝั่งเบราว์เซอร์
 */
const profile = computed(() => {
  const points = (detail.value?.points || [])
    .map(p => Number(p.ele ?? p.elevation))
    .filter(v => Number.isFinite(v));

  if (points.length < 2) return { points: [], line: '', area: '', min: 0, max: 0 };

  const min = Math.min(...points);
  const max = Math.max(...points);
  const span = max - min || 1;

  const coords = points.map((ele, i) => {
    const x = (i / (points.length - 1)) * PROFILE_W;
    const y = PROFILE_H - ((ele - min) / span) * (PROFILE_H - 8) - 4;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  });

  return {
    points,
    line: coords.join(' '),
    area: `0,${PROFILE_H} ${coords.join(' ')} ${PROFILE_W},${PROFILE_H}`,
    min: Math.round(min),
    max: Math.round(max),
  };
});

async function toggle(track) {
  if (!track.booking_ref) return;

  if (openRef.value === track.booking_ref) {
    openRef.value = null;
    detail.value = null;
    destroyMap();
    return;
  }

  destroyMap();
  openRef.value = track.booking_ref;
  detail.value = null;
  detailLoading.value = true;

  try {
    const res = await api.get(`/bookings/${track.booking_ref}/track`);
    detail.value = res.data?.data || null;
  } catch {
    detail.value = null;
  } finally {
    detailLoading.value = false;
  }
}

/**
 * แผนที่ถูกสร้างตอนแถวถูกกางออก จึงต้องรอ ref จริงจาก v-if แทนการ query ล่วงหน้า
 */
function setMapEl(el) {
  if (!el || map) return;
  drawRoute(el);
}

async function drawRoute(el) {
  const points = (detail.value?.points || [])
    .map(p => [Number(p.lat), Number(p.lng)])
    .filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng));

  if (points.length < 2) return;

  const L = await loadLeaflet();
  if (map) return;

  map = L.map(el, { zoomControl: false, attributionControl: true, scrollWheelZoom: false });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  L.polyline(points, { color: '#006565', weight: 4 }).addTo(map);
  L.circleMarker(points[0], { radius: 6, color: '#fff', weight: 2, fillColor: '#0F3D3E', fillOpacity: 1 }).addTo(map);
  L.circleMarker(points[points.length - 1], { radius: 6, color: '#fff', weight: 2, fillColor: '#B42318', fillOpacity: 1 }).addTo(map);

  map.fitBounds(L.latLngBounds(points), { padding: [30, 30] });
}

function destroyMap() {
  map?.remove();
  map = null;
}

onMounted(async () => {
  try {
    const res = await api.get('/me/tracks');
    tracks.value = res.data?.data || [];
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(destroyMap);
</script>
