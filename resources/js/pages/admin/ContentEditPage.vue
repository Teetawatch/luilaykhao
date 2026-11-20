<template>
  <div class="admin-page">
    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <template v-else-if="page">
      <div class="page-header">
        <div>
          <router-link to="/admin/content" class="back-link">
            <span class="material-symbols-rounded">arrow_back</span> เนื้อหาหน้าเว็บ
          </router-link>
          <h1 class="page-title">{{ page.label }}</h1>
          <p class="page-subtitle">{{ page.description }}</p>
        </div>
        <div class="header-actions">
          <a class="btn-secondary" :href="page.route" target="_blank">
            <span class="material-symbols-rounded">open_in_new</span> ดูหน้าจริง
          </a>
          <button class="btn-secondary" @click="resetContent">
            <span class="material-symbols-rounded">restart_alt</span> คืนค่าเริ่มต้น
          </button>
          <button class="btn-primary" :disabled="saving" @click="save">
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </div>

      <!-- ต้นฉบับไทย / คำแปลภาษาอังกฤษของหน้า (เว็บ /en) -->
      <div v-if="page.translation_id" class="content-tabs" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'thai'" :class="['content-tab', { on: tab === 'thai' }]" @click="tab = 'thai'">
          <span class="material-symbols-rounded">edit_note</span> เนื้อหา (ไทย)
        </button>
        <button type="button" role="tab" :aria-selected="tab === 'english'" :class="['content-tab', { on: tab === 'english' }]" @click="tab = 'english'">
          <span class="material-symbols-rounded">translate</span> English
        </button>
      </div>

      <div v-if="tab === 'english' && page.translation_id" class="table-card editor-card">
        <AdminContentTranslationPanel type="page" :id="page.translation_id" />
      </div>

      <div v-show="tab === 'thai'" class="table-card editor-card">
        <div class="form-grid">
          <ContentField
            v-for="field in page.fields"
            :key="field.key"
            :field="field"
            :model-value="content[field.key]"
            :root="content"
            @update:model-value="content[field.key] = $event"
          />
        </div>
      </div>

      <div v-show="tab === 'thai'" class="save-bar">
        <button class="btn-primary" :disabled="saving" @click="save">
          {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </template>

    <div class="empty-state" v-else>
      <span class="material-symbols-rounded">error</span>
      <p>ไม่พบหน้าเนื้อหานี้</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import ContentField from '../../components/ContentField.vue';
import AdminContentTranslationPanel from '../../components/AdminContentTranslationPanel.vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

const route = useRoute();
const toast = useToast();
const swal = useSwal();

const loading = ref(true);
const saving = ref(false);
const page = ref(null);
const content = ref({});
const tab = ref('thai');

async function load() {
  loading.value = true;

  try {
    const res = await api.get(`/admin/content/${route.params.key}`);
    page.value = res.data?.data || null;
    content.value = { ...(page.value?.content || {}) };
  } catch {
    page.value = null;
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;

  try {
    const res = await api.put(`/admin/content/${route.params.key}`, { content: content.value });
    content.value = { ...(res.data?.data || content.value) };
    toast.success('บันทึกเนื้อหาแล้ว');
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function resetContent() {
  const result = await swal.confirm({
    title: 'คืนค่าเนื้อหาเริ่มต้น?',
    text: 'ข้อความที่แก้ไว้ในหน้านี้จะถูกแทนที่ด้วยเนื้อหาที่มากับระบบ',
    confirmText: 'คืนค่า',
  });

  if (!result.isConfirmed) return;

  try {
    const res = await api.post(`/admin/content/${route.params.key}/reset`);
    content.value = { ...(res.data?.data || {}) };
    toast.success('คืนค่าเนื้อหาเริ่มต้นแล้ว');
  } catch (err) {
    toast.error(err.response?.data?.message || 'คืนค่าไม่สำเร็จ');
  }
}

onMounted(load);
</script>

<style scoped>
.content-tabs { display: flex; gap: 6px; margin-bottom: 16px; border-bottom: 1px solid #e5e7eb; }
.content-tab {
  display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; margin-bottom: -1px;
  border: 0; border-bottom: 2px solid transparent; background: none; color: #6b7280;
  font-size: 16px; font-weight: 600; cursor: pointer;
}
.content-tab .material-symbols-rounded { font-size: 20px; }
.content-tab.on { color: var(--color-accent); border-bottom-color: var(--color-accent); }
.back-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  font-weight: 700;
  color: #6b7280;
  text-decoration: none;
  margin-bottom: 6px;
}

.back-link:hover {
  color: #006565;
}

.back-link .material-symbols-rounded {
  font-size: 18px;
}

.header-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: flex-start;
}

.header-actions .material-symbols-rounded {
  font-size: 18px;
}

.editor-card {
  padding: 20px;
}

.editor-card .form-grid {
  display: grid;
  gap: 18px;
}

.save-bar {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
