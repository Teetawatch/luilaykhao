<template>
  <!--
    ชวนเข้าห้องแชท ไม่ใช่ชวนโหลดแอป

    ไม่มีใครอยากได้ไอคอนเพิ่มในเครื่อง เขาอยากได้ของข้างใน — และของที่เว็บให้ไม่ได้
    จริง ๆ คือห้องแชทของรอบ QR เช็คอิน และการติดตามรถ คำชวนจึงเริ่มจากตรงนั้น

    ซ่อนตัวเองเมื่อลูกค้าเปิดแอปมาแล้ว (has_app จาก /auth/me) — เห็นของที่ตัวเอง
    ถืออยู่ถูกขายซ้ำทุกครั้งที่จอง ทำให้คำชวนครั้งที่สำคัญจริงหมดน้ำหนัก
  -->
  <div v-if="show" class="bg-white border-2 border-teal-100 rounded-[1.75rem] p-6">
    <div class="flex items-start gap-4">
      <span class="w-12 h-12 shrink-0 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center">
        <span class="material-symbols-rounded text-[26px]">forum</span>
      </span>
      <div class="min-w-0">
        <h3 class="text-gray-900 font-black text-lg leading-snug">เพื่อนร่วมทริปรอบนี้คุยกันอยู่ในแอป</h3>
        <p class="text-gray-500 font-bold text-sm leading-relaxed mt-1.5">
          ทุกรอบเดินทางมีห้องแชทของตัวเอง ไว้ถามทีมงานและนัดแนะกับเพื่อนร่วมทาง
        </p>
      </div>
    </div>

    <ul class="mt-5 flex flex-col gap-2.5">
      <li v-for="item in features" :key="item.icon" class="flex items-center gap-3 text-sm font-bold text-gray-600">
        <span class="material-symbols-rounded text-[20px] text-teal-500">{{ item.icon }}</span>
        <span>{{ item.text }}</span>
      </li>
    </ul>

    <div class="mt-6 flex flex-col sm:flex-row gap-3">
      <a :href="appStore" target="_blank" rel="noopener"
        class="flex-1 flex items-center justify-center gap-2 py-3.5 bg-teal-600 text-white rounded-2xl font-black text-sm hover:bg-teal-700 active:scale-95 transition-all">
        App Store
      </a>
      <a :href="playStore" target="_blank" rel="noopener"
        class="flex-1 flex items-center justify-center gap-2 py-3.5 bg-teal-600 text-white rounded-2xl font-black text-sm hover:bg-teal-700 active:scale-95 transition-all">
        Google Play
      </a>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';
import { appStoreUrl, playStoreUrl } from '../lib/appLinks';

const auth = useAuthStore();

const appStore = appStoreUrl();
const playStore = playStoreUrl();

const features = [
  { icon: 'badge', text: 'ชื่อและเบอร์ทีมงาน กดโทรได้เลย' },
  { icon: 'qr_code_2', text: 'QR เช็คอิน ให้ทีมงานสแกนหน้างาน' },
  { icon: 'near_me', text: 'ติดตามรถแบบเรียลไทม์วันเดินทาง' },
];

/**
 * has_app มาจาก /auth/me — undefined ได้เมื่อ auth_user ใน localStorage ถูกเก็บไว้
 * ตั้งแต่ก่อนมีฟิลด์นี้ ถือว่า "ยังไม่มีแอป" ไว้ก่อน เพราะการชวนคนที่มีแล้วหนึ่งครั้ง
 * เสียหายน้อยกว่าการไม่ชวนคนที่ยังไม่มีเลย และค่าจะถูกต้องเองในการโหลดหน้าถัดไป
 */
const show = computed(() => auth.user?.has_app !== true);
</script>
