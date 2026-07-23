<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">landscape</span> สถานที่</h1>
        <p class="page-subtitle">
          ข้อมูลภูเขา/เกาะ/อุทยาน ที่อยู่ต่อได้แม้ทริปปิดขาย — เป็นฐานของหน้า /places และปฏิทิน /seasons
        </p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <span class="material-symbols-rounded">add</span> เพิ่มสถานที่
      </button>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

      <div class="empty-state" v-else-if="!places.length">
        <span class="material-symbols-rounded">landscape</span>
        <p>ยังไม่มีสถานที่ — เพิ่มที่แรกเพื่อให้หน้า "เดือนไหนไปไหนดี" มีข้อมูลแสดง</p>
      </div>

      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>สถานที่</th>
              <th>ภาค</th>
              <th>ช่วงที่ควรไป</th>
              <th>ทริปที่ผูก</th>
              <th>สถานะ</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="place in places" :key="place.id">
              <td>
                <div class="trip-cell">
                  <img :src="place.cover_image || '/images/placeholder.jpg'" class="trip-thumb" />
                  <div>
                    <span class="trip-name">{{ place.name }}</span>
                    <div class="muted-sub">
                      {{ typeLabel(place.type) }}<span v-if="place.province"> · {{ place.province }}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td>{{ regionLabel(place.region) }}</td>
              <td>{{ monthsLabel(place.best_months) }}</td>
              <td>{{ place.trips_count ?? 0 }}</td>
              <td>
                <span class="status-badge" :class="place.status === 'published' ? 'status-active' : 'status-inactive'">
                  {{ place.status === 'published' ? 'เผยแพร่แล้ว' : 'ฉบับร่าง' }}
                </span>
              </td>
              <td>
                <div class="action-btns">
                  <a
                    v-if="place.status === 'published'"
                    class="btn-icon"
                    :href="'/places/' + encodeURIComponent(place.slug)"
                    target="_blank"
                    title="ดูหน้าเว็บ"
                  >
                    <span class="material-symbols-rounded">open_in_new</span>
                  </a>
                  <button class="btn-icon" title="แก้ไข" @click="openEdit(place)">
                    <span class="material-symbols-rounded">edit</span>
                  </button>
                  <button class="btn-icon btn-danger" title="ลบ" @click="confirmDelete(place)">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ฟอร์ม -->
    <div class="modal-overlay" v-if="showForm" @click.self="showForm = false">
      <div class="modal-card" style="max-width:760px">
        <div class="modal-header">
          <h3>{{ form.id ? 'แก้ไขสถานที่' : 'เพิ่มสถานที่' }}</h3>
          <button class="modal-close" @click="showForm = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <form @submit.prevent="submit" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อสถานที่ *</label>
              <input v-model="form.name" required placeholder="เช่น ภูกระดึง" />
            </div>

            <div class="form-group">
              <label>ประเภท</label>
              <select v-model="form.type">
                <option v-for="t in TYPES" :key="t.key" :value="t.key">{{ t.label }}</option>
              </select>
            </div>

            <div class="form-group">
              <label>ภูมิภาค</label>
              <select v-model="form.region">
                <option value="">— ไม่ระบุ —</option>
                <option v-for="r in REGIONS" :key="r.key" :value="r.key">{{ r.label }}</option>
              </select>
            </div>

            <div class="form-group">
              <label>จังหวัด</label>
              <input v-model="form.province" placeholder="เช่น เลย" />
            </div>

            <div class="form-group">
              <label>อุทยาน / เขตที่สังกัด</label>
              <input v-model="form.park" placeholder="เช่น อุทยานแห่งชาติภูกระดึง" />
            </div>

            <div class="form-group">
              <label>ละติจูด</label>
              <input v-model="form.latitude" type="number" step="any" placeholder="16.8721" />
            </div>

            <div class="form-group">
              <label>ลองจิจูด</label>
              <input v-model="form.longitude" type="number" step="any" placeholder="101.8103" />
            </div>

            <div class="form-group">
              <label>ความสูงจากระดับน้ำทะเล (ม.)</label>
              <input v-model="form.elevation_m" type="number" min="0" />
            </div>

            <div class="form-group">
              <label>ระยะเดินรวม (กม.)</label>
              <input v-model="form.trail_distance_km" type="number" step="0.1" min="0" />
            </div>

            <div class="form-group">
              <label>ความสูงที่ต้องไต่ (ม.)</label>
              <input v-model="form.elevation_gain_m" type="number" min="0" />
            </div>

            <div class="form-group">
              <label>ระดับความยาก</label>
              <select v-model="form.difficulty">
                <option value="">— ไม่ระบุ —</option>
                <option v-for="d in DIFFICULTIES" :key="d.key" :value="d.key">{{ d.label }}</option>
              </select>
            </div>

            <!-- เดือน -->
            <div class="form-group full-width">
              <label>เดือนที่ควรไป <span style="font-weight:400;color:#9ca3af">(ขึ้นในปฏิทิน /seasons)</span></label>
              <div class="month-grid">
                <button
                  v-for="(name, i) in THAI_MONTHS_SHORT"
                  :key="i"
                  type="button"
                  class="month-chip"
                  :class="{ 'month-chip--best': form.best_months.includes(i + 1) }"
                  @click="toggleMonth('best_months', i + 1)"
                >{{ name }}</button>
              </div>
            </div>

            <div class="form-group full-width">
              <label>เดือนที่ปิด <span style="font-weight:400;color:#9ca3af">(ทับเดือนที่ควรไปเสมอ)</span></label>
              <div class="month-grid">
                <button
                  v-for="(name, i) in THAI_MONTHS_SHORT"
                  :key="i"
                  type="button"
                  class="month-chip"
                  :class="{ 'month-chip--closed': form.closed_months.includes(i + 1) }"
                  @click="toggleMonth('closed_months', i + 1)"
                >{{ name }}</button>
              </div>
            </div>

            <div class="form-group full-width">
              <label>หมายเหตุเรื่องฤดูกาล</label>
              <textarea v-model="form.season_note" rows="2" placeholder="เช่น ใบเมเปิลแดงช่วงปลาย ธ.ค. ถึงต้น ม.ค."></textarea>
            </div>

            <div class="form-group full-width">
              <label>หมายเหตุเรื่องการปิด</label>
              <textarea v-model="form.closure_note" rows="2" placeholder="เช่น ปิดฟื้นฟูธรรมชาติ 1 มิ.ย. – 30 ก.ย. ทุกปี"></textarea>
            </div>

            <div class="form-group full-width">
              <label>สรุปสั้น (ขึ้นบนการ์ด)</label>
              <textarea v-model="form.summary" rows="2" maxlength="500"></textarea>
            </div>

            <div class="form-group full-width">
              <label>รายละเอียด</label>
              <textarea v-model="form.description" rows="6"></textarea>
            </div>

            <div class="form-group full-width">
              <label>ไฮไลต์ <span style="font-weight:400;color:#9ca3af">(บรรทัดละ 1 ข้อ)</span></label>
              <textarea v-model="highlightsText" rows="4"></textarea>
            </div>

            <div class="form-group full-width">
              <label>ต้องรู้ก่อนไป <span style="font-weight:400;color:#9ca3af">(บรรทัดละ 1 ข้อ)</span></label>
              <textarea v-model="knowBeforeText" rows="4"></textarea>
            </div>

            <div class="form-group full-width">
              <label>URL ภาพหน้าปก</label>
              <input v-model="form.cover_image" placeholder="https://..." />
            </div>

            <div class="form-group full-width">
              <label>อัลบั้มภาพ <span style="font-weight:400;color:#9ca3af">(URL บรรทัดละ 1 รูป)</span></label>
              <textarea v-model="galleryText" rows="3"></textarea>
            </div>

            <div class="form-group full-width">
              <label>ทริปที่พาไปที่นี่</label>
              <select v-model="form.trip_ids" multiple size="6">
                <option v-for="trip in trips" :key="trip.id" :value="trip.id">{{ trip.title }}</option>
              </select>
              <p style="font-size:12px;color:#9ca3af;margin-top:4px">กด Ctrl/Cmd ค้างเพื่อเลือกหลายทริป</p>
            </div>

            <div class="form-group">
              <label>สถานะ</label>
              <select v-model="form.status">
                <option value="draft">ฉบับร่าง</option>
                <option value="published">เผยแพร่</option>
              </select>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';
import { THAI_MONTHS_SHORT } from '../../lib/thaiDate';

const TYPES = [
  { key: 'mountain', label: 'ภูเขา / ยอดดอย' },
  { key: 'national_park', label: 'อุทยานแห่งชาติ' },
  { key: 'island', label: 'เกาะ / ทะเล' },
  { key: 'waterfall', label: 'น้ำตก' },
  { key: 'viewpoint', label: 'จุดชมวิว' },
  { key: 'other', label: 'อื่น ๆ' },
];

const REGIONS = [
  { key: 'north', label: 'ภาคเหนือ' },
  { key: 'northeast', label: 'ภาคอีสาน' },
  { key: 'central', label: 'ภาคกลาง' },
  { key: 'east', label: 'ภาคตะวันออก' },
  { key: 'west', label: 'ภาคตะวันตก' },
  { key: 'south', label: 'ภาคใต้' },
];

const DIFFICULTIES = [
  { key: 'easy', label: 'สายชิล' },
  { key: 'medium', label: 'ปานกลาง' },
  { key: 'hard', label: 'สายโหด' },
];

const toast = useToast();
const swal = useSwal();

const loading = ref(true);
const saving = ref(false);
const places = ref([]);
const trips = ref([]);
const showForm = ref(false);

const form = ref(emptyForm());

/** ฟิลด์ที่เก็บเป็น array แต่กรอกสะดวกกว่าในรูปข้อความหลายบรรทัด */
const highlightsText = linesProxy('highlights');
const knowBeforeText = linesProxy('know_before');
const galleryText = linesProxy('gallery');

function linesProxy(key) {
  return computed({
    get: () => (form.value[key] || []).join('\n'),
    set: (value) => {
      form.value[key] = value.split('\n').map(l => l.trim()).filter(Boolean);
    },
  });
}

function emptyForm() {
  return {
    id: null,
    name: '',
    type: 'mountain',
    region: '',
    province: '',
    park: '',
    latitude: '',
    longitude: '',
    elevation_m: '',
    trail_distance_km: '',
    elevation_gain_m: '',
    difficulty: '',
    best_months: [],
    closed_months: [],
    season_note: '',
    closure_note: '',
    summary: '',
    description: '',
    highlights: [],
    know_before: [],
    cover_image: '',
    gallery: [],
    status: 'draft',
    trip_ids: [],
  };
}

function typeLabel(key) {
  return TYPES.find(t => t.key === key)?.label || key || '—';
}

function regionLabel(key) {
  return REGIONS.find(r => r.key === key)?.label || '—';
}

function monthsLabel(months) {
  if (!months?.length) return '—';
  return months.map(m => THAI_MONTHS_SHORT[m - 1]).join(' ');
}

function toggleMonth(key, month) {
  const list = form.value[key];
  const index = list.indexOf(month);
  if (index >= 0) list.splice(index, 1);
  else list.push(month);
  list.sort((a, b) => a - b);
}

function openCreate() {
  form.value = emptyForm();
  showForm.value = true;
}

async function openEdit(place) {
  const res = await api.get(`/admin/places/${place.id}`);
  const data = res.data?.data || {};

  form.value = {
    ...emptyForm(),
    ...data,
    // API คืน null สำหรับฟิลด์ว่าง แต่ input ต้องการสตริง/อาเรย์เสมอ
    region: data.region || '',
    difficulty: data.difficulty || '',
    best_months: data.best_months || [],
    closed_months: data.closed_months || [],
    highlights: data.highlights || [],
    know_before: data.know_before || [],
    gallery: data.gallery || [],
    trip_ids: (data.trips || []).map(t => t.id),
  };

  showForm.value = true;
}

function payload() {
  const body = { ...form.value };
  delete body.id;
  delete body.trips;
  delete body.created_at;
  delete body.updated_at;
  delete body.views_count;
  delete body.trips_count;

  // ส่ง null แทนสตริงว่าง เพื่อให้ nullable validation ผ่านและ DB เก็บ null จริง
  ['region', 'difficulty', 'province', 'park', 'season_note', 'closure_note',
    'summary', 'description', 'cover_image', 'slug'].forEach((key) => {
    if (body[key] === '') body[key] = null;
  });

  ['latitude', 'longitude', 'elevation_m', 'trail_distance_km', 'elevation_gain_m'].forEach((key) => {
    body[key] = body[key] === '' || body[key] === null ? null : Number(body[key]);
  });

  return body;
}

async function submit() {
  saving.value = true;
  try {
    if (form.value.id) {
      await api.put(`/admin/places/${form.value.id}`, payload());
      toast.success('อัปเดตสถานที่แล้ว');
    } else {
      await api.post('/admin/places', payload());
      toast.success('สร้างสถานที่แล้ว');
    }
    showForm.value = false;
    await fetchPlaces();
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function confirmDelete(place) {
  const result = await swal.confirm({
    title: `ลบ "${place.name}"?`,
    text: 'ข้อมูลสถานที่นี้จะหายถาวร ทริปที่ผูกไว้ไม่ถูกลบ',
    confirmText: 'ลบ',
  });
  if (!result.isConfirmed) return;

  try {
    await api.delete(`/admin/places/${place.id}`);
    toast.success('ลบสถานที่แล้ว');
    await fetchPlaces();
  } catch (err) {
    toast.error(err.response?.data?.message || 'ลบไม่สำเร็จ');
  }
}

async function fetchPlaces() {
  loading.value = true;
  try {
    const res = await api.get('/admin/places');
    places.value = res.data?.data || [];
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  await fetchPlaces();

  try {
    const res = await api.get('/trips', { params: { per_page: 200 } });
    trips.value = (res.data?.data || []).map(t => ({ id: t.id, title: t.title }));
  } catch {
    trips.value = [];
  }
});
</script>

<style scoped>
.month-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 6px;
}

.month-chip {
  padding: 8px 4px;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  background: #fff;
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  cursor: pointer;
}

.month-chip--best {
  background: #006565;
  border-color: #006565;
  color: #fff;
}

.month-chip--closed {
  background: #fee4e2;
  border-color: #fda29b;
  color: #b42318;
}
</style>
