<template>
  <div v-if="!seatMap || !seatMap.has_seat_map" class="text-center py-10 text-[#6e7979]">
    <span class="material-symbols-rounded text-5xl text-[#bdc9c8] mb-3 block" style="font-variation-settings:'FILL' 0,'wght' 300">airline_seat_recline_normal</span>
    <p class="font-medium">ทริปนี้ไม่มีผังที่นั่ง</p>
    <p class="text-sm mt-1">ที่นั่งว่าง: {{ seatMap?.available_seats ?? 0 }} / {{ seatMap?.total_seats ?? 0 }}</p>
  </div>

  <div v-else class="space-y-6">
    <!-- Legend -->
    <div class="flex flex-wrap gap-5 p-5 bg-[#f3f3f3] rounded-2xl text-sm">
      <div class="flex items-center gap-2">
        <div class="w-6 h-6 rounded-lg bg-[#e2e2e2]"></div>
        <span class="text-[#3e4949]">ว่าง</span>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-6 h-6 rounded-lg transition-colors duration-300" :class="isWomenOnly ? 'bg-[#db2777] shadow-lg shadow-[#db2777]/20 border border-white/20' : 'bg-[#006565]'"></div>
        <span class="text-[#3e4949]">กำลังเลือก</span>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-6 h-6 rounded-lg bg-[#bdc9c8] opacity-60"></div>
        <span class="text-[#3e4949]">ล็อคอยู่</span>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-6 h-6 rounded-lg bg-[#6e7979]"></div>
        <span class="text-[#3e4949]">จองแล้ว</span>
      </div>
    </div>

    <!-- Van layout container -->
    <div class="relative bg-white rounded-3xl p-6 md:p-10 shadow-xl border border-[#bdc9c8]/20 overflow-hidden">
      <div class="absolute -top-20 -right-20 w-56 h-56 bg-opacity-10 rounded-full blur-3xl pointer-events-none" :class="isWomenOnly ? 'bg-[#db2777]/5' : 'bg-[#006565]/5'"></div>
      <div class="absolute -bottom-20 -left-20 w-56 h-56 bg-[#9e380d]/5 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative max-w-sm mx-auto">

        <!-- Front of Van: Front passenger (LEFT) + Driver (RIGHT) -->
        <div class="flex items-end justify-between mb-8 pb-6 border-b-2 border-dashed border-[#bdc9c8]/50">
          <!-- Front passenger seat -->
          <button
            v-if="frontPassengerSeat"
            :disabled="frontPassengerSeat.status === 'booked' || frontPassengerSeat.status === 'locked'"
            @click="handleSeatClick(frontPassengerSeat)"
            class="group flex flex-col items-center transition-all duration-200 shrink-0"
            :class="frontPassengerSeat.status === 'booked' || frontPassengerSeat.status === 'locked' ? 'cursor-not-allowed' : 'cursor-pointer'"
            :title="frontPassengerSeat.status === 'booked' ? 'จองโดย ' + (frontPassengerSeat.passenger_name || 'ไ่ม่ระบุชื่อ') : ''"
          >
            <div class="w-12 h-12 md:w-14 md:h-14 rounded-2xl flex items-center justify-center transition-all duration-200"
              :class="seatBgClass(frontPassengerSeat)">
              <span class="material-symbols-rounded text-xl transition-all duration-200"
                :class="seatIconClass(frontPassengerSeat)"
                style="font-variation-settings:'FILL' 1,'wght' 400">airline_seat_recline_normal</span>
            </div>
            <span class="text-[10px] mt-1 font-bold transition-colors" :class="seatLabelClass(frontPassengerSeat)">
              {{ frontPassengerSeat.label ?? frontPassengerSeat.id }}
            </span>
            <span v-if="frontPassengerSeat.status === 'booked'" class="text-[9px] text-gray-400 truncate w-14 text-center -mt-0.5 font-medium">
              {{ frontPassengerSeat.passenger_name || '...' }}
            </span>
          </button>
          <div v-else class="w-12 md:w-14 shrink-0"></div>

          <!-- หน้ารถ label -->
          <div class="flex-1 flex justify-center">
            <span class="px-3 py-1 rounded-full text-xs font-bold tracking-widest uppercase transition-colors duration-300"
              :class="isWomenOnly ? 'bg-[#db2777]/10 text-[#db2777]' : 'bg-[#006565]/10 text-[#006565]'">หน้ารถ</span>
          </div>

          <!-- Driver -->
          <div class="flex flex-col items-center opacity-50 shrink-0">
            <div class="w-12 h-12 rounded-xl bg-[#e8e8e8] flex items-center justify-center">
              <span class="material-symbols-rounded text-2xl text-[#3e4949]" style="font-variation-settings:'FILL' 0,'wght' 400">directions_car</span>
            </div>
            <span class="text-xs mt-1.5 font-medium text-[#6e7979]">คนขับ</span>
          </div>
        </div>

        <!-- Seat rows -->
        <div class="space-y-4">
          <div
            v-for="(rowDef, rowIdx) in vanBodyRows"
            :key="rowIdx"
            class="flex items-center justify-center gap-1 pl-18"
          >
            <!-- Left group -->
            <div class="flex gap-2">
              <template v-for="seatId in rowDef.left" :key="seatId">
                <button
                  :disabled="getSeat(seatId)?.status === 'booked' || getSeat(seatId)?.status === 'locked'"
                  @click="handleSeatClick(getSeat(seatId))"
                  class="group flex flex-col items-center transition-all duration-200"
                  :class="getSeat(seatId)?.status === 'booked' || getSeat(seatId)?.status === 'locked' ? 'cursor-not-allowed' : 'cursor-pointer'"
                  :title="getSeat(seatId)?.status === 'booked' ? 'จองโดย ' + (getSeat(seatId).passenger_name || 'ไม่ระบุชื่อ') : ''"
                >
                  <div class="w-12 h-12 md:w-14 md:h-14 rounded-2xl flex items-center justify-center transition-all duration-200"
                    :class="seatBgClass(getSeat(seatId))">
                    <span class="material-symbols-rounded text-xl transition-all duration-200"
                      :class="seatIconClass(getSeat(seatId))"
                      style="font-variation-settings:'FILL' 1,'wght' 400">airline_seat_recline_normal</span>
                  </div>
                  <span class="text-[10px] mt-1 font-bold transition-colors" :class="seatLabelClass(getSeat(seatId))">
                    {{ getSeat(seatId)?.label ?? seatId }}
                  </span>
                  <span v-if="getSeat(seatId)?.status === 'booked'" class="text-[9px] text-gray-400 truncate w-14 text-center -mt-0.5 font-medium">
                    {{ getSeat(seatId).passenger_name || '...' }}
                  </span>
                </button>
              </template>
            </div>

            <!-- Center group (for A4, B4, C4) -->
            <div v-if="rowDef.center && rowDef.center.length > 0" class="flex gap-3 ml-2">
              <template v-for="seatId in rowDef.center" :key="seatId">
                <button
                  :disabled="getSeat(seatId)?.status === 'booked' || getSeat(seatId)?.status === 'locked'"
                  @click="handleSeatClick(getSeat(seatId))"
                  class="group flex flex-col items-center transition-all duration-200"
                  :class="getSeat(seatId)?.status === 'booked' || getSeat(seatId)?.status === 'locked' ? 'cursor-not-allowed' : 'cursor-pointer'"
                  :title="getSeat(seatId)?.status === 'booked' ? 'จองโดย ' + (getSeat(seatId).passenger_name || 'ไม่ระบุชื่อ') : ''"
                >
                  <div class="w-12 h-12 md:w-14 md:h-14 rounded-2xl flex items-center justify-center transition-all duration-200"
                    :class="seatBgClass(getSeat(seatId))">
                    <span class="material-symbols-rounded text-xl transition-all duration-200"
                      :class="seatIconClass(getSeat(seatId))"
                      style="font-variation-settings:'FILL' 1,'wght' 400">airline_seat_recline_normal</span>
                  </div>
                  <span class="text-[10px] mt-1 font-bold transition-colors" :class="seatLabelClass(getSeat(seatId))">
                    {{ getSeat(seatId)?.label ?? seatId }}
                  </span>
                  <span v-if="getSeat(seatId)?.status === 'booked'" class="text-[9px] text-gray-400 truncate w-14 text-center -mt-0.5 font-medium">
                    {{ getSeat(seatId).passenger_name || '...' }}
                  </span>
                </button>
              </template>
            </div>

            <!-- Aisle -->
            <div v-if="rowDef.hasAisle" class="w-12 flex items-center justify-center px-1">
              <div class="w-0.5 h-12 bg-[#bdc9c8]/30 rounded-full mx-auto"></div>
            </div>
            <div v-else class="w-12"></div>

            <!-- Right group -->
            <div class="flex gap-2">
              <template v-for="seatId in rowDef.right" :key="seatId">
                <button
                  :disabled="getSeat(seatId)?.status === 'booked' || getSeat(seatId)?.status === 'locked'"
                  @click="handleSeatClick(getSeat(seatId))"
                  class="group flex flex-col items-center transition-all duration-200"
                  :class="getSeat(seatId)?.status === 'booked' || getSeat(seatId)?.status === 'locked' ? 'cursor-not-allowed' : 'cursor-pointer'"
                  :title="getSeat(seatId)?.status === 'booked' ? 'จองโดย ' + (getSeat(seatId).passenger_name || 'ไม่ระบุชื่อ') : ''"
                >
                  <div class="w-12 h-12 md:w-14 md:h-14 rounded-2xl flex items-center justify-center transition-all duration-200"
                    :class="seatBgClass(getSeat(seatId))">
                    <span class="material-symbols-rounded text-xl transition-all duration-200"
                      :class="seatIconClass(getSeat(seatId))"
                      style="font-variation-settings:'FILL' 1,'wght' 400">airline_seat_recline_normal</span>
                  </div>
                  <span class="text-[10px] mt-1 font-bold transition-colors" :class="seatLabelClass(getSeat(seatId))">
                    {{ getSeat(seatId)?.label ?? seatId }}
                  </span>
                  <span v-if="getSeat(seatId)?.status === 'booked'" class="text-[9px] text-gray-400 truncate w-14 text-center -mt-0.5 font-medium">
                    {{ getSeat(seatId).passenger_name || '...' }}
                  </span>
                </button>
              </template>
            </div>
          </div>
        </div>

        <!-- Rear bumper -->
        <div class="mt-8 pt-5 border-t-2 border-dashed border-[#bdc9c8]/50 flex justify-center">
          <span class="px-3 py-1 rounded-full bg-[#f3f3f3] text-[#6e7979] text-xs font-bold tracking-widest uppercase">ท้ายรถ (สำหรับเก็บสัมภาระ)</span>
        </div>
      </div>
    </div>

    <!-- Seat availability info -->
    <div class="text-center text-sm text-[#6e7979]">
      ที่นั่งว่าง: <span class="font-bold transition-colors duration-300" :class="isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]'">{{ seatMap.available_seats }}</span> / {{ seatMap.total_seats }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useSeatsStore } from '../stores/seats';

const seatsStore = useSeatsStore();

const props = defineProps({
  seatMap: { type: Object, default: null },
  isWomenOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['seat-click']);

function seatBgClass(seat) {
  if (!seat) return 'bg-[#e2e2e2]';
  if (seat.status === 'booked') return 'bg-[#6e7979]';
  if (seat.status === 'locked') return 'bg-[#bdc9c8] opacity-60';
  
  if (isSelected(seat)) {
    return (props.isWomenOnly ? 'bg-[#db2777] shadow-[#db2777]/25' : 'bg-[#006565] shadow-[#006565]/25') + ' shadow-lg scale-105';
  }
  
  return 'bg-[#e2e2e2] ' + (props.isWomenOnly ? 'group-hover:bg-[#db2777]/10' : 'group-hover:bg-[#006565]/10') + ' group-hover:scale-105';
}

function seatIconClass(seat) {
  if (!seat) return 'text-[#6e7979]';
  if (seat.status === 'booked') return 'text-white';
  if (seat.status === 'locked') return 'text-[#6e7979]';
  if (isSelected(seat)) return 'text-white';
  return 'text-[#6e7979] ' + (props.isWomenOnly ? 'group-hover:text-[#db2777]' : 'group-hover:text-[#006565]');
}

function seatLabelClass(seat) {
  if (!seat) return 'text-[#6e7979]';
  if (seat.status === 'booked' || seat.status === 'locked') return 'text-[#6e7979] opacity-40';
  if (isSelected(seat)) return (props.isWomenOnly ? 'text-[#db2777]' : 'text-[#006565]');
  return 'text-[#6e7979] ' + (props.isWomenOnly ? 'group-hover:text-[#db2777]' : 'group-hover:text-[#006565]');
}

const frontPassengerSeat = computed(() => {
  if (!props.seatMap?.seats || !props.seatMap?.columns) return null;
  const firstCol = props.seatMap.columns.find(c => c !== '');
  if (!firstCol) return null;
  return props.seatMap.seats.find(s => s.id === firstCol + '1') ?? null;
});

const vanRows = computed(() => {
  if (!props.seatMap?.seats) return [];

  const rows = props.seatMap.rows ?? 0;
  const columns = props.seatMap.columns ?? [];

  const result = [];

  for (let r = 1; r <= rows; r++) {
    const left = [];
    const right = [];
    const center = [];
    let hasAisle = false;
    let inRight = false;

    for (const col of columns) {
      if (col === '') {
        hasAisle = true;
        inRight = true;
        continue;
      }
      const seatId = col + r;
      const exists = props.seatMap.seats.some(s => s.id === seatId);
      if (!exists) continue;

      // Check if this is A4, B4, or C4 - move to center
      if ((col === 'A' || col === 'B' || col === 'C') && r === 4) {
        center.push(seatId);
      } else if (inRight) {
        right.push(seatId);
      } else {
        left.push(seatId);
      }
    }

    result.push({ left, right, center, hasAisle: hasAisle && right.length > 0 });
  }

  return result;
});

const vanBodyRows = computed(() => {
  if (!frontPassengerSeat.value) return vanRows.value;
  return vanRows.value.filter(row => {
    const allIds = [...row.left, ...row.right, ...row.center];
    return !allIds.includes(frontPassengerSeat.value.id);
  });
});

function getSeat(id) {
  return props.seatMap?.seats?.find(s => s.id === id) || null;
}

function isSelected(seat) {
  return seatsStore.selectedSeats.some(s => s.id === seat?.id);
}

function handleSeatClick(seat) {
  if (!seat || seat.status === 'booked' || seat.status === 'locked') return;
  seatsStore.toggleSeat(seat);
  emit('seat-click', seat);
}
</script>
