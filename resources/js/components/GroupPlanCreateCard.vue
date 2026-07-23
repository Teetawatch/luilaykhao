<template>
  <div class="rounded-[1.25rem] border border-gray-200 bg-white p-4">
    <div class="flex items-start gap-3">
      <span class="material-symbols-rounded text-[22px] text-[var(--color-primary)] shrink-0 mt-0.5">group_add</span>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-extrabold text-[var(--color-text-dark)]">ไปกันหลายคน?</p>
        <p class="text-xs font-medium text-[var(--color-text-muted)] mt-0.5 leading-relaxed">
          ตั้งกลุ่มแล้วแชร์ลิงก์ให้เพื่อน แต่ละคนเลือกที่นั่งและกรอกข้อมูลเอง แล้วคุณค่อยจองรวมทีเดียว
        </p>

        <button
          v-if="!open"
          type="button"
          class="mt-3 text-sm font-bold text-[var(--color-primary)]"
          @click="start"
        >
          ชวนเพื่อนไปด้วยกัน
        </button>

        <form v-else class="mt-3 space-y-3" @submit.prevent="submit">
          <label class="block">
            <span class="text-xs font-bold text-[var(--color-text-muted)] block mb-1">ชื่อกลุ่ม (ไม่ใส่ก็ได้)</span>
            <input
              v-model.trim="name"
              maxlength="120"
              placeholder="เช่น ทีมออฟฟิศ, ก๊วนเดินป่า"
              class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm"
            />
          </label>

          <label class="block">
            <span class="text-xs font-bold text-[var(--color-text-muted)] block mb-1">จองไว้กี่ที่นั่ง</span>
            <input
              v-model.number="seatCount"
              type="number"
              min="1"
              :max="maxSeats"
              class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm"
            />
            <span class="text-[11px] text-[var(--color-text-muted)] mt-1 block">
              กันที่นั่งไว้ให้กลุ่มก่อน เพื่อนที่ยังไม่มาเลือกก็ไม่โดนคนอื่นแย่ง
            </span>
          </label>

          <div class="flex gap-2">
            <button
              type="button"
              class="flex-1 rounded-xl border border-gray-200 py-2.5 text-sm font-bold text-[var(--color-text-muted)]"
              @click="open = false"
            >
              ยกเลิก
            </button>
            <button
              type="submit"
              :disabled="busy || !seatCount"
              class="flex-1 rounded-xl bg-[var(--color-primary)] py-2.5 text-sm font-bold text-white disabled:opacity-40"
            >
              {{ busy ? 'กำลังสร้าง...' : 'สร้างกลุ่ม' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../lib/axios';
import { useAuthStore } from '../stores/auth';
import { useToast } from '../lib/toast';

const props = defineProps({
  scheduleId: { type: [Number, String], required: true },
  availableSeats: { type: Number, default: 20 },
});

const router = useRouter();
const auth = useAuthStore();
const toast = useToast();

const open = ref(false);
const busy = ref(false);
const name = ref('');
const seatCount = ref(2);

// เพดานเดียวกับ validation ฝั่ง API (max:20) แต่ไม่เกินที่นั่งที่ยังว่างจริง
const maxSeats = Math.max(1, Math.min(20, props.availableSeats || 20));

function start() {
  if (!auth.isLoggedIn) {
    router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
    return;
  }
  open.value = true;
}

async function submit() {
  busy.value = true;
  try {
    const res = await api.post(`/schedules/${props.scheduleId}/group-plans`, {
      seat_count: seatCount.value,
      name: name.value || null,
    });
    const code = res.data?.data?.invite_code;
    toast.success(res.data?.message || 'สร้างกลุ่มแล้ว');
    if (code) router.push(`/group/${code}`);
  } catch (err) {
    toast.error(err.response?.data?.message || 'สร้างกลุ่มไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}
</script>
