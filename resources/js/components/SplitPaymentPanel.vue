<template>
  <div class="p-4 bg-accent-tint rounded-[16px] border border-accent/15">
    <div v-if="loading" class="py-3 text-center">
      <div class="inline-block w-5 h-5 border-2 border-accent border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-center gap-2 mb-3">
        <span class="material-symbols-rounded text-accent text-[20px]" style="font-variation-settings:'FILL' 1">groups</span>
        <span class="text-sm font-black text-accent-mid">{{ $t('แบ่งจ่ายกับเพื่อน') }}</span>
        <span v-if="split?.enabled" class="ml-auto text-xs font-bold text-accent">
          {{ $t('จ่ายแล้ว {paid_shares}/{total_shares} · คงเหลือ ฿{money}', { paid_shares: split.paid_shares, total_shares: split.total_shares, money: money(split.outstanding_amount) }) }}
        </span>
      </div>

      <!-- ยังไม่เริ่มแบ่งจ่าย -->
      <div v-if="!split?.enabled">
        <p class="text-xs font-bold text-accent-mid/70 mb-3 leading-relaxed">
          {{ $t('แบ่งยอดคงเหลือ ฿{money} ให้เพื่อนร่วมทริปช่วยจ่าย แต่ละคนจ่ายส่วนของตัวเองผ่านลิงก์ ไม่ต้องออกเงินก้อนเดียว', { money: money(split?.outstanding_amount) }) }}
        </p>
        <button
          v-if="split?.is_owner"
          @click="setup"
          :disabled="busy"
          class="w-full py-2.5 rounded-full bg-accent text-white font-extrabold text-sm hover:bg-accent-mid transition disabled:opacity-50">
          {{ busy ? $t('กำลังสร้าง...') : $t('แบ่งจ่ายกับเพื่อน (หารเท่ากัน)') }}
        </button>
      </div>

      <!-- แบ่งจ่ายแล้ว: รายการส่วนแบ่ง -->
      <div v-else class="space-y-2">
        <div
          v-for="s in split.shares"
          :key="s.id"
          class="flex items-center gap-2.5 p-2.5 rounded-xl bg-white border"
          :class="s.is_mine && s.status !== 'paid' ? 'border-accent/40' : 'border-gray-100'">
          <div class="w-8 h-8 rounded-full shrink-0 flex items-center justify-center"
               :class="s.status === 'paid' ? 'bg-green-100 text-green-600' : 'bg-amber-100 text-amber-600'">
            <span class="material-symbols-rounded text-[17px]">{{ s.status === 'paid' ? 'check' : 'hourglass_bottom' }}</span>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-[#1a1c1c] truncate">
              {{ s.name }}<span v-if="s.is_mine" class="text-accent"> {{ $t('(คุณ)') }}</span>
            </p>
            <p class="text-xs font-bold" :class="s.status === 'paid' ? 'text-green-600' : 'text-amber-600'">
              {{ $t('฿{money} · {v}', { money: money(s.amount), v: s.status === 'paid' ? $t('จ่ายแล้ว') : $t('รอจ่าย') }) }}
            </p>
          </div>
          <!-- Actions ต่อส่วนแบ่ง (เฉพาะที่ยังไม่จ่าย) -->
          <div v-if="s.status !== 'paid'" class="flex items-center gap-1 shrink-0">
            <a
              v-if="s.is_mine && s.pay_url"
              :href="s.pay_url"
              target="_blank"
              class="px-3 py-1.5 rounded-full bg-accent text-white text-xs font-extrabold hover:bg-accent-mid transition">
              {{ $t('จ่าย') }}
            </a>
            <button
              v-if="s.pay_url && (split.is_owner || s.is_mine)"
              @click="copyLink(s)"
              class="w-8 h-8 rounded-full border border-gray-200 text-gray-500 hover:bg-gray-50 transition flex items-center justify-center"
              :title="$t('คัดลอกลิงก์จ่าย')">
              <span class="material-symbols-rounded text-[16px]">{{ copiedId === s.id ? 'check' : 'link' }}</span>
            </button>
            <button
              v-if="split.is_owner && s.member_id"
              @click="remind(s)"
              :disabled="busy"
              class="w-8 h-8 rounded-full border border-gray-200 text-gray-500 hover:bg-gray-50 transition flex items-center justify-center"
              :title="$t('เตือน')">
              <span class="material-symbols-rounded text-[16px]">notifications_active</span>
            </button>
          </div>
        </div>

        <!-- Owner controls -->
        <button
          v-if="split.is_owner"
          @click="cancel"
          :disabled="busy"
          class="w-full mt-1 text-xs font-bold text-gray-400 hover:text-red-500 transition">
          {{ $t('ยกเลิกการแบ่งจ่าย') }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { $t } from '@/i18n';
import { DATE_LOCALE } from '@/i18n';
import { ref, onMounted } from 'vue';
import api from '../lib/axios';

const props = defineProps({
  bookingRef: { type: String, required: true },
});

const split = ref(null);
const loading = ref(true);
const busy = ref(false);
const copiedId = ref(null);

function money(v) {
  return Number(v || 0).toLocaleString(DATE_LOCALE, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

async function load() {
  try {
    const res = await api.get(`/bookings/${props.bookingRef}/split`);
    split.value = res.data.data;
  } catch {
    split.value = null;
  } finally {
    loading.value = false;
  }
}

async function setup() {
  busy.value = true;
  try {
    const res = await api.post(`/bookings/${props.bookingRef}/split`);
    split.value = res.data.data;
  } catch (e) {
    alert(e?.response?.data?.message || $t('เริ่มแบ่งจ่ายไม่สำเร็จ'));
  } finally {
    busy.value = false;
  }
}

async function remind(s) {
  busy.value = true;
  try {
    await api.post(`/bookings/${props.bookingRef}/split/shares/${s.id}/remind`);
    alert($t('ส่งเตือน {name} แล้ว', { name: s.name }));
  } catch (e) {
    alert(e?.response?.data?.message || $t('ส่งเตือนไม่สำเร็จ'));
  } finally {
    busy.value = false;
  }
}

async function cancel() {
  if (!confirm($t('ยกเลิกการแบ่งจ่าย? ส่วนที่เพื่อนจ่ายแล้วยังคงอยู่ ยอดที่เหลือกลับไปชำระรวมตามปกติ'))) return;
  busy.value = true;
  try {
    await api.delete(`/bookings/${props.bookingRef}/split`);
    await load();
  } catch (e) {
    alert(e?.response?.data?.message || $t('ยกเลิกไม่สำเร็จ'));
  } finally {
    busy.value = false;
  }
}

async function copyLink(s) {
  try {
    await navigator.clipboard.writeText(s.pay_url);
    copiedId.value = s.id;
    setTimeout(() => { if (copiedId.value === s.id) copiedId.value = null; }, 1500);
  } catch {
    alert(s.pay_url);
  }
}

onMounted(load);
</script>
