<template>
  <div class="min-h-screen bg-gray-50/50 flex flex-col pt-8 pb-20 relative overflow-hidden">
    
    <!-- Loading -->
    <div v-if="loading" class="grow flex items-center justify-center relative z-10">
      <div class="flex flex-col items-center gap-4">
        <div class="w-14 h-14 rounded-full border-4 border-teal-100 border-t-teal-600 animate-spin"></div>
        <p class="text-sm font-bold text-gray-500 font-anuphan animate-pulse">กำลังโหลดข้อมูลการจอง...</p>
      </div>
    </div>

    <!-- Success State -->
    <main v-else-if="booking" class="grow flex items-center justify-center px-4 py-8 relative z-10">
      <div class="max-w-4xl w-full">

        <!-- Hero Header -->
        <div class="text-center mb-12">
          <div class="mb-6"
            :class="{
              'text-teal-500': booking.status === 'confirmed',
              'text-amber-500': booking.status === 'pending',
              'text-red-500': booking.status === 'cancelled',
              'text-blue-500': booking.status === 'refunded',
            }">
            <span class="material-symbols-rounded text-[120px]" style="font-variation-settings:'FILL' 1,'wght' 400">
              {{ { confirmed: 'check_circle', pending: 'schedule', cancelled: 'cancel', refunded: 'currency_exchange' }[booking.status] ?? 'info' }}
            </span>
          </div>
          <h1 class="text-3xl md:text-5xl font-extrabold tracking-tight mb-4 font-anuphan"
            :class="{
              'text-teal-700': booking.status === 'confirmed',
              'text-amber-600': booking.status === 'pending',
              'text-red-600': booking.status === 'cancelled',
              'text-blue-600': booking.status === 'refunded',
            }">
            {{ { confirmed: 'การจองเสร็จสมบูรณ์!', pending: 'รอการยืนยัน', cancelled: 'การจองถูกยกเลิก', refunded: 'คืนเงินเรียบร้อย' }[booking.status] ?? booking.status }}
          </h1>
          <p class="text-gray-500 text-base md:text-lg max-w-md mx-auto font-medium font-anuphan">
            {{ {
              confirmed: 'ขอบคุณที่เลือกใช้บริการ เราได้รับการจองของคุณแล้ว',
              pending: 'กรุณารอการชำระเงินเพื่อยืนยันการจอง',
              cancelled: 'การจองนี้ถูกยกเลิกแล้ว หากมีข้อสงสัยกรุณาติดต่อเจ้าหน้าที่',
              refunded: 'การคืนเงินดำเนินการเรียบร้อยแล้ว กรุณาตรวจสอบบัญชีของคุณ',
            }[booking.status] ?? '' }}
          </p>
        </div>

        <!-- Booking Summary Card -->
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden mb-8 transition-transform hover:shadow-md duration-300 font-anuphan">
          <div class="flex flex-col md:flex-row">

            <!-- Trip Image -->
            <div class="w-full md:w-[40%] h-56 md:h-auto overflow-hidden shrink-0 relative">
              <img
                v-if="booking.schedule?.trip?.image_url"
                :src="booking.schedule.trip.image_url"
                :alt="booking.schedule.trip.title"
                class="w-full h-full object-cover"
              />
              <div v-else class="w-full h-full bg-teal-600 flex items-center justify-center">
                <span class="material-symbols-rounded text-white/30 text-[80px]" style="font-variation-settings:'FILL' 0,'wght' 300">explore</span>
              </div>
            </div>

            <!-- Card Content -->
            <div class="p-6 md:p-8 flex flex-col justify-between grow relative">

              <!-- Trip Title & Booking Ref -->
              <div class="flex flex-col sm:flex-row justify-between items-start gap-4 mb-6">
                <div>
                  <span class="text-[11px] font-bold tracking-widest text-teal-700 bg-teal-50 border border-teal-100 px-3 py-1.5 rounded-full mb-3 inline-block uppercase">
                    กิจกรรมที่จอง
                  </span>
                  <h2 class="text-xl md:text-2xl font-bold text-gray-900 leading-snug">
                    {{ booking.schedule?.trip?.title }}
                  </h2>
                </div>
                <div class="text-left sm:text-right shrink-0 bg-gray-50 px-4 py-2 rounded-xl border border-gray-100">
                  <span class="text-[11px] text-gray-500 font-bold block mb-0.5 uppercase tracking-wider">รหัสการจอง</span>
                  <span class="font-mono font-bold text-teal-700 text-base">{{ booking.booking_ref }}</span>
                </div>
              </div>

              <!-- Info Grid -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-5 gap-x-4 mb-8">
                <div class="flex items-start gap-3">
                  <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                    <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">calendar_today</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-gray-500 mb-0.5">วันที่เดินทาง</p>
                    <p class="font-bold text-sm text-gray-900">{{ formatDate(booking.schedule?.departure_date) }}</p>
                  </div>
                </div>
                <div class="flex items-start gap-3">
                  <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                    <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">payments</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-gray-500 mb-0.5">ราคาสุทธิ</p>
                    <p class="font-bold text-sm text-teal-700">฿{{ Number(booking.total_amount).toLocaleString() }}</p>
                  </div>
                </div>
                <div v-if="booking.seats?.length" class="flex items-start gap-3">
                  <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                    <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">event_seat</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-gray-500 mb-0.5">ที่นั่ง</p>
                    <p class="font-bold text-sm text-gray-900">{{ booking.seats.map(s => s.seat_id).join(', ') }}</p>
                  </div>
                </div>
                <div v-if="booking.pickup_region" class="flex items-start gap-3">
                  <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                    <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">location_on</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-gray-500 mb-0.5">จุดขึ้นรถ</p>
                    <p class="font-bold text-sm text-gray-900">{{ pickupLabel }}</p>
                  </div>
                </div>
                <div class="flex items-start gap-3">
                  <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                    <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">group</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-gray-500 mb-0.5">จำนวนผู้โดยสาร</p>
                    <p class="font-bold text-sm text-gray-900">{{ booking.passengers?.length || 0 }} คน</p>
                  </div>
                </div>
              </div>

              <!-- Status Bar -->
              <div class="pt-5 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2 font-bold px-3 py-1.5 rounded-lg w-fit"
                  :class="{
                    'bg-teal-50 text-teal-700': booking.status === 'confirmed',
                    'bg-amber-50 text-amber-600': booking.status === 'pending',
                    'bg-red-50 text-red-600': booking.status === 'cancelled',
                    'bg-blue-50 text-blue-600': booking.status === 'refunded',
                  }">
                  <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400">
                    {{ { confirmed: 'verified', pending: 'schedule', cancelled: 'cancel', refunded: 'currency_exchange' }[booking.status] ?? 'info' }}
                  </span>
                  <span class="text-sm">{{ statusLabel }}</span>
                </div>
                <div v-if="booking.paid_at" class="text-xs font-medium text-gray-500 bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100">
                  ชำระเมื่อ {{ new Date(booking.paid_at).toLocaleString('th-TH') }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- QR Code Ticket -->
        <div v-if="booking.qr_code && booking.status === 'confirmed'" class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 mb-8 text-center relative overflow-hidden font-anuphan">
          
          <h3 class="font-bold text-gray-900 text-lg mb-6 flex items-center justify-center gap-2 relative z-10">
            <span class="material-symbols-rounded text-teal-600 text-[24px]" style="font-variation-settings:'FILL' 0,'wght' 400">qr_code_2</span>
            ตั๋ว QR Code สำหรับเช็คอิน
          </h3>
          <div class="inline-block p-5 bg-white border-2 border-dashed border-teal-200 rounded-3xl mb-5 shadow-sm relative z-10 group transition-all hover:border-teal-400">
            <canvas ref="qrCanvas" class="mx-auto" style="image-rendering:pixelated"></canvas>
          </div>
          <p class="text-base font-mono font-bold text-teal-700 mb-2 relative z-10 bg-teal-50 inline-block px-4 py-1.5 rounded-lg border border-teal-100">{{ booking.qr_code }}</p>
          <p class="text-sm font-medium text-gray-500 mb-6 relative z-10">แสดง QR Code นี้เมื่อเช็คอินที่จุดนัดพบ</p>
          
          <div v-if="booking.checked_in" class="inline-flex items-center gap-2 px-5 py-3 bg-green-50 border border-green-200 rounded-2xl text-green-700 text-sm font-bold relative z-10 shadow-sm">
            <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">check_circle</span>
            เช็คอินแล้วเมื่อ {{ new Date(booking.checked_in_at).toLocaleString('th-TH') }}
          </div>
        </div>

        <!-- Group Booking Info -->
        <div v-if="booking.is_group && booking.group_name" class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8 mb-8 font-anuphan">
          <h3 class="font-bold text-gray-900 text-lg mb-6 flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center border border-teal-100 shadow-sm">
              <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">groups</span>
            </div>
            ข้อมูลกลุ่ม
          </h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 bg-gray-50 p-5 rounded-2xl border border-gray-100">
            <div>
              <p class="text-xs font-bold text-gray-500 mb-1">ชื่อกลุ่ม</p>
              <p class="font-bold text-sm text-gray-900">{{ booking.group_name }}</p>
            </div>
            <div v-if="booking.group_notes">
              <p class="text-xs font-bold text-gray-500 mb-1">หมายเหตุ</p>
              <p class="font-bold text-sm text-gray-900">{{ booking.group_notes }}</p>
            </div>
          </div>
        </div>

        <!-- Passengers List -->
        <div v-if="booking.passengers?.length" class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8 mb-10 font-anuphan">
          <h3 class="font-bold text-gray-900 text-lg mb-6 flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center border border-teal-100 shadow-sm">
              <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">people</span>
            </div>
            รายชื่อผู้โดยสาร
          </h3>
          <ul class="space-y-3">
            <li v-for="(p, i) in booking.passengers" :key="i"
              class="flex items-center gap-4 text-sm p-4 bg-gray-50/50 border border-gray-100 rounded-2xl hover:bg-gray-50 transition-colors">
              <span class="w-10 h-10 bg-teal-600 text-white shadow-sm shadow-teal-600/20 rounded-xl flex items-center justify-center text-sm font-bold shrink-0">
                {{ i + 1 }}
              </span>
              <div class="flex flex-col">
                <span class="font-bold text-gray-900">{{ p.name }}</span>
                <span v-if="p.phone" class="text-xs font-medium text-gray-500 flex items-center gap-1 mt-0.5">
                  <span class="material-symbols-rounded text-[14px]">call</span>
                  {{ p.phone }}
                </span>
              </div>
              <span v-if="booking.seats?.[i]" class="ml-auto text-sm font-bold text-teal-700 bg-teal-50 border border-teal-100 px-3 py-1.5 rounded-xl flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[16px]">airline_seat_recline_extra</span>
                {{ booking.seats[i].seat_id }}
              </span>
            </li>
          </ul>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center font-anuphan">
          <router-link to="/my-bookings"
            class="flex-1 sm:flex-none px-10 py-4 bg-teal-600 text-white rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 flex items-center justify-center gap-2">
            <span>ดูการจองของฉัน</span>
            <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_forward</span>
          </router-link>
          <router-link to="/trips"
            class="flex-1 sm:flex-none px-10 py-4 bg-white border-2 border-gray-200 text-gray-700 rounded-2xl font-bold text-base hover:bg-gray-50 hover:border-gray-300 active:scale-95 transition-all duration-300 flex items-center justify-center gap-2 shadow-sm">
            <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">explore</span>
            <span>ค้นหากิจกรรมเพิ่มเติม</span>
          </router-link>
        </div>

        <!-- Help Footer -->
        <div class="mt-16 pt-8 text-center border-t border-gray-200/60 font-anuphan">
          <p class="text-sm font-medium text-gray-500 mb-4">มีคำถามเกี่ยวกับการเดินทางของคุณ?</p>
          <div class="flex justify-center gap-8">
            <a href="#" class="text-teal-600 font-bold text-sm hover:text-teal-700 hover:underline underline-offset-4 flex items-center gap-1.5 transition-colors">
              <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">support_agent</span>
              ติดต่อฝ่ายบริการลูกค้า
            </a>
          </div>
        </div>

      </div>
    </main>

    <!-- Not Found -->
    <div v-else class="grow flex items-center justify-center text-center py-16 relative z-10 font-anuphan">
      <div class="bg-white p-10 rounded-3xl shadow-sm border border-gray-100 flex flex-col items-center">
        <span class="material-symbols-rounded text-gray-300 mb-4 block text-[64px]" style="font-variation-settings:'FILL' 0,'wght' 300">sentiment_dissatisfied</span>
        <p class="text-gray-900 font-bold text-xl mb-2">ไม่พบข้อมูลการจอง</p>
        <p class="text-gray-500 text-sm mb-6">รหัสการจองอาจไม่ถูกต้อง หรือถูกลบออกจากระบบ</p>
        <router-link to="/trips" class="bg-teal-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-teal-700 transition-colors shadow-sm shadow-teal-600/20">
          กลับไปหน้ากิจกรรม
        </router-link>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import QRCode from 'qrcode';

const route = useRoute();
const booking = ref(null);
const loading = ref(true);
const qrCanvas = ref(null);

const statusMap = { pending: 'รอชำระเงิน', confirmed: 'ยืนยันแล้ว', cancelled: 'ยกเลิกแล้ว', refunded: 'คืนเงินแล้ว' };
const statusLabel = computed(() => statusMap[booking.value?.status] || booking.value?.status);

const pickupLabel = computed(() => {
  const region = booking.value?.pickup_region;
  if (!region) return '';
  const pts = booking.value?.schedule?.pickup_points || [];
  const pt = pts.find(p => p.region === region);
  return pt ? `${pt.region_label} — ${pt.pickup_location}` : region;
});

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

async function renderQrCode() {
  await nextTick();
  if (qrCanvas.value && booking.value?.qr_code) {
    try {
      await QRCode.toCanvas(qrCanvas.value, booking.value.qr_code, {
        width: 200,
        margin: 2,
        color: { dark: '#006565', light: '#ffffff' },
      });
    } catch (e) {
      console.error('QR render error:', e);
    }
  }
}

watch(() => booking.value?.qr_code, (val) => {
  if (val) renderQrCode();
});

onMounted(async () => {
  try {
    const res = await api.get(`/bookings/${route.params.bookingRef}`);
    booking.value = res.data.data;
    if (booking.value?.qr_code) {
      renderQrCode();
    }
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
});
</script>
