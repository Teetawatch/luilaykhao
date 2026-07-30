<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">article</span> เนื้อหาหน้าเว็บ</h1>
        <p class="page-subtitle">
          แก้ข้อความบนหน้า "ข้อมูลก่อนไป" ได้เอง ไม่ต้องรอนักพัฒนา — บันทึกแล้วขึ้นหน้าเว็บทันที
        </p>
      </div>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <div v-else class="content-grid">
      <router-link
        v-for="page in pages"
        :key="page.key"
        class="content-card"
        :to="`/admin/content/${page.key}`"
      >
        <div class="content-card-head">
          <h3>{{ page.label }}</h3>
          <span class="status-badge" :class="page.customised ? 'status-active' : 'status-inactive'">
            {{ page.customised ? 'แก้ไขแล้ว' : 'ค่าเริ่มต้น' }}
          </span>
        </div>
        <p class="content-card-desc">{{ page.description }}</p>
        <div class="content-card-foot">
          <code>{{ page.route }}</code>
          <span v-if="page.updated_at">แก้ล่าสุด {{ thaiShort(page.updated_at) }}</span>
        </div>
      </router-link>
    </div>

    <div class="table-card note-card">
      <h3><span class="material-symbols-rounded">tips_and_updates</span> เนื้อหาส่วนอื่นแก้ที่ไหน</h3>
      <ul>
        <li>
          ปฏิทิน <strong>"เดือนไหนไปไหนดี"</strong> (<code>/seasons</code>) ดึงข้อมูลจากสถานที่ที่กรอกไว้
          — เพิ่มที่ <router-link to="/admin/places">สถานที่/ฤดูกาล</router-link>
        </li>
        <li>คำถามเฉพาะของแต่ละทริป แก้ในหน้าแก้ไขทริปนั้น ไม่ใช่หน้า FAQ รวม</li>
        <li>บทความ/บล็อก แก้ที่ <router-link to="/admin/articles">บทความ/บล็อก</router-link></li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { thaiShort } from '../../lib/thaiDate';

const loading = ref(true);
const pages = ref([]);

onMounted(async () => {
  try {
    const res = await api.get('/admin/content');
    pages.value = res.data?.data || [];
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.content-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
  margin-bottom: 20px;
}

.content-card {
  display: block;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 18px;
  text-decoration: none;
  color: inherit;
  transition: border-color 0.15s;
}

.content-card:hover {
  border-color: #006565;
}

.content-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 6px;
}

.content-card-head h3 {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
  margin: 0;
}

.content-card-desc {
  font-size: 13px;
  color: #6b7280;
  line-height: 1.55;
  margin: 0 0 12px;
}

.content-card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
  color: #9ca3af;
}

.content-card-foot code {
  background: #f3f4f6;
  border-radius: 6px;
  padding: 2px 6px;
}

.note-card {
  padding: 18px;
}

.note-card h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 10px;
}

.note-card .material-symbols-rounded {
  color: #006565;
}

.note-card ul {
  margin: 0;
  padding-left: 20px;
  display: grid;
  gap: 6px;
}

.note-card li {
  font-size: 13px;
  color: #6b7280;
  line-height: 1.6;
}

.note-card code {
  background: #f3f4f6;
  border-radius: 6px;
  padding: 1px 5px;
}

.note-card a {
  color: #006565;
  font-weight: 700;
}
</style>
