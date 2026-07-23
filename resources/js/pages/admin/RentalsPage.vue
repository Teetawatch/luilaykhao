<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">backpack</span> อุปกรณ์เช่าที่ต้องเตรียม</h1>
        <p class="page-subtitle">รวมของที่ลูกค้าเช่าไว้ในแต่ละรอบ ใช้เป็นเช็กลิสต์ตอนขนของขึ้นรถและตอนรับของคืน</p>
      </div>
      <div class="head-actions">
        <label class="past-toggle">
          <input type="checkbox" v-model="includePast" @change="loadSchedules" />
          แสดงรอบที่ผ่านไปแล้ว
        </label>
        <button v-if="detail" class="btn-secondary" @click="printList">
          <span class="material-symbols-rounded">print</span> พิมพ์ใบรวม
        </button>
      </div>
    </div>

    <div v-if="loadingSchedules" class="loading-state"><div class="spinner"></div></div>

    <div v-else-if="!schedules.length" class="empty-state">
      <span class="material-symbols-rounded">inventory_2</span>
      <p>ยังไม่มีรอบไหนที่ลูกค้าเช่าอุปกรณ์</p>
      <span class="empty-hint">ตั้งรายการอุปกรณ์ให้เช่าได้ที่หน้าแก้ไขทริป → "อุปกรณ์ให้เช่า"</span>
    </div>

    <div v-else class="rental-layout">
      <!-- รายการรอบเดินทางที่มีของต้องเตรียม -->
      <aside class="schedule-rail">
        <button
          v-for="s in schedules"
          :key="s.id"
          class="schedule-item"
          :class="{ active: s.id === selectedId, past: s.is_past }"
          @click="select(s.id)"
        >
          <span class="sched-trip">{{ s.trip_title }}</span>
          <span class="sched-date">{{ s.departure_date_thai }}</span>
          <span class="sched-meta">
            {{ s.bookings_with_rentals }} ใบจอง · ฿{{ formatMoney(s.rentals_revenue) }}
          </span>
        </button>
      </aside>

      <section class="rental-detail" ref="printArea">
        <div v-if="loadingDetail" class="loading-state"><div class="spinner"></div></div>

        <template v-else-if="detail">
          <div class="detail-head">
            <div>
              <h2>{{ detail.schedule.trip_title }}</h2>
              <p>รอบเดินทาง {{ detail.schedule.departure_date_thai }}</p>
            </div>
            <div class="totals">
              <div class="total-cell">
                <span class="total-num">{{ detail.totals.pieces }}</span>
                <span class="total-label">ชิ้นที่ต้องขน</span>
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

          <h3 class="section-label">สรุปของที่ต้องเตรียม</h3>
          <div class="item-grid">
            <div v-for="item in detail.items" :key="item.name" class="item-card">
              <div class="item-thumb">
                <img v-if="item.image_url" :src="item.image_url" :alt="item.name" />
                <span v-else class="material-symbols-rounded">backpack</span>
              </div>
              <div class="item-info">
                <span class="item-name">{{ item.name }}</span>
                <span class="item-sub">{{ item.renters }} ใบจอง · ฿{{ formatMoney(item.revenue) }}</span>
              </div>
              <span class="item-qty">×{{ item.quantity }}</span>
            </div>
          </div>

          <h3 class="section-label">ของใครบ้าง</h3>
          <div class="table-card">
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>เลขการจอง</th>
                    <th>ลูกค้า</th>
                    <th>รายการที่เช่า</th>
                    <th class="num">ยอดค่าเช่า</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="b in detail.bookings" :key="b.booking_ref">
                    <td><strong>{{ b.booking_ref }}</strong></td>
                    <td>
                      {{ b.customer_name }}
                      <a v-if="b.phone" class="phone-link" :href="`tel:${b.phone}`">{{ b.phone }}</a>
                    </td>
                    <td>
                      <span v-for="it in b.items" :key="it.name" class="rent-chip">
                        {{ it.name }} ×{{ it.quantity }}
                      </span>
                    </td>
                    <td class="num">฿{{ formatMoney(b.rentals_total) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import './admin-shared.css';

const toast = useToast();

const schedules = ref([]);
const detail = ref(null);
const selectedId = ref(null);
const includePast = ref(false);
const loadingSchedules = ref(false);
const loadingDetail = ref(false);
const printArea = ref(null);

function formatMoney(v) {
  return Number(v || 0).toLocaleString('th-TH');
}

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

function printList() {
  window.print();
}

onMounted(loadSchedules);
</script>

<style scoped>
.head-actions { display: flex; align-items: center; gap: 10px; }
.past-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #6b7280; cursor: pointer; }

.empty-state { padding: 70px 16px; text-align: center; color: #9ca3af; }
.empty-state .material-symbols-rounded { font-size: 46px !important; color: #d1d5db; }
.empty-state p { margin: 10px 0 4px; font-size: 15px; font-weight: 600; }
.empty-hint { font-size: 13px; }

.rental-layout { display: grid; grid-template-columns: 260px 1fr; gap: 20px; align-items: start; }
@media (max-width: 900px) { .rental-layout { grid-template-columns: 1fr; } }

.schedule-rail { display: flex; flex-direction: column; gap: 8px; }
.schedule-item {
  display: flex; flex-direction: column; gap: 2px; text-align: left;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
  padding: 12px 14px; cursor: pointer; transition: all 0.15s;
}
.schedule-item:hover { border-color: #9ca3af; }
.schedule-item.active { border-color: var(--color-accent); background: #f0fdf4; }
.schedule-item.past { opacity: 0.65; }
.sched-trip { font-size: 14px; font-weight: 700; color: #1f2937; }
.sched-date { font-size: 12.5px; color: #4b5563; }
.sched-meta { font-size: 11.5px; color: #9ca3af; }

.rental-detail { min-width: 0; }
.detail-head {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;
  flex-wrap: wrap; background: #fff; border: 1px solid #e5e7eb;
  border-radius: 12px; padding: 18px 22px; margin-bottom: 20px;
}
.detail-head h2 { margin: 0; font-size: 19px; font-weight: 700; color: #111827; }
.detail-head p { margin: 2px 0 0; font-size: 13.5px; color: #6b7280; }
.totals { display: flex; gap: 26px; }
.total-cell { display: flex; flex-direction: column; align-items: center; }
.total-num { font-size: 21px; font-weight: 800; color: var(--color-accent); }
.total-label { font-size: 11.5px; color: #9ca3af; }

.section-label {
  font-size: 13px; font-weight: 700; color: #6b7280;
  text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px;
}

.item-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 12px; margin-bottom: 26px;
}
.item-card {
  display: flex; align-items: center; gap: 12px;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 16px;
}
.item-thumb {
  width: 46px; height: 46px; border-radius: 10px; flex-shrink: 0;
  background: #f3f4f6; display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.item-thumb .material-symbols-rounded { color: #9ca3af; font-size: 24px !important; }
.item-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.item-name { font-size: 14.5px; font-weight: 700; color: #1f2937; }
.item-sub { font-size: 12px; color: #9ca3af; }
.item-qty { font-size: 20px; font-weight: 800; color: var(--color-accent); }

.rent-chip {
  display: inline-block; background: #f3f4f6; color: #374151;
  border-radius: 6px; padding: 2px 9px; font-size: 12.5px; font-weight: 600;
  margin: 2px 4px 2px 0;
}
.phone-link { display: block; font-size: 12px; color: #2563eb; text-decoration: none; }
.num { text-align: right; }

@media print {
  .page-header, .schedule-rail { display: none; }
  .rental-layout { grid-template-columns: 1fr; }
}
</style>
