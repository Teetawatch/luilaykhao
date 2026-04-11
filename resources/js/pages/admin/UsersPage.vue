<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-users"></i> ผู้ใช้งาน</h1>
        <p class="page-subtitle">จัดการบัญชีผู้ใช้งานทั้งหมด</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <i class="fas fa-user-plus"></i> เพิ่มผู้ใช้ใหม่
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input v-model="filters.search" placeholder="ค้นหาชื่อ, อีเมล, เบอร์โทร..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.role" @change="fetchData()">
        <option value="">ทุกบทบาท</option>
        <option value="admin">ผู้ดูแล</option>
        <option value="operator">เจ้าหน้าที่</option>
        <option value="customer">ลูกค้า</option>
      </select>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>ผู้ใช้</th>
              <th>อีเมล</th>
              <th>เบอร์โทร</th>
              <th>บทบาท</th>
              <th>การจอง</th>
              <th>วันที่สร้าง</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in admin.users.data" :key="u.id">
              <td>
                <div class="user-cell">
                  <div class="user-avatar-sm">{{ u.name?.charAt(0)?.toUpperCase() }}</div>
                  <span class="user-name-cell">{{ u.name }}</span>
                </div>
              </td>
              <td>{{ u.email }}</td>
              <td>{{ u.phone || '-' }}</td>
              <td>
                <span class="role-badge" :class="`role-${u.roles?.[0] || 'customer'}`">
                  {{ roleLabels[u.roles?.[0]] || 'ลูกค้า' }}
                </span>
              </td>
              <td>{{ u.bookings_count || 0 }}</td>
              <td class="date">{{ formatDate(u.created_at) }}</td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-edit" @click="openForm(u)" title="แก้ไข"><i class="fas fa-edit"></i></button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(u)" title="ลบ"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>
            <tr v-if="!admin.users.data?.length">
              <td colspan="7" class="empty-state">ไม่พบผู้ใช้</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination" v-if="admin.users.meta?.last_page > 1">
        <button :disabled="admin.users.meta.current_page <= 1" @click="goPage(admin.users.meta.current_page - 1)"><i class="fas fa-chevron-left"></i></button>
        <span class="page-info">{{ admin.users.meta.current_page }} / {{ admin.users.meta.last_page }}</span>
        <button :disabled="admin.users.meta.current_page >= admin.users.meta.last_page" @click="goPage(admin.users.meta.current_page + 1)"><i class="fas fa-chevron-right"></i></button>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อ *</label>
              <input v-model="form.name" required placeholder="ชื่อ-นามสกุล" />
            </div>
            <div class="form-group">
              <label>อีเมล *</label>
              <input v-model="form.email" type="email" required placeholder="email@example.com" />
            </div>
            <div class="form-group">
              <label>เบอร์โทร</label>
              <input v-model="form.phone" placeholder="08XXXXXXXX" />
            </div>
            <div class="form-group">
              <label>{{ editing ? 'รหัสผ่านใหม่ (ว่าง = ไม่เปลี่ยน)' : 'รหัสผ่าน *' }}</label>
              <input v-model="form.password" type="password" :required="!editing" placeholder="••••••" />
            </div>
            <div class="form-group">
              <label>บทบาท *</label>
              <select v-model="form.role" required>
                <option value="customer">ลูกค้า</option>
                <option value="operator">เจ้าหน้าที่</option>
                <option value="admin">ผู้ดูแล</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <i class="fas fa-spinner fa-spin" v-if="submitting"></i>
              {{ editing ? 'บันทึก' : 'สร้างผู้ใช้' }}
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
          <button class="modal-close" @click="showDeleteConfirm = false"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบผู้ใช้ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><i class="fas fa-exclamation-triangle"></i> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบผู้ใช้</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();
const filters = reactive({ search: '', role: '' });
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
const form = reactive({ name: '', email: '', phone: '', password: '', role: 'customer' });

const roleLabels = { admin: 'ผู้ดูแล', operator: 'เจ้าหน้าที่', customer: 'ลูกค้า' };

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
};

let debounceTimer = null;
const debouncedFetch = () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => fetchData(), 300); };
const fetchData = (page = 1) => admin.fetchUsers({ ...filters, page });
const goPage = (page) => fetchData(page);

const openForm = (u = null) => {
  editing.value = u;
  if (u) {
    Object.assign(form, { name: u.name, email: u.email, phone: u.phone || '', password: '', role: u.roles?.[0] || 'customer' });
  } else {
    Object.assign(form, { name: '', email: '', phone: '', password: '', role: 'customer' });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  try {
    const data = { ...form };
    if (editing.value && !data.password) delete data.password;
    if (editing.value) {
      await admin.updateUser(editing.value.id, data);
    } else {
      await admin.createUser(data);
    }
    showForm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (u) => { deleting.value = u; showDeleteConfirm.value = true; };

const doDelete = async () => {
  submitting.value = true;
  try {
    await admin.deleteUser(deleting.value.id);
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

.user-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.user-avatar-sm {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  background: #2d7a4f;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}

.user-name-cell {
  font-weight: 600;
  color: #111827;
}
</style>
