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
                    <div class="trip-badges">
                      <span v-if="trip.is_women_only" class="badge-women"><i class="fas fa-female"></i> หญิงล้วน</span>
                      <span class="trip-duration">{{ trip.duration_days }} วัน · สูงสุด {{ trip.max_participants }} คน</span>
                    </div>
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

            <!-- ─── Highlights ─── -->
            <div class="form-group full-width">
              <label class="list-editor-label text-[var(--color-primary)] font-black text-lg mb-4 flex items-center gap-2">
                <i class="fas fa-star"></i> จุดเด่นของทริป
              </label>
              <div class="highlights-editor space-y-4">
                <div v-for="(hi, idx) in form.highlights" :key="idx" class="highlight-item bg-gray-50 p-5 rounded-2xl border border-gray-100 flex gap-4 items-start relative group">
                  <div class="highlight-icon-selector">
                    <div class="w-12 h-12 rounded-xl bg-white border border-gray-200 flex items-center justify-center text-[var(--color-accent)] cursor-pointer hover:bg-gray-50 transition-colors"
                      @click="toggleIconPicker(idx)">
                      <span class="material-symbols-rounded text-2xl">{{ hi.icon || 'star' }}</span>
                    </div>
                    <div v-if="activeIconPicker === idx" class="icon-picker-dropdown absolute top-full left-0 z-50 bg-white border border-gray-200 shadow-xl rounded-xl p-3 grid grid-cols-5 gap-2 mt-2 w-64">
                      <button v-for="icon in commonIcons" :key="icon" type="button" @click="selectIcon(idx, icon)"
                        class="w-10 h-10 rounded-lg hover:bg-[var(--color-sand)] flex items-center justify-center transition-colors">
                        <span class="material-symbols-rounded text-xl">{{ icon }}</span>
                      </button>
                      <div class="col-span-5 pt-2 border-t mt-1">
                        <input v-model="hi.icon" placeholder="ระบุชื่อไอคอน (Google)" class="text-xs p-2 w-full border rounded-md" />
                      </div>
                    </div>
                  </div>
                  <div class="flex-1 space-y-3">
                    <input v-model="hi.title" placeholder="หัวข้อจุดเด่น (เช่น ประกันภัยการเดินทาง)" class="font-bold w-full bg-white px-3 py-2 border rounded-lg focus:ring-2 ring-[var(--color-accent)]/20" />
                    <textarea v-model="hi.desc" rows="2" placeholder="คำอธิบาย (เช่น คุ้มครองอุบัติเหตุตลอดการเดินทาง...)" class="text-sm w-full bg-white px-3 py-2 border rounded-lg focus:ring-2 ring-[var(--color-accent)]/20"></textarea>
                  </div>
                  <button type="button" class="remove-highlight-btn text-red-400 hover:text-red-600 p-2" @click="removeItem('highlights', idx)">
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </div>
                <button type="button" class="w-full py-4 border-2 border-dashed border-gray-200 rounded-2xl text-gray-400 font-bold hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-all flex items-center justify-center gap-2 group" @click="addItem('highlights')">
                  <i class="fas fa-plus-circle group-hover:scale-110 transition-transform"></i> เพิ่มจุดเด่นใหม่
                </button>
              </div>
            </div>

            <!-- ─── Inclusions / Exclusions ─── -->
            <div class="form-group full-width">
              <div class="list-editor-container">
                <div class="list-editor">
                  <label class="list-editor-label text-green-700">
                    <i class="fas fa-check-circle"></i> สิ่งที่รวมในทริป
                  </label>
                  <div class="list-items">
                    <div v-for="(item, idx) in form.inclusions" :key="idx" class="list-item">
                      <input v-model="form.inclusions[idx]" placeholder="เช่น ค่าธรรมเนียมเข้าอุทยาน" />
                      <button type="button" class="remove-item-btn" @click="removeItem('inclusions', idx)">
                        <i class="fas fa-times"></i>
                      </button>
                    </div>
                    <button type="button" class="add-item-btn" @click="addItem('inclusions')">
                      <i class="fas fa-plus"></i> เพิ่มรายการ
                    </button>
                  </div>
                </div>
                <div class="list-editor">
                  <label class="list-editor-label text-red-600">
                    <i class="fas fa-times-circle"></i> สิ่งที่ไม่รวม
                  </label>
                  <div class="list-items">
                    <div v-for="(item, idx) in form.exclusions" :key="idx" class="list-item">
                      <input v-model="form.exclusions[idx]" placeholder="เช่น ค่าใช้จ่ายส่วนตัว" />
                      <button type="button" class="remove-item-btn" @click="removeItem('exclusions', idx)">
                        <i class="fas fa-times"></i>
                      </button>
                    </div>
                    <button type="button" class="add-item-btn" @click="addItem('exclusions')">
                      <i class="fas fa-plus"></i> เพิ่มรายการ
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- ─── Must Know (Popup Info) ─── -->
            <div class="form-group full-width">
              <label class="list-editor-label text-amber-600 font-black text-lg mb-4 flex items-center gap-2">
                <i class="fas fa-bullhorn"></i> ข้อควรรู้สำหรับทริปนี้ (แสดงเป็น Popup ตอนเข้าชม)
              </label>
              <div class="must-know-editor bg-amber-50 p-6 rounded-[2rem] border border-amber-100 space-y-6">
                <div class="space-y-4">
                  <label class="text-sm font-black text-amber-700 uppercase tracking-widest pl-1 mb-1 block">รายการเพิ่มเติม / ราคาพิเศษ</label>
                  <div class="space-y-3">
                    <div v-for="(item, idx) in form.must_know.items" :key="idx" class="flex gap-3 items-center animate-fade-in">
                      <input v-model="item.name" placeholder="ชื่อรายการ (เช่น ข้าวไข่เจียว)" class="flex-1 px-4 py-3 rounded-xl border border-gray-200 focus:ring-2 ring-amber-500/20" />
                      <div class="flex items-center gap-2">
                        <span class="text-gray-400 font-bold">฿</span>
                        <input v-model.number="item.price" type="number" placeholder="ราคา" class="w-24 px-4 py-3 rounded-xl border border-gray-200 focus:ring-2 ring-amber-500/20" />
                      </div>
                      <button type="button" @click="removeItem('must_know_items', idx)" class="text-red-400 hover:text-red-600 p-2 transition-colors">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </div>
                    <button type="button" @click="addItem('must_know_items')" class="w-full py-4 border-2 border-dashed border-amber-200 rounded-2xl text-amber-600 font-bold hover:bg-white hover:border-amber-400 transition-all flex items-center justify-center gap-2 group">
                      <i class="fas fa-plus-circle group-hover:scale-110 transition-transform"></i> เพิ่มรายการใหม่
                    </button>
                  </div>
                </div>
                <div class="pt-4 border-t border-amber-200 space-y-3">
                  <label class="text-sm font-black text-amber-700 uppercase tracking-widest pl-1 mb-1 block">หมายเหตุเพิ่มเติม</label>
                  <textarea v-model="form.must_know.remarks" rows="2" placeholder="เช่น กรุณาแจ้งล่วงหน้า 1 วันหากต้องการสั่งอาหารเพิ่มเติม" class="w-full px-4 py-4 rounded-xl border border-gray-200 focus:ring-2 ring-amber-500/20 resize-none font-bold text-gray-700"></textarea>
                </div>
              </div>
            </div>

            <!-- ─── Image Upload ─── -->
            <div class="form-group">
              <label>แนะนำบนหน้าหลัก</label>
              <label class="featured-toggle">
                <input type="checkbox" v-model="form.is_featured" />
                <span class="featured-toggle-label">
                  <i class="fas fa-star"></i> แนะนำ
                </span>
              </label>
            </div>
            <div class="form-group">
              <label>กลุ่มเป้าหมาย</label>
              <label class="women-only-toggle" :class="{ active: form.is_women_only }">
                <input type="checkbox" v-model="form.is_women_only" />
                <span class="women-only-label">
                  <i class="fas fa-female"></i> ทริปสำหรับผู้หญิงเท่านั้น
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
                    <p class="dropzone-hint">รองรับ JPG, PNG, WebP, GIF (สูงสุด 10MB)</p>
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

            <div class="form-group full-width">
              <label>รูปภาพเพิ่มเติมในแกลเลอรี่</label>
              <div class="gallery-grid-editor">
                <div v-for="(img, idx) in form.gallery" :key="idx" class="gallery-item-preview">
                  <img :src="img" />
                  <button type="button" class="remove-gallery-img" @click="removeItem('gallery', idx)">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
                <div class="gallery-add-btn" @click="triggerGalleryUpload">
                  <i class="fas fa-plus" v-if="!galleryUploading"></i>
                  <i class="fas fa-spinner fa-spin" v-else></i>
                  <span>เพิ่มรูป</span>
                </div>
              </div>
              <input
                ref="galleryInput"
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp,image/gif"
                class="hidden-file-input"
                @change="handleGallerySelect"
              />
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
  latitude: null, longitude: null, is_featured: false, is_women_only: false,
  gallery: [], inclusions: [], exclusions: [],
  highlights: [],
  must_know: { items: [], remarks: '' },
});

const activeIconPicker = ref(null);
const commonIcons = [
  'shield_person', 'restaurant', 'scuba_diving', 'directions_boat', 'photo_camera',
  'hiking', 'camping', 'airport_shuttle', 'badge', 'hotel', 'explore', 'terrain',
  'schedule', 'verified_user', 'map', 'stars', 'local_taxi', 'groups', 'eco', 'waves'
];

const toggleIconPicker = (idx) => {
  activeIconPicker.value = activeIconPicker.value === idx ? null : idx;
};

const selectIcon = (idx, icon) => {
  form.highlights[idx].icon = icon;
  activeIconPicker.value = null;
};

const galleryInput = ref(null);
const galleryUploading = ref(false);

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
  // Validate file size (10MB max)
  if (file.size > 10 * 1024 * 1024) {
    alert('ไฟล์มีขนาดเกิน 10MB');
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
    formData.append('file', file);

    const res = await api.post('/admin/upload-image', formData);

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

// ─── Gallery Upload ─────────────────────────

const triggerGalleryUpload = () => {
  galleryInput.value?.click();
};

const handleGallerySelect = async (event) => {
  const files = Array.from(event.target.files);
  if (!files.length) return;

  galleryUploading.value = true;
  try {
    const validFiles = files.filter(file => {
      if (file.size > 10 * 1024 * 1024) {
        console.warn(`File ${file.name} is too large (>10MB)`);
        return false;
      }
      return true;
    });

    if (validFiles.length < files.length) {
      alert(`มีบางไฟล์ขนาดเกิน 10MB และจะถูกข้ามไป`);
    }

    if (!validFiles.length) {
      galleryUploading.value = false;
      return;
    }

    const uploadPromises = validFiles.map(async (file) => {
      const formData = new FormData();
      formData.append('file', file);
      const res = await api.post('/admin/upload-image', formData);
      return res.data.data.url;
    });

    const urls = await Promise.all(uploadPromises);
    form.gallery = [...(form.gallery || []), ...urls];
  } catch (e) {
    alert('อัปโหลดรูปภาพแกลเลอรี่บางส่วนล้มเหลว');
  } finally {
    galleryUploading.value = false;
    if (galleryInput.value) galleryInput.value.value = '';
  }
};

// ─── Dynamic List Helpers ──────────────────

const addItem = (field) => {
  if (!form[field]) form[field] = [];
  if (field === 'highlights') {
    form[field].push({ title: '', desc: '', icon: 'star' });
  } else if (field === 'must_know_items') {
    if (!form.must_know.items) form.must_know.items = [];
    form.must_know.items.push({ name: '', price: 0 });
  } else {
    form[field].push('');
  }
};

const removeItem = (field, index) => {
  if (field === 'must_know_items') {
    form.must_know.items.splice(index, 1);
  } else {
    form[field].splice(index, 1);
  }
};

// ─── Form Methods ────────────────────────────

const openForm = (trip = null) => {
  editingTrip.value = trip;
  imagePreview.value = null;
  if (trip) {
    Object.assign(form, { ...trip });
    form.latitude = trip.latitude || null;
    form.longitude = trip.longitude || null;
    form.gallery = trip.gallery || [];
    form.inclusions = trip.inclusions || [];
    form.exclusions = trip.exclusions || [];
    form.highlights = trip.highlights || [];
    form.must_know = trip.must_know || { items: [], remarks: '' };
  } else {
    Object.assign(form, {
      title: '', type: 'trekking', location: '', description: '',
      difficulty: 'medium', duration_days: 1, max_participants: 10,
      price_per_person: 0, departure_point: '', status: 'active', cover_image: '',
      latitude: null, longitude: null, is_featured: false, is_women_only: false,
      gallery: [], inclusions: [], exclusions: [],
      highlights: [],
      must_know: { items: [], remarks: '' },
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

/* ─── Women Only Toggle ───────────────── */
.women-only-toggle {
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
.women-only-toggle:has(input:checked) {
  border-color: #db2777;
  background: #fdf2f8;
}
.women-only-toggle input[type="checkbox"] {
  width: 16px;
  height: 16px;
  accent-color: #db2777;
  cursor: pointer;
}
.women-only-label {
  font-size: 14px;
  color: #374151;
  display: flex;
  align-items: center;
  gap: 7px;
}
.women-only-label i {
  color: #db2777;
  font-size: 15px;
}

/* ─── Table Badges ───────────────────── */
.trip-badges {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 4px;
}

.badge-women {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #fdf2f8;
  color: #db2777;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 800;
  border: 1px solid #f9a8d4;
  width: fit-content;
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

/* ─── Highlights Editor ───────────────── */
.highlights-editor {
  background: white;
  padding: 1.5rem;
  border-radius: 1.5rem;
  border: 1px solid #f1f5f9;
}

.highlight-item {
  transition: all 0.2s ease;
}

.highlight-item:hover {
  background: #f8fafc;
  border-color: #e2e8f0;
}

.highlight-icon-selector {
  position: relative;
}

.icon-picker-dropdown {
  width: 280px;
  max-height: 320px;
  overflow-y: auto;
  box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
}

.remove-highlight-btn {
  opacity: 0;
  transition: opacity 0.2s;
}

.highlight-item:hover .remove-highlight-btn {
  opacity: 1;
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

/* ─── List Editor ─── */
.list-editor-container {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
@media (max-width: 640px) {
  .list-editor-container { grid-template-columns: 1fr; }
}
.list-editor-label {
  display: block;
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 10px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.list-items {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.list-item {
  display: flex;
  gap: 8px;
}
.list-item input {
  flex: 1;
  font-size: 13px;
  padding: 8px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
}
.remove-item-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #e5e7eb;
  background: white;
  color: #ef4444;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
}
.remove-item-btn:hover { background: #fef2f2; border-color: #fca5a5; }
.add-item-btn {
  width: 100%;
  padding: 8px;
  border: 1px dashed #d1d5db;
  background: #FAFAFA;
  color: #6b7280;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
}
.add-item-btn:hover { border-color: #9ca3af; background: #EEEEEE; }

/* ─── Gallery Editor ─── */
.gallery-grid-editor {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
  gap: 12px;
  background: #FAFAFA;
  padding: 16px;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
}
.gallery-item-preview {
  position: relative;
  aspect-ratio: 1/1;
  border-radius: 6px;
  overflow: hidden;
  border: 1px solid #e5e7eb;
}
.gallery-item-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.remove-gallery-img {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 22px;
  height: 22px;
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.9);
  color: #ef4444;
  border: 1px solid #e5e7eb;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  cursor: pointer;
}
.gallery-add-btn {
  aspect-ratio: 1/1;
  border: 2px dashed #d1d5db;
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.15s;
}
.gallery-add-btn:hover { border-color: #9ca3af; background: #EEEEEE; color: #374151; }
.gallery-add-btn i { font-size: 16px; }
.gallery-add-btn span { font-size: 10px; font-weight: 700; }
</style>
