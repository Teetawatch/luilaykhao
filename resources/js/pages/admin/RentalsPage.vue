<template>
  <div class="admin-page rentals-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">backpack</span> อุปกรณ์เช่าที่ต้องเตรียม</h1>
        <p class="page-subtitle">รวมของที่ลูกค้าเช่าไว้ในแต่ละรอบ ใช้เป็นเช็กลิสต์ตอนขนของขึ้นรถและตอนรับของคืน</p>
      </div>
      <div class="head-actions">
        <button class="btn-secondary" :disabled="loadingSchedules" @click="refresh">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loadingSchedules }">refresh</span>
          รีเฟรช
        </button>
        <button v-if="detail" class="btn-primary" @click="printList">
          <span class="material-symbols-rounded">print</span> พิมพ์ใบรวม
        </button>
      </div>
    </div>

    <!-- ยอดรวมของทุกรอบที่กำลังดูอยู่ -->
    <div v-if="!loadingSchedules && schedules.length" class="stat-strip">
      <div v-for="tile in statTiles" :key="tile.label" class="stat-tile">
        <span class="stat-tile-icon" :class="`tone-${tile.tone}`">
          <span class="material-symbols-rounded">{{ tile.icon }}</span>
        </span>
        <span class="stat-tile-body">
          <span class="stat-tile-value">{{ tile.value }}</span>
          <span class="stat-tile-label">{{ tile.label }}</span>
        </span>
      </div>
    </div>

    <div v-if="loadingSchedules" class="rental-layout">
      <aside class="schedule-rail">
        <span v-for="n in 4" :key="`sk-rail-${n}`" class="sk sk-rail"></span>
      </aside>
      <section class="rental-detail">
        <span class="sk sk-block sk-head"></span>
        <div class="item-grid">
          <span v-for="n in 4" :key="`sk-item-${n}`" class="sk sk-block sk-item"></span>
        </div>
      </section>
    </div>

    <div v-else-if="!schedules.length" class="empty-card">
      <span class="material-symbols-rounded">inventory_2</span>
      <p>{{ includePast ? 'ยังไม่มีรอบไหนที่ลูกค้าเช่าอุปกรณ์' : 'ยังไม่มีรอบข้างหน้าที่ลูกค้าเช่าอุปกรณ์' }}</p>
      <span class="empty-hint">ตั้งรายการอุปกรณ์ให้เช่าได้ที่หน้าแก้ไขทริป → “อุปกรณ์ให้เช่า”</span>
      <button v-if="!includePast" class="btn-secondary" @click="includePast = true; loadSchedules()">
        <span class="material-symbols-rounded">history</span> ดูรอบที่ผ่านไปแล้ว
      </button>
    </div>

    <div v-else class="rental-layout">
      <!-- รายการรอบเดินทางที่มีของต้องเตรียม -->
      <aside class="schedule-rail">
        <div class="rail-tools">
          <div class="search-box">
            <span class="material-symbols-rounded">search</span>
            <input v-model="scheduleQuery" placeholder="ค้นหารอบ / ชื่อทริป..." />
          </div>
          <label class="past-toggle">
            <input type="checkbox" v-model="includePast" @change="loadSchedules" />
            <span>แสดงรอบที่ผ่านไปแล้ว</span>
          </label>
        </div>

        <p v-if="!filteredSchedules.length" class="rail-empty">ไม่พบรอบที่ตรงกับคำค้น</p>

        <button
          v-for="s in filteredSchedules"
          :key="s.id"
          class="schedule-item"
          :class="{ active: s.id === selectedId, past: s.is_past }"
          :aria-current="s.id === selectedId ? 'true' : undefined"
          @click="select(s.id)"
        >
          <span class="sched-top">
            <span class="sched-trip">{{ s.trip_title }}</span>
            <span class="countdown-chip" :class="countdownTone(s)">{{ countdownLabel(s) }}</span>
          </span>
          <span class="sched-date">{{ s.departure_date_thai }}</span>
          <span class="sched-meta">
            <span><span class="material-symbols-rounded">receipt_long</span>{{ s.bookings_with_rentals }} ใบจอง</span>
            <span><span class="material-symbols-rounded">payments</span>฿{{ formatMoney(s.rentals_revenue) }}</span>
          </span>
        </button>
      </aside>

      <section class="rental-detail" ref="printArea">
        <div v-if="loadingDetail" class="detail-loading">
          <span class="sk sk-block sk-head"></span>
          <div class="item-grid">
            <span v-for="n in 4" :key="`sk-d-${n}`" class="sk sk-block sk-item"></span>
          </div>
        </div>

        <template v-else-if="detail">
          <!-- หัวกระดาษ เห็นเฉพาะตอนพิมพ์ -->
          <div class="print-head">
            <h1>ใบรวมอุปกรณ์เช่า</h1>
            <p>{{ detail.schedule.trip_title }} · รอบเดินทาง {{ detail.schedule.departure_date_thai }}</p>
            <p class="print-meta">พิมพ์เมื่อ {{ printedAt }} · รวม {{ detail.totals.pieces }} ชิ้น จาก {{ detail.totals.bookings }} ใบจอง</p>
          </div>

          <div class="detail-head">
            <div class="detail-title">
              <span class="countdown-chip" :class="countdownTone(selectedSchedule)">
                {{ countdownLabel(selectedSchedule) }}
              </span>
              <h2>{{ detail.schedule.trip_title }}</h2>
              <p>
                <span class="material-symbols-rounded">event</span>
                รอบเดินทาง {{ detail.schedule.departure_date_thai }}
              </p>
            </div>
            <div class="totals">
              <div class="total-cell">
                <span class="total-num">{{ detail.totals.pieces }}</span>
                <span class="total-label">ชิ้นที่ต้องขน</span>
              </div>
              <div class="total-cell">
                <span class="total-num">{{ detail.items.length }}</span>
                <span class="total-label">รายการ</span>
              </div>
              <div class="total-cell">
                <span class="total-num">{{ detail.totals.bookings }}</span>
                <span class="total-label">ใบจอง</span>
              </div>
              <div class="total-cell">
                <span class="total-num">฿{{ formatMoney(detail.totals.revenue) }}</span>
                <span class="total-label">รายได้ค่าเช่า</span>
              </div>
            </div>
          </div>

          <div class="section-head">
            <h3 class="section-label">สรุปของที่ต้องเตรียม</h3>
            <button class="link-btn no-print" @click="copySummary">
              <span class="material-symbols-rounded">content_copy</span> คัดลอกรายการ
            </button>
          </div>

          <div class="item-grid">
            <div v-for="item in detail.items" :key="item.name" class="item-card">
              <span class="tick-box print-only"></span>
              <div class="item-thumb">
                <img v-if="item.image_url" :src="item.image_url" :alt="item.name" loading="lazy" />
                <span v-else class="material-symbols-rounded">backpack</span>
              </div>
              <div class="item-info">
                <span class="item-name">{{ item.name }}</span>
                <span class="item-sub">{{ item.renters }} ใบจอง · ฿{{ formatMoney(item.revenue) }}</span>
                <span class="item-bar no-print">
                  <span class="item-bar-fill" :style="{ width: `${sharePercent(item)}%` }"></span>
                </span>
              </div>
              <span class="item-qty">
                <span class="qty-num">{{ item.quantity }}</span>
                <span class="qty-unit">ชิ้น</span>
              </span>
            </div>
          </div>

          <div class="section-head">
            <h3 class="section-label">ของใครบ้าง</h3>
            <div class="search-box search-sm no-print">
              <span class="material-symbols-rounded">search</span>
              <input v-model="bookingQuery" placeholder="ค้นหาชื่อ เบอร์ เลขจอง หรือชื่ออุปกรณ์..." />
            </div>
          </div>

          <div class="table-card">
            <div class="table-container">
              <table class="data-table rentals-table">
                <thead>
                  <tr>
                    <th class="print-only tick-col">เช็ก</th>
                    <th>เลขการจอง</th>
                    <th>ลูกค้า</th>
                    <th>รายการที่เช่า</th>
                    <th class="num">ยอดค่าเช่า</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="b in filteredBookings" :key="b.booking_ref">
                    <td class="print-only tick-col"><span class="tick-box"></span></td>
                    <td><span class="booking-ref">{{ b.booking_ref }}</span></td>
                    <td>
                      <span class="cust-name">{{ b.customer_name }}</span>
                      <a v-if="b.phone" class="phone-link" :href="`tel:${b.phone}`">
                        <span class="material-symbols-rounded">call</span>{{ b.phone }}
                      </a>
                    </td>
                    <td>
                      <span v-for="it in b.items" :key="it.name" class="rent-chip">
                        {{ it.name }} <strong>×{{ it.quantity }}</strong>
                      </span>
                    </td>
                    <td class="num money">฿{{ formatMoney(b.rentals_total) }}</td>
                  </tr>
                  <tr v-if="!filteredBookings.length">
                    <td colspan="5" class="empty-state">ไม่พบใบจองที่ตรงกับคำค้น</td>
                  </tr>
                </tbody>
                <tfoot v-if="filteredBookings.length">
                  <tr>
                    <td class="print-only tick-col"></td>
                    <td colspan="2">รวม {{ filteredBookings.length }} ใบจอง</td>
                    <td>{{ filteredPieces }} ชิ้น</td>
                    <td class="num money">฿{{ formatMoney(filteredRevenue) }}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { daysUntil } from '../../lib/bangkokDate';
import './admin-shared.css';

const toast = useToast();

const schedules = ref([]);
const detail = ref(null);
const selectedId = ref(null);
const includePast = ref(false);
const loadingSchedules = ref(false);
const loadingDetail = ref(false);
const printArea = ref(null);
const scheduleQuery = ref('');
const bookingQuery = ref('');

function formatMoney(v) {
  return Number(v || 0).toLocaleString('th-TH');
}

/** "อีก 3 วัน" / "พรุ่งนี้" / "วันนี้" — ถ้อยคำเดียวกับที่ใช้ทั่วระบบ */
function countdownLabel(schedule) {
  const days = daysUntil(schedule?.departure_date);

  if (days === null) return '—';
  if (days === 0) return 'วันนี้';
  if (days === 1) return 'พรุ่งนี้';
  if (days < 0) return `ผ่านไปแล้ว ${Math.abs(days)} วัน`;

  return `อีก ${days} วัน`;
}

function countdownTone(schedule) {
  const days = daysUntil(schedule?.departure_date);

  if (days === null || days < 0) return 'tone-grey';
  if (days <= 1) return 'tone-red';
  if (days <= 3) return 'tone-amber';

  return 'tone-green';
}

const selectedSchedule = computed(
  () => schedules.value.find((s) => s.id === selectedId.value) || null
);

const filteredSchedules = computed(() => {
  const q = scheduleQuery.value.trim().toLowerCase();

  if (!q) return schedules.value;

  return schedules.value.filter((s) =>
    `${s.trip_title || ''} ${s.departure_date_thai || ''} ${s.departure_date || ''}`.toLowerCase().includes(q)
  );
});

const statTiles = computed(() => {
  const bookings = schedules.value.reduce((sum, s) => sum + Number(s.bookings_with_rentals || 0), 0);
  const revenue = schedules.value.reduce((sum, s) => sum + Number(s.rentals_revenue || 0), 0);
  const next = schedules.value.find((s) => !s.is_past);

  return [
    { label: 'รอบที่มีของต้องเตรียม', value: schedules.value.length, icon: 'event_available', tone: 'neutral' },
    { label: 'ใบจองที่เช่าอุปกรณ์', value: bookings, icon: 'receipt_long', tone: 'green' },
    { label: 'รายได้ค่าเช่ารวม', value: `฿${formatMoney(revenue)}`, icon: 'payments', tone: 'amber' },
    { label: next ? `รอบถัดไป · ${next.trip_title}` : 'ไม่มีรอบข้างหน้า', value: next ? countdownLabel(next) : '—', icon: 'schedule', tone: 'grey' },
  ];
});

const filteredBookings = computed(() => {
  const list = detail.value?.bookings || [];
  const q = bookingQuery.value.trim().toLowerCase();

  if (!q) return list;

  return list.filter((b) => {
    const items = (b.items || []).map((it) => it.name).join(' ');

    return `${b.booking_ref} ${b.customer_name} ${b.phone || ''} ${items}`.toLowerCase().includes(q);
  });
});

const filteredPieces = computed(() =>
  filteredBookings.value.reduce(
    (sum, b) => sum + (b.items || []).reduce((n, it) => n + Number(it.quantity || 0), 0),
    0
  )
);

const filteredRevenue = computed(() =>
  filteredBookings.value.reduce((sum, b) => sum + Number(b.rentals_total || 0), 0)
);

/** แถบสัดส่วนเทียบกับของที่ต้องขนมากที่สุดในรอบ — ให้เห็นว่าชิ้นไหนกินพื้นที่รถ */
function sharePercent(item) {
  const max = Math.max(...(detail.value?.items || []).map((i) => Number(i.quantity || 0)), 1);

  return Math.round((Number(item.quantity || 0) / max) * 100);
}

const printedAt = computed(() =>
  new Date().toLocaleString('th-TH', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Asia/Bangkok' })
);

async function loadSchedules() {
  loadingSchedules.value = true;
  try {
    const res = await api.get('/admin/rentals/schedules', {
      params: includePast.value ? { include_past: 1 } : {},
    });
    schedules.value = res.data.data.schedules || [];

    // เลือกรอบแรกให้อัตโนมัติ เพื่อไม่ต้องคลิกซ้ำก่อนเห็นของ
    if (schedules.value.length && !schedules.value.some((s) => s.id === selectedId.value)) {
      await select(schedules.value[0].id);
    } else if (!schedules.value.length) {
      detail.value = null;
      selectedId.value = null;
    }
  } catch {
    toast.error('โหลดรายการรอบเดินทางไม่สำเร็จ');
  } finally {
    loadingSchedules.value = false;
  }
}

async function select(id) {
  selectedId.value = id;
  bookingQuery.value = '';
  loadingDetail.value = true;
  try {
    const res = await api.get(`/admin/rentals/schedules/${id}`);
    detail.value = res.data.data;
  } catch {
    toast.error('โหลดใบรวมอุปกรณ์ไม่สำเร็จ');
    detail.value = null;
  } finally {
    loadingDetail.value = false;
  }
}

function refresh() {
  loadSchedules();
}

/** คัดลอกสรุปเป็นข้อความ ส่งต่อในไลน์ทีมงานได้เลย */
async function copySummary() {
  if (!detail.value) return;

  const lines = [
    `ใบรวมอุปกรณ์เช่า — ${detail.value.schedule.trip_title}`,
    `รอบเดินทาง ${detail.value.schedule.departure_date_thai}`,
    '',
    ...detail.value.items.map((it) => `• ${it.name} ×${it.quantity}`),
    '',
    `รวม ${detail.value.totals.pieces} ชิ้น จาก ${detail.value.totals.bookings} ใบจอง`,
  ];

  try {
    await navigator.clipboard.writeText(lines.join('\n'));
    toast.success('คัดลอกรายการแล้ว');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ ลองเลือกข้อความแล้วคัดลอกเอง');
  }
}

function printList() {
  window.print();
}

onMounted(loadSchedules);
</script>

<style scoped>
.head-actions { display: flex; align-items: center; gap: 10px; }

/* ─── แถบยอดรวม ─────────────────────────── */
.stat-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}

.stat-tile {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 16px;
  background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px;
}

.stat-tile-icon {
  display: flex; align-items: center; justify-content: center;
  width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
}
.stat-tile-icon .material-symbols-rounded { font-size: 20px; }

.tone-neutral { background: rgba(45, 122, 79, 0.08); color: var(--color-accent); }
.tone-green   { background: #F5F5F5; color: #15803d; }
.tone-amber   { background: #fef9c3; color: #a16207; }
.tone-grey    { background: #EEEEEE; color: #6b7280; }
.tone-red     { background: #fee2e2; color: #b91c1c; }

.stat-tile-body { display: flex; flex-direction: column; min-width: 0; }
.stat-tile-value { font-size: 20px; font-weight: 700; color: #111827; line-height: 1.2; }
.stat-tile-label {
  font-size: 12px; color: #6b7280;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ─── สถานะว่าง ─────────────────────────── */
.empty-card {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 64px 20px; text-align: center; color: #9ca3af;
}
.empty-card .material-symbols-rounded { font-size: 44px !important; color: #d1d5db; }
.empty-card p { margin: 6px 0 0; font-size: 15px; font-weight: 700; color: #4b5563; }
.empty-hint { font-size: 13px; }
.empty-card .btn-secondary { margin-top: 14px; }

/* ─── โครงหน้า ──────────────────────────── */
.rental-layout {
  display: grid; grid-template-columns: 300px 1fr; gap: 20px; align-items: start;
}

/* ─── รายชื่อรอบ ────────────────────────── */
.schedule-rail {
  display: flex; flex-direction: column; gap: 8px;
  position: sticky; top: 16px;
  max-height: calc(100vh - 40px); overflow-y: auto;
  padding-right: 2px;
}

.rail-tools {
  display: flex; flex-direction: column; gap: 10px;
  background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 12px; margin-bottom: 2px;
}

.search-box input { padding: 8px 12px 8px 34px; font-size: 13.5px; }
.search-box .material-symbols-rounded { font-size: 19px; }

.past-toggle {
  display: inline-flex; align-items: center; gap: 7px;
  font-size: 13px; color: #6b7280; cursor: pointer;
}
.past-toggle input { accent-color: var(--color-accent); width: 15px; height: 15px; cursor: pointer; }

.rail-empty { font-size: 13px; color: #9ca3af; text-align: center; padding: 20px 0; margin: 0; }

.schedule-item {
  display: flex; flex-direction: column; gap: 4px; text-align: left;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 12px 14px; cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.schedule-item:hover { border-color: #d1d5db; background: #FAFAFA; }
.schedule-item.active {
  border-color: var(--color-accent);
  background: rgba(45, 122, 79, 0.04);
}
.schedule-item.past { opacity: 0.7; }

.sched-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.sched-trip {
  font-size: 14px; font-weight: 700; color: #111827; line-height: 1.35;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.sched-date { font-size: 12.5px; color: #4b5563; }

.sched-meta { display: flex; align-items: center; gap: 12px; font-size: 11.5px; color: #9ca3af; }
.sched-meta > span { display: inline-flex; align-items: center; gap: 3px; }
.sched-meta .material-symbols-rounded { font-size: 14px !important; }

.countdown-chip {
  flex-shrink: 0; border-radius: 20px; padding: 2px 9px;
  font-size: 11.5px; font-weight: 700; white-space: nowrap;
}

/* ─── ใบรวมของรอบที่เลือก ───────────────── */
.rental-detail { min-width: 0; }

.detail-head {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;
  flex-wrap: wrap; background: #fff; border: 1px solid #e5e7eb;
  border-radius: 12px; padding: 18px 22px; margin-bottom: 22px;
}
.detail-title { min-width: 0; }
.detail-title .countdown-chip { display: inline-block; margin-bottom: 8px; }
.detail-head h2 { margin: 0; font-size: 20px; font-weight: 700; color: #111827; line-height: 1.3; }
.detail-head p {
  margin: 4px 0 0; font-size: 13.5px; color: #6b7280;
  display: inline-flex; align-items: center; gap: 5px;
}
.detail-head p .material-symbols-rounded { font-size: 17px !important; }

.totals { display: flex; gap: 10px; flex-wrap: wrap; }
.total-cell {
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  min-width: 92px; padding: 10px 12px;
  background: #FAFAFA; border: 1px solid #EEEEEE; border-radius: 10px;
}
.total-num { font-size: 20px; font-weight: 800; color: var(--color-accent); line-height: 1.2; }
.total-label { font-size: 11.5px; color: #6b7280; }

.section-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
}
.section-label {
  font-size: 13px; font-weight: 700; color: #6b7280;
  text-transform: uppercase; letter-spacing: 0.5px; margin: 0;
}
.search-sm { flex: 0 1 320px; min-width: 200px; }

.link-btn {
  display: inline-flex; align-items: center; gap: 4px;
  background: none; border: none; padding: 0;
  font-size: 13px; color: var(--color-accent); font-weight: 700; cursor: pointer;
}
.link-btn .material-symbols-rounded { font-size: 17px; }
.link-btn:hover { text-decoration: underline; }

/* ─── การ์ดของแต่ละรายการ ───────────────── */
.item-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 12px; margin-bottom: 26px;
}
.item-card {
  display: flex; align-items: center; gap: 12px;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 16px;
  transition: border-color 0.15s;
}
.item-card:hover { border-color: #d1d5db; }

.item-thumb {
  width: 48px; height: 48px; border-radius: 10px; flex-shrink: 0;
  background: #F5F5F5; display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.item-thumb .material-symbols-rounded { color: #9ca3af; font-size: 24px !important; }

.item-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.item-name {
  font-size: 14.5px; font-weight: 700; color: #111827;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.item-sub { font-size: 12px; color: #9ca3af; }
.item-bar { display: block; height: 4px; border-radius: 4px; background: #F0F1F0; margin-top: 5px; overflow: hidden; }
.item-bar-fill { display: block; height: 100%; border-radius: 4px; background: var(--color-accent); }

.item-qty { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; }
.qty-num { font-size: 22px; font-weight: 800; color: var(--color-accent); line-height: 1.1; }
.qty-unit { font-size: 11px; color: #9ca3af; }

/* ─── ตารางว่าของใครบ้าง ────────────────── */
.rentals-table td { vertical-align: middle; }
.rentals-table tfoot td {
  background: #FAFAFA; border-top: 1px solid #e5e7eb;
  font-size: 13px; font-weight: 700; color: #4b5563;
}

.cust-name { display: block; font-weight: 600; color: #111827; }

.rent-chip {
  display: inline-block; background: #F0F1F0; color: #374151;
  border-radius: 6px; padding: 3px 9px; font-size: 12.5px; font-weight: 500;
  margin: 2px 4px 2px 0;
}
.rent-chip strong { color: #111827; font-weight: 700; }

.phone-link {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 12px; color: #6b7280; text-decoration: none; margin-top: 2px;
}
.phone-link .material-symbols-rounded { font-size: 14px !important; }
.phone-link:hover { color: var(--color-accent); }

.num { text-align: right; }
.money { font-weight: 700; color: #111827; }

/* ─── โครงร่างระหว่างโหลด ───────────────── */
.sk {
  display: block; border-radius: 12px;
  background: linear-gradient(90deg, #EEEEEE 25%, #F5F5F5 50%, #EEEEEE 75%);
  background-size: 200% 100%;
  animation: skPulse 1.2s ease-in-out infinite;
}
.sk-rail { height: 78px; }
.sk-head { height: 96px; margin-bottom: 22px; }
.sk-item { height: 74px; }

@keyframes skPulse {
  from { background-position: 200% 0; }
  to { background-position: -200% 0; }
}

/* ─── จอเล็ก ────────────────────────────── */
@media (max-width: 1100px) {
  .stat-strip { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 900px) {
  .rental-layout { grid-template-columns: 1fr; }
  .schedule-rail {
    position: static; max-height: none;
    flex-direction: row; overflow-x: auto; padding-bottom: 6px;
  }
  .rail-tools { flex-shrink: 0; width: 240px; }
  .schedule-item { flex-shrink: 0; width: 240px; }
  .rail-empty { flex-shrink: 0; }
  .totals { width: 100%; }
  .total-cell { flex: 1; }
}

@media (max-width: 560px) {
  .stat-strip { grid-template-columns: 1fr; }
}

/* ─── ตอนพิมพ์: เหลือแค่ใบรวมกระดาษเดียว ── */
.print-head { display: none; }
.print-only { display: none !important; }
.tick-box { display: none; }

@media print {
  :global(.admin-sidebar),
  :global(.admin-topbar) { display: none !important; }
  :global(.admin-main) { margin-left: 0 !important; }
  :global(.admin-content) { padding: 0 !important; }

  .page-header, .stat-strip, .schedule-rail, .no-print, .section-head .link-btn { display: none !important; }
  .rental-layout { display: block; }
  .admin-page { animation: none; }

  .print-head { display: block; margin-bottom: 16px; }
  .print-head h1 { margin: 0; font-size: 20px; font-weight: 700; color: #000; }
  .print-head p { margin: 3px 0 0; font-size: 13px; color: #000; }
  .print-head .print-meta { color: #444; font-size: 11.5px; }

  .detail-head { border: none; padding: 0; margin-bottom: 14px; }
  .detail-head h2, .detail-title { display: none; }
  .total-cell { border: 1px solid #999; background: none; }
  .total-num { color: #000; }

  .item-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; page-break-inside: avoid; }
  .item-card { border-color: #999; padding: 8px 10px; }
  .item-thumb { display: none; }
  .qty-num { color: #000; }

  .print-only { display: table-cell !important; }
  .tick-col { width: 40px; text-align: center; }
  .tick-box {
    display: inline-block; width: 15px; height: 15px;
    border: 1.5px solid #333; border-radius: 3px; flex-shrink: 0;
  }

  .table-card { border-color: #999; }
  .data-table th, .data-table td { border-color: #ccc !important; color: #000 !important; }
  .rentals-table tfoot td { background: none; }
  tr { page-break-inside: avoid; }
}
</style>
