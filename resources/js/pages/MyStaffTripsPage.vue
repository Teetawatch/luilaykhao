<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-24">
    <div class="max-w-5xl mx-auto px-4 sm:px-6">
      <section class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-[#1a1c1c] mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ตารางงานสตาฟของฉัน</h1>
          <p class="text-sm text-[#505E5E]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ดูว่าคุณได้รับมอบหมายให้ดูแลทริปไหน วันไหนบ้าง</p>
        </div>
        <button class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-[#D7E0E1] text-[#006565] font-semibold text-sm hover:bg-[#F7FBFB]" @click="loadData" :disabled="loading" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          <span class="material-symbols-rounded text-[18px]">refresh</span>
          รีเฟรช
        </button>
      </section>

      <div class="grid sm:grid-cols-2 gap-4 mb-6">
        <div class="bg-white border border-[#E8EEEF] rounded-2xl p-4">
          <div class="text-xs text-[#889696] font-bold uppercase mb-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">คะแนนเฉลี่ยความพึงพอใจ</div>
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-[#F59E0B]">star</span>
            <span class="text-2xl font-bold text-[#1a1c1c]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ summary.avg_rating ?? '-' }}</span>
          </div>
        </div>

        <div class="bg-white border border-[#E8EEEF] rounded-2xl p-4">
          <div class="text-xs text-[#889696] font-bold uppercase mb-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">จำนวนรีวิวที่ได้รับ</div>
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-[#006565]">reviews</span>
            <span class="text-2xl font-bold text-[#1a1c1c]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ summary.total_reviews || 0 }}</span>
          </div>
        </div>
      </div>

      <div v-if="loading" class="flex justify-center py-20">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <div v-else-if="!isStaff" class="bg-white rounded-2xl border border-[#E8EEEF] p-10 text-center">
        <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">lock</span>
        <p class="mt-3 text-[#505E5E] font-medium" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">หน้านี้สำหรับผู้ใช้ที่มีสิทธิ์สตาฟเท่านั้น</p>
      </div>

      <div v-else-if="!schedules.length" class="bg-white rounded-2xl border border-[#E8EEEF] p-10 text-center">
        <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">event_busy</span>
        <p class="mt-3 text-[#505E5E] font-medium" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ยังไม่มีงานที่ได้รับมอบหมายในตอนนี้</p>
      </div>

      <div v-else class="space-y-4">
        <article v-for="sch in schedules" :key="sch.id" class="bg-white border border-[#E8EEEF] rounded-2xl p-4 sm:p-5">
          <div class="flex flex-wrap justify-between gap-3 mb-3">
            <div>
              <h2 class="text-lg font-bold text-[#1a1c1c]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ sch.trip?.title || 'ไม่ระบุทริป' }}</h2>
              <p class="text-sm text-[#6b7280]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ sch.trip?.location || '-' }}</p>
            </div>
            <span class="inline-flex items-center h-fit px-3 py-1 rounded-full text-xs font-bold" :class="statusClass(sch.status)" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
              {{ statusLabel(sch.status) }}
            </span>
          </div>

          <div class="grid sm:grid-cols-3 gap-3 text-sm text-[#334155]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            <div class="info-box">
              <span class="material-symbols-rounded text-[18px] text-[#006565]">event</span>
              <div>
                <div class="text-[#889696] text-xs font-bold uppercase">วันเดินทาง</div>
                <div class="font-semibold">{{ formatDate(sch.departure_date) }}</div>
              </div>
            </div>
            <div class="info-box">
              <span class="material-symbols-rounded text-[18px] text-[#006565]">event_repeat</span>
              <div>
                <div class="text-[#889696] text-xs font-bold uppercase">วันกลับ</div>
                <div class="font-semibold">{{ formatDate(sch.return_date) }}</div>
              </div>
            </div>
            <div class="info-box">
              <span class="material-symbols-rounded text-[18px] text-[#006565]">airport_shuttle</span>
              <div>
                <div class="text-[#889696] text-xs font-bold uppercase">พาหนะ</div>
                <div class="font-semibold">{{ sch.vehicle?.name || sch.transport_type || '-' }}</div>
              </div>
            </div>
          </div>
        </article>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '../stores/auth';
import api from '../lib/axios';

const auth = useAuthStore();

const loading = ref(false);
const summary = ref({ avg_rating: null, total_reviews: 0 });
const schedules = ref([]);

const isStaff = computed(() => {
  const roles = auth.user?.roles?.map((r) => (typeof r === 'string' ? r : r.name)) || [];
  return roles.includes('staff');
});

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
};

const statusLabel = (status) => {
  const map = {
    open: 'เปิดรับจอง',
    closed: 'ปิดรับจอง',
    full: 'เต็ม',
    cancelled: 'ยกเลิก',
  };

  return map[status] || status;
};

const statusClass = (status) => {
  const map = {
    open: 'bg-[#ECFDF3] text-[#047857]',
    closed: 'bg-[#F3F4F6] text-[#4B5563]',
    full: 'bg-[#FFF7ED] text-[#9A3412]',
    cancelled: 'bg-[#FEF2F2] text-[#B91C1C]',
  };

  return map[status] || 'bg-[#F3F4F6] text-[#4B5563]';
};

const loadData = async () => {
  if (!auth.isLoggedIn) return;

  loading.value = true;
  try {
    const res = await api.get('/staff/schedules/my');
    summary.value = res.data.data?.summary || { avg_rating: null, total_reviews: 0 };
    schedules.value = res.data.data?.schedules || [];
  } catch (e) {
    if (e?.response?.status === 403) {
      schedules.value = [];
      return;
    }

    alert(e?.response?.data?.message || 'โหลดตารางงานสตาฟไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

onMounted(loadData);
</script>

<style scoped>
.info-box {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid #e8eeef;
  border-radius: 12px;
  background: #f8fbfb;
  padding: 10px 12px;
}
</style>
