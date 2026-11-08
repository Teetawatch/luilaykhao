<template>
  <!--
    ปฏิทิน 12 เดือนของสถานที่ — เขียว = ช่วงที่ควรไป, แดงอ่อน = ปิด, เทา = ไปได้แต่ไม่ใช่ช่วงพีค
    sm: แท่งบาง ๆ บนการ์ด (อ่านด้วยตาในแวบเดียว) · lg: ช่องมีชื่อเดือน ไล่กันเติมสีตอนเลื่อนมาถึง
  -->
  <div
    class="month-strip"
    :class="[`month-strip--${size}`]"
    role="img"
    :aria-label="ariaLabel"
  >
    <span
      v-for="m in 12"
      :key="m"
      class="month-strip__cell"
      :class="[`is-${stateOf(m)}`, { 'is-now': m === nowMonth, 'is-selected': m === selected }]"
      :style="{ '--i': m - 1 }"
      :title="`${THAI_MONTHS_LONG[m - 1]} · ${STATE_LABEL[stateOf(m)]}`"
      @click="$emit('select', m)"
    >
      <span v-if="size === 'lg'" class="month-strip__name">{{ THAI_MONTHS_SHORT[m - 1] }}</span>
    </span>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { THAI_MONTHS_LONG, THAI_MONTHS_SHORT } from '../lib/thaiDate';

const props = defineProps({
  best: { type: Array, default: () => [] },
  closed: { type: Array, default: () => [] },
  size: { type: String, default: 'sm' },
  /** เดือนที่ผู้ใช้เลือกอยู่ (หน้าเดือนไหนไปไหนดี) */
  selected: { type: Number, default: 0 },
});

defineEmits(['select']);

const STATE_LABEL = { best: 'ช่วงที่ควรไป', closed: 'ปิด', open: 'ไปได้ แต่ไม่ใช่ช่วงพีค' };

// เดือนปัจจุบันตามเวลาไทย ไม่ใช่ตามเครื่องผู้ใช้
const nowMonth = Number(new Date().toLocaleDateString('en-US', { timeZone: 'Asia/Bangkok', month: 'numeric' }));

function stateOf(m) {
  if (props.closed.includes(m)) return 'closed';
  if (props.best.includes(m)) return 'best';
  return 'open';
}

const ariaLabel = computed(() => {
  const best = props.best.map((m) => THAI_MONTHS_SHORT[m - 1]).join(' ');
  const closed = props.closed.map((m) => THAI_MONTHS_SHORT[m - 1]).join(' ');
  return [best && `ควรไป ${best}`, closed && `ปิด ${closed}`].filter(Boolean).join(' · ') || 'ไปได้ทั้งปี';
});
</script>

<style scoped>
.month-strip {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
}
.month-strip__cell {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.3s ease, color 0.3s ease, transform 0.2s ease;
}

/* ── แท่งบนการ์ด ── */
.month-strip--sm { gap: 3px; }
.month-strip--sm .month-strip__cell {
  height: 6px;
  border-radius: 999px;
}
.month-strip--sm .month-strip__cell.is-now::after {
  content: '';
  position: absolute;
  top: -6px;
  left: 50%;
  width: 3px;
  height: 3px;
  margin-left: -1.5px;
  border-radius: 999px;
  background-color: var(--color-text-dark);
}

/* ── ช่องมีชื่อเดือน ── */
/* สองแถว ๆ ละหกเดือน — สิบสองช่องในการ์ดข้างแคบเกินกว่าจะอ่านชื่อเดือนออก */
.month-strip--lg { gap: 6px; grid-template-columns: repeat(6, minmax(0, 1fr)); }
.month-strip--lg .month-strip__cell {
  height: 2.5rem;
  border-radius: 0.75rem;
  font-size: 12px;
  font-weight: 800;
}
.month-strip--lg .month-strip__cell.is-now {
  box-shadow: inset 0 0 0 2px var(--color-text-dark);
}
.month-strip--lg .month-strip__cell.is-selected {
  transform: translateY(-3px);
}


.is-best   { background-color: var(--color-accent); color: #fff; }
.is-closed { background-color: #FEE4E2; color: #B42318; }
.is-open   { background-color: var(--color-sand-dark); color: var(--color-text-muted); }

/* ช่องใหญ่ไล่กันเติมสีตอนกล่องที่ครอบ (v-reveal) โผล่ขึ้นมา — ต้องจบก่อน motion.js ถอดคลาส is-revealed (~850ms) */
:global(html.motion-ok [data-reveal]:not(.is-revealed) .month-strip--lg .month-strip__cell) {
  opacity: 0;
}
:global(html.motion-ok .is-revealed .month-strip--lg .month-strip__cell) {
  animation: monthPop 0.4s var(--ease-out) backwards;
  animation-delay: calc(var(--i) * 30ms + 100ms);
}
@keyframes monthPop {
  from { opacity: 0; transform: scale(0.7); }
}
@media (prefers-reduced-motion: reduce) {
  .month-strip__cell { animation: none !important; transition: none; }
}
</style>
