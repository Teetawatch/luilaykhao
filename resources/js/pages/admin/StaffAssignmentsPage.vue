<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">badge</span> จัดการสตาฟประจำรอบ</h1>
        <p class="page-subtitle">กำหนดว่าสตาฟคนไหนดูแลทริปไหนในแต่ละรอบเดินทาง</p>
      </div>
      <div class="header-actions">
        <div class="tab-switcher">
          <button :class="['tab-btn', { active: activeTab === 'assign' }]" @click="activeTab = 'assign'">
            <span class="material-symbols-rounded">assignment_ind</span> มอบหมายสตาฟ
          </button>
          <button :class="['tab-btn', { active: activeTab === 'roster' }]" @click="activeTab = 'roster'; loadRoster()">
            <span class="material-symbols-rounded">calendar_month</span> ตารางงาน
          </button>
        </div>
        <button class="btn-secondary" @click="loadData" :disabled="loading">
          <span class="material-symbols-rounded">refresh</span> รีเฟรช
        </button>
      </div>
    </div>

    <!-- ─── TODAY'S TRIPS PANEL ─────────────────────────────── -->
    <div v-if="todaySchedules.length" class="today-panel">
      <div class="today-header">
        <span class="material-symbols-rounded">today</span>
        <strong>รอบเดินทางวันนี้ — {{ todayLabel }}</strong>
        <span class="today-count">{{ todaySchedules.length }} รอบ</span>
      </div>
      <div class="today-grid">
        <div v-for="sch in todaySchedules" :key="sch.id" class="today-card">
          <div class="today-trip-name">{{ sch.trip?.title || 'ไม่ระบุทริป' }}</div>
          <div class="today-trip-meta">
            <span class="material-symbols-rounded">place</span>{{ sch.trip?.location || '-' }}
            <span class="material-symbols-rounded" style="margin-left:6px;">directions_car</span>{{ vehicleLabel(sch.vehicle, sch.transport_type) }}
          </div>
          <div v-if="sch.assignedStaff && sch.assignedStaff.length" class="today-staff-row">
            <div v-for="st in sch.assignedStaff" :key="st.id" class="today-staff-chip">
              <img v-if="st.avatar_url" :src="st.avatar_url" :alt="st.name" class="today-avatar" />
              <div v-else class="today-avatar fallback">{{ st.name?.charAt(0)?.toUpperCase() }}</div>
              <div class="today-staff-info">
                <span class="today-staff-name">{{ st.name }}<span v-if="st.nickname" class="today-staff-nick"> ({{ st.nickname }})</span></span>
                <a v-if="st.phone" :href="`tel:${st.phone}`" class="today-phone">
                  <span class="material-symbols-rounded">call</span>{{ st.phone }}
                </a>
              </div>
            </div>
          </div>
          <div v-else class="today-no-staff">
            <span class="material-symbols-rounded">person_off</span> ยังไม่มีสตาฟ
          </div>
        </div>
      </div>
    </div>

    <!-- ─── ASSIGN TAB ──────────────────────────────────────── -->
    <template v-if="activeTab === 'assign'">
      <div class="summary-grid">
        <div class="summary-card">
          <span class="material-symbols-rounded">event_note</span>
          <div>
            <p>รอบที่แสดง</p>
            <strong>{{ visibleSchedulesCount }}</strong>
          </div>
        </div>
        <div class="summary-card">
          <span class="material-symbols-rounded">badge</span>
          <div>
            <p>สตาฟทั้งหมด</p>
            <strong>{{ staffUsers.length }}</strong>
          </div>
        </div>
        <div class="summary-card">
          <span class="material-symbols-rounded">assignment_ind</span>
          <div>
            <p>มอบหมายในรอบนี้</p>
            <strong>{{ selectedStaffIds.length }}</strong>
          </div>
        </div>
        <div class="summary-card warning">
          <span class="material-symbols-rounded">person_off</span>
          <div>
            <p>รอบที่ยังไม่มีสตาฟ</p>
            <strong>{{ unassignedSchedulesCount }}</strong>
          </div>
        </div>
      </div>

      <div class="assign-layout">
        <!-- ── LEFT: trip / round picker ── -->
        <aside class="trip-panel table-card">
          <div class="panel-head">
            <div class="form-group">
              <label>ช่วงรอบเดินทาง</label>
              <select v-model="scheduleScope" @change="loadData" :disabled="loading">
                <option value="upcoming">รอบที่กำลังจะมาถึง</option>
                <option value="all">ทั้งหมด</option>
              </select>
            </div>
            <div class="search-box">
              <span class="material-symbols-rounded">travel_explore</span>
              <input v-model="scheduleSearch" placeholder="ค้นหาทริป / สถานที่ / วันที่" />
            </div>
          </div>

          <div v-if="loading" class="loading-state"><div class="spinner"></div></div>

          <div v-else class="trip-groups">
            <div v-for="group in groupedTrips" :key="group.id" class="trip-group">
              <button type="button" class="trip-group-head" @click="toggleTrip(group.id)">
                <span class="material-symbols-rounded chev">{{ isTripExpanded(group.id) ? 'expand_more' : 'chevron_right' }}</span>
                <div class="trip-group-info">
                  <span class="trip-group-title">{{ group.title }}</span>
                  <span v-if="group.location" class="trip-group-loc">
                    <span class="material-symbols-rounded">place</span>{{ group.location }}
                  </span>
                </div>
                <div class="trip-group-badges">
                  <span class="rounds-count">{{ group.rounds.length }} รอบ</span>
                  <span v-if="group.missingStaff" class="missing-badge">
                    <span class="material-symbols-rounded">person_off</span>{{ group.missingStaff }}
                  </span>
                </div>
              </button>

              <div v-if="isTripExpanded(group.id)" class="round-list">
                <button
                  v-for="round in group.rounds"
                  :key="round.id"
                  type="button"
                  class="round-item"
                  :class="{ active: Number(round.id) === Number(selectedScheduleId) }"
                  @click="selectSchedule(round.id)"
                >
                  <div class="round-main">
                    <span class="round-date">
                      <span class="material-symbols-rounded">event</span>{{ scheduleDateRange(round) }}
                    </span>
                    <span class="round-meta">
                      <span class="material-symbols-rounded">event_seat</span>{{ round.booked_seats ?? 0 }}/{{ round.total_seats ?? 0 }}
                      <span class="status-dot" :class="`dot-${round.status || 'unknown'}`"></span>{{ statusLabel(round.status) }}
                    </span>
                  </div>
                  <span class="round-staff-badge" :class="{ none: !Number(round.assigned_staff_count || 0) }">
                    <span class="material-symbols-rounded">{{ Number(round.assigned_staff_count || 0) ? 'group' : 'person_off' }}</span>
                    {{ Number(round.assigned_staff_count || 0) ? `สตาฟ ${round.assigned_staff_count} คน` : 'ยังไม่มีสตาฟ' }}
                  </span>
                </button>
              </div>
            </div>

            <div v-if="!groupedTrips.length" class="panel-empty">
              <span class="material-symbols-rounded">search_off</span>
              <p>ไม่พบรอบเดินทาง</p>
            </div>
          </div>
        </aside>

        <!-- ── RIGHT: staff assignment for selected round ── -->
        <section class="detail-panel">
          <div v-if="!selectedScheduleId" class="table-card detail-empty">
            <span class="material-symbols-rounded">touch_app</span>
            <p>เลือกรอบเดินทางจากรายการด้านซ้าย<br />เพื่อจัดสตาฟประจำรอบ</p>
          </div>

          <template v-else>
            <!-- Round header -->
            <div class="table-card round-header-card" v-if="activeScheduleMeta">
              <div class="round-header-top">
                <div>
                  <h2 class="round-title">{{ activeScheduleMeta.trip_title }}</h2>
                  <p class="round-subtitle">
                    <span class="material-symbols-rounded">event</span>{{ scheduleDateRange(activeScheduleMeta) }}
                  </p>
                </div>
                <span class="meta-pill status" :class="`status-${activeScheduleMeta.status || 'unknown'}`">
                  <span class="material-symbols-rounded">radio_button_checked</span>{{ statusLabel(activeScheduleMeta.status) }}
                </span>
              </div>
              <div class="schedule-meta">
                <span class="meta-pill"><span class="material-symbols-rounded">place</span>{{ activeScheduleMeta.trip_location || '-' }}</span>
                <span class="meta-pill"><span class="material-symbols-rounded">directions_car</span>{{ vehicleLabel(activeScheduleMeta.vehicle, activeScheduleMeta.transport_type) }}</span>
                <span class="meta-pill"><span class="material-symbols-rounded">groups</span>จองแล้ว {{ activeScheduleMeta.active_bookings_count ?? activeScheduleMeta.booked_seats ?? 0 }} รายการ</span>
                <span class="meta-pill"><span class="material-symbols-rounded">event_seat</span>{{ activeScheduleMeta.booked_seats ?? 0 }}/{{ activeScheduleMeta.total_seats ?? 0 }} ที่นั่ง</span>
              </div>
            </div>

            <!-- Assigned staff -->
            <div class="table-card assigned-card">
              <div class="section-head">
                <span class="material-symbols-rounded">how_to_reg</span>
                <strong>สตาฟประจำรอบนี้</strong>
                <span class="section-count">{{ selectedStaff.length }} คน</span>
              </div>
              <div v-if="selectedStaff.length" class="assigned-list">
                <div v-for="staff in selectedStaff" :key="staff.id" class="assigned-chip">
                  <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="chip-avatar" />
                  <div v-else class="chip-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
                  <div class="chip-info">
                    <span class="chip-name">{{ staff.name }}<span v-if="staff.nickname" class="chip-nick"> ({{ staff.nickname }})</span></span>
                    <a v-if="staff.phone" :href="`tel:${staff.phone}`" class="phone-link">
                      <span class="material-symbols-rounded">call</span>{{ staff.phone }}
                    </a>
                  </div>
                  <button type="button" class="chip-remove" @click="removeStaff(staff.id)" title="นำออกจากรอบนี้">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
              </div>
              <div v-else class="assigned-empty">
                <span class="material-symbols-rounded">person_off</span>
                ยังไม่มีสตาฟในรอบนี้ — เลือกจากรายชื่อด้านล่าง
              </div>
            </div>

            <!-- Staff picker -->
            <div class="table-card pick-card">
              <div class="section-head">
                <span class="material-symbols-rounded">person_add</span>
                <strong>เลือกสตาฟเพิ่ม</strong>
                <div class="search-box pick-search">
                  <span class="material-symbols-rounded">search</span>
                  <input v-model="search" placeholder="ค้นหาสตาฟ ชื่อ / ชื่อเล่น / เบอร์โทร" />
                </div>
              </div>
              <div class="staff-pick-list">
                <div v-for="staff in availableStaff" :key="staff.id" class="staff-pick-row">
                  <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="staff-avatar" />
                  <div v-else class="staff-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
                  <div class="pick-info">
                    <span class="pick-name">{{ staff.name }}<span v-if="staff.nickname" class="chip-nick"> ({{ staff.nickname }})</span></span>
                    <div class="pick-meta">
                      <a v-if="staff.phone" :href="`tel:${staff.phone}`" class="phone-link">
                        <span class="material-symbols-rounded">call</span>{{ staff.phone }}
                      </a>
                      <span class="count-pill">{{ staff.assigned_schedules_count || 0 }} รอบ</span>
                      <span class="rating-pill" :class="{ empty: !staff.avg_staff_rating }">
                        <span class="material-symbols-rounded">star</span>{{ formatRating(staff.avg_staff_rating) }}
                        <span v-if="staff.total_staff_reviews" class="review-count">({{ staff.total_staff_reviews }})</span>
                      </span>
                    </div>
                  </div>
                  <button type="button" class="btn-add" @click="addStaff(staff.id)">
                    <span class="material-symbols-rounded">add</span> เพิ่ม
                  </button>
                </div>
                <div v-if="!availableStaff.length" class="panel-empty small">
                  <span class="material-symbols-rounded">{{ search ? 'search_off' : 'done_all' }}</span>
                  <p>{{ search ? 'ไม่พบสตาฟที่ค้นหา' : 'สตาฟทุกคนถูกเลือกแล้ว' }}</p>
                </div>
              </div>
            </div>

            <!-- Save bar -->
            <div class="save-bar" :class="{ dirty: isDirty }">
              <span v-if="isDirty" class="dirty-note">
                <span class="material-symbols-rounded">warning</span> มีการแก้ไขที่ยังไม่บันทึก
              </span>
              <span v-else class="saved-note">
                <span class="material-symbols-rounded">check_circle</span> ข้อมูลล่าสุดถูกบันทึกแล้ว
              </span>
              <button v-if="isDirty" class="btn-secondary" @click="resetSelection" :disabled="saving">
                <span class="material-symbols-rounded">undo</span> ยกเลิกการแก้ไข
              </button>
              <button class="btn-primary" @click="saveAssignments" :disabled="saving || !isDirty">
                <span class="material-symbols-rounded">{{ saving ? 'sync' : 'save' }}</span>
                บันทึกการมอบหมายสตาฟ
              </button>
            </div>
          </template>
        </section>
      </div>
    </template>

    <!-- ─── ROSTER TAB ──────────────────────────────────────── -->
    <template v-if="activeTab === 'roster'">
      <div class="table-card filters-card">
        <div class="filters-row">
          <div class="form-group">
            <label>วันเริ่มต้น</label>
            <input type="date" v-model="rosterFrom" @change="loadRoster" />
          </div>
          <div class="form-group">
            <label>วันสิ้นสุด</label>
            <input type="date" v-model="rosterTo" @change="loadRoster" />
          </div>
          <button class="btn-secondary" @click="loadRoster" :disabled="rosterLoading">
            <span class="material-symbols-rounded">refresh</span> โหลด
          </button>
        </div>
      </div>

      <div v-if="rosterLoading" class="table-card"><div class="loading-state"><div class="spinner"></div></div></div>

      <div v-else-if="!rosterSchedules.length" class="table-card empty-state" style="padding:40px;text-align:center;">
        <span class="material-symbols-rounded" style="font-size:40px;color:#94a3b8;">calendar_month</span>
        <p style="color:#64748b;margin-top:8px;">ไม่มีรอบเดินทางในช่วงนี้</p>
      </div>

      <div v-else class="roster-wrapper">
        <!-- Staff legend -->
        <div class="roster-legend">
          <div v-for="staff in rosterStaff" :key="staff.id" class="legend-item">
            <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="legend-avatar" />
            <div v-else class="legend-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
            <div class="legend-info">
              <span class="legend-name">{{ staff.name }}</span>
              <span v-if="staff.nickname" class="legend-nick">{{ staff.nickname }}</span>
              <a v-if="staff.phone" :href="`tel:${staff.phone}`" class="legend-phone">
                <span class="material-symbols-rounded">call</span>{{ staff.phone }}
              </a>
            </div>
          </div>
        </div>

        <!-- Schedule timetable -->
        <div class="table-card roster-table-card">
          <div class="table-container">
            <table class="data-table roster-table">
              <thead>
                <tr>
                  <th class="roster-schedule-col">รอบเดินทาง</th>
                  <th class="roster-date-col">วันที่</th>
                  <th v-for="staff in rosterStaff" :key="staff.id" class="roster-staff-col">
                    <div class="roster-th-staff">
                      <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="roster-th-avatar" />
                      <div v-else class="roster-th-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
                      <span>{{ staff.nickname || staff.name }}</span>
                    </div>
                  </th>
                  <th v-if="!rosterStaff.length" class="empty-state" style="padding:16px;">ไม่มีสตาฟในช่วงนี้</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="sch in rosterSchedules" :key="sch.id">
                  <td>
                    <div class="roster-trip-cell">
                      <span class="roster-trip-name">{{ sch.trip_title }}</span>
                      <span v-if="sch.trip_location" class="roster-trip-loc">
                        <span class="material-symbols-rounded">place</span>{{ sch.trip_location }}
                      </span>
                    </div>
                  </td>
                  <td>
                    <div class="roster-date-cell">
                      <span>{{ formatDate(sch.departure_date) }}</span>
                      <span v-if="sch.return_date && sch.return_date !== sch.departure_date" class="muted">→ {{ formatDate(sch.return_date) }}</span>
                    </div>
                  </td>
                  <td v-for="staff in rosterStaff" :key="staff.id" class="roster-cell">
                    <span v-if="isAssigned(sch.id, staff.id)" class="assigned-badge">
                      <span class="material-symbols-rounded">check_circle</span>
                    </span>
                    <span v-else class="unassigned-dot">—</span>
                  </td>
                  <td v-if="!rosterStaff.length"></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();

const activeTab = ref('assign');
const loading = ref(false);
const saving = ref(false);
const search = ref('');
const scheduleSearch = ref('');
const scheduleScope = ref('upcoming');
const selectedScheduleId = ref('');

const schedules = ref([]);
const staffUsers = ref([]);
const selectedScheduleMeta = ref(null);
const selectedStaffIds = ref([]);
const originalStaffIds = ref([]);
const expandedTripIds = ref([]);

// Roster state
const rosterLoading = ref(false);
const rosterFrom = ref(todayIso());
const rosterTo = ref(addDays(todayIso(), 29));
const rosterStaff = ref([]);
const rosterSchedules = ref([]);
const rosterAssignments = ref({});

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function addDays(isoDate, days) {
  const d = new Date(isoDate);
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

const todayLabel = computed(() => {
  return new Date().toLocaleDateString('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
});

const todaySchedules = computed(() => {
  const today = todayIso();
  return schedules.value.filter((s) => s.departure_date === today || (s.departure_date <= today && s.return_date >= today));
});

const scheduleOptions = computed(() => {
  const keyword = scheduleSearch.value.trim().toLowerCase();
  const list = schedules.value || [];
  if (!keyword) return list;
  return list.filter((schedule) => {
    const haystack = [
      schedule.trip?.title,
      schedule.trip?.location,
      schedule.departure_date,
      schedule.return_date,
      schedule.vehicle?.name,
      schedule.vehicle?.license_plate,
      schedule.transport_type,
      schedule.status,
    ].filter(Boolean).join(' ').toLowerCase();
    return haystack.includes(keyword);
  });
});

// Group filtered schedules into Trip → rounds, trips ordered by earliest round.
const groupedTrips = computed(() => {
  const groups = new Map();
  for (const schedule of scheduleOptions.value) {
    const tripId = schedule.trip?.id ?? `none-${schedule.id}`;
    if (!groups.has(tripId)) {
      groups.set(tripId, {
        id: tripId,
        title: schedule.trip?.title || 'ไม่ระบุทริป',
        location: schedule.trip?.location || '',
        rounds: [],
      });
    }
    groups.get(tripId).rounds.push(schedule);
  }
  const list = [...groups.values()];
  for (const group of list) {
    group.rounds.sort((a, b) => String(a.departure_date).localeCompare(String(b.departure_date)));
    group.missingStaff = group.rounds.filter((r) => !Number(r.assigned_staff_count || 0)).length;
  }
  list.sort((a, b) => String(a.rounds[0]?.departure_date).localeCompare(String(b.rounds[0]?.departure_date)));
  return list;
});

const visibleSchedulesCount = computed(() => scheduleOptions.value.length);

const isTripExpanded = (tripId) => {
  // While searching, always show matches expanded so results aren't hidden.
  if (scheduleSearch.value.trim()) return true;
  return expandedTripIds.value.includes(tripId);
};

const toggleTrip = (tripId) => {
  if (expandedTripIds.value.includes(tripId)) {
    expandedTripIds.value = expandedTripIds.value.filter((id) => id !== tripId);
  } else {
    expandedTripIds.value = [...expandedTripIds.value, tripId];
  }
};

const expandTripOfSchedule = (scheduleId) => {
  const schedule = schedules.value.find((s) => Number(s.id) === Number(scheduleId));
  const tripId = schedule?.trip?.id;
  if (tripId != null && !expandedTripIds.value.includes(tripId)) {
    expandedTripIds.value = [...expandedTripIds.value, tripId];
  }
};

const selectedSchedule = computed(() => {
  const id = Number(selectedScheduleId.value);
  return schedules.value.find((schedule) => Number(schedule.id) === id) || null;
});

const activeScheduleMeta = computed(() => {
  if (selectedScheduleMeta.value) return selectedScheduleMeta.value;
  if (!selectedSchedule.value) return null;
  return {
    id: selectedSchedule.value.id,
    trip_title: selectedSchedule.value.trip?.title || 'ไม่ระบุทริป',
    trip_location: selectedSchedule.value.trip?.location,
    departure_date: selectedSchedule.value.departure_date,
    return_date: selectedSchedule.value.return_date,
    status: selectedSchedule.value.status,
    transport_type: selectedSchedule.value.transport_type,
    vehicle: selectedSchedule.value.vehicle,
    total_seats: selectedSchedule.value.total_seats,
    booked_seats: selectedSchedule.value.booked_seats,
    available_seats: selectedSchedule.value.available_seats,
    active_bookings_count: selectedSchedule.value.active_bookings_count,
    assigned_staff_count: selectedSchedule.value.assigned_staff_count,
  };
});

const selectedStaff = computed(() => {
  const ids = new Set(selectedStaffIds.value.map(Number));
  return staffUsers.value.filter((staff) => ids.has(Number(staff.id)));
});

const unassignedSchedulesCount = computed(() => {
  return scheduleOptions.value.filter((schedule) => Number(schedule.assigned_staff_count || 0) === 0).length;
});

const filteredStaff = computed(() => {
  const keyword = search.value.trim().toLowerCase();
  if (!keyword) return staffUsers.value;
  return staffUsers.value.filter((staff) => {
    const haystack = `${staff.name || ''} ${staff.nickname || ''} ${staff.email || ''} ${staff.phone || ''}`.toLowerCase();
    return haystack.includes(keyword);
  });
});

const availableStaff = computed(() => filteredStaff.value.filter((staff) => !isStaffSelected(staff.id)));

const isDirty = computed(() => {
  const current = [...selectedStaffIds.value.map(Number)].sort((a, b) => a - b);
  const original = [...originalStaffIds.value.map(Number)].sort((a, b) => a - b);
  return JSON.stringify(current) !== JSON.stringify(original);
});

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
};

const scheduleDateRange = (schedule) => {
  if (!schedule) return '-';
  const start = formatDate(schedule.departure_date);
  const end = formatDate(schedule.return_date);
  return schedule.return_date && schedule.return_date !== schedule.departure_date ? `${start} - ${end}` : start;
};

const vehicleLabel = (vehicle, fallbackType = '') => {
  if (!vehicle) return fallbackType || '-';
  const plate = vehicle.license_plate ? ` (${vehicle.license_plate})` : '';
  return `${vehicle.name || vehicle.type || fallbackType || '-'}${plate}`;
};

const statusLabel = (status) => {
  const labels = { open: 'เปิดรับจอง', closed: 'ปิดรับจอง', full: 'เต็มแล้ว', cancelled: 'ยกเลิก' };
  return labels[status] || status || '-';
};

const formatRating = (rating) => {
  if (!rating) return '-';
  return Number(rating).toFixed(2).replace(/\.?0+$/, '');
};

const normalizeStaffFromUsersApi = (users = []) => {
  return users.map((user) => ({
    id: user.id,
    name: user.name,
    nickname: user.nickname,
    email: user.email,
    phone: user.phone,
    avatar_url: user.avatar_url,
    assigned_schedules_count: user.assigned_schedules_count || 0,
    total_staff_reviews: user.total_staff_reviews || 0,
    avg_staff_rating: user.avg_staff_rating ?? null,
  }));
};

const loadStaffUsers = async () => {
  try {
    const staffRes = await admin.fetchStaffUsers({ per_page: 200 });
    const primaryData = staffRes?.data || [];
    if (primaryData.length) {
      staffUsers.value = primaryData;
      return;
    }
    await admin.fetchUsers({ role: 'staff', per_page: 200 });
    staffUsers.value = normalizeStaffFromUsersApi(admin.users.data || []);
  } catch (e) {
    await admin.fetchUsers({ role: 'staff', per_page: 200 });
    staffUsers.value = normalizeStaffFromUsersApi(admin.users.data || []);
  }
};

const loadData = async () => {
  loading.value = true;
  try {
    const params = { per_page: 500 };
    if (scheduleScope.value === 'upcoming') params.upcoming = 1;

    await admin.fetchSchedules(params);
    schedules.value = admin.schedules.data || [];

    await loadStaffUsers();

    // Attach today's staff to today's schedules for the panel
    await attachTodayStaff();

    const selectedStillExists = schedules.value.some((schedule) => Number(schedule.id) === Number(selectedScheduleId.value));
    if ((!selectedScheduleId.value || !selectedStillExists) && schedules.value.length) {
      selectedScheduleId.value = schedules.value[0].id;
    }

    if (selectedScheduleId.value) {
      expandTripOfSchedule(selectedScheduleId.value);
      await loadAssignedStaff();
    }
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดข้อมูลสตาฟไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const attachTodayStaff = async () => {
  const today = todayIso();
  const todayIds = schedules.value
    .filter((s) => s.departure_date === today || (s.departure_date <= today && s.return_date >= today))
    .map((s) => s.id);

  for (const id of todayIds) {
    try {
      const res = await admin.fetchScheduleStaff(id);
      const staffList = res.data?.staff || [];
      const sch = schedules.value.find((s) => s.id === id);
      if (sch) sch.assignedStaff = staffList;
    } catch {
      // ignore
    }
  }
};

const selectSchedule = async (scheduleId) => {
  if (Number(scheduleId) === Number(selectedScheduleId.value)) return;
  if (isDirty.value && !confirm('มีการแก้ไขที่ยังไม่บันทึก ต้องการละทิ้งการแก้ไขหรือไม่?')) return;
  selectedScheduleId.value = scheduleId;
  await loadAssignedStaff();
};

const loadAssignedStaff = async () => {
  if (!selectedScheduleId.value) {
    selectedScheduleMeta.value = null;
    selectedStaffIds.value = [];
    originalStaffIds.value = [];
    return;
  }
  loading.value = true;
  try {
    const res = await admin.fetchScheduleStaff(selectedScheduleId.value);
    selectedScheduleMeta.value = res.data?.schedule || null;
    selectedStaffIds.value = (res.data?.staff || []).map((s) => s.id);
    originalStaffIds.value = [...selectedStaffIds.value];
    mergeAssignedStaffStats(res.data?.staff || []);
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดรายการสตาฟของรอบไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const addStaff = (staffId) => {
  if (!selectedScheduleId.value || isStaffSelected(staffId)) return;
  selectedStaffIds.value = [...selectedStaffIds.value, Number(staffId)];
};

const removeStaff = (staffId) => {
  selectedStaffIds.value = selectedStaffIds.value.filter((id) => Number(id) !== Number(staffId));
};

const resetSelection = () => {
  selectedStaffIds.value = [...originalStaffIds.value];
};

const isStaffSelected = (staffId) => selectedStaffIds.value.map(Number).includes(Number(staffId));

const mergeAssignedStaffStats = (assignedStaff = []) => {
  if (!assignedStaff.length) return;
  const statsById = new Map(assignedStaff.map((staff) => [Number(staff.id), staff]));
  staffUsers.value = staffUsers.value.map((staff) => {
    const updated = statsById.get(Number(staff.id));
    return updated ? { ...staff, ...updated } : staff;
  });
};

const saveAssignments = async () => {
  if (!selectedScheduleId.value) return;
  saving.value = true;
  try {
    const res = await admin.syncScheduleStaff(selectedScheduleId.value, selectedStaffIds.value.map(Number));
    selectedScheduleMeta.value = res.data?.schedule || selectedScheduleMeta.value;
    selectedStaffIds.value = (res.data?.staff || []).map((s) => s.id);
    originalStaffIds.value = [...selectedStaffIds.value];
    mergeAssignedStaffStats(res.data?.staff || []);
    await loadStaffUsers();
    await admin.fetchSchedules({ per_page: 500, ...(scheduleScope.value === 'upcoming' ? { upcoming: 1 } : {}) });
    schedules.value = admin.schedules.data || [];
    alert('บันทึกการมอบหมายสตาฟสำเร็จ');
  } catch (e) {
    alert(e?.response?.data?.message || 'บันทึกข้อมูลไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
};

// ─── ROSTER ────────────────────────────────────────────────
const loadRoster = async () => {
  rosterLoading.value = true;
  try {
    const data = await admin.fetchStaffRoster({ from: rosterFrom.value, to: rosterTo.value });
    rosterStaff.value = data.staff || [];
    rosterSchedules.value = data.schedules || [];
    rosterAssignments.value = data.assignments || {};
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดตารางงานไม่สำเร็จ');
  } finally {
    rosterLoading.value = false;
  }
};

const isAssigned = (scheduleId, staffId) => {
  const ids = rosterAssignments.value[scheduleId] || [];
  return ids.map(Number).includes(Number(staffId));
};

onMounted(loadData);
</script>

<style scoped>
@import url('./admin-shared.css');

/* ── Header ──────────────────────────────────────────────── */
.header-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}

.tab-switcher {
  display: flex;
  background: #f1f5f9;
  border-radius: 12px;
  padding: 3px;
  gap: 2px;
}

.tab-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: #64748b;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}

.tab-btn .material-symbols-rounded {
  font-size: 17px;
}

.tab-btn.active {
  background: #fff;
  color: var(--color-accent);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

/* ── Today Panel ──────────────────────────────────────────── */
.today-panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  padding: 16px 20px;
  margin-bottom: 16px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}

.today-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 800;
  color: #0f172a;
  margin-bottom: 14px;
}

.today-header .material-symbols-rounded {
  color: var(--color-accent);
  font-size: 20px;
}

.today-count {
  margin-left: auto;
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
  background: #f1f5f9;
  border-radius: 999px;
  padding: 3px 10px;
}

.today-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}

.today-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px;
}

.today-trip-name {
  font-size: 14px;
  font-weight: 800;
  color: #0f172a;
  margin-bottom: 4px;
}

.today-trip-meta {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 10px;
}

.today-trip-meta .material-symbols-rounded {
  font-size: 14px;
  color: #94a3b8;
}

.today-staff-row {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.today-staff-chip {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 10px;
}

.today-avatar {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}

.today-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 14px;
}

.today-staff-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.today-staff-name {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
}

.today-staff-nick {
  color: #64748b;
  font-weight: 600;
}

.today-phone {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent);
  text-decoration: none;
}

.today-phone:hover {
  text-decoration: underline;
}

.today-phone .material-symbols-rounded {
  font-size: 14px;
}

.today-no-staff {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #94a3b8;
}

.today-no-staff .material-symbols-rounded {
  font-size: 16px;
}

/* ── Summary grid ─────────────────────────────────────────── */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.summary-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 14px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.summary-card > .material-symbols-rounded {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ecfdf5;
  color: #047857;
}

.summary-card.warning > .material-symbols-rounded { background: #fffbeb; color: #b45309; }
.summary-card p { margin: 0; color: #64748b; font-size: 12px; font-weight: 600; }
.summary-card strong { display: block; color: #0f172a; font-size: 22px; line-height: 1; margin-top: 4px; }

/* ── Assign layout (master–detail) ───────────────────────── */
.assign-layout {
  display: grid;
  grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

/* Left: trip panel */
.trip-panel {
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 120px);
  position: sticky;
  top: 16px;
  overflow: hidden;
}

.panel-head {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}

.panel-head .form-group label {
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
}

.panel-head select {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  color: #0f172a;
  background: #fff;
}

.trip-groups {
  overflow-y: auto;
  padding: 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.trip-group {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
}

.trip-group-head {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 10px 12px;
  border: none;
  background: #f8fafc;
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}

.trip-group-head:hover { background: #f1f5f9; }

.trip-group-head .chev {
  font-size: 20px;
  color: #94a3b8;
  flex-shrink: 0;
}

.trip-group-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.trip-group-title {
  font-size: 13px;
  font-weight: 800;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.trip-group-loc {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}

.trip-group-loc .material-symbols-rounded { font-size: 13px; }

.trip-group-badges {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.rounds-count {
  font-size: 11px;
  font-weight: 700;
  color: #475569;
  background: #e2e8f0;
  border-radius: 999px;
  padding: 3px 8px;
}

.missing-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  font-weight: 700;
  color: #b45309;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 999px;
  padding: 2px 8px;
}

.missing-badge .material-symbols-rounded { font-size: 13px; }

.round-list {
  display: flex;
  flex-direction: column;
  border-top: 1px solid #f1f5f9;
}

.round-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  padding: 10px 12px 10px 30px;
  border: none;
  border-top: 1px solid #f8fafc;
  background: #fff;
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}

.round-item:hover { background: #f8fafc; }

.round-item.active {
  background: #ecfdf5;
  box-shadow: inset 3px 0 0 var(--color-accent);
}

.round-main {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.round-date {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
}

.round-date .material-symbols-rounded { font-size: 15px; color: #94a3b8; }

.round-item.active .round-date .material-symbols-rounded { color: var(--color-accent); }

.round-meta {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}

.round-meta .material-symbols-rounded { font-size: 13px; }

.status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #cbd5e1;
  margin-left: 4px;
}

.dot-open { background: #10b981; }
.dot-closed, .dot-full { background: #f59e0b; }
.dot-cancelled { background: #ef4444; }

.round-staff-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 700;
  color: #047857;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 999px;
  padding: 3px 8px;
  flex-shrink: 0;
  white-space: nowrap;
}

.round-staff-badge.none {
  color: #b45309;
  background: #fffbeb;
  border-color: #fde68a;
}

.round-staff-badge .material-symbols-rounded { font-size: 13px; }

.panel-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 36px 16px;
  color: #94a3b8;
}

.panel-empty .material-symbols-rounded { font-size: 36px; }
.panel-empty p { margin: 0; font-size: 13px; font-weight: 600; color: #64748b; text-align: center; }
.panel-empty.small { padding: 24px 16px; }
.panel-empty.small .material-symbols-rounded { font-size: 28px; }

/* Right: detail panel */
.detail-panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

.detail-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 80px 24px;
  color: #94a3b8;
  text-align: center;
}

.detail-empty .material-symbols-rounded { font-size: 44px; }
.detail-empty p { margin: 0; font-size: 14px; font-weight: 600; color: #64748b; line-height: 1.6; }

.round-header-card { padding: 16px 18px; }

.round-header-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.round-title {
  margin: 0;
  font-size: 17px;
  font-weight: 800;
  color: #0f172a;
}

.round-subtitle {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 4px 0 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--color-accent);
}

.round-subtitle .material-symbols-rounded { font-size: 16px; }

.schedule-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.meta-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #0f172a;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 5px 10px;
}

.meta-pill.status-open { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
.meta-pill.status-closed, .meta-pill.status-full { color: #92400e; background: #fffbeb; border-color: #fde68a; }
.meta-pill.status-cancelled { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
.meta-pill .material-symbols-rounded { font-size: 15px; }

/* Section heads */
.section-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 14px 16px;
  border-bottom: 1px solid #f1f5f9;
  font-size: 14px;
  color: #0f172a;
  flex-wrap: wrap;
}

.section-head .material-symbols-rounded {
  font-size: 19px;
  color: var(--color-accent);
}

.section-count {
  font-size: 12px;
  font-weight: 700;
  color: #047857;
  background: #ecfdf5;
  border-radius: 999px;
  padding: 3px 10px;
}

.pick-search {
  margin-left: auto;
  min-width: 240px;
  flex: 1;
  max-width: 340px;
}

/* Assigned chips */
.assigned-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 14px 16px;
}

.assigned-chip {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 12px;
  padding: 8px 10px;
}

.chip-avatar {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  object-fit: cover;
  border: 1px solid #d1fae5;
  flex-shrink: 0;
}

.chip-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 13px;
}

.chip-info {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.chip-name { font-size: 13px; font-weight: 700; color: #065f46; }
.chip-nick { color: #64748b; font-weight: 600; }

.chip-remove {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #059669;
  cursor: pointer;
  transition: all 0.15s;
  flex-shrink: 0;
}

.chip-remove:hover { background: #fee2e2; color: #b91c1c; }
.chip-remove .material-symbols-rounded { font-size: 17px; }

.assigned-empty {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 18px 16px;
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
}

.assigned-empty .material-symbols-rounded { font-size: 18px; }

/* Staff picker list */
.staff-pick-list {
  max-height: 420px;
  overflow-y: auto;
}

.staff-pick-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  border-top: 1px solid #f8fafc;
  transition: background 0.15s;
}

.staff-pick-row:hover { background: #f8fafc; }

.staff-avatar {
  width: 36px;
  height: 36px;
  border-radius: 9px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}

.staff-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
}

.pick-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
  flex: 1;
}

.pick-name { font-size: 13px; font-weight: 700; color: #0f172a; }

.pick-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.phone-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent);
  text-decoration: none;
}

.phone-link:hover { text-decoration: underline; }
.phone-link .material-symbols-rounded { font-size: 14px; }

.count-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #334155;
  font-weight: 600;
  font-size: 11px;
}

.rating-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px;
  border-radius: 999px;
  background: #fff7ed;
  color: #9a3412;
  font-weight: 600;
  font-size: 11px;
}

.rating-pill.empty { background: #f3f4f6; color: #6b7280; }
.rating-pill .material-symbols-rounded { font-size: 13px; }
.review-count { color: #b45309; font-weight: 600; }

.btn-add {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 7px 14px;
  border: 1px solid #a7f3d0;
  border-radius: 10px;
  background: #ecfdf5;
  color: #047857;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
  flex-shrink: 0;
}

.btn-add:hover { background: #d1fae5; }
.btn-add .material-symbols-rounded { font-size: 16px; }

/* Save bar */
.save-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 12px 16px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  position: sticky;
  bottom: 12px;
}

.save-bar.dirty { border-color: #fde68a; }

.dirty-note,
.saved-note {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
  margin-right: auto;
}

.dirty-note { color: #b45309; }
.saved-note { color: #047857; }
.dirty-note .material-symbols-rounded,
.saved-note .material-symbols-rounded { font-size: 17px; }

/* ── Roster ───────────────────────────────────────────────── */
.filters-card {
  padding: 16px;
  margin-bottom: 16px;
}

.filters-row {
  display: flex;
  gap: 12px;
  align-items: end;
  flex-wrap: wrap;
}

.roster-wrapper {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.roster-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  padding: 16px;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 14px;
}

.legend-avatar {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}

.legend-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 15px;
}

.legend-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.legend-name { font-size: 13px; font-weight: 800; color: #0f172a; }
.legend-nick { font-size: 11px; font-weight: 700; color: #64748b; }

.legend-phone {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent);
  text-decoration: none;
}

.legend-phone:hover { text-decoration: underline; }
.legend-phone .material-symbols-rounded { font-size: 13px; }

.roster-table-card { overflow: auto; }

.roster-table .roster-schedule-col { min-width: 180px; max-width: 240px; }
.roster-table .roster-date-col { min-width: 130px; white-space: nowrap; }
.roster-table .roster-staff-col { text-align: center; min-width: 100px; }

.roster-th-staff {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 700;
  color: #334155;
}

.roster-th-avatar {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
}

.roster-th-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 12px;
}

.roster-trip-cell { display: flex; flex-direction: column; gap: 2px; }
.roster-trip-name { font-size: 13px; font-weight: 700; color: #0f172a; }
.roster-trip-loc {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
}

.roster-trip-loc .material-symbols-rounded { font-size: 12px; }

.roster-date-cell { display: flex; flex-direction: column; gap: 2px; font-size: 12px; font-weight: 600; }

.roster-cell { text-align: center; vertical-align: middle; }

.assigned-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--color-accent);
}

.assigned-badge .material-symbols-rounded { font-size: 20px; }

.unassigned-dot { color: #cbd5e1; font-size: 16px; }

/* ── Responsive ───────────────────────────────────────────── */
@media (max-width: 1024px) {
  .assign-layout { grid-template-columns: 1fr; }
  .trip-panel {
    position: static;
    max-height: 420px;
  }
}

@media (max-width: 900px) {
  .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .today-grid { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
  .summary-grid { grid-template-columns: 1fr; }
  .tab-switcher { width: 100%; }
  .tab-btn { flex: 1; justify-content: center; }
  .pick-search { min-width: 100%; max-width: 100%; }
  .save-bar { flex-direction: column; align-items: stretch; }
  .dirty-note, .saved-note { margin-right: 0; justify-content: center; }
}
</style>
