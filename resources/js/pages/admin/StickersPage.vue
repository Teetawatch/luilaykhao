<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-face-smile-wink st-title-icon"></i> สติกเกอร์ในห้องแชท</h1>
        <p class="page-subtitle">สติกเกอร์ของแบรนด์ที่ลูกทริปและทีมงานส่งในห้องแชททริป — อัปโหลดแล้วขึ้นในแอปทันที</p>
      </div>
      <button class="btn-primary" @click="createPack">
        <i class="fas fa-plus"></i> สร้างชุดใหม่
      </button>
    </div>

    <div class="st-tips">
      <i class="fas fa-lightbulb"></i>
      <div>
        <strong>ไฟล์ที่เหมาะ:</strong> PNG หรือ WebP พื้นใส ขนาดราว 512×512 (ภาพใหญ่กว่านี้ระบบย่อให้เอง)
        ภาพเคลื่อนไหวใช้ GIF/WebP ได้ ไม่เกิน 2 MB ต่อไฟล์ ·
        <strong>ตั้งชื่อไฟล์เป็นคำที่สติกเกอร์สื่อ</strong> เช่น <code>01-รับทราบ.png</code> ระบบใช้เป็นป้ายให้เอง
        (เลขนำหน้าใช้เรียงลำดับ ไม่ขึ้นในป้าย) ป้ายนี้คือข้อความที่คนใช้แอปรุ่นเก่าและเว็บจะเห็นแทนรูป
      </div>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <div v-else-if="!packs.length" class="table-card st-empty">
      <i class="fas fa-face-smile"></i>
      <p>ยังไม่มีชุดสติกเกอร์ — กด "สร้างชุดใหม่" แล้วลากไฟล์ทั้งหมดมาวางได้เลย</p>
    </div>

    <div v-else class="st-packs">
      <section
        v-for="(pack, pIndex) in packs"
        :key="pack.id"
        class="table-card st-pack"
        :class="{ 'st-pack-off': !pack.is_active }"
      >
        <header class="st-pack-head">
          <div class="st-pack-title">
            <h2>{{ pack.name }}</h2>
            <span class="st-count">{{ pack.stickers.length }} ตัว</span>
            <span class="status-pill" :class="pack.is_active ? 'pill-on' : 'pill-off'">
              {{ pack.is_active ? 'เปิดให้ใช้' : 'ซ่อนอยู่' }}
            </span>
          </div>
          <div class="action-btns">
            <button class="btn-icon" :disabled="pIndex === 0" title="เลื่อนขึ้น" @click="movePack(pIndex, -1)">
              <i class="fas fa-arrow-up"></i>
            </button>
            <button class="btn-icon" :disabled="pIndex === packs.length - 1" title="เลื่อนลง" @click="movePack(pIndex, 1)">
              <i class="fas fa-arrow-down"></i>
            </button>
            <button class="btn-icon" :title="pack.is_active ? 'ซ่อนทั้งชุด' : 'เปิดทั้งชุด'" @click="togglePack(pack)">
              <i class="fas" :class="pack.is_active ? 'fa-eye-slash' : 'fa-eye'"></i>
            </button>
            <button class="btn-icon btn-edit" title="เปลี่ยนชื่อชุด" @click="renamePack(pack)">
              <i class="fas fa-pen"></i>
            </button>
            <button class="btn-icon btn-delete" title="ลบทั้งชุด" @click="deletePack(pack)">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </header>

        <div class="st-grid">
          <button
            v-for="(s, sIndex) in pack.stickers"
            :key="s.id"
            type="button"
            class="st-tile"
            :class="{ 'st-tile-off': !s.is_active }"
            :title="s.keywords ? `${s.label} · ${s.keywords}` : s.label"
            @click="openSticker(pack, s, sIndex)"
          >
            <span class="st-img-wrap"><img :src="s.url" :alt="s.label" loading="lazy" /></span>
            <span class="st-label">{{ s.label }}</span>
            <span class="st-meta">
              <span v-if="s.is_animated" class="st-badge">เคลื่อนไหว</span>
              <span v-if="!s.is_active" class="st-badge st-badge-off">ซ่อน</span>
              <span v-if="s.uses" class="st-uses">ส่งแล้ว {{ s.uses }}</span>
            </span>
          </button>

          <label
            class="st-drop"
            :class="{ 'st-drop-active': dragPackId === pack.id, 'st-drop-busy': uploadingPackId === pack.id }"
            @dragover.prevent="dragPackId = pack.id"
            @dragleave.prevent="dragPackId = null"
            @drop.prevent="onDrop(pack, $event)"
          >
            <input type="file" multiple :accept="accept" class="st-file" @change="onPick(pack, $event)" />
            <template v-if="uploadingPackId === pack.id">
              <i class="fas fa-spinner fa-spin"></i>
              <span>กำลังอัปโหลด {{ uploadProgress }}%</span>
            </template>
            <template v-else>
              <i class="fas fa-cloud-arrow-up"></i>
              <span>ลากไฟล์มาวาง<br />หรือคลิกเลือกหลายไฟล์</span>
            </template>
          </label>
        </div>
      </section>
    </div>

    <!-- แก้สติกเกอร์ -->
    <div v-if="editing" class="modal-overlay" @click.self="closeEditor">
      <div class="modal-card" style="max-width:520px">
        <div class="modal-header">
          <h2>แก้ไขสติกเกอร์</h2>
          <button class="modal-close" @click="closeEditor"><i class="fas fa-xmark"></i></button>
        </div>
        <form @submit.prevent="saveSticker">
          <div class="modal-body">
            <div class="st-edit-top">
              <span class="st-img-wrap st-img-lg"><img :src="editing.sticker.url" :alt="editing.sticker.label" /></span>
              <div class="st-edit-side">
                <label class="btn-secondary st-replace">
                  <input type="file" :accept="accept" class="st-file" @change="replaceImage" />
                  <i class="fas" :class="replacing ? 'fa-spinner fa-spin' : 'fa-image'"></i> เปลี่ยนรูป
                </label>
                <div class="st-move">
                  <button type="button" class="btn-icon" :disabled="editing.index === 0" title="เลื่อนไปซ้าย" @click="moveSticker(-1)">
                    <i class="fas fa-arrow-left"></i>
                  </button>
                  <span>ลำดับที่ {{ editing.index + 1 }}</span>
                  <button
                    type="button"
                    class="btn-icon"
                    :disabled="editing.index === editing.pack.stickers.length - 1"
                    title="เลื่อนไปขวา"
                    @click="moveSticker(1)"
                  >
                    <i class="fas fa-arrow-right"></i>
                  </button>
                </div>
                <p class="st-hint">ส่งไปแล้ว {{ editing.sticker.uses }} ครั้ง</p>
              </div>
            </div>

            <div class="form-group">
              <label>ป้าย (คำที่สติกเกอร์สื่อ)</label>
              <input v-model="form.label" maxlength="40" required placeholder="เช่น รับทราบ" />
              <p class="st-hint">คนที่ยังใช้แอปรุ่นเก่า/เว็บจะเห็นเป็น “[สติกเกอร์] {{ form.label || '…' }}”</p>
            </div>
            <div class="form-group">
              <label>คำค้นเพิ่มเติม <span class="st-optional">(คั่นด้วยจุลภาค)</span></label>
              <input v-model="form.keywords" maxlength="255" placeholder="เช่น โอเค, ok, ได้เลย" />
              <p class="st-hint">ลูกทริปพิมพ์คำเหล่านี้ แอปเสนอสติกเกอร์ตัวนี้ให้กดส่งแทน</p>
            </div>
            <div class="form-group">
              <label>ย้ายไปชุด</label>
              <select v-model="form.pack_id">
                <option v-for="p in packs" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <label class="st-check">
              <input v-model="form.is_active" type="checkbox" />
              เปิดให้ใช้ (ปิดแล้วข้อความเก่ายังแสดงรูปตามเดิม แค่เลือกส่งใหม่ไม่ได้)
            </label>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-danger" :disabled="saving" @click="deleteSticker">
              <i class="fas fa-trash"></i> ลบ
            </button>
            <span class="st-spacer"></span>
            <button type="button" class="btn-secondary" @click="closeEditor">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="saving || !form.label.trim()">
              <i v-if="saving" class="fas fa-spinner fa-spin"></i> บันทึก
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// สติกเกอร์ในห้องแชท — ดู StickerService (ย่อภาพนิ่งเหลือ 512px คงพื้นใส
// ภาพเคลื่อนไหวเก็บตามต้นฉบับ) ลูกค้าเห็นผ่าน GET chat/stickers

const toast = useToast();
const swal = useSwal();

const accept = 'image/png,image/webp,image/gif,image/jpeg';
const MAX_BATCH = 40;

const packs = ref([]);
const loading = ref(true);
const dragPackId = ref(null);
const uploadingPackId = ref(null);
const uploadProgress = ref(0);

const editing = ref(null);
const saving = ref(false);
const replacing = ref(false);
const form = reactive({ label: '', keywords: '', is_active: true, pack_id: null });

function errorText(e, fallback) {
  const errors = e.response?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e.response?.data?.message || fallback;
}

async function load() {
  try {
    const res = await api.get('/admin/sticker-packs');
    packs.value = res.data.data?.packs || [];
    // หน้าต่างแก้ไขที่เปิดค้างอยู่ชี้ไปที่ข้อมูลชุดใหม่
    if (editing.value) {
      const pack = packs.value.find((p) => p.id === editing.value.pack.id);
      const index = pack ? pack.stickers.findIndex((s) => s.id === editing.value.sticker.id) : -1;
      editing.value = index >= 0 ? { pack, sticker: pack.stickers[index], index } : null;
    }
  } catch (e) {
    toast.error(errorText(e, 'โหลดสติกเกอร์ไม่สำเร็จ'));
  } finally {
    loading.value = false;
  }
}

async function createPack() {
  const res = await swal.confirm({
    title: 'สร้างชุดสติกเกอร์',
    input: 'text',
    inputPlaceholder: 'เช่น ลุยเลยเขา',
    inputAttributes: { maxlength: 60 },
    icon: null,
    confirmText: 'สร้าง',
    inputValidator: (v) => (!v || !v.trim() ? 'ตั้งชื่อชุดก่อน' : undefined),
  });
  if (!res.isConfirmed) return;
  try {
    await api.post('/admin/sticker-packs', { name: res.value.trim() });
    toast.success('สร้างชุดแล้ว ลากไฟล์สติกเกอร์มาวางในชุดได้เลย');
    await load();
  } catch (e) {
    toast.error(errorText(e, 'สร้างชุดไม่สำเร็จ'));
  }
}

async function renamePack(pack) {
  const res = await swal.confirm({
    title: 'เปลี่ยนชื่อชุด',
    input: 'text',
    inputValue: pack.name,
    inputAttributes: { maxlength: 60 },
    icon: null,
    confirmText: 'บันทึก',
    inputValidator: (v) => (!v || !v.trim() ? 'ชื่อชุดว่างไม่ได้' : undefined),
  });
  if (!res.isConfirmed) return;
  try {
    await api.put(`/admin/sticker-packs/${pack.id}`, { name: res.value.trim() });
    await load();
  } catch (e) {
    toast.error(errorText(e, 'บันทึกไม่สำเร็จ'));
  }
}

async function togglePack(pack) {
  try {
    await api.put(`/admin/sticker-packs/${pack.id}`, { is_active: !pack.is_active });
    toast.success(pack.is_active ? 'ซ่อนชุดนี้จากแอปแล้ว' : 'เปิดชุดนี้ในแอปแล้ว');
    await load();
  } catch (e) {
    toast.error(errorText(e, 'บันทึกไม่สำเร็จ'));
  }
}

async function deletePack(pack) {
  const used = pack.stickers.reduce((sum, s) => sum + (s.uses || 0), 0);
  const res = await swal.confirm({
    title: `ลบชุด “${pack.name}”?`,
    text: used
      ? `สติกเกอร์ชุดนี้ถูกส่งไปแล้ว ${used} ครั้ง ข้อความเหล่านั้นจะเหลือแค่ป้ายตัวหนังสือ ถ้าแค่ไม่อยากให้เลือกใหม่ ใช้ปุ่มซ่อนแทน`
      : 'ลบสติกเกอร์ทั้งหมดในชุดนี้ ย้อนกลับไม่ได้',
    icon: 'warning',
    confirmText: 'ลบทั้งชุด',
  });
  if (!res.isConfirmed) return;
  try {
    await api.delete(`/admin/sticker-packs/${pack.id}`);
    toast.success('ลบชุดแล้ว');
    await load();
  } catch (e) {
    toast.error(errorText(e, 'ลบไม่สำเร็จ'));
  }
}

async function movePack(index, direction) {
  const next = [...packs.value];
  const target = index + direction;
  [next[index], next[target]] = [next[target], next[index]];
  packs.value = next;
  try {
    await api.post('/admin/sticker-packs/reorder', { ids: next.map((p) => p.id) });
  } catch (e) {
    toast.error(errorText(e, 'จัดลำดับไม่สำเร็จ'));
    await load();
  }
}

function onDrop(pack, event) {
  dragPackId.value = null;
  upload(pack, Array.from(event.dataTransfer?.files || []));
}

function onPick(pack, event) {
  upload(pack, Array.from(event.target.files || []));
  event.target.value = '';
}

async function upload(pack, files) {
  const images = files.filter((f) => accept.split(',').includes(f.type));
  if (images.length < files.length) {
    toast.warning(`ข้ามไฟล์ที่ไม่ใช่รูป ${files.length - images.length} ไฟล์`);
  }
  if (!images.length || uploadingPackId.value) return;

  // อัปเป็นก้อนละไม่เกิน MAX_BATCH ไฟล์ — ตรงกับเพดานฝั่งเซิร์ฟเวอร์
  uploadingPackId.value = pack.id;
  uploadProgress.value = 0;
  let done = 0;
  try {
    for (let i = 0; i < images.length; i += MAX_BATCH) {
      const chunk = images.slice(i, i + MAX_BATCH);
      const data = new FormData();
      chunk.forEach((f) => data.append('files[]', f));
      await api.post(`/admin/sticker-packs/${pack.id}/stickers`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (e) => {
          const chunkShare = e.total ? e.loaded / e.total : 0;
          uploadProgress.value = Math.round(((i + chunk.length * chunkShare) / images.length) * 100);
        },
      });
      done += chunk.length;
    }
    toast.success(`เพิ่มสติกเกอร์ ${done} ตัวแล้ว ตรวจป้ายให้ตรงกับรูปด้วยนะ`);
  } catch (e) {
    toast.error(errorText(e, done ? `อัปได้ ${done} ตัว ที่เหลืออัปไม่สำเร็จ` : 'อัปโหลดไม่สำเร็จ'));
  } finally {
    uploadingPackId.value = null;
    await load();
  }
}

function openSticker(pack, sticker, index) {
  editing.value = { pack, sticker, index };
  Object.assign(form, {
    label: sticker.label,
    keywords: sticker.keywords || '',
    is_active: !!sticker.is_active,
    pack_id: pack.id,
  });
}

function closeEditor() {
  editing.value = null;
}

async function saveSticker() {
  if (!editing.value || saving.value) return;
  saving.value = true;
  try {
    await api.put(`/admin/stickers/${editing.value.sticker.id}`, {
      label: form.label.trim(),
      keywords: form.keywords,
      is_active: form.is_active,
      pack_id: form.pack_id,
    });
    toast.success('บันทึกแล้ว');
    editing.value = null;
    await load();
  } catch (e) {
    toast.error(errorText(e, 'บันทึกไม่สำเร็จ'));
  } finally {
    saving.value = false;
  }
}

async function replaceImage(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || !editing.value) return;
  replacing.value = true;
  try {
    const data = new FormData();
    data.append('file', file);
    await api.post(`/admin/stickers/${editing.value.sticker.id}/image`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    toast.success('เปลี่ยนรูปแล้ว ข้อความเก่าที่ส่งตัวนี้จะเห็นรูปใหม่ด้วย');
    await load();
  } catch (e) {
    toast.error(errorText(e, 'เปลี่ยนรูปไม่สำเร็จ'));
  } finally {
    replacing.value = false;
  }
}

async function moveSticker(direction) {
  if (!editing.value) return;
  const { pack, index } = editing.value;
  const list = [...pack.stickers];
  const target = index + direction;
  [list[index], list[target]] = [list[target], list[index]];
  pack.stickers = list;
  editing.value = { ...editing.value, index: target };
  try {
    await api.post(`/admin/sticker-packs/${pack.id}/stickers/reorder`, { ids: list.map((s) => s.id) });
  } catch (e) {
    toast.error(errorText(e, 'จัดลำดับไม่สำเร็จ'));
    await load();
  }
}

async function deleteSticker() {
  if (!editing.value) return;
  const s = editing.value.sticker;
  const res = await swal.confirm({
    title: `ลบสติกเกอร์ “${s.label}”?`,
    text: s.uses
      ? `ถูกส่งไปแล้ว ${s.uses} ครั้ง ข้อความเหล่านั้นจะเหลือแค่ “[สติกเกอร์] ${s.label}” ถ้าแค่ไม่อยากให้เลือกใหม่ ให้ปิด "เปิดให้ใช้" แทน`
      : 'ลบแล้วย้อนกลับไม่ได้',
    icon: 'warning',
    confirmText: 'ลบ',
  });
  if (!res.isConfirmed) return;
  try {
    await api.delete(`/admin/stickers/${s.id}`);
    toast.success('ลบสติกเกอร์แล้ว');
    editing.value = null;
    await load();
  } catch (e) {
    toast.error(errorText(e, 'ลบไม่สำเร็จ'));
  }
}

onMounted(load);
</script>

<style scoped>
@import url('./admin-shared.css');

.st-title-icon { color: var(--color-accent); font-size: 26px; margin-right: 4px; }

.st-tips {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 12px;
  padding: 12px 16px;
  margin-bottom: 18px;
  font-size: 13px;
  line-height: 1.7;
  color: #166534;
}
.st-tips > i { margin-top: 4px; }
.st-tips code { background: #dcfce7; padding: 1px 6px; border-radius: 6px; }

.st-empty { text-align: center; padding: 48px 16px; color: #6b7280; }
.st-empty > i { font-size: 40px; color: #d1d5db; display: block; margin-bottom: 12px; }

.st-packs { display: flex; flex-direction: column; gap: 18px; }
.st-pack { padding: 16px 18px 18px; }
.st-pack-off { opacity: 0.7; }
.st-pack-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.st-pack-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.st-pack-title h2 { font-size: 17px; font-weight: 800; color: #111827; margin: 0; }
.st-count { font-size: 12px; color: #6b7280; font-weight: 600; }

.status-pill { padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.pill-on { background: #d1fae5; color: #047857; }
.pill-off { background: #f3f4f6; color: #6b7280; }

.st-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
  gap: 12px;
}

.st-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px 8px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #fff;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
  min-width: 0;
}
.st-tile:hover { border-color: var(--color-accent); background: #f9fafb; }
.st-tile-off { opacity: 0.45; }

/* ตารางหมากรุกด้านหลัง ให้เห็นว่ารูปพื้นใสจริงไหม */
.st-img-wrap {
  width: 92px;
  height: 92px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #fff;
  background-image:
    linear-gradient(45deg, #f1f5f9 25%, transparent 25%),
    linear-gradient(-45deg, #f1f5f9 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, #f1f5f9 75%),
    linear-gradient(-45deg, transparent 75%, #f1f5f9 75%);
  background-size: 16px 16px;
  background-position: 0 0, 0 8px, 8px -8px, -8px 0;
}
.st-img-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }
.st-img-lg { width: 160px; height: 160px; flex-shrink: 0; }

.st-label {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.st-meta { display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; min-height: 18px; }
.st-badge { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 6px; background: #e0f2fe; color: #0369a1; }
.st-badge-off { background: #f3f4f6; color: #6b7280; }
.st-uses { font-size: 11px; color: #6b7280; }

.st-drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 150px;
  border: 2px dashed #d1d5db;
  border-radius: 12px;
  color: #6b7280;
  font-size: 12px;
  font-weight: 600;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.st-drop > i { font-size: 22px; color: #9ca3af; }
.st-drop:hover, .st-drop-active { border-color: var(--color-accent); background: #f0fdf4; }
.st-drop-busy { pointer-events: none; opacity: 0.8; }
.st-file { display: none; }

.st-edit-top { display: flex; gap: 16px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
.st-edit-side { display: flex; flex-direction: column; gap: 10px; }
.st-replace { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.st-move { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #374151; font-weight: 600; }
.st-hint { font-size: 12px; color: #6b7280; margin-top: 4px; }
.st-optional { font-weight: 400; color: #9ca3af; }
.st-check { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; color: #374151; cursor: pointer; }
.st-check input { margin-top: 3px; }
.st-spacer { flex: 1; }
.modal-footer { display: flex; gap: 8px; align-items: center; }
</style>
