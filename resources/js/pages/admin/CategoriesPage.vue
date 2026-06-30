<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">category</span> หมวดหมู่กิจกรรม</h1>
        <p class="page-subtitle">จัดการประเภททริป ภาพ และการ์ดที่แสดงในหน้าแรก · ลากปุ่มลูกศรเพื่อจัดลำดับ</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add_circle</span> เพิ่มหมวดหมู่ใหม่
      </button>
    </div>

    <!-- List -->
    <div class="table-card">
      <div class="loading-state" v-if="categoriesStore.loading"><div class="spinner"></div></div>
      <div v-else>
        <div v-if="!categoriesStore.categories?.length" class="empty-state">
          <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">category</span>
          ยังไม่มีหมวดหมู่ กด "เพิ่มหมวดหมู่ใหม่" เพื่อเริ่มต้น
        </div>

        <div v-else class="cat-list">
          <div
            v-for="(c, index) in categoriesStore.categories"
            :key="c.id"
            class="cat-row"
            :class="{ 'cat-inactive': !c.is_active }"
          >
            <!-- Order indicator -->
            <div class="cat-order">
              <span class="material-symbols-rounded drag-handle">drag_indicator</span>
              <span class="order-num">{{ index + 1 }}</span>
            </div>

            <!-- Cover thumbnail -->
            <div class="cat-preview">
              <img v-if="c.image_url" :src="c.image_url" :alt="c.name" class="cat-thumb" @error="(e) => e.target.style.opacity = '0.3'" />
              <div v-else class="cat-thumb cat-thumb--empty">
                <span class="material-symbols-rounded">{{ c.icon || 'category' }}</span>
              </div>
            </div>

            <!-- Info -->
            <div class="cat-info">
              <div class="cat-name-row">
                <span class="material-symbols-rounded cat-icon" :style="{ color: c.color || 'var(--color-accent)' }">{{ c.icon || 'category' }}</span>
                <p class="cat-name">{{ c.display_title || c.name }}</p>
                <span v-if="c.is_popular" class="popular-pill"><span class="material-symbols-rounded" style="font-size:13px">star</span> ยอดนิยม</span>
              </div>
              <p class="cat-sub">{{ c.subtitle || c.name }}</p>
              <p class="cat-slug"><code>{{ c.slug }}</code><span v-if="!c.image_url" class="no-image-hint">· ไม่มีภาพ จะไม่แสดงบนหน้าแรก</span></p>
            </div>

            <!-- Status -->
            <span :class="['status-pill', c.is_active ? 'pill-active' : 'pill-inactive']">
              {{ c.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน' }}
            </span>

            <!-- Actions -->
            <div class="action-btns">
              <button class="btn-icon" :disabled="index === 0" @click="moveCategory(index, -1)" title="เลื่อนขึ้น">
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_upward</span>
              </button>
              <button class="btn-icon" :disabled="index === categoriesStore.categories.length - 1" @click="moveCategory(index, 1)" title="เลื่อนลง">
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_downward</span>
              </button>
              <button class="btn-icon btn-edit" @click="openForm(c)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
              <button class="btn-icon btn-delete" @click="confirmDelete(c)" title="ลบ"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm" @click.self="showForm = false">
      <div class="modal-card" style="max-width:680px">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่กิจกรรม' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">

            <!-- Cover image upload -->
            <div class="form-group full-width">
              <label>ภาพหน้าปก (แสดงบนการ์ดหน้าแรก)</label>
              <div class="upload-zone" @click="fileInput.click()" @dragover.prevent @drop.prevent="handleDrop">
                <input
                  ref="fileInput"
                  type="file"
                  accept="image/jpeg,image/jpg,image/png,image/webp"
                  style="display:none"
                  @change="handleFileSelect"
                />
                <div v-if="form.image_url" class="upload-preview-wrap">
                  <img :src="form.image_url" class="upload-preview-img" />
                  <button type="button" class="upload-clear" @click.stop="clearUpload" title="ลบภาพ">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <div v-else class="upload-placeholder">
                  <span class="material-symbols-rounded">cloud_upload</span>
                  <p style="font-size:14px;font-weight:600;color:#6b7280">คลิกหรือลากไฟล์มาวางที่นี่</p>
                  <p style="font-size:12px;color:#9ca3af">ระบบจะให้ครอปเป็นสัดส่วน 3:4 ให้พอดีการ์ด</p>
                </div>
              </div>
              <div class="upload-progress" v-if="uploading">
                <div class="progress-bar" :style="{ width: uploadProgress + '%' }"></div>
              </div>
              <p v-if="uploading" style="font-size:12px;color:#6b7280;margin-top:4px">กำลังอัปโหลด {{ uploadProgress }}%...</p>
            </div>

            <div class="form-group full-width">
              <label>ชื่อหมวดหมู่ * <span style="font-weight:400;color:#9ca3af">(ใช้ในตัวกรองทริป)</span></label>
              <input v-model="form.name" required placeholder="เช่น ดำน้ำตื้น (Snorkeling)" @input="handleNameInput" />
            </div>

            <div class="form-group">
              <label>Slug (URL) *</label>
              <input v-model="form.slug" required placeholder="เช่น snorkeling" />
            </div>

            <div class="form-group">
              <label>ไอคอน (Material Symbol) *</label>
              <div class="icon-input-group">
                <input v-model="form.icon" required placeholder="เช่น scuba_diving, hiking" />
                <span class="material-symbols-rounded preview-icon">{{ form.icon || 'question_mark' }}</span>
              </div>
            </div>

            <div class="form-group">
              <label>ชื่อบนการ์ดหน้าแรก <span style="font-weight:400;color:#9ca3af">(ไม่บังคับ)</span></label>
              <input v-model="form.display_title" placeholder="เช่น Snorkeling" />
            </div>

            <div class="form-group">
              <label>ข้อความปุ่ม (CTA)</label>
              <input v-model="form.cta_text" placeholder="เช่น ดูทริปดำน้ำ" />
            </div>

            <div class="form-group full-width">
              <label>คำโปรยใต้ชื่อ</label>
              <textarea v-model="form.subtitle" rows="2" placeholder="เช่น สำรวจโลกใต้ทะเลที่สวยที่สุดในอันดามัน"></textarea>
            </div>

            <div class="form-group">
              <label>สีหลัก (Accent)</label>
              <div class="color-input-group">
                <input type="color" v-model="form.color" />
                <input v-model="form.color" placeholder="#2D7A4F" />
              </div>
            </div>

            <div class="form-group">
              <label>สีพื้นหลัง</label>
              <div class="color-input-group">
                <input type="color" v-model="form.bg_color" />
                <input v-model="form.bg_color" placeholder="#E8F5EC" />
              </div>
            </div>

            <div class="form-group">
              <label>ป้าย "ยอดนิยม"</label>
              <div class="toggle-group mt-2">
                <label class="switch">
                  <input type="checkbox" v-model="form.is_popular">
                  <span class="slider round"></span>
                </label>
                <span class="ml-2">{{ form.is_popular ? 'แสดงป้าย' : 'ไม่แสดง' }}</span>
              </div>
            </div>

            <div class="form-group">
              <label>สถานะ</label>
              <div class="toggle-group mt-2">
                <label class="switch">
                  <input type="checkbox" v-model="form.is_active">
                  <span class="slider round"></span>
                </label>
                <span class="ml-2">{{ form.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span>
              </div>
            </div>

          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting || uploading">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ editing ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างหมวดหมู่' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Crop Modal -->
    <div class="modal-overlay" v-if="showCropper" @click.self="closeCropper">
      <div class="modal-card" style="max-width:620px">
        <div class="modal-header">
          <h2>ปรับขนาดภาพ (3:4)</h2>
          <button class="modal-close" @click="closeCropper"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;color:#6b7280;margin-bottom:12px;display:flex;align-items:center;gap:6px">
            <span class="material-symbols-rounded" style="font-size:18px;color:var(--color-accent)">crop</span>
            ลากเพื่อย้ายตำแหน่ง และเลื่อนสกอลล์/บีบนิ้วเพื่อซูม เลือกเฉพาะส่วนที่ต้องการให้แสดง
          </p>
          <div class="cropper-wrap">
            <img ref="cropImg" :src="cropImageSrc" alt="ครอปภาพ" />
          </div>
          <div class="cropper-tools">
            <button type="button" class="btn-icon" @click="cropperInstance?.zoom(0.1)" title="ซูมเข้า"><span class="material-symbols-rounded" style="font-size:18px">zoom_in</span></button>
            <button type="button" class="btn-icon" @click="cropperInstance?.zoom(-0.1)" title="ซูมออก"><span class="material-symbols-rounded" style="font-size:18px">zoom_out</span></button>
            <button type="button" class="btn-icon" @click="cropperInstance?.rotate(-90)" title="หมุนซ้าย"><span class="material-symbols-rounded" style="font-size:18px">rotate_left</span></button>
            <button type="button" class="btn-icon" @click="cropperInstance?.rotate(90)" title="หมุนขวา"><span class="material-symbols-rounded" style="font-size:18px">rotate_right</span></button>
            <button type="button" class="btn-icon" @click="cropperInstance?.reset()" title="รีเซ็ต"><span class="material-symbols-rounded" style="font-size:18px">restart_alt</span></button>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" @click="closeCropper">ยกเลิก</button>
          <button type="button" class="btn-primary" @click="confirmCrop" :disabled="cropping">
            <span class="material-symbols-rounded animate-spin" style="font-size:16px" v-if="cropping">sync</span>
            ใช้ภาพนี้
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div class="modal-overlay" v-if="showDeleteConfirm" @click.self="showDeleteConfirm = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบหมวดหมู่ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning text-red-500 text-sm mt-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-sm">warning</span>
            หากมีทริปที่ใช้หมวดหมู่นี้ ตัวกรองอาจแสดงผลไม่ถูกต้อง
          </p>
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
import { ref, reactive, onMounted, onBeforeUnmount, nextTick } from 'vue';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';
import { useCategoriesStore } from '../../stores/categories';
import api from '../../../js/lib/axios';

const categoriesStore = useCategoriesStore();
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);

// ─── Upload state ───
const fileInput = ref(null);
const uploading = ref(false);
const uploadProgress = ref(0);

// ─── Crop state ───
const CAT_ASPECT = 3 / 4;
const showCropper = ref(false);
const cropImageSrc = ref('');
const cropImg = ref(null);
const cropping = ref(false);
let cropperInstance = null;
let cropObjectUrl = '';

const form = reactive({
  name: '',
  display_title: '',
  subtitle: '',
  cta_text: '',
  slug: '',
  icon: 'category',
  image_url: '',
  color: '#2D7A4F',
  bg_color: '#E8F5EC',
  is_popular: false,
  is_active: true,
});

const handleNameInput = () => {
  if (!editing.value) {
    form.slug = form.name.toLowerCase()
      .trim()
      .replace(/[^\w\s-]/g, '')
      .replace(/[\s_-]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }
};

const openForm = (c = null) => {
  editing.value = c;
  if (c) {
    Object.assign(form, {
      name: c.name,
      display_title: c.display_title || '',
      subtitle: c.subtitle || '',
      cta_text: c.cta_text || '',
      slug: c.slug,
      icon: c.icon || 'category',
      image_url: c.image_url || '',
      color: c.color || '#2D7A4F',
      bg_color: c.bg_color || '#E8F5EC',
      is_popular: !!c.is_popular,
      is_active: !!c.is_active,
    });
  } else {
    Object.assign(form, {
      name: '', display_title: '', subtitle: '', cta_text: '',
      slug: '', icon: 'category', image_url: '',
      color: '#2D7A4F', bg_color: '#E8F5EC', is_popular: false, is_active: true,
    });
  }
  showForm.value = true;
};

// ─── Image upload + crop ───
const handleDrop = (e) => {
  const file = e.dataTransfer.files[0];
  if (file) openCropper(file);
};

const handleFileSelect = (e) => {
  const file = e.target.files[0];
  if (file) openCropper(file);
};

const openCropper = async (file) => {
  if (!file.type.startsWith('image/')) {
    alert('รองรับเฉพาะไฟล์รูปภาพเท่านั้น');
    return;
  }
  if (cropObjectUrl) URL.revokeObjectURL(cropObjectUrl);
  cropObjectUrl = URL.createObjectURL(file);
  cropImageSrc.value = cropObjectUrl;
  showCropper.value = true;
  await nextTick();
  cropperInstance?.destroy();
  cropperInstance = new Cropper(cropImg.value, {
    aspectRatio: CAT_ASPECT,
    viewMode: 1,
    autoCropArea: 1,
    background: false,
    responsive: true,
    movable: true,
    zoomable: true,
  });
};

const closeCropper = () => {
  cropperInstance?.destroy();
  cropperInstance = null;
  showCropper.value = false;
  cropImageSrc.value = '';
  if (cropObjectUrl) {
    URL.revokeObjectURL(cropObjectUrl);
    cropObjectUrl = '';
  }
  if (fileInput.value) fileInput.value.value = '';
};

const confirmCrop = () => {
  if (!cropperInstance) return;
  cropping.value = true;
  const canvas = cropperInstance.getCroppedCanvas({
    maxWidth: 1200,
    maxHeight: 1600,
    imageSmoothingQuality: 'high',
  });
  canvas.toBlob(
    (blob) => {
      cropping.value = false;
      if (!blob) {
        alert('ครอปภาพไม่สำเร็จ กรุณาลองใหม่');
        return;
      }
      const file = new File([blob], `category-${Date.now()}.jpg`, { type: 'image/jpeg' });
      closeCropper();
      uploadFile(file);
    },
    'image/jpeg',
    0.92,
  );
};

const clearUpload = () => {
  form.image_url = '';
  uploadProgress.value = 0;
  if (fileInput.value) fileInput.value.value = '';
};

const uploadFile = async (file) => {
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
  } catch (e) {
    const msg = e.response?.data?.errors?.file?.[0]
      ?? e.response?.data?.message
      ?? 'อัปโหลดภาพไม่สำเร็จ';
    alert(msg);
  } finally {
    uploading.value = false;
  }
};

const submitForm = async () => {
  submitting.value = true;
  try {
    if (editing.value) {
      await categoriesStore.updateCategory(editing.value.id, { ...form });
    } else {
      await categoriesStore.createCategory({ ...form });
    }
    showForm.value = false;
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึก');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (c) => {
  deleting.value = c;
  showDeleteConfirm.value = true;
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await categoriesStore.deleteCategory(deleting.value.id);
    showDeleteConfirm.value = false;
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถลบหมวดหมู่ได้');
  } finally {
    submitting.value = false;
  }
};

const moveCategory = async (index, direction) => {
  const list = [...categoriesStore.categories];
  const target = index + direction;
  if (target < 0 || target >= list.length) return;
  [list[index], list[target]] = [list[target], list[index]];
  categoriesStore.categories = list;
  try {
    await categoriesStore.reorderCategories(list.map((c) => c.id));
  } catch (e) {
    alert(e.response?.data?.message || 'จัดเรียงไม่สำเร็จ');
    await categoriesStore.fetchAdminCategories();
  }
};

onBeforeUnmount(() => {
  cropperInstance?.destroy();
  if (cropObjectUrl) URL.revokeObjectURL(cropObjectUrl);
});

onMounted(() => categoriesStore.fetchAdminCategories());
</script>

<style scoped>
@import url('./admin-shared.css');

.heading-icon { color: var(--color-accent); font-size: 28px; }

/* ─── Category list ─────────────────────── */
.cat-list { display: flex; flex-direction: column; }

.cat-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 20px;
  border-bottom: 1px solid #f0f0f0;
  transition: background 0.15s;
}
.cat-row:last-child { border-bottom: none; }
.cat-row:hover { background: #fafafa; }
.cat-inactive { opacity: 0.5; }

.cat-order {
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  width: 32px; flex-shrink: 0; color: #aaa;
}
.drag-handle { font-size: 20px; color: #cbd5e1; }
.order-num { font-size: 11px; font-weight: 700; color: #9ca3af; }

.cat-preview { flex-shrink: 0; }
.cat-thumb {
  width: 60px; height: 80px;
  object-fit: cover; border-radius: 10px;
  border: 1px solid #e5e7eb; background: #f3f4f6; display: block;
}
.cat-thumb--empty {
  display: flex; align-items: center; justify-content: center;
  color: #cbd5e1;
}
.cat-thumb--empty .material-symbols-rounded { font-size: 28px; }

.cat-info { flex: 1; min-width: 0; }
.cat-name-row { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }
.cat-icon { font-size: 20px; }
.cat-name { font-weight: 700; font-size: 15px; color: #111827; }
.popular-pill {
  display: inline-flex; align-items: center; gap: 2px;
  background: #fef3c7; color: #b45309;
  font-size: 11px; font-weight: 700;
  padding: 2px 8px; border-radius: 20px;
}
.cat-sub {
  font-size: 13px; color: #6b7280; margin-bottom: 2px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 420px;
}
.cat-slug { font-size: 12px; color: #9ca3af; }
.cat-slug code { background: #f3f4f6; padding: 1px 6px; border-radius: 5px; }
.no-image-hint { margin-left: 8px; color: #d97706; }

.status-pill { flex-shrink: 0; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.pill-active { background: #dbf4e5; color: #10b981; }
.pill-inactive { background: #fef2f2; color: #ef4444; }

/* ─── Form helpers ─────────────────────── */
.icon-input-group { display: flex; align-items: center; gap: 12px; }
.preview-icon {
  width: 42px; height: 42px;
  display: flex; align-items: center; justify-content: center;
  background: white; border: 1px solid #e5e7eb; border-radius: 8px; color: var(--color-accent);
}
.color-input-group { display: flex; align-items: center; gap: 10px; }
.color-input-group input[type="color"] {
  width: 44px; height: 40px; padding: 2px; border: 1px solid #e5e7eb;
  border-radius: 8px; background: white; cursor: pointer; flex-shrink: 0;
}
.color-input-group input:not([type="color"]) { flex: 1; }

/* ─── Upload zone ──────────────────────── */
.upload-zone {
  border: 2px dashed #d1d5db; border-radius: 12px; padding: 20px;
  cursor: pointer; text-align: center; transition: border-color 0.2s, background 0.2s;
  position: relative; min-height: 140px;
  display: flex; align-items: center; justify-content: center;
}
.upload-zone:hover { border-color: var(--color-accent); background: #f0faf4; }
.upload-placeholder { display: flex; flex-direction: column; align-items: center; gap: 8px; color: #9ca3af; pointer-events: none; }
.upload-placeholder .material-symbols-rounded { font-size: 40px; color: #d1d5db; }
.upload-preview-wrap { position: relative; }
.upload-preview-img {
  max-height: 220px; max-width: 100%; object-fit: contain;
  border-radius: 8px; display: block;
}
.upload-clear {
  position: absolute; top: 8px; right: 8px;
  background: rgba(0,0,0,0.55); color: white; border: none; border-radius: 50%;
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer;
}
.upload-clear:hover { background: rgba(0,0,0,0.75); }
.upload-clear .material-symbols-rounded { font-size: 16px; }
.upload-progress { height: 4px; background: #e5e7eb; border-radius: 4px; margin-top: 8px; overflow: hidden; }
.progress-bar { height: 100%; background: var(--color-accent); transition: width 0.25s; }

/* ─── Cropper ──────────────────────────── */
.cropper-wrap { width: 100%; max-height: 60vh; background: #1f2937; border-radius: 10px; overflow: hidden; }
.cropper-wrap img { display: block; max-width: 100%; }
.cropper-tools { display: flex; justify-content: center; gap: 8px; margin-top: 12px; }

/* ─── Toggle switch ────────────────────── */
.switch { position: relative; display: inline-block; width: 40px; height: 22px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
  position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
  background-color: #ccc; transition: .4s;
}
.slider:before {
  position: absolute; content: ""; height: 16px; width: 16px;
  left: 3px; bottom: 3px; background-color: white; transition: .4s;
}
input:checked + .slider { background-color: var(--color-accent); }
input:checked + .slider:before { transform: translateX(18px); }
.slider.round { border-radius: 34px; }
.slider.round:before { border-radius: 50%; }
</style>
