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
              <td>{{ baht(b.total_amount) }}</td>
              <td>
                <span class="status-badge" :class="badgeClass(b.status)">{{ b.status_label }}</span>
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
            <span>ที่พัก</span><strong>{{ detail.stay?.name }} · {{ detail.room_name }} × {{ detail.units }}</strong>
            <span>วันที่</span><strong>{{ shortDate(detail.check_in) }} – {{ shortDate(detail.check_out) }} ({{ detail.nights }} คืน) · {{ detail.guests }} คน</strong>
            <span>แขก</span><strong>{{ detail.guest_name }} · {{ detail.guest_phone }}<template v-if="detail.guest_email"> · {{ detail.guest_email }}</template></strong>
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
          </div>

          <template v-if="detail.slip_url">
            <h4 class="section-title">สลิป <span class="muted-sub">({{ detail.slip_ocr_status }})</span></h4>
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
import { onMounted, ref } from 'vue';
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
];

const toast = useToast();
const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, counts: {} });
const status = ref('');
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

function countFor(key) {
  if (!key) return 0;
  return meta.value.counts?.[key] || 0;
}

function setStatus(key) {
  status.value = key;
  fetchPage(1);
}

async function fetchPage(page = 1) {
  loading.value = true;
  try {
    const res = await api.get('/admin/stay-bookings', { params: { page, status: status.value || undefined, q: q.value || undefined } });
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

onMounted(() => fetchPage(1));
</script>

<style scoped>
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
  .kv { grid-template-columns: 1fr; }
  .kv span { margin-top: 6px; }
}
</style>
