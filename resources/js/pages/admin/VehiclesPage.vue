<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">airport_shuttle</span> ยานพาหนะ</h1>
        <p class="page-subtitle">จัดการรถตู้และเรือ</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add</span> เพิ่มยานพาหนะ
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="searchQuery" placeholder="ค้นหาชื่อ, ทะเบียน, คนขับ..." />
      </div>
      <select v-model="filters.type" @change="fetchData()">
        <option value="">ทุกประเภท</option>
        <option value="van">รถตู้</option>
        <option value="boat">เรือ</option>
      </select>
    </div>

    <!-- Grid Cards -->
    <div class="table-card" style="background: transparent; border: none; padding: 0;">
      <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
      <div class="vehicles-grid" v-else>
        <div class="vehicle-card" v-for="v in filteredVehicles" :key="v.id">
          <div class="vehicle-icon" :class="`vtype-${v.type}`">
            <span class="material-symbols-rounded">{{ v.type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
          </div>
          <div class="vehicle-info">
            <h3>{{ v.name }}</h3>
            <div class="vehicle-meta">
              <span class="type-tag" :class="`type-${v.type === 'van' ? 'trekking' : 'diving'}`">
                {{ v.type === 'van' ? 'รถตู้' : 'เรือ' }}
              </span>
              <span class="capacity-badge">
                <span class="material-symbols-rounded" style="font-size:14px;">groups</span> {{ v.capacity }} ที่นั่ง
              </span>
              <span class="plate-badge" v-if="v.license_plate">
                <span class="material-symbols-rounded" style="font-size:14px;">badge</span> {{ v.license_plate }}
              </span>
            </div>
            <div class="vehicle-detail-row" v-if="v.color">
              <span class="material-symbols-rounded" :style="{ color: colorHex(v.color), fontSize: '14px' }">circle</span>
              <span>{{ v.color }}</span>
            </div>
            <div class="vehicle-detail-row" v-if="v.driver_name">
              <div class="driver-photo-avatar" v-if="v.driver_photo">
                <img :src="v.driver_photo" />
              </div>
              <span class="material-symbols-rounded" style="font-size:14px;" v-else>person</span>
              <span>{{ v.driver_name }}</span>
              <span v-if="v.driver_phone" class="driver-phone">
                <span class="material-symbols-rounded" style="font-size:14px;">phone</span> {{ v.driver_phone }}
              </span>
            </div>
            <div class="pickup-summary" v-if="v.pickup_points?.length">
              <div class="pickup-summary-header" @click="togglePickups(v.id)">
                <span class="material-symbols-rounded" style="font-size:14px;">location_on</span>
                <span>{{ v.pickup_points.length }} จุดรับผู้โดยสาร</span>
                <span class="material-symbols-rounded toggle-icon">{{ expandedPickups.has(v.id) ? 'expand_less' : 'expand_more' }}</span>
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
                      <span class="material-symbols-rounded" style="font-size:12px;">radio_button_checked</span>
                      <span>{{ loc.pickup_location }}</span>
                      <span v-if="loc.notes" class="pickup-notes">{{ loc.notes }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="seat-layout-info" v-if="v.seat_layout">
              <span class="layout-badge">
                <span class="material-symbols-rounded" style="font-size:14px;">grid_view</span> {{ v.seat_layout.rows }} แถว · {{ v.seat_layout.seats?.length || 0 }} ที่นั่ง
              </span>
            </div>
          </div>
          <div class="vehicle-actions">
            <button class="btn-icon btn-edit" @click="openForm(v)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
            <button class="btn-icon btn-layout" @click="openLayoutEditor(v)" title="ผังที่นั่ง"><span class="material-symbols-rounded" style="font-size:16px;">grid_view</span></button>
            <button class="btn-icon btn-pickup" @click="openPickupManager(v)" title="จุดรับผู้โดยสาร"><span class="material-symbols-rounded" style="font-size:16px;">location_on</span></button>
            <button class="btn-icon btn-delete" @click="confirmDelete(v)" title="ลบ"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
          </div>
        </div>
        <div class="empty-state-card" v-if="!filteredVehicles.length">
          <span class="material-symbols-rounded" style="font-size:48px;">directions_car</span>
          <p>ไม่พบยานพาหนะ</p>
        </div>
      </div>
    </div>

    <!-- Vehicle Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขยานพาหนะ' : 'เพิ่มยานพาหนะใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-section-title"><span class="material-symbols-rounded" style="font-size:18px;">directions_car</span> ข้อมูลยานพาหนะ</div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อ *</label>
              <input v-model="form.name" required placeholder="เช่น รถตู้ VIP-01" class="form-input" />
            </div>
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" required class="form-input">
                <option value="van">รถตู้</option>
                <option value="boat">เรือ</option>
              </select>
            </div>
            <div class="form-group">
              <label>ความจุ (ที่นั่ง) *</label>
              <input v-model.number="form.capacity" type="number" min="1" required class="form-input" />
            </div>
            <div class="form-group">
              <label>เลขทะเบียนรถ</label>
              <input v-model="form.license_plate" placeholder="เช่น กข 1234 กรุงเทพ" class="form-input" />
            </div>
            <div class="form-group">
              <label>สีรถ</label>
              <input v-model="form.color" placeholder="เช่น ขาว, เทา, น้ำเงิน" class="form-input" />
            </div>
          </div>
          <div class="form-section-title"><span class="material-symbols-rounded" style="font-size:18px;">person</span> ข้อมูลคนขับ</div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>เลือกจากผู้ใช้งาน (ถ้ามี)</label>
              <div class="select-with-icon">
                <span class="material-symbols-rounded">person_search</span>
                <select @change="onDriverSelect" class="form-input">
                  <option value="">-- เลือกผู้ใช้งานเพื่อดึงข้อมูล --</option>
                  <option v-for="u in staffUsers" :key="u.id" :value="u.id">
                    {{ u.name }} {{ u.phone ? `(${u.phone})` : '' }}
                  </option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>ชื่อคนขับ</label>
              <input v-model="form.driver_name" placeholder="ชื่อ-นามสกุลคนขับ" class="form-input" />
            </div>
            <div class="form-group">
              <label>เบอร์โทรศัพท์คนขับ</label>
              <input v-model="form.driver_phone" placeholder="08x-xxx-xxxx" class="form-input" />
            </div>
            <div class="form-group full-width">
              <label>รูปคนขับประจำรถ</label>
              <div class="media-upload-row">
                <div class="media-preview-sm" v-if="form.driver_photo">
                  <img :src="form.driver_photo" />
                  <button type="button" class="remove-btn" @click="form.driver_photo = ''"><span class="material-symbols-rounded" style="font-size:12px;">close</span></button>
                </div>
                <div class="upload-placeholder" v-else @click="triggerUpload(driverPhotoInput)">
                  <span class="material-symbols-rounded animate-spin" v-if="uploadState.driver">sync</span>
                  <span class="material-symbols-rounded" v-else>photo_camera</span>
                  <span>อัปโหลดรูปคนขับ</span>
                </div>
                <input ref="driverPhotoInput" type="file" hidden accept="image/*" @change="handleMediaUpload($event, 'driver')" />
              </div>
            </div>
          </div>

          <div class="form-section-title"><span class="material-symbols-rounded" style="font-size:18px;">photo_library</span> รูปภาพและวิดีโอ (สำหรับลูปพาเหรด)</div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>รูปภาพภายในรถ (สูงสุด 10 รูป)</label>
              <div class="gallery-grid-editor">
                <div v-for="(img, idx) in form.images" :key="idx" class="gallery-item-preview">
                  <img :src="img" />
                  <button type="button" class="remove-btn" @click="removeItem('images', idx)"><span class="material-symbols-rounded" style="font-size:12px;">close</span></button>
                </div>
                <div class="gallery-add-btn" v-if="form.images.length < 10" @click="triggerUpload(galleryInput)">
                  <span class="material-symbols-rounded animate-spin" v-if="uploadState.gallery">sync</span>
                  <span class="material-symbols-rounded" v-else>add</span>
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
                  <button type="button" class="remove-btn" @click="form.interior_video = ''"><span class="material-symbols-rounded" style="font-size:12px;">close</span></button>
                </div>
                <div class="upload-placeholder" v-else @click="triggerUpload(videoInput)">
                  <span class="material-symbols-rounded animate-spin" v-if="uploadState.video">sync</span>
                  <span class="material-symbols-rounded" v-else>videocam</span>
                  <span>อัปโหลดวิดีโอ</span>
                </div>
                <input ref="videoInput" type="file" hidden accept="video/*" @change="handleMediaUpload($event, 'video')" />
              </div>
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

    <!-- Pickup Points Manager Modal -->
    <div class="modal-overlay" v-if="showPickupManager">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <h2><span class="material-symbols-rounded heading-icon">location_on</span> จุดรับผู้โดยสาร — {{ pickupVehicle?.name }}</h2>
          <button class="modal-close" @click="closePickupManager"><span class="material-symbols-rounded">close</span></button>
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
                    <span class="pickup-loc-name"><span class="material-symbols-rounded" style="font-size:14px;">location_on</span> {{ pt.pickup_location }}</span>
                    <span v-if="pt.notes" class="pickup-notes-text"><span class="material-symbols-rounded" style="font-size:14px;">notes</span> {{ pt.notes }}</span>
                    <a v-if="pt.map_url" :href="pt.map_url" target="_blank" class="map-link"><span class="material-symbols-rounded" style="font-size:14px;">open_in_new</span> แผนที่</a>
                  </div>
                  <div class="pickup-manager-item-actions">
                    <button class="btn-icon btn-edit btn-sm" @click="openPickupForm(pt)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:14px;">edit</span></button>
                    <button class="btn-icon btn-delete btn-sm" @click="confirmDeletePickup(pt)" title="ลบ"><span class="material-symbols-rounded" style="font-size:14px;">delete</span></button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="pickup-empty" v-else>
            <span class="material-symbols-rounded" style="font-size:48px;">map</span>
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
                <select v-model="pickupForm.region" @change="onRegionChange" class="form-input">
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
                <input v-model="pickupForm.region_label" placeholder="เช่น ภาคเหนือ" required class="form-input" />
              </div>
              <div class="form-group full-width">
                <label>ชื่อจุดขึ้นรถ *</label>
                <input v-model="pickupForm.pickup_location" placeholder="เช่น ปั๊มน้ำมัน ปตท. แยกลาดพร้าว" required class="form-input" />
              </div>
              <div class="form-group full-width">
                <label>หมายเหตุ / เวลานัดพบ</label>
                <input v-model="pickupForm.notes" placeholder="เช่น นัดพบ 05:30 น." class="form-input" />
              </div>
              <div class="form-group full-width">
                <label>ลิงก์ Google Maps</label>
                <input v-model="pickupForm.map_url" placeholder="https://maps.google.com/..." class="form-input" />
              </div>
            </div>
            <div class="pickup-form-actions">
              <button v-if="editingPickup" type="button" class="btn-secondary btn-sm" @click="cancelPickupEdit">ยกเลิก</button>
              <button type="button" class="btn-primary btn-sm" @click="submitPickupForm" :disabled="pickupSubmitting">
                <span class="material-symbols-rounded animate-spin" v-if="pickupSubmitting">sync</span>
                {{ editingPickup ? 'บันทึกการแก้ไข' : 'เพิ่มจุดรับ' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Pickup Confirm -->
    <div class="modal-overlay" v-if="showDeletePickupConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบจุดรับ</h2>
          <button class="modal-close" @click="showDeletePickupConfirm = false"><span class="material-symbols-rounded">close</span></button>
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
    <div class="modal-overlay" v-if="showDeleteConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded" style="color:var(--color-gold);">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบ</button>
        </div>
      </div>
    </div>

    <!-- Seat Layout Editor Modal -->
    <div class="modal-overlay" v-if="showLayoutEditor">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2><span class="material-symbols-rounded heading-icon">grid_view</span> ผังที่นั่ง — {{ layoutVehicle?.name }}</h2>
          <button class="modal-close" @click="showLayoutEditor = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body p-0">
          <SeatMapEditor v-model="layoutForm" />
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showLayoutEditor = false">ยกเลิก</button>
          <button class="btn-primary" @click="saveLayout" :disabled="submittingLayout">
            <span class="material-symbols-rounded animate-spin" v-if="submittingLayout">sync</span>
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
import api from '../../lib/axios';

const admin = useAdminStore();
const filters = reactive({ type: '' });
const searchQuery = ref('');
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
const expandedPickups = ref(new Set());
const staffUsers = ref([]);

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
  return map[colorName] || 'var(--color-text-muted)';
};

const togglePickups = (id) => {
  const s = new Set(expandedPickups.value);
  s.has(id) ? s.delete(id) : s.add(id);
  expandedPickups.value = s;
};

const fetchData = () => {
  admin.fetchVehicles({ ...filters });
  fetchStaff();
};

const fetchStaff = async () => {
  try {
    // Fetch both staff and operators as they can be drivers
    const res = await api.get('/admin/users', { params: { per_page: 100 } });
    staffUsers.value = res.data.data.filter(u => 
      u.roles.includes('staff') || u.roles.includes('operator') || u.roles.includes('admin')
    );
  } catch (e) {
    console.error('Failed to fetch staff:', e);
  }
};

const onDriverSelect = (e) => {
  const userId = e.target.value;
  if (!userId) return;
  const user = staffUsers.value.find(u => u.id == userId);
  if (user) {
    form.driver_name = user.name;
    form.driver_phone = user.phone || '';
    form.driver_photo = user.avatar_url || '';
    e.target.value = ''; // Reset select to placeholder
  }
};

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
  const maxSize = type === 'video' ? 50 * 1024 * 1024 : 10 * 1024 * 1024;
  if (file.size > maxSize) {
    alert(`ไฟล์มีขนาดเกินกำหนด (${type === 'video' ? '50MB' : '10MB'})`);
    return;
  }

  uploadState[type] = true;
  try {
    const formData = new FormData();
    formData.append('file', file);

    const res = await api.post('/admin/upload-image', formData);

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
  const currentCount = form.images.length;
  const remainingCount = 10 - currentCount;
  
  if (remainingCount <= 0) {
    alert('ครบโควตารูปภาพแล้ว (สูงสุด 10 รูป)');
    return;
  }

  const filesToUpload = files.slice(0, remainingCount);
  if (files.length > remainingCount) {
    console.warn(`Allowed only ${remainingCount} more files, others ignored.`);
  }

  uploadState.gallery = true;
  let successCount = 0;
  let errorCount = 0;

  try {
    for (const file of filesToUpload) {
      if (file.size > 10 * 1024 * 1024) {
        errorCount++;
        continue;
      }

      const formData = new FormData();
      formData.append('file', file);

      try {
        const res = await api.post('/admin/upload-image', formData);
        form.images.push(res.data.data.url);
        successCount++;
      } catch (innerError) {
        console.error('Individual upload failed:', innerError);
        errorCount++;
      }
    }

    if (errorCount > 0) {
      alert(`อัปโหลดสำเร็จ ${successCount} รูป และล้มเหลว ${errorCount} รูป`);
    }
  } catch (e) {
    alert('เกิดข้อผิดพลาดในการอัปโหลด');
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
  gap: 16px;
  padding: 0 0 20px 0;
}

.vehicle-card {
  background: var(--color-white);
  border-radius: 16px;
  border: 1px solid var(--color-sand-dark);
  padding: 20px;
  display: flex;
  gap: 16px;
  align-items: flex-start;
  transition: all 0.2s;
  box-shadow: 0 4px 6px rgba(0,0,0,0.02);
}

.vehicle-card:hover {
  box-shadow: 0 10px 20px rgba(0,0,0,0.05);
  transform: translateY(-2px);
}

.vehicle-icon {
  width: 52px;
  height: 52px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.vehicle-icon .material-symbols-rounded { font-size: 28px; }

.vtype-van { background: var(--color-sand); color: var(--color-accent); }
.vtype-boat { background: #eff6ff; color: #2563eb; }

.vehicle-info {
  flex: 1;
  min-width: 0;
}

.vehicle-info h3 {
  margin: 0 0 8px;
  font-size: 16px;
  color: var(--color-text-dark);
  font-weight: 700;
}

.vehicle-meta {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 8px;
}

.capacity-badge {
  font-size: 12px;
  color: var(--color-text-mid);
  display: flex;
  align-items: center;
  gap: 4px;
}

.plate-badge {
  font-size: 12px;
  background: var(--color-sand);
  color: var(--color-text-mid);
  padding: 4px 8px;
  border-radius: 6px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 4px;
  border: 1px solid var(--color-sand-dark);
}

.vehicle-detail-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--color-text-muted);
  margin-top: 6px;
}

.select-with-icon {
  position: relative;
  display: flex;
  align-items: center;
}

.select-with-icon .material-symbols-rounded {
  position: absolute;
  left: 12px;
  color: var(--color-text-muted);
  pointer-events: none;
}

.select-with-icon .form-input {
  padding-left: 40px;
}

.driver-phone {
  margin-left: 8px;
  color: var(--color-ocean);
  display: flex;
  align-items: center;
  gap: 4px;
}

.seat-layout-info {
  margin-top: 8px;
}

.layout-badge {
  font-size: 12px;
  color: var(--color-text-mid);
  display: flex;
  align-items: center;
  gap: 4px;
}

.pickup-summary {
  margin-top: 12px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  overflow: hidden;
}

.pickup-summary-header {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--color-text-muted);
  padding: 8px 12px;
  background: var(--color-sand);
  cursor: pointer;
  user-select: none;
  font-weight: 500;
}

.pickup-summary-header:hover { background: var(--color-sand-dark); color: var(--color-text-dark); }

.toggle-icon { margin-left: auto; font-size: 18px; }

.pickup-list { padding: 8px 12px; background: var(--color-white); }

.pickup-item { margin-bottom: 10px; }
.pickup-item:last-child { margin-bottom: 0; }

.region-chip {
  display: inline-block;
  font-size: 11px;
  background: #f3e8ff;
  color: #7e22ce;
  padding: 2px 10px;
  border-radius: 12px;
  font-weight: 700;
  margin-bottom: 6px;
}

.pickup-locations { padding-left: 6px; }

.pickup-loc-row {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  font-size: 13px;
  color: var(--color-text-dark);
  margin-top: 4px;
}

.pickup-notes {
  font-size: 12px;
  color: var(--color-text-mid);
  margin-left: 4px;
}

.vehicle-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.btn-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  transition: all 0.15s;
}

.btn-pickup {
  background: #f3e8ff;
  color: #7e22ce;
  border: none;
}
.btn-pickup:hover { background: #e9d5ff; }

.btn-layout {
  background: #fce7f3;
  color: #be185d;
  border: none;
}
.btn-layout:hover { background: #fbcfe8; }

.empty-state-card {
  grid-column: 1 / -1;
  text-align: center;
  padding: 80px 20px;
  color: var(--color-text-muted);
  background: var(--color-white);
  border-radius: 16px;
  border: 1px dashed var(--color-sand-dark);
}
.empty-state-card p {
  margin-top: 12px;
  font-size: 15px;
}

/* Modal sizes */
.modal-lg { max-width: 620px; }
.modal-xl { max-width: 760px; }

/* Form sections */
.form-section-title {
  font-size: 14px;
  font-weight: 700;
  color: var(--color-text-dark);
  margin: 20px 0 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Pickup manager */
.pickup-manager-list { margin-bottom: 20px; }

.pickup-region-group { margin-bottom: 20px; }

.pickup-region-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.region-chip-lg {
  display: inline-block;
  background: #f3e8ff;
  color: #7e22ce;
  font-size: 13px;
  font-weight: 700;
  padding: 4px 14px;
  border-radius: 16px;
}

.region-code { font-size: 12px; color: var(--color-text-mid); }

.pickup-manager-items { display: flex; flex-direction: column; gap: 8px; }

.pickup-manager-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 12px 14px;
}

.pickup-manager-item-info {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  flex: 1;
}

.pickup-loc-name { display:flex; align-items:center; gap:4px; font-size: 14px; color: var(--color-text-dark); font-weight: 600; }
.pickup-notes-text { display:flex; align-items:center; gap:4px; font-size: 13px; color: var(--color-text-muted); }
.map-link { display:flex; align-items:center; gap:4px; font-size: 13px; color: var(--color-ocean); text-decoration: none; }
.map-link:hover { text-decoration: underline; }

.pickup-manager-item-actions { display: flex; gap: 6px; }

.btn-sm { padding: 6px 12px !important; font-size: 13px !important; height: auto; border-radius: 8px;}

.pickup-empty {
  text-align: center;
  padding: 40px;
  color: var(--color-text-muted);
}
.pickup-empty p { margin-top: 12px; font-size: 15px;}

.pickup-add-section {
  border-top: 1px solid var(--color-sand-dark);
  padding-top: 20px;
}

.pickup-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 16px;
}
.driver-photo-avatar { width: 28px; height: 28px; border-radius: 50%; overflow: hidden; border: 1px solid var(--color-sand-dark); }
.driver-photo-avatar img { width: 100%; height: 100%; object-fit: cover; }
.media-upload-row { display: flex; gap: 12px; margin-top: 8px; }
.media-preview-sm { position: relative; width: 120px; height: 120px; border-radius: 12px; overflow: hidden; border: 1px solid var(--color-sand-dark); }
.media-preview-sm img { width: 100%; height: 100%; object-fit: cover; }
.video-preview { position: relative; width: 100%; max-width: 320px; border-radius: 12px; overflow: hidden; border: 1px solid var(--color-sand-dark); }
.video-preview video { width: 100%; display: block; }
.upload-placeholder { 
  width: 140px; height: 120px; border: 2px dashed var(--color-sand-dark); border-radius: 12px; 
  display: flex; flex-direction: column; align-items: center; justify-content: center; 
  cursor: pointer; color: var(--color-text-mid); font-size: 13px; transition: all 0.2s; background: var(--color-white);
}
.upload-placeholder:hover { border-color: var(--color-ocean); color: var(--color-ocean); background: #eff6ff; }
.upload-placeholder .material-symbols-rounded { font-size: 28px; margin-bottom: 6px; }

.gallery-grid-editor { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 12px; margin-top: 8px; }
.gallery-item-preview { position: relative; height: 110px; border-radius: 10px; overflow: hidden; border: 1px solid var(--color-sand-dark); }
.gallery-item-preview img { width: 100%; height: 100%; object-fit: cover; }
.gallery-add-btn { 
  height: 110px; border: 2px dashed var(--color-sand-dark); border-radius: 10px; 
  display: flex; flex-direction: column; align-items: center; justify-content: center; 
  cursor: pointer; color: var(--color-text-mid); font-size: 12px; background: var(--color-white);
}
.gallery-add-btn:hover { border-color: var(--color-ocean); color: var(--color-ocean); background: #eff6ff; }
.gallery-add-btn .material-symbols-rounded { font-size: 24px; margin-bottom: 4px; }
.remove-btn { 
  position: absolute; top: 6px; right: 6px; width: 24px; height: 24px; 
  border-radius: 50%; background: rgba(255, 255, 255, 0.95); color: #ef4444; 
  border: 1px solid #fee2e2; display: flex; align-items: center; justify-content: center; 
  cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: transform 0.15s;
}
.remove-btn:hover { transform: scale(1.1); }
</style>
