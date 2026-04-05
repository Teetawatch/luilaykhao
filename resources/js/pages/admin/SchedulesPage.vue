<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-calendar-alt"></i> รอบเดินทาง</h1>
        <p class="page-subtitle">จัดการรอบเดินทางและตารางวันเดินทาง</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <i class="fas fa-plus"></i> เพิ่มรอบใหม่
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input v-model="filters.trip_id" placeholder="กรอง Trip ID..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.status" @change="fetchData()">
        <option value="">ทุกสถานะ</option>
        <option value="open">เปิด</option>
        <option value="closed">ปิด</option>
        <option value="full">เต็ม</option>
        <option value="cancelled">ยกเลิก</option>
      </select>
      <label class="checkbox-filter">
        <input type="checkbox" v-model="filters.upcoming" @change="fetchData()" />
        <span>เฉพาะที่กำลังจะถึง</span>
      </label>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>ทริป</th>
              <th>วันเดินทาง</th>
              <th>วันกลับ</th>
              <th>พาหนะ</th>
              <th>ที่นั่ง</th>
              <th>สถานะ</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sch in admin.schedules.data" :key="sch.id">
              <td><span class="trip-name">{{ sch.trip?.title || 'N/A' }}</span></td>
              <td class="date">{{ sch.departure_date }}</td>
              <td class="date">{{ sch.return_date }}</td>
              <td>
                <span class="type-tag" :class="`type-${sch.transport_type === 'van' ? 'trekking' : 'diving'}`">
                  <i :class="sch.transport_type === 'van' ? 'fas fa-shuttle-van' : 'fas fa-ship'"></i>
                  {{ sch.vehicle?.name || sch.transport_type }}
                </span>
              </td>
              <td>
                <div class="seats-info">
                  <div class="seats-bar">
                    <div class="seats-fill" :style="{ width: Math.min(100, ((sch.booked_seats || 0) / sch.total_seats) * 100) + '%' }"></div>
                  </div>
                  <span class="seats-text">{{ sch.booked_seats || 0 }}/{{ sch.total_seats }}</span>
                </div>
              </td>
              <td><span class="status-badge" :class="`status-${sch.status}`">{{ statusLabels[sch.status] }}</span></td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-pickup" @click="openPickupManager(sch)" title="จุดรับผู้โดยสาร">
                    <i class="fas fa-map-marker-alt"></i>
                  </button>
                  <button class="btn-icon btn-edit" @click="openForm(sch)" title="แก้ไข"><i class="fas fa-edit"></i></button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(sch)" title="ลบ"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
            <tr v-if="!admin.schedules.data?.length">
              <td colspan="7" class="empty-state">ไม่พบรอบเดินทาง</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination" v-if="admin.schedules.meta?.last_page > 1">
        <button :disabled="admin.schedules.meta.current_page <= 1" @click="goPage(admin.schedules.meta.current_page - 1)"><i class="fas fa-chevron-left"></i></button>
        <span class="page-info">{{ admin.schedules.meta.current_page }} / {{ admin.schedules.meta.last_page }}</span>
        <button :disabled="admin.schedules.meta.current_page >= admin.schedules.meta.last_page" @click="goPage(admin.schedules.meta.current_page + 1)"><i class="fas fa-chevron-right"></i></button>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm" @click.self="showForm = false">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขรอบเดินทาง' : 'เพิ่มรอบใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ทริป *</label>
              <select v-model.number="form.trip_id" required>
                <option value="" disabled>เลือกทริป</option>
                <option v-for="t in tripOptions" :key="t.id" :value="t.id">{{ t.title }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>วันเดินทาง *</label>
              <input v-model="form.departure_date" type="date" required />
            </div>
            <div class="form-group">
              <label>วันกลับ *</label>
              <input v-model="form.return_date" type="date" required />
            </div>
            <div class="form-group">
              <label>จำนวนที่นั่ง *</label>
              <input v-model.number="form.total_seats" type="number" min="1" required />
            </div>
            <div class="form-group">
              <label>ประเภทพาหนะ *</label>
              <select v-model="form.transport_type" required>
                <option value="van">รถตู้</option>
                <option value="boat">เรือ</option>
                <option value="bus">รถบัส</option>
              </select>
            </div>
            <div class="form-group">
              <label>ยานพาหนะ</label>
              <select v-model="form.vehicle_id">
                <option :value="null">ไม่ระบุ</option>
                <option v-for="v in vehicleOptions" :key="v.id" :value="v.id">{{ v.name }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>ราคาพิเศษ (฿)</label>
              <input v-model.number="form.price_override" type="number" min="0" placeholder="ไม่ระบุใช้ราคาทริป" />
            </div>
            <div class="form-group" v-if="editing">
              <label>สถานะ</label>
              <select v-model="form.status">
                <option value="open">เปิด</option>
                <option value="closed">ปิด</option>
                <option value="full">เต็ม</option>
                <option value="cancelled">ยกเลิก</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <i class="fas fa-spinner fa-spin" v-if="submitting"></i>
              {{ editing ? 'บันทึก' : 'สร้างรอบ' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Pickup Points Manager Modal -->
    <div class="modal-overlay" v-if="showPickupManager" @click.self="showPickupManager = false">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <div>
            <h2><i class="fas fa-map-marker-alt" style="color:#2d7a4f;margin-right:8px;"></i>จุดรับผู้โดยสาร</h2>
            <p class="modal-subtitle" v-if="pickupSchedule">{{ pickupSchedule.trip?.title }} — {{ pickupSchedule.departure_date }}</p>
          </div>
          <button class="modal-close" @click="showPickupManager = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <!-- Add / Edit pickup point form -->
          <div class="pickup-form-section">
            <h3 class="section-label">{{ editingPickup ? 'แก้ไขจุดรับ' : 'เพิ่มจุดรับใหม่' }}</h3>
            <form @submit.prevent="submitPickupForm" class="pickup-form">
              <div class="form-grid">
                <div class="form-group">
                  <label>ภูมิภาค *</label>
                  <select v-model="pickupForm.region" required @change="onRegionChange">
                    <option value="" disabled>เลือกภูมิภาค</option>
                    <option v-for="r in REGIONS" :key="r.value" :value="r.value">{{ r.label }}</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>ชื่อภูมิภาค (ไทย) *</label>
                  <input v-model="pickupForm.region_label" required placeholder="เช่น ภาคเหนือ" />
                </div>
                <div class="form-group full-width">
                  <label>จุดนัดหมาย / จุดขึ้นรถ *</label>
                  <input v-model="pickupForm.pickup_location" required placeholder="เช่น ปั้ม PTT สาขาเชียงใหม่ เหนือ" />
                </div>
                <div class="form-group">
                  <label>ราคา (฿) *</label>
                  <input v-model.number="pickupForm.price" type="number" min="0" required placeholder="0" />
                </div>
                <div class="form-group">
                  <label>ลำดับการแสดง</label>
                  <input v-model.number="pickupForm.sort_order" type="number" min="0" placeholder="0" />
                </div>
                <div class="form-group full-width">
                  <label>Google Maps URL</label>
                  <input v-model="pickupForm.map_url" type="url" placeholder="https://maps.google.com/..." />
                </div>
                <div class="form-group">
                  <label>Latitude</label>
                  <input v-model.number="pickupForm.latitude" type="number" step="any" placeholder="18.7883" />
                </div>
                <div class="form-group">
                  <label>Longitude</label>
                  <input v-model.number="pickupForm.longitude" type="number" step="any" placeholder="98.9853" />
                </div>
                <div class="form-group full-width">
                  <label>หมายเหตุ (เวลานัด, landmark ฯลฯ)</label>
                  <textarea v-model="pickupForm.notes" rows="2" placeholder="เช่น นัดพบ 05:30 น. ก่อนรถออก"></textarea>
                </div>
              </div>
              <div class="pickup-form-actions">
                <button type="button" class="btn-secondary" v-if="editingPickup" @click="cancelEditPickup">ยกเลิก</button>
                <button type="submit" class="btn-primary" :disabled="pickupSubmitting">
                  <i class="fas fa-spinner fa-spin" v-if="pickupSubmitting"></i>
                  {{ editingPickup ? 'บันทึกการแก้ไข' : 'เพิ่มจุดรับ' }}
                </button>
              </div>
            </form>
          </div>

          <!-- Existing pickup points list -->
          <div class="pickup-list-section">
            <h3 class="section-label">จุดรับที่กำหนดไว้ ({{ pickupPoints.length }} รายการ)</h3>
            <div v-if="pickupLoading" class="pickup-loading"><div class="spinner"></div></div>
            <div v-else-if="!pickupPoints.length" class="pickup-empty">
              <i class="fas fa-map-marker-slash"></i>
              <p>ยังไม่มีจุดรับผู้โดยสาร<br>เพิ่มจากฟอร์มด้านบน</p>
            </div>
            <div v-else class="pickup-cards">
              <div v-for="pt in pickupPoints" :key="pt.id" class="pickup-card">
                <div class="pickup-card-header">
                  <span class="region-badge">{{ pt.region_label }}</span>
                  <span class="pickup-price">฿{{ Number(pt.price).toLocaleString() }}</span>
                </div>
                <div class="pickup-card-body">
                  <p class="pickup-location"><i class="fas fa-map-pin"></i> {{ pt.pickup_location }}</p>
                  <p class="pickup-notes" v-if="pt.notes"><i class="fas fa-clock"></i> {{ pt.notes }}</p>
                  <a :href="pt.map_url" target="_blank" class="pickup-map-link" v-if="pt.map_url">
                    <i class="fas fa-external-link-alt"></i> ดูแผนที่
                  </a>
                </div>
                <div class="pickup-card-actions">
                  <button class="btn-icon btn-edit" @click="editPickupPoint(pt)" title="แก้ไข"><i class="fas fa-edit"></i></button>
                  <button class="btn-icon btn-delete" @click="deletePickupPoint(pt)" title="ลบ"><i class="fas fa-trash"></i></button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div class="modal-overlay" v-if="showDeleteConfirm" @click.self="showDeleteConfirm = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบรอบเดินทางนี้ใช่หรือไม่?</p>
          <p class="confirm-warning"><i class="fas fa-exclamation-triangle"></i> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบรอบ</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import api from '../../lib/axios';

const REGIONS = [
  { value: 'north',     label: 'ภาคเหนือ' },
  { value: 'northeast', label: 'ภาคอีสาน' },
  { value: 'central',   label: 'ภาคกลาง' },
  { value: 'east',      label: 'ภาคตะวันออก' },
  { value: 'west',      label: 'ภาคตะวันตก' },
  { value: 'south',     label: 'ภาคใต้' },
];

const admin = useAdminStore();
const filters = reactive({ trip_id: '', status: '', upcoming: false });
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
const tripOptions = ref([]);
const vehicleOptions = ref([]);

const form = reactive({
  trip_id: '', departure_date: '', return_date: '',
  total_seats: 10, transport_type: 'van', vehicle_id: null,
  price_override: null, status: 'open',
});

const statusLabels = { open: 'เปิด', closed: 'ปิด', full: 'เต็ม', cancelled: 'ยกเลิก' };

let debounceTimer = null;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(), 300);
};

const fetchData = (page = 1) => {
  const params = { page };
  if (filters.trip_id) params.trip_id = filters.trip_id;
  if (filters.status) params.status = filters.status;
  if (filters.upcoming) params.upcoming = 1;
  admin.fetchSchedules(params);
};

const goPage = (page) => fetchData(page);

const loadOptions = async () => {
  try {
    const [tripsRes, vehiclesRes] = await Promise.all([
      api.get('/admin/trips', { params: { per_page: 100 } }),
      api.get('/admin/vehicles', { params: { per_page: 100 } }),
    ]);
    tripOptions.value = tripsRes.data.data;
    vehicleOptions.value = vehiclesRes.data.data;
  } catch {}
};

const openForm = (item = null) => {
  editing.value = item;
  if (item) {
    Object.assign(form, {
      trip_id: item.trip_id,
      departure_date: item.departure_date,
      return_date: item.return_date,
      total_seats: item.total_seats,
      transport_type: item.transport_type,
      vehicle_id: item.vehicle?.id || null,
      price_override: item.price || null,
      status: item.status,
    });
  } else {
    Object.assign(form, {
      trip_id: '', departure_date: '', return_date: '',
      total_seats: 10, transport_type: 'van', vehicle_id: null,
      price_override: null, status: 'open',
    });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  try {
    const data = { ...form };
    if (!data.price_override) data.price_override = null;
    if (editing.value) {
      await admin.updateSchedule(editing.value.id, data);
    } else {
      await admin.createSchedule(data);
    }
    showForm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (item) => { deleting.value = item; showDeleteConfirm.value = true; };

// ─── Pickup Points Manager ───────────────────────────────
const showPickupManager = ref(false);
const pickupSchedule = ref(null);
const pickupPoints = ref([]);
const pickupLoading = ref(false);
const pickupSubmitting = ref(false);
const editingPickup = ref(null);

const pickupForm = reactive({
  region: '', region_label: '', pickup_location: '',
  price: '', map_url: '', latitude: null, longitude: null,
  notes: '', sort_order: 0,
});

const resetPickupForm = () => {
  Object.assign(pickupForm, {
    region: '', region_label: '', pickup_location: '',
    price: '', map_url: '', latitude: null, longitude: null,
    notes: '', sort_order: 0,
  });
  editingPickup.value = null;
};

const onRegionChange = () => {
  const found = REGIONS.find(r => r.value === pickupForm.region);
  if (found && !editingPickup.value) {
    pickupForm.region_label = found.label;
  }
};

const openPickupManager = async (sch) => {
  pickupSchedule.value = sch;
  resetPickupForm();
  showPickupManager.value = true;
  await loadPickupPoints(sch.id);
};

const loadPickupPoints = async (scheduleId) => {
  pickupLoading.value = true;
  try {
    const res = await api.get(`/admin/schedules/${scheduleId}/pickup-points`);
    pickupPoints.value = res.data.data;
  } catch {
    pickupPoints.value = [];
  } finally {
    pickupLoading.value = false;
  }
};

const submitPickupForm = async () => {
  pickupSubmitting.value = true;
  try {
    const scheduleId = pickupSchedule.value.id;
    const payload = { ...pickupForm };
    if (!payload.map_url) payload.map_url = null;
    if (!payload.latitude) payload.latitude = null;
    if (!payload.longitude) payload.longitude = null;

    if (editingPickup.value) {
      await api.put(`/admin/schedules/${scheduleId}/pickup-points/${editingPickup.value.id}`, payload);
    } else {
      await api.post(`/admin/schedules/${scheduleId}/pickup-points`, payload);
    }
    resetPickupForm();
    await loadPickupPoints(scheduleId);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

const editPickupPoint = (pt) => {
  editingPickup.value = pt;
  Object.assign(pickupForm, {
    region: pt.region,
    region_label: pt.region_label,
    pickup_location: pt.pickup_location,
    price: pt.price,
    map_url: pt.map_url || '',
    latitude: pt.latitude || null,
    longitude: pt.longitude || null,
    notes: pt.notes || '',
    sort_order: pt.sort_order || 0,
  });
};

const cancelEditPickup = () => resetPickupForm();

const deletePickupPoint = async (pt) => {
  if (!confirm(`ลบจุดรับ "${pt.region_label}" ใช่หรือไม่?`)) return;
  try {
    await api.delete(`/admin/schedules/${pickupSchedule.value.id}/pickup-points/${pt.id}`);
    await loadPickupPoints(pickupSchedule.value.id);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  }
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await admin.deleteSchedule(deleting.value.id);
    showDeleteConfirm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  fetchData();
  loadOptions();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.checkbox-filter {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
  padding: 0 4px;
}

.checkbox-filter input[type="checkbox"] {
  accent-color: #2d7a4f;
  width: 15px;
  height: 15px;
}

.seats-info {
  display: flex;
  align-items: center;
  gap: 10px;
}

.seats-bar {
  width: 60px;
  height: 5px;
  background: #e5e7eb;
  border-radius: 3px;
  overflow: hidden;
}

.seats-fill {
  height: 100%;
  background: #2d7a4f;
  border-radius: 3px;
  transition: width 0.3s;
}

.seats-text {
  font-size: 13px;
  color: #6b7280;
  font-weight: 600;
}

.btn-pickup {
  color: #7c3aed;
}
.btn-pickup:hover {
  background: #f5f3ff;
  border-color: #c4b5fd;
}

.modal-lg {
  max-width: 860px;
}

.modal-subtitle {
  font-size: 13px;
  color: #6b7280;
  margin: 2px 0 0;
}

.pickup-form-section {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 18px;
  margin-bottom: 24px;
  background: #FAFAFA;
}

.section-label {
  font-size: 13px;
  font-weight: 700;
  color: #374151;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin: 0 0 14px;
}

.pickup-form .form-grid {
  gap: 12px;
}

.pickup-form-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 14px;
}

.pickup-list-section {
  margin-top: 8px;
}

.pickup-loading {
  display: flex;
  justify-content: center;
  padding: 30px;
}

.pickup-empty {
  text-align: center;
  padding: 32px;
  color: #9ca3af;
}

.pickup-empty i {
  font-size: 28px;
  margin-bottom: 10px;
  display: block;
}

.pickup-empty p {
  font-size: 14px;
  line-height: 1.6;
  margin: 0;
}

.pickup-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}

.pickup-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #ffffff;
  overflow: hidden;
}

.pickup-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: #f0faf4;
  border-bottom: 1px solid #e5e7eb;
}

.region-badge {
  font-size: 13px;
  font-weight: 700;
  color: #2d7a4f;
}

.pickup-price {
  font-size: 14px;
  font-weight: 700;
  color: #111827;
}

.pickup-card-body {
  padding: 12px 14px;
}

.pickup-location {
  font-size: 13px;
  color: #374151;
  margin: 0 0 6px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
}

.pickup-location i { color: #dc2626; margin-top: 2px; flex-shrink: 0; }

.pickup-notes {
  font-size: 12px;
  color: #6b7280;
  margin: 0 0 6px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
}

.pickup-notes i { color: #f59e0b; margin-top: 2px; flex-shrink: 0; }

.pickup-map-link {
  font-size: 12px;
  color: #2d7a4f;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 4px;
}

.pickup-map-link:hover { text-decoration: underline; }

.pickup-card-actions {
  display: flex;
  gap: 6px;
  padding: 8px 14px;
  border-top: 1px solid #f3f4f6;
  justify-content: flex-end;
}
</style>
