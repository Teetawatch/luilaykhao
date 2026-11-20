<template>
  <div class="ct-panel">
    <div class="ct-head">
      <div>
        <h3 class="ct-title"><span class="material-symbols-rounded">translate</span> คำแปลภาษาอังกฤษ</h3>
        <p class="ct-sub">
          AI แปลให้อัตโนมัติทุกครั้งที่บันทึก และขึ้นหน้าเว็บ /en ทันที — ช่องที่ยังแปลไม่เสร็จ ลูกค้าเห็นภาษาไทยไปก่อน
          ช่องที่แก้เองจะล็อกไว้ AI ไม่เขียนทับ
        </p>
      </div>
      <div class="ct-actions">
        <button type="button" class="ct-btn ghost" :disabled="loading" @click="load()">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span> รีเฟรช
        </button>
        <button
          v-if="data?.ai_ready"
          type="button"
          class="ct-btn"
          :disabled="queueing"
          @click="refresh()"
        >
          <span class="material-symbols-rounded">auto_awesome</span> แปลช่องที่ยังขาด
        </button>
      </div>
    </div>

    <div v-if="error" class="ct-error"><span class="material-symbols-rounded">error</span> {{ error }}</div>
    <div v-if="notice" class="ct-notice"><span class="material-symbols-rounded">schedule</span> {{ notice }}</div>
    <div v-if="data && !data.ai_ready" class="ct-notice warn">
      <span class="material-symbols-rounded">key_off</span>
      ยังไม่ได้ตั้งค่า ANTHROPIC_API_KEY บนเซิร์ฟเวอร์ — ระบบแปลอัตโนมัติไม่ได้ แต่แก้คำแปลเองได้ทีละช่อง
    </div>

    <template v-if="data">
      <div class="ct-stats">
        <button type="button" :class="['ct-chip', { on: filter === 'all' }]" @click="filter = 'all'">
          ทั้งหมด <b>{{ counts.total }}</b>
        </button>
        <button type="button" :class="['ct-chip', { on: filter === 'review' }]" @click="filter = 'review'">
          ต้องตรวจ <b>{{ counts.stale + counts.pending }}</b>
        </button>
        <span class="ct-pill ok">แปลแล้ว {{ counts.translated }}</span>
        <span class="ct-pill manual">แก้เอง {{ counts.manual }}</span>
        <span v-if="counts.stale" class="ct-pill stale">ต้นฉบับเปลี่ยน {{ counts.stale }}</span>
        <span v-if="counts.pending" class="ct-pill pending">รอแปล {{ counts.pending }}</span>
      </div>

      <p v-if="!groups.length" class="ct-empty">
        {{ filter === 'review' ? 'ไม่มีช่องที่ต้องตรวจ — ทุกช่องแปลแล้ว' : 'ยังไม่มีข้อความภาษาไทยให้แปล' }}
      </p>

      <section v-for="group in groups" :key="group.field" class="ct-group">
        <h4 class="ct-group-title">{{ fieldLabel(group.field) }}</h4>

        <div v-for="item in group.items" :key="item.path" :class="['ct-row', item.status]">
          <div class="ct-meta">
            <span class="ct-path">{{ pathLabel(item.path) }}</span>
            <span :class="['ct-badge', item.status]">{{ statusLabel(item.status) }}</span>
          </div>
          <div class="ct-cols">
            <div class="ct-thai">
              <span class="ct-lang">TH</span>
              <p>{{ item.thai }}</p>
              <p v-if="item.status === 'stale'" class="ct-prev">
                <span class="material-symbols-rounded">history</span>
                ตอนที่แก้คำแปล ต้นฉบับเขียนว่า: “{{ item.previous_thai }}”
              </p>
            </div>
            <div class="ct-en">
              <span class="ct-lang">EN</span>
              <textarea
                v-model="drafts[item.path]"
                :rows="rowsFor(item)"
                :placeholder="item.status === 'pending' ? 'ยังไม่มีคำแปล — ลูกค้าเห็นภาษาไทย พิมพ์คำแปลเองได้' : ''"
                lang="en"
              ></textarea>
              <div class="ct-row-actions">
                <button
                  v-if="isDirty(item)"
                  type="button"
                  class="ct-btn small"
                  :disabled="saving[item.path]"
                  @click="save(item)"
                >
                  <span class="material-symbols-rounded">lock</span> บันทึกและล็อก
                </button>
                <button
                  v-if="item.status === 'stale'"
                  type="button"
                  class="ct-btn small ghost"
                  :disabled="saving[item.path]"
                  @click="save(item, true)"
                >
                  <span class="material-symbols-rounded">check</span> คำแปลนี้ยังถูก
                </button>
                <button
                  v-if="(item.status === 'manual' || item.status === 'stale') && data.ai_ready"
                  type="button"
                  class="ct-btn small ghost"
                  :disabled="saving[item.path]"
                  @click="unlock(item)"
                >
                  <span class="material-symbols-rounded">lock_open</span> ให้ AI แปลใหม่
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>

    <p v-else-if="loading" class="ct-empty">กำลังโหลดคำแปล…</p>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import api from '../lib/axios';

const props = defineProps({
  // ชนิดเนื้อหาตาม ContentTranslationService::TYPES เช่น trip, category
  type: { type: String, required: true },
  id: { type: Number, required: true },
});

const data = ref(null);
const loading = ref(false);
const queueing = ref(false);
const error = ref('');
const notice = ref('');
const filter = ref('all');
const drafts = reactive({});
const saving = reactive({});
let pollTimer = null;
let pollsLeft = 0;

const FIELD_LABELS = {
  title: 'ชื่อทริป',
  location: 'สถานที่',
  description: 'รายละเอียดทริป',
  departure_point: 'จุดนัดพบ',
  checkin_bring: 'สิ่งที่ต้องพกวันเดินทาง',
  highlights: 'จุดเด่น',
  inclusions: 'รวมในราคา',
  exclusions: 'ไม่รวมในราคา',
  must_know: 'ข้อควรรู้และรายการเสริม',
  itinerary: 'แผนการเดินทาง',
  preparations: 'สิ่งที่ต้องเตรียม',
  faqs: 'คำถามที่พบบ่อย',
  rental_items: 'อุปกรณ์ให้เช่า',
  document_requirements: 'เอกสารที่ต้องแนบ',
  meal_plan: 'มื้ออาหาร',
  name: 'ชื่อ',
  display_title: 'หัวข้อที่แสดง',
  subtitle: 'คำโปรย',
  cta_text: 'ข้อความปุ่ม',
  alt_text: 'คำบรรยายรูป',
  body: 'ข้อความ',
  label: 'ชื่อ',
  note: 'หมายเหตุ',
  notes: 'หมายเหตุ',
  region_label: 'ภูมิภาค',
  pickup_location: 'จุดรับ',
  badge_label: 'ป้าย',
  tagline: 'คำโปรย',
  meeting_point: 'จุดนัดพบ',
  baggage_allowance: 'น้ำหนักกระเป๋า',
  force_majeure_reason: 'เหตุผลที่เลื่อน',
};

const PART_LABELS = {
  question: 'คำถาม', answer: 'คำตอบ', title: 'หัวข้อ', desc: 'คำอธิบาย', name: 'ชื่อ',
  description: 'รายละเอียด', items: 'รายการ', stops: 'จุดแวะ', detail: 'รายละเอียด', sector: 'ช่วง',
  remarks: 'หมายเหตุ', note: 'หมายเหตุ', label: 'ชื่อเอกสาร', guide: 'วิธีแนบ', tips: 'คำแนะนำ',
  parts: 'ของในชุด', days: 'วัน', breakfast: 'มื้อเช้า', lunch: 'มื้อกลางวัน', dinner: 'มื้อเย็น',
};

const counts = computed(() => ({
  total: 0, translated: 0, manual: 0, stale: 0, pending: 0, ...(data.value?.counts || {}),
}));

const groups = computed(() => {
  const items = (data.value?.items || []).filter(
    (item) => filter.value === 'all' || item.status === 'stale' || item.status === 'pending',
  );
  const byField = new Map();
  for (const item of items) {
    if (!byField.has(item.field)) byField.set(item.field, []);
    byField.get(item.field).push(item);
  }

  return [...byField].map(([field, list]) => ({ field, items: list }));
});

function fieldLabel(field) {
  return FIELD_LABELS[field] || field;
}

/** "faqs.2.answer" → "ข้อ 3 · คำตอบ" */
function pathLabel(path) {
  const parts = path.split('.').slice(1);
  if (!parts.length) return fieldLabel(path);

  return parts
    .map((part) => (/^\d+$/.test(part) ? `ข้อ ${Number(part) + 1}` : PART_LABELS[part] || part))
    .join(' · ');
}

function statusLabel(status) {
  return {
    translated: 'AI แปล',
    manual: 'แก้เอง · ล็อก',
    stale: 'ต้นฉบับเปลี่ยน',
    pending: 'รอแปล',
  }[status] || status;
}

function rowsFor(item) {
  const length = Math.max(item.thai.length, (drafts[item.path] || '').length);

  return Math.min(12, Math.max(1, Math.ceil(length / 60)));
}

function isDirty(item) {
  return (drafts[item.path] || '').trim() !== '' && (drafts[item.path] || '').trim() !== (item.text || '').trim();
}

function apply(payload) {
  data.value = payload;
  for (const item of payload.items || []) {
    drafts[item.path] = item.text || '';
  }
}

async function load(silent = false) {
  if (!silent) loading.value = true;
  error.value = '';
  try {
    const res = await api.get(`/admin/translations/${props.type}/${props.id}`);
    apply(res.data.data);
    if (pollsLeft > 0 && !res.data.data.counts?.pending) {
      pollsLeft = 0;
      notice.value = '';
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'โหลดคำแปลไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

/** งานแปลอยู่ในคิว — ถามซ้ำสักพักจนกว่าช่องที่รอแปลจะหมด */
function poll(times = 8) {
  pollsLeft = times;
  clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    if (pollsLeft <= 0) {
      clearInterval(pollTimer);
      notice.value = '';

      return;
    }
    pollsLeft -= 1;
    await load(true);
  }, 5000);
}

async function refresh() {
  queueing.value = true;
  error.value = '';
  try {
    const res = await api.post(`/admin/translations/${props.type}/${props.id}/refresh`);
    notice.value = res.data.message || 'ส่งเข้าคิวแปลแล้ว';
    poll();
  } catch (e) {
    error.value = e.response?.data?.message || 'สั่งแปลไม่สำเร็จ';
  } finally {
    queueing.value = false;
  }
}

async function save(item, keepCurrent = false) {
  const text = keepCurrent ? item.text : drafts[item.path];
  saving[item.path] = true;
  error.value = '';
  try {
    const res = await api.put(`/admin/translations/${props.type}/${props.id}`, { path: item.path, text });
    apply(res.data.data);
  } catch (e) {
    error.value = e.response?.data?.message || 'บันทึกคำแปลไม่สำเร็จ';
  } finally {
    saving[item.path] = false;
  }
}

async function unlock(item) {
  if (!confirm('ปลดล็อกช่องนี้แล้วให้ AI แปลใหม่ตามต้นฉบับตอนนี้? คำแปลที่แก้เองจะหายไป')) return;
  saving[item.path] = true;
  error.value = '';
  try {
    const res = await api.delete(`/admin/translations/${props.type}/${props.id}`, { data: { path: item.path } });
    apply(res.data.data);
    notice.value = 'ส่งเข้าคิวแปลใหม่แล้ว';
    poll();
  } catch (e) {
    error.value = e.response?.data?.message || 'ปลดล็อกไม่สำเร็จ';
  } finally {
    saving[item.path] = false;
  }
}

watch(() => [props.type, props.id], () => load());
onMounted(() => {
  load().then(() => {
    // เพิ่งบันทึกทริปมา งานแปลยังอยู่ในคิว — รอดูผลให้เอง
    if (data.value?.ai_ready && counts.value.pending) {
      notice.value = 'มีช่องที่กำลังรอแปล — หน้านี้จะอัปเดตเอง';
      poll();
    }
  });
});
onBeforeUnmount(() => clearInterval(pollTimer));
</script>

<style scoped>
.ct-panel { display: flex; flex-direction: column; gap: 16px; }
.ct-head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; align-items: flex-start; }
.ct-title { display: flex; align-items: center; gap: 8px; font-size: 20px; font-weight: 700; color: #111827; margin: 0; }
.ct-title .material-symbols-rounded { color: var(--color-accent); }
.ct-sub { margin: 4px 0 0; font-size: 14px; color: #6b7280; max-width: 680px; line-height: 1.5; }
.ct-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.ct-btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px;
  border: 1px solid var(--color-accent); background: var(--color-accent); color: #fff;
  font-size: 15px; font-weight: 600; cursor: pointer;
}
.ct-btn .material-symbols-rounded { font-size: 18px; }
.ct-btn.ghost { background: #fff; color: #374151; border-color: #e5e7eb; }
.ct-btn.small { padding: 5px 10px; font-size: 14px; }
.ct-btn:disabled { opacity: .55; cursor: default; }
.ct-error, .ct-notice {
  display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 10px; font-size: 14px;
}
.ct-error { background: #fef2f2; color: #b91c1c; }
.ct-notice { background: #eff6ff; color: #1d4ed8; }
.ct-notice.warn { background: #fffbeb; color: #92400e; }
.ct-error .material-symbols-rounded, .ct-notice .material-symbols-rounded { font-size: 18px; }
.ct-stats { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ct-chip {
  padding: 6px 12px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff;
  font-size: 14px; color: #374151; cursor: pointer;
}
.ct-chip.on { border-color: var(--color-accent); color: var(--color-accent); background: #f6faf7; }
.ct-pill { padding: 4px 10px; border-radius: 999px; font-size: 13px; }
.ct-pill.ok { background: #ecfdf5; color: #047857; }
.ct-pill.manual { background: #eef2ff; color: #4338ca; }
.ct-pill.stale { background: #fffbeb; color: #b45309; }
.ct-pill.pending { background: #f3f4f6; color: #4b5563; }
.ct-empty { color: #6b7280; font-size: 14px; padding: 24px 0; text-align: center; }
.ct-group { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; overflow: hidden; }
.ct-group-title { margin: 0; padding: 12px 16px; font-size: 16px; font-weight: 700; color: #111827; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
.ct-row { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; }
.ct-row:last-child { border-bottom: 0; }
.ct-row.stale { background: #fffdf5; }
.ct-meta { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 8px; align-items: center; }
.ct-path { font-size: 13px; color: #6b7280; }
.ct-badge { font-size: 12px; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.ct-badge.translated { background: #ecfdf5; color: #047857; }
.ct-badge.manual { background: #eef2ff; color: #4338ca; }
.ct-badge.stale { background: #fef3c7; color: #b45309; }
.ct-badge.pending { background: #f3f4f6; color: #4b5563; }
.ct-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.ct-thai, .ct-en { position: relative; }
.ct-lang { position: absolute; top: 6px; right: 8px; font-size: 11px; font-weight: 700; color: #9ca3af; letter-spacing: .05em; }
.ct-thai p { margin: 0; padding: 10px 34px 10px 12px; background: #f9fafb; border-radius: 10px; font-size: 15px; line-height: 1.55; white-space: pre-wrap; color: #374151; }
.ct-thai p.ct-prev { margin-top: 6px; background: #fef3c7; color: #92400e; font-size: 13px; display: flex; gap: 6px; }
.ct-prev .material-symbols-rounded { font-size: 16px; }
.ct-en textarea {
  width: 100%; padding: 10px 34px 10px 12px; border: 1px solid #e5e7eb; border-radius: 10px;
  font-size: 15px; line-height: 1.55; resize: vertical; font-family: inherit; color: #111827; background: #fff;
}
.ct-en textarea:focus { outline: none; border-color: var(--color-accent); }
.ct-row-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
@media (max-width: 720px) {
  .ct-cols { grid-template-columns: 1fr; }
}
</style>
