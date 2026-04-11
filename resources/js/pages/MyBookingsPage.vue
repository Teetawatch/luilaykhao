<template>
  <div class="min-h-screen bg-gray-50/50 pt-8 pb-32">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

      <!-- Page Header -->
      <section class="mb-10 relative">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 tracking-tight mb-2 font-anuphan relative z-10">
          การจองของฉัน
        </h1>
        <p class="text-gray-500 font-medium text-base font-anuphan relative z-10">
          จัดการแผนการเดินทางที่แสนพิเศษของคุณได้ที่นี่
        </p>
      </section>

      <!-- Tabs -->
      <div class="flex gap-2 mb-10 bg-gray-100/80 p-1.5 rounded-2xl w-fit relative z-10 shadow-inner border border-gray-200/50">
        <button
          @click="activeTab = 'upcoming'"
          class="px-6 py-3 text-sm font-bold rounded-xl transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'upcoming'
            ? 'bg-white text-teal-700 shadow-sm border border-gray-100'
            : 'text-gray-500 hover:text-teal-600 hover:bg-white/50'"
          style="font-family: 'Anuphan', sans-serif;">
          <span class="material-symbols-rounded text-[18px]" :style="activeTab === 'upcoming' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">event_upcoming</span>
          ที่กำลังจะมาถึง
        </button>
        <button
          @click="activeTab = 'past'"
          class="px-6 py-3 text-sm font-bold rounded-xl transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'past'
            ? 'bg-white text-teal-700 shadow-sm border border-gray-100'
            : 'text-gray-500 hover:text-teal-600 hover:bg-white/50'"
          style="font-family: 'Anuphan', sans-serif;">
          <span class="material-symbols-rounded text-[18px]" :style="activeTab === 'past' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">history</span>
          ที่ผ่านมาแล้ว
        </button>
      </div>

      <!-- Loading -->
      <div v-if="bookingStore.loading" class="text-center py-24 flex flex-col items-center justify-center">
        <div class="w-14 h-14 border-4 border-teal-100 border-t-teal-600 rounded-full animate-spin mb-4"></div>
        <p class="text-gray-500 font-medium animate-pulse" style="font-family: 'Anuphan', sans-serif;">กำลังโหลดข้อมูลการจอง...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="filteredBookings.length === 0" class="text-center py-24 bg-white rounded-3xl shadow-sm border border-gray-100 relative overflow-hidden">
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center mb-6 border border-gray-100">
            <span class="material-symbols-rounded text-[48px] text-gray-300" style="font-variation-settings:'FILL' 0,'wght' 300">
              {{ activeTab === 'upcoming' ? 'event_busy' : 'history_toggle_off' }}
            </span>
          </div>
          <h3 class="text-xl font-bold text-gray-900 mb-2" style="font-family: 'Anuphan', sans-serif;">ยังไม่มีการจอง</h3>
          <p class="text-gray-500 text-base mb-8 max-w-sm mx-auto" style="font-family: 'Anuphan', sans-serif;">
            {{ activeTab === 'upcoming' 
                ? 'คุณยังไม่มีแผนการเดินทางที่กำลังจะมาถึง เริ่มค้นหาประสบการณ์ใหม่ๆ ได้เลย!' 
                : 'คุณยังไม่เคยเดินทางกับเรามาก่อน ลองดูทริปที่น่าสนใจสิ' }}
          </p>
          <router-link to="/trips"
            class="inline-flex items-center gap-2 bg-teal-600 text-white px-8 py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 group"
            style="font-family: 'Anuphan', sans-serif;">
            <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">explore</span>
            เริ่มค้นหากิจกรรม
            <span class="material-symbols-rounded text-[20px] transition-transform group-hover:translate-x-1" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_forward</span>
          </router-link>
        </div>
      </div>

      <!-- Booking Cards -->
      <div v-else class="space-y-6">
        <article
          v-for="b in filteredBookings"
          :key="b.id"
          class="bg-white rounded-3xl overflow-hidden flex flex-col md:flex-row group border border-gray-100 shadow-sm transition-all duration-300 hover:shadow-md hover:border-teal-100 relative"
          :class="{ 'opacity-80': b.status === 'cancelled' || b.status === 'refunded' }">
          
          <div class="absolute top-0 left-0 w-1.5 h-full bg-teal-600 z-10" v-if="b.status === 'confirmed'"></div>
          <div class="absolute top-0 left-0 w-1.5 h-full bg-amber-500 z-10" v-if="b.status === 'pending'"></div>

          <!-- Image -->
          <div class="md:w-1/3 h-56 md:h-auto relative overflow-hidden shrink-0"
            :class="{ 'grayscale opacity-75': b.status === 'cancelled' || b.status === 'refunded' }">
            <img
              v-if="b.schedule?.trip?.cover_image || b.schedule?.trip?.thumbnail_url"
              :src="b.schedule.trip.cover_image || b.schedule.trip.thumbnail_url"
              :alt="b.schedule?.trip?.title"
              class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" />
            <div v-else class="w-full h-full bg-gray-100 flex items-center justify-center">
              <span class="material-symbols-rounded text-gray-300 text-[48px]">image_not_supported</span>
            </div>
            
            <!-- Date Badge on Image (Mobile only) -->
            <div class="absolute top-4 left-4 md:hidden bg-white px-3 py-1.5 rounded-xl shadow-sm border border-gray-200 font-anuphan flex flex-col items-center leading-tight">
              <span class="text-xs font-bold text-gray-500 uppercase">{{ getMonthShort(b.schedule?.departure_date) }}</span>
              <span class="text-lg font-extrabold text-gray-900">{{ getDay(b.schedule?.departure_date) }}</span>
            </div>
          </div>

          <!-- Content -->
          <div class="p-6 md:p-8 flex-1 flex flex-col font-anuphan relative">
            <div class="flex flex-col sm:flex-row justify-between items-start mb-4 gap-3 sm:gap-6">
              <h2 class="text-xl md:text-2xl font-bold text-gray-900 leading-snug line-clamp-2 group-hover:text-teal-700 transition-colors">
                {{ b.schedule?.trip?.title || 'การจอง' }}
              </h2>
              <span class="px-3 py-1.5 text-xs font-bold rounded-lg shrink-0 flex items-center gap-1.5 whitespace-nowrap"
                :class="statusClass(b.status)">
                <span class="w-1.5 h-1.5 rounded-full" :class="statusDotClass(b.status)"></span>
                {{ statusLabel(b.status) }}
              </span>
            </div>

            <div class="space-y-3 mb-6 bg-gray-50 p-4 rounded-2xl border border-gray-100">
              <div class="flex items-center justify-between text-sm text-gray-600">
                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded-xl bg-white flex items-center justify-center shadow-sm border border-gray-100">
                    <span class="material-symbols-rounded text-[18px] text-teal-600">calendar_month</span>
                  </div>
                  <span class="font-medium text-gray-900">{{ formatDate(b.schedule?.departure_date) }}</span>
                </div>
                <div class="text-right">
                  <span class="text-xs text-gray-500 font-bold block mb-0.5 uppercase tracking-wider">หมายเลขการจอง</span>
                  <span class="font-mono text-gray-700 font-medium">{{ b.booking_ref }}</span>
                </div>
              </div>
            </div>

            <div class="flex justify-between items-end mb-6">
              <div class="text-sm font-bold text-gray-500 uppercase tracking-wide">ยอดชำระ</div>
              <div class="text-2xl md:text-3xl font-extrabold text-teal-700 tracking-tight font-anuphan">
                <span class="text-base text-teal-600 mr-1 font-bold">฿</span>{{ Number(b.total_amount).toLocaleString() }}
              </div>
            </div>

            <!-- Actions -->
            <div class="mt-auto flex gap-3 flex-wrap sm:flex-nowrap">
              <router-link
                :to="`/confirmation/${b.booking_ref}`"
                class="flex-1 text-center bg-teal-600 text-white py-3.5 px-4 rounded-xl font-bold text-sm hover:bg-teal-700 active:scale-95 transition-all shadow-sm shadow-teal-600/20 flex items-center justify-center gap-2">
                <span v-if="b.status === 'confirmed'" class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400">confirmation_number</span>
                <span v-else class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">visibility</span>
                {{ b.status === 'confirmed' ? 'ดาวน์โหลดตั๋ว' : 'ดูรายละเอียด' }}
              </router-link>
              
              <button
                v-if="b.status === 'confirmed' || b.status === 'pending'"
                @click="$router.push(`/confirmation/${b.booking_ref}`)"
                class="flex-1 bg-gray-100 text-gray-700 border border-gray-200 py-3.5 px-4 rounded-xl font-bold text-sm hover:bg-gray-200 active:scale-95 transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">info</span>
                รายละเอียด
              </button>
              
              <router-link
                v-if="activeTab === 'past' && b.status === 'completed'"
                to="/my-reviews"
                class="flex-1 text-center border-2 border-teal-600 text-teal-600 py-3.5 px-4 rounded-xl font-bold text-sm hover:bg-teal-50 active:scale-95 transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400">star</span>
                เขียนรีวิว
              </router-link>
              
              <button
                v-if="b.status === 'pending'"
                @click="handleCancel(b)"
                class="flex-1 sm:flex-none border border-gray-200 text-red-500 py-3.5 px-6 rounded-xl font-bold text-sm hover:bg-red-50 hover:border-red-100 active:scale-95 transition-all">
                ยกเลิก
              </button>
            </div>
          </div>
        </article>

        <!-- Pagination -->
        <div v-if="bookingStore.meta && bookingStore.meta.last_page > 1" class="flex justify-center mt-10 gap-2">
          <button
            v-for="page in bookingStore.meta.last_page"
            :key="page"
            @click="bookingStore.fetchMyBookings(page)"
            class="w-10 h-10 rounded-xl text-sm font-bold transition-all duration-300"
            :class="page === bookingStore.meta.current_page
              ? 'bg-teal-600 text-white shadow-sm shadow-teal-600/20'
              : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 hover:border-gray-300'"
            style="font-family: 'Anuphan', sans-serif;">
            {{ page }}
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useBookingStore } from '../stores/booking';

const bookingStore = useBookingStore();
const activeTab = ref('upcoming');

const upcomingStatuses = ['pending', 'confirmed'];
const pastStatuses = ['cancelled', 'refunded', 'completed'];

const filteredBookings = computed(() => {
  return bookingStore.bookings.filter(b =>
    activeTab.value === 'upcoming'
      ? upcomingStatuses.includes(b.status)
      : pastStatuses.includes(b.status)
  );
});

const statusMap = {
  pending:   'รอชำระเงิน',
  confirmed: 'ยืนยันแล้ว',
  cancelled: 'ยกเลิกแล้ว',
  refunded:  'คืนเงินแล้ว',
  completed: 'เสร็จสิ้นแล้ว',
};

function statusLabel(s) { return statusMap[s] || s; }

function statusClass(s) {
  const map = {
    pending:   'bg-amber-50 text-amber-700 border border-amber-200/50',
    confirmed: 'bg-teal-50 text-teal-700 border border-teal-200/50',
    cancelled: 'bg-gray-100 text-gray-500 border border-gray-200',
    refunded:  'bg-gray-100 text-gray-500 border border-gray-200',
    completed: 'bg-blue-50 text-blue-700 border border-blue-200/50',
  };
  return map[s] || 'bg-gray-100 text-gray-500 border border-gray-200';
}

function statusDotClass(s) {
  const map = {
    pending:   'bg-amber-500',
    confirmed: 'bg-teal-500',
    cancelled: 'bg-gray-400',
    refunded:  'bg-gray-400',
    completed: 'bg-blue-500',
  };
  return map[s] || 'bg-gray-400';
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function getDay(d) {
  if (!d) return '';
  return new Date(d).getDate();
}

function getMonthShort(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { month: 'short' });
}

async function handleCancel(b) {
  if (!confirm('ต้องการยกเลิกการจองนี้หรือไม่?')) return;
  try {
    await bookingStore.cancelBooking(b.booking_ref, 'ยกเลิกโดยลูกค้า');
    await bookingStore.fetchMyBookings();
  } catch (e) {
    alert(e?.response?.data?.message || 'ยกเลิกไม่สำเร็จ');
  }
}

onMounted(() => {
  bookingStore.fetchMyBookings();
});
</script>
