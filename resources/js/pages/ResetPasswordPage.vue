<template>
  <div class="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12 bg-sand/20">
    <div class="w-full max-w-md">
      <div class="bg-white rounded-3xl p-8 border border-sand-dark/30">

        <div class="text-center mb-8">
          <div class="w-16 h-16 rounded-2xl bg-accent/10 flex items-center justify-center mx-auto mb-5">
            <i class="fa-solid fa-lock-open text-accent text-2xl"></i>
          </div>
          <h1 class="font-anuphan text-3xl font-bold text-text-dark mb-2">ตั้งรหัสผ่านใหม่</h1>
          <p v-if="email" class="text-text-muted text-sm">สำหรับบัญชี {{ email }}</p>
        </div>

        <!-- Link is unusable: nothing to fill in, so offer the way forward instead -->
        <div v-if="!token || !email" class="space-y-6">
          <div class="p-5 bg-amber-50 border border-amber-100 rounded-2xl text-center">
            <i class="fa-solid fa-link-slash text-amber-600 text-xl mb-3"></i>
            <p class="font-bold text-amber-800 text-sm mb-1">ลิงก์ไม่สมบูรณ์</p>
            <p class="text-amber-700/80 text-xs leading-relaxed">
              ลิงก์นี้อาจถูกตัดตอนคัดลอก ลองขอลิงก์ใหม่อีกครั้งนะครับ
            </p>
          </div>
          <router-link to="/forgot-password"
            class="block w-full text-center bg-accent text-white py-3.5 rounded-xl font-bold hover:bg-accent-mid transition-colors">
            ขอลิงก์ใหม่
          </router-link>
        </div>

        <!-- Done -->
        <div v-else-if="done" class="space-y-6">
          <div class="p-5 bg-green-50 border border-green-100 rounded-2xl text-center">
            <div class="w-12 h-12 rounded-full bg-green-500/10 flex items-center justify-center mx-auto mb-3">
              <i class="fa-solid fa-circle-check text-green-600 text-xl"></i>
            </div>
            <p class="font-bold text-green-800 text-sm mb-1">เรียบร้อยแล้วครับ</p>
            <p class="text-green-700/80 text-xs leading-relaxed">
              ตั้งรหัสผ่านใหม่สำเร็จ เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย
            </p>
          </div>
          <router-link to="/login"
            class="block w-full text-center bg-accent text-white py-3.5 rounded-xl font-bold hover:bg-accent-mid transition-colors">
            ไปหน้าเข้าสู่ระบบ
          </router-link>
        </div>

        <!-- Form -->
        <form v-else @submit.prevent="handleSubmit" :class="{ 'animate-shake-x': shake }" class="space-y-5">
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-text-dark">รหัสผ่านใหม่</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <i class="fa-solid fa-lock text-text-muted/60"></i>
              </div>
              <input v-model="form.password" :type="showPassword ? 'text' : 'password'" required autofocus
                class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-12 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                placeholder="อย่างน้อย 8 ตัวอักษร" />
              <button type="button" @click="showPassword = !showPassword"
                class="absolute inset-y-0 right-0 pr-4 flex items-center text-text-muted/60 hover:text-text-muted transition-colors">
                <i :class="showPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'"></i>
              </button>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="text-sm font-medium text-text-dark">ยืนยันรหัสผ่านใหม่</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <i class="fa-solid fa-lock text-text-muted/60"></i>
              </div>
              <input v-model="form.password_confirmation" :type="showPassword ? 'text' : 'password'" required
                class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                placeholder="พิมพ์อีกครั้งให้ตรงกัน" />
            </div>
          </div>

          <div v-if="error" class="p-4 bg-red-50 border border-red-100 rounded-2xl flex items-start gap-3">
            <div class="w-8 h-8 rounded-full bg-red-500/10 flex items-center justify-center shrink-0">
              <i class="fa-solid fa-circle-exclamation text-red-600 text-sm"></i>
            </div>
            <div class="flex-1">
              <p class="font-bold text-red-800 text-sm mb-0.5">เกิดข้อผิดพลาด</p>
              <p class="text-red-700/80 text-xs leading-relaxed">{{ error }}</p>
              <router-link v-if="expired" to="/forgot-password"
                class="text-red-800 text-xs font-bold underline mt-1.5 inline-block">
                ขอลิงก์ใหม่
              </router-link>
            </div>
          </div>

          <div class="p-3.5 bg-sand/40 rounded-xl">
            <p class="text-xs text-text-muted leading-relaxed">
              <i class="fa-solid fa-shield-halved text-text-muted/70 mr-1"></i>
              เมื่อตั้งรหัสผ่านใหม่แล้ว อุปกรณ์อื่นที่ค้างล็อกอินอยู่จะถูกออกจากระบบทั้งหมดครับ
            </p>
          </div>

          <button type="submit" :disabled="auth.loading"
            class="w-full bg-accent text-white py-3.5 rounded-xl font-bold hover:bg-accent-mid transition-all duration-300 disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2">
            <i v-if="auth.loading" class="fa-solid fa-circle-notch fa-spin"></i>
            <i v-else class="fa-solid fa-check"></i>
            {{ auth.loading ? 'กำลังบันทึก...' : 'บันทึกรหัสผ่านใหม่' }}
          </button>
        </form>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const route = useRoute();

// Both arrive on the query string of the link we mailed. The token is only ever
// posted straight back to the API — never stored, never logged.
const token = ref(route.query.token || '');
const email = ref(route.query.email || '');

const form = ref({ password: '', password_confirmation: '' });
const showPassword = ref(false);
const error = ref('');
const expired = ref(false);
const shake = ref(false);
const done = ref(false);

async function handleSubmit() {
  error.value = '';
  expired.value = false;

  if (form.value.password !== form.value.password_confirmation) {
    error.value = 'รหัสผ่านทั้งสองช่องไม่ตรงกันครับ';
    return;
  }

  try {
    await auth.resetPassword({
      token: token.value,
      email: email.value,
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
    });
    done.value = true;
  } catch (e) {
    shake.value = true;
    setTimeout(() => { shake.value = false; }, 500);

    const data = e?.response?.data;
    if (data?.errors) {
      error.value = data.errors[Object.keys(data.errors)[0]][0];
    } else {
      error.value = data?.message || 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ในขณะนี้';
      // A spent or aged-out token is the common failure here, and the only fix
      // is a fresh link — so surface that shortcut rather than leaving the
      // customer to find it.
      expired.value = e?.response?.status === 422;
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
