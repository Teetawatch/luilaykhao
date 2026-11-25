<template>
  <!--
    จ่ายผ่านเกตเวย์ (Beam) — ชุดเดียวกับ _BeamPaymentSection ในแอป ใช้ทั้งหน้าจ่ายค่าทริป
    งวดผ่อน และค่าที่พัก

      1. เลือกช่องทาง  รายการ radio (QR เลือกไว้ก่อน) + ปุ่มชำระปุ่มเดียวที่บอกว่ากดแล้วจะเกิดอะไร
      2. QR            ออกใบเมื่อกดปุ่มเท่านั้น — เดิมออกตั้งแต่เปิดหน้า เวลาหมดอายุเลยเริ่มนับ
                       ทั้งที่ลูกค้ายังไม่พร้อม และคนที่จะจ่ายบัตรต้องเห็น QR ใหญ่ก่อน
      3. รอผล          PaymentSettlingPanel (useBeamCharge.settling)

    สถานะการจ่ายทั้งหมดอยู่ที่ useBeamCharge ของหน้า (ส่งมาเป็น reactive ทาง beam) —
    หน้ายังต้องอ่านมันเองสำหรับแถบสรุป/แถบล่าง ตัวนี้ถือแค่ "ลูกค้าอยู่ขั้นไหน เลือกอะไร"
    ออกใบผ่าน emit('create', type) เพราะ payload (purpose, งวด, ปลายทาง) เป็นเรื่องของหน้า
  -->
  <Transition name="bp-stage" mode="out-in">
    <!-- 3. จ่ายแล้ว รอผล — เก็บ QR และทุกปุ่มจ่ายออกไป กันจ่ายซ้ำ -->
    <div v-if="beam.settling" key="settling" class="pb-5">
      <PaymentSettlingPanel :seconds="beam.settlingSeconds" :slow="beam.slow" :final-step="finalStep">
        <template #actions>
          <button type="button" @click="notPaidYet"
            class="mt-5 text-[11px] font-bold text-gray-400 hover:text-gray-600 underline underline-offset-4">
            {{ awayPayment ? $t('ยังไม่ได้จ่าย · เลือกวิธีชำระใหม่') : $t('ยังไม่ได้จ่าย · กลับไปสแกน QR') }}
          </button>
        </template>
      </PaymentSettlingPanel>
    </div>

    <!-- 2. QR -->
    <div v-else-if="showQr" key="qr" ref="qrEl" class="bp-qr-stage pb-6 pt-1">
      <div class="flex items-center justify-between gap-3">
        <button type="button" @click="backToMethods" :disabled="beam.loading"
          class="inline-flex items-center gap-1 -ml-1 px-1 py-2 text-xs font-bold text-gray-500 hover:text-gray-900 transition-colors disabled:opacity-50">
          <span class="material-symbols-rounded icon-18" aria-hidden="true">arrow_back</span>{{ $t('เปลี่ยนวิธีชำระ') }}
        </button>
        <Transition name="bp-fade">
          <span v-if="live" class="bp-timer tabular-nums" :class="{ 'is-urgent': urgent }">
            <span class="material-symbols-rounded icon-14" aria-hidden="true">schedule</span>
            {{ $t('ใช้ได้อีก {time}', { time: beam.countdownText }) }}
          </span>
        </Transition>
      </div>

      <div class="mt-2 text-center">
        <p class="text-lg font-black text-gray-900">{{ $t('สแกนเพื่อชำระเงิน') }}</p>
        <p class="text-3xl font-black text-accent tabular-nums leading-tight">฿{{ money(amount) }}</p>
      </div>

      <div class="bp-frame mx-auto mt-5">
        <span class="bp-corner is-tl" aria-hidden="true"></span>
        <span class="bp-corner is-tr" aria-hidden="true"></span>
        <span class="bp-corner is-bl" aria-hidden="true"></span>
        <span class="bp-corner is-br" aria-hidden="true"></span>

        <div class="bp-qr-box">
          <img v-if="live" :key="`qr-${beam.payment?.payment_id}`" :src="beam.qrSrc" :alt="$t('QR พร้อมเพย์')"
            class="bp-qr-img" />
          <span v-if="live" class="bp-scan" aria-hidden="true"></span>

          <div v-if="beam.loading" class="bp-overlay">
            <div class="w-10 h-10 rounded-full border-4 border-accent-tint border-t-accent animate-spin"></div>
          </div>

          <!-- ธนาคารตอบว่าไม่ผ่าน — บอกให้ชัดแทนที่จะปล่อยให้ QR ใบตายค้างอยู่ -->
          <div v-else-if="beam.failed" class="bp-overlay flex-col gap-2 px-6 text-center">
            <span class="material-symbols-rounded text-4xl text-red-400">credit_card_off</span>
            <p class="text-sm font-black text-gray-900">{{ $t('การชำระเงินไม่สำเร็จ') }}</p>
            <p class="text-[11px] text-gray-500 font-medium leading-relaxed">{{ $t('ยังไม่มีการตัดเงิน สร้างรายการใหม่แล้วลองอีกครั้งได้เลย') }}</p>
            <button type="button" @click="newQr" class="bp-mini-btn mt-1">{{ $t('สร้าง QR ใหม่') }}</button>
          </div>

          <!-- QR หมดอายุก่อนที่นั่งจะถูกคืน ลูกค้าจึงยังกดออกใบใหม่ได้ -->
          <div v-else-if="beam.expired" class="bp-overlay flex-col gap-3 px-6 text-center">
            <span class="material-symbols-rounded text-4xl text-gray-300">timer_off</span>
            <p class="text-sm font-black text-gray-900">{{ $t('QR หมดอายุแล้ว') }}</p>
            <button type="button" @click="newQr" class="bp-mini-btn">{{ $t('สร้าง QR ใหม่') }}</button>
          </div>

          <div v-else-if="!live" class="bp-overlay flex-col gap-3 px-6 text-center">
            <span class="material-symbols-rounded text-4xl text-gray-300">qr_code_2</span>
            <p class="text-xs font-bold text-gray-500">{{ $t('ยังสร้าง QR ไม่ได้') }}</p>
            <button type="button" @click="newQr" class="bp-mini-btn">{{ $t('สร้าง QR ใหม่') }}</button>
          </div>
        </div>
      </div>

      <p class="mt-3 flex items-center justify-center gap-2 text-xs font-bold text-gray-500">
        <img src="/images/pay/thaiqr.png" alt="" width="34" height="22" class="h-[22px] w-[34px] rounded-[4px]" />
        {{ $t('ใช้ได้กับทุกแอปธนาคารที่รองรับพร้อมเพย์') }}
      </p>

      <PayTimeline v-if="live" class="mt-6 max-w-sm mx-auto" :current="0" :steps="[
        { title: $t('สแกน QR ด้วยแอปธนาคาร'), hint: $t('เปิดแอปธนาคาร เลือกสแกน แล้วส่องที่ QR นี้') },
        { title: $t('รอธนาคารยืนยันว่าเงินเข้า'), hint: $t('ส่วนใหญ่ใช้เวลาไม่กี่วินาที ไม่ต้องแนบสลิป') },
        { title: finalStep, hint: $t('หน้านี้จะเปลี่ยนให้เองเมื่อเงินเข้า') },
      ]" />

      <p v-if="beam.error" class="mt-4 text-xs font-bold text-red-600 text-center">{{ beam.error }}</p>
    </div>

    <!-- 1. เลือกช่องทาง -->
    <div v-else key="methods">
      <div class="divide-y divide-gray-100" role="radiogroup" :aria-label="$t('ช่องทางชำระเงิน')">
        <PayMethodRow badge="thaiqr.png" title="QR PromptPay" :subtitle="qrSubtitle"
          :selected="choice === 'qr'" :disabled="beam.loading" @select="choice = 'qr'">
          <template #below>
            <span class="bp-tag"><span class="material-symbols-rounded icon-14" aria-hidden="true" style="font-variation-settings:'FILL' 1">bolt</span>{{ $t('แนะนำ · ยืนยันทันที') }}</span>
          </template>
        </PayMethodRow>

        <PayMethodRow v-if="bankApps.length" icon="smartphone" :title="$t('แอปธนาคาร')"
          :subtitle="$t('เปิดแอปธนาคารแล้วกดยืนยันได้เลย ไม่ต้องสแกน')"
          :selected="choice === 'bank'" :disabled="beam.loading" @select="choice = 'bank'">
          <template #below>
            <span class="flex flex-wrap gap-1.5">
              <img v-for="app in bankApps" :key="app.type" :src="`/images/pay/${app.badge}.svg`" alt=""
                class="h-[22px] w-[34px]" width="34" height="22" loading="lazy" />
            </span>
          </template>

          <div class="grid grid-cols-2 gap-2" role="radiogroup" :aria-label="$t('แอปธนาคาร')">
            <button v-for="app in bankApps" :key="app.type" type="button" role="radio"
              :aria-checked="String(bankChoice === app.type)" :disabled="beam.loading"
              @click="bankChoice = app.type"
              class="bp-bank" :class="{ 'is-on': bankChoice === app.type }">
              <img :src="`/images/pay/${app.badge}.svg`" alt="" width="34" height="22" class="h-[22px] w-[34px] shrink-0" />
              <span class="flex-1 min-w-0 truncate text-[13px] font-bold text-gray-900">{{ app.label }}</span>
              <span class="bp-bank-check material-symbols-rounded icon-18" aria-hidden="true" style="font-variation-settings:'FILL' 1">check_circle</span>
            </button>
          </div>
          <p class="mt-3 flex items-start gap-2 text-[12px] text-gray-500 font-medium leading-relaxed">
            <span class="material-symbols-rounded icon-16 text-gray-400 shrink-0 mt-px">info</span>
            {{ $t('ระบบจะพาไปที่แอปธนาคารพร้อมยอด ฿{money} จ่ายเสร็จแล้วกลับมาหน้านี้ได้เลย ระบบจะตรวจสอบให้อัตโนมัติ', { money: money(amount) }) }}
          </p>
        </PayMethodRow>

        <!-- บัตร · Apple/Google Pay · ผ่อน · e-Wallet → หน้าชำระเงินของ Beam -->
        <PaymentCheckoutMethods v-if="checkoutAvailable" selectable :selected="choice"
          :amount="amount" :installment-min="installmentMin" :loading="beam.loading"
          @select="(key) => (choice = key)" />
      </div>

      <div class="border-t border-gray-100 pt-5 pb-5">
        <button type="button" data-testid="beam-pay-cta" @click="pay" :disabled="!cta.ready || beam.loading"
          class="bp-cta group">
          <span class="pay-shine" aria-hidden="true"></span>
          <Transition name="bp-label" mode="out-in">
            <span v-if="beam.loading" key="loading" class="w-5 h-5 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
            <span v-else :key="cta.label" class="flex items-center justify-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-xl shrink-0" aria-hidden="true">{{ cta.icon }}</span>
              <span class="truncate">{{ cta.label }}</span>
            </span>
          </Transition>
        </button>
        <p v-if="beam.error" class="mt-3 text-xs font-bold text-red-600 text-center">{{ beam.error }}</p>
        <p class="mt-3 flex items-center justify-center gap-1.5 text-[12px] font-medium text-gray-500 text-center">
          <span class="material-symbols-rounded icon-14 text-gray-400" style="font-variation-settings:'FILL' 1">verified_user</span>
          {{ $t('ชำระผ่านระบบที่ปลอดภัย ยืนยันอัตโนมัติ ไม่ต้องแนบสลิป') }}
        </p>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { $t, DATE_LOCALE } from '@/i18n';
import { computed, nextTick, ref, watch } from 'vue';
import PayMethodRow from './PayMethodRow.vue';
import PayTimeline from './PayTimeline.vue';
import PaymentCheckoutMethods from './PaymentCheckoutMethods.vue';
import PaymentSettlingPanel from './PaymentSettlingPanel.vue';

const props = defineProps({
  /** reactive(useBeamCharge(...)) ของหน้า */
  beam: { type: Object, required: true },
  amount: { type: Number, required: true },
  /** payment_gateway.methods จากเซิร์ฟเวอร์ — ไม่ hardcode ว่าเปิดอะไร */
  methods: { type: Array, default: () => [] },
  installmentMin: { type: Number, default: 0 },
  qrSubtitle: { type: String, default: () => $t('จ่ายแล้วระบบยืนยันให้อัตโนมัติ ไม่ต้องแนบสลิปและไม่ต้องรอเจ้าหน้าที่ตรวจ') },
  /** ขั้นสุดท้ายของไทม์ไลน์ ต่างกันตามว่าจ่ายเพื่ออะไร */
  finalStep: { type: String, default: () => $t('บันทึกยอดที่ชำระให้อัตโนมัติ') },
});
const emit = defineEmits(['create']);

// badge = ป้ายใน public/images/pay (สัญลักษณ์ธนาคารจาก omise/banks-logo ชุดเดียวกับแอป)
const ALL_BANK_APPS = [
  { type: 'KPLUS', label: 'K PLUS', badge: 'kbank' },
  { type: 'SCB_EASY', label: 'SCB EASY', badge: 'scb' },
  { type: 'KRUNGSRI_APP', label: 'Krungsri', badge: 'bay' },
  { type: 'BANGKOK_BANK_APP', label: 'Bualuang', badge: 'bbl' },
];
const bankApps = computed(() => ALL_BANK_APPS.filter((app) => props.methods.includes(app.type)));
const checkoutAvailable = computed(() => props.methods.includes('CHECKOUT'));

/** qr · bank · card · wallet-pay · installment · ewallet */
const choice = ref('qr');
const bankChoice = ref('');
const showQr = ref(false);
const qrEl = ref(null);

const money = (n) => Number(n || 0).toLocaleString(DATE_LOCALE, { maximumFractionDigits: 2 });

/** ใบที่ส่งลูกค้าออกไปหน้าอื่น (แอปธนาคาร/หน้าของ Beam) ไม่ใช่ QR */
const awayPayment = computed(() => !!props.beam.payment?.redirect_url);
const live = computed(() =>
  !!props.beam.qrSrc && !props.beam.expired && !props.beam.failed && !props.beam.loading && !awayPayment.value
);
const urgent = computed(() => live.value && props.beam.secondsLeft <= 60);

const cta = computed(() => {
  const amount = money(props.amount);
  if (choice.value === 'qr') {
    return { ready: true, icon: 'qr_code_2', label: $t('แสดง QR ชำระ ฿{money}', { money: amount }) };
  }
  if (choice.value === 'bank') {
    const app = bankApps.value.find((a) => a.type === bankChoice.value);
    return app
      ? { ready: true, icon: 'open_in_new', label: $t('เปิดแอป {app}', { app: app.label }) }
      : { ready: false, icon: 'touch_app', label: $t('เลือกแอปธนาคารก่อน') };
  }
  return { ready: true, icon: 'lock', label: $t('ชำระเงิน ฿{money}', { money: amount }) };
});

/** ปุ่มเดียวท้ายรายการ — ทำตามช่องทางที่เลือก (แถบล่างบนมือถือของหน้าเรียกตัวนี้ด้วย) */
function pay() {
  if (!cta.value.ready || props.beam.loading) return;

  if (choice.value === 'qr') {
    // QR ใบเดิมยังใช้ได้ (กดเปลี่ยนช่องทางแล้วกลับมา) — โชว์ใบเดิม ไม่ออกใบซ้ำ
    if (!live.value) emit('create', 'QR_PROMPT_PAY');
    showQr.value = true;
    revealQr();
    return;
  }
  emit('create', choice.value === 'bank' ? bankChoice.value : 'CHECKOUT');
}

/** การ์ดสรุปการจองอยู่ข้างบนยาว กดแล้วต้องเลื่อนไปที่ QR ให้ ไม่งั้นกดแล้วจอดูไม่เปลี่ยน */
function revealQr() {
  // รอ Transition (out-in) สลับขั้นเสร็จก่อน ถึงจะมี qrEl ให้เลื่อนไปหา
  setTimeout(async () => {
    await nextTick();
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    qrEl.value?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
  }, 240);
}

function newQr() {
  emit('create', 'QR_PROMPT_PAY');
}

/** กลับไปเลือกช่องทาง — QR ใบนั้นยังรอเงินอยู่เบื้องหลัง เผื่อสแกนจากอีกเครื่องไปแล้ว */
function backToMethods() {
  showQr.value = false;
}

/** "ยังไม่ได้จ่าย" — QR กลับไปที่ QR เดิม · ใบที่ส่งออกไปข้างนอกไม่มี QR ให้กลับ → หน้าเลือก */
function notPaidYet() {
  if (awayPayment.value) {
    props.beam.reset();
    showQr.value = false;
    return;
  }
  props.beam.resumeWaiting();
}

// เข้าโหมด "ไปจ่ายมาแล้ว" ตอนกลับเข้าแท็บ ก็ต่อเมื่อ QR อยู่บนจอจริงๆ
watch(showQr, (on) => props.beam.present?.(on), { immediate: true });

// หน้ารีเซ็ตใบทิ้ง (ยอดเปลี่ยน) หรือเงินเข้าแล้ว → กลับไปหน้าเลือก
watch(() => [props.beam.payment, props.beam.loading], ([p, loading]) => {
  if (loading) return;
  if (!p || p.status === 'succeeded') showQr.value = false;
});

defineExpose({ pay, cta, showQr });
</script>

<style scoped>
.bp-qr-stage {
  scroll-margin-top: 96px;
}

.bp-timer {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 10px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 800;
  background: var(--color-accent-tint, #e8f3ee);
  color: var(--color-accent);
  transition: background-color 0.24s, color 0.24s;
}
.bp-timer.is-urgent {
  background: #fef2f2;
  color: #dc2626;
}

/* กรอบ QR: มุมสี่มุมสีแบรนด์รอบกล่องขาว */
.bp-frame {
  position: relative;
  width: 100%;
  max-width: 272px;
  aspect-ratio: 1;
  padding: 7px;
}
.bp-corner {
  position: absolute;
  width: 26px;
  height: 26px;
  border-color: var(--color-accent);
  border-style: solid;
  border-width: 0;
}
.bp-corner.is-tl { top: 0; left: 0; border-top-width: 3px; border-left-width: 3px; border-top-left-radius: 12px; }
.bp-corner.is-tr { top: 0; right: 0; border-top-width: 3px; border-right-width: 3px; border-top-right-radius: 12px; }
.bp-corner.is-bl { bottom: 0; left: 0; border-bottom-width: 3px; border-left-width: 3px; border-bottom-left-radius: 12px; }
.bp-corner.is-br { bottom: 0; right: 0; border-bottom-width: 3px; border-right-width: 3px; border-bottom-right-radius: 12px; }

.bp-qr-box {
  position: relative;
  width: 100%;
  height: 100%;
  padding: 12px;
  border-radius: 14px;
  background: #fff;
  border: 1px solid rgba(0, 0, 0, 0.06);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.bp-qr-img {
  display: block;
  width: 100%;
  height: auto;
  animation: bp-qr-in 0.42s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
.bp-scan {
  position: absolute;
  left: 12px;
  right: 12px;
  height: 3px;
  border-radius: 2px;
  background: linear-gradient(90deg, transparent, color-mix(in srgb, var(--color-accent) 55%, transparent), transparent);
  animation: bp-scan 2.6s ease-in-out infinite alternate;
  pointer-events: none;
}
.bp-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.95);
}
.bp-mini-btn {
  padding: 9px 18px;
  border-radius: 14px;
  background: var(--color-accent);
  color: #fff;
  font-size: 12px;
  font-weight: 900;
  transition: transform 0.15s;
}
.bp-mini-btn:active { transform: scale(0.95); }

.bp-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 9px 2px 7px;
  border-radius: 9999px;
  background: var(--color-accent-tint, #e8f3ee);
  color: var(--color-accent);
  font-size: 11px;
  font-weight: 800;
}

.bp-bank {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px;
  border-radius: 14px;
  border: 1px solid #e5e7eb;
  background: #fafafa;
  text-align: left;
  transition: border-color 0.18s, background-color 0.18s, box-shadow 0.18s;
}
.bp-bank:hover:not(:disabled) { border-color: var(--color-accent); }
.bp-bank.is-on {
  border-color: var(--color-accent);
  background: var(--color-accent-tint, #e8f3ee);
  box-shadow: inset 0 0 0 0.5px var(--color-accent);
}
.bp-bank-check {
  color: var(--color-accent);
  transform: scale(0);
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.bp-bank.is-on .bp-bank-check { transform: scale(1); }

.bp-cta {
  position: relative;
  overflow: hidden;
  width: 100%;
  min-height: 54px;
  padding: 0 18px;
  border-radius: 16px;
  background: var(--color-accent);
  color: #fff;
  font-size: 15px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.2s, transform 0.15s;
}
.bp-cta:hover:not(:disabled) { background: var(--color-accent-mid, var(--color-accent)); }
.bp-cta:active:not(:disabled) { transform: scale(0.985); }
.bp-cta:disabled { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; }

/* แสงวิ่งผ่านปุ่มช้าๆ — ชุดเดียวกับปุ่มยืนยันของโหมดโอนเอง */
.pay-shine {
  position: absolute;
  inset: 0;
  background: linear-gradient(110deg, transparent 30%, rgba(255, 255, 255, 0.22) 50%, transparent 70%);
  transform: translateX(-100%);
  animation: bp-shine 3.2s ease-in-out infinite;
  pointer-events: none;
}
.bp-cta:disabled .pay-shine { display: none; }

.bp-stage-enter-active,
.bp-stage-leave-active {
  transition: opacity 0.22s ease, transform 0.26s cubic-bezier(0.2, 0.7, 0.2, 1);
}
.bp-stage-enter-from { opacity: 0; transform: translateY(10px); }
.bp-stage-leave-to { opacity: 0; transform: translateY(-6px); }

.bp-label-enter-active,
.bp-label-leave-active { transition: opacity 0.16s, transform 0.16s; }
.bp-label-enter-from,
.bp-label-leave-to { opacity: 0; transform: scale(0.96); }

.bp-fade-enter-active,
.bp-fade-leave-active { transition: opacity 0.2s; }
.bp-fade-enter-from,
.bp-fade-leave-to { opacity: 0; }

@keyframes bp-scan {
  from { top: 12px; }
  to { top: calc(100% - 15px); }
}
@keyframes bp-qr-in {
  from { opacity: 0; transform: scale(0.9); }
  to { opacity: 1; transform: none; }
}
@keyframes bp-shine {
  0%, 55% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

@media (prefers-reduced-motion: reduce) {
  .bp-scan,
  .pay-shine,
  .bp-qr-img { animation: none; }
  .bp-scan { display: none; }
  .bp-stage-enter-active,
  .bp-stage-leave-active,
  .bp-label-enter-active,
  .bp-label-leave-active { transition: none; }
}
</style>
