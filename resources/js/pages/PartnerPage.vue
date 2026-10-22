<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32 font-anuphan">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">

      <section class="mb-6">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2" style="font-family:'DB Heavent','Anuphan',sans-serif;">
          พาร์ทเนอร์ช่วยขาย
        </h1>
        <p class="text-[#505E5E] text-sm">แชร์ลิงก์ของคุณ ลูกค้าจองและจ่ายเงินกับเราโดยตรง คุณได้ค่าคอมเมื่อทริปเดินทางจริง</p>
      </section>

      <div v-if="loading" class="flex justify-center py-24">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <div v-else-if="!data" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-[40px] text-[#889696]">handshake</span>
        <p class="mt-2 font-bold text-[#1a1c1c]">บัญชีนี้ยังไม่ได้เป็นพาร์ทเนอร์</p>
        <p class="mt-1 text-sm text-[#505E5E]">อยากช่วยขายทริปและรับค่าคอม ทักทีมงานได้เลยครับ</p>
        <router-link to="/contact" class="mt-4 inline-flex rounded-full bg-[#006565] px-5 py-2.5 text-sm font-bold text-white">ติดต่อทีมงาน</router-link>
      </div>

      <template v-else>
        <div v-if="data.status !== 'active'" class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800">
          บัญชีพาร์ทเนอร์หยุดชั่วคราว — ลิงก์ยังเปิดได้ แต่ใบจองใหม่จะไม่นับคอม ใบเดิมได้คอมตามปกติ
        </div>

        <!-- โค้ดและลิงก์ -->
        <div class="bg-gradient-to-br from-[#4c1d95] to-[#7c3aed] rounded-[24px] p-7 text-white mb-5 relative overflow-hidden">
          <div class="absolute -right-8 -top-8 w-32 h-32 rounded-full bg-white/10"></div>
          <p class="text-white/80 text-xs font-bold uppercase tracking-wider mb-2">โค้ดพาร์ทเนอร์ · คอม {{ data.rate_label }}</p>
          <div class="flex items-center gap-3 mb-5">
            <span class="text-3xl font-black tracking-widest">{{ data.code }}</span>
            <button class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition" @click="copy(data.code, 'code')">
              <span class="material-symbols-rounded text-[18px]">{{ copied === 'code' ? 'check' : 'content_copy' }}</span>
            </button>
          </div>
          <div class="flex gap-2.5 flex-wrap">
            <button class="flex-1 min-w-[130px] bg-white text-[#4c1d95] py-2.5 px-4 rounded-full font-extrabold text-sm hover:bg-white/90 transition flex items-center justify-center gap-1.5" @click="share">
              <span class="material-symbols-rounded text-[18px]">share</span> แชร์ลิงก์
            </button>
            <button class="bg-white/20 hover:bg-white/30 text-white py-2.5 px-4 rounded-full font-bold text-sm transition flex items-center justify-center gap-1.5" @click="copy(data.share_url, 'url')">
              <span class="material-symbols-rounded text-[18px]">{{ copied === 'url' ? 'check' : 'link' }}</span> ลิงก์เว็บ
            </button>
            <button v-if="data.liff_url" class="bg-white/20 hover:bg-white/30 text-white py-2.5 px-4 rounded-full font-bold text-sm transition flex items-center justify-center gap-1.5" @click="copy(data.liff_url, 'liff')">
              <span class="material-symbols-rounded text-[18px]">{{ copied === 'liff' ? 'check' : 'chat' }}</span> ลิงก์ LINE
            </button>
          </div>
        </div>

        <!-- ลิงก์ทริปเฉพาะ -->
        <div class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 mb-5">
          <p class="font-bold text-[#1a1c1c] text-sm mb-1">ทำลิงก์ของทริปที่จะโพสต์</p>
          <p class="text-xs text-[#889696] mb-3">วางลิงก์หน้าทริปจากเว็บของเรา ระบบจะต่อโค้ดของคุณให้</p>
          <div class="flex gap-2">
            <input v-model="tripLink" type="url" placeholder="https://.../trips/..." class="min-w-0 flex-1 rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none focus:border-[#7c3aed]" />
            <button :disabled="!taggedTripLink" class="shrink-0 rounded-xl bg-[#1a1c1c] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-40" @click="copy(taggedTripLink, 'trip')">
              {{ copied === 'trip' ? 'คัดลอกแล้ว' : 'คัดลอก' }}
            </button>
          </div>
          <p v-if="tripLink && !taggedTripLink" class="mt-1 text-xs font-bold text-red-500">ใช้ได้เฉพาะลิงก์ในเว็บของเรา</p>
        </div>

        <!-- ยอด -->
        <div class="grid grid-cols-3 gap-3 mb-5">
          <div class="bg-white rounded-[16px] border border-[#E8EEEF] p-4 text-center">
            <p class="text-xl font-black text-[#c2410c]">฿{{ money(data.summary.pending) }}</p>
            <p class="text-[11px] font-bold text-[#889696] mt-0.5">รอเดินทาง</p>
          </div>
          <div class="bg-white rounded-[16px] border border-[#E8EEEF] p-4 text-center">
            <p class="text-xl font-black text-green-600">฿{{ money(data.summary.approved) }}</p>
            <p class="text-[11px] font-bold text-[#889696] mt-0.5">รอโอน</p>
          </div>
          <div class="bg-white rounded-[16px] border border-[#E8EEEF] p-4 text-center">
            <p class="text-xl font-black text-[#4c1d95]">฿{{ money(data.summary.paid) }}</p>
            <p class="text-[11px] font-bold text-[#889696] mt-0.5">โอนแล้ว</p>
          </div>
        </div>
        <p class="text-center text-xs text-[#889696] -mt-3 mb-5">ขายได้ {{ data.summary.bookings }} ใบจอง · {{ data.summary.seats }} ที่นั่ง</p>

        <!-- บัญชีรับเงิน -->
        <div class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 mb-5">
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="font-bold text-[#1a1c1c] text-sm">บัญชีรับค่าคอม</p>
              <p v-if="data.payout_account" class="text-sm text-[#505E5E] truncate">
                {{ data.payout_account.bank }} {{ data.payout_account.number_masked }} · {{ data.payout_account.name }}
              </p>
              <p v-else class="text-sm font-bold text-red-500">ยังไม่ได้ใส่ — ใส่ไว้เพื่อให้ทีมงานโอนได้</p>
            </div>
            <button class="shrink-0 rounded-full border border-[#E8EEEF] px-4 py-2 text-sm font-bold text-[#1a1c1c]" @click="editingAccount = !editingAccount">
              {{ editingAccount ? 'ปิด' : (data.payout_account ? 'แก้ไข' : 'ใส่บัญชี') }}
            </button>
          </div>
          <form v-if="editingAccount" class="mt-4 space-y-2" @submit.prevent="saveAccount">
            <input v-model="account.bank" required maxlength="60" placeholder="ธนาคาร เช่น กสิกรไทย" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none focus:border-[#7c3aed]" />
            <input v-model="account.number" required maxlength="30" placeholder="เลขบัญชี หรือพร้อมเพย์" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none focus:border-[#7c3aed]" />
            <input v-model="account.name" required maxlength="100" placeholder="ชื่อบัญชี" class="w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm outline-none focus:border-[#7c3aed]" />
            <button type="submit" :disabled="saving" class="w-full rounded-xl bg-[#4c1d95] py-3 text-sm font-bold text-white disabled:opacity-50">บันทึก</button>
          </form>
        </div>

        <!-- รายการคอม -->
        <div class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 mb-5">
          <div class="mb-3 flex gap-2 overflow-x-auto">
            <button
              v-for="t in tabs"
              :key="t.key || 'all'"
              class="shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-bold"
              :class="filter === t.key ? 'border-[#7c3aed] bg-[#f5f3ff] text-[#4c1d95]' : 'border-[#E8EEEF] text-[#505E5E]'"
              @click="setFilter(t.key)"
            >{{ t.label }}</button>
          </div>
          <p v-if="!rows.length && !rowsLoading" class="py-6 text-center text-sm text-[#889696]">ยังไม่มีรายการ</p>
          <div v-for="c in rows" :key="c.id" class="flex items-start justify-between gap-3 border-b border-[#F1F4F4] py-3 last:border-0">
            <div class="min-w-0">
              <p class="truncate text-sm font-bold text-[#1a1c1c]">{{ c.trip_title || 'ทริป' }}</p>
              <p class="text-xs text-[#889696]">{{ thaiDate(c.departure_date) }} · {{ c.seats }} ที่นั่ง · {{ c.booking_ref }}</p>
              <p v-if="c.void_reason" class="text-xs text-[#889696]">{{ c.void_reason }}</p>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-sm font-black" :class="c.amount < 0 ? 'text-red-600' : 'text-[#1a1c1c]'">฿{{ money(c.amount) }}</p>
              <span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-bold" :class="badgeClass(c)">{{ statusLabel(c) }}</span>
            </div>
          </div>
          <button v-if="hasMore" :disabled="rowsLoading" class="mt-3 w-full rounded-xl border border-[#E8EEEF] py-2.5 text-sm font-bold text-[#505E5E]" @click="loadRows(page + 1)">
            {{ rowsLoading ? 'กำลังโหลด...' : 'ดูเพิ่ม' }}
          </button>
        </div>

        <!-- กติกา -->
        <div class="bg-white rounded-[20px] border border-[#E8EEEF] p-5">
          <p class="mb-2 font-bold text-[#1a1c1c] text-sm">กติกาค่าคอม</p>
          <ul class="space-y-1.5 text-xs text-[#505E5E]">
            <li v-for="rule in data.rules" :key="rule" class="flex gap-2"><span class="text-[#7c3aed]">•</span>{{ rule }}</li>
          </ul>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';

// หน้าของพาร์ทเนอร์ช่วยขาย — ยอดทุกตัวมาจาก GET /partner (App\Services\PartnerService)

const toast = useToast();
const loading = ref(true);
const data = ref(null);
const copied = ref('');
const tripLink = ref('');
const editingAccount = ref(false);
const saving = ref(false);
const account = ref({ bank: '', number: '', name: '' });

const tabs = [
  { key: null, label: 'ทั้งหมด' },
  { key: 'pending', label: 'รอเดินทาง' },
  { key: 'approved', label: 'รอโอน' },
  { key: 'paid', label: 'โอนแล้ว' },
  { key: 'void', label: 'ไม่ได้คอม' },
];
const filter = ref(null);
const rows = ref([]);
const page = ref(1);
const hasMore = ref(false);
const rowsLoading = ref(false);

const money = (n) => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });

function thaiDate(d) {
  if (!d) return '';
  const date = new Date(`${d}T00:00:00`);
  return Number.isNaN(date.getTime()) ? d : date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });
}

const statusLabel = (c) => (c.type === 'clawback'
  ? 'หักคืน'
  : { pending: 'รอเดินทาง', approved: 'รอโอน', paid: 'โอนแล้ว', void: 'ไม่ได้คอม' }[c.status] || c.status);

const badgeClass = (c) => ({
  pending: 'bg-orange-50 text-orange-700',
  approved: 'bg-green-50 text-green-700',
  paid: 'bg-violet-50 text-violet-700',
  void: 'bg-gray-100 text-gray-500',
}[c.status] || 'bg-gray-100 text-gray-500');

// ต่อ ?partner= ให้ลิงก์ในโดเมนของเราเท่านั้น
const taggedTripLink = computed(() => {
  const raw = tripLink.value.trim();
  if (!raw || !data.value) return '';
  try {
    const url = new URL(raw);
    const ours = new URL(data.value.share_url);
    if (url.host !== ours.host) return '';
    url.searchParams.set('partner', data.value.code);
    return url.toString();
  } catch {
    return '';
  }
});

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/partner');
    data.value = res.data.data;
    if (data.value) await loadRows(1);
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดข้อมูลไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

async function loadRows(p) {
  rowsLoading.value = true;
  try {
    const params = { page: p };
    if (filter.value) params.status = filter.value;
    const res = await api.get('/partner/commissions', { params });
    rows.value = p === 1 ? res.data.data : [...rows.value, ...res.data.data];
    page.value = res.data.meta.current_page;
    hasMore.value = res.data.meta.current_page < res.data.meta.last_page;
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดรายการไม่สำเร็จ');
  } finally {
    rowsLoading.value = false;
  }
}

function setFilter(key) {
  filter.value = key;
  loadRows(1);
}

async function saveAccount() {
  saving.value = true;
  try {
    const res = await api.put('/partner/payout-account', account.value);
    data.value.payout_account = res.data.data.payout_account;
    editingAccount.value = false;
    account.value = { bank: '', number: '', name: '' };
    toast.success('บันทึกบัญชีรับเงินแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(text);
    copied.value = key;
    setTimeout(() => { if (copied.value === key) copied.value = ''; }, 2000);
  } catch {
    toast.error('คัดลอกไม่สำเร็จ');
  }
}

async function share() {
  const url = data.value.share_url;
  if (navigator.share) {
    try {
      await navigator.share({ title: 'ทริปเที่ยวกับลุยเลเขา', url });
      return;
    } catch {
      // ผู้ใช้กดยกเลิก
      return;
    }
  }
  copy(url, 'url');
}

onMounted(load);
</script>
