<template>
  <div class="admin-page shopping-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">shopping_cart</span> ของที่ต้องซื้อก่อนออกทริป</h1>
        <p class="page-subtitle">ตั้งรายการประจำทริปครั้งเดียว ทุกรอบจะได้ใบซื้อของไปเอง สตาฟติ๊กในแอปแล้วส่งรูปกลับมาที่นี่</p>
      </div>
      <div class="head-actions">
        <div class="tab-switch" role="tablist">
          <button role="tab" :class="{ active: tab === 'rounds' }" @click="tab = 'rounds'">
            <span class="material-symbols-rounded">fact_check</span> รายงานรายรอบ
          </button>
          <button role="tab" :class="{ active: tab === 'template' }" @click="openTemplateTab">
            <span class="material-symbols-rounded">edit_note</span> รายการประจำทริป
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ แท็บ 1 : ใบซื้อของรายรอบ ═══ -->
    <template v-if="tab === 'rounds'">
      <div v-if="loadingRounds && !rounds.length" class="shop-layout">
        <aside class="schedule-rail">
          <span v-for="n in 4" :key="`sk-${n}`" class="sk sk-rail"></span>
        </aside>
        <section><span class="sk sk-block"></span></section>
      </div>

      <div v-else-if="!rounds.length" class="empty-card">
        <span class="material-symbols-rounded">shopping_basket</span>
        <p>ไม่มีรอบเดินทางข้างหน้า</p>
        <button class="btn-secondary" @click="includePast = true; loadRounds()">
          <span class="material-symbols-rounded">history</span> ดูรอบที่ผ่านไปแล้ว
        </button>
      </div>

      <div v-else class="shop-layout">
        <aside class="schedule-rail">
          <div class="rail-tools">
            <div class="search-box">
              <span class="material-symbols-rounded">search</span>
              <input v-model="roundQuery" placeholder="ค้นหาชื่อทริป..." />
            </div>
            <label class="past-toggle">
              <input v-model="includePast" type="checkbox" @change="loadRounds" />
              <span>แสดงรอบที่ผ่านไปแล้ว</span>
            </label>
          </div>

          <button
            v-for="r in filteredRounds"
            :key="r.id"
            class="schedule-item"
            :class="{ active: r.id === selectedId }"
            @click="selectRound(r.id)"
          >
            <span class="sched-top">
              <span class="sched-trip">{{ r.trip_title }}</span>
              <span class="status-chip" :class="`st-${r.status}`">{{ statusLabel(r.status) }}</span>
            </span>
            <span class="sched-date">{{ r.departure_date_thai }} · {{ countdownLabel(r.days_left) }}</span>
            <span v-if="r.items_count" class="sched-meta">
              ซื้อแล้ว {{ r.bought_count }}/{{ r.items_count }}
              <template v-if="r.total_amount"> · ฿{{ formatMoney(r.total_amount) }}</template>
            </span>
          </button>
        </aside>

        <section class="shop-detail">
          <div v-if="loadingDetail && !detail" class="sk sk-block"></div>

          <template v-else-if="detail">
            <div class="detail-head">
              <div class="detail-title">
                <h2>{{ detail.schedule.trip_title }}</h2>
                <p>
                  <span class="material-symbols-rounded">event</span>
                  รอบเดินทาง {{ detail.schedule.departure_date_thai }}
                </p>
                <p>
                  <span class="material-symbols-rounded">groups</span>
                  คิดของ "ต่อคน" จาก {{ detail.headcount.used }} คน
                  (ลูกค้า {{ detail.headcount.travellers }} + ทีมงาน {{ detail.headcount.staff }})
                </p>
              </div>
              <div class="totals">
                <div class="total-cell strong">
                  <span class="total-num">{{ detail.summary.bought_items }}/{{ detail.summary.total_items }}</span>
                  <span class="total-label">ซื้อแล้ว</span>
                </div>
                <div class="total-cell">
                  <span class="total-num">{{ detail.report?.total_amount != null ? `฿${formatMoney(detail.report.total_amount)}` : '—' }}</span>
                  <span class="total-label">ยอดที่สตาฟแจ้ง</span>
                </div>
              </div>
            </div>

            <!-- รายงานจากสตาฟ -->
            <div v-if="detail.report?.submitted" class="report-card">
              <div class="report-head">
                <div>
                  <h3>
                    <span class="material-symbols-rounded">task_alt</span>
                    ส่งรายงานแล้ว
                  </h3>
                  <p>{{ detail.report.submitted_at_label }} น. · โดย {{ detail.report.submitted_by_name || 'สตาฟ' }}</p>
                </div>
                <div class="report-actions">
                  <span v-if="detail.report.reviewed_at" class="status-chip st-reviewed">
                    รับทราบแล้ว · {{ detail.report.reviewed_by_name }}
                  </span>
                  <button v-else class="btn-primary" :disabled="busy" @click="acknowledge">
                    <span class="material-symbols-rounded">done_all</span> รับทราบ
                  </button>
                  <button class="btn-secondary" :disabled="busy" @click="reopen">
                    <span class="material-symbols-rounded">undo</span> ตีกลับให้แก้
                  </button>
                </div>
              </div>

              <p v-if="detail.report.unbought.length" class="report-warn">
                <span class="material-symbols-rounded">error</span>
                ไม่ได้ซื้อ {{ detail.report.unbought.length }} รายการ: {{ detail.report.unbought.join(', ') }}
              </p>
              <p v-if="detail.report.note" class="report-note">“{{ detail.report.note }}”</p>
              <p v-if="detail.report.in_ledger" class="report-ledger">
                <span class="material-symbols-rounded">receipt_long</span>
                ลงบัญชีหน้างานแล้ว (หมวดอาหาร/เครื่องดื่ม) — ดู/แก้ได้ที่หน้าบัญชีทริป
              </p>

              <div class="photo-grid">
                <a v-for="(url, i) in detail.report.photos" :key="i" :href="url" target="_blank" rel="noopener">
                  <img :src="url" :alt="`รูปที่ ${i + 1}`" loading="lazy" />
                </a>
              </div>
            </div>

            <div v-else-if="detail.report?.reopen_reason" class="report-card reopened">
              <h3><span class="material-symbols-rounded">undo</span> ตีกลับให้สตาฟแก้แล้ว — รอส่งใหม่</h3>
              <p>เหตุผล: {{ detail.report.reopen_reason }} · โดย {{ detail.report.reopened_by_name }}</p>
            </div>

            <div v-else class="report-card pending">
              <h3><span class="material-symbols-rounded">hourglass_top</span> สตาฟยังไม่ได้ส่งรายงาน</h3>
              <p>สตาฟต้องถ่ายรูปอย่างน้อย 1 รูปตอนส่ง รายงานจะโผล่ที่นี่และในหน้า "สิ่งที่รอคุณ"</p>
            </div>

            <!-- ใบซื้อของของรอบ -->
            <div class="section-head">
              <h3 class="section-label">ใบซื้อของของรอบนี้</h3>
              <button class="link-btn" :disabled="busy" @click="resync">
                <span class="material-symbols-rounded">sync</span> ดึงรายการจากทริปอีกครั้ง
              </button>
            </div>

            <div class="table-card">
              <div class="table-container">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>ของ</th>
                      <th class="num">ต้องซื้อ</th>
                      <th>สถานะ</th>
                      <th class="num"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in detail.items" :key="item.id">
                      <td>
                        <span class="item-name">{{ item.name }}</span>
                        <span v-if="item.source === 'extra'" class="extra-tag">เฉพาะรอบนี้</span>
                        <span v-if="item.note" class="item-sub">{{ item.note }}</span>
                      </td>
                      <td class="num">
                        <strong>{{ item.total_label }}</strong>
                        <span v-if="item.rule_label" class="item-sub">{{ item.rule_label }}</span>
                      </td>
                      <td>
                        <span v-if="item.bought" class="bought-yes">
                          <span class="material-symbols-rounded">check_circle</span> {{ item.bought_by_name || 'ซื้อแล้ว' }}
                        </span>
                        <span v-else class="bought-no">ยังไม่ซื้อ</span>
                      </td>
                      <td class="num">
                        <button class="btn-icon" title="ลบ" @click="removeItem(item)">
                          <span class="material-symbols-rounded">delete</span>
                        </button>
                      </td>
                    </tr>
                    <tr v-if="!detail.items.length">
                      <td colspan="4" class="empty-state">
                        {{ detail.has_template ? 'รอบนี้ไม่มีของต้องซื้อ' : 'ทริปนี้ยังไม่มีรายการประจำ — ตั้งได้ที่แท็บ "รายการประจำทริป"' }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <form class="add-row" @submit.prevent="addItem">
              <input v-model="newItem.name" placeholder="เพิ่มของเฉพาะรอบนี้ เช่น ถ่านไฟฉาย" maxlength="255" />
              <input v-model="newItem.quantity" type="number" min="0.01" step="any" placeholder="จำนวน" class="qty-input" />
              <input v-model="newItem.unit" placeholder="หน่วย" maxlength="32" class="unit-input" />
              <label class="pp-toggle"><input v-model="newItem.per_person" type="checkbox" /> ต่อคน</label>
              <button class="btn-secondary" type="submit" :disabled="busy || !newItem.name.trim()">
                <span class="material-symbols-rounded">add</span> เพิ่ม
              </button>
            </form>
          </template>
        </section>
      </div>
    </template>

    <!-- ═══ แท็บ 2 : รายการประจำทริป ═══ -->
    <template v-else>
      <div class="template-card">
        <div class="template-picker">
          <label for="trip-select">ทริป</label>
          <select id="trip-select" v-model="templateTripId" @change="loadTemplate">
            <option :value="null" disabled>— เลือกทริป —</option>
            <option v-for="t in trips" :key="t.id" :value="t.id">{{ t.title }}</option>
          </select>
        </div>

        <p v-if="!templateTripId" class="template-hint">
          เลือกทริปเพื่อตั้งของที่ต้องซื้อทุกครั้งที่ทริปนี้ออกเดินทาง
        </p>

        <template v-else>
          <p class="template-hint">
            ติ๊ก <strong>ต่อคน</strong> เมื่อจำนวนควรคูณตามคนในรอบ (ลูกค้า + ทีมงาน) เช่น น้ำดื่ม 2 ขวด/คน.
            รอบที่สตาฟเปิดดูไปแล้วจะไม่เปลี่ยนตาม — กด "ดึงรายการจากทริปอีกครั้ง" ที่รอบนั้น
          </p>

          <div v-if="loadingTemplate" class="sk sk-block"></div>

          <div v-else class="template-rows">
            <div v-for="(row, i) in templateRows" :key="row._key" class="template-row">
              <div class="order-btns">
                <button type="button" :disabled="i === 0" title="เลื่อนขึ้น" @click="move(i, -1)">
                  <span class="material-symbols-rounded">keyboard_arrow_up</span>
                </button>
                <button type="button" :disabled="i === templateRows.length - 1" title="เลื่อนลง" @click="move(i, 1)">
                  <span class="material-symbols-rounded">keyboard_arrow_down</span>
                </button>
              </div>
              <input v-model="row.name" class="name-input" placeholder="ชื่อของ เช่น น้ำดื่ม" maxlength="255" />
              <input v-model="row.quantity" type="number" min="0.01" step="any" class="qty-input" placeholder="จำนวน" />
              <input v-model="row.unit" class="unit-input" placeholder="หน่วย" maxlength="32" />
              <label class="pp-toggle"><input v-model="row.per_person" type="checkbox" /> ต่อคน</label>
              <input v-model="row.note" class="note-input" placeholder="หมายเหตุ เช่น ร้านไหน ยี่ห้อไหน" maxlength="500" />
              <button type="button" class="btn-icon" title="ลบ" @click="templateRows.splice(i, 1)">
                <span class="material-symbols-rounded">delete</span>
              </button>
            </div>

            <p v-if="!templateRows.length" class="template-hint">ยังไม่มีรายการ</p>

            <div class="template-actions">
              <button type="button" class="btn-secondary" @click="addTemplateRow">
                <span class="material-symbols-rounded">add</span> เพิ่มรายการ
              </button>
              <button type="button" class="btn-primary" :disabled="savingTemplate" @click="saveTemplate">
                <span class="material-symbols-rounded">{{ savingTemplate ? 'hourglass_top' : 'save' }}</span>
                บันทึกรายการประจำทริป
              </button>
            </div>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';
import './admin-shared.css';

const toast = useToast();
const swal = useSwal();

const tab = ref('rounds');

// ─── รายงานรายรอบ ────────────────────────────
const rounds = ref([]);
const loadingRounds = ref(false);
const includePast = ref(false);
const roundQuery = ref('');
const selectedId = ref(null);
const detail = ref(null);
const loadingDetail = ref(false);
const busy = ref(false);
const newItem = ref({ name: '', quantity: '', unit: '', per_person: false });

const STATUS = {
  submitted: 'รอตรวจ',
  reviewed: 'รับทราบแล้ว',
  reopened: 'ตีกลับแล้ว',
  in_progress: 'กำลังซื้อ',
  not_started: 'ยังไม่เริ่ม',
  no_list: 'ไม่มีรายการ',
};

function statusLabel(s) {
  return STATUS[s] || s;
}

function formatMoney(v) {
  return Number(v || 0).toLocaleString('th-TH');
}

function countdownLabel(days) {
  if (days === null || days === undefined) return '—';
  if (days === 0) return 'วันนี้';
  if (days === 1) return 'พรุ่งนี้';
  if (days < 0) return `ผ่านไปแล้ว ${Math.abs(days)} วัน`;
  return `อีก ${days} วัน`;
}

const filteredRounds = computed(() => {
  const q = roundQuery.value.trim().toLowerCase();
  return q ? rounds.value.filter((r) => (r.trip_title || '').toLowerCase().includes(q)) : rounds.value;
});

function errorMessage(e, fallback) {
  return e?.response?.data?.message || fallback;
}

async function loadRounds() {
  loadingRounds.value = true;
  try {
    const res = await api.get('/admin/shopping/rounds', { params: includePast.value ? { include_past: 1 } : {} });
    rounds.value = res.data.data || [];

    // รอบที่รอตรวจขึ้นก่อน เพราะเป็นงานที่ต้องทำ
    const first = rounds.value.find((r) => r.status === 'submitted') || rounds.value[0];
    if (first && !rounds.value.some((r) => r.id === selectedId.value)) {
      await selectRound(first.id);
    }
  } catch {
    toast.error('โหลดรายการรอบไม่สำเร็จ');
  } finally {
    loadingRounds.value = false;
  }
}

async function selectRound(id) {
  selectedId.value = id;
  loadingDetail.value = true;
  try {
    const res = await api.get(`/admin/schedules/${id}/shopping`);
    detail.value = res.data.data;
  } catch {
    toast.error('โหลดใบซื้อของไม่สำเร็จ');
    detail.value = null;
  } finally {
    loadingDetail.value = false;
  }
}

/** ทุก action คืนใบซื้อของทั้งใบ — แทนที่ detail แล้วอัปเดตแถวในรางซ้ายตาม */
async function run(request, okMessage) {
  busy.value = true;
  try {
    const res = await request();
    detail.value = res.data.data;
    toast.success(res.data.message || okMessage);
    syncRail();
  } catch (e) {
    toast.error(errorMessage(e, 'ทำรายการไม่สำเร็จ'));
  } finally {
    busy.value = false;
  }
}

function syncRail() {
  const row = rounds.value.find((r) => r.id === selectedId.value);
  const d = detail.value;
  if (!row || !d) return;

  row.items_count = d.summary.total_items;
  row.bought_count = d.summary.bought_items;
  row.total_amount = d.report?.total_amount ?? null;
  row.status = d.report?.submitted
    ? (d.report.reviewed_at ? 'reviewed' : 'submitted')
    : d.report?.reopened_at ? 'reopened'
      : d.summary.bought_items > 0 ? 'in_progress'
        : d.summary.total_items > 0 ? 'not_started' : 'no_list';
}

function acknowledge() {
  run(() => api.post(`/admin/schedules/${selectedId.value}/shopping/acknowledge`), 'รับทราบแล้ว');
}

async function reopen() {
  const result = await swal.confirm({
    title: 'ตีกลับให้สตาฟแก้?',
    text: 'สตาฟจะได้แจ้งเตือนพร้อมเหตุผล และติ๊ก/ส่งรายงานใหม่ได้อีกครั้ง',
    icon: 'warning',
    input: 'text',
    inputPlaceholder: 'เหตุผล เช่น ขอรูปใบเสร็จที่ชัดกว่านี้',
    confirmText: 'ตีกลับ',
    inputValidator: (v) => (!v || !v.trim() ? 'ต้องบอกเหตุผลให้สตาฟรู้' : undefined),
  });
  if (!result.isConfirmed) return;

  run(() => api.post(`/admin/schedules/${selectedId.value}/shopping/reopen`, { reason: result.value.trim() }), 'ตีกลับแล้ว');
}

function resync() {
  run(() => api.post(`/admin/schedules/${selectedId.value}/shopping/resync`), 'ดึงรายการแล้ว');
}

async function addItem() {
  const payload = {
    name: newItem.value.name.trim(),
    quantity: newItem.value.quantity === '' ? null : Number(newItem.value.quantity),
    unit: newItem.value.unit.trim() || null,
    per_person: newItem.value.per_person,
  };
  await run(() => api.post(`/admin/schedules/${selectedId.value}/shopping/items`, payload), 'เพิ่มรายการแล้ว');
  newItem.value = { name: '', quantity: '', unit: '', per_person: false };
}

async function removeItem(item) {
  const result = await swal.confirm({
    title: `ลบ "${item.name}"?`,
    text: 'ลบจากใบของรอบนี้เท่านั้น รายการประจำทริปไม่เปลี่ยน',
    icon: 'warning',
    confirmText: 'ลบ',
  });
  if (!result.isConfirmed) return;

  run(() => api.delete(`/admin/schedules/${selectedId.value}/shopping/items/${item.id}`), 'ลบแล้ว');
}

// ─── รายการประจำทริป ──────────────────────────
const trips = ref([]);
const templateTripId = ref(null);
const templateRows = ref([]);
const loadingTemplate = ref(false);
const savingTemplate = ref(false);
let keySeq = 0;

function toRow(item = {}) {
  return {
    _key: ++keySeq,
    id: item.id ?? null,
    name: item.name ?? '',
    quantity: item.quantity ?? 1,
    unit: item.unit ?? '',
    per_person: !!item.per_person,
    note: item.note ?? '',
  };
}

async function openTemplateTab() {
  tab.value = 'template';
  if (!templateTripId.value && detail.value?.schedule?.trip_id) {
    templateTripId.value = detail.value.schedule.trip_id;
  }
  if (!trips.value.length) {
    try {
      const res = await api.get('/admin/trips', { params: { per_page: 200, sort: 'title' } });
      trips.value = res.data.data?.data || res.data.data || [];
    } catch {
      toast.error('โหลดรายชื่อทริปไม่สำเร็จ');
    }
  }
  if (templateTripId.value) loadTemplate();
}

async function loadTemplate() {
  loadingTemplate.value = true;
  try {
    const res = await api.get(`/admin/trips/${templateTripId.value}/shopping-template`);
    templateRows.value = (res.data.data.items || []).map(toRow);
  } catch {
    toast.error('โหลดรายการประจำทริปไม่สำเร็จ');
  } finally {
    loadingTemplate.value = false;
  }
}

function addTemplateRow() {
  templateRows.value.push(toRow());
}

function move(i, delta) {
  const rows = templateRows.value;
  [rows[i], rows[i + delta]] = [rows[i + delta], rows[i]];
}

async function saveTemplate() {
  const items = templateRows.value
    .filter((r) => r.name.trim())
    .map((r) => ({
      id: r.id,
      name: r.name.trim(),
      quantity: r.quantity === '' ? null : Number(r.quantity),
      unit: r.unit.trim() || null,
      per_person: r.per_person,
      note: r.note.trim() || null,
    }));

  savingTemplate.value = true;
  try {
    const res = await api.put(`/admin/trips/${templateTripId.value}/shopping-template`, { items });
    templateRows.value = (res.data.data.items || []).map(toRow);
    toast.success('บันทึกรายการประจำทริปแล้ว');
  } catch (e) {
    toast.error(errorMessage(e, 'บันทึกไม่สำเร็จ'));
  } finally {
    savingTemplate.value = false;
  }
}

onMounted(loadRounds);
</script>

<style scoped>
.head-actions { display: flex; align-items: center; gap: 10px; }

.tab-switch {
  display: inline-flex; gap: 4px; padding: 4px;
  background: #F3F4F6; border-radius: 12px;
}
.tab-switch button {
  display: inline-flex; align-items: center; gap: 6px;
  border: none; background: transparent; cursor: pointer;
  padding: 8px 14px; border-radius: 9px;
  font-size: 13.5px; font-weight: 700; color: #6b7280;
}
.tab-switch button .material-symbols-rounded { font-size: 18px; }
.tab-switch button.active { background: #fff; color: var(--color-accent); }

.empty-card {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 64px 20px; text-align: center; color: #9ca3af;
}
.empty-card .material-symbols-rounded { font-size: 44px !important; color: #d1d5db; }
.empty-card p { margin: 6px 0 0; font-size: 15px; font-weight: 700; color: #4b5563; }
.empty-card .btn-secondary { margin-top: 14px; }

.shop-layout { display: grid; grid-template-columns: 300px 1fr; gap: 20px; align-items: start; }

/* ─── รางรายชื่อรอบ ─────────────────────── */
.schedule-rail {
  display: flex; flex-direction: column; gap: 8px;
  position: sticky; top: 16px; max-height: calc(100vh - 40px); overflow-y: auto;
}
.rail-tools {
  display: flex; flex-direction: column; gap: 10px;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px;
}
.search-box input { padding: 8px 12px 8px 34px; font-size: 13.5px; }
.search-box .material-symbols-rounded { font-size: 19px; }
.past-toggle { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: #6b7280; cursor: pointer; }
.past-toggle input { accent-color: var(--color-accent); }

.schedule-item {
  display: flex; flex-direction: column; gap: 4px; text-align: left;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 12px 14px; cursor: pointer;
}
.schedule-item:hover { border-color: #d1d5db; background: #FAFAFA; }
.schedule-item.active { border-color: var(--color-accent); background: rgba(45, 122, 79, 0.04); }
.sched-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.sched-trip {
  font-size: 14px; font-weight: 700; color: #111827; line-height: 1.35;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.sched-date { font-size: 12.5px; color: #4b5563; }
.sched-meta { font-size: 11.5px; color: #9ca3af; }

.status-chip {
  flex-shrink: 0; border-radius: 20px; padding: 2px 9px;
  font-size: 11.5px; font-weight: 700; white-space: nowrap;
  background: #EEEEEE; color: #6b7280;
}
.st-submitted { background: #fef9c3; color: #a16207; }
.st-reviewed { background: #dcfce7; color: #15803d; }
.st-reopened { background: #fee2e2; color: #b91c1c; }
.st-in_progress { background: rgba(45, 122, 79, 0.08); color: var(--color-accent); }

/* ─── รายละเอียดรอบ ─────────────────────── */
.shop-detail { min-width: 0; }
.detail-head {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 22px; margin-bottom: 16px;
}
.detail-head h2 { margin: 0 0 4px; font-size: 20px; font-weight: 700; color: #111827; }
.detail-head p { margin: 2px 0 0; font-size: 13.5px; color: #6b7280; display: flex; align-items: center; gap: 5px; }
.detail-head p .material-symbols-rounded { font-size: 17px !important; }
.totals { display: flex; gap: 10px; }
.total-cell {
  display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 110px; padding: 10px 12px;
  background: #FAFAFA; border: 1px solid #EEEEEE; border-radius: 10px;
}
.total-cell.strong { background: rgba(45, 122, 79, 0.06); border-color: rgba(45, 122, 79, 0.18); }
.total-num { font-size: 20px; font-weight: 800; color: var(--color-accent); line-height: 1.2; }
.total-label { font-size: 11.5px; color: #6b7280; }

.report-card {
  background: #fff; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px 20px; margin-bottom: 22px;
}
.report-card.reopened { border-color: #fecaca; background: #fff7f7; }
.report-card.pending { border-color: #e5e7eb; background: #FAFAFA; }
.report-card h3 { margin: 0; font-size: 15px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 6px; }
.report-card h3 .material-symbols-rounded { font-size: 20px; color: var(--color-accent); }
.report-card.reopened h3 .material-symbols-rounded { color: #b91c1c; }
.report-card.pending h3 .material-symbols-rounded { color: #9ca3af; }
.report-card p { margin: 4px 0 0; font-size: 13px; color: #6b7280; }
.report-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.report-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.report-warn { color: #a16207 !important; font-weight: 600; display: flex; align-items: center; gap: 5px; margin-top: 12px !important; }
.report-warn .material-symbols-rounded { font-size: 17px !important; }
.report-note { color: #374151 !important; font-size: 14px !important; margin-top: 8px !important; }
.report-ledger { display: flex; align-items: center; gap: 5px; }
.report-ledger .material-symbols-rounded { font-size: 17px !important; }

.photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; margin-top: 14px; }
.photo-grid img {
  width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; border: 1px solid #e5e7eb; display: block;
}

.section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.section-label { font-size: 13px; font-weight: 700; color: #6b7280; letter-spacing: 0.5px; margin: 0; }
.link-btn {
  display: inline-flex; align-items: center; gap: 4px; background: none; border: none; padding: 0;
  font-size: 13px; color: var(--color-accent); font-weight: 700; cursor: pointer;
}
.link-btn .material-symbols-rounded { font-size: 17px; }

.num { text-align: right; }
.item-name { font-weight: 700; color: #111827; }
.item-sub { display: block; font-size: 12px; color: #9ca3af; font-weight: 400; }
.extra-tag {
  margin-left: 6px; font-size: 11px; font-weight: 700; color: #c2410c;
  background: #ffedd5; border-radius: 20px; padding: 1px 7px;
}
.bought-yes { display: inline-flex; align-items: center; gap: 4px; color: #15803d; font-weight: 600; font-size: 13px; }
.bought-yes .material-symbols-rounded { font-size: 17px !important; }
.bought-no { color: #9ca3af; font-size: 13px; }

.add-row, .template-row {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 12px;
}
.add-row input, .template-row input:not([type='checkbox']), .template-picker select {
  border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 12px; font-size: 13.5px; background: #fff;
}
.add-row input:first-child { flex: 1 1 220px; }
.qty-input { width: 90px; }
.unit-input { width: 100px; }
.name-input { flex: 1 1 180px; }
.note-input { flex: 2 1 220px; }
.pp-toggle { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: #374151; cursor: pointer; white-space: nowrap; }
.pp-toggle input { accent-color: var(--color-accent); }

/* ─── รายการประจำทริป ───────────────────── */
.template-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 22px; }
.template-picker { display: flex; align-items: center; gap: 10px; }
.template-picker label { font-size: 13px; font-weight: 700; color: #374151; }
.template-picker select { min-width: 280px; max-width: 100%; }
.template-hint { font-size: 13px; color: #6b7280; margin: 12px 0 0; }
.template-rows { margin-top: 8px; }
.template-row { padding: 10px 0; border-bottom: 1px solid #F0F1F0; margin-top: 0; }
.order-btns { display: flex; flex-direction: column; }
.order-btns button {
  border: none; background: none; cursor: pointer; color: #9ca3af; padding: 0; line-height: 1;
}
.order-btns button:disabled { opacity: 0.3; cursor: default; }
.template-actions { display: flex; justify-content: space-between; gap: 10px; margin-top: 16px; flex-wrap: wrap; }

.sk { display: block; background: #F3F4F6; border-radius: 12px; }
.sk-rail { height: 78px; }
.sk-block { height: 220px; }

@media (max-width: 900px) {
  .shop-layout { grid-template-columns: 1fr; }
  .schedule-rail { position: static; max-height: none; flex-direction: row; overflow-x: auto; padding-bottom: 6px; }
  .rail-tools, .schedule-item { flex-shrink: 0; width: 240px; }
  .totals { width: 100%; }
  .total-cell { flex: 1; }
  .head-actions { width: 100%; }
  .tab-switch { width: 100%; }
  .tab-switch button { flex: 1; justify-content: center; }
}
</style>
