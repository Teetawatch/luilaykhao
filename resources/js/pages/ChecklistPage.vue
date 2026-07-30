<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32 print:bg-white print:pt-0">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">

      <section class="mb-6 print:mb-4">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">{{ content.title }}</h1>
        <p class="text-[#505E5E] text-sm print:hidden">{{ content.intro }}</p>
        <p class="hidden print:block text-sm text-[#505E5E]">
          {{ activeTypeLabel }} · {{ activeSeasonLabel }} · {{ activeNightsLabel }}
        </p>
      </section>

      <!-- ตัวเลือก -->
      <section class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5 print:hidden">
        <div class="space-y-4">
          <div>
            <p class="text-[12px] font-bold text-[#505E5E] mb-2">แบบทริป</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="type in content.trip_types"
                :key="type.key"
                type="button"
                class="chip"
                :class="options.tripType === type.key ? 'chip--on' : ''"
                @click="options.tripType = type.key"
              >{{ type.label }}</button>
            </div>
          </div>

          <div>
            <p class="text-[12px] font-bold text-[#505E5E] mb-2">ช่วงเวลา</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="season in content.seasons"
                :key="season.key"
                type="button"
                class="chip"
                :class="options.season === season.key ? 'chip--on' : ''"
                @click="options.season = season.key"
              >{{ season.label }}</button>
            </div>
          </div>

          <div>
            <p class="text-[12px] font-bold text-[#505E5E] mb-2">ระยะเวลา</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="night in content.nights"
                :key="night.key"
                type="button"
                class="chip"
                :class="options.nights === night.key ? 'chip--on' : ''"
                @click="options.nights = night.key"
              >{{ night.label }}</button>
            </div>
          </div>
        </div>
      </section>

      <!-- ความคืบหน้า -->
      <section class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 mb-5 print:hidden">
        <div class="flex items-center justify-between mb-2">
          <p class="text-[13px] font-bold text-[#505E5E]">จัดของแล้ว</p>
          <p class="text-[13px] font-extrabold text-[#1a1c1c]">{{ checkedCount }} / {{ totalCount }}</p>
        </div>
        <div class="h-2 bg-[#E8EEEF] rounded-full overflow-hidden">
          <div class="h-full bg-[#006565] rounded-full transition-all" :style="{ width: progress + '%' }"></div>
        </div>

        <div class="flex flex-wrap gap-4 mt-4">
          <button type="button" class="text-[13px] font-bold text-[#006565]" @click="printList">พิมพ์รายการ</button>
          <button v-if="checkedCount" type="button" class="text-[13px] font-bold text-[#B42318]" @click="clearChecks">
            ล้างที่ติ๊กไว้
          </button>
        </div>
      </section>

      <!-- รายการ -->
      <section
        v-for="category in visibleCategories"
        :key="category.key"
        class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-4 print:border-0 print:p-0 print:mb-6"
      >
        <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">{{ category.title }}</h2>

        <ul class="space-y-3">
          <li v-for="item in category.items" :key="item.label">
            <label class="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                :checked="checked[item.label]"
                class="mt-0.5 w-5 h-5 rounded-[6px] border-[#D6DEDE] accent-[#006565] shrink-0"
                @change="toggle(item.label)"
              />
              <span class="min-w-0">
                <span
                  class="text-sm leading-snug"
                  :class="checked[item.label] ? 'text-[#B4C4C4] line-through' : 'text-[#1a1c1c]'"
                >
                  {{ item.label }}
                  <span v-if="item.essential" class="text-[11px] font-bold text-[#B42318] ml-1.5 print:hidden">ขาดไม่ได้</span>
                </span>
                <span v-if="item.note" class="block text-[12px] text-[#8A9A9A] mt-0.5 leading-snug">{{ item.note }}</span>
              </span>
            </label>
          </li>
        </ul>
      </section>

      <p v-if="content.footnote" class="text-center text-[13px] text-[#8A9A9A] mt-6 print:hidden">
        {{ content.footnote }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { usePageContent } from '../lib/pageContent';

const STORAGE_KEY = 'packing_checklist';

const { content } = usePageContent('checklist', {
  title: '',
  intro: '',
  footnote: '',
  trip_types: [],
  seasons: [],
  nights: [],
  categories: [],
});

const options = reactive({ tripType: '', season: '', nights: '' });
const checked = ref(loadChecked());

/**
 * รายการที่ตรงกับเงื่อนไขที่เลือก
 *
 * เงื่อนไขที่ "ว่าง" แปลว่าใช้ได้ทุกกรณี — ต้องเช็ค length ไม่ใช่แค่ความมีอยู่ของ array
 * เพราะฝั่งแอดมินส่ง [] มาเสมอเมื่อไม่ได้เลือกอะไร
 */
function matches(item) {
  if (item.trips?.length && !item.trips.includes(options.tripType)) return false;
  if (item.seasons?.length && !item.seasons.includes(options.season)) return false;
  if (item.overnight && options.nights !== 'overnight') return false;

  return true;
}

const visibleCategories = computed(() => (content.value.categories || [])
  .map(category => ({ ...category, items: (category.items || []).filter(matches) }))
  .filter(category => category.items.length > 0));

const allItems = computed(() => visibleCategories.value.flatMap(c => c.items));
const totalCount = computed(() => allItems.value.length);
const checkedCount = computed(() => allItems.value.filter(item => checked.value[item.label]).length);
const progress = computed(() => (totalCount.value ? Math.round((checkedCount.value / totalCount.value) * 100) : 0));

const activeTypeLabel = computed(() => labelOf(content.value.trip_types, options.tripType));
const activeSeasonLabel = computed(() => labelOf(content.value.seasons, options.season));
const activeNightsLabel = computed(() => labelOf(content.value.nights, options.nights));

function labelOf(list, key) {
  return (list || []).find(o => o.key === key)?.label || '';
}

/**
 * ตัวเลือกมาจากหลังบ้าน จึงเลือกค่าเริ่มต้นได้ก็ต่อเมื่อโหลดเสร็จ
 * และถ้าแอดมินลบ/เปลี่ยนคีย์ที่ผู้ใช้ค้างไว้ ต้องเด้งกลับไปตัวแรกแทนที่จะได้รายการว่าง
 */
watch(content, (value) => {
  const pick = (list, current) => ((list || []).some(o => o.key === current) ? current : (list?.[0]?.key || ''));

  options.tripType = pick(value.trip_types, options.tripType);
  options.season = pick(value.seasons, options.season);
  options.nights = pick(value.nights, options.nights);
}, { immediate: true });

/**
 * เก็บสถานะติ๊กด้วยชื่อรายการ ไม่ใช่ index — สลับแบบทริปแล้วรายการเปลี่ยนลำดับ
 * แต่ของที่จัดไปแล้วต้องยังติ๊กอยู่
 */
function loadChecked() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
  } catch {
    return {};
  }
}

function toggle(label) {
  checked.value = { ...checked.value, [label]: !checked.value[label] };
}

function clearChecks() {
  checked.value = {};
}

function printList() {
  window.print();
}

watch(checked, (value) => {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
}, { deep: true });
</script>

<style scoped>
.chip {
  border-radius: 12px;
  border: 1px solid #E8EEEF;
  background: #fff;
  padding: 8px 14px;
  font-size: 13px;
  font-weight: 700;
  color: #505E5E;
}

.chip--on {
  background: #006565;
  border-color: #006565;
  color: #fff;
}

@media print {
  .chip {
    display: none;
  }
}
</style>
