<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">sell</span> ระบบโปรโมชั่น / ส่วนลด</h1>
        <p class="page-subtitle">จัดการรหัสส่วนลด กำหนดเงื่อนไข และทริปที่ใช้ได้</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add_circle</span> สร้างโปรโมชั่น
      </button>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>รหัส (Code)</th>
              <th>ชื่อโปรโมชั่น</th>
              <th>ประเภท</th>
              <th>มูลค่า</th>
              <th>การใช้งาน</th>
              <th>สถานะ</th>
              <th>วันหมดอายุ</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in promotions" :key="p.id">
              <td><span class="badge font-mono">{{ p.code }}</span></td>
              <td class="font-bold">{{ p.name }}</td>
              <td>{{ p.type === 'percent' ? 'เปอร์เซ็นต์ (%)' : 'จำนวนเงิน (บาท)' }}</td>
              <td>{{ p.type === 'percent' ? p.value + '%' : formatCurrency(p.value) }}</td>
              <td class="usage-cell">
                <template v-if="p.claim_limit">
                  <div class="text-sm">
                    เก็บแล้ว <strong>{{ p.claimed_count || 0 }}</strong> / {{ p.claim_limit }} ใบ
                    <span v-if="claimsLeft(p) === 0" class="usage-full">ครบแล้ว</span>
                  </div>
                  <div class="usage-bar"><span :style="{ width: claimPercent(p) + '%' }"></span></div>
                </template>
                <div class="text-sm">
                  ใช้จองไป <strong>{{ p.used_count }}</strong>
                  <span v-if="p.max_uses">/ {{ p.max_uses }}</span> ครั้ง
                </div>
                <div class="text-xs text-gray-500">
                  {{ p.per_user_limit ? `คนละ ${p.per_user_limit} ครั้ง` : 'ใช้ซ้ำได้ไม่จำกัด' }}
                </div>
              </td>
              <td>
                <span :class="['status-pill', p.is_active ? 'pill-active' : 'pill-inactive']">
                  {{ p.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน' }}
                </span>
                <span v-if="p.is_flash_sale" class="status-pill pill-flash" style="margin-left:4px;">⚡ Flash</span>
              </td>
              <td class="date text-sm">
                <div v-if="p.is_flash_sale && p.ends_at">จบ: {{ formatDateTime(p.ends_at) }}</div>
                <div v-else-if="p.start_date || p.end_date">
                  <div>เริ่ม: {{ formatDate(p.start_date) || '-' }}</div>
                  <div>สิ้นสุด: {{ formatDate(p.end_date) || '-' }}</div>
                </div>
                <div v-else>ไม่มีกำหนด</div>
              </td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-edit" @click="openForm(p)" title="แก้ไข"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(p)" title="ลบ"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                </div>
              </td>
            </tr>
            <tr v-if="!promotions.length">
              <td colspan="8" class="empty-state">ไม่พบข้อมูลโปรโมชั่น</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขโปรโมชั่น' : 'สร้างโปรโมชั่นใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อโปรโมชั่น (สำหรับแอดมินดู) *</label>
              <input v-model="form.name" required placeholder="เช่น ส่วนลดช่วงสงกรานต์" />
            </div>
            
            <div class="form-group">
              <label>รหัสส่วนลด (Promo Code) *</label>
              <input v-model="form.code" required placeholder="เช่น SONGKRAN2026" style="text-transform: uppercase;" />
              <small class="text-gray-500 mt-1 block">รหัสที่ลูกค้าต้องกรอกเพื่อรับสิทธิ์</small>
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

            <div class="form-group">
              <label>ประเภทส่วนลด *</label>
              <select v-model="form.type" required>
                <option value="fixed">ลดเป็นจำนวนเงิน (บาท)</option>
                <option value="percent">ลดเป็นเปอร์เซ็นต์ (%)</option>
              </select>
            </div>

            <div class="form-group">
              <label>มูลค่าที่ลด *</label>
              <input v-model.number="form.value" type="number" step="0.01" min="0" required />
            </div>

            <div class="form-group">
              <label>วันที่เริ่ม (ไม่บังคับ)</label>
              <input v-model="form.start_date" type="date" />
            </div>

            <div class="form-group">
              <label>วันสิ้นสุด (ไม่บังคับ)</label>
              <input v-model="form.end_date" type="date" />
            </div>

            <div class="form-group">
              <label>⚡ Flash Sale (นับถอยหลังในแอป)</label>
              <div class="toggle-group mt-2">
                <label class="switch">
                  <input type="checkbox" v-model="form.is_flash_sale">
                  <span class="slider round"></span>
                </label>
                <span class="ml-2">{{ form.is_flash_sale ? 'เปิด' : 'ปิด' }}</span>
              </div>
            </div>

            <div class="form-group" v-if="form.is_flash_sale">
              <label>เวลาสิ้นสุด Flash Sale *</label>
              <input v-model="form.ends_at" type="datetime-local" :required="form.is_flash_sale" />
              <small class="text-gray-500 mt-1 block">แอปจะนับถอยหลังถึงเวลานี้ (ระดับวินาที) แล้วซ่อนโปรฯ อัตโนมัติ</small>
            </div>

            <div class="form-group full-width">
              <div class="form-section-title">
                <span class="material-symbols-rounded">confirmation_number</span> จำนวนคูปองและสิทธิ์
              </div>
            </div>

            <div class="form-group">
              <label>จำนวนคูปองที่แจก (ใบ)</label>
              <input v-model.number="form.claim_limit" type="number" :min="Math.max(1, editing?.claimed_count || 0)" placeholder="ไม่จำกัด" />
              <small class="text-gray-500 mt-1 block">
                ลูกค้ากด "เก็บ" ได้คนละ 1 ใบ จนครบจำนวน คนที่เก็บไว้ใช้ได้จนหมดอายุ
                <template v-if="editing?.claimed_count"> · เก็บไปแล้ว {{ editing.claimed_count }} ใบ</template>
              </small>
            </div>

            <div class="form-group">
              <label>ใช้ได้คนละกี่ครั้ง</label>
              <input v-model.number="form.per_user_limit" type="number" min="1" placeholder="ไม่จำกัด" />
              <small class="text-gray-500 mt-1 block">นับเฉพาะการจองที่ไม่ถูกยกเลิก — ยกเลิกแล้วได้สิทธิ์คืน</small>
            </div>

            <div class="form-group full-width">
              <label>จำกัดจำนวนครั้งที่ใช้จองได้ (รวมทุกคน) (ไม่บังคับ)</label>
              <input v-model.number="form.max_uses" type="number" min="1" placeholder="ปล่อยว่างหากไม่จำกัด" />
              <small class="text-gray-500 mt-1 block" v-if="form.claim_limit">
                ถ้าแจกเป็นจำนวนใบแล้ว ส่วนใหญ่ไม่ต้องกรอกช่องนี้ — ไม่งั้นคนที่เก็บไว้อาจใช้ไม่ได้เมื่อครบจำนวนครั้ง
              </small>
            </div>

            <div class="form-group full-width claim-summary" v-if="form.claim_limit || form.per_user_limit">
              <span class="material-symbols-rounded">info</span>
              <span>{{ claimSummary }}</span>
            </div>

            <div class="form-group full-width">
              <label>ทริปที่สามารถใช้ได้ (ปล่อยว่างหากใช้ได้กับทุกทริป)</label>
              <div class="trip-selection border rounded-lg p-3 max-h-60 overflow-y-auto mt-1">
                <div v-if="loadingTrips" class="text-center py-2 text-gray-500">กำลังโหลดทริป...</div>
                <div v-else>
                  <label class="flex items-center gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer" v-for="trip in trips" :key="trip.id">
                    <input type="checkbox" :value="trip.id" v-model="form.trip_ids" class="rounded text-accent focus:ring-accent" />
                    <div class="flex flex-col">
                      <span class="font-medium text-sm">{{ trip.title }}</span>
                      <span class="text-xs text-gray-500">ID: {{ trip.id }}</span>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ editing ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างโปรโมชั่น' }}
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
          <p class="confirm-text">คุณต้องการลบโปรโมชั่น <strong>{{ deleting?.code }}</strong> ใช่หรือไม่?</p>
          <p class="text-sm mt-2 text-gray-600" v-if="deleting?.used_count > 0 || deleting?.claimed_count > 0">
            *โปรโมชั่นนี้<template v-if="deleting.used_count > 0">ถูกใช้ไปแล้ว {{ deleting.used_count }} ครั้ง</template><template v-if="deleting.used_count > 0 && deleting.claimed_count > 0"> และ</template><template v-if="deleting.claimed_count > 0">มีลูกค้าเก็บไว้ {{ deleting.claimed_count }} ใบ</template>
            หากลบ ระบบจะเปลี่ยนสถานะเป็น "ปิดใช้งาน" แทนเพื่อเก็บประวัติ
          </p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ยืนยัน</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../../js/lib/axios';

const promotions = ref([]);
const trips = ref([]);
const loading = ref(true);
const loadingTrips = ref(false);

const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);

const form = reactive({
  code: '',
  name: '',
  type: 'fixed',
  value: null,
  trip_ids: [],
  max_uses: null,
  claim_limit: null,
  per_user_limit: 1,
  is_active: true,
  is_flash_sale: false,
  start_date: '',
  end_date: '',
  ends_at: '',
});

const claimsLeft = (p) => (p.claim_limit ? Math.max(0, p.claim_limit - (p.claimed_count || 0)) : null);
const claimPercent = (p) => (p.claim_limit ? Math.min(100, Math.round(((p.claimed_count || 0) / p.claim_limit) * 100)) : 0);

// อ่านทวนให้แอดมินเห็นว่าตั้งแบบนี้แล้วลูกค้าจะเจออะไร
const claimSummary = computed(() => {
  const parts = [];
  if (form.claim_limit) parts.push(`แจก ${form.claim_limit} ใบ ให้ลูกค้ากดเก็บไว้ใช้ (เก็บครบแล้วคูปองหายจากหน้าแอปสำหรับคนที่ยังไม่ได้เก็บ)`);
  parts.push(form.per_user_limit ? `ใช้ได้คนละ ${form.per_user_limit} ครั้ง` : 'คนเดียวใช้ซ้ำได้ไม่จำกัด');
  if (form.end_date) parts.push(`ใช้ได้ถึง ${formatDate(form.end_date)}`);
  return parts.join(' · ');
});

const formatCurrency = (value) => {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB' }).format(value);
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString('th-TH', {
    day: 'numeric', month: 'short', year: 'numeric'
  });
};

const formatDateTime = (value) => {
  if (!value) return '';
  return new Date(value).toLocaleString('th-TH', {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  });
};

// Convert an ISO instant to the "YYYY-MM-DDTHH:mm" a datetime-local input wants,
// in the admin's local timezone.
const toLocalInput = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const fetchPromotions = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/promotions');
    promotions.value = res.data;
  } catch (e) {
    console.error('Error fetching promotions', e);
  } finally {
    loading.value = false;
  }
};

const fetchTrips = async () => {
  loadingTrips.value = true;
  try {
    const res = await api.get('/admin/trips', { params: { per_page: 100 } });
    trips.value = res.data.data || res.data;
  } catch (e) {
    console.error('Error fetching trips', e);
  } finally {
    loadingTrips.value = false;
  }
};

const openForm = (p = null) => {
  editing.value = p;
  if (p) {
    Object.assign(form, {
      code: p.code,
      name: p.name,
      type: p.type,
      value: p.value,
      trip_ids: p.trip_ids || [],
      max_uses: p.max_uses,
      claim_limit: p.claim_limit,
      per_user_limit: p.per_user_limit,
      is_active: !!p.is_active,
      is_flash_sale: !!p.is_flash_sale,
      start_date: p.start_date ? p.start_date.split('T')[0] : '',
      end_date: p.end_date ? p.end_date.split('T')[0] : '',
      ends_at: toLocalInput(p.ends_at),
    });
  } else {
    Object.assign(form, {
      code: '',
      name: '',
      type: 'fixed',
      value: null,
      trip_ids: [],
      max_uses: null,
      claim_limit: null,
      per_user_limit: 1,
      is_active: true,
      is_flash_sale: false,
      start_date: '',
      end_date: '',
      ends_at: '',
    });
  }
  showForm.value = true;
  if (trips.value.length === 0) {
    fetchTrips();
  }
};

const submitForm = async () => {
  submitting.value = true;
  try {
    const payload = { ...form };
    if (!payload.max_uses) payload.max_uses = null;
    if (!payload.claim_limit) payload.claim_limit = null;
    if (!payload.per_user_limit) payload.per_user_limit = null;
    if (!payload.start_date) payload.start_date = null;
    if (!payload.end_date) payload.end_date = null;
    // Send the flash deadline as an unambiguous UTC instant; clear it when the
    // promo isn't a flash sale so a stale time can't keep it hidden.
    payload.ends_at = payload.is_flash_sale && payload.ends_at
      ? new Date(payload.ends_at).toISOString()
      : null;
    payload.code = payload.code.toUpperCase();

    if (editing.value) {
      await api.put(`/admin/promotions/${editing.value.id}`, payload);
    } else {
      await api.post('/admin/promotions', payload);
    }
    showForm.value = false;
    fetchPromotions();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึก');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (p) => {
  deleting.value = p;
  showDeleteConfirm.value = true;
};

const doDelete = async () => {
  submitting.value = true;
  try {
    const res = await api.delete(`/admin/promotions/${deleting.value.id}`);
    showDeleteConfirm.value = false;
    if (res.data?.deactivated) alert(res.data.message);
    fetchPromotions();
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถลบได้');
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  fetchPromotions();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.badge {
  display: inline-block;
  padding: 4px 8px;
  background-color: #f3f4f6;
  border: 1px dashed #9ca3af;
  border-radius: 4px;
  color: #1f2937;
  font-weight: bold;
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

.usage-cell { min-width: 150px; }
.usage-full {
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 10px;
  background: #fef2f2;
  color: #ef4444;
  font-size: 11px;
  font-weight: 700;
}
.usage-bar {
  height: 4px;
  margin: 4px 0 6px;
  border-radius: 4px;
  background: #e5e7eb;
  overflow: hidden;
}
.usage-bar span {
  display: block;
  height: 100%;
  background: var(--color-accent);
}
.form-section-title {
  display: flex;
  align-items: center;
  gap: 6px;
  padding-top: 6px;
  border-top: 1px solid #e5e7eb;
  font-weight: 700;
}
.form-section-title .material-symbols-rounded { font-size: 18px; }
.claim-summary {
  flex-direction: row;
  align-items: flex-start;
  gap: 6px;
  padding: 10px 12px;
  border-radius: 8px;
  background: #f0f9ff;
  color: #0c4a6e;
  font-size: 13px;
}
.claim-summary .material-symbols-rounded { font-size: 18px; }

.pill-flash {
  background: #fff7ed;
  color: #ea580c;
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
