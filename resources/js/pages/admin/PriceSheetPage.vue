<template>
  <div class="admin-page price-sheet-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">sell</span>
          ราคาทริป
        </h1>
        <p class="page-subtitle">
          ช่วงนี้มีทริปไหน รอบไหน ราคาเท่าไร รวมไว้ที่เดียว — ก๊อปข้อความไปทำรูปโปรโมทได้เลย
        </p>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" type="button" :disabled="loading" @click="load()">
          <span class="material-symbols-rounded" :class="{ spin: loading }">refresh</span>
          รีเฟรช
        </button>
      </div>
    </div>

    <!-- ช่วงเวลา: ปุ่มลัดที่ทีมงานใช้จริง + เลือกวันเองเมื่อไม่ตรงกับปุ่มไหน -->
    <div class="range-bar">
      <div class="preset-row">
        <button
          v-for="p in presets"
          :key="p.id"
          type="button"
          class="preset"
          :class="{ active: activePresetId === p.id }"
          @click="applyPreset(p.id)"
        >{{ p.label }}</button>
      </div>

      <div class="range-right">
        <div class="range-nav">
          <button class="btn-icon" type="button" :title="shiftTitle(-1)" @click="shiftRange(-1)">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <input type="date" v-model="from" class="date-input" aria-label="วันเริ่มต้น" />
          <span class="range-dash">–</span>
          <input type="date" v-model="to" class="date-input" aria-label="วันสิ้นสุด" />
          <button class="btn-icon" type="button" :title="shiftTitle(1)" @click="shiftRange(1)">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
        <span class="range-label">{{ sheet.range_label || '—' }}<em v-if="rangeDays">· {{ rangeDays }} วัน</em></span>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">hiking</span>
        <div>
          <span class="stat-label">ทริปที่เข้าเงื่อนไข</span>
          <strong class="stat-value">{{ visibleTrips.length }}</strong>
          <span class="stat-sub">{{ sheet.range_label || '—' }}</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">event</span>
        <div>
          <span class="stat-label">รอบเดินทาง</span>
          <strong class="stat-value">{{ visibleScheduleCount }}</strong>
          <span class="stat-sub">จากทั้งช่วง {{ summary.schedule_count || 0 }} รอบ</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">airline_seat_recline_normal</span>
        <div>
          <span class="stat-label">ที่นั่งว่างรวม</span>
          <strong class="stat-value">{{ visibleSeats }}</strong>
          <span class="stat-sub">เฉพาะรอบที่แสดงอยู่</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">payments</span>
        <div>
          <span class="stat-label">ช่วงราคา</span>
          <strong class="stat-value">{{ priceRangeLabel }}</strong>
          <span class="stat-sub">ราคาที่ขายอยู่จริงในช่วงนี้</span>
        </div>
      </div>
    </div>

    <div class="sheet-layout">
      <div class="sheet-main">
        <!-- ตัวกรอง: ตัดสินว่ารอบไหนเข้ารูปโปรโมท -->
        <div class="options-bar">
          <div class="opt-group">
            <span class="opt-group-label">ที่ว่าง</span>
            <select v-model.number="filters.minSeats" class="opt-select">
              <option v-for="s in seatFilters" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div class="opt-group">
            <span class="opt-group-label">ปลายทาง</span>
            <select v-model="filters.destination" class="opt-select">
              <option value="all">ทั้งหมด</option>
              <option value="domestic">ในประเทศ</option>
              <option value="international">ต่างประเทศ</option>
            </select>
          </div>
          <div class="opt-group">
            <span class="opt-group-label">เรียงตาม</span>
            <select v-model="filters.sort" class="opt-select">
              <option value="date">วันเดินทาง</option>
              <option value="price">ราคาถูกไปแพง</option>
              <option value="seats">ที่ว่างมากไปน้อย</option>
              <option value="title">ชื่อทริป</option>
            </select>
          </div>
          <label class="opt-check">
            <input type="checkbox" v-model="filters.openOnly" />
            <span>เฉพาะรอบที่เปิดรับจอง</span>
          </label>
          <label class="opt-check">
            <input type="checkbox" v-model="filters.hideDeparted" />
            <span>ซ่อนรอบที่ผ่านไปแล้ว</span>
          </label>
          <div class="opt-search">
            <span class="material-symbols-rounded">search</span>
            <input v-model="filters.search" type="search" placeholder="ค้นหาชื่อทริป / จังหวัด" />
          </div>
        </div>

        <div v-if="loading" class="loading-state"><div class="spinner"></div></div>
        <div v-else-if="!visibleTrips.length" class="empty-card">
          <span class="material-symbols-rounded">event_busy</span>
          <p v-if="summary.schedule_count">
            ช่วงนี้มี {{ summary.schedule_count }} รอบ แต่ไม่มีรอบไหนผ่านตัวกรองที่ตั้งไว้
            <button class="link-btn" type="button" @click="resetFilters">ล้างตัวกรอง</button>
          </p>
          <p v-else>ยังไม่มีรอบเดินทางในช่วง {{ sheet.range_label || '—' }}</p>
        </div>

        <div v-else class="trip-list">
          <article v-for="trip in visibleTrips" :key="trip.trip_id" class="trip-block">
            <header class="trip-block-head">
              <div>
                <h2 class="trip-block-title">{{ trip.title }}</h2>
                <p class="trip-block-meta">
                  <span v-if="trip.location">{{ trip.location }}</span>
                  <span v-if="trip.duration_days">{{ trip.duration_days }} วัน</span>
                  <span>{{ trip.schedules.length }} รอบ</span>
                  <span class="meta-seats">ว่าง {{ tripSeats(trip) }} ที่</span>
                </p>
              </div>
              <strong class="trip-block-price">{{ tripPriceLabel(trip) }}</strong>
            </header>

            <div class="round-rows">
              <div v-for="s in trip.schedules" :key="s.id" class="round-row">
                <div class="round-date">
                  <strong>{{ s.date_label }}</strong>
                  <span class="round-full">{{ s.date_full }}</span>
                </div>
                <div class="round-prices">
                  <span class="price-chip main">
                    {{ formatBaht(s.price) }}
                    <em v-if="s.on_flash_sale" class="was">จาก {{ formatBaht(s.original_price) }}</em>
                  </span>
                  <span
                    v-for="p in altPickups(s)"
                    :key="p.id"
                    class="price-chip"
                  >{{ p.label }} {{ formatBaht(p.price) }}</span>
                  <span v-if="s.join_trip_enabled" class="price-chip join">
                    จอยทริป {{ formatBaht(s.join_trip_price) }}
                  </span>
                  <span
                    v-for="v in s.vehicle_options"
                    :key="'v' + v.id"
                    class="price-chip ride"
                  >{{ v.label }} {{ formatBaht(v.price) }}</span>
                </div>
                <div class="round-side">
                  <span class="status-badge" :class="'status-' + s.status">{{ statusLabel(s.status) }}</span>
                  <span class="round-seats" :class="{ tight: s.available_seats > 0 && s.available_seats <= 3 }">
                    ว่าง {{ s.available_seats }}/{{ s.total_seats }}
                  </span>
                </div>
              </div>
            </div>
          </article>
        </div>
      </div>

      <aside class="sheet-copy">
        <div class="copy-head">
          <div>
            <h2 class="copy-title">ข้อความสำหรับทำรูป</h2>
            <p class="copy-sub">ปรับหัวข้อกับรายละเอียดด้านล่าง แล้วค่อยกดคัดลอก</p>
          </div>
        </div>

        <label class="field">
          <span class="field-label">หัวข้อ</span>
          <input v-model="copy.heading" type="text" class="field-input" placeholder="ทริปว่างสัปดาห์นี้" />
        </label>

        <div class="field">
          <span class="field-label">รูปแบบรายการ</span>
          <div class="format-group" role="radiogroup" aria-label="รูปแบบข้อความ">
            <button
              v-for="opt in formatOptions"
              :key="opt.value"
              type="button"
              class="segment"
              :class="{ active: copy.format === opt.value }"
              :title="opt.hint"
              @click="copy.format = opt.value"
            >{{ opt.label }}</button>
          </div>
        </div>

        <div class="field">
          <span class="field-label">ใส่รายละเอียด</span>
          <div class="toggle-grid">
            <label class="opt-check"><input type="checkbox" v-model="copy.showPickup" /><span>ราคาจุดรับ</span></label>
            <label class="opt-check"><input type="checkbox" v-model="copy.showJoin" /><span>ราคาจอยทริป</span></label>
            <label class="opt-check"><input type="checkbox" v-model="copy.showSeats" /><span>ที่นั่งว่าง</span></label>
            <label class="opt-check"><input type="checkbox" v-model="copy.showLocation" /><span>จังหวัด</span></label>
            <label class="opt-check"><input type="checkbox" v-model="copy.showDuration" /><span>จำนวนวัน</span></label>
            <label class="opt-check"><input type="checkbox" v-model="copy.bullets" /><span>จุดนำหน้า</span></label>
          </div>
        </div>

        <label class="field">
          <span class="field-label">ข้อความปิดท้าย</span>
          <input v-model="copy.footer" type="text" class="field-input" placeholder="เช่น ทักแชทจองได้เลยครับ" />
        </label>

        <textarea
          v-model="text"
          class="copy-box"
          spellcheck="false"
          aria-label="ข้อความสรุปราคาทริป"
        ></textarea>
        <div class="copy-actions">
          <button class="btn-primary" type="button" :disabled="!text.trim()" @click="copyText">
            <span class="material-symbols-rounded">content_copy</span> คัดลอกข้อความ
          </button>
          <button class="btn-secondary" type="button" :disabled="!text.trim()" @click="downloadText">
            <span class="material-symbols-rounded">download</span> บันทึกไฟล์
          </button>
          <button class="btn-secondary" type="button" @click="regenerate">
            <span class="material-symbols-rounded">restart_alt</span> สร้างใหม่
          </button>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { bangkokToday } from '../../lib/bangkokDate';
import './admin-shared.css';

const toast = useToast();

/** ตัวเลือกที่ทีมงานตั้งไว้ควรอยู่ข้ามการเปิดหน้าใหม่ ไม่ต้องมาติ๊กซ้ำทุกครั้ง */
const STORE_KEY = 'llk_price_sheet_prefs';

const formatOptions = [
  { value: 'short', label: 'แบบสั้น', hint: 'ทริปละบรรทัดเดียว — ชื่อทริปกับราคา' },
  { value: 'dates', label: 'มีวันเดินทาง', hint: 'รวมรอบที่ราคาเท่ากันไว้บรรทัดเดียว' },
  { value: 'full', label: 'แยกทุกรอบ', hint: 'ทุกรอบแยกบรรทัด พร้อมสถานะ' },
];

const seatFilters = [
  { value: 0, label: 'ทั้งหมด' },
  { value: 1, label: 'ยังมีที่ว่าง' },
  { value: 2, label: 'ว่าง 2 ที่ขึ้นไป' },
  { value: 4, label: 'ว่าง 4 ที่ขึ้นไป' },
  { value: 6, label: 'ว่าง 6 ที่ขึ้นไป' },
];

const statusLabels = {
  open: 'เปิดรับจอง',
  full: 'เต็ม',
  closed: 'ปิด',
  cancelled: 'ยกเลิก',
};

/* ── วันที่แบบ YYYY-MM-DD ───────────────────────────────
   คิดเลขบนสตริงโดยตีความเป็นเที่ยงคืน UTC เสมอ ผลจึงเป็นจำนวนวันเต็ม
   ไม่มีเศษจากโซนเวลาของเครื่องทีมงานมาปน (ดู lib/bangkokDate.js) */
const DAY = 86400000;
const ymd = (ms) => new Date(ms).toISOString().slice(0, 10);
const parse = (s) => Date.parse(`${s}T00:00:00Z`);
const addDays = (s, n) => ymd(parse(s) + n * DAY);

/** วันจันทร์ของสัปดาห์ที่วันนั้นอยู่ (สัปดาห์แบบ จันทร์–อาทิตย์) */
function startOfWeek(s) {
  const dow = new Date(parse(s)).getUTCDay(); // 0 = อาทิตย์
  return addDays(s, dow === 0 ? -6 : 1 - dow);
}

function addMonths(s, n) {
  const d = new Date(parse(s));
  const day = d.getUTCDate();
  d.setUTCDate(1);
  d.setUTCMonth(d.getUTCMonth() + n);
  // สิ้นเดือนสั้นกว่า — 31 ม.ค. +1 เดือนต้องได้ 28/29 ก.พ. ไม่ใช่ 3 มี.ค.
  const last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
  d.setUTCDate(Math.min(day, last));
  return ymd(d.getTime());
}

const startOfMonth = (s) => `${s.slice(0, 7)}-01`;
function endOfMonth(s) {
  const d = new Date(parse(s));
  return ymd(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0));
}

const today = bangkokToday();

/**
 * ปุ่มลัดช่วงเวลา — `unit` บอกว่าลูกศร ‹ › ควรเลื่อนทีละอะไร
 * `heading` คือหัวข้อที่ขึ้นให้อัตโนมัติ เพราะสื่อแต่ละแบบพูดคนละอย่าง
 */
const presets = [
  { id: 'this-week', label: 'สัปดาห์นี้', unit: 'week', heading: 'ทริปว่างสัปดาห์นี้', build: () => weekRange(0) },
  { id: 'next-week', label: 'สัปดาห์หน้า', unit: 'week', heading: 'ทริปว่างสัปดาห์หน้า', build: () => weekRange(1) },
  { id: 'next-7', label: '7 วันข้างหน้า', unit: 'day', heading: 'ทริปว่าง 7 วันนี้', build: () => [today, addDays(today, 6)] },
  { id: 'this-month', label: 'เดือนนี้', unit: 'month', build: () => monthRange(0) },
  { id: 'next-month', label: 'เดือนหน้า', unit: 'month', build: () => monthRange(1) },
  { id: 'next-90', label: '3 เดือนข้างหน้า', unit: 'day', heading: 'ทริปที่กำลังจะออก', build: () => [today, addDays(today, 89)] },
];

function weekRange(offset) {
  const monday = addDays(startOfWeek(today), offset * 7);
  return [monday, addDays(monday, 6)];
}

function monthRange(offset) {
  const anchor = addMonths(startOfMonth(today), offset);
  return [startOfMonth(anchor), endOfMonth(anchor)];
}

const [defaultFrom, defaultTo] = monthRange(0);
const from = ref(defaultFrom);
const to = ref(defaultTo);
const sheet = ref({});
const loading = ref(false);
const text = ref('');

const filters = reactive({
  openOnly: true,
  hideDeparted: true,
  minSeats: 1,
  destination: 'all',
  sort: 'date',
  search: '',
});

const copy = reactive({
  format: 'dates',
  heading: '',
  footer: '',
  showPickup: true,
  showJoin: true,
  showSeats: false,
  showLocation: false,
  showDuration: false,
  bullets: false,
});

restorePrefs();

const summary = computed(() => sheet.value.summary || {});
const rangeDays = computed(() => sheet.value.days || 0);

/** ปุ่มลัดที่ตรงกับช่วงที่เลือกอยู่พอดี — ไม่ตรงเลยแปลว่าเลือกวันเอง */
const activePresetId = computed(() => {
  const match = presets.find((p) => {
    const [a, b] = p.build();
    return a === from.value && b === to.value;
  });
  return match?.id || null;
});

const activePreset = computed(() => presets.find((p) => p.id === activePresetId.value) || null);

const visibleTrips = computed(() => {
  const query = filters.search.trim().toLowerCase();

  let trips = (sheet.value.trips || [])
    .map((t) => ({ ...t, schedules: t.schedules.filter(keepSchedule) }))
    .filter((t) => t.schedules.length);

  if (filters.destination !== 'all') {
    const wantInternational = filters.destination === 'international';
    trips = trips.filter((t) => Boolean(t.is_international) === wantInternational);
  }

  if (query) {
    trips = trips.filter((t) => `${t.title} ${t.location || ''} ${t.region || ''}`
      .toLowerCase()
      .includes(query));
  }

  return sortTrips(trips);
});

function keepSchedule(s) {
  if (filters.openOnly && s.status !== 'open') return false;
  if (filters.minSeats > 0 && (s.available_seats || 0) < filters.minSeats) return false;
  // รอบที่ออกไปแล้วยังอยู่ในเดือนเดียวกัน แต่ไม่มีประโยชน์กับสื่อที่กำลังจะโพสต์
  if (filters.hideDeparted && s.departure_date && s.departure_date < today) return false;
  return true;
}

function sortTrips(trips) {
  const copyOf = [...trips];
  if (filters.sort === 'price') {
    return copyOf.sort((a, b) => tripMinPrice(a) - tripMinPrice(b));
  }
  if (filters.sort === 'seats') {
    return copyOf.sort((a, b) => tripSeats(b) - tripSeats(a));
  }
  if (filters.sort === 'title') {
    return copyOf.sort((a, b) => a.title.localeCompare(b.title, 'th'));
  }
  // วันเดินทาง — เรียงตามรอบแรกที่ยังเหลืออยู่หลังกรอง ไม่ใช่รอบแรกของทั้งเดือน
  return copyOf.sort((a, b) => (firstDeparture(a) || '').localeCompare(firstDeparture(b) || ''));
}

const firstDeparture = (trip) => trip.schedules[0]?.departure_date || '';
const tripSeats = (trip) => trip.schedules.reduce((sum, s) => sum + (s.available_seats || 0), 0);

function tripMinPrice(trip) {
  const prices = trip.schedules.map((s) => s.price).filter(Boolean);
  return prices.length ? Math.min(...prices) : Number.MAX_SAFE_INTEGER;
}

const visibleScheduleCount = computed(
  () => visibleTrips.value.reduce((sum, t) => sum + t.schedules.length, 0),
);

const visibleSeats = computed(
  () => visibleTrips.value.reduce((sum, t) => sum + tripSeats(t), 0),
);

const priceRangeLabel = computed(() => {
  const prices = visibleTrips.value.flatMap((t) => t.schedules.map((s) => s.price)).filter(Boolean);
  if (!prices.length) return '—';
  const min = Math.min(...prices);
  const max = Math.max(...prices);
  return min === max ? formatBaht(min) : `${formatBaht(min)} – ${formatBaht(max)}`;
});

function formatBaht(value) {
  return `฿${Number(value || 0).toLocaleString('th-TH', { maximumFractionDigits: 0 })}`;
}

/** ราคาแบบที่ใช้ในสื่อโปรโมท — "1,290.-" */
function plainPrice(value) {
  return `${Number(value || 0).toLocaleString('th-TH', { maximumFractionDigits: 0 })}.-`;
}

function statusLabel(status) {
  return statusLabels[status] || status;
}

/**
 * จุดรับที่ "ราคาไม่เท่าราคารอบ" เท่านั้น — จุดที่ราคาเท่ากันไม่ได้บอกอะไรใหม่
 * กับคนอ่านรูป (ราคาจุดรับเป็นราคาเต็มของจุดนั้น ไม่ใช่ส่วนต่าง)
 */
function altPickups(schedule) {
  return (schedule.pickup_points || []).filter((p) => !p.is_default_price);
}

/** ราคาที่เขียนหัวทริปได้ — ช่วงราคาเมื่อรอบในช่วงนี้ราคาไม่เท่ากัน */
function tripPriceLabel(trip) {
  const prices = trip.schedules.map((s) => s.price).filter(Boolean);
  if (!prices.length) return '—';
  const min = Math.min(...prices);
  const max = Math.max(...prices);
  return min === max ? formatBaht(min) : `${formatBaht(min)} – ${formatBaht(max)}`;
}

/* ── ช่วงเวลา ────────────────────────────────────────── */
function applyPreset(id) {
  const preset = presets.find((p) => p.id === id);
  if (!preset) return;
  [from.value, to.value] = preset.build();
}

const shiftTitle = (dir) => {
  const unit = activePreset.value?.unit;
  const what = unit === 'month' ? 'เดือน' : unit === 'week' ? 'สัปดาห์' : 'ช่วง';
  return dir < 0 ? `${what}ก่อนหน้า` : `${what}ถัดไป`;
};

/**
 * ลูกศรเลื่อนทีละ "หนึ่งช่วงเดียวกับที่ดูอยู่" — เดือนเลื่อนทีละเดือนและยังกินทั้งเดือน
 * ส่วนช่วงอื่นเลื่อนทีละจำนวนวันของตัวเอง
 */
function shiftRange(dir) {
  if (activePreset.value?.unit === 'month') {
    const anchor = addMonths(startOfMonth(from.value), dir);
    from.value = startOfMonth(anchor);
    to.value = endOfMonth(anchor);
    return;
  }
  const span = Math.round((parse(to.value) - parse(from.value)) / DAY) + 1;
  from.value = addDays(from.value, dir * span);
  to.value = addDays(to.value, dir * span);
}

/* ── ข้อความ ─────────────────────────────────────────── */
/** หัวข้อที่ตั้งให้อัตโนมัติตามช่วงที่เลือก — ทีมงานพิมพ์ทับได้ */
function autoHeading() {
  const label = sheet.value.range_label || '';
  const preset = activePreset.value;
  if (preset?.heading) return label ? `${preset.heading} (${label})` : preset.heading;
  if (sheet.value.is_full_month) return `ทริปเดือน${label}`;
  return label ? `ทริปช่วง ${label}` : 'ทริป';
}

/** ราคาทางเลือกของรอบหนึ่ง เรียงตามที่อยากให้อ่านในรูป */
function extraPrices(schedule) {
  const out = [];
  if (copy.showPickup) {
    altPickups(schedule).forEach((p) => out.push(`${p.label} ${plainPrice(p.price)}`));
    (schedule.vehicle_options || []).forEach((v) => out.push(`${v.label} ${plainPrice(v.price)}`));
  }
  if (copy.showJoin && schedule.join_trip_enabled) {
    out.push(`จอยทริป ${plainPrice(schedule.join_trip_price)}`);
  }
  return out;
}

/** รอบที่ราคาเหมือนกันทุกอย่างยุบเป็นบรรทัดเดียว เหลือแค่วันที่ต่างกัน */
function priceGroups(trip) {
  const groups = [];
  trip.schedules.forEach((s) => {
    const extras = extraPrices(s);
    const key = `${s.price}|${extras.join('|')}`;
    const found = groups.find((g) => g.key === key);
    if (found) {
      found.schedules.push(s);
      return;
    }
    groups.push({ key, price: s.price, extras, schedules: [s] });
  });
  return groups;
}

/** ส่วนขยายท้ายชื่อทริป เช่น "(เพชรบูรณ์ · 2 วัน)" */
function tripSuffix(trip) {
  const bits = [];
  if (copy.showLocation && trip.location) bits.push(trip.location);
  if (copy.showDuration && trip.duration_days) bits.push(`${trip.duration_days} วัน`);
  return bits.length ? ` (${bits.join(' · ')})` : '';
}

function buildText() {
  const trips = visibleTrips.value;
  if (!trips.length) return '';

  const bullet = copy.bullets ? '• ' : '';
  const lines = [copy.heading.trim(), ''];

  trips.forEach((trip) => {
    const name = `${trip.title}${tripSuffix(trip)}`;

    if (copy.format === 'short') {
      const groups = priceGroups(trip);
      groups.forEach((g) => {
        // ทริปที่รอบในช่วงนี้ราคาไม่เท่ากันต้องบอกวันด้วย ไม่งั้นอ่านไม่ออกว่าราคาไหนของรอบไหน
        const head = groups.length > 1
          ? `${name} (${g.schedules.map((s) => s.date_label).join(', ')})`
          : name;
        const bits = [head, plainPrice(g.price), ...g.extras];
        if (copy.showSeats) bits.push(`ว่าง ${groupSeats(g)} ที่`);
        lines.push(bullet + bits.join(' '));
      });
      return;
    }

    if (copy.format === 'dates') {
      lines.push(bullet + name);
      priceGroups(trip).forEach((g) => {
        const dates = g.schedules.map((s) => s.date_label).join(' / ');
        const bits = [plainPrice(g.price), ...g.extras];
        if (copy.showSeats) bits.push(`ว่าง ${groupSeats(g)} ที่`);
        lines.push(`${dates} — ${bits.join(' · ')}`);
      });
      lines.push('');
      return;
    }

    lines.push(bullet + name);
    trip.schedules.forEach((s) => {
      const bits = [plainPrice(s.price), ...extraPrices(s)];
      if (copy.showSeats) bits.push(`ว่าง ${s.available_seats} ที่`);
      if (s.status !== 'open') bits.push(statusLabel(s.status));
      lines.push(`${s.date_full} — ${bits.join(' · ')}`);
    });
    lines.push('');
  });

  if (copy.footer.trim()) lines.push('', copy.footer.trim());

  return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

const groupSeats = (group) => group.schedules.reduce((sum, s) => sum + (s.available_seats || 0), 0);

function regenerate() {
  copy.heading = autoHeading();
  text.value = buildText();
}

function resetFilters() {
  filters.openOnly = false;
  filters.hideDeparted = false;
  filters.minSeats = 0;
  filters.destination = 'all';
  filters.search = '';
}

/* ── ค่าที่จำไว้ให้ทีมงาน ─────────────────────────────── */
function restorePrefs() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
    // หัวข้อไม่เก็บ — มันผูกกับช่วงเวลาที่เลือก ไม่ใช่ความชอบของคนใช้
    Object.keys(filters).forEach((k) => {
      if (k !== 'search' && saved.filters?.[k] !== undefined) filters[k] = saved.filters[k];
    });
    Object.keys(copy).forEach((k) => {
      if (k !== 'heading' && saved.copy?.[k] !== undefined) copy[k] = saved.copy[k];
    });
  } catch {
    // โหมดส่วนตัว/ค่าเสีย — ใช้ค่าตั้งต้นไป
  }
}

function savePrefs() {
  try {
    localStorage.setItem(STORE_KEY, JSON.stringify({
      filters: { ...filters, search: '' },
      copy: { ...copy, heading: '' },
    }));
  } catch {
    // เขียนไม่ได้ก็ไม่เป็นไร ตัวเลือกยังใช้ได้ในรอบนี้
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/price-sheet', {
      params: { from: from.value, to: to.value },
    });
    sheet.value = res.data.data || {};
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดราคาทริปไม่สำเร็จ');
    sheet.value = {};
  } finally {
    loading.value = false;
  }
}

async function copyText() {
  try {
    await navigator.clipboard.writeText(text.value);
    toast.success('คัดลอกข้อความแล้ว');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ — เลือกข้อความในกล่องแล้วกด Ctrl+C');
  }
}

function downloadText() {
  const blob = new Blob([text.value], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `ราคาทริป-${from.value}-ถึง-${to.value}.txt`;
  a.click();
  URL.revokeObjectURL(url);
}

watch([from, to], () => {
  if (!from.value || !to.value) return;
  load();
});

// หัวข้อตั้งใหม่เมื่อช่วงเวลาเปลี่ยน — ที่พิมพ์ทับไว้เป็นของช่วงก่อนหน้า
watch(sheet, () => { copy.heading = autoHeading(); });

// ข้อความสร้างใหม่ทุกครั้งที่ข้อมูล ตัวกรอง หรือตัวเลือกเปลี่ยน — ที่พิมพ์แก้เองจะหายไป
// ซึ่งตรงกับที่คาดหวัง เพราะเนื้อหาที่แก้ไว้เป็นของชุดก่อนหน้า
watch([sheet, filters, copy], () => { text.value = buildText(); }, { deep: true });

watch([filters, copy], savePrefs, { deep: true });

onMounted(load);
</script>

<style scoped>
/* ── ช่วงเวลา ─────────────────────────────────────── */
.range-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px 16px;
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  padding: 10px 12px;
  margin-bottom: 16px;
}

.preset-row { display: flex; flex-wrap: wrap; gap: 6px; }

.preset {
  border: 1px solid var(--color-border, #e5e7eb);
  background: #fff;
  border-radius: 999px;
  padding: 7px 14px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  cursor: pointer;
  white-space: nowrap;
}

.preset:hover { border-color: #9ca3af; }
.preset.active { background: #111827; border-color: #111827; color: #fff; }

.range-right { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; }

.range-nav {
  display: flex;
  align-items: center;
  gap: 2px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 10px;
  padding: 2px;
  background: #fff;
}

.date-input {
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  color: inherit;
  padding: 6px 2px;
}

.date-input:focus { outline: none; }
.range-dash { color: #9ca3af; font-size: 13px; }

.range-label { font-size: 13px; font-weight: 700; color: #374151; }
.range-label em { font-style: normal; font-weight: 500; color: #9ca3af; margin-left: 6px; }

/* ── สถิติ ────────────────────────────────────────── */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.stat-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  padding: 14px 16px;
}

.stat-card > div { display: flex; flex-direction: column; min-width: 0; }
.stat-icon { color: #6b7280; }
.stat-label { font-size: 12px; color: #6b7280; }
.stat-value { font-size: 20px; font-weight: 800; line-height: 1.2; }
.stat-sub { font-size: 11px; color: #9ca3af; }

.sheet-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 380px;
  gap: 16px;
  align-items: start;
}

/* ── ตัวกรอง ──────────────────────────────────────── */
.options-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 14px;
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  padding: 10px 14px;
  margin-bottom: 12px;
}

.opt-group { display: flex; align-items: center; gap: 6px; }
.opt-group-label { font-size: 12px; color: #6b7280; white-space: nowrap; }

.opt-select {
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 8px;
  padding: 6px 8px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  background: #fff;
  color: #111827;
}

.opt-search {
  display: flex;
  align-items: center;
  gap: 6px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 8px;
  padding: 4px 8px;
  flex: 1;
  min-width: 180px;
}

.opt-search .material-symbols-rounded { font-size: 18px; color: #9ca3af; }

.opt-search input {
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 13px;
  padding: 4px 0;
  width: 100%;
}

.opt-search input:focus { outline: none; }

.format-group { display: flex; gap: 2px; background: #f3f4f6; border-radius: 8px; padding: 2px; }

.segment {
  border: none;
  background: transparent;
  border-radius: 6px;
  padding: 6px 12px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  cursor: pointer;
  flex: 1;
}

.segment.active { background: #fff; color: #111827; }

.opt-check { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }

.link-btn {
  border: none;
  background: none;
  padding: 0 0 0 4px;
  font-family: inherit;
  font-size: inherit;
  font-weight: 700;
  color: #2563eb;
  cursor: pointer;
  text-decoration: underline;
}

/* ── รายการทริป ───────────────────────────────────── */
.trip-list { display: flex; flex-direction: column; gap: 12px; }

.trip-block {
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  overflow: hidden;
}

.trip-block-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid #f3f4f6;
}

.trip-block-title { font-size: 16px; font-weight: 800; margin: 0; }

.trip-block-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 4px 0 0;
  font-size: 12px;
  color: #6b7280;
}

.meta-seats { font-weight: 700; color: #047857; }
.trip-block-price { font-size: 16px; font-weight: 800; white-space: nowrap; }

.round-rows { display: flex; flex-direction: column; }

.round-row {
  display: grid;
  grid-template-columns: 160px minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  padding: 10px 16px;
  border-bottom: 1px solid #f9fafb;
}

.round-row:last-child { border-bottom: none; }

.round-date { display: flex; flex-direction: column; }
.round-date strong { font-size: 14px; }
.round-full { font-size: 11px; color: #9ca3af; }

.round-prices { display: flex; flex-wrap: wrap; gap: 6px; }

.price-chip {
  font-size: 12px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 6px;
  background: #f3f4f6;
  color: #374151;
}

.price-chip.main { background: #111827; color: #fff; }
.price-chip.join { background: #e0f2fe; color: #0369a1; }
.price-chip.ride { background: #fef9c3; color: #a16207; }
.price-chip .was { font-style: normal; font-weight: 500; opacity: 0.7; margin-left: 4px; }

.round-side { display: flex; align-items: center; gap: 8px; }
.round-seats { font-size: 11px; color: #6b7280; white-space: nowrap; }
.round-seats.tight { color: #b45309; font-weight: 700; }

/* ── กล่องข้อความ ─────────────────────────────────── */
.sheet-copy {
  position: sticky;
  top: 16px;
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  padding: 14px;
}

.copy-title { font-size: 15px; font-weight: 800; margin: 0; }
.copy-sub { font-size: 12px; color: #6b7280; margin: 2px 0 12px; }

.field { display: block; margin-bottom: 10px; }
.field-label { display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px; }

.field-input {
  width: 100%;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 8px;
  padding: 8px 10px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
}

.toggle-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 10px; }

.copy-box {
  width: 100%;
  min-height: 300px;
  margin-top: 4px;
  resize: vertical;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 10px;
  padding: 12px;
  font-family: inherit;
  font-size: 13px;
  line-height: 1.8;
  white-space: pre;
  overflow-x: auto;
}

.copy-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }

.empty-card {
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  padding: 40px 20px;
  text-align: center;
  color: #6b7280;
}

.empty-card .material-symbols-rounded { font-size: 36px; color: #d1d5db; }

.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 1100px) {
  .sheet-layout { grid-template-columns: minmax(0, 1fr); }
  .sheet-copy { position: static; }
  .round-row { grid-template-columns: minmax(0, 1fr); }
  .round-side { justify-content: flex-start; }
}

@media (max-width: 640px) {
  .range-bar { flex-direction: column; align-items: stretch; }
  .range-right { justify-content: space-between; }
  .toggle-grid { grid-template-columns: 1fr; }
}
</style>
