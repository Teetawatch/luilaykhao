<template>
  <!--
    บัตร · Apple/Google Pay · ผ่อน · e-Wallet — แถวลูกศรในรายการช่องทางชำระเงิน
    ทุกแถวพาไปหน้าชำระเงินของ Beam หน้าเดียวกัน แต่แยกแถวให้คนที่มองหา TrueMoney
    เจอโดยไม่ต้องเดา กดแถวไหนก็ไปต่อทันที (ไม่มีปุ่มยืนยันแยกอีกชั้น)

    คอมโพเนนต์นี้คืนหลายแถวเรียงกัน — วางไว้ใน div ที่มี divide-y ของรายการเดียวกับแถว QR
    โลโก้อยู่ที่ public/images/pay (ไฟล์ชุดเดียวกับในแอป)
  -->
  <PayMethodRow v-for="row in rows" :key="row.key"
    trail="chevron"
    :icon="row.icon"
    :badge="row.badge"
    :title="row.title"
    :subtitle="row.caption"
    :available="row.available"
    :disabled="loading"
    :busy="loading && pending === row.key"
    @select="open(row)">
    <template v-if="row.brands.length || !row.available" #below>
      <span v-if="row.brands.length" class="flex flex-wrap gap-1.5">
        <img v-for="b in row.brands" :key="b" :src="`/images/pay/${b}.svg`" :alt="BRAND_NAMES[b]"
          class="h-[22px] w-[34px]" width="34" height="22" loading="lazy" />
      </span>
      <span v-if="!row.available" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-[11px] font-bold text-gray-500">
        <span class="material-symbols-rounded icon-14">lock</span>ยังไม่ถึงขั้นต่ำ
      </span>
    </template>
  </PayMethodRow>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import PayMethodRow from './PayMethodRow.vue';

const props = defineProps({
  amount: { type: Number, required: true },
  // ผ่อนบัตรมีขั้นต่ำ (ตั้งใน Lighthouse) — ยอดไม่ถึง หน้าของ Beam ไม่มีให้เลือก อย่าสัญญา
  installmentMin: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
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

const money = (n) => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });

const canInstall = computed(() => props.installmentMin > 0 && props.amount >= props.installmentMin);

/** แถวที่เพิ่งกด — ทุกแถวขอใบชนิดเดียวกัน วงหมุนจึงต้องรู้เองว่าขึ้นแถวไหน */
const pending = ref('');
watch(() => props.loading, (busy) => {
  if (!busy) pending.value = '';
});

const rows = computed(() => [
  {
    key: 'card',
    icon: 'credit_card',
    title: 'บัตรเครดิต / เดบิต',
    caption: 'กรอกข้อมูลบัตรบนหน้าของ Beam โดยตรง เราไม่เห็นและไม่เก็บเลขบัตรของคุณ',
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
    caption: '',
    brands: ['truemoney', 'linepay', 'shopeepay', 'alipay'],
    available: true,
  },
]);

function open(row) {
  if (!row.available || props.loading) return;
  pending.value = row.key;
  emit('open');
}
</script>
