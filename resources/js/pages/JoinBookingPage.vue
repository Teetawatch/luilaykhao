<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32 font-anuphan">
    <div class="max-w-lg mx-auto px-4 sm:px-6">

      <!-- Loading -->
      <div v-if="loading" class="flex justify-center py-24">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <!-- คำเชิญใช้ไม่ได้ (หมดอายุ/ถูกใช้แล้ว/ลิงก์ผิด) -->
      <div v-else-if="error" class="bg-white rounded-[24px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-4xl text-[#E11D48]">link_off</span>
        <h1 class="text-xl font-black text-[#1a1c1c] mt-3 mb-2">ใช้คำเชิญนี้ไม่ได้</h1>
        <p class="text-sm text-[#505E5E] leading-relaxed mb-6">{{ error }}</p>
        <p class="text-xs text-[#889696] leading-relaxed mb-6">
          ลองขอลิงก์ใหม่จากเพื่อนที่เป็นเจ้าของการจอง — ลิงก์หนึ่งอันใช้ได้กับหนึ่งคนเท่านั้น
        </p>
        <router-link
          to="/"
          class="inline-flex items-center gap-1.5 bg-[#006565] text-white px-5 py-2.5 rounded-full text-sm font-extrabold">
          <span class="material-symbols-rounded text-[18px]">home</span> กลับหน้าแรก
        </router-link>
      </div>

      <template v-else-if="preview">
        <section class="mb-6">
          <p class="text-xs font-bold text-[#006565] tracking-wider mb-1">คำเชิญร่วมทริป</p>
          <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight" style="font-family:'DB Heavent','Anuphan',sans-serif;">
            {{ preview.invited_by ? `${preview.invited_by} ชวนคุณไปทริปนี้` : 'มีคนชวนคุณไปทริปนี้' }}
          </h1>
        </section>

        <!-- ทริปที่ถูกชวน -->
        <div class="bg-white rounded-[24px] border border-[#E8EEEF] p-6 mb-5">
          <h2 class="text-lg font-black text-[#1a1c1c] leading-snug mb-3">{{ preview.trip_title || 'ทริป' }}</h2>
          <div class="space-y-2">
            <p v-if="departureText" class="flex items-center gap-2 text-sm font-bold text-[#505E5E]">
              <span class="material-symbols-rounded text-[18px] text-[#006565]">event</span>
              เดินทาง {{ departureText }}
            </p>
            <p v-if="preview.booking_ref" class="flex items-center gap-2 text-sm font-bold text-[#505E5E]">
              <span class="material-symbols-rounded text-[18px] text-[#006565]">confirmation_number</span>
              {{ preview.booking_ref }}
            </p>
          </div>

          <div class="border-t border-[#E8EEEF] mt-5 pt-5 space-y-3">
            <p class="text-xs font-black text-[#889696] tracking-wide">เข้าร่วมแล้วคุณจะได้</p>
            <div v-for="item in benefits" :key="item.icon" class="flex items-start gap-3">
              <span class="material-symbols-rounded text-[20px] text-[#006565] shrink-0">{{ item.icon }}</span>
              <div>
                <p class="text-sm font-bold text-[#1a1c1c]">{{ item.title }}</p>
                <p class="text-xs text-[#889696] leading-relaxed">{{ item.body }}</p>
              </div>
            </div>
          </div>

          <p class="text-[11px] text-[#889696] leading-relaxed mt-5">
            คุณจะอยู่ในการจองใบเดียวกับเพื่อน จึงไม่ต้องจองใหม่และไม่ต้องจ่ายเพิ่ม —
            การชำระเงินยังเป็นของเจ้าของการจองคนเดิม
          </p>
        </div>

        <!-- เข้าร่วมแล้ว -->
        <div v-if="preview.already_member" class="bg-green-50 border border-green-200 rounded-[20px] p-5 text-center">
          <p class="text-sm font-bold text-green-700 mb-3">คุณเข้าร่วมการจองนี้อยู่แล้ว</p>
          <router-link
            to="/my-bookings"
            class="inline-flex items-center gap-1.5 bg-[#006565] text-white px-5 py-2.5 rounded-full text-sm font-extrabold">
            ไปที่การจองของฉัน
          </router-link>
        </div>

        <!-- ปุ่มรับคำเชิญ -->
        <button
          v-else
          :disabled="joining"
          @click="join"
          class="w-full bg-[#006565] hover:bg-[#005252] disabled:opacity-60 text-white py-3.5 rounded-full font-extrabold text-sm flex items-center justify-center gap-2 transition">
          <span class="material-symbols-rounded text-[20px]">group_add</span>
          {{ joining ? 'กำลังเข้าร่วม...' : 'เข้าร่วมการจองนี้' }}
        </button>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHead } from '@unhead/vue';
import api from '../lib/axios';

useHead({ title: 'คำเชิญร่วมทริป — ลุยเลเขา' });

const route = useRoute();
const router = useRouter();

const preview = ref(null);
const loading = ref(true);
const joining = ref(false);
const error = ref('');

const benefits = [
  { icon: 'forum', title: 'แชทกลุ่มของรอบนี้', body: 'คุยกับเพื่อนร่วมทริปและทีมงานได้ในห้องเดียวกัน' },
  { icon: 'near_me', title: 'ติดตามรถแบบเรียลไทม์', body: 'เห็นตำแหน่งรถและเวลาถึงจุดรับในวันเดินทาง' },
  { icon: 'event_note', title: 'กำหนดการและจุดขึ้นรถ', body: 'ดูรายละเอียดทริปชุดเดียวกับเจ้าของการจอง' },
];

// รอบที่กำหนดเวลารถออกจริงไว้ (departs_at) อาจออกก่อนวันทริปหนึ่งคืน
const departureText = computed(() => {
  const raw = preview.value?.departs_at || preview.value?.departure_date;
  if (!raw) return '';
  const date = new Date(raw.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return '';
  const day = date.toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
  if (!preview.value?.departs_at) return day;
  const time = date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
  return `${day} ${time} น.`;
});

function message(e, fallback) {
  return e?.response?.data?.message || fallback;
}

async function join() {
  joining.value = true;
  try {
    await api.post(`/booking-invites/${route.params.token}/accept`);
    router.push('/my-bookings');
  } catch (e) {
    error.value = message(e, 'เข้าร่วมไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    joining.value = false;
  }
}

onMounted(async () => {
  try {
    const res = await api.get(`/booking-invites/${route.params.token}`);
    preview.value = res.data.data;
  } catch (e) {
    error.value = message(e, 'คำเชิญนี้ไม่ถูกต้องหรือถูกใช้ไปแล้ว');
  } finally {
    loading.value = false;
  }
});
</script>
