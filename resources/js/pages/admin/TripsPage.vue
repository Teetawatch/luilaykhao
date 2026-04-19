<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">route</span> จัดการทริป</h1>
        <p class="page-subtitle">จัดการทริปและกิจกรรมทั้งหมด</p>
      </div>
      <button class="btn-primary" @click="router.push({ name: 'admin-trip-create' })">
        <span class="material-symbols-rounded">add</span> เพิ่มทริปใหม่
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="filters.search" placeholder="ค้นหาทริป..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.type" @change="fetchData()">
        <option value="">ทุกประเภท</option>
        <option v-for="cat in categoriesStore.categories" :key="cat.id" :value="cat.slug">
          {{ cat.name }}
        </option>
      </select>
      <select v-model="filters.status" @change="fetchData()">
        <option value="">ทุกสถานะ</option>
        <option value="active">ใช้งาน</option>
        <option value="inactive">ปิด</option>
        <option value="full">เต็ม</option>
      </select>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="loading-state" v-if="admin.loading">
        <div class="spinner"></div>
      </div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>ทริป</th>
              <th>ประเภท</th>
              <th>สถานที่</th>
              <th>ราคา</th>
              <th>ความยาก</th>
              <th>สถานะ</th>
              <th>แนะนำ</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="trip in admin.trips.data" :key="trip.id">
              <td>
                <div class="trip-cell">
                  <img :src="trip.cover_image || '/images/placeholder.jpg'" class="trip-thumb" />
                  <div>
                    <span class="trip-name">{{ trip.title }}</span>
                    <div class="trip-badges">
                      <span v-if="trip.is_women_only" class="badge-women"><span class="material-symbols-rounded" style="font-size:inherit; vertical-align:middle;">woman</span> หญิงล้วน</span>
                      <span class="trip-duration">{{ trip.duration_days }} วัน · สูงสุด {{ trip.max_participants }} คน</span>
                    </div>
                  </div>
                </div>
              </td>
              <td><span class="type-tag" :class="`type-${trip.type}`">{{ getCategoryLabel(trip.type) }}</span></td>
              <td>{{ trip.location }}</td>
              <td class="money">{{ formatMoney(trip.price_per_person) }}</td>
              <td><span class="diff-badge" :class="`diff-${trip.difficulty}`">{{ diffLabels[trip.difficulty] }}</span></td>
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
                  <button class="btn-icon btn-edit" @click="router.push({ name: 'admin-trip-edit', params: { id: trip.id } })" title="แก้ไข">
                    <span class="material-symbols-rounded" style="font-size:16px;">edit</span>
                  </button>
                  <button class="btn-icon btn-copy" @click="duplicateTrip(trip)" title="คัดลอกทริป">
                    <span class="material-symbols-rounded" style="font-size:16px;">content_copy</span>
                  </button>
                  <button class="btn-icon btn-delete" @click="confirmDelete(trip)" title="ลบ">
                    <span class="material-symbols-rounded" style="font-size:16px;">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!admin.trips.data?.length">
              <td colspan="8" class="empty-state">ไม่พบข้อมูลทริป</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="pagination" v-if="admin.trips.meta?.last_page > 1">
        <button :disabled="admin.trips.meta.current_page <= 1" @click="goPage(admin.trips.meta.current_page - 1)">
          <span class="material-symbols-rounded">chevron_left</span>
        </button>
        <span class="page-info">{{ admin.trips.meta.current_page }} / {{ admin.trips.meta.last_page }}</span>
        <button :disabled="admin.trips.meta.current_page >= admin.trips.meta.last_page" @click="goPage(admin.trips.meta.current_page + 1)">
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
          <p class="confirm-text">คุณต้องการลบทริป <strong>{{ deletingTrip?.title }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded" style="font-size:inherit; vertical-align:middle;">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
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
import api from '../../lib/axios';

const router = useRouter();
const admin = useAdminStore();
const categoriesStore = useCategoriesStore();

const filters = reactive({ search: '', type: '', status: '' });
const showDeleteConfirm = ref(false);
const deletingTrip = ref(null);
const submitting = ref(false);


const getCategoryLabel = (slug) => {
  const cat = categoriesStore.categories.find(c => c.slug === slug);
  return cat ? cat.name : slug;
};

const diffLabels = { easy: 'ง่าย', medium: 'ปานกลาง', hard: 'ยาก' };
const statusLabels = { active: 'ใช้งาน', inactive: 'ปิด', full: 'เต็ม' };

const formatMoney = (amount) => new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);

let debounceTimer = null;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(), 300);
};

const fetchData = (page = 1) => {
  admin.fetchTrips({ ...filters, page });
};

const goPage = (page) => fetchData(page);

// ─── Form Methods ────────────────────────────

const duplicateTrip = async (trip) => {
  if (!confirm(`คุณต้องการคัดลอกทริป "${trip.title}" ใช่หรือไม่?`)) return;
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
    fetchData();
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

.badge-women {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #fdf2f8;
  color: #db2777;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 800;
  border: 1px solid #f9a8d4;
  width: fit-content;
}
</style>
