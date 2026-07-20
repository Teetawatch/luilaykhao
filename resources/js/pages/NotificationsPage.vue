<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">

      <section class="mb-8 flex items-end justify-between">
        <div>
          <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            การแจ้งเตือน
          </h1>
          <p class="text-[#505E5E] text-sm" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            {{ unreadCount > 0 ? `คุณมี ${unreadCount} รายการใหม่ที่ยังไม่ได้อ่าน` : 'คุณอ่านการแจ้งเตือนครบทุกรายการแล้ว' }}
          </p>
        </div>
        <button
          v-if="unreadCount > 0"
          @click="markAllRead"
          class="flex items-center gap-1.5 text-sm text-[#006565] font-semibold hover:text-[#004f4f] bg-[#006565]/10 hover:bg-[#006565]/15 px-4 py-2.5 rounded-full transition-all"
          style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          <span class="material-symbols-rounded text-[20px]">done_all</span>
          <span>อ่านทั้งหมด</span>
        </button>
      </section>

      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">กำลังโหลดข้อมูล...</p>
      </div>

      <div v-else-if="notifications.length === 0" class="text-center py-20 bg-white rounded-[24px] border border-[#E8EEEF] flex flex-col items-center justify-center">
        <div class="w-20 h-20 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5">
          <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">notifications_off</span>
        </div>
        <h3 class="text-lg font-bold text-[#1a1c1c] mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ไม่มีการแจ้งเตือนใหม่</h3>
        <p class="text-[#505E5E] text-sm" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ขณะนี้คุณยังไม่มีข้อความแจ้งเตือนใดๆ</p>
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="n in notifications"
          :key="n.id"
          @click="handleNotificationClick(n)"
          class="group relative bg-white rounded-[20px] p-5 flex gap-4 cursor-pointer transition-all duration-300 border border-[#E8EEEF] hover:border-[#006565]/30 overflow-hidden"
          :class="{ 'opacity-80': n.is_read }">
          
          <!-- Unread Indicator Line -->
          <div v-if="!n.is_read" class="absolute left-0 top-0 bottom-0 w-1.5 bg-[#006565]"></div>

          <div
            class="w-14 h-14 rounded-[16px] flex items-center justify-center shrink-0 transition-transform group-hover:scale-105"
            :class="[notifStyle(n.type).bg, !n.is_read ? 'ml-1' : '']">
            <span class="material-symbols-rounded text-[28px]" :class="notifStyle(n.type).text">
              {{ notifIcon(n.type) }}
            </span>
          </div>

          <div class="flex-1 min-w-0 pr-6">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-1 sm:gap-4 mb-1">
              <h4 class="font-semibold text-[15px] text-[#1a1c1c] line-clamp-1" :class="{ 'font-bold': !n.is_read }" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                {{ n.title }}
              </h4>
              <p class="text-xs font-medium text-[#889696] whitespace-nowrap shrink-0 sm:mt-0.5" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                {{ timeAgo(n.created_at) }}
              </p>
            </div>
            <p class="text-[14px] text-[#505E5E] line-clamp-2 leading-relaxed" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
              {{ n.body }}
            </p>
          </div>

          <button
            @click.stop="deleteNotification(n.id)"
            class="absolute top-1/2 -translate-y-1/2 right-4 w-10 h-10 flex items-center justify-center rounded-full text-[#A0B0B0] bg-white opacity-0 md:group-hover:opacity-100 max-md:opacity-100 hover:bg-[#FFF0F0] hover:text-[#DC2626] transition-all duration-200 border border-transparent hover:border-[#FCA5A5]"
            title="ลบการแจ้งเตือน">
            <span class="material-symbols-rounded text-[20px]">delete</span>
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../lib/axios';

const router = useRouter();
const loading = ref(true);
const notifications = ref([]);

const unreadCount = computed(() => notifications.value.filter(n => !n.is_read).length);

async function loadNotifications() {
  loading.value = true;
  try {
    const res = await api.get('/notifications', { params: { per_page: 50 } });
    notifications.value = res.data.data;
  } finally {
    loading.value = false;
  }
}

async function handleNotificationClick(n) {
  if (!n.is_read) {
    await api.put(`/notifications/${n.id}/read`);
    n.is_read = true;
    n.read_at = new Date().toISOString();
  }
  if (n.data?.booking_ref) {
    router.push(`/confirmation/${n.data.booking_ref}`);
  } else if (n.data?.trip_slug) {
    router.push(`/trips/${n.data.trip_slug}`);
  }
}

async function markAllRead() {
  await api.put('/notifications/read-all');
  notifications.value.forEach(n => { n.is_read = true; });
}

async function deleteNotification(id) {
  await api.delete(`/notifications/${id}`);
  notifications.value = notifications.value.filter(n => n.id !== id);
}

function notifIcon(type) {
  const map = {
    seat_alert: 'local_fire_department',
    booking_reminder: 'calendar_month',
    promo: 'featured_seasonal_and_gifts',
    system: 'info',
    loyalty: 'star',
  };
  return map[type] || 'notifications';
}

function notifStyle(type) {
  const map = {
    seat_alert: { bg: 'bg-[#FEF2F2]', text: 'text-[#DC2626]' },
    booking_reminder: { bg: 'bg-[#EFF6FF]', text: 'text-[#2563EB]' },
    promo: { bg: 'bg-[#FFFBEB]', text: 'text-[#D97706]' },
    system: { bg: 'bg-[#F4F7F6]', text: 'text-[#505E5E]' },
    loyalty: { bg: 'bg-[#FFF7ED]', text: 'text-[#EA580C]' },
  };
  return map[type] || { bg: 'bg-[#E3F2F2]', text: 'text-[#006565]' };
}

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const diff = Date.now() - new Date(dateStr).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1) return 'เมื่อกี้';
  if (m < 60) return `${m} นาทีที่แล้ว`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} ชั่วโมงที่แล้ว`;
  const d = Math.floor(h / 24);
  if (d < 7) return `${d} วันที่แล้ว`;
  return new Date(dateStr).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

onMounted(loadNotifications);
</script>
