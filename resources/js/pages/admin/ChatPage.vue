<template>
  <div class="chat-page">
    <div class="chat-sidebar">
      <div class="chat-sidebar-header">
        <h2><i class="fas fa-comments"></i> แชทกลุ่มทริป</h2>
        <button class="refresh-btn" :disabled="loadingList" @click="loadConversations">
          <i class="fas fa-sync" :class="{ spin: loadingList }"></i>
        </button>
      </div>

      <div v-if="loadingList" class="empty-hint">กำลังโหลด...</div>
      <div v-else-if="!conversations.length" class="empty-hint">ยังไม่มีห้องแชทที่มีข้อความ</div>

      <ul v-else class="conv-list">
        <li
          v-for="conv in conversations"
          :key="conv.schedule_id"
          class="conv-item"
          :class="{ active: conv.schedule_id === activeId }"
          @click="openConversation(conv)"
        >
          <div class="conv-title">{{ conv.trip_title || 'ทริป' }}</div>
          <div class="conv-meta">
            <span><i class="fas fa-calendar-day"></i> {{ formatDate(conv.departure_date) }}</span>
            <span class="conv-count">{{ conv.message_count }} ข้อความ</span>
          </div>
          <div v-if="conv.last_message" class="conv-preview">
            {{ roleLabel(conv.last_message.sender_role) }}: {{ conv.last_message.body }}
          </div>
        </li>
      </ul>
    </div>

    <div class="chat-main">
      <div v-if="!activeId" class="chat-empty">
        <i class="fas fa-comment-dots"></i>
        <p>เลือกห้องแชทเพื่อเริ่มสนทนา</p>
      </div>

      <template v-else>
        <div class="chat-header">
          <div>
            <h3>{{ activeConv?.trip_title || 'ทริป' }}</h3>
            <span class="chat-sub">เดินทาง {{ formatDate(activeConv?.departure_date) }}</span>
          </div>
          <span class="ws-dot" :class="{ on: wsConnected }" :title="wsConnected ? 'เชื่อมต่อแบบเรียลไทม์' : 'ออฟไลน์'"></span>
        </div>

        <div ref="threadEl" class="chat-thread">
          <div v-if="loadingMessages" class="empty-hint">กำลังโหลดข้อความ...</div>
          <div
            v-for="m in messages"
            :key="m.id"
            class="msg-row"
            :class="{ mine: m.is_mine }"
          >
            <div class="msg-bubble" :class="'role-' + m.sender_role">
              <div v-if="!m.is_mine" class="msg-author">
                {{ (m.user && (m.user.nickname || m.user.name)) || 'ผู้ใช้' }}
                <span class="role-tag">{{ roleLabel(m.sender_role) }}</span>
              </div>
              <div class="msg-body">{{ m.body }}</div>
              <div class="msg-time">{{ formatTime(m.created_at) }}</div>
            </div>
          </div>
        </div>

        <form class="chat-input" @submit.prevent="send">
          <input
            v-model="draft"
            type="text"
            placeholder="พิมพ์ข้อความ..."
            maxlength="2000"
            :disabled="sending"
          />
          <button type="submit" :disabled="sending || !draft.trim()">
            <i class="fas fa-paper-plane"></i>
          </button>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../../lib/axios';

const conversations = ref([]);
const loadingList = ref(false);
const activeId = ref(null);
const activeConv = ref(null);
const messages = ref([]);
const loadingMessages = ref(false);
const draft = ref('');
const sending = ref(false);
const wsConnected = ref(false);
const threadEl = ref(null);
let currentChannel = null;

function roleLabel(role) {
  return { customer: 'ลูกค้า', staff: 'สตาฟ', admin: 'แอดมิน', system: 'ระบบ' }[role] || role;
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
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

async function send() {
  const body = draft.value.trim();
  if (!body || sending.value) return;
  sending.value = true;
  try {
    const res = await api.post(`/schedules/${activeId.value}/chat/messages`, { body });
    messages.value.push(res.data.data);
    draft.value = '';
    scrollToBottom();
  } finally {
    sending.value = false;
  }
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
onBeforeUnmount(leaveChannel);
</script>

<style scoped>
.chat-page {
  display: flex;
  height: calc(100vh - 120px);
  gap: 16px;
}
.chat-sidebar {
  width: 320px;
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
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.conv-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; }
.conv-item { padding: 14px 16px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background 0.15s; }
.conv-item:hover { background: #f9fafb; }
.conv-item.active { background: #ecfdf5; border-left: 3px solid #2D7A4F; }
.conv-title { font-weight: 800; font-size: 14px; color: #111827; }
.conv-meta { display: flex; justify-content: space-between; font-size: 11.5px; color: #6b7280; margin-top: 3px; }
.conv-count { font-weight: 700; }
.conv-preview { font-size: 12px; color: #6b7280; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.empty-hint { padding: 24px 16px; color: #9ca3af; font-size: 13px; text-align: center; }
.chat-main { flex: 1; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; display: flex; flex-direction: column; overflow: hidden; }
.chat-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #c0c4cc; gap: 12px; }
.chat-empty i { font-size: 48px; }
.chat-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #f0f0f0; }
.chat-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.chat-sub { font-size: 12px; color: #6b7280; }
.ws-dot { width: 10px; height: 10px; border-radius: 50%; background: #d1d5db; }
.ws-dot.on { background: #22c55e; }
.chat-thread { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 10px; background: #fafafa; }
.msg-row { display: flex; }
.msg-row.mine { justify-content: flex-end; }
.msg-bubble { max-width: 70%; padding: 10px 13px; border-radius: 14px; background: #fff; border: 1px solid #eceff1; }
.msg-row.mine .msg-bubble { background: #2D7A4F; color: #fff; border-color: #2D7A4F; }
.msg-author { font-size: 11.5px; font-weight: 800; color: #374151; margin-bottom: 3px; display: flex; gap: 6px; align-items: center; }
.role-tag { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; background: #eef2ff; color: #4338ca; }
.role-staff .role-tag { background: #ecfeff; color: #0e7490; }
.role-admin .role-tag { background: #fef3c7; color: #b45309; }
.msg-body { font-size: 13.5px; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
.msg-time { font-size: 10px; opacity: 0.65; margin-top: 4px; text-align: right; }
.chat-input { display: flex; gap: 10px; padding: 14px 16px; border-top: 1px solid #f0f0f0; }
.chat-input input { flex: 1; border: 1px solid #d1d5db; border-radius: 999px; padding: 10px 16px; font-size: 14px; outline: none; }
.chat-input input:focus { border-color: #2D7A4F; }
.chat-input button { border: none; background: #2D7A4F; color: #fff; width: 44px; border-radius: 50%; cursor: pointer; }
.chat-input button:disabled { background: #cbd5e1; cursor: not-allowed; }
</style>
