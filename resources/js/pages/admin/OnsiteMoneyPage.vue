<template>
  <div class="money-page">
    <div class="money-head">
      <div>
        <h2><i class="fas fa-wallet"></i> เงินหน้างาน</h2>
        <p class="sub">
          เงินที่ทีมงานเก็บในห้องแชท (เก็บเงินหน้างาน / ค่าลูกหาบ) — เงินสดอยู่กับคนที่กดรับ เงินโอนเข้าพร้อมเพย์ของคนเปิดการ์ด
          สตาฟกด "ส่งเงินแล้ว" ในแอป แล้วคุณกด "ได้รับแล้ว" ที่นี่ · ค่าอาหารไม่นับ (สตาฟสำรองจ่ายเอง)
        </p>
      </div>
      <div class="totals" v-if="!loading">
        <span>ค้างส่ง <b>{{ pendingCount }}</b> รอบ</span>
        <span>รวม <b>{{ baht(pendingAmount) }}</b></span>
      </div>
    </div>

    <div class="tabs">
      <button v-for="t in tabs" :key="t.key" class="tab" :class="{ active: filter === t.key }" @click="filter = t.key">
        {{ t.label }}
      </button>
    </div>

    <div v-if="loading" class="empty-hint">กำลังโหลด...</div>
    <div v-else-if="!visibleRounds.length" class="empty-hint">ไม่มีรายการ</div>

    <div v-else class="list">
      <div v-for="r in visibleRounds" :key="r.id" class="round" :class="{ open: openId === r.id }">
        <button class="round-row" @click="toggle(r)">
          <div class="round-main">
            <span class="trip">{{ formatShort(r.departure_date) }} · {{ r.trip_title || 'ทริป' }}</span>
            <span class="meta">
              เก็บได้ {{ baht(r.collected) }} (เงินสด {{ baht(r.cash) }} · โอน {{ baht(r.transfer) }})
              <template v-if="r.outstanding > 0"> · ลูกทริปยังค้าง {{ baht(r.outstanding) }}</template>
            </span>
          </div>
          <span class="badge" :class="r.is_settled ? 'received' : 'pending'">
            {{ r.is_settled ? 'รับครบแล้ว' : `ค้างส่ง ${baht(r.pending_amount)}` }}
          </span>
          <i class="fas" :class="openId === r.id ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
        </button>

        <div v-if="openId === r.id" class="detail">
          <div v-if="detailLoading" class="hint">กำลังโหลด...</div>
          <template v-else-if="detail">
            <p v-if="detail.is_frozen" class="hint"><i class="fas fa-box-archive"></i> ห้องแชทถูกล้างแล้ว — ยอดที่บันทึกไว้ก่อนล้าง</p>

            <h4>เงินอยู่กับใคร</h4>
            <div v-if="!detail.holders.length" class="hint">ไม่มีใครถือเงิน</div>
            <div v-for="h in detail.holders" :key="h.user_id" class="holder">
              <div class="holder-main">
                <b>{{ h.name }}</b>
                <span class="meta">
                  <template v-if="h.cash > 0">เงินสด {{ baht(h.cash) }}</template>
                  <template v-if="h.cash > 0 && h.transfer > 0"> · </template>
                  <template v-if="h.transfer > 0">โอน {{ baht(h.transfer) }}<template v-if="h.promptpay"> ({{ h.promptpay }})</template></template>
                </span>
                <span v-if="h.handover?.note" class="meta">สตาฟบอก: {{ h.handover.note }}</span>
                <span v-if="h.status === 'changed'" class="meta warn">
                  สตาฟส่งไว้ {{ baht(h.handover.total) }} แต่ตอนนี้ถือ {{ baht(h.total) }} — รอสตาฟกดส่งใหม่
                </span>
                <span v-if="h.status === 'received'" class="meta">
                  รับแล้วโดย {{ h.handover.received_by || '-' }} · {{ formatDateTime(h.handover.received_at) }}
                  <template v-if="h.handover.received_note"> · {{ h.handover.received_note }}</template>
                </span>
              </div>
              <b class="amount">{{ baht(h.total) }}</b>
              <span class="badge" :class="h.status">{{ statusLabel(h.status) }}</span>
              <div class="holder-actions">
                <template v-if="h.status === 'submitted'">
                  <input v-model="notes[h.user_id]" class="input sm" maxlength="300" placeholder="หมายเหตุ (ไม่บังคับ)" />
                  <button class="primary-btn sm" :disabled="busy" @click="receive(r, h)"><i class="fas fa-check"></i> ได้รับแล้ว</button>
                </template>
                <button v-if="h.status === 'received'" class="ghost-btn sm" :disabled="busy" @click="unreceive(r, h)">ยกเลิกการรับ</button>
              </div>
            </div>

            <h4>รายการเก็บเงิน</h4>
            <table class="table">
              <thead>
                <tr><th>รายการ</th><th>โอนเข้า</th><th class="num">เก็บได้</th><th class="num">เงินสด</th><th class="num">โอน</th><th>ยังไม่จ่าย</th></tr>
              </thead>
              <tbody>
                <tr v-for="c in detail.collections" :key="c.id">
                  <td><b>{{ c.title }}</b></td>
                  <td>{{ c.receiver || '-' }}<span v-if="c.promptpay" class="meta"> {{ c.promptpay }}</span></td>
                  <td class="num">{{ baht(c.collected) }} / {{ baht(c.total) }}</td>
                  <td class="num">{{ baht(c.cash) }}</td>
                  <td class="num">{{ baht(c.transfer) }}</td>
                  <td>
                    <span v-if="!c.unpaid.length" class="meta">ครบ</span>
                    <span v-else class="meta warn">{{ c.unpaid.map(u => `${u.name} ${baht(u.amount)}${u.claimed ? ' (แนบสลิป)' : ''}`).join(', ') }}</span>
                  </td>
                </tr>
              </tbody>
            </table>

            <template v-if="detail.food.length">
              <h4>ค่าอาหาร <span class="meta">(ไม่นับในยอดส่งบริษัท)</span></h4>
              <div v-for="f in detail.food" :key="f.id" class="meta">
                {{ f.title || 'ค่าอาหาร' }} — เก็บได้ {{ baht(f.collected) }} / {{ baht(f.total) }}
                <template v-if="f.owing_count > 0"> · ค้าง {{ f.owing_count }} คน</template>
              </div>
            </template>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// เงินหน้างาน (หลังบ้าน การเงิน) — ดู OnsiteMoneyService
// รายการรอบจาก GET /admin/finance/onsite-money รายละเอียดต่อรอบจาก /{scheduleId}

const toast = useToast();
const swal = useSwal();

const tabs = [
  { key: 'pending', label: 'ค้างส่ง/ค้างรับ' },
  { key: 'settled', label: 'รับครบแล้ว' },
  { key: 'all', label: 'ทั้งหมด' },
];

const filter = ref('pending');
const rounds = ref([]);
const pendingCount = ref(0);
const pendingAmount = ref(0);
const loading = ref(false);
const busy = ref(false);
const openId = ref(null);
const detail = ref(null);
const detailLoading = ref(false);
const notes = ref({});

const visibleRounds = computed(() => rounds.value.filter((r) => (
  filter.value === 'all' ? true : (filter.value === 'settled' ? r.is_settled : !r.is_settled)
)));

const statusLabel = (s) => ({
  pending: 'สตาฟยังไม่ได้ส่ง',
  submitted: 'สตาฟส่งแล้ว รอรับ',
  received: 'ได้รับแล้ว',
  changed: 'ยอดเปลี่ยนหลังส่ง',
})[s] || s;

function baht(v) {
  const n = Number(v || 0);
  return `฿${n.toLocaleString('th-TH', { minimumFractionDigits: Number.isInteger(n) ? 0 : 2, maximumFractionDigits: 2 })}`;
}
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

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/finance/onsite-money');
    rounds.value = res.data.data?.rounds || [];
    pendingCount.value = res.data.data?.pending_count || 0;
    pendingAmount.value = res.data.data?.pending_amount || 0;
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายการไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

async function loadDetail(r) {
  detailLoading.value = true;
  try {
    const res = await api.get(`/admin/finance/onsite-money/${r.id}`);
    detail.value = res.data.data;
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายละเอียดไม่สำเร็จ');
  } finally {
    detailLoading.value = false;
  }
}

function toggle(r) {
  if (openId.value === r.id) {
    openId.value = null;
    detail.value = null;
    return;
  }
  openId.value = r.id;
  detail.value = null;
  loadDetail(r);
}

async function act(request, done) {
  if (busy.value) return;
  busy.value = true;
  try {
    const res = await request();
    detail.value = res.data.data;
    toast.success(done);
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function receive(r, h) {
  const res = await swal.confirm({
    title: `ได้รับเงิน ${baht(h.total)} จาก ${h.name}?`,
    text: `เงินสด ${baht(h.cash)} · โอน ${baht(h.transfer)} — เช็กยอดเข้าบัญชีบริษัท/นับเงินสดแล้วค่อยกด`,
    icon: 'question',
    confirmText: 'ได้รับแล้ว',
  });
  if (!res.isConfirmed) return;
  return act(
    () => api.post(`/admin/finance/onsite-money/${r.id}/handovers/${h.user_id}/receive`, { note: (notes.value[h.user_id] || '').trim() || null }),
    'บันทึกรับเงินแล้ว',
  );
}

async function unreceive(r, h) {
  const res = await swal.confirm({ title: `ยกเลิกการรับเงินจาก ${h.name}?`, icon: 'warning', confirmText: 'ยกเลิกการรับ' });
  if (!res.isConfirmed) return;
  return act(() => api.delete(`/admin/finance/onsite-money/${r.id}/handovers/${h.user_id}/receive`), 'ยกเลิกแล้ว');
}

onMounted(load);
</script>

<style scoped>
.money-page { display: flex; flex-direction: column; gap: 16px; }
.money-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.money-head h2 { margin: 0; font-size: 20px; font-weight: 800; color: #1f2937; }
.money-head h2 i { color: #2D7A4F; margin-right: 6px; }
.sub { margin: 4px 0 0; font-size: 13px; color: #6b7280; max-width: 760px; }
.totals { display: flex; gap: 14px; font-size: 13px; color: #4b5563; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px 14px; }
.totals b { color: #b45309; font-size: 15px; }

.tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.tab { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 700; cursor: pointer; }
.tab.active { background: #ecfdf5; border-color: #2D7A4F; color: #2D7A4F; }

.empty-hint { padding: 32px 16px; color: #9ca3af; font-size: 13px; text-align: center; background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; }

.list { display: flex; flex-direction: column; gap: 10px; }
.round { background: #fff; border: 1px solid #e9edf0; border-radius: 14px; overflow: hidden; }
.round.open { border-color: #2D7A4F; }
.round-row { width: 100%; border: none; background: transparent; padding: 14px 16px; display: flex; align-items: center; gap: 12px; cursor: pointer; text-align: left; font-family: inherit; }
.round-row i { color: #9ca3af; }
.round-main { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.trip { font-size: 14.5px; font-weight: 800; color: #111827; }
.meta { font-size: 12.5px; color: #6b7280; }
.meta.warn { color: #b45309; font-weight: 700; }
.badge { flex-shrink: 0; font-size: 11.5px; font-weight: 800; border-radius: 999px; padding: 3px 10px; }
.badge.pending { background: #fffbeb; color: #b45309; }
.badge.submitted { background: #eff6ff; color: #2563eb; }
.badge.received { background: #ecfdf5; color: #2D7A4F; }
.badge.changed { background: #fef2f2; color: #dc2626; }

.detail { border-top: 1px solid #f1f5f9; padding: 12px 16px 16px; display: flex; flex-direction: column; gap: 10px; }
.detail h4 { margin: 8px 0 0; font-size: 13px; font-weight: 700; color: #6b7280; }
.hint { margin: 0; font-size: 12.5px; color: #6b7280; }
.holder { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; border: 1px solid #f1f5f9; border-radius: 12px; padding: 10px 12px; }
.holder-main { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 220px; }
.amount { font-size: 15px; color: #111827; }
.holder-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.input { border: 1px solid #d1d5db; border-radius: 10px; padding: 9px 12px; font-size: 14px; outline: none; font-family: inherit; background: #fff; }
.input.sm { padding: 6px 10px; font-size: 13px; min-width: 200px; }
.input:focus { border-color: #2D7A4F; }

.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table th { text-align: left; font-size: 12px; color: #6b7280; font-weight: 700; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
.table td { padding: 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; color: #374151; }
.table .num { text-align: right; white-space: nowrap; }

.primary-btn { border: none; background: #2D7A4F; color: #fff; border-radius: 10px; padding: 10px 16px; font-size: 14px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
.primary-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.ghost-btn { border: 1px solid #e5e7eb; background: #fff; color: #4b5563; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
.primary-btn.sm, .ghost-btn.sm { padding: 7px 12px; font-size: 13px; }

@media (max-width: 640px) {
  .table { display: block; overflow-x: auto; }
}
</style>
