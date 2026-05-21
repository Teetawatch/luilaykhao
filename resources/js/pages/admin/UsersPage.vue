<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">group</span> ผู้ใช้งาน</h1>
        <p class="page-subtitle">จัดการบัญชีผู้ใช้งานทั้งหมด</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">person_add</span> เพิ่มผู้ใช้ใหม่
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="filters.search" placeholder="ค้นหาชื่อ, อีเมล, เบอร์โทร..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.role" @change="fetchData()">
        <option value="">ทุกบทบาท</option>
        <option value="admin">ผู้ดูแล</option>
        <option value="operator">เจ้าหน้าที่</option>
        <option value="staff">สตาฟ</option>
        <option value="customer">ลูกค้า</option>
      </select>
    </div>

    <!-- Fetch Error Banner -->
    <div v-if="admin.error" class="fetch-error-bar">
      <span class="material-symbols-rounded">error</span>
      <span>{{ admin.error }}</span>
      <button @click="admin.error = ''" class="fetch-error-close">✕</button>
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
              <th>สมัครผ่าน</th>
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
                  <div class="user-avatar-sm">
                    <img :src="u.avatar_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(u.name || '')}&background=2D7A4F&color=fff`" :alt="u.name" class="user-avatar-img" @error="(e) => { e.target.style.display = 'none'; e.target.nextElementSibling.style.display = 'flex'; }" />
                    <span class="avatar-fallback" style="display: none;">{{ u.name?.charAt(0)?.toUpperCase() }}</span>
                  </div>
                  <span class="user-name-cell">{{ u.name }}</span>
                </div>
              </td>
              <td>{{ u.email }}</td>
              <td>
                  <span class="signup-provider" :class="`provider-${normalizeSignupProvider(u.social_provider)}`">
                    <svg v-if="normalizeSignupProvider(u.social_provider) === 'google'" class="provider-icon" viewBox="0 0 48 48" width="14" height="14"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59a14.5 14.5 0 0 1 0-9.18l-7.98-6.19a24.01 24.01 0 0 0 0 21.56l7.98-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    <i v-else-if="normalizeSignupProvider(u.social_provider) === 'facebook'" class="fa-brands fa-facebook mr-1"></i>
                    <i v-else-if="normalizeSignupProvider(u.social_provider) === 'line'" class="fa-brands fa-line mr-1"></i>
                    <i v-else class="fa-regular fa-envelope mr-1"></i>
                    {{ signupProviderLabel(u.social_provider) }}
                  </span>
              </td>
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
                  <button class="btn-icon btn-edit" @click="openForm(u)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(u)" title="ลบ"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                </div>
              </td>
            </tr>
            <tr v-if="!admin.users.data?.length">
              <td colspan="8" class="empty-state">ไม่พบผู้ใช้</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination" v-if="admin.users.meta?.last_page > 1">
        <button :disabled="admin.users.meta.current_page <= 1" @click="goPage(admin.users.meta.current_page - 1)"><span class="material-symbols-rounded">chevron_left</span></button>
        <span class="page-info">{{ admin.users.meta.current_page }} / {{ admin.users.meta.last_page }}</span>
        <button :disabled="admin.users.meta.current_page >= admin.users.meta.last_page" @click="goPage(admin.users.meta.current_page + 1)"><span class="material-symbols-rounded">chevron_right</span></button>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div v-if="formError" class="form-error-bar">
          <span class="material-symbols-rounded">error</span>
          {{ formError }}
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
              <label>{{ editing ? 'รหัสคนขับใหม่ (ว่าง = ไม่เปลี่ยน)' : 'รหัสคนขับ' }}</label>
              <input v-model="form.driver_pin" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="เช่น 2486" />
              <small class="form-hint">ใช้ 4-8 ตัวเลข สำหรับเข้าแอปคนขับแบบง่าย</small>
            </div>
            <div class="form-group">
              <label>บทบาท *</label>
              <select v-model="form.role" required>
                <option value="customer">ลูกค้า</option>
                <option value="operator">เจ้าหน้าที่</option>
                <option value="staff">สตาฟ</option>
                <option value="admin">ผู้ดูแล</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
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
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบผู้ใช้ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
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
const formError = ref('');
const form = reactive({ name: '', email: '', phone: '', password: '', driver_pin: '', role: 'customer' });

const roleLabels = { admin: 'ผู้ดูแล', operator: 'เจ้าหน้าที่', staff: 'สตาฟ', customer: 'ลูกค้า' };
const signupProviderLabels = { email: 'อีเมล', google: 'Gmail', facebook: 'Facebook', line: 'LINE' };

const normalizeSignupProvider = (provider) => {
  const key = (provider || '').toString().trim().toLowerCase();
  return ['google', 'facebook', 'line'].includes(key) ? key : 'email';
};

const signupProviderLabel = (provider) => signupProviderLabels[normalizeSignupProvider(provider)] || 'อีเมล';

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
  formError.value = '';
  if (u) {
    Object.assign(form, { name: u.name, email: u.email, phone: u.phone || '', password: '', driver_pin: '', role: u.roles?.[0] || 'customer' });
  } else {
    Object.assign(form, { name: '', email: '', phone: '', password: '', driver_pin: '', role: 'customer' });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  formError.value = '';
  try {
    const data = { ...form };
    if (editing.value && !data.password) delete data.password;
    if (!data.driver_pin) delete data.driver_pin;
    if (editing.value) {
      await admin.updateUser(editing.value.id, data);
    } else {
      await admin.createUser(data);
    }
    showForm.value = false;
    fetchData();
  } catch (e) {
    const errData = e.response?.data;
    if (errData?.errors) {
      const firstKey = Object.keys(errData.errors)[0];
      formError.value = errData.errors[firstKey][0];
    } else {
      formError.value = errData?.message || 'เกิดข้อผิดพลาด กรุณาลองอีกครั้ง';
    }
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
  background: var(--color-accent);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-white);
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}

.user-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: inherit;
  display: block;
}

.avatar-fallback {
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
}

.provider-icon {
  flex-shrink: 0;
  margin-right: 4px;
}

.user-name-cell {
  font-weight: 600;
  color: var(--color-text-dark);
}

.signup-provider {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
}

.provider-email {
  background: #eef2f7;
  color: #3f4a5a;
}

.provider-google {
  background: #fff1f0;
  color: #c2410c;
}

.provider-facebook {
  background: #eff6ff;
  color: #1d4ed8;
}

.provider-line {
  background: #ecfdf3;
  color: #047857;
}

.form-hint {
  display: block;
  margin-top: 6px;
  color: var(--color-text-muted);
  font-size: 12px;
}

.fetch-error-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  margin-bottom: 16px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 12px;
  color: #b91c1c;
  font-size: 13px;
  font-weight: 600;
}

.fetch-error-close {
  margin-left: auto;
  background: none;
  border: none;
  cursor: pointer;
  color: #b91c1c;
  font-size: 14px;
  padding: 0 4px;
}

.form-error-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  background: #fef2f2;
  border-bottom: 1px solid #fecaca;
  color: #b91c1c;
  font-size: 13px;
  font-weight: 600;
}
</style>
