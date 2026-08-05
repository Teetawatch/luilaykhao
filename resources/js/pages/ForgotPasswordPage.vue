<template>
  <div class="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12 bg-sand/20">
    <div class="w-full max-w-md">
      <div class="bg-white rounded-3xl p-8 border border-sand-dark/30">

        <div class="text-center mb-8">
          <div class="w-16 h-16 rounded-2xl bg-accent/10 flex items-center justify-center mx-auto mb-5">
            <i class="fa-solid fa-key text-accent text-2xl"></i>
          </div>
          <h1 class="font-anuphan text-3xl font-bold text-text-dark mb-2">ลืมรหัสผ่าน</h1>
          <p class="text-text-muted text-sm leading-relaxed">
            ไม่ต้องกังวลนะครับ กรอกอีเมลที่ใช้สมัคร แล้วเราจะส่งลิงก์ตั้งรหัสผ่านใหม่ไปให้
          </p>
        </div>

        <!-- Sent -->
        <div v-if="sent" class="space-y-6">
          <div class="p-5 bg-green-50 border border-green-100 rounded-2xl text-center">
            <div class="w-12 h-12 rounded-full bg-green-500/10 flex items-center justify-center mx-auto mb-3">
              <i class="fa-solid fa-envelope-circle-check text-green-600 text-xl"></i>
            </div>
            <p class="font-bold text-green-800 text-sm mb-1">ส่งลิงก์ไปแล้วครับ</p>
            <p class="text-green-700/80 text-xs leading-relaxed">{{ sentMessage }}</p>
          </div>

          <p class="text-xs text-text-muted text-center leading-relaxed">
            ไม่เห็นอีเมล? ลองดูในโฟลเดอร์อีเมลขยะ (Junk / Spam) อีกครั้งนะครับ<br />
            ลิงก์มีอายุ 60 นาที
          </p>

          <button type="button" @click="sent = false"
            class="w-full py-3 rounded-xl font-bold text-sm text-text-dark border border-sand-dark/60 hover:bg-sand/30 transition-colors">
            ส่งไปที่อีเมลอื่น
          </button>
        </div>

        <!-- Form -->
        <form v-else @submit.prevent="handleSubmit" :class="{ 'animate-shake-x': shake }" class="space-y-5">
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-text-dark">อีเมลที่ใช้สมัคร</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <i class="fa-regular fa-envelope text-text-muted/60"></i>
              </div>
              <input v-model="email" type="email" required autofocus
                class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                placeholder="luilaykhao@example.com" />
            </div>
          </div>

          <div v-if="error" class="p-4 bg-red-50 border border-red-100 rounded-2xl flex items-start gap-3">
            <div class="w-8 h-8 rounded-full bg-red-500/10 flex items-center justify-center shrink-0">
              <i class="fa-solid fa-circle-exclamation text-red-600 text-sm"></i>
            </div>
            <div class="flex-1">
              <p class="font-bold text-red-800 text-sm mb-0.5">เกิดข้อผิดพลาด</p>
              <p class="text-red-700/80 text-xs leading-relaxed">{{ error }}</p>
            </div>
          </div>

          <button type="submit" :disabled="auth.loading"
            class="w-full bg-accent text-white py-3.5 rounded-xl font-bold hover:bg-accent-mid transition-all duration-300 disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2 mt-2">
            <i v-if="auth.loading" class="fa-solid fa-circle-notch fa-spin"></i>
            <i v-else class="fa-solid fa-paper-plane"></i>
            {{ auth.loading ? 'กำลังส่ง...' : 'ส่งลิงก์ตั้งรหัสผ่านใหม่' }}
          </button>
        </form>

        <div class="mt-8 text-center border-t border-sand-dark/50 pt-6">
          <router-link to="/login" class="text-sm text-accent hover:text-accent-mid font-semibold inline-flex items-center gap-1.5">
            <i class="fa-solid fa-arrow-left text-xs"></i>
            กลับไปหน้าเข้าสู่ระบบ
          </router-link>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const email = ref('');
const error = ref('');
const shake = ref(false);
const sent = ref(false);
const sentMessage = ref('');

async function handleSubmit() {
  error.value = '';
  try {
    const res = await auth.forgotPassword(email.value);
    // Shown whether or not that address has an account — the API will not say
    // which, and neither should this screen.
    sentMessage.value = res?.message || 'ถ้าอีเมลนี้มีบัญชีอยู่ เราได้ส่งลิงก์ไปให้แล้วครับ';
    sent.value = true;
  } catch (e) {
    shake.value = true;
    setTimeout(() => { shake.value = false; }, 500);

    const data = e?.response?.data;
    if (e?.response?.status === 429) {
      error.value = 'ขออีเมลถี่เกินไปครับ รอสักครู่แล้วลองใหม่อีกครั้ง';
    } else if (data?.errors) {
      error.value = data.errors[Object.keys(data.errors)[0]][0];
    } else {
      error.value = data?.message || 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ในขณะนี้';
    }
  }
}
</script>

<style scoped>
@keyframes shake-x {
  0%, 100% { transform: translateX(0); }
  10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
  20%, 40%, 60%, 80% { transform: translateX(5px); }
}
.animate-shake-x { animation: shake-x 0.5s ease-in-out; }
</style>
