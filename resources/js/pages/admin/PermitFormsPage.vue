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
          ฟอร์มปีใหม่ใช้ปุ่ม <strong>คัดลอกช่องจากฟอร์มอื่น</strong> ได้ถ้าหน้าตาเหมือนเดิม ·
          ช่องกลุ่ม <strong>"ที่อยู่ (ลูกค้ากรอกตอนเซ็น)"</strong> (ภูมิลำเนา โทรศัพท์บ้าน) เราไม่เก็บตอนจอง —
          วางไว้บนฟอร์มเมื่อไหร่ ลูกค้าต้องกรอกครบตอนเซ็น และลบทิ้งพร้อมลายเซ็นหลังจบทริป
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
            <span class="pf-meta">
              {{ f.pages.length }} หน้า · {{ f.fields.length }} ช่อง<template v-if="f.requires_signature"> · ลูกค้าเซ็นออนไลน์<template v-if="f.sign_due_days != null"> (ก่อน {{ f.sign_due_days }} วัน)</template></template>
            </span>
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
    <div v-else class="pf-workspace" :style="{ '--pf-bar-h': `${barHeight}px` }">
      <!-- แถบเครื่องมือลอย — ชื่อ/หน้า/บันทึก อยู่ในมือตลอด ไม่ว่าจะเลื่อนลงไปหน้าไหนของ PDF -->
      <div ref="barEl" class="pf-toolbar">
        <div class="pf-toolbar-row">
          <button class="pf-icon-btn" title="กลับไปรายการแบบฟอร์ม" @click="closeEditor"><i class="fas fa-arrow-left"></i></button>
          <div class="pf-title-block">
            <input v-model="editing.name" class="pf-name-input" maxlength="255" aria-label="ชื่อแบบฟอร์ม" @input="dirty = true" />
            <div class="pf-meta">
              <span>{{ editing.pages.length }} หน้า</span>
              <span class="pf-meta-sep"></span>
              <span>{{ editing.fields.length }} ช่อง</span>
              <span v-if="dirty" class="pf-status pf-status-dirty"><i class="fas fa-circle"></i> ยังไม่บันทึก</span>
              <span v-else class="pf-status pf-status-saved"><i class="fas fa-check"></i> บันทึกแล้ว</span>
            </div>
          </div>
          <nav v-if="editing.pages.length > 1" class="pf-page-nav" aria-label="ไปยังหน้า">
            <span class="pf-page-nav-label">หน้า</span>
            <button
              v-for="(p, pi) in editing.pages"
              :key="pi"
              :class="{ active: currentPage === pi }"
              :title="`ไปหน้า ${pi + 1} (${fieldsOnPage(pi).length} ช่อง)`"
              @click="scrollToPage(pi)"
            >
              {{ pi + 1 }}<span v-if="fieldsOnPage(pi).length" class="pf-page-dot"></span>
            </button>
          </nav>
          <div class="pf-bar-actions">
            <button class="btn-secondary" :disabled="previewing" @click="preview(editing, editing.fields)">
              <i class="fas" :class="previewing ? 'fa-spinner fa-spin' : 'fa-eye'"></i><span class="pf-hide-sm"> ดูตัวอย่าง PDF</span>
            </button>
            <button class="btn-primary" :disabled="saving" @click="save">
              <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i> บันทึก
            </button>
          </div>
        </div>
        <div class="pf-hint" :class="{ armed: armed }">
          <template v-if="armed">
            <i class="fas fa-crosshairs"></i>
            <span>คลิกตรงต้นเส้นประที่จะวาง <strong>"{{ labelOf(armed) }}"</strong> · กด Esc เพื่อยกเลิก</span>
            <button class="pf-hint-cancel" @click="armed = null">ยกเลิก</button>
          </template>
          <template v-else>
            <i class="fas fa-circle-info"></i>
            <span>เลือกช่องจากแผงขวา แล้วคลิกบนฟอร์ม · ลากเพื่อย้าย ลากมุมขวาล่างเพื่อขยาย · ปุ่มลูกศรขยับทีละนิด (Shift = ทีละมาก)</span>
          </template>
        </div>
      </div>

      <div class="pf-editor">
        <!-- หน้าแบบฟอร์ม -->
        <div ref="pagesEl" class="pf-pages">
          <section v-for="(page, pi) in editing.pages" :key="pi" class="pf-page-block" :ref="(el) => (pageBlockEls[pi] = el)">
            <header class="pf-page-label">
              <span>หน้า {{ pi + 1 }} / {{ editing.pages.length }}</span>
              <span class="pf-muted">{{ fieldsOnPage(pi).length }} ช่อง</span>
            </header>
            <div
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
                :class="{ selected: selectedId === field.id, check: isCheck(field.source), sig: isSignature(field.source), dragging: draggingId === field.id }"
                :style="fieldStyle(field, page)"
                @pointerdown.stop="startDrag($event, field, pi, 'move')"
                @click.stop
              >
                <span class="pf-field-text" :style="{ textAlign: field.align }">{{ sampleOf(field) }}</span>
                <span class="pf-resize" @pointerdown.stop="startDrag($event, field, pi, 'resize')"></span>
              </div>
            </div>
          </section>
        </div>

        <!-- แผงควบคุม — ลอยตามเมื่อเลื่อน เลื่อนในตัวเองเมื่อยาวกว่าจอ -->
        <aside ref="sideEl" class="pf-side">
          <section v-if="selected" class="table-card pf-panel pf-panel-selected">
            <div class="pf-panel-head">
              <h4><i class="fas fa-crop-simple"></i> ช่องที่เลือก <span class="pf-muted">· หน้า {{ selected.page + 1 }}</span></h4>
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
            <template v-else-if="selected.source === 'ask'">
              <!-- อุทยานแต่ละแห่งขอข้อมูลไม่เหมือนกัน — ตั้งคำถามเอง ลูกค้าตอบตอนเซ็น -->
              <label class="pf-label">คำถามที่ให้ลูกค้าตอบ</label>
              <input
                ref="questionInput"
                v-model="selected.question" maxlength="120" placeholder="เช่น ชื่อสถานที่ทำงาน"
                :class="{ 'pf-input-missing': !selected.question?.trim() }"
                @input="dirty = true"
              />
              <label class="pf-label">ตัวอย่างคำตอบ (ไม่บังคับ)</label>
              <input v-model="selected.hint" maxlength="100" placeholder="เช่น บริษัท ตัวอย่าง จำกัด" @input="dirty = true" />
              <label class="pf-check">
                <input type="checkbox" v-model="selected.allow_none" @change="dirty = true" />
                <span>ตอบ "ไม่มี" ได้ (เช่น นักเรียน หรือคนที่ไม่ได้ทำงาน) — พิมพ์ "-" ลงฟอร์ม</span>
              </label>
              <p class="pf-muted pf-help">ลูกค้าต้องตอบก่อนเซ็น คำถามเดียวกันวางหลายจุดได้ ลูกค้าตอบครั้งเดียว · แก้คำถามแล้วคนที่ตอบไปแล้วต้องตอบใหม่</p>
            </template>
            <div class="pf-row">
              <div v-if="!isSignature(selected.source)">
                <label class="pf-label">ขนาดตัวอักษร (pt)</label>
                <input v-model.number="selected.size" type="number" min="5" max="36" step="0.5" @input="onSizeChange(selected)" />
              </div>
              <p v-else class="pf-muted pf-help">ลากมุมขวาล่างเพื่อปรับขนาดกรอบ — ลายเซ็นถูกย่อให้พอดีกรอบและนั่งบนขอบล่าง</p>
              <div v-if="!isCheck(selected.source)">
                <label class="pf-label">จัดวาง</label>
                <div class="pf-align">
                  <button v-for="a in ['left', 'center', 'right']" :key="a" :class="{ active: selected.align === a }" @click="selected.align = a; dirty = true">
                    <i class="fas" :class="`fa-align-${a}`"></i>
                  </button>
                </div>
              </div>
            </div>
            <div v-if="editing.pages.length > 1">
              <label class="pf-label">อยู่หน้า</label>
              <select :value="selected.page" @change="moveToPage(selected, Number($event.target.value))">
                <option v-for="(p, pi) in editing.pages" :key="pi" :value="pi">หน้า {{ pi + 1 }}</option>
              </select>
              <p class="pf-muted pf-help">ลากข้ามหน้าได้ หรือเลือกหน้าตรงนี้ — ตำแหน่งบนหน้าเหมือนเดิม (กด "ทำซ้ำ" แล้วเลือกหน้า = วางช่องเดียวกันอีกหน้า)</p>
            </div>
            <div class="pf-panel-actions">
              <button class="btn-secondary" @click="duplicate(selected)"><i class="fas fa-clone"></i> ทำซ้ำ</button>
              <button class="btn-danger" @click="removeField(selected)"><i class="fas fa-trash"></i> ลบช่อง</button>
            </div>
          </section>

          <section class="table-card pf-panel pf-panel-tabs">
            <div class="pf-tabs" role="tablist">
              <button role="tab" :aria-selected="sideTab === 'fields'" :class="{ active: sideTab === 'fields' }" @click="sideTab = 'fields'">
                <i class="fas fa-plus"></i> เพิ่มช่อง
              </button>
              <button role="tab" :aria-selected="sideTab === 'trips'" :class="{ active: sideTab === 'trips' }" @click="sideTab = 'trips'">
                <i class="fas fa-route"></i> ทริป <span class="pf-tab-badge">{{ editing.trip_ids.length }}</span>
              </button>
              <button role="tab" :aria-selected="sideTab === 'settings'" :class="{ active: sideTab === 'settings' }" @click="sideTab = 'settings'">
                <i class="fas fa-sliders"></i> ตั้งค่า <span v-if="askedWithoutSignature" class="pf-tab-alert" title="มีเรื่องต้องแก้"></span>
              </button>
            </div>

            <div v-show="sideTab === 'fields'" class="pf-tab-body">
              <div class="pf-search">
                <i class="fas fa-magnifying-glass"></i>
                <input v-model="fieldSearch" type="search" placeholder="ค้นหาช่อง เช่น ชื่อ วันที่ ที่อยู่" />
              </div>
              <div v-for="g in filteredCatalogGroups" :key="g.name" class="pf-palette-group">
                <p class="pf-group-name">{{ g.name }}</p>
                <div class="pf-palette">
                  <button
                    v-for="c in g.items"
                    :key="c.key"
                    class="pf-pill"
                    :class="{ active: armed === c.key, used: usedSources.has(c.key) }"
                    :title="usedSources.has(c.key) ? 'วางไว้แล้ว — วางซ้ำได้' : ''"
                    @click="armed = armed === c.key ? null : c.key"
                  ><i v-if="usedSources.has(c.key)" class="fas fa-check"></i>{{ c.label }}</button>
                </div>
              </div>
              <p v-if="!filteredCatalogGroups.length" class="pf-muted pf-empty-search">ไม่พบช่องที่ตรงกับ "{{ fieldSearch }}"</p>
            </div>

            <div v-show="sideTab === 'trips'" class="pf-tab-body">
            <input v-model="tripSearch" placeholder="ค้นหาทริป" class="pf-trip-search" />
            <div class="pf-trip-list">
              <label v-for="t in filteredTrips" :key="t.id" class="pf-trip-row">
                <input type="checkbox" :value="t.id" v-model="editing.trip_ids" @change="dirty = true" />
                <span>{{ t.title }}</span>
                <small v-if="t.permit_form_id && t.permit_form_id !== editing.id" class="pf-muted">(ใช้ฟอร์มอื่นอยู่ — ติ๊กแล้วจะย้ายมา)</small>
              </label>
            </div>
            </div>

            <div v-show="sideTab === 'settings'" class="pf-tab-body">
            <label class="pf-label">คัดลอกช่องจากฟอร์มอื่น</label>
            <select :value="''" @change="copyFrom($event.target.value); $event.target.value = ''">
              <option value="">— เลือกแบบฟอร์ม —</option>
              <option v-for="f in otherForms" :key="f.id" :value="f.id">{{ f.name }} ({{ f.fields.length }} ช่อง)</option>
            </select>
            <div class="pf-panel-actions">
              <button class="btn-secondary" @click="exportJson"><i class="fas fa-copy"></i> คัดลอก JSON</button>
              <button class="btn-secondary" @click="importJson"><i class="fas fa-paste"></i> วาง JSON</button>
            </div>
            <template v-if="hasSignatureField">
              <label class="pf-label">ต้องเซ็นก่อนวันเดินทางกี่วัน (วันที่ต้องลงทะเบียนกับอุทยาน)</label>
              <input
                v-model.number="editing.sign_due_days"
                type="number" min="0" max="90" placeholder="เช่น 7 — เว้นว่าง = ไม่ส่งเตือน"
                @input="dirty = true"
              />
              <p class="pf-muted pf-help">ลูกค้าที่ยังไม่เซ็นจะได้แจ้งเตือนวันละครั้ง 2 วันก่อนถึงกำหนดจนถึงวันกำหนด</p>
            </template>
            <p v-else-if="askedWithoutSignature" class="pf-warn">
              <i class="fas fa-triangle-exclamation"></i>
              ฟอร์มนี้มีช่องที่อยู่/โทรศัพท์บ้าน/คำถามที่ตั้งเอง ซึ่งลูกค้ากรอกตอนเซ็นเท่านั้น แต่ยังไม่มีช่อง "ลายเซ็น"
              — ลูกค้าจะไม่ถูกถาม และช่องเหล่านี้จะว่างในไฟล์ วางช่องลายเซ็นด้วยครับ
            </p>
            <p v-else class="pf-muted pf-help">วางช่อง "ลายเซ็น" ถ้าอยากให้ลูกค้าเซ็นในแอป/เว็บ/ลิงก์ก่อนเดินทาง</p>
            <label class="pf-label">หมายเหตุ</label>
            <input v-model="editing.note" maxlength="500" placeholder="เช่น ปีงบ 2570 ส่งทางอีเมลอุทยาน" @input="dirty = true" />
            </div>
          </section>
        </aside>
      </div>
    </div>

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
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
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
const questionInput = ref(null);
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

// แถบเครื่องมือลอย + แผงขวาลอย — แผงขวาลอยใต้แถบ จึงต้องรู้ความสูงแถบ (ขึ้นบรรทัดใหม่ได้)
const barEl = ref(null);
const sideEl = ref(null);
const barHeight = ref(0);
const barObserver = typeof ResizeObserver !== 'undefined'
  ? new ResizeObserver(([entry]) => { barHeight.value = Math.round(entry.borderBoxSize?.[0]?.blockSize ?? entry.target.offsetHeight); })
  : null;
const pageBlockEls = [];
const currentPage = ref(0);
const sideTab = ref('fields');
const fieldSearch = ref('');

const catalogByKey = computed(() => Object.fromEntries(catalog.value.map((c) => [c.key, c])));
const filteredCatalogGroups = computed(() => {
  const q = fieldSearch.value.trim().toLowerCase();
  if (!q) return catalogGroups.value;
  return catalogGroups.value
    .map((g) => ({ ...g, items: g.items.filter((c) => c.label.toLowerCase().includes(q) || g.name.toLowerCase().includes(q)) }))
    .filter((g) => g.items.length);
});
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
// คำถามที่ตั้งเองวางได้หลายข้อ — ไม่ทำให้ปุ่มดูเหมือนใช้ไปแล้ว
const usedSources = computed(() => new Set((editing.value?.fields || []).map((f) => f.source).filter((s) => s !== 'ask')));
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
function isSignature(key) { return catalogByKey.value[key]?.type === 'signature'; }
const hasSignatureField = computed(() => (editing.value?.fields || []).some((f) => isSignature(f.source)));
// ช่องที่อยู่ถามลูกค้าตอนเซ็นเท่านั้น — ไม่มีช่องลายเซ็น = ไม่มีใครถาม ช่องจะว่างในฟอร์ม
const askedWithoutSignature = computed(() => !hasSignatureField.value
  && (editing.value?.fields || []).some((f) => catalogByKey.value[f.source]?.asks_customer));
function sampleOf(field) {
  if (isCheck(field.source)) return '✓';
  if (isSignature(field.source)) return '( ลายเซ็น )';
  if (field.source === 'static') return field.text || '(ข้อความ)';
  if (field.source === 'ask') return field.hint || field.question || '(ยังไม่ได้ตั้งคำถาม)';
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
  fieldSearch.value = '';
  sideTab.value = 'fields';
  currentPage.value = 0;
  pageBlockEls.length = 0;
  window.scrollTo({ top: 0 });
  await nextTick();
  if (pagesEl.value) {
    pagesWidth.value = pagesEl.value.clientWidth;
    resizeObserver?.observe(pagesEl.value);
  }
  if (barEl.value) {
    barHeight.value = barEl.value.offsetHeight;
    barObserver?.observe(barEl.value);
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
  barObserver?.disconnect();
  editing.value = null;
  revokePages();
}

// ─── เลื่อนระหว่างหน้า ──────────────────────────────

/** ระยะที่ถูกบังด้านบน = topbar ของหลังบ้าน + แถบเครื่องมือลอย */
function stickyOffset() {
  const topbar = document.querySelector('.admin-topbar')?.offsetHeight ?? 64;
  return topbar + barHeight.value + 12;
}

function scrollToPage(pi) {
  const el = pageBlockEls[pi];
  if (!el) return;
  currentPage.value = pi;
  window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - stickyOffset(), behavior: 'smooth' });
}

// หน้าที่กำลังดู = หน้าสุดท้ายที่ขอบบนเลยเส้นใต้แถบเครื่องมือลงมาแล้ว (เผื่อหนึ่งในสามจอ)
let scrollFrame = 0;
function onScroll() {
  if (scrollFrame || !editing.value) return;
  scrollFrame = requestAnimationFrame(() => {
    scrollFrame = 0;
    const line = stickyOffset() + window.innerHeight * 0.3;
    let page = 0;
    pageBlockEls.forEach((el, i) => {
      if (el && el.getBoundingClientRect().top <= line) page = i;
    });
    currentPage.value = page;
  });
}

// เลือกช่องแล้วแผงขวาเลื่อนกลับขึ้นบน — กล่อง "ช่องที่เลือก" อยู่ตรงนั้น
watch(selectedId, (id) => {
  if (id) sideEl.value?.scrollTo({ top: 0, behavior: 'smooth' });
});

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
  const sig = isSignature(armed.value);
  const size = check ? 12 : 11;
  // ลายเซ็นสูงราว 3 บรรทัด — ภาพถูกย่อให้พอดีกล่องโดยไม่บิดสัดส่วน
  const h = sig ? boxHeight(size, page) * 2.6 : boxHeight(size, page);
  const w = check ? checkWidth(size, page) : sig ? 0.28 : 0.18;

  // คลิกที่เส้นประ = ฐานของตัวอักษร กล่องจึงอยู่เหนือจุดคลิก; เครื่องหมายถูกวางกลางจุดคลิก;
  // ลายเซ็นนั่งบนเส้นประ (ขอบล่างของกล่องอยู่ที่จุดคลิก) และกึ่งกลางตามแนวนอน
  const field = {
    id: newId(),
    page: pi,
    source: armed.value,
    x: clamp(check || sig ? px - w / 2 : px, 0, 1 - w),
    y: clamp(check ? py - h / 2 : sig ? py - h : py - h * 0.85, 0, 1 - h),
    w, h, size,
    align: check || sig ? 'center' : 'left',
    ...(armed.value === 'static' ? { text: '' } : {}),
    ...(armed.value === 'ask' ? { question: '', hint: '', allow_none: false } : {}),
  };
  editing.value.fields.push(field);
  selectedId.value = field.id;
  if (field.source === 'ask') nextTick(() => questionInput.value?.focus());
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
  if (!field.size || field.size < 5 || isSignature(field.source)) return;
  field.h = Math.min(boxHeight(field.size, page), 1 - field.y);
  if (isCheck(field.source)) field.w = Math.min(checkWidth(field.size, page), 1 - field.x);
  dirty.value = true;
}

// ลาก — ฟังที่ window ระหว่างลาก (ไม่ใช่แค่พื้นที่หน้า) ช่องจึงลากข้ามหน้าได้: หน้าที่อยู่ใต้
// เมาส์คือหน้าของช่อง และจุดที่จับไว้บนกล่องอยู่ใต้เมาส์ตลอด ใกล้ขอบจอเลื่อนหน้าให้เอง
let drag = null;
const draggingId = ref(null);
const AUTO_SCROLL_ZONE = 64;
function startDrag(event, field, pi, mode) {
  if (armed.value || event.button > 0) return;
  event.preventDefault();
  selectedId.value = field.id;
  const rect = pageEls[pi].getBoundingClientRect();
  drag = {
    field, mode,
    pointerX: event.clientX, pointerY: event.clientY,
    // จุดที่จับ เทียบมุมซ้ายบนของกล่อง (สัดส่วนของหน้า) — ข้ามหน้าแล้วกล่องไม่กระโดด
    grabX: (event.clientX - rect.left) / rect.width - field.x,
    grabY: (event.clientY - rect.top) / rect.height - field.y,
    startX: event.clientX, startY: event.clientY, orig: { ...field }, rect,
    scrollFrame: 0,
  };
  draggingId.value = field.id;
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp);
  window.addEventListener('pointercancel', onPointerUp);
}

/** หน้าที่อยู่ใต้ตำแหน่งแนวตั้งนี้ — อยู่ช่องว่างระหว่างหน้าก็คงหน้าเดิม */
function pageAt(clientY, fallback) {
  const i = pageEls.findIndex((el) => {
    if (!el) return false;
    const r = el.getBoundingClientRect();
    return clientY >= r.top && clientY <= r.bottom;
  });
  return i === -1 ? fallback : i;
}

function applyDrag() {
  const { field, mode, pointerX, pointerY } = drag;
  if (mode === 'move') {
    const pi = pageAt(pointerY, field.page);
    const rect = pageEls[pi].getBoundingClientRect();
    const x = clamp((pointerX - rect.left) / rect.width - drag.grabX, 0, 1 - field.w);
    const y = clamp((pointerY - rect.top) / rect.height - drag.grabY, 0, 1 - field.h);
    if (pi !== field.page || x !== field.x || y !== field.y) dirty.value = true;
    field.page = pi;
    field.x = x;
    field.y = y;
  } else {
    const { rect, orig } = drag;
    field.w = clamp(orig.w + (pointerX - drag.startX) / rect.width, 0.01, 1 - field.x);
    field.h = clamp(orig.h + (pointerY - drag.startY) / rect.height, 0.005, 1 - field.y);
    dirty.value = true;
  }
}

function onPointerMove(event) {
  if (!drag) return;
  drag.pointerX = event.clientX;
  drag.pointerY = event.clientY;
  applyDrag();
  if (drag.mode === 'move' && !drag.scrollFrame) drag.scrollFrame = requestAnimationFrame(autoScroll);
}

// เมาส์ชิดขอบล่างจอ หรือชิดใต้แถบเครื่องมือ — เลื่อนหน้าไปเรื่อย ๆ ระหว่างที่ยังกดค้าง
function autoScroll() {
  if (!drag) return;
  drag.scrollFrame = 0;
  const top = stickyOffset();
  const y = drag.pointerY;
  let speed = 0;
  if (y > window.innerHeight - AUTO_SCROLL_ZONE) speed = (y - (window.innerHeight - AUTO_SCROLL_ZONE)) / AUTO_SCROLL_ZONE;
  else if (y < top + AUTO_SCROLL_ZONE) speed = -((top + AUTO_SCROLL_ZONE - y) / AUTO_SCROLL_ZONE);
  if (!speed) return;
  window.scrollBy(0, Math.max(-1, Math.min(1, speed)) * 22);
  applyDrag();
  drag.scrollFrame = requestAnimationFrame(autoScroll);
}

function onPointerUp() {
  if (drag?.scrollFrame) cancelAnimationFrame(drag.scrollFrame);
  drag = null;
  draggingId.value = null;
  window.removeEventListener('pointermove', onPointerMove);
  window.removeEventListener('pointerup', onPointerUp);
  window.removeEventListener('pointercancel', onPointerUp);
}

/** ย้ายช่องไปอีกหน้า ตำแหน่งบนหน้าเท่าเดิม (บีบให้อยู่ในหน้า) แล้วเลื่อนไปให้เห็น */
function moveToPage(field, pi) {
  if (pi === field.page || !editing.value.pages[pi]) return;
  field.page = pi;
  field.x = clamp(field.x, 0, 1 - field.w);
  field.y = clamp(field.y, 0, 1 - field.h);
  dirty.value = true;
  scrollToPage(pi);
}

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
  const noQuestion = editing.value.fields.find((f) => f.source === 'ask' && !f.question?.trim());
  if (noQuestion) {
    selectedId.value = noQuestion.id;
    nextTick(() => questionInput.value?.focus());
    toast.error('มีช่อง "คำถามที่ตั้งเอง" ที่ยังไม่ได้พิมพ์คำถาม');
    return;
  }
  saving.value = true;
  try {
    const res = await api.put(`/admin/permit-forms/${editing.value.id}`, {
      name: editing.value.name.trim(),
      note: editing.value.note || null,
      sign_due_days: hasSignatureField.value && editing.value.sign_due_days !== '' ? editing.value.sign_due_days ?? null : null,
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
  window.addEventListener('scroll', onScroll, { passive: true });
  load();
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey);
  window.removeEventListener('beforeunload', onBeforeUnload);
  window.removeEventListener('scroll', onScroll);
  if (scrollFrame) cancelAnimationFrame(scrollFrame);
  onPointerUp();
  resizeObserver?.disconnect();
  barObserver?.disconnect();
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
/* ─── พื้นที่ทำงาน: แถบเครื่องมือลอย + หน้าฟอร์ม + แผงขวาลอย ─── */
.pf-workspace {
  --pf-topbar: 64px;
  --pf-gutter: 28px;
  --pf-ink: #111827;
  --pf-muted: #6b7280;
  --pf-line: #e5e7eb;
  --pf-blue: #1d4ed8;
  --pf-blue-soft: #eff6ff;
}

.pf-toolbar {
  position: sticky;
  top: var(--pf-topbar);
  z-index: 20;
  margin: calc(var(--pf-gutter) * -1) calc(var(--pf-gutter) * -1) 18px;
  padding: 12px var(--pf-gutter) 0;
  background: #fff;
  border-bottom: 1px solid var(--pf-line);
}
.pf-toolbar-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }

.pf-icon-btn {
  width: 38px; height: 38px; flex: none;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--pf-line); border-radius: 10px; background: #fff;
  color: #374151; cursor: pointer; transition: background 0.15s, border-color 0.15s;
}
.pf-icon-btn:hover { background: #f9fafb; border-color: #d1d5db; }

.pf-title-block { flex: 1; min-width: 220px; }
.pf-name-input {
  width: 100%;
  font-size: 18px; font-weight: 700; color: var(--pf-ink);
  border: 1px solid transparent; border-radius: 8px; padding: 3px 8px; margin-left: -8px; background: transparent;
}
.pf-name-input:hover, .pf-name-input:focus { border-color: #d1d5db; background: #fff; outline: none; }
.pf-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; color: var(--pf-muted); margin-top: 1px; }
.pf-meta-sep { width: 3px; height: 3px; border-radius: 50%; background: #cbd5e1; }
.pf-status { display: inline-flex; align-items: center; gap: 5px; padding: 1px 9px; border-radius: 999px; font-weight: 600; }
.pf-status i { font-size: 7px; }
.pf-status-saved i { font-size: 10px; }
.pf-status-dirty { color: #b45309; background: #fef3c7; }
.pf-status-saved { color: #047857; background: #ecfdf5; }

.pf-page-nav {
  display: flex; align-items: center; gap: 4px;
  padding: 4px; border: 1px solid var(--pf-line); border-radius: 12px; background: #f9fafb;
  max-width: 100%; overflow-x: auto; scrollbar-width: none;
}
.pf-page-nav::-webkit-scrollbar { display: none; }
.pf-page-nav-label { font-size: 12px; color: var(--pf-muted); padding: 0 6px 0 4px; white-space: nowrap; }
.pf-page-nav button {
  position: relative; min-width: 32px; height: 30px; padding: 0 8px; flex: none;
  border: 0; border-radius: 8px; background: transparent;
  font-size: 13px; font-weight: 600; color: #4b5563; cursor: pointer;
  font-variant-numeric: tabular-nums; transition: background 0.15s, color 0.15s;
}
.pf-page-nav button:hover { background: #fff; color: var(--pf-ink); }
.pf-page-nav button.active { background: var(--pf-blue); color: #fff; }
.pf-page-dot { position: absolute; top: 4px; right: 4px; width: 5px; height: 5px; border-radius: 50%; background: #60a5fa; }
.pf-page-nav button.active .pf-page-dot { background: #bfdbfe; }

.pf-bar-actions { display: flex; gap: 8px; }

.pf-hint {
  display: flex; align-items: center; gap: 8px;
  margin: 10px calc(var(--pf-gutter) * -1) 0; padding: 7px var(--pf-gutter);
  font-size: 12.5px; color: var(--pf-muted);
  border-top: 1px solid #f3f4f6;
  transition: background 0.2s, color 0.2s;
}
.pf-hint i { flex: none; }
.pf-hint span { flex: 1; min-width: 0; }
.pf-hint.armed { background: var(--pf-blue-soft); color: var(--pf-blue); border-top-color: #dbeafe; }
.pf-hint-cancel {
  flex: none; font-size: 12px; font-weight: 600; color: var(--pf-blue);
  background: #fff; border: 1px solid #bfdbfe; border-radius: 999px; padding: 2px 12px; cursor: pointer;
}
.pf-hint-cancel:hover { background: #dbeafe; }

.pf-editor { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; align-items: start; }

.pf-page-block { margin-bottom: 20px; scroll-margin-top: calc(var(--pf-topbar) + var(--pf-bar-h) + 12px); }
.pf-page-label {
  display: flex; justify-content: space-between; align-items: baseline;
  font-size: 12px; font-weight: 600; color: #374151; margin: 0 2px 6px;
}
.pf-page-label .pf-muted { font-weight: 400; }

.pf-warn {
  margin: 8px 0 0;
  padding: 10px 12px;
  border-radius: 10px;
  background: #fef3c7;
  color: #92400e;
  font-size: 12px;
  line-height: 1.5;
}


.pf-page {
  position: relative; width: 100%;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 4px;
  overflow: hidden;
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
  color: #000000;
  line-height: 1.15;
  white-space: nowrap;
  overflow: visible;
}
.pf-page.armed .pf-field { pointer-events: none; }
.pf-field.selected { border: 1.5px solid #1d4ed8; background: rgba(191, 219, 254, 0.5); z-index: 2; }
.pf-field.dragging { cursor: grabbing; z-index: 3; outline: 3px solid rgba(29, 78, 216, 0.18); }
.pf-field.check { font-family: 'DejaVu Sans', 'Segoe UI Symbol', sans-serif; }
.pf-field.sig { border-color: #f59e0b; background: rgba(254, 243, 199, 0.55); color: #b45309; text-align: center; }
.pf-field.sig .pf-field-text { text-align: center !important; font-size: 11px; padding-top: 2px; }
.pf-help { margin: -2px 0 2px; line-height: 1.5; }
.pf-field-text { display: block; width: 100%; margin-top: -0.1em; }
.pf-resize {
  position: absolute; right: -5px; bottom: -5px;
  width: 10px; height: 10px; border-radius: 2px;
  background: #1d4ed8; cursor: nwse-resize;
  opacity: 0;
}
.pf-field.selected .pf-resize { opacity: 1; }

.pf-side {
  display: flex; flex-direction: column; gap: 12px;
  position: sticky;
  top: calc(var(--pf-topbar) + var(--pf-bar-h) + 16px);
  max-height: calc(100vh - var(--pf-topbar) - var(--pf-bar-h) - 32px);
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: #d1d5db transparent;
  padding-right: 2px;
}
.pf-side::-webkit-scrollbar { width: 6px; }
.pf-side::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 999px; }
.pf-panel-selected { flex: none; border-color: #bfdbfe; }
.pf-panel-selected h4 i { color: var(--pf-blue); margin-right: 2px; }
.pf-panel.pf-panel-tabs { flex: none; padding: 0; gap: 0; }

.pf-tabs { display: grid; grid-template-columns: repeat(3, 1fr); border-bottom: 1px solid var(--pf-line); }
.pf-tabs button {
  position: relative; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 11px 6px; border: 0; background: transparent; cursor: pointer;
  font-size: 13px; font-weight: 600; color: var(--pf-muted); transition: color 0.15s;
}
.pf-tabs button i { font-size: 12px; }
.pf-tabs button:hover { color: var(--pf-ink); }
.pf-tabs button.active { color: var(--pf-blue); }
.pf-tabs button.active::after {
  content: ''; position: absolute; left: 14px; right: 14px; bottom: -1px; height: 2px; border-radius: 2px; background: var(--pf-blue);
}
.pf-tab-badge {
  min-width: 18px; padding: 0 5px; border-radius: 999px; background: #f3f4f6; color: #4b5563;
  font-size: 11px; line-height: 18px; font-variant-numeric: tabular-nums;
}
.pf-tabs button.active .pf-tab-badge { background: #dbeafe; color: var(--pf-blue); }
.pf-tab-alert { width: 7px; height: 7px; border-radius: 50%; background: #f59e0b; }
.pf-tab-body { display: flex; flex-direction: column; gap: 8px; padding: 14px; }

.pf-search { position: relative; }
.pf-search i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: 12px; color: #9ca3af; pointer-events: none; }
.pf-panel .pf-search input[type='search'] { padding-left: 32px; background: #f9fafb; }
.pf-panel .pf-search input[type='search']:focus { background: #fff; border-color: #93c5fd; outline: none; }
.pf-empty-search { font-size: 13px; text-align: center; padding: 12px 0; }
.pf-panel { padding: 14px; display: flex; flex-direction: column; gap: 8px; }
.pf-panel h4 { margin: 0 0 2px; font-size: 14px; font-weight: 700; }
.pf-panel-head { display: flex; justify-content: space-between; align-items: center; }
.pf-panel select, .pf-panel input:not([type='checkbox']) {
  width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; background: #fff;
}
.pf-label { font-size: 12px; color: #6b7280; margin-top: 2px; }
.pf-check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: #374151; line-height: 1.5; cursor: pointer; }
.pf-check input { margin-top: 3px; }
.pf-panel input.pf-input-missing { border-color: #f59e0b; background: #fffbeb; }
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
.pf-pill { display: inline-flex; align-items: center; gap: 5px; transition: background 0.15s, border-color 0.15s, color 0.15s; }
.pf-pill:hover { border-color: #93c5fd; color: var(--pf-blue); }
.pf-pill i { font-size: 9px; color: #10b981; }
.pf-pill.used { background: #f9fafb; color: #6b7280; }
.pf-pill.active { background: var(--pf-blue); border-color: var(--pf-blue); color: #fff; }
.pf-pill.active i { color: #fff; }

.pf-trip-list { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; }
.pf-trip-row { display: flex; gap: 8px; align-items: baseline; font-size: 13px; padding: 4px 2px; cursor: pointer; }
.pf-trip-row small { display: block; }

.pf-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
.pf-thumbs img { width: 90px; border: 1px solid #e5e7eb; border-radius: 4px; }

@media (max-width: 1023px) {
  .pf-workspace { --pf-topbar: 56px; --pf-gutter: 12px; }
  .pf-toolbar { margin-top: -16px; padding-top: 10px; }
  .pf-editor { grid-template-columns: 1fr; }
  /* จอแคบแผงอยู่บนหน้าฟอร์ม — ไม่ลอย ไม่ต้องเลื่อนในตัวเอง */
  .pf-side { order: -1; position: static; max-height: none; overflow: visible; padding-right: 0; }
  .pf-hide-sm { display: none; }
  /* คำแนะนำเมาส์/คีย์บอร์ดไม่ใช้บนจอสัมผัส — โชว์แถวนี้เฉพาะตอนกำลังวางช่อง */
  .pf-hint:not(.armed) { display: none; }
  .pf-title-block { min-width: 0; flex-basis: calc(100% - 52px); }
  .pf-page-nav { order: 3; flex: 1; }
  .pf-bar-actions { order: 4; }
}
</style>
