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

    <!-- Slides list -->
    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div v-else>
        <div v-if="!slides.length" class="empty-state">
          <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">slideshow</span>
          ยังไม่มีภาพสไลด์ กด "เพิ่มภาพใหม่" เพื่อเริ่มต้น
        </div>

        <div v-else class="slides-list">
          <div
            v-for="(slide, index) in slides"
            :key="slide.id"
            class="slide-row"
            :class="{ 'slide-inactive': !slide.is_active }"
          >
            <!-- Order indicator -->
            <div class="slide-order">
              <span class="material-symbols-rounded drag-handle">drag_indicator</span>
              <span class="order-num">{{ index + 1 }}</span>
            </div>

            <!-- Thumbnail -->
            <div class="slide-preview">
              <img :src="slide.image_url" :alt="slide.alt_text" class="slide-thumb" @error="(e) => e.target.style.opacity = '0.3'" />
            </div>

            <!-- Info -->
            <div class="slide-info">
              <p class="slide-alt">{{ slide.alt_text || '(ไม่มีคำอธิบาย)' }}</p>
              <p class="slide-url" :title="slide.image_url">{{ slide.image_url }}</p>
            </div>

            <!-- Status badge -->
            <span class="status-pill" :class="slide.is_active ? 'pill-active' : 'pill-inactive'">
              {{ slide.is_active ? 'แสดงผล' : 'ซ่อนอยู่' }}
            </span>

            <!-- Actions -->
            <div class="action-btns">
              <button
                class="btn-icon"
                :disabled="index === 0"
                @click="moveSlide(index, -1)"
                title="เลื่อนขึ้น"
              >
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_upward</span>
              </button>
              <button
                class="btn-icon"
                :disabled="index === slides.length - 1"
                @click="moveSlide(index, 1)"
                title="เลื่อนลง"
              >
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
    <div class="modal-overlay" v-if="showForm" @click.self="showForm = false">
      <div class="modal-card" style="max-width:560px">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขภาพสไลด์' : 'เพิ่มภาพสไลด์ใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <form @submit.prevent="submitForm">
          <div class="modal-body">
            <div class="form-grid">

              <!-- Upload zone -->
              <div class="form-group full-width">
                <label>อัปโหลดภาพ</label>
                <div
                  class="upload-zone"
                  @click="fileInput.click()"
                  @dragover.prevent
                  @drop.prevent="handleDrop"
                >
                  <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/jpg,image/png,image/webp"
                    style="display:none"
                    @change="handleFileSelect"
                  />
                  <div v-if="uploadPreview" class="upload-preview-wrap">
                    <img :src="uploadPreview" class="upload-preview-img" />
                    <button type="button" class="upload-clear" @click.stop="clearUpload" title="ลบภาพ">
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                  <div v-else class="upload-placeholder">
                    <span class="material-symbols-rounded">cloud_upload</span>
                    <p style="font-size:14px;font-weight:600;color:#6b7280">คลิกหรือลากไฟล์มาวางที่นี่</p>
                    <p style="font-size:12px;color:#9ca3af">รองรับ JPG, PNG, WebP (สูงสุด 50 MB)</p>
                  </div>
                </div>
                <div class="upload-progress" v-if="uploading">
                  <div class="progress-bar" :style="{ width: uploadProgress + '%' }"></div>
                </div>
                <p v-if="uploading" style="font-size:12px;color:#6b7280;margin-top:4px">กำลังอัปโหลด {{ uploadProgress }}%...</p>
              </div>

              <!-- URL input -->
              <div class="form-group full-width">
                <label>หรือใส่ URL ภาพโดยตรง</label>
                <input
                  v-model="form.image_url"
                  type="url"
                  placeholder="https://example.com/image.jpg"
                  @input="urlPreviewError = false"
                />
              </div>

              <!-- URL preview -->
              <div class="form-group full-width" v-if="form.image_url && !uploadPreview">
                <label>ตัวอย่างภาพจาก URL</label>
                <img
                  v-if="!urlPreviewError"
                  :src="form.image_url"
                  class="url-preview"
                  @error="urlPreviewError = true"
                />
                <p v-else style="font-size:13px;color:#dc2626">⚠ ไม่สามารถโหลดภาพจาก URL นี้ได้</p>
              </div>

              <!-- Alt text -->
              <div class="form-group full-width">
                <label>Alt Text <span style="font-weight:400;color:#9ca3af">(คำอธิบายภาพ)</span></label>
                <input v-model="form.alt_text" placeholder="เช่น ภูสอยดาว ยอดดอยสวยงาม" />
              </div>

              <!-- Status toggle -->
              <div class="form-group">
                <label>สถานะการแสดงผล</label>
                <div class="toggle-group" style="margin-top:8px">
                  <label class="switch">
                    <input type="checkbox" v-model="form.is_active" />
                    <span class="slider round"></span>
                  </label>
                  <span style="font-size:14px;font-weight:600;" :style="{ color: form.is_active ? '#059669' : '#6b7280' }">
                    {{ form.is_active ? 'แสดงผล' : 'ซ่อนอยู่' }}
                  </span>
                </div>
              </div>

            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button
              type="submit"
              class="btn-primary"
              :disabled="submitting || uploading || (!form.image_url)"
            >
              <span class="material-symbols-rounded animate-spin" style="font-size:16px" v-if="submitting">sync</span>
              {{ editing ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มสไลด์' }}
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
          <button class="modal-close" @click="showDeleteConfirm = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบภาพสไลด์นี้ใช่หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
          <div v-if="deleting" style="margin-top:12px">
            <img
              :src="deleting.image_url"
              :alt="deleting.alt_text"
              style="width:100%;max-height:140px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb"
            />
            <p style="font-size:13px;color:#6b7280;margin-top:6px;text-align:center">{{ deleting.alt_text }}</p>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">
            <span class="material-symbols-rounded animate-spin" style="font-size:16px" v-if="submitting">sync</span>
            ยืนยันการลบ
          </button>
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
    uploadPreview.value = slide.image_url;
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
  form.image_url = '';
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
@import url('./admin-shared.css');

/* ─── Heading icon ─────────────────────── */
.heading-icon {
  color: var(--color-accent);
  font-size: 28px;
}

/* ─── Slides list ──────────────────────── */
.slides-list {
  display: flex;
  flex-direction: column;
}

.slide-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 20px;
  border-bottom: 1px solid #f0f0f0;
  transition: background 0.15s;
}
.slide-row:last-child { border-bottom: none; }
.slide-row:hover { background: #fafafa; }
.slide-inactive { opacity: 0.45; }

.slide-order {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  width: 32px;
  flex-shrink: 0;
  color: #aaa;
}
.drag-handle { cursor: grab; font-size: 20px; color: #cbd5e1; }
.order-num { font-size: 11px; font-weight: 700; color: #9ca3af; }

.slide-preview { flex-shrink: 0; }
.slide-thumb {
  width: 128px;
  height: 72px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
  background: #f3f4f6;
  display: block;
}

.slide-info {
  flex: 1;
  min-width: 0;
}
.slide-alt {
  font-weight: 600;
  font-size: 14px;
  color: #111827;
  margin-bottom: 2px;
}
.slide-url {
  font-size: 12px;
  color: #9ca3af;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 300px;
}

.status-pill {
  flex-shrink: 0;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 700;
}
.pill-active  { background: #d1fae5; color: #059669; }
.pill-inactive { background: #fee2e2; color: #dc2626; }

/* ─── Upload zone ──────────────────────── */
.upload-zone {
  border: 2px dashed #d1d5db;
  border-radius: 12px;
  padding: 24px;
  cursor: pointer;
  text-align: center;
  transition: border-color 0.2s, background 0.2s;
  position: relative;
  min-height: 148px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.upload-zone:hover { border-color: var(--color-accent); background: #f0faf4; }

.upload-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: #9ca3af;
  pointer-events: none;
}
.upload-placeholder .material-symbols-rounded { font-size: 44px; color: #d1d5db; }

.upload-preview-wrap { position: relative; width: 100%; }
.upload-preview-img {
  width: 100%;
  max-height: 220px;
  object-fit: cover;
  border-radius: 8px;
  display: block;
}
.upload-clear {
  position: absolute;
  top: 8px;
  right: 8px;
  background: rgba(0,0,0,0.55);
  color: white;
  border: none;
  border-radius: 50%;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
}
.upload-clear:hover { background: rgba(0,0,0,0.75); }
.upload-clear .material-symbols-rounded { font-size: 16px; }

.upload-progress {
  height: 4px;
  background: #e5e7eb;
  border-radius: 4px;
  margin-top: 8px;
  overflow: hidden;
}
.progress-bar {
  height: 100%;
  background: var(--color-accent);
  transition: width 0.25s;
}

.url-preview {
  width: 100%;
  max-height: 180px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  display: block;
}

/* ─── Toggle switch ────────────────────── */
.toggle-group { display: flex; align-items: center; gap: 8px; }
.switch { position: relative; display: inline-block; width: 40px; height: 22px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
  position: absolute; cursor: pointer; inset: 0;
  background: #d1d5db; border-radius: 22px; transition: .3s;
}
.slider:before {
  content: ''; position: absolute;
  height: 16px; width: 16px;
  left: 3px; bottom: 3px;
  background: white; border-radius: 50%; transition: .3s;
}
input:checked + .slider { background: var(--color-accent); }
input:checked + .slider:before { transform: translateX(18px); }
</style>
