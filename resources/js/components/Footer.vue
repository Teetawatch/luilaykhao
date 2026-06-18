<template>
  <footer class="bg-white text-text-dark mt-auto border-t border-sand-dark/40 relative overflow-hidden">
    <!-- Subtle background decoration -->
    <div class="absolute top-0 right-0 -mr-20 -mt-20 w-64 h-64 rounded-full bg-sand/30 blur-3xl -z-10 pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-6 py-16 relative z-10">
      <div class="grid grid-cols-1 md:grid-cols-12 gap-12 md:gap-8 lg:gap-12">
        
        <!-- Brand & About -->
        <!-- Brand & About -->
        <div class="md:col-span-6 lg:col-span-4">
          <router-link to="/" class="flex items-center gap-3 mb-6">
            <img src="/images/logo.png" alt="ลุยเลเขา Logo" class="w-24 h-24 object-contain drop-shadow-sm" />
          </router-link>
          <p class="text-m text-text-muted leading-relaxed max-w-sm mb-6">
            เราไม่ได้อยากเป็นแค่แพลตฟอร์มจองทริป แต่อยากเป็น "เพื่อน" ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น และมีความสุขมากขึ้นในทุกการเดินทาง
          </p>
          
          <!-- Social Icons -->
          <div class="flex items-center gap-3">
            <a href="https://www.facebook.com/profile.php?id=61572124170207" target="_blank" class="w-9 h-9 rounded-full border border-sand-dark/60 flex items-center justify-center text-text-muted hover:bg-accent hover:border-accent hover:text-white transition-all duration-300">
              <i class="fa-brands fa-facebook-f"></i>
            </a>
            <a href="https://instagram.com/luilaykhao" target="_blank" class="w-9 h-9 rounded-full border border-sand-dark/60 flex items-center justify-center text-text-muted hover:bg-accent hover:border-accent hover:text-white transition-all duration-300">
              <i class="fa-brands fa-instagram"></i>
            </a>
            <a href="https://line.me/ti/p/@luilaykhao" target="_blank" class="w-9 h-9 rounded-full border border-sand-dark/60 flex items-center justify-center text-text-muted hover:bg-accent hover:border-accent hover:text-white transition-all duration-300">
              <i class="fa-brands fa-line text-lg"></i>
            </a>
          </div>

      </div>

        <!-- Activities -->
        <div class="md:col-span-3 lg:col-span-2 lg:pl-4">
          <h4 class="font-anuphan font-bold text-lg mb-6 text-text-dark">
            กิจกรรมยอดนิยม
          </h4>
          <ul v-if="categories.length > 0" class="space-y-3 text-sm text-text-muted">
            <li v-for="cat in categories" :key="cat.id">
              <router-link :to="`/trips?type=${cat.slug}`" class="group flex items-center gap-3 hover:text-accent transition-colors">
                <span class="w-8 h-8 rounded-lg bg-sand/50 flex items-center justify-center group-hover:bg-accent/10 group-hover:text-accent transition-colors">
                  <span class="material-symbols-rounded text-[18px]">{{ cat.icon || 'explore' }}</span>
                </span>
                <span class="font-medium">{{ cat.name }}</span>
              </router-link>
            </li>
          </ul>
          <!-- Skeleton / Loading state -->
          <ul v-else-if="loading" class="space-y-3">
            <li v-for="i in 3" :key="i" class="flex items-center gap-3 animate-pulse">
              <div class="w-8 h-8 rounded-lg bg-sand/50"></div>
              <div class="h-4 bg-sand/50 rounded w-20"></div>
            </li>
          </ul>
        </div>

        <!-- Contact -->
        <div class="md:col-span-3 lg:col-span-3">
          <h4 class="font-anuphan font-bold text-lg mb-6 text-text-dark">
            ติดต่อเรา
          </h4>
          <ul class="space-y-4 text-sm text-text-muted">
            <li>
              <a href="https://line.me/ti/p/@luilaykhao" target="_blank" class="flex items-start gap-3.5 group">
                <div class="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center text-accent shrink-0 group-hover:bg-accent group-hover:text-white transition-colors">
                  <i class="fa-brands fa-line text-lg"></i>
                </div>
                <div class="pt-1.5">
                  <p class="font-semibold text-text-dark leading-none mb-1 group-hover:text-accent transition-colors">LINE Official</p>
                  <p>@luilaykhao</p>
                </div>
              </a>
            </li>
            <li>
              <a href="tel:0626126006" class="flex items-start gap-3.5 group">
                <div class="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center text-accent shrink-0 group-hover:bg-accent group-hover:text-white transition-colors">
                  <span class="material-symbols-rounded text-[18px]">call</span>
                </div>
                <div class="pt-1.5">
                  <p class="font-semibold text-text-dark leading-none mb-1 group-hover:text-accent transition-colors">โทรศัพท์</p>
                  <p>062-612-6006</p>
                </div>
              </a>
            </li>
            <li>
              <a href="mailto:[EMAIL_ADDRESS]" class="flex items-start gap-3.5 group">
                <div class="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center text-accent shrink-0 group-hover:bg-accent group-hover:text-white transition-colors">
                  <span class="material-symbols-rounded text-[18px]">mail</span>
                </div>
                <div class="pt-1.5">
                  <p class="font-semibold text-text-dark leading-none mb-1 group-hover:text-accent transition-colors">อีเมล</p>
                  <p>luilaykhao.info@gmail.com</p>
                </div>
              </a>
            </li>
          </ul>
        </div>

        <!-- Download App -->
        <div class="md:col-span-12 lg:col-span-3 lg:pl-4">
          <h4 class="font-anuphan font-bold text-lg mb-6 text-text-dark">
            ดาวน์โหลด Luilaykhao
          </h4>
          <div class="flex items-center gap-3 ">
            <div class="shrink-0 -mt-10">
              <img src="/images/logo.png" alt="Luilaykhao Logo" class="w-20 h-20 rounded-[1.25rem] shadow-sm border border-gray-100 bg-white p-2 object-contain" />
            </div>
            <div class="flex flex-col gap-0 -mt-10">
              <a href="#" class="block hover:opacity-80 transition-opacity">
                <img src="/images/icon-appstore.webp" alt="App Store" class="w-28 h-28 object-contain" />
              </a>
              <a href="#" class="block -mt-15 hover:opacity-80 transition-opacity">
                <img src="/images/icon-playstore.webp" alt="Play Store" class="w-28 h-28 object-contain" />
              </a>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Bottom Bar -->
      <div class="border-t border-sand-dark/40 mt-16 pt-8 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="text-sm text-text-muted font-medium">
          &copy; {{ new Date().getFullYear() }} ลุยเลเขา. All rights reserved.
        </div>
        <div class="flex gap-6 text-sm font-medium text-text-muted">
          <router-link to="/gallery" class="hover:text-accent transition-colors">ภาพประทับใจ</router-link>
          <router-link to="/terms" class="hover:text-accent transition-colors">เงื่อนไขการให้บริการ</router-link>
          <router-link to="/privacy" class="hover:text-accent transition-colors">นโยบายความเป็นส่วนตัว</router-link>
        </div>
      </div>
    </div>
  </footer>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../lib/axios';

const categories = ref([]);
const loading = ref(true);

onMounted(async () => {
  try {
    const res = await api.get('/categories');
    // Show only first 6 popular categories in footer
    categories.value = (res.data.data || []).slice(0, 6);
  } catch (e) {
    console.error('Failed to fetch footer categories:', e);
  } finally {
    loading.value = false;
  }
});
</script>

