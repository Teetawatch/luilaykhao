<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">calendar_clock</span>
          ผ่อนชำระ
        </h1>
        <p class="page-subtitle">
          ดูว่าลูกค้าแต่ละคนจ่ายไปกี่งวดแล้ว เหลืออีกเท่าไหร่ ใครเลยกำหนด และเปิดดูสลิปของทุกงวดพร้อมผลตรวจสลิป
        </p>
      </div>
      <div class="header-actions">
        <label class="toggle-inline">
          <input v-model="includeCancelled" type="checkbox" @change="fetchData" />
          รวมการจองที่ยกเลิกแล้ว
        </label>
        <button class="btn-secondary" :disabled="loading" @click="fetchData">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span>
          {{ loading ? 'กำลังโหลด' : 'รีเฟรช' }}
        </button>
      </div>
    </div>

    <!-- การ์ดสรุป — กดเพื่อกรองตารางด้านล่าง -->
    <div class="summary-grid">
      <button
        v-for="card in summaryCards"
        :key="card.filter"
        class="summary-card"
        :class="[card.tone, { active: filter === card.filter }]"
        @click="filter = card.filter"
      >
        <span class="material-symbols-rounded">{{ card.icon }}</span>
        <div>
          <strong>{{ card.value }}</strong>
          <span>{{ card.label }}</span>
          <small v-if="card.hint">{{ card.hint }}</small>
        </div>
      </button>
    </div>

    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model.trim="search" type="text" placeholder="ค้นหา ชื่อลูกค้า / เบอร์โทร / อีเมล / รหัสจอง / ทริป" />
      </div>
      <select v-model="filter">
        <option value="all">ทุกสถานะ</option>
        <option value="outstanding">ยังผ่อนไม่ครบ</option>
        <option value="overdue">เลยกำหนดชำระ</option>
        <option value="due_soon">ครบกำหนดใน 7 วัน</option>
        <option value="needs_review">มีสลิปรอตรวจ</option>
        <option value="completed">ผ่อนครบแล้ว</option>
      </select>
      <select v-model="scheduleId">
        <option value="">ทุกรอบเดินทาง</option>
        <option v-for="s in schedules" :key="s.id" :value="s.id">
          {{ s.trip_title }} · {{ thaiShort(s.departure_date) }}
        </option>
      </select>
    </div>

    <div class="table-card">
      <div v-if="loading" class="loading-state"><div class="spinner"></div></div>

      <div v-else-if="!items.length" class="empty-state">
        <span class="material-symbols-rounded empty-icon">receipt_long</span>
        ไม่พบการจองแบบผ่อนชำระตามเงื่อนไขที่เลือก
      </div>

      <div v-else class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:34px;"></th>
              <th>ลูกค้า</th>
              <th>ทริป / วันเดินทาง</th>
              <th>ชำระแล้ว</th>
              <th>ยอดเงิน</th>
              <th>งวดถัดไป</th>
              <th>สลิป</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in items" :key="row.booking_ref">
              <tr class="summary-row" :class="{ open: expanded === row.booking_ref }" @click="toggle(row)">
                <td>
                  <span class="material-symbols-rounded chevron" :class="{ rotated: expanded === row.booking_ref }">
                    expand_more
                  </span>
                </td>
                <td>
                  <strong>{{ row.customer_name }}</strong>
                  <div class="cell-sub">
                    {{ row.customer_phone || 'ไม่มีเบอร์' }}
                    <span class="dot">·</span>
                    <span class="booking-ref">{{ row.booking_ref }}</span>
                    <span v-if="row.booking_status === 'cancelled'" class="status-badge status-cancelled">ยกเลิกแล้ว</span>
                  </div>
                </td>
                <td>
                  {{ row.trip_title || '—' }}
                  <div class="cell-sub">
                    <span class="material-symbols-rounded inline-icon">event</span>
                    {{ thaiShort(row.departure_date) || 'ยังไม่ระบุ' }}
                  </div>
                </td>
                <td>
                  <div class="progress-wrap">
                    <div class="progress-track">
                      <div
                        class="progress-fill"
                        :class="{ done: row.is_complete, late: row.overdue_count > 0 }"
                        :style="{ width: row.progress_percent + '%' }"
                      ></div>
                    </div>
                    <span class="progress-text">{{ row.paid_count }}/{{ row.installment_count }} งวด</span>
                  </div>
                </td>
                <td>
                  <strong class="money">{{ money(row.paid_amount) }}</strong>
                  <div class="cell-sub">
                    <template v-if="row.outstanding_amount > 0">
                      ค้างอีก <span class="text-due">{{ money(row.outstanding_amount) }}</span>
                    </template>
                    <template v-else>ครบตามยอด {{ money(row.total_amount) }}</template>
                  </div>
                </td>
                <td>
                  <template v-if="row.next_due">
                    <span class="due-chip" :class="dueTone(row.next_due)">{{ dueLabel(row.next_due) }}</span>
                    <div class="cell-sub">
                      งวดที่ {{ row.next_due.installment_no }} · {{ money(row.next_due.amount) }}
                      <span class="dot">·</span>{{ thaiShort(row.next_due.due_date) }}
                    </div>
                  </template>
                  <span v-else class="due-chip done">ผ่อนครบแล้ว</span>
                </td>
                <td>
                  <span v-if="row.needs_review_count" class="review-chip">
                    <span class="material-symbols-rounded inline-icon">pending_actions</span>
                    รอตรวจ {{ row.needs_review_count }}
                  </span>
                  <span v-else class="slip-count">{{ slipCount(row) }} ใบ</span>
                </td>
              </tr>

              <tr v-if="expanded === row.booking_ref" class="detail-row">
                <td colspan="7">
                  <div class="detail-panel">
                    <div class="detail-toolbar">
                      <div class="detail-facts">
                        <span><b>ยอดรวม</b> {{ money(row.total_amount) }}</span>
                        <span><b>ชำระแล้ว</b> {{ money(row.paid_amount) }}</span>
                        <span :class="{ 'text-due': row.outstanding_amount > 0 }">
                          <b>คงเหลือ</b> {{ money(row.outstanding_amount) }}
                        </span>
                        <span v-if="row.overdue_count" class="text-late">
                          <b>เลยกำหนด</b> {{ row.overdue_count }} งวด · {{ money(row.overdue_amount) }}
                        </span>
                        <span v-if="row.customer_email"><b>อีเมล</b> {{ row.customer_email }}</span>
                      </div>
                      <div class="detail-buttons">
                        <button v-if="row.pay_url" class="btn-secondary compact" @click.stop="copyPayLink(row)">
                          <span class="material-symbols-rounded">link</span>
                          {{ copiedRef === row.booking_ref ? 'คัดลอกแล้ว' : 'คัดลอกลิงก์ชำระเงิน' }}
                        </button>
                        <button
                          v-if="!row.is_complete && row.booking_status !== 'cancelled'"
                          class="btn-primary compact"
                          :disabled="sending === row.booking_ref"
                          @click.stop="sendPaymentLink(row)"
                        >
                          <span class="material-symbols-rounded">outgoing_mail</span>
                          ส่งลิงก์ทวงค่างวด
                        </button>
                        <router-link
                          class="btn-secondary compact"
                          :to="{ name: 'admin-bookings', query: { search: row.booking_ref } }"
                          @click.stop
                        >
                          <span class="material-symbols-rounded">open_in_new</span>
                          เปิดหน้าการจอง
                        </router-link>
                      </div>
                    </div>

                    <div class="installment-grid">
                      <div
                        v-for="inst in row.installments"
                        :key="inst.id"
                        class="installment-card"
                        :class="inst.display_status"
                      >
                        <div class="inst-head">
                          <span class="inst-no">{{ inst.installment_no }}</span>
                          <div>
                            <strong>{{ money(inst.amount) }}</strong>
                            <span class="inst-due">ครบกำหนด {{ thaiShort(inst.due_date) || '—' }}</span>
                          </div>
                          <span class="inst-status" :class="inst.display_status">
                            {{ statusLabel(inst) }}
                          </span>
                        </div>

                        <dl class="inst-facts">
                          <template v-if="inst.is_paid">
                            <dt>ชำระเมื่อ</dt>
                            <dd>{{ dateTime(inst.paid_at) || '—' }}</dd>
                            <dt>เวลาที่โอน</dt>
                            <dd>{{ dateTime(inst.transfer_datetime) || 'ลูกค้าไม่ได้ระบุ' }}</dd>
                            <dt>ช่องทาง</dt>
                            <dd>{{ methodLabel(inst.payment_method) }}</dd>
                            <dt v-if="inst.payment_ref">เลขอ้างอิง</dt>
                            <dd v-if="inst.payment_ref" class="mono">{{ inst.payment_ref }}</dd>
                          </template>
                          <template v-else>
                            <dt>สถานะ</dt>
                            <dd>{{ inst.is_overdue ? `เลยกำหนดมา ${Math.abs(inst.days_until_due)} วัน` : `อีก ${inst.days_until_due} วันครบกำหนด` }}</dd>
                          </template>
                        </dl>

                        <div v-if="inst.has_slip" class="slip-block">
                          <div class="slip-head">
                            <span class="ocr-badge" :class="`ocr-${inst.slip_ocr_status || 'none'}`">
                              {{ ocrLabel(inst.slip_ocr_status) }}
                            </span>
                            <a :href="inst.slip_url" target="_blank" rel="noopener" class="slip-open" @click.stop>
                              <span class="material-symbols-rounded inline-icon">open_in_new</span>
                              เปิดไฟล์
                            </a>
                          </div>

                          <a
                            v-if="!inst.slip_is_pdf"
                            class="slip-thumb"
                            href="#"
                            @click.stop.prevent="lightbox = { url: inst.slip_url, title: `${row.booking_ref} · งวดที่ ${inst.installment_no}` }"
                          >
                            <img :src="inst.slip_url" :alt="`สลิปงวดที่ ${inst.installment_no}`" loading="lazy" />
                          </a>
                          <a v-else class="slip-pdf" :href="inst.slip_url" target="_blank" rel="noopener" @click.stop>
                            <span class="material-symbols-rounded">picture_as_pdf</span>
                            สลิปเป็นไฟล์ PDF — กดเพื่อเปิด
                          </a>

                          <ul v-if="inst.slip_ocr" class="ocr-facts">
                            <li>
                              ยอดในสลิป
                              <b>{{ inst.slip_ocr.amount === null ? 'อ่านยอดไม่ออก' : money(inst.slip_ocr.amount) }}</b>
                              <template v-if="inst.slip_ocr.amount !== null">
                                <span
                                  v-if="inst.slip_ocr.amount_diff"
                                  class="diff"
                                  :class="inst.slip_ocr.amount_diff > 0 ? 'over' : 'under'"
                                >
                                  {{ inst.slip_ocr.amount_diff > 0 ? 'เกิน' : 'ขาด' }} {{ money(Math.abs(inst.slip_ocr.amount_diff)) }}
                                </span>
                                <span v-else class="diff match">ตรงกับยอดที่ต้องจ่าย</span>
                              </template>
                            </li>
                            <li v-if="inst.slip_ocr.datetime">เวลาในสลิป <b>{{ inst.slip_ocr.datetime }}</b></li>
                            <li v-if="inst.slip_ocr.bank">ธนาคาร <b>{{ inst.slip_ocr.bank }}</b></li>
                            <li v-if="inst.slip_ocr.transaction_id">รหัสรายการ <b class="mono">{{ inst.slip_ocr.transaction_id }}</b></li>
                          </ul>

                          <div class="slip-actions">
                            <button
                              v-if="['pending', 'failed', 'rejected'].includes(inst.slip_ocr_status) || !inst.slip_ocr_status"
                              class="btn-primary compact xs"
                              @click.stop="approveSlip(row, inst)"
                            >อนุมัติสลิป</button>
                            <button
                              v-if="['pending', 'failed', 'verified'].includes(inst.slip_ocr_status) || !inst.slip_ocr_status"
                              class="btn-danger compact xs"
                              @click.stop="rejectSlip(row, inst)"
                            >ปฏิเสธ</button>
                            <button class="btn-secondary compact xs" @click.stop="reverifySlip(row, inst)">
                              ตรวจสลิปใหม่
                            </button>
                          </div>
                        </div>

                        <p v-else-if="inst.is_paid" class="no-slip">
                          บันทึกว่าชำระแล้วโดยไม่มีสลิปในระบบ (เช่น แอดมินบันทึกเอง)
                        </p>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ดูสลิปเต็มจอ -->
    <div v-if="lightbox" class="modal-overlay" @click="lightbox = null">
      <div class="lightbox-card" @click.stop>
        <div class="lightbox-head">
          <strong>{{ lightbox.title }}</strong>
          <div>
            <a :href="lightbox.url" target="_blank" rel="noopener" class="btn-secondary compact">
              <span class="material-symbols-rounded">open_in_new</span>
              เปิดแท็บใหม่
            </a>
            <button class="modal-close" @click="lightbox = null">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
        </div>
        <img :src="lightbox.url" alt="สลิปโอนเงิน" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';
import { thaiShort } from '../../lib/thaiDate';

const toast = useToast();
const swal = useSwal();

const loading = ref(true);
const items = ref([]);
const schedules = ref([]);
const summary = ref({});
const search = ref('');
const filter = ref('all');
const scheduleId = ref('');
const includeCancelled = ref(false);
const expanded = ref(null);
const lightbox = ref(null);
const copiedRef = ref(null);
const sending = ref(null);

const summaryCards = computed(() => [
  {
    filter: 'outstanding',
    icon: 'hourglass_top',
    tone: 'tone-blue',
    value: summary.value.active_bookings ?? 0,
    label: 'กำลังผ่อนอยู่',
    hint: `ค้างรวม ${money(summary.value.outstanding_amount)}`,
  },
  {
    filter: 'overdue',
    icon: 'event_busy',
    tone: 'tone-red',
    value: summary.value.overdue_bookings ?? 0,
    label: 'เลยกำหนดชำระ',
    hint: `ค้าง ${money(summary.value.overdue_amount)}`,
  },
  {
    filter: 'due_soon',
    icon: 'notifications_active',
    tone: 'tone-amber',
    value: summary.value.due_soon_bookings ?? 0,
    label: 'ครบกำหนดใน 7 วัน',
    hint: 'ควรส่งลิงก์เตือนล่วงหน้า',
  },
  {
    filter: 'needs_review',
    icon: 'fact_check',
    tone: 'tone-amber',
    value: summary.value.needs_review_bookings ?? 0,
    label: 'มีสลิปรอตรวจ',
    hint: 'OCR ยังไม่ผ่านหรือยังไม่ได้ตรวจ',
  },
  {
    filter: 'completed',
    icon: 'task_alt',
    tone: 'tone-green',
    value: summary.value.completed_bookings ?? 0,
    label: 'ผ่อนครบแล้ว',
    hint: `เก็บเงินได้ ${money(summary.value.collected_amount)}`,
  },
  {
    filter: 'all',
    icon: 'receipt_long',
    tone: 'tone-slate',
    value: summary.value.bookings ?? 0,
    label: 'การจองผ่อนทั้งหมด',
    hint: 'กดเพื่อดูทุกสถานะ',
  },
]);

async function fetchData() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/installments', {
      params: {
        search: search.value || undefined,
        filter: filter.value,
        schedule_id: scheduleId.value || undefined,
        include_cancelled: includeCancelled.value ? 1 : undefined,
      },
    });
    const payload = data.data ?? {};
    items.value = payload.items ?? [];
    schedules.value = payload.schedules ?? [];
    summary.value = payload.summary ?? {};
    // แถวที่กางอยู่หายไปจากผลลัพธ์ใหม่ ก็ไม่ต้องจำว่ากางไว้
    if (expanded.value && !items.value.some((row) => row.booking_ref === expanded.value)) {
      expanded.value = null;
    }
  } catch (e) {
    items.value = [];
    toast.error(e.response?.data?.message || 'โหลดข้อมูลผ่อนชำระไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

/** ดึงแถวเดียวมาแทนที่ของเดิม — ใช้หลังอนุมัติ/ปฏิเสธสลิป ลิงก์สลิปจะได้ถูกเซ็นใหม่ด้วย */
async function refreshRow(bookingRef) {
  try {
    const { data } = await api.get(`/admin/installments/${bookingRef}`);
    const index = items.value.findIndex((row) => row.booking_ref === bookingRef);
    if (index !== -1) items.value[index] = data.data;
  } catch {
    await fetchData();
  }
}

function toggle(row) {
  if (expanded.value === row.booking_ref) {
    expanded.value = null;
    return;
  }
  expanded.value = row.booking_ref;
  // ลิงก์สลิปที่เซ็นไว้หมดอายุใน 30 นาที — เปิดดูทีไรก็ขอชุดใหม่ทุกครั้ง
  // ไม่งั้นแท็บที่เปิดค้างไว้ทั้งเช้าจะกดดูสลิปไม่ขึ้น
  refreshRow(row.booking_ref);
}

async function approveSlip(row, inst) {
  const ok = await swal.confirm({
    title: `อนุมัติสลิปงวดที่ ${inst.installment_no}?`,
    text: `${row.customer_name} · ${money(inst.amount)}`,
    confirmText: 'อนุมัติ',
  });
  if (!ok.isConfirmed) return;

  try {
    await api.post(`/admin/bookings/${row.booking_ref}/slip/approve`, {
      slip_type: 'installment',
      installment_no: inst.installment_no,
    });
    toast.success('อนุมัติสลิปแล้ว');
    await refreshRow(row.booking_ref);
  } catch (e) {
    toast.error(e.response?.data?.message || 'อนุมัติสลิปไม่สำเร็จ');
  }
}

async function rejectSlip(row, inst) {
  const result = await swal.confirm({
    title: `ปฏิเสธสลิปงวดที่ ${inst.installment_no}?`,
    text: 'ลูกค้าจะได้รับแจ้งเตือนให้อัพโหลดสลิปใหม่',
    icon: 'warning',
    confirmText: 'ปฏิเสธสลิป',
    input: 'text',
    inputPlaceholder: 'เหตุผล (ไม่บังคับ)',
  });
  if (!result.isConfirmed) return;

  try {
    await api.post(`/admin/bookings/${row.booking_ref}/slip/reject`, {
      slip_type: 'installment',
      installment_no: inst.installment_no,
      reason: result.value || undefined,
    });
    toast.success('ปฏิเสธสลิปแล้ว');
    await refreshRow(row.booking_ref);
  } catch (e) {
    toast.error(e.response?.data?.message || 'ปฏิเสธสลิปไม่สำเร็จ');
  }
}

async function reverifySlip(row, inst) {
  try {
    await api.post(`/admin/bookings/${row.booking_ref}/slip/reverify`, {
      slip_type: 'installment',
      installment_no: inst.installment_no,
    });
    toast.info('ส่งสลิปเข้าคิวตรวจใหม่แล้ว');
    await refreshRow(row.booking_ref);
  } catch (e) {
    toast.error(e.response?.data?.message || 'ส่งตรวจสลิปใหม่ไม่สำเร็จ');
  }
}

async function sendPaymentLink(row) {
  const ok = await swal.confirm({
    title: 'ส่งลิงก์ชำระค่างวด?',
    text: `ส่งอีเมลถึง ${row.customer_email || row.customer_name}`,
    confirmText: 'ส่งเลย',
  });
  if (!ok.isConfirmed) return;

  sending.value = row.booking_ref;
  try {
    await api.post(`/admin/payments/${row.booking_ref}/send-link`, { channels: ['email'] });
    toast.success('ส่งลิงก์ชำระเงินแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'ส่งลิงก์ไม่สำเร็จ');
  } finally {
    sending.value = null;
  }
}

async function copyPayLink(row) {
  try {
    await navigator.clipboard.writeText(row.pay_url);
    copiedRef.value = row.booking_ref;
    setTimeout(() => {
      if (copiedRef.value === row.booking_ref) copiedRef.value = null;
    }, 1800);
  } catch {
    prompt('คัดลอกลิงก์นี้:', row.pay_url);
  }
}

function money(value) {
  const number = Number(value ?? 0);
  return `฿${number.toLocaleString('th-TH', { maximumFractionDigits: 2 })}`;
}

function dateTime(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return `${thaiShort(date)} ${date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })} น.`;
}

function dueLabel(next) {
  if (next.days_until_due === null) return 'ไม่ได้กำหนดวัน';
  if (next.is_overdue) return `เลยกำหนด ${Math.abs(next.days_until_due)} วัน`;
  if (next.days_until_due === 0) return 'ครบกำหนดวันนี้';
  return `อีก ${next.days_until_due} วัน`;
}

function dueTone(next) {
  if (next.is_overdue) return 'late';
  if (next.days_until_due !== null && next.days_until_due <= 7) return 'soon';
  return '';
}

function statusLabel(inst) {
  return {
    paid: 'ชำระแล้ว',
    overdue: 'เลยกำหนด',
    due_soon: 'ใกล้ครบกำหนด',
    pending: 'ยังไม่ถึงกำหนด',
  }[inst.display_status] ?? 'รอชำระ';
}

function methodLabel(method) {
  return {
    promptpay: 'พร้อมเพย์ (QR)',
    bank_transfer: 'โอนผ่านธนาคาร',
    credit_card: 'บัตรเครดิต',
    cash: 'เงินสด',
  }[method] ?? (method || '—');
}

function ocrLabel(status) {
  return {
    pending: '⏳ กำลังตรวจสอบ',
    verified: '✅ ผ่านอัตโนมัติ',
    failed: '❌ ต้องตรวจสอบ',
    manually_approved: '✅ อนุมัติด้วยตนเอง',
    rejected: '🚫 ปฏิเสธแล้ว',
  }[status] ?? '— ยังไม่ตรวจ';
}

function slipCount(row) {
  return row.installments.filter((inst) => inst.has_slip).length;
}

let searchTimer = null;
watch(search, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(fetchData, 350);
});
watch([filter, scheduleId], fetchData);

fetchData();
</script>

<style scoped>
@import url('./admin-shared.css');

.header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

.toggle-inline {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #4b5563;
  cursor: pointer;
}

/* ─── การ์ดสรุป ─── */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 18px;
}

.summary-card {
  display: flex;
  align-items: center;
  gap: 12px;
  text-align: left;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 14px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
  font-family: inherit;
}

.summary-card:hover { border-color: #d1d5db; background: #fcfcfc; }
.summary-card.active { border-color: currentColor; background: #fafafa; }
.summary-card > div { display: flex; flex-direction: column; min-width: 0; }
.summary-card strong { font-size: 22px; font-weight: 800; color: #111827; line-height: 1.1; }
.summary-card span:not(.material-symbols-rounded) { font-size: 13px; font-weight: 600; color: #374151; }
.summary-card small { font-size: 11px; color: #9ca3af; margin-top: 2px; }
.summary-card .material-symbols-rounded { font-size: 26px; }

.tone-blue { color: #2563eb; }
.tone-red { color: #dc2626; }
.tone-amber { color: #d97706; }
.tone-green { color: #059669; }
.tone-slate { color: #64748b; }

/* ─── ตัวกรอง (สไตล์หลักมาจาก admin-shared.css) ─── */
.filters-bar select { max-width: 280px; }

/* ─── ตาราง ─── */
.summary-row { cursor: pointer; }
.summary-row.open td { background: #f8fafc; }
.summary-row td { vertical-align: middle; }

.chevron { font-size: 20px; color: #9ca3af; transition: transform 0.15s; }
.chevron.rotated { transform: rotate(180deg); }

.cell-sub { font-size: 12px; color: #6b7280; margin-top: 3px; }
.cell-sub .dot { margin: 0 5px; color: #d1d5db; }
.inline-icon { font-size: 13px !important; vertical-align: -2px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
.money { font-size: 15px; font-weight: 800; color: #111827; }
.text-due { color: #b45309; font-weight: 700; }
.text-late { color: #b91c1c; }

.progress-wrap { display: flex; align-items: center; gap: 8px; min-width: 140px; }
.progress-track { flex: 1; height: 8px; background: #e5e7eb; border-radius: 999px; overflow: hidden; }
.progress-fill { height: 100%; background: #2563eb; border-radius: 999px; transition: width 0.2s; }
.progress-fill.done { background: #059669; }
.progress-fill.late { background: #dc2626; }
.progress-text { font-size: 12px; font-weight: 700; color: #374151; white-space: nowrap; }

.due-chip {
  display: inline-block;
  font-size: 12px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
  background: #eef2ff;
  color: #4338ca;
}
.due-chip.soon { background: #fef3c7; color: #b45309; }
.due-chip.late { background: #fee2e2; color: #b91c1c; }
.due-chip.done { background: #d1fae5; color: #065f46; }

.review-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: #b45309;
  background: #fef3c7;
  padding: 3px 10px;
  border-radius: 999px;
}
.slip-count { font-size: 12px; color: #9ca3af; }

/* ─── แผงรายละเอียดรายงวด ─── */
.detail-row td { background: #f8fafc; padding: 0 !important; }

.detail-panel { padding: 16px 20px 20px; border-top: 1px solid #e5e7eb; }

.detail-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}

.detail-facts { display: flex; flex-wrap: wrap; gap: 16px; font-size: 13px; color: #374151; }
.detail-facts b { color: #6b7280; font-weight: 700; margin-right: 4px; }
.detail-buttons { display: flex; flex-wrap: wrap; gap: 8px; }

.compact { padding: 7px 12px; font-size: 13px; }
.compact.xs { padding: 5px 10px; font-size: 12px; }

.installment-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}

.installment-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-left: 4px solid #cbd5e1;
  border-radius: 10px;
  padding: 14px;
}

.installment-card.paid { border-left-color: #059669; }
.installment-card.overdue { border-left-color: #dc2626; }
.installment-card.due_soon { border-left-color: #d97706; }

.inst-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.inst-head > div { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.inst-head strong { font-size: 16px; font-weight: 800; color: #111827; }

.inst-no {
  width: 26px;
  height: 26px;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: #f1f5f9;
  color: #475569;
  font-size: 13px;
  font-weight: 800;
}

.inst-due { font-size: 12px; color: #6b7280; }

.inst-status { font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; }
.inst-status.paid { background: #d1fae5; color: #065f46; }
.inst-status.overdue { background: #fee2e2; color: #b91c1c; }
.inst-status.due_soon { background: #fef3c7; color: #b45309; }

.inst-facts {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 3px 10px;
  margin: 0 0 10px;
  font-size: 12px;
}
.inst-facts dt { color: #9ca3af; font-weight: 600; }
.inst-facts dd { margin: 0; color: #374151; }

.slip-block { border-top: 1px dashed #e5e7eb; padding-top: 10px; }
.slip-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }

.ocr-badge { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; background: #f1f5f9; color: #475569; }
.ocr-verified, .ocr-manually_approved { background: #d1fae5; color: #065f46; }
.ocr-failed, .ocr-rejected { background: #fee2e2; color: #b91c1c; }
.ocr-pending { background: #fef3c7; color: #b45309; }

.slip-open { font-size: 12px; font-weight: 700; color: var(--color-accent, #2d7a4f); text-decoration: none; }

.slip-thumb { display: block; border-radius: 8px; overflow: hidden; background: #f8fafc; }
.slip-thumb img { width: 100%; max-height: 190px; object-fit: cover; object-position: top; display: block; }

.slip-pdf {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #b91c1c;
  background: #fef2f2;
  border-radius: 8px;
  padding: 10px;
  text-decoration: none;
}

.ocr-facts { list-style: none; margin: 10px 0 0; padding: 0; font-size: 12px; color: #6b7280; }
.ocr-facts li { display: flex; flex-wrap: wrap; gap: 5px; padding: 2px 0; }
.ocr-facts b { color: #111827; font-weight: 700; }

.diff { font-weight: 700; }
.diff.match { color: #059669; }
.diff.over { color: #b45309; }
.diff.under { color: #b91c1c; }

.slip-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }

.no-slip { margin: 8px 0 0; font-size: 12px; color: #9ca3af; }

.empty-icon { font-size: 48px; color: #d1d5db; display: block; margin-bottom: 12px; }

/* ─── ดูสลิปเต็มจอ ─── */
.lightbox-card {
  background: #fff;
  border-radius: 12px;
  max-width: 560px;
  width: 92vw;
  max-height: 90vh;
  overflow: auto;
}

.lightbox-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 16px;
  border-bottom: 1px solid #e5e7eb;
  position: sticky;
  top: 0;
  background: #fff;
}

.lightbox-head > div { display: flex; align-items: center; gap: 8px; }
.lightbox-card img { width: 100%; display: block; }

@media (max-width: 768px) {
  .detail-facts { gap: 10px; font-size: 12px; }
  .installment-grid { grid-template-columns: 1fr; }
}
</style>
