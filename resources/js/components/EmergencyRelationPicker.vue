<template>
  <div>
    <div class="flex flex-wrap gap-2" role="radiogroup" :aria-label="label">
      <button v-for="option in EMERGENCY_RELATIONS" :key="option" type="button" role="radio"
        :aria-checked="!otherMode && modelValue === option"
        class="px-3.5 py-2 rounded-full border-2 text-sm font-bold transition-colors"
        :class="!otherMode && modelValue === option
          ? 'border-accent bg-accent-tint text-accent'
          : error ? 'border-red-300 bg-white text-gray-700 hover:bg-gray-50' : 'border-gray-200 bg-gray-50/50 text-gray-700 hover:bg-gray-50'"
        @click="pick(option)">
        {{ $t(option) }}
      </button>
      <button type="button" role="radio" :aria-checked="otherMode"
        class="px-3.5 py-2 rounded-full border-2 text-sm font-bold transition-colors"
        :class="otherMode
          ? 'border-accent bg-accent-tint text-accent'
          : error ? 'border-red-300 bg-white text-gray-700 hover:bg-gray-50' : 'border-gray-200 bg-gray-50/50 text-gray-700 hover:bg-gray-50'"
        @click="startOther">
        {{ $t('อื่น ๆ') }}
      </button>
    </div>
    <input v-if="otherMode" ref="otherInput" :value="modelValue" type="text" :maxlength="EMERGENCY_RELATION_MAX"
      :placeholder="$t('เช่น หัวหน้างาน, ลูกพี่ลูกน้อง')"
      class="mt-3 w-full border-2 rounded-2xl px-4 py-3 text-sm text-gray-900 focus:ring-4 focus:ring-accent/10 focus:border-accent outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
      :class="error ? 'border-red-300' : 'border-gray-200'"
      @input="emit('update:modelValue', $event.target.value)" />
  </div>
</template>

<script setup>
import { $t } from '@/i18n';
// ความสัมพันธ์ผู้ติดต่อฉุกเฉิน: แตะตัวเลือก หรือ "อื่น ๆ" แล้วพิมพ์เอง — เก็บเป็นข้อความเสมอ
import { nextTick, ref, watch } from 'vue';
import { EMERGENCY_RELATIONS, EMERGENCY_RELATION_MAX } from '../lib/emergencyRelation';

const props = defineProps({
  modelValue: { type: String, default: '' },
  error: { type: Boolean, default: false },
  label: { type: String, default: () => $t('ความสัมพันธ์กับผู้ติดต่อฉุกเฉิน') },
});
const emit = defineEmits(['update:modelValue']);

const isOther = (v) => !!String(v || '').trim() && !EMERGENCY_RELATIONS.includes(String(v).trim());
const otherMode = ref(isOther(props.modelValue));
const otherInput = ref(null);

// ค่าเปลี่ยนจากข้างนอก (เติมจากโปรไฟล์/สมุดผู้ร่วมเดินทาง) — สลับโหมดให้ตรง
watch(() => props.modelValue, (v) => {
  if (isOther(v)) otherMode.value = true;
  else if (EMERGENCY_RELATIONS.includes(String(v || '').trim())) otherMode.value = false;
});

function pick(option) {
  otherMode.value = false;
  emit('update:modelValue', option);
}

async function startOther() {
  if (!otherMode.value) {
    otherMode.value = true;
    emit('update:modelValue', '');
  }
  await nextTick();
  otherInput.value?.focus();
}
</script>
