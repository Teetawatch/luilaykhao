<template>
  <div class="min-h-screen bg-[#F4F7F6] pb-32">

    <div v-if="loading" class="flex flex-col items-center justify-center py-32 space-y-4">
      <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      <p class="text-[#505E5E] font-medium animate-pulse text-sm">กำลังโหลดข้อมูลสถานที่...</p>
    </div>

    <div v-else-if="notFound" class="max-w-2xl mx-auto px-4 pt-16 text-center">
      <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">explore_off</span>
      <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ไม่พบสถานที่นี้</p>
      <router-link to="/places" class="inline-flex mt-4 rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3">
        ดูสถานที่ทั้งหมด
      </router-link>
    </div>

    <template v-else-if="place">
      <!-- ภาพปก -->
      <div class="w-full h-56 sm:h-80 bg-[#EDF1F1]">
        <img
          v-if="place.cover_image"
          :src="place.cover_image"
          :alt="place.name"
          class="w-full h-full object-cover"
        />
      </div>

      <div class="max-w-3xl mx-auto px-4 sm:px-6 -mt-10 relative">

        <!-- หัวเรื่อง -->
        <section class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <p class="text-[12px] font-bold text-[#8A9A9A] mb-1">{{ place.type_label }}</p>
          <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1a1c1c] leading-tight">{{ place.name }}</h1>
          <p class="text-[13px] text-[#505E5E] mt-1">
            {{ [place.park, place.province, place.region_label].filter(Boolean).join(' · ') }}
          </p>

          <p v-if="place.summary" class="text-sm text-[#1a1c1c] leading-relaxed mt-4">{{ place.summary }}</p>

          <!-- ตัวเลขสำคัญ -->
          <div v-if="hasFacts" class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-5 pt-5 border-t border-[#F0F4F4]">
            <div v-if="place.elevation_m">
              <p class="text-xl font-extrabold text-[#1a1c1c]">{{ place.elevation_m.toLocaleString('th-TH') }}</p>
              <p class="text-[12px] font-bold text-[#8A9A9A]">ม. เหนือระดับน้ำทะเล</p>
            </div>
            <div v-if="place.trail_distance_km">
              <p class="text-xl font-extrabold text-[#1a1c1c]">{{ place.trail_distance_km }}</p>
              <p class="text-[12px] font-bold text-[#8A9A9A]">กม. ระยะเดิน</p>
            </div>
            <div v-if="place.elevation_gain_m">
              <p class="text-xl font-extrabold text-[#1a1c1c]">{{ place.elevation_gain_m.toLocaleString('th-TH') }}</p>
              <p class="text-[12px] font-bold text-[#8A9A9A]">ม. ที่ต้องไต่</p>
            </div>
            <div v-if="place.difficulty_label">
              <p class="text-xl font-extrabold text-[#1a1c1c]">{{ place.difficulty_label }}</p>
              <router-link to="/difficulty" class="text-[12px] font-bold text-[#006565]">ระดับนี้คืออะไร</router-link>
            </div>
          </div>
        </section>

        <!-- ปฏิทินไปได้/ปิด -->
        <section class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">ไปเดือนไหนดี</h2>

          <div class="grid grid-cols-6 gap-1.5 mb-4">
            <div
              v-for="(name, i) in THAI_MONTHS_SHORT"
              :key="i"
              class="rounded-[10px] py-2 text-center text-[12px] font-bold border"
              :class="monthClass(i + 1)"
            >
              {{ name }}
            </div>
          </div>

          <div class="flex flex-wrap gap-x-4 gap-y-1.5 text-[12px] text-[#8A9A9A] mb-4">
            <span class="inline-flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-[4px] bg-[#006565]"></span> ควรไป
            </span>
            <span class="inline-flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-[4px] bg-[#FEE4E2] border border-[#FDA29B]"></span> ปิด
            </span>
            <span class="inline-flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-[4px] bg-white border border-[#E8EEEF]"></span> ไปได้แต่ไม่ใช่ช่วงพีค
            </span>
          </div>

          <p v-if="place.season_note" class="text-sm text-[#1a1c1c] leading-relaxed">{{ place.season_note }}</p>
          <p v-if="place.closure_note" class="text-sm text-[#B42318] leading-relaxed mt-3 font-medium">
            {{ place.closure_note }}
          </p>
        </section>

        <!-- เนื้อหา -->
        <section v-if="place.description" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-3">เกี่ยวกับที่นี่</h2>
          <p class="text-sm text-[#1a1c1c] leading-[1.9] whitespace-pre-line">{{ place.description }}</p>
        </section>

        <!-- ไฮไลต์ -->
        <section v-if="place.highlights?.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-3">ไฮไลต์</h2>
          <ul class="space-y-2.5">
            <li v-for="(item, i) in place.highlights" :key="i" class="flex gap-2.5 text-sm text-[#1a1c1c]">
              <span class="material-symbols-rounded text-[18px] text-[#006565] shrink-0 mt-0.5" style="font-variation-settings:'FILL' 1">check_circle</span>
              {{ item }}
            </li>
          </ul>
        </section>

        <!-- ต้องรู้ก่อนไป -->
        <section v-if="place.know_before?.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-3">ต้องรู้ก่อนไป</h2>
          <ul class="space-y-2.5">
            <li v-for="(item, i) in place.know_before" :key="i" class="flex gap-2.5 text-sm text-[#1a1c1c]">
              <span class="material-symbols-rounded text-[18px] text-[#8A9A9A] shrink-0 mt-0.5">info</span>
              {{ item }}
            </li>
          </ul>
        </section>

        <!-- แผนที่ -->
        <section v-if="place.latitude && place.longitude" class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden mb-5">
          <div class="p-5 sm:p-6 pb-3">
            <h2 class="text-[13px] font-bold text-[#505E5E]">อยู่ตรงไหน</h2>
          </div>
          <div ref="mapEl" class="h-[260px] w-full bg-[#EDF2F2]"></div>
        </section>

        <!-- อัลบั้ม -->
        <section v-if="place.gallery?.length" class="mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-3 px-1">ภาพจากที่นี่</h2>
          <div class="grid grid-cols-3 gap-1.5">
            <img
              v-for="(photo, i) in place.gallery"
              :key="i"
              :src="photo"
              :alt="place.name"
              loading="lazy"
              class="w-full aspect-square object-cover rounded-[12px]"
            />
          </div>
        </section>

        <!-- ทริปที่ไปที่นี่ — วางท้ายสุดโดยตั้งใจ หน้านี้เป็นหน้าข้อมูล ไม่ใช่หน้าขาย -->
        <section v-if="place.trips?.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-1">ถ้าอยากไปกับกลุ่ม</h2>
          <p class="text-[12px] text-[#8A9A9A] mb-4">ทริปของเราที่พาไปที่นี่</p>

          <ul class="space-y-3">
            <li v-for="trip in place.trips" :key="trip.id">
              <router-link :to="`/trips/${trip.slug}`" class="flex gap-3 items-center">
                <img
                  v-if="trip.cover_image"
                  :src="trip.cover_image"
                  :alt="trip.title"
                  class="w-16 h-16 rounded-[12px] object-cover shrink-0"
                />
                <div class="min-w-0 flex-1">
                  <p class="font-bold text-[#1a1c1c] text-sm truncate">{{ trip.title }}</p>
                  <p class="text-[12px] text-[#8A9A9A] mt-0.5">
                    <template v-if="trip.upcoming_count">
                      รอบถัดไป {{ trip.next_departure_label }} · อีก {{ trip.upcoming_count }} รอบ
                    </template>
                    <template v-else>ยังไม่มีรอบเปิด</template>
                  </p>
                </div>
                <span class="material-symbols-rounded text-[20px] text-[#B4C4C4] shrink-0">chevron_right</span>
              </router-link>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import { loadLeaflet } from '../lib/leaflet';
import { THAI_MONTHS_SHORT } from '../lib/thaiDate';

const route = useRoute();

const loading = ref(true);
const notFound = ref(false);
const place = ref(null);
const mapEl = ref(null);

let map = null;

const hasFacts = computed(() => {
  const p = place.value;
  return !!(p?.elevation_m || p?.trail_distance_km || p?.elevation_gain_m || p?.difficulty_label);
});

function monthClass(month) {
  const p = place.value;
  if (p?.closed_months?.includes(month)) return 'bg-[#FEE4E2] border-[#FDA29B] text-[#B42318]';
  if (p?.best_months?.includes(month)) return 'bg-[#006565] border-[#006565] text-white';
  return 'bg-white border-[#E8EEEF] text-[#8A9A9A]';
}

async function drawMap() {
  const p = place.value;
  if (!p?.latitude || !p?.longitude) return;

  const L = await loadLeaflet();
  await nextTick();

  if (!mapEl.value || map) return;

  map = L.map(mapEl.value, { zoomControl: false, attributionControl: true, scrollWheelZoom: false })
    .setView([p.latitude, p.longitude], 11);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  L.circleMarker([p.latitude, p.longitude], {
    radius: 9,
    color: '#fff',
    weight: 3,
    fillColor: '#006565',
    fillOpacity: 1,
  }).addTo(map);
}

function destroyMap() {
  map?.remove();
  map = null;
}

async function load(slug) {
  loading.value = true;
  notFound.value = false;
  place.value = null;
  destroyMap();

  try {
    const res = await api.get(`/places/${encodeURIComponent(slug)}`);
    place.value = res.data?.data;
    document.title = `${place.value.name} | ลุยเลเขา`;
  } catch (err) {
    if (err.response?.status === 404) notFound.value = true;
  } finally {
    loading.value = false;
  }

  await drawMap();
}

watch(() => route.params.slug, (slug) => { if (slug) load(slug); }, { immediate: true });

onBeforeUnmount(destroyMap);
</script>
