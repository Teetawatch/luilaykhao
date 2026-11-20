<template>
  <div class="space-y-5" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
    <div v-if="loading" class="flex justify-center py-16">
      <div class="w-8 h-8 border-4 border-[var(--color-accent)]/20 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
    </div>

    <template v-else-if="info">
      <!-- รหัสสมาชิกของฉัน — ให้เพื่อนเอาไปกรอกตอนจะโอนแต้มมาให้ -->
      <div class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 flex items-center gap-4">
        <div class="flex-1 min-w-0">
          <p class="text-[13px] font-bold text-[#505E5E]">{{ $t('รหัสสมาชิกของคุณ') }}</p>
          <p class="font-mono text-2xl font-bold text-[var(--color-accent)] tracking-widest mt-0.5">{{ info.member_code }}</p>
          <p class="text-[12px] text-[#889696] mt-1">{{ $t('ส่งรหัสนี้ให้เพื่อน เพื่อให้เพื่อนโอนแต้มมาให้คุณได้') }}</p>
        </div>
        <button @click="copyCode"
          class="shrink-0 flex items-center gap-1.5 px-4 py-2.5 rounded-[12px] border border-[#BCDFDF] text-[var(--color-accent)] text-sm font-bold hover:bg-[#F0FAFA] transition-colors">
          <span class="material-symbols-rounded text-[18px]">{{ copied ? 'check' : 'content_copy' }}</span>
          {{ copied ? $t('คัดลอกแล้ว') : $t('คัดลอก') }}
        </button>
      </div>

      <!-- ฟอร์มโอน -->
      <div class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
        <h2 class="text-lg font-bold text-[#1a1c1c] mb-1">{{ $t('โอนแต้มให้เพื่อน') }}</h2>
        <p class="text-[13px] text-[#505E5E] mb-5"> {{ $t('ขั้นต่ำครั้งละ {fmt} แต้ม · วันนี้โอนได้อีก {fmt2} แต้ม ({sent_today_count}/{daily_max_count} ครั้ง)', { fmt: fmt(info.limits.min_points), fmt2: fmt(info.limits.remaining_today_points), sent_today_count: info.limits.sent_today_count, daily_max_count: info.limits.daily_max_count }) }} </p>

        <p v-if="!info.limits.enabled" class="text-sm font-bold text-[#B45309] bg-[#FFFBEB] border border-[#FDE68A] rounded-[12px] px-4 py-3"> {{ $t('ระบบโอนแต้มปิดให้บริการชั่วคราว') }} </p>

        <template v-else>
          <label class="block text-[13px] font-bold text-[#505E5E] mb-1.5" for="pt-recipient">{{ $t('ผู้รับ') }}</label>
          <div class="flex gap-2">
            <input id="pt-recipient" v-model.trim="recipientInput" @input="recipient = null" @keyup.enter="lookup"
              type="text" autocomplete="off" :placeholder="$t('เบอร์โทร อีเมล หรือรหัสสมาชิกของเพื่อน')"
              class="flex-1 min-w-0 rounded-[12px] border border-[#D5DEDE] px-4 py-3 text-[15px] focus:outline-none focus:border-[var(--color-accent)]" />
            <button @click="lookup" :disabled="!recipientInput || looking"
              class="shrink-0 px-4 rounded-[12px] bg-[#E3F2F2] text-[var(--color-accent)] text-sm font-bold disabled:opacity-50">
              {{ looking ? $t('กำลังค้นหา...') : $t('ตรวจสอบ') }}
            </button>
          </div>
          <p v-if="lookupError" class="text-[13px] text-[#DC2626] font-medium mt-2">{{ lookupError }}</p>

          <div v-if="recipient" class="mt-4 flex items-center gap-3 bg-[#F0FAFA] border border-[#BCDFDF] rounded-[14px] px-4 py-3">
            <img v-if="recipient.avatar_url" :src="recipient.avatar_url" alt="" class="w-10 h-10 rounded-full object-cover" />
            <div v-else class="w-10 h-10 rounded-full bg-[var(--color-accent)] text-white font-bold flex items-center justify-center">{{ recipient.initial }}</div>
            <div class="min-w-0">
              <p class="text-[12px] text-[#505E5E]">{{ $t('โอนให้') }}</p>
              <p class="font-bold text-[#1a1c1c] truncate">{{ recipient.display_name }}</p>
            </div>
            <span class="material-symbols-rounded text-[#0F6B5C] ml-auto" style="font-variation-settings:'FILL' 1">verified</span>
          </div>

          <template v-if="recipient">
            <label class="block text-[13px] font-bold text-[#505E5E] mt-5 mb-1.5" for="pt-points">{{ $t('จำนวนแต้ม') }}</label>
            <input id="pt-points" v-model.number="points" type="number" inputmode="numeric"
              :min="info.limits.min_points" :max="maxSendable"
              class="w-full rounded-[12px] border border-[#D5DEDE] px-4 py-3 text-xl font-bold focus:outline-none focus:border-[var(--color-accent)]" />
            <div class="flex flex-wrap gap-2 mt-2">
              <button v-for="q in quickAmounts" :key="q" @click="points = q"
                class="px-3 py-1.5 rounded-full border text-[13px] font-bold transition-colors"
                :class="points === q ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white' : 'border-[#D5DEDE] text-[#505E5E] hover:border-[var(--color-accent)]'">
                {{ fmt(q) }}
              </button>
            </div>
            <p class="text-[12px] text-[#889696] mt-2">{{ $t('คุณมี {fmt} แต้ม · โอนได้สูงสุด {fmt2} แต้ม', { fmt: fmt(info.points), fmt2: fmt(maxSendable) }) }}</p>

            <label class="block text-[13px] font-bold text-[#505E5E] mt-5 mb-1.5" for="pt-message">{{ $t('ข้อความถึงเพื่อน (ไม่บังคับ)') }}</label>
            <input id="pt-message" v-model="message" type="text" maxlength="120" :placeholder="$t('เช่น ขอบคุณที่ชวนไปเที่ยวนะ')"
              class="w-full rounded-[12px] border border-[#D5DEDE] px-4 py-3 text-[15px] focus:outline-none focus:border-[var(--color-accent)]" />

            <button @click="send" :disabled="!canSend || sending"
              class="mt-6 w-full bg-[var(--color-accent)] text-white py-3.5 rounded-[14px] font-bold hover:bg-[var(--color-accent-mid)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
              <span v-if="sending" class="material-symbols-rounded text-[18px] animate-spin">progress_activity</span>
              {{ sending ? $t('กำลังโอน...') : $t('โอน {fmt} แต้ม', { fmt: fmt(points || 0) }) }}
            </button>
            <p class="text-[12px] text-[#889696] mt-2 text-center">{{ $t('แต้มที่โอนแล้วเรียกคืนไม่ได้ และยังหมดอายุตามวันเดิมของแต้มก้อนนั้น') }}</p>
          </template>
        </template>
      </div>

      <!-- ประวัติโอนเข้า-ออก -->
      <div v-if="info.history.length" class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden">
        <h3 class="text-[13px] font-bold text-[#505E5E] px-5 pt-5 pb-3">{{ $t('ประวัติการโอนแต้ม') }}</h3>
        <div v-for="h in info.history" :key="h.id" class="flex items-center gap-3 px-5 py-3.5 border-t border-[#F0F4F4]">
          <span class="material-symbols-rounded text-[22px]" :class="h.direction === 'in' ? 'text-[var(--color-accent)]' : 'text-[#D97706]'">
            {{ h.direction === 'in' ? 'call_received' : 'call_made' }}
          </span>
          <div class="flex-1 min-w-0">
            <p class="text-[14px] font-bold text-[#1a1c1c] truncate">
              {{ h.direction === 'in' ? $t('ได้รับจาก') : $t('โอนให้') }} {{ h.counterpart_name }}
            </p>
            <p v-if="h.message" class="text-[12px] text-[#505E5E] truncate">"{{ h.message }}"</p>
            <p class="text-[12px] text-[#A0B0B0]">{{ thaiShort(h.created_at) }}</p>
          </div>
          <p class="font-bold text-lg shrink-0" :class="h.direction === 'in' ? 'text-[var(--color-accent)]' : 'text-[#D97706]'">
            {{ h.direction === 'in' ? '+' : '-' }}{{ fmt(h.points) }}
          </p>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { $t } from '@/i18n';
import { ref, computed, onMounted } from 'vue';
import api from '../lib/axios';
import { thaiShort } from '../lib/thaiDate';

const emit = defineEmits(['transferred']);

const loading = ref(true);
const info = ref(null);
const recipientInput = ref('');
const recipient = ref(null);
const lookupError = ref('');
const looking = ref(false);
const points = ref(null);
const message = ref('');
const sending = ref(false);
const copied = ref(false);

const fmt = (n) => Number(n || 0).toLocaleString();

const maxSendable = computed(() => Math.min(info.value?.points ?? 0, info.value?.limits.remaining_today_points ?? 0));

const quickAmounts = computed(() =>
  [10, 50, 100, 500].filter(q => q >= info.value.limits.min_points && q <= maxSendable.value));

const canSend = computed(() =>
  recipient.value
  && Number.isInteger(points.value)
  && points.value >= info.value.limits.min_points
  && points.value <= maxSendable.value);

async function load() {
  try {
    const res = await api.get('/loyalty/transfers');
    info.value = res.data.data;
  } finally {
    loading.value = false;
  }
}

async function lookup() {
  if (!recipientInput.value) return;
  looking.value = true;
  lookupError.value = '';
  recipient.value = null;
  try {
    const res = await api.post('/loyalty/transfers/lookup', { recipient: recipientInput.value });
    recipient.value = res.data.data;
  } catch (e) {
    lookupError.value = e?.response?.data?.message || $t('ค้นหาไม่สำเร็จ ลองอีกครั้ง');
  } finally {
    looking.value = false;
  }
}

async function send() {
  if (!canSend.value) return;
  if (!confirm($t('ยืนยันโอน {fmt} แต้ม ให้ {display_name}?', { fmt: fmt(points.value), display_name: recipient.value.display_name }))) return;
  sending.value = true;
  try {
    const res = await api.post('/loyalty/transfers', {
      recipient: recipientInput.value,
      points: points.value,
      message: message.value || null,
    });
    alert($t('โอน {fmt} แต้ม ให้ {recipient_name} แล้ว', { fmt: fmt(res.data.data.points), recipient_name: res.data.data.recipient_name }));
    recipientInput.value = '';
    recipient.value = null;
    points.value = null;
    message.value = '';
    await load();
    emit('transferred');
  } catch (e) {
    alert(e?.response?.data?.message || $t('โอนแต้มไม่สำเร็จ'));
  } finally {
    sending.value = false;
  }
}

async function copyCode() {
  try {
    await navigator.clipboard.writeText(info.value.member_code);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch {
    // clipboard ถูกบล็อก — รหัสยังอยู่บนจอให้จดเองได้
  }
}

onMounted(load);
</script>
