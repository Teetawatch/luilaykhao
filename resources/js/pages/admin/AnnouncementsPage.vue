<template>
  <div class="ann-page">
    <!-- ─── Sidebar : schedule list ─────────────────── -->
    <div class="ann-sidebar">
      <div class="ann-sidebar-header">
        <h2><i class="fas fa-bullhorn"></i> ประกาศจากผู้จัด</h2>
        <button class="refresh-btn" :disabled="loadingList" @click="loadConversations" title="รีเฟรช">
          <i class="fas fa-sync" :class="{ spin: loadingList }"></i>
        </button>
      </div>

      <div class="sidebar-search">
        <i class="fas fa-search"></i>
        <input v-model="search" type="text" placeholder="ค้นหารอบเดินทาง..." />
        <button v-if="search" class="clear-search" @click="search = ''"><i class="fas fa-times"></i></button>
      </div>

      <div v-if="loadingList" class="empty-hint">กำลังโหลด...</div>
      <div v-else-if="!filteredConversations.length" class="empty-hint">
        {{ search ? 'ไม่พบรอบเดินทางที่ค้นหา' : 'ไม่มีรอบเดินทาง' }}
      </div>

      <ul v-else class="conv-list">
        <li
          v-for="conv in filteredConversations"
          :key="conv.schedule_id"
          class="conv-item"
          :class="{ active: conv.schedule_id === activeId }"
          @click="openSchedule(conv)"
        >
          <div class="conv-thumb">
            <img v-if="conv.trip_image" :src="conv.trip_image" alt="" />
            <i v-else class="fas fa-mountain-sun"></i>
          </div>
          <div class="conv-content">
            <div class="conv-top">
              <span class="conv-title">{{ conv.trip_title || 'ทริป' }}</span>
            </div>
            <div class="conv-sub">
              <i class="fas fa-calendar-day"></i> {{ formatDate(conv.departure_date) }}
              <span v-if="conv.vehicle_name" class="conv-vehicle">
                <i class="fas fa-van-shuttle"></i> {{ conv.vehicle_name }}
              </span>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- ─── Main : composer + list ──────────────────── -->
    <div class="ann-main">
      <div v-if="!activeId" class="ann-empty">
        <i class="fas fa-bullhorn"></i>
        <p>เลือกรอบเดินทางเพื่อจัดการประกาศ</p>
        <span>ประกาศจะถูกส่งแจ้งเตือนให้สมาชิกของรอบนั้นทุกคน</span>
      </div>

      <template v-else>
        <div class="ann-header">
          <div class="ann-header-info">
            <div class="header-thumb">
              <img v-if="activeConv?.trip_image" :src="activeConv.trip_image" alt="" />
              <i v-else class="fas fa-mountain-sun"></i>
            </div>
            <div>
              <h3>{{ activeConv?.trip_title || 'ทริป' }}</h3>
              <span class="ann-sub">เดินทาง {{ formatDate(activeConv?.departure_date) }}</span>
            </div>
          </div>
          <span class="ws-pill" :class="{ on: wsConnected }">
            <span class="ws-dot"></span>{{ wsConnected ? 'เรียลไทม์' : 'ออฟไลน์' }}
          </span>
        </div>

        <div class="ann-scroll">
          <!-- composer -->
          <form class="composer" @submit.prevent="post">
            <div class="composer-title">
              <i class="fas fa-pen-to-square"></i> เขียนประกาศใหม่
            </div>

            <div class="cat-row">
              <button
                v-for="c in categories"
                :key="c.value"
                type="button"
                class="cat-chip"
                :class="{ active: form.category === c.value }"
                :style="form.category === c.value ? { background: c.color, borderColor: c.color, color: '#fff' } : {}"
                @click="form.category = c.value"
              >
                <i :class="c.icon"></i> {{ c.label }}
              </button>
            </div>

            <input
              v-model="form.title"
              class="composer-input"
              type="text"
              maxlength="140"
              placeholder="หัวข้อประกาศ เช่น เปลี่ยนจุดนัดพบ"
            />
            <textarea
              v-model="form.body"
              class="composer-textarea"
              rows="3"
              maxlength="4000"
              placeholder="รายละเอียด..."
            ></textarea>

            <div class="composer-actions">
              <label class="pin-toggle">
                <input v-model="form.is_pinned" type="checkbox" />
                <i class="fas fa-thumbtack"></i> ปักหมุดไว้บนสุด
              </label>
              <button type="submit" class="post-btn" :disabled="posting || !canPost">
                <i class="fas" :class="posting ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i>
                {{ editingId ? 'บันทึกการแก้ไข' : 'โพสต์ประกาศ' }}
              </button>
              <button v-if="editingId" type="button" class="cancel-btn" @click="resetForm">ยกเลิก</button>
            </div>
          </form>

          <!-- list -->
          <div class="list-title" v-if="!loadingItems">
            ประกาศทั้งหมด <span class="muted">({{ items.length }})</span>
          </div>
          <div v-if="loadingItems" class="empty-hint">กำลังโหลดประกาศ...</div>
          <div v-else-if="!items.length" class="empty-hint">ยังไม่มีประกาศ — เริ่มเขียนด้านบนได้เลย</div>

          <div v-else class="ann-list">
            <article
              v-for="a in items"
              :key="a.id"
              class="ann-card"
              :class="{ pinned: a.is_pinned }"
              :style="a.is_pinned ? { borderColor: catColor(a.category) } : {}"
            >
              <div class="ann-card-glyph" :style="{ background: catColor(a.category) + '22', color: catColor(a.category) }">
                <i :class="catIcon(a.category)"></i>
              </div>
              <div class="ann-card-main">
                <div class="ann-card-top">
                  <span class="cat-label" :style="{ color: catColor(a.category) }">{{ catLabel(a.category) }}</span>
                  <span v-if="a.is_pinned" class="pin-flag" :style="{ color: catColor(a.category) }">
                    <i class="fas fa-thumbtack"></i> ปักหมุด
                  </span>
                  <span class="ann-time">{{ formatDateTime(a.created_at) }}</span>
                </div>
                <h4 class="ann-card-title">{{ a.title }}</h4>
                <p class="ann-card-body">{{ a.body }}</p>
                <div class="ann-card-foot">
                  <span class="ann-author"><i class="fas fa-circle-check"></i> {{ a.author_name }}</span>
                  <div class="ann-card-actions">
                    <button class="mini-btn" @click="togglePin(a)" :title="a.is_pinned ? 'ปลดหมุด' : 'ปักหมุด'">
                      <i class="fas fa-thumbtack" :class="{ off: !a.is_pinned }"></i>
                    </button>
                    <button class="mini-btn" @click="startEdit(a)" title="แก้ไข"><i class="fas fa-pen"></i></button>
                    <button class="mini-btn danger" @click="remove(a)" title="ลบ"><i class="fas fa-trash"></i></button>
                  </div>
                </div>
              </div>
            </article>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

const toast = useToast();
const swal = useSwal();

const categories = [
  { value: 'general', label: 'ทั่วไป', icon: 'fas fa-bullhorn', color: '#007AFF' },
  { value: 'meeting_point', label: 'จุดนัดพบ', icon: 'fas fa-location-dot', color: '#5856D6' },
  { value: 'schedule_change', label: 'เปลี่ยนเวลา', icon: 'fas fa-clock', color: '#FF9500' },
  { value: 'packing', label: 'ของที่ต้องเตรียม', icon: 'fas fa-suitcase-rolling', color: '#34C759' },
  { value: 'weather', label: 'สภาพอากาศ', icon: 'fas fa-cloud', color: '#32ADE6' },
  { value: 'urgent', label: 'ด่วน', icon: 'fas fa-triangle-exclamation', color: '#FF3B30' },
];

const conversations = ref([]);
const loadingList = ref(false);
const search = ref('');
const activeId = ref(null);
const activeConv = ref(null);

const items = ref([]);
const loadingItems = ref(false);
const posting = ref(false);
const editingId = ref(null);
const wsConnected = ref(false);
let currentChannel = null;

const form = ref({ category: 'general', title: '', body: '', is_pinned: false });

const canPost = computed(() => form.value.title.trim() && form.value.body.trim());

const filteredConversations = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return conversations.value;
  return conversations.value.filter((c) => (c.trip_title || '').toLowerCase().includes(q));
});

function catMeta(v) {
  return categories.find((c) => c.value === v) || categories[0];
}
function catLabel(v) { return catMeta(v).label; }
function catIcon(v) { return catMeta(v).icon; }
function catColor(v) { return catMeta(v).color; }

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}
function formatDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

async function loadConversations() {
  loadingList.value = true;
  try {
    const res = await api.get('/admin/chat/conversations');
    conversations.value = res.data.data || [];
  } catch {
    toast.error('โหลดรายชื่อรอบเดินทางไม่สำเร็จ');
  } finally {
    loadingList.value = false;
  }
}

async function openSchedule(conv) {
  if (activeId.value === conv.schedule_id) return;
  leaveChannel();
  activeId.value = conv.schedule_id;
  activeConv.value = conv;
  resetForm();
  await loadItems();
  subscribe(conv.schedule_id);
}

async function loadItems() {
  loadingItems.value = true;
  try {
    const res = await api.get(`/schedules/${activeId.value}/announcements`);
    items.value = res.data.data?.announcements || [];
  } catch {
    toast.error('โหลดประกาศไม่สำเร็จ');
  } finally {
    loadingItems.value = false;
  }
}

function resetForm() {
  editingId.value = null;
  form.value = { category: 'general', title: '', body: '', is_pinned: false };
}

function startEdit(a) {
  editingId.value = a.id;
  form.value = {
    category: a.category,
    title: a.title,
    body: a.body,
    is_pinned: a.is_pinned,
  };
}

async function post() {
  if (!canPost.value || posting.value) return;
  posting.value = true;
  try {
    if (editingId.value) {
      const res = await api.put(`/schedules/${activeId.value}/announcements/${editingId.value}`, {
        category: form.value.category,
        title: form.value.title.trim(),
        body: form.value.body.trim(),
      });
      const updated = res.data.data;
      const idx = items.value.findIndex((x) => x.id === editingId.value);
      if (idx >= 0) items.value[idx] = { ...items.value[idx], ...updated };
      toast.success('แก้ไขประกาศแล้ว');
    } else {
      await api.post(`/schedules/${activeId.value}/announcements`, {
        category: form.value.category,
        title: form.value.title.trim(),
        body: form.value.body.trim(),
        is_pinned: form.value.is_pinned,
      });
      toast.success('โพสต์ประกาศและแจ้งเตือนสมาชิกแล้ว');
      await loadItems();
    }
    resetForm();
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    posting.value = false;
  }
}

async function togglePin(a) {
  const next = !a.is_pinned;
  try {
    if (next) {
      await api.post(`/schedules/${activeId.value}/announcements/${a.id}/pin`);
    } else {
      await api.delete(`/schedules/${activeId.value}/announcements/${a.id}/pin`);
    }
    await loadItems();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ปรับการปักหมุดไม่สำเร็จ');
  }
}

async function remove(a) {
  const ok = await swal.confirm({
    title: 'ลบประกาศนี้?',
    text: a.title,
    icon: 'warning',
    confirmText: 'ลบ',
  });
  if (!ok.isConfirmed) return;
  try {
    await api.delete(`/schedules/${activeId.value}/announcements/${a.id}`);
    items.value = items.value.filter((x) => x.id !== a.id);
    if (editingId.value === a.id) resetForm();
    toast.success('ลบประกาศแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'ลบไม่สำเร็จ');
  }
}

function subscribe(scheduleId) {
  if (!window.Echo) return;
  currentChannel = window.Echo.private(`announcements.schedule.${scheduleId}`)
    .listen('.announcement.posted', () => loadItems())
    .subscribed(() => { wsConnected.value = true; })
    .error(() => { wsConnected.value = false; });
}

function leaveChannel() {
  if (currentChannel && activeId.value && window.Echo) {
    window.Echo.leave(`announcements.schedule.${activeId.value}`);
  }
  currentChannel = null;
  wsConnected.value = false;
}

onMounted(loadConversations);
onBeforeUnmount(leaveChannel);
</script>

<style scoped>
.ann-page { display: flex; height: calc(100vh - 120px); gap: 16px; }

/* ─── Sidebar ──────────────────────────────────────── */
.ann-sidebar {
  width: 340px; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb;
  display: flex; flex-direction: column; overflow: hidden;
}
.ann-sidebar-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px; border-bottom: 1px solid #f0f0f0;
}
.ann-sidebar-header h2 { font-size: 16px; font-weight: 800; margin: 0; color: #1f2937; }
.refresh-btn { border: none; background: #f3f4f6; border-radius: 8px; padding: 8px 10px; cursor: pointer; color: #4b5563; }
.refresh-btn:hover { background: #e5e7eb; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.sidebar-search { position: relative; display: flex; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f5f5f5; }
.sidebar-search > i.fa-search { position: absolute; left: 22px; color: #9ca3af; font-size: 12px; }
.sidebar-search input { width: 100%; border: 1px solid #e5e7eb; border-radius: 999px; padding: 8px 32px; font-size: 13px; outline: none; }
.sidebar-search input:focus { border-color: #2D7A4F; }
.clear-search { position: absolute; right: 20px; border: none; background: none; color: #9ca3af; cursor: pointer; }

.conv-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; flex: 1; }
.conv-item { display: flex; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background 0.15s; }
.conv-item:hover { background: #f9fafb; }
.conv-item.active { background: #ecfdf5; box-shadow: inset 3px 0 0 #2D7A4F; }
.conv-thumb {
  width: 48px; height: 48px; border-radius: 12px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.conv-thumb img { width: 100%; height: 100%; object-fit: cover; }
.conv-content { flex: 1; min-width: 0; }
.conv-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.conv-title { font-weight: 800; font-size: 13.5px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-sub { font-size: 11.5px; color: #6b7280; margin-top: 2px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.conv-sub i { font-size: 10px; }
.conv-vehicle { display: inline-flex; align-items: center; gap: 3px; color: #2D7A4F; font-weight: 700; }
.empty-hint { padding: 24px 16px; color: #9ca3af; font-size: 13px; text-align: center; }

/* ─── Main ─────────────────────────────────────────── */
.ann-main { flex: 1; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; display: flex; flex-direction: column; overflow: hidden; }
.ann-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #c0c4cc; gap: 10px; }
.ann-empty i { font-size: 48px; }
.ann-empty p { margin: 0; font-size: 15px; font-weight: 700; color: #9ca3af; }
.ann-empty span { font-size: 12.5px; }

.ann-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
.ann-header-info { display: flex; align-items: center; gap: 12px; }
.header-thumb {
  width: 42px; height: 42px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.header-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ann-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.ann-sub { font-size: 12px; color: #6b7280; }
.ws-pill { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: #9ca3af; background: #f3f4f6; border-radius: 999px; padding: 4px 10px; }
.ws-pill.on { color: #15803d; background: #ecfdf5; }
.ws-dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; }
.ws-pill.on .ws-dot { background: #22c55e; }

.ann-scroll { flex: 1; overflow-y: auto; padding: 20px; background: #fafafa; }

/* ─── Composer ─────────────────────────────────────── */
.composer { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; margin-bottom: 20px; }
.composer-title { font-size: 14px; font-weight: 800; color: #1f2937; margin-bottom: 12px; }
.composer-title i { color: #2D7A4F; margin-right: 6px; }
.cat-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.cat-chip {
  border: 1px solid #e5e7eb; background: #fff; color: #4b5563;
  border-radius: 999px; padding: 6px 12px; font-size: 12.5px; font-weight: 700;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s;
}
.cat-chip i { font-size: 11px; }
.composer-input, .composer-textarea {
  width: 100%; border: 1px solid #d1d5db; border-radius: 10px;
  padding: 10px 14px; font-size: 14px; outline: none; margin-bottom: 10px;
  font-family: inherit;
}
.composer-input:focus, .composer-textarea:focus { border-color: #2D7A4F; }
.composer-textarea { resize: vertical; line-height: 1.5; }
.composer-actions { display: flex; align-items: center; gap: 12px; }
.pin-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: #6b7280; cursor: pointer; }
.pin-toggle input { width: 16px; height: 16px; accent-color: #2D7A4F; }
.post-btn {
  margin-left: auto; border: none; background: #2D7A4F; color: #fff;
  border-radius: 10px; padding: 10px 20px; font-size: 14px; font-weight: 800;
  cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
}
.post-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.cancel-btn { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; border-radius: 10px; padding: 10px 16px; font-weight: 700; cursor: pointer; }

/* ─── List ─────────────────────────────────────────── */
.list-title { font-size: 13.5px; font-weight: 800; color: #374151; margin-bottom: 12px; }
.list-title .muted { color: #9ca3af; font-weight: 600; }
.ann-list { display: flex; flex-direction: column; gap: 12px; }
.ann-card {
  display: flex; gap: 12px; background: #fff; border: 1px solid #e9edf0;
  border-radius: 14px; padding: 14px;
}
.ann-card.pinned { border-width: 1.5px; box-shadow: 0 4px 14px rgba(0,0,0,0.04); }
.ann-card-glyph {
  width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: 17px;
}
.ann-card-main { flex: 1; min-width: 0; }
.ann-card-top { display: flex; align-items: center; gap: 8px; }
.cat-label { font-size: 11.5px; font-weight: 800; letter-spacing: 0.2px; }
.pin-flag { font-size: 11px; font-weight: 700; }
.pin-flag i { font-size: 10px; }
.ann-time { margin-left: auto; font-size: 11.5px; color: #9ca3af; }
.ann-card-title { margin: 4px 0 4px; font-size: 15px; font-weight: 800; color: #111827; }
.ann-card-body { margin: 0; font-size: 13.5px; line-height: 1.55; color: #4b5563; white-space: pre-wrap; word-break: break-word; }
.ann-card-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; }
.ann-author { font-size: 12px; font-weight: 700; color: #6b7280; }
.ann-author i { color: #2D7A4F; margin-right: 4px; }
.ann-card-actions { display: flex; gap: 6px; }
.mini-btn { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; transition: all 0.15s; }
.mini-btn:hover { background: #f3f4f6; color: #374151; }
.mini-btn .fa-thumbtack.off { opacity: 0.4; }
.mini-btn.danger:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
</style>
