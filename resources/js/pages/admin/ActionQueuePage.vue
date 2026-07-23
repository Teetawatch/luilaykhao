<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">checklist</span> สิ่งที่รอคุณ</h1>
        <p class="page-subtitle">งานค้างจากทุกหน้ารวมไว้ที่เดียว เรียงตามความเร่งด่วน — กดที่การ์ดเพื่อไปจัดการ</p>
      </div>
      <button class="btn-secondary" :disabled="loading" @click="load">
        <span class="material-symbols-rounded" :class="{ spin: loading }">refresh</span> รีเฟรช
      </button>
    </div>

    <div v-if="loading && !groups.length" class="loading-state"><div class="spinner"></div></div>

    <template v-else>
      <!-- สรุปบนสุด: ว่างจริงหรือมีอะไรค้าง -->
      <div v-if="total === 0" class="all-clear">
        <span class="material-symbols-rounded">task_alt</span>
        <div>
          <strong>เคลียร์หมดแล้ว</strong>
          <p>ตอนนี้ไม่มีงานค้างรอการตัดสินใจ</p>
        </div>
      </div>

      <div v-else class="summary-bar" :class="{ urgent: urgent > 0 }">
        <span class="material-symbols-rounded">{{ urgent > 0 ? 'e911_emergency' : 'pending_actions' }}</span>
        <div>
          <strong>มีงานรอคุณอยู่ {{ total }} รายการ</strong>
          <p v-if="urgent > 0">ในนั้นเป็นเรื่องความปลอดภัย {{ urgent }} รายการ — จัดการก่อนเป็นอันดับแรก</p>
          <p v-else>ไม่มีเรื่องเร่งด่วนด้านความปลอดภัย</p>
        </div>
      </div>

      <div class="queue-grid">
        <router-link
          v-for="g in visibleGroups"
          :key="g.key"
          :to="g.route"
          class="queue-card"
          :class="g.severity"
        >
          <div class="card-head">
            <span class="card-icon"><span class="material-symbols-rounded">{{ g.icon }}</span></span>
            <span class="card-count">{{ g.count }}</span>
          </div>
          <h3 class="card-label">{{ g.label }}</h3>

          <ul v-if="g.items.length" class="card-items">
            <li v-for="(item, i) in g.items" :key="i">
              <span class="item-title">{{ item.title }}</span>
              <span v-if="item.detail" class="item-detail">{{ item.detail }}</span>
              <span v-if="item.at" class="item-at">{{ timeAgo(item.at) }}</span>
            </li>
          </ul>

          <span v-if="g.count > g.items.length" class="card-more">
            และอีก {{ g.count - g.items.length }} รายการ
          </span>
          <span class="card-cta">ไปจัดการ <span class="material-symbols-rounded">arrow_forward</span></span>
        </router-link>
      </div>

      <button v-if="clearedGroups.length" class="show-cleared" @click="showCleared = !showCleared">
        {{ showCleared ? 'ซ่อน' : 'แสดง' }}กองงานที่เคลียร์แล้ว ({{ clearedGroups.length }})
      </button>

      <div v-if="showCleared" class="cleared-grid">
        <router-link v-for="g in clearedGroups" :key="g.key" :to="g.route" class="cleared-card">
          <span class="material-symbols-rounded">{{ g.icon }}</span>
          <span class="cleared-label">{{ g.label }}</span>
          <span class="cleared-mark">เคลียร์แล้ว</span>
        </router-link>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import './admin-shared.css';

const toast = useToast();

const groups = ref([]);
const total = ref(0);
const urgent = ref(0);
const loading = ref(false);
const showCleared = ref(false);
let refreshTimer = null;

// เรียงกองงานตามความเร่งด่วน แล้วค่อยตามจำนวนที่ค้าง
const SEVERITY_ORDER = { critical: 0, high: 1, medium: 2, low: 3 };

const visibleGroups = computed(() =>
  groups.value
    .filter((g) => g.count > 0)
    .sort((a, b) =>
      (SEVERITY_ORDER[a.severity] ?? 9) - (SEVERITY_ORDER[b.severity] ?? 9)
      || b.count - a.count),
);

const clearedGroups = computed(() => groups.value.filter((g) => g.count === 0));

function timeAgo(at) {
  const mins = Math.floor((Date.now() - new Date(at).getTime()) / 60000);
  if (mins < 1) return 'เมื่อครู่';
  if (mins < 60) return `${mins} นาทีที่แล้ว`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours} ชม.ที่แล้ว`;
  const days = Math.floor(hours / 24);
  if (days < 30) return `${days} วันที่แล้ว`;
  return new Date(at).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

async function load(silent = false) {
  if (!silent) loading.value = true;
  try {
    const res = await api.get('/admin/action-queue');
    groups.value = res.data.data.groups || [];
    total.value = res.data.data.total || 0;
    urgent.value = res.data.data.urgent || 0;
  } catch {
    if (!silent) toast.error('โหลดรายการงานค้างไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  load();
  // หน้านี้มักถูกเปิดค้างไว้เป็นแดชบอร์ดประจำวัน จึงรีเฟรชเงียบ ๆ ให้เอง
  refreshTimer = setInterval(() => load(true), 60000);
});

onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<style scoped>
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.all-clear, .summary-bar {
  display: flex; align-items: center; gap: 16px;
  border-radius: 12px; padding: 16px 20px; margin-bottom: 22px;
}
.all-clear { background: #f0fdf4; border: 1px solid #bbf7d0; }
.all-clear .material-symbols-rounded { font-size: 34px !important; color: #16a34a; }
.all-clear strong { font-size: 16px; color: #15803d; }
.all-clear p { margin: 2px 0 0; font-size: 13px; color: #16a34a; }

.summary-bar { background: #f9fafb; border: 1px solid #e5e7eb; }
.summary-bar .material-symbols-rounded { font-size: 32px !important; color: #6b7280; }
.summary-bar strong { font-size: 16px; color: #111827; }
.summary-bar p { margin: 2px 0 0; font-size: 13px; color: #6b7280; }
.summary-bar.urgent { background: #fef2f2; border-color: #fecaca; }
.summary-bar.urgent .material-symbols-rounded { color: #dc2626; }
.summary-bar.urgent strong { color: #991b1b; }
.summary-bar.urgent p { color: #b91c1c; }

.queue-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 16px;
}

.queue-card {
  display: flex; flex-direction: column;
  background: #fff; border: 1px solid #e5e7eb; border-top: 3px solid #9ca3af;
  border-radius: 12px; padding: 16px 18px; text-decoration: none;
  transition: border-color 0.15s, transform 0.15s;
}
.queue-card:hover { transform: translateY(-2px); border-color: #9ca3af; }
.queue-card.critical { border-top-color: #dc2626; }
.queue-card.high { border-top-color: #ea580c; }
.queue-card.medium { border-top-color: #d97706; }
.queue-card.low { border-top-color: #64748b; }

.card-head { display: flex; align-items: center; justify-content: space-between; }
.card-icon .material-symbols-rounded { font-size: 22px !important; color: #9ca3af; }
.queue-card.critical .card-icon .material-symbols-rounded { color: #dc2626; }
.queue-card.high .card-icon .material-symbols-rounded { color: #ea580c; }
.queue-card.medium .card-icon .material-symbols-rounded { color: #d97706; }
.card-count { font-size: 26px; font-weight: 800; color: #111827; line-height: 1; }

.card-label { margin: 10px 0 0; font-size: 14.5px; font-weight: 700; color: #1f2937; }

.card-items {
  list-style: none; margin: 12px 0 0; padding: 12px 0 0;
  border-top: 1px solid #f3f4f6; display: flex; flex-direction: column; gap: 7px;
}
.card-items li { display: flex; align-items: baseline; gap: 7px; min-width: 0; }
.item-title { font-size: 12.5px; font-weight: 700; color: #374151; white-space: nowrap; }
.item-detail {
  font-size: 12px; color: #9ca3af; overflow: hidden;
  text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;
}
.item-at { font-size: 11px; color: #cbd5e1; white-space: nowrap; margin-left: auto; }

.card-more { margin-top: 9px; font-size: 11.5px; color: #9ca3af; }
.card-cta {
  display: inline-flex; align-items: center; gap: 4px; margin-top: 12px;
  font-size: 12.5px; font-weight: 700; color: var(--color-accent);
}
.card-cta .material-symbols-rounded { font-size: 15px !important; }

.show-cleared {
  margin-top: 22px; background: none; border: none; cursor: pointer;
  font-size: 12.5px; font-weight: 600; color: #9ca3af; text-decoration: underline;
}
.cleared-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 10px; margin-top: 12px;
}
.cleared-card {
  display: flex; align-items: center; gap: 9px;
  background: #fafafa; border: 1px solid #f3f4f6; border-radius: 9px;
  padding: 10px 14px; text-decoration: none;
}
.cleared-card .material-symbols-rounded { font-size: 18px !important; color: #cbd5e1; }
.cleared-label { font-size: 12.5px; color: #9ca3af; }
.cleared-mark { margin-left: auto; font-size: 11px; color: #16a34a; font-weight: 700; }
</style>
