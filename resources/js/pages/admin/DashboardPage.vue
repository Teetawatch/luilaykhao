<template>
  <div class="admin-dashboard">
    <div class="page-header">
      <h1 class="page-title">
        <i class="fas fa-tachometer-alt"></i>
        แดชบอร์ด
      </h1>
      <p class="page-subtitle">ภาพรวมระบบ TrailDive</p>
    </div>

    <!-- Loading State -->
    <div class="loading-state" v-if="loading">
      <div class="spinner"></div>
      <p>กำลังโหลดข้อมูล...</p>
    </div>

    <template v-else-if="stats">
      <!-- Stats Grid -->
      <div class="stats-grid">
        <div class="stat-card stat-primary">
          <div class="stat-icon"><i class="fas fa-route"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.active_trips }}</span>
            <span class="stat-label">ทริปที่เปิดอยู่</span>
          </div>
          <div class="stat-badge">จาก {{ stats.total_trips }} ทริป</div>
        </div>

        <div class="stat-card stat-success">
          <div class="stat-icon"><i class="fas fa-ticket-alt"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.total_bookings }}</span>
            <span class="stat-label">การจองทั้งหมด</span>
          </div>
          <div class="stat-badge">{{ stats.pending_bookings }} รอดำเนินการ</div>
        </div>

        <div class="stat-card stat-revenue">
          <div class="stat-icon"><i class="fas fa-coins"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ formatMoney(stats.monthly_revenue) }}</span>
            <span class="stat-label">รายได้เดือนนี้</span>
          </div>
          <div class="stat-badge">รวม {{ formatMoney(stats.total_revenue) }}</div>
        </div>

        <div class="stat-card stat-info">
          <div class="stat-icon"><i class="fas fa-users"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.total_customers }}</span>
            <span class="stat-label">ลูกค้าทั้งหมด</span>
          </div>
          <div class="stat-badge">{{ stats.total_vehicles }} ยานพาหนะ</div>
        </div>
      </div>

      <!-- Booking Status + Revenue Chart Row -->
      <div class="charts-row">
        <!-- Booking Status -->
        <div class="chart-card">
          <div class="card-header">
            <h3><i class="fas fa-chart-pie"></i> สถานะการจอง</h3>
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
              class="type-bar"
            >
              <span class="type-name">{{ typeLabels[type] || type }}</span>
              <div class="bar-track">
                <div
                  class="bar-fill"
                  :class="`bar-${type}`"
                  :style="{ width: getTypePercent(count) + '%' }"
                ></div>
              </div>
              <span class="type-count">{{ count }}</span>
            </div>
          </div>
        </div>

        <!-- Revenue Chart -->
        <div class="chart-card">
          <div class="card-header">
            <h3><i class="fas fa-chart-bar"></i> รายได้ 6 เดือนล่าสุด</h3>
          </div>
          <div class="revenue-chart">
            <div
              v-for="(item, idx) in stats.revenue_chart"
              :key="idx"
              class="revenue-bar-wrapper"
            >
              <div class="revenue-bar-container">
                <div
                  class="revenue-bar"
                  :style="{ height: getRevenuePercent(item.revenue) + '%' }"
                ></div>
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
          <i class="fas fa-calendar-check"></i>
          <div>
            <span class="qs-value">{{ stats.upcoming_schedules }}</span>
            <span class="qs-label">รอบเดินทางที่กำลังจะถึง</span>
          </div>
        </div>
      </div>

      <!-- Recent Bookings -->
      <div class="recent-section">
        <div class="section-header">
          <h3><i class="fas fa-clock"></i> การจองล่าสุด</h3>
          <router-link to="/admin/bookings" class="view-all-btn">
            ดูทั้งหมด <i class="fas fa-arrow-right"></i>
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
.admin-dashboard {
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.page-header {
  margin-bottom: 24px;
}

.page-title {
  font-family: 'Playfair Display', serif;
  font-size: 26px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 4px 0;
  display: flex;
  align-items: center;
  gap: 10px;
}

.page-title i {
  color: #2d7a4f;
}

.page-subtitle {
  font-size: 14px;
  color: #6b7280;
  margin: 0;
}

/* ─── Loading ─────────────────────────── */
.loading-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px;
  color: #9ca3af;
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid #e5e7eb;
  border-top-color: #2d7a4f;
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
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.stat-card {
  background: #ffffff;
  border-radius: 12px;
  padding: 20px;
  border: 1px solid #e5e7eb;
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 14px;
  position: relative;
  overflow: hidden;
  transition: box-shadow 0.15s;
}

.stat-card:hover {
  box-shadow: 0 4px 16px rgba(17, 24, 39, 0.08);
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
}

.stat-primary::before { background: #2d7a4f; }
.stat-success::before { background: #1d4ed8; }
.stat-revenue::before { background: #d97706; }
.stat-info::before { background: #7c3aed; }

.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}

.stat-primary .stat-icon { background: #F5F5F5; color: #15803d; }
.stat-success .stat-icon { background: #dbeafe; color: #1d4ed8; }
.stat-revenue .stat-icon { background: #fef9c3; color: #a16207; }
.stat-info .stat-icon { background: #ede9fe; color: #6d28d9; }

.stat-content {
  flex: 1;
}

.stat-value {
  display: block;
  font-size: 26px;
  font-weight: 700;
  color: #111827;
  line-height: 1.2;
}

.stat-label {
  font-size: 13px;
  color: #6b7280;
  margin-top: 2px;
}

.stat-badge {
  width: 100%;
  font-size: 12px;
  color: #9ca3af;
  padding-top: 10px;
  border-top: 1px solid #f3f4f6;
}

/* ─── Charts Row ──────────────────────── */
.charts-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 24px;
}

.chart-card {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  overflow: hidden;
}

.card-header {
  padding: 16px 20px;
  border-bottom: 1px solid #f3f4f6;
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

.card-header h3 i {
  color: #2d7a4f;
}

/* Booking Status */
.booking-status-grid {
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.status-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.status-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}

.confirmed .status-dot { background: #16a34a; }
.pending .status-dot { background: #d97706; }
.cancelled .status-dot { background: #dc2626; }

.status-label {
  flex: 1;
  font-size: 14px;
  color: #6b7280;
}

.status-count {
  font-size: 17px;
  font-weight: 700;
  color: #111827;
}

/* Type Distribution */
.type-distribution {
  padding: 0 20px 20px;
}

.type-distribution h4 {
  font-size: 11px;
  font-weight: 700;
  color: #9ca3af;
  margin: 0 0 10px 0;
  text-transform: uppercase;
  letter-spacing: 0.8px;
}

.type-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.type-name {
  width: 70px;
  font-size: 13px;
  color: #6b7280;
}

.bar-track {
  flex: 1;
  height: 7px;
  background: #EEEEEE;
  border-radius: 4px;
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.6s ease;
}

.bar-trekking { background: #16a34a; }
.bar-diving { background: #1d4ed8; }
.bar-snorkeling { background: #0369a1; }
.bar-climbing { background: #d97706; }

.type-count {
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  width: 30px;
  text-align: right;
}

/* Revenue Chart */
.revenue-chart {
  padding: 20px;
  display: flex;
  justify-content: space-around;
  align-items: flex-end;
  height: 220px;
  gap: 8px;
}

.revenue-bar-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  gap: 6px;
}

.revenue-bar-container {
  width: 100%;
  max-width: 44px;
  height: 150px;
  display: flex;
  align-items: flex-end;
}

.revenue-bar {
  width: 100%;
  background: #FAFAFA;
  border: 1px solid #EEEEEE;
  border-radius: 5px 5px 0 0;
  min-height: 4px;
  transition: height 0.5s ease;
}

.revenue-label {
  font-size: 11px;
  color: #9ca3af;
}

.revenue-value {
  font-size: 11px;
  color: #374151;
  font-weight: 600;
}

/* ─── Quick Stats ─────────────────────── */
.quick-stats-row {
  display: flex;
  gap: 16px;
  margin-bottom: 24px;
}

.quick-stat {
  flex: 1;
  background: #ffffff;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
}

.quick-stat i {
  font-size: 18px;
  color: #2d7a4f;
}

.qs-value {
  display: block;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
}

.qs-label {
  font-size: 12px;
  color: #6b7280;
}

/* ─── Recent Bookings ─────────────────── */
.recent-section {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  overflow: hidden;
}

.section-header {
  padding: 16px 20px;
  border-bottom: 1px solid #f3f4f6;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.section-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #111827;
  display: flex;
  align-items: center;
  gap: 8px;
}

.section-header h3 i {
  color: #2d7a4f;
}

.view-all-btn {
  font-size: 13px;
  color: #2d7a4f;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: 500;
  transition: color 0.15s;
}

.view-all-btn:hover {
  color: #1a5535;
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
  padding: 12px 20px;
  font-size: 11px;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  text-align: left;
  border-bottom: 1px solid #e5e7eb;
  background: #FAFAFA;
  white-space: nowrap;
}

.data-table td {
  padding: 13px 20px;
  font-size: 14px;
  color: #374151;
  border-bottom: 1px solid #f3f4f6;
}

.data-table tr:last-child td {
  border-bottom: none;
}

.data-table tr:hover td {
  background: #FAFAFA;
}

.booking-ref {
  font-family: monospace;
  font-size: 13px;
  color: #2d7a4f;
  font-weight: 700;
}

.status-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

.status-pending { background: #fef9c3; color: #a16207; }
.status-confirmed { background: #F5F5F5; color: #15803d; }
.status-cancelled { background: #fee2e2; color: #b91c1c; }
.status-refunded { background: #ede9fe; color: #6d28d9; }

td.money {
  font-weight: 600;
  color: #111827;
}

td.date {
  color: #6b7280;
  font-size: 13px;
}

.empty-state {
  text-align: center;
  color: #9ca3af;
  padding: 48px !important;
  font-size: 14px;
}

/* ─── Responsive ──────────────────────── */
@media (max-width: 1024px) {
  .charts-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }
}
</style>
