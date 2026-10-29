<template>
  <div>
    <button type="button" @click="open = true"
      class="inline-flex items-center gap-1 text-xs font-bold text-violet-600 hover:text-violet-700 hover:underline">
      <span class="material-symbols-rounded text-[16px]">help</span>
      {{ hasExample ? 'ดูวิธีแนบและตัวอย่าง' : 'ดูวิธีแนบ' }}
    </button>

    <Teleport to="body">
      <div v-if="open" class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 sm:px-4"
        @click.self="open = false">
        <div class="bg-white w-full sm:max-w-lg rounded-t-[24px] sm:rounded-[24px] overflow-hidden flex flex-col max-h-[90vh]"
          role="dialog" aria-modal="true" :aria-label="`วิธีแนบ ${doc.label}`">
          <div class="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3 shrink-0">
            <div class="min-w-0">
              <p class="text-xs font-bold text-violet-600">วิธีแนบเอกสาร</p>
              <h3 class="text-lg font-bold text-gray-900 leading-snug">{{ doc.label }}</h3>
            </div>
            <button type="button" @click="open = false" aria-label="ปิด"
              class="w-8 h-8 rounded-lg border border-gray-200 hover:bg-gray-50 inline-flex items-center justify-center shrink-0">
              <span class="material-symbols-rounded text-[18px]">close</span>
            </button>
          </div>

          <div class="p-5 space-y-5 overflow-y-auto">
            <p v-if="doc.note" class="text-sm text-gray-600 leading-relaxed">{{ doc.note }}</p>

            <!-- รูปตัวอย่างที่แอดมินอัปโหลด — กดเปิดเต็มจอเพื่อดูรายละเอียด -->
            <a v-if="hasExample" :href="doc.example_image_url" target="_blank" rel="noopener"
              class="block rounded-2xl overflow-hidden border border-gray-200 bg-gray-50">
              <img :src="doc.example_image_url" :alt="`ตัวอย่าง ${doc.label}`" class="w-full max-h-72 object-contain" loading="lazy" />
              <span class="flex items-center justify-center gap-1 py-2 text-xs font-bold text-gray-500 border-t border-gray-200 bg-white">
                <span class="material-symbols-rounded text-[14px]">zoom_in</span> ตัวอย่างที่ถูกต้อง · แตะเพื่อดูภาพใหญ่
              </span>
            </a>

            <div v-if="steps.length">
              <p class="text-sm font-bold text-gray-900 mb-2">ทำตามนี้</p>
              <ol class="space-y-2">
                <li v-for="(step, i) in steps" :key="i" class="flex items-start gap-3 text-sm text-gray-700 leading-relaxed">
                  <span class="w-6 h-6 rounded-full bg-violet-100 text-violet-700 text-xs font-black flex items-center justify-center shrink-0">{{ i + 1 }}</span>
                  <span class="pt-0.5">{{ step }}</span>
                </li>
              </ol>
            </div>

            <div v-if="tips.length" class="rounded-2xl bg-gray-50 border border-gray-100 p-4">
              <p class="text-sm font-bold text-gray-900 mb-2">เช็คก่อนกดแนบ</p>
              <ul class="space-y-1.5">
                <li v-for="(tip, i) in tips" :key="i" class="flex items-start gap-2 text-sm text-gray-700 leading-relaxed">
                  <span class="material-symbols-rounded text-[18px] text-teal-600 shrink-0">check_circle</span>
                  <span>{{ tip }}</span>
                </li>
              </ul>
            </div>

            <p class="text-xs text-gray-500">
              รับไฟล์รูปภาพหรือ PDF ไม่เกิน {{ maxSizeMb }} MB ต่อไฟล์
              <template v-if="maxFiles > 1"> · แนบได้สูงสุด {{ maxFiles }} ไฟล์ (เอกสารหลายหน้าแนบแยกทีละหน้าได้)</template>
              <template v-else> · แนบได้ 1 ไฟล์ เลือกใหม่จะแทนที่ไฟล์เดิม</template>
            </p>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
/**
 * ปุ่ม "ดูวิธีแนบ" ข้างช่องแนบเอกสาร พร้อมหน้าต่างอธิบาย
 *
 * เนื้อหามาจากข้อกำหนดของทริปทั้งหมด: `guide` (แอดมินเขียน บรรทัดละข้อ),
 * `example_image_url` (รูปตัวอย่าง) และ `tips` (เช็คลิสต์ของระบบ) — ใช้ทั้ง
 * หน้าจองและหน้าเอกสารแนบหลังจอง ลูกค้าจะได้เห็นคำอธิบายชุดเดียวกัน
 */
import { computed, ref } from 'vue';

const props = defineProps({
  doc: { type: Object, required: true },
  maxSizeMb: { type: Number, default: 10 },
  maxFiles: { type: Number, default: 5 },
});

const open = ref(false);

const steps = computed(() => String(props.doc.guide || '').split('\n').map((line) => line.trim()).filter(Boolean));
const tips = computed(() => (Array.isArray(props.doc.tips) ? props.doc.tips : []));
const hasExample = computed(() => !!props.doc.example_image_url);
</script>
