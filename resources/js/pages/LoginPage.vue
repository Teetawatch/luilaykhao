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

        <form @submit.prevent="handleLogin" class="space-y-5">
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
          <div v-if="error" class="p-4 bg-red-50 border border-red-100 rounded-xl flex items-start gap-3">
            <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
            <p class="text-red-700 text-sm">{{ error }}</p>
          </div>

          <!-- Submit Button -->
          <button type="submit" :disabled="auth.loading"
            class="w-full bg-accent text-white py-3.5 rounded-xl font-bold shadow-lg shadow-accent/20 hover:bg-accent-mid hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300 disabled:opacity-70 disabled:cursor-not-allowed disabled:transform-none disabled:shadow-none flex items-center justify-center gap-2 mt-2">
            <i v-if="auth.loading" class="fa-solid fa-circle-notch fa-spin"></i>
            <i v-else class="fa-solid fa-right-to-bracket"></i>
            {{ auth.loading ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ' }}
          </button>
        </form>

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

const form = ref({
  email: '',
  password: '',
});

async function handleLogin() {
  error.value = '';
  try {
    await auth.login(form.value);
    const redirect = route.query.redirect || '/';
    router.push(redirect);
  } catch (e) {
    error.value = e?.response?.data?.message || 'เข้าสู่ระบบล้มเหลว';
  }
}
</script>
