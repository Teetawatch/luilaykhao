<template>
  <!-- Loading -->
  <div v-if="loading" class="flex items-center justify-center min-h-[60vh]">
    <div class="flex flex-col items-center gap-4">
      <div class="w-12 h-12 rounded-full border-4 border-[#b4eae9] border-t-[#006565] animate-spin"></div>
      <p class="text-[#6e7979] font-['Anuphan']">กำลังโหลด...</p>
    </div>
  </div>

  <!-- No booking -->
  <div v-else-if="!booking" class="flex flex-col items-center justify-center min-h-[60vh] text-[#6e7979] font-['Anuphan']">
    <span class="material-symbols-outlined text-6xl mb-4 text-[#bdc9c8]">sentiment_dissatisfied</span>
    <p class="text-lg">ไม่พบข้อมูลการจอง</p>
  </div>

  <!-- Main Content -->
  <div v-else class="font-['Anuphan'] bg-[#f9f9f9] min-h-screen pt-8 pb-24 px-4 md:px-8 lg:px-12">
    <!-- Progress Stepper -->
    <div class="flex items-center justify-center mb-12 max-w-7xl mx-auto">
      <div class="flex items-center w-full max-w-2xl">
        <!-- Step 1 -->
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#b4eae9] text-[#376b6a] flex items-center justify-center mb-2 font-bold text-sm">1</div>
          <span class="text-xs font-medium text-[#6e7979]">เลือกทริป</span>
        </div>
        <div class="h-[2px] flex-1 bg-[#b4eae9]"></div>
        <!-- Step 2 -->
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#b4eae9] text-[#376b6a] flex items-center justify-center mb-2 font-bold text-sm">2</div>
          <span class="text-xs font-medium text-[#6e7979]">รายละเอียด</span>
        </div>
        <div class="h-[2px] flex-1 bg-[#006565]"></div>
        <!-- Step 3 Active -->
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#006565] text-white flex items-center justify-center mb-2 font-bold text-sm ring-4 ring-[#93f2f2]/40">3</div>
          <span class="text-xs font-bold text-[#006565]">ชำระเงิน</span>
        </div>
      </div>
    </div>

    <!-- Countdown -->
    <div v-if="seatsStore.countdownSeconds > 0" class="max-w-7xl mx-auto mb-6">
      <CountdownTimer :seconds="seatsStore.countdownSeconds" />
    </div>

    <!-- Two-column layout -->
    <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-10 items-start">

      <!-- LEFT: Payment Form -->
      <div class="lg:col-span-8 space-y-6">

        <!-- Payment Method Tabs -->
        <section class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,101,101,0.08)] p-8">
          <h1 class="text-2xl font-bold mb-7 text-[#1a1c1c]">วิธีการชำระเงิน</h1>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8">
            <button @click="paymentMethod = 'credit_card'"
              class="flex flex-col items-center gap-2.5 p-5 border-2 rounded-xl transition-all"
              :class="paymentMethod === 'credit_card'
                ? 'border-[#006565] bg-[#006565]/5 text-[#006565]'
                : 'border-transparent bg-[#f3f3f3] hover:bg-[#e8e8e8] text-[#3e4949]'">
              <span class="font-medium text-sm">บัตรเครดิต/เดบิต</span>
            </button>
            <button @click="paymentMethod = 'promptpay'"
              class="flex flex-col items-center gap-2.5 p-5 border-2 rounded-xl transition-all"
              :class="paymentMethod === 'promptpay'
                ? 'border-[#006565] bg-[#006565]/5 text-[#006565]'
                : 'border-transparent bg-[#f3f3f3] hover:bg-[#e8e8e8] text-[#3e4949]'">
              <span class="font-medium text-sm">พร้อมเพย์ (PromptPay)</span>
            </button>
            <button @click="paymentMethod = 'mobile_banking'"
              class="flex flex-col items-center gap-2.5 p-5 border-2 rounded-xl transition-all"
              :class="paymentMethod === 'mobile_banking'
                ? 'border-[#006565] bg-[#006565]/5 text-[#006565]'
                : 'border-transparent bg-[#f3f3f3] hover:bg-[#e8e8e8] text-[#3e4949]'">
              <span class="font-medium text-sm">โมบายแบงก์กิ้ง</span>
            </button>
          </div>

          <!-- Credit/Debit Card Form -->
          <div v-if="paymentMethod === 'credit_card'" class="space-y-5">
            <div class="space-y-1.5">
              <label class="block text-sm font-medium text-[#3e4949] ml-0.5">หมายเลขบัตร</label>
              <div class="relative">
                <input v-model="cardNumber" type="text" placeholder="0000 0000 0000 0000"
                  class="w-full px-4 py-3.5 bg-[#f3f3f3] border-none rounded-xl focus:ring-2 focus:ring-[#006565]/20 outline-none text-base text-[#1a1c1c] placeholder:text-[#bdc9c8]" />
                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-[#bdc9c8]">credit_card</span>
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="block text-sm font-medium text-[#3e4949] ml-0.5">ชื่อผู้ถือบัตร</label>
              <input v-model="cardHolder" type="text" placeholder="ชื่อภาษาอังกฤษตามหน้าบัตร"
                class="w-full px-4 py-3.5 bg-[#f3f3f3] border-none rounded-xl focus:ring-2 focus:ring-[#006565]/20 outline-none text-base text-[#1a1c1c] placeholder:text-[#bdc9c8]" />
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <label class="block text-sm font-medium text-[#3e4949] ml-0.5">วันหมดอายุ</label>
                <input v-model="cardExpiry" type="text" placeholder="ดด/ปป"
                  class="w-full px-4 py-3.5 bg-[#f3f3f3] border-none rounded-xl focus:ring-2 focus:ring-[#006565]/20 outline-none text-base text-[#1a1c1c] placeholder:text-[#bdc9c8]" />
              </div>
              <div class="space-y-1.5">
                <label class="block text-sm font-medium text-[#3e4949] ml-0.5">CVV</label>
                <input v-model="cardCvv" type="password" placeholder="123"
                  class="w-full px-4 py-3.5 bg-[#f3f3f3] border-none rounded-xl focus:ring-2 focus:ring-[#006565]/20 outline-none text-base text-[#1a1c1c] placeholder:text-[#bdc9c8]" />
              </div>
            </div>
            <div class="flex items-center gap-3 py-2">
              <input id="save-card" type="checkbox" v-model="saveCard"
                class="w-5 h-5 rounded border-[#bdc9c8] text-[#006565] focus:ring-[#006565]" />
              <label for="save-card" class="text-sm text-[#6e7979]">บันทึกข้อมูลบัตรไว้สำหรับการจองครั้งถัดไป</label>
            </div>
          </div>

          <!-- PromptPay -->
          <div v-else-if="paymentMethod === 'promptpay'" class="text-center py-8 space-y-4">
            <p class="text-[#3e4949] font-medium">QR Code PromptPay จะแสดงหลังกดชำระเงิน</p>
            <p class="text-sm text-[#6e7979]">สแกนจ่ายผ่าน Mobile Banking ได้ทุกธนาคาร</p>
            <p class="text-xs text-[#6e7979] bg-[#f3f3f3] rounded-xl px-4 py-2 inline-block">
              * ในโหมดทดสอบ จะจำลองการชำระเงินสำเร็จโดยอัตโนมัติ
            </p>
          </div>

          <!-- Mobile Banking -->
          <div v-else class="text-center py-8 space-y-4">
            <p class="text-[#3e4949] font-medium">ระบบจะแสดงรายการธนาคารหลังกดชำระเงิน</p>
            <p class="text-xs text-[#6e7979] bg-[#f3f3f3] rounded-xl px-4 py-2 inline-block">
              * ในโหมดทดสอบ จะจำลองการชำระเงินสำเร็จโดยอัตโนมัติ
            </p>
          </div>
        </section>

        <!-- Security Badge -->
        <div class="flex items-center gap-4 p-5 bg-[#93f2f2]/20 rounded-2xl border border-[#93f2f2]/40">
          <p class="text-sm text-[#3e4949] leading-relaxed">
            ข้อมูลการชำระเงินของคุณได้รับการคุ้มครองด้วยเทคโนโลยีการเข้ารหัสความปลอดภัยระดับสากลสูงสุด (SSL Encryption)
          </p>
        </div>

      </div>

      <!-- RIGHT: Booking Summary -->
      <aside class="lg:col-span-4 lg:sticky lg:top-24">
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,101,101,0.08)] overflow-hidden">

          <!-- Trip image -->
          <div class="h-44 relative overflow-hidden bg-[#b4eae9]">
            <img v-if="booking.schedule?.trip?.thumbnail_url"
              :src="booking.schedule.trip.thumbnail_url"
              :alt="booking.schedule?.trip?.title"
              class="w-full h-full object-cover" />
            <div v-else class="w-full h-full flex items-center justify-center">
            </div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent flex items-end p-5">
              <span class="bg-[#93f2f2] text-[#002020] text-xs font-bold px-3 py-1 rounded-full tracking-wide">
                รหัสจอง: {{ booking.booking_ref }}
              </span>
            </div>
          </div>

          <!-- Summary details -->
          <div class="p-7">
            <h2 class="text-lg font-bold mb-5 text-[#1a1c1c]">สรุปการจอง</h2>

            <div class="space-y-4 mb-6">
              <div class="flex gap-3">
                <div>
                  <p class="font-bold text-[#1a1c1c] leading-snug">{{ booking.schedule?.trip?.title }}</p>
                  <p class="text-sm text-[#6e7979] mt-0.5">{{ booking.passengers?.length || 0 }} ที่นั่ง</p>
                </div>
              </div>
              <div class="flex gap-3 items-center">
                <p class="text-sm text-[#3e4949]">{{ formatDate(booking.schedule?.departure_date) }}</p>
              </div>
              <div v-if="booking.seats?.length" class="flex gap-3 items-center">
                <p class="text-sm text-[#3e4949]">ที่นั่ง: {{ booking.seats.map(s => s.seat_id).join(', ') }}</p>
              </div>
            </div>

            <!-- Price breakdown -->
            <div class="space-y-2.5 py-5 border-y border-[#e2e2e2] mb-5">
              <div class="flex justify-between text-sm text-[#6e7979]">
                <span>ราคาต่อที่นั่ง × {{ booking.passengers?.length || 0 }}</span>
                <span>฿{{ Number(booking.total_amount).toLocaleString() }}</span>
              </div>
              <div class="flex justify-between text-sm text-[#6e7979]">
                <span>ค่าบริการ</span>
                <span>ฟรี</span>
              </div>
            </div>

            <div class="flex justify-between items-end mb-7">
              <span class="font-bold text-[#1a1c1c]">ยอดชำระสุทธิ</span>
              <span class="text-3xl font-extrabold text-[#9e380d]">฿{{ Number(booking.total_amount).toLocaleString() }}</span>
            </div>

            <!-- Pay Button -->
            <button @click="processPayment" :disabled="paying"
              class="w-full bg-[#006565] text-white py-4 rounded-full font-bold text-base flex items-center justify-center gap-2.5 hover:bg-[#004f4f] active:scale-95 transition-all shadow-lg shadow-[#006565]/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100">
              <span v-if="paying" class="w-5 h-5 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
              {{ paying ? 'กำลังประมวลผล...' : 'ชำระเงินตอนนี้' }}
            </button>

            <p v-if="paymentError" class="mt-4 text-sm text-red-600 text-center flex items-center justify-center gap-1.5">
              <span class="material-symbols-outlined text-base">error</span>
              {{ paymentError }}
            </p>

            <p class="text-center mt-5 text-xs text-[#6e7979] leading-relaxed px-2">
              โดยการคลิกชำระเงิน แสดงว่าคุณยอมรับ
              <a href="#" class="text-[#006565] underline decoration-[#006565]/30">ข้อกำหนดและเงื่อนไข</a>
              ของเรา
            </p>
          </div>
        </div>
      </aside>

    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useBookingStore } from '../stores/booking';
import { useSeatsStore } from '../stores/seats';
import CountdownTimer from '../components/CountdownTimer.vue';

const route = useRoute();
const router = useRouter();
const bookingStore = useBookingStore();
const seatsStore = useSeatsStore();

const booking = ref(null);
const loading = ref(true);
const paying = ref(false);
const paymentError = ref('');
const paymentMethod = ref('credit_card');
const cardNumber = ref('4242424242424242');
const cardHolder = ref('');
const cardExpiry = ref('12/28');
const cardCvv = ref('123');
const saveCard = ref(false);

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

async function processPayment() {
  paying.value = true;
  paymentError.value = '';
  try {
    await bookingStore.chargePayment({
      booking_ref: booking.value.booking_ref,
      omise_token: 'tokn_test_' + Date.now(),
      amount: parseFloat(booking.value.total_amount),
    });
    router.push(`/confirmation/${booking.value.booking_ref}`);
  } catch (e) {
    paymentError.value = e?.response?.data?.message || 'การชำระเงินล้มเหลว กรุณาลองใหม่';
  } finally {
    paying.value = false;
  }
}

onMounted(async () => {
  try {
    booking.value = await bookingStore.fetchBooking(route.params.bookingRef);
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
});
</script>
