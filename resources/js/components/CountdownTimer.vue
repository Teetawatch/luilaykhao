<template>
  <div v-if="seconds > 0"
    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-bold"
    :class="seconds < 120 ? 'bg-red-100 text-red-700' : 'bg-sand text-accent'">
    <i class="fa-solid fa-stopwatch animate-pulse"></i>
    <span>เวลาที่เหลือ: {{ formatted }}</span>
  </div>
  <div v-else-if="expired" class="bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm font-bold">
    <i class="fa-solid fa-triangle-exclamation mr-1"></i>หมดเวลา! กรุณาเลือกที่นั่งใหม่
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  seconds: { type: Number, default: 0 },
  expired: { type: Boolean, default: false },
});

const formatted = computed(() => {
  const m = Math.floor(props.seconds / 60);
  const s = props.seconds % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
});
</script>
