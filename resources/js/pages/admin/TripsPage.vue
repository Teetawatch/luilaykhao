<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">route</span> จัดการทริป</h1>
        <p class="page-subtitle">แคตตาล็อกทริปทั้งหมด — ราคา ความยาก และรอบที่เปิดขายอยู่</p>
      </div>
      <button class="btn-primary" @click="router.push({ name: 'admin-trip-create' })">
        <span class="material-symbols-rounded">add</span> เพิ่มทริปใหม่
      </button>
    </div>

    <!-- ยอดรวมทั้งชุด (ไม่ใช่แค่หน้านี้) — กดเพื่อกรองสถานะไปในตัว -->
    <div class="stat-strip">
      <button
        v-for="tile in statTiles"
        :key="tile.value"
        class="stat-tile"
        :class="{ active: filters.status === tile.value }"
        @click="setStatus(tile.value)"
      >
        <span class="stat-tile-icon" :class="`tone-${tile.tone}`">
          <span class="material-symbols-rounded">{{ tile.icon }}</span>
        </span>
        <span class="stat-tile-body">
          <span class="stat-tile-value">{{ tile.count }}</span>
          <span class="stat-tile-label">{{ tile.label }}</span>
        </span>
      </button>
    </div>

    <!-- ตัวกรอง -->
    <div class="toolbar-card">
      <div class="toolbar-row">
        <div class="search-box">
          <span class="material-symbols-rounded">search</span>
          <input v-model="filters.search" placeholder="ค้นหาชื่อทริปหรือสถานที่..." @input="debouncedFetch" />
        </div>

        <select v-model="filters.type" @change="fetchData()">
          <option value="">ทุกประเภท</option>
          <option v-for="cat in categoriesStore.categories" :key="cat.id" :value="cat.slug">
            {{ cat.name }}
          </option>
        </select>

        <select v-model="filters.sort" @change="fetchData()">
          <option value="newest">ใหม่ล่าสุด</option>
          <option value="oldest">เก่าสุด</option>
          <option value="title">ชื่อ ก–ฮ</option>
          <option value="price_low">ราคาน้อย → มาก</option>
          <option value="price_high">ราคามาก → น้อย</option>
          <option value="views">ยอดเข้าชมมากสุด</option>
        </select>

        <button
          class="chip-toggle"
          :class="{ active: filters.featured }"
          @click="filters.featured = !filters.featured; fetchData()"
        >
          <span class="material-symbols-rounded">star</span> เฉพาะที่แนะนำ
        </button>
      </div>

      <div class="toolbar-foot">
        <span class="result-count">
          <template v-if="admin.loading">กำลังโหลด...</template>
          <template v-else>พบ <strong>{{ meta.total || 0 }}</strong> ทริป</template>
        </span>
        <button v-if="hasActiveFilters" class="link-btn" @click="clearFilters">
          <span class="material-symbols-rounded">close</span> ล้างตัวกรอง
        </button>
      </div>
    </div>

    <!-- ตาราง -->
    <div class="table-card">
      <div class="table-container">
        <table class="data-table trips-table">
          <thead>
            <tr>
              <th class="col-trip">ทริป</th>
              <th>ปลายทาง</th>
              <th>โปรไฟล์</th>
              <th class="col-right">ราคา/คน</th>
              <th>รอบเดินทาง</th>
              <th>สถานะ</th>
              <th class="col-right">จัดการ</th>
            </tr>
          </thead>

          <tbody v-if="admin.loading">
            <tr v-for="n in 6" :key="`sk-${n}`" class="skeleton-row">
              <td>
                <div class="trip-cell">
                  <span class="sk sk-thumb"></span>
                  <div style="flex:1;">
                    <span class="sk sk-line" style="width:60%;"></span>
                    <span class="sk sk-line sk-sm" style="width:35%;"></span>
                  </div>
                </div>
              </td>
              <td><span class="sk sk-line" style="width:70%;"></span></td>
              <td><span class="sk sk-line" style="width:80%;"></span></td>
              <td><span class="sk sk-line" style="width:60%;margin-left:auto;"></span></td>
              <td><span class="sk sk-line" style="width:50%;"></span></td>
              <td><span class="sk sk-line" style="width:55%;"></span></td>
              <td><span class="sk sk-line" style="width:70%;margin-left:auto;"></span></td>
            </tr>
          </tbody>

          <tbody v-else-if="trips.length">
            <tr v-for="trip in trips" :key="trip.id">
              <td class="col-trip">
                <div class="trip-cell">
                  <img
                    :src="trip.thumbnail_image || trip.cover_image || '/images/placeholder.jpg'"
                    class="trip-thumb"
                    :alt="trip.title"
                  />
                  <div class="trip-cell-body">
                    <button class="trip-name-link" @click="editTrip(trip)" :title="trip.title">
                      {{ trip.title }}
                    </button>
                    <div class="tag-row">
                      <span class="type-tag" :class="`type-${trip.type}`">{{ getCategoryLabel(trip.type) }}</span>
                      <span v-if="trip.is_featured" class="mini-tag tag-featured">
                        <span class="material-symbols-rounded">star</span> แนะนำ
                      </span>
                      <span v-if="trip.is_women_only" class="mini-tag tag-women">
                        <span class="material-symbols-rounded">woman</span> หญิงล้วน
                      </span>
                    </div>
                  </div>
                </div>
              </td>

              <td>
                <div class="stack">
                  <span class="stack-main">{{ trip.location || '—' }}</span>
                  <span class="stack-sub" v-if="trip.is_international || trip.region">
                    <span v-if="trip.is_international" class="mini-tag tag-intl">
                      <span class="material-symbols-rounded">flight</span> ต่างประเทศ
                    </span>
                    {{ trip.is_international ? (trip.country_label || '') : regionLabel(trip.region) }}
                  </span>
                </div>
              </td>

              <td>
                <div class="stack">
                  <span class="stack-main">
                    {{ trip.duration_days }} วัน · สูงสุด {{ trip.max_participants }} คน
                  </span>
                  <span class="stack-sub">
                    <span class="diff-badge" :class="`diff-${trip.difficulty}`">{{ diffLabels[trip.difficulty] }}</span>
                    <span v-if="trip.views_count" class="views">
                      <span class="material-symbols-rounded">visibility</span> {{ formatCount(trip.views_count) }}
                    </span>
                  </span>
                </div>
              </td>

              <td class="col-right money">{{ formatMoney(trip.price_per_person) }}</td>

              <td>
                <button class="rounds-cell" @click="goToSchedules(trip)" title="ดูรอบเดินทางของทริปนี้">
                  <span class="rounds-count" :class="{ zero: !trip.open_schedules_count }">
                    {{ trip.open_schedules_count ?? 0 }}
                  </span>
                  <span class="rounds-total">เปิดขาย · ทั้งหมด {{ trip.schedules_count ?? 0 }}</span>
                </button>
              </td>

              <td><span class="status-badge" :class="`status-${trip.status}`">{{ statusLabels[trip.status] }}</span></td>

              <td class="col-right">
                <div class="action-btns">
                  <button
                    class="btn-icon btn-featured"
                    :class="{ active: trip.is_featured }"
                    @click="toggleFeatured(trip)"
                    :title="trip.is_featured ? 'ยกเลิกแนะนำ' : 'ตั้งเป็นแนะนำ'"
                  >
                    <span class="material-symbols-rounded">star</span>
                  </button>
                  <button class="btn-icon btn-edit" @click="editTrip(trip)" title="แก้ไข">
                    <span class="material-symbols-rounded">edit</span>
                  </button>
                  <button class="btn-icon btn-copy" @click="duplicateTrip(trip)" title="คัดลอกทริป">
                    <span class="material-symbols-rounded">content_copy</span>
                  </button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(trip)" title="ลบ">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>

          <tbody v-else>
            <tr>
              <td colspan="7">
                <div class="empty-block">
                  <span class="material-symbols-rounded">{{ hasActiveFilters ? 'search_off' : 'route' }}</span>
                  <p v-if="hasActiveFilters">ไม่พบทริปที่ตรงกับตัวกรองนี้</p>
                  <p v-else>ยังไม่มีทริปในระบบ</p>
                  <button v-if="hasActiveFilters" class="btn-secondary" @click="clearFilters">ล้างตัวกรอง</button>
                  <button v-else class="btn-primary" @click="router.push({ name: 'admin-trip-create' })">
                    <span class="material-symbols-rounded">add</span> เพิ่มทริปแรก
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="table-foot" v-if="meta.last_page > 1">
        <span class="page-range">{{ pageRangeLabel }}</span>
        <div class="pagination">
          <button :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <span class="page-info">{{ meta.current_page }} / {{ meta.last_page }}</span>
          <button :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="modal-overlay" v-if="showDeleteConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบทริป <strong>{{ deletingTrip?.title }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">
            <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
            ลบทริป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAdminStore } from '../../stores/admin';
import { useCategoriesStore } from '../../stores/categories';

const router = useRouter();
const admin = useAdminStore();
const categoriesStore = useCategoriesStore();

const filters = reactive({ search: '', type: '', status: '', sort: 'newest', featured: false });
const showDeleteConfirm = ref(false);
const deletingTrip = ref(null);
const submitting = ref(false);

const REGION_LABELS = {
  bangkok: 'กรุงเทพมหานคร',
  north: 'ภาคเหนือ',
  northeast: 'ภาคอีสาน',
  central: 'ภาคกลาง',
  east: 'ภาคตะวันออก',
  west: 'ภาคตะวันตก',
  south: 'ภาคใต้',
};

const diffLabels = { easy: 'ง่าย', medium: 'ปานกลาง', hard: 'ยาก' };
const statusLabels = { active: 'ใช้งาน', inactive: 'ปิด', full: 'เต็ม' };

const trips = computed(() => admin.trips.data || []);
const meta = computed(() => admin.trips.meta || {});
// ยอดรวมมาจากเซิร์ฟเวอร์ (ทั้งชุด ไม่ใช่แค่หน้านี้) — นับเองจากหน้าเดียวจะผิดทันทีที่เกิน 1 หน้า
const summary = computed(() => meta.value.summary || {});

const statTiles = computed(() => [
  { value: '', label: 'ทริปทั้งหมด', icon: 'route', tone: 'neutral', count: summary.value.total ?? 0 },
  { value: 'active', label: 'เปิดใช้งาน', icon: 'check_circle', tone: 'green', count: summary.value.active ?? 0 },
  { value: 'full', label: 'เต็มแล้ว', icon: 'groups', tone: 'amber', count: summary.value.full ?? 0 },
  { value: 'inactive', label: 'ปิดอยู่', icon: 'visibility_off', tone: 'grey', count: summary.value.inactive ?? 0 },
]);

const hasActiveFilters = computed(
  () => Boolean(filters.search || filters.type || filters.status || filters.featured || filters.sort !== 'newest'),
);

const pageRangeLabel = computed(() => {
  const { current_page: page, per_page: perPage, total } = meta.value;
  if (!page || !perPage || !total) return '';
  const from = (page - 1) * perPage + 1;

  return `แสดง ${from}–${Math.min(page * perPage, total)} จาก ${total}`;
});

const getCategoryLabel = (slug) => categoriesStore.categories.find((c) => c.slug === slug)?.name || slug;
const regionLabel = (region) => REGION_LABELS[region] || region || '';

const formatMoney = (amount) => new Intl.NumberFormat('th-TH', {
  style: 'currency', currency: 'THB', minimumFractionDigits: 0,
}).format(amount || 0);

const formatCount = (value) => new Intl.NumberFormat('th-TH').format(value || 0);

let debounceTimer = null;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(), 300);
};

const fetchData = (page = 1) => {
  admin.fetchTrips({
    search: filters.search,
    type: filters.type,
    status: filters.status,
    sort: filters.sort,
    featured: filters.featured ? 1 : '',
    page,
  });
};

const goPage = (page) => fetchData(page);

const setStatus = (value) => {
  filters.status = filters.status === value ? '' : value;
  fetchData();
};

const clearFilters = () => {
  Object.assign(filters, { search: '', type: '', status: '', sort: 'newest', featured: false });
  fetchData();
};

const editTrip = (trip) => router.push({ name: 'admin-trip-edit', params: { id: trip.id } });

// เปิดหน้ารอบเดินทางโดยเจาะเข้าทริปนี้เลย — เดิมต้องไปเปิดหน้านั้นแล้วค้นหาชื่อทริปเอง
const goToSchedules = (trip) => router.push({ name: 'admin-schedules', query: { trip: trip.id } });

const duplicateTrip = async (trip) => {
  if (!confirm(`คุณต้องการคัดลอกทริป "${trip.title}" ใช่หรือไม่?`)) return;
  submitting.value = true;
  try {
    const newTrip = {
      ...trip,
      title: `${trip.title} (สำเนา)`,
      status: 'inactive',
      is_featured: false,
    };
    delete newTrip.id;
    delete newTrip.created_at;
    delete newTrip.updated_at;

    await admin.createTrip(newTrip);
    fetchData(meta.value.current_page || 1);
  } catch (e) {
    alert('คัดลอกทริปล้มเหลว');
  } finally {
    submitting.value = false;
  }
};

const toggleFeatured = async (trip) => {
  try {
    await admin.updateTrip(trip.id, { ...trip, is_featured: !trip.is_featured });
    trip.is_featured = !trip.is_featured;
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  }
};

const confirmDelete = (trip) => {
  deletingTrip.value = trip;
  showDeleteConfirm.value = true;
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await admin.deleteTrip(deletingTrip.value.id);
    showDeleteConfirm.value = false;
    fetchData(meta.value.current_page || 1);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  fetchData();
  categoriesStore.fetchAdminCategories();
});
</script>

<style scoped>
@import url('./admin-shared.css');

/* ─── แถบยอดรวม (กดกรองสถานะได้) ───────── */
.stat-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}

.stat-tile {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s, background 0.15s;
}

.stat-tile:hover {
  border-color: #d1d5db;
  background: #FAFAFA;
}

.stat-tile.active {
  border-color: var(--color-accent);
  background: rgba(45, 122, 79, 0.04);
}

.stat-tile-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 10px;
  flex-shrink: 0;
}

.stat-tile-icon .material-symbols-rounded {
  font-size: 20px;
}

.tone-neutral { background: rgba(45, 122, 79, 0.08); color: var(--color-accent); }
.tone-green   { background: #F5F5F5; color: #15803d; }
.tone-amber   { background: #fef9c3; color: #a16207; }
.tone-grey    { background: #EEEEEE; color: #6b7280; }

.stat-tile-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.stat-tile-value {
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  line-height: 1.2;
}

.stat-tile-label {
  font-size: 12px;
  color: #6b7280;
}

/* ─── แถบตัวกรอง ────────────────────────── */
.toolbar-card {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 14px 16px;
  margin-bottom: 16px;
}

.toolbar-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
}

.toolbar-row select {
  padding: 9px 14px;
  background: #ffffff;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  color: #111827;
  font-size: 14px;
  outline: none;
  cursor: pointer;
  min-width: 150px;
  transition: border-color 0.15s;
}

.toolbar-row select:focus {
  border-color: var(--color-accent);
}

.chip-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 14px;
  background: #ffffff;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  color: #6b7280;
  cursor: pointer;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}

.chip-toggle .material-symbols-rounded { font-size: 18px; }

.chip-toggle:hover { border-color: var(--color-gold); color: var(--color-gold); }

.chip-toggle.active {
  border-color: var(--color-gold);
  background: rgba(200, 150, 62, 0.08);
  color: var(--color-gold-dark);
  font-weight: 700;
}

.toolbar-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #EEEEEE;
}

.result-count {
  font-size: 13px;
  color: #6b7280;
}

.result-count strong { color: #111827; }

.link-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: none;
  border: none;
  padding: 0;
  font-size: 13px;
  color: var(--color-accent);
  font-weight: 700;
  cursor: pointer;
}

.link-btn .material-symbols-rounded { font-size: 16px; }

/* ─── ตาราง ─────────────────────────────── */
.trips-table .col-trip { min-width: 280px; }
.trips-table .col-right { text-align: right; }
.trips-table td { vertical-align: middle; }

.trip-cell-body { min-width: 0; }

.trip-name-link {
  display: block;
  background: none;
  border: none;
  padding: 0;
  font-size: 14px;
  font-weight: 700;
  color: #111827;
  text-align: left;
  cursor: pointer;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.trip-name-link:hover { color: var(--color-accent); }

.tag-row {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
  margin-top: 5px;
}

.mini-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 7px;
  border-radius: 5px;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}

.mini-tag .material-symbols-rounded { font-size: 13px; }

.tag-featured { background: rgba(200, 150, 62, 0.12); color: var(--color-gold-dark); }
.tag-women    { background: #fdf2f8; color: #db2777; }
.tag-intl     { background: #e0f2fe; color: #0369a1; }

.stack { display: flex; flex-direction: column; gap: 4px; }

.stack-main {
  font-size: 13.5px;
  color: #374151;
  font-weight: 600;
}

.stack-sub {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #6b7280;
}

.views {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.views .material-symbols-rounded { font-size: 14px; }

.money { font-weight: 700; color: #111827; white-space: nowrap; }

/* ─── รอบเดินทาง ────────────────────────── */
.rounds-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
  background: none;
  border: none;
  padding: 0;
  text-align: left;
  cursor: pointer;
}

.rounds-count {
  font-size: 16px;
  font-weight: 700;
  color: var(--color-accent);
  line-height: 1.2;
}

.rounds-count.zero { color: #b91c1c; }

.rounds-total {
  font-size: 11.5px;
  color: #6b7280;
  white-space: nowrap;
}

.rounds-cell:hover .rounds-total { color: var(--color-accent); text-decoration: underline; }

/* ─── ปุ่มในแถว ─────────────────────────── */
.action-btns { justify-content: flex-end; }

.btn-copy { color: #2563eb; }
.btn-copy:hover { background: #eff6ff; border-color: #93c5fd; }

.btn-featured { color: #d1d5db; }
.btn-featured:hover {
  background: rgba(200, 150, 62, 0.06);
  border-color: rgba(200, 150, 62, 0.35);
  color: var(--color-gold);
}
.btn-featured.active { color: var(--color-gold); }
.btn-featured.active:hover { border-color: var(--color-gold); }

.btn-icon .material-symbols-rounded { font-size: 17px; }

/* ─── สถานะว่าง / กำลังโหลด ─────────────── */
.empty-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 52px 20px;
  color: #6b7280;
}

.empty-block .material-symbols-rounded {
  font-size: 40px;
  color: #d1d5db;
}

.empty-block p { margin: 0; font-size: 14px; }

.skeleton-row td { vertical-align: middle; }
.skeleton-row:hover td { background: transparent; }

.sk {
  display: block;
  border-radius: 6px;
  background: linear-gradient(90deg, #EEEEEE 25%, #F5F5F5 50%, #EEEEEE 75%);
  background-size: 200% 100%;
  animation: skPulse 1.2s ease-in-out infinite;
}

.sk-thumb { width: 44px; height: 44px; border-radius: 8px; flex-shrink: 0; }
.sk-line { height: 11px; }
.sk-line + .sk-line { margin-top: 7px; }
.sk-sm { height: 9px; }

@keyframes skPulse {
  from { background-position: 200% 0; }
  to { background-position: -200% 0; }
}

/* ─── ท้ายตาราง ─────────────────────────── */
.table-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 20px;
  border-top: 1px solid #EEEEEE;
  flex-wrap: wrap;
}

.page-range {
  font-size: 12.5px;
  color: #6b7280;
}

.table-foot .pagination { margin: 0; padding: 0; border-top: none; }

@media (max-width: 1024px) {
  .stat-strip { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
  .toolbar-row select,
  .chip-toggle { flex: 1; min-width: 0; }
}
</style>
