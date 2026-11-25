<template>
  <span
    v-if="tier && tier !== 'friend'"
    class="inline-flex items-center gap-1 rounded-full font-bold whitespace-nowrap align-middle"
    :class="[sizeClasses, colours]"
    :title="label"
  >
    <span class="material-symbols-rounded" :class="iconSize" style="font-variation-settings:'FILL' 1">explore</span>
    <span v-if="!iconOnly">{{ label }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue';

/**
 * ป้ายระดับสมาชิก — ตั้งใจให้ไปโผล่ในที่ที่ "คนอื่นเห็น" ด้วย (แชทกลุ่มทริป
 * รีวิว ฟีดรูป โปรไฟล์สาธารณะ) เพราะคุณค่าของระดับอยู่ที่คนอื่นเห็น ไม่ใช่แค่
 * เจ้าตัวเห็นในหน้าตัวเอง
 *
 * ระดับเริ่มต้น (friend) ไม่แสดงป้าย — ถ้าทุกคนมีป้าย ป้ายก็ไม่ได้แปลว่าอะไร
 */
const props = defineProps({
  tier: { type: String, default: '' },
  /** ชื่อระดับจาก API — ไม่ควรแปลเองฝั่งนี้ ให้ backend เป็นแหล่งเดียว */
  label: { type: String, default: '' },
  size: { type: String, default: 'md' },
  iconOnly: { type: Boolean, default: false },
});

// เงิน / ทอง / แพลทินัม — แพลทินัมเป็นป้ายทึบสีเข้ม ให้ต่างจากเงินชัด ๆ แม้ตัวเล็ก
const palette = {
  frequent: 'bg-[#EEF1F4] text-[#4B5563] border border-[#D9DFE5]',
  comrade: 'bg-[#F6EDDC] text-[#8A5A12] border border-[#EAD8B4]',
  insider: 'bg-[#1F2329] text-[#E6E8EC] border border-[#1F2329]',
};

const colours = computed(() => palette[props.tier] || palette.frequent);

const sizeClasses = computed(() => (props.size === 'sm'
  ? 'text-[10px] px-2 py-0.5'
  : 'text-xs px-2.5 py-1'));

const iconSize = computed(() => (props.size === 'sm' ? 'text-[12px]' : 'text-[14px]'));
</script>
