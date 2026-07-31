<template>
  <!--
    "ช่วยกันเปิดรอบ" — รอบที่ยังจองไม่ถึงขั้นต่ำและใกล้ถึงวันเดินทาง
    ชวนคนที่จองแล้วช่วยหาเพื่อนมาเติม เพราะเขาคือคนที่อยากให้รอบออกมากที่สุด
    ซ่อนตัวเองเงียบ ๆ เมื่อรอบครบแล้ว / ยังไกล / เต็มแล้ว / ไม่มีสิทธิ์ดู
  -->
  <section
    v-if="data?.active"
    class="bg-amber-50/60 rounded-[2rem] border border-amber-200 p-7 md:p-8"
  >
    <div class="flex items-start justify-between gap-4 mb-4">
      <div class="flex items-center gap-3">
        <span class="w-11 h-11 rounded-full bg-white border border-amber-200 flex items-center justify-center shrink-0">
          <span class="material-symbols-rounded text-amber-600">groups</span>
        </span>
        <h2 class="text-lg font-bold text-[#1a1c1c]">ช่วยกันเปิดรอบนี้</h2>
      </div>
      <span
        v-if="data.days_left > 0"
        class="shrink-0 px-3 py-1 rounded-full bg-white border border-amber-200 text-xs font-bold text-amber-700"
      >เหลือ {{ data.days_left }} วัน</span>
    </div>

    <p class="text-[14px] text-[#505E5E] leading-relaxed mb-5">{{ data.headline }}</p>

    <div class="mb-5">
      <div class="h-2 rounded-full bg-white overflow-hidden border border-amber-100">
        <div class="h-full bg-amber-500 transition-all duration-500" :style="{ width: percent + '%' }"></div>
      </div>
      <div class="flex justify-between mt-2 text-xs font-bold text-amber-700">
        <span>จองแล้ว {{ data.booked_seats }} ท่าน</span>
        <span>ออกเดินทางที่ {{ data.guarantee_min_seats }} ท่าน</span>
      </div>
    </div>

    <div class="flex flex-col sm:flex-row gap-2.5">
      <button
        class="flex-1 bg-amber-600 text-white py-3 px-5 rounded-[14px] font-bold text-sm hover:bg-amber-700 transition-all flex items-center justify-center gap-2"
        @click="share"
      >
        <span class="material-symbols-rounded text-[19px]">ios_share</span>
        {{ data.seats_needed === 1 ? 'ชวนเพื่อนอีก 1 คน' : `ชวนเพื่อนอีก ${data.seats_needed} คน` }}
      </button>
      <button
        class="sm:w-auto bg-white text-amber-700 border border-amber-200 py-3 px-5 rounded-[14px] font-bold text-sm hover:bg-amber-50 transition-all flex items-center justify-center gap-2"
        @click="copyLink"
      >
        <span class="material-symbols-rounded text-[19px]">{{ copied ? 'check' : 'link' }}</span>
        {{ copied ? 'คัดลอกแล้ว' : 'คัดลอกลิงก์' }}
      </button>
    </div>

    <p class="mt-4 text-[12.5px] text-amber-700/80 leading-relaxed">
      ถ้าเพื่อนของคุณเป็นลูกค้าใหม่ ทั้งคุณและเพื่อนจะได้แต้มสะสมตามโปรแกรมแนะนำเพื่อน
      และหากรอบนี้ไม่ได้ออกเดินทาง เราคืนเงินเต็มจำนวนครับ
    </p>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/axios';

const props = defineProps({
  scheduleId: { type: [Number, String], required: true },
});

const data = ref(null);
const copied = ref(false);

const percent = computed(() => {
  const target = data.value?.guarantee_min_seats || 0;
  if (!target) return 0;
  return Math.min(100, Math.round((data.value.booked_seats / target) * 100));
});

async function load() {
  try {
    const res = await api.get(`/schedules/${props.scheduleId}/rally`);
    data.value = res.data.data;
  } catch {
    // 403 (ไม่ได้จองรอบนี้) / ออฟไลน์ = ซ่อนไปเงียบ ๆ ไม่ใช่เรื่องที่ต้องแจ้งผู้ใช้
    data.value = null;
  }
}

async function share() {
  const text = data.value?.share_message || data.value?.share_url || '';
  if (!text) return;

  if (navigator.share) {
    try {
      await navigator.share({ text, title: 'ชวนเพื่อนมาเที่ยวด้วยกัน' });
      return;
    } catch {
      // ผู้ใช้กดยกเลิก share sheet — ตกไปใช้การคัดลอกแทน
    }
  }

  await copyText(text);
}

async function copyLink() {
  await copyText(data.value?.share_url || '');
}

async function copyText(text) {
  if (!text) return;
  try {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch {
    // เบราว์เซอร์ไม่อนุญาต clipboard — ผู้ใช้ยังกดปุ่มแชร์ได้
  }
}

onMounted(load);
</script>
