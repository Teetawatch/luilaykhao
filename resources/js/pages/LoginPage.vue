<template>
  <div class="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12 bg-sand/20">
    <div class="w-full max-w-md">
      <!-- Card -->
      <div class="bg-white rounded-3xl p-8 shadow-xl shadow-sand-dark/10 border border-sand-dark/30">
        <div class="text-center mb-8">
          <div class="w-20 h-20 bg-white rounded-2xl flex items-center justify-center mx-auto mb-5 rotate-3 hover:rotate-0 transition-transform shadow-sm">
            <img src="/images/logo.png" alt="TrailDive Logo" class="w-12 h-12 object-contain" />
          </div>
          <h1 class="font-anuphan text-3xl font-bold text-text-dark mb-2">เข้าสู่ระบบ</h1>
          <p class="text-text-muted">ยินดีต้อนรับกลับมา เข้าสู่บัญชีของคุณ</p>
        </div>

        <form @submit.prevent="handleLogin" :class="{ 'animate-shake-x': shake }" class="space-y-5">
          <!-- Email -->
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-text-dark flex items-center gap-2">อีเมล</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <i class="fa-regular fa-envelope text-text-muted/60"></i>
              </div>
              <input v-model="form.email" type="email" required autofocus
                class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                placeholder="email@example.com" />
            </div>
          </div>

          <!-- Password -->
          <div class="space-y-1.5">
            <div class="flex items-center justify-between">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">รหัสผ่าน</label>
              <a href="#" class="text-xs text-accent hover:text-accent-mid font-medium">ลืมรหัสผ่าน?</a>
            </div>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <i class="fa-solid fa-lock text-text-muted/60"></i>
              </div>
              <input v-model="form.password" type="password" required
                class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                placeholder="••••••••" />
            </div>
          </div>

          <!-- Error Alert -->
          <div v-if="error" class="p-4 bg-red-50 border border-red-100 rounded-2xl flex items-start gap-3 animate-fade-in shadow-sm">
            <div class="w-8 h-8 rounded-full bg-red-500/10 flex items-center justify-center shrink-0">
              <i class="fa-solid fa-circle-exclamation text-red-600 text-sm"></i>
            </div>
            <div class="flex-1">
              <p class="font-bold text-red-800 text-sm mb-0.5">เกิดข้อผิดพลาด</p>
              <p class="text-red-700/80 text-xs leading-relaxed">{{ error }}</p>
            </div>
          </div>

          <!-- Submit Button -->
          <button type="submit" :disabled="auth.loading"
            class="w-full bg-accent text-white py-3.5 rounded-xl font-bold shadow-lg shadow-accent/20 hover:bg-accent-mid hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300 disabled:opacity-70 disabled:cursor-not-allowed disabled:transform-none disabled:shadow-none flex items-center justify-center gap-2 mt-2">
            <i v-if="auth.loading" class="fa-solid fa-circle-notch fa-spin"></i>
            <i v-else class="fa-solid fa-right-to-bracket"></i>
            {{ auth.loading ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ' }}
          </button>
        </form>

        <!-- Divider -->
        <div class="relative my-8 text-center">
          <div class="absolute inset-0 flex items-center" aria-hidden="true">
            <div class="w-full border-t border-sand-dark/40"></div>
          </div>
          <span class="relative px-4 bg-white text-xs font-bold text-text-muted uppercase tracking-widest">หรือเข้าสู่ระบบด้วย</span>
        </div>

        <!-- Social Logins -->
        <div class="flex flex-col gap-3">
          <button @click="loginWithSocial('google')"
            class="w-full flex items-center justify-center gap-3 py-3 px-4 bg-white border border-sand-dark/60 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all duration-200 group shadow-sm">
            <img src="https://www.svgrepo.com/show/355037/google.svg" class="w-5 h-5 flex-shrink-0" alt="Google" />
            <span class="text-sm font-bold text-text-dark">ดำเนินการต่อด้วย Google</span>
          </button>
          
          <button @click="loginWithSocial('facebook')"
            class="w-full flex items-center justify-center gap-3 py-3 px-4 bg-[#1877F2] text-white rounded-xl hover:bg-[#0c63d4] transition-all duration-200 shadow-sm">
            <i class="fa-brands fa-facebook text-xl flex-shrink-0"></i>
            <span class="text-sm font-bold">ดำเนินการต่อด้วย Facebook</span>
          </button>
        </div>

        <!-- Register Link -->
        <div class="mt-8 text-center border-t border-sand-dark/50 pt-6">
          <p class="text-sm text-text-muted">
            ยังไม่มีบัญชี?
            <router-link to="/register" class="text-accent hover:text-accent-mid font-semibold inline-flex items-center gap-1 transition-colors">
              สมัครสมาชิก
              <i class="fa-solid fa-arrow-right text-xs ml-0.5"></i>
            </router-link>
          </p>
        </div>
      </div>

      <!-- Demo credentials -->
      <div class="mt-6 p-4 bg-white/60 backdrop-blur-sm rounded-2xl text-center text-xs text-text-muted border border-sand-dark/40 shadow-sm">
        <p class="font-semibold mb-2 text-text-dark flex items-center justify-center gap-1.5">
          <i class="fa-solid fa-flask text-accent"></i> บัญชีทดสอบ
        </p>
        <div class="space-y-1.5">
          <p class="flex justify-center gap-2">
            <span class="font-medium text-text-dark w-16 text-right">Customer:</span>
            <span class="font-mono bg-white px-2 py-0.5 rounded border border-sand-dark/30">demo@traildive.com</span>
            <span class="font-mono bg-white px-2 py-0.5 rounded border border-sand-dark/30">password</span>
          </p>
          <p class="flex justify-center gap-2">
            <span class="font-medium text-text-dark w-16 text-right">Admin:</span>
            <span class="font-mono bg-white px-2 py-0.5 rounded border border-sand-dark/30">admin@traildive.com</span>
            <span class="font-mono bg-white px-2 py-0.5 rounded border border-sand-dark/30">password</span>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const error = ref('');
const shake = ref(false);

const form = ref({
  email: '',
  password: '',
});

async function handleLogin() {
  error.value = '';
  shake.value = false;
  
  try {
    await auth.login(form.value);
    const redirect = route.query.redirect || '/';
    router.push(redirect);
  } catch (e) {
    shake.value = true;
    setTimeout(() => { shake.value = false; }, 500);

    // Handle Laravel validation error structure
    const data = e?.response?.data;
    if (data?.errors) {
      // Show first error message
      const firstKey = Object.keys(data.errors)[0];
      error.value = data.errors[firstKey][0];
    } else if (data?.message) {
      error.value = data.message === 'Unauthorized' ? 'อีเมลหรือรหัสผ่านไม่ถูกต้อง' : data.message;
    } else {
      error.value = 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ในขณะนี้';
    }
  }
}

function loginWithSocial(platform) {
  alert(`ระบบเข้าสู่ระบบด้วย ${platform.charAt(0).toUpperCase() + platform.slice(1)} จะเปิดให้บริการเร็วๆ นี้!`);
}
</script>

<style scoped>
@keyframes fade-in {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes shake-x {
  0%, 100% { transform: translateX(0); }
  10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
  20%, 40%, 60%, 80% { transform: translateX(5px); }
}

.animate-fade-in {
  animation: fade-in 0.3s ease-out forwards;
}

.animate-shake-x {
  animation: shake-x 0.5s cubic-bezier(.36,.07,.19,.97) both;
}
</style>
