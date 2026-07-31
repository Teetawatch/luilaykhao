<template>
  <div class="admin-page at-risk-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">group_off</span>
          รอบเสี่ยงไม่ได้ออกเดินทาง
        </h1>
        <p class="page-subtitle">
          รอบที่ใกล้ถึงวันเดินทางแต่ยังจองไม่ถึง {{ summary.min_seats || '—' }} ท่าน
          รวมไว้ที่เดียวพร้อมปุ่มลงมือแก้ — จับตาตั้งแต่เนิ่น ๆ จะยังมีเวลาขายที่นั่งที่เหลือ
        </p>
      </div>
      <div class="header-actions">
        <div class="segmented">
          <button
            v-for="opt in windowOptions"
            :key="opt.value"
            class="segment"
            :class="{ active: windowDays === opt.value }"
            type="button"
            @click="setWindow(opt.value)"
          >{{ opt.label }}</button>
        </div>
        <button class="btn-secondary" :disabled="loading" @click="load()">
          <span class="material-symbols-rounded" :class="{ spin: loading }">refresh</span>
          รีเฟรช
        </button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded warn">event_busy</span>
        <div>
          <span class="stat-label">รอบที่ยังไม่ครบ</span>
          <strong class="stat-value">{{ summary.count || 0 }}</strong>
          <span class="stat-sub">ใน {{ summary.window_days || windowDays }} วันข้างหน้า</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded open">groups</span>
        <div>
          <span class="stat-label">มีลูกค้าจองแล้ว</span>
          <strong class="stat-value">{{ summary.with_bookings || 0 }}</strong>
          <span class="stat-sub">ต้องได้คำตอบ ไม่ปล่อยเงียบ</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded danger">alarm</span>
        <div>
          <span class="stat-label">เหลือไม่ถึง 7 วัน</span>
          <strong class="stat-value">{{ summary.critical || 0 }}</strong>
          <span class="stat-sub">ตัดสินใจวันนี้</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded money">currency_exchange</span>
        <div>
          <span class="stat-label">ยอดที่ต้องคืนถ้าไม่ออก</span>
          <strong class="stat-value">{{ formatCurrency(summary.revenue_at_risk || 0) }}</strong>
          <span class="stat-sub">เงินที่ลูกค้าจ่ายมาแล้ว</span>
        </div>
      </div>
    </div>

    <div v-if="loading && !rows.length" class="loading-state"><div class="spinner"></div></div>

    <template v-else>
      <div v-if="!rows.length" class="empty-card">
        <span class="material-symbols-rounded">verified</span>
        <p>ทุกรอบที่กำลังจะถึงมีคนครบขั้นต่ำแล้ว ไม่มีอะไรต้องเร่งจัดการ</p>
      </div>

      <div v-else class="risk-list">
        <article v-for="row in rows" :key="row.id" class="risk-card" :class="row.severity">
          <header class="risk-head">
            <div class="risk-title-wrap">
              <h3 class="risk-title">{{ row.trip_title }}</h3>
              <span class="risk-date">{{ row.departure_label }}</span>
            </div>
            <span class="days-chip" :class="row.severity">
              {{ row.days_left === 0 ? 'วันนี้' : `เหลือ ${row.days_left} วัน` }}
            </span>
          </header>

          <div class="seat-meter">
            <div class="seat-track">
              <div class="seat-fill" :style="{ width: seatPercent(row) + '%' }"></div>
            </div>
            <span class="seat-text">
              จองแล้ว <strong>{{ row.booked_seats }}/{{ row.min_seats }}</strong> ท่าน ·
              ขาดอีก <strong>{{ row.seats_needed }}</strong> ท่าน ·
              ที่นั่งว่าง {{ row.seats_available }}
            </span>
          </div>

          <div class="risk-facts">
            <span><span class="material-symbols-rounded">receipt_long</span>{{ row.bookings_count }} การจอง</span>
            <span><span class="material-symbols-rounded">payments</span>{{ formatCurrency(row.revenue_at_risk) }} รับมาแล้ว</span>
            <span><span class="material-symbols-rounded">sell</span>ราคาขายตอนนี้ {{ formatCurrency(row.current_price) }}</span>
          </div>

          <div v-if="badges(row).length" class="risk-badges">
            <span v-for="b in badges(row)" :key="b.text" class="badge" :class="b.tone">
              <span class="material-symbols-rounded">{{ b.icon }}</span>{{ b.text }}
            </span>
          </div>

          <!-- รอบอื่นที่รับคนจากรอบนี้ไหว — ยุบรวมแทนที่จะล่มทั้งคู่ -->
          <div v-if="row.merge_candidates.length" class="merge-block">
            <p class="merge-label">
              <span class="material-symbols-rounded">merge</span>
              ย้ายคนไปรวมกับรอบอื่นของทริปนี้ได้
            </p>
            <div class="merge-options">
              <button
                v-for="cand in row.merge_candidates"
                :key="cand.id"
                class="merge-option"
                :class="{ reaches: cand.reaches_minimum }"
                :disabled="busyId === row.id"
                @click="confirmMerge(row, cand)"
              >
                <strong>{{ cand.departure_label }}</strong>
                <span>{{ cand.booked_seats }} ท่านแล้ว · ว่าง {{ cand.seats_free }} ที่</span>
                <span v-if="cand.reaches_minimum" class="merge-win">รวมแล้วครบขั้นต่ำ</span>
              </button>
            </div>
          </div>

          <footer class="risk-actions">
            <button
              class="btn-secondary"
              :disabled="busyId === row.id || row.bookings_count === 0 || row.rally_cooldown_hours > 0"
              :title="rallyTitle(row)"
              @click="sendNudge(row)"
            >
              <span class="material-symbols-rounded">campaign</span>
              ชวนช่วยกันเปิดรอบ
            </button>
            <button class="btn-secondary" :disabled="busyId === row.id" @click="openFlash(row)">
              <span class="material-symbols-rounded">bolt</span>
              {{ row.flash_sale_active ? 'แก้ราคาลดโค้งท้าย' : 'ลดราคาโค้งท้าย' }}
            </button>
            <button
              class="btn-secondary"
              :disabled="busyId === row.id || row.bookings_count === 0 || !!row.flexi_offer"
              @click="openFlexi(row)"
            >
              <span class="material-symbols-rounded">interests</span>
              ยื่น Flexi-Price
            </button>
            <router-link class="btn-secondary" to="/admin/schedules">
              <span class="material-symbols-rounded">calendar_month</span>
              จัดการรอบเดินทาง
            </router-link>
          </footer>
        </article>
      </div>
    </template>

    <!-- ── Modal: ลดราคาโค้งท้าย ─────────────────────────── -->
    <div v-if="flashModal.open" class="modal-overlay" @click.self="flashModal.open = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">
              <span class="material-symbols-rounded" style="vertical-align:-4px">bolt</span>
              ลดราคาโค้งท้าย
            </h2>
            <p class="modal-subtitle">{{ flashModal.row?.trip_title }} · {{ flashModal.row?.departure_label }}</p>
          </div>
          <button class="modal-close" @click="flashModal.open = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label>ราคาลดพิเศษต่อท่าน (บาท)</label>
              <input v-model.number="flashForm.price" type="number" min="0" step="10" />
            </div>
            <div class="form-group">
              <label>สิ้นสุดเมื่อ</label>
              <input v-model="flashForm.ends_at" type="datetime-local" :min="minDateTime" />
            </div>
          </div>
          <p v-if="flashDiscount > 0" class="est-note">
            <span class="material-symbols-rounded">calculate</span>
            ถูกลง <strong>{{ formatCurrency(flashDiscount) }}</strong> ต่อท่าน
            จากราคาปกติ {{ formatCurrency(flashModal.row?.current_price || 0) }}
          </p>
          <p class="modal-hint">
            <span class="material-symbols-rounded">info</span>
            ราคานี้จะมีผลกับผู้ที่จองใหม่ทันที และระบบจะยิงแจ้งเตือนลูกค้าที่สนใจทริปนี้ให้เอง
            ผู้ที่จองไปแล้วในราคาเต็มจะไม่ถูกเรียกเก็บเพิ่มหรือคืนส่วนต่างอัตโนมัติ
          </p>
        </div>

        <div class="modal-footer">
          <button
            v-if="flashModal.row?.flash_sale_active"
            class="btn-danger"
            :disabled="submitting"
            @click="stopFlash"
          >หยุดลดราคา</button>
          <button class="btn-secondary" @click="flashModal.open = false">ยกเลิก</button>
          <button class="btn-primary" :disabled="!canSubmitFlash || submitting" @click="submitFlash">
            <span class="material-symbols-rounded">{{ submitting ? 'progress_activity' : 'check' }}</span>
            {{ submitting ? 'กำลังบันทึก...' : 'เริ่มลดราคา' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal: ยื่น Flexi-Price ────────────────────────── -->
    <div v-if="flexiModal.open" class="modal-overlay" @click.self="flexiModal.open = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">
              <span class="material-symbols-rounded" style="vertical-align:-4px">interests</span>
              ยื่นข้อเสนอ Flexi-Price
            </h2>
            <p class="modal-subtitle">{{ flexiModal.row?.trip_title }} · {{ flexiModal.row?.departure_label }}</p>
          </div>
          <button class="modal-close" @click="flexiModal.open = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label>ส่วนต่างค่ารถต่อท่าน (บาท)</label>
              <input v-model.number="flexiForm.surcharge_per_person" type="number" min="1" step="1" placeholder="เช่น 300" />
            </div>
            <div class="form-group">
              <label>ปิดรับการตอบภายใน</label>
              <input v-model="flexiForm.respond_by" type="datetime-local" :min="minDateTime" />
            </div>
            <div class="form-group full-width">
              <label>ข้อความถึงลูกค้า (ไม่บังคับ)</label>
              <textarea
                v-model="flexiForm.reason"
                rows="3"
                maxlength="500"
                placeholder="เช่น รอบนี้มีผู้ร่วมเดินทางไม่ครบ หากช่วยกันจ่ายส่วนต่างค่ารถ ทริปจะออกเดินทางได้ตามกำหนดเดิม"
              ></textarea>
            </div>
          </div>
          <p class="modal-hint">
            <span class="material-symbols-rounded">info</span>
            ระบบจะแจ้งเจ้าของทุกการจองที่ยืนยันแล้ว ทริปจะไปต่อได้เมื่อ <strong>ทุกคน</strong> กดยอมรับ
            ดูความคืบหน้าได้ที่หน้า Flexi-Price
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn-secondary" @click="flexiModal.open = false">ยกเลิก</button>
          <button class="btn-primary" :disabled="!canSubmitFlexi || submitting" @click="submitFlexi">
            <span class="material-symbols-rounded">{{ submitting ? 'progress_activity' : 'send' }}</span>
            {{ submitting ? 'กำลังส่ง...' : 'ส่งข้อเสนอ' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import './admin-shared.css';

const toast = useToast();

const windowOptions = [
  { value: 7, label: '7 วัน' },
  { value: 14, label: '14 วัน' },
  { value: 21, label: '21 วัน' },
  { value: 45, label: '45 วัน' },
];

const rows = ref([]);
const summary = ref({});
const loading = ref(false);
const submitting = ref(false);
const busyId = ref(null);
const windowDays = ref(21);

const flashModal = reactive({ open: false, row: null });
const flashForm = reactive({ price: null, ends_at: '' });
const flexiModal = reactive({ open: false, row: null });
const flexiForm = reactive({ surcharge_per_person: null, respond_by: '', reason: '' });

/** ค่าต่ำสุดของ input datetime-local — เวลาเครื่องผู้ใช้ ไม่ใช่ UTC */
const minDateTime = computed(() => toLocalInput(new Date(Date.now() + 5 * 60 * 1000)));

const flashDiscount = computed(() => {
  const base = flashModal.row?.current_price || 0;
  const price = flashForm.price || 0;
  return price > 0 && base > price ? base - price : 0;
});

const canSubmitFlash = computed(() => (flashForm.price ?? -1) >= 0 && !!flashForm.ends_at);
const canSubmitFlexi = computed(
  () => (flexiForm.surcharge_per_person || 0) > 0 && !!flexiForm.respond_by,
);

function toLocalInput(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function formatCurrency(value) {
  return `฿${Number(value || 0).toLocaleString('th-TH', { maximumFractionDigits: 0 })}`;
}

function seatPercent(row) {
  if (!row.min_seats) return 0;
  return Math.min(100, Math.round((row.booked_seats / row.min_seats) * 100));
}

function badges(row) {
  const out = [];
  if (row.flash_sale_active) out.push({ icon: 'bolt', text: 'กำลังลดราคาอยู่', tone: 'ok' });
  if (row.flexi_offer) out.push({ icon: 'interests', text: 'มีข้อเสนอ Flexi-Price รออยู่', tone: 'info' });
  if (row.rally_nudged_at) {
    out.push({
      icon: 'campaign',
      text: `ชวนช่วยกันเปิดรอบแล้ว ${relativeTime(row.rally_nudged_at)}`,
      tone: 'muted',
    });
  }
  if (row.bookings_count === 0) out.push({ icon: 'person_off', text: 'ยังไม่มีใครจอง', tone: 'muted' });
  return out;
}

function relativeTime(at) {
  const hours = Math.floor((Date.now() - new Date(at).getTime()) / 3600000);
  if (hours < 1) return 'เมื่อครู่';
  if (hours < 24) return `${hours} ชม.ที่แล้ว`;
  return `${Math.floor(hours / 24)} วันที่แล้ว`;
}

function rallyTitle(row) {
  if (row.bookings_count === 0) return 'รอบนี้ยังไม่มีใครจอง จึงยังไม่มีใครให้ชวน';
  if (row.rally_cooldown_hours > 0) return `เพิ่งชวนไป รออีก ${row.rally_cooldown_hours} ชม.`;
  return 'แจ้งเตือนผู้ที่จองรอบนี้แล้วให้ช่วยชวนเพื่อนมาเติมที่นั่ง';
}

function setWindow(value) {
  windowDays.value = value;
  load();
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/admin/schedules/at-risk', { params: { days: windowDays.value } });
    rows.value = res.data.data.schedules || [];
    summary.value = res.data.data.summary || {};
  } catch {
    toast.error('โหลดรอบเสี่ยงไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

async function sendNudge(row) {
  busyId.value = row.id;
  try {
    const res = await api.post(`/admin/schedules/${row.id}/rally-nudge`);
    toast.success(res.data.message || 'ส่งคำชวนแล้ว');
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ส่งคำชวนไม่สำเร็จ');
  } finally {
    busyId.value = null;
  }
}

function openFlash(row) {
  flashModal.row = row;
  flashModal.open = true;
  flashForm.price = row.flash_sale_active ? null : Math.max(0, Math.round((row.current_price * 0.9) / 10) * 10);
  // ปิดการลดราคาก่อนวันเดินทาง 1 วัน — เลยจากนั้นขายเพิ่มไม่ทันแล้ว
  const ends = new Date();
  ends.setDate(ends.getDate() + Math.max(1, row.days_left - 1));
  ends.setHours(23, 59, 0, 0);
  flashForm.ends_at = toLocalInput(ends);
}

async function submitFlash() {
  submitting.value = true;
  try {
    await api.put(`/admin/schedules/${flashModal.row.id}`, {
      flash_sale_enabled: true,
      flash_sale_price: flashForm.price,
      flash_sale_starts_at: new Date().toISOString(),
      flash_sale_ends_at: new Date(flashForm.ends_at).toISOString(),
    });
    toast.success('เริ่มลดราคาโค้งท้ายแล้ว');
    flashModal.open = false;
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ตั้งราคาลดไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

async function stopFlash() {
  submitting.value = true;
  try {
    await api.put(`/admin/schedules/${flashModal.row.id}`, { flash_sale_enabled: false });
    toast.success('หยุดลดราคาแล้ว');
    flashModal.open = false;
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'หยุดลดราคาไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

function openFlexi(row) {
  flexiModal.row = row;
  flexiModal.open = true;
  flexiForm.surcharge_per_person = null;
  flexiForm.reason = '';
  // ให้ลูกค้าตอบภายใน 2 วัน แต่ไม่เลยวันเดินทาง
  const by = new Date();
  by.setDate(by.getDate() + Math.min(2, Math.max(1, row.days_left)));
  by.setHours(20, 0, 0, 0);
  flexiForm.respond_by = toLocalInput(by);
}

async function submitFlexi() {
  submitting.value = true;
  try {
    await api.post(`/admin/schedules/${flexiModal.row.id}/flexi-offer`, {
      surcharge_per_person: flexiForm.surcharge_per_person,
      respond_by: new Date(flexiForm.respond_by).toISOString(),
      reason: flexiForm.reason || null,
    });
    toast.success('ส่งข้อเสนอ Flexi-Price แล้ว');
    flexiModal.open = false;
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ส่งข้อเสนอไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

async function confirmMerge(row, candidate) {
  const ok = window.confirm(
    `ย้ายผู้เดินทางทั้ง ${row.booked_seats} ท่านจากรอบ ${row.departure_label} `
    + `ไปรอบ ${candidate.departure_label} ใช่ไหม?\n\n`
    + 'ระบบจะย้ายการจอง จุดรับ และที่นั่งไปให้ทั้งหมด อย่าลืมแจ้งลูกค้าด้วยนะครับ',
  );
  if (!ok) return;

  busyId.value = row.id;
  try {
    await api.post('/admin/schedules/move-bookings', {
      source_schedule_id: row.id,
      target_schedule_id: candidate.id,
    });
    toast.success('ย้ายผู้เดินทางไปรอบใหม่แล้ว');
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ย้ายรอบไม่สำเร็จ');
  } finally {
    busyId.value = null;
  }
}

onMounted(load);
</script>

<style scoped>
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.segmented { display: inline-flex; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
.segment {
  padding: 7px 13px; font-size: 12.5px; font-weight: 600; color: #6b7280;
  background: #fff; border: 0; border-right: 1px solid #e5e7eb; cursor: pointer;
}
.segment:last-child { border-right: 0; }
.segment.active { background: #111827; color: #fff; }

.stat-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 14px; margin-bottom: 22px;
}
.stat-card {
  display: flex; align-items: flex-start; gap: 13px;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 15px 17px;
}
.stat-icon { font-size: 26px !important; color: #9ca3af; }
.stat-icon.warn { color: #d97706; }
.stat-icon.open { color: #2563eb; }
.stat-icon.danger { color: #dc2626; }
.stat-icon.money { color: #059669; }
.stat-label { display: block; font-size: 12px; color: #6b7280; }
.stat-value { display: block; font-size: 21px; font-weight: 800; color: #111827; line-height: 1.3; }
.stat-sub { display: block; font-size: 11.5px; color: #9ca3af; }

.empty-card {
  display: flex; align-items: center; gap: 14px;
  background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 20px 22px;
}
.empty-card .material-symbols-rounded { font-size: 30px !important; color: #16a34a; }
.empty-card p { margin: 0; font-size: 13.5px; color: #15803d; }

.risk-list { display: flex; flex-direction: column; gap: 14px; }

.risk-card {
  background: #fff; border: 1px solid #e5e7eb; border-left: 3px solid #9ca3af;
  border-radius: 12px; padding: 17px 19px;
}
.risk-card.critical { border-left-color: #dc2626; }
.risk-card.high { border-left-color: #ea580c; }
.risk-card.medium { border-left-color: #d97706; }
.risk-card.low { border-left-color: #cbd5e1; }

.risk-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.risk-title { margin: 0; font-size: 15.5px; font-weight: 700; color: #111827; }
.risk-date { font-size: 12.5px; color: #6b7280; }

.days-chip {
  flex-shrink: 0; padding: 4px 11px; border-radius: 999px;
  font-size: 12px; font-weight: 700; background: #f3f4f6; color: #4b5563;
}
.days-chip.critical { background: #fef2f2; color: #b91c1c; }
.days-chip.high { background: #fff7ed; color: #c2410c; }
.days-chip.medium { background: #fffbeb; color: #b45309; }

.seat-meter { margin-top: 14px; }
.seat-track { height: 6px; background: #f3f4f6; border-radius: 999px; overflow: hidden; }
.seat-fill { height: 100%; background: #f59e0b; }
.seat-text { display: block; margin-top: 7px; font-size: 12.5px; color: #6b7280; }
.seat-text strong { color: #111827; }

.risk-facts { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 12px; }
.risk-facts span { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; color: #6b7280; }
.risk-facts .material-symbols-rounded { font-size: 17px !important; color: #9ca3af; }

.risk-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600;
  background: #f3f4f6; color: #4b5563;
}
.badge .material-symbols-rounded { font-size: 15px !important; }
.badge.ok { background: #ecfdf5; color: #047857; }
.badge.info { background: #eff6ff; color: #1d4ed8; }
.badge.muted { background: #f8fafc; color: #94a3b8; }

.merge-block { margin-top: 14px; padding-top: 13px; border-top: 1px dashed #e5e7eb; }
.merge-label {
  display: flex; align-items: center; gap: 6px;
  margin: 0 0 9px; font-size: 12.5px; font-weight: 600; color: #4b5563;
}
.merge-label .material-symbols-rounded { font-size: 18px !important; color: #9ca3af; }
.merge-options { display: flex; flex-wrap: wrap; gap: 9px; }
.merge-option {
  display: flex; flex-direction: column; gap: 2px; text-align: left;
  padding: 9px 13px; border: 1px solid #e5e7eb; border-radius: 9px;
  background: #fff; cursor: pointer;
}
.merge-option:hover:not(:disabled) { border-color: #9ca3af; }
.merge-option:disabled { opacity: 0.5; cursor: not-allowed; }
.merge-option strong { font-size: 12.5px; color: #111827; }
.merge-option span { font-size: 11.5px; color: #9ca3af; }
.merge-option.reaches { border-color: #86efac; background: #f0fdf4; }
.merge-win { font-size: 11px !important; font-weight: 700; color: #16a34a !important; }

.risk-actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 15px; }
.risk-actions .btn-secondary { font-size: 12.5px; text-decoration: none; }

.est-note {
  display: flex; align-items: center; gap: 7px; margin: 14px 0 0;
  padding: 10px 13px; background: #f9fafb; border-radius: 8px;
  font-size: 12.5px; color: #4b5563;
}
.est-note .material-symbols-rounded { font-size: 18px !important; color: #9ca3af; }

.modal-title { display: flex; align-items: center; gap: 7px; margin: 0; }
.modal-subtitle { margin: 4px 0 0; font-size: 13px; color: #6b7280; }
.modal-hint {
  display: flex; gap: 8px; margin: 14px 0 0;
  font-size: 12.5px; color: #6b7280; line-height: 1.6;
}
.modal-hint .material-symbols-rounded { font-size: 17px !important; color: #9ca3af; flex-shrink: 0; }

.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }
.form-group.full-width { grid-column: 1 / -1; }

@media (max-width: 640px) {
  .risk-actions .btn-secondary { flex: 1 1 100%; justify-content: center; }
}
</style>
