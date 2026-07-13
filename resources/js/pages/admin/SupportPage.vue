<template>
  <div class="chat-page">
    <!-- ─── Sidebar : conversation list ─────────────── -->
    <div class="chat-sidebar">
      <div class="chat-sidebar-header">
        <h2><i class="fas fa-headset"></i> ศูนย์ช่วยเหลือ</h2>
        <button class="refresh-btn" :disabled="loadingList" @click="loadConversations()" title="รีเฟรช">
          <i class="fas fa-sync" :class="{ spin: loadingList }"></i>
        </button>
      </div>

      <div class="sidebar-search">
        <i class="fas fa-search"></i>
        <input v-model="search" type="text" placeholder="ค้นหาชื่อลูกค้า..." />
        <button v-if="search" class="clear-search" @click="search = ''"><i class="fas fa-times"></i></button>
      </div>

      <div class="sidebar-tabs">
        <button :class="{ active: tab === 'open' }" @click="switchTab('open')">
          รอตอบ
          <span class="tab-badge" v-if="openCount">{{ openCount }}</span>
        </button>
        <button :class="{ active: tab === 'all' }" @click="switchTab('all')">
          ทั้งหมด
          <span class="tab-badge" v-if="conversations.length">{{ conversations.length }}</span>
        </button>
      </div>

      <div v-if="loadingList" class="empty-hint">กำลังโหลด...</div>
      <div v-else-if="!filteredConversations.length" class="empty-hint">
        {{ search ? 'ไม่พบลูกค้าที่ค้นหา' : (tab === 'open' ? 'ไม่มีเคสรอตอบ' : 'ยังไม่มีการสนทนา') }}
      </div>

      <ul v-else class="conv-list">
        <li
          v-for="conv in filteredConversations"
          :key="conv.id"
          class="conv-item"
          :class="{ active: conv.id === activeId }"
          @click="openConversation(conv)"
        >
          <div class="conv-thumb">
            <img v-if="conv.user?.avatar_url" :src="conv.user.avatar_url" alt="" />
            <i v-else class="fas fa-user"></i>
          </div>
          <div class="conv-content">
            <div class="conv-top">
              <span class="conv-title">{{ customerName(conv.user) }}</span>
              <span class="conv-time" v-if="conv.last_message_at">{{ shortTime(conv.last_message_at) }}</span>
            </div>
            <div class="conv-sub">
              <span class="status-dot" :class="conv.status"></span>
              {{ conv.status === 'closed' ? 'ปิดเคสแล้ว' : 'เปิดอยู่' }}
              <span v-if="conv.user?.phone" class="conv-phone"><i class="fas fa-phone"></i> {{ conv.user.phone }}</span>
            </div>
            <div class="conv-bottom">
              <span class="conv-preview" v-if="conv.last_message_preview">{{ conv.last_message_preview }}</span>
              <span class="conv-preview muted" v-else>ยังไม่มีข้อความ</span>
              <span class="conv-count" v-if="conv.unread_count">{{ conv.unread_count }}</span>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- ─── Main : thread ───────────────────────────── -->
    <div class="chat-main">
      <div v-if="!activeId" class="chat-empty">
        <i class="fas fa-comments"></i>
        <p>เลือกการสนทนาเพื่อเริ่มตอบ</p>
        <span>ลูกค้าที่ทักเข้ามาจะแสดงอยู่ด้านซ้าย</span>
      </div>

      <template v-else>
        <div class="chat-header">
          <div class="chat-header-info">
            <div class="header-thumb">
              <img v-if="activeConv?.user?.avatar_url" :src="activeConv.user.avatar_url" alt="" />
              <i v-else class="fas fa-user"></i>
            </div>
            <div>
              <h3>{{ customerName(activeConv?.user) }}</h3>
              <span class="chat-sub">
                <span v-if="activeConv?.user?.phone"><i class="fas fa-phone"></i> {{ activeConv.user.phone }}</span>
                <span v-if="activeConv?.user?.email"> · <i class="fas fa-envelope"></i> {{ activeConv.user.email }}</span>
              </span>
            </div>
          </div>
          <div class="header-actions">
            <span class="ws-pill" :class="{ on: wsConnected }">
              <span class="ws-dot"></span>
              {{ wsConnected ? 'เรียลไทม์' : 'ออฟไลน์' }}
            </span>
            <button
              class="status-btn"
              :class="activeConv?.status === 'closed' ? 'reopen' : 'close'"
              :disabled="updatingStatus"
              @click="toggleStatus"
            >
              <i class="fas" :class="activeConv?.status === 'closed' ? 'fa-rotate-left' : 'fa-check'"></i>
              {{ activeConv?.status === 'closed' ? 'เปิดเคสอีกครั้ง' : 'ปิดเคส' }}
            </button>
          </div>
        </div>

        <div ref="threadEl" class="chat-thread">
          <div v-if="loadingMessages" class="empty-hint">กำลังโหลดข้อความ...</div>

          <template v-for="(m, i) in messages" :key="m.id">
            <div v-if="m.sender_role === 'system'" class="system-notice">{{ m.body }}</div>
            <div v-else class="msg-row" :class="{ mine: m.is_mine }">
              <div class="msg-avatar" v-if="!m.is_mine && showAvatar(i)">
                <img :src="m.sender?.avatar_url || fallbackAvatar(m)" :alt="senderName(m)" />
              </div>
              <div class="msg-avatar spacer" v-else-if="!m.is_mine"></div>

              <div class="msg-col">
                <div class="msg-bubble" :class="{ customer: !m.is_mine }">
                  <div v-if="m.image_url" class="msg-image" @click="lightbox = m.image_url">
                    <img :src="m.image_url" alt="รูปภาพ" />
                  </div>
                  <div v-if="m.body" class="msg-body">{{ m.body }}</div>
                  <div class="msg-time">{{ formatTime(m.created_at) }}</div>
                </div>
              </div>
            </div>
          </template>
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
              placeholder="พิมพ์คำตอบถึงลูกค้า..."
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
const tab = ref('open');
const activeId = ref(null);
const activeConv = ref(null);
const messages = ref([]);
const loadingMessages = ref(false);
const draft = ref('');
const sending = ref(false);
const updatingStatus = ref(false);
const wsConnected = ref(false);
const threadEl = ref(null);
const pendingImage = ref(null);
const imagePreview = ref(null);
const lightbox = ref(null);
let currentChannel = null;
let listRefreshTimer = null;

const openCount = computed(() => conversations.value.filter((c) => c.status === 'open' && c.unread_count > 0).length);

const filteredConversations = computed(() => {
  let list = conversations.value;
  if (tab.value === 'open') list = list.filter((c) => c.status === 'open');
  const q = search.value.trim().toLowerCase();
  if (q) list = list.filter((c) => customerName(c.user).toLowerCase().includes(q));
  return list;
});

function customerName(user) {
  return (user && (user.nickname || user.name)) || 'ลูกค้า';
}

function senderName(m) {
  return (m.sender && (m.sender.nickname || m.sender.name)) || 'ลูกค้า';
}

function fallbackAvatar(m) {
  const name = encodeURIComponent(senderName(m));
  return `https://ui-avatars.com/api/?name=${name}&background=2D7A4F&color=fff`;
}

// แสดงรูปเฉพาะข้อความแรกของลูกค้าที่ส่งติดต่อกัน
function showAvatar(i) {
  if (i === 0) return true;
  const prev = messages.value[i - 1];
  return prev.is_mine || prev.sender_role === 'system';
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

function switchTab(t) {
  tab.value = t;
}

async function loadConversations(silent = false) {
  if (!silent) loadingList.value = true;
  try {
    const res = await api.get('/admin/support/conversations');
    conversations.value = res.data.data || [];
    // keep the open thread's meta in sync with the freshly loaded list
    if (activeId.value) {
      const match = conversations.value.find((c) => c.id === activeId.value);
      if (match) match.unread_count = 0;
    }
  } finally {
    if (!silent) loadingList.value = false;
  }
}

function scheduleListRefresh() {
  clearTimeout(listRefreshTimer);
  listRefreshTimer = setTimeout(() => loadConversations(true), 700);
}

async function openConversation(conv) {
  if (activeId.value === conv.id) return;
  leaveChannel();
  activeId.value = conv.id;
  activeConv.value = conv;
  clearPendingImage();
  conv.unread_count = 0;
  await loadMessages();
  subscribe(conv.id);
}

async function loadMessages() {
  loadingMessages.value = true;
  try {
    const res = await api.get(`/admin/support/conversations/${activeId.value}`, {
      params: { per_page: 50 },
    });
    messages.value = res.data.data?.messages || [];
    if (res.data.data?.conversation) activeConv.value = res.data.data.conversation;
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
      res = await api.post(`/admin/support/conversations/${activeId.value}/messages`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
    } else {
      res = await api.post(`/admin/support/conversations/${activeId.value}/messages`, { body });
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

async function toggleStatus() {
  if (!activeConv.value || updatingStatus.value) return;
  const closing = activeConv.value.status !== 'closed';
  updatingStatus.value = true;
  try {
    const res = await api.post(
      `/admin/support/conversations/${activeId.value}/${closing ? 'close' : 'reopen'}`
    );
    activeConv.value = res.data.data;
    const conv = conversations.value.find((c) => c.id === activeId.value);
    if (conv) conv.status = res.data.data.status;
  } catch (e) {
    alert(e.response?.data?.message || 'อัปเดตสถานะไม่สำเร็จ');
  } finally {
    updatingStatus.value = false;
  }
}

// อัปเดต preview ในรายการด้านซ้ายเมื่อทีมงานตอบ
function bumpConversation(msg) {
  const conv = conversations.value.find((c) => c.id === activeId.value);
  if (!conv) return;
  conv.last_message_preview = msg.body || (msg.image_url ? '📷 รูปภาพ' : '');
  conv.last_message_at = msg.created_at;
  conv.status = 'open';
}

function subscribe(conversationId) {
  if (!window.Echo) return;
  currentChannel = window.Echo.private(`support.conversation.${conversationId}`)
    .listen('.support.message', (data) => {
      if (data.sender_role === 'admin') return; // our own reply, already appended
      messages.value.push({ ...data, is_mine: false });
      scrollToBottom();
      api.post(`/admin/support/conversations/${conversationId}/read`).catch(() => {});
    })
    .subscribed(() => { wsConnected.value = true; })
    .error(() => { wsConnected.value = false; });
}

function leaveChannel() {
  if (currentChannel && activeId.value && window.Echo) {
    window.Echo.leave(`support.conversation.${activeId.value}`);
  }
  currentChannel = null;
  wsConnected.value = false;
}

// รับสัญญาณห้องใหม่/ข้อความใหม่จากทุกห้อง เพื่ออัปเดตลิสต์ด้านซ้ายแบบเรียลไทม์
function subscribeInbox() {
  if (!window.Echo) return;
  window.Echo.private('support.admins').listen('.support.inbox', () => {
    scheduleListRefresh();
  });
}

function scrollToBottom() {
  nextTick(() => {
    if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight;
  });
}

onMounted(() => {
  loadConversations();
  subscribeInbox();
});
onBeforeUnmount(() => {
  leaveChannel();
  clearPendingImage();
  clearTimeout(listRefreshTimer);
  if (window.Echo) window.Echo.leave('support.admins');
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
  border-radius: 50%;
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
.conv-sub { font-size: 11.5px; color: #6b7280; margin-top: 2px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: #22c55e; }
.status-dot.closed { background: #cbd5e1; }
.conv-phone { display: inline-flex; align-items: center; gap: 3px; }
.conv-phone i { font-size: 10px; }
.conv-bottom { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 3px; }
.conv-preview { font-size: 12px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.conv-preview.muted { color: #b0b6c0; font-style: italic; }
.conv-count {
  background: #ef4444;
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

.chat-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; gap: 12px; }
.chat-header-info { display: flex; align-items: center; gap: 12px; min-width: 0; }
.header-thumb {
  width: 42px; height: 42px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.header-thumb img { width: 100%; height: 100%; object-fit: cover; }
.chat-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.chat-sub { font-size: 12px; color: #6b7280; }
.chat-sub i { font-size: 11px; }
.header-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.ws-pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 11.5px; font-weight: 700; color: #9ca3af;
  background: #f3f4f6; border-radius: 999px; padding: 4px 10px;
}
.ws-pill.on { color: #15803d; background: #ecfdf5; }
.ws-dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; }
.ws-pill.on .ws-dot { background: #22c55e; }
.status-btn {
  display: inline-flex; align-items: center; gap: 6px;
  border: 1px solid #e5e7eb; background: #fff; border-radius: 8px;
  padding: 7px 12px; font-size: 12.5px; font-weight: 700; cursor: pointer;
}
.status-btn.close { color: #b45309; border-color: #fcd34d; background: #fffbeb; }
.status-btn.reopen { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }
.status-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.chat-thread { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 4px; background: #fafafa; }
.system-notice {
  align-self: center; max-width: 80%; text-align: center;
  font-size: 12px; color: #6b7280; background: #fff;
  border: 1px solid #eceff1; border-radius: 12px; padding: 8px 14px; margin: 8px 0;
}
.msg-row { display: flex; align-items: flex-end; gap: 8px; margin-top: 6px; }
.msg-row.mine { justify-content: flex-end; }
.msg-avatar { width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex-shrink: 0; }
.msg-avatar img { width: 100%; height: 100%; object-fit: cover; }
.msg-avatar.spacer { background: transparent; }
.msg-col { display: flex; flex-direction: column; max-width: 70%; }
.msg-bubble { padding: 9px 13px; border-radius: 14px; background: #2D7A4F; color: #fff; border: 1px solid #2D7A4F; }
.msg-bubble.customer { background: #fff; color: #1f2937; border-color: #eceff1; }
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
