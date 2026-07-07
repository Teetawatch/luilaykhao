<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">dynamic_feed</span> ดูแลฟีดนักเดินทาง</h1>
        <p class="page-subtitle">ตรวจและจัดการโพสต์รูปที่ลูกค้าแชร์เข้าฟีดสาธารณะ</p>
      </div>
    </div>

    <!-- Stats -->
    <div class="review-stats" v-if="counts">
      <div class="rs-card">
        <span class="rs-val">{{ counts.total }}</span>
        <span class="rs-lbl">โพสต์ทั้งหมด</span>
      </div>
      <div class="rs-card rs-red">
        <span class="rs-val">{{ counts.reported }}</span>
        <span class="rs-lbl">ถูกรายงาน</span>
      </div>
      <div class="rs-card rs-gray">
        <span class="rs-val">{{ counts.hidden }}</span>
        <span class="rs-lbl">ซ่อนอยู่</span>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="filter-tabs">
      <button
        v-for="t in tabs"
        :key="t.value"
        class="filter-tab"
        :class="{ active: activeTab === t.value }"
        @click="setTab(t.value)">
        {{ t.label }}
        <span v-if="t.value === 'reported' && counts?.reported" class="tab-badge">{{ counts.reported }}</span>
      </button>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <template v-else>
      <div class="posts-grid">
        <div
          v-for="post in posts"
          :key="post.id"
          class="post-card"
          :class="{ 'post-hidden': post.status === 'hidden' }">

          <!-- Photos -->
          <div class="post-photos">
            <img
              v-for="(photo, i) in post.photos"
              :key="i"
              :src="photo.url"
              class="post-photo"
              @click="previewImage = photo.url" />
            <span v-if="post.status === 'hidden'" class="hidden-tag">
              <span class="material-symbols-rounded" style="font-size:14px; vertical-align:text-bottom;">visibility_off</span> ซ่อนอยู่
            </span>
            <span v-if="post.reports_count > 0" class="report-tag">
              <span class="material-symbols-rounded" style="font-size:14px; vertical-align:text-bottom;">flag</span> {{ post.reports_count }} รายงาน
            </span>
          </div>

          <div class="post-body">
            <!-- Author -->
            <div class="post-author">
              <div class="avatar" v-if="!post.user?.avatar_url">{{ (post.user?.name || 'น').charAt(0).toUpperCase() }}</div>
              <img v-else :src="post.user.avatar_url" class="avatar-img" />
              <div class="author-meta">
                <p class="author-name">{{ post.user?.name || 'นักเดินทาง' }}</p>
                <p class="author-sub">{{ formatDate(post.created_at) }}</p>
              </div>
              <span v-if="post.trip?.title" class="trip-badge">{{ post.trip.title }}</span>
            </div>

            <p v-if="post.caption" class="post-caption">{{ post.caption }}</p>

            <div class="post-engage">
              <span><span class="material-symbols-rounded eng-icon">favorite</span> {{ post.likes_count }}</span>
              <span><span class="material-symbols-rounded eng-icon">chat_bubble</span> {{ post.comments_count }}</span>
            </div>

            <!-- Actions -->
            <div class="post-actions">
              <button
                v-if="post.status === 'published'"
                class="action-btn"
                @click="hide(post)">
                <span class="material-symbols-rounded" style="font-size:16px;">visibility_off</span> ซ่อน
              </button>
              <button
                v-else
                class="action-btn action-active"
                @click="unhide(post)">
                <span class="material-symbols-rounded" style="font-size:16px;">visibility</span> แสดงอีกครั้ง
              </button>
              <button class="action-btn action-danger" @click="remove(post)">
                <span class="material-symbols-rounded" style="font-size:16px;">delete</span> ลบ
              </button>
            </div>
          </div>
        </div>

        <div v-if="posts.length === 0" class="empty-state">
          {{ activeTab === 'reported' ? 'ไม่มีโพสต์ที่ถูกรายงาน' : 'ยังไม่มีโพสต์ในฟีด' }}
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="meta && meta.last_page > 1" class="pagination">
        <button
          v-for="p in meta.last_page"
          :key="p"
          @click="loadPosts(p)"
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
import { ref, onMounted } from 'vue';
import api from '../../lib/axios';

const posts = ref([]);
const meta = ref(null);
const counts = ref(null);
const loading = ref(false);
const previewImage = ref(null);
const activeTab = ref('all');

const tabs = [
  { value: 'all', label: 'ทั้งหมด' },
  { value: 'reported', label: 'ถูกรายงาน' },
  { value: 'hidden', label: 'ซ่อนอยู่' },
];

function setTab(value) {
  if (activeTab.value === value) return;
  activeTab.value = value;
  loadPosts();
}

async function loadPosts(page = 1) {
  loading.value = true;
  try {
    const params = { page, per_page: 12 };
    if (activeTab.value === 'reported') params.reported = 1;
    if (activeTab.value === 'hidden') params.status = 'hidden';

    const res = await api.get('/admin/trip-posts', { params });
    posts.value = res.data.data;
    meta.value = res.data.meta;
    counts.value = res.data.meta?.counts ?? counts.value;
  } finally {
    loading.value = false;
  }
}

async function hide(post) {
  try {
    await api.post(`/admin/trip-posts/${post.id}/hide`);
    post.status = 'hidden';
    if (counts.value) counts.value.hidden += 1;
  } catch {
    alert('ซ่อนโพสต์ไม่สำเร็จ');
  }
}

async function unhide(post) {
  try {
    await api.post(`/admin/trip-posts/${post.id}/unhide`);
    post.status = 'published';
    if (counts.value && counts.value.hidden > 0) counts.value.hidden -= 1;
  } catch {
    alert('แสดงโพสต์ไม่สำเร็จ');
  }
}

async function remove(post) {
  if (!confirm('ต้องการลบโพสต์นี้ถาวรหรือไม่? รูปจะถูกลบจากระบบด้วย')) return;
  try {
    await api.delete(`/admin/trip-posts/${post.id}`);
    posts.value = posts.value.filter(p => p.id !== post.id);
    if (counts.value && counts.value.total > 0) counts.value.total -= 1;
  } catch {
    alert('ลบโพสต์ไม่สำเร็จ');
  }
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

onMounted(() => loadPosts());
</script>

<style scoped>
@import url('./admin-shared.css');

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
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 14px;
  text-align: center;
}

.rs-val {
  display: block;
  font-size: 22px;
  font-weight: 700;
  color: var(--color-text-dark);
}

.rs-red .rs-val { color: #dc2626; }
.rs-gray .rs-val { color: var(--color-text-muted); }

.rs-lbl {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

/* Filter tabs */
.filter-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.filter-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 18px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-muted);
  background: var(--color-white);
  cursor: pointer;
  transition: all 0.15s;
}

.filter-tab:hover { background: var(--color-sand); }

.filter-tab.active {
  background: var(--color-accent);
  color: var(--color-white);
  border-color: var(--color-accent);
}

.tab-badge {
  background: #dc2626;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  border-radius: 20px;
  padding: 1px 7px;
  line-height: 1.5;
}

.filter-tab.active .tab-badge { background: rgba(255,255,255,0.28); }

/* Posts grid */
.posts-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
}

.post-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: box-shadow 0.15s;
}

.post-card:hover { box-shadow: 0 4px 16px rgba(17,24,39,0.06); }

.post-hidden { opacity: 0.6; border-style: dashed; }

.post-photos {
  position: relative;
  display: flex;
  gap: 2px;
  overflow-x: auto;
  background: var(--color-sand);
}

.post-photo {
  height: 180px;
  min-width: 180px;
  flex: 1;
  object-fit: cover;
  cursor: pointer;
}

.hidden-tag, .report-tag {
  position: absolute;
  top: 8px;
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  border-radius: 20px;
  padding: 3px 10px;
}

.hidden-tag { left: 8px; background: rgba(17,24,39,0.75); }
.report-tag { right: 8px; background: #dc2626; }

.post-body {
  padding: 14px 16px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
}

.post-author {
  display: flex;
  align-items: center;
  gap: 8px;
}

.avatar, .avatar-img {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  flex-shrink: 0;
}

.avatar {
  background: var(--color-accent);
  color: var(--color-white);
  font-weight: 700;
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.avatar-img { object-fit: cover; }

.author-meta { flex: 1; min-width: 0; }

.author-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-dark);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.author-sub {
  font-size: 11px;
  color: var(--color-text-muted);
  margin: 0;
}

.trip-badge {
  font-size: 11px;
  background: var(--color-sand);
  color: var(--color-accent);
  border: 1px solid var(--color-sand-dark);
  border-radius: 20px;
  padding: 2px 10px;
  font-weight: 600;
  white-space: nowrap;
  max-width: 130px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.post-caption {
  font-size: 13px;
  color: var(--color-text-mid);
  line-height: 1.5;
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.post-engage {
  display: flex;
  gap: 16px;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-muted);
}

.post-engage span { display: inline-flex; align-items: center; gap: 5px; }

.eng-icon { font-size: 16px; vertical-align: middle; }

.post-actions {
  display: flex;
  gap: 8px;
  margin-top: auto;
  padding-top: 10px;
  border-top: 1px solid var(--color-sand-dark);
}

.action-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-muted);
  background: var(--color-white);
  cursor: pointer;
  transition: all 0.15s;
}

.action-btn:hover { background: var(--color-sand); color: var(--color-text-dark); }

.action-active {
  border-color: var(--color-accent);
  color: var(--color-accent);
  background: rgba(45, 122, 79, 0.05);
}

.action-danger { color: #dc2626; border-color: #fecaca; }
.action-danger:hover { background: #fef2f2; }

.pagination {
  display: flex;
  justify-content: center;
  gap: 6px;
  margin-top: 20px;
}

.page-btn {
  width: 34px;
  height: 34px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-mid);
  background: var(--color-white);
  cursor: pointer;
  transition: all 0.15s;
}

.page-btn:hover { background: var(--color-sand); }
.page-active { background: var(--color-accent); color: var(--color-white); border-color: var(--color-accent); }

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
