<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-shuttle-van"></i> ยานพาหนะ</h1>
        <p class="page-subtitle">จัดการรถตู้และเรือ</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <i class="fas fa-plus"></i> เพิ่มยานพาหนะ
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input v-model="searchQuery" placeholder="ค้นหาชื่อ, ทะเบียน, คนขับ..." />
      </div>
      <select v-model="filters.type" @change="fetchData()">
        <option value="">ทุกประเภท</option>
        <option value="van">รถตู้</option>
        <option value="boat">เรือ</option>
      </select>
    </div>

    <!-- Grid Cards -->
    <div class="table-card">
      <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
      <div class="vehicles-grid" v-else>
        <div class="vehicle-card" v-for="v in filteredVehicles" :key="v.id">
          <div class="vehicle-icon" :class="`vtype-${v.type}`">
            <i :class="v.type === 'van' ? 'fas fa-shuttle-van' : 'fas fa-ship'"></i>
          </div>
          <div class="vehicle-info">
            <h3>{{ v.name }}</h3>
            <div class="vehicle-meta">
              <span class="type-tag" :class="`type-${v.type === 'van' ? 'trekking' : 'diving'}`">
                {{ v.type === 'van' ? 'รถตู้' : 'เรือ' }}
              </span>
              <span class="capacity-badge">
                <i class="fas fa-users"></i> {{ v.capacity }} ที่นั่ง
              </span>
              <span class="plate-badge" v-if="v.license_plate">
                <i class="fas fa-id-card"></i> {{ v.license_plate }}
              </span>
            </div>
            <div class="vehicle-detail-row" v-if="v.color">
              <i class="fas fa-circle" :style="{ color: colorHex(v.color) }"></i>
              <span>{{ v.color }}</span>
            </div>
            <div class="vehicle-detail-row" v-if="v.driver_name">
              <div class="driver-photo-avatar" v-if="v.driver_photo">
                <img :src="v.driver_photo" />
              </div>
              <i class="fas fa-user-tie" v-else></i>
              <span>{{ v.driver_name }}</span>
              <span v-if="v.driver_phone" class="driver-phone">
                <i class="fas fa-phone"></i> {{ v.driver_phone }}
              </span>
            </div>
            <div class="pickup-summary" v-if="v.pickup_points?.length">
              <div class="pickup-summary-header" @click="togglePickups(v.id)">
                <i class="fas fa-map-marker-alt"></i>
                <span>{{ v.pickup_points.length }} จุดรับผู้โดยสาร</span>
                <i :class="expandedPickups.has(v.id) ? 'fas fa-chevron-up' : 'fas fa-chevron-down'" class="toggle-icon"></i>
              </div>
              <div class="pickup-list" v-if="expandedPickups.has(v.id)">
                <div
                  class="pickup-item"
                  v-for="pt in groupedPickups(v.pickup_points)"
                  :key="pt.region"
                >
                  <span class="region-chip">{{ pt.region_label }}</span>
                  <div class="pickup-locations">
                    <div v-for="loc in pt.locations" :key="loc.id" class="pickup-loc-row">
                      <i class="fas fa-dot-circle"></i>
                      <span>{{ loc.pickup_location }}</span>
                      <span v-if="loc.notes" class="pickup-notes">{{ loc.notes }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="seat-layout-info" v-if="v.seat_layout">
              <span class="layout-badge">
                <i class="fas fa-th"></i> {{ v.seat_layout.rows }} แถว · {{ v.seat_layout.seats?.length || 0 }} ที่นั่ง
              </span>
            </div>
          </div>
          <div class="vehicle-actions">
            <button class="btn-icon btn-edit" @click="openForm(v)" title="แก้ไข"><i class="fas fa-edit"></i></button>
            <button class="btn-icon btn-layout" @click="openLayoutEditor(v)" title="ผังที่นั่ง"><i class="fas fa-th"></i></button>
            <button class="btn-icon btn-pickup" @click="openPickupManager(v)" title="จุดรับผู้โดยสาร"><i class="fas fa-map-marker-alt"></i></button>
            <button class="btn-icon btn-delete" @click="confirmDelete(v)" title="ลบ"><i class="fas fa-trash"></i></button>
          </div>
        </div>
        <div class="empty-state-card" v-if="!filteredVehicles.length">
          <i class="fas fa-car-side"></i>
          <p>ไม่พบยานพาหนะ</p>
        </div>
      </div>
    </div>

    <!-- Vehicle Form Modal -->
    <div class="modal-overlay" v-if="showForm" @click.self="showForm = false">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขยานพาหนะ' : 'เพิ่มยานพาหนะใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-section-title"><i class="fas fa-car"></i> ข้อมูลยานพาหนะ</div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อ *</label>
              <input v-model="form.name" required placeholder="เช่น รถตู้ VIP-01" />
            </div>
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" required>
                <option value="van">รถตู้</option>
                <option value="boat">เรือ</option>
              </select>
            </div>
            <div class="form-group">
              <label>ความจุ (ที่นั่ง) *</label>
              <input v-model.number="form.capacity" type="number" min="1" required />
            </div>
            <div class="form-group">
              <label>เลขทะเบียนรถ</label>
              <input v-model="form.license_plate" placeholder="เช่น กข 1234 กรุงเทพ" />
            </div>
            <div class="form-group">
              <label>สีรถ</label>
              <input v-model="form.color" placeholder="เช่น ขาว, เทา, น้ำเงิน" />
            </div>
          </div>
          <div class="form-section-title"><i class="fas fa-user-tie"></i> ข้อมูลคนขับ</div>
          <div class="form-grid">
            <div class="form-group">
              <label>ชื่อคนขับ</label>
              <input v-model="form.driver_name" placeholder="ชื่อ-นามสกุลคนขับ" />
            </div>
            <div class="form-group">
              <label>เบอร์โทรศัพท์คนขับ</label>
              <input v-model="form.driver_phone" placeholder="08x-xxx-xxxx" />
            </div>
            <div class="form-group full-width">
              <label>รูปคนขับประจำรถ</label>
              <div class="media-upload-row">
                <div class="media-preview-sm" v-if="form.driver_photo">
                  <img :src="form.driver_photo" />
                  <button type="button" class="remove-btn" @click="form.driver_photo = ''"><i class="fas fa-times"></i></button>
                </div>
                <div class="upload-placeholder" v-else @click="triggerUpload(driverPhotoInput)">
                  <i class="fas fa-spinner fa-spin" v-if="uploadState.driver"></i>
                  <i class="fas fa-camera" v-else></i>
                  <span>อัปโหลดรูปคนขับ</span>
                </div>
                <input ref="driverPhotoInput" type="file" hidden accept="image/*" @change="handleMediaUpload($event, 'driver')" />
              </div>
            </div>
          </div>

          <div class="form-section-title"><i class="fas fa-images"></i> รูปภาพและวิดีโอ (สำหรับลูปพาเหรด)</div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>รูปภาพภายในรถ (สูงสุด 10 รูป)</label>
              <div class="gallery-grid-editor">
                <div v-for="(img, idx) in form.images" :key="idx" class="gallery-item-preview">
                  <img :src="img" />
                  <button type="button" class="remove-btn" @click="removeItem('images', idx)"><i class="fas fa-times"></i></button>
                </div>
                <div class="gallery-add-btn" v-if="form.images.length < 10" @click="triggerUpload(galleryInput)">
                  <i class="fas fa-spinner fa-spin" v-if="uploadState.gallery"></i>
                  <i class="fas fa-plus" v-else></i>
                  <span>เพิ่มรูป</span>
                </div>
              </div>
              <input ref="galleryInput" type="file" hidden multiple accept="image/*" @change="handleMediaUpload($event, 'gallery')" />
            </div>
            <div class="form-group full-width">
              <label>วิดีโอภายในรถ</label>
              <div class="media-upload-row">
                <div class="video-preview" v-if="form.interior_video">
                  <video :src="form.interior_video" controls></video>
                  <button type="button" class="remove-btn" @click="form.interior_video = ''"><i class="fas fa-times"></i></button>
                </div>
                <div class="upload-placeholder" v-else @click="triggerUpload(videoInput)">
                  <i class="fas fa-spinner fa-spin" v-if="uploadState.video"></i>
                  <i class="fas fa-video" v-else></i>
                  <span>อัปโหลดวิดีโอ</span>
                </div>
                <input ref="videoInput" type="file" hidden accept="video/*" @change="handleMediaUpload($event, 'video')" />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <i class="fas fa-spinner fa-spin" v-if="submitting"></i>
              {{ editing ? 'บันทึก' : 'สร้าง' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Pickup Points Manager Modal -->
    <div class="modal-overlay" v-if="showPickupManager" @click.self="closePickupManager">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <h2><i class="fas fa-map-marker-alt"></i> จุดรับผู้โดยสาร — {{ pickupVehicle?.name }}</h2>
          <button class="modal-close" @click="closePickupManager"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <!-- Pickup list grouped by region -->
          <div class="pickup-manager-list" v-if="pickupPoints.length">
            <div v-for="group in groupedPickups(pickupPoints)" :key="group.region" class="pickup-region-group">
              <div class="pickup-region-header">
                <span class="region-chip-lg">{{ group.region_label }}</span>
                <span class="region-code">({{ group.region }})</span>
              </div>
              <div class="pickup-manager-items">
                <div v-for="pt in group.locations" :key="pt.id" class="pickup-manager-item">
                  <div class="pickup-manager-item-info">
                    <span class="pickup-loc-name"><i class="fas fa-map-pin"></i> {{ pt.pickup_location }}</span>
                    <span v-if="pt.notes" class="pickup-notes-text"><i class="fas fa-sticky-note"></i> {{ pt.notes }}</span>
                    <a v-if="pt.map_url" :href="pt.map_url" target="_blank" class="map-link"><i class="fas fa-external-link-alt"></i> แผนที่</a>
                  </div>
                  <div class="pickup-manager-item-actions">
                    <button class="btn-icon btn-edit btn-sm" @click="openPickupForm(pt)" title="แก้ไข"><i class="fas fa-edit"></i></button>
                    <button class="btn-icon btn-delete btn-sm" @click="confirmDeletePickup(pt)" title="ลบ"><i class="fas fa-trash"></i></button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="pickup-empty" v-else>
            <i class="fas fa-map-marked-alt"></i>
            <p>ยังไม่มีจุดรับผู้โดยสาร</p>
          </div>

          <!-- Add / Edit Pickup Form -->
          <div class="pickup-add-section">
            <div class="form-section-title">
              {{ editingPickup ? 'แก้ไขจุดรับผู้โดยสาร' : 'เพิ่มจุดรับผู้โดยสาร' }}
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>ภูมิภาค (region key) *</label>
                <select v-model="pickupForm.region" @change="onRegionChange">
                  <option value="">-- เลือกภูมิภาค --</option>
                  <option value="north">north — ภาคเหนือ</option>
                  <option value="northeast">northeast — ภาคอีสาน</option>
                  <option value="central">central — ภาคกลาง</option>
                  <option value="east">east — ภาคตะวันออก</option>
                  <option value="west">west — ภาคตะวันตก</option>
                  <option value="south">south — ภาคใต้</option>
                </select>
              </div>
              <div class="form-group">
                <label>ชื่อภูมิภาค (ไทย) *</label>
                <input v-model="pickupForm.region_label" placeholder="เช่น ภาคเหนือ" required />
              </div>
              <div class="form-group full-width">
                <label>ชื่อจุดขึ้นรถ *</label>
                <input v-model="pickupForm.pickup_location" placeholder="เช่น ปั๊มน้ำมัน ปตท. แยกลาดพร้าว" required />
              </div>
              <div class="form-group full-width">
                <label>หมายเหตุ / เวลานัดพบ</label>
                <input v-model="pickupForm.notes" placeholder="เช่น นัดพบ 05:30 น." />
              </div>
              <div class="form-group full-width">
                <label>ลิงก์ Google Maps</label>
                <input v-model="pickupForm.map_url" placeholder="https://maps.google.com/..." />
              </div>
            </div>
            <div class="pickup-form-actions">
              <button v-if="editingPickup" type="button" class="btn-secondary btn-sm" @click="cancelPickupEdit">ยกเลิก</button>
              <button type="button" class="btn-primary btn-sm" @click="submitPickupForm" :disabled="pickupSubmitting">
                <i class="fas fa-spinner fa-spin" v-if="pickupSubmitting"></i>
                {{ editingPickup ? 'บันทึกการแก้ไข' : 'เพิ่มจุดรับ' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Pickup Confirm -->
    <div class="modal-overlay" v-if="showDeletePickupConfirm" @click.self="showDeletePickupConfirm = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบจุดรับ</h2>
          <button class="modal-close" @click="showDeletePickupConfirm = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">ลบจุดรับ <strong>{{ deletingPickup?.pickup_location }}</strong>?</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeletePickupConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDeletePickup" :disabled="pickupSubmitting">ลบ</button>
        </div>
      </div>
    </div>

    <!-- Delete Vehicle Confirm -->
    <div class="modal-overlay" v-if="showDeleteConfirm" @click.self="showDeleteConfirm = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><i class="fas fa-exclamation-triangle"></i> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบ</button>
        </div>
      </div>
    </div>

    <!-- Seat Layout Editor Modal -->
    <div class="modal-overlay" v-if="showLayoutEditor" @click.self="showLayoutEditor = false">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2><i class="fas fa-th"></i> ผังที่นั่ง — {{ layoutVehicle?.name }}</h2>
          <button class="modal-close" @click="showLayoutEditor = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body p-0">
          <SeatMapEditor v-model="layoutForm" />
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showLayoutEditor = false">ยกเลิก</button>
          <button class="btn-primary" @click="saveLayout" :disabled="submittingLayout">
            <i class="fas fa-spinner fa-spin" v-if="submittingLayout"></i>
            บันทึกผังที่นั่ง
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import SeatMapEditor from '../../components/SeatMapEditor.vue';

const admin = useAdminStore();
const filters = reactive({ type: '' });
const searchQuery = ref('');
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
const expandedPickups = ref(new Set());

const form = reactive({
  name: '', type: 'van', capacity: 10,
  license_plate: '', color: '', driver_name: '', driver_phone: '',
  driver_photo: '', interior_video: '', images: [],
});

const driverPhotoInput = ref(null);
const galleryInput = ref(null);
const videoInput = ref(null);
const uploadState = reactive({ driver: false, gallery: false, video: false });

// Pickup manager state
const showPickupManager = ref(false);
const pickupVehicle = ref(null);
const pickupPoints = ref([]);
const editingPickup = ref(null);
const deletingPickup = ref(null);
const showDeletePickupConfirm = ref(false);
const pickupSubmitting = ref(false);
const pickupForm = reactive({
  region: '', region_label: '', pickup_location: '', notes: '', map_url: '',
});

// Layout editor state
const showLayoutEditor = ref(false);
const layoutVehicle = ref(null);
const layoutForm = ref({ rows: 4, columns: ['A','B','C','','D','E',], seats: [] });
const submittingLayout = ref(false);

const REGION_LABELS = {
  north: 'ภาคเหนือ', northeast: 'ภาคอีสาน', central: 'ภาคกลาง',
  east: 'ภาคตะวันออก', west: 'ภาคตะวันตก', south: 'ภาคใต้',
};

const filteredVehicles = computed(() => {
  let data = admin.vehicles.data || [];
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    data = data.filter(v =>
      v.name.toLowerCase().includes(q) ||
      (v.license_plate || '').toLowerCase().includes(q) ||
      (v.driver_name || '').toLowerCase().includes(q)
    );
  }
  return data;
});

const groupedPickups = (points) => {
  const map = {};
  for (const pt of points) {
    if (!map[pt.region]) {
      map[pt.region] = { region: pt.region, region_label: pt.region_label, locations: [] };
    }
    map[pt.region].locations.push(pt);
  }
  return Object.values(map);
};

const colorHex = (colorName) => {
  const map = {
    'ขาว': '#ffffff', 'ดำ': '#1f2937', 'เทา': '#9ca3af', 'แดง': '#ef4444',
    'น้ำเงิน': '#3b82f6', 'เขียว': '#22c55e', 'เหลือง': '#eab308',
    'ส้ม': '#f97316', 'ม่วง': '#a855f7', 'ชมพู': '#ec4899',
  };
  return map[colorName] || '#6b7280';
};

const togglePickups = (id) => {
  const s = new Set(expandedPickups.value);
  s.has(id) ? s.delete(id) : s.add(id);
  expandedPickups.value = s;
};

const fetchData = () => admin.fetchVehicles({ ...filters });

const openForm = (v = null) => {
  editing.value = v;
  if (v) {
    Object.assign(form, {
      name: v.name, type: v.type, capacity: v.capacity,
      license_plate: v.license_plate || '', color: v.color || '',
      driver_name: v.driver_name || '', driver_phone: v.driver_phone || '',
      driver_photo: v.driver_photo || '', interior_video: v.interior_video || '',
      images: v.images || [],
    });
  } else {
    Object.assign(form, {
      name: '', type: 'van', capacity: 10, license_plate: '', color: '',
      driver_name: '', driver_phone: '', driver_photo: '', interior_video: '',
      images: [],
    });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  try {
    const data = { ...form };
    if (editing.value) {
      await admin.updateVehicle(editing.value.id, data);
    } else {
      await admin.createVehicle(data);
    }
    showForm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

// ─── Media Upload Methods ──────────────────

const triggerUpload = (input) => input?.click();

const handleMediaUpload = async (event, type) => {
  const file = event.target.files?.[0];
  if (!file) return;

  if (type === 'gallery') {
    handleGalleryUpload(Array.from(event.target.files));
    return;
  }

  // Validate size
  const maxSize = type === 'video' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
  if (file.size > maxSize) {
    alert(`ไฟล์มีขนาดเกินกำหนด (${type === 'video' ? '50MB' : '5MB'})`);
    return;
  }

  uploadState[type] = true;
  try {
    const formData = new FormData();
    formData.append('file', file);

    const res = await api.post('/admin/upload-image', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (type === 'driver') form.driver_photo = res.data.data.url;
    else if (type === 'video') form.interior_video = res.data.data.url;
  } catch (e) {
    alert('อัปโหลดล้มเหลว');
  } finally {
    uploadState[type] = false;
    if (event.target) event.target.value = '';
  }
};

const handleGalleryUpload = async (files) => {
  uploadState.gallery = true;
  try {
    for (const file of files) {
      if (file.size > 5 * 1024 * 1024) continue;
      const formData = new FormData();
      formData.append('file', file);
      const res = await api.post('/admin/upload-image', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      form.images.push(res.data.data.url);
    }
  } catch (e) {
    alert('แกลเลอรี่บางส่วนล้มเหลว');
  } finally {
    uploadState.gallery = false;
    if (galleryInput.value) galleryInput.value.value = '';
  }
};

const removeItem = (field, index) => {
  if (field === 'images') form.images.splice(index, 1);
};

const confirmDelete = (v) => { deleting.value = v; showDeleteConfirm.value = true; };

const doDelete = async () => {
  submitting.value = true;
  try {
    await admin.deleteVehicle(deleting.value.id);
    showDeleteConfirm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

// ─── Pickup Manager ─────────────────────────────────────────

const openPickupManager = async (v) => {
  pickupVehicle.value = v;
  pickupPoints.value = v.pickup_points || [];
  resetPickupForm();
  showPickupManager.value = true;
};

const closePickupManager = () => {
  showPickupManager.value = false;
  pickupVehicle.value = null;
  pickupPoints.value = [];
  resetPickupForm();
  fetchData();
};

const resetPickupForm = () => {
  editingPickup.value = null;
  Object.assign(pickupForm, { region: '', region_label: '', pickup_location: '', notes: '', map_url: '' });
};

const onRegionChange = () => {
  if (pickupForm.region && REGION_LABELS[pickupForm.region]) {
    pickupForm.region_label = REGION_LABELS[pickupForm.region];
  }
};

const openPickupForm = (pt) => {
  editingPickup.value = pt;
  Object.assign(pickupForm, {
    region: pt.region, region_label: pt.region_label,
    pickup_location: pt.pickup_location, notes: pt.notes || '', map_url: pt.map_url || '',
  });
};

const cancelPickupEdit = () => resetPickupForm();

const submitPickupForm = async () => {
  if (!pickupForm.region || !pickupForm.region_label || !pickupForm.pickup_location) {
    alert('กรุณากรอกภูมิภาคและชื่อจุดรับให้ครบ');
    return;
  }
  pickupSubmitting.value = true;
  try {
    const data = { ...pickupForm };
    if (editingPickup.value) {
      await admin.updateVehiclePickupPoint(pickupVehicle.value.id, editingPickup.value.id, data);
    } else {
      await admin.createVehiclePickupPoint(pickupVehicle.value.id, data);
    }
    const res = await admin.fetchVehiclePickupPoints(pickupVehicle.value.id);
    pickupPoints.value = res.data;
    resetPickupForm();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

const confirmDeletePickup = (pt) => {
  deletingPickup.value = pt;
  showDeletePickupConfirm.value = true;
};

const doDeletePickup = async () => {
  pickupSubmitting.value = true;
  try {
    await admin.deleteVehiclePickupPoint(pickupVehicle.value.id, deletingPickup.value.id);
    const res = await admin.fetchVehiclePickupPoints(pickupVehicle.value.id);
    pickupPoints.value = res.data;
    showDeletePickupConfirm.value = false;
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

// ─── Layout Editor ──────────────────────────────────────────

const openLayoutEditor = (v) => {
  layoutVehicle.value = v;
  // Deep copy the existing layout or use default
  if (v.seat_layout && v.seat_layout.seats) {
    layoutForm.value = JSON.parse(JSON.stringify(v.seat_layout));
  } else {
    layoutForm.value = { 
      rows: 4, 
      columns: ['A','B','C','','D','E'], 
      seats: [] 
    };
  }
  showLayoutEditor.value = true;
};

const saveLayout = async () => {
  submittingLayout.value = true;
  try {
    // Prepare the update data for the whole vehicle
    const vehicleData = {
      name: layoutVehicle.value.name,
      type: layoutVehicle.value.type,
      capacity: layoutVehicle.value.capacity,
      seat_layout: layoutForm.value,
      license_plate: layoutVehicle.value.license_plate,
      color: layoutVehicle.value.color,
      driver_name: layoutVehicle.value.driver_name,
      driver_phone: layoutVehicle.value.driver_phone,
    };

    await admin.updateVehicle(layoutVehicle.value.id, vehicleData);
    showLayoutEditor.value = false;
    fetchData();
    alert('บันทึกผังที่นั่งสำเร็จ');
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกผังที่นั่ง');
  } finally {
    submittingLayout.value = false;
  }
};

onMounted(() => fetchData());
</script>

<style scoped>
@import url('./admin-shared.css');

.vehicles-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 14px;
  padding: 20px;
}

.vehicle-card {
  background: #ffffff;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  padding: 18px;
  display: flex;
  gap: 14px;
  align-items: flex-start;
  transition: box-shadow 0.15s;
}

.vehicle-card:hover {
  box-shadow: 0 4px 14px rgba(17, 24, 39, 0.07);
}

.vehicle-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.vtype-van { background: #f0fdf4; color: #15803d; }
.vtype-boat { background: #dbeafe; color: #1d4ed8; }

.vehicle-info {
  flex: 1;
  min-width: 0;
}

.vehicle-info h3 {
  margin: 0 0 8px;
  font-size: 15px;
  color: #111827;
  font-weight: 600;
}

.vehicle-meta {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 6px;
}

.capacity-badge {
  font-size: 12px;
  color: #6b7280;
  display: flex;
  align-items: center;
  gap: 4px;
}

.plate-badge {
  font-size: 12px;
  background: #EEEEEE;
  color: #374151;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #d1d5db;
}

.vehicle-detail-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #6b7280;
  margin-top: 4px;
}

.driver-phone {
  margin-left: 8px;
  color: #3b82f6;
  display: flex;
  align-items: center;
  gap: 4px;
}

.seat-layout-info {
  margin-top: 6px;
}

.layout-badge {
  font-size: 12px;
  color: #9ca3af;
  display: flex;
  align-items: center;
  gap: 4px;
}

.pickup-summary {
  margin-top: 8px;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  overflow: hidden;
}

.pickup-summary-header {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #6b7280;
  padding: 6px 10px;
  background: #FAFAFA;
  cursor: pointer;
  user-select: none;
}

.pickup-summary-header:hover { background: #EEEEEE; }

.toggle-icon { margin-left: auto; font-size: 10px; }

.pickup-list { padding: 8px 10px; }

.pickup-item { margin-bottom: 8px; }

.region-chip {
  display: inline-block;
  font-size: 11px;
  background: #ede9fe;
  color: #6d28d9;
  padding: 1px 8px;
  border-radius: 10px;
  font-weight: 600;
  margin-bottom: 4px;
}

.pickup-locations { padding-left: 4px; }

.pickup-loc-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #374151;
  margin-top: 3px;
}

.pickup-loc-row i { color: #9ca3af; font-size: 10px; }

.pickup-notes {
  font-size: 11px;
  color: #9ca3af;
  margin-left: 4px;
}

.vehicle-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.btn-pickup {
  background: #ede9fe;
  color: #6d28d9;
  border: none;
  width: 32px;
  height: 32px;
  border-radius: 6px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  transition: background 0.15s;
}

.btn-pickup:hover { background: #ddd6fe; }

.btn-layout {
  background: #fdf2f8;
  color: #db2777;
  border: none;
  width: 32px;
  height: 32px;
  border-radius: 6px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  transition: background 0.15s;
}

.btn-layout:hover { background: #fce7f3; }

.empty-state-card {
  grid-column: 1 / -1;
  text-align: center;
  padding: 60px;
  color: #9ca3af;
}

.empty-state-card i {
  font-size: 36px;
  margin-bottom: 12px;
  display: block;
}

/* Modal sizes */
.modal-lg { max-width: 620px; }
.modal-xl { max-width: 760px; }

/* Form sections */
.form-section-title {
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 16px 0 10px;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Pickup manager */
.pickup-manager-list { margin-bottom: 20px; }

.pickup-region-group { margin-bottom: 16px; }

.pickup-region-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.region-chip-lg {
  display: inline-block;
  background: #ede9fe;
  color: #6d28d9;
  font-size: 12px;
  font-weight: 700;
  padding: 3px 12px;
  border-radius: 12px;
}

.region-code { font-size: 12px; color: #9ca3af; }

.pickup-manager-items { display: flex; flex-direction: column; gap: 6px; }

.pickup-manager-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #FAFAFA;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 10px 12px;
}

.pickup-manager-item-info {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  flex: 1;
}

.pickup-loc-name { font-size: 13px; color: #111827; font-weight: 500; }
.pickup-notes-text { font-size: 12px; color: #9ca3af; }
.map-link { font-size: 12px; color: #3b82f6; text-decoration: none; }
.map-link:hover { text-decoration: underline; }

.pickup-manager-item-actions { display: flex; gap: 6px; }

.btn-sm { padding: 4px 10px !important; font-size: 12px !important; height: 28px; }

.pickup-empty {
  text-align: center;
  padding: 24px;
  color: #9ca3af;
}

.pickup-empty i { font-size: 28px; margin-bottom: 8px; display: block; }

.pickup-add-section {
  border-top: 1px solid #e5e7eb;
  padding-top: 16px;
}

.pickup-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
}
.driver-photo-avatar { width: 24px; height: 24px; border-radius: 50%; overflow: hidden; border: 1px solid #e5e7eb; }
.driver-photo-avatar img { width: 100%; height: 100%; object-fit: cover; }
.media-upload-row { display: flex; gap: 12px; margin-top: 8px; }
.media-preview-sm { position: relative; width: 100px; height: 100px; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
.media-preview-sm img { width: 100%; height: 100%; object-fit: cover; }
.video-preview { position: relative; width: 100%; max-width: 300px; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
.video-preview video { width: 100%; display: block; }
.upload-placeholder { 
  width: 120px; height: 100px; border: 2px dashed #d1d5db; border-radius: 8px; 
  display: flex; flex-direction: column; align-items: center; justify-content: center; 
  cursor: pointer; color: #9ca3af; font-size: 12px; transition: all 0.2s;
}
.upload-placeholder:hover { border-color: #3b82f6; color: #3b82f6; background: #eff6ff; }
.upload-placeholder i { font-size: 20px; margin-bottom: 4px; }

.gallery-grid-editor { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; margin-top: 8px; }
.gallery-item-preview { position: relative; height: 100px; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
.gallery-item-preview img { width: 100%; height: 100%; object-fit: cover; }
.gallery-add-btn { 
  height: 100px; border: 2px dashed #d1d5db; border-radius: 8px; 
  display: flex; flex-direction: column; align-items: center; justify-content: center; 
  cursor: pointer; color: #9ca3af; font-size: 11px;
}
.gallery-add-btn:hover { border-color: #3b82f6; color: #3b82f6; background: #eff6ff; }
.remove-btn { 
  position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; 
  border-radius: 50%; background: rgba(255, 255, 255, 0.9); color: #ef4444; 
  border: 1px solid #fee2e2; display: flex; align-items: center; justify-content: center; 
  cursor: pointer; font-size: 10px;
}
</style>
