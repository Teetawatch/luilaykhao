<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">airport_shuttle</span> บริการรถตู้</h1>
        <p class="page-subtitle">จัดการทริปบริการรถตู้ VIP นำเที่ยวทั้งหมด</p>
      </div>
      <button class="btn-primary" @click="router.push({ name: 'admin-van-trip-create' })">
        <span class="material-symbols-rounded">add</span> เพิ่มบริการรถตู้ใหม่
      </button>
    </div>

    <!-- Stats Summary -->
    <div class="van-stats">
      <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">airport_shuttle</span></div>
        <div class="stat-info">
          <span class="stat-value">{{ vanTrips.length }}</span>
          <span class="stat-label">บริการรถตู้ทั้งหมด</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon active"><span class="material-symbols-rounded">check_circle</span></div>
        <div class="stat-info">
          <span class="stat-value">{{ vanTrips.filter(t => t.status === 'active').length }}</span>
          <span class="stat-label">เปิดให้บริการ</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon featured"><span class="material-symbols-rounded">star</span></div>
        <div class="stat-info">
          <span class="stat-value">{{ vanTrips.filter(t => t.is_featured).length }}</span>
          <span class="stat-label">แนะนำ</span>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="filters.search" placeholder="ค้นหาบริการรถตู้..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.status" @change="fetchData()">
        <option value="">ทุกสถานะ</option>
        <option value="active">เปิดให้บริการ</option>
        <option value="inactive">ปิด</option>
        <option value="full">เต็ม</option>
      </select>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="loading">
        <div class="spinner"></div>
      </div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>บริการรถตู้</th>
              <th>เส้นทาง</th>
              <th>ราคา</th>
              <th>ระยะเวลา</th>
              <th>จำนวนคน</th>
              <th>สถานะ</th>
              <th>แนะนำ</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="trip in vanTrips" :key="trip.id">
              <td>
                <div class="trip-cell">
                  <img :src="trip.cover_image || '/images/placeholder.jpg'" class="trip-thumb" />
                  <div>
                    <span class="trip-name">{{ trip.title }}</span>
                    <div class="trip-badges">
                      <span class="van-badge">
                        <span class="material-symbols-rounded" style="font-size:inherit; vertical-align:middle;">airport_shuttle</span> รถตู้ VIP
                      </span>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <div class="route-info">
                  <span class="material-symbols-rounded route-icon">place</span>
                  <span>{{ trip.location || '—' }}</span>
                </div>
              </td>
              <td class="money">{{ formatMoney(trip.price_per_person) }}</td>
              <td>
                <span class="duration-badge">
                  <span class="material-symbols-rounded" style="font-size:14px;">schedule</span>
                  {{ trip.duration_days }} วัน
                </span>
              </td>
              <td>
                <span class="capacity-badge">
                  <span class="material-symbols-rounded" style="font-size:14px;">groups</span>
                  {{ trip.max_participants }} คน
                </span>
              </td>
              <td><span class="status-badge" :class="`status-${trip.status}`">{{ statusLabels[trip.status] }}</span></td>
              <td>
                <button
                  class="btn-icon btn-featured"
                  :class="{ active: trip.is_featured }"
                  @click="toggleFeatured(trip)"
                  :title="trip.is_featured ? 'ยกเลิกแนะนำ' : 'ตั้งเป็นแนะนำ'"
                >
                  <span class="material-symbols-rounded" style="font-size:inherit; vertical-align:middle;">star</span>
                </button>
              </td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-edit" @click="router.push({ name: 'admin-van-trip-edit', params: { id: trip.id } })" title="แก้ไข">
                    <span class="material-symbols-rounded" style="font-size:16px;">edit</span>
                  </button>
                  <button class="btn-icon btn-copy" @click="duplicateTrip(trip)" title="คัดลอก">
                    <span class="material-symbols-rounded" style="font-size:16px;">content_copy</span>
                  </button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(trip)" title="ลบ">
                    <span class="material-symbols-rounded" style="font-size:16px;">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!vanTrips.length && !loading">
              <td colspan="8" class="empty-state">
                <div class="empty-van">
                  <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;">airport_shuttle</span>
                  <p>ไม่พบข้อมูลบริการรถตู้</p>
                  <button class="btn-primary" @click="router.push({ name: 'admin-van-trip-create' })">
                    <span class="material-symbols-rounded">add</span> เพิ่มบริการรถตู้ใหม่
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="pagination" v-if="totalPages > 1">
        <button :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">
          <span class="material-symbols-rounded">chevron_left</span>
        </button>
        <span class="page-info">{{ currentPage }} / {{ totalPages }}</span>
        <button :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">
          <span class="material-symbols-rounded">chevron_right</span>
        </button>
      </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="modal-overlay" v-if="showDeleteConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded" style="font-size:inherit; vertical-align:middle;">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบบริการรถตู้ <strong>{{ deletingTrip?.title }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded" style="font-size:inherit; vertical-align:middle;">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">
            <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
            ลบบริการ
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
import api from '../../lib/axios';

const router = useRouter();
const admin = useAdminStore();

const filters = reactive({ search: '', status: '' });
const showDeleteConfirm = ref(false);
const deletingTrip = ref(null);
const submitting = ref(false);
const loading = ref(false);
const allVanTrips = ref([]);
const currentPage = ref(1);
const totalPages = ref(1);

// Van category slugs — the system uses "climbing" slug for van/shuttle service
const VAN_CATEGORY_SLUGS = ['climbing', 'van-service', 'van', 'shuttle'];

const statusLabels = { active: 'เปิดให้บริการ', inactive: 'ปิด', full: 'เต็ม' };

const formatMoney = (amount) => new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);

const vanTrips = computed(() => {
  let trips = allVanTrips.value || [];
  if (filters.search) {
    const q = filters.search.toLowerCase();
    trips = trips.filter(t =>
      t.title?.toLowerCase().includes(q) ||
      t.location?.toLowerCase().includes(q)
    );
  }
  if (filters.status) {
    trips = trips.filter(t => t.status === filters.status);
  }
  return trips;
});

let debounceTimer = null;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {}, 300);
};

const fetchData = async (page = 1) => {
  loading.value = true;
  try {
    const res = await api.get('/admin/trips', { params: { per_page: 100, page } });
    const all = res.data.data || [];
    // Filter trips that are van/shuttle service type
    allVanTrips.value = all.filter(t => VAN_CATEGORY_SLUGS.includes(t.type));
    currentPage.value = res.data.meta?.current_page || 1;
    totalPages.value = res.data.meta?.last_page || 1;
  } catch (e) {
    console.error('Failed to fetch van trips', e);
  } finally {
    loading.value = false;
  }
};

const goPage = (page) => fetchData(page);

const duplicateTrip = async (trip) => {
  if (!confirm(`คุณต้องการคัดลอกบริการรถตู้ "${trip.title}" ใช่หรือไม่?`)) return;
  submitting.value = true;
  try {
    const newTrip = {
      ...trip,
      title: `${trip.title} (สำเนา)`,
      status: 'inactive',
      is_featured: false
    };
    delete newTrip.id;
    delete newTrip.created_at;
    delete newTrip.updated_at;

    await admin.createTrip(newTrip);
    fetchData();
  } catch (e) {
    alert('คัดลอกบริการรถตู้ล้มเหลว');
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
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  fetchData();
});
</script>

<style scoped>
@import url('./admin-shared.css');

/* ─── Stats Cards ────────────────────── */
.van-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}

.stat-card {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px;
  background: #ffffff;
  border-radius: 14px;
  border: 1px solid #e5e7eb;
  transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #0369a1;
  flex-shrink: 0;
}

.stat-icon .material-symbols-rounded {
  font-size: 24px;
}

.stat-icon.active {
  background: linear-gradient(135deg, #f0faf4, #dcfce7);
  color: #15803d;
}

.stat-icon.featured {
  background: linear-gradient(135deg, #fffbeb, #fef3c7);
  color: #b45309;
}

.stat-info {
  display: flex;
  flex-direction: column;
}

.stat-value {
  font-size: 28px;
  font-weight: 800;
  color: #111827;
  line-height: 1;
}

.stat-label {
  font-size: 13px;
  color: #6b7280;
  margin-top: 2px;
}

/* ─── Van Badge ──────────────────────── */
.van-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  color: #92400e;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 800;
  border: 1px solid #fcd34d;
  width: fit-content;
}

/* ─── Route Info ─────────────────────── */
.route-info {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #374151;
}

.route-icon {
  font-size: 16px;
  color: #2d7a4f;
}

/* ─── Duration & Capacity Badges ─────── */
.duration-badge,
.capacity-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  background: #f3f4f6;
  color: #374151;
}

/* ─── Copy Button ────────────────────── */
.btn-copy {
  color: #2563eb;
}
.btn-copy:hover {
  background: #eff6ff;
  border-color: #93c5fd;
}

/* ─── Featured Button ───────────────── */
.btn-featured {
  color: #d1d5db;
}
.btn-featured:hover {
  background: rgba(245, 158, 11, 0.05);
  border-color: rgba(245, 158, 11, 0.3);
  color: var(--color-gold);
}
.btn-featured.active {
  color: var(--color-gold);
}
.btn-featured.active:hover {
  background: rgba(217, 119, 6, 0.1);
  border-color: var(--color-gold);
  color: var(--color-gold);
}

/* ─── Table Badges ───────────────────── */
.trip-badges {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 4px;
}

/* ─── Empty State ────────────────────── */
.empty-van {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 20px 0;
}

.empty-van p {
  margin: 0;
  font-size: 15px;
  color: #9ca3af;
}

/* ─── Responsive ─────────────────────── */
@media (max-width: 768px) {
  .van-stats {
    grid-template-columns: 1fr;
  }
}
</style>
