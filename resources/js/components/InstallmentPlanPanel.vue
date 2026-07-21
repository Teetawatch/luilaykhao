<template>
  <div v-if="installments.length" class="space-y-5">

    <!-- หัวข้อ + ความคืบหน้าเป็นตัวเลข -->
    <div class="flex items-center justify-between gap-3">
      <h3 class="font-black text-gray-900 flex items-center gap-2">
        <span class="material-symbols-rounded text-amber-500 text-xl">calendar_month</span>
        แผนผ่อนชำระ {{ total }} งวด
      </h3>
      <span class="text-[10px] font-black px-3 py-1 rounded-full whitespace-nowrap"
        :class="allPaid ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
        {{ paidCount }} / {{ total }} งวด
      </span>
    </div>

    <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
      <div class="h-full bg-green-500 rounded-full transition-all duration-700"
        :style="{ width: progressPercent + '%' }"></div>
    </div>

    <!-- รายงวด -->
    <div class="space-y-2">
      <div v-for="inst in installments" :key="inst.installment_no"
        class="flex items-center gap-4 p-3 rounded-xl"
        :class="{
          'bg-green-50 border border-green-100': inst.status === 'paid',
          'bg-amber-50 border border-amber-200': inst.status !== 'paid' && isNext(inst),
          'bg-gray-50 border border-gray-100': inst.status !== 'paid' && !isNext(inst),
        }">
        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-black shrink-0"
          :class="{
            'bg-green-500 text-white': inst.status === 'paid',
            'bg-amber-500 text-white': inst.status !== 'paid' && isNext(inst),
            'bg-gray-200 text-gray-400': inst.status !== 'paid' && !isNext(inst),
          }">
          <span v-if="inst.status === 'paid'" class="material-symbols-rounded text-sm">check</span>
          <span v-else>{{ inst.installment_no }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-black" :class="inst.status === 'paid' ? 'text-green-800' : 'text-gray-700'">
            งวดที่ {{ inst.installment_no }} · ฿{{ Number(inst.amount).toLocaleString() }}
          </p>
          <p class="text-xs font-bold" :class="inst.status === 'paid' ? 'text-green-600' : 'text-gray-400'">
            <template v-if="inst.status === 'paid'">
              {{ inst.paid_at ? `ชำระแล้ว ${formatDate(inst.paid_at)}` : 'ชำระแล้ว' }}
            </template>
            <template v-else>
              กำหนดชำระ {{ formatDate(inst.due_date) }}
            </template>
          </p>
        </div>
      </div>
    </div>

    <!-- งวดถัดไป — เน้นเป็นพิเศษเมื่อเลยกำหนดหรือใกล้ครบ -->
    <div v-if="nextPending" class="flex items-center justify-between gap-3 p-3 rounded-xl border-2 border-dashed"
      :class="overdue ? 'bg-red-50 border-red-300' : dueSoon ? 'bg-amber-50 border-amber-300' : 'bg-white border-gray-200'">
      <div class="flex items-center gap-2.5 min-w-0">
        <span class="material-symbols-rounded text-[18px] shrink-0"
          :class="overdue ? 'text-red-500' : 'text-amber-600'"
          :style="overdue ? `font-variation-settings:'FILL' 1` : ''">
          {{ overdue ? 'warning' : 'schedule' }}
        </span>
        <div class="min-w-0">
          <p class="text-[11px] font-black uppercase tracking-wide" :class="overdue ? 'text-red-700' : 'text-amber-800'">
            {{ overdue ? 'เลยกำหนดชำระแล้ว' : `งวดถัดไป: งวดที่ ${nextPending.installment_no}` }}
          </p>
          <p class="text-[10px] font-bold" :class="overdue ? 'text-red-600' : 'text-amber-700'">
            กำหนด {{ formatDate(nextPending.due_date) }}
            <span v-if="!overdue"> · {{ dueLabel }}</span>
          </p>
        </div>
      </div>
      <router-link :to="`/installment-payment/${bookingRef}`"
        class="px-4 py-2 rounded-xl text-xs font-black text-white transition-all active:scale-95 flex items-center gap-1.5 shrink-0"
        :class="overdue ? 'bg-red-500 hover:bg-red-600' : 'bg-amber-500 hover:bg-amber-600'">
        <span class="material-symbols-rounded text-[14px]">payments</span>
        ชำระงวด
      </router-link>
    </div>

    <div v-else class="flex items-center gap-2 p-3 bg-green-50 rounded-xl border border-green-200">
      <span class="material-symbols-rounded text-green-600 text-lg" style="font-variation-settings:'FILL' 1">verified</span>
      <span class="text-xs font-black text-green-700">ชำระครบทุกงวดเรียบร้อยแล้ว</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { daysUntil } from '../lib/bangkokDate';

const props = defineProps({
  bookingRef: { type: String, required: true },
  installments: { type: Array, default: () => [] },
  /** จำนวนงวดตามสัญญา — ใช้เป็นตัวหารแทนความยาว array เผื่อ backend ส่งมาไม่ครบ */
  installmentCount: { type: Number, default: 0 },
});

const total = computed(() => props.installmentCount || props.installments.length);
const paidCount = computed(() => props.installments.filter(i => i.status === 'paid').length);
const allPaid = computed(() => total.value > 0 && paidCount.value >= total.value);
const nextPending = computed(() => props.installments.find(i => i.status !== 'paid') || null);

const progressPercent = computed(() => (total.value ? (paidCount.value / total.value) * 100 : 0));

// กำหนดชำระเป็นวันที่ตามปฏิทินไทย จึงนับเป็น "วัน" ไม่เอาเวลาเบราว์เซอร์มาปน
const daysToDue = computed(() => daysUntil(nextPending.value?.due_date));
const overdue = computed(() => daysToDue.value !== null && daysToDue.value < 0);
const dueSoon = computed(() => daysToDue.value !== null && daysToDue.value >= 0 && daysToDue.value <= 7);

const dueLabel = computed(() => {
  const days = daysToDue.value;
  if (days === null) return '';
  if (days === 0) return 'วันนี้';
  if (days === 1) return 'พรุ่งนี้';
  return `อีก ${days} วัน`;
});

function isNext(inst) {
  return nextPending.value?.installment_no === inst.installment_no;
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}
</script>
