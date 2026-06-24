<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">edit_note</span>
          {{ isEdit ? 'แก้ไขบทความ' : 'เขียนบทความใหม่' }}
        </h1>
        <p class="page-subtitle">เนื้อหาที่ดีช่วยให้ Google จัดอันดับและดึงคนเข้าเว็บฟรี</p>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" :disabled="saving" @click="save('draft')">บันทึกร่าง</button>
        <button class="btn-primary" :disabled="saving" @click="save('published')">
          <span class="material-symbols-rounded">publish</span> เผยแพร่
        </button>
      </div>
    </div>

    <div class="editor-grid">
      <!-- Main column -->
      <div class="col-main">
        <div class="card">
          <label class="fld">
            <span>ชื่อบทความ</span>
            <input v-model="form.title" class="input title-input" placeholder="เช่น เตรียมตัวเดินป่าภูกระดึงฉบับมือใหม่" @input="onTitleInput" />
          </label>
          <label class="fld">
            <span>Slug (URL)</span>
            <div class="slug-row">
              <span class="slug-prefix">/blog/</span>
              <input v-model="form.slug" class="input" placeholder="auto" />
            </div>
          </label>
          <label class="fld">
            <span>เกริ่นนำ (excerpt)</span>
            <textarea v-model="form.excerpt" class="input" rows="2" placeholder="สรุปสั้น ๆ แสดงในการ์ดและผลค้นหา Google"></textarea>
          </label>
        </div>

        <!-- TipTap editor -->
        <div class="card">
          <div class="editor-toolbar" v-if="editor">
            <button :class="{ on: editor.isActive('bold') }" @click="editor.chain().focus().toggleBold().run()" title="ตัวหนา"><b>B</b></button>
            <button :class="{ on: editor.isActive('italic') }" @click="editor.chain().focus().toggleItalic().run()" title="ตัวเอียง"><i>I</i></button>
            <span class="sep"></span>
            <button :class="{ on: editor.isActive('heading', { level: 2 }) }" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">H2</button>
            <button :class="{ on: editor.isActive('heading', { level: 3 }) }" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()">H3</button>
            <span class="sep"></span>
            <button :class="{ on: editor.isActive('bulletList') }" @click="editor.chain().focus().toggleBulletList().run()" title="หัวข้อย่อย">• List</button>
            <button :class="{ on: editor.isActive('orderedList') }" @click="editor.chain().focus().toggleOrderedList().run()" title="ลำดับเลข">1. List</button>
            <button :class="{ on: editor.isActive('blockquote') }" @click="editor.chain().focus().toggleBlockquote().run()" title="ยกคำพูด">❝</button>
            <span class="sep"></span>
            <button @click="setLink" :class="{ on: editor.isActive('link') }" title="ลิงก์">🔗</button>
            <button @click="pickInlineImage" title="แทรกรูป">🖼️</button>
          </div>
          <editor-content :editor="editor" class="prose-editor" />
        </div>
      </div>

      <!-- Sidebar -->
      <div class="col-side">
        <div class="card">
          <h3 class="card-title">ภาพปก</h3>
          <div class="cover-box" :style="form.cover_image_url ? `background-image:url('${form.cover_image_url}')` : ''">
            <span v-if="!form.cover_image_url" class="cover-hint">ยังไม่มีภาพปก</span>
          </div>
          <button class="btn-secondary full" :disabled="uploadingCover" @click="pickCover">
            {{ uploadingCover ? 'กำลังอัปโหลด...' : 'อัปโหลดภาพปก' }}
          </button>
          <button v-if="form.cover_image_url" class="btn-link" @click="form.cover_image_url = ''">ลบภาพปก</button>
        </div>

        <div class="card">
          <h3 class="card-title">หมวดหมู่</h3>
          <select v-model="form.category_id" class="input">
            <option :value="null">— ไม่ระบุ —</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <div class="new-cat">
            <input v-model="newCategory" class="input" placeholder="เพิ่มหมวดหมู่ใหม่" @keyup.enter="addCategory" />
            <button class="btn-secondary" @click="addCategory" :disabled="!newCategory.trim()">เพิ่ม</button>
          </div>
        </div>

        <div class="card">
          <h3 class="card-title">แท็ก</h3>
          <div class="chips">
            <span v-for="(t, i) in form.tags" :key="t" class="chip">
              {{ t }} <button @click="form.tags.splice(i, 1)">×</button>
            </span>
          </div>
          <input v-model="tagInput" class="input" placeholder="พิมพ์แล้วกด Enter" @keyup.enter.prevent="addTag" />
        </div>

        <div class="card">
          <h3 class="card-title">ทริปที่เกี่ยวข้อง (Funnel)</h3>
          <p class="card-hint">เลือกทริปที่อยากให้คนอ่านกดไปจอง</p>
          <select class="input" @change="addTrip($event)">
            <option value="">+ เพิ่มทริป...</option>
            <option v-for="t in availableTrips" :key="t.id" :value="t.id">{{ t.title }}</option>
          </select>
          <div class="chips">
            <span v-for="t in selectedTrips" :key="t.id" class="chip">
              {{ t.title }} <button @click="removeTrip(t.id)">×</button>
            </span>
          </div>
        </div>

        <div class="card">
          <h3 class="card-title">SEO (ไม่บังคับ)</h3>
          <label class="fld">
            <span>Meta title</span>
            <input v-model="form.meta_title" class="input" placeholder="ใช้ชื่อบทความถ้าเว้นว่าง" />
          </label>
          <label class="fld">
            <span>Meta description</span>
            <textarea v-model="form.meta_description" class="input" rows="2" placeholder="ใช้ excerpt ถ้าเว้นว่าง"></textarea>
          </label>
        </div>
      </div>
    </div>

    <input ref="fileInput" type="file" accept="image/*" hidden @change="onFileChosen" />
  </div>
</template>

<script setup>
import { reactive, ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import { useAdminStore } from '../../stores/admin';
import { useToast } from '../../lib/toast';
import api from '../../lib/axios';

const route = useRoute();
const router = useRouter();
const admin = useAdminStore();
const toast = useToast();

const isEdit = computed(() => !!route.params.id);
const saving = ref(false);
const uploadingCover = ref(false);
const slugTouched = ref(false);

const form = reactive({
  title: '', slug: '', excerpt: '', cover_image_url: '',
  category_id: null, tags: [], trip_ids: [],
  meta_title: '', meta_description: '',
});

const categories = ref([]);
const allTrips = ref([]);
const newCategory = ref('');
const tagInput = ref('');
const fileInput = ref(null);
let fileTarget = 'cover';

const editor = useEditor({
  content: '',
  extensions: [
    StarterKit.configure({ heading: { levels: [2, 3] } }),
    Link.configure({ openOnClick: false }),
    Image,
    Placeholder.configure({ placeholder: 'เขียนเนื้อหาบทความที่นี่...' }),
  ],
});

const selectedTrips = computed(() => allTrips.value.filter((t) => form.trip_ids.includes(t.id)));
const availableTrips = computed(() => allTrips.value.filter((t) => !form.trip_ids.includes(t.id)));

function onTitleInput() {
  if (!slugTouched.value && !isEdit.value) {
    form.slug = form.title.trim().toLowerCase().replace(/[^\p{L}\p{N}\p{M}]+/gu, '-').replace(/^-+|-+$/g, '');
  }
}

function addTag() {
  const v = tagInput.value.trim();
  if (v && !form.tags.includes(v)) form.tags.push(v);
  tagInput.value = '';
}

function addTrip(e) {
  const id = Number(e.target.value);
  if (id && !form.trip_ids.includes(id)) form.trip_ids.push(id);
  e.target.value = '';
}

function removeTrip(id) {
  form.trip_ids = form.trip_ids.filter((x) => x !== id);
}

async function addCategory() {
  const name = newCategory.value.trim();
  if (!name) return;
  try {
    const cat = await admin.createArticleCategory({ name });
    categories.value.push(cat);
    form.category_id = cat.id;
    newCategory.value = '';
  } catch (e) {
    toast.error(e.response?.data?.message || 'เพิ่มหมวดหมู่ไม่สำเร็จ');
  }
}

function pickCover() { fileTarget = 'cover'; fileInput.value.click(); }
function pickInlineImage() { fileTarget = 'inline'; fileInput.value.click(); }

async function onFileChosen(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  try {
    if (fileTarget === 'cover') uploadingCover.value = true;
    const url = await admin.uploadArticleImage(file);
    if (fileTarget === 'cover') form.cover_image_url = url;
    else editor.value?.chain().focus().setImage({ src: url }).run();
  } catch (err) {
    toast.error(err.response?.data?.errors?.file?.[0] || 'อัปโหลดรูปไม่สำเร็จ');
  } finally {
    uploadingCover.value = false;
  }
}

function setLink() {
  const prev = editor.value.getAttributes('link').href;
  const url = window.prompt('ลิงก์ URL', prev || 'https://');
  if (url === null) return;
  if (url === '') { editor.value.chain().focus().unsetLink().run(); return; }
  editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}

async function save(status) {
  if (!form.title.trim()) { toast.error('กรุณาใส่ชื่อบทความ'); return; }
  const body = editor.value?.getHTML() || '';
  if (!body || body === '<p></p>') { toast.error('กรุณาใส่เนื้อหาบทความ'); return; }

  saving.value = true;
  const payload = { ...form, body, status };
  try {
    if (isEdit.value) {
      await admin.updateArticle(route.params.id, payload);
    } else {
      const created = await admin.createArticle(payload);
      // Switch to edit mode so subsequent saves update the same article.
      router.replace({ name: 'admin-article-edit', params: { id: created.id } });
    }
    toast.success(status === 'published' ? 'เผยแพร่บทความแล้ว' : 'บันทึกฉบับร่างแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function loadArticle() {
  const a = await admin.fetchArticle(route.params.id);
  form.title = a.title;
  form.slug = a.slug;
  form.excerpt = a.excerpt || '';
  form.cover_image_url = a.cover_image_url || '';
  form.category_id = a.category?.id ?? null;
  form.tags = (a.tags || []).map((t) => t.name);
  form.trip_ids = (a.trips || []).map((t) => t.id);
  form.meta_title = a.meta_title || '';
  form.meta_description = a.meta_description || '';
  editor.value?.commands.setContent(a.body || '');
}

onMounted(async () => {
  try {
    categories.value = await admin.fetchArticleCategories();
    const res = await api.get('/admin/trips', { params: { per_page: 200 } });
    allTrips.value = res.data.data;
    if (isEdit.value) await loadArticle();
  } catch (e) {
    toast.error('โหลดข้อมูลไม่สำเร็จ');
  }
});

onBeforeUnmount(() => editor.value?.destroy());
</script>

<style scoped src="./admin-shared.css"></style>
<style scoped>
.header-actions { display: flex; gap: 10px; }
.editor-grid { display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
@media (max-width: 980px) { .editor-grid { grid-template-columns: 1fr; } }
.card { background: #fff; border: 1px solid #e5e9ee; border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
.card-title { font-size: 14px; font-weight: 800; color: #1f2937; margin-bottom: 10px; }
.card-hint { font-size: 12px; color: #94a3b8; margin: -4px 0 8px; }
.fld { display: block; margin-bottom: 12px; }
.fld > span { display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 5px; }
.input { width: 100%; border: 1px solid #d6dce5; border-radius: 9px; padding: 10px 12px; font-size: 15px; font-family: inherit; background: #fff; }
.input:focus { outline: none; border-color: #087C68; box-shadow: 0 0 0 3px rgba(8,124,104,.12); }
.title-input { font-size: 20px; font-weight: 700; }
.slug-row { display: flex; align-items: center; border: 1px solid #d6dce5; border-radius: 9px; overflow: hidden; }
.slug-row .slug-prefix { padding: 0 10px; color: #94a3b8; background: #f6f8fa; font-size: 14px; }
.slug-row .input { border: none; border-radius: 0; }
.full { width: 100%; }
.btn-link { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 13px; margin-top: 8px; }
.cover-box { aspect-ratio: 16/9; border-radius: 10px; background: #eef2f1 center/cover no-repeat; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
.cover-hint { color: #94a3b8; font-size: 13px; }
.new-cat { display: flex; gap: 8px; margin-top: 8px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
.chip { background: #e8f3f0; color: #0b6b59; border-radius: 999px; padding: 4px 10px; font-size: 13px; font-weight: 600; }
.chip button { background: none; border: none; color: #0b6b59; cursor: pointer; font-weight: 800; }

.editor-toolbar { display: flex; flex-wrap: wrap; gap: 4px; padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px solid #eef2f1; }
.editor-toolbar button { min-width: 34px; height: 32px; padding: 0 8px; border: 1px solid #e1e6ec; background: #fff; border-radius: 7px; cursor: pointer; font-size: 14px; color: #334155; }
.editor-toolbar button.on { background: #0D2B1E; color: #fff; border-color: #0D2B1E; }
.editor-toolbar .sep { width: 1px; background: #e6eaee; margin: 0 4px; }
</style>

<style>
/* TipTap content area (unscoped so ProseMirror nodes are styled) */
.prose-editor .ProseMirror { min-height: 320px; outline: none; font-size: 16px; line-height: 1.75; color: #1f2937; }
.prose-editor .ProseMirror:focus { outline: none; }
.prose-editor .ProseMirror h2 { font-size: 24px; font-weight: 800; margin: 18px 0 8px; color: #0D2B1E; }
.prose-editor .ProseMirror h3 { font-size: 20px; font-weight: 800; margin: 14px 0 6px; }
.prose-editor .ProseMirror p { margin: 8px 0; }
.prose-editor .ProseMirror ul, .prose-editor .ProseMirror ol { padding-left: 1.4em; margin: 8px 0; }
.prose-editor .ProseMirror img { max-width: 100%; border-radius: 10px; margin: 12px 0; }
.prose-editor .ProseMirror blockquote { border-left: 4px solid #087C68; padding-left: 14px; color: #64748b; font-style: italic; }
.prose-editor .ProseMirror a { color: #087C68; text-decoration: underline; }
.prose-editor .ProseMirror p.is-editor-empty:first-child::before { content: attr(data-placeholder); color: #adb5bd; float: left; height: 0; pointer-events: none; }
</style>
