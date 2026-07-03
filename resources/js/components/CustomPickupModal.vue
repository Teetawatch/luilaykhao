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

        <!-- ค้นหาสถานที่ตามชื่อ (Nominatim / OpenStreetMap) -->
        <div class="relative">
          <div class="relative">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[20px]">search</span>
            <input v-model="searchQuery" @input="onSearchInput" @keyup.enter="runSearch" type="text"
              placeholder="ค้นหาสถานที่ เช่น เซ็นทรัลลาดพร้าว, ปั๊ม ปตท. ทางเข้าเขาใหญ่"
              class="w-full pl-10 pr-10 py-3 rounded-xl border border-gray-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20 outline-none text-sm font-medium" />
            <span v-if="searching" class="material-symbols-rounded animate-spin absolute right-3 top-1/2 -translate-y-1/2 text-teal-500 text-[20px]">progress_activity</span>
            <button v-else-if="searchQuery" type="button" @click="clearSearch"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
              <span class="material-symbols-rounded text-[20px]">close</span>
            </button>
          </div>
          <!-- ผลการค้นหา -->
          <ul v-if="searchResults.length"
            class="absolute z-[500] mt-1 w-full max-h-56 overflow-y-auto bg-white rounded-xl border border-gray-200 shadow-lg">
            <li v-for="(r, i) in searchResults" :key="i">
              <button type="button" @click="pickResult(r)"
                class="w-full text-left px-4 py-2.5 hover:bg-teal-50 flex items-start gap-2 border-b border-gray-50 last:border-0">
                <span class="material-symbols-rounded text-teal-600 text-[18px] mt-0.5 shrink-0">location_on</span>
                <span class="text-sm text-gray-700 leading-snug">{{ r.label }}</span>
              </button>
            </li>
          </ul>
          <p v-else-if="searchedEmpty" class="mt-1.5 text-xs text-gray-400 font-medium">ไม่พบสถานที่ตามคำค้นหา ลองพิมพ์ใหม่หรือปักหมุดบนแผนที่เอง</p>
        </div>

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

        <!-- พิกัด Lat/Long — แก้ไขเองได้ กด "ไป" เพื่อเลื่อนแผนที่ไปยังพิกัดนั้น -->
        <div>
          <label class="block text-sm font-bold text-gray-700 mb-1.5">พิกัด (ละติจูด, ลองจิจูด)</label>
          <div class="flex items-center gap-2">
            <input v-model="latInput" @keyup.enter="goToLatLng" @focus="latLngFocused = true" @blur="latLngFocused = false"
              type="text" inputmode="decimal" placeholder="ละติจูด เช่น 13.75630"
              class="min-w-0 flex-1 px-3 py-2.5 rounded-xl border border-gray-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20 outline-none text-sm font-medium tabular-nums" />
            <input v-model="lngInput" @keyup.enter="goToLatLng" @focus="latLngFocused = true" @blur="latLngFocused = false"
              type="text" inputmode="decimal" placeholder="ลองจิจูด เช่น 100.50180"
              class="min-w-0 flex-1 px-3 py-2.5 rounded-xl border border-gray-200 focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20 outline-none text-sm font-medium tabular-nums" />
            <button type="button" @click="goToLatLng" :disabled="!canGoLatLng"
              class="shrink-0 px-4 py-2.5 rounded-xl bg-teal-600 text-white text-sm font-bold hover:bg-teal-700 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
              ไป
            </button>
          </div>
          <p v-if="latLngError" class="mt-1.5 text-xs text-red-500 font-medium">{{ latLngError }}</p>
          <p v-else class="mt-1.5 text-xs text-gray-400 font-medium">วางพิกัดจาก Google Maps ได้ (เช่น 13.7563, 100.5018)</p>
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

// ค้นหาสถานที่ตามชื่อ
const searchQuery = ref('');
const searchResults = ref([]);
const searching = ref(false);
const searchedEmpty = ref(false);
let searchTimer = null;
let searchSeq = 0;

// ช่องกรอกพิกัดเอง (แยกจาก coords เพื่อให้พิมพ์/วางได้อิสระ)
const latInput = ref(props.initial?.lat != null ? Number(props.initial.lat).toFixed(6) : '');
const lngInput = ref(props.initial?.lng != null ? Number(props.initial.lng).toFixed(6) : '');
const latLngError = ref('');
const latLngFocused = ref(false);

let map = null;
let L = null;

const canConfirm = computed(() => coords.value && label.value.trim().length > 0);

// รองรับการวาง "lat, lng" รวมในช่องละติจูด (คัดลอกจาก Google Maps)
const parsedLatLng = computed(() => {
  let latRaw = String(latInput.value).trim();
  let lngRaw = String(lngInput.value).trim();
  if (latRaw.includes(',')) {
    const parts = latRaw.split(',');
    latRaw = parts[0].trim();
    if (!lngRaw) lngRaw = (parts[1] || '').trim();
  }
  const lat = Number(latRaw);
  const lng = Number(lngRaw);
  const valid = latRaw !== '' && lngRaw !== '' && Number.isFinite(lat) && Number.isFinite(lng)
    && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
  return { lat, lng, valid };
});

const canGoLatLng = computed(() => parsedLatLng.value.valid);

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
  // สะท้อนพิกัดกึ่งกลางลงช่องกรอก (เว้นตอนที่ผู้ใช้กำลังโฟกัสแก้ไขเอง)
  if (!latLngFocused.value) {
    latInput.value = c.lat.toFixed(6);
    lngInput.value = c.lng.toFixed(6);
    latLngError.value = '';
  }
}

// ── ค้นหาสถานที่ตามชื่อ (Nominatim / OpenStreetMap) ──
function onSearchInput() {
  searchedEmpty.value = false;
  clearTimeout(searchTimer);
  if (searchQuery.value.trim().length < 3) {
    searchResults.value = [];
    return;
  }
  searchTimer = setTimeout(runSearch, 600);
}

async function runSearch() {
  clearTimeout(searchTimer);
  const q = searchQuery.value.trim();
  if (q.length < 2) return;
  const seq = ++searchSeq;
  searching.value = true;
  searchedEmpty.value = false;
  try {
    const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=6&accept-language=th&countrycodes=th&q='
      + encodeURIComponent(q);
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    const data = await res.json();
    if (seq !== searchSeq) return; // ผลลัพธ์เก่า ทิ้งไป
    searchResults.value = (Array.isArray(data) ? data : []).map((r) => ({
      label: r.display_name,
      lat: parseFloat(r.lat),
      lng: parseFloat(r.lon),
    }));
    searchedEmpty.value = searchResults.value.length === 0;
  } catch (e) {
    if (seq === searchSeq) { searchResults.value = []; searchedEmpty.value = true; }
  } finally {
    if (seq === searchSeq) searching.value = false;
  }
}

function pickResult(r) {
  searchResults.value = [];
  searchedEmpty.value = false;
  searchQuery.value = r.label.split(',')[0];
  if (map) {
    map.setView([r.lat, r.lng], 16);
    syncCenter();
  }
  // เติมชื่อจุดรับให้อัตโนมัติหากยังว่าง
  if (!label.value.trim()) label.value = r.label.split(',')[0];
}

function clearSearch() {
  searchQuery.value = '';
  searchResults.value = [];
  searchedEmpty.value = false;
}

// ── ไปยังพิกัดที่กรอกเอง ──
function goToLatLng() {
  const { lat, lng, valid } = parsedLatLng.value;
  if (!valid) {
    latLngError.value = 'พิกัดไม่ถูกต้อง กรุณากรอกละติจูด (-90 ถึง 90) และลองจิจูด (-180 ถึง 180)';
    return;
  }
  latLngError.value = '';
  latInput.value = lat.toFixed(6);
  lngInput.value = lng.toFixed(6);
  if (map) {
    map.setView([lat, lng], 16);
    syncCenter();
  }
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
  clearTimeout(searchTimer);
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
