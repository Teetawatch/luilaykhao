<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">article</span> บทความ / บล็อก</h1>
        <p class="page-subtitle">เขียนบทความให้ความรู้ ดักทราฟฟิกจาก Google เข้าสู่ระบบจอง</p>
      </div>
      <button class="btn-primary" @click="router.push({ name: 'admin-article-create' })">
        <span class="material-symbols-rounded">add</span> เขียนบทความใหม่
      </button>
    </div>

    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="filters.search" placeholder="ค้นหาบทความ..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.status" @change="fetchData()">
        <option value="">ทุกสถานะ</option>
        <option value="published">เผยแพร่แล้ว</option>
        <option value="draft">ฉบับร่าง</option>
      </select>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
      <div class="empty-state" v-else-if="!admin.articles.data.length">
        <span class="material-symbols-rounded">article</span>
        <p>ยังไม่มีบทความ — เริ่มเขียนบทความแรกเพื่อดึงคนเข้าเว็บกันเลย</p>
      </div>
      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>บทความ</th>
              <th>หมวดหมู่</th>
              <th>สถานะ</th>
              <th>เผยแพร่</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in admin.articles.data" :key="a.id">
              <td>
                <div class="trip-cell">
                  <img :src="a.cover_image_url || '/images/placeholder.jpg'" class="trip-thumb" />
                  <div>
                    <span class="trip-name">{{ a.title }}</span>
                    <div class="muted-sub">อ่าน {{ a.reading_minutes }} นาที</div>
                  </div>
                </div>
              </td>
              <td>{{ a.category?.name || '—' }}</td>
              <td>
                <span class="status-badge" :class="a.status === 'published' ? 'status-active' : 'status-inactive'">
                  {{ a.status === 'published' ? 'เผยแพร่แล้ว' : 'ฉบับร่าง' }}
                </span>
              </td>
              <td class="date">{{ formatDate(a.published_at) }}</td>
              <td>
                <div class="action-btns">
                  <a class="btn-icon" :href="'/blog/' + encodeURIComponent(a.slug)" target="_blank" title="ดูหน้าเว็บ">
                    <span class="material-symbols-rounded">open_in_new</span>
                  </a>
                  <button class="btn-icon" title="แก้ไข"
                          @click="router.push({ name: 'admin-article-edit', params: { id: a.id } })">
                    <span class="material-symbols-rounded">edit</span>
                  </button>
                  <button class="btn-icon" :title="a.status === 'published' ? 'เปลี่ยนเป็นร่าง' : 'เผยแพร่'"
                          @click="togglePublish(a)">
                    <span class="material-symbols-rounded">{{ a.status === 'published' ? 'visibility_off' : 'publish' }}</span>
                  </button>
                  <button class="btn-icon btn-delete" title="ลบ" @click="remove(a)">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination" v-if="admin.articles.meta && admin.articles.meta.last_page > 1">
        <button :disabled="page <= 1" @click="go(page - 1)">ก่อนหน้า</button>
        <span>หน้า {{ admin.articles.meta.current_page }} / {{ admin.articles.meta.last_page }}</span>
        <button :disabled="page >= admin.articles.meta.last_page" @click="go(page + 1)">ถัดไป</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAdminStore } from '../../stores/admin';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

const router = useRouter();
const admin = useAdminStore();
const toast = useToast();
const swal = useSwal();
const filters = reactive({ search: '', status: '' });
const page = ref(1);
let timer = null;

function fetchData() {
  admin.fetchArticles({ ...filters, page: page.value });
}

function go(p) {
  page.value = p;
  fetchData();
}

function debouncedFetch() {
  clearTimeout(timer);
  timer = setTimeout(() => { page.value = 1; fetchData(); }, 350);
}

async function togglePublish(a) {
  try {
    await admin.publishArticle(a.id, a.status !== 'published');
    toast.success(a.status === 'published' ? 'เปลี่ยนเป็นฉบับร่างแล้ว' : 'เผยแพร่บทความแล้ว');
    fetchData();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ทำรายการไม่สำเร็จ');
  }
}

async function remove(a) {
  const res = await swal.confirm({
    title: 'ลบบทความ?',
    text: a.title,
    icon: 'warning',
    confirmText: 'ลบ',
  });
  if (!res.isConfirmed) return;
  try {
    await admin.deleteArticle(a.id);
    toast.success('ลบบทความแล้ว');
    fetchData();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ลบไม่สำเร็จ');
  }
}

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

onMounted(fetchData);
</script>

<style scoped src="./admin-shared.css"></style>
<style scoped>
.muted-sub { font-size: 12px; color: #94a3b8; margin-top: 2px; }
</style>
