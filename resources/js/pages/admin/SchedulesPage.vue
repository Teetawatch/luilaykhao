<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-calendar-alt"></i> รอบเดินทาง</h1>
        <p class="page-subtitle">จัดการรอบเดินทางและตารางวันเดินทาง</p>
      </div>
      <div style="display:flex;gap:10px;">
        <button class="btn-secondary" @click="openBatchForm()">
          <i class="fas fa-layer-group"></i> สร้างหลายรอบพร้อมกัน
        </button>
        <button class="btn-primary" @click="openForm()">
          <i class="fas fa-plus"></i> เพิ่มรอบใหม่
        </button>
      </div>
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
              <td class="date">
                {{ sch.departure_date }}
                <div v-if="sch.pickup_points?.length" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;">
                  <span v-for="pt in sch.pickup_points" :key="pt.id" class="region-pill">{{ pt.region_label }}</span>
                </div>
              </td>
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
                  <button class="btn-icon btn-copy" @click="copyPickupPoints(sch)" title="คัดลอกจุดรับไปรอบอื่น">
                    <i class="fas fa-copy"></i>
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
    <div class="modal-overlay" v-if="showForm">
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

    <!-- Pickup Points Manager Modal (Region-grouped) -->
    <div class="modal-overlay" v-if="showPickupManager">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><i class="fas fa-map-marker-alt" style="color:#2d7a4f;margin-right:8px;"></i>จุดรับผู้โดยสาร</h2>
            <p class="modal-subtitle" v-if="pickupSchedule">{{ pickupSchedule.trip?.title }} — {{ pickupSchedule.departure_date }}</p>
          </div>
          <button class="modal-close" @click="showPickupManager = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <div v-if="pickupLoading" class="pickup-loading"><div class="spinner"></div></div>
          <template v-else>

            <!-- Region sections -->
            <div v-for="r in REGIONS" :key="r.value" class="pickup-region-section">
              <div class="pickup-region-header">
                <div class="pickup-region-title">
                  <span class="region-dot"></span>
                  <span>{{ r.label }}</span>
                  <span class="pickup-region-count">{{ pickupPointsByRegion[r.value]?.length || 0 }} จุด</span>
                </div>
                <button type="button" class="btn-sm btn-secondary" @click="startAddInRegion(r.value)">
                  <i class="fas fa-plus"></i> เพิ่มจุด
                </button>
              </div>

              <!-- Points in this region -->
              <div class="pickup-region-body">
                <!-- Existing points -->
                <div v-if="pickupPointsByRegion[r.value]?.length" class="pickup-region-list">
                  <div v-for="pt in pickupPointsByRegion[r.value]" :key="pt.id" class="pickup-item">
                    <template v-if="editingPickup?.id === pt.id">
                      <!-- Inline edit form -->
                      <div class="pickup-item-edit">
                        <div class="pif-row">
                          <input v-model="pickupForm.pickup_location" placeholder="จุดขึ้นรถ *" class="pif-location" />
                          <input v-model="pickupForm.notes" placeholder="เวลานัด / หมายเหตุ" class="pif-notes" />
                          <div class="pif-price-wrap">
                            <span class="pif-baht">฿</span>
                            <input v-model.number="pickupForm.price" type="number" min="0" placeholder="ราคา" class="pif-price" />
                          </div>
                        </div>
                        <div class="pif-row">
                          <input v-model="pickupForm.map_url" placeholder="Google Maps URL (ไม่บังคับ)" class="pif-map" />
                          <input v-model.number="pickupForm.sort_order" type="number" min="0" placeholder="ลำดับ" class="pif-order" />
                        </div>
                        <div class="pif-actions">
                          <button type="button" class="btn-sm btn-secondary" @click="cancelEditPickup">ยกเลิก</button>
                          <button type="button" class="btn-sm btn-primary" @click="submitPickupForm" :disabled="pickupSubmitting">
                            <i class="fas fa-spinner fa-spin" v-if="pickupSubmitting"></i> บันทึก
                          </button>
                        </div>
                      </div>
                    </template>
                    <template v-else>
                      <!-- Display row -->
                      <div class="pickup-item-display">
                        <div class="pid-left">
                          <span class="pid-location"><i class="fas fa-map-pin"></i> {{ pt.pickup_location }}</span>
                          <span class="pid-notes" v-if="pt.notes"><i class="fas fa-clock"></i> {{ pt.notes }}</span>
                        </div>
                        <div class="pid-right">
                          <a :href="pt.map_url" target="_blank" class="pid-map" v-if="pt.map_url" title="ดูแผนที่">
                            <i class="fas fa-map"></i>
                          </a>
                          <span class="pid-price">฿{{ Number(pt.price).toLocaleString() }}</span>
                          <button class="btn-icon btn-edit" @click="editPickupPoint(pt)" title="แก้ไข"><i class="fas fa-edit"></i></button>
                          <button class="btn-icon btn-delete" @click="deletePickupPoint(pt)" title="ลบ"><i class="fas fa-trash"></i></button>
                        </div>
                      </div>
                    </template>
                  </div>
                </div>

                <!-- Inline add form for this region -->
                <div v-if="addingInRegion === r.value" class="pickup-item-edit pickup-item-add">
                  <div class="pif-row">
                    <input v-model="pickupForm.pickup_location" placeholder="จุดขึ้นรถ *" class="pif-location" />
                    <input v-model="pickupForm.notes" placeholder="เวลานัด / หมายเหตุ" class="pif-notes" />
                    <div class="pif-price-wrap">
                      <span class="pif-baht">฿</span>
                      <input v-model.number="pickupForm.price" type="number" min="0" placeholder="ราคา" class="pif-price" />
                    </div>
                  </div>
                  <div class="pif-row">
                    <input v-model="pickupForm.map_url" placeholder="Google Maps URL (ไม่บังคับ)" class="pif-map" />
                    <input v-model.number="pickupForm.sort_order" type="number" min="0" placeholder="ลำดับ" class="pif-order" />
                  </div>
                  <div class="pif-actions">
                    <button type="button" class="btn-sm btn-secondary" @click="addingInRegion = null">ยกเลิก</button>
                    <button type="button" class="btn-sm btn-primary" @click="submitPickupForm" :disabled="pickupSubmitting">
                      <i class="fas fa-spinner fa-spin" v-if="pickupSubmitting"></i> เพิ่มจุดรับ
                    </button>
                  </div>
                </div>

                <!-- Empty state for region -->
                <div v-if="!pickupPointsByRegion[r.value]?.length && addingInRegion !== r.value" class="pickup-region-empty">
                  <i class="fas fa-map-marker-slash"></i> ยังไม่มีจุดรับในภาคนี้
                </div>
              </div>
            </div>

          </template>
        </div>
      </div>
    </div>

    <!-- Batch Create Modal -->
    <div class="modal-overlay" v-if="showBatchForm">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><i class="fas fa-layer-group" style="color:#2d7a4f;margin-right:8px;"></i>สร้างหลายรอบพร้อมกัน</h2>
            <p class="modal-subtitle">กำหนดพาหนะ + จุดขึ้นรถครั้งเดียว แล้วเพิ่มวันเดินทางได้หลายรอบ</p>
          </div>
          <button class="modal-close" @click="showBatchForm = false"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="submitBatchForm" class="modal-body">

          <!-- Section 1: Trip + Vehicle -->
          <div class="batch-section">
            <h3 class="section-label"><i class="fas fa-info-circle"></i> ข้อมูลพื้นฐาน</h3>
            <div class="form-grid">
              <div class="form-group full-width">
                <label>ทริป *</label>
                <select v-model.number="batchForm.trip_id" required>
                  <option value="" disabled>เลือกทริป</option>
                  <option v-for="t in tripOptions" :key="t.id" :value="t.id">{{ t.title }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>ประเภทพาหนะ *</label>
                <select v-model="batchForm.transport_type" required>
                  <option value="van">รถตู้</option>
                  <option value="boat">เรือ</option>
                  <option value="bus">รถบัส</option>
                </select>
              </div>
              <div class="form-group">
                <label>ยานพาหนะ</label>
                <select v-model="batchForm.vehicle_id">
                  <option :value="null">ไม่ระบุ</option>
                  <option v-for="v in vehicleOptions" :key="v.id" :value="v.id">{{ v.name }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>จำนวนที่นั่ง / รอบ *</label>
                <input v-model.number="batchForm.total_seats" type="number" min="1" required />
              </div>
              <div class="form-group">
                <label>ราคาพิเศษ (฿)</label>
                <input v-model.number="batchForm.price_override" type="number" min="0" placeholder="ไม่ระบุใช้ราคาทริป" />
              </div>
            </div>
          </div>

          <!-- Section 2: Dates -->
          <div class="batch-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
              <h3 class="section-label" style="margin:0;"><i class="fas fa-calendar-alt"></i> วันเดินทาง ({{ batchForm.dates.length }} รอบ)</h3>
              <button type="button" class="btn-sm btn-secondary" @click="addDateRow">
                <i class="fas fa-plus"></i> เพิ่มวัน
              </button>
            </div>
            <div class="date-rows">
              <div v-for="(d, i) in batchForm.dates" :key="i" class="date-row">
                <span class="date-row-num">{{ i + 1 }}</span>
                <div class="form-group" style="flex:1;margin:0;">
                  <input v-model="d.departure_date" type="date" required placeholder="วันเดินทาง" />
                </div>
                <span style="color:#9ca3af;font-size:13px;">→</span>
                <div class="form-group" style="flex:1;margin:0;">
                  <input v-model="d.return_date" type="date" required placeholder="วันกลับ" />
                </div>
                <button type="button" class="btn-icon btn-delete" @click="removeDateRow(i)" :disabled="batchForm.dates.length <= 1">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
          </div>

          <!-- Section 3: Pickup Points -->
          <div class="batch-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
              <h3 class="section-label" style="margin:0;"><i class="fas fa-map-marker-alt"></i> จุดขึ้นรถแต่ละภูมิภาค</h3>
              <button type="button" class="btn-sm btn-secondary" @click="addPickupRow">
                <i class="fas fa-plus"></i> เพิ่มภูมิภาค
              </button>
            </div>
            <div class="pickup-inline-list">
              <div v-for="(pt, i) in batchForm.pickups" :key="i" class="pickup-inline-row">
                <div class="pickup-inline-left">
                  <select v-model="pt.region" @change="onBatchRegionChange(pt)" style="min-width:130px;">
                    <option value="" disabled>ภูมิภาค</option>
                    <option v-for="r in REGIONS" :key="r.value" :value="r.value">{{ r.label }}</option>
                  </select>
                  <input v-model="pt.pickup_location" placeholder="จุดขึ้นรถ เช่น ปั้ม PTT เชียงใหม่" style="flex:2;" />
                  <input v-model="pt.notes" placeholder="เวลานัด เช่น 05:30 น." style="flex:1;" />
                </div>
                <div class="pickup-inline-right">
                  <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:12px;color:#6b7280;">฿</span>
                    <input v-model.number="pt.price" type="number" min="0" placeholder="ราคา" style="width:90px;" />
                  </div>
                  <input v-model="pt.map_url" placeholder="Maps URL (ไม่บังคับ)" style="flex:1;" />
                  <button type="button" class="btn-icon btn-delete" @click="removePickupRow(i)">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>
              <div v-if="!batchForm.pickups.length" class="pickup-inline-empty">
                <i class="fas fa-map-marker-slash"></i> ไม่มีจุดขึ้นรถ (เพิ่มได้ภายหลัง)
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showBatchForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="batchSubmitting">
              <i class="fas fa-spinner fa-spin" v-if="batchSubmitting"></i>
              {{ batchSubmitting ? 'กำลังสร้าง...' : `สร้าง ${batchForm.dates.length} รอบ` }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Copy Pickup Modal -->
    <div class="modal-overlay" v-if="showCopyModal">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2><i class="fas fa-copy" style="color:#2d7a4f;margin-right:8px;"></i>คัดลอกจุดรับ</h2>
          <button class="modal-close" @click="showCopyModal = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;color:#374151;margin-bottom:12px;">
            คัดลอกจุดรับจาก <strong>{{ copySource?.departure_date }}</strong> ไปยังรอบ:
          </p>
          <div class="copy-target-list">
            <label v-for="sch in copyTargets" :key="sch.id" class="copy-target-item">
              <input type="checkbox" v-model="copySelectedIds" :value="sch.id" />
              <span>{{ sch.departure_date }} — {{ sch.trip?.title }}</span>
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showCopyModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="doCopyPickups" :disabled="!copySelectedIds.length || copySubmitting">
            <i class="fas fa-spinner fa-spin" v-if="copySubmitting"></i>
            คัดลอกไป {{ copySelectedIds.length }} รอบ
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div class="modal-overlay" v-if="showDeleteConfirm">
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
import { ref, reactive, computed, onMounted } from 'vue';
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

// ─── Batch Create ──────────────────────────────────────────
const showBatchForm = ref(false);
const batchSubmitting = ref(false);

const batchForm = reactive({
  trip_id: '',
  transport_type: 'van',
  vehicle_id: null,
  total_seats: 10,
  price_override: null,
  dates: [{ departure_date: '', return_date: '' }],
  pickups: [],
});

const openBatchForm = () => {
  Object.assign(batchForm, {
    trip_id: '',
    transport_type: 'van',
    vehicle_id: null,
    total_seats: 10,
    price_override: null,
    dates: [{ departure_date: '', return_date: '' }],
    pickups: [],
  });
  showBatchForm.value = true;
};

const addDateRow = () => batchForm.dates.push({ departure_date: '', return_date: '' });
const removeDateRow = (i) => { if (batchForm.dates.length > 1) batchForm.dates.splice(i, 1); };

const addPickupRow = () => batchForm.pickups.push({
  region: '', region_label: '', pickup_location: '', price: '', notes: '', map_url: '',
});
const removePickupRow = (i) => batchForm.pickups.splice(i, 1);

const onBatchRegionChange = (pt) => {
  const found = REGIONS.find(r => r.value === pt.region);
  if (found) pt.region_label = found.label;
};

const submitBatchForm = async () => {
  batchSubmitting.value = true;
  try {
    const results = [];
    for (const d of batchForm.dates) {
      const scheduleRes = await api.post('/admin/schedules', {
        trip_id: batchForm.trip_id,
        transport_type: batchForm.transport_type,
        vehicle_id: batchForm.vehicle_id,
        total_seats: batchForm.total_seats,
        price_override: batchForm.price_override || null,
        departure_date: d.departure_date,
        return_date: d.return_date,
        status: 'open',
      });
      const scheduleId = scheduleRes.data.data.id;
      for (const pt of batchForm.pickups) {
        if (!pt.region || !pt.pickup_location || pt.price === '') continue;
        await api.post(`/admin/schedules/${scheduleId}/pickup-points`, {
          region: pt.region,
          region_label: pt.region_label,
          pickup_location: pt.pickup_location,
          price: pt.price,
          notes: pt.notes || null,
          map_url: pt.map_url || null,
        });
      }
      results.push(scheduleId);
    }
    showBatchForm.value = false;
    fetchData();
    alert(`สร้าง ${results.length} รอบสำเร็จ`);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    batchSubmitting.value = false;
  }
};

// ─── Copy Pickup Points ────────────────────────────────────
const showCopyModal = ref(false);
const copySource = ref(null);
const copySelectedIds = ref([]);
const copySubmitting = ref(false);

const copyTargets = computed(() =>
  (admin.schedules.data || []).filter(s => s.id !== copySource.value?.id)
);

const copyPickupPoints = async (sch) => {
  copySource.value = sch;
  copySelectedIds.value = [];
  showCopyModal.value = true;
};

const doCopyPickups = async () => {
  copySubmitting.value = true;
  try {
    const res = await api.get(`/admin/schedules/${copySource.value.id}/pickup-points`);
    const points = res.data.data;
    for (const targetId of copySelectedIds.value) {
      for (const pt of points) {
        await api.post(`/admin/schedules/${targetId}/pickup-points`, {
          region: pt.region,
          region_label: pt.region_label,
          pickup_location: pt.pickup_location,
          price: pt.price,
          notes: pt.notes || null,
          map_url: pt.map_url || null,
          latitude: pt.latitude || null,
          longitude: pt.longitude || null,
          sort_order: pt.sort_order || 0,
        });
      }
    }
    showCopyModal.value = false;
    fetchData();
    alert(`คัดลอกจุดรับไป ${copySelectedIds.value.length} รอบสำเร็จ`);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    copySubmitting.value = false;
  }
};

// ─── Pickup Points Manager ───────────────────────────────
const showPickupManager = ref(false);
const pickupSchedule = ref(null);
const pickupPoints = ref([]);
const pickupLoading = ref(false);
const pickupSubmitting = ref(false);
const editingPickup = ref(null);
const addingInRegion = ref(null); // which region's inline-add form is open

const pickupForm = reactive({
  region: '', region_label: '', pickup_location: '',
  price: '', map_url: '', latitude: null, longitude: null,
  notes: '', sort_order: 0,
});

// Group pickup points by region value
const pickupPointsByRegion = computed(() => {
  const map = {};
  for (const pt of pickupPoints.value) {
    if (!map[pt.region]) map[pt.region] = [];
    map[pt.region].push(pt);
  }
  return map;
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
  addingInRegion.value = null;
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

// Open inline-add form for a specific region
const startAddInRegion = (regionValue) => {
  editingPickup.value = null;
  const found = REGIONS.find(r => r.value === regionValue);
  Object.assign(pickupForm, {
    region: regionValue,
    region_label: found?.label || regionValue,
    pickup_location: '', price: '', map_url: '',
    latitude: null, longitude: null, notes: '', sort_order: 0,
  });
  addingInRegion.value = regionValue;
};

const submitPickupForm = async () => {
  if (!pickupForm.pickup_location || pickupForm.price === '') {
    alert('กรุณากรอกจุดขึ้นรถและราคา');
    return;
  }
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
    addingInRegion.value = null;
    await loadPickupPoints(scheduleId);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

const editPickupPoint = (pt) => {
  addingInRegion.value = null;
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

const cancelEditPickup = () => {
  resetPickupForm();
  addingInRegion.value = null;
};

const deletePickupPoint = async (pt) => {
  if (!confirm(`ลบจุดรับ "${pt.pickup_location}" ใช่หรือไม่?`)) return;
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

.region-pill {
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  color: #2d7a4f;
  background: #e8f5ec;
  border: 1px solid #b7dfc5;
  border-radius: 20px;
  padding: 1px 7px;
}

.btn-copy {
  color: #2563eb;
}
.btn-copy:hover {
  background: #eff6ff;
  border-color: #bfdbfe;
}

.modal-xl {
  max-width: 960px;
  width: 96vw;
}

.batch-section {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 18px;
  margin-bottom: 18px;
  background: #fafafa;
}

.date-rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.date-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.date-row-num {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #e5e7eb;
  color: #374151;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.pickup-inline-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pickup-inline-row {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 10px 12px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pickup-inline-left,
.pickup-inline-right {
  display: flex;
  gap: 8px;
  align-items: center;
}

.pickup-inline-left input,
.pickup-inline-right input {
  min-width: 0;
}

.pickup-inline-empty {
  text-align: center;
  padding: 20px;
  color: #9ca3af;
  font-size: 13px;
  border: 1px dashed #e5e7eb;
  border-radius: 8px;
}

.btn-sm {
  padding: 5px 12px;
  font-size: 12px;
  border-radius: 6px;
  border: 1px solid;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}

.btn-sm.btn-secondary {
  background: #fff;
  border-color: #d1d5db;
  color: #374151;
}
.btn-sm.btn-secondary:hover {
  background: #f9fafb;
}

.copy-target-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 280px;
  overflow-y: auto;
}

.copy-target-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: #374151;
  cursor: pointer;
  padding: 8px 10px;
  border-radius: 6px;
  border: 1px solid #e5e7eb;
  transition: background 0.15s;
}

.copy-target-item:hover {
  background: #f0faf4;
  border-color: #b7dfc5;
}

.copy-target-item input {
  accent-color: #2d7a4f;
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}

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

.section-label {
  font-size: 13px;
  font-weight: 700;
  color: #374151;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin: 0 0 14px;
}

.pickup-loading {
  display: flex;
  justify-content: center;
  padding: 30px;
}

/* ── Region-grouped pickup manager ── */
.pickup-region-section {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 12px;
}

.pickup-region-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: #f0faf4;
  border-bottom: 1px solid #e5e7eb;
}

.pickup-region-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: #2d7a4f;
}

.region-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #2d7a4f;
  flex-shrink: 0;
}

.pickup-region-count {
  font-size: 11px;
  font-weight: 600;
  color: #6b7280;
  background: #e5e7eb;
  border-radius: 20px;
  padding: 1px 7px;
}

.pickup-region-body {
  background: #fff;
}

.pickup-region-list {
  display: flex;
  flex-direction: column;
}

.pickup-region-empty {
  padding: 12px 16px;
  font-size: 12px;
  color: #9ca3af;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* ── Pickup item display row ── */
.pickup-item {
  border-bottom: 1px solid #f3f4f6;
}
.pickup-item:last-child {
  border-bottom: none;
}

.pickup-item-display {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  gap: 12px;
}

.pid-left {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.pid-location {
  font-size: 13px;
  font-weight: 600;
  color: #111827;
  display: flex;
  align-items: center;
  gap: 6px;
}
.pid-location i { color: #dc2626; font-size: 11px; flex-shrink: 0; }

.pid-notes {
  font-size: 12px;
  color: #6b7280;
  display: flex;
  align-items: center;
  gap: 5px;
}
.pid-notes i { color: #f59e0b; font-size: 11px; flex-shrink: 0; }

.pid-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.pid-price {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
  white-space: nowrap;
}

.pid-map {
  color: #2d7a4f;
  font-size: 13px;
  text-decoration: none;
  padding: 4px;
  border-radius: 4px;
  transition: background 0.1s;
}
.pid-map:hover { background: #e8f5ec; }

/* ── Inline add/edit form (inside region body) ── */
.pickup-item-edit {
  padding: 12px 14px;
  background: #fafafa;
  border-top: 1px dashed #d1fae5;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pickup-item-add {
  border-top: 1px dashed #d1fae5;
}

.pif-row {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}

.pif-location {
  flex: 2;
  min-width: 160px;
}

.pif-notes {
  flex: 1.5;
  min-width: 130px;
}

.pif-price-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}

.pif-baht {
  font-size: 13px;
  color: #6b7280;
  font-weight: 600;
}

.pif-price {
  width: 90px;
}

.pif-map {
  flex: 2;
  min-width: 160px;
}

.pif-order {
  width: 70px;
  flex-shrink: 0;
}

.pif-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
</style>
