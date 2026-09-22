<!--
  แถบค้นหาบนฮีโร่หน้าแรก — เลือกทริป แล้วเลือกรอบวันเดินทาง

  เดิมเป็น <select> ของเบราว์เซอร์สองอัน ซึ่งบอกได้แค่ชื่อทริปกับวันที่:
  คนเลือกรอบโดยไม่รู้ว่าเหลือกี่ที่ ราคาเท่าไหร่ ตรงเสาร์-อาทิตย์หรือเปล่า
  จนกว่าจะกดเข้าไปในหน้าทริป ที่นี่จึงเปลี่ยนเป็นรายการของเราเอง:
  เดสก์ท็อปเป็น dropdown ใต้ช่อง มือถือเป็นชีตจากขอบล่าง (นิ้วโป้งถึง)
  พร้อมค้นหาด้วยชื่อ/จังหวัด และป้ายที่นั่งว่างกับราคาในแต่ละแถว

  แผงถูก Teleport ออกไปที่ body เสมอ เพราะ .hero-content มี animation ที่ทิ้ง
  transform ไว้ (สร้าง containing block ให้ position:fixed) และ section ฮีโร่
  เป็น overflow-hidden — ถ้าวางไว้ในนั้นแผงจะโดนตัดหรือเลื่อนตำแหน่งผิด
-->
<template>
  <div ref="rootEl" class="search-bar hs-root relative max-w-3xl mx-auto z-20">
    <div
      class="flex flex-col md:flex-row md:items-center gap-1.5 md:gap-0 bg-white rounded-[1.75rem] md:rounded-full p-1.5 border border-black/[0.06]"
    >
      <!-- ── ทริป ─────────────────────────────────────────────── -->
      <div class="relative flex-1 min-w-0">
        <button
          ref="tripFieldEl"
          type="button"
          class="hs-field"
          :class="open === 'trip' ? 'hs-field--active' : ''"
          :aria-expanded="open === 'trip'"
          aria-haspopup="listbox"
          @click="toggle('trip')"
          @keydown.down.prevent="openPanel('trip')"
        >
          <span class="hs-avatar hs-avatar--primary">
            <span class="material-symbols-rounded hs-ico">explore</span>
          </span>
          <span class="min-w-0 flex-1 text-left">
            <span class="hs-label">อยากไปเที่ยวที่ไหน?</span>
            <span class="hs-value" :class="selectedTrip ? 'text-gray-900' : 'text-gray-400'">
              {{ selectedTrip ? selectedTrip.title : 'เลือกทริปที่ต้องการ' }}
            </span>
          </span>
          <span class="material-symbols-rounded hs-ico hs-ico--sm hs-chevron" :class="{ 'hs-chevron--open': open === 'trip' }">
            expand_more
          </span>
        </button>
      </div>

      <div class="hidden md:block w-px h-9 bg-gray-200 shrink-0" aria-hidden="true"></div>

      <!-- ── รอบวันเดินทาง ────────────────────────────────────── -->
      <div class="relative flex-1 min-w-0">
        <button
          ref="scheduleFieldEl"
          type="button"
          class="hs-field"
          :class="open === 'schedule' ? 'hs-field--active' : ''"
          :aria-expanded="open === 'schedule'"
          aria-haspopup="listbox"
          @click="toggle('schedule')"
          @keydown.down.prevent="openPanel('schedule')"
        >
          <span class="hs-avatar hs-avatar--accent">
            <span v-if="schedulesLoading" class="hs-spinner" aria-hidden="true"></span>
            <span v-else class="material-symbols-rounded hs-ico">calendar_month</span>
          </span>
          <span class="min-w-0 flex-1 text-left">
            <span class="hs-label">รอบวันเดินทาง</span>
            <span class="hs-value" :class="selectedSchedule ? 'text-gray-900' : 'text-gray-400'">
              {{ scheduleFieldText }}
            </span>
          </span>
          <span class="material-symbols-rounded hs-ico hs-ico--sm hs-chevron" :class="{ 'hs-chevron--open': open === 'schedule' }">
            expand_more
          </span>
        </button>
      </div>

      <!-- ── ปุ่มค้นหา ────────────────────────────────────────── -->
      <button
        type="button"
        class="hs-cta"
        @click="goBook"
      >
        <span class="material-symbols-rounded hs-ico">search</span>
        <span>{{ selectedTrip ? 'ไปที่ทริปนี้' : 'ค้นหาทริป' }}</span>
      </button>
    </div>

    <Teleport to="body">
      <!-- ฉากหลังของชีตบนมือถือ — เดสก์ท็อปปิดด้วยการคลิกนอกแผงแทน -->
      <Transition name="hs-fade">
        <div v-if="open && isMobile" class="fixed inset-0 z-[68] bg-black/50" @click="close()"></div>
      </Transition>

      <Transition :name="isMobile ? 'hs-sheet' : 'hs-pop'">
        <div
          v-if="open"
          ref="panelEl"
          class="hs-panel"
          tabindex="-1"
          :class="isMobile ? 'hs-panel--sheet' : 'hs-panel--drop'"
          :style="isMobile ? null : panelStyle"
          role="dialog"
          :aria-label="open === 'trip' ? 'เลือกทริป' : 'เลือกรอบวันเดินทาง'"
          @keydown.esc.prevent="close(true)"
          @keydown.down.prevent="move(1)"
          @keydown.up.prevent="move(-1)"
          @keydown.enter="onEnter"
        >
          <!-- หัวชีต (เฉพาะมือถือ) -->
          <div v-if="isMobile" class="shrink-0 px-4 pt-3 pb-2 border-b border-gray-100">
            <div class="w-10 h-1 rounded-full bg-gray-200 mx-auto mb-3"></div>
            <div class="flex items-center gap-2">
              <h3 class="flex-1 text-[15px] font-extrabold text-gray-900">
                {{ open === 'trip' ? 'เลือกทริป' : 'เลือกรอบวันเดินทาง' }}
              </h3>
              <button
                type="button"
                class="w-9 h-9 rounded-full border border-gray-200 flex items-center justify-center text-gray-500 hover:bg-gray-50"
                aria-label="ปิด"
                @click="close(true)"
              >
                <span class="material-symbols-rounded hs-ico hs-ico--sm">close</span>
              </button>
            </div>
          </div>

          <!-- ── รายการทริป ─────────────────────────────────── -->
          <template v-if="open === 'trip'">
            <div class="shrink-0 p-2.5 border-b border-gray-100">
              <div class="flex items-center gap-2 px-3 py-2 rounded-full bg-gray-100/80 focus-within:bg-gray-100">
                <span class="material-symbols-rounded hs-ico hs-ico--sm text-gray-400">search</span>
                <input
                  ref="queryEl"
                  v-model="tripQuery"
                  type="text"
                  inputmode="search"
                  class="flex-1 min-w-0 bg-transparent border-none outline-none p-0 text-[14px] font-bold text-gray-900 placeholder:font-normal placeholder:text-gray-400"
                  placeholder="พิมพ์ชื่อทริป หรือจังหวัด"
                />
                <button
                  v-if="tripQuery"
                  type="button"
                  class="text-gray-400 hover:text-gray-600 flex items-center"
                  aria-label="ล้างคำค้นหา"
                  @click="tripQuery = ''"
                >
                  <span class="material-symbols-rounded hs-ico hs-ico--sm">cancel</span>
                </button>
              </div>
            </div>

            <ul ref="listEl" class="hs-list" role="listbox" aria-label="ทริปทั้งหมด">
              <li v-if="loading && !tripOptions.length" class="px-4 py-8 text-center text-sm text-gray-400">
                กำลังโหลดทริป...
              </li>
              <li v-else-if="!tripOptions.length" class="px-4 py-8 text-center text-sm text-gray-500">
                ไม่พบทริปที่ตรงกับ “{{ tripQuery }}”
              </li>
              <li
                v-for="(t, i) in tripOptions"
                :key="t.slug || '__all__'"
                data-opt
                role="option"
                :aria-selected="t.slug === selectedTripSlug"
                class="hs-opt"
                :class="{ 'hs-opt--active': i === activeIndex, 'hs-opt--chosen': t.slug === selectedTripSlug }"
                @mouseenter="activeIndex = i"
                @click="selectTrip(t)"
              >
                <img
                  v-if="t.slug && (t.thumbnail_image || t.cover_image)"
                  :src="t.thumbnail_image || t.cover_image"
                  :alt="t.title"
                  loading="lazy"
                  class="w-12 h-12 rounded-xl object-cover shrink-0 bg-gray-100"
                />
                <span v-else class="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center shrink-0 text-gray-400">
                  <span class="material-symbols-rounded hs-ico">{{ t.slug ? 'image' : 'grid_view' }}</span>
                </span>

                <span class="min-w-0 flex-1">
                  <span class="block text-[14px] font-extrabold text-gray-900 truncate">{{ t.title }}</span>
                  <span class="block text-[12px] text-gray-500 truncate">{{ tripSubtitle(t) }}</span>
                </span>

                <span v-if="tripPrice(t)" class="shrink-0 text-right">
                  <span class="block text-[10px] text-gray-400 leading-tight">เริ่มต้น</span>
                  <span class="block text-[13px] font-extrabold text-[var(--color-accent)] leading-tight">
                    ฿{{ tripPrice(t) }}
                  </span>
                </span>
                <span
                  v-else-if="t.slug === selectedTripSlug"
                  class="material-symbols-rounded hs-ico shrink-0 text-[var(--color-accent)]"
                >check_circle</span>
              </li>
            </ul>
          </template>

          <!-- ── รายการรอบเดินทาง ───────────────────────────── -->
          <template v-else>
            <div v-if="selectedTrip" class="shrink-0 px-4 py-2.5 border-b border-gray-100 flex items-center gap-2">
              <span class="material-symbols-rounded hs-ico hs-ico--sm text-gray-400">hiking</span>
              <span class="text-[13px] font-bold text-gray-700 truncate">{{ selectedTrip.title }}</span>
              <button
                type="button"
                class="ml-auto shrink-0 text-[12px] font-bold text-[var(--color-accent)] hover:underline"
                @click="openPanel('trip')"
              >
                เปลี่ยนทริป
              </button>
            </div>

            <ul ref="listEl" class="hs-list" role="listbox" aria-label="รอบวันเดินทาง">
              <li v-if="!selectedTrip" class="px-6 py-10 text-center">
                <span class="material-symbols-rounded hs-ico hs-ico--lg text-gray-300">explore</span>
                <p class="mt-2 text-sm text-gray-500">เลือกทริปก่อน แล้วรอบที่ยังว่างจะขึ้นตรงนี้</p>
                <button
                  type="button"
                  class="mt-3 px-4 py-2 rounded-full bg-[var(--color-accent)] text-white text-[13px] font-bold"
                  @click="openPanel('trip')"
                >
                  เลือกทริป
                </button>
              </li>

              <li v-else-if="schedulesLoading" class="p-2 space-y-2">
                <span v-for="n in 3" :key="n" class="block h-14 rounded-2xl bg-gray-100 animate-pulse"></span>
              </li>

              <li v-else-if="!schedules.length" class="px-6 py-10 text-center text-sm text-gray-500">
                รอบของทริปนี้เต็มหรือยังไม่เปิดขาย —
                <button type="button" class="font-bold text-[var(--color-accent)] hover:underline" @click="goBook">
                  ดูรายละเอียดทริป
                </button>
              </li>

              <template v-else>
                <li
                  v-for="(s, i) in schedules"
                  :key="s.id"
                  data-opt
                  role="option"
                  :aria-selected="s.id === selectedScheduleId"
                  class="hs-opt"
                  :class="{ 'hs-opt--active': i === activeIndex, 'hs-opt--chosen': s.id === selectedScheduleId }"
                  @mouseenter="activeIndex = i"
                  @click="selectSchedule(s)"
                >
                  <span class="w-11 shrink-0 text-center">
                    <span class="block text-[10px] font-bold text-gray-400 leading-tight">{{ weekdayRange(s) }}</span>
                    <span class="block text-[19px] font-extrabold text-gray-900 leading-tight">{{ dayNumber(s) }}</span>
                  </span>

                  <span class="min-w-0 flex-1">
                    <span class="block text-[14px] font-extrabold text-gray-900 truncate">{{ dateRangeLabel(s) }}</span>
                    <span class="flex items-center gap-1.5 text-[12px]">
                      <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="seatDotClass(s)"></span>
                      <span :class="seatTextClass(s)">{{ seatLabel(s) }}</span>
                    </span>
                  </span>

                  <span v-if="s.price" class="shrink-0 text-right">
                    <span class="block text-[10px] text-gray-400 leading-tight">ต่อท่าน</span>
                    <span class="block text-[13px] font-extrabold text-[var(--color-accent)] leading-tight">
                      ฿{{ money(s.price) }}
                    </span>
                  </span>
                </li>
              </template>
            </ul>
          </template>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../lib/axios';
import { THAI_MONTHS_SHORT } from '../lib/thaiDate';
import {
  bookableSeats,
  isScheduleBookable,
  scheduleAvailabilityLabel,
  scheduleAvailabilityDotClass,
  scheduleAvailabilityTextClass,
} from '../lib/scheduleHelpers';

const props = defineProps({
  /** ทริปทั้งหมดที่ขายอยู่ (โหลดมาแล้วจากหน้าแรก) */
  trips: { type: Array, default: () => [] },
  /** หน้าแรกยังโหลดข้อมูลอยู่ไหม — ใช้แยก "ยังไม่มา" ออกจาก "ไม่มี" */
  loading: { type: Boolean, default: false },
});

const router = useRouter();

const ALL_TRIPS = { slug: '', title: 'ทุกทริป', all: true };

const rootEl = ref(null);
const panelEl = ref(null);
const listEl = ref(null);
const queryEl = ref(null);
const tripFieldEl = ref(null);
const scheduleFieldEl = ref(null);

const open = ref(null); // 'trip' | 'schedule' | null
const activeIndex = ref(-1);
const tripQuery = ref('');
const selectedTripSlug = ref('');
const selectedScheduleId = ref('');
const schedules = ref([]);
const schedulesLoading = ref(false);
const isMobile = ref(false);
const panelStyle = ref({});

// ── ข้อมูลที่เลือกอยู่ ───────────────────────────────────────

const selectedTrip = computed(
  () => props.trips.find((t) => t.slug === selectedTripSlug.value) || null,
);

const selectedSchedule = computed(
  () => schedules.value.find((s) => s.id === selectedScheduleId.value) || null,
);

const tripOptions = computed(() => {
  const q = tripQuery.value.trim().toLowerCase();
  const list = q
    ? props.trips.filter((t) =>
        [t.title, t.location, t.region, t.country_label]
          .filter(Boolean)
          .some((v) => String(v).toLowerCase().includes(q)),
      )
    : props.trips;

  // แถว "ทุกทริป" มีไว้ให้ถอยกลับไปหน้ารวมทริป — ซ่อนตอนกำลังค้นหาอยู่
  return q || !selectedTripSlug.value ? [...list] : [ALL_TRIPS, ...list];
});

const options = computed(() => (open.value === 'trip' ? tripOptions.value : schedules.value));

const scheduleFieldText = computed(() => {
  if (selectedSchedule.value) return dateRangeLabel(selectedSchedule.value);
  if (!selectedTripSlug.value) return 'เลือกวันเดินทาง';
  if (schedulesLoading.value) return 'กำลังโหลดรอบ...';
  return schedules.value.length ? `${schedules.value.length} รอบที่ยังว่าง` : 'ยังไม่มีรอบว่าง';
});

// ── เปิด/ปิดแผง ─────────────────────────────────────────────

function openPanel(which) {
  open.value = which;
  activeIndex.value = currentIndex();
  nextTick(() => {
    syncPanelPosition();
    if (which === 'trip' && !isMobile.value) queryEl.value?.focus();
    else panelEl.value?.focus?.();
    scrollActiveIntoView();
  });
}

function close(returnFocus = false) {
  if (!open.value) return;
  const which = open.value;
  open.value = null;
  activeIndex.value = -1;
  if (returnFocus) {
    (which === 'trip' ? tripFieldEl.value : scheduleFieldEl.value)?.focus();
  }
}

function toggle(which) {
  if (open.value === which) close(true);
  else openPanel(which);
}

function currentIndex() {
  if (open.value === 'trip') {
    return tripOptions.value.findIndex((t) => t.slug === selectedTripSlug.value);
  }
  return schedules.value.findIndex((s) => s.id === selectedScheduleId.value);
}

function move(step) {
  const total = options.value.length;
  if (!total) return;
  activeIndex.value = (activeIndex.value + step + total) % total;
  scrollActiveIntoView();
}

function scrollActiveIntoView() {
  if (activeIndex.value < 0) return;
  const rows = listEl.value?.querySelectorAll('[data-opt]');
  rows?.[activeIndex.value]?.scrollIntoView({ block: 'nearest' });
}

/** Enter ในแผง = เลือกแถวที่ไฮไลต์อยู่ ยกเว้นตอนโฟกัสอยู่บนปุ่มจริง ๆ ในแผง */
function onEnter(e) {
  if (e.target instanceof HTMLButtonElement) return;
  e.preventDefault();
  chooseActive();
}

function chooseActive() {
  const opt = options.value[activeIndex.value] || (options.value.length === 1 ? options.value[0] : null);
  if (!opt) return;
  if (open.value === 'trip') selectTrip(opt);
  else selectSchedule(opt);
}

// ── เลือกทริป / รอบ ─────────────────────────────────────────

async function selectTrip(trip) {
  const slug = trip?.slug || '';
  const changed = slug !== selectedTripSlug.value;
  selectedTripSlug.value = slug;
  tripQuery.value = '';

  if (changed) {
    selectedScheduleId.value = '';
    schedules.value = [];
  }

  if (!slug) {
    close();
    return;
  }

  // เลือกทริปเสร็จก็ไปต่อที่วันเดินทางเลย ไม่ต้องกดเปิดเอง
  openPanel('schedule');
  if (changed) await loadSchedules(slug);
}

function selectSchedule(schedule) {
  selectedScheduleId.value = schedule.id;
  close();
}

async function loadSchedules(slug) {
  schedulesLoading.value = true;
  try {
    const res = await api.get(`/trips/${slug}/schedules`);
    const seen = new Set();
    schedules.value = (res.data.data || [])
      .filter((s) => isScheduleBookable(s))
      .filter((s) => {
        // รอบซ้ำวันเดียวกัน (คนละคัน) ขึ้นแถวเดียวพอ
        const key = `${s.departure_date}_${s.return_date}`;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });
  } catch {
    schedules.value = [];
  } finally {
    schedulesLoading.value = false;
    if (open.value === 'schedule') {
      activeIndex.value = -1;
      nextTick(syncPanelPosition);
    }
  }
}

function goBook() {
  close();
  if (!selectedTripSlug.value) {
    router.push('/trips');
    return;
  }
  router.push({
    path: `/trips/${selectedTripSlug.value}`,
    query: selectedScheduleId.value ? { schedule: selectedScheduleId.value } : {},
  });
}

// ── ข้อความในแต่ละแถว ───────────────────────────────────────

function money(value) {
  return Number(value || 0).toLocaleString('th-TH');
}

function tripSubtitle(trip) {
  if (trip.all) return 'ดูรอบเดินทางทั้งหมด';
  return [trip.location || trip.region, trip.duration_days ? `${trip.duration_days} วัน` : null]
    .filter(Boolean)
    .join(' · ');
}

function tripPrice(trip) {
  if (trip.all) return '';
  const value = trip.min_price || trip.price_per_person;
  return value ? money(value) : '';
}

/** '2026-09-05' → { d: 5, m: 9, y: 2026 } — แยกสตริงเอง ไม่ผ่าน Date เพื่อกันเพี้ยนข้ามโซนเวลา */
function parts(iso) {
  if (!iso) return null;
  const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
  return y && m && d ? { y, m, d } : null;
}

const WEEKDAYS = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

function weekday(iso) {
  const p = parts(iso);
  if (!p) return '';
  return WEEKDAYS[new Date(Date.UTC(p.y, p.m - 1, p.d)).getUTCDay()];
}

function weekdayRange(s) {
  const from = weekday(s.departure_date);
  const to = weekday(s.return_date);
  return to && to !== from ? `${from}–${to}` : from;
}

function dayNumber(s) {
  return parts(s.departure_date)?.d ?? '';
}

/** '5 – 7 ก.ย. 2569' / '28 ก.ย. – 2 ต.ค. 2569' / '5 ก.ย. 2569' */
function dateRangeLabel(s) {
  const from = parts(s.departure_date);
  if (!from) return '';
  const to = parts(s.return_date);
  const year = from.y + 543;
  const head = `${from.d} ${THAI_MONTHS_SHORT[from.m - 1]}`;

  if (!to || (to.d === from.d && to.m === from.m && to.y === from.y)) {
    return `${head} ${year}`;
  }
  if (to.m === from.m && to.y === from.y) {
    return `${from.d}–${to.d} ${THAI_MONTHS_SHORT[to.m - 1]} ${to.y + 543}`;
  }
  return `${head} – ${to.d} ${THAI_MONTHS_SHORT[to.m - 1]} ${to.y + 543}`;
}

function seatLabel(s) {
  return scheduleAvailabilityLabel(s);
}

function seatDotClass(s) {
  return scheduleAvailabilityDotClass(s);
}

function seatTextClass(s) {
  return bookableSeats(s) <= 3 ? 'font-bold text-red-500' : scheduleAvailabilityTextClass(s);
}

// ── ตำแหน่งแผงบนเดสก์ท็อป ───────────────────────────────────

function syncPanelPosition() {
  if (!open.value || isMobile.value) return;
  const anchor = open.value === 'trip' ? tripFieldEl.value : scheduleFieldEl.value;
  if (!anchor) return;

  const r = anchor.getBoundingClientRect();
  const gap = 10;
  const below = window.innerHeight - r.bottom - gap - 16;
  const above = r.top - gap - 16;
  const flip = below < 260 && above > below;
  const maxHeight = Math.round(Math.max(180, Math.min(400, flip ? above : below)));

  panelStyle.value = {
    left: `${Math.round(r.left)}px`,
    width: `${Math.round(r.width)}px`,
    maxHeight: `${maxHeight}px`,
    ...(flip
      ? { bottom: `${Math.round(window.innerHeight - r.top + gap)}px` }
      : { top: `${Math.round(r.bottom + gap)}px` }),
  };
}

function onPointerDown(e) {
  if (!open.value || isMobile.value) return;
  if (rootEl.value?.contains(e.target) || panelEl.value?.contains(e.target)) return;
  close();
}

let mq = null;

function onBreakpoint(e) {
  isMobile.value = e.matches;
  if (open.value) nextTick(syncPanelPosition);
}

onMounted(() => {
  mq = window.matchMedia('(max-width: 767px)');
  isMobile.value = mq.matches;
  mq.addEventListener('change', onBreakpoint);
  document.addEventListener('pointerdown', onPointerDown, true);
  window.addEventListener('scroll', syncPanelPosition, { capture: true, passive: true });
  window.addEventListener('resize', syncPanelPosition);
});

onBeforeUnmount(() => {
  mq?.removeEventListener('change', onBreakpoint);
  document.removeEventListener('pointerdown', onPointerDown, true);
  window.removeEventListener('scroll', syncPanelPosition, { capture: true });
  window.removeEventListener('resize', syncPanelPosition);
  document.body.style.overflow = '';
});

// ชีตบนมือถือกินเต็มจอ — ล็อกไม่ให้หน้าหลังเลื่อนตาม
watch([open, isMobile], ([which, mobile]) => {
  document.body.style.overflow = which && mobile ? 'hidden' : '';
});

watch(tripQuery, () => {
  if (open.value !== 'trip') return;
  // เหลือผลลัพธ์เดียว = กด Enter ได้เลยโดยไม่ต้องกดลูกศรลงก่อน
  activeIndex.value = tripOptions.value.length === 1 ? 0 : -1;
});
</script>

<style scoped>
/* .material-symbols-rounded ถูกตรึงไว้ที่ 24px แบบ unlayered ใน app.css —
   คลาส text-[Npx] ของ Tailwind เอาชนะไม่ได้ ต้องออกแรงด้วย selector สองชั้นแบบนี้ */
.hs-root .hs-ico,
.hs-panel .hs-ico {
  font-size: 21px;
}
.hs-root .hs-ico--sm,
.hs-panel .hs-ico--sm {
  font-size: 18px;
}
.hs-panel .hs-ico--lg {
  font-size: 40px;
}

/* ── ช่องเลือกบนแถบ ── */
.hs-field {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.6rem 0.9rem;
  border-radius: 1.25rem;
  background: rgb(249 250 251 / 0.8);
  cursor: pointer;
  transition: background-color 0.2s ease;
}
.hs-field:hover {
  background: rgb(243 244 246);
}
.hs-field--active {
  background: color-mix(in srgb, var(--color-accent) 10%, white);
}
.hs-field:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: -2px;
}

@media (min-width: 768px) {
  .hs-field {
    background: transparent;
    border-radius: 9999px;
    padding: 0.5rem 1rem;
  }
}

.hs-avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 9999px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.hs-avatar--primary {
  background: color-mix(in srgb, var(--color-primary) 10%, transparent);
  color: var(--color-primary);
}
.hs-avatar--accent {
  background: color-mix(in srgb, var(--color-accent) 12%, transparent);
  color: var(--color-accent);
}

.hs-label {
  display: block;
  font-size: 11px;
  font-weight: 700;
  color: rgb(107 114 128);
  line-height: 1.2;
}
.hs-value {
  display: block;
  font-size: 15px;
  font-weight: 800;
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hs-chevron {
  flex-shrink: 0;
  color: rgb(156 163 175);
  transition: transform 0.2s ease;
}
.hs-chevron--open {
  transform: rotate(180deg);
}

.hs-spinner {
  width: 1.15rem;
  height: 1.15rem;
  border-radius: 9999px;
  border: 2px solid color-mix(in srgb, var(--color-accent) 30%, transparent);
  border-top-color: var(--color-accent);
  animation: hs-spin 0.8s linear infinite;
}
@keyframes hs-spin {
  to { transform: rotate(360deg); }
}

/* ── ปุ่มค้นหา ── */
.hs-cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  flex-shrink: 0;
  padding: 0.9rem 1.5rem;
  border-radius: 1.25rem;
  background: var(--color-primary);
  color: #fff;
  font-weight: 700;
  font-size: 1.0625rem;
  cursor: pointer;
  transition: background-color 0.25s ease;
}
.hs-cta:hover {
  background: var(--color-accent);
}
@media (min-width: 768px) {
  .hs-cta {
    align-self: stretch;
    border-radius: 9999px;
    padding: 0.85rem 1.4rem;
    font-size: 0.9375rem;
  }
}

/* ── แผงผลลัพธ์ ── */
.hs-panel {
  position: fixed;
  z-index: 70;
  display: flex;
  flex-direction: column;
  background: #fff;
  overflow: hidden;
  font-family: var(--font-anuphan);
}
.hs-panel:focus {
  outline: none;
}
.hs-panel--drop {
  border-radius: 1.5rem;
  border: 1px solid rgb(0 0 0 / 0.08);
}
.hs-panel--sheet {
  left: 0;
  right: 0;
  bottom: 0;
  max-height: 82svh;
  border-radius: 1.5rem 1.5rem 0 0;
  padding-bottom: env(safe-area-inset-bottom);
}

.hs-list {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  padding: 0.375rem;
}

.hs-opt {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 0.625rem;
  border-radius: 1rem;
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.hs-opt--active {
  background: rgb(243 244 246);
}
.hs-opt--chosen {
  background: color-mix(in srgb, var(--color-accent) 10%, white);
}

/* ── การเข้า-ออกของแผง ── */
.hs-pop-enter-active,
.hs-pop-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.hs-pop-enter-from,
.hs-pop-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

.hs-sheet-enter-active,
.hs-sheet-leave-active {
  transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}
.hs-sheet-enter-from,
.hs-sheet-leave-to {
  transform: translateY(100%);
}

.hs-fade-enter-active,
.hs-fade-leave-active {
  transition: opacity 0.25s ease;
}
.hs-fade-enter-from,
.hs-fade-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .hs-pop-enter-active,
  .hs-pop-leave-active,
  .hs-sheet-enter-active,
  .hs-sheet-leave-active,
  .hs-fade-enter-active,
  .hs-fade-leave-active,
  .hs-chevron {
    transition: none !important;
  }
  .hs-spinner {
    animation: none !important;
  }
}
</style>
