<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">group</span> จัดการลูกค้า</h1>
        <p class="page-subtitle">ข้อมูลลูกค้าและประวัติการจอง</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded" style="font-size:18px;">search</span>
        <input v-model="searchQuery" placeholder="ค้นหาชื่อ, อีเมล, เบอร์โทร..." @keyup.enter="fetchData()" />
      </div>
      <select v-model="filters.sort" @change="fetchData()">
        <option value="newest">ล่าสุด</option>
        <option value="name">ชื่อ A-Z</option>
        <option value="bookings">จองมากสุด</option>
      </select>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
      <template v-else>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>ลูกค้า</th>
                <th>เบอร์โทร</th>
                <th>จำนวนจอง</th>
                <th>ยอดใช้จ่าย</th>
                <th>จองล่าสุด</th>
                <th>สมัครเมื่อ</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in admin.customers.data" :key="c.id">
                <td>
                  <div class="customer-cell">
                    <div class="customer-avatar">
                      <img v-if="c.avatar_url" :src="c.avatar_url" :alt="c.name" class="avatar-img" />
                      <span v-else>{{ c.name?.charAt(0)?.toUpperCase() }}</span>
                    </div>
                    <div>
                      <span class="customer-name">{{ c.name }}</span>
                      <span class="customer-email">{{ c.email }}</span>
                    </div>
                  </div>
                </td>
                <td>{{ c.phone || '-' }}</td>
                <td><span class="booking-count-badge">{{ c.bookings_count }}</span></td>
                <td class="money">{{ formatMoney(c.total_spent) }}</td>
                <td class="date">{{ formatDate(c.last_booking_at) }}</td>
                <td class="date">{{ formatDate(c.created_at) }}</td>
                <td>
                  <button class="btn-icon btn-edit" @click="openDetail(c)" title="ดูรายละเอียด">
                    <span class="material-symbols-rounded" style="font-size:16px;">visibility</span>
                  </button>
                </td>
              </tr>
              <tr v-if="!admin.customers.data?.length">
                <td colspan="7" class="empty-state">ไม่พบลูกค้า</td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Pagination -->
        <div class="pagination" v-if="admin.customers.meta?.last_page > 1">
          <button @click="goPage(admin.customers.meta.current_page - 1)" :disabled="admin.customers.meta.current_page <= 1">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <span class="page-info">{{ admin.customers.meta.current_page }} / {{ admin.customers.meta.last_page }}</span>
          <button @click="goPage(admin.customers.meta.current_page + 1)" :disabled="admin.customers.meta.current_page >= admin.customers.meta.last_page">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
      </template>
    </div>

    <!-- Customer Detail Modal -->
    <div class="modal-overlay" v-if="showDetail">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2><span class="material-symbols-rounded" style="margin-right:8px; vertical-align:text-bottom;">person</span> {{ detail?.customer?.name }}</h2>
          <button class="modal-close" @click="showDetail = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body" v-if="detail">
          <!-- Customer Info -->
          <div class="customer-info-grid">
            <div class="info-card">
              <span class="material-symbols-rounded">mail</span>
              <div>
                <span class="info-label">อีเมล</span>
                <span class="info-value">{{ detail.customer.email }}</span>
              </div>
            </div>
            <div class="info-card">
              <span class="material-symbols-rounded">phone</span>
              <div>
                <span class="info-label">เบอร์โทร</span>
                <span class="info-value">{{ detail.customer.phone || '-' }}</span>
              </div>
            </div>
            <div class="info-card">
              <span class="material-symbols-rounded">calendar_today</span>
              <div>
                <span class="info-label">สมัครเมื่อ</span>
                <span class="info-value">{{ formatDate(detail.customer.created_at) }}</span>
              </div>
            </div>
          </div>

          <!-- Stats -->
          <div class="customer-stats">
            <div class="cs-item">
              <span class="cs-value">{{ detail.stats.total_bookings }}</span>
              <span class="cs-label">จองทั้งหมด</span>
            </div>
            <div class="cs-item cs-confirmed">
              <span class="cs-value">{{ detail.stats.confirmed }}</span>
              <span class="cs-label">ยืนยันแล้ว</span>
            </div>
            <div class="cs-item cs-cancelled">
              <span class="cs-value">{{ detail.stats.cancelled }}</span>
              <span class="cs-label">ยกเลิก</span>
            </div>
            <div class="cs-item cs-spent">
              <span class="cs-value">{{ formatMoney(detail.stats.total_spent) }}</span>
              <span class="cs-label">ยอดใช้จ่ายรวม</span>
            </div>
            <div class="cs-item">
              <span class="cs-value">{{ detail.stats.total_passengers }}</span>
              <span class="cs-label">ผู้โดยสารรวม</span>
            </div>
          </div>

          <!-- Booking History -->
          <h3 class="section-title"><span class="material-symbols-rounded">history</span> ประวัติการจอง</h3>
          <div class="booking-history">
            <div v-for="b in detail.bookings" :key="b.id" class="history-item">
              <div class="history-main">
                <span class="booking-ref">{{ b.booking_ref }}</span>
                <span class="history-trip">{{ b.schedule?.trip?.title || '-' }}</span>
                <span class="status-badge" :class="`status-${b.status}`">{{ statusLabels[b.status] }}</span>
              </div>
              <div class="history-meta">
                <span>{{ b.passengers?.length || 0 }} ผู้โดยสาร</span>
                <span class="money-sm">{{ formatMoney(b.total_amount) }}</span>
                <span class="date-sm">{{ formatDate(b.created_at) }}</span>
              </div>
            </div>
            <p v-if="!detail.bookings?.length" class="empty-text">ยังไม่มีประวัติการจอง</p>
          </div>
        </div>
        <div class="loading-state" v-else><div class="spinner"></div></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();
const searchQuery = ref('');
const filters = reactive({ sort: 'newest' });
const showDetail = ref(false);
const detail = ref(null);

const statusLabels = { pending: 'รอดำเนินการ', confirmed: 'ยืนยันแล้ว', cancelled: 'ยกเลิก', refunded: 'คืนเงินแล้ว' };

function formatMoney(amount) {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function fetchData(page = 1) {
  admin.fetchCustomers({ search: searchQuery.value || undefined, sort: filters.sort, page });
}

function goPage(p) {
  fetchData(p);
}

async function openDetail(c) {
  showDetail.value = true;
  detail.value = null;
  try {
    const res = await admin.fetchCustomerDetail(c.id);
    detail.value = res.data;
  } catch (e) {
    alert('ไม่สามารถโหลดข้อมูลลูกค้าได้');
    showDetail.value = false;
  }
}

onMounted(() => fetchData());
</script>

<style scoped>
@import url('./admin-shared.css');

.customer-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.customer-avatar {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: var(--color-accent);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
  overflow: hidden;
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.customer-name {
  display: block;
  font-weight: 600;
  color: var(--color-text-dark);
  font-size: 14px;
}

.customer-email {
  font-size: 12px;
  color: var(--color-text-muted);
}

.booking-count-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 24px;
  background: var(--color-sand);
  color: var(--color-accent);
  border-radius: 6px;
  font-size: 13px;
  font-weight: 700;
}

.modal-lg {
  max-width: 800px;
}

.customer-info-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-bottom: 20px;
}

.info-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  background: var(--color-sand);
  border-radius: 8px;
  border: 1px solid var(--color-sand-dark);
}

.info-card .material-symbols-rounded {
  color: var(--color-accent);
  font-size: 20px;
}

.info-label {
  display: block;
  font-size: 11px;
  color: var(--color-text-muted);
}

.info-value {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-dark);
}

.customer-stats {
  display: flex;
  gap: 12px;
  margin-bottom: 24px;
  flex-wrap: wrap;
}

.cs-item {
  flex: 1;
  min-width: 100px;
  text-align: center;
  padding: 14px 10px;
  background: var(--color-sand);
  border-radius: 8px;
  border: 1px solid var(--color-sand-dark);
}

.cs-value {
  display: block;
  font-size: 22px;
  font-weight: 700;
  color: var(--color-text-dark);
}

.cs-confirmed .cs-value { color: #16a34a; }
.cs-cancelled .cs-value { color: #dc2626; }
.cs-spent .cs-value { color: var(--color-ocean); font-size: 18px; }

.cs-label {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

.section-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--color-text-dark);
  margin: 0 0 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.section-title .material-symbols-rounded {
  color: var(--color-accent);
}

.booking-history {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 300px;
  overflow-y: auto;
}

.history-item {
  padding: 10px 14px;
  background: var(--color-white);
  border-radius: 8px;
  border: 1px solid var(--color-sand-dark);
}

.history-main {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
}

.history-trip {
  flex: 1;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-mid);
}

.history-meta {
  display: flex;
  gap: 16px;
  font-size: 12px;
  color: var(--color-text-muted);
}

.money-sm {
  font-weight: 600;
  color: var(--color-text-dark);
}

.date-sm {
  color: var(--color-text-muted);
}

.empty-text {
  text-align: center;
  color: var(--color-text-muted);
  font-size: 14px;
  padding: 24px;
}

@media (max-width: 768px) {
  .customer-info-grid {
    grid-template-columns: 1fr;
  }
  .customer-stats {
    flex-direction: column;
  }
}
</style>
