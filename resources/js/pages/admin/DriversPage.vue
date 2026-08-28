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
        <input v-model="searchQuery" placeholder="ค้นหาชื่อ เบอร์โทร เลขใบขับขี่ หรือทะเบียนรถ..." @input="onSearch" />
      </div>
      <button class="filter-chip" :class="{ active: unlinkedOnly }" @click="toggleUnlinked">
        <span class="material-symbols-rounded">person_off</span>
        เฉพาะที่ยังไม่ผูกรถ
      </button>
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
                <th>รถที่ขับ</th>
                <th>การใช้งาน</th>
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
                <td>
                  {{ d.license_number || '-' }}
                  <div class="cell-sub" v-if="d.license_type">{{ d.license_type }}</div>
                  <span class="licence-badge" v-if="licenseBadge(d)" :class="licenseBadge(d).tone">
                    <span class="material-symbols-rounded">warning</span>
                    {{ licenseBadge(d).label }}
                  </span>
                </td>
                <td>
                  <div class="veh-list" v-if="d.vehicles?.length">
                    <div class="veh-chip" v-for="v in d.vehicles" :key="v.id">
                      <span class="color-dot" v-if="v.color"
                        :style="{ background: colorHex(v.color), border: v.color === 'ขาว' ? '1px solid #d1d5db' : 'none' }"
                        :title="v.color"
                      ></span>
                      <span class="veh-name">{{ v.name }}</span>
                      <span class="veh-plate" v-if="v.license_plate">{{ v.license_plate }}</span>
                      <span class="veh-meta">{{ vehicleTypeLabel(v.type) }} · {{ v.capacity }} ที่</span>
                      <span class="veh-pin" v-if="v.has_driver_pin" title="ตั้งรหัสส่ง GPS (PIN) ไว้แล้ว">
                        <span class="material-symbols-rounded">key</span>
                      </span>
                    </div>
                  </div>
                  <span v-else class="veh-none">ยังไม่ผูกรถ</span>
                </td>
                <td>
                  <div class="usage-cell">
                    <span class="usage-upcoming" v-if="d.upcoming_trips_count">
                      <span class="material-symbols-rounded">event_upcoming</span>
                      มีงาน {{ d.upcoming_trips_count }} รอบ
                    </span>
                    <span class="usage-last" v-if="d.last_trip_date">ล่าสุด {{ formatDate(d.last_trip_date) }}</span>
                    <span class="usage-never" v-if="!d.upcoming_trips_count && !d.last_trip_date">
                      <span class="material-symbols-rounded">block</span> ยังไม่เคยใช้งาน
                    </span>
                  </div>
                </td>
                <td>
                  <span class="status-badge" :class="d.is_active ? 'status-active' : 'status-inactive'">
                    {{ d.is_active ? 'ใช้งาน' : 'พักงาน' }}
                  </span>
                </td>
                <td>
                  <div class="action-btns">
                    <button class="btn-icon btn-edit" @click="openForm(d)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                    <button
                      class="btn-icon btn-delete"
                      :disabled="!!d.vehicles_count"
                      @click="confirmDelete(d)"
                      :title="d.vehicles_count ? 'ลบไม่ได้ — ต้องปลดคนขับออกจากรถก่อน' : 'ลบ'"
                    >
                      <span class="material-symbols-rounded" style="font-size:16px;">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!drivers.data.length">
                <td colspan="7" class="empty-state">
                  {{ unlinkedOnly ? 'คนขับทุกคนผูกกับรถอยู่แล้ว' : 'ยังไม่มีคนขับในทะเบียน' }}
                </td>
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
          <div class="form-section-title"><span class="material-symbols-rounded">person</span> ข้อมูลคนขับ</div>
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
              <label>LINE ID</label>
              <input v-model="form.line_id" placeholder="เช่น somchai_d" />
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
              <small class="field-hint">รูปนี้ลูกค้าเห็นได้ (แสดงคู่กับรถในหน้าติดตาม) — ใช้รูปสุภาพ</small>
            </div>
          </div>

          <div class="form-section-title"><span class="material-symbols-rounded">badge</span> ใบขับขี่</div>
          <div class="form-grid">
            <div class="form-group">
              <label>เลขใบขับขี่</label>
              <input v-model="form.license_number" placeholder="เช่น บ1234567" />
              <small class="field-hint">เลขบนใบขับขี่ของคนขับ — ไม่ใช่ทะเบียนรถ</small>
            </div>
            <div class="form-group">
              <label>ประเภทใบขับขี่</label>
              <input v-model="form.license_type" list="driver-license-types" placeholder="เช่น ท.2" />
              <datalist id="driver-license-types">
                <option value="ส่วนบุคคล (บ.1)"></option>
                <option value="ส่วนบุคคล (บ.2)"></option>
                <option value="สาธารณะ (ท.1)"></option>
                <option value="สาธารณะ (ท.2)"></option>
                <option value="สาธารณะ (ท.3)"></option>
                <option value="สาธารณะ (ท.4)"></option>
              </datalist>
            </div>
            <div class="form-group">
              <label>วันหมดอายุใบขับขี่</label>
              <input v-model="form.license_expires_at" type="date" />
              <small class="field-hint" :class="licenseHintTone">{{ licenseHint }}</small>
            </div>
            <div class="form-group full-width">
              <label>รูป/ไฟล์ใบขับขี่</label>
              <div class="licence-field" v-if="editing">
                <a v-if="editing.license_photo_url" :href="editing.license_photo_url" target="_blank" rel="noopener" class="licence-thumb">
                  <img :src="editing.license_photo_url" alt="ใบขับขี่" @error="licencePreviewFailed = true" v-if="!licencePreviewFailed" />
                  <span v-else class="licence-file"><span class="material-symbols-rounded">description</span> เปิดไฟล์ใบขับขี่</span>
                </a>
                <div class="licence-actions">
                  <label class="photo-pick">
                    <span class="material-symbols-rounded">{{ editing.has_license_photo ? 'swap_horiz' : 'upload_file' }}</span>
                    {{ editing.has_license_photo ? 'เปลี่ยนไฟล์' : 'แนบไฟล์' }}
                    <input type="file" accept="image/*,application/pdf" hidden @change="uploadLicensePhoto" />
                  </label>
                  <button type="button" class="btn-danger compact" v-if="editing.has_license_photo" @click="removeLicensePhoto">
                    ลบไฟล์
                  </button>
                </div>
              </div>
              <small class="field-hint" v-else>บันทึกคนขับก่อน แล้วเปิดแก้ไขอีกครั้งเพื่อแนบไฟล์ใบขับขี่</small>
              <small class="field-hint">
                เก็บในที่ส่วนตัว ไม่ได้อยู่ในคลังมีเดียสาธารณะ — เปิดดูได้เฉพาะแอดมินผ่านลิงก์ชั่วคราว
              </small>
            </div>
          </div>

          <div class="form-section-title"><span class="material-symbols-rounded">contact_emergency</span> ตัวตนและผู้ติดต่อฉุกเฉิน</div>
          <div class="form-grid">
            <div class="form-group">
              <label>เลขบัตรประชาชน</label>
              <input v-model="form.id_card" inputmode="numeric" maxlength="20" placeholder="13 หลัก" />
              <small class="field-hint">เก็บแบบเข้ารหัสในฐานข้อมูล</small>
            </div>
            <div class="form-group">
              <label>วัน/เดือน/ปีเกิด</label>
              <input v-model="form.birth_date" type="date" />
            </div>
            <div class="form-group">
              <label>ชื่อผู้ติดต่อฉุกเฉิน</label>
              <input v-model="form.emergency_contact" placeholder="เช่น สมหญิง ใจดี (ภรรยา)" />
            </div>
            <div class="form-group">
              <label>เบอร์ผู้ติดต่อฉุกเฉิน</label>
              <input v-model="form.emergency_phone" placeholder="08x-xxx-xxxx" />
            </div>
            <div class="form-group full-width">
              <label>ที่อยู่</label>
              <textarea v-model="form.address" rows="2" placeholder="บ้านเลขที่ ถนน ตำบล อำเภอ จังหวัด"></textarea>
            </div>
            <div class="form-group full-width">
              <label>หมายเหตุ</label>
              <textarea v-model="form.notes" rows="2" placeholder="เช่น ชำนาญเส้นทางภาคเหนือ, ภาษาอังกฤษได้..."></textarea>
            </div>
          </div>

          <div class="linked-box" v-if="editing && editing.vehicles?.length">
            <p class="linked-hint">
              <span class="material-symbols-rounded">info</span>
              คนขับนี้ผูกกับรถ {{ editing.vehicles.length }} คัน — แก้ชื่อ/เบอร์/รูปที่นี่ แล้วข้อมูลบนรถทุกคันจะอัปเดตตาม
            </p>
            <div class="linked-vehicles">
              <span class="linked-veh" v-for="v in editing.vehicles" :key="v.id">
                {{ v.name }}
                <b v-if="v.license_plate">{{ v.license_plate }}</b>
                <i v-else>ยังไม่ได้ใส่ทะเบียน</i>
              </span>
            </div>
            <p class="linked-hint muted">
              ทะเบียนรถเป็นของรถแต่ละคัน ไม่ได้อยู่กับคนขับ (คนขับหนึ่งคนสลับขับได้หลายคัน) —
              แก้ได้ที่ <router-link to="/admin/vehicles" target="_blank">หน้ายานพาหนะ</router-link>
            </p>
          </div>
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
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import MediaLibrary from '../../components/MediaLibrary.vue';
import api from '../../lib/axios';
import { colorHex, vehicleTypeLabel } from '../../lib/vehicleDisplay';

const admin = useAdminStore();
const drivers = ref({ data: [], meta: null });
const loading = ref(false);
const searchQuery = ref('');
const unlinkedOnly = ref(false);
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const showMediaLibrary = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
let searchTimer = null;

const defaultForm = {
  name: '', phone: '', line_id: '', photo: '', notes: '', is_active: true,
  license_number: '', license_type: '', license_expires_at: '',
  id_card: '', birth_date: '', address: '', emergency_contact: '', emergency_phone: '',
};
const form = reactive({ ...defaultForm });
const licencePreviewFailed = ref(false);

// ข้อความใต้ช่องวันหมดอายุ — บอกทันทีว่าใบยังใช้ได้อีกกี่วัน ไม่ต้องนับเอง
const licenseHint = computed(() => {
  if (!form.license_expires_at) return 'เว้นว่างได้ แต่ใส่ไว้ระบบจะเตือนก่อนหมดอายุ';
  const days = daysUntil(form.license_expires_at);
  if (days === null) return '';
  if (days < 0) return `หมดอายุไปแล้ว ${Math.abs(days)} วัน`;
  if (days === 0) return 'หมดอายุวันนี้';
  if (days <= 60) return `อีก ${days} วันหมดอายุ`;
  return `ใช้ได้อีก ${days} วัน`;
});

const licenseHintTone = computed(() => {
  const days = daysUntil(form.license_expires_at);
  if (days === null) return '';
  return days < 0 ? 'is-expired' : days <= 60 ? 'is-expiring' : '';
});

/** จำนวนวันจากวันนี้ (ตามเวลาไทย) ถึงวันที่กำหนด — ติดลบคือเลยมาแล้ว */
function daysUntil(date) {
  if (!date) return null;
  const target = new Date(`${date}T00:00:00+07:00`);
  if (Number.isNaN(target.getTime())) return null;
  const today = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Bangkok' }));
  today.setHours(0, 0, 0, 0);
  return Math.round((target - today) / 86400000);
}

function licenseBadge(driver) {
  return {
    expired: { label: 'ใบขับขี่หมดอายุ', tone: 'is-expired' },
    expiring: { label: `อีก ${driver.license_days_left} วันหมดอายุ`, tone: 'is-expiring' },
  }[driver.license_status] || null;
}

async function fetchData(page = 1) {
  loading.value = true;
  try {
    const res = await admin.fetchDrivers({
      search: searchQuery.value || undefined,
      unlinked_only: unlinkedOnly.value ? 1 : undefined,
      page,
    });
    drivers.value = { data: res.data, meta: res.meta };
  } finally {
    loading.value = false;
  }
}

function onSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => fetchData(1), 300);
}

function toggleUnlinked() {
  unlinkedOnly.value = !unlinkedOnly.value;
  fetchData(1);
}

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
};

function goPage(p) { fetchData(p); }

function openForm(d = null) {
  editing.value = d;
  licencePreviewFailed.value = false;
  if (d) {
    Object.assign(form, {
      name: d.name, phone: d.phone || '', line_id: d.line_id || '',
      photo: d.photo || '', notes: d.notes || '', is_active: d.is_active,
      license_number: d.license_number || '', license_type: d.license_type || '',
      license_expires_at: d.license_expires_at || '',
      id_card: d.id_card || '', birth_date: d.birth_date || '', address: d.address || '',
      emergency_contact: d.emergency_contact || '', emergency_phone: d.emergency_phone || '',
    });
  } else {
    Object.assign(form, defaultForm);
  }
  showForm.value = true;
}

/**
 * ไฟล์ใบขับขี่อัปโหลดแยกจากการบันทึกฟอร์ม เพราะเก็บบนดิสก์ส่วนตัว
 * (คนละทางกับคลังมีเดียสาธารณะที่รูปคนขับใช้)
 */
async function uploadLicensePhoto(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || !editing.value) return;

  const body = new FormData();
  body.append('license_photo', file);
  try {
    const res = await api.post(`/admin/drivers/${editing.value.id}/license-photo`, body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    editing.value = res.data.data;
    licencePreviewFailed.value = false;
    fetchData(drivers.value.meta?.current_page || 1);
  } catch (e) {
    alert(e.response?.data?.message || 'อัปโหลดไฟล์ใบขับขี่ไม่สำเร็จ');
  }
}

async function removeLicensePhoto() {
  if (!editing.value) return;
  try {
    const res = await api.delete(`/admin/drivers/${editing.value.id}/license-photo`);
    editing.value = res.data.data;
    fetchData(drivers.value.meta?.current_page || 1);
  } catch (e) {
    alert(e.response?.data?.message || 'ลบไฟล์ใบขับขี่ไม่สำเร็จ');
  }
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
/* บรรทัดนำเข้าไฟล์กลางต้องอยู่บนสุดของ style block เสมอ — ถ้ามีกฎ CSS อื่นนำหน้า
   ตัว build จะทิ้งการนำเข้าไปเงียบ ๆ แล้วสไตล์กลางของหน้าแอดมิน (ปุ่ม ตาราง โมดัล)
   จะหายไปทั้งหน้า หน้าจะยังโหลดขึ้นแต่กดปุ่มอะไรไม่ได้ */
@import url('./admin-shared.css');

.form-section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 18px 0 10px;
  font-size: 13px;
  font-weight: 800;
  color: #374151;
}

.form-section-title:first-child { margin-top: 0; }
.form-section-title .material-symbols-rounded { font-size: 18px; color: var(--color-accent, #2d7a4f); }

.field-hint.is-expiring { color: #b45309; font-weight: 700; }
.field-hint.is-expired { color: #b91c1c; font-weight: 700; }

.cell-sub { font-size: 11px; color: #9ca3af; margin-top: 2px; }

.licence-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 4px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}

.licence-badge .material-symbols-rounded { font-size: 13px; }
.licence-badge.is-expiring { background: #fef3c7; color: #b45309; }
.licence-badge.is-expired { background: #fee2e2; color: #b91c1c; }

.licence-field { display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; }

.licence-thumb {
  display: block;
  width: 120px;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  background: #f8fafc;
}

.licence-thumb img { width: 100%; display: block; }

.licence-file {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 14px 10px;
  font-size: 12px;
  color: #374151;
}

.licence-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.licence-actions .photo-pick { cursor: pointer; }
.btn-danger.compact { padding: 7px 12px; font-size: 13px; }
.field-hint {
  display: block;
  margin-top: 4px;
  font-size: 11px;
  color: #9ca3af;
}

/* ห่อเฉย ๆ — ตัว .linked-hint ข้างในเป็นแถบฟ้าเดิมอยู่แล้ว ไม่ต้องมีกรอบซ้อนอีกชั้น */
.linked-box { margin-top: 4px; }

.linked-vehicles {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 8px 0;
}

.linked-veh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 12px;
  color: #374151;
}

.linked-veh b {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  font-weight: 700;
  color: #111827;
  background: #f1f5f9;
  border-radius: 5px;
  padding: 1px 6px;
}

.linked-veh i { font-size: 11px; font-style: normal; color: #b45309; }

/* บรรทัดอธิบายท้ายกล่อง — ไม่เอาพื้นหลังฟ้าของ .linked-hint ตัวหลัก */
.linked-hint.muted {
  display: block;
  margin: 8px 0 0;
  padding: 0;
  background: transparent;
  color: #9ca3af;
  font-size: 11px;
  line-height: 1.6;
}

.linked-hint.muted a { color: var(--color-accent, #2d7a4f); font-weight: 700; }

.driver-cell { display: flex; align-items: center; gap: 10px; }
.driver-avatar { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #eef2f7; display: flex; align-items: center; justify-content: center; }
.driver-avatar img { width: 100%; height: 100%; object-fit: cover; }
.driver-avatar.placeholder .material-symbols-rounded { font-size: 20px; color: #94a3b8; }
.driver-name { font-weight: 700; }
.veh-list { display: flex; flex-direction: column; gap: 6px; }
.veh-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; width: fit-content; }
.color-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.veh-name { font-weight: 700; color: #0f172a; }
.veh-plate { font-family: ui-monospace, monospace; font-weight: 700; color: #0f766e; background: #ccfbf1; padding: 1px 6px; border-radius: 4px; }
.veh-meta { color: #64748b; }
.veh-pin { display: inline-flex; color: #b45309; }
.veh-pin .material-symbols-rounded { font-size: 15px; }
.veh-none { color: #94a3b8; font-size: 13px; }

.usage-cell { display: flex; flex-direction: column; gap: 3px; font-size: 13px; }
.usage-upcoming { display: inline-flex; align-items: center; gap: 4px; font-weight: 700; color: #0f766e; }
.usage-upcoming .material-symbols-rounded { font-size: 15px; }
.usage-last { color: #64748b; }
.usage-never { display: inline-flex; align-items: center; gap: 4px; color: #b91c1c; font-weight: 700; }
.usage-never .material-symbols-rounded { font-size: 15px; }

.filter-chip { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #475569; font-weight: 700; font-size: 13px; cursor: pointer; white-space: nowrap; }
.filter-chip.active { background: #0f172a; border-color: #0f172a; color: #fff; }
.filter-chip .material-symbols-rounded { font-size: 17px; }

.btn-icon:disabled { opacity: 0.35; cursor: not-allowed; }

.photo-field { display: flex; align-items: center; gap: 12px; }
.photo-preview { position: relative; width: 72px; height: 72px; border-radius: 12px; overflow: hidden; }
.photo-preview img { width: 100%; height: 100%; object-fit: cover; }
.photo-preview .remove-btn { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border: none; border-radius: 50%; background: rgba(0,0,0,0.6); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.photo-preview .remove-btn .material-symbols-rounded { font-size: 15px; }
.photo-pick { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border: 1px dashed #cbd5e1; border-radius: 12px; background: #f8fafc; color: #475569; font-weight: 700; cursor: pointer; }

.linked-hint { display: flex; align-items: center; gap: 6px; margin-top: 12px; padding: 10px 12px; background: #eff6ff; color: #1d4ed8; border-radius: 10px; font-size: 13px; }
.linked-hint .material-symbols-rounded { font-size: 18px; }
</style>
