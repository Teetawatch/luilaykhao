<template>
  <div class="min-h-screen bg-[#F4F7F6] font-anuphan flex flex-col" style="height: 100dvh;">

    <!-- Header -->
    <div class="bg-white border-b border-[#E8EEEF] px-4 py-3 flex items-center gap-3 shrink-0">
      <button @click="$router.back()" class="w-9 h-9 rounded-[10px] border border-[#E8EEEF] flex items-center justify-center hover:bg-[#F4F7F6] transition-all">
        <span class="material-symbols-rounded text-[20px] text-[#505E5E]">arrow_back</span>
      </button>
      <div class="flex-1 min-w-0">
        <h1 class="text-base font-bold text-[#1a1c1c] truncate" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          {{ tripTitle || 'แชทกลุ่มทริป' }}
        </h1>
        <p v-if="departureDate" class="text-xs text-[#889696]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          เดินทาง {{ formatDate(departureDate) }}
        </p>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-2 h-2 rounded-full transition-colors" :class="wsConnected ? 'bg-green-500' : 'bg-gray-300'"></span>
        <span class="text-[11px] font-medium" :class="wsConnected ? 'text-green-600' : 'text-gray-400'" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          {{ wsConnected ? 'เรียลไทม์' : 'ออฟไลน์' }}
        </span>
      </div>
    </div>

    <!-- Error State -->
    <div v-if="errorMsg" class="flex-1 flex flex-col items-center justify-center p-8 text-center">
      <div class="w-16 h-16 rounded-full bg-red-50 flex items-center justify-center mb-4">
        <span class="material-symbols-rounded text-3xl text-red-400">lock</span>
      </div>
      <p class="text-[#1a1c1c] font-bold mb-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ไม่สามารถเข้าถึงได้</p>
      <p class="text-sm text-[#505E5E]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ errorMsg }}</p>
      <button @click="$router.push('/my-bookings')" class="mt-5 px-5 py-2.5 bg-[#006565] text-white rounded-full text-sm font-bold" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
        กลับหน้าการจอง
      </button>
    </div>

    <template v-else>
      <!-- Load older messages -->
      <div class="shrink-0 text-center py-2" v-if="hasMore">
        <button @click="loadOlder" :disabled="loadingOlder" class="text-xs font-bold text-[#006565] hover:underline disabled:opacity-50" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          {{ loadingOlder ? 'กำลังโหลด...' : 'โหลดข้อความก่อนหน้า' }}
        </button>
      </div>

      <!-- Message Thread -->
      <div ref="threadEl" class="flex-1 overflow-y-auto px-4 py-3 space-y-3" @scroll="onScroll">
        <div v-if="loading" class="flex justify-center py-8">
          <div class="w-8 h-8 border-3 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        </div>

        <template v-else>
          <div v-if="messages.length === 0" class="flex flex-col items-center justify-center py-16 text-center">
            <div class="w-16 h-16 rounded-full bg-[#E8EEEF] flex items-center justify-center mb-4">
              <span class="material-symbols-rounded text-3xl text-[#A0B0B0]">chat_bubble</span>
            </div>
            <p class="text-[#505E5E] text-sm" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ยังไม่มีข้อความ เริ่มสนทนากับสตาฟได้เลย!</p>
          </div>

          <div
            v-for="m in messages"
            :key="m.id"
            class="flex gap-2"
            :class="m.is_mine ? 'flex-row-reverse' : 'flex-row'"
          >
            <!-- Avatar -->
            <div v-if="!m.is_mine" class="w-8 h-8 rounded-full shrink-0 overflow-hidden mt-auto">
              <img v-if="m.user?.avatar_url" :src="m.user.avatar_url" :alt="m.user?.name" class="w-full h-full object-cover" />
              <div v-else class="w-full h-full flex items-center justify-center text-[11px] font-black text-white"
                :class="roleAvatarClass(m.sender_role)">
                {{ roleInitial(m) }}
              </div>
            </div>

            <div class="flex flex-col gap-0.5" :class="m.is_mine ? 'items-end' : 'items-start'" style="max-width: 72%;">
              <!-- Name + role -->
              <div v-if="!m.is_mine" class="flex items-center gap-1.5 px-1">
                <span class="text-[11px] font-bold text-[#374151]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  {{ m.user?.nickname || m.user?.name || 'ผู้ใช้' }}
                </span>
                <TierBadge :tier="m.user?.tier" :label="m.user?.tier_label" size="sm" />
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full" :class="roleTagClass(m.sender_role)" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                  {{ roleLabel(m.sender_role) }}
                </span>
              </div>

              <!-- Bubble -->
              <div class="px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed"
                :class="m.is_mine
                  ? 'bg-[#006565] text-white rounded-tr-sm'
                  : 'bg-white text-[#1a1c1c] border border-[#E8EEEF] rounded-tl-sm'"
                style="font-family:'DB Heavent', 'Anuphan',sans-serif; word-break: break-word;">
                <!-- Image -->
                <img v-if="m.image_url" :src="m.image_url" alt="รูปภาพ"
                  class="max-w-[220px] rounded-xl mb-1 cursor-pointer"
                  @click="openImage(m.image_url)" />
                <span v-if="m.body">{{ m.body }}</span>
              </div>

              <!-- Time -->
              <span class="text-[10px] text-[#A0B0B0] px-1" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ formatTime(m.created_at) }}</span>
            </div>
          </div>
        </template>
      </div>

      <!-- Input -->
      <div class="shrink-0 bg-white border-t border-[#E8EEEF] px-4 py-3 flex items-end gap-2 safe-area-pb">
        <!-- Image preview -->
        <div v-if="imagePreview" class="absolute bottom-20 left-4 right-4 bg-white border border-[#E8EEEF] rounded-2xl p-3 flex items-center gap-3">
          <img :src="imagePreview" class="w-16 h-16 rounded-xl object-cover" />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-[#1a1c1c] truncate" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ imageFile?.name }}</p>
            <p class="text-xs text-[#889696]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">แนบรูปภาพ</p>
          </div>
          <button @click="clearImage" class="w-7 h-7 rounded-full bg-[#F4F7F6] flex items-center justify-center">
            <span class="material-symbols-rounded text-[16px] text-[#505E5E]">close</span>
          </button>
        </div>

        <label class="w-9 h-9 rounded-[10px] border border-[#E8EEEF] flex items-center justify-center cursor-pointer hover:bg-[#F4F7F6] transition-all shrink-0">
          <span class="material-symbols-rounded text-[20px] text-[#505E5E]">image</span>
          <input type="file" accept="image/*" class="hidden" ref="fileInputEl" @change="onFileChange" />
        </label>

        <div class="flex-1 bg-[#F4F7F6] rounded-[16px] px-3.5 py-2 flex items-center">
          <textarea
            v-model="draft"
            rows="1"
            placeholder="พิมพ์ข้อความ..."
            maxlength="2000"
            :disabled="sending"
            @keydown.enter.exact.prevent="send"
            @input="autoResize"
            ref="textareaEl"
            class="flex-1 bg-transparent text-sm text-[#1a1c1c] resize-none outline-none placeholder-[#A0B0B0] max-h-28 leading-relaxed"
            style="font-family:'DB Heavent', 'Anuphan',sans-serif;"
          ></textarea>
        </div>

        <button @click="send" :disabled="sending || (!draft.trim() && !imageFile)"
          class="w-9 h-9 rounded-[10px] flex items-center justify-center transition-all shrink-0"
          :class="(draft.trim() || imageFile) && !sending ? 'bg-[#006565] text-white' : 'bg-[#E8EEEF] text-[#A0B0B0]'">
          <span v-if="sending" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
          <span v-else class="material-symbols-rounded text-[20px]">send</span>
        </button>
      </div>
    </template>

    <!-- Lightbox -->
    <div v-if="lightboxUrl" class="fixed inset-0 bg-black/90 z-50 flex items-center justify-center p-4" @click="lightboxUrl = null">
      <img :src="lightboxUrl" class="max-w-full max-h-full rounded-xl object-contain" />
    </div>
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import TierBadge from '../components/TierBadge.vue';

const route = useRoute();
const scheduleId = route.params.scheduleId;

const tripTitle = ref(route.query.title || '');
const departureDate = ref(route.query.date || '');
const messages = ref([]);
const loading = ref(true);
const loadingOlder = ref(false);
const hasMore = ref(false);
const draft = ref('');
const sending = ref(false);
const wsConnected = ref(false);
const errorMsg = ref('');
const threadEl = ref(null);
const textareaEl = ref(null);
const fileInputEl = ref(null);
const imageFile = ref(null);
const imagePreview = ref(null);
const lightboxUrl = ref(null);
let channel = null;
let pollTimer = null;

async function loadMessages() {
  loading.value = true;
  try {
    const res = await api.get(`/schedules/${scheduleId}/chat/messages`, { params: { per_page: 30 } });
    const data = res.data.data;
    messages.value = data.messages || [];
    hasMore.value = !!data.has_more;

    scrollToBottom(true);
    markRead();
  } catch (e) {
    const status = e?.response?.status;
    if (status === 403) {
      errorMsg.value = 'คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้';
    } else if (status === 404) {
      errorMsg.value = 'ไม่พบห้องแชทนี้';
    } else {
      errorMsg.value = 'ไม่สามารถโหลดข้อความได้ กรุณาลองใหม่';
    }
  } finally {
    loading.value = false;
  }
}

async function loadOlder() {
  if (loadingOlder.value || !hasMore.value) return;
  const oldestId = messages.value[0]?.id;
  if (!oldestId) return;

  loadingOlder.value = true;
  const prevHeight = threadEl.value?.scrollHeight ?? 0;
  try {
    const res = await api.get(`/schedules/${scheduleId}/chat/messages`, {
      params: { per_page: 30, before_id: oldestId },
    });
    const data = res.data.data;
    messages.value = [...(data.messages || []), ...messages.value];
    hasMore.value = !!data.has_more;
    await nextTick();
    if (threadEl.value) {
      threadEl.value.scrollTop = threadEl.value.scrollHeight - prevHeight;
    }
  } finally {
    loadingOlder.value = false;
  }
}

async function send() {
  if (sending.value || (!draft.value.trim() && !imageFile.value)) return;
  sending.value = true;
  try {
    let res;
    if (imageFile.value) {
      const form = new FormData();
      if (draft.value.trim()) form.append('body', draft.value.trim());
      form.append('image', imageFile.value);
      res = await api.post(`/schedules/${scheduleId}/chat/messages`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
    } else {
      res = await api.post(`/schedules/${scheduleId}/chat/messages`, { body: draft.value.trim() });
    }
    messages.value.push(res.data.data);
    draft.value = '';
    clearImage();
    resetTextarea();
    scrollToBottom();
  } catch (e) {
    alert(e?.response?.data?.message || 'ส่งข้อความไม่สำเร็จ');
  } finally {
    sending.value = false;
  }
}

function markRead() {
  api.post(`/schedules/${scheduleId}/chat/read`).catch(() => {});
}

function subscribe() {
  if (!window.Echo) {
    startPolling();
    return;
  }
  channel = window.Echo.private(`chat.schedule.${scheduleId}`)
    .listen('.chat.message', (data) => {
      if (!messages.value.find(m => m.id === data.id)) {
        messages.value.push({ ...data, is_mine: false });
        scrollToBottom();
        markRead();
      }
    })
    .subscribed(() => { wsConnected.value = true; })
    .error(() => {
      wsConnected.value = false;
      startPolling();
    });
}

function startPolling() {
  if (pollTimer) return;
  pollTimer = setInterval(async () => {
    const lastId = messages.value.at(-1)?.id ?? 0;
    try {
      const res = await api.get(`/schedules/${scheduleId}/chat/messages`, {
        params: { after_id: lastId, per_page: 20 },
      });
      const newMsgs = res.data.data?.messages || [];
      if (newMsgs.length) {
        messages.value.push(...newMsgs);
        scrollToBottom();
        markRead();
      }
    } catch {}
  }, 5000);
}

function onFileChange(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  imageFile.value = file;
  imagePreview.value = URL.createObjectURL(file);
}

function clearImage() {
  imageFile.value = null;
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value);
  imagePreview.value = null;
  if (fileInputEl.value) fileInputEl.value.value = '';
}

function openImage(url) {
  lightboxUrl.value = url;
}

function scrollToBottom(instant = false) {
  nextTick(() => {
    if (threadEl.value) {
      threadEl.value.scrollTo({ top: threadEl.value.scrollHeight, behavior: instant ? 'instant' : 'smooth' });
    }
  });
}

function autoResize() {
  if (!textareaEl.value) return;
  textareaEl.value.style.height = 'auto';
  textareaEl.value.style.height = Math.min(textareaEl.value.scrollHeight, 112) + 'px';
}

function resetTextarea() {
  if (!textareaEl.value) return;
  textareaEl.value.style.height = 'auto';
}

function onScroll() {
  if (threadEl.value?.scrollTop === 0 && hasMore.value) loadOlder();
}

function roleLabel(role) {
  return { customer: 'ลูกค้า', staff: 'สตาฟ', admin: 'แอดมิน', system: 'ระบบ' }[role] || role;
}

function roleInitial(m) {
  const name = m.user?.nickname || m.user?.name || '?';
  return name.charAt(0).toUpperCase();
}

function roleAvatarClass(role) {
  return {
    staff: 'bg-[#0C4A6E]',
    admin: 'bg-[#92400E]',
    customer: 'bg-[#006565]',
  }[role] || 'bg-[#A0B0B0]';
}

function roleTagClass(role) {
  return {
    staff: 'bg-cyan-50 text-cyan-700',
    admin: 'bg-amber-50 text-amber-700',
    customer: 'bg-[#E3F2F2] text-[#006565]',
  }[role] || 'bg-gray-100 text-gray-500';
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

onMounted(async () => {
  await loadMessages();
  if (!errorMsg.value) subscribe();
});

onBeforeUnmount(() => {
  if (channel && window.Echo) window.Echo.leave(`chat.schedule.${scheduleId}`);
  if (pollTimer) clearInterval(pollTimer);
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value);
});
</script>
