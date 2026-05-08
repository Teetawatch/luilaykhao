<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">badge</span> จัดการสตาฟประจำรอบ</h1>
        <p class="page-subtitle">กำหนดว่าสตาฟคนไหนดูแลทริปไหนในแต่ละรอบเดินทาง</p>
      </div>
      <button class="btn-secondary" @click="loadData" :disabled="loading">
        <span class="material-symbols-rounded">refresh</span> รีเฟรช
      </button>
    </div>

    <div class="table-card filters-card">
      <div class="filters-row">
        <div class="form-group scope-picker">
          <label>ช่วงรอบเดินทาง</label>
          <select v-model="scheduleScope" @change="loadData" :disabled="loading">
            <option value="upcoming">รอบที่กำลังจะมาถึง</option>
            <option value="all">ทั้งหมด</option>
          </select>
        </div>

        <div class="form-group schedule-picker">
          <label>เลือกรอบเดินทาง</label>
          <select v-model="selectedScheduleId" @change="loadAssignedStaff" :disabled="loading || !scheduleOptions.length">
            <option value="">-- เลือกรอบ --</option>
            <option v-for="sch in scheduleOptions" :key="sch.id" :value="sch.id">
              {{ scheduleOptionLabel(sch) }}
            </option>
          </select>
        </div>

        <div class="search-box schedule-search">
          <span class="material-symbols-rounded">travel_explore</span>
          <input v-model="scheduleSearch" placeholder="ค้นหารอบ/ชื่อทริป/สถานที่" />
        </div>

        <div class="search-box staff-search">
          <span class="material-symbols-rounded">search</span>
          <input v-model="search" placeholder="ค้นหาสตาฟ ชื่อ / อีเมล / เบอร์โทร" />
        </div>
      </div>

      <div v-if="activeScheduleMeta" class="schedule-meta">
        <span class="meta-pill"><span class="material-symbols-rounded">route</span>{{ activeScheduleMeta.trip_title }}</span>
        <span class="meta-pill"><span class="material-symbols-rounded">place</span>{{ activeScheduleMeta.trip_location || '-' }}</span>
        <span class="meta-pill"><span class="material-symbols-rounded">event</span>{{ scheduleDateRange(activeScheduleMeta) }}</span>
        <span class="meta-pill"><span class="material-symbols-rounded">directions_car</span>{{ vehicleLabel(activeScheduleMeta.vehicle, activeScheduleMeta.transport_type) }}</span>
        <span class="meta-pill"><span class="material-symbols-rounded">groups</span>จองแล้ว {{ activeScheduleMeta.active_bookings_count ?? activeScheduleMeta.booked_seats ?? 0 }} รายการ</span>
        <span class="meta-pill"><span class="material-symbols-rounded">event_seat</span>{{ activeScheduleMeta.booked_seats ?? 0 }}/{{ activeScheduleMeta.total_seats ?? 0 }} ที่นั่ง</span>
        <span class="meta-pill status" :class="`status-${activeScheduleMeta.status || 'unknown'}`">
          <span class="material-symbols-rounded">radio_button_checked</span>{{ statusLabel(activeScheduleMeta.status) }}
        </span>
        <span class="meta-pill selected"><span class="material-symbols-rounded">check_circle</span>เลือกแล้ว {{ selectedStaffIds.length }} คน</span>
      </div>
    </div>

    <div class="summary-grid">
      <div class="summary-card">
        <span class="material-symbols-rounded">event_note</span>
        <div>
          <p>รอบที่แสดง</p>
          <strong>{{ scheduleOptions.length }}</strong>
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

    <div class="table-card">
      <div v-if="loading" class="loading-state"><div class="spinner"></div></div>

      <div v-else class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:56px;">เลือก</th>
              <th>สตาฟ</th>
              <th>ติดต่อ</th>
              <th>รอบที่รับผิดชอบ</th>
              <th>คะแนนเฉลี่ย</th>
              <th>จำนวนรีวิว</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="staff in filteredStaff" :key="staff.id" :class="{ selected: isStaffSelected(staff.id) }">
              <td>
                <input
                  class="staff-checkbox"
                  type="checkbox"
                  :checked="isStaffSelected(staff.id)"
                  @change="toggleStaff(staff.id)"
                  :disabled="!selectedScheduleId"
                />
              </td>
              <td>
                <div class="staff-cell">
                  <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="staff-avatar" />
                  <div v-else class="staff-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
                  <span class="staff-name">{{ staff.name }}</span>
                </div>
              </td>
              <td>
                <div class="staff-contact">
                  <div>{{ staff.email || '-' }}</div>
                  <div class="muted">{{ staff.phone || '-' }}</div>
                </div>
              </td>
              <td><span class="count-pill">{{ staff.assigned_schedules_count || 0 }} รอบ</span></td>
              <td>
                <span class="rating-pill" :class="{ empty: !staff.avg_staff_rating }">
                  <span class="material-symbols-rounded">star</span>
                  {{ formatRating(staff.avg_staff_rating) }}
                </span>
              </td>
              <td>{{ staff.total_staff_reviews || 0 }}</td>
            </tr>
            <tr v-if="!filteredStaff.length">
              <td colspan="6" class="empty-state">ไม่พบสตาฟ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="actions-row">
        <div class="selected-preview">
          <span v-if="!selectedStaff.length" class="muted">ยังไม่ได้เลือกสตาฟสำหรับรอบนี้</span>
          <span v-for="staff in selectedStaff" :key="staff.id" class="selected-chip">
            {{ staff.name }}
          </span>
        </div>
        <button class="btn-primary" @click="saveAssignments" :disabled="saving || !selectedScheduleId">
          <span class="material-symbols-rounded" v-if="saving">sync</span>
          บันทึกการมอบหมายสตาฟ
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();

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
    const haystack = `${staff.name || ''} ${staff.email || ''} ${staff.phone || ''}`.toLowerCase();
    return haystack.includes(keyword);
  });
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
  const labels = {
    open: 'เปิดรับจอง',
    closed: 'ปิดรับจอง',
    full: 'เต็มแล้ว',
    cancelled: 'ยกเลิก',
  };
  return labels[status] || status || '-';
};

const formatRating = (rating) => {
  if (!rating) return '-';
  return Number(rating).toFixed(2).replace(/\.?0+$/, '');
};

const scheduleOptionLabel = (schedule) => {
  const title = schedule.trip?.title || 'ไม่ระบุทริป';
  const staffCount = Number(schedule.assigned_staff_count || 0);
  return `${title} · ${scheduleDateRange(schedule)} · สตาฟ ${staffCount} คน`;
};

const normalizeStaffFromUsersApi = (users = []) => {
  return users.map((user) => ({
    id: user.id,
    name: user.name,
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

    const selectedStillExists = schedules.value.some((schedule) => Number(schedule.id) === Number(selectedScheduleId.value));
    if ((!selectedScheduleId.value || !selectedStillExists) && schedules.value.length) {
      selectedScheduleId.value = schedules.value[0].id;
    }

    if (selectedScheduleId.value) {
      await loadAssignedStaff();
    }
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดข้อมูลสตาฟไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const loadAssignedStaff = async () => {
  if (!selectedScheduleId.value) {
    selectedScheduleMeta.value = null;
    selectedStaffIds.value = [];
    return;
  }

  loading.value = true;
  try {
    const res = await admin.fetchScheduleStaff(selectedScheduleId.value);
    selectedScheduleMeta.value = res.data?.schedule || null;
    selectedStaffIds.value = (res.data?.staff || []).map((s) => s.id);
    mergeAssignedStaffStats(res.data?.staff || []);
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดรายการสตาฟของรอบไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const toggleStaff = (staffId) => {
  const normalizedId = Number(staffId);
  if (isStaffSelected(normalizedId)) {
    selectedStaffIds.value = selectedStaffIds.value.filter((id) => Number(id) !== normalizedId);
    return;
  }

  selectedStaffIds.value = [...selectedStaffIds.value, normalizedId];
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

onMounted(loadData);
</script>

<style scoped>
@import url('./admin-shared.css');

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

.scope-picker {
  min-width: 180px;
}

.schedule-picker {
  min-width: 320px;
  flex: 1.2;
}

.schedule-search,
.staff-search {
  min-width: 260px;
  flex: 1;
}

.schedule-meta {
  margin-top: 12px;
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

.meta-pill.selected {
  color: #047857;
  background: #ecfdf5;
  border-color: #a7f3d0;
}

.meta-pill.status-open {
  color: #047857;
  background: #ecfdf5;
  border-color: #a7f3d0;
}

.meta-pill.status-closed,
.meta-pill.status-full {
  color: #92400e;
  background: #fffbeb;
  border-color: #fde68a;
}

.meta-pill.status-cancelled {
  color: #b91c1c;
  background: #fef2f2;
  border-color: #fecaca;
}

.meta-pill .material-symbols-rounded {
  font-size: 15px;
}

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

.summary-card.warning > .material-symbols-rounded {
  background: #fffbeb;
  color: #b45309;
}

.summary-card p {
  margin: 0;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
}

.summary-card strong {
  display: block;
  color: #0f172a;
  font-size: 22px;
  line-height: 1;
  margin-top: 4px;
}

.data-table tbody tr.selected {
  background: #f0fdfa;
}

.staff-checkbox {
  width: 16px;
  height: 16px;
  accent-color: var(--color-accent);
}

.staff-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.staff-avatar {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
}

.staff-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
}

.staff-name {
  font-weight: 600;
  color: #111827;
}

.staff-contact .muted {
  color: #6b7280;
  font-size: 12px;
}

.count-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
}

.rating-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-radius: 999px;
  background: #fff7ed;
  color: #9a3412;
  font-weight: 600;
  font-size: 12px;
}

.rating-pill.empty {
  background: #f3f4f6;
  color: #6b7280;
}

.rating-pill .material-symbols-rounded {
  font-size: 14px;
}

.actions-row {
  border-top: 1px solid #e5e7eb;
  padding: 12px 16px;
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: flex-end;
}

.selected-preview {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.selected-preview .muted {
  color: #6b7280;
  font-size: 13px;
  font-weight: 600;
}

.selected-chip {
  display: inline-flex;
  align-items: center;
  padding: 5px 10px;
  border-radius: 999px;
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #a7f3d0;
  font-size: 12px;
  font-weight: 700;
}

@media (max-width: 900px) {
  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .summary-grid {
    grid-template-columns: 1fr;
  }

  .schedule-picker,
  .schedule-search,
  .staff-search,
  .scope-picker {
    min-width: 100%;
  }

  .actions-row {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
