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
        <div class="form-group schedule-picker">
          <label>เลือกรอบเดินทาง</label>
          <select v-model="selectedScheduleId" @change="loadAssignedStaff" :disabled="loading || !scheduleOptions.length">
            <option value="">-- เลือกรอบ --</option>
            <option v-for="sch in scheduleOptions" :key="sch.id" :value="sch.id">
              {{ sch.trip?.title || 'ไม่ระบุทริป' }} · {{ formatDate(sch.departure_date) }}
            </option>
          </select>
        </div>

        <div class="search-box staff-search">
          <span class="material-symbols-rounded">search</span>
          <input v-model="search" placeholder="ค้นหาสตาฟ ชื่อ / อีเมล / เบอร์โทร" />
        </div>
      </div>

      <div v-if="selectedScheduleMeta" class="schedule-meta">
        <span class="meta-pill"><span class="material-symbols-rounded">route</span>{{ selectedScheduleMeta.trip_title }}</span>
        <span class="meta-pill"><span class="material-symbols-rounded">event</span>{{ formatDate(selectedScheduleMeta.departure_date) }}</span>
        <span class="meta-pill"><span class="material-symbols-rounded">check_circle</span>เลือกแล้ว {{ selectedStaffIds.length }} คน</span>
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
            </tr>
          </thead>
          <tbody>
            <tr v-for="staff in filteredStaff" :key="staff.id">
              <td>
                <input
                  class="staff-checkbox"
                  type="checkbox"
                  :checked="selectedStaffIds.includes(staff.id)"
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
              <td>{{ staff.assigned_schedules_count || 0 }}</td>
              <td>
                <span class="rating-pill" :class="{ empty: !staff.avg_staff_rating }">
                  <span class="material-symbols-rounded">star</span>
                  {{ staff.avg_staff_rating ?? '-' }}
                </span>
              </td>
            </tr>
            <tr v-if="!filteredStaff.length">
              <td colspan="5" class="empty-state">ไม่พบสตาฟ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="actions-row">
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
const selectedScheduleId = ref('');

const schedules = ref([]);
const staffUsers = ref([]);
const selectedScheduleMeta = ref(null);
const selectedStaffIds = ref([]);

const scheduleOptions = computed(() => schedules.value || []);

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

const loadData = async () => {
  loading.value = true;
  try {
    await admin.fetchSchedules({ upcoming: 1, per_page: 100 });
    schedules.value = admin.schedules.data || [];

    const staffRes = await admin.fetchStaffUsers({ per_page: 200 });
    staffUsers.value = staffRes.data || [];

    if (!selectedScheduleId.value && schedules.value.length) {
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
  if (!selectedScheduleId.value) return;

  loading.value = true;
  try {
    const res = await admin.fetchScheduleStaff(selectedScheduleId.value);
    selectedScheduleMeta.value = res.data?.schedule || null;
    selectedStaffIds.value = (res.data?.staff || []).map((s) => s.id);
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดรายการสตาฟของรอบไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const toggleStaff = (staffId) => {
  if (selectedStaffIds.value.includes(staffId)) {
    selectedStaffIds.value = selectedStaffIds.value.filter((id) => id !== staffId);
    return;
  }

  selectedStaffIds.value = [...selectedStaffIds.value, staffId];
};

const saveAssignments = async () => {
  if (!selectedScheduleId.value) return;

  saving.value = true;
  try {
    await admin.syncScheduleStaff(selectedScheduleId.value, selectedStaffIds.value);
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

.schedule-picker {
  min-width: 320px;
  flex: 1;
}

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

.meta-pill .material-symbols-rounded {
  font-size: 15px;
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
  justify-content: flex-end;
}
</style>
