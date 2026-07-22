<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen py-8 md:py-12 px-4 md:px-8">
    <div class="max-w-3xl mx-auto">

      <header class="mb-6">
        <h1 class="text-2xl md:text-3xl font-extrabold text-text-dark">ศูนย์ช่วยเหลือ</h1>
        <p class="mt-2 text-text-muted leading-relaxed">
          คุยกับทีมงานได้ที่นี่ ข้อความอยู่ในเธรดเดียวกันทั้งหมด ทีมงานเห็นประวัติการจองของคุณอยู่แล้ว
          ไม่ต้องเล่าใหม่ทุกครั้ง
        </p>
      </header>

      <div class="bg-white rounded-[2rem] border border-sand-dark/30 overflow-hidden flex flex-col h-[calc(100vh-16rem)] min-h-[26rem]">

        <!-- Thread -->
        <div ref="threadEl" class="flex-1 overflow-y-auto px-4 md:px-6 py-5 space-y-3">
          <div v-if="loading" class="h-full flex items-center justify-center">
            <div class="w-8 h-8 rounded-full border-4 border-sand-dark/40 border-t-primary animate-spin"></div>
          </div>

          <template v-else>
            <button v-if="hasMore" type="button" @click="loadOlder" :disabled="loadingOlder"
              class="mx-auto block px-4 py-1.5 rounded-full text-xs font-bold text-text-muted bg-sand/60 hover:bg-sand disabled:opacity-50">
              {{ loadingOlder ? 'กำลังโหลด...' : 'ดูข้อความก่อนหน้า' }}
            </button>

            <!-- Empty state: ตัวอย่างเรื่องที่ทักเข้ามาบ่อย กดแล้วเติมให้เลย -->
            <div v-if="!messages.length" class="h-full flex flex-col items-center justify-center text-center px-4">
              <span class="material-symbols-rounded text-5xl text-sand-dark">forum</span>
              <p class="mt-3 font-bold text-text-dark">ยังไม่มีข้อความ</p>
              <p class="mt-1 text-sm text-text-muted">พิมพ์เรื่องที่อยากถามได้เลย หรือเริ่มจากหัวข้อเหล่านี้</p>
              <div class="mt-4 flex flex-wrap justify-center gap-2">
                <button v-for="s in starters" :key="s" type="button" @click="draft = s"
                  class="px-3 py-2 rounded-xl text-sm font-bold text-text-mid bg-sand/60 border border-sand-dark/40 hover:border-primary hover:text-primary transition-colors">
                  {{ s }}
                </button>
              </div>
            </div>

            <template v-for="(m, i) in messages" :key="m.id">
              <p v-if="m.sender_role === 'system'" class="text-center text-xs text-text-muted font-medium py-2">
                {{ m.body }}
              </p>
              <div v-else class="flex gap-2" :class="m.is_mine ? 'justify-end' : 'justify-start'">
                <div v-if="!m.is_mine" class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0 self-end"
                  :class="{ 'opacity-0': !showAvatar(i) }">
                  <span class="material-symbols-rounded text-primary text-[18px]">support_agent</span>
                </div>
                <div class="max-w-[78%] rounded-2xl px-4 py-2.5"
                  :class="m.is_mine ? 'bg-primary text-white' : 'bg-sand/70 text-text-dark'">
                  <button v-if="m.image_url" type="button" @click="lightbox = m.image_url" class="block mb-1.5">
                    <img :src="m.image_url" alt="รูปที่แนบมา" class="rounded-xl max-h-64 w-auto" />
                  </button>
                  <p v-if="m.body" class="text-sm leading-relaxed whitespace-pre-line break-words">{{ m.body }}</p>
                  <p class="text-[10px] mt-1 font-medium" :class="m.is_mine ? 'text-white/60' : 'text-text-muted'">
                    {{ formatTime(m.created_at) }}
                  </p>
                </div>
              </div>
            </template>
          </template>
        </div>

        <!-- Composer -->
        <div class="border-t border-sand-dark/30 px-4 md:px-6 py-3">
          <div v-if="imagePreview" class="mb-3 relative w-20 h-20">
            <img :src="imagePreview" alt="" class="w-full h-full object-cover rounded-xl border border-sand-dark/40" />
            <button type="button" @click="clearPendingImage"
              class="absolute -top-2 -right-2 w-6 h-6 rounded-full bg-text-dark text-white flex items-center justify-center">
              <span class="material-symbols-rounded text-[15px]">close</span>
            </button>
          </div>

          <form class="flex items-end gap-2" @submit.prevent="send">
            <label class="w-11 h-11 rounded-xl bg-sand/60 border border-sand-dark/40 flex items-center justify-center cursor-pointer hover:bg-sand transition-colors shrink-0"
              :class="{ 'opacity-50 pointer-events-none': sending }" title="แนบรูปภาพ">
              <span class="material-symbols-rounded text-text-mid">image</span>
              <input type="file" accept="image/*" hidden :disabled="sending" @change="onPickImage" />
            </label>
            <textarea v-model="draft" rows="1" maxlength="2000" :disabled="sending"
              placeholder="พิมพ์ข้อความถึงทีมงาน..."
              @keydown.enter.exact.prevent="send"
              class="flex-1 resize-none px-4 py-3 bg-sand/30 border border-sand-dark/40 rounded-2xl text-sm focus:outline-none focus:border-primary focus:bg-white transition-colors max-h-32"></textarea>
            <button type="submit" :disabled="sending || (!draft.trim() && !pendingImage)"
              class="w-11 h-11 rounded-xl bg-primary text-white flex items-center justify-center disabled:opacity-40 active:scale-95 transition-all shrink-0">
              <span v-if="sending" class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              <span v-else class="material-symbols-rounded text-[20px]">send</span>
            </button>
          </form>
        </div>
      </div>

      <p class="mt-4 text-sm text-text-muted text-center">
        เรื่องด่วนวันเดินทาง โทร <a href="tel:0626126006" class="font-bold text-primary">062-612-6006</a>
        · เรื่องทั่วไปตอบในเวลาทำการ 09:00-20:00 น.
      </p>
    </div>

    <!-- Lightbox -->
    <div v-if="lightbox" class="fixed inset-0 z-[200] bg-black/90 flex items-center justify-center p-4" @click="lightbox = null">
      <img :src="lightbox" alt="รูปที่แนบมา" class="max-w-full max-h-full object-contain rounded-xl" />
      <button type="button" class="absolute top-5 right-5 w-11 h-11 rounded-full bg-white/10 text-white flex items-center justify-center">
        <span class="material-symbols-rounded">close</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';

const toast = useToast();

const conversationId = ref(null);
const messages = ref([]);
const loading = ref(true);
const loadingOlder = ref(false);
const hasMore = ref(false);
const draft = ref('');
const sending = ref(false);
const threadEl = ref(null);
const pendingImage = ref(null);
const imagePreview = ref(null);
const lightbox = ref(null);

let channel = null;
let pollTimer = null;

const starters = [
  'สอบถามเรื่องการจองของฉัน',
  'โอนเงินแล้วแต่สถานะยังไม่อัปเดต',
  'ขอเลื่อนวันเดินทาง',
  'สอบถามจุดขึ้นรถ',
];

// รูปคนตอบแสดงเฉพาะข้อความแรกของชุดที่ทีมงานส่งติดกัน
function showAvatar(i) {
  if (i === 0) return true;
  const prev = messages.value[i - 1];
  return prev.is_mine || prev.sender_role === 'system';
}

function formatTime(d) {
  if (!d) return '';
  const date = new Date(d);
  const sameDay = date.toDateString() === new Date().toDateString();
  return sameDay
    ? date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
    : `${date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' })} ${date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })}`;
}

function scrollToBottom() {
  nextTick(() => {
    if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight;
  });
}

async function load() {
  try {
    const [conv, msgs] = await Promise.all([
      api.get('/support/conversation'),
      api.get('/support/messages', { params: { per_page: 30 } }),
    ]);
    conversationId.value = conv.data.data?.id ?? null;
    messages.value = msgs.data.data?.messages || [];
    hasMore.value = Boolean(msgs.data.data?.has_more);
    scrollToBottom();
    markRead();
  } catch {
    toast.error('โหลดข้อความไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
  } finally {
    loading.value = false;
  }
}

async function loadOlder() {
  const oldest = messages.value[0];
  if (!oldest || loadingOlder.value) return;
  loadingOlder.value = true;
  const keepHeight = threadEl.value?.scrollHeight ?? 0;
  try {
    const res = await api.get('/support/messages', { params: { per_page: 30, before_id: oldest.id } });
    messages.value = [...(res.data.data?.messages || []), ...messages.value];
    hasMore.value = Boolean(res.data.data?.has_more);
    // คงตำแหน่งที่อ่านอยู่ไว้ ไม่ให้เนื้อหาที่เพิ่งเติมดันจอ
    nextTick(() => {
      if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight - keepHeight;
    });
  } catch {
    toast.error('โหลดข้อความก่อนหน้าไม่สำเร็จ');
  } finally {
    loadingOlder.value = false;
  }
}

function markRead() {
  const last = messages.value[messages.value.length - 1];
  if (!last) return;
  api.post('/support/read', { message_id: last.id }).catch(() => {});
}

function onPickImage(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    toast.error('ไฟล์รูปต้องไม่เกิน 5MB');
    return;
  }
  clearPendingImage();
  pendingImage.value = file;
  imagePreview.value = URL.createObjectURL(file);
}

function clearPendingImage() {
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value);
  pendingImage.value = null;
  imagePreview.value = null;
}

async function send() {
  const body = draft.value.trim();
  if ((!body && !pendingImage.value) || sending.value) return;
  sending.value = true;
  try {
    let res;
    if (pendingImage.value) {
      const fd = new FormData();
      if (body) fd.append('body', body);
      fd.append('image', pendingImage.value);
      res = await api.post('/support/messages', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    } else {
      res = await api.post('/support/messages', { body });
    }
    messages.value.push(res.data.data);
    if (!conversationId.value) {
      // ห้องเพิ่งถูกสร้างตอนส่งข้อความแรก — ต้องรู้ id ถึงจะฟังเรียลไทม์ได้
      conversationId.value = res.data.data?.conversation_id ?? null;
      subscribe();
    }
    draft.value = '';
    clearPendingImage();
    scrollToBottom();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ส่งข้อความไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
  } finally {
    sending.value = false;
  }
}

function subscribe() {
  if (!window.Echo || !conversationId.value || channel) return;
  channel = window.Echo.private(`support.conversation.${conversationId.value}`)
    .listen('.support.message', (data) => {
      if (data.sender_role === 'customer') return; // ข้อความของเราเอง ต่อท้ายไปแล้ว
      messages.value.push({ ...data, is_mine: false });
      scrollToBottom();
      markRead();
    });
}

// สำรองไว้เมื่อ WebSocket ต่อไม่ติด — ดึงเฉพาะข้อความที่ใหม่กว่าอันล่าสุด
function startPolling() {
  pollTimer = setInterval(async () => {
    if (document.hidden || !messages.value.length) return;
    const last = messages.value[messages.value.length - 1];
    try {
      const res = await api.get('/support/messages', { params: { after_id: last.id } });
      const fresh = (res.data.data?.messages || []).filter(
        (m) => !messages.value.some((existing) => existing.id === m.id)
      );
      if (!fresh.length) return;
      messages.value.push(...fresh);
      scrollToBottom();
      markRead();
    } catch {}
  }, 20000);
}

onMounted(async () => {
  await load();
  subscribe();
  startPolling();
});

onBeforeUnmount(() => {
  clearInterval(pollTimer);
  clearPendingImage();
  if (channel && window.Echo && conversationId.value) {
    window.Echo.leave(`support.conversation.${conversationId.value}`);
  }
});
</script>
