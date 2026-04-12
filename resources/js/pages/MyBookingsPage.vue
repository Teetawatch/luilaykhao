<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

      <!-- Page Header -->
      <section class="mb-8 relative">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2" style="font-family:'Anuphan',sans-serif;">
          การจองของฉัน
        </h1>
        <p class="text-[#505E5E] text-sm" style="font-family:'Anuphan',sans-serif;">
          จัดการแผนการเดินทางที่แสนพิเศษของคุณได้ที่นี่
        </p>
      </section>

      <!-- Tabs -->
      <div class="flex gap-2 mb-8 bg-[#E8EEEF] p-1.5 rounded-[16px] w-fit shadow-inner">
        <button
          @click="activeTab = 'upcoming'"
          class="px-5 py-2.5 text-sm font-bold rounded-[12px] transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'upcoming'
            ? 'bg-white text-[#006565] shadow-sm'
            : 'text-[#505E5E] hover:text-[#006565] hover:bg-white/40'"
          style="font-family: 'Anuphan', sans-serif;">
          <span class="material-symbols-rounded text-[20px]" :style="activeTab === 'upcoming' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">event_upcoming</span>
          ที่กำลังจะมาถึง
        </button>
        <button
          @click="activeTab = 'past'"
          class="px-5 py-2.5 text-sm font-bold rounded-[12px] transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'past'
            ? 'bg-white text-[#006565] shadow-sm'
            : 'text-[#505E5E] hover:text-[#006565] hover:bg-white/40'"
          style="font-family: 'Anuphan', sans-serif;">
          <span class="material-symbols-rounded text-[20px]" :style="activeTab === 'past' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">history</span>
          ที่ผ่านมาแล้ว
        </button>
      </div>

      <!-- Loading -->
      <div v-if="bookingStore.loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm" style="font-family: 'Anuphan', sans-serif;">กำลังโหลดข้อมูลการจอง...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="filteredBookings.length === 0" class="text-center py-20 bg-white rounded-[24px] shadow-sm border border-[#E8EEEF] relative overflow-hidden">
        <div class="relative z-10 flex flex-col items-center px-4">
          <div class="w-20 h-20 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5">
            <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">
              {{ activeTab === 'upcoming' ? 'event_busy' : 'history_toggle_off' }}
            </span>
          </div>
          <h3 class="text-lg font-bold text-[#1a1c1c] mb-2" style="font-family: 'Anuphan', sans-serif;">ยังไม่มีการจอง</h3>
          <p class="text-[#505E5E] text-sm mb-6 max-w-sm mx-auto" style="font-family: 'Anuphan', sans-serif;">
            {{ activeTab === 'upcoming' 
                ? 'คุณยังไม่มีแผนการเดินทางที่กำลังจะมาถึง เริ่มค้นหาประสบการณ์ใหม่ๆ ได้เลย!' 
                : 'คุณยังไม่เคยเดินทางกับเรามาก่อน ลองดูทริปที่น่าสนใจสิ' }}
          </p>
          <router-link to="/trips"
             class="inline-flex items-center gap-2 bg-[#006565] text-white px-6 py-3 rounded-full font-bold text-sm hover:bg-[#004f4f] transition-all"
             style="font-family: 'Anuphan', sans-serif;">
            <span class="material-symbols-rounded text-[20px]">explore</span>
            เริ่มค้นหากิจกรรม
            <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
          </router-link>
        </div>
      </div>

      <!-- Booking Cards -->
      <div v-else class="space-y-4">
        <article
          v-for="b in filteredBookings"
          :key="b.id"
          class="bg-white rounded-[20px] overflow-hidden flex flex-col md:flex-row group border border-[#E8EEEF] shadow-sm transition-all duration-300 hover:shadow-md hover:border-[#006565]/30 relative"
          :class="{ 'opacity-80': b.status === 'cancelled' || b.status === 'refunded' }">
          
          <div class="absolute top-0 left-0 w-1.5 h-full bg-[#006565] z-10" v-if="b.status === 'confirmed'"></div>
          <div class="absolute top-0 left-0 w-1.5 h-full bg-[#D97706] z-10" v-if="b.status === 'pending'"></div>

          <!-- Image -->
          <div class="md:w-[240px] h-48 md:h-auto relative overflow-hidden shrink-0"
            :class="{ 'grayscale opacity-75': b.status === 'cancelled' || b.status === 'refunded' }">
            <img
              v-if="b.schedule?.trip?.cover_image || b.schedule?.trip?.thumbnail_url"
              :src="b.schedule.trip.cover_image || b.schedule.trip.thumbnail_url"
              :alt="b.schedule?.trip?.title"
              class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" />
            <div v-else class="w-full h-full bg-[#F4F7F6] flex items-center justify-center">
              <span class="material-symbols-rounded text-[#A0B0B0] text-3xl">image_not_supported</span>
            </div>
            
            <!-- Date Badge on Image (Mobile only) -->
            <div class="absolute top-4 left-4 md:hidden bg-white px-3 py-1.5 rounded-[12px] shadow-sm flex flex-col items-center leading-tight">
              <span class="text-[10px] font-bold text-[#889696] uppercase" style="font-family: 'Anuphan', sans-serif;">{{ getMonthShort(b.schedule?.departure_date) }}</span>
              <span class="text-base font-extrabold text-[#1a1c1c]">{{ getDay(b.schedule?.departure_date) }}</span>
            </div>
          </div>

          <!-- Content -->
          <div class="p-5 md:p-6 flex-1 flex flex-col relative w-full">
            <div class="flex flex-col sm:flex-row justify-between items-start mb-3 gap-3">
              <h2 class="text-lg font-bold text-[#1a1c1c] leading-snug line-clamp-2 md:mr-8 transition-colors group-hover:text-[#006565]" style="font-family:'Anuphan',sans-serif;">
                {{ b.schedule?.trip?.title || 'การจอง' }}
              </h2>
              <span class="px-2.5 py-1 text-xs font-bold rounded-[8px] shrink-0 flex items-center gap-1.5 whitespace-nowrap"
                :class="statusClass(b.status)"
                style="font-family:'Anuphan',sans-serif;">
                <span class="w-1.5 h-1.5 rounded-full" :class="statusDotClass(b.status)"></span>
                {{ statusLabel(b.status) }}
              </span>
            </div>

            <div class="space-y-3 mb-5 bg-[#F9FAFA] p-3.5 rounded-[16px] border border-[#E8EEEF]">
              <div class="flex items-center justify-between text-[13px] text-[#505E5E]">
                <div class="flex items-center gap-2.5" style="font-family:'Anuphan',sans-serif;">
                  <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center border border-[#E8EEEF] shrink-0">
                    <span class="material-symbols-rounded text-[16px] text-[#006565]">calendar_month</span>
                  </div>
                  <span class="font-medium text-[#1a1c1c]">{{ formatDate(b.schedule?.departure_date) }}</span>
                </div>
                <div class="text-right shrink-0" style="font-family:'Anuphan',sans-serif;">
                  <span class="text-[10px] text-[#889696] font-bold block mb-0.5 uppercase tracking-wider">หมายเลขการจอง</span>
                  <span class="font-bold text-[#1a1c1c]">{{ b.booking_ref }}</span>
                </div>
              </div>
            </div>

            <div class="flex justify-between items-end mb-5" style="font-family:'Anuphan',sans-serif;">
              <div class="text-[11px] font-bold text-[#889696] uppercase tracking-wide">ยอดชำระ</div>
              <div class="text-xl md:text-2xl font-bold text-[#006565] tracking-tight">
                <span class="text-sm text-[#006565] mr-0.5">฿</span>{{ Number(b.total_amount).toLocaleString() }}
              </div>
            </div>

            <!-- Actions -->
            <div class="mt-auto flex gap-2.5 flex-wrap sm:flex-nowrap">
              <router-link
                :to="`/confirmation/${b.booking_ref}`"
                class="flex-1 text-center bg-[#006565] text-white py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#004f4f] transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span v-if="b.status === 'confirmed'" class="material-symbols-rounded text-[18px]">confirmation_number</span>
                <span v-else class="material-symbols-rounded text-[18px]">visibility</span>
                {{ b.status === 'confirmed' ? 'ดาวน์โหลดตั๋ว' : 'ดูรายละเอียด' }}
              </router-link>
              
              <button
                v-if="b.status === 'confirmed' || b.status === 'pending'"
                @click="$router.push(`/confirmation/${b.booking_ref}`)"
                class="flex-1 bg-white text-[#505E5E] border border-[#E8EEEF] hover:bg-[#F9FAFA] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">info</span>
                รายละเอียด
              </button>
              
              <router-link
                v-if="activeTab === 'past' && b.status === 'completed'"
                to="/my-reviews"
                class="flex-1 text-center border-2 border-[#006565] text-[#006565] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E3F2F2] transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">star</span>
                เขียนรีวิว
              </router-link>
              
              <button
                v-if="b.status === 'pending'"
                @click="handleCancel(b)"
                class="flex-1 sm:flex-none border border-[#FCA5A5] text-[#DC2626] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#FEF2F2] hover:border-[#F87171] transition-all"
                style="font-family:'Anuphan',sans-serif;">
                ยกเลิก
              </button>
            </div>
          </div>
        </article>

        <!-- Pagination -->
        <div v-if="bookingStore.meta && bookingStore.meta.last_page > 1" class="flex justify-center mt-8 gap-2">
          <button
            v-for="page in bookingStore.meta.last_page"
            :key="page"
            @click="bookingStore.fetchMyBookings(page)"
            class="w-9 h-9 rounded-[10px] text-sm font-bold transition-all duration-300"
            :class="page === bookingStore.meta.current_page
              ? 'bg-[#006565] text-white shadow-sm'
              : 'bg-white border border-[#E8EEEF] text-[#505E5E] hover:bg-[#F9FAFA]'"
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
    pending:   'bg-[#FFFAF0] text-[#D97706] border border-[#FDE68A]',
    confirmed: 'bg-[#F0FAFA] text-[#006565] border border-[#BCDFDF]',
    cancelled: 'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]',
    refunded:  'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]',
    completed: 'bg-[#EFF6FF] text-[#2563EB] border border-[#BFDBFE]',
  };
  return map[s] || 'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]';
}

function statusDotClass(s) {
  const map = {
    pending:   'bg-[#D97706]',
    confirmed: 'bg-[#006565]',
    cancelled: 'bg-[#A0B0B0]',
    refunded:  'bg-[#A0B0B0]',
    completed: 'bg-[#2563EB]',
  };
  return map[s] || 'bg-[#A0B0B0]';
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
