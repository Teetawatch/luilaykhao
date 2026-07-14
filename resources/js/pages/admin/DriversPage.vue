<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">badge</span> ทะเบียนคนขับ</h1>
        <p class="page-subtitle">เก็บข้อมูลคนขับไว้ครั้งเดียว แล้วเลือกผูกกับรถได้ทุกคัน ไม่ต้องพิมพ์ซ้ำ</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add</span> เพิ่มคนขับ
      </button>
    </div>

    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="searchQuery" placeholder="ค้นหาชื่อ หรือเบอร์โทร..." @input="onSearch" />
      </div>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <template v-else>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>คนขับ</th>
                <th>เบอร์โทร</th>
                <th>เลขใบขับขี่</th>
                <th>รถที่ใช้</th>
                <th>สถานะ</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in drivers.data" :key="d.id">
                <td>
                  <div class="driver-cell">
                    <div class="driver-avatar" v-if="d.photo"><img :src="d.photo" :alt="d.name" /></div>
                    <div class="driver-avatar placeholder" v-else><span class="material-symbols-rounded">person</span></div>
                    <span class="driver-name">{{ d.name }}</span>
                  </div>
                </td>
                <td>{{ d.phone || '-' }}</td>
                <td>{{ d.license_number || '-' }}</td>
                <td>
                  <span class="veh-count" v-if="d.vehicles_count">
                    <span class="material-symbols-rounded">directions_car</span> {{ d.vehicles_count }} คัน
                  </span>
                  <span v-else class="veh-none">ยังไม่ผูกรถ</span>
                </td>
                <td>
                  <span class="status-badge" :class="d.is_active ? 'status-active' : 'status-inactive'">
                    {{ d.is_active ? 'ใช้งาน' : 'พักงาน' }}
                  </span>
                </td>
                <td>
                  <div class="action-btns">
                    <button class="btn-icon btn-edit" @click="openForm(d)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                    <button class="btn-icon btn-delete" @click="confirmDelete(d)" title="ลบ"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                  </div>
                </td>
              </tr>
              <tr v-if="!drivers.data.length">
                <td colspan="6" class="empty-state">ยังไม่มีคนขับในทะเบียน</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pagination" v-if="drivers.meta?.last_page > 1">
          <button @click="goPage(drivers.meta.current_page - 1)" :disabled="drivers.meta.current_page <= 1">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <span class="page-info">{{ drivers.meta.current_page }} / {{ drivers.meta.last_page }}</span>
          <button @click="goPage(drivers.meta.current_page + 1)" :disabled="drivers.meta.current_page >= drivers.meta.last_page">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
      </template>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขคนขับ' : 'เพิ่มคนขับ' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label>ชื่อ-นามสกุล *</label>
              <input v-model="form.name" required placeholder="เช่น สมชาย ใจดี" />
            </div>
            <div class="form-group">
              <label>เบอร์โทรศัพท์</label>
              <input v-model="form.phone" placeholder="08x-xxx-xxxx" />
            </div>
            <div class="form-group">
              <label>เลขใบขับขี่</label>
              <input v-model="form.license_number" placeholder="เช่น บ1234567" />
            </div>
            <div class="form-group">
              <label>สถานะ</label>
              <select v-model="form.is_active">
                <option :value="true">ใช้งาน</option>
                <option :value="false">พักงาน</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label>รูปคนขับ</label>
              <div class="photo-field">
                <div class="photo-preview" v-if="form.photo">
                  <img :src="form.photo" alt="" />
                  <button type="button" class="remove-btn" @click="form.photo = ''"><span class="material-symbols-rounded">close</span></button>
                </div>
                <button type="button" class="photo-pick" @click="showMediaLibrary = true">
                  <span class="material-symbols-rounded">{{ form.photo ? 'swap_horiz' : 'add_a_photo' }}</span>
                  {{ form.photo ? 'เปลี่ยนรูป' : 'เลือกรูป' }}
                </button>
              </div>
            </div>
            <div class="form-group full-width">
              <label>หมายเหตุ</label>
              <textarea v-model="form.notes" rows="2" placeholder="เช่น ชำนาญเส้นทางภาคเหนือ, ภาษาอังกฤษได้..."></textarea>
            </div>
          </div>
          <p class="linked-hint" v-if="editing && editing.vehicles_count">
            <span class="material-symbols-rounded">info</span>
            คนขับนี้ผูกกับรถ {{ editing.vehicles_count }} คัน — แก้ไขแล้วข้อมูลบนรถเหล่านั้นจะอัปเดตตาม
          </p>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ editing ? 'บันทึก' : 'เพิ่ม' }}
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
          <p class="confirm-text">ต้องการลบคนขับ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบ</button>
        </div>
      </div>
    </div>

    <MediaLibrary
      :show="showMediaLibrary"
      media-type="image"
      :initial-selection="form.photo"
      @close="showMediaLibrary = false"
      @select="(v) => form.photo = v || ''"
    />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import MediaLibrary from '../../components/MediaLibrary.vue';

const admin = useAdminStore();
const drivers = ref({ data: [], meta: null });
const loading = ref(false);
const searchQuery = ref('');
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const showMediaLibrary = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
let searchTimer = null;

const defaultForm = { name: '', phone: '', license_number: '', photo: '', notes: '', is_active: true };
const form = reactive({ ...defaultForm });

async function fetchData(page = 1) {
  loading.value = true;
  try {
    const res = await admin.fetchDrivers({ search: searchQuery.value || undefined, page });
    drivers.value = { data: res.data, meta: res.meta };
  } finally {
    loading.value = false;
  }
}

function onSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => fetchData(1), 300);
}

function goPage(p) { fetchData(p); }

function openForm(d = null) {
  editing.value = d;
  if (d) {
    Object.assign(form, {
      name: d.name, phone: d.phone || '', license_number: d.license_number || '',
      photo: d.photo || '', notes: d.notes || '', is_active: d.is_active,
    });
  } else {
    Object.assign(form, defaultForm);
  }
  showForm.value = true;
}

async function submitForm() {
  submitting.value = true;
  try {
    if (editing.value) {
      await admin.updateDriver(editing.value.id, { ...form });
    } else {
      await admin.createDriver({ ...form });
    }
    showForm.value = false;
    fetchData(drivers.value.meta?.current_page || 1);
  } catch (e) {
    alert(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

function confirmDelete(d) {
  deleting.value = d;
  showDeleteConfirm.value = true;
}

async function doDelete() {
  submitting.value = true;
  try {
    await admin.deleteDriver(deleting.value.id);
    showDeleteConfirm.value = false;
    fetchData(drivers.value.meta?.current_page || 1);
  } catch (e) {
    alert(e.response?.data?.message || 'ลบไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

onMounted(() => fetchData());
</script>

<style scoped>
@import url('./admin-shared.css');

.driver-cell { display: flex; align-items: center; gap: 10px; }
.driver-avatar { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #eef2f7; display: flex; align-items: center; justify-content: center; }
.driver-avatar img { width: 100%; height: 100%; object-fit: cover; }
.driver-avatar.placeholder .material-symbols-rounded { font-size: 20px; color: #94a3b8; }
.driver-name { font-weight: 700; }
.veh-count { display: inline-flex; align-items: center; gap: 4px; font-weight: 700; color: #0f766e; }
.veh-count .material-symbols-rounded { font-size: 16px; }
.veh-none { color: #94a3b8; font-size: 13px; }

.photo-field { display: flex; align-items: center; gap: 12px; }
.photo-preview { position: relative; width: 72px; height: 72px; border-radius: 12px; overflow: hidden; }
.photo-preview img { width: 100%; height: 100%; object-fit: cover; }
.photo-preview .remove-btn { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border: none; border-radius: 50%; background: rgba(0,0,0,0.6); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.photo-preview .remove-btn .material-symbols-rounded { font-size: 15px; }
.photo-pick { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border: 1px dashed #cbd5e1; border-radius: 12px; background: #f8fafc; color: #475569; font-weight: 700; cursor: pointer; }

.linked-hint { display: flex; align-items: center; gap: 6px; margin-top: 12px; padding: 10px 12px; background: #eff6ff; color: #1d4ed8; border-radius: 10px; font-size: 13px; }
.linked-hint .material-symbols-rounded { font-size: 18px; }
</style>
