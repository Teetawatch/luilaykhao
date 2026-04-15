<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">analytics</span>
          Analytics Dashboard
        </h1>
        <p class="page-subtitle">สถิติเชิงลึก การจอง รายได้ และแนวโน้ม</p>
      </div>
      <div class="date-range">
        <input v-model="filters.from" type="date" class="date-input" />
        <span class="date-sep">—</span>
        <input v-model="filters.to" type="date" class="date-input" />
        <button class="btn-primary" @click="loadAnalytics" :disabled="loading">
          <span class="material-symbols-rounded" :class="{ 'anim-spin': loading }">sync</span> โหลด
        </button>
      </div>
    </div>

    <div class="loading-state" v-if="loading && !data"><div class="spinner"></div></div>

    <template v-if="data">

      <!-- KPI Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon bg-primary-light">
            <span class="material-symbols-rounded">confirmation_number</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">จองทั้งหมด</span>
            <span class="stat-value">{{ data.summary.total_bookings.toLocaleString() }}</span>
          </div>
          <div class="stat-badge fill-primary">ยืนยัน {{ data.summary.confirmed }} | ยกเลิก {{ data.summary.cancelled }}</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-accent-light">
            <span class="material-symbols-rounded">payments</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">รายได้รวม</span>
            <span class="stat-value">{{ formatShort(data.summary.total_revenue) }}</span>
          </div>
          <div class="stat-badge fill-accent">เฉลี่ย {{ formatMoney(data.summary.avg_order_value) }}/จอง</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-ocean-light">
            <span class="material-symbols-rounded">group_add</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">ลูกค้าใหม่</span>
            <span class="stat-value">{{ data.summary.new_customers.toLocaleString() }}</span>
          </div>
          <div class="stat-badge fill-ocean">Conversion {{ data.summary.conversion_rate }}%</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-gold-light">
            <span class="material-symbols-rounded">star</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">คะแนนเฉลี่ย</span>
            <span class="stat-value">{{ data.summary.avg_rating }}</span>
          </div>
          <div class="stat-badge fill-gold">จาก {{ data.summary.total_reviews }} รีวิว</div>
        </div>
      </div>

      <!-- Seat Alerts Banner -->
      <div v-if="seatAlerts.length > 0" class="alert-banner">
        <div class="alert-icon"><span class="material-symbols-rounded">local_fire_department</span></div>
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
          <h3>
            <span class="material-symbols-rounded">trending_up</span> แนวโน้มรายได้
          </h3>
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
            <h3><span class="material-symbols-rounded">emoji_events</span> ทริปยอดนิยม</h3>
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
            <h3><span class="material-symbols-rounded">calendar_month</span> การจองตามวัน</h3>
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
            <h3><span class="material-symbols-rounded">star_half</span> การกระจายคะแนน</h3>
          </div>
          <div class="rating-dist">
            <div
              v-for="r in [...data.rating_distribution].reverse()"
              :key="r.stars"
              class="rating-row">
              <div class="rating-stars">
                <span v-for="s in r.stars" :key="s" class="material-symbols-rounded star-icon">star</span>
              </div>
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

.heading-icon {
  color: var(--color-accent);
  font-size: 28px;
}

.anim-spin {
  animation: spin 1s linear infinite;
}

.date-range {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.date-input {
  padding: 10px 16px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  font-size: 14px;
  outline: none;
  color: var(--color-text-dark);
  background-color: var(--color-white);
  transition: all 0.2s ease;
  font-family: var(--font-anuphan);
}

.date-input:focus { 
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(45, 122, 79, 0.1);
}
.date-sep { color: var(--color-text-muted); font-size: 14px; }

/* KPI Grid */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
  margin-bottom: 24px;
}

.stat-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 16px;
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 15px rgba(0, 0, 0, 0.05);
}

.stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat-icon span {
  font-size: 24px;
}

.bg-primary-light { background: #E8F0EC; color: var(--color-primary); }
.bg-accent-light { background: #EAF2EE; color: var(--color-accent); }
.bg-ocean-light { background: #E8F0F5; color: var(--color-ocean); }
.bg-gold-light { background: #F9F4EB; color: var(--color-gold); }

.stat-content {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.stat-label {
  font-size: 14px;
  color: var(--color-text-muted);
  font-weight: 500;
}

.stat-value {
  font-size: 28px;
  font-weight: 700;
  color: var(--color-text-dark);
  font-family: var(--font-anuphan);
}

.stat-badge {
  font-size: 12px;
  padding: 6px 12px;
  border-radius: 8px;
  font-weight: 600;
  display: inline-block;
  align-self: flex-start;
}

.fill-primary { background: var(--color-sand); color: var(--color-primary-mid); }
.fill-accent { background: var(--color-sand); color: var(--color-accent); }
.fill-ocean { background: var(--color-sand); color: var(--color-ocean); }
.fill-gold { background: var(--color-sand); color: var(--color-gold-dark); }

/* Alert Banner */
.alert-banner {
  display: flex;
  gap: 16px;
  background: var(--color-white);
  border: 1px solid #fed7aa;
  border-left: 4px solid #ea580c;
  border-radius: 12px;
  padding: 16px 20px;
  margin-bottom: 24px;
  align-items: flex-start;
  box-shadow: 0 4px 6px rgba(234, 88, 12, 0.05);
}

.alert-icon {
  font-size: 24px;
  color: #ea580c;
}

.alert-title {
  font-size: 15px;
  font-weight: 700;
  color: #9a3412;
  margin: 0 0 8px;
}

.alert-items {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.alert-item {
  font-size: 13px;
  background: #ffedd5;
  color: #9a3412;
  border-radius: 6px;
  padding: 4px 12px;
  font-weight: 600;
}

/* Chart Cards */
.chart-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 16px;
  overflow: hidden;
  margin-bottom: 24px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
}

.full-width { width: 100%; }

.card-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--color-sand-dark);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-header h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text-dark);
  display: flex;
  align-items: center;
  gap: 10px;
}

.card-header h3 span { color: var(--color-accent); font-size: 20px; }

.period-label {
  font-size: 13px;
  color: var(--color-text-muted);
  font-weight: 500;
}

/* Trend Chart */
.trend-chart {
  display: flex;
  padding: 24px 24px 16px;
  height: 260px;
  gap: 16px;
}

.trend-y-axis {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding-bottom: 28px;
  width: 50px;
  text-align: right;
}

.y-label {
  font-size: 11px;
  color: var(--color-text-muted);
  font-weight: 500;
}

.trend-bars-wrap {
  flex: 1;
  display: flex;
  align-items: flex-end;
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 8px;
}

.trend-bar-col {
  flex: 1;
  min-width: 32px;
  max-width: 64px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  height: 100%;
  justify-content: flex-end;
}

.trend-bar-container {
  width: 100%;
  height: 180px;
  display: flex;
  align-items: flex-end;
  border-radius: 6px;
  background: var(--color-sand);
  overflow: hidden;
}

.trend-bar {
  width: 100%;
  background: var(--color-accent);
  min-height: 4px;
  transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  cursor: pointer;
  border-radius: 6px 6px 0 0;
}

.trend-bar:hover { background: var(--color-accent-mid); }

.trend-bar:hover .trend-tooltip { opacity: 1; transform: translateX(-50%) translateY(0); }

.trend-tooltip {
  opacity: 0;
  position: absolute;
  top: -36px;
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  background: var(--color-text-dark);
  color: var(--color-white);
  font-size: 11px;
  font-weight: 600;
  padding: 6px 10px;
  border-radius: 6px;
  white-space: nowrap;
  pointer-events: none;
  transition: all 0.2s ease;
  z-index: 10;
}

.trend-tooltip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border-width: 4px;
  border-style: solid;
  border-color: var(--color-text-dark) transparent transparent transparent;
}

.trend-label {
  font-size: 11px;
  color: var(--color-text-muted);
  font-weight: 600;
  text-align: center;
}

/* Bottom Row */
.bottom-row {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr;
  gap: 24px;
}

/* Top Trips */
.top-trips {
  padding: 16px 24px 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.top-trip-row {
  display: flex;
  align-items: center;
  gap: 16px;
}

.rank {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-sand);
  color: var(--color-text-muted);
  flex-shrink: 0;
}

.rank-1 { background: #FEF3C7; color: #B45309; }
.rank-2 { background: #E5E7EB; color: #4B5563; }
.rank-3 { background: #FFEDD5; color: #C2410C; }

.trip-info { flex: 1; min-width: 0; }
.trip-name { font-size: 14px; font-weight: 700; color: var(--color-text-dark); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.trip-sub { font-size: 12px; color: var(--color-text-muted); margin: 4px 0 0; }

.trip-revenue { text-align: right; min-width: 90px; }
.rev-amount { font-size: 14px; font-weight: 700; color: var(--color-accent); display: block; }

.rev-bar-track {
  height: 6px;
  background: var(--color-sand);
  border-radius: 3px;
  margin-top: 6px;
  overflow: hidden;
}

.rev-bar-fill {
  height: 100%;
  background: var(--color-accent);
  border-radius: 3px;
  transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Day of Week */
.dow-chart {
  padding: 24px;
  display: flex;
  justify-content: space-around;
  align-items: flex-end;
  height: 220px;
  gap: 8px;
}

.dow-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.dow-count { font-size: 12px; font-weight: 700; color: var(--color-text-mid); }

.dow-bar-track {
  width: 100%;
  max-width: 36px;
  height: 120px;
  display: flex;
  align-items: flex-end;
  background: var(--color-sand);
  border-radius: 6px;
  overflow: hidden;
}

.dow-bar {
  width: 100%;
  background: var(--color-ocean);
  border-radius: 6px 6px 0 0;
  min-height: 4px;
  transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.dow-bar:hover {
  background: var(--color-ocean-mid);
}

.dow-label { font-size: 12px; font-weight: 600; color: var(--color-text-muted); }

/* Rating Distribution */
.rating-dist {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.rating-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.rating-stars {
  display: flex;
  align-items: center;
  width: 70px;
  justify-content: flex-end;
}

.star-icon {
  font-size: 14px;
  color: var(--color-gold);
}

.rating-track {
  flex: 1;
  height: 10px;
  background: var(--color-sand);
  border-radius: 5px;
  overflow: hidden;
}

.rating-fill {
  height: 100%;
  border-radius: 5px;
  transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Solid non-gradient ratings */
.rating-fill-5 { background: var(--color-accent); }
.rating-fill-4 { background: #4ADE80; }
.rating-fill-3 { background: var(--color-gold); }
.rating-fill-2 { background: #F97316; }
.rating-fill-1 { background: #EF4444; }

.rating-count { font-size: 13px; font-weight: 700; color: var(--color-text-mid); width: 28px; text-align: right; }

.no-data {
  padding: 40px;
  text-align: center;
  color: var(--color-text-muted);
  font-size: 15px;
  font-weight: 500;
}

@media (max-width: 1100px) {
  .bottom-row { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 768px) {
  .bottom-row { grid-template-columns: 1fr; }
  .date-range { flex-wrap: wrap; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 480px) {
  .stats-grid { grid-template-columns: 1fr; }
}
</style>
