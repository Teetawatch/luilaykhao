<template>
  <div class="gv-page">
    <div class="gv-head">
      <div>
        <h2><i class="fas fa-gift"></i> บัตรของขวัญ</h2>
        <p class="sub">บัตรแบบระบุยอดเงิน — ตรวจสลิปที่ระบบอ่านไม่ผ่าน ดูสมุดบัญชีของแต่ละใบ ปรับยอด และออกบัตรชดเชยให้ลูกค้า</p>
      </div>
      <button class="primary-btn" @click="showIssue = !showIssue">
        <i class="fas" :class="showIssue ? 'fa-times' : 'fa-plus'"></i>
        {{ showIssue ? 'ปิด' : 'ออกบัตรให้ลูกค้า' }}
      </button>
    </div>

    <div class="tiles">
      <div class="tile">
        <span class="tile-label">ขายบัตรได้</span>
        <b>฿{{ money(summary.sold_total) }}</b>
        <span class="tile-note">{{ summary.sold_count || 0 }} ใบ</span>
      </div>
      <div class="tile">
        <span class="tile-label">ยอดในบัตรที่ลูกค้ายังใช้ได้</span>
        <b>฿{{ money(summary.outstanding_balance) }}</b>
        <span class="tile-note">ต้องให้บริการในอนาคต</span>
      </div>
      <div class="tile">
        <span class="tile-label">ใช้จองทริปไปแล้ว</span>
        <b>฿{{ money(summary.redeemed_total) }}</b>
      </div>
      <div class="tile">
        <span class="tile-label">ออกให้ฟรี (ชดเชย)</span>
        <b>฿{{ money(summary.complimentary_total) }}</b>
      </div>
    </div>

    <!-- ออกบัตรชดเชย -->
    <form v-if="showIssue" class="panel" @submit.prevent="issue">
      <div class="field-row">
        <div class="field">
          <label>มูลค่า (บาท)</label>
          <input v-model.number="issueForm.amount" type="number" min="1" class="input" />
        </div>
        <div class="field">
          <label>ชื่อผู้รับ (แสดงบนบัตร)</label>
          <input v-model="issueForm.recipient_name" class="input" maxlength="100" />
        </div>
      </div>
      <div class="field">
        <label>ข้อความถึงลูกค้า (ไม่บังคับ)</label>
        <input v-model="issueForm.message" class="input" maxlength="300" placeholder="เช่น ขอโทษที่ทริปครั้งก่อนต้องเลื่อนนะครับ" />
      </div>
      <div class="field">
        <label>เหตุผล (บันทึกภายใน)</label>
        <input v-model="issueForm.note" class="input" maxlength="300" placeholder="เช่น ชดเชยทริป LLK-20261001-0001" />
      </div>
      <p class="hint">บัตรที่ออกที่นี่ใช้ได้ทันทีและไม่นับเป็นยอดขาย ส่งรหัสหรือลิงก์ให้ลูกค้าหลังกดออกบัตร</p>
      <button type="submit" class="primary-btn" :disabled="busy || !(issueForm.amount > 0) || !issueForm.note.trim()">
        <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-gift'"></i> ออกบัตร
      </button>
    </form>

    <div class="toolbar">
      <div class="tabs">
        <button v-for="t in tabs" :key="t.key ?? 'all'" class="tab" :class="{ active: status === t.key }" @click="setStatus(t.key)">
          {{ t.label }}
          <span v-if="t.key === 'under_review' && summary.under_review_count" class="count alert">{{ summary.under_review_count }}</span>
        </button>
      </div>
      <input v-model="q" class="input search" placeholder="ค้นหารหัส / ชื่อ / อีเมล / เบอร์" @input="onSearch" />
    </div>

    <div v-if="loading" class="empty-hint">กำลังโหลด...</div>
    <div v-else-if="!items.length" class="empty-hint">ไม่มีรายการ</div>

    <div v-else class="list">
      <button v-for="v in items" :key="v.id" class="row" :class="{ selected: detail?.id === v.id }" @click="open(v)">
        <div class="row-main">
          <span class="code">{{ v.display_code }}</span>
          <span class="badge" :class="v.display_status">{{ statusLabel(v.display_status) }}</span>
          <span v-if="v.is_complimentary" class="badge comp">ออกให้ฟรี</span>
        </div>
        <div class="row-sub">
          {{ v.purchaser?.name || (v.is_complimentary ? 'ทีมงาน' : '-') }}
          <template v-if="v.recipient_name"> → {{ v.recipient_name }}</template>
          <template v-if="v.owner && v.owner.id !== v.purchaser?.id"> · เจ้าของ: {{ v.owner.name }}</template>
        </div>
        <div class="row-amount">
          <b>฿{{ money(v.amount) }}</b>
          <span v-if="v.status === 'active'">คงเหลือ ฿{{ money(v.balance) }}</span>
          <span>{{ formatDateTime(v.created_at) }}</span>
        </div>
      </button>
    </div>

    <div v-if="lastPage > 1" class="pager">
      <button class="ghost-btn sm" :disabled="page <= 1" @click="go(page - 1)">ก่อนหน้า</button>
      <span>หน้า {{ page }} / {{ lastPage }}</span>
      <button class="ghost-btn sm" :disabled="page >= lastPage" @click="go(page + 1)">ถัดไป</button>
    </div>

    <!-- รายละเอียด -->
    <div v-if="detail" class="overlay" @click.self="detail = null">
      <div class="drawer">
        <div class="drawer-head">
          <div>
            <h3>{{ detail.display_code }}</h3>
            <span class="badge" :class="detail.display_status">{{ statusLabel(detail.display_status) }}</span>
          </div>
          <button class="ghost-btn sm" @click="detail = null"><i class="fas fa-times"></i></button>
        </div>

        <div class="kv">
          <div><span>มูลค่า</span><b>฿{{ money(detail.amount) }}</b></div>
          <div><span>คงเหลือ</span><b>฿{{ money(detail.balance) }}</b></div>
          <div><span>ผู้ซื้อ</span><b>{{ person(detail.purchaser) || (detail.is_complimentary ? 'ทีมงานออกให้' : '-') }}</b></div>
          <div><span>เจ้าของบัตร</span><b>{{ person(detail.owner) || 'ยังไม่มีใครเพิ่มเข้าบัญชี' }}</b></div>
          <div v-if="detail.recipient_name"><span>มอบให้</span><b>{{ detail.recipient_name }}</b></div>
          <div v-if="detail.from_name"><span>จาก</span><b>{{ detail.from_name }}</b></div>
          <div v-if="detail.paid_at"><span>ชำระเมื่อ</span><b>{{ formatDateTime(detail.paid_at) }}</b></div>
          <div v-if="detail.expires_at"><span>ใช้ได้ถึง</span><b>{{ formatDateTime(detail.expires_at) }}</b></div>
          <div v-if="detail.review_note"><span>หมายเหตุทีมงาน</span><b>{{ detail.review_note }}<template v-if="detail.reviewed_by"> ({{ detail.reviewed_by }})</template></b></div>
        </div>

        <p v-if="detail.message" class="message">"{{ detail.message }}"</p>

        <div v-if="detail.share_url" class="share">
          <input :value="detail.share_url" class="input" readonly />
          <button class="ghost-btn sm" @click="copy(detail.share_url)"><i class="fas fa-copy"></i> คัดลอกลิงก์</button>
        </div>

        <!-- สลิป -->
        <div v-if="detail.has_slip" class="slip">
          <a v-if="detail.slip_url" :href="detail.slip_url" target="_blank" rel="noopener">
            <img :src="detail.slip_url" alt="สลิป" />
          </a>
          <div class="slip-ocr">
            <b>ผลอ่านสลิปอัตโนมัติ</b>
            <span>สถานะ: {{ detail.slip_ocr_status || '-' }}</span>
            <span v-if="detail.slip_ocr">ยอดที่อ่านได้: {{ detail.slip_ocr.amount != null ? '฿' + money(detail.slip_ocr.amount) : '-' }}</span>
            <span v-if="detail.slip_ocr?.bank">ธนาคาร: {{ detail.slip_ocr.bank }}</span>
            <span v-if="detail.slip_ocr?.date">โอนเมื่อ: {{ detail.slip_ocr.date }} {{ detail.slip_ocr.time || '' }}</span>
          </div>
        </div>

        <!-- การกระทำ -->
        <div class="actions">
          <template v-if="['under_review', 'rejected', 'pending'].includes(detail.status)">
            <button class="primary-btn sm" :disabled="busy" @click="approve"><i class="fas fa-check"></i> ยืนยันว่าได้รับเงินแล้ว</button>
            <button v-if="detail.status === 'under_review'" class="ghost-btn sm danger" :disabled="busy" @click="reject"><i class="fas fa-ban"></i> สลิปไม่ผ่าน</button>
          </template>
          <template v-if="detail.status === 'active'">
            <button class="ghost-btn sm" :disabled="busy" @click="adjust"><i class="fas fa-pen"></i> ปรับยอด</button>
            <button class="ghost-btn sm danger" :disabled="busy" @click="cancelVoucher"><i class="fas fa-ban"></i> ยกเลิกบัตร</button>
          </template>
        </div>

        <h4>สมุดบัญชีของบัตร</h4>
        <div v-if="!detail.transactions?.length" class="empty-hint sm">ยังไม่มีรายการ</div>
        <table v-else class="ledger">
          <thead>
            <tr><th>เมื่อ</th><th>รายการ</th><th class="num">ยอด</th><th class="num">คงเหลือ</th></tr>
          </thead>
          <tbody>
            <tr v-for="t in detail.transactions" :key="t.id">
              <td>{{ formatDateTime(t.created_at) }}</td>
              <td>
                <b>{{ txLabel(t.type) }}</b>
                <span v-if="t.booking_ref" class="ref">{{ t.booking_ref }}</span>
                <div v-if="t.note" class="note">{{ t.note }}<template v-if="t.actor"> · {{ t.actor }}</template></div>
              </td>
              <td class="num" :class="t.amount < 0 ? 'neg' : 'pos'">{{ t.amount > 0 ? '+' : '' }}{{ money(t.amount) }}</td>
              <td class="num">{{ money(t.balance_after) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// บัตรของขวัญ (หลังบ้าน) — ดู App\Services\GiftVoucherService
// ยอดคงเหลือเปลี่ยนผ่าน endpoint ของหน้านี้เท่านั้น ทุกครั้งมีแถวในสมุดบัญชี

const toast = useToast();
const swal = useSwal();

const tabs = [
  { key: 'under_review', label: 'รอตรวจสลิป' },
  { key: 'active', label: 'ใช้งานอยู่' },
  { key: 'pending', label: 'ยังไม่ได้จ่าย' },
  { key: 'rejected', label: 'สลิปไม่ผ่าน' },
  { key: 'cancelled', label: 'ยกเลิก' },
  { key: null, label: 'ทั้งหมด' },
];

const status = ref(null);
const q = ref('');
const items = ref([]);
const summary = ref({});
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const busy = ref(false);
const detail = ref(null);
const showIssue = ref(false);
const issueForm = ref({ amount: 500, recipient_name: '', message: '', note: '' });
let searchTimer = null;

const statusLabel = (s) => ({
  pending: 'ยังไม่ได้จ่าย',
  under_review: 'รอตรวจสลิป',
  active: 'ใช้งานได้',
  used_up: 'ใช้ครบแล้ว',
  expired: 'หมดอายุ',
  rejected: 'สลิปไม่ผ่าน',
  cancelled: 'ยกเลิก',
})[s] || s;

const txLabel = (t) => ({
  purchase: 'เติมเงินเข้าบัตร',
  redeem: 'ใช้จองทริป',
  restore: 'คืนยอดเข้าบัตร',
  adjust: 'ทีมงานปรับยอด',
})[t] || t;

const money = (n) => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });
const person = (p) => (p ? [p.name, p.phone || p.email].filter(Boolean).join(' · ') : '');

function formatDateTime(d) {
  if (!d) return '';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleString('th-TH', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}

async function load() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (status.value) params.status = status.value;
    if (q.value.trim()) params.q = q.value.trim();
    const res = await api.get('/admin/gift-vouchers', { params });
    items.value = res.data.data || [];
    summary.value = res.data.meta?.summary || {};
    lastPage.value = res.data.meta?.last_page || 1;
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายการไม่สำเร็จ');
  } finally {
    loading.value = false;
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

async function open(v) {
  try {
    const res = await api.get(`/admin/gift-vouchers/${v.id}`);
    detail.value = res.data.data;
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

async function approve() {
  const needsNote = !detail.value.has_slip;
  const res = await swal.confirm({
    input: 'text',
    title: 'ยืนยันว่าได้รับเงินแล้ว?',
    text: `บัตร ฿${money(detail.value.amount)} จะใช้ได้ทันทีและแจ้งลูกค้า`,
    inputPlaceholder: needsNote ? 'ยืนยันจากอะไร เช่น สลิปที่ส่งมาทางแชท (จำเป็น)' : 'หมายเหตุ (ไม่บังคับ)',
    confirmText: 'ยืนยัน',
  });
  if (!res.isConfirmed) return;
  if (needsNote && !String(res.value || '').trim()) {
    toast.error('กรุณาระบุหมายเหตุ');
    return;
  }
  return act(() => api.post(`/admin/gift-vouchers/${detail.value.id}/approve`, { note: res.value || null }), 'ยืนยันแล้ว');
}

async function reject() {
  const res = await swal.confirm({
    input: 'text',
    title: 'สลิปไม่ผ่าน',
    text: 'ลูกค้าจะเห็นข้อความนี้และส่งสลิปใหม่ได้',
    inputPlaceholder: 'เช่น ยอดโอนไม่ตรง / ไม่พบรายการเงินเข้า',
    confirmText: 'แจ้งลูกค้า',
  });
  if (!res.isConfirmed || !String(res.value || '').trim()) return;
  return act(() => api.post(`/admin/gift-vouchers/${detail.value.id}/reject`, { note: res.value }), 'แจ้งลูกค้าแล้ว');
}

async function adjust() {
  const amount = await swal.confirm({
    input: 'text',
    title: 'ปรับยอดบัตร',
    text: `คงเหลือ ฿${money(detail.value.balance)} — ใส่ยอดบวกเพื่อเพิ่ม ลบเพื่อลด`,
    inputPlaceholder: 'เช่น -300 หรือ 200',
    confirmText: 'ถัดไป',
  });
  if (!amount.isConfirmed) return;
  const delta = Number(amount.value);
  if (!delta) {
    toast.error('กรุณาใส่ยอดเป็นตัวเลขที่ไม่ใช่ 0');
    return;
  }
  const note = await swal.confirm({ input: 'text', title: 'เหตุผลที่ปรับยอด', inputPlaceholder: 'บันทึกไว้ในสมุดบัญชีของบัตร', confirmText: 'บันทึก' });
  if (!note.isConfirmed || !String(note.value || '').trim()) return;
  return act(() => api.post(`/admin/gift-vouchers/${detail.value.id}/adjust`, { amount: delta, note: note.value }), 'ปรับยอดแล้ว');
}

async function cancelVoucher() {
  const res = await swal.confirm({
    input: 'text',
    title: 'ยกเลิกบัตรนี้?',
    text: `ยอดคงเหลือ ฿${money(detail.value.balance)} จะเป็นศูนย์ — การจองที่ใช้บัตรไปแล้วไม่ถูกแตะ`,
    inputPlaceholder: 'เหตุผล เช่น คืนเงินค่าบัตรให้ลูกค้าทางบัญชีแล้ว',
    confirmText: 'ยกเลิกบัตร',
  });
  if (!res.isConfirmed || !String(res.value || '').trim()) return;
  return act(() => api.post(`/admin/gift-vouchers/${detail.value.id}/cancel`, { note: res.value }), 'ยกเลิกแล้ว');
}

async function issue() {
  if (busy.value) return;
  busy.value = true;
  try {
    const res = await api.post('/admin/gift-vouchers', {
      amount: issueForm.value.amount,
      recipient_name: issueForm.value.recipient_name || null,
      message: issueForm.value.message || null,
      note: issueForm.value.note,
    });
    toast.success('ออกบัตรแล้ว');
    showIssue.value = false;
    issueForm.value = { amount: 500, recipient_name: '', message: '', note: '' };
    detail.value = res.data.data;
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ออกบัตรไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast.success('คัดลอกแล้ว');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ');
  }
}

onMounted(load);
</script>

<style scoped>
.gv-page { display: flex; flex-direction: column; gap: 16px; }
.gv-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.gv-head h2 { margin: 0; font-size: 20px; font-weight: 800; color: #1f2937; }
.gv-head h2 i { color: #2D7A4F; margin-right: 6px; }
.sub { margin: 4px 0 0; font-size: 13px; color: #6b7280; }

.tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.tile { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 2px; }
.tile-label { font-size: 12px; font-weight: 700; color: #6b7280; }
.tile b { font-size: 20px; color: #111827; }
.tile-note { font-size: 11.5px; color: #9ca3af; }

.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.field-row { display: flex; gap: 12px; flex-wrap: wrap; }
.field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 200px; }
.field label { font-size: 12px; font-weight: 700; color: #6b7280; }
.input { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 9px 12px; font-size: 14px; outline: none; font-family: inherit; background: #fff; }
.input:focus { border-color: #2D7A4F; }
.hint { margin: 0; font-size: 12.5px; color: #6b7280; }

.primary-btn { border: none; background: #2D7A4F; color: #fff; border-radius: 10px; padding: 10px 16px; font-size: 14px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; align-self: flex-start; }
.primary-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.ghost-btn { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.ghost-btn.danger:hover { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
.primary-btn.sm, .ghost-btn.sm { padding: 7px 12px; font-size: 13px; }

.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
.search { max-width: 320px; }
.tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.tab { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.tab.active { background: #ecfdf5; border-color: #2D7A4F; color: #2D7A4F; }
.count { background: #f3f4f6; border-radius: 999px; padding: 0 7px; font-size: 12px; }
.count.alert { background: #fee2e2; color: #b91c1c; }

.empty-hint { padding: 32px 16px; color: #9ca3af; font-size: 13px; text-align: center; background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; }
.empty-hint.sm { padding: 14px; }

.list { display: flex; flex-direction: column; gap: 8px; }
.row { text-align: left; font-family: inherit; background: #fff; border: 1px solid #e9edf0; border-radius: 12px; padding: 12px 14px; cursor: pointer; display: grid; grid-template-columns: 1fr auto; gap: 4px 16px; }
.row:hover, .row.selected { border-color: #2D7A4F; }
.row-main { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.code { font-weight: 800; letter-spacing: 1px; color: #111827; }
.row-sub { grid-column: 1; font-size: 13px; color: #6b7280; }
.row-amount { grid-column: 2; grid-row: 1 / span 2; display: flex; flex-direction: column; align-items: flex-end; font-size: 12px; color: #6b7280; }
.row-amount b { font-size: 16px; color: #111827; }

.badge { font-size: 11.5px; font-weight: 800; border-radius: 999px; padding: 2px 9px; background: #f3f4f6; color: #4b5563; }
.badge.active { background: #ecfdf5; color: #2D7A4F; }
.badge.under_review { background: #fff7ed; color: #c2410c; }
.badge.pending { background: #f3f4f6; color: #6b7280; }
.badge.rejected, .badge.cancelled { background: #fef2f2; color: #b91c1c; }
.badge.used_up, .badge.expired { background: #f3f4f6; color: #9ca3af; }
.badge.comp { background: #eff6ff; color: #2563eb; }

.pager { display: flex; gap: 12px; align-items: center; justify-content: center; font-size: 13px; color: #6b7280; }

.overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.35); z-index: 60; display: flex; justify-content: flex-end; }
.drawer { width: min(560px, 100%); height: 100%; overflow-y: auto; background: #fff; padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.drawer-head { display: flex; justify-content: space-between; align-items: flex-start; }
.drawer-head h3 { margin: 0 0 4px; font-size: 18px; font-weight: 800; letter-spacing: 1px; }
.drawer h4 { margin: 6px 0 0; font-size: 14px; font-weight: 800; color: #374151; }
.kv { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kv div { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
.kv span { color: #6b7280; font-size: 12px; font-weight: 700; }
.message { margin: 0; background: #f9fafb; border-radius: 10px; padding: 10px 12px; font-style: italic; font-size: 13.5px; color: #374151; }
.share { display: flex; gap: 8px; }
.slip { display: flex; gap: 12px; align-items: flex-start; background: #f9fafb; border-radius: 12px; padding: 10px; }
.slip img { width: 140px; border-radius: 8px; border: 1px solid #e5e7eb; }
.slip-ocr { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #374151; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }

.ledger { width: 100%; border-collapse: collapse; font-size: 13px; }
.ledger th { text-align: left; font-size: 12px; color: #6b7280; padding: 6px 4px; border-bottom: 1px solid #e5e7eb; }
.ledger td { padding: 8px 4px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.ledger .num { text-align: right; white-space: nowrap; }
.ledger .neg { color: #b91c1c; }
.ledger .pos { color: #2D7A4F; }
.ledger .ref { margin-left: 6px; font-size: 12px; color: #2563eb; }
.ledger .note { font-size: 12px; color: #6b7280; }
</style>
