<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">slideshow</span>
          สไลด์ภาพหน้าแรก
        </h1>
        <p class="page-subtitle">จัดการภาพสไลด์ที่แสดงใน Hero Section หน้าแรกของเว็บไซต์</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add_photo_alternate</span> เพิ่มภาพใหม่
      </button>
    </div>

    <!-- Slides Grid -->
    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div v-else>
        <!-- Empty -->
        <div v-if="!slides.length" class="empty-state">ยังไม่มีภาพสไลด์ กด "เพิ่มภาพใหม่" เพื่อเริ่มต้น</div>

        <!-- Slides list -->
        <div v-else class="slides-list">
          <div
            v-for="(slide, index) in slides"
            :key="slide.id"
            class="slide-row"
            :class="{ 'slide-inactive': !slide.is_active }"
          >
            <!-- Drag handle + order -->
            <div class="slide-order">
              <span class="material-symbols-rounded drag-handle">drag_indicator</span>
              <span class="order-num">{{ index + 1 }}</span>
            </div>

            <!-- Preview -->
            <div class="slide-preview">
              <img :src="slide.image_url" :alt="slide.alt_text" class="slide-thumb" />
            </div>

            <!-- Info -->
            <div class="slide-info">
              <p class="slide-alt">{{ slide.alt_text }}</p>
              <p class="slide-url">{{ slide.image_url }}</p>
            </div>

            <!-- Status -->
            <span :class="['status-pill', slide.is_active ? 'pill-active' : 'pill-inactive']">
              {{ slide.is_active ? 'แสดงผล' : 'ซ่อนอยู่' }}
            </span>

            <!-- Actions -->
            <div class="action-btns">
              <button class="btn-icon" :disabled="index === 0" @click="moveSlide(index, -1)" title="เลื่อนขึ้น">
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_upward</span>
              </button>
              <button class="btn-icon" :disabled="index === slides.length - 1" @click="moveSlide(index, 1)" title="เลื่อนลง">
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_downward</span>
              </button>
              <button class="btn-icon btn-edit" @click="openForm(slide)" title="แก้ไข">
                <span class="material-symbols-rounded" style="font-size:16px;">edit</span>
              </button>
              <button class="btn-icon btn-delete" @click="confirmDelete(slide)" title="ลบ">
                <span class="material-symbols-rounded" style="font-size:16px;">delete</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขภาพสไลด์' : 'เพิ่มภาพสไลด์ใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">

            <!-- Upload or URL -->
            <div class="form-group full-width">
              <label>อัปโหลดภาพ</label>
              <div class="upload-zone" @click="$refs.fileInput.click()" @dragover.prevent @drop.prevent="handleDrop">
                <input ref="fileInput" type="file" accept="image/jpeg,image/jpg,image/png,image/webp" class="hidden" @change="handleFileSelect" />
                <div v-if="uploadPreview" class="upload-preview-wrap">
                  <img :src="uploadPreview" class="upload-preview-img" />
                  <button type="button" class="upload-clear" @click.stop="clearUpload">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <div v-else class="upload-placeholder">
                  <span class="material-symbols-rounded">cloud_upload</span>
                  <p>คลิกหรือลากไฟล์มาวางที่นี่</p>
                  <p class="text-xs text-gray-400">รองรับ JPG, PNG, WebP</p>
                </div>
              </div>
              <div class="upload-progress" v-if="uploading">
                <div class="progress-bar" :style="{ width: uploadProgress + '%' }"></div>
              </div>
            </div>

            <div class="form-group full-width">
              <label>หรือใส่ URL ภาพโดยตรง</label>
              <input v-model="form.image_url" type="url" placeholder="https://example.com/image.jpg" />
            </div>

            <!-- Preview when URL entered -->
            <div class="form-group full-width" v-if="form.image_url && !uploadPreview">
              <label>ตัวอย่างภาพ</label>
              <img :src="form.image_url" class="url-preview" @error="urlPreviewError = true" v-if="!urlPreviewError" />
              <p class="text-red-500 text-sm" v-else>ไม่สามารถโหลดภาพจาก URL นี้ได้</p>
            </div>

            <div class="form-group full-width">
              <label>Alt Text (คำอธิบายภาพ)</label>
              <input v-model="form.alt_text" placeholder="เช่น ภูสอยดาว ยอดดอยสวยงาม" />
            </div>

            <div class="form-group">
              <label>สถานะ</label>
              <div class="toggle-group mt-2">
                <label class="switch">
                  <input type="checkbox" v-model="form.is_active" />
                  <span class="slider round"></span>
                </label>
                <span class="ml-2">{{ form.is_active ? 'แสดงผล' : 'ซ่อนอยู่' }}</span>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting || uploading || (!form.image_url && !uploadPreview)">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ editing ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มสไลด์' }}
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
          <button class="modal-close" @click="showDeleteConfirm = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบภาพสไลด์นี้ใช่หรือไม่?</p>
          <div class="mt-3" v-if="deleting">
            <img :src="deleting.image_url" class="rounded-lg w-full max-h-40 object-cover" />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ยืนยันการลบ</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../../js/lib/axios';

const slides = ref([]);
const loading = ref(true);
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);

const fileInput = ref(null);
const uploadPreview = ref('');
const uploading = ref(false);
const uploadProgress = ref(0);
const urlPreviewError = ref(false);

const form = reactive({
  image_url: '',
  alt_text: 'ลุยเลเขา',
  is_active: true,
});

const fetchSlides = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/hero-slides');
    slides.value = res.data.data ?? res.data;
  } finally {
    loading.value = false;
  }
};

onMounted(fetchSlides);

const openForm = (slide = null) => {
  editing.value = slide;
  urlPreviewError.value = false;
  clearUpload();
  if (slide) {
    Object.assign(form, {
      image_url: slide.image_url,
      alt_text: slide.alt_text,
      is_active: !!slide.is_active,
    });
  } else {
    Object.assign(form, { image_url: '', alt_text: 'ลุยเลเขา', is_active: true });
  }
  showForm.value = true;
};

const handleDrop = (e) => {
  const file = e.dataTransfer.files[0];
  if (file) uploadFile(file);
};

const handleFileSelect = (e) => {
  const file = e.target.files[0];
  if (file) uploadFile(file);
};

const clearUpload = () => {
  uploadPreview.value = '';
  uploadProgress.value = 0;
  if (fileInput.value) fileInput.value.value = '';
};

const uploadFile = async (file) => {
  uploadPreview.value = URL.createObjectURL(file);
  uploading.value = true;
  uploadProgress.value = 0;
  try {
    const formData = new FormData();
    formData.append('file', file);
    const res = await api.post('/admin/upload-image', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        uploadProgress.value = Math.round((e.loaded / e.total) * 100);
      },
    });
    form.image_url = res.data.data?.url ?? res.data.url;
  } catch {
    alert('อัปโหลดภาพไม่สำเร็จ');
    clearUpload();
  } finally {
    uploading.value = false;
  }
};

const submitForm = async () => {
  if (!form.image_url) return;
  submitting.value = true;
  try {
    if (editing.value) {
      await api.put(`/admin/hero-slides/${editing.value.id}`, form);
    } else {
      await api.post('/admin/hero-slides', form);
    }
    showForm.value = false;
    await fetchSlides();
  } catch (e) {
    alert(e.response?.data?.message ?? 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (slide) => {
  deleting.value = slide;
  showDeleteConfirm.value = true;
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await api.delete(`/admin/hero-slides/${deleting.value.id}`);
    showDeleteConfirm.value = false;
    await fetchSlides();
  } finally {
    submitting.value = false;
  }
};

const moveSlide = async (index, direction) => {
  const newSlides = [...slides.value];
  const target = index + direction;
  [newSlides[index], newSlides[target]] = [newSlides[target], newSlides[index]];
  slides.value = newSlides;
  await api.post('/admin/hero-slides/reorder', { ids: newSlides.map((s) => s.id) });
};
</script>

<style scoped>
.slides-list {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.slide-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 20px;
  border-bottom: 1px solid #f0f0f0;
  transition: background 0.15s;
}
.slide-row:last-child { border-bottom: none; }
.slide-row:hover { background: #fafafa; }
.slide-inactive { opacity: 0.5; }

.slide-order {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  width: 32px;
  color: #aaa;
}
.drag-handle { cursor: grab; font-size: 20px; }
.order-num { font-size: 11px; font-weight: 600; color: #999; }

.slide-preview { flex-shrink: 0; }
.slide-thumb {
  width: 120px;
  height: 68px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
}

.slide-info {
  flex: 1;
  min-width: 0;
}
.slide-alt { font-weight: 600; font-size: 14px; color: #374151; }
.slide-url {
  font-size: 12px;
  color: #9ca3af;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Upload zone */
.upload-zone {
  border: 2px dashed #d1d5db;
  border-radius: 12px;
  padding: 24px;
  cursor: pointer;
  text-align: center;
  transition: border-color 0.2s, background 0.2s;
  position: relative;
  min-height: 140px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.upload-zone:hover { border-color: var(--color-accent, #4f46e5); background: #f9f9ff; }

.upload-placeholder { display: flex; flex-direction: column; align-items: center; gap: 8px; color: #9ca3af; }
.upload-placeholder .material-symbols-rounded { font-size: 40px; }

.upload-preview-wrap { position: relative; width: 100%; }
.upload-preview-img { width: 100%; max-height: 200px; object-fit: cover; border-radius: 8px; }
.upload-clear {
  position: absolute;
  top: 8px;
  right: 8px;
  background: rgba(0,0,0,0.5);
  color: white;
  border: none;
  border-radius: 50%;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.upload-progress {
  height: 4px;
  background: #e5e7eb;
  border-radius: 4px;
  margin-top: 8px;
  overflow: hidden;
}
.progress-bar {
  height: 100%;
  background: var(--color-accent, #4f46e5);
  transition: width 0.3s;
}

.url-preview {
  width: 100%;
  max-height: 180px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
}

.empty-state {
  padding: 60px 20px;
  text-align: center;
  color: #9ca3af;
  font-size: 15px;
}

/* reuse toggle & status styles from global admin CSS */
.toggle-group { display: flex; align-items: center; }
.switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
  position: absolute; cursor: pointer; inset: 0;
  background: #d1d5db; border-radius: 24px; transition: .3s;
}
.slider:before {
  content: ''; position: absolute;
  height: 18px; width: 18px;
  left: 3px; bottom: 3px;
  background: white; border-radius: 50%; transition: .3s;
}
input:checked + .slider { background: var(--color-accent, #4f46e5); }
input:checked + .slider:before { transform: translateX(20px); }
</style>
