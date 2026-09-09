<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">celebration</span>
          แคมเปญวันพิเศษ
        </h1>
        <p class="page-subtitle">
          ตั้งครั้งเดียว ลดราคาทริปทุกรอบที่ยังเปิดขายพร้อมกัน (9.9 / 10.10 / 12.12) แล้วราคาเด้งกลับเองเมื่อหมดเวลา
        </p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add_circle</span> สร้างแคมเปญ
      </button>
    </div>

    <!-- แคมเปญที่กำลังลดราคาอยู่ตอนนี้ -->
    <div class="table-card live-banner" v-if="liveCampaign" :style="{ borderColor: liveCampaign.theme_color || '#e11d48' }">
      <div class="live-main">
        <div class="live-badge" :style="{ background: liveCampaign.theme_color || '#e11d48' }">
          {{ liveCampaign.badge_label || 'SALE' }}
        </div>
        <div>
          <p class="live-title">{{ liveCampaign.name }} · {{ liveCampaign.discount_label }}</p>
          <p class="live-sub">{{ liveCampaign.tagline || 'กำลังลดราคาทุกทริปที่ยังเปิดขายอยู่ตอนนี้' }}</p>
        </div>
      </div>
      <div class="live-stats">
        <div><span class="stat-label">เหลืออีก</span><strong class="stat-value countdown">{{ countdown }}</strong></div>
        <div><span class="stat-label">จองในแคมเปญ</span><strong class="stat-value">{{ liveCampaign.bookings_count }} ใบ</strong></div>
        <div><span class="stat-label">ยอดขาย</span><strong class="stat-value">{{ money(liveCampaign.revenue) }}</strong></div>
        <div><span class="stat-label">ส่วนลดที่ให้ไป</span><strong class="stat-value">{{ money(liveCampaign.discount_given) }}</strong></div>
      </div>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div class="empty-state" v-else-if="!campaigns.length">
        <p>ยังไม่มีแคมเปญ — กด "สร้างแคมเปญ" เพื่อตั้งวัน 9.9 ครั้งแรก</p>
      </div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>แคมเปญ</th>
              <th>ส่วนลด</th>
              <th>ช่วงเวลา</th>
              <th>สถานะ</th>
              <th>ผลลัพธ์</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in campaigns" :key="c.id">
              <td>
                <div class="font-bold">
                  <span class="badge" v-if="c.badge_label">{{ c.badge_label }}</span>
                  {{ c.name }}
                </div>
                <div class="text-xs text-gray-500" v-if="c.tagline">{{ c.tagline }}</div>
                <div class="text-xs text-gray-500" v-if="c.excluded_trip_ids?.length">
                  ยกเว้น {{ c.excluded_trip_ids.length }} ทริป
                </div>
              </td>
              <td>
                <strong>{{ c.discount_label }}</strong>
                <div class="text-xs text-gray-500" v-if="c.max_discount">ไม่เกิน {{ money(c.max_discount) }}/คน</div>
              </td>
              <td class="date text-sm">
                <div>เริ่ม: {{ formatDateTime(c.starts_at) }}</div>
                <div>จบ: {{ formatDateTime(c.ends_at) }}</div>
              </td>
              <td>
                <span class="status-pill" :class="statusClass(c)">{{ statusLabel(c) }}</span>
              </td>
              <td class="text-sm">
                <div>{{ c.bookings_count }} ใบจอง · {{ money(c.revenue) }}</div>
                <div class="text-xs text-gray-500">ลดไป {{ money(c.discount_given) }}</div>
              </td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-edit" @click="openForm(c)" title="แก้ไข">
                    <span class="material-symbols-rounded" style="font-size:16px;">edit</span>
                  </button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(c)" title="ลบ">
                    <span class="material-symbols-rounded" style="font-size:16px;">delete</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ฟอร์มสร้าง/แก้ไข -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขแคมเปญ' : 'สร้างแคมเปญวันพิเศษ' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submit">
          <div class="modal-body form-grid">
            <div class="form-group">
              <label>ชื่อแคมเปญ *</label>
              <input v-model.trim="form.name" type="text" maxlength="120" required placeholder="9.9 ลุยลายเขา" />
            </div>

            <div class="form-group">
              <label>ป้ายสั้น (ขึ้นบนการ์ดทริป)</label>
              <input v-model.trim="form.badge_label" type="text" maxlength="20" placeholder="9.9" />
            </div>

            <div class="form-group full-width">
              <label>คำโปรย (ใช้เป็นข้อความแจ้งเตือนตอนแคมเปญเริ่ม)</label>
              <input v-model.trim="form.tagline" type="text" maxlength="255"
                placeholder="ลด 15% ทุกทริปทั้งเว็บ วันเดียวเท่านั้น" />
            </div>

            <div class="form-group">
              <label>ประเภทส่วนลด *</label>
              <select v-model="form.discount_type" required>
                <option value="percent">ลดเป็นเปอร์เซ็นต์ (%)</option>
                <option value="amount">ลดเป็นจำนวนเงิน (บาท/คน)</option>
              </select>
            </div>

            <div class="form-group">
              <label>ส่วนลด *</label>
              <input v-model.number="form.discount_value" type="number" step="0.01" min="0"
                :max="form.discount_type === 'percent' ? 100 : null" required />
            </div>

            <div class="form-group">
              <label>ส่วนลดสูงสุดต่อคน (ไม่บังคับ)</label>
              <input v-model.number="form.max_discount" type="number" step="1" min="0" placeholder="เช่น 1000" />
              <small class="text-gray-500 mt-1 block">กันแคมเปญ % กินกำไรทริปราคาสูงเกินไป</small>
            </div>

            <div class="form-group">
              <label>เวลาเริ่ม *</label>
              <input v-model="form.starts_at" type="datetime-local" required />
              <small class="text-gray-500 mt-1 block">ตั้งล่วงหน้าได้ ก่อนถึงเวลาราคายังปกติและไม่มีแจ้งเตือน</small>
            </div>

            <div class="form-group">
              <label>เวลาสิ้นสุด *</label>
              <input v-model="form.ends_at" type="datetime-local" required />
            </div>

            <div class="form-group">
              <label>สีธีม (ไม่บังคับ)</label>
              <input v-model.trim="form.theme_color" type="text" maxlength="20" placeholder="#e11d48" />
            </div>

            <div class="form-group">
              <label>เปิดใช้งาน</label>
              <div class="toggle-group mt-2">
                <label class="switch">
                  <input type="checkbox" v-model="form.is_active">
                  <span class="slider round"></span>
                </label>
                <span class="ml-2">{{ form.is_active ? 'เปิด' : 'ปิด' }}</span>
              </div>
            </div>

            <div class="form-group">
              <label>แจ้งเตือนลูกค้าตอนเริ่ม</label>
              <div class="toggle-group mt-2">
                <label class="switch">
                  <input type="checkbox" v-model="form.announce_on_start">
                  <span class="slider round"></span>
                </label>
                <span class="ml-2">{{ form.announce_on_start ? 'ส่ง push' : 'เงียบ' }}</span>
              </div>
            </div>

            <!-- พรีวิว: จะลดกี่รอบ ราคาจากเท่าไหร่เหลือเท่าไหร่ -->
            <div class="form-group full-width">
              <label>ผลที่จะเกิดขึ้นถ้าเปิดแคมเปญนี้ตอนนี้</label>
              <div class="preview-box">
                <div v-if="previewLoading" class="text-sm text-gray-500">กำลังคำนวณ...</div>
                <template v-else-if="preview">
                  <div class="preview-summary">
                    <div><span>รอบที่จะลด</span><strong>{{ preview.schedules_count }} รอบ</strong></div>
                    <div><span>รอบที่ยกเว้น</span><strong>{{ preview.excluded_count }} รอบ</strong></div>
                    <div><span>ที่นั่งที่ยังว่าง</span><strong>{{ preview.total_seats_left }} ที่</strong></div>
                    <div>
                      <span>ส่วนลดสูงสุดถ้าขายหมด</span>
                      <strong class="text-rose">{{ money(preview.max_discount_exposure) }}</strong>
                    </div>
                  </div>
                  <div class="preview-list">
                    <div class="preview-row" v-for="row in preview.schedules.slice(0, 12)" :key="row.schedule_id">
                      <span class="preview-trip">{{ row.trip_title }}</span>
                      <span class="preview-date">{{ formatDate(row.departure_date) }}</span>
                      <span class="preview-price">
                        <s>{{ money(row.price_before) }}</s>
                        <strong>{{ money(row.price_after) }}</strong>
                      </span>
                    </div>
                    <div class="text-xs text-gray-500" v-if="preview.schedules.length > 12">
                      และอีก {{ preview.schedules.length - 12 }} รอบ
                    </div>
                  </div>
                </template>
                <div v-else class="text-sm text-gray-500">กรอกส่วนลดเพื่อดูตัวอย่าง</div>
              </div>
            </div>

            <div class="form-group full-width">
              <label>ทริปที่ไม่ร่วมแคมเปญ (ติ๊กเพื่อยกเว้น)</label>
              <div class="trip-selection border rounded-lg p-3 max-h-60 overflow-y-auto mt-1">
                <div v-if="loadingTrips" class="text-center py-2 text-gray-500">กำลังโหลดทริป...</div>
                <div v-else>
                  <label class="flex items-center gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer"
                    v-for="trip in trips" :key="trip.id">
                    <input type="checkbox" :value="trip.id" v-model="form.excluded_trip_ids" />
                    <div class="flex flex-col">
                      <span class="font-medium text-sm">{{ trip.title }}</span>
                      <span class="text-xs text-gray-500">ราคาปกติ {{ money(trip.price_per_person) }}</span>
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
              {{ editing ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างแคมเปญ' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal-overlay" v-if="showDeleteConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">ลบแคมเปญ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="text-sm mt-2 text-gray-600" v-if="deleting && isLive(deleting)">
            แคมเปญนี้กำลังลดราคาอยู่ — ลบแล้วราคาทุกทริปจะกลับเป็นราคาปกติทันที
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
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue';
import api from '../../../js/lib/axios';

const campaigns = ref([]);
const trips = ref([]);
const loading = ref(true);
const loadingTrips = ref(false);
const submitting = ref(false);
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const preview = ref(null);
const previewLoading = ref(false);
const now = ref(Date.now());
let ticker = null;
let previewTimer = null;

const blankForm = () => ({
  name: '',
  badge_label: '',
  tagline: '',
  discount_type: 'percent',
  discount_value: 10,
  max_discount: null,
  starts_at: '',
  ends_at: '',
  is_active: true,
  announce_on_start: true,
  theme_color: '',
  excluded_trip_ids: [],
});

const form = reactive(blankForm());

const money = (v) => '฿' + Number(v || 0).toLocaleString('th-TH', { maximumFractionDigits: 0 });

const formatDate = (d) => (d ? new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) : '-');

const formatDateTime = (d) =>
  d ? new Date(d).toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-';

const isLive = (c) => c.is_live;

const statusLabel = (c) => {
  if (!c.is_active) return 'ปิดใช้งาน';
  if (c.is_live) return 'กำลังลดราคา';
  if (c.is_upcoming) return 'รอถึงเวลา';
  return 'จบแล้ว';
};

const statusClass = (c) => {
  if (!c.is_active) return 'pill-inactive';
  if (c.is_live) return 'pill-flash';
  if (c.is_upcoming) return 'pill-pending';
  return 'pill-inactive';
};

const liveCampaign = computed(() => campaigns.value.find((c) => c.is_live) || null);

// นับถอยหลังจนแคมเปญจบ — ให้แอดมินเห็นเวลาที่เหลือเท่ากับที่ลูกค้าเห็น
const countdown = computed(() => {
  if (!liveCampaign.value?.ends_at) return '-';
  const diff = new Date(liveCampaign.value.ends_at).getTime() - now.value;
  if (diff <= 0) return 'หมดเวลา';
  const s = Math.floor(diff / 1000);
  const pad = (n) => String(n).padStart(2, '0');
  const days = Math.floor(s / 86400);
  const time = `${pad(Math.floor((s % 86400) / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
  return days > 0 ? `${days} วัน ${time}` : time;
});

// datetime-local อ่าน/เขียนเป็นเวลาเครื่องแอดมิน แต่ส่งขึ้นเซิร์ฟเวอร์เป็น UTC ISO
const toLocalInput = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const toIso = (local) => (local ? new Date(local).toISOString() : null);

const fetchCampaigns = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/sale-campaigns');
    campaigns.value = res.data.data || [];
  } catch (e) {
    console.error('Error fetching sale campaigns', e);
  } finally {
    loading.value = false;
  }
};

const fetchTrips = async () => {
  if (trips.value.length) return;
  loadingTrips.value = true;
  try {
    const res = await api.get('/admin/sale-campaigns/trips');
    trips.value = res.data.data || [];
  } catch (e) {
    console.error('Error fetching trips', e);
  } finally {
    loadingTrips.value = false;
  }
};

const fetchPreview = async () => {
  if (!form.discount_value) {
    preview.value = null;
    return;
  }
  previewLoading.value = true;
  try {
    const res = await api.get('/admin/sale-campaigns/preview', {
      params: {
        discount_type: form.discount_type,
        discount_value: form.discount_value,
        max_discount: form.max_discount || undefined,
        excluded_trip_ids: form.excluded_trip_ids,
      },
    });
    preview.value = res.data.data;
  } catch (e) {
    console.error('Error previewing campaign', e);
    preview.value = null;
  } finally {
    previewLoading.value = false;
  }
};

// หน่วงไว้ ไม่ให้ยิงพรีวิวทุกตัวเลขที่แอดมินพิมพ์
watch(
  () => [form.discount_type, form.discount_value, form.max_discount, form.excluded_trip_ids.length],
  () => {
    if (!showForm.value) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(fetchPreview, 400);
  }
);

const openForm = (campaign = null) => {
  editing.value = campaign;
  Object.assign(form, blankForm());

  if (campaign) {
    Object.assign(form, {
      name: campaign.name,
      badge_label: campaign.badge_label || '',
      tagline: campaign.tagline || '',
      discount_type: campaign.discount_type,
      discount_value: Number(campaign.discount_value),
      max_discount: campaign.max_discount !== null ? Number(campaign.max_discount) : null,
      starts_at: toLocalInput(campaign.starts_at),
      ends_at: toLocalInput(campaign.ends_at),
      is_active: !!campaign.is_active,
      announce_on_start: !!campaign.announce_on_start,
      theme_color: campaign.theme_color || '',
      excluded_trip_ids: [...(campaign.excluded_trip_ids || [])],
    });
  }

  showForm.value = true;
  preview.value = null;
  fetchTrips();
  fetchPreview();
};

const submit = async () => {
  submitting.value = true;
  try {
    const payload = {
      ...form,
      max_discount: form.max_discount || null,
      starts_at: toIso(form.starts_at),
      ends_at: toIso(form.ends_at),
    };

    if (editing.value) {
      await api.put(`/admin/sale-campaigns/${editing.value.id}`, payload);
    } else {
      await api.post('/admin/sale-campaigns', payload);
    }

    showForm.value = false;
    await fetchCampaigns();
  } catch (e) {
    const errors = e.response?.data?.errors;
    alert(errors ? Object.values(errors).flat().join('\n') : e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (campaign) => {
  deleting.value = campaign;
  showDeleteConfirm.value = true;
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await api.delete(`/admin/sale-campaigns/${deleting.value.id}`);
    showDeleteConfirm.value = false;
    await fetchCampaigns();
  } catch (e) {
    alert('ลบไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  fetchCampaigns();
  ticker = setInterval(() => (now.value = Date.now()), 1000);
});

onUnmounted(() => {
  clearInterval(ticker);
  clearTimeout(previewTimer);
});
</script>

<style scoped>
@import url('./admin-shared.css');

.live-banner {
  border: 2px solid #e11d48;
  padding: 20px;
  margin-bottom: 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  justify-content: space-between;
  align-items: center;
}

.live-main { display: flex; align-items: center; gap: 14px; }

.live-badge {
  color: #fff;
  font-weight: 800;
  font-size: 18px;
  padding: 10px 14px;
  border-radius: 10px;
  min-width: 64px;
  text-align: center;
}

.live-title { font-size: 16px; font-weight: 800; }
.live-sub { font-size: 13px; color: #64748b; }

.live-stats { display: flex; flex-wrap: wrap; gap: 24px; }
.live-stats > div { display: flex; flex-direction: column; }
.stat-label { font-size: 12px; color: #64748b; }
.stat-value { font-size: 16px; font-weight: 800; }
.countdown { font-variant-numeric: tabular-nums; }

.preview-box {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px;
  background: #f8fafc;
}

.preview-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  padding-bottom: 12px;
  border-bottom: 1px solid #e2e8f0;
  margin-bottom: 10px;
}
.preview-summary > div { display: flex; flex-direction: column; }
.preview-summary span { font-size: 12px; color: #64748b; }
.preview-summary strong { font-size: 15px; font-weight: 800; }
.text-rose { color: #e11d48; }

.preview-list { max-height: 220px; overflow-y: auto; }
.preview-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 12px;
  align-items: center;
  padding: 6px 0;
  font-size: 13px;
  border-bottom: 1px dashed #e2e8f0;
}
.preview-trip { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.preview-date { color: #64748b; }
.preview-price { display: flex; gap: 8px; align-items: baseline; }
.preview-price s { color: #94a3b8; }
.preview-price strong { color: #e11d48; font-weight: 800; }
</style>
