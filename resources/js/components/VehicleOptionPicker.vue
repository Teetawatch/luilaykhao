<template>
  <!--
    เลือกว่าจะเดินทางไปกับคันไหน — รอบเดียวกันวิ่งได้ทั้งรถบัสและรถตู้ คนละราคา

    ราคาที่แสดงเป็น "ส่วนต่างต่อคน" ตรงกับที่เซิร์ฟเวอร์คิด (บวกท้ายราคาจุดขึ้นรถ)
    ไม่ใช่ราคาเต็มของคันนั้น เพราะราคาเต็มขึ้นกับจุดที่อาจยังไม่ได้เลือก
  -->
  <div v-if="options.length > 1" class="mb-8 bg-white p-6 md:p-8 rounded-3xl border border-gray-100">
    <div class="flex items-center gap-3 mb-2">
      <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-600 flex items-center justify-center">
        <span class="material-symbols-rounded text-lg">directions_bus</span>
      </div>
      <h2 class="text-2xl font-bold text-gray-900">เลือกประเภทรถ</h2>
    </div>
    <p class="text-gray-500 mb-6">รอบนี้มีให้เลือกมากกว่าหนึ่งแบบ ราคาต่อท่านต่างกันตามคันที่เลือก</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <button
        v-for="option in options" :key="option.id"
        type="button"
        :disabled="option.is_sold_out"
        @click="$emit('update:modelValue', option.id)"
        class="text-left p-5 rounded-3xl border-2 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
        :class="modelValue === option.id
          ? 'border-emerald-500 bg-emerald-50/30 ring-4 ring-emerald-500/10'
          : 'border-gray-100 bg-white hover:border-teal-500/30'">
        <div class="flex items-start gap-3">
          <img v-if="option.image_url" :src="option.image_url" :alt="option.label"
            class="w-16 h-16 rounded-2xl object-cover shrink-0" loading="lazy" />
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-[20px]"
                :class="modelValue === option.id ? 'text-emerald-600' : 'text-gray-300'">
                {{ modelValue === option.id ? 'check_circle' : 'radio_button_unchecked' }}
              </span>
              <p class="font-bold text-gray-900 truncate">{{ option.label }}</p>
            </div>
            <p v-if="option.note" class="text-sm text-gray-500 mt-1 line-clamp-2">{{ option.note }}</p>
            <div class="flex items-center gap-2 mt-2 flex-wrap">
              <span class="text-sm font-bold" :class="Number(option.price_adjustment) === 0 ? 'text-gray-500' : 'text-teal-700'">
                {{ priceLabel(option) }}
              </span>
              <span v-if="seatLabel(option)" class="text-[11px] font-bold px-2 py-0.5 rounded-full"
                :class="option.is_sold_out ? 'bg-red-50 text-red-600' : 'bg-teal-50 text-teal-700'">
                {{ seatLabel(option) }}
              </span>
            </div>
          </div>
        </div>
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  options: { type: Array, default: () => [] },
  modelValue: { type: Number, default: null },
});
defineEmits(['update:modelValue']);

const money = (value) => `฿${Number(value || 0).toLocaleString()}`;

const priceLabel = (option) => {
  const adjustment = Number(option.price_adjustment || 0);
  if (adjustment === 0) return 'ราคาปกติ';
  return `${adjustment > 0 ? '+' : '-'}${money(Math.abs(adjustment))} / ท่าน`;
};

// null = ไม่ได้กำหนดโควตาย่อย ใช้ที่นั่งว่างรวมของรอบ จึงไม่ขึ้นตัวเลข
const seatLabel = (option) => {
  if (option.is_sold_out) return 'เต็มแล้ว';
  if (option.available_seats === null || option.available_seats === undefined) return '';
  return `เหลือ ${option.available_seats} ที่`;
};
</script>
