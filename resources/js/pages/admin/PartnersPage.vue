<template>
  <div class="pt-page">
    <div class="pt-head">
      <div>
        <h2><i class="fas fa-handshake"></i> พาร์ทเนอร์ช่วยขาย</h2>
        <p class="sub">คนช่วยขายแชร์ลิงก์/โค้ดของตัวเอง ลูกค้าจ่ายเงินเข้าบริษัทตรง คอมอนุมัติเองหลังวันเดินทางกลับ (เมื่อชำระครบ) แล้วกดบันทึกการโอนที่นี่</p>
      </div>
      <button class="primary-btn" @click="toggleCreate">
        <i class="fas" :class="showCreate ? 'fa-times' : 'fa-plus'"></i>
        {{ showCreate ? 'ปิด' : 'เปิดบัญชีพาร์ทเนอร์' }}
      </button>
    </div>

    <div class="tiles">
      <div class="tile">
        <span class="tile-label">รอโอน (อนุมัติแล้ว)</span>
        <b>฿{{ money(summary.approved) }}</b>
        <span class="tile-note">ทริปจบและลูกค้าจ่ายครบแล้ว</span>
      </div>
      <div class="tile">
        <span class="tile-label">รอเดินทาง</span>
        <b>฿{{ money(summary.pending) }}</b>
        <span class="tile-note">ยังอาจเปลี่ยน ถ้ายกเลิก/ปรับยอด</span>
      </div>
      <div class="tile">
        <span class="tile-label">โอนไปแล้วทั้งหมด</span>
        <b>฿{{ money(summary.paid) }}</b>
      </div>
      <div class="tile">
        <span class="tile-label">ใบจองจากพาร์ทเนอร์</span>
        <b>{{ summary.bookings || 0 }}</b>
        <span class="tile-note">{{ summary.partners || 0 }} พาร์ทเนอร์</span>
      </div>
    </div>

    <!-- เปิดบัญชี -->
    <form v-if="showCreate" class="panel" @submit.prevent="create">
      <div class="field">
        <label>บัญชีผู้ใช้ของพาร์ทเนอร์ (ต้องสมัครสมาชิกก่อน เพื่อเข้าดูยอดของตัวเองได้)</label>
        <div v-if="form.user" class="picked">
          <span><b>{{ form.user.name }}</b> · {{ form.user.phone || form.user.email }}</span>
          <button type="button" class="ghost-btn sm" @click="form.user = null">เปลี่ยน</button>
        </div>
        <template v-else>
          <input v-model="userQuery" class="input" placeholder="ค้นหาชื่อ / เบอร์ / อีเมล" @input="searchUsers" />
          <div v-if="candidates.length" class="candidates">
            <button
              v-for="u in candidates"
              :key="u.id"
              type="button"
              class="candidate"
              :disabled="!!u.partner_code"
              @click="pickUser(u)"
            >
              <b>{{ u.name }}</b>
              <span>{{ u.phone || u.email }}</span>
              <span v-if="u.partner_code" class="badge">เป็นพาร์ทเนอร์แล้ว ({{ u.partner_code }})</span>
            </button>
          </div>
        </template>
      </div>
      <div class="field-row">
        <div class="field">
          <label>ชื่อที่แสดง (เช่น ชื่อเพจ)</label>
          <input v-model="form.display_name" class="input" maxlength="100" />
        </div>
        <div class="field">
          <label>โค้ด (เว้นว่าง = สุ่มให้)</label>
          <input v-model="form.code" class="input code-input" maxlength="20" placeholder="เช่น PAGEPA" />
        </div>
      </div>
      <RateFields v-model:type="form.commission_type" v-model:rate="form.commission_rate" />
      <AccountFields v-model="form.payout_account" />
      <div class="field">
        <label>บันทึกภายใน (ไม่บังคับ)</label>
        <input v-model="form.note" class="input" maxlength="1000" />
      </div>
      <button type="submit" class="primary-btn" :disabled="busy || !form.user || !(form.commission_rate > 0)">
        <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-check'"></i> เปิดบัญชี
      </button>
    </form>

    <div class="toolbar">
      <div class="tabs">
        <button v-for="t in tabs" :key="t.key ?? 'all'" class="tab" :class="{ active: status === t.key }" @click="setStatus(t.key)">
          {{ t.label }}
        </button>
      </div>
      <input v-model="q" class="input search" placeholder="ค้นหาโค้ด / ชื่อ / เบอร์" @input="onSearch" />
    </div>

    <div v-if="loading" class="empty-hint">กำลังโหลด...</div>
    <div v-else-if="!items.length" class="empty-hint">ยังไม่มีพาร์ทเนอร์ — กด "เปิดบัญชีพาร์ทเนอร์" เพื่อเริ่ม</div>

    <div v-else class="list">
      <button v-for="p in items" :key="p.id" class="row" :class="{ selected: detail?.id === p.id }" @click="open(p)">
        <div class="row-main">
          <span class="code">{{ p.code }}</span>
          <b class="name">{{ p.display_name }}</b>
          <span class="badge" :class="p.status">{{ p.status === 'active' ? 'เปิดอยู่' : 'หยุดชั่วคราว' }}</span>
          <span class="badge rate">{{ p.rate_label }}</span>
          <span v-if="!p.has_payout_account" class="badge warn">ยังไม่มีบัญชีรับเงิน</span>
        </div>
        <div class="row-sub">{{ p.user?.name }} · {{ p.user?.phone || p.user?.email }} · {{ p.summary.bookings }} ใบจอง / {{ p.summary.seats }} ที่นั่ง</div>
        <div class="row-amount">
          <b :class="{ due: p.summary.approved > 0 }">฿{{ money(p.summary.approved) }}</b>
          <span>รอโอน</span>
          <span>รอเดินทาง ฿{{ money(p.summary.pending) }}</span>
        </div>
      </button>
    </div>

    <!-- รายละเอียด -->
    <div v-if="detail" class="overlay" @click.self="detail = null">
      <div class="drawer">
        <div class="drawer-head">
          <div>
            <h3>{{ detail.display_name }}</h3>
            <span class="code">{{ detail.code }}</span>
            <span class="badge" :class="detail.status">{{ detail.status === 'active' ? 'เปิดอยู่' : 'หยุดชั่วคราว' }}</span>
          </div>
          <button class="ghost-btn sm" @click="detail = null"><i class="fas fa-times"></i></button>
        </div>

        <div class="kv">
          <div><span>รอโอน</span><b class="due">฿{{ money(detail.summary.approved) }}</b></div>
          <div><span>รอเดินทาง</span><b>฿{{ money(detail.summary.pending) }}</b></div>
          <div><span>โอนแล้ว</span><b>฿{{ money(detail.summary.paid) }}</b></div>
          <div><span>อัตราคอม</span><b>{{ detail.rate_label }}</b></div>
          <div><span>บัญชีผู้ใช้</span><b>{{ detail.user?.name }} · {{ detail.user?.phone || detail.user?.email }}</b></div>
          <div>
            <span>บัญชีรับเงิน</span>
            <b v-if="detail.payout_account?.number">{{ detail.payout_account.bank }} {{ detail.payout_account.number }}<br />{{ detail.payout_account.name }}</b>
            <b v-else class="warn-text">ยังไม่มี — พาร์ทเนอร์กรอกเองในแอป/เว็บได้</b>
          </div>
        </div>
        <p v-if="detail.note" class="message">{{ detail.note }}</p>

        <div class="share">
          <input :value="detail.share_url" class="input" readonly />
          <button class="ghost-btn sm" @click="copy(detail.share_url)"><i class="fas fa-copy"></i> ลิงก์เว็บ</button>
          <button v-if="detail.liff_url" class="ghost-btn sm" @click="copy(detail.liff_url)"><i class="fab fa-line"></i> ลิงก์ LINE</button>
        </div>

        <!-- โอนคอม -->
        <div v-if="detail.summary.approved > 0" class="pay-box">
          <b>โอนคอม ฿{{ money(detail.summary.approved) }}</b>
          <span class="hint">รวมทุกใบที่อนุมัติแล้ว (หักยอดคืนจากใบที่คืนเงินหลังจ่ายแล้ว) — โอนเงินจริงก่อน แล้วบันทึกที่นี่ พาร์ทเนอร์จะได้รับแจ้งเตือน</span>
          <div class="field-row">
            <input v-model="payForm.reference" class="input" placeholder="เลขอ้างอิง/วันที่โอน (ไม่บังคับ)" maxlength="100" />
            <input ref="slipInput" type="file" accept="image/*" class="input" @change="payForm.slip = $event.target.files[0] || null" />
          </div>
          <button class="primary-btn sm" :disabled="busy" @click="payout">
            <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-money-bill-transfer'"></i> บันทึกว่าโอนแล้ว
          </button>
        </div>

        <!-- แก้ไข -->
        <details class="edit-box">
          <summary>แก้ไขอัตรา / สถานะ / บัญชีรับเงิน</summary>
          <div class="field-row">
            <div class="field">
              <label>ชื่อที่แสดง</label>
              <input v-model="edit.display_name" class="input" maxlength="100" />
            </div>
            <div class="field">
              <label>โค้ด</label>
              <input v-model="edit.code" class="input code-input" maxlength="20" />
            </div>
          </div>
          <RateFields v-model:type="edit.commission_type" v-model:rate="edit.commission_rate" />
          <p class="hint">อัตราใหม่ใช้กับใบจองที่ยืนยันหลังจากนี้ — ใบเดิมคงอัตราตอนขาย</p>
          <AccountFields v-model="edit.payout_account" />
          <div class="field">
            <label>บันทึกภายใน</label>
            <input v-model="edit.note" class="input" maxlength="1000" />
          </div>
          <label class="check">
            <input v-model="edit.paused" type="checkbox" />
            หยุดชั่วคราว (ไม่รับใบจองใหม่ ใบเดิมยังได้คอมตามปกติ)
          </label>
          <button class="primary-btn sm" :disabled="busy" @click="save"><i class="fas fa-save"></i> บันทึก</button>
        </details>

        <h4>ค่าคอมรายใบจอง</h4>
        <div v-if="!detail.commissions?.length" class="empty-hint sm">ยังไม่มีใบจองที่ยืนยันแล้ว</div>
        <table v-else class="ledger">
          <thead>
            <tr><th>ใบจอง</th><th>สถานะ</th><th class="num">คอม</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="c in detail.commissions" :key="c.id">
              <td>
                <b>{{ c.booking_ref }}</b>
                <div class="note">{{ c.trip_title }} · {{ formatDate(c.departure_date) }} · {{ c.seats }} ที่นั่ง</div>
                <div class="note">ฐาน ฿{{ money(c.base_amount) }} × {{ c.rate_label }}</div>
                <div v-if="c.void_reason" class="note">{{ c.void_reason }}</div>
              </td>
              <td><span class="badge" :class="c.status">{{ statusLabel(c) }}</span></td>
              <td class="num" :class="c.amount < 0 ? 'neg' : ''">{{ money(c.amount) }}</td>
              <td class="num">
                <button v-if="['pending', 'approved'].includes(c.status) && c.type === 'commission'" class="ghost-btn xs danger" :disabled="busy" @click="voidCommission(c)">ตัดทิ้ง</button>
              </td>
            </tr>
          </tbody>
        </table>

        <h4>ประวัติการโอน</h4>
        <div v-if="!detail.payouts?.length" class="empty-hint sm">ยังไม่เคยโอน</div>
        <table v-else class="ledger">
          <tbody>
            <tr v-for="p in detail.payouts" :key="p.id">
              <td>
                <b>{{ formatDateTime(p.paid_at) }}</b>
                <div class="note">{{ p.commission_count }} ใบจอง<template v-if="p.reference"> · {{ p.reference }}</template><template v-if="p.paid_by"> · {{ p.paid_by }}</template></div>
              </td>
              <td><a v-if="p.slip_url" :href="p.slip_url" target="_blank" rel="noopener">สลิป</a></td>
              <td class="num pos">฿{{ money(p.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineComponent, h, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// พาร์ทเนอร์ช่วยขาย (หลังบ้าน) — ดู App\Services\PartnerService
// ยอดคอมคำนวณฝั่งเซิร์ฟเวอร์ทั้งหมด หน้านี้แค่แสดงและสั่งงาน

const toast = useToast();
const swal = useSwal();

const tabs = [
  { key: null, label: 'ทั้งหมด' },
  { key: 'active', label: 'เปิดอยู่' },
  { key: 'paused', label: 'หยุดชั่วคราว' },
];

const status = ref(null);
const q = ref('');
const items = ref([]);
const summary = ref({});
const loading = ref(false);
const busy = ref(false);
const detail = ref(null);
const edit = ref({});
const showCreate = ref(false);
const userQuery = ref('');
const candidates = ref([]);
const slipInput = ref(null);
const payForm = ref({ reference: '', slip: null });
const blankForm = () => ({
  user: null,
  display_name: '',
  code: '',
  commission_type: 'percent',
  commission_rate: 10,
  payout_account: { bank: '', number: '', name: '' },
  note: '',
});
const form = ref(blankForm());
let searchTimer = null;
let userTimer = null;

const money = (n) => Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

const statusLabel = (c) => {
  if (c.type === 'clawback') return 'หักคืน';
  return { pending: 'รอเดินทาง', approved: 'รอโอน', paid: 'โอนแล้ว', void: 'ตัดทิ้ง' }[c.status] || c.status;
};

function formatDate(d) {
  if (!d) return '';
  const date = new Date(`${d}T00:00:00`);
  return Number.isNaN(date.getTime()) ? d : date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });
}

function formatDateTime(d) {
  if (!d) return '';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleString('th-TH', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}

// ช่องอัตราคอม — ใช้ทั้งฟอร์มเปิดบัญชีและฟอร์มแก้ไข
const RateFields = defineComponent({
  props: { type: String, rate: [Number, String] },
  emits: ['update:type', 'update:rate'],
  setup(props, { emit }) {
    return () => h('div', { class: 'field-row' }, [
      h('div', { class: 'field' }, [
        h('label', 'คิดคอมแบบ'),
        h('select', {
          class: 'input',
          value: props.type,
          onChange: (e) => emit('update:type', e.target.value),
        }, [
          h('option', { value: 'percent' }, '% ของค่าทริป'),
          h('option', { value: 'per_seat' }, 'บาทต่อที่นั่ง'),
        ]),
      ]),
      h('div', { class: 'field' }, [
        h('label', props.type === 'per_seat' ? 'บาทต่อที่นั่ง' : 'เปอร์เซ็นต์ (%)'),
        h('input', {
          class: 'input',
          type: 'number',
          min: 0,
          step: props.type === 'per_seat' ? 10 : 0.5,
          value: props.rate,
          onInput: (e) => emit('update:rate', e.target.value === '' ? '' : Number(e.target.value)),
        }),
      ]),
    ]);
  },
});

const AccountFields = defineComponent({
  props: { modelValue: Object },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const set = (key, value) => emit('update:modelValue', { ...(props.modelValue || {}), [key]: value });
    const input = (key, label, placeholder) => h('div', { class: 'field' }, [
      h('label', label),
      h('input', {
        class: 'input',
        value: props.modelValue?.[key] || '',
        placeholder,
        onInput: (e) => set(key, e.target.value),
      }),
    ]);
    return () => h('div', { class: 'field-row' }, [
      input('bank', 'ธนาคาร', 'เช่น กสิกรไทย'),
      input('number', 'เลขบัญชี / พร้อมเพย์', ''),
      input('name', 'ชื่อบัญชี', ''),
    ]);
  },
});

async function load() {
  loading.value = true;
  try {
    const params = {};
    if (status.value) params.status = status.value;
    if (q.value.trim()) params.q = q.value.trim();
    const res = await api.get('/admin/partners', { params });
    items.value = res.data.data?.partners || [];
    summary.value = res.data.data?.summary || {};
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายการไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

function setStatus(key) {
  status.value = key;
  load();
}

function onSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(load, 300);
}

function toggleCreate() {
  showCreate.value = !showCreate.value;
  if (showCreate.value) {
    form.value = blankForm();
    userQuery.value = '';
    candidates.value = [];
  }
}

function searchUsers() {
  clearTimeout(userTimer);
  const term = userQuery.value.trim();
  if (term.length < 2) {
    candidates.value = [];
    return;
  }
  userTimer = setTimeout(async () => {
    try {
      const res = await api.get('/admin/partners/candidates', { params: { q: term } });
      candidates.value = res.data.data || [];
    } catch {
      candidates.value = [];
    }
  }, 300);
}

function pickUser(u) {
  form.value.user = u;
  if (!form.value.display_name) form.value.display_name = u.name;
  candidates.value = [];
}

async function create() {
  if (busy.value) return;
  busy.value = true;
  try {
    const f = form.value;
    const res = await api.post('/admin/partners', {
      user_id: f.user.id,
      display_name: f.display_name || null,
      code: f.code || null,
      commission_type: f.commission_type,
      commission_rate: f.commission_rate,
      payout_account: f.payout_account?.number ? f.payout_account : null,
      note: f.note || null,
    });
    toast.success('เปิดบัญชีพาร์ทเนอร์แล้ว');
    showCreate.value = false;
    await load();
    openDetail(res.data.data);
  } catch (e) {
    toast.error(e.response?.data?.message || 'เปิดบัญชีไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

function openDetail(data) {
  detail.value = data;
  edit.value = {
    display_name: data.display_name,
    code: data.code,
    commission_type: data.commission_type,
    commission_rate: data.commission_rate,
    payout_account: { bank: '', number: '', name: '', ...(data.payout_account || {}) },
    note: data.note || '',
    paused: data.status === 'paused',
  };
  payForm.value = { reference: '', slip: null };
}

async function open(p) {
  try {
    const res = await api.get(`/admin/partners/${p.id}`);
    openDetail(res.data.data);
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายละเอียดไม่สำเร็จ');
  }
}

async function save() {
  if (busy.value) return;
  busy.value = true;
  try {
    const e = edit.value;
    const res = await api.put(`/admin/partners/${detail.value.id}`, {
      display_name: e.display_name,
      code: e.code,
      commission_type: e.commission_type,
      commission_rate: e.commission_rate,
      payout_account: e.payout_account?.number ? e.payout_account : null,
      note: e.note || null,
      status: e.paused ? 'paused' : 'active',
    });
    toast.success('บันทึกแล้ว');
    openDetail(res.data.data);
    await load();
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function payout() {
  const amount = money(detail.value.summary.approved);
  const res = await swal.confirm({
    title: `บันทึกว่าโอน ฿${amount} แล้ว?`,
    text: 'ทำหลังโอนเงินจริงเท่านั้น — ทุกใบที่รอโอนจะเปลี่ยนเป็น "โอนแล้ว"',
    confirmText: 'บันทึก',
  });
  if (!res.isConfirmed || busy.value) return;
  busy.value = true;
  try {
    const body = new FormData();
    if (payForm.value.reference) body.append('reference', payForm.value.reference);
    if (payForm.value.slip) body.append('slip', payForm.value.slip);
    const r = await api.post(`/admin/partners/${detail.value.id}/payouts`, body);
    toast.success(r.data?.message || 'บันทึกแล้ว');
    if (slipInput.value) slipInput.value.value = '';
    openDetail(r.data.data.partner);
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function voidCommission(c) {
  const res = await swal.confirm({
    input: 'text',
    title: `ตัดคอมของ ${c.booking_ref}?`,
    text: 'พาร์ทเนอร์จะเห็นเหตุผลนี้',
    inputPlaceholder: 'เช่น จองให้คนในบ้านเพื่อเอาคอม',
    confirmText: 'ตัดทิ้ง',
  });
  if (!res.isConfirmed || !String(res.value || '').trim() || busy.value) return;
  busy.value = true;
  try {
    await api.post(`/admin/partner-commissions/${c.id}/void`, { reason: res.value });
    toast.success('ตัดคอมแล้ว');
    await open(detail.value);
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
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
.pt-page { display: flex; flex-direction: column; gap: 16px; }
.pt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.pt-head h2 { margin: 0; font-size: 20px; font-weight: 800; color: #1f2937; }
.pt-head h2 i { color: #2D7A4F; margin-right: 6px; }
.sub { margin: 4px 0 0; font-size: 13px; color: #6b7280; max-width: 720px; }

.tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(200px, 100%), 1fr)); gap: 12px; }
.tile { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 2px; }
.tile-label { font-size: 12px; font-weight: 700; color: #6b7280; }
.tile b { font-size: 20px; color: #111827; }
.tile-note { font-size: 11.5px; color: #9ca3af; }

.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.panel :deep(.field-row), .edit-box :deep(.field-row), .pay-box .field-row { display: flex; gap: 12px; flex-wrap: wrap; }
.field, :deep(.field) { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: min(200px, 100%); }
.field label, :deep(.field label) { font-size: 12px; font-weight: 700; color: #6b7280; }
.input, :deep(.input) { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 9px 12px; font-size: 14px; outline: none; font-family: inherit; background: #fff; box-sizing: border-box; }
.input:focus, :deep(.input:focus) { border-color: #2D7A4F; }
.code-input { text-transform: uppercase; letter-spacing: 1px; }
.hint { margin: 0; font-size: 12.5px; color: #6b7280; }
.picked { display: flex; justify-content: space-between; align-items: center; gap: 8px; background: #ecfdf5; border-radius: 10px; padding: 8px 12px; font-size: 14px; }
.candidates { display: flex; flex-direction: column; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
.candidate { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; text-align: left; font-family: inherit; font-size: 13.5px; background: #fff; border: none; border-bottom: 1px solid #f3f4f6; padding: 9px 12px; cursor: pointer; }
.candidate:hover:not(:disabled) { background: #f9fafb; }
.candidate:disabled { opacity: 0.55; cursor: not-allowed; }
.candidate span { color: #6b7280; }

.primary-btn { border: none; background: #2D7A4F; color: #fff; border-radius: 10px; padding: 10px 16px; font-size: 14px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; align-self: flex-start; }
.primary-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.ghost-btn { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
.ghost-btn.danger:hover { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
.primary-btn.sm, .ghost-btn.sm { padding: 7px 12px; font-size: 13px; }
.ghost-btn.xs { padding: 4px 9px; font-size: 12px; border-radius: 8px; }

.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
.search { max-width: 320px; }
.tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.tab { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
.tab.active { background: #ecfdf5; border-color: #2D7A4F; color: #2D7A4F; }

.empty-hint { padding: 32px 16px; color: #9ca3af; font-size: 13px; text-align: center; background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; }
.empty-hint.sm { padding: 14px; }

.list { display: flex; flex-direction: column; gap: 8px; }
.row { text-align: left; font-family: inherit; background: #fff; border: 1px solid #e9edf0; border-radius: 12px; padding: 12px 14px; cursor: pointer; display: grid; grid-template-columns: 1fr auto; gap: 4px 16px; }
.row:hover, .row.selected { border-color: #2D7A4F; }
.row-main { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.code { font-weight: 800; letter-spacing: 1px; color: #111827; }
.name { color: #374151; }
.row-sub { grid-column: 1; font-size: 13px; color: #6b7280; }
.row-amount { grid-column: 2; grid-row: 1 / span 2; display: flex; flex-direction: column; align-items: flex-end; font-size: 12px; color: #6b7280; }
.row-amount b { font-size: 16px; color: #111827; }
b.due { color: #2D7A4F; }

.badge { font-size: 11.5px; font-weight: 800; border-radius: 999px; padding: 2px 9px; background: #f3f4f6; color: #4b5563; white-space: nowrap; }
.badge.active, .badge.approved { background: #ecfdf5; color: #2D7A4F; }
.badge.paused, .badge.void { background: #f3f4f6; color: #9ca3af; }
.badge.pending { background: #fff7ed; color: #c2410c; }
.badge.paid { background: #eff6ff; color: #2563eb; }
.badge.rate { background: #f5f3ff; color: #6d28d9; }
.badge.warn { background: #fef2f2; color: #b91c1c; }
.warn-text { color: #b91c1c; }

.overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.35); z-index: 60; display: flex; justify-content: flex-end; }
.drawer { width: min(620px, 100%); height: 100%; overflow-y: auto; background: #fff; padding: 20px; display: flex; flex-direction: column; gap: 14px; box-sizing: border-box; }
.drawer-head { display: flex; justify-content: space-between; align-items: flex-start; }
.drawer-head h3 { margin: 0 0 4px; font-size: 18px; font-weight: 800; }
.drawer-head .code { margin-right: 8px; }
.drawer h4 { margin: 6px 0 0; font-size: 14px; font-weight: 800; color: #374151; }
.kv { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kv div { display: flex; flex-direction: column; gap: 2px; font-size: 13px; min-width: 0; overflow-wrap: anywhere; }
.kv span { color: #6b7280; font-size: 12px; font-weight: 700; }
.message { margin: 0; background: #f9fafb; border-radius: 10px; padding: 10px 12px; font-size: 13.5px; color: #374151; }
.share { display: flex; gap: 8px; flex-wrap: wrap; }
.share .input { flex: 1; min-width: 200px; }
.pay-box { display: flex; flex-direction: column; gap: 8px; background: #ecfdf5; border: 1px solid #bbf7d0; border-radius: 12px; padding: 12px; }
.pay-box .input { flex: 1; min-width: 180px; }
.edit-box { border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px 12px; display: flex; flex-direction: column; gap: 10px; }
.edit-box summary { cursor: pointer; font-weight: 800; font-size: 13.5px; color: #374151; }
.edit-box[open] > *:not(summary) { margin-top: 10px; }
.check { display: flex; gap: 8px; align-items: center; font-size: 13px; color: #374151; }

.ledger { width: 100%; border-collapse: collapse; font-size: 13px; }
.ledger td, .ledger th { padding: 8px 4px; border-bottom: 1px solid #f3f4f6; vertical-align: top; text-align: left; }
.ledger th { font-size: 12px; color: #6b7280; border-bottom-color: #e5e7eb; }
.ledger .num { text-align: right; white-space: nowrap; }
.ledger .neg { color: #b91c1c; }
.ledger .pos { color: #2D7A4F; }
.ledger .note { font-size: 12px; color: #6b7280; }

@media (max-width: 640px) {
  .kv { grid-template-columns: 1fr; }
  .row { grid-template-columns: 1fr; }
  .row-amount { grid-column: 1; grid-row: auto; align-items: flex-start; flex-direction: row; gap: 8px; flex-wrap: wrap; }
}
</style>
