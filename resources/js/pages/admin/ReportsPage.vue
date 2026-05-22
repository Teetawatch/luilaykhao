<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">show_chart</span> รายงานและส่งออก</h1>
        <p class="page-subtitle">สร้างรายงานและส่งออก Excel/PDF สำหรับบัญชี</p>
      </div>
    </div>

    <!-- Report Tabs -->
    <div class="report-tabs">
      <button :class="{ active: activeTab === 'bookings' }" @click="activeTab = 'bookings'">
        <span class="material-symbols-rounded">confirmation_number</span> รายงานการจอง
      </button>
      <button :class="{ active: activeTab === 'revenue' }" @click="activeTab = 'revenue'">
        <span class="material-symbols-rounded">payments</span> รายงานรายได้
      </button>
      <button :class="{ active: activeTab === 'vehicles' }" @click="activeTab = 'vehicles'">
        <span class="material-symbols-rounded">airport_shuttle</span> รายงานยานพาหนะ
      </button>
    </div>

    <!-- ─── Bookings Report ───────────────── -->
    <div v-if="activeTab === 'bookings'">
      <div class="report-filters">
        <div class="form-group">
          <label>ประเภทวันที่</label>
          <select v-model="bookingFilters.date_type">
            <option value="booking">วันที่จอง (Booking Date)</option>
            <option value="travel">วันที่เดินทาง (Travel Date)</option>
          </select>
        </div>
        <div class="form-group">
          <label>จากวันที่</label>
          <input v-model="bookingFilters.from" type="date" />
        </div>
        <div class="form-group">
          <label>ถึงวันที่</label>
          <input v-model="bookingFilters.to" type="date" />
        </div>
        <div class="form-group">
          <label>ทริป</label>
          <select v-model="bookingFilters.trip_id">
            <option value="">ทั้งหมด</option>
            <option v-for="t in allTrips" :key="t.id" :value="t.id">{{ t.title }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>สถานะ</label>
          <select v-model="bookingFilters.status">
            <option value="">ทั้งหมด</option>
            <option value="confirmed">ยืนยันแล้ว</option>
            <option value="pending">รอดำเนินการ</option>
            <option value="cancelled">ยกเลิก</option>
          </select>
        </div>
        <div class="filter-actions">
          <button class="btn-primary" @click="loadBookingReport" :disabled="loadingReport">
            <span class="material-symbols-rounded">search</span> สร้างรายงาน
          </button>
        </div>
      </div>

      <div class="loading-state" v-if="loadingReport"><div class="spinner"></div></div>

      <template v-if="bookingReport">
        <!-- Summary -->
        <div class="report-summary">
          <div class="rs-item"><span class="rs-val">{{ bookingReport.summary.total_bookings }}</span><span class="rs-label">จองทั้งหมด</span></div>
          <div class="rs-item rs-green"><span class="rs-val">{{ bookingReport.summary.confirmed }}</span><span class="rs-label">ยืนยัน</span></div>
          <div class="rs-item rs-yellow"><span class="rs-val">{{ bookingReport.summary.pending }}</span><span class="rs-label">รอดำเนินการ</span></div>
          <div class="rs-item rs-red"><span class="rs-val">{{ bookingReport.summary.cancelled }}</span><span class="rs-label">ยกเลิก</span></div>
          <div class="rs-item rs-blue"><span class="rs-val">{{ formatMoney(bookingReport.summary.total_revenue) }}</span><span class="rs-label">รายได้รวม</span></div>
          <div class="rs-item"><span class="rs-val">{{ bookingReport.summary.total_passengers }}</span><span class="rs-label">ผู้โดยสารรวม</span></div>
        </div>

        <!-- Export Buttons -->
        <div class="export-actions">
          <button class="btn-export btn-excel" @click="exportExcel('bookings')">
            <span class="material-symbols-rounded">table_view</span> ส่งออก Excel
          </button>
          <button class="btn-export btn-pdf" @click="exportPdf('bookings')">
            <span class="material-symbols-rounded">picture_as_pdf</span> ส่งออก PDF
          </button>
        </div>

        <!-- Table -->
        <div class="table-card">
          <div class="table-container">
            <table class="data-table report-table">
              <thead>
                <tr>
                  <th>รหัสจอง</th>
                  <th>ลูกค้า</th>
                  <th>ทริป</th>
                  <th>วันเดินทาง</th>
                  <th>ผู้โดยสาร</th>
                  <th>สถานะ</th>
                  <th>ยอดรวม</th>
                  <th>ชำระแล้ว</th>
                  <th>กลุ่ม</th>
                  <th>วันที่จอง</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in bookingReport.rows" :key="row.booking_ref">
                  <td><span class="booking-ref">{{ row.booking_ref }}</span></td>
                  <td>{{ row.customer_name }}</td>
                  <td>{{ row.trip_title }}</td>
                  <td class="date">{{ row.departure_date }}</td>
                  <td>{{ row.passengers_count }}</td>
                  <td><span class="status-badge" :class="`status-${row.status}`">{{ statusLabels[row.status] }}</span></td>
                  <td class="money">{{ formatMoney(row.total_amount) }}</td>
                  <td class="money">{{ formatMoney(row.paid_amount) }}</td>
                  <td>{{ row.is_group }}</td>
                  <td class="date">{{ row.created_at }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- ─── Revenue Report ────────────────── -->
    <div v-if="activeTab === 'revenue'">
      <div class="report-filters">
        <div class="form-group">
          <label>จากวันที่</label>
          <input v-model="revenueFilters.from" type="date" />
        </div>
        <div class="form-group">
          <label>ถึงวันที่</label>
          <input v-model="revenueFilters.to" type="date" />
        </div>
        <div class="filter-actions">
          <button class="btn-primary" @click="loadRevenueReport" :disabled="loadingReport">
            <span class="material-symbols-rounded">search</span> สร้างรายงาน
          </button>
        </div>
      </div>

      <div class="loading-state" v-if="loadingReport"><div class="spinner"></div></div>

      <template v-if="revenueReport">
        <!-- Primary Summary -->
        <div class="report-summary">
          <div class="rs-item rs-blue">
            <span class="rs-val">{{ formatMoney(revenueReport.summary.total_amount) }}</span>
            <span class="rs-label">ยอดรวมทั้งหมด</span>
          </div>
          <div class="rs-item rs-green">
            <span class="rs-val">{{ formatMoney(revenueReport.summary.paid_amount) }}</span>
            <span class="rs-label">ชำระแล้ว</span>
          </div>
          <div class="rs-item rs-orange">
            <span class="rs-val">{{ formatMoney(revenueReport.summary.remaining_amount) }}</span>
            <span class="rs-label">ยังไม่ชำระ</span>
          </div>
          <div class="rs-item">
            <span class="rs-val">{{ revenueReport.summary.total_bookings }}</span>
            <span class="rs-label">จองทั้งหมด</span>
          </div>
          <div class="rs-item">
            <span class="rs-val">{{ revenueReport.summary.total_passengers }}</span>
            <span class="rs-label">ผู้โดยสารรวม</span>
          </div>
        </div>

        <!-- Payment Type Breakdown -->
        <div class="payment-type-section">
          <h3 class="section-title"><span class="material-symbols-rounded">account_balance_wallet</span> แยกตามประเภทการชำระ</h3>
          <div class="payment-type-cards">
            <div v-for="pt in revenueReport.by_payment_type" :key="pt.payment_type" class="pt-card">
              <div class="pt-header">
                <span class="pt-badge" :class="`pt-${pt.payment_type}`">{{ paymentTypeLabels[pt.payment_type] || pt.payment_type }}</span>
                <span class="pt-count">{{ pt.bookings_count }} จอง · {{ pt.passengers_count }} คน</span>
              </div>
              <div class="pt-amounts">
                <div class="pt-row">
                  <span class="pt-key">ยอดรวม</span>
                  <span class="pt-val">{{ formatMoney(pt.total_amount) }}</span>
                </div>
                <div class="pt-row">
                  <span class="pt-key">ชำระแล้ว</span>
                  <span class="pt-val pt-paid">{{ formatMoney(pt.paid_amount) }}</span>
                </div>
                <div class="pt-row pt-row-remaining" v-if="pt.remaining_amount > 0">
                  <span class="pt-key">ยังไม่ชำระ</span>
                  <span class="pt-val pt-remaining">{{ formatMoney(pt.remaining_amount) }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="export-actions">
          <button class="btn-export btn-excel" @click="exportExcel('revenue')">
            <span class="material-symbols-rounded">table_view</span> ส่งออก Excel
          </button>
          <button class="btn-export btn-pdf" @click="exportPdf('revenue')">
            <span class="material-symbols-rounded">picture_as_pdf</span> ส่งออก PDF
          </button>
        </div>

        <!-- Monthly Chart -->
        <div class="chart-section">
          <h3><span class="material-symbols-rounded">bar_chart</span> รายได้รายเดือน</h3>
          <div class="bar-chart">
            <div v-for="m in revenueReport.monthly" :key="m.month" class="bar-col">
              <div class="bar-value">{{ formatShort(m.total_amount) }}</div>
              <div class="bar-track-v">
                <div class="bar-fill-v" :style="{ height: getRevPercent(m.total_amount) + '%' }">
                  <div class="bar-paid-overlay" :style="{ height: getPaidPercent(m) + '%' }"></div>
                </div>
              </div>
              <div class="bar-label">{{ m.month }}</div>
              <div class="bar-sub">{{ m.bookings_count }} จอง</div>
            </div>
          </div>
          <div class="bar-legend">
            <span class="legend-item"><span class="legend-dot legend-total"></span>ยอดรวม</span>
            <span class="legend-item"><span class="legend-dot legend-paid"></span>ชำระแล้ว</span>
          </div>
        </div>

        <!-- Monthly Table -->
        <div class="table-card" style="margin-bottom: 20px;">
          <div class="card-header"><h3><span class="material-symbols-rounded">calendar_month</span> รายละเอียดรายเดือน</h3></div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>เดือน</th>
                  <th>จอง</th>
                  <th>คน</th>
                  <th class="money">ยอดรวม</th>
                  <th class="money">ชำระแล้ว</th>
                  <th class="money">ยังไม่ชำระ</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in revenueReport.monthly" :key="m.month">
                  <td><strong>{{ m.month }}</strong></td>
                  <td>{{ m.bookings_count }}</td>
                  <td>{{ m.passengers_count }}</td>
                  <td class="money">{{ formatMoney(m.total_amount) }}</td>
                  <td class="money money-green">{{ formatMoney(m.paid_amount) }}</td>
                  <td class="money" :class="m.remaining_amount > 0 ? 'money-orange' : ''">{{ formatMoney(m.remaining_amount) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="tfoot-total">
                  <td><strong>รวม</strong></td>
                  <td><strong>{{ revenueReport.summary.total_bookings }}</strong></td>
                  <td><strong>{{ revenueReport.summary.total_passengers }}</strong></td>
                  <td class="money"><strong>{{ formatMoney(revenueReport.summary.total_amount) }}</strong></td>
                  <td class="money money-green"><strong>{{ formatMoney(revenueReport.summary.paid_amount) }}</strong></td>
                  <td class="money money-orange"><strong>{{ formatMoney(revenueReport.summary.remaining_amount) }}</strong></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- By Trip -->
        <div class="table-card">
          <div class="card-header"><h3><span class="material-symbols-rounded">route</span> รายได้ตามทริป</h3></div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>ทริป</th>
                  <th>จอง</th>
                  <th>คน</th>
                  <th class="money">ยอดรวม</th>
                  <th class="money">ชำระแล้ว</th>
                  <th class="money">ยังไม่ชำระ</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in revenueReport.by_trip" :key="t.trip">
                  <td>{{ t.trip }}</td>
                  <td>{{ t.bookings_count }}</td>
                  <td>{{ t.passengers_count }}</td>
                  <td class="money">{{ formatMoney(t.total_amount) }}</td>
                  <td class="money money-green">{{ formatMoney(t.paid_amount) }}</td>
                  <td class="money" :class="t.remaining_amount > 0 ? 'money-orange' : ''">{{ formatMoney(t.remaining_amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- ─── Vehicles Report ───────────────── -->
    <div v-if="activeTab === 'vehicles'">
      <div class="report-filters">
        <div class="filter-actions">
          <button class="btn-primary" @click="loadVehicleReport" :disabled="loadingReport">
            <span class="material-symbols-rounded">search</span> สร้างรายงาน
          </button>
        </div>
      </div>

      <div class="loading-state" v-if="loadingReport"><div class="spinner"></div></div>

      <template v-if="vehicleReport">
        <div class="export-actions">
          <button class="btn-export btn-excel" @click="exportExcel('vehicles')">
            <span class="material-symbols-rounded">table_view</span> ส่งออก Excel
          </button>
          <button class="btn-export btn-pdf" @click="exportPdf('vehicles')">
            <span class="material-symbols-rounded">picture_as_pdf</span> ส่งออก PDF
          </button>
        </div>

        <div class="table-card">
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>ยานพาหนะ</th>
                  <th>ประเภท</th>
                  <th>ความจุ</th>
                  <th>ทริปที่ผ่านมา</th>
                  <th>ทริปที่จะมา</th>
                  <th>บำรุงรักษา</th>
                  <th>ค่าบำรุงรักษารวม</th>
                  <th>บำรุงรักษาถัดไป</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="v in vehicleReport" :key="v.id">
                  <td><strong>{{ v.name }}</strong></td>
                  <td>{{ v.type === 'van' ? 'รถตู้' : 'เรือ' }}</td>
                  <td>{{ v.capacity }}</td>
                  <td>{{ v.total_trips }}</td>
                  <td>{{ v.upcoming_trips }}</td>
                  <td>{{ v.total_maintenances }}</td>
                  <td class="money">{{ formatMoney(v.total_maintenance_cost) }}</td>
                  <td class="date">{{ v.next_maintenance || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();
const activeTab = ref('bookings');
const loadingReport = ref(false);

// Auto load on tab change
watch(activeTab, (val) => {
  if (val === 'bookings' && !bookingReport.value) loadBookingReport();
  if (val === 'revenue' && !revenueReport.value) loadRevenueReport();
  if (val === 'vehicles' && !vehicleReport.value) loadVehicleReport();
});

onMounted(() => {
  // Initial load
  loadBookingReport();
});

const bookingFilters = reactive({ 
  from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10), 
  to: new Date().toISOString().slice(0, 10), 
  status: '',
  trip_id: '',
  date_type: 'booking'
});
const revenueFilters = reactive({ 
  from: new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10), 
  to: new Date().toISOString().slice(0, 10) 
});

const bookingReport = ref(null);
const revenueReport = ref(null);
const vehicleReport = ref(null);
const allTrips = ref([]);

onMounted(async () => {
  // Load trips for filters
  try {
    const res = await admin.fetchTrips();
    allTrips.value = res.data;
  } catch (e) {}

  // Initial load
  loadBookingReport();
});

const statusLabels = { pending: 'รอดำเนินการ', confirmed: 'ยืนยันแล้ว', cancelled: 'ยกเลิก', refunded: 'คืนเงินแล้ว' };
const paymentTypeLabels = { full: 'ชำระเต็ม', deposit: 'มัดจำ', installment: 'ผ่อนชำระ' };

function formatMoney(amount) {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
}

function formatShort(amount) {
  if (amount >= 1000000) return (amount / 1000000).toFixed(1) + 'M';
  if (amount >= 1000) return (amount / 1000).toFixed(1) + 'K';
  return amount?.toString() || '0';
}

function getRevPercent(totalAmount) {
  if (!revenueReport.value?.monthly?.length) return 0;
  const max = Math.max(...revenueReport.value.monthly.map(m => m.total_amount));
  return max > 0 ? (totalAmount / max) * 100 : 0;
}

function getPaidPercent(m) {
  if (!m.total_amount) return 0;
  return (m.paid_amount / m.total_amount) * 100;
}

async function loadBookingReport() {
  loadingReport.value = true;
  try {
    const res = await admin.fetchReportBookings({
      from: bookingFilters.from || undefined,
      to: bookingFilters.to || undefined,
      status: bookingFilters.status || undefined,
      trip_id: bookingFilters.trip_id || undefined,
      date_type: bookingFilters.date_type
    });
    bookingReport.value = res.data;
  } catch (e) {
    alert('ไม่สามารถโหลดรายงานได้');
  } finally {
    loadingReport.value = false;
  }
}

async function loadRevenueReport() {
  loadingReport.value = true;
  try {
    const res = await admin.fetchReportRevenue({
      from: revenueFilters.from || undefined,
      to: revenueFilters.to || undefined,
    });
    revenueReport.value = res.data;
  } catch (e) {
    alert('ไม่สามารถโหลดรายงานได้');
  } finally {
    loadingReport.value = false;
  }
}

async function loadVehicleReport() {
  loadingReport.value = true;
  try {
    const res = await admin.fetchReportVehicles();
    vehicleReport.value = res.data;
  } catch (e) {
    alert('ไม่สามารถโหลดรายงานได้');
  } finally {
    loadingReport.value = false;
  }
}

function exportExcel(type) {
  let data, headers, filename;

  if (type === 'bookings' && bookingReport.value) {
    headers = ['รหัสจอง', 'ลูกค้า', 'อีเมล', 'เบอร์โทร', 'ทริป', 'วันเดินทาง', 'ผู้โดยสาร', 'สถานะ', 'ยอดรวม', 'ชำระแล้ว', 'วิธีชำระ', 'กลุ่ม', 'ชื่อกลุ่ม', 'วันที่จอง'];
    data = bookingReport.value.rows.map(r => [r.booking_ref, r.customer_name, r.customer_email, r.customer_phone, r.trip_title, r.departure_date, r.passengers_count, r.status, r.total_amount, r.paid_amount, r.payment_method, r.is_group, r.group_name, r.created_at]);
    filename = `booking-report-${new Date().toISOString().slice(0,10)}`;
  } else if (type === 'revenue' && revenueReport.value) {
    headers = ['เดือน', 'จำนวนจอง', 'ผู้โดยสาร', 'ยอดรวม', 'ชำระแล้ว', 'ยังไม่ชำระ'];
    data = revenueReport.value.monthly.map(m => [m.month, m.bookings_count, m.passengers_count, m.total_amount, m.paid_amount, m.remaining_amount]);
    filename = `revenue-report-${new Date().toISOString().slice(0,10)}`;
  } else if (type === 'vehicles' && vehicleReport.value) {
    headers = ['ยานพาหนะ', 'ประเภท', 'ความจุ', 'ทริปที่ผ่านมา', 'ทริปที่จะมา', 'บำรุงรักษา', 'ค่าบำรุงรักษารวม', 'บำรุงรักษาถัดไป'];
    data = vehicleReport.value.map(v => [v.name, v.type, v.capacity, v.total_trips, v.upcoming_trips, v.total_maintenances, v.total_maintenance_cost, v.next_maintenance || '-']);
    filename = `vehicle-report-${new Date().toISOString().slice(0,10)}`;
  } else return;

  // Generate CSV (Excel-compatible with BOM for Thai)
  const BOM = '\uFEFF';
  const csv = BOM + [headers.join(','), ...data.map(row => row.map(cell => `"${cell}"`).join(','))].join('\n');
  downloadFile(csv, filename + '.csv', 'text/csv;charset=utf-8');
}

function exportPdf(type) {
  let title, summaryLines, tableHeaders, tableRows;

  if (type === 'bookings' && bookingReport.value) {
    title = 'รายงานการจอง';
    summaryLines = [
      `จองทั้งหมด: ${bookingReport.value.summary.total_bookings}`,
      `ยืนยัน: ${bookingReport.value.summary.confirmed}`,
      `รายได้รวม: ${formatMoney(bookingReport.value.summary.total_revenue)}`,
    ];
    tableHeaders = ['รหัสจอง', 'ลูกค้า', 'ทริป', 'สถานะ', 'ยอดรวม'];
    tableRows = bookingReport.value.rows.map(r => [r.booking_ref, r.customer_name, r.trip_title, r.status, formatMoney(r.total_amount)]);
  } else if (type === 'revenue' && revenueReport.value) {
    title = 'รายงานรายได้';
    const s = revenueReport.value.summary;
    const ptLines = revenueReport.value.by_payment_type.map(pt =>
      `${paymentTypeLabels[pt.payment_type] || pt.payment_type}: ${pt.bookings_count} จอง (${pt.passengers_count} คน) ยอดรวม ${formatMoney(pt.total_amount)} ชำระแล้ว ${formatMoney(pt.paid_amount)} เหลือ ${formatMoney(pt.remaining_amount)}`
    );
    summaryLines = [
      `ช่วงเวลา: ${s.period}`,
      `จองทั้งหมด: ${s.total_bookings} จอง (${s.total_passengers} คน)`,
      `ยอดรวมทั้งหมด: ${formatMoney(s.total_amount)}`,
      `ชำระแล้ว: ${formatMoney(s.paid_amount)}`,
      `ยังไม่ชำระ: ${formatMoney(s.remaining_amount)}`,
      '--- แยกตามประเภทการชำระ ---',
      ...ptLines,
    ];
    tableHeaders = ['เดือน', 'จอง', 'คน', 'ยอดรวม', 'ชำระแล้ว', 'ยังไม่ชำระ'];
    tableRows = revenueReport.value.monthly.map(m => [m.month, m.bookings_count, m.passengers_count, formatMoney(m.total_amount), formatMoney(m.paid_amount), formatMoney(m.remaining_amount)]);
  } else if (type === 'vehicles' && vehicleReport.value) {
    title = 'รายงานยานพาหนะ';
    summaryLines = [`จำนวนยานพาหนะ: ${vehicleReport.value.length}`];
    tableHeaders = ['ยานพาหนะ', 'ประเภท', 'ความจุ', 'ทริป', 'ค่าบำรุงรักษา'];
    tableRows = vehicleReport.value.map(v => [v.name, v.type, v.capacity, v.total_trips, formatMoney(v.total_maintenance_cost)]);
  } else return;

  // Generate printable HTML
  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>
<style>
  body{font-family:'Anuphan',sans-serif;padding:40px;color:#333}
  h1{color:#2d7a4f;margin-bottom:8px}
  .summary{margin:16px 0;padding:12px;background:#FAFAFA;border-radius:8px}
  .summary p{margin:4px 0;font-size:14px}
  table{width:100%;border-collapse:collapse;margin-top:20px}
  th{background:#2d7a4f;color:white;padding:8px 12px;text-align:left;font-size:12px}
  td{padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px}
  tr:nth-child(even) td{background:#FAFAFA}
  .footer{margin-top:30px;text-align:center;color:#9ca3af;font-size:11px}
</style></head><body>
<h1>${title}</h1>
<p style="color:#6b7280;font-size:13px">สร้างเมื่อ ${new Date().toLocaleString('th-TH')}</p>
<div class="summary">${summaryLines.map(l => `<p>${l}</p>`).join('')}</div>
<table><thead><tr>${tableHeaders.map(h => `<th>${h}</th>`).join('')}</tr></thead>
<tbody>${tableRows.map(row => `<tr>${row.map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table>
<div class="footer">TrailDive Admin - รายงานอัตโนมัติ</div></body></html>`;

  const printWindow = window.open('', '_blank');
  printWindow.document.write(html);
  printWindow.document.close();
  printWindow.onload = () => printWindow.print();
}

function downloadFile(content, filename, contentType) {
  const blob = new Blob([content], { type: contentType });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
</script>

<style scoped>
@import url('./admin-shared.css');

.report-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 20px;
  background: var(--color-white);
  border-radius: 10px;
  border: 1px solid var(--color-sand-dark);
  padding: 4px;
}

.report-tabs button {
  flex: 1;
  padding: 10px 16px;
  border: none;
  background: transparent;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-mid);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.15s;
}

.report-tabs button:hover {
  background: var(--color-sand);
  color: var(--color-text-dark);
}

.report-tabs button.active {
  background: var(--color-accent);
  color: white;
}

.report-filters {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
  align-items: flex-end;
  flex-wrap: wrap;
}

.report-filters .form-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.report-filters label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-muted);
}

.report-filters input,
.report-filters select {
  padding: 8px 12px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  font-size: 14px;
  color: var(--color-text-dark);
  background: var(--color-white);
  outline: none;
}

.report-filters input:focus,
.report-filters select:focus {
  border-color: var(--color-accent);
}

.filter-actions {
  margin-left: auto;
}

.report-summary {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.rs-item {
  flex: 1;
  min-width: 120px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 14px;
  text-align: center;
}

.rs-val {
  display: block;
  font-size: 22px;
  font-weight: 700;
  color: var(--color-text-dark);
}

.rs-green .rs-val { color: #16a34a; }
.rs-yellow .rs-val { color: #ca8a04; }
.rs-red .rs-val { color: #dc2626; }
.rs-blue .rs-val { color: var(--color-ocean); font-size: 18px; }
.rs-orange .rs-val { color: #ea580c; font-size: 18px; }

.rs-label {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

.export-actions {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
}

.btn-export {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 18px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
  background: var(--color-white);
}

.btn-excel {
  color: #16a34a;
  border-color: #bbf7d0;
}

.btn-excel:hover {
  background: #f0fdf4;
}

.btn-pdf {
  color: #dc2626;
  border-color: #fecaca;
}

.btn-pdf:hover {
  background: #fef2f2;
}

.report-table {
  font-size: 13px;
}

.chart-section {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 20px;
}

.chart-section h3 {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-dark);
  margin: 0 0 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.chart-section h3 .material-symbols-rounded {
  color: var(--color-accent);
}

.bar-chart {
  display: flex;
  justify-content: space-around;
  align-items: flex-end;
  height: 220px;
  gap: 12px;
}

.bar-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.bar-value {
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-mid);
}

.bar-track-v {
  width: 100%;
  max-width: 48px;
  height: 140px;
  display: flex;
  align-items: flex-end;
}

.bar-fill-v {
  width: 100%;
  background: var(--color-accent);
  border-radius: 5px 5px 0 0;
  min-height: 4px;
  transition: height 0.5s ease;
}

.bar-label {
  font-size: 12px;
  color: var(--color-text-muted);
  font-weight: 500;
}

.bar-sub {
  font-size: 10px;
  color: var(--color-text-muted);
}

.card-header {
  padding: 14px 20px;
  border-bottom: 1px solid var(--color-sand-dark);
}

.card-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-dark);
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-header h3 .material-symbols-rounded {
  color: var(--color-accent);
}

/* Payment type breakdown */
.payment-type-section {
  margin-bottom: 20px;
}

.section-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-dark);
  margin: 0 0 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.section-title .material-symbols-rounded {
  color: var(--color-accent);
}

.payment-type-cards {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.pt-card {
  flex: 1;
  min-width: 200px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 12px;
  padding: 16px;
}

.pt-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  gap: 8px;
  flex-wrap: wrap;
}

.pt-badge {
  font-size: 12px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 99px;
}

.pt-full   { background: #dbeafe; color: #1d4ed8; }
.pt-deposit { background: #fef9c3; color: #854d0e; }
.pt-installment { background: #fce7f3; color: #9d174d; }

.pt-count {
  font-size: 12px;
  color: var(--color-text-muted);
}

.pt-amounts {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pt-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.pt-key {
  font-size: 12px;
  color: var(--color-text-muted);
}

.pt-val {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-dark);
}

.pt-paid { color: #16a34a; }
.pt-remaining { color: #ea580c; }

.pt-row-remaining {
  padding-top: 6px;
  border-top: 1px dashed var(--color-sand-dark);
  margin-top: 2px;
}

/* Money color classes */
.money-green { color: #16a34a !important; }
.money-orange { color: #ea580c !important; }

/* Bar chart paid overlay */
.bar-fill-v {
  position: relative;
  overflow: hidden;
}

.bar-paid-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: rgba(255, 255, 255, 0.35);
  border-radius: 5px 5px 0 0;
  pointer-events: none;
}

.bar-legend {
  display: flex;
  gap: 16px;
  justify-content: center;
  margin-top: 10px;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--color-text-muted);
}

.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 2px;
}

.legend-total { background: var(--color-accent); }
.legend-paid  { background: rgba(255,255,255,0.35); border: 1px solid var(--color-accent); }

/* Table footer total row */
.tfoot-total td {
  background: var(--color-sand);
  border-top: 2px solid var(--color-sand-dark);
  font-size: 13px;
}

@media (max-width: 768px) {
  .report-filters {
    flex-direction: column;
  }
  .filter-actions {
    margin-left: 0;
  }
  .report-summary {
    flex-direction: column;
  }
}
</style>
