<template>
  <div class="min-h-screen flex flex-col lg:flex-row bg-white">
    <!-- Image Side -->
    <div class="w-full lg:w-1/2 relative bg-sand-dark h-64 sm:h-80 md:h-96 lg:h-auto overflow-hidden">
      <div class="absolute inset-0 bg-black/40 z-10"></div>
      <!-- ภาพของหน้าไทยมีตัวหนังสือไทยฝังอยู่ในภาพ หน้าอังกฤษใช้ภาพที่ไม่มีตัวหนังสือ -->
      <img :src="$isEnglish ? '/images/hiking.webp' : '/images/register_page.png'" :alt="$t('เริ่มต้นการเดินทาง')" class="absolute inset-0 w-full h-full object-cover" />
      <div class="relative z-20 flex flex-col justify-center px-6 sm:px-10 md:px-16 text-white h-full">
        <h1 class="font-anuphan text-2xl sm:text-3xl md:text-5xl font-bold mb-3 md:mb-6 leading-tight">
          {{ $t('เริ่มต้นการเดินทาง') }}<br />{{ $t('ครั้งใหม่ไปกับเรา') }}
        </h1>
        <p class="hidden sm:block text-sm md:text-lg text-white/90 max-w-md leading-relaxed mb-6 md:mb-12">
          {{ $t('เข้าร่วมกับ ลุยเลเขา เพื่อค้นพบเส้นทางธรรมชาติที่สวยงาม และประสบการณ์การผจญภัยที่คุณจะไม่มีวันลืม') }}
        </p>
        
        <div class="hidden sm:flex items-center space-x-4 text-xs md:text-sm font-medium">
          <div class="flex -space-x-3">
            <div class="w-8 h-8 md:w-10 md:h-10 rounded-full bg-accent flex items-center justify-center border-2 border-transparent text-white z-30">
              <i class="fa-solid fa-user"></i>
            </div>
            <div class="w-8 h-8 md:w-10 md:h-10 rounded-full bg-accent-mid flex items-center justify-center border-2 border-white text-white z-20">
              <i class="fa-solid fa-star"></i>
            </div>
            <div class="w-8 h-8 md:w-10 md:h-10 rounded-full bg-sand-dark flex items-center justify-center border-2 border-white text-text-dark z-10">
              <span class="text-[10px] md:text-xs">+1k</span>
            </div>
          </div>
          <span class="text-white/90">{{ $t('นักเดินทางกว่า 1,000+ คนไว้วางใจเรา') }}</span>
        </div>
      </div>
    </div>

    <!-- Form Side -->
    <div class="w-full lg:w-1/2 flex items-center justify-center px-6 py-10 md:py-16 lg:px-16 bg-sand/5">
      <div class="w-full max-w-xl">
        <div class="text-center lg:text-left mb-10">
          <div class="lg:hidden w-16 h-16 bg-accent/10 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-mountain-sun text-accent text-2xl"></i>
          </div>
          <h2 class="font-anuphan text-3xl md:text-4xl font-bold text-text-dark mb-3">{{ $t('สร้างบัญชีใหม่') }}</h2>
          <p class="text-text-muted">{{ $t('กรอกข้อมูลด้านล่างเพื่อเริ่มต้นใช้งาน ลุยเลเขา') }}</p>
        </div>

        <form @submit.prevent="handleRegister" class="space-y-5">
          <!-- Title & Name & Nickname -->
          <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12 md:col-span-3 space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('คำนำหน้า') }} <span class="text-red-500">*</span>
              </label>
              <select v-model="form.title" required
                class="w-full bg-white border border-sand-dark/60 rounded-xl px-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none">
                <option value="" disabled>{{ $t('เลือก...') }}</option>
                <option value="นาย">{{ $t('นาย') }}</option>
                <option value="นาง">{{ $t('นาง') }}</option>
                <option value="นางสาว">{{ $t('นางสาว') }}</option>
              </select>
            </div>
            <div class="col-span-12 md:col-span-9 space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('ชื่อ-นามสกุล') }} <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-regular fa-user text-text-muted/60"></i>
                </div>
                <input v-model="form.name" type="text" required
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  :placeholder="$t('สมชาย ใจดี')" />
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('ชื่อเล่น') }}
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-tag text-text-muted/60"></i>
                </div>
                <input v-model="form.nickname" type="text"
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  :placeholder="$t('น้องเอ')" />
              </div>
            </div>
            <!-- วันเกิดถูกใช้ต่อในขั้นตอนจอง (ประกันการเดินทาง/ออกตั๋ว)
                 กรอกที่นี่ครั้งเดียวแล้วระบบเติมให้เองตอนจอง -->
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('วัน/เดือน/ปีเกิด') }}
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-cake-candles text-text-muted/60"></i>
                </div>
                <input v-model="form.birth_date" type="date" :max="today"
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none" />
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('กรุ๊ปเลือด') }}
              </label>
              <select v-model="form.blood_group"
                class="w-full bg-white border border-sand-dark/60 rounded-xl px-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none">
                <option value="">{{ $t('ไม่ระบุ') }}</option>
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
                {{ $t('อีเมล') }}
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-regular fa-envelope text-text-muted/60"></i>
                </div>
                <input v-model="form.email" type="email" required
                  @blur="validateEmail" @input="emailError = ''"
                  :class="[
                    'w-full bg-white border rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 outline-none placeholder:text-text-muted/50',
                    emailError
                      ? 'border-red-400 focus:ring-red-200 focus:border-red-400'
                      : 'border-sand-dark/60 focus:ring-accent/20 focus:border-accent'
                  ]"
                  placeholder="example@gmail.com" />
              </div>
              <p v-if="emailError" class="text-xs text-red-600 flex items-center gap-1">
                <i class="fa-solid fa-circle-exclamation"></i>{{ emailError }}
              </p>
              <p v-else class="text-xs text-text-muted/70">
                {{ $t('รองรับ Gmail, Hotmail, Outlook, Yahoo, iCloud ฯลฯ') }}
              </p>
            </div>

            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('เบอร์โทรศัพท์') }}
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-mobile-screen-button text-text-muted/60"></i>
                </div>
                <input v-model="form.phone" type="tel"
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  placeholder="08X-XXX-XXXX" />
              </div>
            </div>
          </div>

          <!-- Password Row -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('รหัสผ่าน') }}
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-lock text-text-muted/60"></i>
                </div>
                <input v-model="form.password" type="password" required
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  :placeholder="$t('อย่างน้อย 8 ตัวอักษร')" />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-sm font-medium text-text-dark flex items-center gap-2">
                {{ $t('ยืนยันรหัสผ่าน') }}
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fa-solid fa-check text-text-muted/60"></i>
                </div>
                <input v-model="form.password_confirmation" type="password" required
                  class="w-full bg-white border border-sand-dark/60 rounded-xl pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50"
                  :placeholder="$t('ยืนยันรหัสผ่านอีกครั้ง')" />
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
              {{ $t('ฉันยอมรับ') }} <router-link to="/terms" class="text-accent hover:underline font-medium">{{ $t('ข้อกำหนดการใช้งาน') }}</router-link> {{ $t('และ') }} <router-link to="/privacy" class="text-accent hover:underline font-medium">{{ $t('นโยบายความเป็นส่วนตัว') }}</router-link> {{ $t('ของ ลุยเลเขา') }}
            </label>
          </div>

          <!-- Error Alert -->
          <div v-if="error" class="p-4 bg-red-50 border border-red-100 rounded-xl flex items-start gap-3">
            <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
            <p class="text-red-700 text-sm">{{ error }}</p>
          </div>

          <!-- Submit Button -->
          <button type="submit" :disabled="auth.loading"
            class="w-full bg-accent text-white py-3.5 rounded-xl font-bold shadow-accent/20 hover:bg-accent-mid hover:-translate-y-0.5 transition-all duration-300 disabled:opacity-70 disabled:cursor-not-allowed disabled:transform-none disabled: flex items-center justify-center gap-2 mt-4">
            <i v-if="auth.loading" class="fa-solid fa-circle-notch fa-spin"></i>
            <i v-else class="fa-solid fa-user-plus"></i>
            {{ auth.loading ? $t('กำลังสร้างบัญชี...') : $t('สมัครสมาชิก') }}
          </button>
        </form>
        <!-- Login Link -->
        <div class="mt-8 text-center lg:text-left border-t border-sand-dark/50 pt-6">
          <p class="text-sm text-text-muted">
            {{ $t('มีบัญชีอยู่แล้ว?') }}
            <router-link :to="{ name: 'login', query: route.query.redirect ? { redirect: route.query.redirect } : {} }" class="text-accent hover:text-accent-mid font-semibold inline-flex items-center gap-1 transition-colors">
              {{ $t('เข้าสู่ระบบเลย') }}
              <i class="fa-solid fa-arrow-right-to-bracket text-xs ml-1"></i>
            </router-link>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { $t, LOCALE, isEnglish, rememberLocale } from '@/i18n';
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { bangkokToday } from '../lib/bangkokDate';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const error = ref('');
const emailError = ref('');

// Mirror of config/email_domains.php — well-known active providers only.
// The backend is the real gate; this is for immediate UX feedback.
const ALLOWED_EMAIL_DOMAINS = [
  'gmail.com', 'googlemail.com',
  'hotmail.com', 'hotmail.co.th', 'outlook.com', 'outlook.co.th',
  'live.com', 'live.co.th', 'msn.com',
  'yahoo.com', 'yahoo.co.th', 'ymail.com',
  'icloud.com', 'me.com', 'mac.com',
  'proton.me', 'protonmail.com',
];

// ห้ามเลือกวันในอนาคต — ฝั่ง API ก็ปฏิเสธด้วยกฎ before:today
// ใช้วันที่ไทย ไม่ใช่ UTC ไม่งั้นช่วงเช้ามืดจะกันวันนี้ทิ้งไปด้วย
const today = bangkokToday();

const form = ref({
  title: '',
  name: '',
  nickname: '',
  birth_date: '',
  blood_group: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
});

function validateEmail() {
  const value = (form.value.email || '').trim().toLowerCase();
  if (!value) {
    emailError.value = '';
    return true;
  }
  const at = value.lastIndexOf('@');
  const domain = at >= 0 ? value.slice(at + 1) : '';
  if (!ALLOWED_EMAIL_DOMAINS.includes(domain)) {
    emailError.value = $t('กรุณาใช้อีเมลจากผู้ให้บริการที่รองรับ เช่น Gmail, Hotmail, Outlook, Yahoo หรือ iCloud');
    return false;
  }
  emailError.value = '';
  return true;
}

async function handleRegister() {
  error.value = '';
  if (!validateEmail()) return;
  try {
    await auth.register(form.value);
    // มาจากลิงก์ที่ต้องล็อกอินก่อน (เช่น คำเชิญร่วมทริป) — พากลับไปที่เดิม
    router.push(route.query.redirect || '/');
  } catch (e) {
    const errors = e?.response?.data?.errors;
    if (errors) {
      error.value = Object.values(errors).flat().join(', ');
    } else {
      error.value = e?.response?.data?.message || $t('สมัครสมาชิกล้มเหลว');
    }
  }
}

// ผู้ให้บริการส่งกลับมาที่ URL ไทยเสมอ — จำภาษาไว้ก่อน หน้าที่กลับมาจะพาไป /en เอง
function loginWithGoogle() {
  if (isEnglish) rememberLocale(LOCALE);
  window.location.href = '/auth/google/redirect';
}

function loginWithFacebook() {
  if (isEnglish) rememberLocale(LOCALE);
  window.location.href = '/auth/facebook/redirect';
}
</script>
