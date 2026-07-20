<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32 font-anuphan">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">

      <!-- Header -->
      <section class="mb-6">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2" style="font-family:'DB Heavent','Anuphan',sans-serif;">
          ชวนเพื่อน รับแต้ม
        </h1>
        <p class="text-[#505E5E] text-sm">แชร์โค้ดให้เพื่อน เมื่อเพื่อนจองทริปแรกสำเร็จ รับแต้มทั้งคู่</p>
      </section>

      <div v-if="loading" class="flex justify-center py-24">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <template v-else-if="data">
        <!-- Referral code card -->
        <div class="bg-gradient-to-br from-[#006565] to-[#00A3A3] rounded-[24px] p-7 text-white mb-5 relative overflow-hidden">
          <div class="absolute -right-8 -top-8 w-32 h-32 rounded-full bg-white/10"></div>
          <div class="absolute -right-2 bottom-2 w-20 h-20 rounded-full bg-white/5"></div>
          <p class="text-white/80 text-xs font-bold uppercase tracking-wider mb-2">โค้ดชวนเพื่อนของคุณ</p>
          <div class="flex items-center gap-3 mb-5">
            <span class="text-3xl font-black tracking-widest">{{ data.code }}</span>
            <button
              @click="copy(data.code, 'code')"
              class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition">
              <span class="material-symbols-rounded text-[18px]">{{ copied === 'code' ? 'check' : 'content_copy' }}</span>
            </button>
          </div>
          <div class="flex gap-2.5 flex-wrap">
            <button
              @click="share"
              class="flex-1 min-w-[130px] bg-white text-[#006565] py-2.5 px-4 rounded-full font-extrabold text-sm hover:bg-white/90 transition flex items-center justify-center gap-1.5">
              <span class="material-symbols-rounded text-[18px]">share</span> แชร์ลิงก์ชวนเพื่อน
            </button>
            <button
              @click="copy(data.share_url, 'url')"
              class="bg-white/20 hover:bg-white/30 text-white py-2.5 px-4 rounded-full font-bold text-sm transition flex items-center justify-center gap-1.5">
              <span class="material-symbols-rounded text-[18px]">{{ copied === 'url' ? 'check' : 'link' }}</span> คัดลอกลิงก์
            </button>
          </div>
        </div>

        <!-- Reward explainer -->
        <div class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 mb-5">
          <div class="flex items-center gap-4">
            <div class="flex-1 text-center">
              <p class="text-2xl font-black text-[#006565]">+{{ data.referrer_points }}</p>
              <p class="text-xs font-bold text-[#889696] mt-0.5">แต้มสำหรับคุณ</p>
            </div>
            <div class="w-px h-10 bg-[#E8EEEF]"></div>
            <div class="flex-1 text-center">
              <p class="text-2xl font-black text-[#00A3A3]">+{{ data.referee_points }}</p>
              <p class="text-xs font-bold text-[#889696] mt-0.5">แต้มสำหรับเพื่อน</p>
            </div>
          </div>
          <p class="text-[11px] text-[#889696] text-center mt-3 leading-relaxed">
            เพื่อนกรอกโค้ดตอนสมัคร และรับแต้มเมื่อจ่ายเงินทริปแรกสำเร็จ
          </p>
        </div>

        <!-- Summary stats -->
        <div class="grid grid-cols-3 gap-3 mb-5">
          <div class="bg-white rounded-[16px] border border-[#E8EEEF] p-4 text-center">
            <p class="text-xl font-black text-[#1a1c1c]">{{ data.summary.invited }}</p>
            <p class="text-[11px] font-bold text-[#889696] mt-0.5">ชวนแล้ว</p>
          </div>
          <div class="bg-white rounded-[16px] border border-[#E8EEEF] p-4 text-center">
            <p class="text-xl font-black text-green-600">{{ data.summary.rewarded }}</p>
            <p class="text-[11px] font-bold text-[#889696] mt-0.5">สำเร็จ</p>
          </div>
          <div class="bg-white rounded-[16px] border border-[#E8EEEF] p-4 text-center">
            <p class="text-xl font-black text-[#006565]">{{ data.summary.points_earned }}</p>
            <p class="text-[11px] font-bold text-[#889696] mt-0.5">แต้มที่ได้</p>
          </div>
        </div>

        <!-- Friends list -->
        <div v-if="data.friends.length > 0" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5">
          <h2 class="text-sm font-black text-[#1a1c1c] mb-3">เพื่อนที่คุณชวน</h2>
          <div class="space-y-2.5">
            <div v-for="(f, i) in data.friends" :key="i" class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-full bg-[#F4F7F6] flex items-center justify-center text-[#006565] font-black text-sm shrink-0">
                {{ (f.name || '?').charAt(0) }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-[#1a1c1c] truncate">{{ f.name }}</p>
                <p class="text-[11px] font-bold text-[#889696]">{{ formatDate(f.joined_at) }}</p>
              </div>
              <span
                class="text-[11px] font-black px-2.5 py-1 rounded-full"
                :class="f.status === 'rewarded' ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600'">
                {{ f.status === 'rewarded' ? `+${f.points} แต้ม` : 'รอจองทริปแรก' }}
              </span>
            </div>
          </div>
        </div>
        <div v-else class="bg-white rounded-[20px] border border-dashed border-[#E8EEEF] p-8 text-center">
          <span class="material-symbols-rounded text-4xl text-[#A0B0B0] mb-2">group_add</span>
          <p class="text-sm font-bold text-[#505E5E]">ยังไม่มีเพื่อนที่ชวน — แชร์โค้ดด้านบนได้เลย!</p>
        </div>
      </template>

      <!-- Copy toast -->
      <transition name="fade">
        <div v-if="toast" class="fixed bottom-8 left-1/2 -translate-x-1/2 bg-[#1a1c1c] text-white text-sm font-bold px-5 py-2.5 rounded-full z-50">
          {{ toast }}
        </div>
      </transition>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../lib/axios';
import { useHead } from '@unhead/vue';

useHead({ title: 'ชวนเพื่อน รับแต้ม — ลุยเลเขา' });

const data = ref(null);
const loading = ref(true);
const copied = ref(null);
const toast = ref('');

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function showToast(msg) {
  toast.value = msg;
  setTimeout(() => { toast.value = ''; }, 1800);
}

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(text);
    copied.value = key;
    showToast('คัดลอกแล้ว');
    setTimeout(() => { if (copied.value === key) copied.value = null; }, 1500);
  } catch {
    showToast(text);
  }
}

async function share() {
  const payload = {
    title: 'ลุยเลเขา',
    text: data.value?.share_message || 'มาลุยทริปกับลุยเลเขากัน!',
    url: data.value?.share_url,
  };
  if (navigator.share) {
    try { await navigator.share(payload); return; } catch { /* ผู้ใช้ยกเลิก */ }
  }
  copy(data.value?.share_url, 'url');
}

onMounted(async () => {
  try {
    const res = await api.get('/referral');
    data.value = res.data.data;
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
