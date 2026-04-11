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

            <button @click="paymentMethod = 'promptpay'"
              class="flex flex-col items-center justify-center gap-2.5 p-4 border-2 rounded-xl transition-all h-full"
              :class="paymentMethod === 'promptpay'
                ? 'border-[#006565] bg-[#006565]/5 text-[#006565]'
                : 'border-transparent bg-[#f3f3f3] hover:bg-[#e8e8e8] text-[#3e4949]'">
              <img src="/images/qr_promptpay.png" alt="พร้อมเพย์" class="h-32 w-auto object-contain" />
              <span class="font-bold text-[16px] uppercase tracking-tight">QR Code PromptPay</span>
            </button>
            <button @click="paymentMethod = 'mobile_banking'"
              class="flex flex-col items-center justify-center gap-2.5 p-4 border-2 rounded-xl transition-all h-full"
              :class="paymentMethod === 'mobile_banking'
                ? 'border-[#006565] bg-[#006565]/5 text-[#006565]'
                : 'border-transparent bg-[#f3f3f3] hover:bg-[#e8e8e8] text-[#3e4949]'">
              <img src="/images/pay_bank.png" alt="โมบายแบงก์กิ้ง" class="h-32 w-auto object-contain" />
              <span class="font-bold text-[16px] uppercase tracking-tight">โอนเงินผ่านบัญชีธนาคาร</span>
            </button>
          </div>
          <!-- PromptPay -->
          <div v-if="paymentMethod === 'promptpay'" class="space-y-6">
            <!-- QR Section -->
            <div class="flex flex-col items-center gap-4 py-6">
              <p class="text-sm text-[#6e7979]">สแกน QR ชำระเงินผ่าน Mobile Banking ได้ทุกธนาคาร</p>
              <div class="relative p-3 bg-white rounded-2xl shadow-[0_4px_20px_rgba(0,101,101,0.12)] border border-[#b4eae9]">
                <canvas ref="qrCanvas" class="block rounded-xl"></canvas>
                <div v-if="!qrGenerated" class="absolute inset-0 flex items-center justify-center">
                  <div class="w-8 h-8 rounded-full border-4 border-[#b4eae9] border-t-[#006565] animate-spin"></div>
                </div>
              </div>
              <p v-if="booking" class="text-xs text-[#6e7979]">
                เบอร์พร้อมเพย์: <span class="font-semibold text-[#006565]">062-612-6006</span>
              </p>
              <button v-if="qrGenerated" @click="saveQR"
                class="flex items-center gap-2 px-5 py-2.5 bg-[#006565] text-white text-m font-semibold rounded-full hover:bg-[#004f4f] active:scale-95 transition-all shadow-md shadow-[#006565]/20">
                บันทึก QR Code
              </button>
            </div>

            <!-- Divider -->
            <div class="flex items-center gap-3">
              <div class="flex-1 h-px bg-[#e2e2e2]"></div>
              <span class="text-xs text-[#6e7979] font-medium">หลังโอนแล้ว อัปโหลดหลักฐาน</span>
              <div class="flex-1 h-px bg-[#e2e2e2]"></div>
            </div>

            <!-- Slip Upload -->
            <div class="space-y-3">
              <label class="block text-sm font-semibold text-[#1a1c1c]">อัปโหลดสลิปการโอนเงิน</label>
              <div v-if="!slipPreview"
                @click="slipInputRef?.click()"
                class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-[#b4eae9] rounded-2xl p-8 cursor-pointer hover:border-[#006565] hover:bg-[#006565]/5 transition-all">
                <span class="material-symbols-outlined text-4xl text-[#b4eae9]">อัปโหลดสลิป</span>
                <p class="text-sm text-[#6e7979]">คลิกหรือลากไฟล์รูปสลิปมาวางที่นี่</p>
                <p class="text-xs text-[#9eadad]">รองรับ JPG, PNG ขนาดไม่เกิน 5MB</p>
              </div>
              <div v-else class="relative rounded-2xl overflow-hidden border border-[#b4eae9]">
                <img :src="slipPreview" alt="slip" class="w-full max-h-64 object-contain bg-[#f9f9f9]" />
                <button @click="removeSlip"
                  class="absolute top-2 right-2 w-8 h-8 bg-white/90 rounded-full flex items-center justify-center shadow hover:bg-red-50 transition-colors">
                  <span class="material-symbols-outlined text-[18px] text-red-500">close</span>
                </button>
              </div>
              <input ref="slipInputRef" type="file" accept="image/*" class="hidden" @change="onSlipChange" />
            </div>

            <!-- Transfer Datetime -->
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-[#1a1c1c]">วันที่โอน</label>
                <input v-model="transferDate" type="date"
                  class="w-full px-4 py-2.5 rounded-xl border border-[#e2e2e2] bg-white text-sm text-[#1a1c1c] focus:outline-none focus:border-[#006565] focus:ring-2 focus:ring-[#006565]/20 transition" />
              </div>
              <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-[#1a1c1c]">เวลาที่โอน</label>
                <input v-model="transferTime" type="time"
                  class="w-full px-4 py-2.5 rounded-xl border border-[#e2e2e2] bg-white text-sm text-[#1a1c1c] focus:outline-none focus:border-[#006565] focus:ring-2 focus:ring-[#006565]/20 transition" />
              </div>
            </div>
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
            <img v-if="booking.schedule?.trip?.cover_image || booking.schedule?.trip?.thumbnail_url"
              :src="booking.schedule.trip.cover_image || booking.schedule.trip.thumbnail_url"
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
import { ref, onMounted, watch, nextTick } from 'vue';
import QRCode from 'qrcode';
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
const paymentMethod = ref('promptpay');
const cardNumber = ref('4242424242424242');
const cardHolder = ref('');
const cardExpiry = ref('12/28');
const cardCvv = ref('123');
const saveCard = ref(false);

// PromptPay QR
const qrCanvas = ref(null);
const qrGenerated = ref(false);

// Slip upload
const slipFile = ref(null);
const slipPreview = ref(null);
const slipInputRef = ref(null);

// Transfer datetime
const transferDate = ref('');
const transferTime = ref('');

function buildPromptPayPayload(phone, amount) {
  const normalizePhone = (p) => {
    p = p.replace(/\D/g, '');
    if (p.startsWith('0')) p = '66' + p.slice(1);
    return p;
  };
  const normalized = normalizePhone(phone);
  const tag = (id, value) => {
    const len = value.length.toString().padStart(2, '0');
    return `${id}${len}${value}`;
  };
  const merchantAccInfo = tag('00', 'A000000677010111') + tag('01', normalized);
  const merchantInfo = tag('29', merchantAccInfo);
  const amtStr = amount.toFixed(2);
  let payload =
    tag('00', '01') +
    tag('01', '12') +
    merchantInfo +
    tag('53', '764') +
    tag('54', amtStr) +
    tag('58', 'TH') +
    tag('62', tag('07', 'LUILAYKHAO')) +
    '6304';
  const crc = crc16(payload);
  return payload + crc;
}

function crc16(str) {
  let crc = 0xffff;
  for (let i = 0; i < str.length; i++) {
    crc ^= str.charCodeAt(i) << 8;
    for (let j = 0; j < 8; j++) {
      crc = crc & 0x8000 ? (crc << 1) ^ 0x1021 : crc << 1;
    }
  }
  return ((crc & 0xffff).toString(16).toUpperCase()).padStart(4, '0');
}

async function generateQR() {
  await nextTick();
  if (!qrCanvas.value || !booking.value) return;
  const amount = parseFloat(booking.value.total_amount);
  const payload = buildPromptPayPayload('0626126006', amount);
  await QRCode.toCanvas(qrCanvas.value, payload, {
    width: 240,
    margin: 2,
    color: { dark: '#006565', light: '#ffffff' },
  });
  qrGenerated.value = true;
}

watch(paymentMethod, (val) => {
  if (val === 'promptpay') nextTick(generateQR);
});

function saveQR() {
  if (!qrCanvas.value) return;
  const link = document.createElement('a');
  link.download = 'promptpay-qr.png';
  link.href = qrCanvas.value.toDataURL('image/png');
  link.click();
}

function onSlipChange(e) {
  const file = e.target.files[0];
  if (!file) return;
  slipFile.value = file;
  const reader = new FileReader();
  reader.onload = (ev) => { slipPreview.value = ev.target.result; };
  reader.readAsDataURL(file);
}

function removeSlip() {
  slipFile.value = null;
  slipPreview.value = null;
  if (slipInputRef.value) slipInputRef.value.value = '';
}

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
  if (paymentMethod.value === 'promptpay') {
    await nextTick();
    generateQR();
  }
});
</script>
