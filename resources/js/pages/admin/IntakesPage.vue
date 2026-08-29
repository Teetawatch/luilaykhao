<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">contact_mail</span>
          ข้อมูลลูกค้าจากลิงก์
        </h1>
        <p class="page-subtitle">
          ลูกค้าที่ทักมาทางไลน์/เฟส/ไอจี กรอกข้อมูลเองผ่านลิงก์ แล้วดึงมาเปิดการจองได้เลย
          ไม่ต้องพิมพ์ตามในแชท
        </p>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" :disabled="loading" @click="fetchAll">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span>
          {{ loading ? 'กำลังโหลด' : 'รีเฟรช' }}
        </button>
      </div>
    </div>

    <!-- ── ลิงก์ที่ส่งให้ลูกค้า ─────────────────────────────────────── -->
    <section class="panel">
      <header class="panel-head">
        <div>
          <h2>ลิงก์สำหรับส่งให้ลูกค้า</h2>
          <p>สร้างครั้งเดียว ใช้ซ้ำได้ไม่จำกัดคน แปะไว้ใน auto-reply ของไลน์หรือไบโอไอจีได้</p>
        </div>
        <button class="btn-primary btn-sm" @click="showLinkForm = !showLinkForm">
          <span class="material-symbols-rounded" style="font-size:16px">{{ showLinkForm ? 'close' : 'add_link' }}</span>
          {{ showLinkForm ? 'ปิด' : 'สร้างลิงก์ใหม่' }}
        </button>
      </header>

      <div v-if="showLinkForm" class="link-form">
        <div class="form-block">
          <label class="block-label">ลิงก์นี้ใช้กับอะไร</label>
          <div class="mode-cards">
            <button
              type="button"
              class="mode-card"
              :class="{ active: linkMode === 'general' }"
              @click="linkMode = 'general'; newLink.trip_schedule_id = ''"
            >
              <span class="material-symbols-rounded">public</span>
              <span class="mode-text">
                <strong>ลิงก์กลาง</strong>
                <small>ลูกค้าเลือกรอบเอง — แปะไบโอไอจี/auto-reply ได้ยาว ๆ</small>
              </span>
            </button>
            <button
              type="button"
              class="mode-card"
              :class="{ active: linkMode === 'schedule' }"
              @click="linkMode = 'schedule'"
            >
              <span class="material-symbols-rounded">event_available</span>
              <span class="mode-text">
                <strong>เจาะจงรอบเดินทาง</strong>
                <small>ลูกค้ากรอกเข้ารอบนี้เลย ไม่ต้องเลือกวันเอง</small>
              </span>
            </button>
          </div>
        </div>

        <!-- รอบเยอะเกินกว่าจะไล่อ่านใน dropdown — เลือกจากรูป + วันที่ + ที่นั่งที่เหลือ -->
        <div v-if="linkMode === 'schedule'" class="form-block">
          <div class="picker-head">
            <label class="block-label">เลือกรอบเดินทาง</label>
            <span class="picker-count">{{ filteredSchedules.length }} รอบข้างหน้า</span>
          </div>

          <div class="picker-filters">
            <div class="search-box">
              <span class="material-symbols-rounded">search</span>
              <input v-model.trim="scheduleSearch" type="text" placeholder="พิมพ์ชื่อทริป เช่น ภูกระดึง" />
              <button v-if="scheduleSearch" class="btn-icon clear-search" @click="scheduleSearch = ''">
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
            <div v-if="scheduleMonths.length > 1" class="month-strip">
              <button class="month-chip" :class="{ active: !monthFilter }" @click="monthFilter = ''">
                ทุกเดือน
              </button>
              <button
                v-for="month in scheduleMonths"
                :key="month.key"
                class="month-chip"
                :class="{ active: monthFilter === month.key }"
                @click="monthFilter = monthFilter === month.key ? '' : month.key"
              >
                {{ month.label }} <span class="month-count">{{ month.count }}</span>
              </button>
            </div>
          </div>

          <div v-if="!scheduleOptions.length" class="empty-inline">ยังไม่มีรอบเดินทางข้างหน้า</div>
          <div v-else-if="!filteredSchedules.length" class="empty-inline">ไม่พบรอบที่ตรงกับที่ค้นหา</div>
          <div v-else class="schedule-grid">
            <button
              v-for="option in filteredSchedules"
              :key="option.id"
              type="button"
              class="sched-card"
              :class="{ active: newLink.trip_schedule_id === option.id }"
              @click="newLink.trip_schedule_id = option.id"
            >
              <img v-if="option.image" :src="option.image" alt="" class="sched-thumb" />
              <span v-else class="sched-thumb fallback material-symbols-rounded">hiking</span>
              <span class="sched-body">
                <strong>{{ option.tripTitle }}</strong>
                <span class="sched-date">
                  <span class="material-symbols-rounded">calendar_month</span>
                  {{ option.dateLabel }}
                </span>
                <span class="sched-meta">
                  <span class="status-badge" :class="`status-${option.status}`">
                    {{ statusLabels[option.status] || option.status }}
                  </span>
                  <span class="seat-note" :class="{ tight: option.seatsTight }">{{ option.seatLabel }}</span>
                </span>
              </span>
              <span class="material-symbols-rounded tick">
                {{ newLink.trip_schedule_id === option.id ? 'check_circle' : 'radio_button_unchecked' }}
              </span>
            </button>
          </div>
        </div>

        <div class="form-block form-foot">
          <div class="filter-field" style="flex:1;min-width:200px;">
            <label>ป้ายกำกับ (ไว้ให้เรารู้เอง)</label>
            <input v-model.trim="newLink.label" type="text" placeholder="เช่น ไลน์ OA, ไอจี" />
          </div>
          <button
            class="btn-primary"
            :disabled="creatingLink || (linkMode === 'schedule' && !newLink.trip_schedule_id)"
            @click="createLink"
          >
            {{ creatingLink ? 'กำลังสร้าง…' : 'สร้างลิงก์' }}
          </button>
        </div>
      </div>

      <div v-if="!links.length" class="empty-inline">ยังไม่มีลิงก์ — กด "สร้างลิงก์ใหม่" เพื่อเริ่ม</div>

      <table v-else class="data-table">
        <thead>
          <tr>
            <th>ลิงก์</th>
            <th>รอบเดินทาง</th>
            <th>ใช้ไปแล้ว</th>
            <th style="text-align:right;">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="link in links" :key="link.id" :class="{ inactive: !link.is_active }">
            <td>
              <strong>{{ link.label || 'ลิงก์เก็บข้อมูล' }}</strong>
              <div class="cell-sub link-url">{{ link.url }}</div>
            </td>
            <td>
              <div v-if="link.trip_schedule_id" class="sched-chip">
                <img v-if="link.schedule_image" :src="link.schedule_image" alt="" class="chip-thumb" />
                <span v-else class="chip-thumb fallback material-symbols-rounded">hiking</span>
                <span class="chip-text">
                  <strong>{{ link.schedule_trip_title || 'รอบเดินทาง' }}</strong>
                  <span class="cell-sub">{{ thaiShort(link.schedule_departure_date) || link.schedule_label }}</span>
                </span>
              </div>
              <div v-else class="sched-chip general">
                <span class="chip-thumb fallback material-symbols-rounded">public</span>
                <span class="chip-text">
                  <strong>ลิงก์กลาง</strong>
                  <span class="cell-sub">ลูกค้าเลือกรอบเอง</span>
                </span>
              </div>
            </td>
            <td>
              <span class="progress-pill">{{ link.intakes_count }} กลุ่ม</span>
              <div class="cell-sub">{{ link.last_used_at ? formatDateTime(link.last_used_at) : 'ยังไม่มีใครกรอก' }}</div>
            </td>
            <td style="text-align:right;white-space:nowrap;">
              <button class="btn-primary btn-sm" @click="copy(link.url, `link-${link.id}`)">
                <span class="material-symbols-rounded" style="font-size:16px">
                  {{ copiedKey === `link-${link.id}` ? 'check' : 'content_copy' }}
                </span>
                {{ copiedKey === `link-${link.id}` ? 'คัดลอกแล้ว' : 'คัดลอก' }}
              </button>
              <button class="btn-secondary btn-sm" @click="toggleLink(link)">
                {{ link.is_active ? 'ปิดลิงก์' : 'เปิดลิงก์' }}
              </button>
              <button class="btn-danger btn-sm" @click="deleteLink(link)">
                <span class="material-symbols-rounded" style="font-size:16px">delete</span>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ── ข้อมูลที่กรอกเข้ามา ──────────────────────────────────────── -->
    <div class="filter-bar">
      <div class="status-tabs">
        <button
          v-for="tab in statusTabs"
          :key="tab.value"
          class="status-tab"
          :class="{ active: status === tab.value }"
          @click="status = tab.value; fetchIntakes()"
        >
          {{ tab.label }}
          <span v-if="tab.value === 'new' && newCount" class="tab-count">{{ newCount }}</span>
        </button>
      </div>
      <div class="filter-field" style="flex:1;min-width:220px;">
        <label>ค้นหา</label>
        <input v-model.trim="search" type="text" placeholder="ชื่อลูกค้า / เบอร์โทร" @keyup.enter="fetchIntakes" />
      </div>
    </div>

    <!-- ลูกค้าที่มาด้วยกันมักกดลิงก์คนละครั้งจนกลายเป็นคนละกลุ่ม แต่ต้องขึ้นรถคันเดียวกัน
         เลือกหลายกลุ่มแล้วดึงไปเปิดเป็นการจองใบเดียว จะได้เลือกที่นั่งพร้อมกัน -->
    <div v-if="selectedIntakes.length" class="bulk-bar">
      <div class="bulk-info">
        <strong>เลือกไว้ {{ selectedIntakes.length }} กลุ่ม · {{ selectedPeopleCount }} คน</strong>
        <span v-if="selectedScheduleLabel">{{ selectedScheduleLabel }}</span>
        <span v-if="mixedSchedules" class="bulk-warn">
          <span class="material-symbols-rounded">warning</span>
          กลุ่มที่เลือกอยู่คนละรอบเดินทาง — จะใช้รอบของกลุ่มแรกให้
        </span>
      </div>
      <button class="btn-secondary btn-sm" @click="selectedIds = []">ล้างที่เลือก</button>
      <button class="btn-primary btn-sm" @click="pullSelectedIntoBooking">
        <span class="material-symbols-rounded" style="font-size:16px">event_seat</span>
        ดึงไปจองรวมกัน ({{ selectedPeopleCount }} คน)
      </button>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div v-else>
        <div v-if="!intakes.length" class="empty-state">
          <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">
            inbox
          </span>
          ยังไม่มีข้อมูลลูกค้าในหมวดนี้
        </div>

        <table v-else class="data-table">
          <thead>
            <tr>
              <th class="pick-col">
                <input
                  type="checkbox"
                  :checked="allPickableSelected"
                  :disabled="!pickableIntakes.length"
                  @change="toggleSelectAll($event.target.checked)"
                />
              </th>
              <th>ลูกค้า</th>
              <th>ทริปที่สนใจ</th>
              <th>กรอกแล้ว</th>
              <th>เข้ามาเมื่อ</th>
              <th style="text-align:right;">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="intake in intakes" :key="intake.id" :class="{ picked: selectedIds.includes(intake.id) }">
              <td class="pick-col">
                <input
                  type="checkbox"
                  :disabled="intake.status !== 'new'"
                  :checked="selectedIds.includes(intake.id)"
                  @change="toggleSelect(intake)"
                />
              </td>
              <td>
                <strong>{{ intake.contact_name }}</strong>
                <div class="cell-sub">
                  {{ intake.contact_phone || 'ไม่มีเบอร์' }}
                  <template v-if="intake.source"><span class="dot">·</span>{{ sourceLabel(intake.source) }}</template>
                </div>
                <div v-if="intake.note_excerpt" class="note-line">“{{ intake.note_excerpt }}”</div>
              </td>
              <td>
                {{ intake.schedule_label || 'ยังไม่ระบุรอบ' }}
                <div v-if="intake.link_label" class="cell-sub">มาจาก {{ intake.link_label }}</div>
              </td>
              <td>
                <span class="progress-pill" :class="{ done: intake.filled_count >= intake.party_size }">
                  {{ intake.filled_count }}/{{ intake.party_size }} คน
                </span>
                <div v-if="intake.filled_count < intake.party_size" class="cell-sub waiting">
                  รอเพื่อนอีก {{ intake.party_size - intake.filled_count }} คน
                </div>
              </td>
              <td>
                {{ formatDateTime(intake.created_at) }}
                <div v-if="intake.last_activity_at !== intake.created_at" class="cell-sub">
                  อัปเดต {{ formatDateTime(intake.last_activity_at) }}
                </div>
              </td>
              <td style="text-align:right;white-space:nowrap;">
                <button class="btn-secondary btn-sm" @click="openDetail(intake)">
                  <span class="material-symbols-rounded" style="font-size:16px">visibility</span>
                  ดูข้อมูล
                </button>
                <button
                  v-if="intake.status === 'new'"
                  class="btn-primary btn-sm"
                  @click="pullIntoBooking(intake)"
                >
                  <span class="material-symbols-rounded" style="font-size:16px">event_seat</span>
                  ดึงไปจอง
                </button>
                <span v-else class="status-chip" :class="intake.status">
                  {{ intake.status === 'booked' ? 'จองแล้ว' : 'เก็บเข้ากรุ' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── รายละเอียดกลุ่ม ──────────────────────────────────────────── -->
    <div v-if="detail" class="drawer-backdrop" @click.self="detail = null">
      <aside class="drawer">
        <header class="drawer-head">
          <div>
            <h2>{{ detail.contact_name }}</h2>
            <p>{{ detail.schedule_label || 'ยังไม่ระบุรอบ' }}</p>
          </div>
          <button class="btn-icon" @click="detail = null">
            <span class="material-symbols-rounded">close</span>
          </button>
        </header>

        <div class="drawer-body">
          <div class="drawer-row">
            <label>ลิงก์ของกลุ่ม (ให้ลูกค้าส่งต่อให้เพื่อน)</label>
            <div class="copy-row">
              <input type="text" readonly :value="detail.group_url" @focus="$event.target.select()" />
              <button class="btn-secondary btn-sm" @click="copy(detail.group_url, 'group')">
                {{ copiedKey === 'group' ? 'คัดลอกแล้ว' : 'คัดลอก' }}
              </button>
            </div>
          </div>

          <div v-if="detail.note" class="drawer-row">
            <label>ลูกค้าฝากบอก</label>
            <p class="note-box">{{ detail.note }}</p>
          </div>

          <div class="drawer-row">
            <label>ผู้เดินทางที่กรอกแล้ว ({{ detail.people.length }}/{{ detail.party_size }})</label>
            <ul class="people">
              <li v-for="person in detail.people" :key="person.id">
                <div>
                  <strong>{{ person.name }}</strong>
                  <span v-if="person.is_lead" class="lead-chip">คนติดต่อ</span>
                  <div class="cell-sub">{{ person.phone || 'ไม่มีเบอร์' }}</div>
                </div>
                <button class="btn-danger btn-sm" @click="removePerson(person)">
                  <span class="material-symbols-rounded" style="font-size:16px">delete</span>
                </button>
              </li>
            </ul>
          </div>
        </div>

        <footer class="drawer-foot">
          <button v-if="detail.status === 'new'" class="btn-primary" @click="pullIntoBooking(detail)">
            <span class="material-symbols-rounded" style="font-size:18px">event_seat</span>
            ดึงไปเปิดการจอง
          </button>
          <button v-if="detail.status === 'new'" class="btn-secondary" @click="archive(detail)">เก็บเข้ากรุ</button>
          <button class="btn-danger" @click="destroy(detail)">ลบข้อมูลกลุ่มนี้</button>
        </footer>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../../lib/axios';
import { thaiShort, thaiDayMonth, THAI_MONTHS_SHORT } from '../../lib/thaiDate';

const router = useRouter();

const statusLabels = { open: 'เปิด', closed: 'ปิด', full: 'เต็ม', cancelled: 'ยกเลิก' };

const statusTabs = [
  { value: 'new', label: 'ยังไม่ได้จอง' },
  { value: 'booked', label: 'จองแล้ว' },
  { value: 'archived', label: 'เก็บเข้ากรุ' },
];

const loading = ref(true);
const links = ref([]);
const intakes = ref([]);
const scheduleOptions = ref([]);
const detail = ref(null);
const status = ref('new');
const search = ref('');
const newCount = ref(0);
const copiedKey = ref(null);
const selectedIds = ref([]);
const showLinkForm = ref(false);
const creatingLink = ref(false);
const newLink = ref({ trip_schedule_id: '', label: '' });
const linkMode = ref('general');
const scheduleSearch = ref('');
const monthFilter = ref('');

// เดือนที่มีรอบจริง เรียงตามปฏิทิน — คีย์เป็น 'YYYY-MM' ตัดจากสตริงวันที่ตรง ๆ
// ไม่ผ่าน Date เพื่อไม่ให้ timezone ดันวันที่ข้ามเดือน
const scheduleMonths = computed(() => {
  const buckets = new Map();
  scheduleOptions.value.forEach((option) => {
    if (!option.monthKey) return;
    buckets.set(option.monthKey, (buckets.get(option.monthKey) ?? 0) + 1);
  });

  return [...buckets.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, count]) => ({ key, count, label: monthLabel(key) }));
});

const filteredSchedules = computed(() => {
  const term = scheduleSearch.value.toLowerCase();

  return scheduleOptions.value.filter((option) => {
    if (monthFilter.value && option.monthKey !== monthFilter.value) return false;
    return !term || option.tripTitle.toLowerCase().includes(term);
  });
});

// เลือกได้เฉพาะกลุ่มที่ยังไม่ถูกดึงไปจอง — กลุ่มที่จองแล้ว/เก็บเข้ากรุ ดึงซ้ำไม่ได้
const pickableIntakes = computed(() => intakes.value.filter((row) => row.status === 'new'));
const selectedIntakes = computed(() => intakes.value.filter((row) => selectedIds.value.includes(row.id)));
const allPickableSelected = computed(
  () => pickableIntakes.value.length > 0 && pickableIntakes.value.every((row) => selectedIds.value.includes(row.id)),
);
// นับ "คนที่กรอกมาแล้ว" ไม่ใช่จำนวนที่แจ้งไว้ — ที่นั่งที่ต้องเลือกคือเท่าที่มีข้อมูลจริง
const selectedPeopleCount = computed(
  () => selectedIntakes.value.reduce((sum, row) => sum + (row.filled_count || 0), 0),
);
const selectedScheduleLabel = computed(() => selectedIntakes.value.find((row) => row.schedule_label)?.schedule_label || '');
const mixedSchedules = computed(
  () => new Set(selectedIntakes.value.map((row) => row.trip_schedule_id ?? null)).size > 1,
);

const toggleSelect = (intake) => {
  if (intake.status !== 'new') return;
  selectedIds.value = selectedIds.value.includes(intake.id)
    ? selectedIds.value.filter((id) => id !== intake.id)
    : [...selectedIds.value, intake.id];
};

const toggleSelectAll = (checked) => {
  selectedIds.value = checked ? pickableIntakes.value.map((row) => row.id) : [];
};

const fetchAll = async () => {
  loading.value = true;
  try {
    await Promise.all([fetchLinks(), fetchIntakes(false), fetchSchedules()]);
  } finally {
    loading.value = false;
  }
};

const fetchLinks = async () => {
  const res = await api.get('/admin/intake-links');
  links.value = res.data?.data ?? [];
};

const fetchIntakes = async (toggleLoading = true) => {
  if (toggleLoading) loading.value = true;
  try {
    const res = await api.get('/admin/intakes', {
      params: { status: status.value, search: search.value || undefined },
    });
    intakes.value = res.data?.data ?? [];
    newCount.value = res.data?.meta?.new_count ?? 0;
    // แถวที่หายไปจากรายการใหม่ต้องไม่ค้างอยู่ในสิ่งที่เลือกไว้
    selectedIds.value = selectedIds.value.filter((id) => intakes.value.some((row) => row.id === id));
  } catch (e) {
    intakes.value = [];
    alert(e.response?.data?.message ?? 'โหลดข้อมูลไม่สำเร็จ');
  } finally {
    if (toggleLoading) loading.value = false;
  }
};

/**
 * รอบข้างหน้าเรียงจากใกล้ที่สุดไปไกล — order=asc สำคัญ เพราะถ้าปล่อยให้เรียง
 * ใหม่→เก่าตามค่าเริ่มต้น การตัดหน้าแรกจะได้รอบที่ไกลที่สุดมาแทนรอบที่ใกล้จะถึง
 */
const fetchSchedules = async () => {
  const res = await api.get('/admin/schedules', { params: { upcoming: 1, per_page: 200, order: 'asc' } });
  scheduleOptions.value = (res.data?.data ?? []).map((schedule) => {
    const available = Number(schedule.available_seats ?? 0);

    return {
      id: schedule.id,
      tripTitle: schedule.trip?.title ?? 'ทริป',
      image: schedule.trip?.thumbnail_image || schedule.trip?.cover_image || null,
      status: schedule.status,
      monthKey: (schedule.departure_date ?? '').slice(0, 7),
      dateLabel: dateRangeLabel(schedule.departure_date, schedule.return_date),
      seatLabel: seatLabel(schedule, available),
      seatsTight: !schedule.is_charter && available <= 3,
    };
  });
};

const seatLabel = (schedule, available) => {
  if (schedule.is_charter) return 'รอบเหมา';
  if (available <= 0) return 'เต็มแล้ว';

  return `ว่าง ${available}/${schedule.total_seats ?? available} ที่`;
};

/** 'ศ. 5 ก.ย. 2569' หรือ 'ศ. 5 – 7 ก.ย. 2569' เมื่อค้างคืน */
const dateRangeLabel = (departure, returnDate) => {
  if (!departure) return 'ยังไม่ระบุวัน';

  const weekday = new Date(departure).toLocaleDateString('th-TH', { weekday: 'short' });
  if (!returnDate || returnDate === departure) return `${weekday} ${thaiShort(departure)}`;

  return `${weekday} ${thaiDayMonth(departure)} – ${thaiShort(returnDate)}`;
};

/** 'ก.ย. 69' จากคีย์ 'YYYY-MM' */
const monthLabel = (key) => {
  const [year, month] = key.split('-');

  return `${THAI_MONTHS_SHORT[Number(month) - 1]} ${String(Number(year) + 543).slice(2)}`;
};

const createLink = async () => {
  creatingLink.value = true;
  try {
    const res = await api.post('/admin/intake-links', {
      trip_schedule_id: newLink.value.trip_schedule_id || null,
      label: newLink.value.label || null,
    });
    links.value.unshift(res.data.data);
    newLink.value = { trip_schedule_id: '', label: '' };
    linkMode.value = 'general';
    scheduleSearch.value = '';
    monthFilter.value = '';
    showLinkForm.value = false;
    // คัดลอกให้เลย เพราะสร้างเสร็จก็ต้องเอาไปวางในแชทอยู่ดี
    await copy(res.data.data.url, `link-${res.data.data.id}`);
  } catch (e) {
    alert(e.response?.data?.message ?? 'สร้างลิงก์ไม่สำเร็จ');
  } finally {
    creatingLink.value = false;
  }
};

const toggleLink = async (link) => {
  const res = await api.put(`/admin/intake-links/${link.id}`, { is_active: !link.is_active });
  Object.assign(link, res.data.data);
};

const deleteLink = async (link) => {
  if (!confirm('ลบลิงก์นี้? ข้อมูลลูกค้าที่กรอกมาแล้วจะยังอยู่ครบ')) return;
  await api.delete(`/admin/intake-links/${link.id}`);
  links.value = links.value.filter((row) => row.id !== link.id);
};

const openDetail = async (intake) => {
  const res = await api.get(`/admin/intakes/${intake.id}`);
  detail.value = res.data.data;
};

const removePerson = async (person) => {
  if (!confirm(`ลบ ${person.name} ออกจากกลุ่มนี้?`)) return;
  await api.delete(`/admin/intakes/${detail.value.id}/people/${person.id}`);
  detail.value.people = detail.value.people.filter((row) => row.id !== person.id);
  detail.value.passengers = detail.value.passengers.filter((row) => row.name !== person.name);
  await fetchIntakes();
};

const archive = async (intake) => {
  await api.put(`/admin/intakes/${intake.id}`, { status: 'archived' });
  detail.value = null;
  await fetchIntakes();
};

const destroy = async (intake) => {
  if (!confirm('ลบข้อมูลลูกค้ากลุ่มนี้ทั้งหมด? กู้คืนไม่ได้')) return;
  await api.delete(`/admin/intakes/${intake.id}`);
  detail.value = null;
  await fetchIntakes();
};

/**
 * ส่งต่อไปหน้า "จองแทนลูกค้า" พร้อม id ของกลุ่ม — หน้านั้นดึงข้อมูลไปเติมฟอร์ม
 * ผู้โดยสารเอง แล้วส่ง intake_id กลับมาตอนบันทึกเพื่อปิดกลุ่มนี้ให้อัตโนมัติ
 */
const pullIntoBooking = (intake) => {
  router.push({ name: 'admin-manual-booking', query: { intake: String(intake.id) } });
};

/** หลายกลุ่ม = การจองใบเดียว ผู้โดยสารรวมกันทุกคน (หน้าปลายทางกรองชื่อซ้ำให้เอง) */
const pullSelectedIntoBooking = () => {
  if (!selectedIds.value.length) return;
  router.push({ name: 'admin-manual-booking', query: { intake: selectedIds.value.join(',') } });
};

const copy = async (text, key) => {
  try {
    await navigator.clipboard.writeText(text);
    copiedKey.value = key;
    setTimeout(() => {
      if (copiedKey.value === key) copiedKey.value = null;
    }, 1800);
  } catch {
    prompt('คัดลอกลิงก์นี้:', text);
  }
};

const sourceLabel = (source) =>
  ({ line: 'LINE', facebook: 'Facebook', instagram: 'Instagram', other: 'อื่น ๆ' }[source] ?? source);

const formatDateTime = (value) => {
  if (!value) return '—';
  try {
    return new Date(value).toLocaleString('th-TH', {
      day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
    });
  } catch {
    return value;
  }
};

onMounted(fetchAll);
</script>

<style scoped>
@import url('./admin-shared.css');

.heading-icon { color: var(--color-accent); font-size: 28px; }

/* คลาสตารางชุดเดียวกับหน้าตามเก็บวันเกิด — ยังไม่ได้อยู่ใน admin-shared.css */
.cell-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
.cell-sub .dot, .dot { margin: 0 5px; color: #d1d5db; }
.progress-pill {
  display: inline-block; font-size: 12px; font-weight: 800;
  color: #b45309; background: #fef3c7; padding: 3px 10px; border-radius: 999px;
}
.progress-pill.done { color: #065f46; background: #d1fae5; }
.btn-sm { font-size: 13px; padding: 7px 12px; }
.btn-secondary.btn-sm, .btn-danger.btn-sm, .btn-primary.btn-sm { margin-left: 6px; }

.panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
}
.panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.panel-head h2 { font-size: 15px; font-weight: 700; }
.panel-head p { font-size: 12.5px; color: #6b7280; margin-top: 2px; }

.link-form {
  display: flex; flex-direction: column; gap: 16px;
  background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
  padding: 14px; margin-bottom: 14px;
}
.form-block { display: flex; flex-direction: column; gap: 8px; }
.form-foot { flex-direction: row; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.block-label { font-size: 11px; font-weight: 700; color: #6b7280; }

/* ── เลือกชนิดลิงก์ ─────────────────────────────────────────────── */
.mode-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; }
.mode-card {
  display: flex; align-items: flex-start; gap: 10px; text-align: left; cursor: pointer;
  background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 12px;
}
.mode-card:hover { border-color: #d1d5db; }
.mode-card.active { border-color: var(--color-accent); background: #f0fdf4; }
.mode-card > .material-symbols-rounded { font-size: 22px; color: #9ca3af; }
.mode-card.active > .material-symbols-rounded { color: var(--color-accent); }
.mode-text { display: flex; flex-direction: column; gap: 2px; }
.mode-text strong { font-size: 13.5px; color: #111827; }
.mode-text small { font-size: 11.5px; color: #6b7280; line-height: 1.4; }

/* ── ตัวเลือกรอบเดินทางแบบเห็นภาพ ───────────────────────────────── */
.picker-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.picker-count { font-size: 11.5px; color: #9ca3af; }
.picker-filters { display: flex; flex-direction: column; gap: 8px; }
.search-box { position: relative; display: flex; align-items: center; }
.search-box > .material-symbols-rounded {
  position: absolute; left: 10px; font-size: 18px; color: #9ca3af; pointer-events: none;
}
.search-box input { width: 100%; padding-left: 34px; padding-right: 34px; }
.clear-search { position: absolute; right: 4px; }
.clear-search .material-symbols-rounded { font-size: 18px; color: #9ca3af; }

.month-strip { display: flex; flex-wrap: wrap; gap: 6px; }
.month-chip {
  display: inline-flex; align-items: center; gap: 5px; cursor: pointer;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 999px;
  padding: 5px 11px; font-size: 12.5px; font-weight: 600; color: #4b5563;
}
.month-chip.active { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.month-count { font-size: 11px; color: #9ca3af; }
.month-chip.active .month-count { color: rgba(255, 255, 255, .75); }

.schedule-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(268px, 1fr)); gap: 8px;
  max-height: 340px; overflow-y: auto; padding: 2px;
}
.sched-card {
  display: flex; align-items: center; gap: 10px; text-align: left; cursor: pointer;
  background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 8px;
}
.sched-card:hover { border-color: #d1d5db; }
.sched-card.active { border-color: var(--color-accent); background: #f0fdf4; }
.sched-thumb {
  width: 46px; height: 46px; flex-shrink: 0; border-radius: 8px;
  object-fit: cover; background: #f3f4f6;
}
.sched-thumb.fallback {
  display: flex; align-items: center; justify-content: center; font-size: 22px; color: #9ca3af;
}
.sched-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.sched-body strong {
  font-size: 13px; color: #111827;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sched-date { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--color-accent); font-weight: 600; }
.sched-date .material-symbols-rounded { font-size: 14px; }
.sched-meta { display: flex; align-items: center; gap: 6px; }
.seat-note { font-size: 11.5px; color: #6b7280; }
.seat-note.tight { color: #b45309; font-weight: 600; }
.sched-card .tick { font-size: 20px; color: #d1d5db; flex-shrink: 0; }
.sched-card.active .tick { color: var(--color-accent); }

/* ── รอบที่ลิงก์ผูกอยู่ ในตาราง ─────────────────────────────────── */
.sched-chip { display: flex; align-items: center; gap: 8px; }
.chip-thumb { width: 34px; height: 34px; flex-shrink: 0; border-radius: 7px; object-fit: cover; background: #f3f4f6; }
.chip-thumb.fallback { display: flex; align-items: center; justify-content: center; font-size: 18px; color: #9ca3af; }
.chip-text { display: flex; flex-direction: column; }
.sched-chip strong { font-size: 13px; }
.sched-chip.general strong { color: #6b7280; font-weight: 600; }
.link-url { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; word-break: break-all; }
tr.inactive { opacity: .5; }

.filter-bar {
  display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-bottom: 16px;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px;
}
.filter-field { display: flex; flex-direction: column; gap: 4px; }
.filter-field label { font-size: 11px; font-weight: 700; color: #6b7280; }

.status-tabs { display: flex; gap: 6px; }
.status-tab {
  border: 1px solid #e5e7eb; background: #fff; border-radius: 999px;
  padding: 7px 14px; font-size: 13px; font-weight: 600; color: #4b5563; cursor: pointer;
}
.status-tab.active { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.tab-count {
  display: inline-block; margin-left: 6px; background: #ef4444; color: #fff;
  border-radius: 999px; padding: 0 6px; font-size: 11px;
}

.note-line { font-size: 12px; color: #6b7280; margin-top: 4px; font-style: italic; }
.waiting { color: #b45309; }
.status-chip { font-size: 12px; font-weight: 600; color: #6b7280; }
.status-chip.booked { color: #047857; }
.empty-inline { font-size: 13px; color: #9ca3af; padding: 12px 2px; }

.drawer-backdrop {
  position: fixed; inset: 0; background: rgba(15, 23, 42, .45);
  display: flex; justify-content: flex-end; z-index: 60;
}
.drawer { width: min(460px, 100%); background: #fff; display: flex; flex-direction: column; }
.drawer-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 12px; padding: 18px; border-bottom: 1px solid #e5e7eb;
}
.drawer-head h2 { font-size: 17px; font-weight: 700; }
.drawer-head p { font-size: 12.5px; color: #6b7280; margin-top: 2px; }
.drawer-body { flex: 1; overflow-y: auto; padding: 18px; }
.drawer-row { margin-bottom: 20px; }
.drawer-row > label { display: block; font-size: 11px; font-weight: 700; color: #6b7280; margin-bottom: 6px; }
.copy-row { display: flex; gap: 8px; }
.copy-row input { flex: 1; font-size: 12px; }
.note-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; font-size: 13px; white-space: pre-wrap; }
.people { list-style: none; }
.people li {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 10px 0; border-bottom: 1px solid #f3f4f6;
}
.lead-chip {
  margin-left: 6px; font-size: 10.5px; font-weight: 700; color: #047857;
  background: #ecfdf5; border-radius: 999px; padding: 2px 7px;
}
.drawer-foot { display: flex; flex-wrap: wrap; gap: 8px; padding: 16px 18px; border-top: 1px solid #e5e7eb; }

/* ── แถบ "เลือกไว้กี่กลุ่ม" เหนือตาราง ─────────────────────────── */
.bulk-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  padding: 12px 16px;
  border: 1px solid #bbf7d0;
  border-radius: 12px;
  background: #f0fdf4;
}
.bulk-info { flex: 1; min-width: 220px; display: flex; flex-direction: column; gap: 2px; }
.bulk-info strong { font-size: 14px; color: #065f46; }
.bulk-info span { font-size: 12.5px; color: #047857; }
.bulk-warn { display: flex; align-items: center; gap: 4px; color: #b45309; }
.bulk-warn .material-symbols-rounded { font-size: 16px; }
.pick-col { width: 36px; text-align: center; }
.pick-col input { width: 16px; height: 16px; accent-color: #059669; cursor: pointer; }
.data-table tr.picked { background: #f0fdf4; }
</style>