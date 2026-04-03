<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-route"></i> จัดการทริป</h1>
        <p class="page-subtitle">จัดการทริปและกิจกรรมทั้งหมด</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <i class="fas fa-plus"></i> เพิ่มทริปใหม่
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input v-model="filters.search" placeholder="ค้นหาทริป..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.type" @change="fetchData()">
        <option value="">ทุกประเภท</option>
        <option value="trekking">เดินป่า</option>
        <option value="diving">ดำน้ำ</option>
        <option value="snorkeling">ดำน้ำตื้น</option>
        <option value="climbing">ปีนผา</option>
      </select>
      <select v-model="filters.status" @change="fetchData()">
        <option value="">ทุกสถานะ</option>
        <option value="active">ใช้งาน</option>
        <option value="inactive">ปิด</option>
        <option value="full">เต็ม</option>
      </select>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="admin.loading">
        <div class="spinner"></div>
      </div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>ทริป</th>
              <th>ประเภท</th>
              <th>สถานที่</th>
              <th>ราคา</th>
              <th>ความยาก</th>
              <th>สถานะ</th>
              <th>แนะนำ</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="trip in admin.trips.data" :key="trip.id">
              <td>
                <div class="trip-cell">
                  <img :src="trip.cover_image || '/images/placeholder.jpg'" class="trip-thumb" />
                  <div>
                    <span class="trip-name">{{ trip.title }}</span>
                    <span class="trip-duration">{{ trip.duration_days }} วัน · สูงสุด {{ trip.max_participants }} คน</span>
                  </div>
                </div>
              </td>
              <td><span class="type-tag" :class="`type-${trip.type}`">{{ typeLabels[trip.type] }}</span></td>
              <td>{{ trip.location }}</td>
              <td class="money">{{ formatMoney(trip.price_per_person) }}</td>
              <td><span class="diff-badge" :class="`diff-${trip.difficulty}`">{{ diffLabels[trip.difficulty] }}</span></td>
              <td><span class="status-badge" :class="`status-${trip.status}`">{{ statusLabels[trip.status] }}</span></td>
              <td>
                <button
                  class="btn-icon btn-featured"
                  :class="{ active: trip.is_featured }"
                  @click="toggleFeatured(trip)"
                  :title="trip.is_featured ? 'ยกเลิกแนะนำ' : 'ตั้งเป็นแนะนำ'"
                >
                  <i class="fas fa-star"></i>
                </button>
              </td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-edit" @click="openForm(trip)" title="แก้ไข"><i class="fas fa-edit"></i></button>
                  <button class="btn-icon btn-copy" @click="duplicateTrip(trip)" title="คัดลอกทริป"><i class="fas fa-copy"></i></button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(trip)" title="ลบ"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
            <tr v-if="!admin.trips.data?.length">
              <td colspan="8" class="empty-state">ไม่พบข้อมูลทริป</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="pagination" v-if="admin.trips.meta?.last_page > 1">
        <button :disabled="admin.trips.meta.current_page <= 1" @click="goPage(admin.trips.meta.current_page - 1)">
          <i class="fas fa-chevron-left"></i>
        </button>
        <span class="page-info">{{ admin.trips.meta.current_page }} / {{ admin.trips.meta.last_page }}</span>
        <button :disabled="admin.trips.meta.current_page >= admin.trips.meta.last_page" @click="goPage(admin.trips.meta.current_page + 1)">
          <i class="fas fa-chevron-right"></i>
        </button>
      </div>
    </div>

    <!-- Modal Form -->
    <div class="modal-overlay" v-if="showForm" @click.self="showForm = false">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editingTrip ? 'แก้ไขทริป' : 'เพิ่มทริปใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อทริป *</label>
              <input v-model="form.title" required placeholder="เช่น เดินป่าดอยอินทนนท์ 2 วัน 1 คืน" />
            </div>
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" required>
                <option value="trekking">เดินป่า</option>
                <option value="diving">ดำน้ำ</option>
                <option value="snorkeling">ดำน้ำตื้น</option>
                <option value="climbing">ปีนผา</option>
              </select>
            </div>
            <div class="form-group">
              <label>สถานที่ *</label>
              <input v-model="form.location" required placeholder="เช่น เชียงใหม่" />
            </div>
            <div class="form-group">
              <label>ระดับความยาก *</label>
              <select v-model="form.difficulty" required>
                <option value="easy">ง่าย</option>
                <option value="medium">ปานกลาง</option>
                <option value="hard">ยาก</option>
              </select>
            </div>
            <div class="form-group">
              <label>ราคาต่อคน (฿) *</label>
              <input v-model.number="form.price_per_person" type="number" min="0" required />
            </div>
            <div class="form-group">
              <label>จำนวนวัน *</label>
              <input v-model.number="form.duration_days" type="number" min="1" required />
            </div>
            <div class="form-group">
              <label>จำนวนคนสูงสุด *</label>
              <input v-model.number="form.max_participants" type="number" min="1" required />
            </div>
            <div class="form-group">
              <label>สถานะ</label>
              <select v-model="form.status">
                <option value="active">ใช้งาน</option>
                <option value="inactive">ปิด</option>
                <option value="full">เต็ม</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label>จุดขึ้นรถ/เรือ</label>
              <input v-model="form.departure_point" placeholder="เช่น ประตูท่าแพ เชียงใหม่" />
            </div>

            <!-- ─── Location (Lat/Lng + Map) ─── -->
            <div class="form-group">
              <label><i class="fas fa-map-marker-alt"></i> Latitude</label>
              <input v-model.number="form.latitude" type="number" step="0.0000001" min="-90" max="90" placeholder="เช่น 8.0863" />
            </div>
            <div class="form-group">
              <label><i class="fas fa-map-marker-alt"></i> Longitude</label>
              <input v-model.number="form.longitude" type="number" step="0.0000001" min="-180" max="180" placeholder="เช่น 98.3706" />
            </div>
            <div class="form-group full-width" v-if="form.latitude && form.longitude">
              <label><i class="fas fa-map"></i> ตำแหน่งบนแผนที่</label>
              <div class="map-preview">
                <iframe
                  :src="mapEmbedUrl"
                  width="100%"
                  height="260"
                  style="border:0; border-radius: 12px;"
                  allowfullscreen
                  loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
              </div>
            </div>
            <div class="form-group full-width">
              <label>รายละเอียด</label>
              <textarea v-model="form.description" rows="3" placeholder="อธิบายทริป..."></textarea>
            </div>

            <!-- ─── Image Upload ─── -->
            <div class="form-group full-width">
              <label>แนะนำบนหน้าหลัก</label>
              <label class="featured-toggle">
                <input type="checkbox" v-model="form.is_featured" />
                <span class="featured-toggle-label">
                  <i class="fas fa-star"></i>
                  ตั้งทริปนี้เป็น "แนะนำสำหรับคุณ" บนหน้าหลัก
                </span>
              </label>
            </div>
            <div class="form-group full-width">
              <label>รูปปกทริป</label>
              <div class="upload-area">
                <!-- Preview -->
                <div class="upload-preview" v-if="imagePreview || form.cover_image">
                  <img :src="imagePreview || form.cover_image" alt="Cover preview" />
                  <button type="button" class="remove-image-btn" @click="removeImage" title="ลบรูป">
                    <i class="fas fa-times"></i>
                  </button>
                </div>

                <!-- Dropzone -->
                <div
                  class="upload-dropzone"
                  :class="{ dragging: isDragging }"
                  v-else
                  @click="triggerFileInput"
                  @dragover.prevent="isDragging = true"
                  @dragleave.prevent="isDragging = false"
                  @drop.prevent="handleDrop"
                >
                  <div class="dropzone-content">
                    <div class="dropzone-icon">
                      <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <p class="dropzone-text">คลิกเพื่อเลือกรูป หรือลากไฟล์มาวาง</p>
                    <p class="dropzone-hint">รองรับ JPG, PNG, WebP, GIF (สูงสุด 5MB)</p>
                  </div>
                </div>

                <!-- Uploading indicator -->
                <div class="upload-progress" v-if="uploading">
                  <div class="upload-progress-bar">
                    <div class="upload-progress-fill"></div>
                  </div>
                  <span class="upload-progress-text">กำลังอัปโหลด...</span>
                </div>

                <input
                  ref="fileInput"
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                  class="hidden-file-input"
                  @change="handleFileSelect"
                />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting || uploading">
              <i class="fas fa-spinner fa-spin" v-if="submitting"></i>
              {{ editingTrip ? 'บันทึก' : 'สร้างทริป' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="modal-overlay" v-if="showDeleteConfirm" @click.self="showDeleteConfirm = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบทริป <strong>{{ deletingTrip?.title }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><i class="fas fa-exclamation-triangle"></i> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">
            <i class="fas fa-spinner fa-spin" v-if="submitting"></i>
            ลบทริป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import api from '../../lib/axios';

const admin = useAdminStore();

const filters = reactive({ search: '', type: '', status: '' });
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editingTrip = ref(null);
const deletingTrip = ref(null);
const submitting = ref(false);

// Image upload state
const fileInput = ref(null);
const imagePreview = ref(null);
const uploading = ref(false);
const isDragging = ref(false);

const form = reactive({
  title: '', type: 'trekking', location: '', description: '',
  difficulty: 'medium', duration_days: 1, max_participants: 10,
  price_per_person: 0, departure_point: '', status: 'active', cover_image: '',
  latitude: null, longitude: null, is_featured: false,
});

const mapEmbedUrl = computed(() => {
  if (!form.latitude || !form.longitude) return '';
  return `https://www.google.com/maps?q=${form.latitude},${form.longitude}&z=14&output=embed`;
});

const typeLabels = { trekking: 'เดินป่า', diving: 'ดำน้ำ', snorkeling: 'ดำน้ำตื้น', climbing: 'ปีนผา' };
const diffLabels = { easy: 'ง่าย', medium: 'ปานกลาง', hard: 'ยาก' };
const statusLabels = { active: 'ใช้งาน', inactive: 'ปิด', full: 'เต็ม' };

const formatMoney = (amount) => new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);

let debounceTimer = null;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(), 300);
};

const fetchData = (page = 1) => {
  admin.fetchTrips({ ...filters, page });
};

const goPage = (page) => fetchData(page);

// ─── Image Upload Methods ────────────────────

const triggerFileInput = () => {
  fileInput.value?.click();
};

const handleFileSelect = (event) => {
  const file = event.target.files?.[0];
  if (file) uploadFile(file);
};

const handleDrop = (event) => {
  isDragging.value = false;
  const file = event.dataTransfer?.files?.[0];
  if (file && file.type.startsWith('image/')) {
    uploadFile(file);
  }
};

const uploadFile = async (file) => {
  // Validate file size (5MB max)
  if (file.size > 5 * 1024 * 1024) {
    alert('ไฟล์มีขนาดเกิน 5MB');
    return;
  }

  // Show local preview immediately
  const reader = new FileReader();
  reader.onload = (e) => {
    imagePreview.value = e.target.result;
  };
  reader.readAsDataURL(file);

  // Upload to server
  uploading.value = true;
  try {
    const formData = new FormData();
    formData.append('image', file);

    const res = await api.post('/admin/upload-image', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    form.cover_image = res.data.data.url;
    imagePreview.value = null; // Use server URL now
  } catch (e) {
    alert(e.response?.data?.message || 'อัปโหลดรูปไม่สำเร็จ');
    imagePreview.value = null;
  } finally {
    uploading.value = false;
    // Reset file input
    if (fileInput.value) fileInput.value.value = '';
  }
};

const removeImage = () => {
  form.cover_image = '';
  imagePreview.value = null;
  if (fileInput.value) fileInput.value.value = '';
};

// ─── Form Methods ────────────────────────────

const openForm = (trip = null) => {
  editingTrip.value = trip;
  imagePreview.value = null;
  if (trip) {
    Object.assign(form, { ...trip });
    form.latitude = trip.latitude || null;
    form.longitude = trip.longitude || null;
  } else {
    Object.assign(form, {
      title: '', type: 'trekking', location: '', description: '',
      difficulty: 'medium', duration_days: 1, max_participants: 10,
      price_per_person: 0, departure_point: '', status: 'active', cover_image: '',
      latitude: null, longitude: null, is_featured: false,
    });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  try {
    if (editingTrip.value) {
      await admin.updateTrip(editingTrip.value.id, form);
    } else {
      await admin.createTrip(form);
    }
    showForm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const duplicateTrip = (trip) => {
  editingTrip.value = null;
  imagePreview.value = null;
  Object.assign(form, {
    ...trip,
    title: `สำเนา ${trip.title}`,
    status: 'active',
    is_featured: false,
  });
  delete form.id;
  showForm.value = true;
};

const toggleFeatured = async (trip) => {
  try {
    await admin.updateTrip(trip.id, { ...trip, is_featured: !trip.is_featured });
    trip.is_featured = !trip.is_featured;
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  }
};

const confirmDelete = (trip) => {
  deletingTrip.value = trip;
  showDeleteConfirm.value = true;
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await admin.deleteTrip(deletingTrip.value.id);
    showDeleteConfirm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

onMounted(() => fetchData());
</script>

<style scoped>
@import url('./admin-shared.css');

/* ─── Copy Button ────────────────────── */
.btn-copy {
  color: #2563eb;
}
.btn-copy:hover {
  background: #eff6ff;
  border-color: #93c5fd;
}

/* ─── Featured Button ───────────────── */
.btn-featured {
  color: #d1d5db;
}
.btn-featured:hover {
  background: #fefce8;
  border-color: #fde68a;
  color: #f59e0b;
}
.btn-featured.active {
  color: #f59e0b;
}
.btn-featured.active:hover {
  background: #fef3c7;
  border-color: #fcd34d;
  color: #d97706;
}

/* ─── Featured Toggle ───────────────── */
.featured-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  padding: 12px 14px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  transition: all 0.15s;
  font-weight: normal;
}
.featured-toggle:has(input:checked) {
  border-color: #fcd34d;
  background: #fefce8;
}
.featured-toggle input[type="checkbox"] {
  width: 16px;
  height: 16px;
  accent-color: #f59e0b;
  cursor: pointer;
  flex-shrink: 0;
}
.featured-toggle-label {
  font-size: 14px;
  color: #374151;
  display: flex;
  align-items: center;
  gap: 7px;
}
.featured-toggle-label i {
  color: #f59e0b;
  font-size: 13px;
}

/* ─── Image Upload ────────────────────── */
.upload-area {
  position: relative;
}

.hidden-file-input {
  display: none;
}

.upload-dropzone {
  border: 2px dashed #d1d5db;
  border-radius: 10px;
  padding: 28px 20px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s ease;
  background: #FAFAFA;
}

.upload-dropzone:hover {
  border-color: #EEEEEE;
  background: #F5F5F5;
}

.upload-dropzone.dragging {
  border-color: #d1d5db;
  background: #EEEEEE;
  box-shadow: 0 0 0 3px rgba(45, 122, 79, 0.08);
}

.dropzone-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.dropzone-icon {
  width: 52px;
  height: 52px;
  border-radius: 12px;
  background: #F5F5F5;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 4px;
}

.dropzone-icon i {
  font-size: 20px;
  color: #15803d;
}

.dropzone-text {
  font-size: 14px;
  color: #374151;
  margin: 0;
  font-weight: 500;
}

.dropzone-hint {
  font-size: 12px;
  color: #9ca3af;
  margin: 0;
}

/* Preview */
.upload-preview {
  position: relative;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  background: #FAFAFA;
}

.upload-preview img {
  display: block;
  width: 100%;
  max-height: 240px;
  object-fit: cover;
}

.remove-image-btn {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 30px;
  height: 30px;
  border-radius: 7px;
  border: 1px solid #e5e7eb;
  background: rgba(255, 255, 255, 0.9);
  color: #dc2626;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.15s;
  font-size: 13px;
}

.remove-image-btn:hover {
  background: #fef2f2;
  border-color: #fca5a5;
}

/* Upload progress */
.upload-progress {
  margin-top: 12px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.upload-progress-bar {
  flex: 1;
  height: 5px;
  background: #e5e7eb;
  border-radius: 3px;
  overflow: hidden;
}

.upload-progress-fill {
  height: 100%;
  width: 60%;
  background: #2d7a4f;
  border-radius: 3px;
  animation: progressPulse 1.2s ease-in-out infinite;
}

@keyframes progressPulse {
  0%, 100% { width: 30%; opacity: 1; }
  50% { width: 80%; opacity: 0.6; }
}

.upload-progress-text {
  font-size: 12px;
  color: #2d7a4f;
  font-weight: 600;
  white-space: nowrap;
}

/* ─── Map Preview ─────────────────────── */
.map-preview {
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  background: #FAFAFA;
  animation: fadeIn 0.3s ease;
}

.map-preview iframe {
  display: block;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(6px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
