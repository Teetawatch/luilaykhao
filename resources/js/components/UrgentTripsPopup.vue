<template>
  <Teleport to="body">
    <Transition name="urgent-pop">
      <div v-if="visible" class="fixed inset-0 z-[250] flex items-center justify-center p-4">
        <!-- backdrop -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="close"></div>

        <div class="relative w-full max-w-md bg-white rounded-[1.75rem] overflow-hidden">
          <!-- header -->
          <div class="relative bg-[var(--color-primary)] px-5 pt-5 pb-4">
            <div class="flex items-center gap-3 pr-8">
              <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shrink-0">
                <span class="material-symbols-rounded text-[24px] text-white">event_available</span>
              </div>
              <div class="flex-1 min-w-0">
                <h3 class="text-lg font-extrabold text-white leading-tight">{{ headline }}</h3>
                <p class="text-[12px] text-white/70 leading-snug mt-0.5">เผื่อคุณกำลังมองรอบเดินทางอยู่</p>
              </div>
            </div>
            <button @click="close" aria-label="ปิด"
              class="absolute top-3 right-3 w-8 h-8 rounded-full bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors">
              <span class="material-symbols-rounded text-[18px]">close</span>
            </button>
          </div>

          <!-- trips -->
          <div class="max-h-[55vh] overflow-y-auto p-3 space-y-2">
            <router-link v-for="item in items" :key="item.trip.id" :to="`/trips/${item.trip.slug}`" @click="goBook"
              class="flex items-center gap-3 p-2.5 rounded-2xl border border-gray-100 hover:border-orange-200 hover:bg-orange-50/50 transition-colors group">
              <div class="w-16 h-16 rounded-xl overflow-hidden bg-gray-100 shrink-0">
                <img v-if="item.trip.thumbnail_image || item.trip.cover_image"
                  :src="item.trip.thumbnail_image || item.trip.cover_image" :alt="item.trip.title"
                  class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-extrabold text-[var(--color-text-dark)] leading-snug line-clamp-1 mb-1">
                  {{ item.trip.title }}
                </p>
                <div class="flex items-center flex-wrap gap-1.5">
                  <!-- flash sale: discount + live countdown -->
                  <template v-if="item.kind === 'flash'">
                    <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full bg-red-500 text-white text-[10px] font-black">
                      <span class="material-symbols-rounded text-[12px]">bolt</span>
                      ลด {{ item.flash.discount_percent }}%
                    </span>
                    <span v-if="countdowns[item.trip.id]"
                      class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full bg-red-50 text-red-600 text-[10px] font-black font-anuphan tabular-nums">
                      <span class="material-symbols-rounded text-[12px]">timer</span>
                      {{ countdowns[item.trip.id] }}
                    </span>
                  </template>
                  <!-- almost full: seats left -->
                  <span v-else-if="scarcityLabel(item.trip)"
                    class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[10px] font-black"
                    :class="scarcityLevel(item.trip) === 'last' ? 'bg-red-500 text-white' : 'bg-amber-100 text-amber-800'">
                    <span class="material-symbols-rounded text-[12px]">airline_seat_recline_normal</span>
                    {{ scarcityLabel(item.trip) }}
                  </span>
                </div>
              </div>
              <div class="text-right shrink-0">
                <p v-if="item.kind === 'flash' && originalPrice(item.trip)"
                  class="text-[10px] text-gray-400 line-through leading-none">฿{{ formatPrice(originalPrice(item.trip)) }}</p>
                <p class="text-sm font-black" :class="item.kind === 'flash' ? 'text-red-600' : 'text-[var(--color-accent)]'">
                  ฿{{ formatPrice(item.trip.min_price) }}
                </p>
              </div>
            </router-link>
          </div>

          <!-- footer -->
          <div class="px-4 pb-4 pt-1">
            <router-link to="/trips" @click="goBook"
              class="flex items-center justify-center gap-2 w-full bg-[var(--color-primary)] text-white py-3 rounded-full text-sm font-extrabold hover:bg-[var(--color-accent)] transition-colors">
              ดูทริปทั้งหมด
              <span class="material-symbols-rounded text-[18px]">arrow_forward</span>
            </router-link>
            <button @click="dontShowToday"
              class="block w-full text-center text-[11px] text-gray-400 hover:text-gray-600 font-bold mt-2 transition-colors">
              ไม่ต้องแสดงอีก
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, reactive, watch, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { useTripsStore } from '../stores/trips';
import { tripScarcityLabel, tripScarcityLevel } from '../lib/scheduleHelpers';

const SNOOZE_KEY = 'llk_urgent_popup_snooze'; // localStorage — ISO date; ปิดไปจนถึงวันนั้น
const SESSION_KEY = 'llk_urgent_popup_shown'; // sessionStorage — once per tab session
const VIEWS_KEY = 'llk_urgent_popup_views'; // sessionStorage — นับหน้าที่ผู้ใช้เปิดในรอบนี้

/**
 * เด้ง popup ใส่คนที่เพิ่งเข้าเว็บวินาทีแรกคือสัญญาณ "เว็บกดดันให้ซื้อ" ที่แรงที่สุด
 * เลยรอจนผู้ใช้เปิดดูอย่างน้อย 3 หน้า (แสดงว่าสนใจจริง) แล้วค่อยเสนอ
 */
const MIN_PAGE_VIEWS = 3;
const SHOW_DELAY_MS = 8000;
/** กด "ไม่ต้องแสดงอีก" แล้วเงียบไป 7 วัน ไม่ใช่แค่วันเดียว */
const SNOOZE_DAYS = 7;
const MAX_ITEMS = 4;

const route = useRoute();
const tripsStore = useTripsStore();

const visible = ref(false);
const title = ref(null);
const items = ref([]); // [{ kind: 'flash'|'almost', trip, flash }]
const countdowns = reactive({}); // trip.id -> "1:23:45"
let showTimer = null;
let tickTimer = null;

const headline = computed(() => title.value || 'รอบเหล่านี้ใกล้เต็มแล้ว');

const scarcityLabel = tripScarcityLabel;
const scarcityLevel = tripScarcityLevel;

function safeGet(storage, key) {
  try { return storage.getItem(key); } catch { return null; }
}
function safeSet(storage, key, val) {
  try { storage.setItem(key, val); } catch { /* ignore */ }
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

/** วันที่ที่ snooze จะหมดอายุ (วันนี้ + SNOOZE_DAYS) */
function snoozeUntil() {
  const d = new Date();
  d.setDate(d.getDate() + SNOOZE_DAYS);
  return d.toISOString().slice(0, 10);
}

/** จำนวนหน้าที่ผู้ใช้เปิดในรอบนี้ — ใช้วัดว่าเขากำลังเลือกดูอยู่จริงไหม */
function pageViews() {
  return Number(safeGet(sessionStorage, VIEWS_KEY) || 0);
}

function formatPrice(n) {
  return Number(n || 0).toLocaleString('th-TH');
}

// The soonest-ending live flash-sale block among the trip's rounds.
function flashBlock(trip) {
  const sales = (trip.schedules || [])
    .map((s) => s.flash_sale)
    .filter((f) => f?.active && f?.ends_at)
    .sort((a, b) => new Date(a.ends_at) - new Date(b.ends_at));
  return sales[0] || null;
}

function originalPrice(trip) {
  const s = (trip.schedules || []).find((x) => x.flash_sale?.active && x.original_price);
  return s?.original_price || null;
}

function formatRemaining(ms) {
  if (ms <= 0) return null;
  const total = Math.floor(ms / 1000);
  const d = Math.floor(total / 86400);
  if (d >= 1) return `อีก ${d} วัน`;
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
}

function tick() {
  const now = Date.now();
  for (const item of items.value) {
    if (item.kind !== 'flash' || !item.flash?.ends_at) continue;
    const left = formatRemaining(new Date(item.flash.ends_at) - now);
    if (left) {
      countdowns[item.trip.id] = left;
    } else {
      delete countdowns[item.trip.id];
    }
  }
}

// Pages where an entry popup would interrupt an in-progress action.
function onQuietPage() {
  const p = route.path;
  return p.startsWith('/booking') || p.startsWith('/payment') || p.startsWith('/confirmation')
    || p === '/login' || p === '/register';
}

async function maybeShow() {
  const snoozedUntil = safeGet(localStorage, SNOOZE_KEY);
  if (snoozedUntil && snoozedUntil > today()) return;
  if (safeGet(sessionStorage, SESSION_KEY) === '1') return;
  if (onQuietPage()) return;
  // ยังดูไม่ถึง 3 หน้า = ยังไม่ได้สนใจจริง อย่าเพิ่งไปขัดจังหวะ
  if (pageViews() < MIN_PAGE_VIEWS) return;

  let data = null;
  try {
    data = await tripsStore.fetchUrgentPopup();
  } catch {
    return;
  }
  if (!data?.enabled) return;

  const flash = (data.flash_sale || []).map((trip) => ({ kind: 'flash', trip, flash: flashBlock(trip) }));
  const almost = (data.almost_full || []).map((trip) => ({ kind: 'almost', trip }));
  const combined = [...flash, ...almost].slice(0, MAX_ITEMS);
  if (!combined.length) return;

  title.value = data.title;
  items.value = combined;
  tick();
  tickTimer = setInterval(tick, 1000);
  visible.value = true;
  safeSet(sessionStorage, SESSION_KEY, '1');
}

function close() {
  visible.value = false;
  stopTick();
}
function goBook() {
  close();
}
function dontShowToday() {
  safeSet(localStorage, SNOOZE_KEY, snoozeUntil());
  close();
}
function stopTick() {
  if (tickTimer) {
    clearInterval(tickTimer);
    tickTimer = null;
  }
}

// นับทุกครั้งที่เปลี่ยนหน้า แล้วลองเสนออีกที — popup จะโผล่ตอนที่ผู้ใช้
// เลือกดูไปสักพักแล้วเท่านั้น ไม่ใช่ทันทีที่เปิดเว็บ
watch(
  () => route.path,
  () => {
    safeSet(sessionStorage, VIEWS_KEY, String(pageViews() + 1));
    if (visible.value || safeGet(sessionStorage, SESSION_KEY) === '1') return;
    if (showTimer) clearTimeout(showTimer);
    showTimer = setTimeout(maybeShow, SHOW_DELAY_MS);
  },
  { immediate: true }
);

onUnmounted(() => {
  if (showTimer) clearTimeout(showTimer);
  stopTick();
});
</script>

<style scoped>
.line-clamp-1 {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.urgent-pop-enter-active {
  transition: opacity 0.35s ease;
}
.urgent-pop-enter-active > div:last-child {
  transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}
.urgent-pop-leave-active {
  transition: opacity 0.2s ease;
}
.urgent-pop-enter-from,
.urgent-pop-leave-to {
  opacity: 0;
}
.urgent-pop-enter-from > div:last-child {
  transform: translateY(24px) scale(0.96);
}
</style>
