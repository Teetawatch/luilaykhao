<template>
  <!--
    ลำดับขั้นการจ่าย (สแกน → รอธนาคาร → บันทึกยอด) แบบไทม์ไลน์มีเส้นเชื่อม — ชุดเดียวกับ
    _PayTimeline ในแอป (payment_beam.part.dart)

    ขั้นที่ผ่านแล้ว = วงเขียวมีเครื่องหมายถูก เส้นเชื่อมไหลเติมสีลงมาถึงขั้นปัจจุบัน
    ขั้นปัจจุบัน = วงคลื่นเต้นช้าๆ ให้เห็นว่ากำลังทำงาน · ขั้นที่ยังไม่ถึง = วงเทามีเลข
    แต่ละแถวเลื่อนขึ้นตามกันตอนปรากฏ — ปิดทั้งหมดเมื่อเครื่องตั้ง "ลดการเคลื่อนไหว"
  -->
  <ol class="pay-timeline text-left">
    <li v-for="(step, i) in steps" :key="step.title" class="pt-step"
      :class="i < current ? 'is-done' : i === current ? 'is-now' : 'is-todo'"
      :style="{ '--i': i }"
      :aria-current="i === current ? 'step' : undefined">
      <span v-if="i < steps.length - 1" class="pt-rail" aria-hidden="true"><span class="pt-fill"></span></span>

      <span class="pt-dot" aria-hidden="true">
        <Transition name="pt-pop" mode="out-in">
          <span v-if="i < current" key="done" class="pt-dot-done">
            <span class="material-symbols-rounded">check</span>
          </span>
          <span v-else-if="i === current" key="now" class="pt-dot-now">
            <span class="pt-halo"></span>
            <span class="pt-ring"><span class="pt-core"></span></span>
          </span>
          <span v-else key="todo" class="pt-dot-todo tabular-nums">{{ i + 1 }}</span>
        </Transition>
      </span>

      <span class="pt-text">
        <span class="pt-title">{{ step.title }}</span>
        <span v-if="step.hint" class="pt-hint">{{ step.hint }}</span>
      </span>
    </li>
  </ol>
</template>

<script setup>
defineProps({
  /** [{ title, hint? }] */
  steps: { type: Array, required: true },
  /** ขั้นที่กำลังทำอยู่ (เริ่ม 0) — ขั้นก่อนหน้านี้นับว่าเสร็จแล้ว */
  current: { type: Number, default: 0 },
});
</script>

<style scoped>
.pt-step {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding-bottom: 18px;
  animation: pt-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) both;
  animation-delay: calc(var(--i) * 120ms);
}
.pt-step:last-child {
  padding-bottom: 0;
}

/* เส้นเชื่อม: จากใต้วงถึงขอบล่างของแถว */
.pt-rail {
  position: absolute;
  left: 13px;
  top: 32px;
  bottom: 4px;
  width: 2px;
  border-radius: 1px;
  background: #e5e7eb;
  overflow: hidden;
}
.pt-fill {
  position: absolute;
  inset: 0;
  background: var(--color-accent);
  transform: scaleY(0);
  transform-origin: top;
  transition: transform 0.6s cubic-bezier(0.65, 0, 0.35, 1);
}
.is-done .pt-fill {
  transform: scaleY(1);
}

.pt-dot {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.pt-dot-done {
  width: 24px;
  height: 24px;
  border-radius: 9999px;
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}
.pt-dot-done .material-symbols-rounded {
  font-size: 15px;
}
.pt-dot-now {
  position: relative;
  width: 24px;
  height: 24px;
}
/* วงคลื่นอยู่ก่อนวงขาวใน DOM จึงถูกวาดไว้ข้างหลังเอง ไม่ต้องพึ่ง z-index ติดลบ
   (ซึ่งจะไปมุดใต้พื้นหลังของกล่องที่ครอบอยู่) */
.pt-ring {
  position: absolute;
  inset: 0;
  border-radius: 9999px;
  border: 2px solid var(--color-accent);
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}
.pt-core {
  width: 8px;
  height: 8px;
  border-radius: 9999px;
  background: var(--color-accent);
}
.pt-halo {
  position: absolute;
  inset: -2px;
  border-radius: 9999px;
  background: var(--color-accent);
  opacity: 0;
  animation: pt-pulse 1.6s cubic-bezier(0, 0, 0.2, 1) infinite;
}
.pt-dot-todo {
  width: 24px;
  height: 24px;
  border-radius: 9999px;
  border: 1.5px solid #e5e7eb;
  color: #9ca3af;
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.pt-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding-top: 3px;
  min-width: 0;
}
.pt-title {
  font-size: 14px;
  font-weight: 700;
  line-height: 1.4;
  color: #111827;
  transition: color 0.24s;
}
.is-todo .pt-title {
  color: #9ca3af;
}
.pt-hint {
  font-size: 12px;
  font-weight: 500;
  line-height: 1.45;
  color: #6b7280;
}

.pt-pop-enter-active {
  transition: transform 0.32s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s;
}
.pt-pop-leave-active {
  transition: transform 0.14s ease-in, opacity 0.14s;
}
.pt-pop-enter-from,
.pt-pop-leave-to {
  transform: scale(0.4);
  opacity: 0;
}

@keyframes pt-rise {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: none; }
}
@keyframes pt-pulse {
  0% { transform: scale(1); opacity: 0.32; }
  80%, 100% { transform: scale(1.7); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
  .pt-step { animation: none; }
  .pt-halo { animation: none; }
  .pt-fill { transition: none; }
  .pt-pop-enter-active,
  .pt-pop-leave-active { transition: none; }
}
</style>
