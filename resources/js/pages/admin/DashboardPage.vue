<template>
  <div class="admin-dashboard">
    <div class="page-header">
      <h1 class="page-title">
        <span class="material-symbols-rounded heading-icon">dashboard</span>
        แดชบอร์ด
      </h1>
      <p class="page-subtitle">ภาพรวมระบบ Luilaykhao</p>
    </div>

    <!-- Loading State -->
    <div class="loading-state" v-if="loading">
      <div class="spinner"></div>
      <p>กำลังโหลดข้อมูล...</p>
    </div>

    <template v-else-if="stats">
      <!-- Stats Grid -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon bg-primary-light">
            <span class="material-symbols-rounded">map</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">ทริปที่เปิดอยู่</span>
            <span class="stat-value">{{ stats.active_trips }}</span>
          </div>
          <div class="stat-badge fill-primary">จาก {{ stats.total_trips }} ทริป</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-accent-light">
            <span class="material-symbols-rounded">confirmation_number</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">การจองทั้งหมด</span>
            <span class="stat-value">{{ stats.total_bookings }}</span>
          </div>
          <div class="stat-badge fill-accent">{{ stats.pending_bookings }} รอดำเนินการ</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-gold-light">
            <span class="material-symbols-rounded">payments</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">รายได้เดือนนี้</span>
            <span class="stat-value">{{ formatMoney(stats.monthly_revenue) }}</span>
          </div>
          <div class="stat-badge fill-gold">รวม {{ formatMoney(stats.total_revenue) }}</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon bg-ocean-light">
            <span class="material-symbols-rounded">group</span>
          </div>
          <div class="stat-content">
            <span class="stat-label">ลูกค้าทั้งหมด</span>
            <span class="stat-value">{{ stats.total_customers }}</span>
          </div>
          <div class="stat-badge fill-ocean">{{ stats.total_vehicles }} ยานพาหนะ</div>
        </div>
      </div>

      <!-- Booking Status + Revenue Chart Row -->
      <div class="charts-row">
        <!-- Booking Status -->
        <div class="chart-card">
          <div class="card-header">
            <h3><span class="material-symbols-rounded">pie_chart</span> สถานะการจอง</h3>
          </div>
          <div class="booking-status-grid">
            <div class="status-item confirmed">
              <div class="status-dot"></div>
              <span class="status-label">ยืนยันแล้ว</span>
              <span class="status-count">{{ stats.confirmed_bookings }}</span>
            </div>
            <div class="status-item pending">
              <div class="status-dot"></div>
              <span class="status-label">รอดำเนินการ</span>
              <span class="status-count">{{ stats.pending_bookings }}</span>
            </div>
            <div class="status-item cancelled">
              <div class="status-dot"></div>
              <span class="status-label">ยกเลิก</span>
              <span class="status-count">{{ stats.cancelled_bookings }}</span>
            </div>
          </div>

          <!-- Bookings by Type -->
          <div class="type-distribution" v-if="stats.bookings_by_type">
            <h4>การจองตามประเภท</h4>
            <div
              v-for="(count, type) in stats.bookings_by_type"
              :key="type"
              class="type-bar">
              <span class="type-name">{{ typeLabels[type] || type }}</span>
              <div class="bar-track">
                <div
                  class="bar-fill"
                  :class="`bar-${type}`"
                  :style="{ width: getTypePercent(count) + '%' }"></div>
              </div>
              <span class="type-count">{{ count }}</span>
            </div>
          </div>
        </div>

        <!-- Revenue Chart -->
        <div class="chart-card">
          <div class="card-header">
            <h3><span class="material-symbols-rounded">bar_chart</span> รายได้ 6 เดือนล่าสุด</h3>
          </div>
          <div class="revenue-chart">
            <div
              v-for="(item, idx) in stats.revenue_chart"
              :key="idx"
              class="revenue-bar-wrapper">
              <div class="revenue-bar-container">
                <div
                  class="revenue-bar"
                  :style="{ height: getRevenuePercent(item.revenue) + '%' }"></div>
              </div>
              <span class="revenue-label">{{ item.month.split(' ')[0] }}</span>
              <span class="revenue-value">{{ formatShortMoney(item.revenue) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="quick-stats-row">
        <div class="quick-stat">
          <span class="material-symbols-rounded qs-icon">event_available</span>
          <div>
            <span class="qs-value">{{ stats.upcoming_schedules }}</span>
            <span class="qs-label">รอบเดินทางที่กำลังจะถึง</span>
          </div>
        </div>
      </div>

      <!-- Recent Bookings -->
      <div class="recent-section">
        <div class="section-header">
          <h3><span class="material-symbols-rounded">history</span> การจองล่าสุด</h3>
          <router-link to="/admin/bookings" class="view-all-btn">
            ดูทั้งหมด <span class="material-symbols-rounded">arrow_forward</span>
          </router-link>
        </div>

        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>รหัสจอง</th>
                <th>ทริป</th>
                <th>สถานะ</th>
                <th>จำนวนเงิน</th>
                <th>วันที่จอง</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="booking in stats.recent_bookings" :key="booking.id">
                <td>
                  <span class="booking-ref">{{ booking.booking_ref }}</span>
                </td>
                <td>{{ booking.schedule?.trip?.title || '-' }}</td>
                <td>
                  <span class="status-badge" :class="`status-${booking.status}`">
                    {{ statusLabels[booking.status] }}
                  </span>
                </td>
                <td class="money">{{ formatMoney(booking.total_amount) }}</td>
                <td class="date">{{ formatDate(booking.created_at) }}</td>
              </tr>
              <tr v-if="!stats.recent_bookings?.length">
                <td colspan="5" class="empty-state">ยังไม่มีการจอง</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { onMounted, computed } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();

const loading = computed(() => admin.loading);
const stats = computed(() => admin.dashboard);

const typeLabels = {
  trekking: 'เดินป่า',
  diving: 'ดำน้ำ',
  snorkeling: 'ดำน้ำตื้น',
  climbing: 'ปีนผา',
};

const statusLabels = {
  pending: 'รอดำเนินการ',
  confirmed: 'ยืนยันแล้ว',
  cancelled: 'ยกเลิก',
  refunded: 'คืนเงินแล้ว',
};

const formatMoney = (amount) => {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    minimumFractionDigits: 0,
  }).format(amount || 0);
};

const formatShortMoney = (amount) => {
  if (amount >= 1000000) return (amount / 1000000).toFixed(1) + 'M';
  if (amount >= 1000) return (amount / 1000).toFixed(0) + 'K';
  return amount?.toString() || '0';
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
};

const getTypePercent = (count) => {
  if (!stats.value?.total_bookings) return 0;
  return Math.round((count / stats.value.total_bookings) * 100);
};

const getRevenuePercent = (revenue) => {
  if (!stats.value?.revenue_chart) return 0;
  const max = Math.max(...stats.value.revenue_chart.map((r) => r.revenue));
  return max > 0 ? (revenue / max) * 100 : 0;
};

onMounted(() => {
  admin.fetchDashboard();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.admin-dashboard {
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.heading-icon {
  color: var(--color-accent);
  font-size: 28px;
}

.page-header {
  margin-bottom: 24px;
}

.page-title {
  font-family: var(--font-anuphan);
  font-size: 26px;
  font-weight: 700;
  color: var(--color-text-dark);
  margin: 0 0 4px 0;
  display: flex;
  align-items: center;
  gap: 10px;
}

.page-subtitle {
  font-size: 14px;
  color: var(--color-text-muted);
  margin: 0;
}

/* ─── Loading ─────────────────────────── */
.loading-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px;
  color: var(--color-text-muted);
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid var(--color-sand-dark);
  border-top-color: var(--color-accent);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  margin-bottom: 14px;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* ─── Stats Grid ──────────────────────── */
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
  display: block;
  font-size: 28px;
  font-weight: 700;
  color: var(--color-text-dark);
  font-family: var(--font-anuphan);
  line-height: 1.2;
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

/* ─── Charts Row ──────────────────────── */
.charts-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
  margin-bottom: 24px;
}

.chart-card {
  background: var(--color-white);
  border-radius: 16px;
  border: 1px solid var(--color-sand-dark);
  overflow: hidden;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
}

.card-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--color-sand-dark);
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

.card-header h3 span {
  color: var(--color-accent);
  font-size: 20px;
}

/* Booking Status */
.booking-status-grid {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.status-item {
  display: flex;
  align-items: center;
  gap: 12px;
}

.status-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}

.confirmed .status-dot { background: var(--color-accent); }
.pending .status-dot { background: var(--color-gold); }
.cancelled .status-dot { background: #EF4444; }

.status-label {
  flex: 1;
  font-size: 14px;
  color: var(--color-text-muted);
  font-weight: 500;
}

.status-count {
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text-dark);
}

/* Type Distribution */
.type-distribution {
  padding: 0 24px 24px;
}

.type-distribution h4 {
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-muted);
  margin: 0 0 12px 0;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.type-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
}

.type-name {
  width: 75px;
  font-size: 13px;
  color: var(--color-text-mid);
  font-weight: 500;
}

.bar-track {
  flex: 1;
  height: 8px;
  background: var(--color-sand);
  border-radius: 4px;
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.bar-trekking { background: var(--color-primary-light); }
.bar-diving { background: var(--color-ocean); }
.bar-snorkeling { background: var(--color-ocean-light); }
.bar-climbing { background: var(--color-gold); }

.type-count {
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-dark);
  width: 32px;
  text-align: right;
}

/* Revenue Chart */
.revenue-chart {
  padding: 24px;
  display: flex;
  justify-content: space-around;
  align-items: flex-end;
  height: 240px;
  gap: 8px;
}

.revenue-bar-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  gap: 8px;
}

.revenue-bar-container {
  width: 100%;
  max-width: 48px;
  height: 160px;
  display: flex;
  align-items: flex-end;
  background: var(--color-sand);
  border-radius: 6px;
  overflow: hidden;
}

.revenue-bar {
  width: 100%;
  background: var(--color-accent);
  border-radius: 6px 6px 0 0;
  min-height: 4px;
  transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.revenue-bar:hover {
  background: var(--color-accent-mid);
}

.revenue-label {
  font-size: 12px;
  color: var(--color-text-muted);
  font-weight: 600;
}

.revenue-value {
  font-size: 12px;
  color: var(--color-text-dark);
  font-weight: 700;
}

/* ─── Quick Stats ─────────────────────── */
.quick-stats-row {
  display: flex;
  gap: 20px;
  margin-bottom: 24px;
}

.quick-stat {
  flex: 1;
  background: var(--color-white);
  border-radius: 16px;
  border: 1px solid var(--color-sand-dark);
  padding: 20px 24px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
}

.qs-icon {
  font-size: 28px;
  color: var(--color-accent);
  background: #EAF2EE;
  padding: 12px;
  border-radius: 12px;
}

.qs-value {
  display: block;
  font-size: 24px;
  font-weight: 700;
  color: var(--color-text-dark);
}

.qs-label {
  font-size: 13px;
  color: var(--color-text-muted);
  font-weight: 500;
}

/* ─── Recent Bookings ─────────────────── */
.recent-section {
  background: var(--color-white);
  border-radius: 16px;
  border: 1px solid var(--color-sand-dark);
  overflow: hidden;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
}

.section-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--color-sand-dark);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.section-header h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text-dark);
  display: flex;
  align-items: center;
  gap: 10px;
}

.section-header h3 span {
  color: var(--color-accent);
  font-size: 20px;
}

.view-all-btn {
  font-size: 13px;
  color: var(--color-accent);
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 4px;
  font-weight: 600;
  transition: color 0.15s;
}

.view-all-btn:hover {
  color: var(--color-accent-mid);
}

/* ─── Table ───────────────────────────── */
.table-container {
  overflow-x: auto;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th {
  padding: 14px 24px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  text-align: left;
  border-bottom: 1px solid var(--color-sand-dark);
  background: var(--color-sand);
  white-space: nowrap;
}

.data-table td {
  padding: 16px 24px;
  font-size: 14px;
  color: var(--color-text-mid);
  border-bottom: 1px solid var(--color-sand-dark);
  font-weight: 500;
}

.data-table tr:last-child td {
  border-bottom: none;
}

.data-table tr:hover td {
  background: var(--color-sand);
}

.booking-ref {
  font-family: var(--font-anuphan);
  font-size: 14px;
  color: var(--color-accent);
  font-weight: 700;
  letter-spacing: 0.5px;
}

.status-badge {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
}

.status-pending { background: #FEF3C7; color: #B45309; }
.status-confirmed { background: #EAF2EE; color: var(--color-accent); }
.status-cancelled { background: #FEE2E2; color: #B91C1C; }
.status-refunded { background: #EDE9FE; color: #6D28D9; }

td.money {
  font-weight: 700;
  color: var(--color-text-dark);
}

td.date {
  color: var(--color-text-muted);
  font-size: 13px;
}

.empty-state {
  text-align: center;
  color: var(--color-text-muted);
  padding: 48px !important;
  font-size: 14px;
  font-weight: 500;
}

/* ─── Responsive ──────────────────────── */
@media (max-width: 1024px) {
  .charts-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .stats-grid {
    grid-template-columns: 1fr 1fr;
  }
  .quick-stats-row {
    flex-direction: column;
  }
}

@media (max-width: 480px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }
}
</style>
