<template>
  <div class="min-h-screen flex flex-col" v-if="!isAdminRoute">
    <Navbar />

    <!-- Global Active Booking Banner -->
    <Transition name="booking-banner">
      <div v-if="seatsStore.hasActiveBooking"
        class="sticky top-[80px] z-40 w-full">
        <div class="bg-white border-b border-gray-200 shadow-sm">
          <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5 flex items-center gap-3">
            <!-- Icon + Status -->
            <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
              :class="seatsStore.countdownSeconds <= 60 ? 'bg-red-100' : seatsStore.countdownSeconds <= 180 ? 'bg-amber-100' : 'bg-teal-100'">
              <span class="material-symbols-rounded text-[18px]"
                :class="seatsStore.countdownSeconds <= 60 ? 'text-red-600 animate-pulse' : seatsStore.countdownSeconds <= 180 ? 'text-amber-600' : 'text-teal-600'"
                style="font-variation-settings:'FILL' 1,'wght' 400">timer</span>
            </div>

            <div class="flex-1 min-w-0">
              <p class="text-xs font-medium text-gray-500 leading-none mb-0.5">รายการจองที่ค้างอยู่</p>
              <p class="text-sm font-bold text-gray-900 truncate">{{ seatsStore.activeBookingInfo?.tripTitle || 'กำลังทำรายการจอง...' }}</p>
            </div>

            <!-- Countdown pill -->
            <div class="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-xl font-bold text-sm"
              :class="seatsStore.countdownSeconds <= 60 ? 'bg-red-100 text-red-700' : seatsStore.countdownSeconds <= 180 ? 'bg-amber-100 text-amber-700' : 'bg-teal-100 text-teal-700'">
              <span class="material-symbols-rounded text-[16px]">hourglass_bottom</span>
              <span class="font-anuphan tracking-tight">{{ formattedGlobal }}</span>
            </div>

            <!-- Go back to booking button -->
            <router-link
              :to="`/booking/${seatsStore.activeBookingInfo?.scheduleId}`"
              class="shrink-0 hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-teal-600 text-white hover:bg-teal-700 transition-colors active:scale-95">
              <span class="material-symbols-rounded text-[16px]">arrow_forward</span>
              ดำเนินการต่อ
            </router-link>
          </div>

          <!-- Progress bar -->
          <div class="h-1 w-full"
            :class="seatsStore.countdownSeconds <= 60 ? 'bg-red-100' : seatsStore.countdownSeconds <= 180 ? 'bg-amber-100' : 'bg-teal-100'">
            <div class="h-full transition-all duration-1000"
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
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Navbar from './components/Navbar.vue';
import Footer from './components/Footer.vue';
import ToastNotification from './components/ToastNotification.vue';
import { useSeatsStore } from './stores/seats';
import { useSwal } from './lib/swal';

const route = useRoute();
const router = useRouter();
const isAdminRoute = computed(() => route.path.startsWith('/admin'));
const seatsStore = useSeatsStore();
const swal = useSwal();

const formattedGlobal = computed(() => {
  const s = seatsStore.countdownSeconds;
  const m = Math.floor(s / 60);
  const sec = s % 60;
  return `${m}:${sec.toString().padStart(2, '0')}`;
});

function handleGlobalExpiry() {
  const isOnBookingPage = route.path.startsWith('/booking/');
  if (isOnBookingPage) return; // BookingPage handles its own expiry
  seatsStore.clearSelection();
  swal.error(
    'หมดเวลาการจองแล้ว!',
    'เวลา 10 นาทีสำหรับการจองหมดลงแล้ว ที่นั่งที่ล็อคไว้ถูกปลดล็อคแล้ว กรุณาเริ่มต้นการจองใหม่'
  ).then(() => {
    router.push('/trips');
  });
}

onMounted(() => {
  seatsStore.onExpire(handleGlobalExpiry);
});

onUnmounted(() => {
  seatsStore.offExpire(handleGlobalExpiry);
});
</script>

<style scoped>
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
</style>
