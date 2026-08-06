<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">flag</span> เนื้อหาที่ถูกรายงาน</h1>
        <p class="page-subtitle">แชท รีวิว โพสต์ และคอมเมนต์ที่ผู้ใช้แจ้งเข้ามา — ตรวจแล้วซ่อนหรือปิดเรื่อง</p>
      </div>
    </div>

    <div class="review-stats" v-if="counts">
      <div class="rs-card rs-red">
        <span class="rs-val">{{ counts.open }}</span>
        <span class="rs-lbl">รอตรวจ</span>
      </div>
      <div class="rs-card">
        <span class="rs-val">{{ counts.total }}</span>
        <span class="rs-lbl">รายงานทั้งหมด</span>
      </div>
    </div>

    <div class="filter-tabs">
      <button
        v-for="t in tabs"
        :key="t.value"
        class="filter-tab"
        :class="{ active: activeTab === t.value }"
        @click="setTab(t.value)">
        {{ t.label }}
        <span v-if="t.value === 'open' && counts?.open" class="tab-badge">{{ counts.open }}</span>
      </button>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <template v-else>
      <div class="report-list">
        <div
          v-for="report in reports"
          :key="report.id"
          class="report-card"
          :class="{ 'is-resolved': report.status !== 'open' }">

          <div class="report-head">
            <span class="type-chip">{{ typeLabel(report.type) }}</span>
            <span class="reason-chip">{{ report.reason_label }}</span>
            <span v-if="report.preview?.hidden" class="hidden-chip">
              <span class="material-symbols-rounded chip-icon">visibility_off</span> ซ่อนอยู่
            </span>
            <span v-if="report.status !== 'open'" class="done-chip">
              {{ report.status === 'actioned' ? 'จัดการแล้ว' : 'ปิดเรื่องแล้ว' }}
            </span>
            <span class="report-date">{{ formatDate(report.created_at) }}</span>
          </div>

          <div class="report-body">
            <p class="excerpt" :class="{ gone: report.preview?.exists === false }">
              {{ report.preview?.excerpt || '(ไม่มีข้อความ)' }}
            </p>
            <img v-if="report.preview?.image_url" :src="report.preview.image_url" class="report-img"
                 @click="previewImage = report.preview.image_url" />

            <p v-if="report.note" class="report-note">
              <span class="material-symbols-rounded chip-icon">chat_bubble</span> {{ report.note }}
            </p>

            <p class="report-people">
              เขียนโดย <strong>{{ report.author?.name || 'ไม่ทราบ' }}</strong>
              · รายงานโดย <strong>{{ report.reporter?.name || 'ไม่ทราบ' }}</strong>
              <button v-if="report.author" class="link-btn" @click="loadHistory(report.author.id)">ดูประวัติผู้เขียน</button>
            </p>

            <p v-if="history[report.author?.id]" class="history-line">
              ถูกรายงานรวม {{ history[report.author.id].reports_received }} ครั้ง
              (ค้าง {{ history[report.author.id].reports_open }})
              · ถูกบล็อกโดยผู้ใช้ {{ history[report.author.id].times_blocked }} คน
            </p>
          </div>

          <div class="report-actions" v-if="report.status === 'open'">
            <button class="action-btn action-danger" @click="resolve(report, 'hide')" :disabled="busyId === report.id">
              <span class="material-symbols-rounded" style="font-size:16px;">visibility_off</span> ซ่อนเนื้อหา
            </button>
            <button class="action-btn" @click="resolve(report, 'dismiss')" :disabled="busyId === report.id">
              <span class="material-symbols-rounded" style="font-size:16px;">check</span> ไม่ผิด ปิดเรื่อง
            </button>
            <button v-if="report.preview?.hidden" class="action-btn action-active" @click="resolve(report, 'unhide')" :disabled="busyId === report.id">
              <span class="material-symbols-rounded" style="font-size:16px;">visibility</span> แสดงอีกครั้ง
            </button>
          </div>
        </div>

        <div v-if="reports.length === 0" class="empty-state">
          {{ activeTab === 'open' ? 'ไม่มีรายงานค้างอยู่ 🎉' : 'ยังไม่มีรายงาน' }}
        </div>
      </div>

      <div v-if="meta && meta.last_page > 1" class="pagination">
        <button
          v-for="p in meta.last_page"
          :key="p"
          @click="loadReports(p)"
          class="page-btn"
          :class="{ 'page-active': p === meta.current_page }">
          {{ p }}
        </button>
      </div>
    </template>

    <div v-if="previewImage" class="img-preview-overlay" @click="previewImage = null">
      <img :src="previewImage" class="img-preview" />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../lib/axios';

const reports = ref([]);
const meta = ref(null);
const counts = ref(null);
const loading = ref(false);
const busyId = ref(null);
const previewImage = ref(null);
const activeTab = ref('open');
const history = ref({});

const tabs = [
  { value: 'open', label: 'รอตรวจ' },
  { value: '', label: 'ทั้งหมด' },
];

const TYPE_LABELS = {
  chat_message: 'ข้อความในแชท',
  review: 'รีวิว',
  trip_post: 'โพสต์ในฟีด',
  trip_post_comment: 'คอมเมนต์',
  user: 'ผู้ใช้',
};

function typeLabel(type) {
  return TYPE_LABELS[type] || type;
}

function setTab(value) {
  if (activeTab.value === value) return;
  activeTab.value = value;
  loadReports();
}

async function loadReports(page = 1) {
  loading.value = true;
  try {
    const params = { page, per_page: 20 };
    if (activeTab.value) params.status = activeTab.value;

    const res = await api.get('/admin/moderation/reports', { params });
    reports.value = res.data.data;
    meta.value = res.data.meta;
    counts.value = res.data.meta?.counts ?? counts.value;
  } finally {
    loading.value = false;
  }
}

async function resolve(report, action) {
  busyId.value = report.id;
  try {
    await api.post(`/admin/moderation/reports/${report.id}/resolve`, { action });
    // โหลดใหม่ทั้งหน้า เพราะรายงานใบอื่นที่ชี้เนื้อหาชิ้นเดียวกันถูกปิดไปพร้อมกัน
    await loadReports(meta.value?.current_page || 1);
  } catch {
    alert('ดำเนินการไม่สำเร็จ');
  } finally {
    busyId.value = null;
  }
}

async function loadHistory(userId) {
  if (history.value[userId]) return;
  try {
    const res = await api.get(`/admin/moderation/users/${userId}`);
    history.value = { ...history.value, [userId]: res.data.data };
  } catch {
    /* ไม่ต้องรบกวนแอดมิน ถ้าดึงประวัติไม่ได้ก็แค่ไม่แสดง */
  }
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

onMounted(() => loadReports());
</script>

<style scoped>
@import url('./admin-shared.css');

.review-stats {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.rs-card {
  flex: 1;
  min-width: 110px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 14px;
  text-align: center;
}

.rs-val {
  display: block;
  font-size: 22px;
  font-weight: 800;
  color: var(--color-forest);
}

.rs-lbl {
  font-size: 12px;
  color: var(--color-gray);
}

.rs-red .rs-val {
  color: #c0392b;
}

.report-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.report-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 12px;
  padding: 16px;
}

.report-card.is-resolved {
  opacity: 0.62;
}

.report-head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.type-chip,
.reason-chip,
.hidden-chip,
.done-chip {
  font-size: 12px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
}

.type-chip {
  background: var(--color-sand);
  color: var(--color-forest);
}

.reason-chip {
  background: #fdecea;
  color: #c0392b;
}

.hidden-chip,
.done-chip {
  background: #eef1f4;
  color: #5b6b7a;
}

.chip-icon {
  font-size: 14px;
  vertical-align: text-bottom;
}

.report-date {
  margin-left: auto;
  font-size: 12px;
  color: var(--color-gray);
}

.excerpt {
  font-size: 14px;
  line-height: 1.6;
  color: #2c3e50;
  white-space: pre-wrap;
  word-break: break-word;
}

.excerpt.gone {
  font-style: italic;
  color: var(--color-gray);
}

.report-img {
  margin-top: 10px;
  max-width: 180px;
  border-radius: 8px;
  cursor: pointer;
}

.report-note {
  margin-top: 8px;
  font-size: 13px;
  color: #5b6b7a;
}

.report-people {
  margin-top: 10px;
  font-size: 12px;
  color: var(--color-gray);
}

.history-line {
  margin-top: 4px;
  font-size: 12px;
  color: #c0392b;
}

.link-btn {
  margin-left: 8px;
  border: none;
  background: none;
  padding: 0;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-forest);
  cursor: pointer;
  text-decoration: underline;
}

.report-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 14px;
}
</style>
