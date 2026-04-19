<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">category</span> หมวดหมู่กิจกรรม</h1>
        <p class="page-subtitle">จัดการประเภทของทริปและไอคอนที่แสดงหน้าเว็บ</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add_circle</span> เพิ่มหมวดหมู่ใหม่
      </button>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="categoriesStore.loading"><div class="spinner"></div></div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>ไอคอน</th>
              <th>ชื่อหมวดหมู่</th>
              <th>Slug</th>
              <th>ลำดับ</th>
              <th>สถานะ</th>
              <th>วันที่สร้าง</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in categoriesStore.categories" :key="c.id">
              <td>
                <div class="icon-avatar">
                  <span class="material-symbols-rounded">{{ c.icon || 'category' }}</span>
                </div>
              </td>
              <td class="font-bold">{{ c.name }}</td>
              <td><code>{{ c.slug }}</code></td>
              <td>{{ c.order }}</td>
              <td>
                <span :class="['status-pill', c.is_active ? 'pill-active' : 'pill-inactive']">
                  {{ c.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน' }}
                </span>
              </td>
              <td class="date">{{ formatDate(c.created_at) }}</td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-edit" @click="openForm(c)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(c)" title="ลบ"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                </div>
              </td>
            </tr>
            <tr v-if="!categoriesStore.categories?.length">
              <td colspan="7" class="empty-state">ไม่พบหมวดหมู่</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่กิจกรรม' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อหมวดหมู่ *</label>
              <input v-model="form.name" required placeholder="เช่น ดำน้ำตื้น (Snorkeling)" @input="handleNameInput" />
            </div>
            <div class="form-group">
              <label>Slug (URL) *</label>
              <input v-model="form.slug" required placeholder="เช่น snorkeling" />
            </div>
            <div class="form-group">
              <label>ไอคอน (Material Symbol) *</label>
              <div class="icon-input-group">
                <input v-model="form.icon" required placeholder="เช่น kayaking, surfing" />
                <span class="material-symbols-rounded preview-icon">{{ form.icon || 'question_mark' }}</span>
              </div>
            </div>
            <div class="form-group">
              <label>ลำดับการแสดงผล</label>
              <input v-model.number="form.order" type="number" placeholder="เช่น 0, 1, 2" />
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
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ editing ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างหมวดหมู่' }}
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
import { ref, reactive, onMounted } from 'vue';
import { useCategoriesStore } from '../../stores/categories';

const categoriesStore = useCategoriesStore();
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);

const form = reactive({
  name: '',
  slug: '',
  icon: 'category',
  order: 0,
  is_active: true
});

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { 
    day: 'numeric', 
    month: 'short', 
    year: 'numeric' 
  });
};

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
      slug: c.slug, 
      icon: c.icon || 'category', 
      order: c.order, 
      is_active: !!c.is_active 
    });
  } else {
    Object.assign(form, { 
      name: '', 
      slug: '', 
      icon: 'category', 
      order: 0, 
      is_active: true 
    });
  }
  showForm.value = true;
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

onMounted(() => categoriesStore.fetchAdminCategories());
</script>

<style scoped>
@import url('./admin-shared.css');

.icon-avatar {
  width: 40px;
  height: 40px;
  background: var(--color-sand);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-accent);
}

.icon-input-group {
  display: flex;
  align-items: center;
  gap: 12px;
}

.preview-icon {
  width: 42px;
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  color: var(--color-accent);
}

.status-pill {
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 700;
}

.pill-active {
  background: #dbf4e5;
  color: #10b981;
}

.pill-inactive {
  background: #fef2f2;
  color: #ef4444;
}

.switch {
  position: relative;
  display: inline-block;
  width: 40px;
  height: 22px;
}

.switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: #ccc;
  transition: .4s;
}

.slider:before {
  position: absolute;
  content: "";
  height: 16px;
  width: 16px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .4s;
}

input:checked + .slider {
  background-color: var(--color-accent);
}

input:checked + .slider:before {
  transform: translateX(18px);
}

.slider.round {
  border-radius: 34px;
}

.slider.round:before {
  border-radius: 50%;
}
</style>
