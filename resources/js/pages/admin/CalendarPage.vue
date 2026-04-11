<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-calendar-alt"></i> ปฏิทินทริปและตารางเวลา</h1>
        <p class="page-subtitle">ดูรอบเดินทางทั้งหมดในรูปแบบปฏิทิน</p>
      </div>
      <div class="header-actions">
        <select v-model="filters.trip_id" @change="fetchData()" class="filter-select">
          <option value="">ทุกทริป</option>
          <option v-for="t in allTrips" :key="t.id" :value="t.id">{{ t.title }}</option>
        </select>
      </div>
    </div>

    <!-- Month Navigation -->
    <div class="calendar-nav">
      <button class="btn-icon" @click="prevMonth"><i class="fas fa-chevron-left"></i></button>
      <h2 class="month-label">{{ monthLabel }}</h2>
      <button class="btn-icon" @click="nextMonth"><i class="fas fa-chevron-right"></i></button>
      <button class="btn-secondary btn-today" @click="goToday">วันนี้</button>
    </div>

    <!-- Loading -->
    <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>

    <!-- Calendar Grid -->
    <div class="calendar-card" v-else>
      <div class="calendar-header">
        <div class="cal-head" v-for="d in dayNames" :key="d">{{ d }}</div>
      </div>
      <div class="calendar-body">
        <div
          v-for="(cell, idx) in calendarCells"
          :key="idx"
          class="cal-cell"
          :class="{
            'other-month': !cell.currentMonth,
            'today': cell.isToday,
            'has-events': cell.events.length > 0,
          }"
        >
          <span class="cal-date">{{ cell.day }}</span>
          <div class="cal-events" v-if="cell.events.length">
            <div
              v-for="ev in cell.events.slice(0, 3)"
              :key="ev.id"
              class="cal-event"
              :class="`event-${ev.status}`"
              @click="openDetail(ev)"
              :title="`${ev.trip_title} (${ev.booked_seats}/${ev.total_seats})`"
            >
              <span class="ev-dot" :class="`dot-${ev.trip_type}`"></span>
              <span class="ev-text">{{ ev.trip_title }}</span>
              <span class="ev-seats">{{ ev.booked_seats }}/{{ ev.total_seats }}</span>
            </div>
            <div v-if="cell.events.length > 3" class="cal-more">
              +{{ cell.events.length - 3 }} อื่นๆ
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Legend -->
    <div class="calendar-legend">
      <div class="legend-item"><span class="ev-dot dot-trekking"></span> เดินป่า</div>
      <div class="legend-item"><span class="ev-dot dot-diving"></span> ดำน้ำ</div>
      <div class="legend-item"><span class="ev-dot dot-snorkeling"></span> ดำน้ำตื้น</div>
      <div class="legend-item"><span class="ev-dot dot-climbing"></span> ปีนผา</div>
      <div class="legend-sep"></div>
      <div class="legend-item"><span class="status-dot-legend open"></span> เปิดรับจอง</div>
      <div class="legend-item"><span class="status-dot-legend full"></span> เต็ม</div>
      <div class="legend-item"><span class="status-dot-legend closed"></span> ปิด</div>
    </div>

    <!-- Detail Modal -->
    <div class="modal-overlay" v-if="selectedEvent">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ selectedEvent.trip_title }}</h2>
          <button class="modal-close" @click="selectedEvent = null"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <div class="detail-grid">
            <div class="detail-item">
              <span class="detail-label">วันเดินทาง</span>
              <span class="detail-value">{{ formatDate(selectedEvent.start) }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">วันกลับ</span>
              <span class="detail-value">{{ formatDate(selectedEvent.end) }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ยานพาหนะ</span>
              <span class="detail-value">{{ selectedEvent.vehicle || '-' }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ประเภทขนส่ง</span>
              <span class="detail-value">{{ transportLabels[selectedEvent.transport_type] || '-' }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ที่นั่ง</span>
              <span class="detail-value">{{ selectedEvent.booked_seats }} / {{ selectedEvent.total_seats }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ว่าง</span>
              <span class="detail-value seats-avail">{{ selectedEvent.available_seats }} ที่นั่ง</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">จองยืนยัน</span>
              <span class="detail-value">{{ selectedEvent.confirmed_bookings }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">จองรอดำเนินการ</span>
              <span class="detail-value">{{ selectedEvent.pending_bookings }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ราคา</span>
              <span class="detail-value">฿{{ Number(selectedEvent.price).toLocaleString() }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">สถานะ</span>
              <span class="status-badge" :class="`status-${selectedEvent.status}`">{{ statusLabels[selectedEvent.status] }}</span>
            </div>
          </div>

          <!-- Pickup Points -->
          <div v-if="selectedEvent.pickup_points?.length" class="pickup-summary">
            <div class="pickup-summary-title"><i class="fas fa-map-marker-alt"></i> จุดขึ้นรถ</div>
            <div class="pickup-summary-list">
              <div v-for="pt in selectedEvent.pickup_points" :key="pt.id" class="pickup-summary-item">
                <span class="pickup-summary-region">{{ pt.region_label }}</span>
                <span class="pickup-summary-loc">{{ pt.pickup_location }}<span v-if="pt.notes" class="pickup-summary-notes"> · {{ pt.notes }}</span></span>
                <span class="pickup-summary-price">฿{{ Number(pt.price).toLocaleString() }}</span>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <router-link :to="`/admin/schedules`" class="btn-primary">
              <i class="fas fa-edit"></i> จัดการรอบเดินทาง
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();
const currentDate = ref(new Date());
const selectedEvent = ref(null);
const allTrips = ref([]);
const filters = reactive({ trip_id: '' });

const dayNames = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

const statusLabels = { open: 'เปิด', closed: 'ปิด', full: 'เต็ม', cancelled: 'ยกเลิก' };
const transportLabels = { van: 'รถตู้', boat: 'เรือ', bus: 'รถบัส' };

const monthLabel = computed(() => {
  return currentDate.value.toLocaleDateString('th-TH', { month: 'long', year: 'numeric' });
});

const calendarCells = computed(() => {
  const year = currentDate.value.getFullYear();
  const month = currentDate.value.getMonth();
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrev = new Date(year, month, 0).getDate();
  const today = new Date();

  const cells = [];

  // Previous month
  for (let i = firstDay - 1; i >= 0; i--) {
    const d = daysInPrev - i;
    const dateStr = formatDateStr(year, month - 1, d);
    cells.push({ day: d, currentMonth: false, isToday: false, events: getEventsForDate(dateStr) });
  }

  // Current month
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = formatDateStr(year, month, d);
    const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === d;
    cells.push({ day: d, currentMonth: true, isToday, events: getEventsForDate(dateStr) });
  }

  // Next month
  const remaining = 42 - cells.length;
  for (let d = 1; d <= remaining; d++) {
    const dateStr = formatDateStr(year, month + 1, d);
    cells.push({ day: d, currentMonth: false, isToday: false, events: getEventsForDate(dateStr) });
  }

  return cells;
});

function formatDateStr(year, month, day) {
  const dt = new Date(year, month, day);
  return dt.toISOString().split('T')[0];
}

function getEventsForDate(dateStr) {
  return (admin.calendarEvents || []).filter(ev => {
    return ev.start <= dateStr && ev.end >= dateStr;
  });
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function prevMonth() {
  const d = new Date(currentDate.value);
  d.setMonth(d.getMonth() - 1);
  currentDate.value = d;
}

function nextMonth() {
  const d = new Date(currentDate.value);
  d.setMonth(d.getMonth() + 1);
  currentDate.value = d;
}

function goToday() {
  currentDate.value = new Date();
}

function openDetail(ev) {
  selectedEvent.value = ev;
}

async function fetchData() {
  const year = currentDate.value.getFullYear();
  const month = currentDate.value.getMonth();
  const start = new Date(year, month - 1, 1).toISOString().split('T')[0];
  const end = new Date(year, month + 2, 0).toISOString().split('T')[0];
  await admin.fetchCalendarSchedules({ start, end, trip_id: filters.trip_id || undefined });
}

async function loadTrips() {
  try {
    await admin.fetchTrips({ per_page: 100 });
    allTrips.value = admin.trips.data;
  } catch (e) { /* ignore */ }
}

watch(currentDate, fetchData);

onMounted(() => {
  fetchData();
  loadTrips();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.header-actions {
  display: flex;
  gap: 10px;
  align-items: center;
}

.filter-select {
  padding: 9px 14px;
  background: #ffffff;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  color: #111827;
  font-size: 14px;
  outline: none;
  min-width: 180px;
}

.calendar-nav {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.month-label {
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  margin: 0;
  min-width: 200px;
  text-align: center;
}

.btn-today {
  margin-left: auto;
  padding: 7px 16px;
  font-size: 13px;
}

.calendar-card {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  overflow: hidden;
}

.calendar-header {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  background: #FAFAFA;
  border-bottom: 1px solid #e5e7eb;
}

.cal-head {
  padding: 10px;
  text-align: center;
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.calendar-body {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
}

.cal-cell {
  min-height: 100px;
  border-right: 1px solid #f3f4f6;
  border-bottom: 1px solid #f3f4f6;
  padding: 6px;
  position: relative;
}

.cal-cell:nth-child(7n) {
  border-right: none;
}

.cal-cell.other-month {
  background: #fafafa;
}

.cal-cell.other-month .cal-date {
  color: #d1d5db;
}

.cal-cell.today {
  background: #f0faf4;
}

.cal-cell.today .cal-date {
  background: #2d7a4f;
  color: #fff;
  border-radius: 50%;
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.cal-date {
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  margin-bottom: 4px;
  display: inline-block;
}

.cal-events {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.cal-event {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 2px 5px;
  border-radius: 4px;
  font-size: 11px;
  cursor: pointer;
  transition: background 0.1s;
  background: #FAFAFA;
  border: 1px solid #EEEEEE;
}

.cal-event:hover {
  background: #f0faf4;
  border-color: #a7d4b8;
}

.cal-event.event-full {
  background: #fef9c3;
  border-color: #fde68a;
}

.cal-event.event-closed, .cal-event.event-cancelled {
  background: #EEEEEE;
  border-color: #e5e7eb;
  opacity: 0.6;
}

.ev-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}

.dot-trekking { background: #16a34a; }
.dot-diving { background: #1d4ed8; }
.dot-snorkeling { background: #0369a1; }
.dot-climbing { background: #d97706; }

.ev-text {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #374151;
  font-weight: 500;
}

.ev-seats {
  font-size: 10px;
  color: #6b7280;
  white-space: nowrap;
}

.cal-more {
  font-size: 10px;
  color: #2d7a4f;
  font-weight: 600;
  padding: 1px 5px;
  cursor: pointer;
}

.calendar-legend {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 16px;
  flex-wrap: wrap;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #6b7280;
}

.legend-sep {
  width: 1px;
  height: 16px;
  background: #e5e7eb;
}

.status-dot-legend {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}

.status-dot-legend.open { background: #16a34a; }
.status-dot-legend.full { background: #d97706; }
.status-dot-legend.closed { background: #9ca3af; }

/* Detail Grid */
.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.detail-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.detail-label {
  font-size: 12px;
  color: #6b7280;
  font-weight: 500;
}

.detail-value {
  font-size: 15px;
  color: #111827;
  font-weight: 600;
}

.seats-avail {
  color: #2d7a4f;
}

.pickup-summary {
  margin-top: 16px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}

.pickup-summary-title {
  padding: 8px 12px;
  background: #f0faf4;
  border-bottom: 1px solid #e5e7eb;
  font-size: 12px;
  font-weight: 700;
  color: #2d7a4f;
  display: flex;
  align-items: center;
  gap: 6px;
}

.pickup-summary-list {
  display: flex;
  flex-direction: column;
}

.pickup-summary-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 7px 12px;
  border-bottom: 1px solid #f3f4f6;
  font-size: 13px;
}

.pickup-summary-item:last-child {
  border-bottom: none;
}

.pickup-summary-region {
  font-weight: 700;
  color: #2d7a4f;
  min-width: 72px;
  flex-shrink: 0;
}

.pickup-summary-loc {
  flex: 1;
  color: #374151;
  font-size: 12px;
}

.pickup-summary-notes {
  color: #9ca3af;
}

.pickup-summary-price {
  font-weight: 700;
  color: #111827;
  white-space: nowrap;
  font-size: 12px;
}

@media (max-width: 768px) {
  .cal-cell { min-height: 60px; }
  .ev-text { display: none; }
  .ev-seats { display: none; }
  .calendar-header, .calendar-body { font-size: 11px; }
}
</style>
