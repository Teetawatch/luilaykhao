<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">build</span> การบำรุงรักษายานพาหนะ</h1>
        <p class="page-subtitle">ติดตามการบำรุงรักษาและซ่อมบำรุง</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add</span> เพิ่มรายการ
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="searchQuery" placeholder="ค้นหา..." @input="filterLocal" />
      </div>
      <select v-model="filters.vehicle_id" @change="fetchData()">
        <option value="">ทุกยานพาหนะ</option>
        <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }}</option>
      </select>
      <select v-model="filters.status" @change="fetchData()">
        <option value="">ทุกสถานะ</option>
        <option value="scheduled">นัดหมาย</option>
        <option value="in_progress">กำลังดำเนินการ</option>
        <option value="completed">เสร็จสิ้น</option>
        <option value="overdue">เกินกำหนด</option>
      </select>
      <select v-model="filters.type" @change="fetchData()">
        <option value="">ทุกประเภท</option>
        <option value="routine">ประจำ</option>
        <option value="repair">ซ่อม</option>
        <option value="inspection">ตรวจสภาพ</option>
        <option value="insurance">ประกันภัย</option>
        <option value="registration">ทะเบียน</option>
      </select>
    </div>

    <!-- Status Summary -->
    <div class="status-summary">
      <div class="summary-card summary-scheduled">
        <span class="material-symbols-rounded">schedule</span>
        <span class="sum-val">{{ statusCounts.scheduled }}</span>
        <span class="sum-label">นัดหมาย</span>
      </div>
      <div class="summary-card summary-progress">
        <span class="material-symbols-rounded">handyman</span>
        <span class="sum-val">{{ statusCounts.in_progress }}</span>
        <span class="sum-label">กำลังดำเนินการ</span>
      </div>
      <div class="summary-card summary-completed">
        <span class="material-symbols-rounded">check_circle</span>
        <span class="sum-val">{{ statusCounts.completed }}</span>
        <span class="sum-label">เสร็จสิ้น</span>
      </div>
      <div class="summary-card summary-overdue">
        <span class="material-symbols-rounded">warning</span>
        <span class="sum-val">{{ statusCounts.overdue }}</span>
        <span class="sum-label">เกินกำหนด</span>
      </div>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
      <template v-else>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>ยานพาหนะ</th>
                <th>ประเภท</th>
                <th>รายการ</th>
                <th>วันนัดหมาย</th>
                <th>สถานะ</th>
                <th>ค่าใช้จ่าย</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in filteredData" :key="m.id">
                <td>
                  <div class="vehicle-cell">
                    <div class="vehicle-mini-icon" :class="`vtype-${m.vehicle_type}`">
                      <span class="material-symbols-rounded" style="font-size:16px;">
                        {{ vehicleTypeIcon(m.vehicle_type) }}
                      </span>
                    </div>
                    <span>{{ m.vehicle_name }}</span>
                  </div>
                </td>
                <td><span class="type-tag" :class="`mtype-${m.type}`">{{ typeLabels[m.type] }}</span></td>
                <td>
                  <span class="maint-title">{{ m.title }}</span>
                  <span class="maint-desc" v-if="m.description">{{ m.description }}</span>
                </td>
                <td class="date">{{ formatDate(m.scheduled_date) }}</td>
                <td><span class="status-badge" :class="`mstatus-${m.status}`">{{ statusLabels[m.status] }}</span></td>
                <td class="money">{{ formatMoney(m.cost) }}</td>
                <td>
                  <div class="action-btns">
                    <button class="btn-icon btn-edit" @click="openForm(m)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                    <button class="btn-icon btn-delete" @click="confirmDelete(m)" title="ลบ"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                  </div>
                </td>
              </tr>
              <tr v-if="!filteredData.length">
                <td colspan="7" class="empty-state">ไม่มีรายการบำรุงรักษา</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pagination" v-if="admin.maintenances.meta?.last_page > 1">
          <button @click="goPage(admin.maintenances.meta.current_page - 1)" :disabled="admin.maintenances.meta.current_page <= 1">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <span class="page-info">{{ admin.maintenances.meta.current_page }} / {{ admin.maintenances.meta.last_page }}</span>
          <button @click="goPage(admin.maintenances.meta.current_page + 1)" :disabled="admin.maintenances.meta.current_page >= admin.maintenances.meta.last_page">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
      </template>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขรายการบำรุงรักษา' : 'เพิ่มรายการบำรุงรักษา' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label>ยานพาหนะ *</label>
              <select v-model="form.vehicle_id" required>
                <option value="">เลือกยานพาหนะ</option>
                <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" required>
                <option value="routine">ประจำ</option>
                <option value="repair">ซ่อม</option>
                <option value="inspection">ตรวจสภาพ</option>
                <option value="insurance">ประกันภัย</option>
                <option value="registration">ทะเบียน</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label>รายการ *</label>
              <input v-model="form.title" required placeholder="เช่น เปลี่ยนถ่ายน้ำมันเครื่อง" />
            </div>
            <div class="form-group full-width">
              <label>รายละเอียด</label>
              <textarea v-model="form.description" rows="2" placeholder="รายละเอียดเพิ่มเติม..."></textarea>
            </div>
            <div class="form-group">
              <label>วันนัดหมาย *</label>
              <input v-model="form.scheduled_date" type="date" required />
            </div>
            <div class="form-group">
              <label>วันที่เสร็จ</label>
              <input v-model="form.completed_date" type="date" />
            </div>
            <div class="form-group">
              <label>สถานะ</label>
              <select v-model="form.status">
                <option value="scheduled">นัดหมาย</option>
                <option value="in_progress">กำลังดำเนินการ</option>
                <option value="completed">เสร็จสิ้น</option>
                <option value="overdue">เกินกำหนด</option>
              </select>
            </div>
            <div class="form-group">
              <label>ค่าใช้จ่าย (บาท)</label>
              <input v-model.number="form.cost" type="number" min="0" step="0.01" />
            </div>
            <div class="form-group">
              <label>ผู้ดำเนินการ</label>
              <input v-model="form.performed_by" placeholder="ชื่อช่าง / อู่" />
            </div>
            <div class="form-group">
              <label>กม. ถัดไป</label>
              <input v-model.number="form.next_km" type="number" min="0" />
            </div>
            <div class="form-group full-width">
              <label>หมายเหตุ</label>
              <textarea v-model="form.notes" rows="2" placeholder="หมายเหตุ..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ editing ? 'บันทึก' : 'สร้าง' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div class="modal-overlay" v-if="showDeleteConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบ <strong>{{ deleting?.title }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบ</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import { vehicleTypeIcon } from '../../lib/vehicleDisplay';

const admin = useAdminStore();
const searchQuery = ref('');
const filters = reactive({ vehicle_id: '', status: '', type: '' });
const vehicles = ref([]);
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);

const defaultForm = {
  vehicle_id: '', type: 'routine', title: '', description: '',
  scheduled_date: '', completed_date: '', status: 'scheduled',
  cost: 0, performed_by: '', notes: '', next_km: null,
};
const form = reactive({ ...defaultForm });

const typeLabels = { routine: 'ประจำ', repair: 'ซ่อม', inspection: 'ตรวจสภาพ', insurance: 'ประกันภัย', registration: 'ทะเบียน' };
const statusLabels = { scheduled: 'นัดหมาย', in_progress: 'กำลังดำเนินการ', completed: 'เสร็จสิ้น', overdue: 'เกินกำหนด' };

const filteredData = computed(() => {
  let data = admin.maintenances.data || [];
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    data = data.filter(m => m.title.toLowerCase().includes(q) || m.vehicle_name.toLowerCase().includes(q));
  }
  return data;
});

const statusCounts = computed(() => {
  const data = admin.maintenances.data || [];
  return {
    scheduled: data.filter(m => m.status === 'scheduled').length,
    in_progress: data.filter(m => m.status === 'in_progress').length,
    completed: data.filter(m => m.status === 'completed').length,
    overdue: data.filter(m => m.status === 'overdue').length,
  };
});

function formatMoney(amount) {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function filterLocal() { /* reactive computed handles this */ }

function fetchData(page = 1) {
  admin.fetchMaintenances({
    vehicle_id: filters.vehicle_id || undefined,
    status: filters.status || undefined,
    type: filters.type || undefined,
    page,
  });
}

function goPage(p) { fetchData(p); }

function openForm(m = null) {
  editing.value = m;
  if (m) {
    Object.assign(form, {
      vehicle_id: m.vehicle_id, type: m.type, title: m.title,
      description: m.description || '', scheduled_date: m.scheduled_date || '',
      completed_date: m.completed_date || '', status: m.status,
      cost: m.cost, performed_by: m.performed_by || '',
      notes: m.notes || '', next_km: m.next_km,
    });
  } else {
    Object.assign(form, defaultForm);
  }
  showForm.value = true;
}

async function submitForm() {
  submitting.value = true;
  try {
    if (editing.value) {
      await admin.updateMaintenance(editing.value.id, form);
    } else {
      await admin.createMaintenance(form);
    }
    showForm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
}

function confirmDelete(m) { deleting.value = m; showDeleteConfirm.value = true; }

async function doDelete() {
  submitting.value = true;
  try {
    await admin.deleteMaintenance(deleting.value.id);
    showDeleteConfirm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
}

async function loadVehicles() {
  try {
    await admin.fetchVehicles({ per_page: 100 });
    vehicles.value = admin.vehicles.data;
  } catch (e) { /* ignore */ }
}

onMounted(() => {
  fetchData();
  loadVehicles();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.status-summary {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 20px;
}

.summary-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  background: var(--color-white);
  border-radius: 10px;
  border: 1px solid var(--color-sand-dark);
}

.summary-card .material-symbols-rounded {
  font-size: 24px;
}

.summary-scheduled .material-symbols-rounded { color: #1d4ed8; }
.summary-progress .material-symbols-rounded { color: #d97706; }
.summary-completed .material-symbols-rounded { color: #16a34a; }
.summary-overdue .material-symbols-rounded { color: #dc2626; }

.sum-val {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-text-dark);
}

.sum-label {
  font-size: 12px;
  color: var(--color-text-muted);
}

.vehicle-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.vehicle-mini-icon {
  width: 30px;
  height: 30px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.vtype-van { background: var(--color-sand); color: var(--color-accent); }
.vtype-boat { background: #eff6ff; color: #2563eb; }
.vtype-bus { background: #f5f3ff; color: #6d28d9; }

.mtype-routine { background: #eff6ff; color: #2563eb; }
.mtype-repair { background: #fef2f2; color: #dc2626; }
.mtype-inspection { background: #fef9c3; color: #ca8a04; }
.mtype-insurance { background: #f3e8ff; color: #9333ea; }
.mtype-registration { background: var(--color-sand); color: var(--color-accent); }

.mstatus-scheduled { background: #eff6ff; color: #2563eb; }
.mstatus-in_progress { background: #fef9c3; color: #ca8a04; }
.mstatus-completed { background: var(--color-sand); color: var(--color-accent); }
.mstatus-overdue { background: #fef2f2; color: #dc2626; }

.maint-title {
  display: block;
  font-weight: 600;
  color: var(--color-text-dark);
  font-size: 14px;
}

.maint-desc {
  display: block;
  font-size: 12px;
  color: var(--color-text-muted);
  margin-top: 2px;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 768px) {
  .status-summary {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
