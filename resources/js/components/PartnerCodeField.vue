<template>
  <div class="partner-field">
    <div v-if="partner" class="flex items-center justify-between gap-3 rounded-xl border border-violet-100 bg-violet-50 px-4 py-3">
      <div class="flex min-w-0 items-center gap-2 text-sm">
        <span class="material-symbols-rounded text-[18px] text-violet-600">handshake</span>
        <span class="truncate font-bold text-violet-900">แนะนำโดย {{ partner.display_name }}</span>
      </div>
      <button type="button" class="shrink-0 text-xs font-bold text-gray-400 hover:text-red-500" @click="remove">เอาออก</button>
    </div>

    <button v-else-if="!open" type="button" class="text-xs font-bold text-gray-500 underline-offset-2 hover:text-teal-700 hover:underline" @click="open = true">
      มีรหัสผู้แนะนำ/พาร์ทเนอร์?
    </button>

    <div v-else class="space-y-1">
      <div class="flex gap-2">
        <input v-model="input" type="text" maxlength="20" placeholder="รหัสผู้แนะนำ"
          class="min-w-0 flex-1 rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm font-bold uppercase text-gray-900 outline-none focus:border-teal-600"
          @keyup.enter="check" />
        <button type="button" :disabled="loading || !input.trim()" class="shrink-0 rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" @click="check">
          ตรวจสอบ
        </button>
      </div>
      <p v-if="error" class="text-xs font-bold text-red-500">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../lib/axios';
import { forgetPartnerCode, rememberPartnerCode, storedPartnerRef } from '../lib/partnerRef';

// พาร์ทเนอร์ช่วยขาย — โค้ดจากลิงก์ที่จำไว้ หรือให้ลูกค้าพิมพ์เอง ส่วนลดไม่มี
// มีไว้ให้คอมไปถึงคนที่แนะนำ หน้าจองอ่านโค้ดจาก storedPartnerRef() ตอนกดจอง

const partner = ref(null);
const open = ref(false);
const input = ref('');
const loading = ref(false);
const error = ref('');

async function lookup(code) {
  const res = await api.get('/partners/check', { params: { code } });
  return res.data?.data?.valid ? res.data.data : null;
}

async function check() {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const found = await lookup(input.value.trim());
    if (!found) {
      error.value = 'ไม่พบรหัสนี้ ลองตรวจตัวสะกดอีกครั้ง';
      return;
    }
    rememberPartnerCode(found.code, 'code');
    partner.value = found;
  } catch {
    error.value = 'ตรวจสอบรหัสไม่สำเร็จ ลองใหม่อีกครั้ง';
  } finally {
    loading.value = false;
  }
}

function remove() {
  forgetPartnerCode();
  partner.value = null;
  input.value = '';
}

onMounted(async () => {
  const stored = storedPartnerRef();
  if (!stored) return;
  try {
    partner.value = await lookup(stored.code);
    // โค้ดที่หยุดใช้ไปแล้ว ไม่ต้องจำต่อ
    if (!partner.value) forgetPartnerCode();
  } catch {
    // เน็ตหลุด — ยังส่งโค้ดไปกับการจองตามเดิม ให้เซิร์ฟเวอร์ตัดสิน
  }
});
</script>
