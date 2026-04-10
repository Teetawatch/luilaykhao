<template>
  <div class="min-h-screen flex items-center justify-center bg-sand/20">
    <div class="text-center">
      <div v-if="!errorMsg" class="space-y-4">
        <i class="fa-solid fa-circle-notch fa-spin text-4xl text-accent"></i>
        <p class="text-text-muted font-anuphan">กำลังเข้าสู่ระบบ...</p>
      </div>
      <div v-else class="space-y-4">
        <i class="fa-solid fa-circle-exclamation text-4xl text-red-500"></i>
        <p class="text-red-700 font-anuphan">{{ errorMsg }}</p>
        <router-link to="/login" class="text-accent hover:underline text-sm">กลับสู่หน้าเข้าสู่ระบบ</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const errorMsg = ref('');

onMounted(() => {
  const token = route.query.token;
  const userParam = route.query.user;
  const error = route.query.error;

  console.log('Social callback:', { token, userParam, error });

  if (error || !token || !userParam) {
    errorMsg.value = 'การเข้าสู่ระบบผ่าน Social ล้มเหลว กรุณาลองใหม่อีกครั้ง';
    console.error('Missing params:', { error, hasToken: !!token, hasUser: !!userParam });
    return;
  }

  try {
    const user = JSON.parse(decodeURIComponent(userParam));
    console.log('Parsed user:', user);
    auth.setAuth({ user, token });
    console.log('Auth set, redirecting to:', route.query.redirect || '/');
    const redirect = route.query.redirect || '/';
    router.replace(redirect);
  } catch (e) {
    console.error('Parse error:', e);
    errorMsg.value = 'เกิดข้อผิดพลาดในการประมวลผลข้อมูล กรุณาลองใหม่อีกครั้ง';
  }
});
</script>
