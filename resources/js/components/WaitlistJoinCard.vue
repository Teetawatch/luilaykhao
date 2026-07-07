<template>
  <!-- คิวรอที่นั่ง — แสดงบนหน้าทริปเมื่อรอบที่เลือกเต็ม -->
  <div class="p-5 rounded-[1.5rem] border-2 border-dashed border-[var(--color-accent)]/40 bg-[var(--color-accent)]/5">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-11 h-11 rounded-full bg-[var(--color-accent)] flex items-center justify-center text-white shrink-0 shadow-md">
        <span class="material-symbols-rounded text-[22px]">hourglass_top</span>
      </div>
      <div>
        <p class="font-extrabold text-[var(--color-text-dark)] text-base leading-tight">รอบนี้เต็มแล้ว</p>
        <p class="text-xs font-bold text-[var(--color-text-muted)]">เข้าคิวรอ — เราจะแจ้งทันทีที่มีที่นั่งว่าง</p>
      </div>
    </div>

    <div v-if="loading" class="py-4 text-center">
      <div class="inline-block w-6 h-6 border-2 border-[var(--color-accent)] border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- ยังไม่อยู่ในคิว -->
      <div v-if="!entry || entry.status === 'left'">
        <div class="flex items-center gap-2 mb-3">
          <label class="text-xs font-bold text-[var(--color-text-muted)]">จำนวนที่นั่ง</label>
          <select v-model.number="seatCount" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm font-bold bg-white">
            <option v-for="n in 10" :key="n" :value="n">{{ n }}</option>
          </select>
        </div>
        <button
          @click="join"
          :disabled="submitting"
          class="w-full py-3 rounded-full bg-[var(--color-accent)] text-white font-extrabold text-base hover:brightness-95 transition disabled:opacity-50">
          {{ submitting ? 'กำลังเข้าคิว...' : 'เข้าคิวรอที่นั่ง' }}
        </button>
      </div>

      <!-- ได้รับข้อเสนอที่นั่ง -->
      <div v-else-if="entry.status === 'offered'" class="space-y-3">
        <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200">
          <p class="text-sm font-black text-emerald-700 flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">celebration</span>
            มีที่นั่งว่างสำหรับคุณแล้ว!
          </p>
          <p v-if="countdown" class="text-xs font-bold text-emerald-600 mt-1">
            รีบจองภายใน <span class="tabular-nums">{{ countdown }}</span> ก่อนสิทธิ์หมดอายุ
          </p>
        </div>
        <router-link
          :to="`/booking/${scheduleId}`"
          class="block text-center py-3 rounded-full bg-[var(--color-primary)] text-white font-extrabold text-base hover:bg-[var(--color-accent)] transition">
          จองที่นั่งเลย
        </router-link>
        <button @click="leave" :disabled="submitting" class="w-full text-xs font-bold text-gray-400 hover:text-red-500 transition">
          ออกจากคิว
        </button>
      </div>

      <!-- อยู่ในคิว รอที่นั่ง -->
      <div v-else class="space-y-3">
        <div class="flex items-center justify-between p-3 rounded-xl bg-white border border-gray-100">
          <span class="text-sm font-bold text-[var(--color-text-muted)]">ลำดับในคิว</span>
          <span class="text-2xl font-black text-[var(--color-accent)]">
            {{ entry.position ? `#${entry.position}` : '—' }}
          </span>
        </div>
        <button @click="leave" :disabled="submitting" class="w-full py-2.5 rounded-full border border-red-200 text-red-600 font-bold text-sm hover:bg-red-50 transition">
          ออกจากคิว
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import api from '../lib/axios';
import { useAuthStore } from '../stores/auth';
import { useRouter } from 'vue-router';

const props = defineProps({
  scheduleId: { type: [Number, String], required: true },
});

const auth = useAuthStore();
const router = useRouter();

const entry = ref(null);
const loading = ref(true);
const submitting = ref(false);
const seatCount = ref(1);
const countdown = ref('');
let timer = null;

async function loadStatus() {
  if (!auth.isLoggedIn) { loading.value = false; return; }
  loading.value = true;
  try {
    const res = await api.get(`/schedules/${props.scheduleId}/waitlist/status`);
    entry.value = res.data.data?.in_waitlist ? res.data.data : null;
    startCountdown();
  } catch {
    entry.value = null;
  } finally {
    loading.value = false;
  }
}

async function join() {
  if (!auth.isLoggedIn) {
    router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
    return;
  }
  submitting.value = true;
  try {
    const res = await api.post(`/schedules/${props.scheduleId}/waitlist`, { seat_count: seatCount.value });
    entry.value = res.data.data;
    startCountdown();
  } catch (e) {
    alert(e?.response?.data?.message || 'เข้าคิวไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

async function leave() {
  submitting.value = true;
  try {
    await api.delete(`/schedules/${props.scheduleId}/waitlist`);
    entry.value = null;
    stopCountdown();
  } catch (e) {
    alert(e?.response?.data?.message || 'ออกจากคิวไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

function startCountdown() {
  stopCountdown();
  if (entry.value?.status !== 'offered' || !entry.value?.expires_at) return;
  const tick = () => {
    const secs = Math.floor((new Date(entry.value.expires_at) - Date.now()) / 1000);
    if (secs <= 0) { countdown.value = ''; loadStatus(); return; }
    const m = String(Math.floor(secs / 60)).padStart(2, '0');
    const s = String(secs % 60).padStart(2, '0');
    countdown.value = `${m}:${s}`;
  };
  tick();
  timer = setInterval(tick, 1000);
}

function stopCountdown() {
  if (timer) { clearInterval(timer); timer = null; }
}

watch(() => props.scheduleId, loadStatus);
onMounted(loadStatus);
onUnmounted(stopCountdown);
</script>
