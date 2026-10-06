<template>
  <div class="ch-page">
    <div class="ch-head">
      <div>
        <h2><i class="fas fa-people-group"></i> คำขอเหมาทริป</h2>
        <p class="sub">ลูกค้าขอจัดทริปส่วนตัว → เสนอราคา → ลูกค้าตอบรับในแอป → เปิดรอบเหมาและลงการจองให้กลุ่ม</p>
      </div>
      <input v-model="q" class="input search" placeholder="ค้นหาเลขคำขอ / ชื่อ / เบอร์ / ปลายทาง" @input="onSearch" />
    </div>

    <div class="tabs">
      <button v-for="t in tabs" :key="t.key ?? 'all'" class="tab" :class="{ active: status === t.key }" @click="setStatus(t.key)">
        {{ t.label }}
        <span v-if="t.key && counts[t.key]" class="count" :class="{ alert: t.key === 'new' || t.key === 'accepted' }">{{ counts[t.key] }}</span>
      </button>
    </div>

    <div v-if="loading" class="empty-hint">กำลังโหลด...</div>
    <div v-else-if="!items.length" class="empty-hint">ไม่มีรายการ</div>

    <div v-else class="list">
      <button v-for="c in items" :key="c.id" class="row" :class="{ selected: detail?.id === c.id }" @click="open(c)">
        <div class="row-main">
          <span class="ref">{{ c.ref }}</span>
          <span class="badge" :class="c.status">{{ statusLabel(c.status) }}</span>
          <span v-if="c.status === 'quoted' && c.quote_expired" class="badge expired">ใบเสนอราคาหมดอายุ</span>
        </div>
        <div class="row-title">{{ c.destination_label }} · {{ c.group_size }} คน · {{ c.group_type_label }}</div>
        <div class="row-sub">
          {{ c.contact_name }} · {{ c.contact_phone }}
          · อยากไป {{ formatDate(c.preferred_date) }}<template v-if="c.flexible_dates"> (ยืดหยุ่น)</template>
        </div>
        <div class="row-amount">
          <b v-if="c.quote_total != null">฿{{ money(c.quote_total) }}</b>
          <span v-if="c.booking_ref">{{ c.booking_ref }}</span>
          <span>{{ formatDateTime(c.created_at) }}</span>
        </div>
      </button>
    </div>

    <div v-if="lastPage > 1" class="pager">
      <button class="ghost-btn sm" :disabled="page <= 1" @click="go(page - 1)">ก่อนหน้า</button>
      <span>หน้า {{ page }} / {{ lastPage }}</span>
      <button class="ghost-btn sm" :disabled="page >= lastPage" @click="go(page + 1)">ถัดไป</button>
    </div>

    <div v-if="detail" class="overlay" @click.self="detail = null">
      <div class="drawer">
        <div class="drawer-head">
          <div>
            <h3>{{ detail.ref }}</h3>
            <span class="badge" :class="detail.status">{{ statusLabel(detail.status) }}</span>
          </div>
          <button class="ghost-btn sm" @click="detail = null"><i class="fas fa-times"></i></button>
        </div>

        <section>
          <h4>ที่ลูกค้าขอ</h4>
          <div class="kv">
            <div><span>ทริป / ปลายทาง</span><b>{{ detail.destination_label }}</b></div>
            <div v-if="detail.duration_days"><span>จำนวนวัน</span><b>{{ detail.duration_days }} วัน</b></div>
            <div><span>วันที่อยากไป</span><b>{{ formatDate(detail.preferred_date) }}<template v-if="detail.flexible_dates"> · ยืดหยุ่น</template></b></div>
            <div v-if="detail.alternate_date"><span>วันสำรอง</span><b>{{ formatDate(detail.alternate_date) }}</b></div>
            <div><span>จำนวนคน</span><b>{{ detail.group_size }} คน · {{ detail.group_type_label }}</b></div>
            <div v-if="detail.pickup_area"><span>จุดรับ</span><b>{{ detail.pickup_area }}</b></div>
            <div v-if="detail.budget_per_person"><span>งบต่อคน</span><b>฿{{ money(detail.budget_per_person) }}</b></div>
            <div><span>ใบกำกับภาษี</span><b>{{ detail.needs_tax_invoice ? 'ต้องการ' : 'ไม่ต้องการ' }}</b></div>
            <div><span>ผู้ติดต่อ</span><b>{{ detail.contact_name }} · <a :href="`tel:${detail.contact_phone}`">{{ detail.contact_phone }}</a></b></div>
            <div v-if="detail.contact_line"><span>LINE</span><b>{{ detail.contact_line }}</b></div>
            <div v-if="detail.user"><span>บัญชีในแอป</span><b>{{ detail.user.name }} · {{ detail.user.email }}</b></div>
          </div>
          <p v-if="detail.note" class="message">"{{ detail.note }}"</p>
        </section>

        <section>
          <h4>บันทึกภายในทีม</h4>
          <textarea v-model="noteDraft" class="input" rows="2" maxlength="2000" placeholder="ลูกค้าไม่เห็นข้อความนี้"></textarea>
          <button class="ghost-btn sm" :disabled="busy || noteDraft === (detail.admin_note || '')" @click="saveNote">บันทึก</button>
        </section>

        <!-- ใบเสนอราคา -->
        <section v-if="canQuote">
          <h4>{{ detail.quote?.total != null ? 'เสนอราคาใหม่' : 'เสนอราคา' }}</h4>
          <p v-if="detail.status === 'declined'" class="warn">
            ลูกค้าปฏิเสธใบเดิม<template v-if="detail.decline_reason">: "{{ detail.decline_reason }}"</template> — เสนอใหม่ได้
          </p>
          <div class="field-row">
            <div class="field">
              <label>ทริป</label>
              <select v-model.number="quoteForm.trip_id" class="input">
                <option :value="null" disabled>— เลือกทริป —</option>
                <option v-for="t in trips" :key="t.id" :value="t.id">{{ t.title }}</option>
              </select>
            </div>
            <div class="field">
              <label>จำนวนคน</label>
              <input v-model.number="quoteForm.group_size" type="number" min="1" class="input" />
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label>วันเดินทาง</label>
              <input v-model="quoteForm.departure_date" type="date" class="input" />
            </div>
            <div class="field">
              <label>วันกลับ</label>
              <input v-model="quoteForm.return_date" type="date" class="input" />
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label>ราคาต่อคน (บาท)</label>
              <input v-model.number="quoteForm.price_per_person" type="number" min="0" class="input" />
            </div>
            <div class="field">
              <label>ตอบรับได้ถึง (เว้นว่าง = 7 วัน)</label>
              <input v-model="quoteForm.valid_until" type="date" class="input" />
            </div>
          </div>
          <p class="hint">รวมทั้งกลุ่ม <b>฿{{ money((Number(quoteForm.price_per_person) || 0) * (Number(quoteForm.group_size) || 0)) }}</b> — ราคาต่อคนนี้จะเป็นราคาของรอบเหมาที่เปิดจากคำขอ</p>
          <div class="field">
            <label>ราคารวมอะไรบ้าง</label>
            <textarea v-model="quoteForm.includes" class="input" rows="3" maxlength="2000" placeholder="รถตู้ VIP ไป-กลับ&#10;ที่พัก 2 คืน&#10;อาหาร 6 มื้อ"></textarea>
          </div>
          <div class="field">
            <label>ข้อความถึงลูกค้า</label>
            <textarea v-model="quoteForm.note" class="input" rows="2" maxlength="2000"></textarea>
          </div>
          <div class="actions">
            <button class="primary-btn sm" :disabled="busy || !quoteValid" @click="sendQuote"><i class="fas fa-paper-plane"></i> ส่งใบเสนอราคา</button>
            <button class="ghost-btn sm danger" :disabled="busy" @click="reject"><i class="fas fa-ban"></i> รับไม่ได้ / ปิดคำขอ</button>
          </div>
        </section>

        <section v-else-if="detail.quote?.total != null">
          <h4>ใบเสนอราคา</h4>
          <div class="kv">
            <div><span>ทริป</span><b>{{ detail.quote.trip_title || '-' }}</b></div>
            <div><span>วันที่</span><b>{{ formatDate(detail.quote.departure_date) }} – {{ formatDate(detail.quote.return_date) }}</b></div>
            <div><span>ราคา</span><b>{{ detail.quote.group_size }} คน × ฿{{ money(detail.quote.price_per_person) }} = ฿{{ money(detail.quote.total) }}</b></div>
            <div><span>เสนอโดย</span><b>{{ detail.quote.quoted_by || '-' }} · {{ formatDateTime(detail.quote.quoted_at) }}</b></div>
          </div>
          <p v-if="detail.reject_reason" class="warn">ปิดคำขอ: {{ detail.reject_reason }}</p>
        </section>

        <!-- หลังลูกค้าตอบรับ -->
        <section v-if="['accepted', 'booked'].includes(detail.status)">
          <h4>เปิดการจองให้กลุ่ม</h4>
          <ol class="steps">
            <li :class="{ done: detail.schedule }">
              <template v-if="detail.schedule">เปิดรอบเหมาแล้ว — {{ formatDate(detail.schedule.departure_date) }} · {{ detail.schedule.total_seats }} ที่</template>
              <template v-else>
                เปิดรอบเหมาตามใบเสนอราคา (ราคาต่อคนตามที่ตกลง ลูกค้าทั่วไปจองรอบนี้ไม่ได้)
                <button class="primary-btn sm" :disabled="busy" @click="createSchedule"><i class="fas fa-calendar-plus"></i> เปิดรอบเหมา</button>
              </template>
            </li>
            <li :class="{ done: detail.booking_ref }">
              <template v-if="detail.booking_ref">ผูกการจอง {{ detail.booking_ref }} แล้ว ({{ detail.booking_status }}) — ลูกค้าเห็นปุ่มชำระเงินในแอป</template>
              <template v-else>
                ลงการจองให้กลุ่ม (เข้าบัญชีผู้ขอและผูกกลับมาที่นี่ให้เอง)
                <router-link class="primary-btn sm" :to="{ path: '/admin/manual-booking', query: { charter_request: detail.id } }">
                  <i class="fas fa-pen-to-square"></i> ไปหน้าจองแทน
                </router-link>
              </template>
            </li>
          </ol>
          <div class="link-row">
            <input v-model="linkRef" class="input" placeholder="หรือผูกการจองที่มีอยู่ด้วยเลข LLK-..." />
            <button class="ghost-btn sm" :disabled="busy || !linkRef.trim()" @click="linkBooking">ผูกการจอง</button>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// คำขอเหมาทริป (หลังบ้าน) — ดู App\Services\CharterRequestService

const toast = useToast();
const swal = useSwal();

const tabs = [
  { key: 'new', label: 'คำขอใหม่' },
  { key: 'quoted', label: 'รอลูกค้าตอบ' },
  { key: 'accepted', label: 'ตอบรับแล้ว รอเปิดการจอง' },
  { key: 'declined', label: 'ลูกค้าปฏิเสธ' },
  { key: 'booked', label: 'จองแล้ว' },
  { key: 'rejected', label: 'ปิดคำขอ' },
  { key: 'cancelled', label: 'ลูกค้ายกเลิก' },
  { key: null, label: 'ทั้งหมด' },
];

const status = ref(null);
const q = ref('');
const items = ref([]);
const counts = ref({});
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const busy = ref(false);
const detail = ref(null);
const trips = ref([]);
const noteDraft = ref('');
const linkRef = ref('');
const quoteForm = ref(emptyQuote());
let searchTimer = null;

function emptyQuote() {
  return { trip_id: null, departure_date: '', return_date: '', group_size: null, price_per_person: null, includes: '', note: '', valid_until: '' };
}

const statusLabel = (s) => ({
  new: 'คำขอใหม่',
  quoted: 'รอลูกค้าตอบ',
  accepted: 'ตอบรับแล้ว',
  declined: 'ลูกค้าปฏิเสธ',
  rejected: 'ปิดคำขอ',
  cancelled: 'ลูกค้ายกเลิก',
  booked: 'จองแล้ว',
})[s] || s;

const canQuote = computed(() => ['new', 'quoted', 'declined'].includes(detail.value?.status));
const quoteValid = computed(() => {
  const f = quoteForm.value;
  return f.trip_id && f.departure_date && f.return_date && f.return_date >= f.departure_date
    && Number(f.group_size) > 0 && Number(f.price_per_person) >= 0 && f.price_per_person !== null && f.price_per_person !== '';
});

const money = (n) => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });

function formatDate(d) {
  if (!d) return '-';
  const date = new Date(`${String(d).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });
}
function formatDateTime(d) {
  if (!d) return '';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

async function load() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (status.value) params.status = status.value;
    if (q.value.trim()) params.q = q.value.trim();
    const res = await api.get('/admin/charter-requests', { params });
    items.value = res.data.data || [];
    counts.value = res.data.meta?.counts || {};
    lastPage.value = res.data.meta?.last_page || 1;
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายการไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

async function loadTrips() {
  try {
    const res = await api.get('/admin/trips', { params: { per_page: 500 } });
    trips.value = (res.data.data || []).map((t) => ({ id: t.id, title: t.title }));
  } catch {
    trips.value = [];
  }
}

function setStatus(key) {
  status.value = key;
  page.value = 1;
  load();
}

function onSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    page.value = 1;
    load();
  }, 300);
}

function go(p) {
  page.value = p;
  load();
}

function fillQuote(c) {
  const quote = c.quote || {};
  // ใบเสนอราคาเดิมมาก่อน ถ้าไม่มีก็ตั้งจากสิ่งที่ลูกค้าขอ
  quoteForm.value = {
    trip_id: quote.trip_id ?? c.trip_id ?? null,
    departure_date: quote.departure_date || c.preferred_date || '',
    return_date: quote.return_date || c.preferred_date || '',
    group_size: quote.group_size ?? c.group_size,
    price_per_person: quote.price_per_person ?? null,
    includes: quote.includes || '',
    note: quote.note || '',
    valid_until: '',
  };
}

async function open(c) {
  try {
    const res = await api.get(`/admin/charter-requests/${c.id}`);
    detail.value = res.data.data;
    noteDraft.value = detail.value.admin_note || '';
    linkRef.value = '';
    fillQuote(detail.value);
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายละเอียดไม่สำเร็จ');
  }
}

async function act(request, done) {
  if (busy.value) return;
  busy.value = true;
  try {
    const res = await request();
    toast.success(res.data?.message || done);
    const id = detail.value?.id;
    await load();
    if (id) await open({ id });
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function sendQuote() {
  const f = quoteForm.value;
  const total = (Number(f.price_per_person) || 0) * (Number(f.group_size) || 0);
  const res = await swal.confirm({
    title: 'ส่งใบเสนอราคา?',
    text: `${f.group_size} คน × ฿${money(f.price_per_person)} = ฿${money(total)} — ลูกค้าได้แจ้งเตือนและอีเมล`,
    confirmText: 'ส่ง',
  });
  if (!res.isConfirmed) return;
  const payload = { ...f };
  if (!payload.valid_until) delete payload.valid_until;
  return act(() => api.post(`/admin/charter-requests/${detail.value.id}/quote`, payload), 'ส่งแล้ว');
}

async function reject() {
  const res = await swal.confirm({
    input: 'text',
    title: 'ปิดคำขอนี้?',
    text: 'ลูกค้าจะเห็นเหตุผล',
    inputPlaceholder: 'เช่น วันนั้นรถเต็มทุกคัน',
    confirmText: 'ปิดคำขอ',
    icon: 'warning',
  });
  if (!res.isConfirmed || !String(res.value || '').trim()) return;
  return act(() => api.post(`/admin/charter-requests/${detail.value.id}/reject`, { reason: res.value }), 'ปิดแล้ว');
}

function saveNote() {
  return act(() => api.put(`/admin/charter-requests/${detail.value.id}/note`, { admin_note: noteDraft.value || null }), 'บันทึกแล้ว');
}

function createSchedule() {
  return act(() => api.post(`/admin/charter-requests/${detail.value.id}/schedule`), 'เปิดรอบเหมาแล้ว');
}

function linkBooking() {
  return act(() => api.post(`/admin/charter-requests/${detail.value.id}/link-booking`, { booking_ref: linkRef.value.trim() }), 'ผูกแล้ว');
}

onMounted(() => {
  load();
  loadTrips();
});
</script>

<style scoped>
.ch-page { display: flex; flex-direction: column; gap: 16px; }
.ch-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ch-head h2 { margin: 0; font-size: 20px; font-weight: 800; color: #1f2937; }
.ch-head h2 i { color: #2D7A4F; margin-right: 6px; }
.sub { margin: 4px 0 0; font-size: 13px; color: #6b7280; }
.search { max-width: 340px; }

.input { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 9px 12px; font-size: 14px; outline: none; font-family: inherit; background: #fff; box-sizing: border-box; }
.input:focus { border-color: #2D7A4F; }
.hint { margin: 0; font-size: 12.5px; color: #6b7280; }
.warn { margin: 0; font-size: 13px; color: #b45309; background: #fffbeb; border-radius: 8px; padding: 8px 10px; }

.primary-btn { border: none; background: #2D7A4F; color: #fff; border-radius: 10px; padding: 10px 16px; font-size: 14px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
.primary-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.ghost-btn { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.ghost-btn.danger:hover { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
.primary-btn.sm, .ghost-btn.sm { padding: 7px 12px; font-size: 13px; }

.tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.tab { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.tab.active { background: #ecfdf5; border-color: #2D7A4F; color: #2D7A4F; }
.count { background: #f3f4f6; border-radius: 999px; padding: 0 7px; font-size: 12px; }
.count.alert { background: #fee2e2; color: #b91c1c; }

.empty-hint { padding: 32px 16px; color: #9ca3af; font-size: 13px; text-align: center; background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; }

.list { display: flex; flex-direction: column; gap: 8px; }
.row { text-align: left; font-family: inherit; background: #fff; border: 1px solid #e9edf0; border-radius: 12px; padding: 12px 14px; cursor: pointer; display: grid; grid-template-columns: 1fr auto; gap: 3px 16px; }
.row:hover, .row.selected { border-color: #2D7A4F; }
.row-main { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ref { font-weight: 800; color: #111827; }
.row-title { grid-column: 1; font-size: 14px; font-weight: 700; color: #1f2937; }
.row-sub { grid-column: 1; font-size: 12.5px; color: #6b7280; }
.row-amount { grid-column: 2; grid-row: 1 / span 3; display: flex; flex-direction: column; align-items: flex-end; font-size: 12px; color: #6b7280; }
.row-amount b { font-size: 16px; color: #111827; }

.badge { font-size: 11.5px; font-weight: 800; border-radius: 999px; padding: 2px 9px; background: #f3f4f6; color: #4b5563; }
.badge.new { background: #fff7ed; color: #c2410c; }
.badge.quoted { background: #eff6ff; color: #2563eb; }
.badge.accepted { background: #fef3c7; color: #92400e; }
.badge.booked { background: #ecfdf5; color: #2D7A4F; }
.badge.declined, .badge.rejected, .badge.cancelled, .badge.expired { background: #fef2f2; color: #b91c1c; }

.pager { display: flex; gap: 12px; align-items: center; justify-content: center; font-size: 13px; color: #6b7280; }

.overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.35); z-index: 60; display: flex; justify-content: flex-end; }
.drawer { width: min(620px, 100%); height: 100%; overflow-y: auto; background: #fff; padding: 20px; display: flex; flex-direction: column; gap: 18px; box-sizing: border-box; }
.drawer-head { display: flex; justify-content: space-between; align-items: flex-start; }
.drawer-head h3 { margin: 0 0 4px; font-size: 18px; font-weight: 800; }
section { display: flex; flex-direction: column; gap: 10px; }
section h4 { margin: 0; font-size: 14px; font-weight: 800; color: #374151; }
.kv { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kv div { display: flex; flex-direction: column; gap: 2px; font-size: 13px; min-width: 0; }
.kv span { color: #6b7280; font-size: 12px; font-weight: 700; }
.kv a { color: #2563eb; }
.message { margin: 0; background: #f9fafb; border-radius: 10px; padding: 10px 12px; font-style: italic; font-size: 13.5px; color: #374151; }
.field-row { display: flex; gap: 12px; flex-wrap: wrap; }
.field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 180px; }
.field label { font-size: 12px; font-weight: 700; color: #6b7280; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.steps { margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 10px; font-size: 13.5px; color: #374151; }
.steps li { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.steps li.done { color: #2D7A4F; }
.link-row { display: flex; gap: 8px; }
</style>
