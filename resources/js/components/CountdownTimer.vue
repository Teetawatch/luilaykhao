<template>
  <div v-if="seconds > 0"
    class="rounded-2xl border overflow-hidden transition-all duration-300"
    :class="isUrgent ? 'border-red-200 bg-red-50' : isWarning ? 'border-amber-200 bg-amber-50' : 'border-teal-200 bg-teal-50'">
    <div class="flex items-center gap-3 px-4 py-3">
      <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
        :class="isUrgent ? 'bg-red-100' : isWarning ? 'bg-amber-100' : 'bg-teal-100'">
        <span class="material-symbols-rounded text-[20px]"
          :class="[isUrgent ? 'text-red-600 animate-pulse' : isWarning ? 'text-amber-600' : 'text-teal-600']"
          style="font-variation-settings:'FILL' 1,'wght' 400">timer</span>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-bold uppercase tracking-wider mb-0.5"
          :class="isUrgent ? 'text-red-500' : isWarning ? 'text-amber-600' : 'text-teal-600'">
          {{ isUrgent ? 'เวลาใกล้หมดแล้ว!' : 'เวลาที่เหลือในการจอง' }}
        </p>
        <p class="text-xl font-extrabold font-anuphan tracking-tight"
          :class="isUrgent ? 'text-red-700' : isWarning ? 'text-amber-700' : 'text-teal-700'">
          {{ formatted }}
        </p>
      </div>
      <div class="text-right shrink-0">
        <p class="text-xs font-medium"
          :class="isUrgent ? 'text-red-400' : isWarning ? 'text-amber-500' : 'text-teal-500'">
          ที่นั่งจะถูกปลดล็อค
        </p>
        <p class="text-xs font-bold"
          :class="isUrgent ? 'text-red-600' : isWarning ? 'text-amber-600' : 'text-teal-600'">
          หากไม่ทำรายการ
        </p>
      </div>
    </div>
    <!-- Progress bar -->
    <div class="h-1.5 w-full"
      :class="isUrgent ? 'bg-red-100' : isWarning ? 'bg-amber-100' : 'bg-teal-100'">
      <div class="h-full transition-all duration-1000 rounded-full"
        :class="isUrgent ? 'bg-red-500' : isWarning ? 'bg-amber-500' : 'bg-teal-500'"
        :style="{ width: progressWidth }">
      </div>
    </div>
  </div>
  <div v-else-if="expired"
    class="flex items-center gap-3 px-4 py-3 rounded-2xl bg-red-50 border border-red-200">
    <div class="w-9 h-9 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
      <span class="material-symbols-rounded text-[20px] text-red-600" style="font-variation-settings:'FILL' 1,'wght' 400">timer_off</span>
    </div>
    <div>
      <p class="text-sm font-bold text-red-700">หมดเวลาแล้ว!</p>
      <p class="text-xs text-red-500 font-medium">กรุณาเริ่มการจองใหม่</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  seconds: { type: Number, default: 0 },
  expired: { type: Boolean, default: false },
  totalSeconds: { type: Number, default: 600 },
});

const formatted = computed(() => {
  const m = Math.floor(props.seconds / 60);
  const s = props.seconds % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
});

const isUrgent = computed(() => props.seconds <= 60);
const isWarning = computed(() => props.seconds <= 180 && props.seconds > 60);
const progressWidth = computed(() => `${(props.seconds / props.totalSeconds) * 100}%`);
</script>
