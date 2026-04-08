<template>
  <div class="min-h-[calc(100vh-4rem)] flex bg-white">
    <!-- Image Side -->
    <div class="hidden lg:flex lg:w-1/2 relative bg-sand-dark overflow-hidden">
      <div class="absolute inset-0 bg-black/40 z-10"></div>
      <img src="/images/about.jpg" alt="Adventure" class="absolute inset-0 w-full h-full object-cover" />
      <div class="relative z-20 flex flex-col justify-center px-16 text-white h-full">
        <h1 class="font-anuphan text-5xl font-bold mb-6 leading-tight">
          เริ่มต้นการเดินทาง<br />ครั้งใหม่ไปกับเรา
        </h1>
        <p class="text-lg text-white/90 max-w-md leading-relaxed mb-12">
          เข้าร่วมกับ ลุยเลเขา เพื่อค้นพบเส้นทางธรรมชาติที่สวยงาม และประสบการณ์การผจญภัยที่คุณจะไม่มีวันลืม
        </p>
        
        <div class="flex items-center space-x-4 text-sm font-medium">
          <div class="flex -space-x-3">
            <div class="w-10 h-10 rounded-full bg-accent flex items-center justify-center border-2 border-transparent text-white z-30 shadow-md">
              <i class="fa-solid fa-user"></i>
            </div>
            <div class="w-10 h-10 rounded-full bg-accent-mid flex items-center justify-center border-2 border-white text-white z-20 shadow-md">
              <i class="fa-solid fa-star"></i>
            </div>
            <div class="w-10 h-10 rounded-full bg-sand-dark flex items-center justify-center border-2 border-white text-text-dark z-10 shadow-md">
              <span class="text-xs">+1k</span>
            </div>
          </div>
          <span class="text-white/90">นักเดินทางกว่า 1,000+ คนไว้วางใจเรา</span>
        </div>
      </div>
    </div>

    <!-- Form Side -->
    <div class="w-full lg:w-1/2 flex items-center justify-center px-6 py-12 lg:px-16 bg-sand/20">
      <div class="w-full max-w-md">
        <div class="text-center lg:text-left mb-10">
          <div class="lg:hidden w-16 h-16 bg-accent/10 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-mountain-sun text-accent text-2xl"></i>
          </div>
          <h2 class="font-anuphan text-3xl md:text-4xl font-bold text-text-dark mb-3">สร้างบัญชีใหม่</h2>
          <p class="text-text-muted">กรอกข้อมูลด้านล่างเพื่อเริ่มต้นใช้งาน ลุยเลเขา</p>
        </div>

        <form @submit.prevent="handleRegister" class="space-y-5">
          <!-- Title & Name & Nickname -->
          <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12 md:col-span-3 space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                คำนำหน้า <span class="text-red-500">*</span>
              </label>
              <select v-model="form.title" required
                class="w-full bg-white border border-sand-dark/60 rounded-xl px-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none">
                <option value="" disabled>เลือก...</option>
                <option value="นาย">นาย</option>
                <option value="นาง">นาง</option>
                <option value="นางสาว">นางสาว</option>
              </select>
            </div>
            <div class="col-span-12 md:col-span-9 space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                ชื่อ-นามสกุล <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-regular fa-user text-text-muted/60"></i>
                </div>
                <input v-model="form.name" type="text" required
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  placeholder="สมชาย ใจดี" />
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                ชื่อเล่น
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-tag text-text-muted/60"></i>
                </div>
                <input v-model="form.nickname" type="text"
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  placeholder="น้องเอ" />
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                กรุ๊ปเลือด
              </label>
              <select v-model="form.blood_group"
                class="w-full bg-white border border-sand-dark/60 rounded-xl px-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none">
                <option value="">ไม่ระบุ</option>
                <option value="A">A</option>
                <option value="B">B</option>
                <option value="AB">AB</option>
                <option value="O">O</option>
              </select>
            </div>
          </div>

          <!-- Email & Phone Row -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                อีเมล
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-regular fa-envelope text-text-muted/60"></i>
                </div>
                <input v-model="form.email" type="email" required
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  placeholder="email@example.com" />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                เบอร์โทรศัพท์
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-mobile-screen-button text-text-muted/60"></i>
                </div>
                <input v-model="form.phone" type="tel"
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  placeholder="08x-xxx-xxxx" />
              </div>
            </div>
          </div>

          <!-- Password Row -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                รหัสผ่าน
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-lock text-text-muted/60"></i>
                </div>
                <input v-model="form.password" type="password" required
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  placeholder="อย่างน้อย 8 ตัวอักษร" />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                ยืนยันรหัสผ่าน
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-check text-text-muted/60"></i>
                </div>
                <input v-model="form.password_confirmation" type="password" required
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  placeholder="ยืนยันรหัสผ่านอีกครั้ง" />
              </div>
            </div>
          </div>

          <!-- Terms -->
          <div class="flex items-start gap-3 pt-2">
            <div class="flex items-center h-5">
              <input id="terms" type="checkbox" required
                class="w-4 h-4 text-accent border-sand-dark rounded focus:ring-accent focus:ring-2" />
            </div>
            <label for="terms" class="text-sm text-text-muted leading-relaxed">
              ฉันยอมรับ <router-link to="/terms" class="text-accent hover:underline font-medium">ข้อกำหนดการใช้งาน</router-link> และ <router-link to="/privacy" class="text-accent hover:underline font-medium">นโยบายความเป็นส่วนตัว</router-link> ของ ลุยเลเขา
            </label>
          </div>

          <!-- Error Alert -->
          <div v-if="error" class="p-4 bg-red-50 border border-red-100 rounded-xl flex items-start gap-3">
            <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
            <p class="text-red-700 text-sm">{{ error }}</p>
          </div>

          <!-- Submit Button -->
          <button type="submit" :disabled="auth.loading"
            class="w-full bg-accent text-white py-3.5 rounded-xl font-bold shadow-lg shadow-accent/20 hover:bg-accent-mid hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300 disabled:opacity-70 disabled:cursor-not-allowed disabled:transform-none disabled:shadow-none flex items-center justify-center gap-2 mt-4">
            <i v-if="auth.loading" class="fa-solid fa-circle-notch fa-spin"></i>
            <i v-else class="fa-solid fa-user-plus"></i>
            {{ auth.loading ? 'กำลังสร้างบัญชี...' : 'สมัครสมาชิก' }}
          </button>

          <!-- Divider -->
          <div class="relative flex items-center gap-3 my-1">
            <div class="flex-1 h-px bg-sand-dark/40"></div>
            <span class="text-xs text-text-muted/60 font-medium">หรือ</span>
            <div class="flex-1 h-px bg-sand-dark/40"></div>
          </div>

          <!-- Social Buttons -->
          <button type="button" @click="loginWithGoogle"
            class="w-full bg-white border border-sand-dark/60 text-text-dark py-3 rounded-xl font-medium text-sm hover:bg-sand/30 hover:border-sand-dark transition-all duration-200 flex items-center justify-center gap-3 shadow-sm">
            <svg class="w-5 h-5 shrink-0" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M47.532 24.552c0-1.636-.132-3.2-.396-4.704H24.48v8.896h12.984c-.56 3.024-2.252 5.588-4.8 7.308v6.076h7.776c4.552-4.196 7.092-10.38 7.092-17.576z" fill="#4285F4"/>
              <path d="M24.48 48c6.516 0 11.984-2.16 15.98-5.872l-7.776-6.076c-2.16 1.448-4.916 2.3-8.204 2.3-6.308 0-11.648-4.26-13.556-9.988H2.892v6.268C6.872 42.66 15.1 48 24.48 48z" fill="#34A853"/>
              <path d="M10.924 28.364A14.54 14.54 0 0 1 10.16 24c0-1.516.26-2.988.764-4.364v-6.268H2.892A23.956 23.956 0 0 0 .48 24c0 3.872.928 7.532 2.412 10.632l8.032-6.268z" fill="#FBBC05"/>
              <path d="M24.48 9.648c3.552 0 6.74 1.22 9.248 3.624l6.932-6.932C36.46 2.412 30.996 0 24.48 0 15.1 0 6.872 5.34 2.892 13.368l8.032 6.268C12.832 13.908 18.172 9.648 24.48 9.648z" fill="#EA4335"/>
            </svg>
            เข้าสู่ระบบด้วย Google
          </button>

          <button type="button" @click="loginWithFacebook"
            class="w-full bg-[#1877F2] text-white py-3 rounded-xl font-medium text-sm hover:bg-[#166fe5] transition-all duration-200 flex items-center justify-center gap-3 shadow-sm">
            <i class="fa-brands fa-facebook text-lg"></i>
            เข้าสู่ระบบด้วย Facebook
          </button>
        </form>

        <!-- Login Link -->
        <div class="mt-8 text-center lg:text-left border-t border-sand-dark/50 pt-6">
          <p class="text-sm text-text-muted">
            มีบัญชีอยู่แล้ว?
            <router-link to="/login" class="text-accent hover:text-accent-mid font-semibold inline-flex items-center gap-1 transition-colors">
              เข้าสู่ระบบเลย
              <i class="fa-solid fa-arrow-right-to-bracket text-xs ml-1"></i>
            </router-link>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const error = ref('');

const form = ref({
  title: '',
  name: '',
  nickname: '',
  blood_group: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
});

async function handleRegister() {
  error.value = '';
  try {
    await auth.register(form.value);
    router.push('/');
  } catch (e) {
    const errors = e?.response?.data?.errors;
    if (errors) {
      error.value = Object.values(errors).flat().join(', ');
    } else {
      error.value = e?.response?.data?.message || 'สมัครสมาชิกล้มเหลว';
    }
  }
}

function loginWithGoogle() {
  window.location.href = '/auth/google/redirect';
}

function loginWithFacebook() {
  window.location.href = '/auth/facebook/redirect';
}
</script>
