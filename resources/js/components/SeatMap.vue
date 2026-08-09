<template>
  <div v-if="!seatMap || !seatMap.has_seat_map" class="text-center py-12">
    <span class="material-symbols-rounded text-5xl text-gray-200 mb-3 block" style="font-variation-settings:'FILL' 0,'wght' 200">airline_seat_recline_normal</span>
    <p class="font-bold text-gray-400">ทริปนี้ไม่มีผังที่นั่ง</p>
    <p class="text-sm text-gray-400 mt-1">ที่นั่งว่าง: {{ seatMap?.available_seats ?? 0 }} / {{ seatMap?.total_seats ?? 0 }}</p>
  </div>

  <div v-else class="space-y-5">
    <!-- Legend -->
    <div class="flex flex-wrap gap-x-5 gap-y-2 px-4 py-3 bg-gray-50 rounded-2xl border border-gray-100">
      <div class="flex items-center gap-1.5">
        <div class="w-5 h-5 rounded-lg bg-white border-2 border-gray-200"></div>
        <span class="text-[11px] font-bold text-gray-500">ว่าง</span>
      </div>
      <div v-if="!readonly" class="flex items-center gap-1.5">
        <div class="w-5 h-5 rounded-lg border-2" :class="isWomenOnly ? 'bg-[#db2777] border-[#db2777]' : 'bg-[#006565] border-[#006565]'"></div>
        <span class="text-[11px] font-bold text-gray-500">กำลังเลือก</span>
      </div>
      <div class="flex items-center gap-1.5">
        <div class="w-5 h-5 rounded-lg bg-amber-50 border-2 border-amber-300"></div>
        <span class="text-[11px] font-bold text-gray-500">ล็อคชั่วคราว</span>
      </div>
      <div class="flex items-center gap-1.5">
        <div class="w-5 h-5 rounded-lg bg-red-100 border-2 border-red-300"></div>
        <span class="text-[11px] font-bold text-gray-500">จองแล้ว</span>
      </div>
      <div v-if="hasOwnSeats" class="flex items-center gap-1.5">
        <div class="w-5 h-5 rounded-lg border-2 border-dashed"
          :class="isWomenOnly ? 'bg-pink-50 border-[#db2777]' : 'bg-teal-50 border-[#006565]'"></div>
        <span class="text-[11px] font-bold text-gray-500">ที่นั่งของคุณ</span>
      </div>
    </div>

    <!-- ที่นั่งที่ตัวเองถืออยู่ — กันความเข้าใจผิดว่า "มีคนอื่นจองไปแล้ว" -->
    <div v-if="hasOwnSeats" class="flex items-start gap-2 px-4 py-3 rounded-2xl border"
      :class="isWomenOnly ? 'bg-pink-50/60 border-pink-100' : 'bg-teal-50/60 border-teal-100'">
      <span class="material-symbols-rounded text-[18px] shrink-0 mt-px"
        :class="isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]'"
        style="font-variation-settings:'FILL' 1,'wght' 400">how_to_reg</span>
      <span class="text-[11px] font-bold leading-relaxed" :class="isWomenOnly ? 'text-[#9d174d]' : 'text-[#0f5132]'">
        ที่นั่ง {{ ownSeatIds.join(', ') }} เป็นของคุณอยู่แล้ว
        <template v-if="ownLockedSeatIds.length">— แตะเพื่อเลือกอีกครั้ง หรือปล่อยไว้แล้วเลือกที่นั่งอื่นได้เลย</template>
      </span>
    </div>

    <!-- Booking note -->
    <div v-if="!readonly" class="flex items-start gap-2 px-4 py-3 rounded-2xl border"
      :class="isWomenOnly ? 'bg-pink-50/60 border-pink-100' : 'bg-teal-50/60 border-teal-100'">
      <span class="material-symbols-rounded text-[18px] shrink-0 mt-px" :class="isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]'"
        style="font-variation-settings:'FILL' 1,'wght' 400">info</span>
      <span class="text-[11px] font-bold leading-relaxed" :class="isWomenOnly ? 'text-[#9d174d]' : 'text-[#0f5132]'">
        การจองหลายท่าน คือต้องเลือกหลายที่นั่ง ตามจำนวนคน
      </span>
    </div>

    <!-- Vehicle layout -->
    <div class="van-body relative mx-auto max-w-sm bg-white border-2 border-gray-200 rounded-t-[6rem] rounded-b-[3rem] pt-9 pb-6 px-6 md:px-8">
      <!-- Clip layer: ambient glows + headlights (clipped to van shape) -->
      <div class="absolute inset-0 rounded-t-[6rem] rounded-b-[3rem] overflow-hidden pointer-events-none">
        <div class="absolute -top-12 -right-12 w-40 h-40 rounded-full blur-3xl opacity-50"
          :class="isWomenOnly ? 'bg-pink-100' : 'bg-teal-50'"></div>
        <div class="absolute -bottom-12 -left-12 w-40 h-40 bg-gray-50 rounded-full blur-3xl"></div>
        <!-- Headlights -->
        <div class="absolute top-5 left-8 w-7 h-3 rounded-full bg-amber-200/80 blur-[1px]"></div>
        <div class="absolute top-5 right-8 w-7 h-3 rounded-full bg-amber-200/80 blur-[1px]"></div>
        <!-- Front grille hint -->
        <div class="absolute top-3 left-1/2 -translate-x-1/2 w-16 h-1.5 rounded-full bg-gray-100"></div>
      </div>

      <!-- Wheels (front + rear axles, both sides) -->
      <div class="absolute -left-1 top-[22%] w-2.5 h-10 rounded-xl bg-gray-700 pointer-events-none"></div>
      <div class="absolute -right-1 top-[22%] w-2.5 h-10 rounded-xl bg-gray-700 pointer-events-none"></div>
      <div class="absolute -left-1 bottom-[13%] w-2.5 h-10 rounded-xl bg-gray-700 pointer-events-none"></div>
      <div class="absolute -right-1 bottom-[13%] w-2.5 h-10 rounded-xl bg-gray-700 pointer-events-none"></div>

      <!-- Sliding door (left side — Thai vans), spans from behind front seat down to near rear wheel -->
      <div class="absolute left-0 top-[31%] bottom-[27%] z-30 flex flex-col items-center justify-center gap-2 w-6 rounded-r-xl bg-amber-100/95 border-2 border-l-0 border-amber-300 pointer-events-none">
        <span class="material-symbols-rounded text-[16px] text-amber-700" style="font-variation-settings:'FILL' 1,'wght' 500">door_open</span>
        <span class="text-[10px] font-black text-amber-700 tracking-wide" style="writing-mode:vertical-rl;text-orientation:mixed;">ประตู</span>
      </div>

      <div class="relative max-w-xs mx-auto">

        <!-- Windshield (van nose) -->
        <div class="mx-auto w-[94%] h-9 rounded-t-[3.5rem] border-2 border-b-0 pointer-events-none"
          :class="isWomenOnly ? 'bg-gradient-to-b from-pink-200/70 via-pink-50 to-white border-pink-100' : 'bg-gradient-to-b from-sky-200/70 via-sky-50 to-white border-teal-100'"></div>

        <!-- Front cabin: staff + front passenger (left) · label · driver (right) -->
        <div class="flex items-end justify-between mb-7 px-1 pt-1 pb-6 border-2 border-t-0 rounded-b-3xl border-dashed"
          :class="isWomenOnly ? 'border-pink-100/70' : 'border-teal-100/70'">
          <!-- Left group: staff then front passenger seat -->
          <div class="flex items-end gap-2 shrink-0">
            <!-- Staff -->
            <div v-if="layoutConfig.show_staff" class="flex flex-col items-center gap-1 shrink-0">
              <div class="w-12 h-12 rounded-2xl flex items-center justify-center border-2"
                :class="isWomenOnly ? 'bg-pink-50 border-pink-200' : 'bg-teal-50 border-teal-200'">
                <span class="material-symbols-rounded text-[20px]"
                  :class="isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]'"
                  style="font-variation-settings:'FILL' 1,'wght' 400">{{ layoutConfig.staff_icon }}</span>
              </div>
              <span class="text-[10px] font-bold" :class="isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]'">สตาฟ</span>
            </div>

            <!-- Front passenger seat -->
            <button
              v-if="frontPassengerSeat"
              :disabled="readonly || isBlocked(frontPassengerSeat)"
              @click="handleSeatClick(frontPassengerSeat)"
              class="group flex flex-col items-center gap-1 transition-all duration-200 shrink-0"
              :class="readonly ? 'cursor-default' : isBlocked(frontPassengerSeat) ? 'cursor-not-allowed' : 'cursor-pointer'"
              :title="seatTitle(frontPassengerSeat)"
            >
              <div class="w-12 h-12 rounded-2xl flex items-center justify-center transition-all duration-200 border-2"
                :class="seatBgClass(frontPassengerSeat)">
                <span class="material-symbols-rounded text-[20px] transition-all duration-200"
                  :class="seatIconClass(frontPassengerSeat)"
                  style="font-variation-settings:'FILL' 1,'wght' 400">airline_seat_recline_normal</span>
              </div>
              <span class="text-[10px] font-extrabold leading-none transition-colors" :class="seatLabelClass(frontPassengerSeat)">
                {{ frontPassengerSeat.label ?? frontPassengerSeat.id }}
              </span>
              <span v-if="seatStatusLabel(frontPassengerSeat)"
                class="text-[9px] font-bold -mt-0.5" :class="seatStatusLabel(frontPassengerSeat).class">
                {{ seatStatusLabel(frontPassengerSeat).text }}
              </span>
            </button>
          </div>

          <!-- Front label -->
          <div class="flex-1 flex justify-center self-center">
            <span class="px-3 py-1 rounded-full text-[10px] font-black tracking-widest uppercase transition-colors duration-300"
              :class="isWomenOnly ? 'bg-pink-50 text-[#db2777] border border-pink-100' : 'bg-teal-50 text-[#006565] border border-teal-100'">
              {{ layoutConfig.front_label }}
            </span>
          </div>

          <!-- Driver -->
          <div v-if="layoutConfig.show_driver" class="flex flex-col items-center gap-1 shrink-0">
            <div class="w-12 h-12 rounded-2xl bg-gray-100 border-2 border-gray-200 flex items-center justify-center">
              <span class="material-symbols-rounded text-xl text-gray-500" style="font-variation-settings:'FILL' 0,'wght' 300">{{ layoutConfig.driver_icon }}</span>
            </div>
            <span class="text-[10px] font-bold text-gray-400">คนขับ</span>
          </div>
          <div v-else class="w-12 shrink-0"></div>
        </div>

        <!-- Seat rows -->
        <div class="space-y-3.5">
          <div
            v-for="(rowDef, rowIdx) in bodyRows"
            :key="rowIdx"
            class="flex items-center justify-center gap-1"
          >
            <!-- Left group -->
            <div class="flex gap-2.5">
              <template v-for="seatId in rowDef.left" :key="seatId">
                <SeatButton :seat="getSeat(seatId)" :seat-id="seatId" :is-women-only="isWomenOnly"
                  @click="handleSeatClick(getSeat(seatId))" />
              </template>
            </div>

            <!-- Center group -->
            <div v-if="rowDef.center && rowDef.center.length > 0" class="flex gap-2.5 ml-2">
              <template v-for="seatId in rowDef.center" :key="seatId">
                <SeatButton :seat="getSeat(seatId)" :seat-id="seatId" :is-women-only="isWomenOnly"
                  @click="handleSeatClick(getSeat(seatId))" />
              </template>
            </div>

            <!-- Aisle -->
            <div v-if="rowDef.hasAisle" class="w-10 flex items-center justify-center">
              <div class="w-px h-10 bg-gray-100 rounded-full"></div>
            </div>
            <div v-else class="w-10"></div>

            <!-- Right group -->
            <div class="flex gap-2.5">
              <template v-for="seatId in rowDef.right" :key="seatId">
                <SeatButton :seat="getSeat(seatId)" :seat-id="seatId" :is-women-only="isWomenOnly"
                  @click="handleSeatClick(getSeat(seatId))" />
              </template>
            </div>
          </div>
        </div>

        <!-- Rear (cargo door) -->
        <div class="mt-7 pt-5 border-t-2 border-dashed border-gray-100">
          <div class="mx-auto w-[88%] rounded-b-[2rem] border-2 border-t-0 border-gray-100 bg-gray-50/60 pt-2 pb-3 flex flex-col items-center gap-1.5">
            <div class="w-[65%] h-2 rounded-full bg-gray-200"></div>
            <span class="text-gray-400 text-[10px] font-black tracking-widest uppercase text-center px-3 leading-tight">
              {{ layoutConfig.rear_label }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Availability summary -->
    <div v-if="!readonly" class="flex items-center justify-between px-4 py-3 bg-gray-50 rounded-2xl border border-gray-100">
      <div class="flex items-center gap-2">
        <div class="w-2 h-2 rounded-full"
          :class="seatMap.available_seats === 0 ? 'bg-red-400' : seatMap.available_seats <= 3 ? 'bg-amber-400 animate-pulse' : 'bg-emerald-400 animate-pulse'"></div>
        <span class="text-xs font-bold text-gray-500">
          ว่าง <span class="text-gray-800"
            :class="seatMap.available_seats === 0 ? 'text-red-500' : seatMap.available_seats <= 3 ? 'text-amber-600' : 'text-emerald-600'">
            {{ seatMap.available_seats }}
          </span> / {{ seatMap.total_seats }} ที่นั่ง
        </span>
      </div>
      <span v-if="seatMap.available_seats === 0" class="text-[11px] font-black text-red-500">เต็มแล้ว</span>
      <span v-else-if="seatMap.available_seats <= 3" class="text-[11px] font-black text-amber-600 animate-pulse">ใกล้เต็ม!</span>
      <span v-else class="text-[11px] font-bold text-gray-400">เลือกที่นั่งได้เลย</span>
    </div>
    <div v-else class="text-center text-sm text-gray-400">
      ว่าง <span class="font-bold" :class="isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]'">{{ seatMap.available_seats }}</span> / {{ seatMap.total_seats }} ที่นั่ง
    </div>
  </div>
</template>

<script setup>
import { computed, h } from 'vue';
import { useSeatsStore } from '../stores/seats';

const seatsStore = useSeatsStore();

const props = defineProps({
  seatMap: { type: Object, default: null },
  isWomenOnly: { type: Boolean, default: false },
  showNames: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['seat-click']);

// ─── Layout config ────────────────────────────────────────────────
const layoutConfig = computed(() => {
  const sm = props.seatMap;
  return {
    front_seat: sm?.front_seat ?? null,
    last_row_center: sm?.last_row_center ?? [],
    front_label: sm?.front_label || 'หน้ารถ',
    rear_label: sm?.rear_label || 'ท้ายรถ (เก็บสัมภาระ)',
    driver_icon: sm?.driver_icon || 'directions_car',
    show_driver: sm?.show_driver !== false,
    staff_icon: sm?.staff_icon || 'support_agent',
    show_staff: sm?.show_staff !== false,
  };
});

// ─── Own seats ────────────────────────────────────────────────────
// ที่นั่งที่ผู้ใช้คนนี้ถืออยู่เอง (ล็อกไว้ หรืออยู่ในใบจองของตัวเอง) — ต้องแยกให้ออก
// จากที่นั่งของคนอื่น ไม่งั้นลูกค้าที่ล็อกที่นั่งไว้เองจะเห็นว่า "มีคนจองไปแล้ว"
function isOwnLock(seat) {
  if (props.readonly) return false;
  return seat?.status === 'locked' && seat?.locked_by_current_user === true;
}

function isOwnBooking(seat) {
  if (props.readonly) return false;
  return seat?.status === 'booked' && seat?.booked_by_current_user === true;
}

function isBlocked(seat) {
  if (!seat) return true;
  if (isOwnLock(seat)) return false; // ล็อกของตัวเอง เลือกซ้ำได้
  return seat.status === 'booked' || seat.status === 'locked';
}

const ownLockedSeatIds = computed(() =>
  (props.seatMap?.seats || []).filter(isOwnLock).map(s => s.id)
);

const ownSeatIds = computed(() =>
  (props.seatMap?.seats || []).filter(s => isOwnLock(s) || isOwnBooking(s)).map(s => s.id)
);

const hasOwnSeats = computed(() => !props.readonly && ownSeatIds.value.length > 0);

// ─── Seat style helpers ───────────────────────────────────────────
function seatBgClass(seat) {
  if (!seat) return 'bg-gray-50 border-gray-200';
  if (isOwnBooking(seat)) {
    return props.isWomenOnly
      ? 'bg-pink-50 border-[#db2777] border-dashed'
      : 'bg-teal-50 border-[#006565] border-dashed';
  }
  if (seat.status === 'booked') return 'bg-red-100 border-red-300';
  if (isSelected(seat)) {
    return props.isWomenOnly
      ? 'bg-[#db2777] border-[#db2777] shadow-pink-200 scale-105'
      : 'bg-[#006565] border-[#006565] scale-105';
  }
  if (isOwnLock(seat)) {
    return props.isWomenOnly
      ? 'bg-pink-50 border-[#db2777] border-dashed group-hover:bg-pink-100'
      : 'bg-teal-50 border-[#006565] border-dashed group-hover:bg-teal-100';
  }
  if (seat.status === 'locked') return 'bg-amber-50 border-amber-300';
  if (props.readonly) return 'bg-white border-gray-200';
  return props.isWomenOnly
    ? 'bg-white border-gray-200 group-hover:border-[#db2777]/50 group-hover:bg-pink-50 group-hover:scale-105'
    : 'bg-white border-gray-200 group-hover:border-[#006565]/50 group-hover:bg-teal-50 group-hover:scale-105';
}

function seatIconClass(seat) {
  if (!seat) return 'text-gray-300';
  if (isOwnBooking(seat)) return props.isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]';
  if (seat.status === 'booked') return 'text-red-400';
  if (isSelected(seat)) return 'text-white';
  if (isOwnLock(seat)) return props.isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]';
  if (seat.status === 'locked') return 'text-amber-400';
  if (props.readonly) return 'text-gray-300';
  return props.isWomenOnly
    ? 'text-gray-300 group-hover:text-[#db2777]'
    : 'text-gray-300 group-hover:text-[#006565]';
}

function seatLabelClass(seat) {
  if (!seat) return 'text-gray-400';
  if (isOwnBooking(seat)) return props.isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]';
  if (seat.status === 'booked') return 'text-red-400';
  if (isSelected(seat)) return props.isWomenOnly ? 'text-[#db2777] font-black' : 'text-[#006565] font-black';
  if (isOwnLock(seat)) return props.isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]';
  if (seat.status === 'locked') return 'text-amber-500';
  if (props.readonly) return 'text-gray-400';
  return props.isWomenOnly
    ? 'text-gray-400 group-hover:text-[#db2777]'
    : 'text-gray-400 group-hover:text-[#006565]';
}

// ─── Front passenger seat ─────────────────────────────────────────
const frontPassengerSeat = computed(() => {
  const frontId = layoutConfig.value.front_seat;
  if (!frontId || !props.seatMap?.seats) return null;
  return props.seatMap.seats.find(s => s.id === frontId) ?? null;
});

// ─── Build rows ───────────────────────────────────────────────────
const centerSeatIds = computed(() => new Set(layoutConfig.value.last_row_center || []));

const allRows = computed(() => {
  if (!props.seatMap?.seats) return [];
  const rows = props.seatMap.rows ?? 0;
  const columns = props.seatMap.columns ?? [];
  const result = [];
  for (let r = 1; r <= rows; r++) {
    const left = [], right = [], center = [];
    let hasAisle = false, inRight = false;
    for (const col of columns) {
      if (col === '') { hasAisle = true; inRight = true; continue; }
      const seatId = col + r;
      if (!props.seatMap.seats.some(s => s.id === seatId)) continue;
      if (centerSeatIds.value.has(seatId)) center.push(seatId);
      else if (inRight) right.push(seatId);
      else left.push(seatId);
    }
    result.push({ left, right, center, hasAisle: hasAisle && right.length > 0 });
  }
  return result;
});

const bodyRows = computed(() => {
  const frontId = layoutConfig.value.front_seat;
  if (!frontId) return allRows.value;
  return allRows.value.filter(row => ![...row.left, ...row.right, ...row.center].includes(frontId));
});

function getSeat(id) {
  return props.seatMap?.seats?.find(s => s.id === id) || null;
}

function isSelected(seat) {
  if (props.readonly) return false;
  return seatsStore.selectedSeats.some(s => s.id === seat?.id);
}

function handleSeatClick(seat) {
  if (props.readonly) return;
  if (isBlocked(seat)) return;
  seatsStore.toggleSeat(seat);
  emit('seat-click', seat);
}

function seatStatusLabel(seat) {
  if (isOwnBooking(seat)) return { text: 'คุณจองแล้ว', class: props.isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]' };
  if (seat?.status === 'booked') return { text: 'จองแล้ว', class: 'text-red-400' };
  if (isOwnLock(seat)) return { text: 'ของคุณ', class: props.isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]' };
  if (seat?.status === 'locked') return { text: 'ล็อค', class: 'text-amber-500' };
  return null;
}

function seatTitle(seat) {
  if (props.readonly) return '';
  if (isOwnBooking(seat)) {
    return seat.booking_ref ? `อยู่ในการจอง ${seat.booking_ref} ของคุณ` : 'อยู่ในการจองของคุณ';
  }
  if (seat?.status === 'booked') return 'จองแล้ว';
  if (isOwnLock(seat)) return 'คุณล็อคที่นั่งนี้ไว้ — คลิกเพื่อเลือกอีกครั้ง';
  if (seat?.status === 'locked') return 'มีผู้ใช้อื่นกำลังจอง...';
  return 'คลิกเพื่อเลือก';
}

// ─── Inline SeatButton ────────────────────────────────────────────
const SeatButton = (btnProps, { emit: btnEmit }) => {
  const seat = btnProps.seat;
  const seatId = btnProps.seatId;
  const disabled = props.readonly || isBlocked(seat);

  const statusText = () => {
    const label = seatStatusLabel(seat);
    if (!label) return null;
    return h('span', { class: ['text-[9px] font-bold leading-none', label.class] }, label.text);
  };

  return h('div', { class: 'relative group' }, [
    h('button', {
      disabled,
      onClick: () => { if (!disabled) btnEmit('click'); },
      class: [
        'group flex flex-col items-center gap-1 transition-all duration-200',
        props.readonly ? 'cursor-default' : disabled ? 'cursor-not-allowed' : 'cursor-pointer',
      ],
      title: seatTitle(seat),
    }, [
      h('div', {
        class: ['w-12 h-12 rounded-2xl flex items-center justify-center transition-all duration-200 border-2', seatBgClass(seat)],
      }, [
        h('span', {
          class: ['material-symbols-rounded text-[20px] transition-all duration-200', seatIconClass(seat)],
          style: "font-variation-settings:'FILL' 1,'wght' 400",
        }, 'airline_seat_recline_normal'),
      ]),
      h('span', {
        class: ['text-[10px] font-extrabold leading-none transition-colors', seatLabelClass(seat)],
      }, seat?.label ?? seatId),
      statusText(),
    ]),
    (props.showNames && seat?.passenger_name) ? h('div', {
      class: 'absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2.5 py-1.5 bg-gray-900 text-white text-[10px] rounded-xl opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap z-50 font-bold'
    }, seat.passenger_name) : null
  ]);
};
SeatButton.props = { seat: Object, seatId: String, isWomenOnly: Boolean };
SeatButton.emits = ['click'];
</script>

<style scoped>
:deep(.material-symbols-rounded) {
  font-family: 'Material Symbols Rounded' !important;
  font-weight: normal !important;
  font-style: normal !important;
  line-height: 1;
  letter-spacing: normal;
  text-transform: none;
  white-space: nowrap;
  word-wrap: normal;
  direction: ltr;
  -webkit-font-smoothing: antialiased;
  text-rendering: optimizeLegibility;
  font-feature-settings: 'liga';
}
</style>
