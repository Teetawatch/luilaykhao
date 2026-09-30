<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 sm:px-4" @click.self="close">
      <div class="bg-white w-full sm:max-w-md rounded-t-[24px] sm:rounded-[24px] overflow-hidden flex flex-col max-h-[92vh]">
        <!-- Header -->
        <div class="px-5 py-4 border-b border-[#E8EEEF] flex items-start justify-between gap-3 shrink-0">
          <div class="min-w-0">
            <h3 class="text-lg font-bold text-[#1a1c1c]">{{ amount > 0 ? 'ขอรับเงินคืนเต็มจำนวน' : 'ยกเลิกการจอง' }}</h3>
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

        <form class="p-5 space-y-4 overflow-y-auto" @submit.prevent="submit">
          <div class="rounded-[16px] bg-[#F0FAFA] border border-[#BCDFDF] p-4 text-[13px] text-[#0F3D3E]">
            <template v-if="amount > 0">
              <p class="font-bold text-sm">คืนเต็มจำนวน ฿{{ amount.toLocaleString('th-TH') }} (รวมมัดจำ)</p>
              <p class="mt-1">การจองนี้จะถูกยกเลิก และเราจะโอนคืนเข้าบัญชีด้านล่างภายใน 3–7 วันทำการ</p>
            </template>
            <p v-else>การจองนี้ยังไม่มียอดที่ชำระ กดยืนยันเพื่อยกเลิกได้เลยครับ</p>
          </div>

          <template v-if="amount > 0">
            <label class="block">
              <span class="block text-sm font-bold text-[#1a1c1c] mb-1.5">ธนาคาร / พร้อมเพย์</span>
              <select v-model="form.bank" required
                class="w-full rounded-[12px] border border-[#D5DEDE] px-3 py-2.5 text-sm bg-white focus:border-[#006565] focus:outline-none">
                <option value="" disabled>เลือก…</option>
                <option v-for="bank in banks" :key="bank" :value="bank">{{ bank }}</option>
              </select>
            </label>
            <label class="block">
              <span class="block text-sm font-bold text-[#1a1c1c] mb-1.5">
                {{ form.bank === 'พร้อมเพย์' ? 'เบอร์มือถือ / เลขบัตรประชาชน' : 'เลขบัญชี' }}
              </span>
              <input v-model="form.account_number" required inputmode="numeric" autocomplete="off"
                :placeholder="form.bank === 'พร้อมเพย์' ? 'เช่น 081-234-5678' : 'เช่น 123-4-56789-0'"
                class="w-full rounded-[12px] border border-[#D5DEDE] px-3 py-2.5 text-sm focus:border-[#006565] focus:outline-none" />
            </label>
            <label class="block">
              <span class="block text-sm font-bold text-[#1a1c1c] mb-1.5">ชื่อบัญชี</span>
              <input v-model="form.account_name" required maxlength="120" placeholder="ชื่อ-นามสกุลตามบัญชี"
                class="w-full rounded-[12px] border border-[#D5DEDE] px-3 py-2.5 text-sm focus:border-[#006565] focus:outline-none" />
            </label>
          </template>

          <p class="text-xs text-[#B45309]">ยกเลิกแล้วย้อนกลับไม่ได้ ถ้าอยากไปรอบอื่นแทน ปิดหน้าต่างนี้แล้วกด "เลือกรอบใหม่" ได้เลยครับ</p>

          <p v-if="error" class="text-sm text-[#DC2626] bg-[#FEF2F2] border border-[#FECACA] rounded-[12px] p-3">{{ error }}</p>

          <div class="flex gap-2 pt-1">
            <button type="button" @click="close" :disabled="submitting"
              class="px-4 py-3 rounded-[14px] border border-[#E8EEEF] text-sm font-bold text-[#505E5E] hover:bg-[#F4F7F6]">
              ปิด
            </button>
            <button type="submit" :disabled="!canSubmit"
              class="flex-1 py-3 rounded-[14px] bg-[#B45309] text-white text-sm font-bold hover:bg-[#92400E] disabled:opacity-50 flex items-center justify-center gap-2">
              <span v-if="submitting" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
              {{ submitting ? 'กำลังส่งเรื่อง...' : (amount > 0 ? 'ยกเลิกและขอรับเงินคืน' : 'ยืนยันยกเลิก') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { reactive, ref, computed } from 'vue';
import api from '../lib/axios';

/**
 * รอบไม่ได้ออกเพราะผู้ร่วมทริปไม่ครบ — ลูกค้าเลือกรับเงินคืนเต็มจำนวนแทนรอบใหม่
 * ยอดคืนและรายการธนาคารมาจาก booking.force_majeure ของเซิร์ฟเวอร์ (ForceMajeureService)
 */
const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['close', 'done']);

const fm = computed(() => props.booking.force_majeure || {});
const amount = computed(() => Number(fm.value.refund_amount || 0));
const banks = computed(() => fm.value.refund_banks || []);

const form = reactive({ bank: '', account_number: '', account_name: '' });
const submitting = ref(false);
const error = ref('');

const canSubmit = computed(() => {
  if (submitting.value) return false;
  if (amount.value <= 0) return true;
  return !!form.bank && form.account_number.trim() !== '' && form.account_name.trim() !== '';
});

async function submit() {
  if (!canSubmit.value) return;
  submitting.value = true;
  error.value = '';
  try {
    const body = amount.value > 0 ? { ...form } : {};
    const res = await api.post(`/bookings/${encodeURIComponent(props.booking.booking_ref)}/postponement/refund`, body);
    emit('done', res.data?.data);
  } catch (e) {
    error.value = e?.response?.data?.message || 'ส่งเรื่องไม่สำเร็จ กรุณาลองใหม่';
  } finally {
    submitting.value = false;
  }
}

function close() {
  if (!submitting.value) emit('close');
}
</script>
