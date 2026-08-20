<template>
  <!-- Loading -->
  <div v-if="loading" class="flex items-center justify-center min-h-[60vh]">
    <div class="flex flex-col items-center gap-4">
      <div class="w-12 h-12 rounded-full border-4 border-amber-200 border-t-amber-600 animate-spin"></div>
      <p class="text-gray-500 font-anuphan font-bold">กำลังโหลดข้อมูล...</p>
    </div>
  </div>

  <!-- No booking -->
  <div v-else-if="!booking" class="flex flex-col items-center justify-center min-h-[60vh] text-gray-500 font-anuphan">
    <span class="material-symbols-rounded text-6xl mb-4 text-gray-300">sentiment_dissatisfied</span>
    <p class="text-lg">ไม่พบข้อมูลการจอง</p>
  </div>

  <!-- Main Content -->
  <div v-else class="font-anuphan bg-[#f9f9f9] min-h-screen pt-8 pb-24 px-4 md:px-8 lg:px-12">

    <!-- Back Button -->
    <div class="max-w-4xl mx-auto mb-6">
      <router-link to="/my-bookings" class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-amber-700 transition-colors">
        <span class="material-symbols-rounded text-[18px]">arrow_back</span>
        กลับไปหน้าการจองของฉัน
      </router-link>
    </div>

    <div class="max-w-4xl mx-auto space-y-8">

      <!-- Header -->
      <div class="text-center">
        <div class="w-16 h-16 rounded-2xl bg-amber-100 flex items-center justify-center mx-auto mb-4">
          <span class="material-symbols-rounded text-amber-600 text-[36px]">calendar_month</span>
        </div>
        <h1 class="text-3xl font-black text-gray-900 mb-2">ชำระค่างวดผ่อน</h1>
        <p class="text-gray-500 font-bold">{{ booking.schedule?.trip?.title }} · #{{ booking.booking_ref }}</p>
      </div>

      <!-- Installment Progress Card -->
      <section class="bg-white rounded-3xl p-6 md:p-8 border border-gray-100">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-lg font-black text-gray-900 flex items-center gap-2">
            <span class="material-symbols-rounded text-amber-500">credit_score</span>
            สถานะการผ่อนชำระ
          </h2>
          <span class="text-xs font-black text-amber-600 bg-amber-100 px-3 py-1 rounded-full">
            {{ paidCount }} / {{ booking.installment_count }} งวด
          </span>
        </div>

        <!-- Progress Bar -->
        <div class="h-3 bg-gray-100 rounded-full overflow-hidden mb-6">
          <div class="h-full bg-gradient-to-r from-amber-400 to-amber-500 rounded-full transition-all duration-700"
            :style="{ width: (paidCount / booking.installment_count * 100) + '%' }"></div>
        </div>

        <!-- Installment Table -->
        <div class="overflow-hidden rounded-2xl border border-gray-100">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50">
                <th class="text-left px-4 py-3 font-bold text-gray-500 text-[10px] uppercase tracking-widest">งวดที่</th>
                <th class="text-left px-4 py-3 font-bold text-gray-500 text-[10px] uppercase tracking-widest">ครบกำหนด</th>
                <th class="text-right px-4 py-3 font-bold text-gray-500 text-[10px] uppercase tracking-widest">จำนวนเงิน</th>
                <th class="text-center px-4 py-3 font-bold text-gray-500 text-[10px] uppercase tracking-widest">สถานะ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="inst in installments" :key="inst.installment_no"
                :class="{
                  'bg-green-50/30': inst.status === 'paid',
                  'bg-amber-50/30': inst.status !== 'paid' && isNextDue(inst),
                }">
                <td class="px-4 py-3 font-bold text-gray-700">งวดที่ {{ inst.installment_no }}</td>
                <td class="px-4 py-3 text-gray-600">{{ formatDate(inst.due_date) }}</td>
                <td class="px-4 py-3 text-right font-black text-gray-900">฿{{ Number(inst.amount).toLocaleString() }}</td>
                <td class="px-4 py-3 text-center">
                  <span v-if="inst.status === 'paid'" class="text-[10px] font-black px-3 py-1.5 rounded-full bg-green-100 text-green-700">
                    ✓ ชำระแล้ว
                  </span>
                  <span v-else-if="isNextDue(inst)" class="text-[10px] font-black px-3 py-1.5 rounded-full bg-amber-500 text-white animate-pulse">
                    → ชำระงวดนี้
                  </span>
                  <span v-else class="text-[10px] font-black px-3 py-1.5 rounded-full bg-gray-100 text-gray-400">
                    รอชำระ
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Payment Section (only if there's a next installment to pay) -->
      <section v-if="nextInstallment" class="bg-white rounded-3xl p-6 md:p-8 border border-gray-100">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-lg font-black text-gray-900">ชำระงวดที่ {{ nextInstallment.installment_no }}</h2>
          <div class="text-right">
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-wider">ยอดชำระ</p>
            <p class="text-2xl font-black text-amber-600">฿{{ Number(nextInstallment.amount).toLocaleString() }}</p>
          </div>
        </div>

        <!-- Due date warning -->
        <div class="p-4 rounded-2xl mb-6 flex items-start gap-3"
          :class="isOverdue(nextInstallment) ? 'bg-red-50 border border-red-200' : isDueSoon(nextInstallment) ? 'bg-amber-50 border border-amber-200' : 'bg-blue-50 border border-blue-200'">
          <span class="material-symbols-rounded mt-0.5"
            :class="isOverdue(nextInstallment) ? 'text-red-500' : 'text-amber-500'">
            {{ isOverdue(nextInstallment) ? 'warning' : 'info' }}
          </span>
          <div>
            <p class="text-sm font-black" :class="isOverdue(nextInstallment) ? 'text-red-800' : 'text-amber-800'">
              {{ isOverdue(nextInstallment) ? 'เลยกำหนดชำระแล้ว!' : 'กำหนดชำระ: ' + formatDate(nextInstallment.due_date) }}
            </p>
            <p v-if="!isOverdue(nextInstallment)" class="text-xs font-bold text-amber-600 mt-0.5">
              {{ getDaysUntil(nextInstallment.due_date) }}
            </p>
          </div>
        </div>

        <!-- Payment Method -->
        <div v-if="!useBeam" class="mb-6">
          <label class="text-sm font-black text-gray-700 mb-3 block">เลือกช่องทางชำระ</label>
          <div class="grid grid-cols-2 gap-3">
            <button @click="paymentMethod = 'promptpay'"
              class="p-4 border-2 rounded-2xl transition-all text-center"
              :class="paymentMethod === 'promptpay' ? 'border-amber-500 bg-amber-50/30' : 'border-gray-100 hover:border-amber-200'">
              <span class="text-sm font-black" :class="paymentMethod === 'promptpay' ? 'text-amber-900' : 'text-gray-600'">QR PromptPay</span>
            </button>
            <button @click="paymentMethod = 'mobile_banking'"
              class="p-4 border-2 rounded-2xl transition-all text-center"
              :class="paymentMethod === 'mobile_banking' ? 'border-amber-500 bg-amber-50/30' : 'border-gray-100 hover:border-amber-200'">
              <span class="text-sm font-black" :class="paymentMethod === 'mobile_banking' ? 'text-amber-900' : 'text-gray-600'">โอนธนาคาร</span>
            </button>
          </div>
        </div>

        <!-- QR จากเกตเวย์ — จ่ายแล้วระบบตัดงวดให้เอง ไม่ต้องแนบสลิป -->
        <!-- จ่ายแล้วแต่ผลยังไม่กลับมา — เก็บ QR ไปก่อน หน้าจอต้องบอกว่ากำลังรออะไรอยู่ -->
        <div v-if="useBeam && beamSettling" class="mb-6">
          <PaymentSettlingPanel :seconds="beamSettlingSeconds" :slow="beamSlow" final-step="ตัดงวดที่ชำระให้อัตโนมัติ">
            <template #actions>
              <button @click="resumeBeamWaiting"
                class="mt-5 text-[11px] font-bold text-gray-400 hover:text-gray-600 underline underline-offset-4">
                ยังไม่ได้จ่าย · กลับไปสแกน QR
              </button>
            </template>
          </PaymentSettlingPanel>
        </div>

        <div v-else-if="useBeam" class="flex flex-col items-center gap-4 py-6 bg-gray-50 rounded-2xl border border-gray-100 mb-6">
          <p class="text-sm font-bold text-gray-700">สแกน QR เพื่อชำระ ฿{{ Number(nextInstallment.amount).toLocaleString() }}</p>

          <div class="relative p-2 bg-white rounded-2xl border border-gray-100 min-h-[260px] min-w-[260px] flex items-center justify-center">
            <img v-if="beamQrSrc && !beamExpired" :src="beamQrSrc" alt="QR พร้อมเพย์"
              class="block rounded-xl w-full max-w-[260px] h-auto mx-auto" />

            <div v-if="beamLoading" class="absolute inset-0 flex items-center justify-center bg-white/90 rounded-2xl">
              <div class="w-9 h-9 rounded-full border-4 border-amber-100 border-t-amber-500 animate-spin"></div>
            </div>

            <div v-else-if="beamExpired" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-white/95 rounded-2xl px-6 text-center">
              <p class="text-sm font-bold text-gray-900">QR หมดอายุแล้ว</p>
              <button @click="createBeamCharge()"
                class="px-5 py-2.5 bg-amber-500 text-white text-xs font-black rounded-xl hover:bg-amber-600 active:scale-95 transition-all">
                สร้าง QR ใหม่
              </button>
            </div>
          </div>

          <div v-if="beamPayment && !beamExpired" class="flex items-center gap-2">
            <div class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></div>
            <p class="text-xs font-bold text-gray-600">
              รอชำระ · QR หมดอายุใน <span class="text-amber-600 tabular-nums">{{ beamCountdownText }}</span>
            </p>
          </div>

          <!-- สัญญาณเดียวที่บอกเราได้ว่าลูกค้าสแกนไปแล้ว — ไม่มีปุ่มนี้ก็ได้แต่รอเงียบๆ -->
          <button v-if="beamPayment && !beamExpired" @click="markBeamSettling"
            class="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-amber-500 text-white text-xs font-black hover:bg-amber-600 active:scale-95 transition-all">
            <span class="material-symbols-rounded text-[18px]">task_alt</span>
            จ่ายเงินแล้ว · ตรวจสอบให้ฉัน
          </button>

          <p v-if="beamError" class="text-xs font-bold text-red-600 px-6 text-center">{{ beamError }}</p>
          <p class="text-xs font-bold text-gray-400 px-6 text-center">จ่ายแล้วระบบจะตัดงวดให้อัตโนมัติ ไม่ต้องแนบสลิป</p>
        </div>

        <!-- QR Code -->
        <div v-else-if="paymentMethod === 'promptpay'" class="flex flex-col items-center gap-4 py-6 bg-gray-50 rounded-2xl border border-gray-100 mb-6">
          <p class="text-sm font-bold text-gray-700">สแกน QR เพื่อชำระ ฿{{ Number(nextInstallment.amount).toLocaleString() }}</p>
          <div class="relative p-2 bg-white rounded-2xl border border-gray-100">
            <canvas ref="qrCanvas" class="block rounded-xl w-full max-w-[280px] h-auto mx-auto"></canvas>
          </div>
          <p class="text-xs font-bold text-gray-400">e-Wallet: 004-99923936-2071</p>
        </div>

        <!-- Bank info -->
        <div v-else class="bg-amber-50/50 rounded-2xl p-5 space-y-3 border border-amber-100 mb-6">
          <p class="text-sm font-black text-amber-900">ข้อมูลบัญชีธนาคาร</p>
          <div class="p-3 bg-white rounded-xl border border-amber-100 text-sm">
            <p class="text-gray-500 text-xs font-bold">ธนาคาร</p>
            <p class="font-black text-gray-900">กสิกรไทย (KBANK)</p>
          </div>
          <div class="p-3 bg-white rounded-xl border border-amber-100 text-sm">
            <p class="text-gray-500 text-xs font-bold">ชื่อบัญชี</p>
            <p class="font-black text-gray-900">ลุยเลเขา</p>
          </div>
          <div class="p-3 bg-white rounded-xl border border-amber-200 text-sm flex justify-between items-center">
            <div>
              <p class="text-gray-500 text-xs font-bold">เลขที่บัญชี</p>
              <p class="font-black text-gray-900 text-lg tracking-wider">230-1-39095-8</p>
            </div>
            <button @click="copyAccount" class="p-2 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100">
              <span class="material-symbols-rounded text-lg">content_copy</span>
            </button>
          </div>
        </div>

        <!-- Slip Upload — โหมดเกตเวย์ไม่ต้องใช้ -->
        <div v-if="!useBeam" class="space-y-4 mb-6">
          <label class="block text-sm font-black text-gray-700">อัปโหลดสลิป <span class="text-red-500">*</span></label>
          <div @click="slipInputRef?.click()"
            class="flex flex-col items-center justify-center gap-3 border-2 border-dashed rounded-2xl py-10 px-6 cursor-pointer transition-all"
            :class="slipPreview ? 'border-green-300 bg-green-50/30' : 'border-gray-200 hover:border-amber-300 bg-gray-50/50'">
            <template v-if="!slipPreview">
              <span class="material-symbols-rounded text-3xl text-gray-400">cloud_upload</span>
              <p class="text-sm font-bold text-gray-600">คลิกเพื่ออัปโหลดสลิป</p>
            </template>
            <template v-else>
              <img :src="slipPreview" alt="slip" class="max-h-[200px] object-contain rounded-xl" />
              <p class="text-xs font-bold text-green-600 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">check_circle</span>
                อัปโหลดแล้ว (คลิกเพื่อเปลี่ยน)
              </p>
            </template>
          </div>
          <input ref="slipInputRef" type="file" accept="image/*" class="hidden" @change="onSlipChange" />
        </div>

        <!-- Transfer Date/Time -->
        <div v-if="!useBeam" class="grid grid-cols-2 gap-4 mb-8">
          <div>
            <label class="text-xs font-black text-gray-400 uppercase tracking-widest mb-1 block">วันที่โอน</label>
            <input v-model="transferDate" type="date" class="w-full px-4 py-3 rounded-xl border border-gray-100 bg-white text-sm font-bold focus:border-amber-500 focus:ring-2 focus:ring-amber-500/10 outline-none" />
          </div>
          <div>
            <label class="text-xs font-black text-gray-400 uppercase tracking-widest mb-1 block">เวลาที่โอน</label>
            <input v-model="transferTime" type="time" class="w-full px-4 py-3 rounded-xl border border-gray-100 bg-white text-sm font-bold focus:border-amber-500 focus:ring-2 focus:ring-amber-500/10 outline-none" />
          </div>
        </div>

        <!-- Submit — โหมดเกตเวย์จบที่แอปธนาคาร ไม่มีอะไรให้กดยืนยันที่นี่ -->
        <button v-if="!useBeam" @click="processPayment" :disabled="paying || !slipFile || !transferDate || !transferTime"
          class="w-full py-4 rounded-2xl font-black text-base flex items-center justify-center gap-2 transition-all disabled:bg-gray-100 disabled:text-gray-400 disabled: disabled:cursor-not-allowed bg-amber-500 text-white hover:bg-amber-600 active:scale-[0.98]">
          <template v-if="!paying">
            <span class="material-symbols-rounded text-xl">verified_user</span>
            ยืนยันชำระงวดที่ {{ nextInstallment.installment_no }}
          </template>
          <div v-else class="w-5 h-5 rounded-full border-2 border-white/30 border-t-white animate-spin"></div>
        </button>

        <div v-if="paymentError" class="mt-4 p-4 rounded-xl bg-red-50 border border-red-100 flex items-start gap-2">
          <span class="material-symbols-rounded text-red-500 text-lg shrink-0">error</span>
          <p class="text-xs font-bold text-red-600">{{ paymentError }}</p>
        </div>
      </section>

      <!-- All Paid -->
      <section v-else class="bg-green-50 rounded-3xl p-8 border border-green-200 text-center">
        <span class="material-symbols-rounded text-green-500 text-5xl mb-4 block" style="font-variation-settings:'FILL' 1">task_alt</span>
        <h2 class="text-xl font-black text-green-800 mb-2">ชำระครบทุกงวดแล้ว!</h2>
        <p class="text-sm font-bold text-green-600">ขอบคุณที่ชำระเงินครบถ้วน เตรียมตัวออกทริปได้เลย</p>
        <router-link to="/my-bookings" class="inline-flex items-center gap-2 mt-6 px-6 py-3 bg-green-600 text-white rounded-xl font-bold text-sm hover:bg-green-700 transition-all">
          <span class="material-symbols-rounded text-lg">arrow_back</span>
          กลับหน้าการจอง
        </router-link>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import QRCode from 'qrcode';
import api from '../lib/axios';
import PaymentSettlingPanel from '../components/PaymentSettlingPanel.vue';
import { useBeamCharge } from '../composables/useBeamCharge';

const route = useRoute();
const router = useRouter();

const booking = ref(null);
const loading = ref(true);
const paying = ref(false);
const paymentError = ref('');
const paymentMethod = ref('promptpay');
const slipFile = ref(null);
const slipPreview = ref(null);
const slipInputRef = ref(null);
const transferDate = ref('');
const transferTime = ref('');
const qrCanvas = ref(null);

const installments = computed(() => booking.value?.installment_payments || []);
const paidCount = computed(() => installments.value.filter(i => i.status === 'paid').length);
const nextInstallment = computed(() => installments.value.find(i => i.status !== 'paid'));

// ── Beam Checkout ────────────────────────────────────────────
const gateway = computed(() => booking.value?.payment_gateway || { provider: 'manual' });
const useBeam = computed(() => gateway.value.provider === 'beam');

const {
  payment: beamPayment,
  loading: beamLoading,
  error: beamError,
  qrSrc: beamQrSrc,
  expired: beamExpired,
  settling: beamSettling,
  settlingSeconds: beamSettlingSeconds,
  slow: beamSlow,
  countdownText: beamCountdownText,
  markSettling: markBeamSettling,
  resumeWaiting: resumeBeamWaiting,
  create: createBeamPayment,
} = useBeamCharge(async () => {
  // จ่ายงวดนี้แล้ว โหลดการจองใหม่เพื่อเลื่อนไปงวดถัดไป (หรือขึ้นหน้า "ครบแล้ว")
  const res = await api.get(`/bookings/${booking.value.booking_ref}`);
  booking.value = res.data.data;
  if (nextInstallment.value) createBeamCharge();
});

function createBeamCharge() {
  if (!booking.value || !nextInstallment.value) return;

  return createBeamPayment({
    booking_ref: booking.value.booking_ref,
    purpose: 'installment_due',
    installment_id: nextInstallment.value.id,
    payment_method_type: 'QR_PROMPT_PAY',
  });
}

function isNextDue(inst) {
  return nextInstallment.value?.installment_no === inst.installment_no;
}

function isOverdue(inst) {
  if (!inst?.due_date) return false;
  const due = new Date(inst.due_date);
  due.setHours(23, 59, 59);
  return new Date() > due;
}

function isDueSoon(inst) {
  if (!inst?.due_date) return false;
  const due = new Date(inst.due_date);
  const diffDays = Math.ceil((due - new Date()) / (1000 * 60 * 60 * 24));
  return diffDays <= 7 && diffDays >= 0;
}

function getDaysUntil(dateStr) {
  if (!dateStr) return '';
  const diffDays = Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24));
  if (diffDays < 0) return 'เลยกำหนด';
  if (diffDays === 0) return 'วันนี้!';
  if (diffDays === 1) return 'พรุ่งนี้';
  return `อีก ${diffDays} วัน`;
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function copyAccount() {
  navigator.clipboard.writeText('004999239362071');
}

function onSlipChange(e) {
  const file = e.target.files[0];
  if (!file) return;
  slipFile.value = file;
  const reader = new FileReader();
  reader.onload = (ev) => { slipPreview.value = ev.target.result; };
  reader.readAsDataURL(file);
}

// PromptPay QR
function buildPromptPayPayload(identifier, amount) {
  const cleanId = identifier.replace(/\D/g, '');
  let normalized = cleanId;
  let typeTag = '03';
  if (cleanId.length === 10 && cleanId.startsWith('0')) {
    normalized = '0066' + cleanId.slice(1);
    typeTag = '01';
  } else if (cleanId.length === 13) {
    typeTag = '02';
  }
  const tag = (id, value) => {
    const len = value.length.toString().padStart(2, '0');
    return `${id}${len}${value}`;
  };
  const merchantAccInfo = tag('00', 'A000000677010111') + tag(typeTag, normalized);
  const merchantInfo = tag('29', merchantAccInfo);
  const amtStr = amount.toFixed(2);
  let payload = tag('00', '01') + tag('01', '12') + merchantInfo + tag('53', '764') + tag('54', amtStr) + tag('58', 'TH') + tag('62', tag('07', 'LUILAYKHAO')) + '6304';
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
  if (loading.value || paymentMethod.value !== 'promptpay' || !qrCanvas.value || !nextInstallment.value) return;
  const amount = parseFloat(nextInstallment.value.amount);
  const payload = buildPromptPayPayload('004999239362071', amount);

  const ctx = qrCanvas.value.getContext('2d');
  const bgImg = new Image();
  bgImg.onload = async () => {
    if (!qrCanvas.value) return;
    qrCanvas.value.width = bgImg.width;
    qrCanvas.value.height = bgImg.height;
    ctx.drawImage(bgImg, 0, 0);
    const tempCanvas = document.createElement('canvas');
    const qrSize = bgImg.width * 0.52;
    await QRCode.toCanvas(tempCanvas, payload, {
      width: qrSize, margin: 0,
      color: { dark: '#000000', light: '#ffffff' },
      errorCorrectionLevel: 'H'
    });
    const x = (bgImg.width - qrSize) / 2;
    const y = bgImg.height * 0.245;
    ctx.drawImage(tempCanvas, x, y);
  };
  bgImg.src = '/images/IMG_7195.JPG';
}

async function processPayment() {
  if (!slipFile.value) { paymentError.value = 'กรุณาอัปโหลดสลิป'; return; }
  if (!transferDate.value || !transferTime.value) { paymentError.value = 'กรุณาระบุวันเวลาที่โอน'; return; }

  paying.value = true;
  paymentError.value = '';
  try {
    const fd = new FormData();
    fd.append('booking_ref', booking.value.booking_ref);
    fd.append('installment_no', nextInstallment.value.installment_no);
    fd.append('payment_method', paymentMethod.value);
    fd.append('slip_image', slipFile.value);
    fd.append('transfer_date', transferDate.value);
    fd.append('transfer_time', transferTime.value);

    await api.post('/payments/charge-installment', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });

    // Refresh booking data
    const res = await api.get(`/bookings/${booking.value.booking_ref}`);
    booking.value = res.data.data;

    // Reset form
    slipFile.value = null;
    slipPreview.value = null;
    transferDate.value = '';
    transferTime.value = '';

    if (nextInstallment.value) {
      await nextTick();
      if (paymentMethod.value === 'promptpay') generateQR();
    }
  } catch (e) {
    paymentError.value = e?.response?.data?.message || 'การชำระเงินล้มเหลว กรุณาลองใหม่';
  } finally {
    paying.value = false;
  }
}

watch([paymentMethod, nextInstallment, loading], ([method, installment, isLoading]) => {
  if (isLoading || method !== 'promptpay' || !installment) return;
  // โหมดเกตเวย์ออก QR ตอน mount ครั้งเดียว ไม่ต้องออกใหม่ทุกครั้งที่ watcher ยิง
  if (!useBeam.value) generateQR();
}, { flush: 'post' });

onMounted(async () => {
  try {
    const res = await api.get(`/bookings/${route.params.bookingRef}`);
    booking.value = res.data.data;
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }

  if (useBeam.value && nextInstallment.value) createBeamCharge();
});
</script>
