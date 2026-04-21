<template>
  <div class="min-h-screen flex flex-col" v-if="!isAdminRoute">
    <TopBanner />
    <Navbar />

    <!-- Global Active Booking Banner -->
    <Transition name="booking-banner">
      <div v-if="seatsStore.hasActiveBooking"
        class="sticky z-40 w-full transition-all duration-300 shadow-[0_8px_30px_rgb(0,0,0,0.04)]"
        :style="{ top: isScrolled ? '64px' : '80px' }">
        <div class="bg-white/80 backdrop-blur-md border-b border-gray-200/50">
          <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 md:py-3 flex items-center gap-3">
            <!-- Icon + Status -->
            <div class="w-8 h-8 md:w-9 md:h-9 rounded-2xl flex items-center justify-center shrink-0 shadow-sm border"
              :class="seatsStore.countdownSeconds <= 60 
                ? 'bg-red-50 border-red-100' 
                : seatsStore.countdownSeconds <= 180 
                  ? 'bg-amber-50 border-amber-100' 
                  : 'bg-teal-50 border-teal-100'">
              <span class="material-symbols-rounded text-[18px] md:text-[20px]"
                :class="seatsStore.countdownSeconds <= 60 ? 'text-red-600 animate-pulse' : seatsStore.countdownSeconds <= 180 ? 'text-amber-600' : 'text-teal-600'"
                style="font-variation-settings:'FILL' 1,'wght' 400">timer</span>
            </div>

            <div class="flex-1 min-w-0">
              <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none mb-1">รายการจองที่ค้างอยู่</p>
              <p class="text-sm font-bold text-gray-900 truncate leading-tight">{{ seatsStore.activeBookingInfo?.tripTitle || 'กำลังทำรายการจอง...' }}</p>
            </div>

            <!-- Countdown pill -->
            <div class="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-xl font-black text-xs md:text-sm shadow-sm border transition-all duration-500"
              :class="seatsStore.countdownSeconds <= 60 
                ? 'bg-red-600 text-white border-red-500' 
                : seatsStore.countdownSeconds <= 180 
                  ? 'bg-amber-100 text-amber-700 border-amber-200' 
                  : 'bg-teal-600 text-white border-teal-500'">
              <span class="material-symbols-rounded text-[14px] md:text-[16px]" :class="seatsStore.countdownSeconds <= 60 ? 'animate-spin-slow' : ''">hourglass_bottom</span>
              <span class="font-anuphan tracking-tighter">{{ formattedGlobal }}</span>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-2 shrink-0">
               <!-- Cancel button -->
               <button
                  @click="cancelCurrentBooking"
                  class="flex items-center gap-1.5 px-3 py-1.5 md:px-4 md:py-2 rounded-xl text-xs font-black text-gray-500 hover:text-red-600 hover:bg-red-50 transition-all active:scale-95">
                  <span class="material-symbols-rounded text-[16px] md:text-[18px]">close</span>
                  <span class="hidden md:inline">ยกเลิก</span>
               </button>

              <!-- Go back to booking button -->
              <router-link
                :to="`/booking/${seatsStore.activeBookingInfo?.scheduleId}`"
                class="flex items-center gap-1.5 px-3 py-1.5 md:px-4 md:py-2 rounded-xl text-xs font-black bg-gray-900 text-white hover:bg-black transition-all active:scale-95 shadow-lg shadow-gray-900/10">
                <span class="hidden md:inline">ดำเนินการต่อ</span>
                <span class="material-symbols-rounded text-[16px] md:text-[18px]">arrow_forward</span>
              </router-link>
            </div>
          </div>

          <!-- Progress bar -->
          <div class="h-1 w-full bg-gray-100/50 relative overflow-hidden">
            <div class="h-full transition-all duration-1000 ease-linear shadow-[0_1px_4px_rgba(0,0,0,0.1)]"
              :class="seatsStore.countdownSeconds <= 60 ? 'bg-red-500' : seatsStore.countdownSeconds <= 180 ? 'bg-amber-500' : 'bg-teal-500'"
              :style="{ width: `${(seatsStore.countdownSeconds / 600) * 100}%` }">
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <main class="flex-1">
      <router-view />
    </main>
    <Footer />
  </div>
  <router-view v-else />
  <ToastNotification />

  <!-- Wishlist Toast (Teleported to body to avoid transform conflicts) -->
  <Teleport to="body">
    <Transition name="wishlist-toast">
      <div
        v-if="wishlistStore.lastAdded"
        class="fixed bottom-16 left-1/2 z-[300] flex items-center gap-3 bg-white rounded-2xl shadow-2xl border border-gray-200 px-5 py-3.5"
        style="transform: translateX(-50%); min-width: 280px; max-width: 400px;"
      >
        <div class="w-10 h-10 rounded-xl overflow-hidden shrink-0 bg-gray-100">
          <img v-if="wishlistStore.lastAdded.cover_image" :src="wishlistStore.lastAdded.cover_image" class="w-full h-full object-cover" />
          <span v-else class="material-symbols-rounded text-[20px] text-red-400 flex items-center justify-center w-full h-full" style="font-variation-settings:'FILL' 1">favorite</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-[12px] font-bold text-teal-600">เพิ่มเข้ารายการที่ชอบแล้ว</p>
          <p class="text-[13px] font-bold text-gray-900 truncate">{{ wishlistStore.lastAdded.title || `ทริป #${wishlistStore.lastAdded.id}` }}</p>
        </div>
        <span class="material-symbols-rounded text-[22px] text-red-500 shrink-0" style="font-variation-settings:'FILL' 1">favorite</span>
      </div>
    </Transition>
  </Teleport>
  
  <!-- Back to Top Button -->
  <Teleport to="body">
    <Transition name="back-to-top">
      <button
        v-if="showBackToTop"
        @click="scrollToTop"
        class="fixed bottom-8 right-8 z-[150] w-14 h-14 bg-[var(--color-accent)] text-white rounded-2xl shadow-[0_15px_40px_rgba(45,122,79,0.3)] hover:shadow-[0_20px_50px_rgba(45,122,79,0.5)] hover:bg-[var(--color-primary)] transition-all duration-500 hover:-translate-y-2 group flex items-center justify-center cursor-pointer border border-white/20 backdrop-blur-md"
        aria-label="Back to Top"
      >
        <span class="material-symbols-rounded text-3xl transition-transform duration-500 group-hover:-translate-y-1">arrow_upward</span>
        <div class="absolute inset-0 rounded-2xl border-2 border-white/20 group-hover:scale-110 group-hover:opacity-0 transition-all duration-700"></div>
      </button>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Navbar from './components/Navbar.vue';
import TopBanner from './components/TopBanner.vue';
import Footer from './components/Footer.vue';
import ToastNotification from './components/ToastNotification.vue';
import { useSeatsStore } from './stores/seats';
import { useBookingStore } from './stores/booking';
import { useWishlistStore } from './stores/wishlist';
import { useSwal } from './lib/swal';
import { useHead } from '@unhead/vue';

const route = useRoute();
const router = useRouter();
const isAdminRoute = computed(() => route.path.startsWith('/admin'));
const seatsStore = useSeatsStore();
const bookingStore = useBookingStore();
const wishlistStore = useWishlistStore();
const swal = useSwal();

useHead({
  title: computed(() => route.meta.title || 'แพลตฟอร์มจองและจัดทริปเที่ยวทั่วไทย การันตีความสนุก'),
  titleTemplate: '%s | ลุยเลเขา',
  meta: [
    { name: 'description', content: computed(() => route.meta.description || 'ลุยเลเขา แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทย ตอบโจทย์คนรักธรรมชาติและการผจญภัย ให้ทุกการเดินทางของคุณเป็นเรื่องง่าย') },
    { property: 'og:title', content: computed(() => (route.meta.title ? `${route.meta.title} | ลุยเลเขา` : 'ลุยเลเขา | แพลตฟอร์มจองและจัดทริปเที่ยวทั่วไทย การันตีความสนุก')) },
    { property: 'og:description', content: computed(() => route.meta.description || 'ลุยเลเขา แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทย ตอบโจทย์คนรักธรรมชาติและการผจญภัย ให้ทุกการเดินทางของคุณเป็นเรื่องง่าย') }
  ]
});

const formattedGlobal = computed(() => {
  const s = seatsStore.countdownSeconds;
  const m = Math.floor(s / 60);
  const sec = s % 60;
  return `${m}:${sec.toString().padStart(2, '0')}`;
});

async function cancelCurrentBooking() {
  const isConfirmed = await swal.confirm(
    'ยกเลิกการจองนี้?',
    'ที่นั่งที่คุณเลือกจะถูกปลดล็อค และข้อมูลที่กรอกไว้จะหายไป',
    'ยืนยันยกเลิก',
    'ไม่ ยกเลิก'
  );

  if (!isConfirmed) return;

  try {
    const bookingRef = seatsStore.activeBookingInfo?.bookingRef;
    if (bookingRef) {
      await bookingStore.cancelBooking(bookingRef, 'ผู้ใช้กดยกเลิกจากแถบแจ้งเตือนส่วนกลาง');
    } else if (seatsStore.activeBookingInfo?.scheduleId) {
      // If only seats are locked but no booking record yet
      await seatsStore.unlockSeats(seatsStore.activeBookingInfo.scheduleId);
    }
    
    seatsStore.clearSelection();
    swal.success('ยกเลิกการจองแล้ว', 'คุณสามารถเลือกทริปและที่นั่งใหม่ได้ตามต้องการ');
    
    // Redirect if we are currently on booking/payment pages
    if (route.path.startsWith('/booking/') || route.path.startsWith('/payment/')) {
      router.push('/trips');
    }
  } catch (err) {
    console.error('Cancellation failed:', err);
    // Even if server call fails, we clear local state to avoid getting stuck
    seatsStore.clearSelection();
    router.push('/trips');
  }
}

function handleGlobalExpiry() {
  const isOnBookingPage = route.path.startsWith('/booking/');
  const isOnPaymentPage = route.path.startsWith('/payment/');
  if (isOnBookingPage || isOnPaymentPage) return; // These pages handle their own expiry
  seatsStore.clearSelection();
  swal.error(
    'หมดเวลาการจองแล้ว!',
    'เวลา 10 นาทีสำหรับการจองหมดลงแล้ว ที่นั่งที่ล็อคไว้ถูกปลดล็อคแล้ว กรุณาเริ่มต้นการจองใหม่'
  ).then(() => {
    router.push('/trips');
  });
}

const showBackToTop = ref(false);
const isScrolled = ref(false);

const handleScroll = () => {
  showBackToTop.value = window.scrollY > 400;
  isScrolled.value = window.scrollY > 20;
};

const scrollToTop = () => {
  window.scrollTo({
    top: 0,
    behavior: 'smooth'
  });
};

onMounted(() => {
  seatsStore.restoreCountdown();
  seatsStore.onExpire(handleGlobalExpiry);
  window.addEventListener('scroll', handleScroll);
});

onUnmounted(() => {
  seatsStore.offExpire(handleGlobalExpiry);
  window.removeEventListener('scroll', handleScroll);
});
</script>

<style scoped>
/* Wishlist toast */
.wishlist-toast-enter-active,
.wishlist-toast-leave-active {
  transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.wishlist-toast-enter-from {
  opacity: 0;
  transform: translateX(-50%) translateY(20px) scale(0.9);
}
.wishlist-toast-enter-to {
  opacity: 1;
  transform: translateX(-50%) translateY(0) scale(1);
}
.wishlist-toast-leave-from {
  opacity: 1;
  transform: translateX(-50%) translateY(0) scale(1);
}
.wishlist-toast-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(20px) scale(0.9);
}

.booking-banner-enter-active,
.booking-banner-leave-active {
  transition: all 0.3s ease;
  overflow: hidden;
}
.booking-banner-enter-from,
.booking-banner-leave-to {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}
.booking-banner-enter-to,
.booking-banner-leave-from {
  opacity: 1;
  max-height: 80px;
  transform: translateY(0);
}

/* Back to Top Transition */
.back-to-top-enter-active,
.back-to-top-leave-active {
  transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}
.back-to-top-enter-from,
.back-to-top-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.8);
}
</style>
