<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">receipt_long</span> ใบจองที่พัก</h1>
        <p class="page-subtitle">คำขอรอยืนยัน · สลิปรอตรวจ · เงินคืนรอโอน — เรียงงานที่ต้องทำก่อนไว้บนสุด</p>
      </div>
      <router-link to="/admin/stays" class="btn-secondary">
        <span class="material-symbols-rounded">cottage</span> จัดการที่พัก
      </router-link>
    </div>

    <div class="filter-tabs">
      <button v-for="f in FILTERS" :key="f.key" class="filter-tab" :class="{ active: status === f.key }" @click="setStatus(f.key)">
        {{ f.label }}
        <span v-if="countFor(f.key)" class="count">{{ countFor(f.key) }}</span>
      </button>
    </div>

    <div class="table-card">
      <div class="search-row">
        <input v-model="q" placeholder="ค้นเลขใบจอง ชื่อ หรือเบอร์" @keyup.enter="fetchPage(1)" />
        <select v-model="stayId" @change="fetchPage(1)">
          <option value="">ทุกที่พัก</option>
          <option v-for="st in stayOptions" :key="st.id" :value="String(st.id)">{{ st.name }}</option>
        </select>
        <button class="btn-secondary" @click="fetchPage(1)">ค้นหา</button>
      </div>

      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div class="empty-state" v-else-if="!rows.length">
        <span class="material-symbols-rounded">bedroom_parent</span>
        <p>ไม่มีใบจองในหมวดนี้</p>
      </div>

      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr><th>ใบจอง</th><th>ที่พัก</th><th>เข้าพัก</th><th>แขก</th><th>ยอด</th><th>สถานะ</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="b in rows" :key="b.ref" class="clickable" @click="open(b.ref)">
              <td><strong>{{ b.ref }}</strong><div class="muted-sub">{{ shortDate(b.created_at) }}</div></td>
              <td>{{ b.stay?.name }}<div class="muted-sub">{{ b.room_name }} × {{ b.units }}</div></td>
              <td>{{ shortDate(b.check_in) }} – {{ shortDate(b.check_out) }}<div class="muted-sub">{{ b.nights }} คืน · {{ b.guests }} คน</div></td>
              <td>{{ b.guest_name }}<div class="muted-sub">{{ b.guest_phone }}</div></td>
              <td>
                {{ baht(b.total_amount) }}
                <div v-if="b.payment_method" class="muted-sub">{{ methodLabel(b.payment_method) }}</div>
              </td>
              <td>
                <span class="status-badge" :class="badgeClass(b.status)">{{ b.status_label }}</span>
                <div v-if="b.status === 'awaiting_payment' && b.payment_due_at" class="muted-sub">ภายใน {{ dateTime(b.payment_due_at) }}</div>
                <div v-if="b.refund_status === 'pending'" class="muted-sub warn">รอคืน {{ baht(b.refund_amount) }}</div>
              </td>
              <td><span class="material-symbols-rounded">chevron_right</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pager" v-if="meta.last_page > 1">
        <button class="btn-secondary" :disabled="meta.current_page <= 1" @click="fetchPage(meta.current_page - 1)">ก่อนหน้า</button>
        <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button class="btn-secondary" :disabled="meta.current_page >= meta.last_page" @click="fetchPage(meta.current_page + 1)">ถัดไป</button>
      </div>
    </div>

    <!-- รายละเอียด -->
    <div class="modal-overlay" v-if="detail" @click.self="detail = null">
      <div class="modal-card" style="max-width:720px">
        <div class="modal-header">
          <h3>{{ detail.ref }} · <span class="status-badge" :class="badgeClass(detail.status)">{{ detail.status_label }}</span></h3>
          <button class="modal-close" @click="detail = null"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="kv">
            <span>ที่พัก</span>
            <strong>
              {{ detail.stay?.name }} · {{ detail.room_name }} × {{ detail.units }}
              <a v-if="detail.stay?.slug" :href="'/stays/' + encodeURIComponent(detail.stay.slug)" target="_blank" class="inline-link">หน้าที่พัก ↗</a>
              <button v-if="detail.host_url" type="button" class="inline-link" @click="copy(detail.host_url)">คัดลอกลิงก์เจ้าของ</button>
            </strong>
            <span>วันที่</span><strong>{{ shortDate(detail.check_in) }} – {{ shortDate(detail.check_out) }} ({{ detail.nights }} คืน) · {{ detail.guests }} คน</strong>
            <span>แขก</span><strong>{{ detail.guest_name }} · <a :href="'tel:' + detail.guest_phone" class="inline-link">{{ detail.guest_phone }}</a><template v-if="detail.guest_email"> · {{ detail.guest_email }}</template></strong>
            <span>บัญชี</span><strong>{{ detail.user?.name || '—' }} <template v-if="detail.user">({{ detail.user.phone || detail.user.email }})</template></strong>
            <span v-if="detail.trip_booking_ref">พ่วงทริป</span><strong v-if="detail.trip_booking_ref">{{ detail.trip_booking_ref }}</strong>
            <span v-if="detail.note">หมายเหตุลูกค้า</span><strong v-if="detail.note">{{ detail.note }}</strong>
          </div>

          <h4 class="section-title">ยอดเงิน</h4>
          <div class="kv">
            <span>ค่าห้อง</span><strong>{{ baht(detail.room_amount) }}</strong>
            <span v-if="detail.extra_guest_amount">คนเพิ่ม</span><strong v-if="detail.extra_guest_amount">{{ baht(detail.extra_guest_amount) }}</strong>
            <template v-for="x in detail.experiences" :key="x.id">
              <span>{{ x.title }} × {{ x.people }}</span><strong>{{ baht(x.amount) }}</strong>
            </template>
            <span>รวม</span><strong class="big">{{ baht(detail.total_amount) }}</strong>
            <span>ค่าคอม ({{ detail.commission_percent }}%)</span><strong>{{ baht(detail.commission_amount) }}</strong>
            <span>ส่วนเจ้าของ</span><strong>{{ baht(detail.host_amount) }}</strong>
            <span>นโยบายยกเลิก</span><strong>{{ detail.cancellation_policy?.label }}</strong>
            <template v-if="detail.payment_method">
              <span>ชำระผ่าน</span>
              <strong>{{ methodLabel(detail.payment_method) }}<span v-if="detail.payment_ref" class="muted-sub"> · {{ detail.payment_ref }}</span></strong>
            </template>
            <template v-if="detail.paid_at">
              <span>รับเงินแล้ว</span><strong>{{ baht(detail.paid_amount) }} · {{ dateTime(detail.paid_at) }}</strong>
            </template>
            <template v-if="detail.payout_id">
              <span>โอนให้เจ้าของ</span><strong>โอนแล้ว (รอบ #{{ detail.payout_id }})</strong>
            </template>
            <template v-else-if="detail.payout_eligible_at">
              <span>โอนให้เจ้าของ</span><strong>ถึงรอบโอนแล้ว — ไปที่ "ที่พักและห้อง" แท็บโอนเงิน</strong>
            </template>
          </div>

          <h4 class="section-title">ลำดับเหตุการณ์</h4>
          <ol class="timeline">
            <li v-for="ev in timeline" :key="ev.label" :class="{ 'is-bad': ev.bad }">
              <span class="tl-time">{{ dateTime(ev.at) }}</span>
              <span class="tl-label">{{ ev.label }}</span>
            </li>
            <li v-if="detail.status === 'awaiting_payment' && detail.payment_due_at" class="is-next">
              <span class="tl-time">{{ dateTime(detail.payment_due_at) }}</span>
              <span class="tl-label">หมดเวลาชำระ — ห้องถูกปล่อยถ้ายังไม่จ่าย</span>
            </li>
          </ol>

          <template v-if="detail.beam_payments?.length">
            <h4 class="section-title">การชำระผ่าน Beam <span class="muted-sub">({{ detail.beam_payments.length }} ครั้ง)</span></h4>
            <table class="mini-table">
              <thead><tr><th>เวลา</th><th>ช่องทาง</th><th>ยอด</th><th>ผล</th></tr></thead>
              <tbody>
                <tr v-for="p in detail.beam_payments" :key="p.id">
                  <td>{{ dateTime(p.created_at) }}</td>
                  <td>{{ BEAM_METHOD[p.method] || p.method }}</td>
                  <td>{{ baht(p.amount) }}</td>
                  <td>
                    <span class="status-badge" :class="beamClass(p)">{{ beamLabel(p) }}</span>
                    <div class="muted-sub">{{ p.reference_id }}</div>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>

          <template v-if="detail.slip_url">
            <h4 class="section-title">สลิป <span class="muted-sub">({{ OCR_LABEL[detail.slip_ocr_status] || detail.slip_ocr_status }})</span></h4>
            <a :href="detail.slip_url" target="_blank"><img :src="detail.slip_url" class="slip" /></a>
          </template>

          <template v-if="detail.refund_status">
            <h4 class="section-title">คืนเงิน</h4>
            <div class="kv">
              <span>ยอดคืน</span><strong>{{ baht(detail.refund_amount) }} ({{ detail.refund_status === 'refunded' ? 'โอนแล้ว' : 'รอโอน' }})</strong>
              <span>บัญชี</span>
              <strong v-if="detail.refund_account">{{ detail.refund_account.bank }} {{ detail.refund_account.number }} · {{ detail.refund_account.name }}</strong>
              <strong v-else class="warn">ลูกค้ายังไม่แจ้งบัญชี</strong>
              <span v-if="detail.cancel_reason">เหตุผลยกเลิก</span><strong v-if="detail.cancel_reason">{{ detail.cancel_reason }} ({{ detail.cancelled_by }})</strong>
            </div>
          </template>

          <div class="form-group">
            <label>บันทึกภายใน</label>
            <textarea v-model="note" rows="2" @blur="saveNote"></textarea>
          </div>

          <div class="actions">
            <template v-if="detail.status === 'requested'">
              <input v-model="adjustTotal" type="number" min="1" :placeholder="'ปรับยอด (เดิม ' + detail.total_amount + ')'" class="inline-input" />
              <button class="btn-primary" @click="act('approve', adjustTotal ? { total_amount: Number(adjustTotal) } : {})">ห้องว่าง — ให้ลูกค้าจ่าย</button>
              <button class="btn-secondary" @click="askAndAct('decline', 'reason', 'บอกลูกค้าว่าทำไม (ไม่บังคับ)', false)">ห้องไม่ว่าง</button>
            </template>
            <template v-if="detail.status === 'payment_review'">
              <button class="btn-primary" @click="act('confirm-payment')">สลิปถูกต้อง — ยืนยัน</button>
              <button class="btn-secondary" @click="askAndAct('reject-slip', 'note', 'สลิปมีปัญหาอะไร (ลูกค้าเห็น)')">สลิปไม่ผ่าน</button>
            </template>
            <button v-if="detail.status === 'awaiting_payment'" class="btn-secondary" @click="act('confirm-payment')">ยืนยันว่าได้รับเงินแล้ว</button>
            <button v-if="detail.refund_status === 'pending'" class="btn-primary" @click="askAndAct('refunded', 'note', 'เลขอ้างอิงการโอนคืน (ไม่บังคับ)', false)">บันทึกว่าคืนเงินแล้ว</button>
            <button v-if="['requested','awaiting_payment','payment_review','confirmed'].includes(detail.status)" class="btn-secondary btn-danger-text" @click="cancelBooking">ยกเลิกใบจอง</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';

const FILTERS = [
  { key: '', label: 'ทั้งหมด' },
  { key: 'requested', label: 'รอยืนยันห้อง' },
  { key: 'payment_review', label: 'สลิปรอตรวจ' },
  { key: 'awaiting_payment', label: 'รอชำระ' },
  { key: 'confirmed', label: 'ยืนยันแล้ว' },
  { key: 'refund_pending', label: 'รอคืนเงิน' },
  { key: 'completed', label: 'เข้าพักแล้ว' },
  { key: 'cancelled', label: 'ยกเลิก' },
  { key: 'declined', label: 'ห้องไม่ว่าง' },
  { key: 'expired', label: 'หมดเวลา' },
];

const BEAM_METHOD = {
  QR_PROMPT_PAY: 'QR พร้อมเพย์',
  CHECKOUT: 'หน้าชำระเงิน Beam',
  KPLUS: 'K PLUS',
  SCB_EASY: 'SCB EASY',
  KRUNGSRI_APP: 'Krungsri',
  BANGKOK_BANK_APP: 'Bualuang',
};

const OCR_LABEL = {
  pending: 'รอตรวจยอด',
  verified: 'ระบบอ่านแล้วยอดตรง (เดิม)',
  failed: 'ระบบอ่านไม่ผ่าน — ต้องตรวจเอง',
  manually_approved: 'ทีมงานอนุมัติ',
  rejected: 'ไม่ผ่าน',
};

const toast = useToast();
const route = useRoute();
const router = useRouter();
const stayId = ref(route.query.stay_id ? String(route.query.stay_id) : '');
const stayOptions = ref([]);
const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, counts: {} });
const status = ref(typeof route.query.status === 'string' ? route.query.status : '');
const q = ref('');
const detail = ref(null);
const note = ref('');
const adjustTotal = ref('');

function baht(n) {
  return '฿' + Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });
}

function shortDate(iso) {
  if (!iso) return '—';
  const d = iso.length === 10 ? new Date(iso + 'T00:00:00+07:00') : new Date(iso);
  return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'Asia/Bangkok' });
}

function badgeClass(s) {
  if (['confirmed', 'completed'].includes(s)) return 'status-active';
  if (['requested', 'payment_review', 'awaiting_payment'].includes(s)) return 'status-pending';
  return 'status-inactive';
}

function dateTime(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Bangkok' });
}

function methodLabel(method) {
  if (!method) return '';
  return method.startsWith('beam') ? 'Beam (ยืนยันอัตโนมัติ)' : 'โอน + สลิป';
}

function beamLabel(p) {
  if (p.status === 'succeeded') return p.failure_code ? 'เงินเข้า · ต้องคืน' : 'สำเร็จ';
  return { pending: 'รอจ่าย', failed: 'ไม่สำเร็จ', expired: 'หมดอายุ' }[p.status] || p.status;
}

function beamClass(p) {
  if (p.status === 'succeeded') return p.failure_code ? 'status-cancelled' : 'status-active';
  return p.status === 'pending' ? 'status-pending' : 'status-inactive';
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast.success('คัดลอกแล้ว');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ');
  }
}

/** ใบจองนี้เดินมาอย่างไร — เรียงตามเวลาจริง ไม่ใช่ตามลำดับขั้นในทฤษฎี */
const timeline = computed(() => {
  const d = detail.value;
  if (!d) return [];
  const by = (who) => ({ host: 'โดยเจ้าของที่พัก', staff: 'โดยทีมงาน', customer: 'โดยลูกค้า' }[who] || '');
  return [
    { at: d.created_at, label: d.booking_mode === 'request' ? 'ลูกค้าส่งคำขอจอง' : 'ลูกค้าจอง (จองทันที)' },
    d.responded_at && {
      at: d.responded_at,
      label: (d.status === 'declined' ? 'แจ้งว่าห้องไม่ว่าง ' : 'ยืนยันห้องว่าง ') + by(d.responded_by),
      bad: d.status === 'declined',
    },
    d.paid_at && { at: d.paid_at, label: `ได้รับเงิน ${baht(d.paid_amount)}` },
    d.cancelled_at && { at: d.cancelled_at, label: `ยกเลิก ${by(d.cancelled_by)}`, bad: true },
    d.refunded_at && { at: d.refunded_at, label: `โอนเงินคืน ${baht(d.refund_amount)} แล้ว` },
    d.completed_at && { at: d.completed_at, label: 'เข้าพักเสร็จ' },
  ].filter(Boolean).sort((a, b) => new Date(a.at) - new Date(b.at));
});

function countFor(key) {
  if (!key) return 0;
  return meta.value.counts?.[key] || 0;
}

function setStatus(key) {
  status.value = key;
  fetchPage(1);
}

/** ตัวกรองอยู่ใน URL — ลิงก์จากหน้าที่พักเปิดมาแล้วกรองให้เลย และกดย้อนกลับได้ */
function syncQuery() {
  const query = {};
  if (status.value) query.status = status.value;
  if (stayId.value) query.stay_id = stayId.value;
  router.replace({ query });
}

async function fetchPage(page = 1) {
  loading.value = true;
  try {
    syncQuery();
    const res = await api.get('/admin/stay-bookings', {
      params: { page, status: status.value || undefined, q: q.value || undefined, stay_id: stayId.value || undefined },
    });
    rows.value = res.data.data || [];
    meta.value = res.data.meta || meta.value;
  } finally {
    loading.value = false;
  }
}

async function open(ref) {
  try {
    const res = await api.get(`/admin/stay-bookings/${ref}`);
    detail.value = res.data.data;
    note.value = detail.value.admin_note || '';
    adjustTotal.value = '';
  } catch (err) {
    toast.error(err.response?.data?.message || 'โหลดไม่สำเร็จ');
  }
}

async function act(action, body = {}) {
  try {
    const res = await api.post(`/admin/stay-bookings/${detail.value.ref}/${action}`, body);
    detail.value = res.data.data;
    toast.success(res.data.message);
    fetchPage(meta.value.current_page);
  } catch (err) {
    toast.error(err.response?.data?.message || 'ไม่สำเร็จ');
  }
}

async function askAndAct(action, field, label, required = true) {
  const { value, isConfirmed } = await Swal.fire({
    title: label,
    input: 'text',
    showCancelButton: true,
    confirmButtonText: 'ยืนยัน',
    cancelButtonText: 'ยกเลิก',
    inputValidator: (v) => (required && !v?.trim() ? 'กรุณากรอก' : undefined),
  });
  if (!isConfirmed) return;
  await act(action, value?.trim() ? { [field]: value.trim() } : {});
}

async function cancelBooking() {
  const paid = detail.value.paid_amount || 0;
  const { value, isConfirmed } = await Swal.fire({
    title: 'ยกเลิกใบจองนี้?',
    html: `<p style="font-size:13px;color:#6b7280">ลูกค้าจ่ายแล้ว ${baht(paid)} — ทีมงานยกเลิกคืนเต็มจำนวนถ้าไม่ระบุ</p>`
      + '<input id="sb-reason" class="swal2-input" placeholder="เหตุผล (ลูกค้าเห็น)">'
      + `<input id="sb-refund" class="swal2-input" type="number" min="0" max="${paid}" placeholder="ยอดคืน (ว่าง = ${paid})">`,
    showCancelButton: true,
    confirmButtonText: 'ยกเลิกใบจอง',
    cancelButtonText: 'ปิด',
    preConfirm: () => {
      const reason = document.getElementById('sb-reason').value.trim();
      if (!reason) { Swal.showValidationMessage('กรุณาบอกเหตุผล'); return false; }
      const refund = document.getElementById('sb-refund').value;
      return { reason, ...(refund !== '' ? { refund_amount: Number(refund) } : {}) };
    },
  });
  if (!isConfirmed) return;
  await act('cancel', value);
}

async function saveNote() {
  if ((detail.value.admin_note || '') === note.value) return;
  try {
    const res = await api.put(`/admin/stay-bookings/${detail.value.ref}/note`, { admin_note: note.value || null });
    detail.value = res.data.data;
  } catch {
    toast.error('บันทึกไม่สำเร็จ');
  }
}

onMounted(async () => {
  fetchPage(1);
  try {
    const res = await api.get('/admin/stays');
    stayOptions.value = (res.data?.data?.stays || []).map(st => ({ id: st.id, name: st.name }));
  } catch {
    stayOptions.value = [];
  }
});
</script>

<style scoped>
@import url('./admin-shared.css');

.heading-icon { font-size: 28px; color: var(--color-accent); vertical-align: text-bottom; }
.muted-sub { font-size: 12px; color: #9ca3af; }
.search-row select { padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; max-width: 220px; }
.inline-link { margin-left: 8px; font-size: 12px; font-weight: 700; color: var(--color-accent); background: none; border: 0; padding: 0; cursor: pointer; text-decoration: underline; }
.timeline { list-style: none; margin: 0; padding: 0 0 0 14px; border-left: 2px solid #e5e7eb; display: grid; gap: 8px; }
.timeline li { position: relative; display: flex; gap: 10px; font-size: 13px; }
.timeline li::before { content: ''; position: absolute; left: -20px; top: 5px; width: 10px; height: 10px; border-radius: 999px; background: var(--color-accent); }
.timeline li.is-bad::before { background: #d92d20; }
.timeline li.is-next::before { background: #fff; border: 2px solid #f79009; }
.tl-time { flex: 0 0 120px; color: #6b7280; }
.tl-label { color: #111827; font-weight: 600; }
.mini-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mini-table th { text-align: left; font-size: 12px; color: #6b7280; font-weight: 700; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
.mini-table td { padding: 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.filter-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; font-size: 13px; font-weight: 700; color: #6b7280; cursor: pointer; }
.filter-tab.active { background: #006565; color: #fff; border-color: #006565; }
.filter-tab .count { background: #f04438; color: #fff; border-radius: 999px; font-size: 11px; padding: 0 7px; }
.search-row { display: flex; gap: 8px; padding: 12px; }
.search-row input { flex: 1; min-width: 0; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 10px; }
.clickable { cursor: pointer; }
.muted-sub.warn, .warn { color: #b54708; }
.pager { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 12px; }
.kv { display: grid; grid-template-columns: 140px 1fr; gap: 6px 12px; font-size: 13px; }
.kv span { color: #6b7280; }
.kv .big { font-size: 16px; color: #006565; }
.section-title { font-size: 13px; font-weight: 800; color: #111827; margin: 18px 0 8px; }
.slip { max-width: 240px; border-radius: 12px; border: 1px solid #e5e7eb; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; align-items: center; }
.inline-input { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 10px; width: 200px; }
.btn-danger-text { color: #b42318 !important; }
@media (max-width: 640px) {
  .search-row { flex-wrap: wrap; }
  .search-row select { max-width: none; flex: 1; }
  .tl-time { flex-basis: 90px; }
  .kv { grid-template-columns: 1fr; }
  .kv span { margin-top: 6px; }
}
</style>
