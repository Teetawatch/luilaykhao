<template>
  <!-- แถบแคมเปญวันพิเศษ (9.9 / 10.10) — ขึ้นเฉพาะตอนแคมเปญเปิดจริง และหายเอง
       เมื่อหมดเวลา ไม่มีปุ่มปิด เพราะมันไม่ได้ขวางอะไร และราคาที่ลูกค้าเห็น
       ทั้งเว็บก็เป็นราคาที่แถบนี้อธิบายอยู่ -->
  <div v-if="campaign" class="w-full text-white" :style="{ backgroundColor: campaign.theme_color || '#e11d48' }">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-center gap-3 flex-wrap">
      <span v-if="campaign.badge_label"
        class="px-2.5 py-1 rounded-lg bg-white/20 text-xs font-black tracking-wide">
        {{ campaign.badge_label }}
      </span>
      <p class="text-sm font-bold">
        {{ campaign.tagline || `${campaign.discount_label} ทุกทริปทั้งเว็บ` }}
      </p>
      <span class="text-xs font-bold opacity-90">เหลืออีก</span>
      <span class="px-2.5 py-1 rounded-lg bg-black/20 text-sm font-black tabular-nums">{{ remaining }}</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import api from '../lib/axios';

const campaign = ref(null);
const now = ref(Date.now());
let ticker = null;

const remaining = computed(() => {
  if (!campaign.value?.ends_at) return '';
  const diff = new Date(campaign.value.ends_at).getTime() - now.value;
  if (diff <= 0) return 'หมดเวลา';
  const s = Math.floor(diff / 1000);
  const pad = (n) => String(n).padStart(2, '0');
  const days = Math.floor(s / 86400);
  const clock = `${pad(Math.floor((s % 86400) / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
  return days > 0 ? `${days} วัน ${clock}` : clock;
});

onMounted(async () => {
  try {
    const res = await api.get('/sale-campaign/active');
    campaign.value = res.data?.data || null;
  } catch (e) {
    // แคมเปญเป็นของแถม ไม่ใช่เนื้อหาหลัก — โหลดไม่ได้ก็แค่ไม่ขึ้นแถบ
    campaign.value = null;
  }

  ticker = setInterval(() => {
    now.value = Date.now();
    // แคมเปญจบระหว่างที่ลูกค้าเปิดหน้าค้างไว้ — เก็บแถบเอง ไม่ต้องรอรีเฟรช
    if (campaign.value?.ends_at && new Date(campaign.value.ends_at).getTime() <= now.value) {
      campaign.value = null;
    }
  }, 1000);
});

onUnmounted(() => clearInterval(ticker));
</script>
