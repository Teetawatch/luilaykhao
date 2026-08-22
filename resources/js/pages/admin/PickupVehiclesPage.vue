<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">directions_car</span>
          รถรับ-ส่งจุดรับต่างภูมิภาค
        </h1>
        <p class="page-subtitle">
          ลูกค้าที่จ่ายค่าจุดรับเพิ่มจะเห็นรูปรถชุดนี้ตอนเลือกจุดขึ้นรถ ตั้งครั้งเดียวใช้ได้ทุกทริปทุกรอบ
        </p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add</span> เพิ่มประเภทรถ
      </button>
    </div>

    <div class="note-card">
      <span class="material-symbols-rounded">info</span>
      <p>
        นี่คือ<strong>ไกด์ประเภทรถ</strong> ไม่ใช่การผูกรถคันจริง — ตอนลูกค้าเลือกจุดรับ เรายังไม่รู้ว่าจุดนั้นจะมีคนรวมกี่คน
        แอปจะแสดงข้อความกำกับให้เองว่าประเภทรถขึ้นกับจำนวนผู้โดยสารรวม ณ จุดนั้น
      </p>
    </div>

    <div v-if="coverageWarnings.length" class="warn-card">
      <span class="material-symbols-rounded">warning</span>
      <div>
        <p v-for="w in coverageWarnings" :key="w">{{ w }}</p>
      </div>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div v-else>
        <div v-if="!classes.length" class="empty-state">
          <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">directions_car</span>
          ยังไม่มีประเภทรถ กด "เพิ่มประเภทรถ" เพื่อเริ่มต้น
        </div>

        <div v-else class="rows">
          <div
            v-for="(item, index) in classes"
            :key="item.id"
            class="row"
            :class="{ 'row-inactive': !item.is_active }"
          >
            <span class="order-num">{{ index + 1 }}</span>

            <div class="thumb-wrap">
              <img v-if="item.image_url" :src="item.image_url" :alt="item.label" class="thumb" />
              <div v-else class="thumb thumb-empty">
                <span class="material-symbols-rounded">no_photography</span>
              </div>
            </div>

            <div class="info">
              <p class="label">{{ item.label }}</p>
              <p class="pax">
                <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px">group</span>
                {{ item.pax_label }}
              </p>
              <p class="note" v-if="item.note">{{ item.note }}</p>
            </div>

            <span class="status-pill" :class="item.is_active ? 'pill-active' : 'pill-inactive'">
              {{ item.is_active ? 'แสดงผล' : 'ซ่อนอยู่' }}
            </span>

            <div class="action-btns">
              <button class="btn-icon" :disabled="index === 0" @click="move(index, -1)" title="เลื่อนขึ้น">
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_upward</span>
              </button>
              <button class="btn-icon" :disabled="index === classes.length - 1" @click="move(index, 1)" title="เลื่อนลง">
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_downward</span>
              </button>
              <button class="btn-icon btn-edit" @click="openForm(item)" title="แก้ไข">
                <span class="material-symbols-rounded" style="font-size:16px;">edit</span>
              </button>
              <button class="btn-icon btn-delete" @click="confirmDelete(item)" title="ลบ">
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
          <h2>{{ editing ? 'แก้ไขประเภทรถ' : 'เพิ่มประเภทรถ' }}</h2>
          <button class="modal-close" @click="showForm = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <form @submit.prevent="submitForm">
          <div class="modal-body">
            <div class="form-grid">
              <div class="form-group full-width">
                <label>รูปรถ</label>
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
                    <button type="button" class="upload-clear" @click.stop="clearUpload" title="ลบรูป">
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                  <div v-else class="upload-placeholder">
                    <span class="material-symbols-rounded">cloud_upload</span>
                    <p style="font-size:14px;font-weight:600;color:#6b7280">คลิกหรือลากไฟล์มาวางที่นี่</p>
                    <p style="font-size:12px;color:#9ca3af">แนะนำรูปแนวนอน พื้นหลังโล่ง เห็นรถเต็มคัน</p>
                  </div>
                </div>
                <div class="upload-progress" v-if="uploading">
                  <div class="progress-bar" :style="{ width: uploadProgress + '%' }"></div>
                </div>
                <p v-if="uploading" style="font-size:12px;color:#6b7280;margin-top:4px">กำลังอัปโหลด {{ uploadProgress }}%...</p>
              </div>

              <div class="form-group full-width">
                <label>ชื่อประเภทรถ</label>
                <input v-model="form.label" required maxlength="80" placeholder="เช่น รถ SUV" />
              </div>

              <div class="form-group">
                <label>ผู้โดยสารตั้งแต่ (คน)</label>
                <input v-model.number="form.min_pax" type="number" min="1" max="255" required />
              </div>

              <div class="form-group">
                <label>ถึง (คน) <span style="font-weight:400;color:#9ca3af">— เว้นว่าง = ขึ้นไป</span></label>
                <input v-model="form.max_pax" type="number" min="1" max="255" placeholder="ไม่จำกัด" />
              </div>

              <div class="form-group full-width">
                <label>คำอธิบายสั้น <span style="font-weight:400;color:#9ca3af">(ไม่บังคับ)</span></label>
                <input v-model="form.note" maxlength="255" placeholder="เช่น เดินทาง 3-4 ท่าน สัมภาระเยอะขึ้น" />
              </div>

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

              <div class="form-group full-width">
                <label>ตัวอย่างที่ลูกค้าเห็น</label>
                <div class="preview-chip">
                  <img v-if="form.image_url" :src="form.image_url" class="preview-chip-img" />
                  <div v-else class="preview-chip-img preview-chip-empty">
                    <span class="material-symbols-rounded">directions_car</span>
                  </div>
                  <div>
                    <p class="preview-chip-label">{{ form.label || 'ชื่อประเภทรถ' }}</p>
                    <p class="preview-chip-pax">{{ previewPaxLabel }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting || uploading || !form.label">
              <span class="material-symbols-rounded animate-spin" style="font-size:16px" v-if="submitting">sync</span>
              {{ editing ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มประเภทรถ' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div class="modal-overlay" v-if="showDeleteConfirm" @click.self="showDeleteConfirm = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">
            ลบ "{{ deleting?.label }}" ออกจากไกด์ประเภทรถใช่หรือไม่?
            ลูกค้าที่เดินทาง {{ deleting?.pax_label }} จะไม่เห็นรูปรถอีก
          </p>
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
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../../js/lib/axios';

const classes = ref([]);
const loading = ref(true);
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);

const fileInput = ref(null);
const uploading = ref(false);
const uploadProgress = ref(0);

const form = reactive({
  label: '',
  min_pax: 1,
  max_pax: '',
  image_url: '',
  note: '',
  is_active: true,
});

const paxLabel = (min, max) => {
  if (!min) return '—';
  if (max === null || max === '' || max === undefined) return `${min} ท่านขึ้นไป`;
  if (Number(max) === Number(min)) return `${min} ท่าน`;
  return `${min}-${max} ท่าน`;
};

const previewPaxLabel = computed(() => paxLabel(form.min_pax, form.max_pax));

/**
 * ช่วงจำนวนคนที่ไม่มีรถรองรับ / ที่ทับกันเอง — ลูกค้าที่ตกอยู่ในช่องโหว่จะไม่เห็น
 * รูปรถเลย ส่วนช่วงที่ทับกันจะเห็นสองการ์ดไฮไลต์พร้อมกัน
 */
const coverageWarnings = computed(() => {
  const active = classes.value
    .filter((c) => c.is_active)
    .map((c) => ({ ...c, max: c.max_pax ?? Infinity }))
    .sort((a, b) => a.min_pax - b.min_pax);

  if (!active.length) return ['ยังไม่มีประเภทรถที่เปิดแสดง ลูกค้าจะไม่เห็นรูปรถในหน้าจอง'];

  const warnings = [];

  if (active[0].min_pax > 1) {
    warnings.push(`เดินทาง 1-${active[0].min_pax - 1} ท่าน ยังไม่มีประเภทรถรองรับ`);
  }

  for (let i = 1; i < active.length; i++) {
    const prev = active[i - 1];
    const cur = active[i];
    if (cur.min_pax > prev.max + 1) {
      warnings.push(`เดินทาง ${prev.max + 1}-${cur.min_pax - 1} ท่าน ยังไม่มีประเภทรถรองรับ`);
    } else if (cur.min_pax <= prev.max) {
      warnings.push(`"${prev.label}" กับ "${cur.label}" มีช่วงจำนวนคนทับกัน`);
    }
  }

  if (active[active.length - 1].max !== Infinity) {
    warnings.push(`เดินทางมากกว่า ${active[active.length - 1].max} ท่าน ยังไม่มีประเภทรถรองรับ (เว้นช่อง "ถึง" ว่างไว้เพื่อรองรับไม่จำกัด)`);
  }

  return warnings;
});

const fetchClasses = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/pickup-vehicle-classes');
    classes.value = res.data.data ?? res.data;
  } finally {
    loading.value = false;
  }
};

onMounted(fetchClasses);

const openForm = (item = null) => {
  editing.value = item;
  if (item) {
    Object.assign(form, {
      label: item.label,
      min_pax: item.min_pax,
      max_pax: item.max_pax ?? '',
      image_url: item.image_url ?? '',
      note: item.note ?? '',
      is_active: !!item.is_active,
    });
  } else {
    Object.assign(form, { label: '', min_pax: 1, max_pax: '', image_url: '', note: '', is_active: true });
  }
  if (fileInput.value) fileInput.value.value = '';
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
  form.image_url = '';
  uploadProgress.value = 0;
  if (fileInput.value) fileInput.value.value = '';
};

const uploadFile = async (file) => {
  if (!file.type.startsWith('image/')) {
    alert('รองรับเฉพาะไฟล์รูปภาพเท่านั้น');
    return;
  }
  uploading.value = true;
  uploadProgress.value = 0;
  try {
    const formData = new FormData();
    formData.append('file', file);
    const res = await api.post('/admin/pickup-points/image', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        uploadProgress.value = Math.round((e.loaded / e.total) * 100);
      },
    });
    form.image_url = res.data.data?.url ?? res.data.url;
  } catch (e) {
    alert(e.response?.data?.errors?.file?.[0] ?? e.response?.data?.message ?? 'อัปโหลดรูปไม่สำเร็จ');
    clearUpload();
  } finally {
    uploading.value = false;
  }
};

const submitForm = async () => {
  submitting.value = true;
  try {
    // ช่อง "ถึง" ที่เว้นว่างคือ "ขึ้นไปไม่จำกัด" ต้องส่ง null ไม่ใช่สตริงว่าง
    const payload = { ...form, max_pax: form.max_pax === '' ? null : Number(form.max_pax) };
    if (editing.value) {
      await api.put(`/admin/pickup-vehicle-classes/${editing.value.id}`, payload);
    } else {
      await api.post('/admin/pickup-vehicle-classes', payload);
    }
    showForm.value = false;
    await fetchClasses();
  } catch (e) {
    alert(e.response?.data?.message ?? 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (item) => {
  deleting.value = item;
  showDeleteConfirm.value = true;
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await api.delete(`/admin/pickup-vehicle-classes/${deleting.value.id}`);
    showDeleteConfirm.value = false;
    await fetchClasses();
  } finally {
    submitting.value = false;
  }
};

const move = async (index, direction) => {
  const next = [...classes.value];
  const target = index + direction;
  [next[index], next[target]] = [next[target], next[index]];
  classes.value = next;
  await api.post('/admin/pickup-vehicle-classes/reorder', { ids: next.map((i) => i.id) });
};
</script>

<style scoped>
@import url('./admin-shared.css');

.heading-icon { color: var(--color-accent); font-size: 28px; }

.note-card, .warn-card {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 12px 16px;
  border-radius: 10px;
  margin-bottom: 16px;
  font-size: 13px;
  line-height: 1.6;
}
.note-card { background: #f0f9ff; color: #0c4a6e; border: 1px solid #bae6fd; }
.warn-card { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.note-card .material-symbols-rounded,
.warn-card .material-symbols-rounded { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

.rows { display: flex; flex-direction: column; }

.row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 20px;
  border-bottom: 1px solid #f0f0f0;
  transition: background 0.15s;
}
.row:last-child { border-bottom: none; }
.row:hover { background: #fafafa; }
.row-inactive { opacity: 0.45; }

.order-num { font-size: 12px; font-weight: 700; color: #9ca3af; width: 20px; flex-shrink: 0; }

.thumb-wrap { flex-shrink: 0; }
.thumb {
  width: 110px;
  height: 72px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
  background: #f3f4f6;
  display: block;
}
.thumb-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #d1d5db;
}
.thumb-empty .material-symbols-rounded { font-size: 28px; }

.info { flex: 1; min-width: 0; }
.label { font-weight: 700; font-size: 14px; color: #111827; }
.pax { font-size: 12px; color: #059669; font-weight: 600; margin-top: 2px; }
.note { font-size: 12px; color: #6b7280; margin-top: 2px; }

.status-pill {
  flex-shrink: 0;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 700;
}
.pill-active { background: #d1fae5; color: #059669; }
.pill-inactive { background: #fee2e2; color: #dc2626; }

.upload-zone {
  border: 2px dashed #d1d5db;
  border-radius: 12px;
  padding: 24px;
  cursor: pointer;
  text-align: center;
  transition: border-color 0.2s, background 0.2s;
  min-height: 148px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
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
  object-fit: contain;
  border-radius: 8px;
  display: block;
  background: #f9fafb;
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
}
.upload-clear:hover { background: rgba(0,0,0,0.75); }
.upload-clear .material-symbols-rounded { font-size: 16px; }

.upload-progress { height: 4px; background: #e5e7eb; border-radius: 4px; margin-top: 8px; overflow: hidden; }
.progress-bar { height: 100%; background: var(--color-accent); transition: width 0.25s; }

.preview-chip {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #fafafa;
}
.preview-chip-img {
  width: 92px;
  height: 60px;
  object-fit: cover;
  border-radius: 8px;
  background: #f3f4f6;
  flex-shrink: 0;
}
.preview-chip-empty { display: flex; align-items: center; justify-content: center; color: #d1d5db; }
.preview-chip-empty .material-symbols-rounded { font-size: 26px; }
.preview-chip-label { font-weight: 700; font-size: 14px; color: #111827; }
.preview-chip-pax { font-size: 12px; color: #059669; font-weight: 600; margin-top: 2px; }

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
