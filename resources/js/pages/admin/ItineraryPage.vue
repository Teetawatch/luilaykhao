<template>
  <div class="itin-page">
    <!-- ─── Sidebar : schedule list ─────────────────── -->
    <div class="itin-sidebar">
      <div class="itin-sidebar-header">
        <h2><i class="fas fa-list-check"></i> กำหนดการเดินทาง</h2>
        <button class="refresh-btn" :disabled="loadingList" @click="loadSchedules" title="รีเฟรช">
          <i class="fas fa-sync" :class="{ spin: loadingList }"></i>
        </button>
      </div>

      <div class="sidebar-search">
        <i class="fas fa-search"></i>
        <input v-model="search" type="text" placeholder="ค้นหารอบเดินทาง..." />
        <button v-if="search" class="clear-search" @click="search = ''"><i class="fas fa-times"></i></button>
      </div>

      <label class="upcoming-toggle">
        <input v-model="upcomingOnly" type="checkbox" @change="loadSchedules" />
        เฉพาะรอบที่ยังไม่ออกเดินทาง
      </label>

      <div v-if="loadingList" class="empty-hint">กำลังโหลด...</div>
      <div v-else-if="!groupedSchedules.length" class="empty-hint">
        {{ search ? 'ไม่พบรอบเดินทางที่ค้นหา' : 'ไม่มีรอบเดินทาง' }}
      </div>

      <div v-else class="conv-list">
        <!-- จัดกลุ่มตามทริป (เรียงทริปที่มีรอบใกล้ถึงก่อน) -->
        <section v-for="g in groupedSchedules" :key="g.tripId" class="trip-group">
          <header class="trip-group-head">
            <div class="trip-group-thumb">
              <img v-if="g.cover" :src="g.cover" alt="" />
              <i v-else class="fas fa-mountain-sun"></i>
            </div>
            <span class="trip-group-title">{{ g.title }}</span>
            <span class="trip-group-count">{{ g.items.length }} รอบ</span>
          </header>

          <ul class="round-list">
            <li
              v-for="sch in g.items"
              :key="sch.id"
              class="round-item"
              :class="{ active: sch.id === activeId, past: sch.isPast }"
              @click="openSchedule(sch)"
            >
              <span class="round-dot"></span>
              <div class="round-content">
                <span class="round-date">{{ formatDateRange(sch.departure_date, sch.return_date) }}</span>
                <span class="round-sub">
                  <span class="round-countdown">{{ countdownLabel(sch.departure_date) }}</span>
                  <span v-if="sch.vehicle?.name" class="conv-vehicle">
                    <i class="fas fa-van-shuttle"></i> {{ sch.vehicle.name }}
                  </span>
                </span>
              </div>
            </li>
          </ul>
        </section>
      </div>
    </div>

    <!-- ─── Main : composer + timeline ──────────────── -->
    <div class="itin-main">
      <div v-if="!activeId" class="itin-empty">
        <i class="fas fa-list-check"></i>
        <p>เลือกรอบเดินทางเพื่อจัดการกำหนดการ</p>
        <span>กำหนดการจะแสดงให้สตาฟประจำรอบนั้นอ่านในแอปเพื่อเตรียมตัว</span>
      </div>

      <template v-else>
        <div class="itin-header">
          <div class="itin-header-info">
            <div class="header-thumb">
              <img v-if="activeSchedule?.trip?.cover_image" :src="activeSchedule.trip.cover_image" alt="" />
              <i v-else class="fas fa-mountain-sun"></i>
            </div>
            <div>
              <h3>{{ activeSchedule?.trip?.title || 'ทริป' }}</h3>
              <span class="itin-sub">
                เดินทาง {{ formatDateRange(activeSchedule?.departure_date, activeSchedule?.return_date) }}
              </span>
            </div>
          </div>
        </div>

        <div class="itin-scroll">
          <!-- composer -->
          <form class="composer" @submit.prevent="save">
            <div class="composer-title">
              <i class="fas fa-pen-to-square"></i> {{ editingId ? 'แก้ไขรายการ' : 'เพิ่มรายการกำหนดการ' }}
            </div>

            <div class="field-row">
              <div class="field">
                <label>วันที่</label>
                <input v-model="form.item_date" type="date" class="composer-input" />
              </div>
              <div class="field field-time">
                <label>เวลา</label>
                <input v-model="form.time" type="time" class="composer-input" />
              </div>
            </div>

            <input
              v-model="form.title"
              class="composer-input"
              type="text"
              maxlength="200"
              placeholder="หัวข้อ เช่น ออกเดินทางจากกรุงเทพฯ"
            />
            <textarea
              v-model="form.detail"
              class="composer-textarea"
              rows="3"
              maxlength="4000"
              placeholder="รายละเอียด (ถ้ามี) เช่น นัดพบปั๊ม ปตท. พระราม 2 เวลา 05:45"
            ></textarea>

            <div class="composer-actions">
              <button type="submit" class="post-btn" :disabled="saving || !canSave">
                <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-plus'"></i>
                {{ editingId ? 'บันทึกการแก้ไข' : 'เพิ่มรายการ' }}
              </button>
              <button v-if="editingId" type="button" class="cancel-btn" @click="resetForm">ยกเลิก</button>
            </div>
          </form>

          <!-- timeline -->
          <div class="list-title" v-if="!loadingItems">
            กำหนดการทั้งหมด <span class="muted">({{ items.length }})</span>
          </div>
          <div v-if="loadingItems" class="empty-hint">กำลังโหลดกำหนดการ...</div>
          <div v-else-if="!items.length" class="empty-hint">ยังไม่มีกำหนดการ — เริ่มเพิ่มด้านบนได้เลย</div>

          <div v-else class="day-groups">
            <div v-for="(group, gi) in groupedItems" :key="group.key" class="day-group">
              <div class="day-head">
                <span class="day-badge">{{ group.dayLabel }}</span>
                <span v-if="group.dateText" class="day-date">{{ group.dateText }}</span>
              </div>
              <div class="itin-list">
                <article v-for="it in group.items" :key="it.id" class="itin-card">
                  <div class="itin-time">
                    <i class="fas fa-clock"></i>
                    {{ it.time || '—' }}
                  </div>
                  <div class="itin-card-main">
                    <h4 class="itin-card-title">{{ it.title }}</h4>
                    <p v-if="it.detail" class="itin-card-detail">{{ it.detail }}</p>
                  </div>
                  <div class="itin-card-actions">
                    <button class="mini-btn" @click="startEdit(it)" title="แก้ไข"><i class="fas fa-pen"></i></button>
                    <button class="mini-btn danger" @click="remove(it)" title="ลบ"><i class="fas fa-trash"></i></button>
                  </div>
                </article>
              </div>
              <div v-if="gi < groupedItems.length - 1" class="day-spacer"></div>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

const toast = useToast();
const swal = useSwal();

const schedules = ref([]);
const loadingList = ref(false);
const search = ref('');
const upcomingOnly = ref(true);
const activeId = ref(null);
const activeSchedule = ref(null);

const items = ref([]);
const loadingItems = ref(false);
const saving = ref(false);
const editingId = ref(null);

const form = ref({ item_date: '', time: '', title: '', detail: '' });

const canSave = computed(() => form.value.title.trim().length > 0);

// timestamp ของวันที่ (วันที่ไม่ระบุ → Infinity ให้ไปท้ายสุด)
function dateVal(d) {
  if (!d) return Infinity;
  const t = new Date(d).getTime();
  return Number.isNaN(t) ? Infinity : t;
}

const startOfToday = () => {
  const n = new Date();
  return new Date(n.getFullYear(), n.getMonth(), n.getDate()).getTime();
};

// เรียง "ใกล้จะถึงก่อน": รอบที่ยังไม่ออก (วันนี้เป็นต้นไป) เรียงจากใกล้สุด
// ตามด้วยรอบที่ผ่านไปแล้วเรียงจากล่าสุด
function compareUpcoming(a, b) {
  const now = startOfToday();
  const ta = dateVal(a);
  const tb = dateVal(b);
  const aUp = ta >= now;
  const bUp = tb >= now;
  if (aUp !== bUp) return aUp ? -1 : 1;
  return aUp ? ta - tb : tb - ta;
}

// จัดกลุ่มตามทริป + เรียงรอบในกลุ่ม และเรียงกลุ่มด้วยรอบที่ใกล้ถึงสุด
const groupedSchedules = computed(() => {
  const q = search.value.trim().toLowerCase();
  const list = q
    ? schedules.value.filter((s) => (s.trip?.title || '').toLowerCase().includes(q))
    : schedules.value;

  const now = startOfToday();
  const groups = new Map();
  for (const s of list) {
    const tripId = s.trip_id ?? s.trip?.id ?? `t:${s.trip?.title || '?'}`;
    if (!groups.has(tripId)) {
      groups.set(tripId, {
        tripId,
        title: s.trip?.title || 'ทริป',
        cover: s.trip?.cover_image || null,
        items: [],
      });
    }
    groups.get(tripId).items.push({ ...s, isPast: dateVal(s.departure_date) < now });
  }

  const result = [...groups.values()];
  for (const g of result) {
    g.items.sort((a, b) => compareUpcoming(a.departure_date, b.departure_date));
    g.repDate = g.items[0]?.departure_date ?? null;
  }
  result.sort((a, b) => compareUpcoming(a.repDate, b.repDate));
  return result;
});

// ป้ายนับถอยหลังสั้น ๆ: "วันนี้ / พรุ่งนี้ / อีก N วัน / ผ่านมาแล้ว N วัน"
function countdownLabel(d) {
  const t = dateVal(d);
  if (t === Infinity) return 'ยังไม่ระบุวัน';
  const days = Math.round((new Date(new Date(d).getFullYear(), new Date(d).getMonth(), new Date(d).getDate()).getTime() - startOfToday()) / 86400000);
  if (days === 0) return 'วันนี้';
  if (days === 1) return 'พรุ่งนี้';
  if (days > 1) return `อีก ${days} วัน`;
  if (days === -1) return 'เมื่อวาน';
  return `ผ่านมาแล้ว ${Math.abs(days)} วัน`;
}

// จัดกลุ่มตามวันที่ — backend ส่งมาเรียง วัน → เวลา → ลำดับ อยู่แล้ว
const groupedItems = computed(() => {
  const groups = [];
  const byKey = new Map();
  for (const it of items.value) {
    const key = it.item_date || '__none__';
    if (!byKey.has(key)) {
      const g = { key, date: it.item_date || null, items: [] };
      byKey.set(key, g);
      groups.push(g);
    }
    byKey.get(key).items.push(it);
  }
  let dayNo = 0;
  return groups.map((g) => {
    const hasDate = !!g.date;
    if (hasDate) dayNo += 1;
    return {
      ...g,
      dayLabel: hasDate ? `วันที่ ${dayNo}` : 'ไม่ระบุวัน',
      dateText: hasDate ? formatThaiDate(g.date) : '',
    };
  });
});

function formatThaiDate(d) {
  if (!d) return '';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatDateRange(from, to) {
  const f = formatShort(from);
  const t = formatShort(to);
  if (!f) return '-';
  if (!t || t === f) return f;
  return `${f} - ${t}`;
}
function formatShort(d) {
  if (!d) return '';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

async function loadSchedules() {
  loadingList.value = true;
  try {
    const params = { per_page: 100 };
    if (upcomingOnly.value) params.upcoming = 1;
    const res = await api.get('/admin/schedules', { params });
    schedules.value = res.data.data || [];
  } catch {
    toast.error('โหลดรายชื่อรอบเดินทางไม่สำเร็จ');
  } finally {
    loadingList.value = false;
  }
}

async function openSchedule(sch) {
  if (activeId.value === sch.id) return;
  activeId.value = sch.id;
  activeSchedule.value = sch;
  resetForm();
  // ตั้งค่าวันเริ่มต้นในฟอร์มเป็นวันออกเดินทาง เพื่อกรอกเร็วขึ้น
  form.value.item_date = sch.departure_date || '';
  await loadItems();
}

async function loadItems() {
  loadingItems.value = true;
  try {
    const res = await api.get(`/admin/schedules/${activeId.value}/itinerary`);
    items.value = res.data.data?.items || [];
  } catch {
    toast.error('โหลดกำหนดการไม่สำเร็จ');
  } finally {
    loadingItems.value = false;
  }
}

function resetForm() {
  editingId.value = null;
  form.value = {
    item_date: activeSchedule.value?.departure_date || '',
    time: '',
    title: '',
    detail: '',
  };
}

function startEdit(it) {
  editingId.value = it.id;
  form.value = {
    item_date: it.item_date || '',
    time: it.time || '',
    title: it.title || '',
    detail: it.detail || '',
  };
}

function payload() {
  return {
    item_date: form.value.item_date || null,
    time: form.value.time || null,
    title: form.value.title.trim(),
    detail: form.value.detail.trim() || null,
  };
}

async function save() {
  if (!canSave.value || saving.value) return;
  saving.value = true;
  try {
    if (editingId.value) {
      await api.put(`/admin/schedules/${activeId.value}/itinerary/${editingId.value}`, payload());
      toast.success('แก้ไขกำหนดการแล้ว');
    } else {
      await api.post(`/admin/schedules/${activeId.value}/itinerary`, payload());
      toast.success('เพิ่มกำหนดการแล้ว');
    }
    await loadItems();
    resetForm();
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function remove(it) {
  const ok = await swal.confirm({
    title: 'ลบรายการนี้?',
    text: it.title,
    icon: 'warning',
    confirmText: 'ลบ',
  });
  if (!ok.isConfirmed) return;
  try {
    await api.delete(`/admin/schedules/${activeId.value}/itinerary/${it.id}`);
    items.value = items.value.filter((x) => x.id !== it.id);
    if (editingId.value === it.id) resetForm();
    toast.success('ลบกำหนดการแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'ลบไม่สำเร็จ');
  }
}

loadSchedules();
</script>

<style scoped>
.itin-page { display: flex; height: calc(100vh - 120px); gap: 16px; }

/* ─── Sidebar ──────────────────────────────────────── */
.itin-sidebar {
  width: 340px; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb;
  display: flex; flex-direction: column; overflow: hidden;
}
.itin-sidebar-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px; border-bottom: 1px solid #f0f0f0;
}
.itin-sidebar-header h2 { font-size: 16px; font-weight: 800; margin: 0; color: #1f2937; }
.itin-sidebar-header h2 i { color: #2D7A4F; margin-right: 6px; }
.refresh-btn { border: none; background: #f3f4f6; border-radius: 8px; padding: 8px 10px; cursor: pointer; color: #4b5563; }
.refresh-btn:hover { background: #e5e7eb; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.sidebar-search { position: relative; display: flex; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f5f5f5; }
.sidebar-search > i.fa-search { position: absolute; left: 22px; color: #9ca3af; font-size: 12px; }
.sidebar-search input { width: 100%; border: 1px solid #e5e7eb; border-radius: 999px; padding: 8px 32px; font-size: 13px; outline: none; }
.sidebar-search input:focus { border-color: #2D7A4F; }
.clear-search { position: absolute; right: 20px; border: none; background: none; color: #9ca3af; cursor: pointer; }

.upcoming-toggle {
  display: flex; align-items: center; gap: 8px; padding: 10px 16px;
  font-size: 12.5px; font-weight: 700; color: #6b7280; cursor: pointer;
  border-bottom: 1px solid #f5f5f5;
}
.upcoming-toggle input { width: 15px; height: 15px; accent-color: #2D7A4F; }

.conv-list { margin: 0; padding: 0; overflow-y: auto; flex: 1; }

/* ── Trip group ── */
.trip-group { border-bottom: 8px solid #f3f4f6; }
.trip-group:last-child { border-bottom: none; }
.trip-group-head {
  display: flex; align-items: center; gap: 10px; padding: 11px 14px;
  background: #f9fafb; border-bottom: 1px solid #eef0f2; position: sticky; top: 0; z-index: 1;
}
.trip-group-thumb {
  width: 30px; height: 30px; border-radius: 8px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F; font-size: 13px;
}
.trip-group-thumb img { width: 100%; height: 100%; object-fit: cover; }
.trip-group-title { flex: 1; min-width: 0; font-weight: 800; font-size: 13.5px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.trip-group-count { flex-shrink: 0; font-size: 11px; font-weight: 700; color: #2D7A4F; background: #ecfdf5; border-radius: 999px; padding: 2px 9px; }

/* ── Round item ── */
.round-list { list-style: none; margin: 0; padding: 0; }
.round-item {
  display: flex; align-items: flex-start; gap: 10px; padding: 11px 14px 11px 18px;
  border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background 0.15s;
}
.round-item:hover { background: #f9fafb; }
.round-item.active { background: #ecfdf5; box-shadow: inset 3px 0 0 #2D7A4F; }
.round-dot { width: 8px; height: 8px; border-radius: 50%; background: #2D7A4F; margin-top: 5px; flex-shrink: 0; }
.round-item.past .round-dot { background: #cbd5e1; }
.round-content { flex: 1; min-width: 0; }
.round-date { display: block; font-weight: 800; font-size: 13px; color: #111827; }
.round-item.past .round-date { color: #6b7280; }
.round-sub { font-size: 11.5px; color: #6b7280; margin-top: 2px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.round-countdown { font-weight: 700; color: #2D7A4F; }
.round-item.past .round-countdown { color: #9ca3af; }
.conv-vehicle { display: inline-flex; align-items: center; gap: 3px; color: #6b7280; font-weight: 700; }
.conv-vehicle i { font-size: 10px; }
.empty-hint { padding: 24px 16px; color: #9ca3af; font-size: 13px; text-align: center; }

/* ─── Main ─────────────────────────────────────────── */
.itin-main { flex: 1; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; display: flex; flex-direction: column; overflow: hidden; }
.itin-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #c0c4cc; gap: 10px; }
.itin-empty i { font-size: 48px; }
.itin-empty p { margin: 0; font-size: 15px; font-weight: 700; color: #9ca3af; }
.itin-empty span { font-size: 12.5px; }

.itin-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
.itin-header-info { display: flex; align-items: center; gap: 12px; }
.header-thumb {
  width: 42px; height: 42px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.header-thumb img { width: 100%; height: 100%; object-fit: cover; }
.itin-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.itin-sub { font-size: 12px; color: #6b7280; }

.itin-scroll { flex: 1; overflow-y: auto; padding: 20px; background: #fafafa; }

/* ─── Composer ─────────────────────────────────────── */
.composer { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; margin-bottom: 20px; }
.composer-title { font-size: 14px; font-weight: 800; color: #1f2937; margin-bottom: 12px; }
.composer-title i { color: #2D7A4F; margin-right: 6px; }
.field-row { display: flex; gap: 12px; margin-bottom: 10px; }
.field { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.field-time { max-width: 140px; }
.field label { font-size: 12px; font-weight: 700; color: #6b7280; }
.composer-input, .composer-textarea {
  width: 100%; border: 1px solid #d1d5db; border-radius: 10px;
  padding: 10px 14px; font-size: 14px; outline: none; margin-bottom: 10px;
  font-family: inherit;
}
.field .composer-input { margin-bottom: 0; }
.composer-input:focus, .composer-textarea:focus { border-color: #2D7A4F; }
.composer-textarea { resize: vertical; line-height: 1.5; }
.composer-actions { display: flex; align-items: center; gap: 12px; }
.post-btn {
  border: none; background: #2D7A4F; color: #fff;
  border-radius: 10px; padding: 10px 20px; font-size: 14px; font-weight: 800;
  cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
}
.post-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.cancel-btn { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; border-radius: 10px; padding: 10px 16px; font-weight: 700; cursor: pointer; }

/* ─── Timeline ─────────────────────────────────────── */
.list-title { font-size: 13.5px; font-weight: 800; color: #374151; margin-bottom: 12px; }
.list-title .muted { color: #9ca3af; font-weight: 600; }
.day-group { margin-bottom: 4px; }
.day-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.day-badge { background: #ecfdf5; color: #2D7A4F; font-weight: 800; font-size: 12.5px; border-radius: 999px; padding: 4px 11px; }
.day-date { font-size: 12.5px; font-weight: 700; color: #6b7280; }
.day-spacer { height: 14px; }
.itin-list { display: flex; flex-direction: column; gap: 10px; }
.itin-card {
  display: flex; gap: 12px; align-items: flex-start; background: #fff;
  border: 1px solid #e9edf0; border-radius: 14px; padding: 14px;
}
.itin-time {
  display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0;
  background: #ecfdf5; color: #2D7A4F; font-weight: 800; font-size: 13px;
  border-radius: 8px; padding: 6px 10px; min-width: 74px; justify-content: center;
}
.itin-time i { font-size: 11px; }
.itin-card-main { flex: 1; min-width: 0; }
.itin-card-title { margin: 2px 0 0; font-size: 15px; font-weight: 800; color: #111827; }
.itin-card-detail { margin: 5px 0 0; font-size: 13.5px; line-height: 1.55; color: #4b5563; white-space: pre-wrap; word-break: break-word; }
.itin-card-actions { display: flex; gap: 6px; flex-shrink: 0; }
.mini-btn { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; transition: all 0.15s; }
.mini-btn:hover { background: #f3f4f6; color: #374151; }
.mini-btn.danger:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
</style>
