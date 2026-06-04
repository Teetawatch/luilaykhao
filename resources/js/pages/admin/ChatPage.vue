<template>
  <div class="chat-page">
    <!-- ─── Sidebar : room list ─────────────────────── -->
    <div class="chat-sidebar">
      <div class="chat-sidebar-header">
        <h2><i class="fas fa-comments"></i> แชทกลุ่มทริป</h2>
        <button class="refresh-btn" :disabled="loadingList" @click="loadConversations" title="รีเฟรช">
          <i class="fas fa-sync" :class="{ spin: loadingList }"></i>
        </button>
      </div>

      <div class="sidebar-search">
        <i class="fas fa-search"></i>
        <input v-model="search" type="text" placeholder="ค้นหารอบเดินทาง..." />
        <button v-if="search" class="clear-search" @click="search = ''"><i class="fas fa-times"></i></button>
      </div>

      <div class="sidebar-tabs">
        <button :class="{ active: tab === 'active' }" @click="tab = 'active'">
          มีข้อความ
          <span class="tab-badge" v-if="activeCount">{{ activeCount }}</span>
        </button>
        <button :class="{ active: tab === 'all' }" @click="tab = 'all'">
          ทุกรอบเดินทาง
          <span class="tab-badge" v-if="conversations.length">{{ conversations.length }}</span>
        </button>
      </div>

      <div v-if="loadingList" class="empty-hint">กำลังโหลด...</div>
      <div v-else-if="!filteredConversations.length" class="empty-hint">
        {{ search ? 'ไม่พบรอบเดินทางที่ค้นหา' : (tab === 'active' ? 'ยังไม่มีห้องที่มีข้อความ' : 'ไม่มีรอบเดินทาง') }}
      </div>

      <ul v-else class="conv-list">
        <li
          v-for="conv in filteredConversations"
          :key="conv.schedule_id"
          class="conv-item"
          :class="{ active: conv.schedule_id === activeId }"
          @click="openConversation(conv)"
        >
          <div class="conv-thumb">
            <img v-if="conv.trip_image" :src="conv.trip_image" alt="" />
            <i v-else class="fas fa-mountain-sun"></i>
          </div>
          <div class="conv-content">
            <div class="conv-top">
              <span class="conv-title">{{ conv.trip_title || 'ทริป' }}</span>
              <span class="conv-time" v-if="conv.last_message">{{ shortTime(conv.last_message.created_at) }}</span>
            </div>
            <div class="conv-sub">
              <i class="fas fa-calendar-day"></i> {{ formatDate(conv.departure_date) }}
            </div>
            <div class="conv-bottom">
              <span class="conv-preview" v-if="conv.last_message">
                <template v-if="conv.last_message.sender_name">{{ conv.last_message.sender_name }}: </template>
                <i v-if="conv.last_message.image_url" class="fas fa-image preview-img-icon"></i>
                {{ conv.last_message.body || (conv.last_message.image_url ? 'รูปภาพ' : '') }}
              </span>
              <span class="conv-preview muted" v-else>ยังไม่มีข้อความ — เริ่มแชทได้เลย</span>
              <span class="conv-count" v-if="conv.message_count">{{ conv.message_count }}</span>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- ─── Main : thread ───────────────────────────── -->
    <div class="chat-main">
      <div v-if="!activeId" class="chat-empty">
        <i class="fas fa-comment-dots"></i>
        <p>เลือกห้องแชทเพื่อเริ่มสนทนา</p>
        <span>เลือกรอบเดินทางจากด้านซ้ายได้ทุกรอบ</span>
      </div>

      <template v-else>
        <div class="chat-header">
          <div class="chat-header-info">
            <div class="header-thumb">
              <img v-if="activeConv?.trip_image" :src="activeConv.trip_image" alt="" />
              <i v-else class="fas fa-mountain-sun"></i>
            </div>
            <div>
              <h3>{{ activeConv?.trip_title || 'ทริป' }}</h3>
              <span class="chat-sub">เดินทาง {{ formatDate(activeConv?.departure_date) }}</span>
            </div>
          </div>
          <span class="ws-pill" :class="{ on: wsConnected }">
            <span class="ws-dot"></span>
            {{ wsConnected ? 'เรียลไทม์' : 'ออฟไลน์' }}
          </span>
        </div>

        <div ref="threadEl" class="chat-thread">
          <div v-if="loadingMessages" class="empty-hint">กำลังโหลดข้อความ...</div>
          <div v-else-if="!messages.length" class="empty-hint">ยังไม่มีข้อความในห้องนี้</div>

          <div
            v-for="(m, i) in messages"
            :key="m.id"
            class="msg-row"
            :class="{ mine: m.is_mine }"
          >
            <div class="msg-avatar" v-if="!m.is_mine && showAvatar(i)">
              <img :src="m.user?.avatar_url || fallbackAvatar(m)" :alt="senderName(m)" />
            </div>
            <div class="msg-avatar spacer" v-else-if="!m.is_mine"></div>

            <div class="msg-col">
              <div class="msg-author" v-if="!m.is_mine && showAvatar(i)">
                {{ senderName(m) }}
                <span class="role-tag" :class="'role-' + m.sender_role">{{ roleLabel(m.sender_role) }}</span>
              </div>
              <div class="msg-bubble" :class="'role-' + m.sender_role">
                <div v-if="m.image_url" class="msg-image" @click="lightbox = m.image_url">
                  <img :src="m.image_url" alt="รูปภาพ" />
                </div>
                <div v-if="m.body" class="msg-body">{{ m.body }}</div>
                <div class="msg-time">{{ formatTime(m.created_at) }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- composer -->
        <div class="chat-composer">
          <div v-if="imagePreview" class="image-preview-bar">
            <div class="image-preview">
              <img :src="imagePreview" alt="" />
              <button class="remove-preview" @click="clearPendingImage"><i class="fas fa-times"></i></button>
            </div>
          </div>
          <form class="chat-input" @submit.prevent="send">
            <label class="attach-btn" :class="{ disabled: sending }" title="แนบรูปภาพ">
              <i class="fas fa-image"></i>
              <input type="file" accept="image/*" hidden :disabled="sending" @change="onPickImage" />
            </label>
            <input
              v-model="draft"
              type="text"
              placeholder="พิมพ์ข้อความ..."
              maxlength="2000"
              :disabled="sending"
            />
            <button type="submit" class="send-btn" :disabled="sending || (!draft.trim() && !pendingImage)">
              <i class="fas" :class="sending ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i>
            </button>
          </form>
        </div>
      </template>
    </div>

    <!-- ─── Image lightbox ──────────────────────────── -->
    <div v-if="lightbox" class="lightbox" @click="lightbox = null">
      <button class="lightbox-close"><i class="fas fa-times"></i></button>
      <img :src="lightbox" alt="รูปภาพ" @click.stop />
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../../lib/axios';

const conversations = ref([]);
const loadingList = ref(false);
const search = ref('');
const tab = ref('active');
const activeId = ref(null);
const activeConv = ref(null);
const messages = ref([]);
const loadingMessages = ref(false);
const draft = ref('');
const sending = ref(false);
const wsConnected = ref(false);
const threadEl = ref(null);
const pendingImage = ref(null);
const imagePreview = ref(null);
const lightbox = ref(null);
let currentChannel = null;

const activeCount = computed(() => conversations.value.filter((c) => c.message_count > 0).length);

const filteredConversations = computed(() => {
  let list = conversations.value;
  if (tab.value === 'active') list = list.filter((c) => c.message_count > 0);
  const q = search.value.trim().toLowerCase();
  if (q) list = list.filter((c) => (c.trip_title || '').toLowerCase().includes(q));
  return list;
});

function roleLabel(role) {
  return { customer: 'ลูกค้า', staff: 'สตาฟ', admin: 'แอดมิน', system: 'ระบบ' }[role] || role;
}

function senderName(m) {
  return (m.user && (m.user.nickname || m.user.name)) || 'ผู้ใช้';
}

function fallbackAvatar(m) {
  const name = encodeURIComponent(senderName(m));
  return `https://ui-avatars.com/api/?name=${name}&background=2D7A4F&color=fff`;
}

// แสดงรูป + ชื่อ เฉพาะข้อความแรกของผู้ส่งติดต่อกัน เพื่อความสะอาดตา
function showAvatar(i) {
  if (i === 0) return true;
  const prev = messages.value[i - 1];
  const cur = messages.value[i];
  return prev.is_mine || prev.user?.id !== cur.user?.id;
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

function shortTime(d) {
  if (!d) return '';
  const date = new Date(d);
  const today = new Date();
  const sameDay = date.toDateString() === today.toDateString();
  return sameDay
    ? date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

async function loadConversations() {
  loadingList.value = true;
  try {
    const res = await api.get('/admin/chat/conversations');
    conversations.value = res.data.data || [];
  } finally {
    loadingList.value = false;
  }
}

async function openConversation(conv) {
  if (activeId.value === conv.schedule_id) return;
  leaveChannel();
  activeId.value = conv.schedule_id;
  activeConv.value = conv;
  clearPendingImage();
  await loadMessages();
  subscribe(conv.schedule_id);
  markRead();
}

async function loadMessages() {
  loadingMessages.value = true;
  try {
    const res = await api.get(`/schedules/${activeId.value}/chat/messages`, {
      params: { per_page: 50 },
    });
    messages.value = res.data.data?.messages || [];
    scrollToBottom();
  } finally {
    loadingMessages.value = false;
  }
}

function onPickImage(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    alert('ไฟล์รูปต้องไม่เกิน 5MB');
    return;
  }
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
      res = await api.post(`/schedules/${activeId.value}/chat/messages`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
    } else {
      res = await api.post(`/schedules/${activeId.value}/chat/messages`, { body });
    }
    messages.value.push(res.data.data);
    draft.value = '';
    clearPendingImage();
    scrollToBottom();
    bumpConversation(res.data.data);
  } catch (e) {
    alert(e.response?.data?.message || 'ส่งข้อความไม่สำเร็จ');
  } finally {
    sending.value = false;
  }
}

// อัปเดต preview/ตัวนับในรายการห้องด้านซ้ายเมื่อมีข้อความใหม่
function bumpConversation(msg) {
  const conv = conversations.value.find((c) => c.schedule_id === activeId.value);
  if (!conv) return;
  conv.message_count = (conv.message_count || 0) + 1;
  conv.last_message = {
    body: msg.body,
    image_url: msg.image_url,
    sender_role: msg.sender_role,
    sender_name: msg.user?.nickname || msg.user?.name,
    created_at: msg.created_at,
  };
}

function markRead() {
  api.post(`/schedules/${activeId.value}/chat/read`).catch(() => {});
}

function subscribe(scheduleId) {
  if (!window.Echo) return;
  currentChannel = window.Echo.private(`chat.schedule.${scheduleId}`)
    .listen('.chat.message', (data) => {
      messages.value.push({ ...data, is_mine: false });
      scrollToBottom();
      markRead();
      bumpConversation(data);
    })
    .subscribed(() => { wsConnected.value = true; })
    .error(() => { wsConnected.value = false; });
}

function leaveChannel() {
  if (currentChannel && activeId.value && window.Echo) {
    window.Echo.leave(`chat.schedule.${activeId.value}`);
  }
  currentChannel = null;
  wsConnected.value = false;
}

function scrollToBottom() {
  nextTick(() => {
    if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight;
  });
}

onMounted(loadConversations);
onBeforeUnmount(() => {
  leaveChannel();
  clearPendingImage();
});
</script>

<style scoped>
.chat-page {
  display: flex;
  height: calc(100vh - 120px);
  gap: 16px;
}

/* ─── Sidebar ──────────────────────────────────────── */
.chat-sidebar {
  width: 340px;
  background: #fff;
  border-radius: 16px;
  border: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.chat-sidebar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px;
  border-bottom: 1px solid #f0f0f0;
}
.chat-sidebar-header h2 { font-size: 16px; font-weight: 800; margin: 0; color: #1f2937; }
.refresh-btn { border: none; background: #f3f4f6; border-radius: 8px; padding: 8px 10px; cursor: pointer; color: #4b5563; }
.refresh-btn:hover { background: #e5e7eb; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.sidebar-search {
  position: relative;
  display: flex;
  align-items: center;
  padding: 10px 12px;
  border-bottom: 1px solid #f5f5f5;
}
.sidebar-search > i.fa-search { position: absolute; left: 22px; color: #9ca3af; font-size: 12px; }
.sidebar-search input {
  width: 100%;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 8px 32px;
  font-size: 13px;
  outline: none;
}
.sidebar-search input:focus { border-color: #2D7A4F; }
.clear-search { position: absolute; right: 20px; border: none; background: none; color: #9ca3af; cursor: pointer; }

.sidebar-tabs { display: flex; padding: 8px 12px; gap: 8px; border-bottom: 1px solid #f5f5f5; }
.sidebar-tabs button {
  flex: 1;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 8px;
  padding: 7px 8px;
  font-size: 12.5px;
  font-weight: 700;
  color: #6b7280;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all 0.15s;
}
.sidebar-tabs button.active { background: #2D7A4F; border-color: #2D7A4F; color: #fff; }
.tab-badge {
  background: rgba(0,0,0,0.08);
  border-radius: 999px;
  padding: 0 7px;
  font-size: 11px;
  min-width: 18px;
}
.sidebar-tabs button.active .tab-badge { background: rgba(255,255,255,0.25); }

.conv-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; flex: 1; }
.conv-item {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  border-bottom: 1px solid #f5f5f5;
  cursor: pointer;
  transition: background 0.15s;
}
.conv-item:hover { background: #f9fafb; }
.conv-item.active { background: #ecfdf5; box-shadow: inset 3px 0 0 #2D7A4F; }
.conv-thumb {
  width: 48px; height: 48px;
  border-radius: 12px;
  overflow: hidden;
  flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center;
  color: #2D7A4F;
}
.conv-thumb img { width: 100%; height: 100%; object-fit: cover; }
.conv-content { flex: 1; min-width: 0; }
.conv-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.conv-title { font-weight: 800; font-size: 13.5px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-time { font-size: 11px; color: #9ca3af; flex-shrink: 0; }
.conv-sub { font-size: 11.5px; color: #6b7280; margin-top: 2px; }
.conv-sub i { font-size: 10px; }
.conv-bottom { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 3px; }
.conv-preview { font-size: 12px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.conv-preview.muted { color: #b0b6c0; font-style: italic; }
.preview-img-icon { font-size: 10px; margin-right: 2px; }
.conv-count {
  background: #2D7A4F;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  border-radius: 999px;
  padding: 1px 8px;
  flex-shrink: 0;
}
.empty-hint { padding: 24px 16px; color: #9ca3af; font-size: 13px; text-align: center; }

/* ─── Main ─────────────────────────────────────────── */
.chat-main { flex: 1; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; display: flex; flex-direction: column; overflow: hidden; }
.chat-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #c0c4cc; gap: 10px; }
.chat-empty i { font-size: 48px; }
.chat-empty p { margin: 0; font-size: 15px; font-weight: 700; color: #9ca3af; }
.chat-empty span { font-size: 12.5px; }

.chat-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
.chat-header-info { display: flex; align-items: center; gap: 12px; }
.header-thumb {
  width: 42px; height: 42px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.header-thumb img { width: 100%; height: 100%; object-fit: cover; }
.chat-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.chat-sub { font-size: 12px; color: #6b7280; }
.ws-pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 11.5px; font-weight: 700; color: #9ca3af;
  background: #f3f4f6; border-radius: 999px; padding: 4px 10px;
}
.ws-pill.on { color: #15803d; background: #ecfdf5; }
.ws-dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; }
.ws-pill.on .ws-dot { background: #22c55e; }

.chat-thread { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 4px; background: #fafafa; }
.msg-row { display: flex; align-items: flex-end; gap: 8px; margin-top: 6px; }
.msg-row.mine { justify-content: flex-end; }
.msg-avatar { width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex-shrink: 0; }
.msg-avatar img { width: 100%; height: 100%; object-fit: cover; }
.msg-avatar.spacer { background: transparent; }
.msg-col { display: flex; flex-direction: column; max-width: 70%; }
.msg-author { font-size: 11.5px; font-weight: 800; color: #374151; margin-bottom: 3px; margin-left: 2px; display: flex; gap: 6px; align-items: center; }
.role-tag { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; background: #eef2ff; color: #4338ca; }
.role-tag.role-staff { background: #ecfeff; color: #0e7490; }
.role-tag.role-admin { background: #fef3c7; color: #b45309; }
.role-tag.role-customer { background: #eef2ff; color: #4338ca; }
.msg-bubble { padding: 9px 13px; border-radius: 14px; background: #fff; border: 1px solid #eceff1; }
.msg-row.mine .msg-bubble { background: #2D7A4F; color: #fff; border-color: #2D7A4F; }
.msg-image { margin: -2px 0 4px; cursor: pointer; border-radius: 10px; overflow: hidden; }
.msg-image img { display: block; max-width: 240px; max-height: 240px; width: 100%; object-fit: cover; transition: transform 0.2s; }
.msg-image:hover img { transform: scale(1.02); }
.msg-body { font-size: 13.5px; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
.msg-time { font-size: 10px; opacity: 0.65; margin-top: 4px; text-align: right; }

/* ─── Composer ─────────────────────────────────────── */
.chat-composer { border-top: 1px solid #f0f0f0; }
.image-preview-bar { padding: 10px 16px 0; }
.image-preview { position: relative; display: inline-block; }
.image-preview img { max-height: 80px; border-radius: 10px; border: 1px solid #e5e7eb; }
.remove-preview {
  position: absolute; top: -8px; right: -8px;
  width: 22px; height: 22px; border-radius: 50%;
  border: none; background: #ef4444; color: #fff; cursor: pointer;
  font-size: 11px; display: flex; align-items: center; justify-content: center;
}
.chat-input { display: flex; align-items: center; gap: 10px; padding: 14px 16px; }
.attach-btn {
  width: 40px; height: 40px; border-radius: 50%;
  background: #f3f4f6; color: #4b5563;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; flex-shrink: 0; transition: background 0.15s;
}
.attach-btn:hover { background: #e5e7eb; }
.attach-btn.disabled { opacity: 0.5; cursor: not-allowed; }
.chat-input input[type="text"] { flex: 1; border: 1px solid #d1d5db; border-radius: 999px; padding: 10px 16px; font-size: 14px; outline: none; }
.chat-input input[type="text"]:focus { border-color: #2D7A4F; }
.send-btn { border: none; background: #2D7A4F; color: #fff; width: 44px; height: 44px; border-radius: 50%; cursor: pointer; flex-shrink: 0; }
.send-btn:disabled { background: #cbd5e1; cursor: not-allowed; }

/* ─── Lightbox ─────────────────────────────────────── */
.lightbox {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,0.85);
  display: flex; align-items: center; justify-content: center;
  padding: 40px;
}
.lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 10px; }
.lightbox-close {
  position: absolute; top: 24px; right: 28px;
  background: rgba(255,255,255,0.15); color: #fff; border: none;
  width: 44px; height: 44px; border-radius: 50%; cursor: pointer; font-size: 18px;
}
.lightbox-close:hover { background: rgba(255,255,255,0.28); }
</style>
