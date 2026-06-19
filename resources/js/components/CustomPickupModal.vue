<template>
  <div class="fixed inset-0 z-[1000] flex items-end sm:items-center justify-center">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="$emit('close')"></div>

    <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl overflow-hidden animate-in slide-in-from-bottom sm:zoom-in duration-300 max-h-[92vh] flex flex-col">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-teal-600">add_location_alt</span>
          <h3 class="text-lg font-bold text-gray-900">ปักหมุดจุดรับของคุณ</h3>
        </div>
        <button @click="$emit('close')" class="w-9 h-9 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500">
          <span class="material-symbols-rounded">close</span>
        </button>
      </div>

      <div class="overflow-y-auto px-6 py-4 flex flex-col gap-4">
        <p class="text-sm text-gray-500 leading-relaxed">
          เลื่อนแผนที่ให้หมุดอยู่ตรงจุดที่สะดวก ระบบจะส่งให้เจ้าหน้าที่ตรวจสอบว่าอยู่ในเส้นทางที่รับได้
          แล้ว<strong class="text-gray-700">แจ้งค่าบริการกลับไปยืนยันอีกครั้ง</strong>
        </p>

        <!-- Map — หมุดตรึงกลาง เลื่อนแผนที่เอา (แบบ LINE MAN) -->
        <div class="relative w-full h-64 rounded-2xl border border-gray-200 overflow-hidden bg-gray-50">
          <div ref="mapEl" class="absolute inset-0 z-0"></div>
          <!-- หมุดคงที่กลางจอ ปลายหมุดชี้จุดกึ่งกลาง -->
          <div class="pointer-events-none absolute left-1/2 top-1/2 z-[400] -translate-x-1/2 -translate-y-full">
            <span class="material-symbols-rounded text-teal-600 drop-shadow" style="font-size:42px; font-variation-settings:'FILL' 1">location_on</span>
          </div>
          <!-- ปุ่มหาตำแหน่งฉัน -->
          <button type="button" @click="goToMyLocation" :disabled="locating"
            class="absolute right-3 bottom-3 z-[401] w-11 h-11 rounded-full bg-white shadow-md flex items-center justify-center text-teal-600 hover:bg-gray-50 disabled:opacity-60 transition-all">
            <span v-if="locating" class="material-symbols-rounded animate-spin">progress_activity</span>
            <span v-else class="material-symbols-rounded">my_location</span>
          </button>
        </div>

        <div v-if="coords" class="flex items-center gap-2 text-xs text-gray-500 font-medium">
          <span class="material-symbols-rounded text-[16px] text-teal-600">my_location</span>
          {{ coords.lat.toFixed(5) }}, {{ coords.lng.toFixed(5) }}
        </div>

        <!-- Label -->
        <div>
          <label class="block text-sm font-bold text-gray-700 mb-1.5">ชื่อจุดรับ / จุดสังเกต <span class="text-red-500">*</span></label>
          <input v-model="label" type="text" maxlength="255"
            placeholder="เช่น ปั๊ม ปตท. ทางเข้าเขาใหญ่"
            class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20 outline-none text-sm font-medium" />
        </div>

        <!-- Note -->
        <div>
          <label class="block text-sm font-bold text-gray-700 mb-1.5">รายละเอียดเพิ่มเติม (ถ้ามี)</label>
          <textarea v-model="note" rows="2" maxlength="1000"
            placeholder="เช่น รอตรงร้านกาแฟหน้าปั๊ม"
            class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20 outline-none text-sm font-medium resize-none"></textarea>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-6 py-4 border-t border-gray-100 shrink-0 flex gap-3">
        <button @click="$emit('close')"
          class="flex-1 py-3 rounded-xl font-bold text-gray-500 hover:bg-gray-50 transition-all">
          ยกเลิก
        </button>
        <button @click="confirm" :disabled="!canConfirm"
          class="flex-1 py-3 rounded-xl font-bold text-white bg-teal-600 hover:bg-teal-700 transition-all disabled:opacity-40 disabled:cursor-not-allowed">
          ใช้จุดนี้
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';

const props = defineProps({
  // จุดเริ่มต้นของแผนที่ (ใช้จุดรับแรกของรอบ หากมี) มิฉะนั้น center ที่กรุงเทพฯ
  centerLat: { type: Number, default: 13.7563 },
  centerLng: { type: Number, default: 100.5018 },
  initial: { type: Object, default: null }, // { label, lat, lng, note }
});

const emit = defineEmits(['close', 'confirm']);

const mapEl = ref(null);
// จุดที่เลือก = จุดกึ่งกลางแผนที่เสมอ (หมุดตรึงกลาง เลื่อนแผนที่เอา)
const coords = ref(props.initial?.lat != null ? { lat: props.initial.lat, lng: props.initial.lng } : null);
const label = ref(props.initial?.label || '');
const note = ref(props.initial?.note || '');
const locating = ref(false);

let map = null;
let L = null;

const canConfirm = computed(() => coords.value && label.value.trim().length > 0);

function loadLeaflet() {
  if (window.L) { L = window.L; return Promise.resolve(); }
  return new Promise(resolve => {
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

function syncCenter() {
  const c = map.getCenter();
  coords.value = { lat: c.lat, lng: c.lng };
}

onMounted(async () => {
  await loadLeaflet();
  await nextTick();
  const start = coords.value || { lat: props.centerLat, lng: props.centerLng };
  map = L.map(mapEl.value, { zoomControl: true }).setView(
    [start.lat, start.lng],
    coords.value ? 15 : 11,
  );
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19,
  }).addTo(map);
  syncCenter();
  // อัปเดตพิกัดตามจุดกึ่งกลางขณะเลื่อนแผนที่
  map.on('move', syncCenter);
  map.on('moveend', syncCenter);
  // ให้แผนที่คำนวณขนาดใหม่หลัง modal เปิด (กัน tile โหลดครึ่งเดียว)
  setTimeout(() => { if (map) { map.invalidateSize(); syncCenter(); } }, 200);
});

onBeforeUnmount(() => {
  if (map) { map.remove(); map = null; }
});

// เลื่อนแผนที่ไปตำแหน่ง GPS ปัจจุบัน (ปุ่ม my-location แบบ LINE MAN/Grab)
function goToMyLocation() {
  if (locating.value || !map) return;
  if (!('geolocation' in navigator)) {
    alert('เบราว์เซอร์นี้ไม่รองรับการระบุตำแหน่ง');
    return;
  }
  locating.value = true;
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      map.setView([pos.coords.latitude, pos.coords.longitude], 16);
      syncCenter();
      locating.value = false;
    },
    () => {
      locating.value = false;
      alert('ไม่สามารถระบุตำแหน่งได้ กรุณาอนุญาตการเข้าถึงตำแหน่งและลองอีกครั้ง');
    },
    { enableHighAccuracy: true, timeout: 10000 },
  );
}

function confirm() {
  if (!canConfirm.value) return;
  emit('confirm', {
    label: label.value.trim(),
    lat: coords.value.lat,
    lng: coords.value.lng,
    note: note.value.trim() || null,
  });
}
</script>
