<template>
  <!--
    หนึ่งทางเลือกในรายการชำระเงิน — ชุดเดียวกับแอป (payment_method_list.part.dart)

    ป้าย 46×30 ซ้าย · ชื่อ + คำอธิบาย · ปุ่มเลือกกลม (radio) หรือลูกศร (กดแล้วไปต่อทันที)
    ทางขวา แถวที่เลือกอยู่กางรายละเอียดของตัวเอง (slot หลัก) ออกมาข้างใต้ ให้รู้ทันทีว่า
    QR/เลขบัญชีชุดไหนเป็นของช่องทางไหน วางเรียงกันใน div ที่มี divide-y เพื่อได้เส้นคั่นบาง
  -->
  <div>
    <button type="button" @click="onClick" :disabled="!available || disabled"
      class="pay-row w-full flex items-start gap-3.5 py-4 text-left disabled:cursor-not-allowed"
      :class="available ? '' : 'opacity-55'"
      :role="trail === 'radio' ? 'radio' : undefined"
      :aria-checked="trail === 'radio' ? String(selected) : undefined">
      <!-- ป้าย: โลโก้จาก public/images/pay หรือไอคอนบนพื้นเขียวอ่อน -->
      <img v-if="badge" :src="badgeSrc" alt="" width="46" height="30"
        class="pay-badge shrink-0 w-[46px] h-[30px]" />
      <span v-else class="pay-badge shrink-0 w-[46px] h-[30px] flex items-center justify-center"
        :class="available && !muted ? 'bg-accent-tint text-accent' : 'bg-gray-100 text-gray-400'">
        <span class="material-symbols-rounded icon-18" style="font-variation-settings:'FILL' 1">{{ icon }}</span>
      </span>

      <span class="flex-1 min-w-0">
        <!-- ป้ายสูง 30 — ชื่อบรรทัดแรกอยู่กึ่งกลางป้าย -->
        <span class="flex min-h-[30px] items-center text-[15px] sm:text-base font-bold text-gray-900 leading-snug">{{ title }}</span>
        <span v-if="subtitle" class="block text-xs sm:text-[13px] text-gray-500 font-medium leading-relaxed">{{ subtitle }}</span>
        <span v-if="$slots.below" class="block mt-2.5"><slot name="below" /></span>
      </span>

      <span class="shrink-0 h-[30px] flex items-center">
        <span v-if="busy" class="w-5 h-5 rounded-full border-2 border-accent/20 border-t-accent animate-spin"></span>
        <span v-else-if="trail === 'radio'" class="pay-radio" :class="{ 'is-on': selected }" aria-hidden="true">
          <span class="pay-radio-dot"></span>
        </span>
        <span v-else-if="trail === 'chevron'" class="material-symbols-rounded icon-22 text-gray-400">chevron_right</span>
      </span>
    </button>

    <Transition name="pay-expand">
      <div v-if="selected && $slots.default" class="pb-5">
        <slot />
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  /** ชื่อไฟล์ใน public/images/pay — ไม่มีนามสกุล = .svg */
  badge: { type: String, default: '' },
  /** ไอคอน Material Symbols เมื่อไม่มีโลโก้ */
  icon: { type: String, default: 'payments' },
  muted: { type: Boolean, default: false },
  /** radio = เลือกแล้วกางรายละเอียด · chevron = กดแล้วไปต่อทันที */
  trail: { type: String, default: 'radio' },
  selected: { type: Boolean, default: false },
  /** กำลังขอใบชำระเงินของแถวนี้อยู่ */
  busy: { type: Boolean, default: false },
  /** ใช้กับยอด/การจองนี้ได้ไหม — ไม่ได้ แถวจางและกดไม่ได้ */
  available: { type: Boolean, default: true },
  /** ปิดชั่วคราว (เช่นระหว่างรอใบอื่น) — ไม่จาง */
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['select']);

const badgeSrc = computed(() =>
  `/images/pay/${props.badge.includes('.') ? props.badge : `${props.badge}.svg`}`
);

function onClick() {
  if (!props.available || props.disabled) return;
  emit('select');
}
</script>

<style scoped>
/* มุมเท่ากับ rx ในไฟล์ SVG ชุด public/images/pay — ป้ายไอคอนกับป้ายโลโก้จะได้เป็นชุดเดียวกัน */
.pay-badge {
  border-radius: 5px;
}

.pay-row:not(:disabled):hover .pay-radio:not(.is-on) {
  border-color: var(--color-accent);
}

.pay-radio {
  width: 22px;
  height: 22px;
  border-radius: 9999px;
  border: 1.5px solid #c4cbd4;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.16s, border-color 0.16s;
}
.pay-radio.is-on {
  background: var(--color-accent);
  border-color: var(--color-accent);
}
.pay-radio-dot {
  width: 8px;
  height: 8px;
  border-radius: 9999px;
  background: #fff;
  transform: scale(0);
  transition: transform 0.18s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.pay-radio.is-on .pay-radio-dot {
  transform: scale(1);
}

.pay-expand-enter-active {
  transition: opacity 0.26s ease, transform 0.26s cubic-bezier(0.2, 0.7, 0.2, 1);
}
.pay-expand-enter-from {
  opacity: 0;
  transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
  .pay-radio,
  .pay-radio-dot,
  .pay-expand-enter-active {
    transition: none;
  }
}
</style>
