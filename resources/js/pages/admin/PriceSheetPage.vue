<template>
  <div class="admin-page price-sheet-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">sell</span>
          ราคาทริปรายเดือน
        </h1>
        <p class="page-subtitle">
          เดือนนี้มีทริปไหน รอบไหน ราคาเท่าไร รวมไว้ที่เดียว — ก๊อปข้อความไปทำรูปโปรโมทได้เลย
        </p>
      </div>
      <div class="header-actions">
        <div class="month-nav">
          <button class="btn-icon" type="button" title="เดือนก่อนหน้า" @click="shiftMonth(-1)">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <input type="month" v-model="month" class="month-input" aria-label="เลือกเดือน" />
          <button class="btn-icon" type="button" title="เดือนถัดไป" @click="shiftMonth(1)">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
        <button class="btn-secondary" type="button" :disabled="month === thisMonth" @click="month = thisMonth">
          เดือนนี้
        </button>
        <button class="btn-secondary" type="button" :disabled="loading" @click="load()">
          <span class="material-symbols-rounded" :class="{ spin: loading }">refresh</span>
          รีเฟรช
        </button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">hiking</span>
        <div>
          <span class="stat-label">ทริปที่ออกเดือนนี้</span>
          <strong class="stat-value">{{ visibleTrips.length }}</strong>
          <span class="stat-sub">{{ sheet.month_label || '—' }}</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">event</span>
        <div>
          <span class="stat-label">รอบเดินทาง</span>
          <strong class="stat-value">{{ visibleScheduleCount }}</strong>
          <span class="stat-sub">เปิดรับจอง {{ summary.open_schedule_count || 0 }} รอบ</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">airline_seat_recline_normal</span>
        <div>
          <span class="stat-label">ที่นั่งว่างรวม</span>
          <strong class="stat-value">{{ summary.available_seats || 0 }}</strong>
          <span class="stat-sub">นับทุกรอบที่ยังไม่ปิด</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded">payments</span>
        <div>
          <span class="stat-label">ช่วงราคา</span>
          <strong class="stat-value">{{ priceRangeLabel }}</strong>
          <span class="stat-sub">ราคาที่ขายอยู่จริงในเดือนนี้</span>
        </div>
      </div>
    </div>

    <div class="sheet-layout">
      <div class="sheet-main">
        <div class="options-bar">
          <div class="format-group" role="radiogroup" aria-label="รูปแบบข้อความ">
            <button
              v-for="opt in formatOptions"
              :key="opt.value"
              type="button"
              class="segment"
              :class="{ active: format === opt.value }"
              :title="opt.hint"
              @click="format = opt.value"
            >{{ opt.label }}</button>
          </div>
          <label class="opt-check">
            <input type="checkbox" v-model="options.openOnly" />
            <span>เฉพาะรอบที่เปิดรับจอง</span>
          </label>
          <label class="opt-check">
            <input type="checkbox" v-model="options.showPickup" />
            <span>ราคาจุดรับ</span>
          </label>
          <label class="opt-check">
            <input type="checkbox" v-model="options.showJoin" />
            <span>ราคาจอยทริป</span>
          </label>
          <label class="opt-check">
            <input type="checkbox" v-model="options.showSeats" />
            <span>ที่นั่งว่าง</span>
          </label>
        </div>

        <div v-if="loading" class="loading-state"><div class="spinner"></div></div>
        <div v-else-if="!visibleTrips.length" class="empty-card">
          <span class="material-symbols-rounded">event_busy</span>
          <p v-if="sheet.trips && sheet.trips.length">
            เดือนนี้มีรอบอยู่ แต่ไม่มีรอบที่เปิดรับจอง — เอาเครื่องหมาย "เฉพาะรอบที่เปิดรับจอง" ออกเพื่อดูทั้งหมด
          </p>
          <p v-else>ยังไม่มีรอบเดินทางในเดือน {{ sheet.month_label || '—' }}</p>
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
                  <span class="round-seats">ว่าง {{ s.available_seats }}/{{ s.total_seats }}</span>
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
            <p class="copy-sub">แก้ข้อความในกล่องได้เลย แล้วค่อยกดคัดลอก</p>
          </div>
        </div>
        <textarea
          v-model="text"
          class="copy-box"
          spellcheck="false"
          aria-label="ข้อความสรุปราคารายเดือน"
        ></textarea>
        <div class="copy-actions">
          <button class="btn-primary" type="button" :disabled="!text.trim()" @click="copyText">
            <span class="material-symbols-rounded">content_copy</span> คัดลอกข้อความ
          </button>
          <button class="btn-secondary" type="button" :disabled="!text.trim()" @click="downloadText">
            <span class="material-symbols-rounded">download</span> บันทึกไฟล์
          </button>
          <button class="btn-secondary" type="button" @click="text = buildText()">
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
import './admin-shared.css';

const toast = useToast();

const formatOptions = [
  { value: 'short', label: 'แบบสั้น', hint: 'ทริปละบรรทัดเดียว — ชื่อทริปกับราคา' },
  { value: 'dates', label: 'มีวันเดินทาง', hint: 'รวมรอบที่ราคาเท่ากันไว้บรรทัดเดียว' },
  { value: 'full', label: 'แยกทุกรอบ', hint: 'ทุกรอบแยกบรรทัด พร้อมสถานะ' },
];

const statusLabels = {
  open: 'เปิดรับจอง',
  full: 'เต็ม',
  closed: 'ปิด',
  cancelled: 'ยกเลิก',
};

/** เดือนปัจจุบันตามเวลาไทย — เครื่องทีมงานอาจตั้งโซนอื่นไว้ */
function bangkokMonth() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Bangkok',
    year: 'numeric',
    month: '2-digit',
  }).format(new Date());
  return parts.slice(0, 7);
}

const thisMonth = bangkokMonth();
const month = ref(thisMonth);
const sheet = ref({});
const loading = ref(false);
const text = ref('');

const options = reactive({
  openOnly: true,
  showPickup: true,
  showJoin: true,
  showSeats: false,
});
const format = ref('dates');

const summary = computed(() => sheet.value.summary || {});

const visibleTrips = computed(() => {
  const trips = sheet.value.trips || [];
  if (!options.openOnly) return trips;
  return trips
    .map((t) => ({ ...t, schedules: t.schedules.filter((s) => s.status === 'open') }))
    .filter((t) => t.schedules.length);
});

const visibleScheduleCount = computed(
  () => visibleTrips.value.reduce((sum, t) => sum + t.schedules.length, 0),
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

/** ราคาที่เขียนหัวทริปได้ — ช่วงราคาเมื่อรอบในเดือนนี้ราคาไม่เท่ากัน */
function tripPriceLabel(trip) {
  const prices = trip.schedules.map((s) => s.price).filter(Boolean);
  if (!prices.length) return '—';
  const min = Math.min(...prices);
  const max = Math.max(...prices);
  return min === max ? formatBaht(min) : `${formatBaht(min)} – ${formatBaht(max)}`;
}

/** ราคาทางเลือกของรอบหนึ่ง เรียงตามที่อยากให้อ่านในรูป */
function extraPrices(schedule) {
  const out = [];
  if (options.showPickup) {
    altPickups(schedule).forEach((p) => out.push(`${p.label} ${plainPrice(p.price)}`));
    (schedule.vehicle_options || []).forEach((v) => out.push(`${v.label} ${plainPrice(v.price)}`));
  }
  if (options.showJoin && schedule.join_trip_enabled) {
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

function buildText() {
  const trips = visibleTrips.value;
  if (!trips.length) return '';

  const lines = [`ทริปเดือน${sheet.value.month_label || ''}`.trim(), ''];

  trips.forEach((trip) => {
    if (format.value === 'short') {
      const groups = priceGroups(trip);
      groups.forEach((g) => {
        // ทริปที่รอบในเดือนนี้ราคาไม่เท่ากันต้องบอกวันด้วย ไม่งั้นอ่านไม่ออกว่าราคาไหนของรอบไหน
        const head = groups.length > 1
          ? `${trip.title} (${g.schedules.map((s) => s.date_label).join(', ')})`
          : trip.title;
        lines.push([head, plainPrice(g.price), ...g.extras].join(' '));
      });
      return;
    }

    if (format.value === 'dates') {
      lines.push(trip.title);
      priceGroups(trip).forEach((g) => {
        const dates = g.schedules.map((s) => s.date_label).join(' / ');
        lines.push(`${dates} — ${[plainPrice(g.price), ...g.extras].join(' · ')}`);
      });
      lines.push('');
      return;
    }

    lines.push(trip.title);
    trip.schedules.forEach((s) => {
      const bits = [plainPrice(s.price), ...extraPrices(s)];
      if (options.showSeats) bits.push(`ว่าง ${s.available_seats} ที่`);
      if (s.status !== 'open') bits.push(statusLabel(s.status));
      lines.push(`${s.date_full} — ${bits.join(' · ')}`);
    });
    lines.push('');
  });

  return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/price-sheet', { params: { month: month.value } });
    sheet.value = res.data.data || {};
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดราคารายเดือนไม่สำเร็จ');
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
  a.download = `ราคาทริป-${month.value}.txt`;
  a.click();
  URL.revokeObjectURL(url);
}

watch(month, () => load());
// ข้อความสร้างใหม่ทุกครั้งที่ข้อมูลหรือตัวเลือกเปลี่ยน — ที่พิมพ์แก้เองจะหายไป
// ซึ่งตรงกับที่คาดหวัง เพราะเนื้อหาที่แก้ไว้เป็นของเดือน/รูปแบบก่อนหน้า
watch([sheet, options, format], () => { text.value = buildText(); }, { deep: true });

onMounted(load);
</script>

<style scoped>
.month-nav {
  display: flex;
  align-items: center;
  gap: 4px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 10px;
  padding: 2px;
  background: #fff;
}

.month-input {
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  color: inherit;
  padding: 6px 4px;
}

.month-input:focus { outline: none; }

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

.options-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  padding: 10px 14px;
  margin-bottom: 12px;
}

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
}

.segment.active { background: #fff; color: #111827; }

.opt-check { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }

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

.sheet-copy {
  position: sticky;
  top: 16px;
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  padding: 14px;
}

.copy-title { font-size: 15px; font-weight: 800; margin: 0; }
.copy-sub { font-size: 12px; color: #6b7280; margin: 2px 0 10px; }

.copy-box {
  width: 100%;
  min-height: 420px;
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
</style>
