<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-star"></i> จัดการรีวิว</h1>
        <p class="page-subtitle">ดูและตอบกลับรีวิวจากลูกค้า</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
      <input
        v-model="filters.search"
        type="text"
        placeholder="ค้นหาชื่อลูกค้า, ความคิดเห็น..."
        class="filter-input"
        @input="debouncedLoad" />
      <select v-model="filters.rating" class="filter-select" @change="loadReviews">
        <option value="">ทุกคะแนน</option>
        <option v-for="s in [5,4,3,2,1]" :key="s" :value="s">{{ s }} ดาว</option>
      </select>
      <select v-model="filters.is_approved" class="filter-select" @change="loadReviews">
        <option value="">ทุกสถานะ</option>
        <option value="1">เผยแพร่</option>
        <option value="0">ซ่อน</option>
      </select>
    </div>

    <!-- Stats Row -->
    <div class="review-stats" v-if="stats">
      <div class="rs-card">
        <span class="rs-val">{{ stats.total }}</span>
        <span class="rs-lbl">รีวิวทั้งหมด</span>
      </div>
      <div class="rs-card rs-gold">
        <span class="rs-val">{{ stats.avg_rating?.toFixed(1) }} ★</span>
        <span class="rs-lbl">คะแนนเฉลี่ย</span>
      </div>
      <div class="rs-card rs-green">
        <span class="rs-val">{{ stats.approved }}</span>
        <span class="rs-lbl">เผยแพร่</span>
      </div>
      <div class="rs-card rs-gray">
        <span class="rs-val">{{ stats.pending_reply }}</span>
        <span class="rs-lbl">รอตอบกลับ</span>
      </div>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <template v-else>
      <div class="reviews-list">
        <div
          v-for="r in reviews"
          :key="r.id"
          class="review-card"
          :class="{ 'review-hidden': !r.is_approved }">

          <!-- Header -->
          <div class="review-header">
            <div class="reviewer-info">
              <div class="avatar">{{ r.user_name?.charAt(0)?.toUpperCase() }}</div>
              <div>
                <p class="reviewer-name">{{ r.user_name }}</p>
                <p class="reviewer-email">{{ r.user_email }}</p>
              </div>
            </div>
            <div class="review-meta">
              <div class="stars">
                <span v-for="s in 5" :key="s" :class="s <= r.rating ? 'star-on' : 'star-off'">★</span>
              </div>
              <span class="review-date">{{ formatDate(r.created_at) }}</span>
              <span class="trip-badge">{{ r.trip_title }}</span>
            </div>
          </div>

          <!-- Comment -->
          <p v-if="r.comment" class="review-comment">{{ r.comment }}</p>

          <!-- Images -->
          <div v-if="r.images?.length" class="review-images">
            <img
              v-for="(img, i) in r.images"
              :key="i"
              :src="img"
              class="review-img"
              @click="previewImage = img" />
          </div>

          <!-- Admin Reply -->
          <div v-if="r.admin_reply" class="admin-reply-box">
            <p class="admin-reply-label">
              <i class="fas fa-reply"></i> ตอบกลับโดย {{ r.admin_replied_by }}
            </p>
            <p class="admin-reply-text">{{ r.admin_reply }}</p>
          </div>

          <!-- Reply Form -->
          <div v-if="replyingId === r.id" class="reply-form">
            <textarea
              v-model="replyText"
              rows="3"
              placeholder="พิมพ์คำตอบกลับ..."
              class="reply-textarea"></textarea>
            <div class="reply-actions">
              <button class="btn-primary btn-sm" @click="submitReply(r.id)" :disabled="!replyText || submitting">
                <i class="fas fa-paper-plane"></i> ส่งคำตอบ
              </button>
              <button class="btn-secondary btn-sm" @click="replyingId = null">ยกเลิก</button>
            </div>
          </div>

          <!-- Actions -->
          <div class="review-actions">
            <button
              class="action-btn"
              @click="toggleReply(r.id)"
              :class="{ 'action-active': replyingId === r.id }">
              <i class="fas fa-reply"></i>
              {{ r.admin_reply ? 'แก้ไขคำตอบ' : 'ตอบกลับ' }}
            </button>
            <button class="action-btn" @click="toggleApproval(r)">
              <i :class="r.is_approved ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
              {{ r.is_approved ? 'ซ่อน' : 'เผยแพร่' }}
            </button>
            <button class="action-btn action-danger" @click="deleteReview(r.id)">
              <i class="fas fa-trash"></i> ลบ
            </button>
          </div>
        </div>

        <div v-if="reviews.length === 0" class="empty-state">
          ยังไม่มีรีวิว
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="meta && meta.last_page > 1" class="pagination">
        <button
          v-for="p in meta.last_page"
          :key="p"
          @click="loadReviews(p)"
          class="page-btn"
          :class="{ 'page-active': p === meta.current_page }">
          {{ p }}
        </button>
      </div>
    </template>

    <!-- Image Preview -->
    <div v-if="previewImage" class="img-preview-overlay" @click="previewImage = null">
      <img :src="previewImage" class="img-preview" />
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../lib/axios';

const reviews = ref([]);
const meta = ref(null);
const stats = ref(null);
const loading = ref(false);
const replyingId = ref(null);
const replyText = ref('');
const submitting = ref(false);
const previewImage = ref(null);

const filters = reactive({ search: '', rating: '', is_approved: '' });

let debounceTimer = null;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => loadReviews(), 400);
}

async function loadReviews(page = 1) {
  loading.value = true;
  try {
    const params = { page, per_page: 10 };
    if (filters.search) params.search = filters.search;
    if (filters.rating) params.rating = filters.rating;
    if (filters.is_approved !== '') params.is_approved = filters.is_approved;

    const res = await api.get('/admin/reviews', { params });
    reviews.value = res.data.data;
    meta.value = res.data.meta;
    computeStats();
  } finally {
    loading.value = false;
  }
}

function computeStats() {
  const total = meta.value?.total ?? reviews.value.length;
  const approved = reviews.value.filter(r => r.is_approved).length;
  const withRating = reviews.value.filter(r => r.rating);
  const avg = withRating.length ? withRating.reduce((a, r) => a + r.rating, 0) / withRating.length : 0;
  const pendingReply = reviews.value.filter(r => !r.admin_reply).length;
  stats.value = { total, approved, avg_rating: avg, pending_reply: pendingReply };
}

function toggleReply(id) {
  if (replyingId.value === id) {
    replyingId.value = null;
    replyText.value = '';
  } else {
    replyingId.value = id;
    const r = reviews.value.find(r => r.id === id);
    replyText.value = r?.admin_reply || '';
  }
}

async function submitReply(id) {
  if (!replyText.value) return;
  submitting.value = true;
  try {
    await api.post(`/admin/reviews/${id}/reply`, { reply: replyText.value });
    const r = reviews.value.find(r => r.id === id);
    if (r) r.admin_reply = replyText.value;
    replyingId.value = null;
    replyText.value = '';
  } catch {
    alert('ตอบกลับไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

async function toggleApproval(r) {
  try {
    const res = await api.put(`/admin/reviews/${r.id}/toggle-approval`);
    r.is_approved = res.data.data.is_approved;
    computeStats();
  } catch {
    alert('เกิดข้อผิดพลาด');
  }
}

async function deleteReview(id) {
  if (!confirm('ต้องการลบรีวิวนี้หรือไม่?')) return;
  try {
    await api.delete(`/admin/reviews/${id}`);
    reviews.value = reviews.value.filter(r => r.id !== id);
    computeStats();
  } catch {
    alert('ลบไม่สำเร็จ');
  }
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

onMounted(() => loadReviews());
</script>

<style scoped>
@import url('./admin-shared.css');

.filter-bar {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.filter-input {
  flex: 1;
  min-width: 200px;
  padding: 9px 14px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  outline: none;
}

.filter-input:focus { border-color: #2d7a4f; }

.filter-select {
  padding: 9px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  outline: none;
  background: #fff;
  cursor: pointer;
}

/* Stats */
.review-stats {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.rs-card {
  flex: 1;
  min-width: 110px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 14px;
  text-align: center;
}

.rs-val {
  display: block;
  font-size: 22px;
  font-weight: 700;
  color: #111827;
}

.rs-gold .rs-val { color: #d97706; }
.rs-green .rs-val { color: #16a34a; }
.rs-gray .rs-val { color: #6b7280; }

.rs-lbl {
  font-size: 11px;
  color: #6b7280;
  margin-top: 2px;
}

/* Review Cards */
.reviews-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.review-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 20px;
  transition: box-shadow 0.15s;
}

.review-card:hover {
  box-shadow: 0 4px 16px rgba(17,24,39,0.06);
}

.review-hidden {
  opacity: 0.55;
  border-style: dashed;
}

.review-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.reviewer-info {
  display: flex;
  align-items: center;
  gap: 10px;
}

.avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #2d7a4f;
  color: #fff;
  font-weight: 700;
  font-size: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.reviewer-name {
  font-size: 14px;
  font-weight: 600;
  color: #111827;
  margin: 0;
}

.reviewer-email {
  font-size: 12px;
  color: #6b7280;
  margin: 0;
}

.review-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.stars {
  font-size: 16px;
  letter-spacing: 1px;
}

.star-on { color: #f59e0b; }
.star-off { color: #d1d5db; }

.review-date {
  font-size: 12px;
  color: #9ca3af;
}

.trip-badge {
  font-size: 11px;
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #bbf7d0;
  border-radius: 20px;
  padding: 2px 10px;
  font-weight: 600;
}

.review-comment {
  font-size: 14px;
  color: #374151;
  line-height: 1.6;
  margin-bottom: 12px;
}

.review-images {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.review-img {
  width: 80px;
  height: 80px;
  border-radius: 8px;
  object-fit: cover;
  cursor: pointer;
  border: 1px solid #e5e7eb;
  transition: opacity 0.15s;
}

.review-img:hover { opacity: 0.85; }

.admin-reply-box {
  background: #f0fdf4;
  border-left: 3px solid #2d7a4f;
  border-radius: 0 8px 8px 0;
  padding: 10px 14px;
  margin-bottom: 12px;
}

.admin-reply-label {
  font-size: 11px;
  font-weight: 700;
  color: #2d7a4f;
  margin: 0 0 4px;
}

.admin-reply-text {
  font-size: 13px;
  color: #374151;
  margin: 0;
}

.reply-form {
  margin-bottom: 12px;
}

.reply-textarea {
  width: 100%;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  resize: none;
  outline: none;
  box-sizing: border-box;
}

.reply-textarea:focus { border-color: #2d7a4f; }

.reply-actions {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}

.btn-sm {
  padding: 6px 14px;
  font-size: 13px;
}

.review-actions {
  display: flex;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px solid #f3f4f6;
  flex-wrap: wrap;
}

.action-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  color: #6b7280;
  background: #fff;
  cursor: pointer;
  transition: all 0.15s;
}

.action-btn:hover {
  background: #f9fafb;
  color: #374151;
}

.action-active {
  border-color: #2d7a4f;
  color: #2d7a4f;
  background: #f0fdf4;
}

.action-danger {
  color: #dc2626;
  border-color: #fecaca;
}

.action-danger:hover {
  background: #fef2f2;
}

.pagination {
  display: flex;
  justify-content: center;
  gap: 6px;
  margin-top: 20px;
}

.page-btn {
  width: 34px;
  height: 34px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  background: #fff;
  cursor: pointer;
  transition: all 0.15s;
}

.page-btn:hover { background: #f9fafb; }
.page-active { background: #2d7a4f; color: #fff; border-color: #2d7a4f; }

/* Image Preview */
.img-preview-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.85);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.img-preview {
  max-width: 90vw;
  max-height: 90vh;
  border-radius: 12px;
  object-fit: contain;
}
</style>
