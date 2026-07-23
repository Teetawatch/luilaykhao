<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">กลุ่มไปด้วยกัน</h1>
        <p class="text-[#505E5E] text-sm">
          กลุ่มที่คุณตั้งเองหรือถูกเพื่อนชวน — แต่ละคนเลือกที่นั่งของตัวเอง แล้วค่อยจองรวมทีเดียว
        </p>
      </section>

      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm">กำลังโหลดกลุ่ม...</p>
      </div>

      <div v-else-if="!plans.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">group</span>
        <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ยังไม่มีกลุ่ม</p>
        <p class="text-[#505E5E] text-sm mb-5">
          เปิดหน้าทริปที่อยากไป แล้วกด "ชวนเพื่อนไปด้วยกัน" เพื่อตั้งกลุ่มแรกของคุณ
        </p>
        <router-link
          to="/trips"
          class="inline-flex items-center gap-1.5 rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3"
        >
          <span class="material-symbols-rounded text-[18px]">explore</span>
          ดูทริปที่เปิดอยู่
        </router-link>
      </div>

      <ul v-else class="space-y-3">
        <li v-for="plan in plans" :key="plan.id">
          <router-link
            :to="`/group/${plan.invite_code}`"
            class="block bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden"
          >
            <div class="flex gap-4 p-4">
              <img
                v-if="planImage(plan)"
                :src="planImage(plan)"
                :alt="plan.trip?.title"
                class="w-20 h-20 rounded-[14px] object-cover shrink-0"
              />
              <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-3">
                  <p class="font-bold text-[#1a1c1c] text-sm leading-snug">
                    {{ plan.name || plan.trip?.title || 'กลุ่มเดินทาง' }}
                  </p>
                  <span
                    class="rounded-full px-2.5 py-0.5 text-[11px] font-bold shrink-0"
                    :class="plan.booking_ref
                      ? 'bg-[#006565]/10 text-[#006565]'
                      : plan.is_open ? 'bg-emerald-50 text-emerald-700' : 'bg-[#F4F7F6] text-[#8A9A9A]'"
                  >
                    {{ plan.booking_ref ? 'จองแล้ว' : plan.is_open ? 'กำลังรวมกลุ่ม' : 'ปิดรับแล้ว' }}
                  </span>
                </div>

                <p v-if="plan.name && plan.trip?.title" class="text-[12px] text-[#505E5E] mt-0.5 truncate">
                  {{ plan.trip.title }}
                </p>

                <p class="text-[12px] text-[#8A9A9A] mt-1.5">
                  <span v-if="plan.schedule?.departure_date">{{ thaiShort(plan.schedule.departure_date) }} · </span>
                  {{ plan.claimed_seat_ids?.length || 0 }} / {{ plan.seat_count }} ที่นั่ง
                  <span v-if="plan.is_host"> · คุณเป็นหัวหน้ากลุ่ม</span>
                </p>

                <div class="flex -space-x-2 mt-2.5">
                  <template v-for="member in plan.members.slice(0, 5)" :key="member.id">
                    <img
                      v-if="member.avatar_url"
                      :src="member.avatar_url"
                      :alt="member.display_name"
                      class="w-7 h-7 rounded-full object-cover border-2 border-white"
                    />
                    <span
                      v-else
                      class="w-7 h-7 rounded-full bg-[#E8EEEF] text-[#505E5E] text-[11px] font-bold flex items-center justify-center border-2 border-white"
                    >{{ (member.display_name || '?').charAt(0) }}</span>
                  </template>
                  <span
                    v-if="plan.members.length > 5"
                    class="w-7 h-7 rounded-full bg-[#F4F7F6] text-[#8A9A9A] text-[11px] font-bold flex items-center justify-center border-2 border-white"
                  >+{{ plan.members.length - 5 }}</span>
                </div>
              </div>
            </div>
          </router-link>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../lib/axios';
import { thaiShort } from '../lib/thaiDate';

const loading = ref(true);
const plans = ref([]);

function planImage(plan) {
  return plan.trip?.thumbnail_image || plan.trip?.cover_image || null;
}

onMounted(async () => {
  try {
    const res = await api.get('/group-plans/mine');
    plans.value = res.data?.data || [];
  } finally {
    loading.value = false;
  }
});
</script>
