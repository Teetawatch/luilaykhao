<template>
  <div class="inc-page">
    <div class="inc-head">
      <div class="inc-title">
        <h2><i class="fas fa-triangle-exclamation"></i> แจ้งเหตุ / อุบัติเหตุในทริป</h2>
        <span class="inc-sub">เหตุที่สตาฟรายงานจากหน้างาน — ตรวจสอบและปิดเคสเมื่อจัดการเรียบร้อย</span>
      </div>
      <button class="refresh-btn" :disabled="loading" @click="load" title="รีเฟรช">
        <i class="fas fa-sync" :class="{ spin: loading }"></i>
      </button>
    </div>

    <div class="inc-filters">
      <button
        v-for="f in filters"
        :key="f.value"
        class="filter-chip"
        :class="{ active: status === f.value }"
        @click="setStatus(f.value)"
      >
        {{ f.label }}
        <span v-if="f.value === 'open' && openCount" class="count-badge">{{ openCount }}</span>
      </button>
    </div>

    <div v-if="loading && !incidents.length" class="empty-hint">กำลังโหลด...</div>
    <div v-else-if="!incidents.length" class="empty-hint">
      <i class="fas fa-circle-check"></i>
      <p>{{ status === 'resolved' ? 'ยังไม่มีเคสที่ปิดแล้ว' : 'ไม่มีการแจ้งเหตุ' }}</p>
    </div>

    <div v-else class="inc-list">
      <article
        v-for="it in incidents"
        :key="it.id"
        class="inc-card"
        :class="{ resolved: it.status === 'resolved' }"
        :style="it.status !== 'resolved' ? { borderColor: sevColor(it.severity) } : {}"
      >
        <div class="inc-card-side" :style="{ background: sevColor(it.severity) }"></div>

        <div class="inc-card-main">
          <div class="inc-card-top">
            <span class="sev-badge" :style="{ background: sevColor(it.severity) + '1f', color: sevColor(it.severity) }">
              {{ it.severity_label || it.severity }}
            </span>
            <span class="status-badge" :class="it.status">
              {{ it.status === 'resolved' ? 'ปิดเคสแล้ว' : 'รอดำเนินการ' }}
            </span>
            <span class="inc-time">{{ formatDateTime(it.created_at) }}</span>
          </div>

          <div class="inc-trip">
            <i class="fas fa-mountain-sun"></i>
            <span class="trip-title">{{ it.trip_title || 'ทริป' }}</span>
            <span v-if="it.departure_date" class="trip-date">· เดินทาง {{ formatDate(it.departure_date) }}</span>
          </div>

          <div v-if="it.passenger_name" class="inc-person">
            <i class="fas fa-user-injured"></i> {{ it.passenger_name }}
          </div>

          <p class="inc-desc">{{ it.description }}</p>

          <a v-if="it.photo_url" :href="it.photo_url" target="_blank" class="inc-photo">
            <img :src="it.photo_url" alt="รูปประกอบ" />
          </a>

          <div class="inc-foot">
            <span class="inc-reporter"><i class="fas fa-user-shield"></i> แจ้งโดย {{ it.reported_by_name || 'ไม่ระบุ' }}</span>
            <a
              v-if="it.latitude != null && it.longitude != null"
              class="inc-map"
              :href="`https://www.google.com/maps/search/?api=1&query=${it.latitude},${it.longitude}`"
              target="_blank"
            ><i class="fas fa-location-dot"></i> ดูตำแหน่ง</a>
            <span v-if="it.status === 'resolved' && it.resolved_by_name" class="inc-resolver">
              <i class="fas fa-circle-check"></i> ปิดโดย {{ it.resolved_by_name }}
            </span>
            <button
              v-if="it.status !== 'resolved'"
              class="resolve-btn"
              :disabled="resolvingId === it.id"
              @click="resolve(it)"
            >
              <i class="fas" :class="resolvingId === it.id ? 'fa-spinner fa-spin' : 'fa-check'"></i>
              ปิดเคส
            </button>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

const toast = useToast();
const swal = useSwal();

const filters = [
  { value: 'open', label: 'รอดำเนินการ' },
  { value: 'resolved', label: 'ปิดเคสแล้ว' },
  { value: '', label: 'ทั้งหมด' },
];

const SEVERITY_COLORS = {
  minor: '#059669',
  moderate: '#D97706',
  severe: '#EA580C',
  critical: '#DC2626',
};

const incidents = ref([]);
const loading = ref(false);
const status = ref('open');
const resolvingId = ref(null);

const openCount = computed(
  () => incidents.value.filter((i) => i.status !== 'resolved').length,
);

function sevColor(v) {
  return SEVERITY_COLORS[v] || '#D97706';
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}
function formatDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/incidents', {
      params: status.value ? { status: status.value } : {},
    });
    incidents.value = res.data.data || [];
  } catch {
    toast.error('โหลดรายการแจ้งเหตุไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

function setStatus(value) {
  if (status.value === value) return;
  status.value = value;
  load();
}

async function resolve(it) {
  const ok = await swal.confirm({
    title: 'ปิดเคสนี้?',
    text: `${it.trip_title || 'ทริป'} — ${it.passenger_name || 'ผู้โดยสาร'}`,
    icon: 'question',
    confirmText: 'ปิดเคส',
  });
  if (!ok.isConfirmed) return;

  resolvingId.value = it.id;
  try {
    await api.post(`/admin/incidents/${it.id}/resolve`);
    toast.success('ปิดเคสแล้ว');
    load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ปิดเคสไม่สำเร็จ');
  } finally {
    resolvingId.value = null;
  }
}

onMounted(load);
</script>

<style scoped>
.inc-page { padding: 4px; }

.inc-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
.inc-title h2 { font-size: 18px; font-weight: 800; margin: 0; color: #1f2937; }
.inc-title h2 i { color: #DC2626; margin-right: 8px; }
.inc-sub { font-size: 12.5px; color: #6b7280; }
.refresh-btn { border: none; background: #f3f4f6; border-radius: 8px; padding: 9px 12px; cursor: pointer; color: #4b5563; }
.refresh-btn:hover { background: #e5e7eb; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.inc-filters { display: flex; gap: 8px; margin-bottom: 16px; }
.filter-chip {
  border: 1px solid #e5e7eb; background: #fff; color: #4b5563;
  border-radius: 999px; padding: 7px 16px; font-size: 13px; font-weight: 700;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.filter-chip.active { background: #1f2937; border-color: #1f2937; color: #fff; }
.count-badge { background: #DC2626; color: #fff; border-radius: 999px; font-size: 11px; padding: 1px 7px; font-weight: 800; }

.empty-hint { padding: 60px 16px; color: #9ca3af; font-size: 14px; text-align: center; }
.empty-hint i { font-size: 40px; color: #d1d5db; display: block; margin-bottom: 12px; }
.empty-hint p { margin: 0; }

.inc-list { display: flex; flex-direction: column; gap: 12px; }
.inc-card {
  display: flex; background: #fff; border: 1px solid #e9edf0;
  border-radius: 14px; overflow: hidden;
}
.inc-card.resolved { opacity: 0.78; }
.inc-card-side { width: 5px; flex-shrink: 0; }
.inc-card.resolved .inc-card-side { background: #cbd5e1 !important; }
.inc-card-main { flex: 1; min-width: 0; padding: 14px 16px; }

.inc-card-top { display: flex; align-items: center; gap: 8px; }
.sev-badge { font-size: 11.5px; font-weight: 800; padding: 3px 10px; border-radius: 999px; }
.status-badge { font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.status-badge.open { background: #fef3c7; color: #b45309; }
.status-badge.resolved { background: #dcfce7; color: #15803d; }
.inc-time { margin-left: auto; font-size: 11.5px; color: #9ca3af; }

.inc-trip { margin-top: 10px; font-size: 13.5px; color: #374151; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.inc-trip i { color: #2D7A4F; }
.trip-title { font-weight: 800; }
.trip-date { color: #6b7280; font-weight: 600; }

.inc-person { margin-top: 6px; font-size: 13px; font-weight: 700; color: #b91c1c; }
.inc-person i { margin-right: 4px; }

.inc-desc { margin: 8px 0 0; font-size: 13.5px; line-height: 1.55; color: #4b5563; white-space: pre-wrap; word-break: break-word; }

.inc-photo { display: inline-block; margin-top: 10px; }
.inc-photo img { max-height: 160px; border-radius: 10px; border: 1px solid #e5e7eb; }

.inc-foot { display: flex; align-items: center; gap: 14px; margin-top: 12px; flex-wrap: wrap; }
.inc-reporter, .inc-resolver { font-size: 12px; font-weight: 600; color: #6b7280; }
.inc-reporter i { color: #2D7A4F; margin-right: 4px; }
.inc-resolver i { color: #16a34a; margin-right: 4px; }
.inc-map { font-size: 12px; font-weight: 700; color: #2563eb; text-decoration: none; }
.inc-map i { margin-right: 3px; }
.resolve-btn {
  margin-left: auto; border: none; background: #16a34a; color: #fff;
  border-radius: 9px; padding: 7px 16px; font-size: 13px; font-weight: 800;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.resolve-btn:hover { background: #15803d; }
.resolve-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
</style>
