<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">savings</span> สรุปกำไร/ค่าใช้จ่าย</h1>
        <p class="page-subtitle">รายรับจริง − ค่าใช้จ่าย = กำไร ต่อทริปและต่อรอบเดินทาง</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="form-group">
        <label>จากวันเดินทาง</label>
        <input v-model="filters.from" type="date" />
      </div>
      <div class="form-group">
        <label>ถึงวันเดินทาง</label>
        <input v-model="filters.to" type="date" />
      </div>
      <button class="btn-primary" @click="loadSummary" :disabled="loading">
        <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">{{ loading ? 'sync' : 'search' }}</span>
        แสดงผล
      </button>
      <button class="btn-secondary" @click="clearFilters" v-if="filters.from || filters.to">ล้างช่วงวัน</button>
    </div>

    <!-- Summary cards -->
    <div class="summary-cards" v-if="summary">
      <div class="sc-item">
        <span class="sc-label">รายรับจริง</span>
        <span class="sc-val money-green">{{ formatMoney(summary.paid_revenue) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">ค่าใช้จ่ายรวม</span>
        <span class="sc-val money-orange">{{ formatMoney(summary.expense_total) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">กำไรสุทธิ</span>
        <span class="sc-val" :class="profitClass(summary.profit)">{{ formatMoney(summary.profit) }}</span>
      </div>
      <div class="sc-item">
        <span class="sc-label">ช่วงข้อมูล</span>
        <span class="sc-val sc-period">{{ summary.period }}</span>
      </div>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <!-- Trip table -->
    <div class="table-card" v-if="!loading && trips.length">
      <div class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th></th>
              <th>ทริป</th>
              <th class="num">จอง</th>
              <th class="num">ผู้เดินทาง</th>
              <th class="num">รายรับจริง</th>
              <th class="num">ค่าใช้จ่าย</th>
              <th class="num">กำไร</th>
              <th class="num">มาร์จิน</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="t in trips" :key="t.trip_id">
              <tr class="trip-row" @click="toggleTrip(t)">
                <td><span class="material-symbols-rounded expand-icon" :class="{ open: expanded === t.trip_id }">chevron_right</span></td>
                <td class="trip-title">{{ t.title }}</td>
                <td class="num">{{ t.bookings_count }}</td>
                <td class="num">{{ t.passengers_count }}</td>
                <td class="num money-green">{{ formatMoney(t.paid_revenue) }}</td>
                <td class="num money-orange">{{ formatMoney(t.expense_total) }}</td>
                <td class="num" :class="profitClass(t.profit)">{{ formatMoney(t.profit) }}</td>
                <td class="num">{{ t.margin_percent != null ? t.margin_percent + '%' : '-' }}</td>
                <td>
                  <button class="btn-icon" title="รายการประจำของทริป" @click.stop="openTemplates(t)">
                    <span class="material-symbols-rounded" style="font-size:18px;">repeat</span>
                  </button>
                </td>
              </tr>

              <!-- Expanded: schedules -->
              <tr v-if="expanded === t.trip_id" class="detail-row">
                <td :colspan="9">
                  <div class="schedule-loading" v-if="detailLoading"><div class="spinner spinner-sm"></div></div>
                  <div v-else-if="detail" class="schedule-list">
                    <div v-if="!detail.schedules.length" class="empty-state">
                      <span class="material-symbols-rounded">event_busy</span>
                      <p>ยังไม่มีรอบเดินทาง</p>
                    </div>
                    <template v-else>
                      <div class="sched-list-head">
                        <span class="sched-count">{{ detail.schedules.length }} รอบเดินทาง (รวมรอบที่ผ่านมาแล้ว)</span>
                      </div>
                      <div class="sched-scroll">
                        <div v-for="s in detail.schedules" :key="s.schedule_id" class="sched-card">
                          <div class="sched-head">
                            <div class="sched-date">
                              <span class="material-symbols-rounded">calendar_month</span>
                              {{ s.departure_label }}
                              <span class="status-badge" :class="`status-${s.status}`">{{ s.status }}</span>
                            </div>
                            <button class="btn-secondary btn-xs" @click="openExpenses(t, s)">
                              <span class="material-symbols-rounded" style="font-size:16px;">receipt_long</span>
                              จัดการค่าใช้จ่าย ({{ s.expenses.length }})
                            </button>
                          </div>
                          <div class="sched-figures">
                            <span>รายรับจริง <b class="money-green">{{ formatMoney(s.paid_revenue) }}</b></span>
                            <span>ค่าใช้จ่าย <b class="money-orange">{{ formatMoney(s.expense_total) }}</b></span>
                            <span>กำไร <b :class="profitClass(s.profit)">{{ formatMoney(s.profit) }}</b></span>
                            <span class="sched-pax">{{ s.passengers_count }} คน · {{ s.bookings_count }} จอง</span>
                          </div>
                        </div>
                      </div>
                    </template>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="!loading && !trips.length" class="empty-state">
      <span class="material-symbols-rounded">query_stats</span>
      <p>ยังไม่มีข้อมูลรายรับหรือค่าใช้จ่ายในช่วงที่เลือก</p>
    </div>

    <!-- ─── Expense editor modal ─── -->
    <div class="modal-overlay" v-if="showExpenses">
      <div class="modal-card">
        <div class="modal-header">
          <h2>ค่าใช้จ่าย — {{ activeSchedule?.departure_label }}</h2>
          <button class="modal-close" @click="closeExpenses"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="exp-summary" v-if="expSummary">
            <span>รายรับจริง <b class="money-green">{{ formatMoney(expSummary.paid_revenue) }}</b></span>
            <span>ค่าใช้จ่าย <b class="money-orange">{{ formatMoney(expSummary.expense_total) }}</b></span>
            <span>กำไร <b :class="profitClass(expSummary.profit)">{{ formatMoney(expSummary.profit) }}</b></span>
          </div>

          <div class="exp-toolbar">
            <button class="btn-secondary btn-xs" @click="applyTemplates" :disabled="expBusy">
              <span class="material-symbols-rounded" style="font-size:16px;">playlist_add</span>
              ใช้รายการประจำ
            </button>
            <button class="btn-secondary btn-xs" @click="openCopy" :disabled="expBusy || !selectedIds.length" title="เลือกรายการในตารางก่อน">
              <span class="material-symbols-rounded" style="font-size:16px;">content_copy</span>
              คัดลอกไปรอบอื่น<template v-if="selectedIds.length"> ({{ selectedIds.length }})</template>
            </button>
          </div>

          <div class="loading-state" v-if="expLoading"><div class="spinner spinner-sm"></div></div>
          <table class="data-table exp-table" v-else>
            <thead>
              <tr>
                <th class="check-col"><input type="checkbox" :checked="allSelected" @change="toggleSelectAll" :disabled="!expenses.length" /></th>
                <th>รายการ</th><th class="num">จำนวนเงิน</th><th>หมายเหตุ</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in expenses" :key="e.id" :class="{ 'row-selected': selectedIds.includes(e.id) }">
                <td class="check-col"><input type="checkbox" :value="e.id" v-model="selectedIds" /></td>
                <td>
                  {{ e.name }}
                  <span v-if="e.expense_template_id" class="tmpl-chip" title="มาจากรายการประจำ">ประจำ</span>
                </td>
                <td class="num money-orange">{{ formatMoney(e.amount) }}</td>
                <td class="exp-note">{{ e.note || '-' }}</td>
                <td class="action-btns">
                  <button class="btn-icon btn-edit" title="แก้ไข" @click="startEdit(e)"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                  <button class="btn-icon btn-delete" title="ลบ" @click="removeExpense(e)"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                </td>
              </tr>
              <tr v-if="!expenses.length"><td colspan="5" class="exp-empty">ยังไม่มีรายการค่าใช้จ่าย</td></tr>
            </tbody>
          </table>

          <!-- Add / edit form -->
          <form class="exp-form" @submit.prevent="submitExpense">
            <div class="form-group">
              <label>{{ expForm.id ? 'แก้ไขรายการ' : 'เพิ่มรายการ' }}</label>
              <input v-model="expForm.name" placeholder="เช่น ค่าน้ำมัน" required />
            </div>
            <div class="form-group exp-amount">
              <label>จำนวนเงิน (บาท)</label>
              <input v-model.number="expForm.amount" type="number" step="0.01" min="0" required />
            </div>
            <div class="form-group">
              <label>หมายเหตุ</label>
              <input v-model="expForm.note" placeholder="ไม่บังคับ" />
            </div>
            <div class="exp-form-actions">
              <button type="button" class="btn-secondary btn-xs" v-if="expForm.id" @click="resetExpForm">ยกเลิก</button>
              <button type="submit" class="btn-primary btn-xs" :disabled="expBusy">
                <span class="material-symbols-rounded animate-spin" v-if="expBusy" style="font-size:16px;">sync</span>
                {{ expForm.id ? 'บันทึก' : 'เพิ่ม' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ─── Copy-to-other-rounds modal ─── -->
    <div class="modal-overlay" v-if="showCopy">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>คัดลอกไปรอบอื่น</h2>
          <button class="modal-close" @click="showCopy = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="page-subtitle" style="margin-bottom:12px;">คัดลอก {{ selectedIds.length }} รายการที่เลือก ไปยังรอบเดินทางที่ติ๊กด้านล่าง</p>

          <div v-if="!copyTargets.length" class="exp-empty">ทริปนี้ไม่มีรอบเดินทางอื่นให้คัดลอกไป</div>
          <template v-else>
            <label class="copy-row copy-all">
              <input type="checkbox" :checked="allTargetsSelected" @change="toggleAllTargets" />
              <span>เลือกทุกรอบ</span>
            </label>
            <div class="copy-list">
              <label v-for="s in copyTargets" :key="s.schedule_id" class="copy-row">
                <input type="checkbox" :value="s.schedule_id" v-model="targetIds" />
                <span>{{ s.departure_label }}</span>
                <span class="status-badge" :class="`status-${s.status}`">{{ s.status }}</span>
              </label>
            </div>
          </template>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showCopy = false">ยกเลิก</button>
          <button class="btn-primary" :disabled="copyBusy || !targetIds.length" @click="doCopy">
            <span class="material-symbols-rounded animate-spin" v-if="copyBusy" style="font-size:16px;">sync</span>
            คัดลอก
          </button>
        </div>
      </div>
    </div>

    <!-- ─── Templates manager modal ─── -->
    <div class="modal-overlay" v-if="showTemplates">
      <div class="modal-card">
        <div class="modal-header">
          <h2>รายการประจำ — {{ activeTrip?.title }}</h2>
          <button class="modal-close" @click="showTemplates = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="page-subtitle" style="margin-bottom:12px;">รายการที่ตั้งไว้ที่นี่ดึงเข้ารอบเดินทางได้เร็วด้วยปุ่ม "ใช้รายการประจำ"</p>

          <div class="loading-state" v-if="tmplLoading"><div class="spinner spinner-sm"></div></div>
          <table class="data-table exp-table" v-else>
            <thead>
              <tr><th>รายการ</th><th class="num">จำนวนตั้งต้น</th><th>สถานะ</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="tm in templates" :key="tm.id">
                <td>{{ tm.name }}</td>
                <td class="num">{{ tm.default_amount != null ? formatMoney(tm.default_amount) : '-' }}</td>
                <td><span class="status-badge" :class="tm.is_active ? 'status-active' : 'status-inactive'">{{ tm.is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                <td class="action-btns">
                  <button class="btn-icon btn-edit" title="แก้ไข" @click="startEditTmpl(tm)"><span class="material-symbols-rounded" style="font-size:16px;">edit</span></button>
                  <button class="btn-icon btn-delete" title="ลบ" @click="removeTemplate(tm)"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                </td>
              </tr>
              <tr v-if="!templates.length"><td colspan="4" class="exp-empty">ยังไม่มีรายการประจำ</td></tr>
            </tbody>
          </table>

          <form class="exp-form" @submit.prevent="submitTemplate">
            <div class="form-group">
              <label>{{ tmplForm.id ? 'แก้ไขรายการประจำ' : 'เพิ่มรายการประจำ' }}</label>
              <input v-model="tmplForm.name" placeholder="เช่น ค่าน้ำมัน" required />
            </div>
            <div class="form-group exp-amount">
              <label>จำนวนตั้งต้น</label>
              <input v-model.number="tmplForm.default_amount" type="number" step="0.01" min="0" placeholder="ไม่บังคับ" />
            </div>
            <div class="form-group">
              <label>สถานะ</label>
              <select v-model="tmplForm.is_active">
                <option :value="true">ใช้งาน</option>
                <option :value="false">ปิด</option>
              </select>
            </div>
            <div class="exp-form-actions">
              <button type="button" class="btn-secondary btn-xs" v-if="tmplForm.id" @click="resetTmplForm">ยกเลิก</button>
              <button type="submit" class="btn-primary btn-xs" :disabled="tmplBusy">
                <span class="material-symbols-rounded animate-spin" v-if="tmplBusy" style="font-size:16px;">sync</span>
                {{ tmplForm.id ? 'บันทึก' : 'เพิ่ม' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();

const filters = reactive({ from: '', to: '' });
const loading = ref(false);
const summary = ref(null);
const trips = ref([]);

// expanded trip detail
const expanded = ref(null);
const detail = ref(null);
const detailLoading = ref(false);

// expense modal state
const showExpenses = ref(false);
const activeTrip = ref(null);
const activeSchedule = ref(null);
const expenses = ref([]);
const expSummary = ref(null);
const expLoading = ref(false);
const expBusy = ref(false);
const expForm = reactive({ id: null, name: '', amount: null, note: '' });

// row selection + copy-to-other-rounds state
const selectedIds = ref([]);
const allSelected = computed(() => expenses.value.length > 0 && selectedIds.value.length === expenses.value.length);
const showCopy = ref(false);
const targetIds = ref([]);
const copyBusy = ref(false);
const copyTargets = computed(() => (detail.value?.schedules || []).filter(s => s.schedule_id !== activeSchedule.value?.schedule_id));
const allTargetsSelected = computed(() => copyTargets.value.length > 0 && targetIds.value.length === copyTargets.value.length);

// templates modal state
const showTemplates = ref(false);
const templates = ref([]);
const tmplLoading = ref(false);
const tmplBusy = ref(false);
const tmplForm = reactive({ id: null, name: '', default_amount: null, is_active: true });

function formatMoney(amount) {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
}
function profitClass(v) {
  return v < 0 ? 'money-red' : 'money-green';
}

async function loadSummary() {
  loading.value = true;
  expanded.value = null;
  detail.value = null;
  try {
    const data = await admin.fetchFinanceTrips({
      from: filters.from || undefined,
      to: filters.to || undefined,
    });
    summary.value = data.summary;
    trips.value = data.trips;
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดข้อมูลได้');
  } finally {
    loading.value = false;
  }
}

function clearFilters() {
  filters.from = '';
  filters.to = '';
  loadSummary();
}

async function toggleTrip(t) {
  if (expanded.value === t.trip_id) {
    expanded.value = null;
    return;
  }
  expanded.value = t.trip_id;
  detail.value = null;
  detailLoading.value = true;
  try {
    detail.value = await admin.fetchTripScheduleProfit(t.trip_id);
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดรอบเดินทางได้');
    expanded.value = null;
  } finally {
    detailLoading.value = false;
  }
}

// refresh the row + summary numbers after editing expenses for a schedule
async function refreshAfterExpenseChange() {
  if (expanded.value) {
    detail.value = await admin.fetchTripScheduleProfit(expanded.value);
  }
  const data = await admin.fetchFinanceTrips({ from: filters.from || undefined, to: filters.to || undefined });
  summary.value = data.summary;
  trips.value = data.trips;
}

// ─── Expense editor ───
async function openExpenses(t, s) {
  activeTrip.value = t;
  activeSchedule.value = s;
  showExpenses.value = true;
  resetExpForm();
  await loadExpenses();
}

async function loadExpenses() {
  expLoading.value = true;
  try {
    const data = await admin.fetchScheduleExpenses(activeSchedule.value.schedule_id);
    expenses.value = data.expenses;
    expSummary.value = data.summary;
    // keep selection only for rows that still exist
    const ids = expenses.value.map(e => e.id);
    selectedIds.value = selectedIds.value.filter(id => ids.includes(id));
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดค่าใช้จ่ายได้');
  } finally {
    expLoading.value = false;
  }
}

function toggleSelectAll(ev) {
  selectedIds.value = ev.target.checked ? expenses.value.map(e => e.id) : [];
}

// ─── Copy selected expenses to other rounds ───
function openCopy() {
  if (!selectedIds.value.length) return;
  targetIds.value = [];
  showCopy.value = true;
}

function toggleAllTargets(ev) {
  targetIds.value = ev.target.checked ? copyTargets.value.map(s => s.schedule_id) : [];
}

async function doCopy() {
  if (!targetIds.value.length) return;
  copyBusy.value = true;
  try {
    const res = await admin.copyExpensesTo(activeSchedule.value.schedule_id, {
      expense_ids: [...selectedIds.value],
      target_schedule_ids: [...targetIds.value],
    });
    showCopy.value = false;
    selectedIds.value = [];
    alert(res.message);
    // target rounds changed — refresh trip detail + summary figures
    await refreshAfterExpenseChange();
  } catch (e) {
    alert(e.response?.data?.message || 'คัดลอกไม่สำเร็จ');
  } finally {
    copyBusy.value = false;
  }
}

function closeExpenses() {
  showExpenses.value = false;
  refreshAfterExpenseChange();
}

function resetExpForm() {
  expForm.id = null;
  expForm.name = '';
  expForm.amount = null;
  expForm.note = '';
}
function startEdit(e) {
  expForm.id = e.id;
  expForm.name = e.name;
  expForm.amount = e.amount;
  expForm.note = e.note || '';
}

async function submitExpense() {
  expBusy.value = true;
  try {
    const id = activeSchedule.value.schedule_id;
    if (expForm.id) {
      const data = await admin.updateScheduleExpense(id, expForm.id, { name: expForm.name, amount: expForm.amount, note: expForm.note || null });
      expSummary.value = data.summary;
    } else {
      const data = await admin.createScheduleExpense(id, { name: expForm.name, amount: expForm.amount, note: expForm.note || null });
      expSummary.value = data.summary;
    }
    resetExpForm();
    await loadExpenses();
  } catch (e) {
    alert(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

async function removeExpense(e) {
  if (!confirm(`ลบรายการ "${e.name}" ?`)) return;
  expBusy.value = true;
  try {
    const data = await admin.deleteScheduleExpense(activeSchedule.value.schedule_id, e.id);
    expSummary.value = data.summary;
    await loadExpenses();
  } catch (err) {
    alert(err.response?.data?.message || 'ลบไม่สำเร็จ');
  } finally {
    expBusy.value = false;
  }
}

async function applyTemplates() {
  expBusy.value = true;
  try {
    const res = await admin.applyExpenseTemplates(activeSchedule.value.schedule_id);
    if (res.data) {
      expenses.value = res.data.expenses;
      expSummary.value = res.data.summary;
    }
    alert(res.message);
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถใช้รายการประจำได้');
  } finally {
    expBusy.value = false;
  }
}

// ─── Templates manager ───
async function openTemplates(t) {
  activeTrip.value = t;
  showTemplates.value = true;
  resetTmplForm();
  tmplLoading.value = true;
  try {
    templates.value = await admin.fetchExpenseTemplates(t.trip_id);
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดรายการประจำได้');
  } finally {
    tmplLoading.value = false;
  }
}

function resetTmplForm() {
  tmplForm.id = null;
  tmplForm.name = '';
  tmplForm.default_amount = null;
  tmplForm.is_active = true;
}
function startEditTmpl(tm) {
  tmplForm.id = tm.id;
  tmplForm.name = tm.name;
  tmplForm.default_amount = tm.default_amount != null ? Number(tm.default_amount) : null;
  tmplForm.is_active = !!tm.is_active;
}

async function submitTemplate() {
  tmplBusy.value = true;
  try {
    const tripId = activeTrip.value.trip_id;
    const payload = { name: tmplForm.name, default_amount: tmplForm.default_amount, is_active: tmplForm.is_active };
    if (tmplForm.id) {
      await admin.updateExpenseTemplate(tripId, tmplForm.id, payload);
    } else {
      await admin.createExpenseTemplate(tripId, payload);
    }
    resetTmplForm();
    templates.value = await admin.fetchExpenseTemplates(tripId);
  } catch (e) {
    alert(e.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    tmplBusy.value = false;
  }
}

async function removeTemplate(tm) {
  if (!confirm(`ลบรายการประจำ "${tm.name}" ?`)) return;
  tmplBusy.value = true;
  try {
    await admin.deleteExpenseTemplate(activeTrip.value.trip_id, tm.id);
    templates.value = await admin.fetchExpenseTemplates(activeTrip.value.trip_id);
  } catch (e) {
    alert(e.response?.data?.message || 'ลบไม่สำเร็จ');
  } finally {
    tmplBusy.value = false;
  }
}

onMounted(loadSummary);
</script>

<style scoped>
@import url('./admin-shared.css');

.summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 16px 0; }
.sc-item { background: #fff; border: 1px solid #eef0f3; border-radius: 12px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; }
.sc-label { font-size: 12px; color: #8b909a; font-weight: 700; }
.sc-val { font-size: 20px; font-weight: 800; }
.sc-period { font-size: 14px; color: #444; }

.money-green { color: #16a34a !important; }
.money-orange { color: #ea580c !important; }
.money-red { color: #dc2626 !important; }

td.num, th.num { text-align: right; white-space: nowrap; }

.trip-row { cursor: pointer; }
.trip-row:hover { background: #f7f8fa; }
.trip-title { font-weight: 700; }
.expand-icon { transition: transform .15s ease; color: #9aa0aa; vertical-align: middle; }
.expand-icon.open { transform: rotate(90deg); }

.detail-row > td { background: #f7f8fa; padding: 12px 16px; }
.schedule-loading { display: flex; justify-content: center; padding: 16px; }
.spinner-sm { width: 22px; height: 22px; }
.schedule-list { display: flex; flex-direction: column; gap: 10px; }
.sched-list-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.sched-count { font-size: 13px; font-weight: 700; color: #6b7280; }
.sched-scroll { display: flex; flex-direction: column; gap: 10px; max-height: min(60vh, 520px); overflow-y: auto; padding-right: 4px; }
.sched-card { background: #fff; border: 1px solid #eef0f3; border-radius: 10px; padding: 12px 14px; }
.sched-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px; flex-wrap: wrap; }
.sched-date { display: flex; align-items: center; gap: 8px; font-weight: 700; }
.sched-date .material-symbols-rounded { font-size: 18px; color: #9aa0aa; }
.sched-figures { display: flex; flex-wrap: wrap; gap: 16px; font-size: 14px; color: #555; }
.sched-figures b { font-weight: 800; }
.sched-pax { color: #8b909a; margin-left: auto; }

.btn-xs { padding: 6px 10px; font-size: 13px; display: inline-flex; align-items: center; gap: 4px; }

.exp-summary { display: flex; flex-wrap: wrap; gap: 16px; padding: 10px 12px; background: #f7f8fa; border-radius: 10px; font-size: 14px; margin-bottom: 12px; }
.exp-summary b { font-weight: 800; }
.exp-toolbar { margin-bottom: 10px; }
.exp-table th, .exp-table td { padding: 8px 10px; }
.exp-note { color: #8b909a; max-width: 220px; }
.exp-empty { text-align: center; color: #9aa0aa; padding: 16px; }
.tmpl-chip { display: inline-block; margin-left: 6px; font-size: 11px; font-weight: 700; color: #2563eb; background: #eff6ff; border-radius: 6px; padding: 1px 6px; }

.exp-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #e5e7eb; }
.exp-form .form-group { flex: 1; min-width: 140px; margin: 0; }
.exp-form .exp-amount { flex: 0 0 140px; }
.exp-form-actions { display: flex; gap: 8px; }

.check-col { width: 36px; text-align: center; }
.check-col input { width: 16px; height: 16px; cursor: pointer; }
.exp-table tr.row-selected { background: #eff6ff; }

.copy-all { font-weight: 700; border-bottom: 1px solid #eef0f3; margin-bottom: 6px; }
.copy-list { display: flex; flex-direction: column; gap: 2px; max-height: 280px; overflow-y: auto; }
.copy-row { display: flex; align-items: center; gap: 10px; padding: 8px 6px; border-radius: 8px; cursor: pointer; }
.copy-row:hover { background: #f7f8fa; }
.copy-row input { width: 16px; height: 16px; cursor: pointer; }
.copy-row .status-badge { margin-left: auto; }
</style>
