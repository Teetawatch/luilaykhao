<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 sm:px-4" @click.self="close">
      <div class="bg-white w-full sm:max-w-lg rounded-t-[24px] sm:rounded-[24px] overflow-hidden flex flex-col max-h-[92vh]">
        <!-- Header -->
        <div class="px-5 py-4 border-b border-[#E8EEEF] flex items-start justify-between gap-3 shrink-0">
          <div class="min-w-0">
            <h3 class="text-lg font-bold text-[#1a1c1c]">ส่งต่อที่นั่ง</h3>
            <p class="text-xs text-[#505E5E] mt-0.5 truncate">
              {{ booking.schedule?.trip?.title || 'ทริป' }} · {{ booking.booking_ref }}
            </p>
          </div>
          <button @click="close"
            class="w-8 h-8 rounded-lg border border-[#E8EEEF] hover:bg-[#F4F7F6] inline-flex items-center justify-center shrink-0"
            aria-label="ปิด">
            <span class="material-symbols-rounded text-[18px]">close</span>
          </button>
        </div>

        <div class="p-5 space-y-4 overflow-y-auto">
          <div v-if="loading" class="flex justify-center py-12">
            <div class="w-8 h-8 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
          </div>

          <p v-else-if="loadError" class="text-sm text-[#DC2626] bg-[#FEF2F2] border border-[#FECACA] rounded-[12px] p-3">{{ loadError }}</p>

          <template v-else-if="data">
            <div class="rounded-[16px] bg-[#F0FAFA] border border-[#BCDFDF] p-4 text-[13px] text-[#0F3D3E] space-y-1.5">
              <p class="font-bold text-sm">ไปไม่ได้? ส่งที่นั่งให้คนอื่นไปแทนได้</p>
              <p>สร้างลิงก์แล้วส่งให้คนที่จะไปแทน เขากรอกข้อมูลของตัวเอง แล้วที่นั่งเป็นของเขาทันที ได้เข้าแชทกลุ่มและดูกำหนดการในบัญชีของเขาเอง</p>
              <p v-if="data.deadline_label" class="font-bold">ส่งต่อได้ถึง {{ data.deadline_label }}</p>
              <p class="text-[12px] text-[#505E5E]">ค่าที่นั่งตกลงกันเองระหว่างคุณกับคนรับ ทางเราไม่คืนเงินและไม่เก็บเพิ่ม</p>
            </div>

            <p v-if="!data.available" class="text-sm text-[#B45309] bg-[#FFFBEB] border border-[#FDE68A] rounded-[12px] p-3">
              {{ data.blocked_reason }}
            </p>

            <!-- ที่นั่งทีละคน -->
            <div v-for="seat in data.seats" :key="seat.passenger_id"
              class="rounded-[16px] border border-[#E8EEEF] p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-bold text-[#1a1c1c] truncate">
                    {{ seat.name || 'ผู้เดินทาง' }}<span v-if="seat.nickname" class="font-normal text-[#505E5E]"> ({{ seat.nickname }})</span>
                  </p>
                  <p class="text-xs text-[#889696] mt-0.5">
                    <template v-if="seat.seat_label">ที่นั่ง {{ seat.seat_label }}</template>
                    <template v-if="seat.is_mine"> · ที่นั่งของคุณ</template>
                    <template v-else-if="seat.member_name"> · บัญชี {{ seat.member_name }}</template>
                  </p>
                </div>
                <span v-if="seat.open_handover"
                  class="shrink-0 text-[11px] font-bold px-2 py-1 rounded-full bg-[#FFFBEB] text-[#B45309] border border-[#FDE68A]">
                  รอคนรับ
                </span>
              </div>

              <!-- มีลิงก์ค้างอยู่ -->
              <div v-if="seat.open_handover" class="mt-3 space-y-2">
                <p v-if="seat.open_handover.transfers_ownership" class="text-xs text-[#0F3D3E] bg-[#F0FAFA] rounded-[10px] px-3 py-2">
                  คนรับจะได้ดูแลการจองนี้แทนคุณด้วย
                </p>
                <div class="flex items-center gap-2 bg-[#F4F7F6] rounded-[12px] px-3 py-2">
                  <code class="text-xs text-[#1a1c1c] break-all flex-1">{{ seat.open_handover.url }}</code>
                </div>
                <p class="text-[11px] text-[#889696]">ลิงก์ใช้ได้ถึง {{ formatDateTime(seat.open_handover.expires_at) }} · ใช้ได้คนเดียว</p>
                <div class="flex gap-2">
                  <button @click="share(seat)"
                    class="flex-1 py-2.5 rounded-[12px] bg-[#006565] text-white text-sm font-bold hover:bg-[#005252] flex items-center justify-center gap-1.5">
                    <span class="material-symbols-rounded text-[18px]">share</span>
                    ส่งลิงก์
                  </button>
                  <button @click="copy(seat.open_handover.url)"
                    class="px-4 py-2.5 rounded-[12px] border border-[#BCDFDF] text-[#006565] text-sm font-bold hover:bg-[#F0FAFA]">
                    คัดลอก
                  </button>
                  <button @click="cancel(seat)" :disabled="busy === seat.passenger_id"
                    class="px-4 py-2.5 rounded-[12px] border border-[#FCA5A5] text-[#DC2626] text-sm font-bold hover:bg-[#FEF2F2] disabled:opacity-50">
                    ยกเลิก
                  </button>
                </div>
              </div>

              <!-- ยังไม่มีลิงก์ -->
              <template v-else-if="seat.can_hand_over">
                <div v-if="draftFor === seat.passenger_id" class="mt-3 space-y-3">
                  <label v-if="data.viewer_role === 'owner'"
                    class="flex items-start gap-3 rounded-[12px] border border-[#E8EEEF] p-3"
                    :class="ownershipDisabled(seat) ? 'opacity-60' : 'cursor-pointer'">
                    <input type="checkbox" v-model="draft.transfers_ownership" :disabled="ownershipDisabled(seat)"
                      class="mt-0.5 w-4 h-4 accent-[#006565]" />
                    <span class="text-[13px] text-[#1a1c1c]">
                      <span class="font-bold block">นี่คือที่นั่งของฉันเอง</span>
                      <span class="text-[#505E5E]">คนรับจะได้ดูแลการจองนี้แทนคุณ (แชท วันเดินทาง การชำระเงิน) และการจองจะออกจากบัญชีของคุณ</span>
                      <span v-if="ownershipDisabled(seat)" class="block text-[#B45309] mt-1">{{ data.ownership_blocked_reason }}</span>
                    </span>
                  </label>
                  <label class="block">
                    <span class="block text-sm font-bold text-[#1a1c1c] mb-1.5">ฝากข้อความถึงคนรับ (ไม่บังคับ)</span>
                    <textarea v-model="draft.note" maxlength="300" rows="2" placeholder="เช่น ฝากไปแทนด้วยนะ ค่าที่นั่งโอนมาที่เราได้เลย"
                      class="w-full rounded-[12px] border border-[#D5DEDE] px-3 py-2.5 text-sm focus:border-[#006565] focus:outline-none"></textarea>
                  </label>
                  <div class="flex gap-2">
                    <button @click="draftFor = null"
                      class="px-4 py-2.5 rounded-[12px] border border-[#E8EEEF] text-sm font-bold text-[#505E5E] hover:bg-[#F4F7F6]">
                      ยกเลิก
                    </button>
                    <button @click="create(seat)" :disabled="busy === seat.passenger_id"
                      class="flex-1 py-2.5 rounded-[12px] bg-[#006565] text-white text-sm font-bold hover:bg-[#005252] disabled:opacity-50 flex items-center justify-center gap-2">
                      <span v-if="busy === seat.passenger_id" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
                      สร้างลิงก์ส่งต่อ
                    </button>
                  </div>
                </div>
                <button v-else @click="openDraft(seat)"
                  class="mt-3 w-full py-2.5 rounded-[12px] border border-[#BCDFDF] text-[#006565] text-sm font-bold hover:bg-[#F0FAFA] flex items-center justify-center gap-1.5">
                  <span class="material-symbols-rounded text-[18px]">swap_horiz</span>
                  ส่งต่อที่นั่งนี้
                </button>
              </template>
            </div>

            <!-- ประวัติ -->
            <div v-if="data.history?.length" class="pt-1">
              <p class="text-xs font-black text-[#889696] tracking-wide mb-2">ประวัติการส่งต่อ</p>
              <ul class="space-y-1.5">
                <li v-for="h in data.history" :key="h.id" class="text-[13px] text-[#505E5E] flex items-start gap-2">
                  <span class="material-symbols-rounded text-[16px] mt-0.5"
                    :class="h.status === 'claimed' ? 'text-[#16A34A]' : 'text-[#A0B0B0]'">
                    {{ h.status === 'claimed' ? 'check_circle' : 'link_off' }}
                  </span>
                  <span v-if="h.status === 'claimed'">{{ h.previous_name }} → {{ h.new_name }} · {{ formatDateTime(h.claimed_at) }}</span>
                  <span v-else>ลิงก์ของ {{ h.previous_name }} {{ h.status === 'expired' ? 'หมดอายุ' : 'ถูกยกเลิก' }}</span>
                </li>
              </ul>
            </div>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';
import { useSwal } from '../lib/swal';

/**
 * ส่งต่อที่นั่ง — คนที่ไปไม่ได้ออกลิงก์ให้คนอื่นมารับที่นั่งแทน ทุกกติกา (เส้นตาย
 * ใครส่งที่นั่งไหนได้ โอนสิทธิ์ดูแลได้ไหม) มาจาก GET /bookings/{ref}/handovers
 * หน้านี้แค่วาดตามนั้น ดู SeatHandoverService
 */
const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed']);

const toast = useToast();
const swal = useSwal();

const data = ref(null);
const loading = ref(true);
const loadError = ref('');
const busy = ref(null);
const draftFor = ref(null);
const draft = reactive({ transfers_ownership: false, note: '' });
let changed = false;

function close() {
  emit('close');
  if (changed) emit('changed');
}

async function load() {
  try {
    const res = await api.get(`/bookings/${props.booking.booking_ref}/handovers`);
    data.value = res.data.data;
  } catch (e) {
    loadError.value = e?.response?.data?.message || 'โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่';
  } finally {
    loading.value = false;
  }
}

function ownershipDisabled(seat) {
  return !data.value?.can_transfer_ownership
    && data.value?.open_ownership_passenger_id !== seat.passenger_id;
}

function openDraft(seat) {
  draftFor.value = seat.passenger_id;
  draft.note = '';
  draft.transfers_ownership = !ownershipDisabled(seat) && seat.is_owner_seat_guess;
}

async function create(seat) {
  busy.value = seat.passenger_id;
  try {
    await api.post(`/bookings/${props.booking.booking_ref}/handovers`, {
      passenger_id: seat.passenger_id,
      transfers_ownership: draft.transfers_ownership,
      note: draft.note || null,
    });
    changed = true;
    draftFor.value = null;
    await load();
    const fresh = data.value?.seats?.find((s) => s.passenger_id === seat.passenger_id);
    if (fresh?.open_handover) share(fresh);
  } catch (e) {
    toast.error(e?.response?.data?.message || 'สร้างลิงก์ไม่สำเร็จ');
  } finally {
    busy.value = null;
  }
}

async function cancel(seat) {
  const { isConfirmed } = await swal.confirm({
    title: 'ยกเลิกลิงก์นี้?',
    text: 'ลิงก์ที่ส่งไปแล้วจะใช้รับที่นั่งไม่ได้อีก',
    icon: 'warning',
    confirmText: 'ยกเลิกลิงก์',
    cancelText: 'ไม่ใช่ตอนนี้',
  });
  if (!isConfirmed) return;

  busy.value = seat.passenger_id;
  try {
    await api.delete(`/bookings/${props.booking.booking_ref}/handovers/${seat.open_handover.id}`);
    changed = true;
    await load();
    toast.success('ยกเลิกลิงก์แล้ว');
  } catch (e) {
    toast.error(e?.response?.data?.message || 'ยกเลิกไม่สำเร็จ');
  } finally {
    busy.value = null;
  }
}

function shareText(seat) {
  const trip = props.booking.schedule?.trip?.title || 'ทริป';
  return `ฝากไปทริป "${trip}" แทนหน่อยนะ กดลิงก์นี้ กรอกข้อมูลของตัวเอง แล้วที่นั่งเป็นของคุณเลย`;
}

async function share(seat) {
  const url = seat.open_handover?.url;
  if (!url) return;
  if (navigator.share) {
    try {
      await navigator.share({ title: 'ส่งต่อที่นั่ง', text: shareText(seat), url });
      return;
    } catch (e) {
      if (e?.name === 'AbortError') return;
    }
  }
  copy(url);
}

async function copy(url) {
  try {
    await navigator.clipboard.writeText(url);
    toast.success('คัดลอกลิงก์แล้ว ส่งให้คนที่จะไปแทนได้เลย');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ กดค้างที่ลิงก์เพื่อคัดลอกเองได้');
  }
}

function formatDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString('th-TH', {
    timeZone: 'Asia/Bangkok', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  }) + ' น.';
}

onMounted(load);
</script>
