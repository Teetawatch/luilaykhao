<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">workspace_premium</span> คะแนนรีวิวทีมงาน</h1>
        <p class="page-subtitle">คะแนนที่ลูกค้าให้สตาฟรายทริป — ใช้ประกอบการจัดสตาฟลงรอบและการรีวิวผลงาน</p>
      </div>
      <div class="head-actions">
        <select v-model.number="days" class="range-select" @change="load">
          <option :value="0">ทุกช่วงเวลา</option>
          <option :value="30">30 วันล่าสุด</option>
          <option :value="90">90 วันล่าสุด</option>
          <option :value="365">1 ปีล่าสุด</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading-state"><div class="spinner"></div></div>

    <div v-else-if="!overall.total" class="empty-state">
      <span class="material-symbols-rounded">reviews</span>
      <p>ยังไม่มีรีวิวทีมงานในช่วงเวลานี้</p>
    </div>

    <template v-else>
      <div class="stat-row">
        <div class="stat-card">
          <span class="stat-num">{{ overall.avg_rating }}</span>
          <span class="stat-label">คะแนนเฉลี่ยทั้งทีม</span>
        </div>
        <div class="stat-card">
          <span class="stat-num">{{ overall.total }}</span>
          <span class="stat-label">รีวิวทั้งหมด</span>
        </div>
        <div class="stat-card">
          <span class="stat-num">{{ overall.staff_count }}</span>
          <span class="stat-label">สตาฟที่มีคะแนน</span>
        </div>
      </div>

      <div class="sr-layout">
        <section class="board-card">
          <h2 class="card-heading">อันดับคะแนนรายคน</h2>
          <div class="board-list">
            <button
              v-for="(s, i) in leaderboard"
              :key="s.staff_user_id"
              class="board-row"
              :class="{ active: staffFilter === s.staff_user_id }"
              @click="filterStaff(s.staff_user_id)"
            >
              <span class="rank" :class="{ top: i === 0 }">{{ i + 1 }}</span>
              <span class="staff-name">{{ s.staff_name }}</span>
              <span class="stars">
                <span
                  v-for="n in 5"
                  :key="n"
                  class="material-symbols-rounded star"
                  :class="{ filled: n <= Math.round(s.avg_rating) }"
                >star</span>
              </span>
              <span class="avg">{{ s.avg_rating }}</span>
              <span class="count">{{ s.total }} รีวิว</span>
              <!-- ดาวน้อยคือสัญญาณที่หัวหน้าทีมควรเห็นก่อน ไม่ใช่ค่าเฉลี่ยที่ถูกกลบ -->
              <span v-if="s.low_ratings" class="low-flag" :title="`มีรีวิว 1-2 ดาว ${s.low_ratings} ครั้ง`">
                <span class="material-symbols-rounded">warning</span> {{ s.low_ratings }}
              </span>
            </button>
          </div>
          <button v-if="staffFilter" class="clear-filter" @click="filterStaff(null)">
            ล้างตัวกรอง — ดูทุกคน
          </button>
        </section>

        <section class="reviews-card">
          <h2 class="card-heading">
            รีวิวล่าสุด
            <span v-if="staffFilter" class="filter-tag">{{ filteredStaffName }}</span>
          </h2>
          <div class="review-list">
            <article v-for="r in reviews" :key="r.id" class="review-item">
              <div class="rev-top">
                <span class="stars small">
                  <span
                    v-for="n in 5"
                    :key="n"
                    class="material-symbols-rounded star"
                    :class="{ filled: n <= r.rating }"
                  >star</span>
                </span>
                <span class="rev-staff">{{ r.staff_name }}</span>
                <span class="rev-time">{{ formatDate(r.created_at) }}</span>
              </div>
              <p v-if="r.comment" class="rev-comment">{{ r.comment }}</p>
              <p v-else class="rev-comment muted">— ไม่ได้เขียนคอมเมนต์ —</p>
              <div class="rev-meta">
                <span v-if="r.trip_title"><span class="material-symbols-rounded">hiking</span> {{ r.trip_title }}</span>
                <span v-if="r.departure_date">{{ formatDate(r.departure_date) }}</span>
                <span v-if="r.reviewer_name" class="reviewer">โดย {{ r.reviewer_name }}</span>
              </div>
            </article>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import './admin-shared.css';

const toast = useToast();

const leaderboard = ref([]);
const reviews = ref([]);
const overall = ref({ total: 0, avg_rating: 0, staff_count: 0 });
const loading = ref(false);
const days = ref(0);
const staffFilter = ref(null);

const filteredStaffName = computed(
  () => leaderboard.value.find((s) => s.staff_user_id === staffFilter.value)?.staff_name || '',
);

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

async function load() {
  loading.value = true;
  try {
    const params = {};
    if (days.value) params.days = days.value;
    if (staffFilter.value) params.staff_user_id = staffFilter.value;

    const res = await api.get('/admin/staff-reviews', { params });
    reviews.value = res.data.data.reviews || [];
    overall.value = res.data.data.overall || { total: 0, avg_rating: 0, staff_count: 0 };
    // ตอนกรองรายคน อันดับจะเหลือคนเดียว จึงเก็บอันดับเต็มไว้ให้เลือกคนอื่นต่อได้
    if (!staffFilter.value) leaderboard.value = res.data.data.leaderboard || [];
  } catch {
    toast.error('โหลดคะแนนทีมงานไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

function filterStaff(id) {
  staffFilter.value = staffFilter.value === id ? null : id;
  load();
}

onMounted(load);
</script>

<style scoped>
.range-select {
  padding: 9px 14px; border: 1px solid #d1d5db; border-radius: 8px;
  font-size: 13.5px; color: #374151; background: #fff; font-family: inherit;
}

.empty-state { padding: 70px 16px; text-align: center; color: #9ca3af; }
.empty-state .material-symbols-rounded { font-size: 46px !important; color: #d1d5db; }
.empty-state p { margin: 10px 0 0; font-size: 14px; }

.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 22px; }
.stat-card {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 18px 20px; display: flex; flex-direction: column; gap: 3px;
}
.stat-num { font-size: 27px; font-weight: 800; color: var(--color-accent); line-height: 1.1; }
.stat-label { font-size: 12.5px; color: #6b7280; }

.sr-layout { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 20px; align-items: start; }
@media (max-width: 1000px) { .sr-layout { grid-template-columns: 1fr; } }

.board-card, .reviews-card {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 22px;
}
.card-heading {
  margin: 0 0 14px; font-size: 15.5px; font-weight: 700; color: #111827;
  display: flex; align-items: center; gap: 8px;
}
.filter-tag {
  font-size: 11.5px; font-weight: 700; background: #dbeafe; color: #1d4ed8;
  border-radius: 999px; padding: 2px 10px;
}

.board-list { display: flex; flex-direction: column; gap: 4px; }
.board-row {
  display: flex; align-items: center; gap: 10px; width: 100%;
  background: none; border: 1px solid transparent; border-radius: 9px;
  padding: 9px 11px; cursor: pointer; text-align: left; transition: all 0.15s;
}
.board-row:hover { background: #f9fafb; }
.board-row.active { background: #f0fdf4; border-color: var(--color-accent); }
.rank {
  width: 22px; height: 22px; border-radius: 6px; flex-shrink: 0;
  background: #f3f4f6; color: #6b7280; font-size: 11.5px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
}
.rank.top { background: #fef3c7; color: #b45309; }
.staff-name { font-size: 13.5px; font-weight: 700; color: #1f2937; white-space: nowrap; }
.stars { display: inline-flex; }
.star { font-size: 15px !important; color: #e5e7eb; }
.star.filled { color: #f59e0b; }
.stars.small .star { font-size: 14px !important; }
.avg { margin-left: auto; font-size: 13.5px; font-weight: 800; color: #111827; }
.count { font-size: 11.5px; color: #9ca3af; white-space: nowrap; }
.low-flag {
  display: inline-flex; align-items: center; gap: 2px;
  font-size: 11.5px; font-weight: 700; color: #b91c1c;
  background: #fee2e2; border-radius: 999px; padding: 1px 8px;
}
.low-flag .material-symbols-rounded { font-size: 13px !important; color: #dc2626; }

.clear-filter {
  margin-top: 12px; background: none; border: none; cursor: pointer;
  font-size: 12.5px; color: #6b7280; text-decoration: underline;
}

.review-list { display: flex; flex-direction: column; gap: 12px; max-height: 620px; overflow-y: auto; }
.review-item { border: 1px solid #f3f4f6; border-radius: 11px; padding: 13px 15px; }
.rev-top { display: flex; align-items: center; gap: 9px; }
.rev-staff { font-size: 13px; font-weight: 700; color: #374151; }
.rev-time { margin-left: auto; font-size: 11.5px; color: #9ca3af; }
.rev-comment { margin: 8px 0 0; font-size: 13.5px; line-height: 1.6; color: #4b5563; word-break: break-word; }
.rev-comment.muted { color: #cbd5e1; font-style: italic; }
.rev-meta { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 9px; font-size: 11.5px; color: #9ca3af; }
.rev-meta .material-symbols-rounded { font-size: 14px !important; vertical-align: -2px; }
.reviewer { margin-left: auto; }
</style>
