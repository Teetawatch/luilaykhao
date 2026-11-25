<template>
  <!--
    "ตอนนี้อยู่ตรงไหนของการจ่ายเงิน" — การ์ดเดียว สองระดับ

    waiting   ยังไม่จ่าย: อยู่ใต้ QR เป็นแถบขั้นตอนเฉยๆ ไม่แย่งสายตาไปจาก QR
    settling  จ่ายแล้วแต่ webhook ยังไม่มา: กินพื้นที่แทน QR ไปเลย

    ที่ต้องมีทั้งสองระดับเพราะเดิมหน้าจอช่วงนี้นิ่งสนิท คนจ่ายเงินหลักพันแล้วเห็นหน้าจอ
    ไม่ขยับจะสรุปเองว่าจ่ายไม่ผ่าน แล้วกดจ่ายซ้ำ แถวที่กำลังทำงานอยู่จึงต้องหมุนเสมอ
    และต้องพูดให้ชัดว่าห้ามจ่ายซ้ำ
  -->
  <div :class="isSettling
    ? 'rounded-3xl border border-accent/15 bg-white p-7 text-center'
    : 'rounded-3xl border border-gray-100 bg-white/60 p-4'">
    <template v-if="isSettling">
      <div class="relative mx-auto w-24 h-24 flex items-center justify-center">
        <span class="absolute inset-0 rounded-full bg-accent/10 settling-ping"></span>
        <span class="absolute inset-2 rounded-full bg-accent/10 settling-ping settling-ping-delayed"></span>
        <span class="absolute inset-3 rounded-full border-[3px] border-accent/15 border-t-accent animate-spin"></span>
        <span class="material-symbols-rounded text-accent text-[30px]" style="font-variation-settings:'FILL' 1">
          {{ slow ? 'hourglass_top' : 'account_balance' }}
        </span>
      </div>

      <p class="mt-5 text-base font-black text-gray-900">
        {{ slow ? $t('ยังตรวจสอบอยู่ ใช้เวลานานกว่าปกติเล็กน้อย') : $t('กำลังตรวจสอบการชำระเงิน') }}
      </p>
      <p class="mt-1.5 text-xs text-gray-500 font-medium leading-relaxed px-2">
        {{ slow
          ? $t('ธนาคารบางแห่งส่งผลช้ากว่าปกติในช่วงเวลาเร่งด่วน ระบบยังตามผลให้อยู่ ไม่ต้องจ่ายซ้ำ')
          : $t('ระบบกำลังรอผลจากธนาคาร ปกติใช้เวลาไม่เกิน 1 นาที กรุณาอย่าปิดหน้านี้') }}
      </p>
    </template>

    <!-- ขั้นที่กำลังทำงานอยู่เต้นเสมอ — หน้าจอจึงไม่มีวินาทีไหนที่ดูเหมือนค้าง -->
    <PayTimeline :class="isSettling ? 'mt-6' : ''" :steps="steps" :current="activeIndex" />

    <div v-if="isSettling" class="mt-6 flex items-center justify-center gap-2 text-[11px] font-bold text-gray-400">
      <span class="w-1.5 h-1.5 rounded-full bg-accent-light animate-pulse"></span>
      <span class="tabular-nums">{{ $t('รอมาแล้ว {elapsedText}', { elapsedText }) }}</span>
    </div>

    <p v-if="isSettling && slow" class="mt-4 text-[11px] font-bold text-gray-500 leading-relaxed">
      {{ $t('หากเงินถูกตัดไปแล้วแต่สถานะยังไม่ขึ้น ระบบจะยืนยันที่นั่งให้เองเมื่อได้รับผลจากธนาคาร หรือทักหาเราได้ที่') }}
      <router-link to="/support" class="text-accent underline underline-offset-4">{{ $t('ศูนย์ช่วยเหลือ') }}</router-link>
    </p>

    <slot name="actions" />
  </div>
</template>

<script setup>
import { $t } from '@/i18n';
import { computed } from 'vue';
import PayTimeline from './PayTimeline.vue';

const props = defineProps({
  /**
   * waiting = ยังรอให้ลูกค้าสแกน · settling = จ่ายแล้ว รอผลจากธนาคาร
   *
   * ระดับเดียวกับ useBeamCharge.settling — หน้าจอไม่ตัดสินเองว่าถึงขั้นไหนแล้ว
   */
  stage: {
    type: String,
    default: 'settling',
    validator: (v) => ['waiting', 'settling'].includes(v),
  },
  /** วินาทีที่รอมาแล้ว — ตัวนับเดินอยู่ที่ useBeamCharge ที่เดียว */
  seconds: { type: Number, default: 0 },
  /** รอนานผิดปกติแล้ว เปลี่ยนคำพูดจาก "รอสักครู่" เป็น "ยังตามให้อยู่" */
  slow: { type: Boolean, default: false },
  /** ขั้นแรก ต่างกันตามว่าหน้านั้นให้จ่ายด้วยอะไร (QR / แอปธนาคาร) */
  firstStep: { type: String, default: () => $t('สแกน QR ด้วยแอปธนาคาร') },
  /** ข้อความบรรทัดสุดท้ายของขั้นตอน ต่างกันตามว่าจ่ายเพื่ออะไร */
  finalStep: { type: String, default: () => $t('ยืนยันที่นั่งของคุณอัตโนมัติ') },
});

const isSettling = computed(() => props.stage === 'settling');

/** ขั้นที่กำลังทำอยู่ — ยังไม่จ่ายคือขั้นแรก จ่ายแล้วคือขั้น "รอธนาคารยืนยัน" */
const activeIndex = computed(() => (isSettling.value ? 1 : 0));

// จ่ายแล้ว ขั้นแรกคือ "ส่งให้ธนาคารแล้ว" ไม่ว่าจะจ่ายด้วย QR หรือแอปธนาคาร (ชุดเดียวกับแอป)
const steps = computed(() => [
  isSettling.value
    ? { title: $t('ส่งรายการชำระเงินให้ธนาคารแล้ว') }
    : { title: props.firstStep, hint: $t('เปิดแอปธนาคาร เลือกสแกน แล้วส่องที่ QR นี้') },
  { title: $t('รอธนาคารยืนยันว่าเงินเข้า'), hint: $t('ส่วนใหญ่ใช้เวลาไม่กี่วินาที ไม่ต้องแนบสลิป') },
  { title: props.finalStep, hint: $t('หน้านี้จะเปลี่ยนให้เองเมื่อเงินเข้า') },
]);

const elapsedText = computed(() => {
  const s = Math.max(0, props.seconds);
  return s < 60 ? $t('{s} วินาที', { s }) : $t('{floor} นาที {String} วินาที', { floor: Math.floor(s / 60), String: String(s % 60).padStart(2, '0') });
});
</script>

<style scoped>
/* วงคลื่นสองชั้นเหลื่อมเวลากัน — บอกว่า "ยังทำงานอยู่" ได้โดยไม่ต้องมีข้อความ */
.settling-ping {
  animation: settling-ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}

.settling-ping-delayed {
  animation-delay: 1s;
}

@keyframes settling-ping {
  0% { transform: scale(0.85); opacity: 0.9; }
  80%, 100% { transform: scale(1.35); opacity: 0; }
}

/* ผู้ที่ตั้งค่าเครื่องว่าไม่เอาแอนิเมชัน ยังต้องรู้ว่าระบบทำงานอยู่ผ่านตัวนับวินาที */
@media (prefers-reduced-motion: reduce) {
  .settling-ping { animation: none; opacity: 0.35; }
  .animate-spin { animation-duration: 3s; }
}
</style>
