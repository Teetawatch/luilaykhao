<template>
  <div class="admin-page rental-orders-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">add_shopping_cart</span> เช่าอุปกรณ์เพิ่มในแอป</h1>
        <p class="page-subtitle">
          ของที่ลูกค้าสั่งเช่าเองหลังจอง — สั่งได้ถึงสิ้นวัน {{ cutoffDays }} วันก่อนเดินทาง
          ก้อนที่ไม่จ่ายถูกยกเลิกเองหลังปิดรับ 1 ชั่วโมง
        </p>
      </div>
      <div class="head-actions">
        <router-link to="/admin/rentals" class="btn-secondary">
          <span class="material-symbols-rounded">backpack</span> ใบรวมของต่อรอบ
        </router-link>
        <router-link to="/admin/settings" class="btn-secondary">
          <span class="material-symbols-rounded">settings</span> ตั้งวันปิดรับ
        </router-link>
      </div>
    </div>

    <div class="summary-row">
      <button
        v-for="tile in tiles"
        :key="tile.status"
        class="summary-tile"
        :class="[`tone-${tile.tone}`, { active: filters.status === tile.status }]"
        @click="toggleStatus(tile.status)"
      >
        <span class="summary-label">{{ tile.label }}</span>
        <span class="summary-value">{{ tile.orders }} รายการ</span>
        <span class="summary-amount">฿{{ formatMoney(tile.amount) }}</span>
      </button>
    </div>

    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="filters.q" placeholder="เลขที่การจอง / ชื่อ / เบอร์ / ชื่อทริป..." @input="debouncedLoad" />
      </div>
    </div>

    <div v-if="loading" class="loading-state"><div class="spinner"></div></div>

    <div v-else-if="!orders.length" class="empty-state">
      <span class="material-symbols-rounded">inventory_2</span>
      <p>{{ filters.status || filters.q ? 'ไม่พบรายการที่ตรงกับตัวกรอง' : 'ยังไม่มีลูกค้าสั่งเช่าเพิ่มในแอป' }}</p>
    </div>

    <div v-else class="order-list">
      <article v-for="o in orders" :key="o.id" class="order-card">
        <header class="order-head">
          <span class="status-pill" :class="`st-${o.status}`">{{ statusLabel(o.status) }}</span>
          <router-link :to="{ path: '/admin/bookings', query: { search: o.booking_ref } }" class="booking-ref">
            {{ o.booking_ref }}
          </router-link>
          <span class="order-amount">฿{{ formatMoney(o.amount) }}</span>
        </header>

        <div class="order-meta">
          <span><span class="material-symbols-rounded">person</span>{{ o.customer_name || '—' }}<template v-if="o.customer_phone"> · {{ o.customer_phone }}</template></span>
          <span><span class="material-symbols-rounded">landscape</span>{{ o.trip_title || '—' }}</span>
          <span v-if="o.departure_date_thai"><span class="material-symbols-rounded">event</span>{{ o.departure_date_thai }}</span>
        </div>

        <ul class="order-items">
          <li v-for="(item, i) in o.items" :key="i">
            <span>{{ item.name }} × {{ item.quantity }}</span>
            <span>฿{{ formatMoney(item.total_price) }}</span>
          </li>
        </ul>

        <footer class="order-foot">
          <span>สั่งเมื่อ {{ formatDateTime(o.created_at) }}</span>
          <span v-if="o.paid_at">· จ่ายเมื่อ {{ formatDateTime(o.paid_at) }}</span>
          <span v-else-if="o.cancelled_at">· ยกเลิก {{ formatDateTime(o.cancelled_at) }}<template v-if="o.cancel_reason"> ({{ o.cancel_reason }})</template></span>
          <span v-else-if="o.closes_at" class="closes">· ปิดรับ {{ formatDateTime(o.closes_at) }}</span>
        </footer>
      </article>
    </div>

    <div v-if="meta.last_page > 1" class="pagination">
      <button :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">
        <span class="material-symbols-rounded">chevron_left</span>
      </button>
      <span class="page-info">หน้า {{ meta.current_page }} / {{ meta.last_page }}</span>
      <button :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">
        <span class="material-symbols-rounded">chevron_right</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import './admin-shared.css';

const toast = useToast();

const loading = ref(true);
const orders = ref([]);
const meta = ref({ current_page: 1, last_page: 1 });
const summary = ref({});
const cutoffDays = ref(3);
const filters = reactive({ status: '', q: '' });

const STATUS = {
  awaiting_payment: { label: 'รอชำระ', tone: 'amber' },
  paid: { label: 'จ่ายแล้ว', tone: 'green' },
  cancelled: { label: 'ยกเลิก', tone: 'grey' },
};

const tiles = computed(() =>
  Object.entries(STATUS).map(([status, s]) => ({
    status,
    label: s.label,
    tone: s.tone,
    orders: summary.value[status]?.orders ?? 0,
    amount: summary.value[status]?.amount ?? 0,
  })),
);

function statusLabel(status) {
  return STATUS[status]?.label ?? status;
}

function formatMoney(n) {
  return Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 0 });
}

function formatDateTime(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleString('th-TH', {
    timeZone: 'Asia/Bangkok',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

async function load(page = 1) {
  loading.value = true;
  try {
    const res = await api.get('/admin/rentals/orders', {
      params: { page, status: filters.status || undefined, q: filters.q.trim() || undefined },
    });
    orders.value = res.data.data || [];
    meta.value = res.data.meta || { current_page: 1, last_page: 1 };
    summary.value = res.data.meta?.summary || {};
    cutoffDays.value = res.data.meta?.cutoff_days ?? 3;
  } catch {
    toast.error('โหลดรายการเช่าไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

let timer;
function debouncedLoad() {
  clearTimeout(timer);
  timer = setTimeout(() => load(1), 300);
}

function toggleStatus(status) {
  filters.status = filters.status === status ? '' : status;
  load(1);
}

onMounted(() => load());
</script>

<style scoped>
.head-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.head-actions .btn-secondary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  text-decoration: none;
}
.summary-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}
.summary-tile {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 12px 14px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  text-align: left;
}
.summary-tile.active {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px rgba(45, 122, 79, 0.12);
}
.summary-label {
  font-size: 13px;
  color: #6b7280;
}
.summary-value {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}
.summary-amount {
  font-size: 13px;
  color: #374151;
}
.tone-amber .summary-label { color: #b45309; }
.tone-green .summary-label { color: #15803d; }
.order-list {
  display: grid;
  gap: 10px;
}
.order-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  padding: 12px 14px;
  min-width: 0;
}
.order-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.order-amount {
  margin-left: auto;
  font-weight: 700;
  color: #111827;
}
.status-pill {
  font-size: 12px;
  font-weight: 600;
  padding: 2px 10px;
  border-radius: 999px;
}
.st-awaiting_payment { background: #fef3c7; color: #92400e; }
.st-paid { background: #dcfce7; color: #166534; }
.st-cancelled { background: #f3f4f6; color: #4b5563; }
.order-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  margin-top: 8px;
  font-size: 13px;
  color: #374151;
}
.order-meta > span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  overflow-wrap: anywhere;
}
.order-meta .material-symbols-rounded {
  font-size: 16px;
  color: #9ca3af;
}
.order-items {
  list-style: none;
  margin: 10px 0 0;
  padding: 8px 0 0;
  border-top: 1px dashed #e5e7eb;
  display: grid;
  gap: 4px;
  font-size: 14px;
}
.order-items li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.order-foot {
  margin-top: 8px;
  font-size: 12px;
  color: #6b7280;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.order-foot .closes { color: #b45309; }
@media (max-width: 480px) {
  .summary-row { gap: 6px; }
  .summary-tile { padding: 10px; }
  .summary-value { font-size: 15px; }
}
</style>
