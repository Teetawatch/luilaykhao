<template>
  <div>
    <button v-if="activeCount" type="button" @click="$emit('clear')"
      class="mb-6 w-full inline-flex items-center justify-center gap-1.5 text-xs font-bold text-[var(--color-text-muted)] hover:text-red-500 transition-colors cursor-pointer">
      <span class="material-symbols-rounded text-[16px]">restart_alt</span>
      ล้างตัวกรองทั้งหมด ({{ activeCount }})
    </button>

    <!-- หมวดหมู่มาจาก API ถ้ายังโหลดไม่เสร็จหรือไม่มีเลย อย่าโชว์หัวข้อลอย ๆ -->
    <section v-if="categories.length" class="mb-7">
      <h3 class="text-sm font-extrabold text-[var(--color-text-dark)] mb-3.5 flex items-center gap-2">
        <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">category</span>
        หมวดหมู่กิจกรรม
      </h3>
      <div class="flex flex-wrap gap-2">
        <button v-for="cat in categories" :key="cat.value" type="button"
          @click="$emit('pick-type', cat.value)"
          :aria-pressed="type === cat.value"
          :class="chipClass(type === cat.value)">
          {{ cat.label }}
        </button>
      </div>
    </section>

    <section class="mb-7">
      <h3 class="text-sm font-extrabold text-[var(--color-text-dark)] mb-3.5 flex items-center gap-2">
        <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">schedule</span>
        ระยะเวลา
      </h3>
      <div class="flex flex-wrap gap-2">
        <button v-for="dur in durations" :key="dur.key" type="button"
          @click="$emit('pick-duration', dur.key)"
          :aria-pressed="duration === dur.key"
          :class="chipClass(duration === dur.key)">
          {{ dur.label }}
        </button>
      </div>
    </section>

    <!-- ระดับความยากใช้กับเดินป่าเท่านั้น ทริปดำน้ำหรือรถตู้ไม่มีค่านี้ -->
    <section v-if="type === 'trekking'">
      <h3 class="text-sm font-extrabold text-[var(--color-text-dark)] mb-3.5 flex items-center gap-2">
        <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">terrain</span>
        ระดับความยาก
      </h3>
      <div class="flex flex-wrap gap-2">
        <button v-for="diff in difficulties" :key="diff.value" type="button"
          @click="$emit('pick-difficulty', diff.value)"
          :aria-pressed="difficulty === diff.value"
          :class="chipClass(difficulty === diff.value)">
          {{ diff.label }}
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
/**
 * แผงตัวกรองของหน้ารวมทริป — ใช้ทั้งในแถบซ้ายบนจอใหญ่และในแผ่นเลื่อนขึ้นบนมือถือ
 * ตัวมันเองไม่รู้จัก store: รับค่าที่เลือกอยู่มาเป็น prop แล้วส่ง event กลับ
 * เพื่อให้ทั้งสองที่แสดงสถานะเดียวกันเสมอ
 */
defineProps({
  categories: { type: Array, required: true },
  difficulties: { type: Array, required: true },
  durations: { type: Array, required: true },
  type: { type: String, default: '' },
  difficulty: { type: String, default: '' },
  duration: { type: String, default: '' },
  activeCount: { type: Number, default: 0 },
});

defineEmits(['pick-type', 'pick-difficulty', 'pick-duration', 'clear']);

function chipClass(active) {
  return [
    'rounded-full px-4 py-2.5 text-sm font-bold border transition-colors duration-300 cursor-pointer',
    active
      ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white'
      : 'bg-white border-gray-200 text-[var(--color-text-mid)] hover:border-[var(--color-accent)]',
  ];
}
</script>
