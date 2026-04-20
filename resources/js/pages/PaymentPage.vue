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
    <span class="material-symbols-rounded text-6xl mb-4 text-[#bdc9c8]">sentiment_dissatisfied</span>
    <p class="text-lg">ไม่พบข้อมูลการจอง</p>
  </div>

  <!-- Main Content -->
  <div v-else class="font-['Anuphan'] bg-[#f9f9f9] min-h-screen pt-8 pb-24 px-4 md:px-8 lg:px-12">
    <!-- Progress Stepper -->
    <div class="flex items-center justify-center mb-12 max-w-7xl mx-auto">
      <div class="flex items-center w-full max-w-2xl">
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#b4eae9] text-[#376b6a] flex items-center justify-center mb-2 font-bold text-sm">1</div>
          <span class="text-xs font-medium text-[#6e7979]">เลือกทริป</span>
        </div>
        <div class="h-[2px] flex-1 bg-[#b4eae9]"></div>
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#b4eae9] text-[#376b6a] flex items-center justify-center mb-2 font-bold text-sm">2</div>
          <span class="text-xs font-medium text-[#6e7979]">รายละเอียด</span>
        </div>
        <div class="h-[2px] flex-1 bg-[#006565]"></div>
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

        <!-- ── Payment Type Selection (show only if installment available) ── -->
        <section v-if="installmentAvailable" class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,101,101,0.08)] p-8">
          <h2 class="text-lg font-bold mb-5 text-[#1a1c1c]">เลือกรูปแบบการชำระเงิน</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Full Payment -->
            <button @click="paymentType = 'full'"
              class="flex flex-col gap-2 p-5 border-2 rounded-2xl transition-all text-left"
              :class="paymentType === 'full'
                ? 'border-[#006565] bg-[#006565]/5'
                : 'border-[#e2e2e2] hover:border-[#b4eae9]'">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-[22px]" :class="paymentType === 'full' ? 'text-[#006565]' : 'text-[#6e7979]'">payments</span>
                <span class="font-bold" :class="paymentType === 'full' ? 'text-[#006565]' : 'text-[#1a1c1c]'">ชำระเต็มจำนวน</span>
                <span v-if="paymentType === 'full'" class="ml-auto w-5 h-5 rounded-full bg-[#006565] flex items-center justify-center">
                  <span class="material-symbols-rounded text-white text-[14px]">check</span>
                </span>
              </div>
              <p class="text-sm text-[#6e7979]">ชำระทั้งหมด <span class="font-semibold text-[#1a1c1c]">฿{{ Number(booking.total_amount).toLocaleString() }}</span> ในครั้งเดียว</p>
            </button>

            <!-- Installment Payment -->
            <button @click="paymentType = 'installment'"
              class="flex flex-col gap-2 p-5 border-2 rounded-2xl transition-all text-left"
              :class="paymentType === 'installment'
                ? 'border-[#e87c2a] bg-[#e87c2a]/5'
                : 'border-[#e2e2e2] hover:border-[#f5c99a]'">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-[22px]" :class="paymentType === 'installment' ? 'text-[#e87c2a]' : 'text-[#6e7979]'">calendar_month</span>
                <span class="font-bold" :class="paymentType === 'installment' ? 'text-[#e87c2a]' : 'text-[#1a1c1c]'">ผ่อนชำระ {{ installmentCount }} งวด</span>
                <span v-if="paymentType === 'installment'" class="ml-auto w-5 h-5 rounded-full bg-[#e87c2a] flex items-center justify-center">
                  <span class="material-symbols-rounded text-white text-[14px]">check</span>
                </span>
              </div>
              <p class="text-sm text-[#6e7979]">งวดละ <span class="font-semibold text-[#1a1c1c]">฿{{ perInstallment.toLocaleString() }}</span> · ทุก {{ installmentIntervalDays }} วัน</p>
            </button>
          </div>

          <!-- Installment Schedule Table -->
          <div v-if="paymentType === 'installment'" class="mt-6 space-y-4">
            <h3 class="font-semibold text-[#1a1c1c]">ตารางการผ่อนชำระ</h3>
            <div class="overflow-hidden rounded-xl border border-[#e2e2e2]">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-[#f3f9f9]">
                    <th class="text-left px-4 py-3 font-semibold text-[#1a1c1c]">งวดที่</th>
                    <th class="text-left px-4 py-3 font-semibold text-[#1a1c1c]">ครบกำหนด</th>
                    <th class="text-right px-4 py-3 font-semibold text-[#1a1c1c]">จำนวนเงิน</th>
                    <th class="text-center px-4 py-3 font-semibold text-[#1a1c1c]">สถานะ</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="inst in installmentSchedule" :key="inst.no"
                    class="border-t border-[#f0f0f0]"
                    :class="inst.no === 1 ? 'bg-[#006565]/3' : ''">
                    <td class="px-4 py-3 text-[#3e4949]">งวดที่ {{ inst.no }}</td>
                    <td class="px-4 py-3 text-[#3e4949]">{{ formatDate(inst.dueDate) }}</td>
                    <td class="px-4 py-3 text-right font-semibold text-[#1a1c1c]">฿{{ inst.amount.toLocaleString() }}</td>
                    <td class="px-4 py-3 text-center">
                      <span v-if="inst.no === 1"
                        class="text-xs font-bold px-2.5 py-1 rounded-full bg-[#006565]/10 text-[#006565]">
                        ชำระตอนนี้
                      </span>
                      <span v-else
                        class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#f3f3f3] text-[#6e7979]">
                        รอชำระ
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- No-refund Warning -->
            <div class="flex gap-3 p-4 bg-red-50 border border-red-200 rounded-xl">
              <span class="material-symbols-rounded text-red-500 text-[20px] flex-shrink-0 mt-0.5">warning</span>
              <div class="text-sm text-red-700 leading-relaxed">
                <p class="font-bold mb-1">ข้อสงวนสิทธิ์การผ่อนชำระ</p>
                <p>หากท่านไม่ชำระเงินภายในวันครบกำหนดของแต่ละงวด <strong>ทางลุยเลเขาขอสงวนสิทธิ์ไม่คืนเงินในทุกกรณี</strong> และอาจยกเลิกการจองโดยไม่แจ้งล่วงหน้า กรุณาตรวจสอบวันครบกำหนดในตารางข้างต้นและชำระให้ตรงเวลา</p>
              </div>
            </div>
          </div>
        </section>

        <!-- ── Payment Method ── -->
        <section class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,101,101,0.08)] p-8">
          <h1 class="text-2xl font-bold mb-7 text-[#1a1c1c]">วิธีการชำระเงิน</h1>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-8">
            <button @click="paymentMethod = 'promptpay'"
              class="flex flex-col items-center justify-center gap-2.5 p-4 border-2 rounded-xl transition-all h-full"
              :class="paymentMethod === 'promptpay'
                ? 'border-[#006565] bg-[#006565]/5 text-[#006565]'
                : 'border-transparent bg-[#f3f3f3] hover:bg-[#e8e8e8] text-[#3e4949]'">
              <img src="/images/qr_promptpay.webp" alt="พร้อมเพย์" class="h-32 w-auto object-contain" />
              <span class="font-bold text-[16px] uppercase tracking-tight">QR Code PromptPay</span>
            </button>
            <button @click="paymentMethod = 'mobile_banking'"
              class="flex flex-col items-center justify-center gap-2.5 p-4 border-2 rounded-xl transition-all h-full"
              :class="paymentMethod === 'mobile_banking'
                ? 'border-[#006565] bg-[#006565]/5 text-[#006565]'
                : 'border-transparent bg-[#f3f3f3] hover:bg-[#e8e8e8] text-[#3e4949]'">
              <img src="/images/pay_bank.webp" alt="โมบายแบงก์กิ้ง" class="h-32 w-auto object-contain" />
              <span class="font-bold text-[16px] uppercase tracking-tight">โอนเงินผ่านบัญชีธนาคาร</span>
            </button>
          </div>

          <!-- PromptPay QR (shown only for promptpay) -->
          <div v-if="paymentMethod === 'promptpay'" class="flex flex-col items-center gap-4 py-4">
            <p class="text-sm text-[#6e7979]">
              สแกน QR ชำระเงิน
              <template v-if="paymentType === 'installment'">
                <strong class="text-[#e87c2a]">งวดแรก ฿{{ perInstallment.toLocaleString() }}</strong>
              </template>
              <template v-else>
                <strong class="text-[#006565]">฿{{ Number(booking.total_amount).toLocaleString() }}</strong> ผ่าน Mobile Banking ได้ทุกธนาคาร
              </template>
            </p>
            <div class="relative p-3 bg-white rounded-2xl shadow-[0_4px_20px_rgba(0,101,101,0.12)] border border-[#b4eae9]">
              <canvas ref="qrCanvas" class="block rounded-xl"></canvas>
              <div v-if="!qrGenerated" class="absolute inset-0 flex items-center justify-center">
                <div class="w-8 h-8 rounded-full border-4 border-[#b4eae9] border-t-[#006565] animate-spin"></div>
              </div>
            </div>
            <p class="text-xs text-[#6e7979]">เบอร์พร้อมเพย์: <span class="font-semibold text-[#006565]">062-612-6006</span></p>
            <button v-if="qrGenerated" @click="saveQR"
              class="flex items-center gap-2 px-5 py-2 bg-[#006565] text-white text-sm font-semibold rounded-full hover:bg-[#004f4f] active:scale-95 transition-all shadow-md shadow-[#006565]/20">
              <span class="material-symbols-rounded text-[16px]">download</span> บันทึก QR Code
            </button>
          </div>

          <!-- Mobile Banking account info -->
          <div v-else class="bg-[#f3f9f9] rounded-2xl p-5 space-y-3 border border-[#b4eae9]">
            <p class="text-sm font-bold text-[#1a1c1c] flex items-center gap-2">
              <span class="material-symbols-rounded text-[#006565] text-[18px]">account_balance</span>
              ข้อมูลบัญชีสำหรับโอนเงิน
            </p>
            <div class="space-y-2 text-sm text-[#3e4949]">
              <div class="flex justify-between"><span class="text-[#6e7979]">ธนาคาร</span><span class="font-semibold">กสิกรไทย (KBank)</span></div>
              <div class="flex justify-between"><span class="text-[#6e7979]">ชื่อบัญชี</span><span class="font-semibold">ลุยเลเขา</span></div>
              <div class="flex justify-between"><span class="text-[#6e7979]">เลขที่บัญชี</span><span class="font-semibold tracking-wider">062-6-12600-6</span></div>
            </div>
            <p class="text-xs text-[#6e7979]">
              กรุณาโอนยอด
              <strong class="text-[#1a1c1c]">
                {{ paymentType === 'installment' ? `฿${perInstallment.toLocaleString()} (งวดแรก)` : `฿${Number(booking.total_amount).toLocaleString()}` }}
              </strong>
              แล้วอัปโหลดสลิปด้านล่าง
            </p>
          </div>

          <!-- Divider -->
          <div class="flex items-center gap-3 mt-6">
            <div class="flex-1 h-px bg-[#e2e2e2]"></div>
            <span class="text-xs text-[#6e7979] font-medium">อัปโหลดหลักฐานการโอนเงิน</span>
            <div class="flex-1 h-px bg-[#e2e2e2]"></div>
          </div>

          <!-- Slip Upload (always shown) -->
          <div class="mt-4 space-y-3">
            <label class="block text-sm font-semibold text-[#1a1c1c]">สลิปการโอนเงิน <span class="text-red-500">*</span></label>
            <div v-if="!slipPreview"
              @click="slipInputRef?.click()"
              class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-[#b4eae9] rounded-2xl p-8 cursor-pointer hover:border-[#006565] hover:bg-[#006565]/5 transition-all">
              <span class="material-symbols-rounded text-4xl text-[#b4eae9]">upload_file</span>
              <p class="text-sm text-[#6e7979]">คลิกหรือลากไฟล์รูปสลิปมาวางที่นี่</p>
              <p class="text-xs text-[#9eadad]">รองรับ JPG, PNG ขนาดไม่เกิน 5MB</p>
            </div>
            <div v-else class="relative rounded-2xl overflow-hidden border border-[#b4eae9]">
              <img :src="slipPreview" alt="slip" class="w-full max-h-64 object-contain bg-[#f9f9f9]" />
              <button @click="removeSlip"
                class="absolute top-2 right-2 w-8 h-8 bg-white/90 rounded-full flex items-center justify-center shadow hover:bg-red-50 transition-colors">
                <span class="material-symbols-rounded text-[18px] text-red-500">close</span>
              </button>
            </div>
            <input ref="slipInputRef" type="file" accept="image/*" required class="hidden" @change="onSlipChange" />
          </div>

          <!-- Transfer Datetime -->
          <div class="grid grid-cols-2 gap-4 mt-4">
            <div class="space-y-1.5">
              <label class="block text-sm font-semibold text-[#1a1c1c]">วันที่โอน <span class="text-red-500">*</span></label>
              <input v-model="transferDate" type="date" required
                class="w-full px-4 py-2.5 rounded-xl border border-[#e2e2e2] bg-white text-sm text-[#1a1c1c] focus:outline-none focus:border-[#006565] focus:ring-2 focus:ring-[#006565]/20 transition" />
            </div>
            <div class="space-y-1.5">
              <label class="block text-sm font-semibold text-[#1a1c1c]">เวลาที่โอน <span class="text-red-500">*</span></label>
              <input v-model="transferTime" type="time" required
                class="w-full px-4 py-2.5 rounded-xl border border-[#e2e2e2] bg-white text-sm text-[#1a1c1c] focus:outline-none focus:border-[#006565] focus:ring-2 focus:ring-[#006565]/20 transition" />
            </div>
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
            <div v-else class="w-full h-full flex items-center justify-center"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent flex items-end p-5">
              <span class="bg-[#93f2f2] text-[#002020] text-xs font-bold px-3 py-1 rounded-full tracking-wide">
                รหัสจอง: {{ booking.booking_ref }}
              </span>
            </div>
          </div>

          <div class="p-7">
            <h2 class="text-lg font-bold mb-5 text-[#1a1c1c]">สรุปการจอง</h2>

            <div class="space-y-4 mb-6">
              <div>
                <p class="font-bold text-[#1a1c1c] leading-snug">{{ booking.schedule?.trip?.title }}</p>
                <p class="text-sm text-[#6e7979] mt-0.5">{{ booking.passengers?.length || 0 }} ที่นั่ง</p>
              </div>
              <p class="text-sm text-[#3e4949]">{{ formatDate(booking.schedule?.departure_date) }}</p>
              <p v-if="booking.seats?.length" class="text-sm text-[#3e4949]">ที่นั่ง: {{ booking.seats.map(s => s.seat_id).join(', ') }}</p>
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

            <!-- Total / First installment amount -->
            <div v-if="paymentType === 'installment'" class="mb-7 space-y-2">
              <div class="flex justify-between items-center">
                <span class="text-sm text-[#6e7979]">ยอดรวมทั้งหมด</span>
                <span class="text-base font-bold text-[#6e7979]">฿{{ Number(booking.total_amount).toLocaleString() }}</span>
              </div>
              <div class="flex justify-between items-end">
                <span class="font-bold text-[#1a1c1c]">ชำระงวดแรกตอนนี้</span>
                <span class="text-3xl font-extrabold text-[#e87c2a]">฿{{ perInstallment.toLocaleString() }}</span>
              </div>
              <p class="text-xs text-[#6e7979]">ยังเหลืออีก {{ installmentCount - 1 }} งวด · งวดละ ฿{{ perInstallment.toLocaleString() }}</p>
            </div>
            <div v-else class="flex justify-between items-end mb-7">
              <span class="font-bold text-[#1a1c1c]">ยอดชำระสุทธิ</span>
              <span class="text-3xl font-extrabold text-[#9e380d]">฿{{ Number(booking.total_amount).toLocaleString() }}</span>
            </div>

            <!-- Pay Button -->
            <button @click="processPayment" :disabled="paying"
              class="w-full py-4 rounded-full font-bold text-base flex items-center justify-center gap-2.5 active:scale-95 transition-all shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100"
              :class="paymentType === 'installment'
                ? 'bg-[#e87c2a] hover:bg-[#c96516] text-white shadow-[#e87c2a]/20'
                : 'bg-[#006565] hover:bg-[#004f4f] text-white shadow-[#006565]/20'">
              <span v-if="paying" class="w-5 h-5 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
              <template v-if="!paying">
                <span v-if="paymentType === 'installment'">ชำระงวดแรก ฿{{ perInstallment.toLocaleString() }}</span>
                <span v-else>ชำระเงินตอนนี้</span>
              </template>
              <template v-else>กำลังประมวลผล...</template>
            </button>

            <p v-if="paymentError" class="mt-4 text-sm text-red-600 text-center flex items-center justify-center gap-1.5">
              <span class="material-symbols-rounded text-base">error</span>
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
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import QRCode from 'qrcode';
import { useRoute, useRouter } from 'vue-router';
import { useBookingStore } from '../stores/booking';
import { useSeatsStore } from '../stores/seats';
import CountdownTimer from '../components/CountdownTimer.vue';
import { useSwal } from '../lib/swal';

const route = useRoute();
const router = useRouter();
const bookingStore = useBookingStore();
const seatsStore = useSeatsStore();
const swal = useSwal();

const PAYMENT_TIMEOUT_SECONDS = 10 * 60;

const booking = ref(null);
const loading = ref(true);
const paying = ref(false);
const autoCancelling = ref(false);
const paymentError = ref('');
const paymentMethod = ref('promptpay');
const paymentType = ref('full');

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

// ── Installment helpers ──────────────────────────────────────
const installmentAvailable = computed(() =>
  !!booking.value?.schedule?.installment_enabled
);
const installmentCount = computed(() =>
  booking.value?.schedule?.installment_count ?? 2
);
const installmentIntervalDays = computed(() =>
  booking.value?.schedule?.installment_interval_days ?? 30
);
const perInstallment = computed(() => {
  if (!booking.value) return 0;
  const total = parseFloat(booking.value.total_amount);
  return Math.round((total / installmentCount.value) * 100) / 100;
});
const installmentSchedule = computed(() => {
  if (!booking.value) return [];
  const total = parseFloat(booking.value.total_amount);
  const n = installmentCount.value;
  const interval = installmentIntervalDays.value;
  const per = Math.round((total / n) * 100) / 100;
  const rows = [];
  const now = new Date();
  for (let i = 1; i <= n; i++) {
    const dueDate = new Date(now);
    dueDate.setDate(dueDate.getDate() + (i - 1) * interval);
    const amount = i === n ? Math.round((total - per * (n - 1)) * 100) / 100 : per;
    rows.push({ no: i, dueDate: dueDate.toISOString().split('T')[0], amount });
  }
  return rows;
});

// ── QR regenerates when paymentType or paymentMethod changes ─
watch([paymentType, paymentMethod], ([, method]) => {
  if (method === 'promptpay') nextTick(generateQR);
});

// ── PromptPay QR ─────────────────────────────────────────────
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
  const amount = paymentType.value === 'installment'
    ? perInstallment.value
    : parseFloat(booking.value.total_amount);
  qrGenerated.value = false;
  const payload = buildPromptPayPayload('0626126006', amount);
  await QRCode.toCanvas(qrCanvas.value, payload, {
    width: 240,
    margin: 2,
    color: { dark: '#006565', light: '#ffffff' },
  });
  qrGenerated.value = true;
}

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

function initPaymentCountdown() {
  if (!booking.value || booking.value.status !== 'pending') {
    seatsStore.clearSelection();
    return;
  }

  const createdAtMs = booking.value.created_at ? new Date(booking.value.created_at).getTime() : Date.now();
  const baseTimeMs = Number.isFinite(createdAtMs) ? createdAtMs : Date.now();

  seatsStore.lockExpiry = new Date(baseTimeMs + PAYMENT_TIMEOUT_SECONDS * 1000).toISOString();
  seatsStore.setActiveBookingInfo({
    tripTitle: booking.value.schedule?.trip?.title || 'กิจกรรม',
    scheduleId: booking.value.schedule_id ?? booking.value.schedule?.id,
    bookingRef: booking.value.booking_ref,
    step: 'payment',
    startedAt: baseTimeMs,
  });
  seatsStore.startCountdown();
}

async function handlePaymentExpiry() {
  if (autoCancelling.value) return;
  autoCancelling.value = true;

  try {
    if (booking.value?.booking_ref && booking.value?.status === 'pending') {
      await bookingStore.cancelBooking(
        booking.value.booking_ref,
        'หมดเวลาชำระเงินเกิน 10 นาที ระบบยกเลิกการจองอัตโนมัติ'
      );
      booking.value.status = 'cancelled';
    }
  } catch (e) {
    console.error('Auto-cancel booking failed:', e);
  } finally {
    seatsStore.clearSelection();
  }

  await swal.error(
    'หมดเวลาชำระเงินแล้ว',
    'ครบกำหนด 10 นาที ระบบได้ยกเลิกการจองและคืนที่นั่งเรียบร้อยแล้ว กรุณาทำรายการใหม่อีกครั้ง'
  );
  router.push('/trips');
}

async function processPayment() {
  if (seatsStore.countdownSeconds <= 0) {
    paymentError.value = 'หมดเวลาชำระเงินแล้ว ระบบได้ยกเลิกการจองอัตโนมัติ';
    return;
  }

  if (booking.value?.status !== 'pending') {
    paymentError.value = 'สถานะการจองนี้ไม่สามารถชำระเงินได้';
    return;
  }

  if (!slipFile.value) {
    paymentError.value = 'กรุณาอัปโหลดสลิปการโอนเงินก่อนกดชำระ';
    return;
  }
  if (!transferDate.value) {
    paymentError.value = 'กรุณาระบุวันที่โอนเงิน';
    return;
  }
  if (!transferTime.value) {
    paymentError.value = 'กรุณาระบุเวลาที่โอนเงิน';
    return;
  }
  paying.value = true;
  paymentError.value = '';
  try {
    const fd = new FormData();
    fd.append('booking_ref', booking.value.booking_ref);
    fd.append('payment_type', paymentType.value);
    fd.append('payment_method', paymentMethod.value);
    fd.append('amount', paymentType.value === 'installment'
      ? perInstallment.value
      : parseFloat(booking.value.total_amount));
    fd.append('slip_image', slipFile.value);
    if (transferDate.value) fd.append('transfer_date', transferDate.value);
    if (transferTime.value) fd.append('transfer_time', transferTime.value);

    await bookingStore.chargePayment(fd);
    seatsStore.offExpire(handlePaymentExpiry);
    seatsStore.clearSelection();
    router.push(`/confirmation/${booking.value.booking_ref}`);
  } catch (e) {
    paymentError.value = e?.response?.data?.message || 'การชำระเงินล้มเหลว กรุณาลองใหม่';
  } finally {
    paying.value = false;
  }
}

onMounted(async () => {
  seatsStore.onExpire(handlePaymentExpiry);

  try {
    booking.value = await bookingStore.fetchBooking(route.params.bookingRef);
    initPaymentCountdown();
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

onBeforeUnmount(() => {
  seatsStore.offExpire(handlePaymentExpiry);
});
</script>
