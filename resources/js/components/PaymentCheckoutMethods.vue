<template>
  <!--
    "บัตร · ผ่อนบัตร · e-Wallet" — ทุกแถวพาไปหน้าชำระเงินของ Beam หน้าเดียวกัน
    แต่กดแถวไหนก็ไปต่อได้ คนที่มองหา TrueMoney จะได้ไม่ต้องหาปุ่ม

    โลโก้อยู่ที่ public/images/pay (ไฟล์ชุดเดียวกับในแอป) พื้นขาวขอบบางในตัวไฟล์
  -->
  <div class="space-y-4">
    <ul class="rounded-2xl border border-gray-200 bg-white divide-y divide-gray-100 overflow-hidden">
      <li v-for="row in rows" :key="row.key">
        <button type="button" @click="open(row)" :disabled="loading || !row.available"
          class="w-full flex items-start gap-3 px-3.5 sm:px-4 py-3.5 text-left transition-colors disabled:cursor-not-allowed"
          :class="row.available ? 'hover:bg-gray-50/80' : 'opacity-60'">
          <span class="shrink-0 w-10 h-10 rounded-xl flex items-center justify-center"
            :class="row.available ? tone.tile : 'bg-gray-100 text-gray-400'">
            <span class="material-symbols-rounded text-[22px]">{{ row.icon }}</span>
          </span>
          <span class="flex-1 min-w-0">
            <span class="block text-sm font-black text-gray-900 tracking-tight">{{ row.title }}</span>
            <span class="block text-xs text-gray-500 font-medium leading-relaxed mt-0.5">{{ row.caption }}</span>
            <span v-if="row.brands.length" class="flex flex-wrap gap-1.5 mt-2">
              <img v-for="b in row.brands" :key="b" :src="`/images/pay/${b}.svg`" :alt="BRAND_NAMES[b]"
                class="h-[22px] w-[34px]" width="34" height="22" loading="lazy" />
            </span>
            <span v-if="!row.available" class="inline-flex items-center gap-1 mt-2 px-2 py-0.5 rounded-full bg-gray-100 text-[11px] font-bold text-gray-500">
              <span class="material-symbols-rounded text-[13px]">lock</span>ยังไม่ถึงขั้นต่ำ
            </span>
          </span>
          <span v-if="row.available" class="hidden sm:block material-symbols-rounded shrink-0 self-center text-gray-300 text-[20px]">chevron_right</span>
        </button>
      </li>
    </ul>

    <button type="button" @click="$emit('open')" :disabled="loading"
      class="w-full py-4 rounded-2xl text-white font-black text-sm flex items-center justify-center gap-2 active:scale-[0.98] transition-all disabled:opacity-60"
      :class="tone.button">
      <span v-if="loading" class="w-4 h-4 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
      <template v-else>
        <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">lock</span>
        <span>ไปหน้าชำระเงิน ฿{{ money(amount) }}</span>
      </template>
    </button>

    <p class="flex items-start gap-2 text-[11px] text-gray-500 font-medium leading-relaxed">
      <span class="material-symbols-rounded text-[16px] shrink-0" :class="tone.icon" style="font-variation-settings:'FILL' 1">verified_user</span>
      กรอกข้อมูลบัตรบนหน้าของ Beam โดยตรง เราไม่เห็นและไม่เก็บเลขบัตรของคุณ · จ่ายเสร็จระบบยืนยันให้อัตโนมัติ
    </p>
    <p v-if="error" class="text-xs font-bold text-red-600">{{ error }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  amount: { type: Number, required: true },
  // ผ่อนบัตรมีขั้นต่ำ (ตั้งใน Lighthouse) — ยอดไม่ถึง หน้าของ Beam ไม่มีให้เลือก อย่าสัญญา
  installmentMin: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  // สีของหน้าที่ใช้ — หน้าจ่ายเงินเป็นเขียว หน้าจ่ายงวดเป็นเหลืองอำพัน
  accent: { type: String, default: 'teal' },
});
const emit = defineEmits(['open']);

const BRAND_NAMES = {
  visa: 'Visa',
  mastercard: 'Mastercard',
  applepay: 'Apple Pay',
  googlepay: 'Google Pay',
  truemoney: 'TrueMoney',
  linepay: 'LINE Pay',
  shopeepay: 'ShopeePay',
  alipay: 'Alipay',
};

// เขียนชื่อคลาสเต็ม ๆ ให้ Tailwind หาเจอตอน build
const TONES = {
  teal: { tile: 'bg-teal-50 text-teal-600', button: 'bg-teal-600 hover:bg-teal-700', icon: 'text-teal-600' },
  amber: { tile: 'bg-amber-50 text-amber-600', button: 'bg-amber-500 hover:bg-amber-600', icon: 'text-amber-600' },
};
const tone = computed(() => TONES[props.accent] || TONES.teal);

const money = (n) => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });

const canInstall = computed(() => props.installmentMin > 0 && props.amount >= props.installmentMin);

const rows = computed(() => [
  {
    key: 'card',
    icon: 'credit_card',
    title: 'บัตรเครดิต / เดบิต',
    caption: 'กรอกเลขบัตรบนหน้าชำระเงิน',
    brands: ['visa', 'mastercard'],
    available: true,
  },
  {
    // เว็บรู้ไม่ได้แน่ชัดว่าเบราว์เซอร์ไหนจ่ายอะไรได้ — บอกเงื่อนไขไว้เลย
    key: 'wallet-pay',
    icon: 'contactless',
    title: 'Apple Pay · Google Pay',
    caption: 'ไม่ต้องกรอกเลขบัตร ขึ้นตามเบราว์เซอร์ที่ใช้',
    brands: ['applepay', 'googlepay'],
    available: true,
  },
  {
    key: 'installment',
    icon: 'calendar_month',
    title: 'ผ่อนชำระรายเดือน',
    caption: canInstall.value
      ? 'กับบัตรเครดิตที่ร่วมรายการ เลือกจำนวนงวดบนหน้าชำระเงิน'
      : props.installmentMin > 0
        ? `ผ่อนบัตรได้เมื่อยอดตั้งแต่ ฿${money(props.installmentMin)} ขึ้นไป`
        : 'ยังไม่เปิดให้ผ่อนบัตร',
    brands: [],
    available: canInstall.value,
  },
  {
    key: 'ewallet',
    icon: 'account_balance_wallet',
    title: 'e-Wallet',
    caption: 'TrueMoney · LINE Pay · ShopeePay · Alipay',
    brands: ['truemoney', 'linepay', 'shopeepay', 'alipay'],
    available: true,
  },
]);

function open(row) {
  if (!row.available || props.loading) return;
  emit('open');
}
</script>
