<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pt-8 pb-24 px-4">
    <div class="max-w-2xl mx-auto">
      <router-link to="/my-stays" class="inline-flex items-center gap-1 text-sm font-bold text-[var(--color-text-muted)] hover:text-[var(--color-accent)]">
        <span class="material-symbols-rounded icon-18">arrow_back</span> ที่พักของฉัน
      </router-link>

      <div v-if="loading" class="mt-6 h-64 rounded-3xl shimmer"></div>
      <div v-else-if="!b" class="mt-10 text-center text-[var(--color-text-muted)]">ไม่พบใบจองนี้</div>

      <template v-else>
        <!-- หัวใบจอง -->
        <section class="card mt-4 overflow-hidden !p-0">
          <img v-if="b.stay?.cover_image" :src="b.stay.cover_image" :alt="b.stay.name" class="h-40 w-full object-cover" />
          <div class="p-5">
            <span class="status" :class="'status--' + tone">{{ b.status_label }}</span>
            <h1 class="mt-2 text-2xl font-black text-[var(--color-text-dark)]">{{ b.stay?.name }}</h1>
            <p class="text-[14px] font-semibold text-[var(--color-text-muted)]">{{ b.stay?.community_name }}<span v-if="b.stay?.province"> · {{ b.stay.province }}</span></p>
            <div class="mt-4 grid grid-cols-2 gap-3">
              <div class="mini"><span>เช็คอิน</span><strong>{{ thaiDate(b.check_in) }}</strong><small>ตั้งแต่ {{ b.stay?.check_in_time }} น.</small></div>
              <div class="mini"><span>เช็คเอาต์</span><strong>{{ thaiDate(b.check_out) }}</strong><small>ก่อน {{ b.stay?.check_out_time }} น.</small></div>
            </div>
            <p class="mt-3 text-[14px] text-[var(--color-text-mid)]">
              {{ b.room_name }} × {{ b.units }} ห้อง · {{ b.guests }} คน · {{ b.nights }} คืน · ใบจอง <strong>{{ b.ref }}</strong>
            </p>
            <p v-if="b.trip_booking_ref" class="text-[13px] text-[var(--color-text-muted)]">พ่วงกับทริป {{ b.trip_booking_ref }}</p>
          </div>
        </section>

        <!-- สถานะ / ขั้นต่อไป -->
        <section v-if="b.status === 'requested'" class="card mt-4 notice">
          <span class="material-symbols-rounded">hourglass_top</span>
          <p>ส่งคำขอแล้ว ที่พักกำลังเช็กห้องว่าง จะแจ้งให้ชำระเงินภายใน 24 ชั่วโมง — ตอนนี้ยังไม่ต้องจ่ายอะไรครับ</p>
        </section>
        <section v-if="b.status === 'declined'" class="card mt-4 notice notice--bad">
          <span class="material-symbols-rounded">event_busy</span>
          <p>ห้องไม่ว่างในวันที่ขอ<span v-if="b.decline_reason"> — {{ b.decline_reason }}</span> <router-link :to="`/stays/${b.stay?.slug}`" class="underline font-bold">ลองวันอื่น</router-link></p>
        </section>
        <section v-if="b.status === 'expired'" class="card mt-4 notice notice--bad">
          <span class="material-symbols-rounded">timer_off</span>
          <p>ใบจองหมดเวลาแล้ว ห้องถูกปล่อยให้คนอื่น <router-link :to="`/stays/${b.stay?.slug}`" class="underline font-bold">จองใหม่</router-link></p>
        </section>
        <section v-if="b.status === 'payment_review'" class="card mt-4 notice">
          <span class="material-symbols-rounded">fact_check</span>
          <p>ได้รับสลิปแล้ว ทีมงานกำลังตรวจยอดโอน จะยืนยันห้องให้เร็วที่สุดครับ</p>
        </section>

        <!-- ชำระเงิน -->
        <section v-if="b.can_pay" class="card mt-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-black text-[var(--color-text-dark)]">ชำระเงิน</h2>
              <p v-if="b.payment_due_at" class="text-[13px] font-bold text-amber-700">ภายใน {{ dueText }}</p>
            </div>
            <p class="text-2xl font-black text-[var(--color-accent)]">฿{{ money(b.total_amount) }}</p>
          </div>
          <p v-if="b.payment_note" class="mt-3 rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">สลิปก่อนหน้าไม่ผ่าน: {{ b.payment_note }}</p>

          <!-- Beam: จ่ายแล้วระบบยืนยันห้องให้เอง ไม่ต้องแนบสลิป -->
          <template v-if="useBeam && !manualPay">
            <div class="mt-4 divide-y divide-gray-100 border-b border-gray-100" role="radiogroup" aria-label="ช่องทางชำระ">
              <PayMethodRow badge="thaiqr.png" title="QR PromptPay"
                subtitle="จ่ายแล้วระบบยืนยันห้องให้อัตโนมัติ ไม่ต้องแนบสลิป" :selected="true">
                <div v-if="beamSettling">
                  <PaymentSettlingPanel :seconds="beamSettlingSeconds" :slow="beamSlow" final-step="ยืนยันห้องพักของคุณอัตโนมัติ">
                    <template #actions>
                      <button @click="resumeBeamWaiting"
                        class="mt-5 text-[11px] font-bold text-gray-400 hover:text-gray-600 underline underline-offset-4">
                        ยังไม่ได้จ่าย · กลับไปสแกน QR
                      </button>
                    </template>
                  </PaymentSettlingPanel>
                </div>
                <div v-else class="flex flex-col items-center gap-4 rounded-2xl bg-[var(--color-sand)] px-4 py-6">
                  <div class="relative flex aspect-square w-full max-w-[260px] items-center justify-center rounded-2xl bg-white p-2">
                    <img v-if="beamQrSrc && !beamExpired" :src="beamQrSrc" alt="QR พร้อมเพย์" class="block h-auto w-full rounded-xl" />
                    <div v-if="beamLoading" class="absolute inset-0 flex items-center justify-center rounded-2xl bg-white/90">
                      <div class="h-9 w-9 animate-spin rounded-full border-4 border-[var(--color-sand-dark)] border-t-[var(--color-accent)]"></div>
                    </div>
                    <div v-else-if="beamExpired || !beamPayment" class="absolute inset-0 flex flex-col items-center justify-center gap-3 rounded-2xl bg-white/95 px-6 text-center">
                      <p v-if="beamExpired" class="text-sm font-bold">QR หมดอายุแล้ว</p>
                      <button class="rounded-xl bg-[var(--color-accent)] px-5 py-2.5 text-xs font-black text-white" @click="createBeamCharge()">
                        {{ beamExpired ? 'สร้าง QR ใหม่' : 'แสดง QR' }}
                      </button>
                    </div>
                  </div>
                  <p v-if="beamPayment && !beamExpired" class="text-xs font-bold text-[var(--color-text-mid)]">
                    รอชำระ · QR หมดอายุใน <span class="tabular-nums text-[var(--color-accent)]">{{ beamCountdownText }}</span>
                  </p>
                  <div v-if="beamPayment && !beamExpired" class="w-full">
                    <PaymentSettlingPanel stage="waiting" final-step="ยืนยันห้องพักของคุณอัตโนมัติ" />
                  </div>
                  <p v-if="beamError" class="text-center text-xs font-bold text-red-600">{{ beamError }}</p>
                </div>
              </PayMethodRow>

              <!-- ไม่สะดวกสแกน — บัตร / Apple Pay / ผ่อน / e-Wallet บนหน้าของ Beam -->
              <PaymentCheckoutMethods v-if="checkoutAvailable"
                :amount="Number(b.total_amount)"
                :installment-min="Number(gateway.installment_min_amount || 0)"
                :loading="beamLoading"
                @open="createBeamCharge('CHECKOUT')" />
            </div>
            <button class="mt-3 text-[13px] font-bold text-[var(--color-text-muted)] underline" @click="manualPay = true">
              โอนเองแล้วแนบสลิปแทน
            </button>
          </template>

          <!-- โอนเอง + แนบสลิป (ทางเดิม และทางสำรองเมื่อเกตเวย์ขัดข้อง) -->
          <template v-else>
            <div v-if="pay" class="mt-4 flex flex-col items-center gap-2 rounded-2xl bg-[var(--color-sand)] p-4">
              <img :src="pay.qr_data_uri" alt="QR พร้อมเพย์" class="w-56 rounded-xl bg-white p-2" />
              <p class="text-[13px] font-semibold text-[var(--color-text-mid)]">สแกนจ่ายด้วยแอปธนาคาร · {{ pay.merchant_name }}</p>
              <p v-if="pay.bank_account" class="text-[13px] text-[var(--color-text-muted)]">หรือโอน {{ pay.bank_name }} {{ pay.bank_account }} ({{ pay.bank_holder }})</p>
            </div>
            <label class="slip mt-4" :class="{ 'slip--set': slipPreview }">
              <input type="file" accept="image/*" hidden @change="onSlip" />
              <img v-if="slipPreview" :src="slipPreview" alt="สลิป" class="max-h-48 rounded-xl" />
              <template v-else>
                <span class="material-symbols-rounded icon-32">upload</span>
                <span class="font-bold">แนบสลิปการโอน</span>
              </template>
            </label>
            <button class="btn mt-4" :disabled="!slipFile || uploading" @click="uploadSlip">{{ uploading ? 'กำลังส่ง…' : 'ส่งสลิป' }}</button>
            <button v-if="useBeam" class="mt-3 text-[13px] font-bold text-[var(--color-text-muted)] underline" @click="manualPay = false">
              กลับไปจ่ายผ่าน QR อัตโนมัติ
            </button>
          </template>
        </section>

        <!-- ติดต่อที่พัก (หลังยืนยัน) -->
        <section v-if="b.stay?.host_phone || b.stay?.address" class="card mt-4">
          <h2 class="text-lg font-black text-[var(--color-text-dark)]">ติดต่อที่พัก</h2>
          <p v-if="b.stay.host_name" class="mt-2 font-bold">{{ b.stay.host_name }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <a v-if="b.stay.host_phone" :href="'tel:' + b.stay.host_phone" class="chip"><span class="material-symbols-rounded icon-18">call</span>{{ b.stay.host_phone }}</a>
            <span v-if="b.stay.host_line_id" class="chip"><span class="material-symbols-rounded icon-18">chat</span>LINE {{ b.stay.host_line_id }}</span>
            <a v-if="mapLink" :href="mapLink" target="_blank" rel="noopener" class="chip"><span class="material-symbols-rounded icon-18">map</span>เปิดแผนที่</a>
          </div>
          <p v-if="b.stay.address" class="mt-2 text-[14px] text-[var(--color-text-mid)]">{{ b.stay.address }}</p>
        </section>

        <!-- ยอด -->
        <section class="card mt-4 text-[14px]">
          <h2 class="text-lg font-black text-[var(--color-text-dark)]">รายละเอียดยอด</h2>
          <div class="row mt-3"><span>ค่าห้อง</span><span>฿{{ money(b.room_amount) }}</span></div>
          <div v-if="b.extra_guest_amount" class="row"><span>คนเพิ่ม</span><span>฿{{ money(b.extra_guest_amount) }}</span></div>
          <div v-for="x in b.experiences" :key="x.id" class="row"><span>{{ x.title }} × {{ x.people }}</span><span>฿{{ money(x.amount) }}</span></div>
          <div class="row total"><span>รวม</span><span>฿{{ money(b.total_amount) }}</span></div>
          <div v-if="b.refund_status" class="mt-3 rounded-xl bg-[var(--color-sand)] p-3">
            <p class="font-bold">คืนเงิน ฿{{ money(b.refund_amount) }} · {{ b.refund_status === 'refunded' ? 'โอนคืนแล้ว' : 'รอโอนคืน (ภายใน 7 วันทำการ)' }}</p>
            <template v-if="b.refund_status === 'pending'">
              <p v-if="!b.refund_account_set" class="mt-1 text-sm font-bold text-amber-700">กรุณาแจ้งบัญชีรับเงินคืน</p>
              <AccountForm v-model="account" class="mt-2" />
              <button class="btn mt-2" @click="saveAccount">{{ b.refund_account_set ? 'แก้บัญชีรับเงินคืน' : 'บันทึกบัญชี' }}</button>
            </template>
          </div>
        </section>

        <!-- รีวิว -->
        <section v-if="b.can_review" class="card mt-4">
          <h2 class="text-lg font-black text-[var(--color-text-dark)]">พักที่นี่เป็นยังไงบ้าง?</h2>
          <div class="mt-3 flex gap-1">
            <button v-for="n in 5" :key="n" type="button" class="star" :class="{ on: n <= rating }" @click="rating = n" :aria-label="`${n} ดาว`">★</button>
          </div>
          <textarea v-model="comment" rows="3" maxlength="1000" class="input mt-3" placeholder="เล่าให้คนต่อไปฟังหน่อย เจ้าของบ้าน อาหาร วิว…"></textarea>
          <button class="btn mt-3" :disabled="!rating || reviewing" @click="sendReview">ส่งรีวิว</button>
        </section>
        <section v-else-if="b.review" class="card mt-4">
          <p class="text-amber-500 font-black">{{ '★'.repeat(b.review.rating) }}</p>
          <p class="mt-1">{{ b.review.comment }}</p>
          <p v-if="b.review.host_reply" class="mt-2 text-[13px] text-[var(--color-text-mid)]"><strong>เจ้าของที่พัก:</strong> {{ b.review.host_reply }}</p>
        </section>

        <!-- ยกเลิก -->
        <section v-if="b.can_cancel" class="card mt-4">
          <button v-if="!cancelInfo" class="text-sm font-bold text-red-600 underline" @click="loadCancel">ยกเลิกการจอง</button>
          <template v-else>
            <h2 class="text-lg font-black text-[var(--color-text-dark)]">ยกเลิกการจอง</h2>
            <p class="mt-2 text-[14px] text-[var(--color-text-mid)]">
              <template v-if="cancelInfo.paid_amount > 0">
                ยกเลิกตอนนี้ (ก่อนเข้าพัก {{ cancelInfo.days_before }} วัน) ได้คืน <strong>{{ cancelInfo.refund_percent }}%</strong> = <strong>฿{{ money(cancelInfo.refund_amount) }}</strong> ตามนโยบายแบบ{{ cancelInfo.policy_label }}
              </template>
              <template v-else>ยังไม่ได้ชำระเงิน ยกเลิกได้เลยไม่มีค่าใช้จ่าย</template>
            </p>
            <AccountForm v-if="cancelInfo.needs_account" v-model="account" class="mt-3" />
            <input v-model="cancelReason" class="input mt-3" placeholder="เหตุผล (ไม่บังคับ)" maxlength="300" />
            <div class="mt-3 flex gap-2">
              <button class="btn btn--ghost" @click="cancelInfo = null">ไม่ยกเลิก</button>
              <button class="btn btn--danger" :disabled="cancelling" @click="doCancel">ยืนยันยกเลิก</button>
            </div>
          </template>
        </section>
      </template>
    </div>
  </div>
</template>

<script setup>
import { DATE_LOCALE } from '@/i18n';
import { computed, defineComponent, h, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import { useToast } from '../lib/toast';
import PayMethodRow from '../components/PayMethodRow.vue';
import PaymentCheckoutMethods from '../components/PaymentCheckoutMethods.vue';
import PaymentSettlingPanel from '../components/PaymentSettlingPanel.vue';
import { useBeamCharge } from '../composables/useBeamCharge';

/** ช่องกรอกบัญชีรับเงินคืน — ใช้ทั้งตอนยกเลิกและตอนแจ้งบัญชีทีหลัง */
const AccountForm = defineComponent({
  props: { modelValue: { type: Object, required: true } },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const field = (key, placeholder, extra = {}) => h('input', {
      class: 'input', placeholder, value: props.modelValue[key], ...extra,
      onInput: (e) => emit('update:modelValue', { ...props.modelValue, [key]: e.target.value }),
    });
    return () => h('div', { class: 'grid gap-2' }, [
      field('bank', 'ธนาคาร หรือ "พร้อมเพย์"'),
      field('number', 'เลขบัญชี / เบอร์พร้อมเพย์', { inputmode: 'numeric' }),
      field('name', 'ชื่อบัญชี'),
    ]);
  },
});

const route = useRoute();
const toast = useToast();
const loading = ref(true);
const b = ref(null);
const pay = ref(null);
const slipFile = ref(null);
const slipPreview = ref('');
const uploading = ref(false);
const cancelInfo = ref(null);
const cancelReason = ref('');
const cancelling = ref(false);
const account = ref({ bank: '', number: '', name: '' });
const rating = ref(0);
const comment = ref('');
const reviewing = ref(false);
const manualPay = ref(false);
const now = ref(Date.now());
const timer = setInterval(() => { now.value = Date.now(); }, 30000);
onBeforeUnmount(() => clearInterval(timer));

const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const DAYS = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

const tone = computed(() => {
  const s = b.value?.status;
  if (['confirmed', 'completed'].includes(s)) return 'good';
  if (['declined', 'expired', 'cancelled'].includes(s)) return 'bad';
  return 'wait';
});

const dueText = computed(() => {
  if (!b.value?.payment_due_at) return '';
  const ms = new Date(b.value.payment_due_at).getTime() - now.value;
  if (ms <= 0) return 'หมดเวลาแล้ว';
  const mins = Math.ceil(ms / 60000);
  return mins < 60 ? `${mins} นาที` : `${Math.floor(mins / 60)} ชม. ${mins % 60} นาที`;
});

// ── Beam ─────────────────────────────────────────────────────
// ผู้ยืนยันห้องคือ webhook — หน้านี้แค่รอผลแล้วโหลดใบจองใหม่
const gateway = computed(() => pay.value?.payment_gateway || { provider: 'manual' });
const useBeam = computed(() => gateway.value.provider === 'beam');
const checkoutAvailable = computed(() => useBeam.value && gateway.value.methods?.includes('CHECKOUT'));

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
  resumeWaiting: resumeBeamWaiting,
  create: createBeamPayment,
  stop: stopBeam,
} = useBeamCharge(async () => {
  toast.success('ชำระเงินสำเร็จ ยืนยันที่พักแล้ว');
  await load();
});

function createBeamCharge(methodType = 'QR_PROMPT_PAY') {
  if (!b.value?.can_pay) return null;
  return createBeamPayment({ payment_method_type: methodType }, `/stay-bookings/${b.value.ref}/beam`);
}

const mapLink = computed(() => {
  const s = b.value?.stay;
  if (!s) return null;
  if (s.map_url) return s.map_url;
  return s.latitude && s.longitude ? `https://www.google.com/maps/search/?api=1&query=${s.latitude},${s.longitude}` : null;
});

function money(n) {
  return Number(n || 0).toLocaleString(DATE_LOCALE, { maximumFractionDigits: 2 });
}

function thaiDate(ymd) {
  if (!ymd) return '';
  const [y, m, d] = ymd.split('-').map(Number);
  const dow = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return `${DAYS[dow]} ${d} ${MONTHS[m - 1]} ${String(y + 543).slice(2)}`;
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get(`/stay-bookings/${route.params.ref}`);
    b.value = res.data.data;
    document.title = `ใบจอง ${b.value.ref} | ที่พักชุมชน`;
    if (b.value.can_pay) loadPayment();
    else stopBeam();
  } catch {
    b.value = null;
  } finally {
    loading.value = false;
  }
}

async function loadPayment() {
  try {
    const res = await api.get(`/stay-bookings/${b.value.ref}/payment`);
    pay.value = res.data.data;
    // ออก QR ให้เลย ไม่ต้องกดอีกชั้น — กดรีเฟรชก็ได้ใบเดิมคืน (หลังบ้านคืนใบที่ยังใช้ได้)
    if (useBeam.value && !manualPay.value && !beamPayment.value) createBeamCharge();
  } catch {
    pay.value = null;
  }
}

function onSlip(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  slipFile.value = file;
  slipPreview.value = URL.createObjectURL(file);
}

async function uploadSlip() {
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('slip_image', slipFile.value);
    const res = await api.post(`/stay-bookings/${b.value.ref}/slip`, fd);
    b.value = res.data.data;
    toast.success(res.data.message);
    slipFile.value = null;
    slipPreview.value = '';
  } catch (err) {
    toast.error(err.response?.data?.message || 'ส่งสลิปไม่สำเร็จ');
  } finally {
    uploading.value = false;
  }
}

async function loadCancel() {
  try {
    const res = await api.get(`/stay-bookings/${b.value.ref}/cancel-preview`);
    cancelInfo.value = res.data.data;
  } catch (err) {
    toast.error(err.response?.data?.message || 'โหลดไม่สำเร็จ');
  }
}

async function doCancel() {
  cancelling.value = true;
  try {
    const res = await api.post(`/stay-bookings/${b.value.ref}/cancel`, {
      reason: cancelReason.value || null,
      refund_account: cancelInfo.value.needs_account ? account.value : null,
    });
    b.value = res.data.data;
    cancelInfo.value = null;
    toast.success(res.data.message);
  } catch (err) {
    toast.error(err.response?.data?.message || 'ยกเลิกไม่สำเร็จ');
  } finally {
    cancelling.value = false;
  }
}

async function saveAccount() {
  try {
    const res = await api.put(`/stay-bookings/${b.value.ref}/refund-account`, account.value);
    b.value = res.data.data;
    toast.success(res.data.message);
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  }
}

async function sendReview() {
  reviewing.value = true;
  try {
    const res = await api.post(`/stay-bookings/${b.value.ref}/review`, { rating: rating.value, comment: comment.value || null });
    b.value = res.data.data;
    toast.success(res.data.message);
  } catch (err) {
    toast.error(err.response?.data?.message || 'ส่งรีวิวไม่สำเร็จ');
  } finally {
    reviewing.value = false;
  }
}

watch(() => route.params.ref, (ref) => ref && load(), { immediate: true });
</script>

<style scoped>
.card { padding: 1.25rem; border-radius: 1.5rem; background: #fff; border: 1px solid var(--color-sand-dark); }
.status { display: inline-flex; padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 13px; font-weight: 800; }
.status--good { background: #dcfce7; color: #166534; }
.status--wait { background: #fef3c7; color: #92400e; }
.status--bad { background: #fee2e2; color: #991b1b; }
.mini { display: grid; padding: 0.75rem; border-radius: 1rem; background: var(--color-sand); }
.mini span { font-size: 12px; font-weight: 800; color: var(--color-text-muted); }
.mini strong { font-size: 15px; color: var(--color-text-dark); }
.mini small { font-size: 12px; color: var(--color-text-muted); }
.notice { display: flex; gap: 0.75rem; align-items: flex-start; background: var(--color-accent-tint); border-color: transparent; color: var(--color-text-dark); font-weight: 600; }
.notice .material-symbols-rounded { color: var(--color-accent); }
.notice--bad { background: #fef2f2; }
.notice--bad .material-symbols-rounded { color: #b91c1c; }
.slip { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.25rem; padding: 1.5rem; border: 2px dashed var(--color-sand-dark); border-radius: 1.25rem; color: var(--color-text-muted); cursor: pointer; }
.slip--set { border-color: var(--color-accent); }
.btn { display: block; width: 100%; padding: 0.8rem; border-radius: 999px; background: var(--color-accent); color: #fff; font-weight: 900; }
.btn:disabled { opacity: 0.45; }
.btn--ghost { background: var(--color-sand); color: var(--color-text-dark); }
.btn--danger { background: #dc2626; }
.chip { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.45rem 0.85rem; border-radius: 999px; background: var(--color-sand); font-size: 14px; font-weight: 700; color: var(--color-text-dark); }
.row { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.2rem 0; color: var(--color-text-mid); }
.row.total { margin-top: 0.3rem; padding-top: 0.5rem; border-top: 1px dashed var(--color-sand-dark); font-size: 16px; font-weight: 900; color: var(--color-text-dark); }
.star { font-size: 2rem; line-height: 1; color: var(--color-sand-dark); }
.star.on { color: #f59e0b; }
:deep(.input), .input { width: 100%; padding: 0.65rem 0.8rem; border-radius: 0.8rem; border: 1px solid var(--color-sand-dark); font-size: 15px; }
</style>
