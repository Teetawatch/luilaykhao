<template>
  <div class="cal">
    <div class="cal-head">
      <button type="button" class="cal-nav" @click="shift(-1)" aria-label="เดือนก่อน">
        <span class="material-symbols-rounded">chevron_left</span>
      </button>
      <strong>{{ monthLabel }}</strong>
      <button type="button" class="cal-nav" @click="shift(1)" aria-label="เดือนถัดไป">
        <span class="material-symbols-rounded">chevron_right</span>
      </button>
    </div>

    <div v-if="data?.rooms?.length > 1" class="cal-rooms">
      <button v-for="r in data.rooms" :key="r.room_type_id" type="button" class="cal-room"
        :class="{ on: r.room_type_id === roomId }" @click="roomId = r.room_type_id; clearSel()">
        {{ r.name }}
      </button>
    </div>

    <p v-if="loading" class="cal-hint">กำลังโหลด…</p>
    <p v-else-if="!room" class="cal-hint">ยังไม่มีห้อง — เพิ่มห้องก่อนแล้วค่อยจัดปฏิทิน</p>

    <template v-else>
      <p class="cal-hint">แตะวันแรกแล้วแตะวันสุดท้ายเพื่อเลือกช่วง · ตัวเลขใหญ่ = ห้องว่างจาก {{ room.units }} ห้อง</p>
      <div class="cal-grid">
        <span v-for="d in WEEKDAYS" :key="d" class="cal-wd">{{ d }}</span>
        <span v-for="n in leading" :key="'b' + n"></span>
        <button v-for="day in room.days" :key="day.date" type="button" class="cal-day"
          :class="dayClass(day)" :disabled="day.date < today" @click="pick(day.date)">
          <span class="cal-date">{{ Number(day.date.slice(8)) }}</span>
          <span class="cal-avail">{{ day.available }}</span>
          <span class="cal-price" :class="{ special: day.price_override !== null }">฿{{ Math.round(day.price).toLocaleString() }}</span>
          <span v-if="day.booked" class="cal-booked">จอง {{ day.booked }}</span>
        </button>
      </div>

      <div v-if="selStart" class="cal-panel">
        <p class="cal-sel">
          {{ rangeLabel }}
        </p>
        <div class="cal-actions">
          <label>
            ปิดห้อง (จาก {{ room.units }})
            <input v-model.number="closedUnits" type="number" min="0" :max="room.units" />
          </label>
          <label>
            ราคาพิเศษ/คืน
            <input v-model="price" type="number" min="0" placeholder="ไม่เปลี่ยน" />
          </label>
        </div>
        <div class="cal-buttons">
          <button type="button" class="cal-btn ghost" @click="clearSel">ยกเลิก</button>
          <button type="button" class="cal-btn ghost" @click="apply({ closed_units: 0, clear_price: true })">เปิดทุกห้อง + ราคาปกติ</button>
          <button type="button" class="cal-btn" :disabled="saving" @click="applyForm">บันทึก</button>
        </div>
        <p v-if="message" class="cal-hint" :class="{ err: error }">{{ message }}</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';

/**
 * ปฏิทินห้องว่างรายเดือน — ใช้ทั้งหลังบ้านและหน้าของเจ้าของที่พัก
 * load(month) คืน { month, rooms: [{ room_type_id, name, units, days: [...] }] }
 * save(body) รับ { room_type_id, from, to, closed_units?, price?, clear_price? }
 */
const props = defineProps({
  load: { type: Function, required: true },
  save: { type: Function, required: true },
});

const WEEKDAYS = ['จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส', 'อา'];
const MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Bangkok' });
const month = ref(today.slice(0, 7));
const data = ref(null);
const roomId = ref(null);
const loading = ref(false);
const saving = ref(false);
const selStart = ref(null);
const selEnd = ref(null);
const closedUnits = ref(0);
const price = ref('');
const message = ref('');
const error = ref(false);

const room = computed(() => data.value?.rooms?.find(r => r.room_type_id === roomId.value) || null);

const monthLabel = computed(() => {
  const [y, m] = month.value.split('-').map(Number);
  return `${MONTHS[m - 1]} ${y + 543}`;
});

// วันจันทร์เป็นคอลัมน์แรก
const leading = computed(() => {
  const [y, m] = month.value.split('-').map(Number);
  const dow = new Date(Date.UTC(y, m - 1, 1)).getUTCDay();
  return (dow + 6) % 7;
});

const range = computed(() => {
  if (!selStart.value) return null;
  const end = selEnd.value || selStart.value;
  return selStart.value <= end ? [selStart.value, end] : [end, selStart.value];
});

const rangeLabel = computed(() => {
  if (!range.value) return '';
  const f = (d) => `${Number(d.slice(8))} ${MONTHS[Number(d.slice(5, 7)) - 1].slice(0, 3)}`;
  return range.value[0] === range.value[1] ? `คืนวันที่ ${f(range.value[0])}` : `คืนวันที่ ${f(range.value[0])} – ${f(range.value[1])}`;
});

function dayClass(day) {
  const inRange = range.value && day.date >= range.value[0] && day.date <= range.value[1];
  return {
    sel: inRange,
    full: day.available === 0,
    closed: day.closed > 0,
    past: day.date < today,
  };
}

function pick(date) {
  message.value = '';
  if (!selStart.value || selEnd.value) {
    selStart.value = date;
    selEnd.value = null;
  } else {
    selEnd.value = date;
  }
  const first = room.value.days.find(d => d.date === (range.value?.[0] || date));
  closedUnits.value = first?.closed ?? 0;
  price.value = first?.price_override ?? '';
}

function clearSel() {
  selStart.value = null;
  selEnd.value = null;
  message.value = '';
}

async function fetchMonth() {
  loading.value = true;
  try {
    data.value = await props.load(month.value);
    if (!data.value.rooms.some(r => r.room_type_id === roomId.value)) {
      roomId.value = data.value.rooms[0]?.room_type_id ?? null;
    }
  } finally {
    loading.value = false;
  }
}

function shift(delta) {
  const [y, m] = month.value.split('-').map(Number);
  const d = new Date(Date.UTC(y, m - 1 + delta, 1));
  month.value = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
  clearSel();
  fetchMonth();
}

function applyForm() {
  const body = { closed_units: Number(closedUnits.value || 0) };
  if (price.value !== '' && price.value !== null) body.price = Number(price.value);
  apply(body);
}

async function apply(extra) {
  if (!range.value) return;
  saving.value = true;
  error.value = false;
  try {
    await props.save({ room_type_id: roomId.value, from: range.value[0], to: range.value[1], ...extra });
    clearSel();
    await fetchMonth();
    message.value = 'บันทึกแล้ว';
  } catch (err) {
    error.value = true;
    message.value = err.response?.data?.message || 'บันทึกไม่สำเร็จ';
  } finally {
    saving.value = false;
  }
}

onMounted(fetchMonth);
</script>

<style scoped>
.cal { display: grid; gap: 10px; }
.cal-head { display: flex; align-items: center; justify-content: space-between; }
.cal-nav { width: 36px; height: 36px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.cal-rooms { display: flex; gap: 6px; flex-wrap: wrap; }
.cal-room { padding: 6px 12px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; font-size: 13px; font-weight: 700; color: #6b7280; cursor: pointer; }
.cal-room.on { background: var(--color-accent); color: #fff; border-color: var(--color-accent); }
.cal-hint { font-size: 12px; color: #9ca3af; margin: 0; }
.cal-hint.err { color: #b42318; }
.cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.cal-wd { text-align: center; font-size: 11px; font-weight: 700; color: #9ca3af; }
.cal-day { display: flex; flex-direction: column; align-items: center; gap: 1px; padding: 6px 2px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; cursor: pointer; min-height: 64px; }
.cal-day:disabled { cursor: default; opacity: .35; }
.cal-day.full { background: #fef3f2; border-color: #fecdca; }
.cal-day.closed { background-image: repeating-linear-gradient(135deg, transparent 0 6px, rgba(0,0,0,.04) 6px 12px); }
.cal-day.sel { border-color: var(--color-accent); box-shadow: inset 0 0 0 1px var(--color-accent); background: #e6f4f4; }
.cal-date { font-size: 11px; color: #6b7280; }
.cal-avail { font-size: 17px; font-weight: 900; color: #111827; line-height: 1.1; }
.cal-day.full .cal-avail { color: #b42318; }
.cal-price { font-size: 10px; color: #6b7280; }
.cal-price.special { color: #b54708; font-weight: 800; }
.cal-booked { font-size: 9px; font-weight: 700; color: var(--color-accent); }
.cal-panel { border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; display: grid; gap: 10px; }
.cal-sel { margin: 0; font-weight: 800; font-size: 14px; }
.cal-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.cal-actions label { display: grid; gap: 4px; font-size: 12px; font-weight: 700; color: #374151; }
.cal-actions input { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 10px; font-size: 14px; }
.cal-buttons { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.cal-btn { padding: 8px 14px; border-radius: 10px; border: 0; background: var(--color-accent); color: #fff; font-size: 13px; font-weight: 800; cursor: pointer; }
.cal-btn.ghost { background: #f3f4f6; color: #374151; }
.cal-btn:disabled { opacity: .5; }
</style>
