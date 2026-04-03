<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-chart-area"></i> Analytics Dashboard</h1>
        <p class="page-subtitle">สถิติเชิงลึก การจอง รายได้ และแนวโน้ม</p>
      </div>
      <div class="date-range">
        <input v-model="filters.from" type="date" class="date-input" />
        <span class="date-sep">—</span>
        <input v-model="filters.to" type="date" class="date-input" />
        <button class="btn-primary" @click="loadAnalytics" :disabled="loading">
          <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i> โหลด
        </button>
      </div>
    </div>

    <div class="loading-state" v-if="loading && !data"><div class="spinner"></div></div>

    <template v-if="data">

      <!-- KPI Cards -->
      <div class="stats-grid">
        <div class="stat-card stat-primary">
          <div class="stat-icon"><i class="fas fa-ticket-alt"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ data.summary.total_bookings.toLocaleString() }}</span>
            <span class="stat-label">จองทั้งหมด</span>
          </div>
          <div class="stat-badge">ยืนยัน {{ data.summary.confirmed }} | ยกเลิก {{ data.summary.cancelled }}</div>
        </div>

        <div class="stat-card stat-revenue">
          <div class="stat-icon"><i class="fas fa-coins"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ formatShort(data.summary.total_revenue) }}</span>
            <span class="stat-label">รายได้รวม</span>
          </div>
          <div class="stat-badge">เฉลี่ย {{ formatMoney(data.summary.avg_order_value) }}/จอง</div>
        </div>

        <div class="stat-card stat-success">
          <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ data.summary.new_customers.toLocaleString() }}</span>
            <span class="stat-label">ลูกค้าใหม่</span>
          </div>
          <div class="stat-badge">Conversion {{ data.summary.conversion_rate }}%</div>
        </div>

        <div class="stat-card stat-info">
          <div class="stat-icon"><i class="fas fa-star"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ data.summary.avg_rating }} ★</span>
            <span class="stat-label">คะแนนเฉลี่ย</span>
          </div>
          <div class="stat-badge">จาก {{ data.summary.total_reviews }} รีวิว</div>
        </div>
      </div>

      <!-- Seat Alerts Banner -->
      <div v-if="seatAlerts.length > 0" class="alert-banner">
        <div class="alert-icon"><i class="fas fa-fire"></i></div>
        <div class="alert-content">
          <p class="alert-title">ที่นั่งใกล้เต็ม! {{ seatAlerts.length }} รอบเดินทาง</p>
          <div class="alert-items">
            <span
              v-for="s in seatAlerts.slice(0, 4)"
              :key="s.schedule_id"
              class="alert-item">
              {{ s.trip_title }} ({{ s.available_seats }} ที่ว่าง) — {{ s.departure_date }}
            </span>
          </div>
        </div>
      </div>

      <!-- Revenue Trend Chart -->
      <div class="chart-card full-width">
        <div class="card-header">
          <h3><i class="fas fa-chart-line"></i> แนวโน้มรายได้</h3>
          <span class="period-label">{{ data.period.from }} ถึง {{ data.period.to }}</span>
        </div>
        <div class="trend-chart" v-if="data.revenue_trend.length">
          <div class="trend-y-axis">
            <span v-for="y in yAxisLabels" :key="y" class="y-label">{{ y }}</span>
          </div>
          <div class="trend-bars-wrap">
            <div
              v-for="(t, i) in data.revenue_trend"
              :key="i"
              class="trend-bar-col"
              :title="`${t.period}: ${formatMoney(t.revenue)} (${t.bookings} จอง)`">
              <div class="trend-bar-container">
                <div
                  class="trend-bar"
                  :style="{ height: getTrendPercent(t.revenue) + '%' }">
                  <span class="trend-tooltip">{{ formatShort(t.revenue) }}</span>
                </div>
              </div>
              <span class="trend-label">{{ shortLabel(t.period) }}</span>
            </div>
          </div>
        </div>
        <div v-else class="no-data">ไม่มีข้อมูลในช่วงเวลานี้</div>
      </div>

      <!-- Bottom Row: Top Trips + DoW + Ratings -->
      <div class="bottom-row">

        <!-- Top Trips -->
        <div class="chart-card">
          <div class="card-header">
            <h3><i class="fas fa-trophy"></i> ทริปยอดนิยม</h3>
          </div>
          <div class="top-trips">
            <div
              v-for="(t, i) in data.top_trips"
              :key="t.trip_id"
              class="top-trip-row">
              <span class="rank" :class="`rank-${i + 1}`">{{ i + 1 }}</span>
              <div class="trip-info">
                <p class="trip-name">{{ t.title }}</p>
                <p class="trip-sub">{{ t.bookings_count }} จอง</p>
              </div>
              <div class="trip-revenue">
                <span class="rev-amount">{{ formatShort(t.revenue) }}</span>
                <div class="rev-bar-track">
                  <div
                    class="rev-bar-fill"
                    :style="{ width: getTopTripPercent(t.revenue) + '%' }"></div>
                </div>
              </div>
            </div>
            <div v-if="!data.top_trips.length" class="no-data">ยังไม่มีข้อมูล</div>
          </div>
        </div>

        <!-- Day of Week -->
        <div class="chart-card">
          <div class="card-header">
            <h3><i class="fas fa-calendar-week"></i> การจองตามวัน</h3>
          </div>
          <div class="dow-chart">
            <div
              v-for="d in data.bookings_by_dow"
              :key="d.day"
              class="dow-col">
              <span class="dow-count">{{ d.count }}</span>
              <div class="dow-bar-track">
                <div
                  class="dow-bar"
                  :style="{ height: getDowPercent(d.count) + '%' }"></div>
              </div>
              <span class="dow-label">{{ d.day }}</span>
            </div>
          </div>
        </div>

        <!-- Rating Distribution -->
        <div class="chart-card">
          <div class="card-header">
            <h3><i class="fas fa-star"></i> การกระจายคะแนน</h3>
          </div>
          <div class="rating-dist">
            <div
              v-for="r in [...data.rating_distribution].reverse()"
              :key="r.stars"
              class="rating-row">
              <span class="rating-stars">{{ '★'.repeat(r.stars) }}</span>
              <div class="rating-track">
                <div
                  class="rating-fill"
                  :class="`rating-fill-${r.stars}`"
                  :style="{ width: getRatingPercent(r.count) + '%' }"></div>
              </div>
              <span class="rating-count">{{ r.count }}</span>
            </div>
          </div>
        </div>

      </div>

    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../lib/axios';

const loading = ref(false);
const data = ref(null);
const seatAlerts = ref([]);

const filters = reactive({
  from: new Date(new Date().setDate(1)).toISOString().slice(0, 10),
  to: new Date().toISOString().slice(0, 10),
});

async function loadAnalytics() {
  loading.value = true;
  try {
    const [analyticsRes, alertsRes] = await Promise.all([
      api.get('/admin/analytics/overview', { params: filters }),
      api.get('/admin/analytics/seat-alerts'),
    ]);
    data.value = analyticsRes.data.data;
    seatAlerts.value = alertsRes.data.data;
  } catch {
    alert('โหลดข้อมูลไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

// Chart helpers
const maxRevenue = computed(() => {
  if (!data.value?.revenue_trend?.length) return 1;
  return Math.max(...data.value.revenue_trend.map(t => t.revenue), 1);
});

const maxTopTrip = computed(() => {
  if (!data.value?.top_trips?.length) return 1;
  return Math.max(...data.value.top_trips.map(t => t.revenue), 1);
});

const maxDow = computed(() => {
  if (!data.value?.bookings_by_dow?.length) return 1;
  return Math.max(...data.value.bookings_by_dow.map(d => d.count), 1);
});

const maxRating = computed(() => {
  if (!data.value?.rating_distribution?.length) return 1;
  return Math.max(...data.value.rating_distribution.map(r => r.count), 1);
});

const yAxisLabels = computed(() => {
  const max = maxRevenue.value;
  return [formatShort(max), formatShort(max * 0.75), formatShort(max * 0.5), formatShort(max * 0.25), '0'];
});

function getTrendPercent(rev) { return maxRevenue.value > 0 ? (rev / maxRevenue.value) * 100 : 0; }
function getTopTripPercent(rev) { return maxTopTrip.value > 0 ? (rev / maxTopTrip.value) * 100 : 0; }
function getDowPercent(count) { return maxDow.value > 0 ? (count / maxDow.value) * 100 : 0; }
function getRatingPercent(count) { return maxRating.value > 0 ? (count / maxRating.value) * 100 : 0; }

function shortLabel(period) {
  if (!period) return '';
  const parts = period.split('-');
  if (parts.length === 3) return `${parts[2]}/${parts[1]}`;
  if (parts.length === 2) {
    const months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return months[parseInt(parts[1])] || period;
  }
  return period;
}

function formatMoney(amount) {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
}

function formatShort(amount) {
  if (!amount) return '0';
  if (amount >= 1000000) return (amount / 1000000).toFixed(1) + 'M';
  if (amount >= 1000) return (amount / 1000).toFixed(0) + 'K';
  return Math.round(amount).toString();
}

onMounted(loadAnalytics);
</script>

<style scoped>
@import url('./admin-shared.css');

.date-range {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.date-input {
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 13px;
  outline: none;
  color: #111827;
}

.date-input:focus { border-color: #2d7a4f; }
.date-sep { color: #9ca3af; font-size: 14px; }

/* Alert Banner */
.alert-banner {
  display: flex;
  gap: 14px;
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-left: 4px solid #ea580c;
  border-radius: 10px;
  padding: 14px 18px;
  margin-bottom: 20px;
  align-items: flex-start;
}

.alert-icon {
  font-size: 20px;
  color: #ea580c;
  padding-top: 2px;
}

.alert-title {
  font-size: 14px;
  font-weight: 700;
  color: #9a3412;
  margin: 0 0 6px;
}

.alert-items {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.alert-item {
  font-size: 12px;
  background: #ffedd5;
  color: #7c2d12;
  border-radius: 20px;
  padding: 2px 10px;
  font-weight: 500;
}

/* Chart Cards */
.chart-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 20px;
}

.full-width { width: 100%; }

.card-header {
  padding: 14px 20px;
  border-bottom: 1px solid #f3f4f6;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #111827;
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-header h3 i { color: #2d7a4f; }

.period-label {
  font-size: 12px;
  color: #9ca3af;
}

/* Trend Chart */
.trend-chart {
  display: flex;
  padding: 16px 20px 8px;
  height: 220px;
  gap: 8px;
}

.trend-y-axis {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding-bottom: 24px;
  width: 44px;
  text-align: right;
}

.y-label {
  font-size: 10px;
  color: #9ca3af;
}

.trend-bars-wrap {
  flex: 1;
  display: flex;
  align-items: flex-end;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 8px;
}

.trend-bar-col {
  flex: 1;
  min-width: 28px;
  max-width: 60px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  height: 100%;
  justify-content: flex-end;
}

.trend-bar-container {
  width: 100%;
  height: 160px;
  display: flex;
  align-items: flex-end;
}

.trend-bar {
  width: 100%;
  background: linear-gradient(to top, #2d7a4f, #4ade80);
  border-radius: 4px 4px 0 0;
  min-height: 4px;
  transition: height 0.5s ease;
  position: relative;
  cursor: pointer;
}

.trend-bar:hover .trend-tooltip { display: block; }

.trend-tooltip {
  display: none;
  position: absolute;
  top: -28px;
  left: 50%;
  transform: translateX(-50%);
  background: #111827;
  color: #fff;
  font-size: 10px;
  padding: 3px 6px;
  border-radius: 4px;
  white-space: nowrap;
  z-index: 10;
}

.trend-label {
  font-size: 10px;
  color: #6b7280;
  text-align: center;
}

/* Bottom Row */
.bottom-row {
  display: grid;
  grid-template-columns: 1.6fr 1fr 1fr;
  gap: 16px;
}

/* Top Trips */
.top-trips {
  padding: 12px 20px 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.top-trip-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rank {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f3f4f6;
  color: #6b7280;
  flex-shrink: 0;
}

.rank-1 { background: #fef9c3; color: #a16207; }
.rank-2 { background: #f3f4f6; color: #4b5563; }
.rank-3 { background: #fff7ed; color: #ea580c; }

.trip-info { flex: 1; min-width: 0; }
.trip-name { font-size: 13px; font-weight: 600; color: #111827; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.trip-sub { font-size: 11px; color: #9ca3af; margin: 0; }

.trip-revenue { text-align: right; min-width: 80px; }
.rev-amount { font-size: 13px; font-weight: 700; color: #2d7a4f; display: block; }

.rev-bar-track {
  height: 4px;
  background: #f3f4f6;
  border-radius: 2px;
  margin-top: 4px;
  overflow: hidden;
}

.rev-bar-fill {
  height: 100%;
  background: #2d7a4f;
  border-radius: 2px;
  transition: width 0.5s ease;
}

/* Day of Week */
.dow-chart {
  padding: 16px 20px;
  display: flex;
  justify-content: space-around;
  align-items: flex-end;
  height: 180px;
  gap: 4px;
}

.dow-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.dow-count { font-size: 11px; font-weight: 700; color: #374151; }

.dow-bar-track {
  width: 100%;
  max-width: 32px;
  height: 100px;
  display: flex;
  align-items: flex-end;
}

.dow-bar {
  width: 100%;
  background: #1d4ed8;
  border-radius: 3px 3px 0 0;
  min-height: 3px;
  transition: height 0.5s ease;
}

.dow-label { font-size: 11px; color: #6b7280; }

/* Rating Distribution */
.rating-dist {
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.rating-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.rating-stars {
  width: 52px;
  font-size: 12px;
  color: #f59e0b;
  letter-spacing: 1px;
  text-align: right;
}

.rating-track {
  flex: 1;
  height: 8px;
  background: #f3f4f6;
  border-radius: 4px;
  overflow: hidden;
}

.rating-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.6s ease;
}

.rating-fill-5 { background: #16a34a; }
.rating-fill-4 { background: #65a30d; }
.rating-fill-3 { background: #d97706; }
.rating-fill-2 { background: #ea580c; }
.rating-fill-1 { background: #dc2626; }

.rating-count { font-size: 12px; font-weight: 600; color: #374151; width: 24px; text-align: right; }

.no-data {
  padding: 32px;
  text-align: center;
  color: #9ca3af;
  font-size: 14px;
}

@media (max-width: 1100px) {
  .bottom-row { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 700px) {
  .bottom-row { grid-template-columns: 1fr; }
  .date-range { flex-wrap: wrap; }
}
</style>
