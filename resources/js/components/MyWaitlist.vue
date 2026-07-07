<template>
  <!-- คิวรอที่นั่งของฉัน — แสดงบนหน้าการจองเมื่อมีรายการที่ยัง active -->
  <section v-if="entries.length > 0" class="mb-8">
    <h2 class="text-lg font-black text-[#1a1c1c] mb-3 flex items-center gap-2" style="font-family:'DB Heavent','Anuphan',sans-serif;">
      <span class="material-symbols-rounded text-[var(--color-accent)]">hourglass_top</span>
      คิวรอที่นั่งของฉัน
    </h2>
    <div class="grid gap-3 sm:grid-cols-2">
      <div
        v-for="e in entries"
        :key="e.id"
        class="p-4 rounded-[16px] border bg-white flex items-center gap-3"
        :class="e.status === 'offered' ? 'border-emerald-300 bg-emerald-50/40' : 'border-[#E8EEEF]'">
        <img
          v-if="e.schedule?.trip?.cover_image || e.schedule?.trip?.thumbnail_image"
          :src="e.schedule.trip.cover_image || e.schedule.trip.thumbnail_image"
          class="w-14 h-14 rounded-xl object-cover shrink-0" />
        <div class="min-w-0 flex-1">
          <p class="font-extrabold text-sm text-[#1a1c1c] truncate">{{ e.schedule?.trip?.title || 'ทริป' }}</p>
          <p class="text-xs font-bold text-[#889696]">{{ formatDate(e.schedule?.departure_date) }} · {{ e.seat_count }} ที่นั่ง</p>
          <div class="mt-1">
            <span v-if="e.status === 'offered'" class="text-xs font-black text-emerald-600">
              🎉 มีที่นั่งว่าง! <span v-if="countdowns[e.id]" class="tabular-nums">({{ countdowns[e.id] }})</span>
            </span>
            <span v-else class="text-xs font-bold text-[var(--color-accent)]">
              ลำดับในคิว {{ e.position ? `#${e.position}` : '—' }}
            </span>
          </div>
        </div>
        <div class="flex flex-col gap-1.5 shrink-0">
          <router-link
            v-if="e.status === 'offered'"
            :to="`/booking/${e.schedule_id}`"
            class="text-center px-3 py-1.5 rounded-full bg-[var(--color-primary)] text-white text-xs font-extrabold hover:bg-[var(--color-accent)] transition">
            จองเลย
          </router-link>
          <router-link
            :to="`/trips/${e.schedule?.trip?.slug}`"
            class="text-center px-3 py-1.5 rounded-full border border-[#E8EEEF] text-[#505E5E] text-xs font-bold hover:bg-[#F9FAFA] transition">
            ดูทริป
          </router-link>
          <button @click="leave(e)" :disabled="busyId === e.id" class="text-[11px] font-bold text-gray-400 hover:text-red-500 transition">
            ออกจากคิว
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue';
import api from '../lib/axios';

const entries = ref([]);
const busyId = ref(null);
const countdowns = reactive({});
let timer = null;

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

async function load() {
  try {
    const res = await api.get('/waitlist');
    // แสดงเฉพาะที่ยัง active (waiting / offered)
    entries.value = (res.data.data || []).filter(e => e.status === 'waiting' || e.status === 'offered');
    tickCountdowns();
  } catch {
    entries.value = [];
  }
}

async function leave(e) {
  busyId.value = e.id;
  try {
    await api.delete(`/schedules/${e.schedule_id}/waitlist`);
    entries.value = entries.value.filter(x => x.id !== e.id);
  } catch (err) {
    alert(err?.response?.data?.message || 'ออกจากคิวไม่สำเร็จ');
  } finally {
    busyId.value = null;
  }
}

function tickCountdowns() {
  for (const e of entries.value) {
    if (e.status !== 'offered' || !e.expires_at) { delete countdowns[e.id]; continue; }
    const secs = Math.floor((new Date(e.expires_at) - Date.now()) / 1000);
    if (secs <= 0) { delete countdowns[e.id]; continue; }
    const m = String(Math.floor(secs / 60)).padStart(2, '0');
    const s = String(secs % 60).padStart(2, '0');
    countdowns[e.id] = `${m}:${s}`;
  }
}

onMounted(() => {
  load();
  timer = setInterval(tickCountdowns, 1000);
});
onUnmounted(() => { if (timer) clearInterval(timer); });
</script>
