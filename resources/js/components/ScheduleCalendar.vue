<template>
  <div class="schedule-calendar">
    <!-- Month navigation -->
    <div class="flex items-center justify-between gap-2 mb-3">
      <button
        type="button"
        @click="goPrevMonth"
        :disabled="!hasPrevMonth"
        class="w-9 h-9 rounded-xl flex items-center justify-center border transition-all shrink-0"
        :class="hasPrevMonth
          ? 'bg-white border-gray-200 text-[var(--color-text-dark)] hover:border-[var(--color-accent)]/50 hover:text-[var(--color-accent)] active:scale-95'
          : 'bg-gray-50 border-gray-100 text-gray-300 cursor-not-allowed'"
        aria-label="เดือนก่อนหน้า"
      >
        <span class="material-symbols-rounded text-[20px]">chevron_left</span>
      </button>

      <div class="text-center min-w-0">
        <p class="font-black text-[var(--color-text-dark)] text-base leading-tight truncate">{{ currentMonthLabel }}</p>
        <p class="text-[11px] font-bold leading-tight"
          :class="currentMonthAvailableDays > 0 ? 'text-[var(--color-accent)]' : 'text-gray-400'">
          {{ currentMonthAvailableDays > 0 ? `ว่าง ${currentMonthAvailableDays} วัน` : 'ไม่มีวันที่ว่าง' }}
        </p>
      </div>

      <button
        type="button"
        @click="goNextMonth"
        :disabled="!hasNextMonth"
        class="w-9 h-9 rounded-xl flex items-center justify-center border transition-all shrink-0"
        :class="hasNextMonth
          ? 'bg-white border-gray-200 text-[var(--color-text-dark)] hover:border-[var(--color-accent)]/50 hover:text-[var(--color-accent)] active:scale-95'
          : 'bg-gray-50 border-gray-100 text-gray-300 cursor-not-allowed'"
        aria-label="เดือนถัดไป"
      >
        <span class="material-symbols-rounded text-[20px]">chevron_right</span>
      </button>
    </div>

    <!-- Weekday headers -->
    <div class="grid grid-cols-7 gap-1 sm:gap-1.5 mb-1">
      <div
        v-for="(w, i) in weekdays"
        :key="i"
        class="text-center text-[10px] sm:text-[11px] font-black uppercase tracking-wide py-1"
        :class="i === 0 ? 'text-red-400' : 'text-gray-400'"
      >{{ w }}</div>
    </div>

    <!-- Day grid -->
    <div class="grid grid-cols-7 gap-1 sm:gap-1.5">
      <template v-for="cell in calendarCells" :key="cell.key">
        <!-- Padding cell -->
        <div v-if="!cell.inMonth" class="aspect-square sm:aspect-auto sm:min-h-[58px]"></div>

        <!-- Selectable day (has departure rounds) -->
        <button
          v-else-if="cell.hasSchedules"
          type="button"
          :disabled="!cell.bookable"
          @click="selectDate(cell)"
          class="aspect-square sm:aspect-auto sm:min-h-[58px] rounded-xl border-2 flex flex-col items-center justify-center gap-0.5 px-0.5 py-1 transition-all duration-150 relative overflow-hidden"
          :class="cellClass(cell)"
        >
          <span
            class="text-sm sm:text-base font-black leading-none"
            :class="{ 'line-through decoration-2': !cell.bookable }"
          >{{ cell.day }}</span>

          <!-- Availability indicator -->
          <span
            v-if="cell.bookable"
            class="text-[9px] sm:text-[10px] font-black leading-none flex items-center gap-0.5"
            :class="availabilityTextClass(cell)"
          >
            <span class="w-1.5 h-1.5 rounded-full inline-block" :class="availabilityDotClass(cell)"></span>
            <span class="hidden sm:inline">{{ cellSeatLabel(cell) }}</span>
            <span class="sm:hidden">{{ cell.joinOnly ? '✓' : cell.totalAvailable }}</span>
          </span>
          <span v-else class="text-[9px] sm:text-[10px] font-black leading-none text-red-400">เต็ม</span>

          <!-- Multi-round badge -->
          <span
            v-if="cell.roundCount > 1"
            class="absolute top-0.5 right-0.5 text-[8px] sm:text-[9px] font-black px-1 rounded-full leading-tight"
            :class="cellRangeRole(cell) === 'start' || cellRangeRole(cell) === 'single'
              ? 'bg-white/25 text-white'
              : 'bg-[var(--color-accent)]/15 text-[var(--color-accent)]'"
          >{{ cell.roundCount }}</span>
        </button>

        <!-- Covered day inside the selected multi-day trip (no own departure) -->
        <div
          v-else-if="cellInRange(cell)"
          class="aspect-square sm:aspect-auto sm:min-h-[58px] rounded-xl border-2 border-[var(--color-accent)]/25 bg-[var(--color-accent)]/12 flex flex-col items-center justify-center gap-0.5"
        >
          <span class="text-sm sm:text-base font-black leading-none text-[var(--color-accent)]">{{ cell.day }}</span>
          <span class="text-[8px] sm:text-[9px] font-black leading-none text-[var(--color-accent)]/70 flex items-center gap-0.5">
            <span class="material-symbols-rounded text-[10px] sm:text-[11px]">{{ cellRangeRole(cell) === 'end' ? 'flag' : 'more_horiz' }}</span>
            <span class="hidden sm:inline">{{ cellRangeRole(cell) === 'end' ? 'กลับ' : 'ระหว่างทริป' }}</span>
          </span>
        </div>

        <!-- Empty day -->
        <div v-else class="aspect-square sm:aspect-auto sm:min-h-[58px] rounded-xl flex items-center justify-center">
          <span class="text-sm font-bold" :class="cell.isPast ? 'text-gray-200' : 'text-gray-300'">{{ cell.day }}</span>
        </div>
      </template>
    </div>

    <!-- Legend -->
    <div class="flex items-center justify-center flex-wrap gap-x-4 gap-y-1.5 mt-3 text-[10px] font-bold text-gray-400">
      <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-[var(--color-accent)]"></span>ว่าง</span>
      <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-400"></span>ใกล้เต็ม</span>
      <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-400"></span>เต็ม</span>
      <span class="flex items-center gap-1"><span class="w-3 h-2 rounded-sm bg-[var(--color-accent)]/20 border border-[var(--color-accent)]/30"></span>ช่วงทริปหลายวัน</span>
      <span class="flex items-center gap-1"><span class="text-[var(--color-accent)] font-black">2</span>= จำนวนรอบ</span>
    </div>

    <!-- Selected date — round picker -->
    <div v-if="activeDateSchedules.length" class="mt-4 pt-4 border-t border-gray-100 animate-fade-in">
      <div class="flex items-center gap-2 mb-3">
        <span class="material-symbols-rounded text-[var(--color-accent)] text-[18px]">event_available</span>
        <p class="font-black text-sm text-[var(--color-text-dark)]">{{ activeDateLabel }}</p>
        <span
          v-if="activeDateSchedules.length > 1"
          class="ml-auto text-[10px] font-black px-2 py-0.5 rounded-full bg-[var(--color-accent)]/10 text-[var(--color-accent)] border border-[var(--color-accent)]/15"
        >{{ activeDateSchedules.length }} รอบ</span>
      </div>

      <div class="space-y-2">
        <div
          v-for="(s, idx) in activeDateSchedules"
          :key="s.id"
          @click="isScheduleBookable(s) && emit('select', s)"
          class="border-2 rounded-2xl p-3.5 transition-all duration-200"
          :class="[
            selectedSchedule?.id === s.id
              ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5 shadow-md shadow-[var(--color-accent)]/10'
              : isScheduleBookable(s)
                ? 'border-gray-100 bg-white hover:border-[var(--color-accent)]/40 hover:bg-[var(--color-sand)] hover:shadow-sm cursor-pointer'
                : 'border-gray-100 bg-gray-50 opacity-60 cursor-not-allowed'
          ]"
        >
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
              <span
                v-if="activeDateSchedules.length > 1"
                class="text-[10px] font-black text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full shrink-0"
              >รอบ {{ idx + 1 }}</span>
              <span
                class="inline-flex items-center gap-1.5 text-[11px] font-black px-2.5 py-1 rounded-full border"
                :class="scheduleAvailabilityBadgeClass(s)"
              >
                <span class="material-symbols-rounded text-[12px]"
                  :class="{ 'animate-pulse': !s.is_charter && (!hasAvailableSeats(s) || s.available_seats <= 3) }"
                >{{ s.is_charter ? 'lock' : !hasAvailableSeats(s) ? 'block' : s.available_seats <= 3 ? 'warning' : 'event_seat' }}</span>
                {{ scheduleAvailabilityLabel(s) }}
              </span>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <span class="text-[11px] font-black text-[var(--color-text-dark)]">฿{{ Number(s.price ?? 0).toLocaleString() }}</span>
              <button
                v-if="s.total_seats > 0"
                @click.stop="emit('preview-seats', s)"
                class="flex items-center gap-1 text-[10px] font-bold text-gray-400 hover:text-[var(--color-accent)] transition-colors px-1.5 py-1 rounded-lg hover:bg-[var(--color-accent)]/8"
              >
                <span class="material-symbols-rounded text-[15px]">grid_view</span>
                ดูผัง
              </button>
              <span class="material-symbols-rounded text-[20px] transition-transform duration-300"
                :class="selectedSchedule?.id === s.id ? 'text-[var(--color-accent)] rotate-180' : 'text-gray-300'"
              >expand_more</span>
            </div>
          </div>

          <!-- Expanded: pickup points -->
          <div v-if="selectedSchedule?.id === s.id" class="mt-3 pt-3 border-t border-[var(--color-accent)]/15">
            <div v-if="pickupPointsFor(s).length" class="space-y-2">
              <div v-for="pt in pickupPointsFor(s)" :key="pt.id" class="space-y-1">
                <div class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[13px] text-red-400 shrink-0">pin_drop</span>
                  <span class="text-[11px] font-bold text-[var(--color-text-dark)]">{{ pt.pickup_location }}</span>
                  <span v-if="pt.notes" class="text-[10px] text-gray-400 truncate">· {{ pt.notes }}</span>
                </div>
                <a v-if="pt.map_url" :href="pt.map_url" target="_blank" @click.stop
                  class="ml-5 flex items-center gap-1 text-[10px] font-bold text-[var(--color-accent)] hover:underline"
                >
                  <span class="material-symbols-rounded text-[12px]">map</span>ดูแผนที่
                </a>
              </div>
            </div>
            <p v-else class="text-[11px] text-gray-400 font-medium">
              {{ isTrekking ? 'ไม่มีข้อมูลจุดรับสำหรับภูมิภาคนี้' : 'ไม่มีข้อมูลจุดรับ' }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Hint when nothing selected -->
    <p v-else class="mt-3 text-center text-[11px] font-bold text-gray-400 flex items-center justify-center gap-1.5">
      <span class="material-symbols-rounded text-[15px]">touch_app</span>
      แตะวันที่ในปฏิทินเพื่อเลือกรอบเดินทาง
    </p>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import {
  hasAvailableSeats,
  isScheduleBookable,
  scheduleAvailabilityBadgeClass,
  scheduleAvailabilityLabel,
  getSortedPickupPoints,
} from '../lib/scheduleHelpers';

const props = defineProps({
  schedules: { type: Array, default: () => [] },
  selectedSchedule: { type: Object, default: null },
  isTrekking: { type: Boolean, default: false },
  selectedRegion: { type: String, default: null },
});

const emit = defineEmits(['select', 'preview-seats']);

const weekdays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

const todayKey = (() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

function dateKeyOf(schedule) {
  return String(schedule?.departure_date || '').slice(0, 10);
}

// Map of "YYYY-MM-DD" -> schedules[]
const schedulesByDate = computed(() => {
  const map = new Map();
  for (const s of props.schedules) {
    const key = dateKeyOf(s);
    if (!key) continue;
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(s);
  }
  // Stable order within a day
  for (const list of map.values()) {
    list.sort((a, b) => Number(a?.id || 0) - Number(b?.id || 0));
  }
  return map;
});

// Sorted "YYYY-M" month keys that contain at least one schedule
const availableMonths = computed(() => {
  const set = new Set();
  for (const key of schedulesByDate.value.keys()) {
    const d = new Date(key + 'T00:00:00');
    set.add(`${d.getFullYear()}-${d.getMonth()}`);
  }
  return [...set].sort((a, b) => {
    const [ay, am] = a.split('-').map(Number);
    const [by, bm] = b.split('-').map(Number);
    return ay - by || am - bm;
  });
});

const currentMonthKey = ref(null);

function pickDefaultMonth(months) {
  if (!months.length) return null;
  const now = new Date();
  const nowKey = `${now.getFullYear()}-${now.getMonth()}`;
  if (months.includes(nowKey)) return nowKey;
  const future = months.find((m) => {
    const [y, mo] = m.split('-').map(Number);
    return new Date(y, mo, 1) >= new Date(now.getFullYear(), now.getMonth(), 1);
  });
  return future || months[0];
}

// Keep current month valid as the schedule list / region changes
watch(availableMonths, (months) => {
  if (!months.length) {
    currentMonthKey.value = null;
    return;
  }
  if (currentMonthKey.value && months.includes(currentMonthKey.value)) return;
  currentMonthKey.value = pickDefaultMonth(months);
}, { immediate: true });

const currentMonthIndex = computed(() => availableMonths.value.indexOf(currentMonthKey.value));
const hasPrevMonth = computed(() => currentMonthIndex.value > 0);
const hasNextMonth = computed(() =>
  currentMonthIndex.value >= 0 && currentMonthIndex.value < availableMonths.value.length - 1
);

function goPrevMonth() {
  if (hasPrevMonth.value) currentMonthKey.value = availableMonths.value[currentMonthIndex.value - 1];
}
function goNextMonth() {
  if (hasNextMonth.value) currentMonthKey.value = availableMonths.value[currentMonthIndex.value + 1];
}

const currentMonthLabel = computed(() => {
  if (!currentMonthKey.value) return '';
  const [y, m] = currentMonthKey.value.split('-').map(Number);
  return new Date(y, m, 1).toLocaleDateString('th-TH', { month: 'long', year: 'numeric' });
});

const calendarCells = computed(() => {
  if (!currentMonthKey.value) return [];
  const [year, month] = currentMonthKey.value.split('-').map(Number);
  const startOffset = new Date(year, month, 1).getDay(); // 0 = Sunday
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  const cells = [];
  for (let i = 0; i < startOffset; i++) cells.push({ key: `pad-${i}`, inMonth: false });

  for (let day = 1; day <= daysInMonth; day++) {
    const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const daySchedules = schedulesByDate.value.get(dateKey) || [];
    const bookable = daySchedules.some(isScheduleBookable);
    const totalAvailable = daySchedules.reduce(
      (sum, s) => sum + (s.is_charter ? 0 : Number(s.available_seats || 0)),
      0
    );
    const joinOnly = bookable && totalAvailable === 0 && daySchedules.some((s) => s.join_trip_enabled);

    cells.push({
      key: dateKey,
      inMonth: true,
      day,
      dateKey,
      schedules: daySchedules,
      hasSchedules: daySchedules.length > 0,
      roundCount: daySchedules.length,
      bookable,
      totalAvailable,
      joinOnly,
      isPast: dateKey < todayKey,
    });
  }
  return cells;
});

const currentMonthAvailableDays = computed(() =>
  calendarCells.value.filter((c) => c.inMonth && c.bookable).length
);

// ── Selection / highlight ──
// Exactly one day-range is ever highlighted: the active (tapped) day wins, and
// when its chosen round spans several days the whole departure→return is covered.
const activeDateKey = ref(null);

const selectedRange = computed(() => {
  const s = props.selectedSchedule;
  const selectedKey = s && s.departure_date ? dateKeyOf(s) : null;

  // The tapped day is the anchor. Cover the full trip span only when the
  // selected round actually belongs to that same day.
  if (activeDateKey.value) {
    if (selectedKey === activeDateKey.value) return spanOf(s);
    return { start: activeDateKey.value, end: activeDateKey.value };
  }

  // No tap yet but something is selected externally (e.g. a query param).
  if (selectedKey) return spanOf(s);
  return null;
});

function spanOf(schedule) {
  const start = dateKeyOf(schedule);
  const end = schedule?.return_date ? String(schedule.return_date).slice(0, 10) : start;
  return { start, end: end > start ? end : start };
}

function cellInRange(cell) {
  const r = selectedRange.value;
  return !!r && cell.dateKey >= r.start && cell.dateKey <= r.end;
}

function cellRangeRole(cell) {
  const r = selectedRange.value;
  if (!r || cell.dateKey < r.start || cell.dateKey > r.end) return null;
  if (r.start === r.end) return 'single';
  if (cell.dateKey === r.start) return 'start';
  if (cell.dateKey === r.end) return 'end';
  return 'middle';
}

function isAnchorCell(cell) {
  const role = cellRangeRole(cell);
  return role === 'start' || role === 'single';
}

function cellClass(cell) {
  if (!cell.bookable) {
    return 'border-gray-100 bg-gray-50 text-gray-300 cursor-not-allowed';
  }
  if (isAnchorCell(cell)) {
    return 'border-[var(--color-accent)] bg-[var(--color-accent)] text-white shadow-md shadow-[var(--color-accent)]/20';
  }
  if (cellInRange(cell)) {
    return 'border-[var(--color-accent)]/30 bg-[var(--color-accent)]/12 text-[var(--color-accent)]';
  }
  return 'border-gray-100 bg-white text-[var(--color-text-dark)] hover:border-[var(--color-accent)]/50 hover:bg-[var(--color-sand)] active:scale-95 cursor-pointer';
}

function availabilityDotClass(cell) {
  if (isAnchorCell(cell)) return 'bg-white';
  if (cell.joinOnly) return 'bg-[var(--color-accent)]';
  if (cell.totalAvailable <= 3) return 'bg-amber-400';
  return 'bg-[var(--color-accent)]';
}

function availabilityTextClass(cell) {
  if (isAnchorCell(cell)) return 'text-white';
  if (cell.totalAvailable <= 3 && !cell.joinOnly) return 'text-amber-600';
  return 'text-[var(--color-accent)]';
}

function cellSeatLabel(cell) {
  if (cell.joinOnly) return 'จองได้';
  return `ว่าง ${cell.totalAvailable}`;
}

function selectDate(cell) {
  if (!cell.bookable) return;
  activeDateKey.value = cell.dateKey;
  const bookables = cell.schedules.filter(isScheduleBookable);
  if (bookables.length === 1) {
    // Single round: select it outright.
    emit('select', bookables[0]);
  } else if (props.selectedSchedule && dateKeyOf(props.selectedSchedule) !== cell.dateKey) {
    // Moved to a different multi-round day — clear the stale selection so the
    // booking panel and the green highlight stay on this one day only.
    emit('select', null);
  }
}

const activeDateSchedules = computed(() => {
  if (!activeDateKey.value) return [];
  return schedulesByDate.value.get(activeDateKey.value) || [];
});

const activeDateLabel = computed(() => {
  if (!activeDateKey.value) return '';
  return new Date(activeDateKey.value + 'T00:00:00')
    .toLocaleDateString('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
});

function pickupPointsFor(schedule) {
  let points = schedule?.pickup_points || [];
  if (props.isTrekking && props.selectedRegion) {
    points = points.filter((pt) => pt.region === props.selectedRegion);
  }
  return getSortedPickupPoints(points);
}

// Reflect an externally-selected schedule (e.g. from a query param) by opening its month + date
watch(() => props.selectedSchedule, (s) => {
  if (s && s.departure_date) {
    const key = dateKeyOf(s);
    activeDateKey.value = key;
    const d = new Date(key + 'T00:00:00');
    currentMonthKey.value = `${d.getFullYear()}-${d.getMonth()}`;
  }
}, { immediate: true });

// When the schedule set changes (e.g. region switch), drop an active date that no longer exists
watch(schedulesByDate, (map) => {
  if (activeDateKey.value && !map.has(activeDateKey.value)) {
    activeDateKey.value = null;
  }
});
</script>

<style scoped>
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(6px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
  animation: fadeIn 0.3s ease-out forwards;
}
</style>
