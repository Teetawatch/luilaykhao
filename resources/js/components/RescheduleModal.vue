<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 sm:px-4" @click.self="close">
      <div class="bg-white w-full sm:max-w-lg rounded-t-[24px] sm:rounded-[24px] overflow-hidden flex flex-col max-h-[92vh]">
        <!-- Header -->
        <div class="px-5 py-4 border-b border-[#E8EEEF] flex items-start justify-between gap-3 shrink-0">
          <div class="min-w-0">
            <h3 class="text-lg font-bold text-[#1a1c1c]">
              {{ isForceMajeure ? 'เลือกรอบเดินทางใหม่' : 'เปลี่ยนวันเดินทาง' }}
            </h3>
            <p class="text-xs text-[#505E5E] mt-0.5 truncate">
              {{ booking.schedule?.trip?.title || 'ทริป' }} · {{ booking.booking_ref }}
            </p>
          </div>
          <button @click="close" :disabled="submitting"
            class="w-8 h-8 rounded-lg border border-[#E8EEEF] hover:bg-[#F4F7F6] inline-flex items-center justify-center shrink-0"
            aria-label="ปิด">
            <span class="material-symbols-rounded text-[18px]">close</span>
          </button>
        </div>

        <div class="p-5 space-y-5 overflow-y-auto">
          <!-- กติกาของสิทธิ์ที่กำลังใช้ -->
          <div v-if="isForceMajeure" class="rounded-[16px] bg-[#FFFBEB] border border-[#FDE68A] p-4 text-sm text-[#78350F] space-y-1.5">
            <p class="font-bold flex items-center gap-1.5">
              <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">thunderstorm</span>
              รอบเดิม{{ fm.original_departure_label ? ` ${fm.original_departure_label}` : '' }} ออกเดินทางไม่ได้
            </p>
            <p v-if="fm.reason" class="text-[13px]">เนื่องจาก{{ fm.reason }}</p>
            <ul class="text-[13px] list-disc pl-5 space-y-0.5">
              <li>ยอดที่ชำระไว้ยังอยู่ครบ <strong>ราคาเดิม ไม่มีค่าธรรมเนียม</strong></li>
              <li>เลือกรอบที่ออกเดินทางได้ถึง <strong>{{ fm.until_label }}</strong></li>
              <li>ไม่นับรวมกับสิทธิ์เลื่อนวันเดินทางตามปกติ</li>
            </ul>
          </div>
          <div v-else class="rounded-[16px] bg-[#F0FAFA] border border-[#BCDFDF] p-4 text-[13px] text-[#0F3D3E]">
            <ul class="list-disc pl-5 space-y-0.5">
              <li>เปลี่ยนได้ครั้งเดียวเท่านั้น</li>
              <li>ต้องเปลี่ยนก่อนเดินทางอย่างน้อย 20 วัน<template v-if="deadlineLabel"> (ภายใน {{ deadlineLabel }})</template></li>
              <li>คงราคาเดิม · เลือกได้เฉพาะรอบของทริปนี้</li>
            </ul>
          </div>

          <!-- ขั้นที่ 1: เลือกรอบ -->
          <section>
            <p class="text-sm font-bold text-[#1a1c1c] mb-2.5">เลือกรอบเดินทางใหม่</p>

            <div v-if="loading" class="py-10 flex justify-center">
              <div class="w-8 h-8 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
            </div>

            <p v-else-if="loadError" class="text-sm text-[#DC2626] bg-[#FEF2F2] border border-[#FECACA] rounded-[12px] p-3">
              {{ loadError }}
              <button class="underline font-bold ml-1" @click="loadSchedules">ลองใหม่</button>
            </p>

            <div v-else-if="!options.length" class="text-center text-sm text-[#505E5E] bg-[#F4F7F6] rounded-[16px] px-4 py-6">
              <template v-if="isForceMajeure">
                ตอนนี้ยังไม่มีรอบที่เปิดในช่วงนี้<br />
                เปิดรอบใหม่เมื่อไหร่ เราจะแจ้งให้ทราบทันทีครับ
              </template>
              <template v-else>ไม่มีรอบเดินทางอื่นให้เลือกในขณะนี้</template>
            </div>

            <div v-else class="space-y-2">
              <button
                v-for="s in options"
                :key="s.id"
                type="button"
                :disabled="!s.fits || submitting"
                @click="selectSchedule(s)"
                class="w-full text-left rounded-[14px] border-2 px-4 py-3 flex items-center gap-3 transition"
                :class="selected?.id === s.id
                  ? 'border-[#006565] bg-[#F0FAFA]'
                  : s.fits ? 'border-[#E8EEEF] hover:border-[#006565]/40 bg-white' : 'border-[#E8EEEF] bg-[#F9FAFA] opacity-60 cursor-not-allowed'">
                <span class="material-symbols-rounded text-[20px]"
                  :class="selected?.id === s.id ? 'text-[#006565]' : 'text-[#A0B0B0]'"
                  :style="selected?.id === s.id ? 'font-variation-settings:\'FILL\' 1' : ''">
                  {{ selected?.id === s.id ? 'radio_button_checked' : 'radio_button_unchecked' }}
                </span>
                <span class="flex-1 min-w-0">
                  <span class="block font-bold text-[#1a1c1c] text-sm">{{ formatDate(s.departure_date) }}</span>
                  <span v-if="s.early_departure_label" class="block text-[11px] text-[#B45309] font-semibold">{{ s.early_departure_label }}</span>
                  <span class="block text-xs text-[#505E5E]">{{ s.nightsLabel }}</span>
                  <span v-if="s.hold" class="inline-flex items-center gap-1 mt-1 text-[11px] font-bold text-[#B45309] bg-[#FFFBEB] border border-[#FDE68A] rounded-full px-2 py-0.5">
                    <span class="material-symbols-rounded text-[13px]">lock_clock</span>
                    กันที่ไว้ให้คุณ {{ s.hold.seat_count }} ที่ ถึง {{ s.hold.expires_label }}
                  </span>
                </span>
                <span class="text-xs font-bold shrink-0" :class="s.fits ? 'text-[#006565]' : 'text-[#DC2626]'">
                  {{ s.fits ? `ว่าง ${s.seatsLeft} ที่` : 'ที่นั่งไม่พอ' }}
                </span>
              </button>
            </div>
          </section>

          <!-- ขั้นที่ 2: ที่นั่ง (เฉพาะใบที่มีที่นั่งและรอบใหม่มีผัง) -->
          <section v-if="selected && needsSeats">
            <p class="text-sm font-bold text-[#1a1c1c] mb-2.5">ที่นั่งในรอบใหม่</p>
            <div class="grid grid-cols-2 gap-2 mb-3">
              <button type="button" @click="pickSeats = false"
                class="rounded-[12px] border-2 px-3 py-2.5 text-sm font-bold transition"
                :class="!pickSeats ? 'border-[#006565] bg-[#F0FAFA] text-[#006565]' : 'border-[#E8EEEF] text-[#505E5E]'">
                จัดที่นั่งให้อัตโนมัติ
              </button>
              <button type="button" @click="enableSeatPicking"
                class="rounded-[12px] border-2 px-3 py-2.5 text-sm font-bold transition"
                :class="pickSeats ? 'border-[#006565] bg-[#F0FAFA] text-[#006565]' : 'border-[#E8EEEF] text-[#505E5E]'">
                เลือกที่นั่งเอง
              </button>
            </div>

            <template v-if="pickSeats">
              <div v-if="seatsLoading" class="py-6 flex justify-center">
                <div class="w-7 h-7 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
              </div>
              <p v-else-if="!seatMap?.has_seat_map" class="text-xs text-[#505E5E]">
                รอบนี้ไม่มีผังที่นั่งให้เลือก ทีมงานจะจัดที่นั่งให้ครับ
              </p>
              <template v-else>
                <p class="text-xs text-[#505E5E] mb-2">เลือก {{ selectedSeats.length }}/{{ passengerCount }} ที่นั่ง</p>
                <div class="grid grid-cols-5 sm:grid-cols-6 gap-2">
                  <button
                    v-for="seat in seatMap.seats"
                    :key="seat.id"
                    type="button"
                    :disabled="!isSeatFree(seat)"
                    @click="toggleSeat(seat.id)"
                    class="h-10 rounded-[10px] border-2 text-xs font-bold transition"
                    :class="selectedSeats.includes(seat.id)
                      ? 'bg-[#006565] border-[#006565] text-white'
                      : isSeatFree(seat) ? 'bg-emerald-50 border-emerald-300 text-[#1a1c1c] hover:border-[#006565]' : 'bg-[#F4F7F6] border-[#E8EEEF] text-[#A0B0B0] cursor-not-allowed line-through'">
                    {{ seat.label || seat.id }}
                  </button>
                </div>
              </template>
            </template>
            <p v-else class="text-xs text-[#505E5E]">ระบบจะเลือกที่นั่งที่ว่างให้ {{ passengerCount }} ที่ ดู/เปลี่ยนได้ภายหลังกับทีมงาน</p>
          </section>

          <p v-if="submitError" class="text-sm text-[#DC2626] bg-[#FEF2F2] border border-[#FECACA] rounded-[12px] p-3">{{ submitError }}</p>
        </div>

        <!-- Footer -->
        <div class="px-5 py-4 border-t border-[#E8EEEF] flex gap-2 shrink-0">
          <button @click="close" :disabled="submitting"
            class="px-4 py-3 rounded-[14px] border border-[#E8EEEF] text-sm font-bold text-[#505E5E] hover:bg-[#F4F7F6]">
            ปิด
          </button>
          <button @click="submit" :disabled="!canSubmit"
            class="flex-1 py-3 rounded-[14px] bg-[#006565] text-white text-sm font-bold hover:bg-[#004f4f] disabled:opacity-50 flex items-center justify-center gap-2">
            <span v-if="submitting" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
            {{ submitting ? 'กำลังย้ายรอบ...' : selected ? `ยืนยันรอบ ${formatShortDate(selected.departure_date)}` : 'เลือกรอบก่อน' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../lib/axios';
import { bangkokToday } from '../lib/bangkokDate';

/**
 * เลื่อนรอบเดินทางเอง — ใช้ทั้งเลื่อนปกติ (ครั้งเดียว ก่อน 20 วัน) และสิทธิ์เลือกรอบใหม่
 * เมื่อรอบเดิมถูกยกเลิกเพราะเหตุสุดวิสัย (booking.reschedule_mode === 'force_majeure')
 *
 * ไม่ใช้ SeatMap.vue เพราะผูกกับ seats store ของการจองที่อาจค้างอยู่ในอีกแท็บ
 * การเลือกที่นั่งที่นี่ไม่ล็อกที่นั่ง — เซิร์ฟเวอร์ตรวจซ้ำตอนยืนยันอยู่แล้ว
 */
const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['close', 'done']);

const loading = ref(true);
const loadError = ref('');
const schedules = ref([]);
const selected = ref(null);
const pickSeats = ref(false);
const seatMap = ref(null);
const seatsLoading = ref(false);
const selectedSeats = ref([]);
const submitting = ref(false);
const submitError = ref('');

const isForceMajeure = computed(() => props.booking.reschedule_mode === 'force_majeure');
const fm = computed(() => props.booking.force_majeure || {});
const passengerCount = computed(() => props.booking.passengers?.length || 1);
const latestDeparture = computed(() => props.booking.reschedule_latest_departure || null);
const holds = computed(() => props.booking.force_majeure?.holds || []);
const deadlineLabel = computed(() => props.booking.reschedule_deadline
  ? formatDate(props.booking.reschedule_deadline) : '');

const hasSeats = computed(() => !props.booking.is_join_trip && (props.booking.seats?.length || 0) > 0);
const needsSeats = computed(() => hasSeats.value && selected.value?.allows_seat_selection !== false);

/** คันของรอบใหม่ที่ชื่อตรงกับคันเดิม — เซิร์ฟเวอร์จับคู่ด้วยชื่อแบบเดียวกัน */
const targetOptionId = computed(() => {
  const label = props.booking.vehicle_option?.label;
  if (!label || !selected.value) return null;
  return (selected.value.vehicle_options || []).find((o) => o.label === label)?.id ?? null;
});

const options = computed(() => {
  const today = bangkokToday();
  const currentId = props.booking.schedule?.id;
  const pax = passengerCount.value;

  return schedules.value
    .filter((s) => s.id !== currentId && s.status === 'open')
    .filter((s) => s.departure_date >= today)
    .filter((s) => !latestDeparture.value || s.departure_date <= latestDeparture.value)
    .map((s) => {
      // ที่นั่งสาธารณะหักที่ที่กันไว้ออกแล้ว รวมที่ที่กันไว้ให้คนนี้เอง — บวกคืนให้
      const hold = holds.value.find((h) => h.schedule_id === s.id) || null;
      const seatsLeft = props.booking.is_join_trip
        ? (s.join_trip_enabled ? (s.join_trip_available_seats ?? pax) : 0)
        : (s.bookable_seats ?? s.available_seats ?? 0) + (hold?.seat_count || 0);
      const nights = s.return_date && s.departure_date
        ? Math.round((Date.parse(s.return_date) - Date.parse(s.departure_date)) / 86400000)
        : 0;
      return {
        ...s,
        seatsLeft,
        hold,
        fits: seatsLeft >= pax,
        nightsLabel: nights > 0 ? `${nights + 1} วัน ${nights} คืน` : 'ไป-กลับวันเดียว',
      };
    });
});

const canSubmit = computed(() => {
  if (!selected.value || submitting.value) return false;
  if (pickSeats.value && needsSeats.value && seatMap.value?.has_seat_map) {
    return selectedSeats.value.length === passengerCount.value;
  }
  return true;
});

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', {
    weekday: 'short', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Bangkok',
  });
}

function formatShortDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', timeZone: 'Asia/Bangkok' });
}

async function loadSchedules() {
  const slug = props.booking.schedule?.trip?.slug;
  loading.value = true;
  loadError.value = '';
  try {
    const res = await api.get(`/trips/${encodeURIComponent(slug)}/schedules`);
    const data = res.data?.data;
    schedules.value = Array.isArray(data) ? data : (data?.data ?? []);
  } catch (e) {
    loadError.value = e?.response?.data?.message || 'โหลดรอบเดินทางไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function selectSchedule(s) {
  if (!s.fits) return;
  selected.value = s;
  submitError.value = '';
  selectedSeats.value = [];
  seatMap.value = null;
  if (pickSeats.value) loadSeats();
}

function enableSeatPicking() {
  pickSeats.value = true;
  if (!seatMap.value) loadSeats();
}

async function loadSeats() {
  if (!selected.value) return;
  const scheduleId = selected.value.id;
  seatsLoading.value = true;
  try {
    const res = await api.get(`/schedules/${scheduleId}/seats`, {
      params: targetOptionId.value ? { vehicle_option_id: targetOptionId.value } : {},
    });
    // ผู้ใช้อาจสลับไปรอบอื่นระหว่างรอ — ทิ้งผลของรอบเก่า
    if (selected.value?.id === scheduleId) seatMap.value = res.data?.data ?? null;
  } catch (e) {
    submitError.value = e?.response?.data?.message || 'โหลดผังที่นั่งไม่สำเร็จ';
  } finally {
    seatsLoading.value = false;
  }
}

function isSeatFree(seat) {
  return seat.status === 'available' || (seat.status === 'locked' && seat.locked_by_current_user);
}

function toggleSeat(id) {
  const i = selectedSeats.value.indexOf(id);
  if (i >= 0) selectedSeats.value.splice(i, 1);
  else if (selectedSeats.value.length < passengerCount.value) selectedSeats.value.push(id);
}

async function submit() {
  if (!canSubmit.value) return;
  submitting.value = true;
  submitError.value = '';
  try {
    const body = { target_schedule_id: selected.value.id };
    if (pickSeats.value && needsSeats.value && seatMap.value?.has_seat_map) {
      body.seat_ids = selectedSeats.value;
    }
    const res = await api.post(`/bookings/${encodeURIComponent(props.booking.booking_ref)}/reschedule`, body);
    emit('done', res.data?.data);
  } catch (e) {
    submitError.value = e?.response?.data?.message || 'ย้ายรอบไม่สำเร็จ กรุณาลองใหม่';
    // ที่นั่งอาจถูกจองไปก่อน — โหลดผังใหม่ให้เห็นสถานะล่าสุด
    if (pickSeats.value) loadSeats();
  } finally {
    submitting.value = false;
  }
}

function close() {
  if (!submitting.value) emit('close');
}

onMounted(loadSchedules);
</script>
