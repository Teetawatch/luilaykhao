<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">เดือนไหนไปไหนดี</h1>
        <p class="text-[#505E5E] text-sm max-w-2xl">
          ปฏิทินธรรมชาติของไทยทั้งปี — ที่ไหนอยู่ในช่วงพีค ที่ไหนปิด
          ใช้วางแผนล่วงหน้าได้แม้ยังไม่คิดจะจองอะไร
        </p>
      </section>

      <div v-if="loading" class="space-y-3">
        <div v-for="i in 4" :key="i" class="bg-white rounded-[20px] border border-[#E8EEEF] p-6 animate-pulse">
          <div class="h-4 w-24 bg-[#EDF1F1] rounded mb-3"></div>
          <div class="h-3 w-2/3 bg-[#EDF1F1] rounded"></div>
        </div>
      </div>

      <div v-else-if="!hasAnyData" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">calendar_month</span>
        <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ยังไม่มีข้อมูลฤดูกาล</p>
        <p class="text-[#505E5E] text-sm">ทีมงานกำลังทยอยเพิ่มข้อมูลของแต่ละสถานที่</p>
      </div>

      <template v-else>
        <!-- แถบเลือกเดือน -->
        <div class="flex gap-1.5 overflow-x-auto scrollbar-none mb-6 pb-1">
          <button
            v-for="month in months"
            :key="month.month"
            type="button"
            class="shrink-0 rounded-[12px] px-3.5 py-2 text-[13px] font-bold border transition"
            :class="month.month === selected
              ? 'bg-[#006565] border-[#006565] text-white'
              : 'bg-white border-[#E8EEEF] text-[#505E5E]'"
            @click="selected = month.month"
          >
            {{ THAI_MONTHS_SHORT[month.month - 1] }}
            <span v-if="month.month === currentMonth" class="ml-0.5">·</span>
          </button>
        </div>

        <template v-if="active">
          <!-- หัวเดือน -->
          <section class="rounded-[20px] bg-[#0F3D3E] text-white p-5 sm:p-6 mb-5">
            <p class="text-white/60 text-[13px] font-bold mb-1">
              {{ active.season_label }}<span v-if="active.month === currentMonth"> · เดือนนี้</span>
            </p>
            <h2 class="text-2xl font-extrabold">{{ active.label }}</h2>
            <p class="text-white/70 text-sm mt-2">
              <template v-if="active.best.length">
                {{ active.best.length }} ที่อยู่ในช่วงที่ควรไป
              </template>
              <template v-else>
                เดือนนี้ไม่มีที่ไหนอยู่ในช่วงพีค — เป็นช่วงพักของหลายเส้นทาง
              </template>
            </p>
          </section>

          <!-- ที่ที่ควรไป -->
          <section v-if="active.best.length" class="mb-5">
            <h3 class="text-[13px] font-bold text-[#505E5E] mb-3 px-1">ช่วงที่ควรไป</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <router-link
                v-for="place in active.best"
                :key="place.id"
                :to="`/places/${place.slug}`"
                class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden flex gap-3 p-3"
              >
                <div class="w-20 h-20 rounded-[14px] bg-[#EDF1F1] shrink-0 overflow-hidden">
                  <img
                    v-if="place.cover_image"
                    :src="place.cover_image"
                    :alt="place.name"
                    loading="lazy"
                    class="w-full h-full object-cover"
                  />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-bold text-[#1a1c1c] text-sm truncate">{{ place.name }}</p>
                  <p class="text-[12px] text-[#8A9A9A] mt-0.5 truncate">
                    {{ [place.province, place.region_label].filter(Boolean).join(' · ') }}
                  </p>
                  <p class="text-[12px] text-[#505E5E] mt-1.5 line-clamp-2 leading-snug">{{ place.summary }}</p>
                </div>
              </router-link>
            </div>
          </section>

          <!-- ที่ที่ปิด -->
          <section v-if="active.closed.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
            <h3 class="text-[13px] font-bold text-[#505E5E] mb-1">ปิดในเดือนนี้</h3>
            <p class="text-[12px] text-[#8A9A9A] mb-4">อย่าเพิ่งวางแผนไป — ยังเข้าไม่ได้</p>
            <ul class="space-y-3">
              <li v-for="place in active.closed" :key="place.slug">
                <router-link :to="`/places/${place.slug}`" class="block">
                  <p class="font-bold text-[#1a1c1c] text-sm">{{ place.name }}</p>
                  <p v-if="place.closure_note" class="text-[12px] text-[#8A9A9A] mt-0.5 leading-snug">
                    {{ place.closure_note }}
                  </p>
                </router-link>
              </li>
            </ul>
          </section>
        </template>

        <p class="text-center text-[13px] text-[#8A9A9A] mt-8">
          <router-link to="/places" class="font-bold text-[#006565]">ดูสถานที่ทั้งหมด</router-link>
          · ข้อมูลช่วงปิดอ้างอิงประกาศของอุทยาน อาจเปลี่ยนได้ตามสภาพอากาศแต่ละปี
        </p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/axios';
import { THAI_MONTHS_SHORT } from '../lib/thaiDate';

const loading = ref(true);
const months = ref([]);
const currentMonth = ref(1);
const selected = ref(1);

const active = computed(() => months.value.find(m => m.month === selected.value) || null);

const hasAnyData = computed(
  () => months.value.some(m => m.best.length > 0 || m.closed.length > 0),
);

onMounted(async () => {
  try {
    const res = await api.get('/places/seasons');
    const data = res.data?.data || {};
    months.value = data.months || [];
    currentMonth.value = data.current_month || 1;
    // เปิดมาที่เดือนปัจจุบันก่อน เพราะคำถามส่วนใหญ่คือ "เดือนนี้ไปไหนได้"
    selected.value = currentMonth.value;
  } finally {
    loading.value = false;
  }
});
</script>
