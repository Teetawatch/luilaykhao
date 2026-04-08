<template>
  <div class="min-h-screen bg-[#f9f9f9] pt-8 pb-32">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">

      <section class="mb-6 flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-1" style="font-family:'Anuphan',sans-serif;">
            การแจ้งเตือน
          </h1>
          <p class="text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">
            {{ unreadCount > 0 ? `${unreadCount} รายการที่ยังไม่ได้อ่าน` : 'อ่านครบทุกรายการแล้ว' }}
          </p>
        </div>
        <button
          v-if="unreadCount > 0"
          @click="markAllRead"
          class="text-sm text-[#006565] font-semibold hover:opacity-70 transition-opacity"
          style="font-family:'Anuphan',sans-serif;">
          อ่านทั้งหมด
        </button>
      </section>

      <div v-if="loading" class="text-center py-20">
        <div class="inline-block w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <div v-else-if="notifications.length === 0" class="text-center py-20">
        <img src="/images/notification_notshow.png" alt="No notifications" class="w-100 h-100 mx-auto mb-4 object-contain opacity-90" />
        <p class="text-[#3e4949] text-lg" style="font-family:'Anuphan',sans-serif;">ยังไม่มีการแจ้งเตือน</p>
      </div>

      <div v-else class="space-y-2">
        <div
          v-for="n in notifications"
          :key="n.id"
          @click="handleNotificationClick(n)"
          class="bg-white rounded-2xl p-4 flex gap-4 cursor-pointer transition-all hover:shadow-md"
          :class="{ 'border-l-4 border-[#006565]': !n.is_read }"
          style="box-shadow:0px 2px 8px rgba(0,64,64,0.04);">

          <div
            class="w-11 h-11 rounded-full flex items-center justify-center text-xl shrink-0"
            :class="notifBg(n.type)">
            {{ notifIcon(n.type) }}
          </div>

          <div class="flex-1 min-w-0">
            <div class="flex justify-between items-start gap-2">
              <p class="font-semibold text-[#1a1c1c] text-sm" :class="{ 'font-bold': !n.is_read }" style="font-family:'Anuphan',sans-serif;">
                {{ n.title }}
              </p>
              <div class="flex items-center gap-2 shrink-0">
                <span v-if="!n.is_read" class="w-2 h-2 bg-[#006565] rounded-full"></span>
                <button
                  @click.stop="deleteNotification(n.id)"
                  class="text-[#bdc9c8] hover:text-red-400 text-lg transition-colors leading-none">
                  ×
                </button>
              </div>
            </div>
            <p class="text-sm text-[#3e4949] mt-0.5 leading-relaxed" style="font-family:'Anuphan',sans-serif;">{{ n.body }}</p>
            <p class="text-xs text-[#bdc9c8] mt-1.5" style="font-family:'Anuphan',sans-serif;">{{ timeAgo(n.created_at) }}</p>
          </div>
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
    seat_alert: '🔥',
    booking_reminder: '📅',
    promo: '🎁',
    system: 'ℹ️',
    loyalty: '⭐',
  };
  return map[type] || '🔔';
}

function notifBg(type) {
  const map = {
    seat_alert: 'bg-red-100',
    booking_reminder: 'bg-blue-100',
    promo: 'bg-yellow-100',
    system: 'bg-gray-100',
    loyalty: 'bg-yellow-100',
  };
  return map[type] || 'bg-[#f0fafa]';
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
