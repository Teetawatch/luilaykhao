<template>
  <section class="detail-section">
    <div class="section-heading">
      <span class="material-symbols-rounded">swap_horiz</span>
      ส่งต่อที่นั่ง
    </div>

    <div v-if="loading" class="empty-inline">กำลังโหลด…</div>
    <div v-else-if="error" class="empty-inline">{{ error }}</div>

    <template v-else-if="data">
      <p class="hint">
        ลูกค้าทักมาว่าไปไม่ได้ — สร้างลิงก์แล้วคัดลอกส่งกลับไปในแชท คนที่จะไปแทนกรอกข้อมูลเองและเข้าแชท/วันเดินทางได้ทันที
        ทีมงานและแอดมินจะได้แจ้งเตือนเมื่อมีคนรับ (เช็ครายชื่อประกันอีกครั้ง)
      </p>
      <p v-if="!data.available" class="blocked">{{ data.blocked_reason }}</p>

      <div class="rows">
        <div v-for="seat in data.seats" :key="seat.passenger_id" class="row">
          <div class="who">
            <strong>{{ seat.name || 'ผู้เดินทาง' }}</strong>
            <span>
              <template v-if="seat.seat_label">ที่นั่ง {{ seat.seat_label }}</template>
              <template v-if="seat.member_name"> · บัญชี {{ seat.member_name }}</template>
              <template v-if="seat.is_owner_seat_guess"> · น่าจะเป็นเจ้าของการจอง</template>
            </span>
          </div>

          <div v-if="seat.open_handover" class="actions">
            <span class="pill">รอคนรับ{{ seat.open_handover.transfers_ownership ? ' · โอนสิทธิ์ดูแล' : '' }}</span>
            <button class="btn-secondary compact" @click="copy(seat.open_handover.url)">
              <span class="material-symbols-rounded">content_copy</span>
              คัดลอกลิงก์
            </button>
            <button class="btn-danger compact" :disabled="busy === seat.passenger_id" @click="cancel(seat)">ยกเลิก</button>
          </div>

          <div v-else-if="seat.can_hand_over" class="actions">
            <label class="own" :class="{ disabled: !data.can_transfer_ownership }"
              :title="data.ownership_blocked_reason || 'คนรับจะเป็นเจ้าของการจองแทน'">
              <input v-model="ownership[seat.passenger_id]" type="checkbox" :disabled="!data.can_transfer_ownership" />
              โอนสิทธิ์ดูแลการจองด้วย
            </label>
            <button class="btn-secondary compact" :disabled="busy === seat.passenger_id" @click="create(seat)">
              <span class="material-symbols-rounded">add_link</span>
              สร้างลิงก์
            </button>
          </div>
        </div>
      </div>

      <div v-if="data.history?.length" class="history">
        <p class="history-title">ประวัติ</p>
        <div v-for="h in data.history" :key="h.id" class="history-row">
          <template v-if="h.status === 'claimed'">
            <strong>{{ h.previous_name }} → {{ h.new_name }}</strong>
            <span>
              {{ formatDateTime(h.claimed_at) }}
              · บัญชี {{ h.claimed_by?.name || '-' }} {{ h.claimed_by?.phone || '' }}
              · ออกลิงก์โดย {{ h.created_by_name || '-' }}
              <template v-if="h.transfers_ownership"> · โอนสิทธิ์ดูแลการจอง</template>
              <template v-if="h.channel"> · ผ่าน {{ h.channel }}</template>
              <template v-if="h.terms_version"> · ยอมรับเงื่อนไขฉบับ {{ h.terms_version }}</template>
            </span>
          </template>
          <template v-else>
            <strong>ลิงก์ของ {{ h.previous_name }}</strong>
            <span>{{ h.status === 'expired' ? 'หมดอายุ' : 'ถูกยกเลิก' }} · ออกโดย {{ h.created_by_name || '-' }}</span>
          </template>
        </div>
      </div>
    </template>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';

/**
 * ส่งต่อที่นั่งในโมดัลรายละเอียดการจองของแอดมิน — ใช้ /admin/bookings/{ref}/handovers
 * ลิงก์ที่ทีมงานออกข้ามเส้นตาย 3 ชั่วโมงและรอบเครื่องบินได้ (ทีมงานรู้ว่าต้องไป
 * เปลี่ยนชื่อตั๋วเอง) ดู SeatHandoverService::blockedReason
 */
const props = defineProps({
  bookingRef: { type: String, required: true },
});
const emit = defineEmits(['changed']);

const toast = useToast();
const data = ref(null);
const loading = ref(true);
const error = ref('');
const busy = ref(null);
const ownership = reactive({});

async function load() {
  error.value = '';
  try {
    const res = await api.get(`/admin/bookings/${props.bookingRef}/handovers`);
    data.value = res.data.data;
  } catch (e) {
    error.value = e?.response?.data?.message || 'โหลดข้อมูลส่งต่อที่นั่งไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

async function create(seat) {
  busy.value = seat.passenger_id;
  try {
    const res = await api.post(`/admin/bookings/${props.bookingRef}/handovers`, {
      passenger_id: seat.passenger_id,
      transfers_ownership: !!ownership[seat.passenger_id],
    });
    await load();
    await copy(res.data?.data?.url);
    emit('changed');
  } catch (e) {
    toast.error(e?.response?.data?.message || 'สร้างลิงก์ไม่สำเร็จ');
  } finally {
    busy.value = null;
  }
}

async function cancel(seat) {
  busy.value = seat.passenger_id;
  try {
    await api.delete(`/admin/bookings/${props.bookingRef}/handovers/${seat.open_handover.id}`);
    await load();
    toast.success('ยกเลิกลิงก์แล้ว');
  } catch (e) {
    toast.error(e?.response?.data?.message || 'ยกเลิกไม่สำเร็จ');
  } finally {
    busy.value = null;
  }
}

async function copy(url) {
  if (!url) return;
  try {
    await navigator.clipboard.writeText(url);
    toast.success('คัดลอกลิงก์แล้ว ส่งให้ลูกค้าได้เลย');
  } catch {
    toast.error(`คัดลอกไม่สำเร็จ: ${url}`);
  }
}

function formatDateTime(iso) {
  if (!iso) return '-';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '-';
  return d.toLocaleString('th-TH', {
    timeZone: 'Asia/Bangkok', day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit',
  });
}

watch(() => props.bookingRef, () => {
  loading.value = true;
  load();
});
onMounted(load);
</script>

<style scoped>
@import url('../pages/admin/admin-shared.css');

.detail-section { border-top: 1px solid #eeeeee; padding-top: 16px; }
.section-heading { display: flex; align-items: center; gap: 8px; color: var(--color-text-dark); font-size: 14px; font-weight: 900; margin-bottom: 12px; }
.empty-inline { color: var(--color-text-muted); font-size: 13px; border: 1px dashed var(--color-sand-dark); border-radius: 8px; padding: 12px; }
.hint { font-size: 12px; color: var(--color-text-muted); margin-bottom: 10px; line-height: 1.5; }
.blocked { font-size: 13px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; }
.rows { display: flex; flex-direction: column; gap: 8px; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; border: 1px solid #eeeeee; border-radius: 10px; padding: 10px 12px; }
.who { display: flex; flex-direction: column; min-width: 0; }
.who strong { font-size: 14px; }
.who span { font-size: 12px; color: var(--color-text-muted); }
.actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pill { font-size: 11px; font-weight: 800; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 999px; padding: 3px 8px; }
.own { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--color-text-dark); cursor: pointer; }
.own.disabled { opacity: 0.55; cursor: not-allowed; }
.history { margin-top: 12px; }
.history-title { font-size: 12px; font-weight: 900; color: var(--color-text-muted); margin-bottom: 6px; }
.history-row { display: flex; flex-direction: column; font-size: 12px; padding: 6px 0; border-bottom: 1px dashed #eeeeee; }
.history-row span { color: var(--color-text-muted); }
</style>
