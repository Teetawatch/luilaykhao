<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pt-6 pb-24 px-4">
    <div class="max-w-2xl mx-auto">
      <div v-if="loading" class="h-40 rounded-3xl shimmer"></div>

      <div v-else-if="!data" class="mt-16 text-center">
        <span class="material-symbols-rounded icon-32 text-[var(--color-text-muted)]">link_off</span>
        <p class="mt-3 font-extrabold text-[var(--color-text-dark)]">ลิงก์นี้ใช้ไม่ได้แล้ว</p>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">ขอลิงก์ใหม่จากทีมงานลุยเลเขาทาง LINE ได้เลยครับ</p>
      </div>

      <template v-else>
        <header class="card flex items-center gap-3">
          <img v-if="data.stay.cover_image" :src="data.stay.cover_image" :alt="data.stay.name" class="h-14 w-14 rounded-2xl object-cover" />
          <div class="min-w-0">
            <p class="text-[12px] font-bold text-[var(--color-text-muted)]">หน้าสำหรับเจ้าของที่พัก</p>
            <h1 class="truncate text-xl font-black text-[var(--color-text-dark)]">{{ data.stay.name }}</h1>
            <p v-if="data.stay.rating_count" class="text-[13px] font-bold text-[var(--color-text-mid)]">★ {{ data.stay.rating_avg.toFixed(1) }} · {{ data.stay.rating_count }} รีวิว</p>
          </div>
        </header>

        <!-- รายได้ -->
        <section class="mt-3 grid grid-cols-3 gap-2">
          <div class="stat"><span>รอโอนให้คุณ</span><strong>฿{{ money(data.earnings.pending_payout) }}</strong></div>
          <div class="stat"><span>แขกที่จะมา</span><strong>฿{{ money(data.earnings.upcoming) }}</strong></div>
          <div class="stat"><span>รับไปแล้ว</span><strong>฿{{ money(data.earnings.paid_total) }}</strong></div>
        </section>
        <p class="mt-1 px-1 text-[12px] text-[var(--color-text-muted)]">ยอดที่แสดงคือส่วนของคุณ ({{ Math.round(data.stay.community_share_percent) }}% ของค่าที่พัก) โอนให้หลังแขกเข้าพัก</p>

        <div class="tabs mt-4">
          <button v-for="t in TABS" :key="t.key" class="tab" :class="{ on: tab === t.key }" @click="tab = t.key">
            {{ t.label }}<span v-if="t.key === 'requests' && data.requests.length" class="dot">{{ data.requests.length }}</span>
          </button>
        </div>

        <!-- คำขอ -->
        <section v-if="tab === 'requests'" class="mt-3 grid gap-3">
          <p v-if="!data.requests.length" class="card text-center text-sm text-[var(--color-text-muted)]">ไม่มีคำขอรอตอบ</p>
          <article v-for="b in data.requests" :key="b.ref" class="card">
            <BookingLines :b="b" />
            <p class="mt-2 text-[13px] text-[var(--color-text-muted)]">ถ้ายืนยัน ระบบจะกันห้องให้และแจ้งแขกให้โอนเงินภายใน 24 ชม.</p>
            <div class="mt-3 grid grid-cols-2 gap-2">
              <button class="btn btn--ghost" :disabled="busy" @click="decline(b)">ห้องไม่ว่าง</button>
              <button class="btn" :disabled="busy" @click="approve(b)">ห้องว่าง ยืนยัน</button>
            </div>
          </article>
        </section>

        <!-- แขกที่จะมา -->
        <section v-if="tab === 'upcoming'" class="mt-3 grid gap-3">
          <p v-if="!data.upcoming.length" class="card text-center text-sm text-[var(--color-text-muted)]">ยังไม่มีแขกที่จองไว้</p>
          <article v-for="b in data.upcoming" :key="b.ref" class="card">
            <span class="pill" :class="b.paid ? 'pill--good' : 'pill--wait'">{{ b.paid ? 'จ่ายแล้ว' : 'รอแขกชำระเงิน' }}</span>
            <BookingLines :b="b" class="mt-2" />
            <a :href="'tel:' + b.guest_phone" class="mt-2 inline-flex items-center gap-1 text-sm font-bold text-[var(--color-accent)]">
              <span class="material-symbols-rounded icon-18">call</span>{{ b.guest_phone }}
            </a>
          </article>
          <template v-if="data.recent.length">
            <p class="mt-2 px-1 text-[13px] font-bold text-[var(--color-text-muted)]">ล่าสุด</p>
            <article v-for="b in data.recent" :key="b.ref" class="card opacity-70">
              <span class="pill">{{ b.status_label }}</span>
              <BookingLines :b="b" class="mt-2" />
            </article>
          </template>
        </section>

        <!-- ปฏิทิน -->
        <section v-if="tab === 'calendar'" class="card mt-3">
          <p class="mb-3 text-[13px] text-[var(--color-text-mid)]">ขายห้องผ่านช่องทางอื่นไปแล้ว หรือห้องไม่พร้อม? ปิดห้องในวันนั้นที่นี่ ลูกค้าจะได้ไม่จองซ้อน</p>
          <StayCalendarEditor :load="loadCalendar" :save="saveNights" />
        </section>

        <!-- รีวิว -->
        <section v-if="tab === 'reviews'" class="mt-3 grid gap-3">
          <p v-if="!data.reviews.length" class="card text-center text-sm text-[var(--color-text-muted)]">ยังไม่มีรีวิว</p>
          <article v-for="r in data.reviews" :key="r.id" class="card">
            <p class="font-black text-amber-500">{{ '★'.repeat(r.rating) }}</p>
            <p v-if="r.comment" class="mt-1">{{ r.comment }}</p>
            <p class="text-[12px] text-[var(--color-text-muted)]">{{ r.author }}</p>
            <textarea v-model="replies[r.id]" rows="2" class="input mt-2" placeholder="ตอบแขก (แสดงบนหน้าที่พัก)"></textarea>
            <button class="btn mt-2" @click="reply(r)">บันทึกคำตอบ</button>
          </article>
        </section>

        <p class="mt-8 text-center text-[12px] text-[var(--color-text-muted)]">อย่าส่งลิงก์นี้ต่อให้คนอื่น — ใครมีลิงก์ก็จัดการที่พักนี้ได้</p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { DATE_LOCALE } from '@/i18n';
import { defineComponent, h, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import Swal from 'sweetalert2';
import api from '../lib/axios';
import { useToast } from '../lib/toast';
import StayCalendarEditor from '../components/StayCalendarEditor.vue';

const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const short = (ymd) => { const [y, m, d] = ymd.split('-').map(Number); return `${d} ${MONTHS[m - 1]} ${String(y + 543).slice(2)}`; };

const BookingLines = defineComponent({
  props: { b: { type: Object, required: true } },
  setup(props) {
    return () => h('div', [
      h('p', { class: 'font-extrabold text-[var(--color-text-dark)]' }, `${props.b.guest_name} · ${props.b.guests} คน`),
      h('p', { class: 'text-[14px] text-[var(--color-text-mid)]' }, `${props.b.room_name} × ${props.b.units} · ${short(props.b.check_in)} – ${short(props.b.check_out)} (${props.b.nights} คืน)`),
      h('p', { class: 'text-[14px] font-bold text-[var(--color-accent)]' }, `คุณได้รับ ฿${Number(props.b.host_amount).toLocaleString(DATE_LOCALE)}`),
      props.b.note ? h('p', { class: 'mt-1 rounded-xl bg-[var(--color-sand)] p-2 text-[13px]' }, `ถึงคุณ: ${props.b.note}`) : null,
    ]);
  },
});

const TABS = [
  { key: 'requests', label: 'คำขอ' },
  { key: 'upcoming', label: 'แขกที่จะมา' },
  { key: 'calendar', label: 'ปิด/เปิดห้อง' },
  { key: 'reviews', label: 'รีวิว' },
];

const route = useRoute();
const toast = useToast();
const token = String(route.params.token);
const base = `/host/stays/${token}`;
const loading = ref(true);
const data = ref(null);
const tab = ref('requests');
const busy = ref(false);
const replies = reactive({});

function money(n) {
  return Math.round(Number(n || 0)).toLocaleString(DATE_LOCALE);
}

async function load() {
  try {
    const res = await api.get(base);
    data.value = res.data.data;
    data.value.reviews.forEach((r) => { replies[r.id] = r.host_reply || ''; });
    if (!data.value.requests.length && tab.value === 'requests') tab.value = 'upcoming';
  } catch {
    data.value = null;
  } finally {
    loading.value = false;
  }
}

async function approve(b) {
  busy.value = true;
  try {
    const res = await api.post(`${base}/bookings/${b.ref}/approve`);
    toast.success(res.data.message);
    await load();
  } catch (err) {
    toast.error(err.response?.data?.message || 'ไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function decline(b) {
  const { value, isConfirmed } = await Swal.fire({
    title: 'ห้องไม่ว่าง?',
    input: 'text',
    inputPlaceholder: 'บอกแขกสั้น ๆ (ไม่บังคับ)',
    showCancelButton: true,
    confirmButtonText: 'แจ้งแขก',
    cancelButtonText: 'ยกเลิก',
  });
  if (!isConfirmed) return;
  busy.value = true;
  try {
    const res = await api.post(`${base}/bookings/${b.ref}/decline`, { reason: value || null });
    toast.success(res.data.message);
    await load();
  } catch (err) {
    toast.error(err.response?.data?.message || 'ไม่สำเร็จ');
  } finally {
    busy.value = false;
  }
}

async function reply(r) {
  try {
    const res = await api.post(`${base}/reviews/${r.id}/reply`, { reply: replies[r.id] || null });
    toast.success(res.data.message);
  } catch (err) {
    toast.error(err.response?.data?.message || 'ไม่สำเร็จ');
  }
}

async function loadCalendar(month) {
  const res = await api.get(`${base}/calendar`, { params: { month } });
  return res.data.data;
}

async function saveNights(body) {
  const res = await api.post(`${base}/nights`, body);
  load();
  return res.data;
}

onMounted(load);
</script>

<style scoped>
.card { padding: 1rem; border-radius: 1.25rem; background: #fff; border: 1px solid var(--color-sand-dark); }
.stat { display: grid; padding: 0.75rem; border-radius: 1rem; background: #fff; border: 1px solid var(--color-sand-dark); }
.stat span { font-size: 11px; font-weight: 800; color: var(--color-text-muted); }
.stat strong { font-size: 1.1rem; font-weight: 900; color: var(--color-text-dark); }
.tabs { display: flex; gap: 0.25rem; overflow-x: auto; }
.tab { position: relative; padding: 0.55rem 1rem; border-radius: 999px; background: #fff; border: 1px solid var(--color-sand-dark); font-size: 14px; font-weight: 800; color: var(--color-text-mid); white-space: nowrap; }
.tab.on { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.dot { margin-left: 0.35rem; padding: 0 0.4rem; border-radius: 999px; background: #ef4444; color: #fff; font-size: 11px; }
.btn { width: 100%; padding: 0.75rem; border-radius: 999px; background: var(--color-accent); color: #fff; font-weight: 900; }
.btn:disabled { opacity: 0.5; }
.btn--ghost { background: var(--color-sand); color: var(--color-text-dark); }
.pill { display: inline-flex; padding: 0.15rem 0.6rem; border-radius: 999px; background: var(--color-sand); font-size: 12px; font-weight: 800; }
.pill--good { background: #dcfce7; color: #166534; }
.pill--wait { background: #fef3c7; color: #92400e; }
.input { width: 100%; padding: 0.6rem 0.75rem; border-radius: 0.8rem; border: 1px solid var(--color-sand-dark); font-size: 15px; }
</style>
