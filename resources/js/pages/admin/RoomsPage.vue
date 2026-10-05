<template>
  <div class="rooms-page">
    <!-- ─── Sidebar : schedule list ─────────────────── -->
    <div class="rooms-sidebar">
      <div class="rooms-sidebar-header">
        <h2><i class="fas fa-bed"></i> จัดห้องพัก</h2>
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
      <div v-else-if="!sortedSchedules.length" class="empty-hint">
        {{ search ? 'ไม่พบรอบเดินทางที่ค้นหา' : 'ไม่มีรอบเดินทาง' }}
      </div>

      <ul v-else class="conv-list">
        <li
          v-for="sch in sortedSchedules"
          :key="sch.id"
          class="conv-item"
          :class="{ active: sch.id === activeId, past: sch.isPast }"
          @click="openSchedule(sch)"
        >
          <div class="conv-thumb">
            <img v-if="sch.trip?.cover_image" :src="sch.trip.cover_image" alt="" />
            <i v-else class="fas fa-mountain-sun"></i>
          </div>
          <div class="conv-content">
            <span class="round-date">{{ formatDateRange(sch.departure_date, sch.return_date) }}</span>
            <span class="conv-title">{{ sch.trip?.title || 'ทริป' }}</span>
            <span class="conv-sub">
              <span class="round-countdown">{{ countdownLabel(sch.departure_date) }}</span>
            </span>
          </div>
        </li>
      </ul>
    </div>

    <!-- ─── Main ─────────────────────────────────────── -->
    <div class="rooms-main">
      <div v-if="!activeId" class="rooms-empty">
        <i class="fas fa-bed"></i>
        <p>เลือกรอบเดินทางเพื่อจัดห้องพัก</p>
        <span>ลูกทริปเห็นห้องของตัวเองในแอป และได้แจ้งเตือนว่าพักกับใครเมื่อกดประกาศ</span>
      </div>

      <template v-else>
        <div class="rooms-header">
          <div class="rooms-header-info">
            <div class="header-thumb">
              <img v-if="activeSchedule?.trip?.cover_image" :src="activeSchedule.trip.cover_image" alt="" />
              <i v-else class="fas fa-mountain-sun"></i>
            </div>
            <div>
              <h3>{{ activeSchedule?.trip?.title || 'ทริป' }}</h3>
              <span class="rooms-sub">
                เดินทาง {{ formatDateRange(activeSchedule?.departure_date, activeSchedule?.return_date) }}
                <template v-if="data">
                  · <b :class="unassignedCount ? 'warn' : 'ok'">{{ progressText }}</b>
                </template>
              </span>
            </div>
          </div>
          <div class="header-actions" v-if="data">
            <button class="tool-btn" :class="{ on: panel === 'auto' }" @click="togglePanel('auto')">
              <i class="fas fa-wand-magic-sparkles"></i> จัดอัตโนมัติ
            </button>
            <button class="tool-btn" :class="{ on: panel === 'add' }" @click="togglePanel('add')">
              <i class="fas fa-plus"></i> เพิ่มห้อง
            </button>
            <button class="tool-btn" :disabled="!rooms.length" @click="copyForHotel">
              <i class="fas fa-copy"></i> คัดลอกรายชื่อส่งที่พัก
            </button>
          </div>
        </div>

        <div class="rooms-scroll">
          <div v-if="loading && !data" class="empty-hint">กำลังโหลดห้องพัก...</div>

          <template v-else-if="data">
            <!-- จัดอัตโนมัติ -->
            <form v-if="panel === 'auto'" class="panel" @submit.prevent="autoAssign">
              <div class="panel-title"><i class="fas fa-wand-magic-sparkles"></i> จัดห้องอัตโนมัติ</div>
              <p class="panel-hint">
                จัดเฉพาะคนที่ยังไม่มีห้อง — คนที่จองมาด้วยกันอยู่ห้องเดียวกัน คนที่มาคนเดียวจับคู่กับเพศเดียวกัน
                (ดูจากคำนำหน้าชื่อ) ห้องที่จัดไว้แล้วไม่ถูกแตะ
              </p>
              <div class="field-row">
                <div class="field field-sm">
                  <label>ห้องละ</label>
                  <select v-model.number="autoForm.room_size" class="input">
                    <option v-for="n in [1, 2, 3, 4, 5, 6, 8, 10]" :key="n" :value="n">{{ n }} คน</option>
                  </select>
                </div>
                <div class="field field-sm">
                  <label>ชื่อห้องขึ้นต้นด้วย</label>
                  <input v-model="autoForm.prefix" class="input" maxlength="40" placeholder="ห้อง" />
                </div>
                <div class="field">
                  <label>ที่พัก (ไม่บังคับ — ค้างหลายที่ค่อยใส่)</label>
                  <input v-model="autoForm.stay_label" class="input" maxlength="80" list="stay-options" placeholder="เช่น คืนแรก · ภูชี้ฟ้า" />
                </div>
              </div>
              <div class="panel-actions">
                <button type="submit" class="primary-btn" :disabled="busy">
                  <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-check'"></i> จัดห้อง
                </button>
                <button type="button" class="ghost-btn" @click="panel = null">ยกเลิก</button>
              </div>
            </form>

            <!-- เพิ่มห้อง -->
            <form v-if="panel === 'add'" class="panel" @submit.prevent="addRoom">
              <div class="panel-title"><i class="fas fa-door-open"></i> เพิ่มห้อง</div>
              <div class="field-row">
                <div class="field">
                  <label>ชื่อห้อง</label>
                  <input v-model="addForm.name" class="input" maxlength="60" placeholder="เช่น ห้อง 204" />
                </div>
                <div class="field">
                  <label>หมายเหตุ</label>
                  <input v-model="addForm.note" class="input" maxlength="200" placeholder="เช่น ชั้น 2 / เตียงคู่" />
                </div>
                <div class="field">
                  <label>ที่พัก (ไม่บังคับ)</label>
                  <input v-model="addForm.stay_label" class="input" maxlength="80" list="stay-options" placeholder="เช่น คืนแรก · ภูชี้ฟ้า" />
                </div>
              </div>
              <div class="panel-actions">
                <button type="submit" class="primary-btn" :disabled="busy || !addForm.name.trim()">
                  <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-plus'"></i> เพิ่มห้อง
                </button>
                <button type="button" class="ghost-btn" @click="panel = null">ยกเลิก</button>
              </div>
            </form>

            <datalist id="stay-options">
              <option v-for="s in namedStays" :key="s" :value="s" />
            </datalist>

            <div v-if="!data.total_passengers" class="empty-hint">
              ยังไม่มีผู้เดินทางที่ยืนยันแล้วในรอบนี้
            </div>

            <div v-else-if="!rooms.length && panel !== 'auto'" class="intro">
              <i class="fas fa-bed"></i>
              <div>
                <b>ยังไม่ได้จัดห้อง</b>
                <p>กด "จัดอัตโนมัติ" แล้วค่อยลากชื่อย้ายห้องเอง เสร็จแล้วกดประกาศในแชท ทุกคนจะได้แจ้งเตือนว่าพักห้องไหนกับใคร</p>
              </div>
              <button class="primary-btn" @click="togglePanel('auto')">
                <i class="fas fa-wand-magic-sparkles"></i> จัดอัตโนมัติ
              </button>
            </div>

            <!-- แต่ละที่พัก -->
            <section v-for="stay in stays" :key="stay ?? '__none__'" class="stay">
              <div class="stay-head">
                <h4>{{ stay ?? (stays.length > 1 ? 'ไม่ระบุที่พัก' : 'ห้องพัก') }}</h4>
                <span class="muted">{{ roomsOf(stay).length }} ห้อง</span>
                <span class="spacer"></span>
                <button
                  v-if="roomsOf(stay).some((r) => r.guests.length)"
                  class="announce-btn"
                  :disabled="busy"
                  @click="announce(stay)"
                >
                  <i class="fas fa-bullhorn"></i> ประกาศในแชท
                </button>
              </div>

              <!-- คนที่ยังไม่มีห้อง — ปล่อยชื่อจากห้องลงตรงนี้เพื่อเอาออกจากห้อง -->
              <div
                v-if="rooms.length"
                class="unassigned"
                :class="{ dropping: dropTarget === `u:${stay}` }"
                @dragover.prevent="dropTarget = `u:${stay}`"
                @dragleave="dropTarget = null"
                @drop.prevent="dropOnUnassigned(stay, $event)"
              >
                <div class="unassigned-title">
                  <template v-if="unassignedOf(stay).length">
                    ยังไม่มีห้อง {{ unassignedOf(stay).length }} คน — ลากชื่อไปวางในห้อง หรือเลือกห้องจากเมนูของห้องนั้น
                  </template>
                  <template v-else>ทุกคนมีห้องแล้ว (ลากชื่อมาวางตรงนี้เพื่อเอาออกจากห้อง)</template>
                </div>
                <div class="chips">
                  <span
                    v-for="p in unassignedOf(stay)"
                    :key="p.passenger_id"
                    class="chip"
                    draggable="true"
                    @dragstart="dragStart($event, p.passenger_id, null)"
                    :title="p.full_name"
                  >
                    <i class="fas fa-grip-vertical grip"></i>
                    {{ p.full_name }}
                    <small v-if="p.name !== firstWord(p.full_name)">({{ p.name }})</small>
                    <em v-if="p.gender === 'male'" class="g m">ช</em>
                    <em v-else-if="p.gender === 'female'" class="g f">ญ</em>
                  </span>
                </div>
              </div>

              <div class="room-grid">
                <div
                  v-for="room in roomsOf(stay)"
                  :key="room.id"
                  class="room-card"
                  :class="{ dropping: dropTarget === `r:${room.id}` }"
                  @dragover.prevent="dropTarget = `r:${room.id}`"
                  @dragleave="dropTarget = null"
                  @drop.prevent="dropOnRoom(room, $event)"
                >
                  <div v-if="editingId !== room.id" class="room-head">
                    <div class="room-name">
                      <i class="fas fa-door-closed"></i> {{ room.name }}
                      <span v-if="room.note" class="room-note">{{ room.note }}</span>
                    </div>
                    <span class="room-count">{{ room.guests.length }} คน</span>
                    <button class="mini-btn" title="แก้ชื่อห้อง" @click="startEdit(room)"><i class="fas fa-pen"></i></button>
                    <button class="mini-btn danger" title="ลบห้อง" @click="removeRoom(room)"><i class="fas fa-trash"></i></button>
                  </div>
                  <form v-else class="room-edit" @submit.prevent="saveEdit(room)">
                    <input v-model="editForm.name" class="input" maxlength="60" placeholder="ชื่อห้อง" />
                    <input v-model="editForm.note" class="input" maxlength="200" placeholder="หมายเหตุ" />
                    <div class="panel-actions">
                      <button type="submit" class="primary-btn sm" :disabled="busy || !editForm.name.trim()">บันทึก</button>
                      <button type="button" class="ghost-btn sm" @click="editingId = null">ยกเลิก</button>
                    </div>
                  </form>

                  <ul class="guest-list">
                    <li
                      v-for="g in room.guests"
                      :key="g.passenger_id"
                      draggable="true"
                      @dragstart="dragStart($event, g.passenger_id, room.id)"
                    >
                      <i class="fas fa-grip-vertical grip"></i>
                      <span class="guest-name">
                        {{ g.full_name || g.name }}
                        <small v-if="g.full_name && g.name !== firstWord(g.full_name)">({{ g.name }})</small>
                      </span>
                      <button class="x-btn" title="เอาออกจากห้อง" @click="removeGuest(room, g.passenger_id)">
                        <i class="fas fa-times"></i>
                      </button>
                    </li>
                    <li v-if="!room.guests.length" class="guest-empty">ยังว่าง — ลากชื่อมาวาง</li>
                  </ul>

                  <select
                    v-if="unassignedOf(stay).length"
                    class="input add-select"
                    :disabled="busy"
                    @change="addGuest(room, $event)"
                  >
                    <option value="">+ เพิ่มคนเข้าห้องนี้</option>
                    <option v-for="p in unassignedOf(stay)" :key="p.passenger_id" :value="p.passenger_id">
                      {{ p.full_name }}{{ p.name !== firstWord(p.full_name) ? ` (${p.name})` : '' }}
                    </option>
                  </select>
                </div>
              </div>
            </section>
          </template>
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

// จัดห้องพักของรอบ (ฝั่งหลังบ้าน) — ใช้ API ชุดเดียวกับแอปสตาฟ
// (ScheduleRoomController) ทุกคำสั่งตอบ payload ทั้งก้อนกลับมา จึงแทนที่ทั้งหน้า
// ไม่ต้องประกอบสถานะเอง

const toast = useToast();
const swal = useSwal();

const schedules = ref([]);
const loadingList = ref(false);
const search = ref('');
const upcomingOnly = ref(true);
const activeId = ref(null);
const activeSchedule = ref(null);

const data = ref(null);
const loading = ref(false);
const busy = ref(false);
const panel = ref(null);
const autoForm = ref({ room_size: 2, prefix: 'ห้อง', stay_label: '' });
const addForm = ref({ name: '', note: '', stay_label: '' });
const editingId = ref(null);
const editForm = ref({ name: '', note: '' });
const dropTarget = ref(null);

const rooms = computed(() => data.value?.rooms || []);

// ที่พักที่มีห้องอยู่ — ยังไม่มีห้องเลยก็ถือว่ามีที่พักเดียวที่ไม่มีชื่อ
const stays = computed(() => {
  const list = data.value?.stays || [];
  return list.length ? list : [null];
});
const namedStays = computed(() => stays.value.filter((s) => s));

const roomsOf = (stay) => rooms.value.filter((r) => (r.stay_label ?? null) === (stay ?? null));
const unassignedOf = (stay) =>
  (data.value?.unassigned || []).find((u) => (u.stay_label ?? null) === (stay ?? null))?.passengers || [];

const unassignedCount = computed(() =>
  (data.value?.unassigned || []).reduce((n, u) => n + (u.passengers?.length || 0), 0),
);
const progressText = computed(() => {
  const total = data.value?.total_passengers || 0;
  if (!total) return 'ยังไม่มีผู้เดินทาง';
  if (!unassignedCount.value) return `ทุกคนมีห้องแล้ว (${total} คน)`;
  return `ยังไม่มีห้อง ${unassignedCount.value} คน จาก ${total} คน${stays.value.length > 1 ? ' (นับทุกที่พัก)' : ''}`;
});

const firstWord = (s) => String(s || '').trim().split(/\s+/)[0];

// ─── รายชื่อรอบ (เหมือนหน้ากำหนดการ) ─────────────────

function dateVal(d) {
  if (!d) return Infinity;
  const t = new Date(d).getTime();
  return Number.isNaN(t) ? Infinity : t;
}
const startOfToday = () => {
  const n = new Date();
  return new Date(n.getFullYear(), n.getMonth(), n.getDate()).getTime();
};
function compareUpcoming(a, b) {
  const now = startOfToday();
  const ta = dateVal(a);
  const tb = dateVal(b);
  const aUp = ta >= now;
  const bUp = tb >= now;
  if (aUp !== bUp) return aUp ? -1 : 1;
  return aUp ? ta - tb : tb - ta;
}
const sortedSchedules = computed(() => {
  const q = search.value.trim().toLowerCase();
  const list = q
    ? schedules.value.filter((s) => (s.trip?.title || '').toLowerCase().includes(q))
    : schedules.value;
  const now = startOfToday();
  return list
    .map((s) => ({ ...s, isPast: dateVal(s.departure_date) < now }))
    .sort((a, b) => compareUpcoming(a.departure_date, b.departure_date));
});
function countdownLabel(d) {
  const t = dateVal(d);
  if (t === Infinity) return 'ยังไม่ระบุวัน';
  const date = new Date(d);
  const days = Math.round(
    (new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime() - startOfToday()) / 86400000,
  );
  if (days === 0) return 'วันนี้';
  if (days === 1) return 'พรุ่งนี้';
  if (days > 1) return `อีก ${days} วัน`;
  if (days === -1) return 'เมื่อวาน';
  return `ผ่านมาแล้ว ${Math.abs(days)} วัน`;
}
function formatShort(d) {
  if (!d) return '';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}
function formatDateRange(from, to) {
  const f = formatShort(from);
  const t = formatShort(to);
  if (!f) return '-';
  if (!t || t === f) return f;
  return `${f} - ${t}`;
}

async function loadSchedules() {
  loadingList.value = true;
  try {
    // /admin/schedules แบ่งหน้า (เพดานหน้าละ 100) — ไล่ให้ครบทุกหน้า
    const all = [];
    let page = 1;
    let lastPage = 1;
    do {
      const params = { per_page: 100, page };
      if (upcomingOnly.value) params.upcoming = 1;
      const res = await api.get('/admin/schedules', { params });
      all.push(...(res.data.data || []));
      lastPage = res.data.meta?.last_page || 1;
      page += 1;
    } while (page <= lastPage && page <= 50);
    schedules.value = all;
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
  data.value = null;
  panel.value = null;
  editingId.value = null;
  await loadRooms();
}

async function loadRooms() {
  const id = activeId.value;
  loading.value = true;
  try {
    const res = await api.get(`/schedules/${id}/rooms`);
    // ผู้ใช้กดไปรอบอื่นระหว่างรอ — ทิ้งผลของรอบเก่า
    if (id === activeId.value) data.value = res.data.data;
  } catch (e) {
    toast.error(e.response?.data?.message || 'โหลดห้องพักไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

/** เรียก API ที่ตอบ payload ทั้งก้อน แล้วแทนที่หน้า */
async function run(request, done) {
  if (busy.value) return false;
  const id = activeId.value;
  busy.value = true;
  try {
    const res = await request();
    if (id === activeId.value) data.value = res.data.data;
    if (done) toast.success(typeof done === 'function' ? done(res) : done);
    return true;
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
    return false;
  } finally {
    busy.value = false;
  }
}

const base = () => `/schedules/${activeId.value}/rooms`;
const guestIds = (room) => room.guests.map((g) => g.passenger_id);
const setGuests = (room, ids) => run(() => api.put(`${base()}/${room.id}/guests`, { passenger_ids: ids }));

function togglePanel(name) {
  panel.value = panel.value === name ? null : name;
  if (name === 'auto' && namedStays.value.length === 1 && !autoForm.value.stay_label) {
    autoForm.value.stay_label = namedStays.value[0];
  }
}

async function autoAssign() {
  const ok = await run(
    () => api.post(`${base()}/auto`, {
      room_size: autoForm.value.room_size,
      prefix: autoForm.value.prefix.trim() || 'ห้อง',
      stay_label: autoForm.value.stay_label.trim() || null,
    }),
    (res) => res.data.message || 'จัดห้องแล้ว',
  );
  if (ok) panel.value = null;
}

async function addRoom() {
  const ok = await run(
    () => api.post(base(), {
      name: addForm.value.name.trim(),
      note: addForm.value.note.trim() || null,
      stay_label: addForm.value.stay_label.trim() || null,
    }),
    'เพิ่มห้องแล้ว',
  );
  if (ok) addForm.value = { name: '', note: '', stay_label: addForm.value.stay_label };
}

function startEdit(room) {
  editingId.value = room.id;
  editForm.value = { name: room.name, note: room.note || '' };
}

async function saveEdit(room) {
  const ok = await run(() => api.put(`${base()}/${room.id}`, {
    name: editForm.value.name.trim(),
    note: editForm.value.note.trim() || null,
  }));
  if (ok) editingId.value = null;
}

async function removeRoom(room) {
  const res = await swal.confirm({
    title: `ลบ${room.name}?`,
    text: room.guests.length ? `${room.guests.length} คนในห้องนี้จะกลับไปอยู่ในรายชื่อที่ยังไม่มีห้อง` : '',
    icon: 'warning',
    confirmText: 'ลบห้อง',
  });
  if (!res.isConfirmed) return;
  await run(() => api.delete(`${base()}/${room.id}`), 'ลบห้องแล้ว');
}

function removeGuest(room, pid) {
  return setGuests(room, guestIds(room).filter((id) => id !== pid));
}

function addGuest(room, event) {
  const pid = Number(event.target.value);
  event.target.value = '';
  if (!pid) return;
  return setGuests(room, [...guestIds(room), pid]);
}

// ─── ลากวาง ───────────────────────────────────────────

function dragStart(event, pid, fromRoomId) {
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', JSON.stringify({ pid, fromRoomId }));
}

function readDrag(event) {
  dropTarget.value = null;
  try {
    return JSON.parse(event.dataTransfer.getData('text/plain'));
  } catch {
    return null;
  }
}

function dropOnRoom(room, event) {
  const drag = readDrag(event);
  if (!drag?.pid || guestIds(room).includes(drag.pid)) return;
  // ย้ายในที่พักเดียวกัน เซิร์ฟเวอร์เอาออกจากห้องเดิมให้เอง
  setGuests(room, [...guestIds(room), drag.pid]);
}

function dropOnUnassigned(stay, event) {
  const drag = readDrag(event);
  if (!drag?.fromRoomId) return;
  const room = rooms.value.find((r) => r.id === drag.fromRoomId);
  // ปล่อยลงช่องของที่พักอื่น = ไม่ใช่การเอาออกจากห้องนี้
  if (!room || (room.stay_label ?? null) !== (stay ?? null)) return;
  removeGuest(room, drag.pid);
}

// ─── ประกาศ / คัดลอก ──────────────────────────────────

async function announce(stay) {
  const res = await swal.confirm({
    title: 'ประกาศห้องพักในแชท?',
    text: 'รายชื่อทุกห้องจะขึ้นในห้องแชทของรอบนี้ และลูกทริปทุกคนได้แจ้งเตือนว่าพักห้องไหนกับใคร',
    confirmText: 'ประกาศ',
  });
  if (!res.isConfirmed) return;
  await run(
    () => api.post(`${base()}/announce`, { stay_label: stay ?? null }),
    (r) => r.data.message || 'ประกาศแล้ว',
  );
}

// รายชื่อเข้าพักแบบชื่อจริงเต็ม — วางส่งให้ที่พักทาง LINE/อีเมลได้เลย
async function copyForHotel() {
  const title = activeSchedule.value?.trip?.title || 'ทริป';
  const lines = [`รายชื่อผู้เข้าพัก — ${title} (${formatDateRange(activeSchedule.value?.departure_date, activeSchedule.value?.return_date)})`];
  for (const stay of stays.value) {
    const list = roomsOf(stay).filter((r) => r.guests.length);
    if (!list.length) continue;
    if (stay) lines.push('', `[${stay}]`);
    for (const room of list) {
      lines.push('', `${room.name}${room.note ? ` (${room.note})` : ''} — ${room.guests.length} คน`);
      room.guests.forEach((g, i) => lines.push(`  ${i + 1}. ${g.full_name || g.name}`));
    }
  }
  try {
    await navigator.clipboard.writeText(lines.join('\n'));
    toast.success('คัดลอกรายชื่อแล้ว');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ — เบราว์เซอร์ไม่อนุญาตให้เขียนคลิปบอร์ด');
  }
}

loadSchedules();
</script>

<style scoped>
.rooms-page { display: flex; height: calc(100vh - 120px); gap: 16px; }

/* ─── Sidebar (ชุดเดียวกับหน้ากำหนดการ) ─────────────── */
.rooms-sidebar {
  width: 340px; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb;
  display: flex; flex-direction: column; overflow: hidden; flex-shrink: 0;
}
.rooms-sidebar-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px; border-bottom: 1px solid #f0f0f0;
}
.rooms-sidebar-header h2 { font-size: 16px; font-weight: 800; margin: 0; color: #1f2937; }
.rooms-sidebar-header h2 i { color: #2D7A4F; margin-right: 6px; }
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
  font-size: 12.5px; font-weight: 700; color: #6b7280; cursor: pointer; border-bottom: 1px solid #f5f5f5;
}
.upcoming-toggle input { width: 15px; height: 15px; accent-color: #2D7A4F; }
.conv-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; flex: 1; }
.conv-item { display: flex; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background 0.15s; }
.conv-item:hover { background: #f9fafb; }
.conv-item.active { background: #ecfdf5; box-shadow: inset 3px 0 0 #2D7A4F; }
.conv-thumb {
  width: 48px; height: 48px; border-radius: 12px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.conv-item.past .conv-thumb { filter: grayscale(0.5); opacity: 0.75; }
.conv-thumb img { width: 100%; height: 100%; object-fit: cover; }
.conv-content { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.round-date { font-weight: 800; font-size: 13.5px; color: #111827; }
.conv-item.past .round-date { color: #6b7280; }
.conv-title { font-size: 12px; font-weight: 700; color: #4b5563; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.conv-sub { font-size: 11.5px; color: #6b7280; margin-top: 3px; }
.round-countdown { font-weight: 700; color: #2D7A4F; }
.conv-item.past .round-countdown { color: #9ca3af; }
.empty-hint { padding: 24px 16px; color: #9ca3af; font-size: 13px; text-align: center; }

/* ─── Main ─────────────────────────────────────────── */
.rooms-main { flex: 1; min-width: 0; background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; display: flex; flex-direction: column; overflow: hidden; }
.rooms-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #c0c4cc; gap: 10px; text-align: center; padding: 24px; }
.rooms-empty i { font-size: 48px; }
.rooms-empty p { margin: 0; font-size: 15px; font-weight: 700; color: #9ca3af; }
.rooms-empty span { font-size: 12.5px; }

.rooms-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
.rooms-header-info { display: flex; align-items: center; gap: 12px; min-width: 0; }
.header-thumb {
  width: 42px; height: 42px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
  background: linear-gradient(135deg, #e7f5ee, #d1f0df);
  display: flex; align-items: center; justify-content: center; color: #2D7A4F;
}
.header-thumb img { width: 100%; height: 100%; object-fit: cover; }
.rooms-header h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.rooms-sub { font-size: 12px; color: #6b7280; }
.rooms-sub .ok { color: #2D7A4F; }
.rooms-sub .warn { color: #b45309; }
.header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.tool-btn {
  border: 1px solid #e5e7eb; background: #fff; color: #374151; border-radius: 10px;
  padding: 8px 12px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.tool-btn:hover:not(:disabled) { background: #f9fafb; }
.tool-btn.on { border-color: #2D7A4F; color: #2D7A4F; background: #ecfdf5; }
.tool-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.rooms-scroll { flex: 1; overflow-y: auto; padding: 20px; background: #fafafa; }

.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; margin-bottom: 20px; }
.panel-title { font-size: 14px; font-weight: 800; color: #1f2937; margin-bottom: 6px; }
.panel-title i { color: #2D7A4F; margin-right: 6px; }
.panel-hint { margin: 0 0 12px; font-size: 12.5px; color: #6b7280; line-height: 1.5; }
.field-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 180px; }
.field-sm { flex: 0 0 160px; min-width: 140px; }
.field label { font-size: 12px; font-weight: 700; color: #6b7280; }
.input { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 9px 12px; font-size: 14px; outline: none; font-family: inherit; background: #fff; }
.input:focus { border-color: #2D7A4F; }
.panel-actions { display: flex; align-items: center; gap: 10px; }
.primary-btn {
  border: none; background: #2D7A4F; color: #fff; border-radius: 10px; padding: 10px 18px;
  font-size: 14px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
}
.primary-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
.ghost-btn { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; border-radius: 10px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
.primary-btn.sm, .ghost-btn.sm { padding: 7px 12px; font-size: 13px; }

.intro { display: flex; align-items: center; gap: 14px; background: #fff; border: 1px dashed #cbd5e1; border-radius: 14px; padding: 18px; margin-bottom: 20px; }
.intro > i { font-size: 28px; color: #2D7A4F; }
.intro p { margin: 4px 0 0; font-size: 13px; color: #6b7280; line-height: 1.5; }
.intro > div { flex: 1; }

.stay { margin-bottom: 28px; }
.stay-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.stay-head h4 { margin: 0; font-size: 15px; font-weight: 800; color: #111827; }
.muted { color: #9ca3af; font-size: 12.5px; font-weight: 600; }
.spacer { flex: 1; }
.announce-btn {
  border: none; background: #ecfdf5; color: #2D7A4F; border-radius: 10px; padding: 8px 12px;
  font-size: 13px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.announce-btn:hover:not(:disabled) { background: #d1fae5; }
.announce-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.unassigned { background: #fffbeb; border: 1px dashed #fcd34d; border-radius: 12px; padding: 12px; margin-bottom: 12px; transition: background 0.15s; }
.unassigned.dropping { background: #fef3c7; border-style: solid; }
.unassigned-title { font-size: 12.5px; font-weight: 800; color: #92400e; margin-bottom: 8px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip {
  display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #e5e7eb;
  border-radius: 999px; padding: 5px 10px; font-size: 13px; font-weight: 700; color: #374151; cursor: grab;
}
.chip small, .guest-name small { color: #9ca3af; font-weight: 600; }
.grip { color: #cbd5e1; font-size: 11px; }
.g { font-style: normal; font-size: 11px; font-weight: 800; border-radius: 6px; padding: 0 5px; }
.g.m { background: #eff6ff; color: #2563eb; }
.g.f { background: #fdf2f8; color: #db2777; }

.room-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.room-card { background: #fff; border: 1px solid #e9edf0; border-radius: 14px; padding: 12px; display: flex; flex-direction: column; gap: 8px; transition: border-color 0.15s, background 0.15s; }
.room-card.dropping { border-color: #2D7A4F; background: #f0fdf4; }
.room-head { display: flex; align-items: center; gap: 6px; }
.room-name { flex: 1; min-width: 0; font-size: 14px; font-weight: 800; color: #111827; }
.room-name i { color: #2D7A4F; margin-right: 4px; }
.room-note { display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-top: 2px; }
.room-count { font-size: 12px; font-weight: 700; color: #6b7280; }
.room-edit { display: flex; flex-direction: column; gap: 6px; }
.mini-btn { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; width: 28px; height: 28px; border-radius: 8px; cursor: pointer; font-size: 12px; }
.mini-btn:hover { background: #f3f4f6; color: #374151; }
.mini-btn.danger:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.guest-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.guest-list li { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; background: #f9fafb; cursor: grab; font-size: 13.5px; font-weight: 700; color: #1f2937; }
.guest-list li.guest-empty { background: none; cursor: default; color: #9ca3af; font-weight: 600; font-size: 12.5px; }
.guest-name { flex: 1; min-width: 0; }
.x-btn { border: none; background: none; color: #9ca3af; cursor: pointer; padding: 2px 4px; }
.x-btn:hover { color: #dc2626; }
.add-select { font-size: 13px; padding: 7px 10px; color: #4b5563; }

@media (max-width: 900px) {
  .rooms-page { flex-direction: column; height: auto; }
  .rooms-sidebar { width: 100%; max-height: 320px; }
  .rooms-main { min-height: 70vh; }
}
</style>
