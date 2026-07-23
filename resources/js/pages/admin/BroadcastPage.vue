<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">campaign</span> ส่งข้อความถึงลูกค้า</h1>
        <p class="page-subtitle">เขียนแจ้งเตือนเองได้ทันที — ข้อความจะเด้งเป็น push และเข้ากล่องแจ้งเตือนในแอปพร้อมกัน</p>
      </div>
    </div>

    <div class="bc-layout">
      <!-- ── กล่องเขียนข้อความ ── -->
      <section class="compose-card">
        <h2 class="card-heading">เขียนข้อความใหม่</h2>

        <label class="field-label">ส่งถึงใคร</label>
        <div class="audience-tabs">
          <button
            v-for="opt in audienceOptions"
            :key="opt.value"
            class="aud-tab"
            :class="{ active: form.audience === opt.value }"
            @click="selectAudience(opt.value)"
          >
            <span class="material-symbols-rounded">{{ opt.icon }}</span>
            {{ opt.label }}
          </button>
        </div>

        <p v-if="form.audience === 'all'" class="audience-hint">
          ส่งถึงลูกค้าทุกคนที่เปิดรับข่าวสาร — เหมาะกับข่าวทริปใหม่/โปรโมชั่น
          <strong>ไม่ควรใช้กับเรื่องเฉพาะรอบ</strong>
        </p>
        <p v-else class="audience-hint">
          เป็นเรื่องปฏิบัติการ จึงส่งถึงผู้ร่วมทางทุกคนในกลุ่มนี้ แม้จะปิดรับข่าวสารการตลาดไว้ก็ตาม
        </p>

        <template v-if="form.audience === 'schedule'">
          <label class="field-label">เลือกรอบเดินทาง</label>
          <select v-model.number="form.audience_id" class="field-input">
            <option :value="null">— เลือกรอบเดินทาง —</option>
            <option v-for="s in audiences.schedules" :key="s.id" :value="s.id">
              {{ s.is_today ? '🔴 วันนี้ · ' : '' }}{{ s.label }} ({{ s.reachable }} คน)
            </option>
          </select>
        </template>

        <template v-if="form.audience === 'trip'">
          <label class="field-label">เลือกทริป</label>
          <select v-model.number="form.audience_id" class="field-input">
            <option :value="null">— เลือกทริป —</option>
            <option v-for="t in audiences.trips" :key="t.id" :value="t.id">{{ t.label }}</option>
          </select>
        </template>

        <label class="field-label">หัวข้อ</label>
        <input
          v-model="form.title"
          class="field-input"
          maxlength="120"
          placeholder="เช่น พรุ่งนี้ฝนตก เตรียมเสื้อกันฝน"
        />
        <span class="char-count">{{ form.title.length }}/120</span>

        <label class="field-label">ข้อความ</label>
        <textarea
          v-model="form.body"
          class="field-input"
          rows="4"
          maxlength="500"
          placeholder="พิมพ์รายละเอียดที่อยากให้ลูกค้ารู้..."
        ></textarea>
        <span class="char-count">{{ form.body.length }}/500</span>

        <!-- ตัวอย่างหน้าตาจริงบนมือถือ ก่อนกดส่ง -->
        <div class="preview-box">
          <span class="preview-label">ตัวอย่างที่ลูกค้าจะเห็น</span>
          <div class="push-preview">
            <div class="push-icon"><span class="material-symbols-rounded">hiking</span></div>
            <div class="push-text">
              <strong>{{ form.title || 'หัวข้อข้อความ' }}</strong>
              <p>{{ form.body || 'เนื้อหาข้อความจะแสดงตรงนี้' }}</p>
            </div>
          </div>
        </div>

        <div v-if="inQuietHours" class="quiet-warn">
          <span class="material-symbols-rounded">bedtime</span>
          ตอนนี้อยู่ในช่วงเวลางดรบกวน ({{ audiences.quiet_hours?.start_hour }}:00–{{ audiences.quiet_hours?.end_hour }}:00 น.)
          — ข้อความที่ทีมงานกดส่งเองจะไปถึงทันที ไม่ถูกหน่วง โปรดพิจารณาว่าเร่งด่วนพอหรือไม่
        </div>

        <div class="send-row">
          <span class="reach-hint">
            <span class="material-symbols-rounded">group</span>
            จะส่งถึงประมาณ <strong>{{ estimatedReach }}</strong> คน
          </span>
          <button class="btn-primary" :disabled="!canSend || sending" @click="send">
            <span class="material-symbols-rounded">{{ sending ? 'hourglass_top' : 'send' }}</span>
            {{ sending ? 'กำลังส่ง...' : 'ส่งข้อความ' }}
          </button>
        </div>
      </section>

      <!-- ── ประวัติการส่ง ── -->
      <section class="history-card">
        <div class="history-head">
          <h2 class="card-heading">ประวัติการส่ง</h2>
          <div class="src-filter">
            <button
              v-for="f in sourceFilters"
              :key="f.value"
              class="src-chip"
              :class="{ active: source === f.value }"
              @click="setSource(f.value)"
            >{{ f.label }}</button>
          </div>
        </div>

        <div v-if="loadingHistory" class="loading-state"><div class="spinner"></div></div>
        <div v-else-if="!dispatches.length" class="empty-state">
          <span class="material-symbols-rounded">inbox</span>
          <p>ยังไม่มีประวัติการส่ง</p>
        </div>

        <div v-else class="history-list">
          <article v-for="d in dispatches" :key="d.id" class="hist-item">
            <div class="hist-top">
              <span class="event-tag" :class="{ manual: d.is_manual }">{{ d.event_label }}</span>
              <span class="hist-time">{{ formatDateTime(d.created_at) }}</span>
            </div>
            <!-- แถวเก่าก่อนระบบเก็บเนื้อความ จะไม่มี title/body — แสดงชนิดเหตุการณ์แทน -->
            <strong class="hist-title">{{ d.title || d.event_label }}</strong>
            <p v-if="d.body" class="hist-body">{{ d.body }}</p>
            <p v-else class="hist-body muted">(ส่งก่อนระบบเริ่มเก็บเนื้อความ)</p>
            <div class="hist-meta">
              <span v-if="d.audience_label"><span class="material-symbols-rounded">group</span> {{ d.audience_label }}</span>
              <span v-if="d.recipients_count != null">ส่ง {{ d.recipients_count }} คน</span>
              <span v-if="d.read_percent != null" class="read-rate">
                เปิดอ่าน {{ d.read_count }} ({{ d.read_percent }}%)
              </span>
              <span v-if="d.sent_by_name" class="sender">โดย {{ d.sent_by_name }}</span>
            </div>
            <div v-if="d.read_percent != null" class="read-bar">
              <div class="read-fill" :style="{ width: d.read_percent + '%' }"></div>
            </div>
          </article>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';
import './admin-shared.css';

const toast = useToast();
const swal = useSwal();

const audienceOptions = [
  { value: 'schedule', label: 'รอบเดินทาง', icon: 'event' },
  { value: 'trip', label: 'ลูกค้าของทริป', icon: 'hiking' },
  { value: 'all', label: 'ลูกค้าทั้งหมด', icon: 'public' },
];

const sourceFilters = [
  { value: '', label: 'ทั้งหมด' },
  { value: 'manual', label: 'ทีมงานส่งเอง' },
  { value: 'auto', label: 'ระบบส่งอัตโนมัติ' },
];

const form = reactive({ audience: 'schedule', audience_id: null, title: '', body: '' });
const audiences = ref({ schedules: [], trips: [], all_reachable: 0, quiet_hours: {} });
const dispatches = ref([]);
const loadingHistory = ref(false);
const sending = ref(false);
const source = ref('');

const estimatedReach = computed(() => {
  if (form.audience === 'all') return audiences.value.all_reachable ?? 0;
  if (form.audience === 'schedule') {
    return audiences.value.schedules.find((s) => s.id === form.audience_id)?.reachable ?? 0;
  }
  // จำนวนของทั้งทริปต้องรวมหลายรอบ ฝั่งเซิร์ฟเวอร์คำนวณตอนส่งจริง
  return form.audience_id ? '—' : 0;
});

const canSend = computed(() => {
  if (!form.title.trim() || !form.body.trim()) return false;
  if (form.audience !== 'all' && !form.audience_id) return false;
  return true;
});

const inQuietHours = computed(() => {
  const q = audiences.value.quiet_hours;
  if (!q?.enabled) return false;
  const hour = new Date().getHours();
  return hour >= q.start_hour || hour < q.end_hour;
});

function formatDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('th-TH', {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  });
}

function selectAudience(value) {
  form.audience = value;
  form.audience_id = null;
}

function setSource(value) {
  if (source.value === value) return;
  source.value = value;
  loadHistory();
}

async function loadAudiences() {
  try {
    const res = await api.get('/admin/broadcasts/audiences');
    audiences.value = res.data.data;
  } catch {
    toast.error('โหลดรายชื่อผู้รับไม่สำเร็จ');
  }
}

async function loadHistory() {
  loadingHistory.value = true;
  try {
    const res = await api.get('/admin/broadcasts', {
      params: source.value ? { source: source.value } : {},
    });
    dispatches.value = res.data.data.dispatches || [];
  } catch {
    toast.error('โหลดประวัติการส่งไม่สำเร็จ');
  } finally {
    loadingHistory.value = false;
  }
}

async function send() {
  const audienceLabel = audienceOptions.find((o) => o.value === form.audience)?.label;

  // ส่ง push ย้อนกลับไม่ได้ จึงต้องให้ยืนยันอีกชั้นเสมอ
  const ok = await swal.confirm({
    title: 'ส่งข้อความนี้เลยไหม?',
    html: `<div style="text-align:right"><b>${form.title}</b><br><span style="color:#6b7280">${form.body}</span>`
      + `<br><br>ถึง: ${audienceLabel} (${estimatedReach.value} คน)<br>`
      + '<span style="color:#b91c1c">ส่งแล้วเรียกคืนไม่ได้</span></div>',
    icon: 'question',
    confirmText: 'ส่งเลย',
  });
  if (!ok.isConfirmed) return;

  sending.value = true;
  try {
    const res = await api.post('/admin/broadcasts', {
      title: form.title.trim(),
      body: form.body.trim(),
      audience: form.audience,
      audience_id: form.audience_id,
    });
    toast.success(res.data.message || 'ส่งข้อความแล้ว');
    form.title = '';
    form.body = '';
    await loadHistory();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ส่งข้อความไม่สำเร็จ');
  } finally {
    sending.value = false;
  }
}

onMounted(() => {
  loadAudiences();
  loadHistory();
});
</script>

<style scoped>
.bc-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; align-items: start; }
@media (max-width: 1100px) { .bc-layout { grid-template-columns: 1fr; } }

.compose-card, .history-card {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px 24px;
}
.card-heading { margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #111827; }

.field-label {
  display: block; font-size: 12px; font-weight: 700; color: #6b7280;
  text-transform: uppercase; letter-spacing: 0.4px; margin: 16px 0 6px;
}
.field-input {
  width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 9px;
  font-size: 14px; color: #1f2937; background: #fff; font-family: inherit;
}
.field-input:focus { outline: none; border-color: var(--color-accent); }
textarea.field-input { resize: vertical; line-height: 1.6; }
.char-count { display: block; text-align: right; font-size: 11px; color: #9ca3af; margin-top: 3px; }

.audience-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.aud-tab {
  display: inline-flex; align-items: center; gap: 6px;
  border: 1px solid #e5e7eb; background: #fff; color: #4b5563;
  border-radius: 9px; padding: 9px 15px; font-size: 13.5px; font-weight: 600; cursor: pointer;
}
.aud-tab .material-symbols-rounded { font-size: 18px !important; }
.aud-tab.active { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.aud-tab.active .material-symbols-rounded { color: #fff !important; }
.audience-hint { margin: 10px 0 0; font-size: 12.5px; color: #6b7280; line-height: 1.6; }

.preview-box { margin-top: 20px; }
.preview-label { font-size: 11.5px; font-weight: 700; color: #9ca3af; text-transform: uppercase; }
.push-preview {
  display: flex; gap: 12px; margin-top: 8px;
  background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 13px 15px;
}
.push-icon {
  width: 38px; height: 38px; border-radius: 9px; background: var(--color-accent);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.push-icon .material-symbols-rounded { color: #fff !important; font-size: 21px !important; }
.push-text { min-width: 0; }
.push-text strong { font-size: 14px; color: #111827; display: block; }
.push-text p { margin: 3px 0 0; font-size: 13px; color: #4b5563; line-height: 1.5; word-break: break-word; }

.quiet-warn {
  display: flex; align-items: flex-start; gap: 9px; margin-top: 16px;
  background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
  padding: 11px 14px; font-size: 12.5px; color: #92400e; line-height: 1.6;
}
.quiet-warn .material-symbols-rounded { font-size: 19px !important; color: #b45309; flex-shrink: 0; }

.send-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; margin-top: 20px; padding-top: 18px; border-top: 1px solid #f3f4f6; flex-wrap: wrap;
}
.reach-hint { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #6b7280; }
.reach-hint .material-symbols-rounded { font-size: 18px !important; }
.reach-hint strong { color: #1f2937; }

.history-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.src-filter { display: flex; gap: 6px; }
.src-chip {
  border: 1px solid #e5e7eb; background: #fff; color: #6b7280;
  border-radius: 999px; padding: 5px 13px; font-size: 12px; font-weight: 600; cursor: pointer;
}
.src-chip.active { background: #1f2937; border-color: #1f2937; color: #fff; }

.empty-state { padding: 50px 16px; text-align: center; color: #9ca3af; }
.empty-state .material-symbols-rounded { font-size: 40px !important; color: #d1d5db; }
.empty-state p { margin: 8px 0 0; font-size: 14px; }

.history-list { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; max-height: 640px; overflow-y: auto; }
.hist-item { border: 1px solid #f3f4f6; border-radius: 11px; padding: 13px 15px; }
.hist-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.event-tag {
  font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px;
  background: #EEEEEE; color: #4b5563;
}
.event-tag.manual { background: #dbeafe; color: #1d4ed8; }
.hist-time { font-size: 11.5px; color: #9ca3af; }
.hist-title { display: block; margin-top: 8px; font-size: 14px; color: #111827; }
.hist-body { margin: 3px 0 0; font-size: 13px; color: #6b7280; line-height: 1.55; }
.hist-body.muted { color: #cbd5e1; font-style: italic; }
.hist-meta {
  display: flex; gap: 12px; flex-wrap: wrap; margin-top: 9px;
  font-size: 11.5px; color: #9ca3af;
}
.hist-meta .material-symbols-rounded { font-size: 14px !important; vertical-align: -2px; }
.read-rate { color: #15803d; font-weight: 700; }
.sender { margin-left: auto; }
.read-bar { margin-top: 7px; height: 4px; border-radius: 999px; background: #f3f4f6; overflow: hidden; }
.read-fill { height: 100%; background: #16a34a; }
</style>
