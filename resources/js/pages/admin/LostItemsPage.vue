<template>
  <div class="lost-page">
    <div class="lost-head">
      <div>
        <h2><i class="fas fa-box-open"></i> ของหาย / ลืมของ</h2>
        <p class="sub">ของที่ลูกทริปลืมไว้ทุกทริป — ลูกทริปกด "ของฉัน" ในแอป แล้วทีมงานส่งคืน (อยู่ต่อหลังห้องแชทถูกลบ)</p>
      </div>
      <button class="primary-btn" @click="showPost = !showPost">
        <i class="fas" :class="showPost ? 'fa-times' : 'fa-camera'"></i>
        {{ showPost ? 'ปิด' : 'โพสต์ของที่เจอ' }}
      </button>
    </div>

    <!-- โพสต์ของที่เจอ (เช่น ของที่ส่งกลับมาถึงออฟฟิศ) -->
    <form v-if="showPost" class="panel" @submit.prevent="post">
      <div class="field-row">
        <div class="field">
          <label>ทริป (รอบที่เดินทางช่วง 30 วันก่อน – 7 วันข้างหน้า)</label>
          <select v-model="postForm.schedule_id" class="input">
            <option :value="null" disabled>— เลือกรอบเดินทาง —</option>
            <option v-for="s in recentSchedules" :key="s.id" :value="s.id">
              {{ formatShort(s.departure_date) }} · {{ s.trip?.title || 'ทริป' }}
            </option>
          </select>
        </div>
        <div class="field">
          <label>รูปของ (ไม่บังคับ)</label>
          <input ref="photoInput" type="file" accept="image/*" class="input" @change="onPhoto" />
        </div>
      </div>
      <div class="field">
        <label>เป็นของอะไร เจอที่ไหน</label>
        <input v-model="postForm.description" class="input" maxlength="300" placeholder="เช่น หมวกสีดำ เจอที่เบาะหลังรถตู้" />
      </div>
      <p class="hint">ทุกคนที่ไปทริปนั้นได้แจ้งเตือน ถ้าห้องแชทยังไม่ถูกลบจะขึ้นการ์ดในแชทด้วย</p>
      <button type="submit" class="primary-btn" :disabled="busy || !postForm.schedule_id || !postForm.description.trim()">
        <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-bullhorn'"></i> แจ้งทุกคนในทริป
      </button>
    </form>

    <div class="tabs">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="tab"
        :class="{ active: status === t.key }"
        @click="setStatus(t.key)"
      >
        {{ t.label }}
        <span v-if="t.key && counts[t.key] != null" class="count">{{ counts[t.key] }}</span>
      </button>
    </div>

    <div v-if="loading" class="empty-hint">กำลังโหลด...</div>
    <div v-else-if="!items.length" class="empty-hint">ไม่มีรายการ</div>

    <div v-else class="grid">
      <div v-for="item in items" :key="item.id" class="card">
        <div class="card-top">
          <a v-if="item.photo_url" :href="item.photo_url" target="_blank" rel="noopener" class="photo">
            <img :src="item.photo_url" alt="" loading="lazy" />
          </a>
          <div v-else class="photo empty"><i class="fas fa-box-open"></i></div>
          <div class="card-main">
            <span class="trip">{{ formatShort(item.departure_date) }} · {{ item.trip_title || 'ทริป' }}</span>
            <b class="desc">{{ item.description }}</b>
            <span class="badge" :class="item.status">{{ statusLabel(item.status) }}</span>
            <span class="meta">โพสต์โดย {{ item.posted_by_name || '-' }} · {{ formatDateTime(item.created_at) }}</span>
          </div>
        </div>

        <div v-if="item.status !== 'open'" class="owner">
          <div><i class="fas fa-user"></i> {{ item.claimant_name || '-' }}
            <a v-if="item.claimant_phone" :href="`tel:${item.claimant_phone}`">{{ item.claimant_phone }}</a>
          </div>
          <div v-if="item.claim_note"><i class="fas fa-truck"></i> รับคืน: {{ item.claim_note }}</div>
          <div v-if="item.returned_note"><i class="fas fa-check"></i> ส่งคืน: {{ item.returned_note }}</div>
        </div>

        <div v-if="returningId === item.id" class="return-form">
          <input v-model="returnNote" class="input" maxlength="300" placeholder="หมายเหตุ เช่น เลขพัสดุ EMS TH123456789" />
          <div class="actions">
            <button class="primary-btn sm" :disabled="busy" @click="markReturned(item, true)">บันทึกว่าส่งคืนแล้ว</button>
            <button class="ghost-btn sm" @click="returningId = null">ยกเลิก</button>
          </div>
        </div>
        <div v-else class="actions">
          <button v-if="item.status === 'claimed'" class="primary-btn sm" @click="startReturn(item)">
            <i class="fas fa-truck"></i> ส่งคืนแล้ว
          </button>
          <button v-if="item.status === 'claimed'" class="ghost-btn sm" :disabled="busy" @click="unclaim(item)">ยกเลิกผู้แจ้ง</button>
          <button v-if="item.status === 'returned'" class="ghost-btn sm" :disabled="busy" @click="markReturned(item, false)">ยกเลิกสถานะคืนแล้ว</button>
          <button class="ghost-btn sm danger" :disabled="busy" @click="remove(item)"><i class="fas fa-trash"></i> ลบ</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// ของหาย / ลืมของ (หลังบ้าน) — ดู LostItemService
// รายการรวมทุกทริปมาจาก GET /admin/lost-items ส่วนการกระทำใช้ endpoint
// ชุดเดียวกับแอป (แอดมินจัดการได้ทุกรอบ)

const toast = useToast();
const swal = useSwal();

const tabs = [
  { key: 'open', label: 'ยังไม่มีเจ้าของ' },
  { key: 'claimed', label: 'มีเจ้าของ รอส่งคืน' },
  { key: 'returned', label: 'คืนแล้ว' },
  { key: null, label: 'ทั้งหมด' },
];

const status = ref('claimed');
const items = ref([]);
const counts = ref({});
const loading = ref(false);
const busy = ref(false);
const returningId = ref(null);
const returnNote = ref('');

const showPost = ref(false);
const recentSchedules = ref([]);
const postForm = ref({ schedule_id: null, description: '' });
const photoFile = ref(null);
const photoInput = ref(null);

const statusLabel = (s) => ({ open: 'ยังไม่มีเจ้าของ', claimed: 'มีเจ้าของ รอส่งคืน', returned: 'คืนแล้ว' })[s] || s;

function formatShort(d) {
  if (!d) return '-';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });
}
function formatDateTime(d) {
  if (!d) return '';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
const isoDate = (date) => date.toISOString().slice(0, 10);

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/lost-items', { params: status.value ? { status: status.value } : {} });
    items.value = res.data.data?.items || [];
    counts.value = res.data.data?.counts || {};
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายการไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

function setStatus(key) {
  status.value = key;
  returningId.value = null;
  load();
}

async function loadRecentSchedules() {
  const now = new Date();
  const from = new Date(now.getTime() - 30 * 86400000);
  const to = new Date(now.getTime() + 7 * 86400000);
  try {
    const res = await api.get('/admin/schedules', {
      params: { per_page: 100, from: isoDate(from), to: isoDate(to), order: 'desc' },
    });
    recentSchedules.value = res.data.data || [];
  } catch {
    toast.error('โหลดรายชื่อรอบเดินทางไม่สำเร็จ');
  }
}

function onPhoto(event) {
  photoFile.value = event.target.files?.[0] || null;
}

async function post() {
  if (busy.value) return;
  busy.value = true;
  try {
    const form = new FormData();
    form.append('description', postForm.value.description.trim());
    if (photoFile.value) form.append('photo', photoFile.value);
    await api.post(`/schedules/${postForm.value.schedule_id}/lost-items`, form);
    toast.success('แจ้งทุกคนในทริปแล้ว');
    postForm.value = { schedule_id: postForm.value.schedule_id, description: '' };
    photoFile.value = null;
    if (photoInput.value) photoInput.value.value = '';
    showPost.value = false;
    status.value = 'open';
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'โพสต์ไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

function startReturn(item) {
  returningId.value = item.id;
  returnNote.value = '';
}

async function act(request, done) {
  if (busy.value) return;
  busy.value = true;
  try {
    await request();
    if (done) toast.success(done);
    returningId.value = null;
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

function markReturned(item, returned) {
  return act(
    () => api.post(`/lost-items/${item.id}/returned`, { returned, note: returned ? returnNote.value.trim() || null : null }),
    returned ? 'บันทึกแล้ว แจ้งเจ้าของให้แล้ว' : 'ยกเลิกแล้ว',
  );
}

async function unclaim(item) {
  const res = await swal.confirm({ title: 'ยกเลิกผู้แจ้งรายนี้?', text: 'ของจะกลับไปเป็น "ยังไม่มีเจ้าของ"', icon: 'warning', confirmText: 'ยกเลิกผู้แจ้ง' });
  if (!res.isConfirmed) return;
  return act(() => api.delete(`/lost-items/${item.id}/claim`), 'ยกเลิกแล้ว');
}

async function remove(item) {
  const res = await swal.confirm({ title: 'ลบโพสต์นี้?', text: item.description, icon: 'warning', confirmText: 'ลบ' });
  if (!res.isConfirmed) return;
  return act(() => api.delete(`/lost-items/${item.id}`), 'ลบแล้ว');
}

onMounted(() => {
  load();
  loadRecentSchedules();
});
</script>

<style scoped>
.lost-page { display: flex; flex-direction: column; gap: 16px; }
.lost-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.lost-head h2 { margin: 0; font-size: 20px; font-weight: 800; color: #1f2937; }
.lost-head h2 i { color: #2D7A4F; margin-right: 6px; }
.sub { margin: 4px 0 0; font-size: 13px; color: #6b7280; }

.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.field-row { display: flex; gap: 12px; flex-wrap: wrap; }
.field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 220px; }
.field label { font-size: 12px; font-weight: 700; color: #6b7280; }
.input { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 9px 12px; font-size: 14px; outline: none; font-family: inherit; background: #fff; }
.input:focus { border-color: #2D7A4F; }
.hint { margin: 0; font-size: 12.5px; color: #6b7280; }

.primary-btn {
  border: none; background: #2D7A4F; color: #fff; border-radius: 10px; padding: 10px 16px;
  font-size: 14px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; align-self: flex-start;
}
.primary-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.ghost-btn { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.ghost-btn.danger:hover { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
.primary-btn.sm, .ghost-btn.sm { padding: 7px 12px; font-size: 13px; }

.tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.tab { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.tab.active { background: #ecfdf5; border-color: #2D7A4F; color: #2D7A4F; }
.count { background: #f3f4f6; border-radius: 999px; padding: 0 7px; font-size: 12px; }
.tab.active .count { background: #d1fae5; }

.empty-hint { padding: 32px 16px; color: #9ca3af; font-size: 13px; text-align: center; background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
.card { background: #fff; border: 1px solid #e9edf0; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.card-top { display: flex; gap: 12px; }
.photo { width: 88px; height: 88px; border-radius: 10px; overflow: hidden; flex-shrink: 0; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 26px; }
.photo img { width: 100%; height: 100%; object-fit: cover; }
.card-main { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.trip { font-size: 12px; font-weight: 700; color: #6b7280; }
.desc { font-size: 14.5px; color: #111827; }
.meta { font-size: 11.5px; color: #9ca3af; }
.badge { align-self: flex-start; font-size: 11.5px; font-weight: 800; border-radius: 999px; padding: 2px 9px; }
.badge.open { background: #fffbeb; color: #b45309; }
.badge.claimed { background: #eff6ff; color: #2563eb; }
.badge.returned { background: #ecfdf5; color: #2D7A4F; }
.owner { background: #f9fafb; border-radius: 10px; padding: 10px; font-size: 13px; color: #374151; display: flex; flex-direction: column; gap: 4px; }
.owner i { color: #9ca3af; width: 16px; }
.owner a { color: #2563eb; font-weight: 700; margin-left: 4px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.return-form { display: flex; flex-direction: column; gap: 8px; }
</style>
