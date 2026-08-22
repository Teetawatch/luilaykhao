<!--
  ไกด์ประเภทรถรับ-ส่งจากจุดรับต่างภูมิภาคมายังจุดขึ้นรถจุดแรก

  จงใจแสดงทั้งชุดแล้วไฮไลต์ใบที่ตรงกับจำนวนผู้เดินทาง ไม่ใช่โชว์รถคันเดียว —
  ตอนเลือกจุดรับยังไม่รู้ว่าจุดนั้นจะมีคนรวมกี่คน (ขึ้นกับคนอื่นที่เลือกจุดเดียวกัน)
  การโชว์ใบเดียวจึงกลายเป็นคำสัญญาที่ผิดได้เมื่อวันจริงถูกรวมกลุ่ม
-->
<template>
  <div v-if="classes.length" class="bg-white rounded-[2rem] border border-gray-100 p-5 sm:p-6">
    <div class="flex items-start gap-3 mb-1">
      <span class="material-symbols-rounded text-teal-600 text-[22px] shrink-0">airport_shuttle</span>
      <div class="min-w-0">
        <h3 class="font-bold text-gray-900">รถรับ-ส่งมาที่จุดขึ้นรถ</h3>
        <p v-if="matched" class="text-sm font-bold text-teal-700 mt-0.5">
          เดินทาง {{ paxCount }} ท่าน โดยประมาณจะใช้{{ matched.label }}
        </p>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div
        v-for="item in classes"
        :key="item.id"
        class="rounded-2xl border overflow-hidden transition-colors"
        :class="item.id === matched?.id
          ? 'border-teal-500 border-2 bg-teal-50/50'
          : 'border-gray-100 bg-gray-50/60'"
      >
        <div class="aspect-[16/10] bg-gray-100">
          <img
            v-if="item.image_url"
            :src="item.image_url"
            :alt="item.label"
            class="w-full h-full object-cover"
            loading="lazy"
          />
          <div v-else class="w-full h-full flex items-center justify-center text-gray-300">
            <span class="material-symbols-rounded text-[30px]">directions_car</span>
          </div>
        </div>
        <div class="p-3">
          <p class="font-bold text-sm leading-tight" :class="item.id === matched?.id ? 'text-teal-700' : 'text-gray-900'">
            {{ item.label }}
          </p>
          <p class="text-xs font-bold text-gray-500 mt-0.5">{{ item.pax_label }}</p>
          <p v-if="item.note" class="text-xs text-gray-400 mt-0.5 leading-snug">{{ item.note }}</p>
        </div>
      </div>
    </div>

    <p class="text-xs text-gray-500 mt-4 leading-relaxed">
      <template v-if="hasSurcharge">
        ค่าจุดรับที่จ่ายเพิ่มคือค่ารถรับ-ส่งมาที่จุดขึ้นรถจุดแรก
      </template>
      ประเภทรถขึ้นกับจำนวนผู้โดยสารรวมที่จุดนั้นในวันเดินทาง ทีมงานจะแจ้งรถคันจริงก่อนออกเดินทาง
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { loadPickupVehicleClasses, pickupVehicleClassFor } from '../lib/pickupVehicleClasses';

const props = defineProps({
  /** จำนวนผู้เดินทางของลูกค้ารายนี้ — ใช้ไฮไลต์ใบที่น่าจะตรง (0/null = ไม่ไฮไลต์) */
  paxCount: { type: Number, default: 0 },
  /** จุดรับที่เลือกแพงกว่าราคารอบไหม — ถ้าใช่ จะโยงว่าเงินที่จ่ายเพิ่มคือค่ารถคันนี้ */
  hasSurcharge: { type: Boolean, default: false },
});

const classes = ref([]);

onMounted(async () => {
  classes.value = await loadPickupVehicleClasses();
});

const matched = computed(() => pickupVehicleClassFor(classes.value, props.paxCount));
</script>
