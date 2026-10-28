<template>
  <div class="admin-page">
    <!-- ─── รายการแบบฟอร์ม ─────────────────────────── -->
    <template v-if="!editing">
      <div class="page-header">
        <div>
          <h1 class="page-title"><i class="fas fa-file-signature pf-title-icon"></i> แบบฟอร์มอุทยาน</h1>
          <p class="page-subtitle">อัปโหลดแบบคำขออนุญาตของอุทยาน วางช่องครั้งเดียว แล้วโหลด PDF ที่กรอกข้อมูลลูกทริปให้ครบทั้งรอบ</p>
        </div>
        <button class="btn-primary" @click="openUpload">
          <i class="fas fa-upload"></i> อัปโหลดแบบฟอร์ม
        </button>
      </div>

      <div class="pf-tips">
        <i class="fas fa-lightbulb"></i>
        <div>
          ขั้นตอน: <strong>อัปโหลด PDF</strong> (หรือรูปสแกน) → <strong>วางช่อง</strong>บนเส้นประ → <strong>ติ๊กทริป</strong>ที่ใช้ฟอร์มนี้ →
          โหลด PDF ได้จากปุ่ม <strong>รายชื่อผู้โดยสาร</strong> ในหน้ารอบเดินทาง หรือหน้ารายละเอียดการจองทีละใบ ·
          ช่องที่ลูกค้ายังไม่ได้กรอก (เช่น ศาสนา ที่อยู่) จะเว้นว่างไว้ให้เขียนเอง ·
          ฟอร์มปีใหม่ใช้ปุ่ม <strong>คัดลอกช่องจากฟอร์มอื่น</strong> ได้ถ้าหน้าตาเหมือนเดิม
        </div>
      </div>

      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

      <div v-else-if="!forms.length" class="table-card pf-empty">
        <i class="fas fa-file-signature"></i>
        <p>ยังไม่มีแบบฟอร์ม — กด "อัปโหลดแบบฟอร์ม"</p>
      </div>

      <div v-else class="pf-grid">
        <div v-for="f in forms" :key="f.id" class="table-card pf-card">
          <div class="pf-card-head">
            <h3>{{ f.name }}</h3>
            <span class="pf-meta">{{ f.pages.length }} หน้า · {{ f.fields.length }} ช่อง</span>
          </div>
          <p v-if="f.note" class="pf-note">{{ f.note }}</p>
          <div class="pf-trips">
            <span v-for="t in f.trips" :key="t.id" class="pf-chip">{{ t.title }}</span>
            <span v-if="!f.trips.length" class="pf-muted">ยังไม่ได้ผูกกับทริป</span>
          </div>
          <div class="pf-card-actions">
            <button class="btn-primary" @click="openEditor(f)"><i class="fas fa-pen-ruler"></i> วางช่อง / ผูกทริป</button>
            <button class="btn-secondary" :disabled="previewing" @click="preview(f, f.fields)"><i class="fas fa-eye"></i> ตัวอย่าง</button>
            <button class="btn-danger" @click="remove(f)"><i class="fas fa-trash"></i></button>
          </div>
        </div>
      </div>
    </template>

    <!-- ─── ตัววางช่อง ─────────────────────────── -->
    <template v-else>
      <div class="pf-editor-bar">
        <button class="btn-secondary" @click="closeEditor"><i class="fas fa-arrow-left"></i> กลับ</button>
        <input v-model="editing.name" class="pf-name-input" maxlength="255" @input="dirty = true" />
        <span v-if="dirty" class="pf-dirty">ยังไม่บันทึก</span>
        <div class="pf-bar-actions">
          <button class="btn-secondary" :disabled="previewing" @click="preview(editing, editing.fields)">
            <i class="fas" :class="previewing ? 'fa-spinner fa-spin' : 'fa-eye'"></i> ดูตัวอย่าง PDF
          </button>
          <button class="btn-primary" :disabled="saving" @click="save">
            <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i> บันทึก
          </button>
        </div>
      </div>

      <div class="pf-editor">
        <!-- หน้าแบบฟอร์ม -->
        <div ref="pagesEl" class="pf-pages" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointerleave="onPointerUp">
          <p class="pf-hint" :class="{ armed: armed }">
            <template v-if="armed">
              <i class="fas fa-crosshairs"></i> คลิกตรงต้นเส้นประที่จะวาง "{{ labelOf(armed) }}" · กด Esc เพื่อยกเลิก
            </template>
            <template v-else>
              เลือกช่องจากแผงขวา แล้วคลิกบนฟอร์ม · ลากเพื่อย้าย ลากมุมขวาล่างเพื่อขยาย · ปุ่มลูกศรขยับทีละนิด (Shift = ทีละมาก)
            </template>
          </p>
          <div
            v-for="(page, pi) in editing.pages"
            :key="pi"
            class="pf-page"
            :class="{ armed: armed }"
            :style="{ aspectRatio: `${page.width_mm} / ${page.height_mm}` }"
            :ref="(el) => (pageEls[pi] = el)"
            @click="placeArmed($event, pi)"
          >
            <img v-if="pageUrls[pi]" :src="pageUrls[pi]" alt="" draggable="false" />
            <div v-else class="pf-page-loading"><div class="spinner"></div></div>

            <div
              v-for="field in fieldsOnPage(pi)"
              :key="field.id"
              class="pf-field"
              :class="{ selected: selectedId === field.id, check: isCheck(field.source) }"
              :style="fieldStyle(field, page)"
              @pointerdown.stop="startDrag($event, field, pi, 'move')"
              @click.stop
            >
              <span class="pf-field-text" :style="{ textAlign: field.align }">{{ sampleOf(field) }}</span>
              <span class="pf-resize" @pointerdown.stop="startDrag($event, field, pi, 'resize')"></span>
            </div>
          </div>
        </div>

        <!-- แผงควบคุม -->
        <aside class="pf-side">
          <section v-if="selected" class="table-card pf-panel">
            <div class="pf-panel-head">
              <h4>ช่องที่เลือก</h4>
              <button class="btn-icon" title="ยกเลิกการเลือก" @click="selectedId = null"><i class="fas fa-xmark"></i></button>
            </div>
            <label class="pf-label">ข้อมูล</label>
            <select v-model="selected.source" @change="dirty = true">
              <optgroup v-for="g in catalogGroups" :key="g.name" :label="g.name">
                <option v-for="c in g.items" :key="c.key" :value="c.key">{{ c.label }}</option>
              </optgroup>
            </select>
            <template v-if="selected.source === 'static'">
              <label class="pf-label">ข้อความ (เหมือนกันทุกใบ)</label>
              <input v-model="selected.text" maxlength="300" placeholder="เช่น ชื่อบริษัทประกัน" @input="dirty = true" />
            </template>
            <div class="pf-row">
              <div>
                <label class="pf-label">ขนาดตัวอักษร (pt)</label>
                <input v-model.number="selected.size" type="number" min="5" max="36" step="0.5" @input="onSizeChange(selected)" />
              </div>
              <div v-if="!isCheck(selected.source)">
                <label class="pf-label">จัดวาง</label>
                <div class="pf-align">
                  <button v-for="a in ['left', 'center', 'right']" :key="a" :class="{ active: selected.align === a }" @click="selected.align = a; dirty = true">
                    <i class="fas" :class="`fa-align-${a}`"></i>
                  </button>
                </div>
              </div>
            </div>
            <div class="pf-panel-actions">
              <button class="btn-secondary" @click="duplicate(selected)"><i class="fas fa-clone"></i> ทำซ้ำ</button>
              <button class="btn-danger" @click="removeField(selected)"><i class="fas fa-trash"></i> ลบช่อง</button>
            </div>
          </section>

          <section class="table-card pf-panel">
            <h4>เพิ่มช่อง</h4>
            <div v-for="g in catalogGroups" :key="g.name" class="pf-palette-group">
              <p class="pf-group-name">{{ g.name }}</p>
              <div class="pf-palette">
                <button
                  v-for="c in g.items"
                  :key="c.key"
                  class="pf-pill"
                  :class="{ active: armed === c.key, used: usedSources.has(c.key) }"
                  @click="armed = armed === c.key ? null : c.key"
                >{{ c.label }}</button>
              </div>
            </div>
          </section>

          <section class="table-card pf-panel">
            <h4>ใช้กับทริป <span class="pf-muted">({{ editing.trip_ids.length }})</span></h4>
            <input v-model="tripSearch" placeholder="ค้นหาทริป" class="pf-trip-search" />
            <div class="pf-trip-list">
              <label v-for="t in filteredTrips" :key="t.id" class="pf-trip-row">
                <input type="checkbox" :value="t.id" v-model="editing.trip_ids" @change="dirty = true" />
                <span>{{ t.title }}</span>
                <small v-if="t.permit_form_id && t.permit_form_id !== editing.id" class="pf-muted">(ใช้ฟอร์มอื่นอยู่ — ติ๊กแล้วจะย้ายมา)</small>
              </label>
            </div>
          </section>

          <section class="table-card pf-panel">
            <h4>เครื่องมือ</h4>
            <label class="pf-label">คัดลอกช่องจากฟอร์มอื่น</label>
            <select :value="''" @change="copyFrom($event.target.value); $event.target.value = ''">
              <option value="">— เลือกแบบฟอร์ม —</option>
              <option v-for="f in otherForms" :key="f.id" :value="f.id">{{ f.name }} ({{ f.fields.length }} ช่อง)</option>
            </select>
            <div class="pf-panel-actions">
              <button class="btn-secondary" @click="exportJson"><i class="fas fa-copy"></i> คัดลอก JSON</button>
              <button class="btn-secondary" @click="importJson"><i class="fas fa-paste"></i> วาง JSON</button>
            </div>
            <label class="pf-label">หมายเหตุ</label>
            <input v-model="editing.note" maxlength="500" placeholder="เช่น ปีงบ 2570 ส่งทางอีเมลอุทยาน" @input="dirty = true" />
          </section>
        </aside>
      </div>
    </template>

    <!-- ─── อัปโหลด ─────────────────────────── -->
    <div v-if="showUpload" class="modal-overlay" @click.self="closeUpload">
      <div class="modal-card">
        <div class="modal-header">
          <h2>อัปโหลดแบบฟอร์ม</h2>
          <button class="modal-close" @click="closeUpload"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>ชื่อแบบฟอร์ม</label>
            <input v-model="upload.name" maxlength="255" placeholder="เช่น เขาช้างเผือก ปีงบ 2570" />
          </div>
          <div class="form-group">
            <label>ไฟล์ (PDF หรือรูปสแกน JPG/PNG — ไม่เกิน {{ MAX_PAGES }} หน้า)</label>
            <input type="file" accept="application/pdf,image/jpeg,image/png" multiple @change="onPickFiles" />
          </div>
          <p v-if="upload.rendering" class="pf-muted"><i class="fas fa-spinner fa-spin"></i> กำลังแปลงหน้า {{ upload.pages.length }}…</p>
          <p v-if="upload.error" class="pf-error">{{ upload.error }}</p>
          <div v-if="upload.pages.length" class="pf-thumbs">
            <img v-for="(p, i) in upload.pages" :key="i" :src="p.preview" alt="" />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="closeUpload">ยกเลิก</button>
          <button class="btn-primary" :disabled="!canUpload" @click="submitUpload">
            <i class="fas" :class="upload.saving ? 'fa-spinner fa-spin' : 'fa-upload'"></i> อัปโหลด
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// แบบฟอร์มอุทยาน — ดู PermitFormService/PermitFormFields ฝั่งเซิร์ฟเวอร์
// ตำแหน่งช่องเก็บเป็นสัดส่วน 0–1 ของหน้า ขนาดตัวอักษรเป็น pt (เทียบกระดาษจริง)
// ข้อความบนหน้าจอเป็นแค่ภาพประมาณ ปุ่ม "ดูตัวอย่าง PDF" คือของจริง

const MAX_PAGES = 10;
const RENDER_DPI = 200;
const PT_PER_MM = 72 / 25.4;
const LINE_HEIGHT = 1.15;

const toast = useToast();
const swal = useSwal();

const loading = ref(true);
const forms = ref([]);
const trips = ref([]);
const catalog = ref([]);

const editing = ref(null);
const pageUrls = ref([]);
const pageEls = [];
const selectedId = ref(null);
const armed = ref(null);
const dirty = ref(false);
const saving = ref(false);
const previewing = ref(false);
const tripSearch = ref('');
// ความกว้างหน้าบนจอ — ใช้แปลง pt เป็น px ให้ตัวอักษรในกล่องใกล้ขนาดใน PDF
const pagesEl = ref(null);
const pagesWidth = ref(0);
const resizeObserver = typeof ResizeObserver !== 'undefined'
  ? new ResizeObserver(([entry]) => { pagesWidth.value = entry.contentRect.width; })
  : null;

const catalogByKey = computed(() => Object.fromEntries(catalog.value.map((c) => [c.key, c])));
const catalogGroups = computed(() => {
  const groups = [];
  for (const c of catalog.value) {
    let g = groups.find((x) => x.name === c.group);
    if (!g) groups.push((g = { name: c.group, items: [] }));
    g.items.push(c);
  }
  return groups;
});
const selected = computed(() => editing.value?.fields.find((f) => f.id === selectedId.value) || null);
const usedSources = computed(() => new Set((editing.value?.fields || []).map((f) => f.source)));
const otherForms = computed(() => forms.value.filter((f) => f.id !== editing.value?.id && f.fields.length));
const filteredTrips = computed(() => {
  const q = tripSearch.value.trim().toLowerCase();
  const picked = new Set(editing.value?.trip_ids || []);
  return trips.value
    .filter((t) => !q || t.title.toLowerCase().includes(q))
    // ทริปที่ติ๊กแล้วขึ้นก่อน จะได้เห็นว่าผูกอะไรไว้บ้าง
    .sort((a, b) => Number(picked.has(b.id)) - Number(picked.has(a.id)));
});

function labelOf(key) { return catalogByKey.value[key]?.label || key; }
function isCheck(key) { return catalogByKey.value[key]?.type === 'check'; }
function sampleOf(field) {
  if (isCheck(field.source)) return '✓';
  if (field.source === 'static') return field.text || '(ข้อความ)';
  return catalogByKey.value[field.source]?.sample || labelOf(field.source);
}
function fieldsOnPage(pi) { return editing.value.fields.filter((f) => f.page === pi); }
function newId() { return Math.random().toString(36).slice(2, 10); }

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/permit-forms');
    forms.value = res.data.data.forms;
    trips.value = res.data.data.trips;
    catalog.value = res.data.data.catalog;
  } catch {
    toast.error('โหลดแบบฟอร์มไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

// ─── ตัววางช่อง ─────────────────────────────────

async function openEditor(form) {
  editing.value = {
    ...form,
    fields: form.fields.map((f) => ({ align: 'left', ...f })),
    trip_ids: form.trips.map((t) => t.id),
  };
  selectedId.value = null;
  armed.value = null;
  dirty.value = false;
  tripSearch.value = '';
  window.scrollTo({ top: 0 });
  await nextTick();
  if (pagesEl.value) {
    pagesWidth.value = pagesEl.value.clientWidth;
    resizeObserver?.observe(pagesEl.value);
  }

  revokePages();
  pageUrls.value = form.pages.map(() => null);
  form.pages.forEach(async (p, i) => {
    try {
      const res = await api.get(`/admin/permit-forms/${form.id}/pages/${p.number}`, { responseType: 'blob' });
      pageUrls.value[i] = URL.createObjectURL(res.data);
    } catch {
      toast.error(`โหลดภาพหน้า ${p.number} ไม่สำเร็จ`);
    }
  });
}

async function closeEditor() {
  if (dirty.value) {
    const res = await swal.confirm({ title: 'ออกโดยไม่บันทึก?', text: 'ช่องที่วางหรือแก้ไว้จะหายไป', confirmText: 'ออกเลย', icon: 'warning' });
    if (!res.isConfirmed) return;
  }
  resizeObserver?.disconnect();
  editing.value = null;
  revokePages();
}

function revokePages() {
  pageUrls.value.forEach((u) => u && URL.revokeObjectURL(u));
  pageUrls.value = [];
}

/** ความสูงกล่องที่พอดีหนึ่งบรรทัดของขนาดตัวอักษรนี้ (สัดส่วนของหน้า) */
function boxHeight(size, page) { return (size * LINE_HEIGHT * 1.2) / (page.height_mm * PT_PER_MM); }
function checkWidth(size, page) { return (size * 1.4) / (page.width_mm * PT_PER_MM); }

function placeArmed(event, pi) {
  if (!armed.value) {
    selectedId.value = null;
    return;
  }
  const page = editing.value.pages[pi];
  const rect = pageEls[pi].getBoundingClientRect();
  const px = (event.clientX - rect.left) / rect.width;
  const py = (event.clientY - rect.top) / rect.height;
  const check = isCheck(armed.value);
  const size = check ? 12 : 11;
  const h = boxHeight(size, page);
  const w = check ? checkWidth(size, page) : 0.18;

  // คลิกที่เส้นประ = ฐานของตัวอักษร กล่องจึงอยู่เหนือจุดคลิก; เครื่องหมายถูกวางกลางจุดคลิก
  const field = {
    id: newId(),
    page: pi,
    source: armed.value,
    x: clamp(check ? px - w / 2 : px, 0, 1 - w),
    y: clamp(check ? py - h / 2 : py - h * 0.85, 0, 1 - h),
    w, h, size,
    align: check ? 'center' : 'left',
    ...(armed.value === 'static' ? { text: '' } : {}),
  };
  editing.value.fields.push(field);
  selectedId.value = field.id;
  armed.value = null;
  dirty.value = true;
}

function fieldStyle(field, page) {
  const pxPerPt = (pagesWidth.value || 800) / (page.width_mm * PT_PER_MM);
  return {
    left: `${field.x * 100}%`,
    top: `${field.y * 100}%`,
    width: `${field.w * 100}%`,
    height: `${field.h * 100}%`,
    fontSize: `${field.size * pxPerPt}px`,
  };
}

function onSizeChange(field) {
  const page = editing.value.pages[field.page];
  if (!field.size || field.size < 5) return;
  field.h = Math.min(boxHeight(field.size, page), 1 - field.y);
  if (isCheck(field.source)) field.w = Math.min(checkWidth(field.size, page), 1 - field.x);
  dirty.value = true;
}

let drag = null;
function startDrag(event, field, pi, mode) {
  if (armed.value) return;
  selectedId.value = field.id;
  const rect = pageEls[pi].getBoundingClientRect();
  drag = { field, rect, mode, startX: event.clientX, startY: event.clientY, orig: { ...field } };
  event.target.setPointerCapture?.(event.pointerId);
}
function onPointerMove(event) {
  if (!drag) return;
  const { field, rect, mode, orig } = drag;
  const dx = (event.clientX - drag.startX) / rect.width;
  const dy = (event.clientY - drag.startY) / rect.height;
  if (mode === 'move') {
    field.x = clamp(orig.x + dx, 0, 1 - field.w);
    field.y = clamp(orig.y + dy, 0, 1 - field.h);
  } else {
    field.w = clamp(orig.w + dx, 0.01, 1 - field.x);
    field.h = clamp(orig.h + dy, 0.005, 1 - field.y);
  }
  if (dx || dy) dirty.value = true;
}
function onPointerUp() { drag = null; }

function onKey(event) {
  if (!editing.value) return;
  const tag = event.target?.tagName;
  if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
  if (event.key === 'Escape') {
    armed.value = null;
    selectedId.value = null;
    return;
  }
  const f = selected.value;
  if (!f) return;
  if (event.key === 'Delete' || event.key === 'Backspace') {
    event.preventDefault();
    removeField(f);
    return;
  }
  const step = event.shiftKey ? 0.005 : 0.001;
  const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
  if (moves[event.key]) {
    event.preventDefault();
    f.x = clamp(f.x + moves[event.key][0], 0, 1 - f.w);
    f.y = clamp(f.y + moves[event.key][1], 0, 1 - f.h);
    dirty.value = true;
  }
}

function duplicate(field) {
  const copy = { ...field, id: newId(), y: clamp(field.y + field.h * 1.5, 0, 1 - field.h) };
  editing.value.fields.push(copy);
  selectedId.value = copy.id;
  dirty.value = true;
}
function removeField(field) {
  editing.value.fields = editing.value.fields.filter((f) => f.id !== field.id);
  selectedId.value = null;
  dirty.value = true;
}

async function copyFrom(id) {
  const source = forms.value.find((f) => String(f.id) === String(id));
  if (!source) return;
  if (editing.value.fields.length) {
    const res = await swal.confirm({ title: 'แทนที่ช่องทั้งหมด?', text: `ช่องที่วางไว้ ${editing.value.fields.length} ช่องจะถูกแทนด้วยของ "${source.name}"`, confirmText: 'แทนที่' });
    if (!res.isConfirmed) return;
  }
  const pages = editing.value.pages.length;
  editing.value.fields = source.fields.filter((f) => f.page < pages).map((f) => ({ align: 'left', ...f, id: newId() }));
  selectedId.value = null;
  dirty.value = true;
}

async function exportJson() {
  try {
    await navigator.clipboard.writeText(JSON.stringify(editing.value.fields));
    toast.success(`คัดลอก ${editing.value.fields.length} ช่องแล้ว`);
  } catch {
    toast.error('คัดลอกไม่สำเร็จ');
  }
}
async function importJson() {
  const res = await swal.confirm({
    title: 'วาง JSON ของช่อง',
    input: 'textarea',
    inputPlaceholder: '[{"source":"first_name","page":0,"x":0.4,...}]',
    confirmText: 'นำเข้า',
    icon: null,
  });
  if (!res.isConfirmed || !res.value) return;
  try {
    const parsed = JSON.parse(res.value);
    if (!Array.isArray(parsed)) throw new Error();
    const pages = editing.value.pages.length;
    editing.value.fields = parsed
      .filter((f) => f && catalogByKey.value[f.source] && (f.page ?? 0) < pages)
      .map((f) => ({ align: 'left', size: 11, page: 0, ...f, id: newId() }));
    selectedId.value = null;
    dirty.value = true;
    toast.success(`นำเข้า ${editing.value.fields.length} ช่อง`);
  } catch {
    toast.error('JSON ไม่ถูกต้อง');
  }
}

async function save() {
  if (!editing.value.name?.trim()) {
    toast.error('ตั้งชื่อแบบฟอร์มก่อน');
    return;
  }
  saving.value = true;
  try {
    const res = await api.put(`/admin/permit-forms/${editing.value.id}`, {
      name: editing.value.name.trim(),
      note: editing.value.note || null,
      fields: editing.value.fields,
      trip_ids: editing.value.trip_ids,
    });
    const saved = res.data.data;
    const idx = forms.value.findIndex((f) => f.id === saved.id);
    if (idx >= 0) forms.value[idx] = saved;
    // ทริปที่ย้ายมาจากฟอร์มอื่น — ให้รายการฝั่งนั้นตรงกับความจริงด้วย
    const picked = new Set(editing.value.trip_ids);
    trips.value.forEach((t) => {
      if (picked.has(t.id)) t.permit_form_id = saved.id;
      else if (t.permit_form_id === saved.id) t.permit_form_id = null;
    });
    forms.value.forEach((f) => {
      if (f.id !== saved.id) f.trips = f.trips.filter((t) => !picked.has(t.id));
    });
    editing.value.fields = saved.fields.map((f) => ({ align: 'left', ...f }));
    dirty.value = false;
    toast.success('บันทึกแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function preview(form, fields) {
  // เปิดแท็บไว้ก่อนตอนยังอยู่ในจังหวะคลิก ไม่งั้นเบราว์เซอร์บล็อกป๊อปอัป
  const tab = window.open('', '_blank');
  previewing.value = true;
  try {
    const res = await api.post(`/admin/permit-forms/${form.id}/preview`, { fields }, { responseType: 'blob' });
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
    if (tab) tab.location.href = url;
    else window.location.href = url;
  } catch {
    tab?.close();
    toast.error('สร้างตัวอย่างไม่สำเร็จ');
  } finally {
    previewing.value = false;
  }
}

async function remove(form) {
  const res = await swal.confirm({
    title: `ลบ "${form.name}"?`,
    text: form.trips.length ? `ทริป ${form.trips.length} ทริปที่ใช้ฟอร์มนี้จะไม่มีแบบฟอร์มอุทยานแล้ว` : 'ลบแล้วกู้คืนไม่ได้',
    confirmText: 'ลบ',
    icon: 'warning',
  });
  if (!res.isConfirmed) return;
  try {
    await api.delete(`/admin/permit-forms/${form.id}`);
    forms.value = forms.value.filter((f) => f.id !== form.id);
    trips.value.forEach((t) => { if (t.permit_form_id === form.id) t.permit_form_id = null; });
    toast.success('ลบแล้ว');
  } catch {
    toast.error('ลบไม่สำเร็จ');
  }
}

// ─── อัปโหลด ─────────────────────────────────

const showUpload = ref(false);
const upload = reactive({ name: '', source: null, pages: [], rendering: false, saving: false, error: '' });
const canUpload = computed(() => upload.name.trim() && upload.pages.length && !upload.rendering && !upload.saving);

function openUpload() {
  Object.assign(upload, { name: '', source: null, pages: [], rendering: false, saving: false, error: '' });
  showUpload.value = true;
}
function closeUpload() {
  upload.pages.forEach((p) => URL.revokeObjectURL(p.preview));
  showUpload.value = false;
}

/**
 * pdf.js รุ่น legacy (มี polyfill — รุ่นปกติต้องใช้ Safari 17.4+/Chrome 119+) และให้ Vite
 * รวม worker เป็นไฟล์ .js เอง: ถ้าชี้ workerSrc ไปที่ไฟล์ .mjs nginx ค่าเริ่มต้นส่ง
 * MIME เป็น octet-stream เบราว์เซอร์ไม่ยอมรัน แล้วเปิด PDF ไม่ได้สักไฟล์
 */
async function loadPdfjs() {
  const [pdfjs, { default: PdfWorker }] = await Promise.all([
    import('pdfjs-dist/legacy/build/pdf.min.mjs'),
    import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker'),
  ]);
  pdfjs.GlobalWorkerOptions.workerPort ??= new PdfWorker();
  return pdfjs;
}

function canvasToJpeg(canvas) {
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
}

/**
 * เซิร์ฟเวอร์อ่าน PDF ที่บีบอัดแบบใหม่ไม่ได้ จึงแปลงเป็นภาพที่นี่ (200 dpi) แล้วส่งไป
 * พร้อมขนาดกระดาษจริงเป็นมิลลิเมตร
 */
async function onPickFiles(event) {
  const files = [...(event.target.files || [])];
  upload.pages.forEach((p) => URL.revokeObjectURL(p.preview));
  Object.assign(upload, { source: null, pages: [], error: '' });
  if (!files.length) return;

  upload.rendering = true;
  try {
    const pdf = files.find((f) => f.type === 'application/pdf');
    if (pdf) {
      upload.source = pdf;
      if (!upload.name) upload.name = pdf.name.replace(/\.pdf$/i, '');
      const pdfjs = await loadPdfjs();
      const doc = await pdfjs.getDocument({ data: await pdf.arrayBuffer() }).promise
        .catch((e) => { throw e?.name === 'PasswordException' ? new Error('locked') : e; });
      if (doc.numPages > MAX_PAGES) upload.error = `ไฟล์มี ${doc.numPages} หน้า ใช้แค่ ${MAX_PAGES} หน้าแรก`;
      for (let i = 1; i <= Math.min(doc.numPages, MAX_PAGES); i++) {
        const page = await doc.getPage(i);
        const base = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: RENDER_DPI / 72 });
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(viewport.width);
        canvas.height = Math.round(viewport.height);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport }).promise;
        const blob = await canvasToJpeg(canvas);
        upload.pages.push({
          blob,
          preview: URL.createObjectURL(blob),
          width_mm: base.width / PT_PER_MM,
          height_mm: base.height / PT_PER_MM,
        });
      }
      doc.destroy();
    } else {
      // รูปสแกน — ถือว่าเป็นกระดาษ A4 ตามแนวของรูป
      if (!upload.name) upload.name = files[0].name.replace(/\.(jpe?g|png)$/i, '');
      for (const file of files.slice(0, MAX_PAGES)) {
        const img = await loadImage(file);
        const landscape = img.naturalWidth > img.naturalHeight;
        const widthMm = landscape ? 297 : 210;
        upload.pages.push({
          blob: file,
          preview: URL.createObjectURL(file),
          width_mm: widthMm,
          height_mm: widthMm * (img.naturalHeight / img.naturalWidth),
        });
      }
    }
  } catch (e) {
    console.error(e);
    upload.pages.forEach((p) => URL.revokeObjectURL(p.preview));
    upload.pages = [];
    // แนบสาเหตุจริงไว้ท้ายข้อความ — ส่งต่อให้ทีมดูได้โดยไม่ต้องเปิด console
    upload.error = e?.message === 'locked'
      ? 'ไฟล์ PDF นี้ใส่รหัสผ่านไว้ ปลดรหัสก่อน หรือถ่ายรูป/สแกนเป็น JPG'
      : `เปิดไฟล์นี้ไม่ได้ ลองบันทึกเป็น PDF ใหม่ หรือถ่ายรูป/สแกนเป็น JPG (${e?.name || 'Error'}: ${e?.message || e})`;
  } finally {
    upload.rendering = false;
  }
}

function loadImage(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('image')); };
    img.src = url;
  });
}

async function submitUpload() {
  if (!canUpload.value) return;
  upload.saving = true;
  try {
    const data = new FormData();
    data.append('name', upload.name.trim());
    if (upload.source) data.append('source', upload.source);
    upload.pages.forEach((p, i) => {
      const ext = p.blob.type === 'image/png' ? 'png' : 'jpg';
      data.append(`pages[${i}]`, p.blob, `page-${i + 1}.${ext}`);
      data.append(`page_sizes[${i}][width_mm]`, p.width_mm.toFixed(2));
      data.append(`page_sizes[${i}][height_mm]`, p.height_mm.toFixed(2));
    });
    const res = await api.post('/admin/permit-forms', data);
    const form = res.data.data;
    forms.value.unshift(form);
    closeUpload();
    toast.success('อัปโหลดแล้ว — วางช่องได้เลย');
    openEditor(form);
  } catch (e) {
    upload.error = e.response?.data?.message || 'อัปโหลดไม่สำเร็จ';
  } finally {
    upload.saving = false;
  }
}

function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

function onBeforeUnload(event) {
  if (editing.value && dirty.value) event.preventDefault();
}

onMounted(() => {
  // ฟอนต์เดียวกับที่ใช้ใน PDF ให้ภาพบนจอใกล้ของจริง
  if (!document.getElementById('pf-sarabun')) {
    const link = document.createElement('link');
    link.id = 'pf-sarabun';
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Sarabun:wght@400&display=swap';
    document.head.appendChild(link);
  }
  window.addEventListener('keydown', onKey);
  window.addEventListener('beforeunload', onBeforeUnload);
  load();
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey);
  window.removeEventListener('beforeunload', onBeforeUnload);
  resizeObserver?.disconnect();
  revokePages();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.pf-title-icon { color: var(--color-accent); font-size: 24px; margin-right: 4px; }

.pf-tips {
  display: flex; gap: 12px; align-items: flex-start;
  background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px;
  padding: 12px 16px; margin-bottom: 18px;
  font-size: 13px; line-height: 1.7; color: #166534;
}
.pf-tips > i { margin-top: 4px; }

.pf-empty { text-align: center; padding: 48px 16px; color: #6b7280; }
.pf-empty i { font-size: 32px; margin-bottom: 8px; }

.pf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.pf-card { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.pf-card-head { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
.pf-card-head h3 { margin: 0; font-size: 16px; font-weight: 700; }
.pf-meta { font-size: 12px; color: #6b7280; white-space: nowrap; }
.pf-note { margin: 0; font-size: 13px; color: #4b5563; }
.pf-trips { display: flex; flex-wrap: wrap; gap: 6px; }
.pf-chip { font-size: 12px; padding: 3px 10px; border-radius: 999px; background: #f3f4f6; color: #374151; }
.pf-muted { color: #9ca3af; font-size: 12px; font-weight: 400; }
.pf-error { color: #b91c1c; font-size: 13px; }
.pf-card-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: auto; }

/* ─── ตัววางช่อง ─── */
.pf-editor-bar {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  margin-bottom: 14px;
}
.pf-name-input {
  flex: 1; min-width: 200px;
  font-size: 18px; font-weight: 700;
  border: 1px solid transparent; border-radius: 8px; padding: 6px 10px; background: transparent;
}
.pf-name-input:hover, .pf-name-input:focus { border-color: #d1d5db; background: #fff; outline: none; }
.pf-dirty { font-size: 12px; color: #b45309; background: #fef3c7; padding: 3px 10px; border-radius: 999px; }
.pf-bar-actions { display: flex; gap: 8px; }

.pf-editor { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start; }

.pf-hint { font-size: 13px; color: #6b7280; margin: 0 0 10px; }
.pf-hint.armed { color: #1d4ed8; font-weight: 600; }

.pf-page {
  position: relative; width: 100%;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 4px;
  margin-bottom: 16px; overflow: hidden;
  user-select: none; touch-action: none;
}
.pf-page.armed { cursor: crosshair; }
.pf-page img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; pointer-events: none; }
.pf-page-loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }

.pf-field {
  position: absolute;
  border: 1px dashed rgba(29, 78, 216, 0.55);
  background: rgba(219, 234, 254, 0.35);
  cursor: move;
  font-family: 'Sarabun', sans-serif;
  color: #12307a;
  line-height: 1.15;
  white-space: nowrap;
  overflow: visible;
}
.pf-page.armed .pf-field { pointer-events: none; }
.pf-field.selected { border: 1.5px solid #1d4ed8; background: rgba(191, 219, 254, 0.5); z-index: 2; }
.pf-field.check { font-family: 'DejaVu Sans', 'Segoe UI Symbol', sans-serif; }
.pf-field-text { display: block; width: 100%; margin-top: -0.1em; }
.pf-resize {
  position: absolute; right: -5px; bottom: -5px;
  width: 10px; height: 10px; border-radius: 2px;
  background: #1d4ed8; cursor: nwse-resize;
  opacity: 0;
}
.pf-field.selected .pf-resize { opacity: 1; }

.pf-side { display: flex; flex-direction: column; gap: 12px; }
.pf-panel { padding: 14px; display: flex; flex-direction: column; gap: 8px; }
.pf-panel h4 { margin: 0 0 2px; font-size: 14px; font-weight: 700; }
.pf-panel-head { display: flex; justify-content: space-between; align-items: center; }
.pf-panel select, .pf-panel input:not([type='checkbox']) {
  width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; background: #fff;
}
.pf-label { font-size: 12px; color: #6b7280; margin-top: 2px; }
.pf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pf-align { display: flex; border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; }
.pf-align button { flex: 1; padding: 7px 0; background: #fff; border: 0; color: #6b7280; cursor: pointer; }
.pf-align button + button { border-left: 1px solid #e5e7eb; }
.pf-align button.active { background: #eff6ff; color: #1d4ed8; }
.pf-panel-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.pf-palette-group + .pf-palette-group { margin-top: 4px; }
.pf-group-name { margin: 0 0 6px; font-size: 12px; color: #6b7280; font-weight: 600; }
.pf-palette { display: flex; flex-wrap: wrap; gap: 6px; }
.pf-pill {
  font-size: 12px; padding: 4px 10px; border-radius: 999px;
  border: 1px solid #e5e7eb; background: #fff; color: #374151; cursor: pointer;
}
.pf-pill.used { background: #f3f4f6; color: #9ca3af; }
.pf-pill.active { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }

.pf-trip-list { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; }
.pf-trip-row { display: flex; gap: 8px; align-items: baseline; font-size: 13px; padding: 4px 2px; cursor: pointer; }
.pf-trip-row small { display: block; }

.pf-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
.pf-thumbs img { width: 90px; border: 1px solid #e5e7eb; border-radius: 4px; }

@media (max-width: 1023px) {
  .pf-editor { grid-template-columns: 1fr; }
  .pf-side { order: -1; }
}
</style>
