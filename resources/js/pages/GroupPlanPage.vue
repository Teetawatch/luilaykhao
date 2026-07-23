<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">

      <!-- Loading -->
      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm">กำลังโหลดกลุ่ม...</p>
      </div>

      <!-- ไม่พบกลุ่ม -->
      <div v-else-if="notFound" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">link_off</span>
        <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ไม่พบกลุ่มนี้</p>
        <p class="text-[#505E5E] text-sm mb-5">ลิงก์อาจหมดอายุ หรือหัวหน้ากลุ่มยกเลิกไปแล้ว</p>
        <router-link to="/trips" class="inline-flex items-center gap-1.5 rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3">
          ดูทริปที่เปิดอยู่
        </router-link>
      </div>

      <template v-else-if="plan">
        <!-- หัวเรื่อง + ทริป -->
        <section class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden mb-5">
          <img
            v-if="tripImage"
            :src="tripImage"
            :alt="plan.trip?.title"
            class="w-full h-40 object-cover"
          />
          <div class="p-5 sm:p-6">
            <p class="text-[12px] font-bold text-[#8A9A9A] mb-1">ไปด้วยกัน</p>
            <h1 class="text-xl font-extrabold text-[#1a1c1c] leading-snug">
              {{ plan.name || plan.trip?.title || 'กลุ่มเดินทาง' }}
            </h1>

            <router-link
              v-if="plan.trip?.slug"
              :to="`/trips/${plan.trip.slug}`"
              class="text-sm font-bold text-[#006565] mt-1 inline-block"
            >
              {{ plan.trip.title }}
            </router-link>

            <div class="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-[#505E5E] mt-3">
              <span v-if="plan.schedule?.departure_date" class="inline-flex items-center gap-1">
                <span class="material-symbols-rounded text-[16px]">event</span>
                {{ dateRange }}
              </span>
              <span v-if="plan.schedule?.effective_price" class="inline-flex items-center gap-1">
                <span class="material-symbols-rounded text-[16px]">sell</span>
                {{ money(plan.schedule.effective_price) }} / คน
              </span>
            </div>

            <!-- สถานะกลุ่ม -->
            <div class="mt-4 flex flex-wrap items-center gap-2">
              <span
                class="rounded-full px-3 py-1 text-[12px] font-bold"
                :class="statusChipClass"
              >{{ statusLabel }}</span>
              <span class="rounded-full bg-[#F4F7F6] border border-[#E8EEEF] px-3 py-1 text-[12px] font-bold text-[#505E5E]">
                {{ claimedCount }} / {{ plan.seat_count }} ที่นั่งเลือกแล้ว
              </span>
              <span v-if="expiryLabel" class="rounded-full bg-amber-50 border border-amber-200 px-3 py-1 text-[12px] font-bold text-amber-700">
                {{ expiryLabel }}
              </span>
            </div>
          </div>
        </section>

        <!-- แชร์ลิงก์ -->
        <section v-if="plan.is_open" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-1">ชวนเพื่อนเข้ากลุ่ม</h2>
          <p class="text-[12px] text-[#8A9A9A] mb-3">
            ส่งลิงก์นี้ให้เพื่อน แต่ละคนเลือกที่นั่งและกรอกข้อมูลของตัวเองได้เลย
          </p>
          <div class="flex gap-2">
            <input
              :value="shareUrl"
              readonly
              class="flex-1 min-w-0 rounded-[12px] border border-[#E8EEEF] bg-[#FAFBFB] px-3.5 py-2.5 text-[13px] text-[#505E5E]"
            />
            <button
              type="button"
              @click="copyShareUrl"
              class="rounded-[12px] bg-[#006565] text-white px-4 py-2.5 text-[13px] font-bold shrink-0"
            >
              คัดลอก
            </button>
          </div>
          <p class="text-[12px] text-[#8A9A9A] mt-2.5">
            รหัสกลุ่ม <span class="font-extrabold text-[#1a1c1c] tracking-widest">{{ plan.invite_code }}</span>
          </p>
        </section>

        <!-- ยังไม่ได้เข้าร่วม -->
        <section v-if="!isMember" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5 text-center">
          <p class="text-[#1a1c1c] font-bold mb-1">เพื่อนชวนคุณมาทริปนี้</p>
          <p class="text-[#505E5E] text-sm mb-5">
            เข้าร่วมกลุ่มเพื่อเลือกที่นั่งของตัวเอง — ยังไม่ต้องจ่ายตอนนี้
          </p>
          <button
            type="button"
            :disabled="!plan.is_open || busy"
            @click="joinPlan"
            class="w-full rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3.5 disabled:opacity-40"
          >
            {{ plan.is_open ? 'เข้าร่วมกลุ่ม' : 'กลุ่มนี้ปิดรับแล้ว' }}
          </button>
        </section>

        <!-- ผังที่นั่ง -->
        <section v-if="isMember" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-1">เลือกที่นั่งของคุณ</h2>
          <p class="text-[12px] text-[#8A9A9A] mb-4">
            เลือกได้คนละ 1 ที่ ที่นั่งที่เพื่อนเลือกแล้วจะขึ้นชื่อไว้
          </p>

          <div v-if="seatsLoading" class="py-10 flex justify-center">
            <div class="w-8 h-8 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
          </div>

          <div v-else-if="!seats.length" class="text-center py-8 text-sm text-[#8A9A9A]">
            รอบนี้ไม่มีผังที่นั่ง — หัวหน้ากลุ่มกดจองรวมได้เลย
          </div>

          <div v-else class="grid grid-cols-3 sm:grid-cols-4 gap-2">
            <button
              v-for="seat in seats"
              :key="seat.id"
              type="button"
              :disabled="seatDisabled(seat) || busy"
              @click="onSeatClick(seat)"
              class="rounded-[14px] border p-2.5 text-left transition disabled:cursor-not-allowed"
              :class="seatClass(seat)"
            >
              <p class="text-[13px] font-extrabold leading-none">{{ seat.label || seat.id }}</p>
              <p class="text-[11px] mt-1.5 leading-tight truncate">{{ seatCaption(seat) }}</p>
            </button>
          </div>

          <button
            v-if="mySeatId"
            type="button"
            :disabled="busy || !plan.is_open"
            @click="releaseSeat"
            class="mt-4 text-[13px] font-bold text-[#B42318] disabled:opacity-40"
          >
            ปล่อยที่นั่ง {{ mySeatId }}
          </button>
        </section>

        <!-- สมาชิก -->
        <section v-if="isMember" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">สมาชิกในกลุ่ม ({{ plan.members.length }})</h2>
          <ul class="space-y-3">
            <li v-for="member in plan.members" :key="member.id" class="flex items-center gap-3">
              <img
                v-if="member.avatar_url"
                :src="member.avatar_url"
                :alt="member.display_name"
                class="w-9 h-9 rounded-full object-cover shrink-0"
              />
              <span
                v-else
                class="w-9 h-9 rounded-full bg-[#E8EEEF] text-[#505E5E] text-[13px] font-bold flex items-center justify-center shrink-0"
              >{{ initial(member.display_name) }}</span>

              <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-[#1a1c1c] truncate">
                  {{ member.display_name }}
                  <span v-if="member.is_me" class="text-[#8A9A9A] font-medium">(คุณ)</span>
                </p>
                <p class="text-[12px] text-[#8A9A9A]">
                  <span v-if="member.is_host">หัวหน้ากลุ่ม · </span>
                  <span v-if="member.seat_id">ที่นั่ง {{ member.seat_id }}</span>
                  <span v-else>ยังไม่ได้เลือกที่นั่ง</span>
                </p>
              </div>
            </li>
          </ul>
        </section>

        <!-- หัวหน้ากลุ่ม: จุดรับ + จองรวม -->
        <section v-if="plan.is_host && plan.is_open" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-1">ปิดกลุ่มแล้วจองรวมทีเดียว</h2>
          <p class="text-[12px] text-[#8A9A9A] mb-4">
            ระบบจะสร้างการจองเดียวจากทุกคนที่เลือกที่นั่งแล้ว แล้วพาคุณไปหน้าชำระเงิน
          </p>

          <label v-if="pickupPoints.length" class="block mb-4">
            <span class="text-[12px] font-bold text-[#505E5E] block mb-1.5">จุดรับ</span>
            <select
              v-model="pickupPointId"
              class="w-full rounded-[12px] border border-[#E8EEEF] bg-white px-3.5 py-2.5 text-sm text-[#1a1c1c]"
            >
              <option :value="null">ไม่ระบุจุดรับ</option>
              <option v-for="point in pickupPoints" :key="point.id" :value="point.id">
                {{ point.name }}<template v-if="point.price"> · {{ money(point.price) }}</template>
              </option>
            </select>
          </label>

          <button
            type="button"
            :disabled="busy || claimedCount === 0"
            @click="checkout"
            class="w-full rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3.5 disabled:opacity-40"
          >
            {{ claimedCount === 0 ? 'ยังไม่มีใครเลือกที่นั่ง' : `จองรวม ${claimedCount} ที่นั่ง` }}
          </button>
        </section>

        <!-- จองแล้ว -->
        <section v-if="plan.booking_ref" class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-5">
          <h2 class="text-[13px] font-bold text-[#505E5E] mb-1">กลุ่มนี้จองแล้ว</h2>
          <p class="text-sm text-[#1a1c1c] font-bold mb-4">หมายเลขการจอง {{ plan.booking_ref }}</p>
          <router-link
            :to="`/payment/${plan.booking_ref}`"
            class="inline-flex items-center gap-1.5 rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3"
          >
            ไปหน้าชำระเงิน
          </router-link>
        </section>

        <!-- ออก / ยกเลิก -->
        <div v-if="isMember" class="flex flex-wrap gap-4 px-1">
          <button
            v-if="!plan.is_host"
            type="button"
            :disabled="busy"
            @click="leavePlan"
            class="text-[13px] font-bold text-[#B42318] disabled:opacity-40"
          >
            ออกจากกลุ่ม
          </button>
          <button
            v-if="plan.is_host && plan.is_open"
            type="button"
            :disabled="busy"
            @click="cancelPlan"
            class="text-[13px] font-bold text-[#B42318] disabled:opacity-40"
          >
            ยกเลิกกลุ่มนี้
          </button>
        </div>
      </template>
    </div>

    <!-- ฟอร์มข้อมูลผู้เดินทางตอนเลือกที่นั่ง -->
    <div
      v-if="claimSeat"
      class="fixed inset-0 z-50 bg-black/40 flex items-end sm:items-center justify-center p-0 sm:p-4"
      @click.self="claimSeat = null"
    >
      <div class="bg-white w-full sm:max-w-md rounded-t-[24px] sm:rounded-[20px] p-5 sm:p-6 max-h-[90vh] overflow-y-auto">
        <h2 class="text-lg font-extrabold text-[#1a1c1c] mb-1">
          ที่นั่ง {{ claimSeat.label || claimSeat.id }}
        </h2>
        <p class="text-[13px] text-[#505E5E] mb-5">กรอกข้อมูลผู้เดินทางสำหรับที่นั่งนี้</p>

        <form class="space-y-3.5" @submit.prevent="submitClaim">
          <label class="block">
            <span class="text-[12px] font-bold text-[#505E5E] block mb-1.5">ชื่อ-นามสกุล *</span>
            <input v-model.trim="claimForm.name" required maxlength="120" class="field" />
          </label>
          <label class="block">
            <span class="text-[12px] font-bold text-[#505E5E] block mb-1.5">เบอร์โทร</span>
            <input v-model.trim="claimForm.phone" maxlength="32" inputmode="tel" class="field" />
          </label>
          <label class="block">
            <span class="text-[12px] font-bold text-[#505E5E] block mb-1.5">อีเมล</span>
            <input v-model.trim="claimForm.email" type="email" maxlength="120" class="field" />
          </label>
          <label class="block">
            <span class="text-[12px] font-bold text-[#505E5E] block mb-1.5">อาหารที่แพ้ / ข้อจำกัดอาหาร</span>
            <input v-model.trim="claimForm.allergies" maxlength="500" class="field" />
          </label>
          <label class="block">
            <span class="text-[12px] font-bold text-[#505E5E] block mb-1.5">โรคประจำตัว / เรื่องสุขภาพที่ทีมควรรู้</span>
            <input v-model.trim="claimForm.health_notes" maxlength="500" class="field" />
          </label>

          <div class="flex gap-2.5 pt-2">
            <button
              type="button"
              class="flex-1 rounded-[14px] border border-[#E8EEEF] text-[#505E5E] text-sm font-bold py-3"
              @click="claimSeat = null"
            >
              ยกเลิก
            </button>
            <button
              type="submit"
              :disabled="busy || !claimForm.name"
              class="flex-1 rounded-[14px] bg-[#006565] text-white text-sm font-bold py-3 disabled:opacity-40"
            >
              ยืนยันที่นั่งนี้
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../lib/axios';
import { useAuthStore } from '../stores/auth';
import { useToast } from '../lib/toast';
import { useSwal } from '../lib/swal';
import { thaiShort } from '../lib/thaiDate';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToast();
const swal = useSwal();

const loading = ref(true);
const notFound = ref(false);
const busy = ref(false);
const plan = ref(null);

const seats = ref([]);
const seatsLoading = ref(false);

const pickupPointId = ref(null);
const claimSeat = ref(null);
const claimForm = reactive({
  name: '',
  phone: '',
  email: '',
  allergies: '',
  health_notes: '',
});

const code = computed(() => String(route.params.code || '').toUpperCase());
const shareUrl = computed(() => `${window.location.origin}/group/${code.value}`);
const tripImage = computed(() => plan.value?.trip?.cover_image || plan.value?.trip?.thumbnail_image || null);
const pickupPoints = computed(() => plan.value?.schedule?.pickup_points || []);

const myMember = computed(() => plan.value?.members?.find(m => m.is_me) || null);
const isMember = computed(() => myMember.value !== null);
const mySeatId = computed(() => myMember.value?.seat_id || null);
const claimedCount = computed(() => plan.value?.claimed_seat_ids?.length || 0);

/** ที่นั่ง → สมาชิกที่เลือกไว้ ใช้โชว์ชื่อบนผังโดยไม่ต้องวนหาใหม่ทุกช่อง */
const memberBySeat = computed(() => {
  const map = {};
  (plan.value?.members || []).forEach((m) => {
    if (m.seat_id) map[m.seat_id] = m;
  });
  return map;
});

const dateRange = computed(() => {
  const schedule = plan.value?.schedule;
  if (!schedule?.departure_date) return '';
  const start = thaiShort(schedule.departure_date);
  if (!schedule.return_date || schedule.return_date === schedule.departure_date) return start;
  return `${start} – ${thaiShort(schedule.return_date)}`;
});

const statusLabel = computed(() => {
  if (!plan.value) return '';
  if (plan.value.booking_ref) return 'จองแล้ว';
  return plan.value.is_open ? 'กำลังรวมกลุ่ม' : 'ปิดรับแล้ว';
});

const statusChipClass = computed(() => {
  if (plan.value?.booking_ref) return 'bg-[#006565]/10 text-[#006565]';
  return plan.value?.is_open
    ? 'bg-emerald-50 text-emerald-700'
    : 'bg-[#F4F7F6] text-[#8A9A9A]';
});

/** เตือนเวลาหมดอายุแบบหยาบ ๆ พอให้รู้ว่าต้องรีบ ไม่ต้องนับถอยหลังวินาที */
const expiryLabel = computed(() => {
  const expiresAt = plan.value?.expires_at;
  if (!expiresAt || !plan.value?.is_open) return '';

  const minutes = Math.round((new Date(expiresAt) - Date.now()) / 60000);
  if (minutes <= 0) return 'หมดเวลาแล้ว';
  if (minutes < 60) return `เหลือ ${minutes} นาที`;

  const hours = Math.round(minutes / 60);
  return hours < 48 ? `เหลือ ${hours} ชม.` : `ปิดรับ ${thaiShort(expiresAt)}`;
});

function money(value) {
  return `฿${Math.round(Number(value) || 0).toLocaleString('th-TH')}`;
}

function initial(name) {
  return (name || '?').trim().charAt(0);
}

function seatTaken(seat) {
  return seat.status === 'booked' || seat.status === 'locked';
}

function seatDisabled(seat) {
  if (!plan.value?.is_open) return true;
  const claimer = memberBySeat.value[seat.id];
  // ที่นั่งของตัวเองกดซ้ำได้เพื่อแก้ข้อมูลผู้เดินทาง เพื่อนเลือกไปแล้วกดไม่ได้
  if (claimer) return !claimer.is_me;
  return seatTaken(seat);
}

function seatClass(seat) {
  const claimer = memberBySeat.value[seat.id];
  if (claimer?.is_me) return 'border-[#006565] bg-[#006565] text-white';
  if (claimer) return 'border-[#006565]/30 bg-[#006565]/[0.06] text-[#0F3D3E]';
  if (seatTaken(seat)) return 'border-[#EDF1F1] bg-[#F4F7F6] text-[#B4C4C4]';
  return 'border-[#E8EEEF] bg-white text-[#1a1c1c] hover:border-[#006565]';
}

function seatCaption(seat) {
  const claimer = memberBySeat.value[seat.id];
  if (claimer) return claimer.is_me ? 'ที่นั่งของคุณ' : claimer.display_name;
  if (seat.status === 'booked') return 'จองแล้ว';
  if (seat.status === 'locked') return 'ถูกล็อกอยู่';
  return 'ว่าง';
}

function applyPlan(data) {
  plan.value = data;
}

async function loadPlan() {
  try {
    const res = await api.get(`/group-plans/${code.value}`);
    applyPlan(res.data?.data);
  } catch (err) {
    if (err.response?.status === 404) {
      notFound.value = true;
    } else {
      toast.error(err.response?.data?.message || 'โหลดกลุ่มไม่สำเร็จ');
    }
  } finally {
    loading.value = false;
  }
}

async function loadSeats() {
  const scheduleId = plan.value?.schedule?.id;
  if (!scheduleId) return;

  seatsLoading.value = true;
  try {
    const res = await api.get(`/schedules/${scheduleId}/seats`);
    seats.value = res.data?.data?.seats || [];
  } catch {
    seats.value = [];
  } finally {
    seatsLoading.value = false;
  }
}

async function joinPlan() {
  busy.value = true;
  try {
    const res = await api.post(`/group-plans/${code.value}/join`);
    applyPlan(res.data?.data);
    toast.success(res.data?.message || 'เข้าร่วมกลุ่มแล้ว');
    await loadSeats();
  } catch (err) {
    toast.error(err.response?.data?.message || 'เข้าร่วมกลุ่มไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

function onSeatClick(seat) {
  claimSeat.value = seat;
  // เติมข้อมูลจากโปรไฟล์ให้ก่อน คนส่วนใหญ่จองที่นั่งให้ตัวเอง
  const existing = myMember.value;
  claimForm.name = existing?.passenger_name || auth.user?.name || '';
  claimForm.phone = auth.user?.phone || '';
  claimForm.email = auth.user?.email || '';
  claimForm.allergies = '';
  claimForm.health_notes = '';
}

async function submitClaim() {
  if (!claimSeat.value) return;

  busy.value = true;
  try {
    const res = await api.post(`/group-plans/${code.value}/claim-seat`, {
      seat_id: claimSeat.value.id,
      ...claimForm,
    });
    applyPlan(res.data?.data);
    claimSeat.value = null;
    toast.success(res.data?.message || 'เลือกที่นั่งแล้ว');
    await loadSeats();
  } catch (err) {
    toast.error(err.response?.data?.message || 'เลือกที่นั่งไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function releaseSeat() {
  busy.value = true;
  try {
    const res = await api.post(`/group-plans/${code.value}/release-seat`);
    applyPlan(res.data?.data);
    toast.success('ปล่อยที่นั่งแล้ว');
    await loadSeats();
  } catch (err) {
    toast.error(err.response?.data?.message || 'ปล่อยที่นั่งไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function leavePlan() {
  const result = await swal.confirm({
    title: 'ออกจากกลุ่ม?',
    text: 'ที่นั่งที่คุณเลือกไว้จะถูกปล่อยคืนให้เพื่อนคนอื่น',
    confirmText: 'ออกจากกลุ่ม',
  });
  if (!result.isConfirmed) return;

  busy.value = true;
  try {
    await api.post(`/group-plans/${code.value}/leave`);
    toast.success('ออกจากกลุ่มแล้ว');
    router.push('/group-plans');
  } catch (err) {
    toast.error(err.response?.data?.message || 'ออกจากกลุ่มไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function cancelPlan() {
  const result = await swal.confirm({
    title: 'ยกเลิกกลุ่มนี้?',
    text: 'เพื่อนทุกคนจะหลุดจากกลุ่ม และที่นั่งที่จองไว้จะถูกปล่อยคืนทั้งหมด',
    confirmText: 'ยกเลิกกลุ่ม',
    cancelText: 'เก็บไว้ก่อน',
  });
  if (!result.isConfirmed) return;

  busy.value = true;
  try {
    await api.delete(`/group-plans/${code.value}`);
    toast.success('ยกเลิกกลุ่มแล้ว');
    router.push('/group-plans');
  } catch (err) {
    toast.error(err.response?.data?.message || 'ยกเลิกกลุ่มไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function checkout() {
  const result = await swal.confirm({
    title: `จองรวม ${claimedCount.value} ที่นั่ง?`,
    text: 'หลังจากนี้กลุ่มจะปิดรับ และคนที่ยังไม่เลือกที่นั่งจะไม่ถูกรวมในการจอง',
    confirmText: 'จองเลย',
  });
  if (!result.isConfirmed) return;

  busy.value = true;
  try {
    const res = await api.post(`/group-plans/${code.value}/checkout`, {
      pickup_point_id: pickupPointId.value,
    });
    const ref = res.data?.data?.booking_ref;
    toast.success(res.data?.message || 'สร้างการจองกลุ่มสำเร็จ');

    if (ref) {
      router.push(`/payment/${ref}`);
    } else {
      await loadPlan();
    }
  } catch (err) {
    toast.error(err.response?.data?.message || 'จองรวมไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function copyShareUrl() {
  try {
    await navigator.clipboard.writeText(shareUrl.value);
    toast.success('คัดลอกลิงก์แล้ว');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ กรุณากดค้างที่ลิงก์เพื่อคัดลอก');
  }
}

// ผังที่นั่งโหลดหลังรู้ว่าเป็นสมาชิกแล้ว — คนนอกกลุ่มยังเลือกที่นั่งไม่ได้อยู่ดี
watch(isMember, (value) => {
  if (value && !seats.value.length) loadSeats();
});

onMounted(async () => {
  await loadPlan();
  if (isMember.value) await loadSeats();
});
</script>

<style scoped>
.field {
  width: 100%;
  border-radius: 12px;
  border: 1px solid #E8EEEF;
  background: #fff;
  padding: 10px 14px;
  font-size: 14px;
  color: #1a1c1c;
}

.field:focus {
  outline: none;
  border-color: #006565;
}
</style>
