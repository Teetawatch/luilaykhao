<template>
  <div class="admin-page flexi-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">interests</span>
          Flexi-Price · ไปต่อกันไหม?
        </h1>
        <p class="page-subtitle">
          เมื่อรอบไหนคนไม่ครบใกล้ถึงกำหนด ยื่นข้อเสนอให้ผู้จองช่วยกันจ่ายส่วนต่างค่ารถ
          ถ้าทุกคนตอบรับ ทริปจะออกเดินทางตามกำหนดเดิม (เก็บส่วนต่างในวันเดินทาง)
        </p>
      </div>
      <div class="header-actions">
        <span v-if="lastUpdated" class="last-updated">อัปเดตล่าสุด {{ lastUpdated }}</span>
        <button class="btn-secondary" :disabled="loading" @click="fetchData">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span>
          {{ loading ? 'กำลังโหลด' : 'รีเฟรช' }}
        </button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded open">hourglass_top</span>
        <div>
          <span class="stat-label">ข้อเสนอที่เปิดอยู่</span>
          <strong class="stat-value">{{ stats.open }}</strong>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded ok">celebration</span>
        <div>
          <span class="stat-label">ยืนยันไปต่อแล้ว</span>
          <strong class="stat-value">{{ stats.confirmed }}</strong>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded warn">groups</span>
        <div>
          <span class="stat-label">รอบที่คนไม่ครบ</span>
          <strong class="stat-value">{{ candidates.length }}</strong>
          <span class="stat-sub">ยื่นข้อเสนอได้</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon material-symbols-rounded money">payments</span>
        <div>
          <span class="stat-label">ส่วนต่างที่ตกลงแล้ว</span>
          <strong class="stat-value">{{ formatCurrency(stats.pool) }}</strong>
          <span class="stat-sub">จากข้อเสนอที่ยืนยัน</span>
        </div>
      </div>
    </div>

    <div v-if="loading && !offers.length && !candidates.length" class="loading-state">
      <div class="spinner"></div>
    </div>

    <template v-else>
      <div v-if="error" class="alert-card">
        <span class="material-symbols-rounded">error</span>
        <span>{{ error }}</span>
      </div>

      <!-- ── รอบที่คนไม่ครบ ─────────────────────────────── -->
      <section class="flexi-section">
        <div class="section-head">
          <h2 class="section-title">
            <span class="material-symbols-rounded">group_off</span>
            รอบที่คนไม่ครบ — ยื่นข้อเสนอได้
          </h2>
          <span class="section-count">{{ candidates.length }} รอบ</span>
        </div>

        <div v-if="!candidates.length" class="empty-card">
          <span class="material-symbols-rounded">verified</span>
          <p>ยังไม่มีรอบที่ต้องยื่นข้อเสนอ — ทุกรอบที่กำลังจะถึงมีคนครบหรือถูกจัดการแล้ว</p>
        </div>

        <div v-else class="candidate-grid">
          <article v-for="c in candidates" :key="c.schedule_id" class="candidate-card">
            <div class="cand-head">
              <h3 class="cand-title">{{ c.trip_title }}</h3>
              <span class="cand-date">{{ c.departure_label }}</span>
            </div>
            <div class="cand-meter">
              <div class="cand-meter-track">
                <div class="cand-meter-fill" :style="{ width: seatPercent(c) + '%' }"></div>
              </div>
              <span class="cand-meter-text">
                จองแล้ว {{ c.booked_seats }}/{{ c.total_seats }} ที่ · ขาดอีก {{ c.seats_short }} ที่จะการันตี
              </span>
            </div>
            <div class="cand-facts">
              <span><span class="material-symbols-rounded">receipt_long</span>{{ c.confirmed_bookings }} การจองยืนยันแล้ว</span>
            </div>
            <button class="btn-primary full" @click="openCreate(c)">
              <span class="material-symbols-rounded">campaign</span>
              ยื่นข้อเสนอไปต่อ
            </button>
          </article>
        </div>
      </section>

      <!-- ── ข้อเสนอทั้งหมด ─────────────────────────────── -->
      <section class="flexi-section">
        <div class="section-head">
          <h2 class="section-title">
            <span class="material-symbols-rounded">history</span>
            ข้อเสนอทั้งหมด
          </h2>
          <div class="segmented">
            <button
              v-for="opt in statusFilters"
              :key="opt.value"
              class="segment"
              :class="{ active: statusFilter === opt.value }"
              type="button"
              @click="statusFilter = opt.value"
            >
              {{ opt.label }}
              <span class="seg-count">{{ opt.count }}</span>
            </button>
          </div>
        </div>

        <div v-if="!filteredOffers.length" class="empty-card">
          <span class="material-symbols-rounded">inbox</span>
          <p>{{ offers.length ? 'ไม่มีข้อเสนอในสถานะนี้' : 'ยังไม่เคยยื่นข้อเสนอ Flexi-Price' }}</p>
        </div>

        <div v-else class="offer-list">
          <article v-for="offer in filteredOffers" :key="offer.id" class="offer-card">
            <div class="offer-head">
              <div class="offer-head-info">
                <span class="flexi-badge" :class="`flexi-${offer.status}`">
                  <span class="material-symbols-rounded">{{ statusMeta[offer.status]?.icon || 'help' }}</span>
                  {{ statusMeta[offer.status]?.label || offer.status }}
                </span>
                <h3 class="offer-title">{{ offer.schedule?.trip_title || 'ทริป' }}</h3>
                <span class="offer-date">
                  <span class="material-symbols-rounded">event</span>
                  {{ offer.schedule?.departure_label || '-' }}
                </span>
              </div>
              <div class="offer-head-figures">
                <div class="fig">
                  <span>ส่วนต่าง/ท่าน</span>
                  <strong>{{ formatCurrency(offer.surcharge_per_person) }}</strong>
                </div>
                <div class="fig">
                  <span>รวมทั้งรอบ</span>
                  <strong class="money">{{ formatCurrency(offer.surcharge_pool) }}</strong>
                </div>
              </div>
            </div>

            <div class="offer-progress">
              <div class="prog-track">
                <div class="prog-seg accepted" :style="{ width: pct(offer.progress.accepted, offer.progress.total) + '%' }"></div>
                <div class="prog-seg declined" :style="{ width: pct(offer.progress.declined, offer.progress.total) + '%' }"></div>
              </div>
              <div class="prog-legend">
                <span class="lg accepted">ยอมรับ {{ offer.progress.accepted }}</span>
                <span class="lg pending">รอตอบ {{ offer.progress.pending }}</span>
                <span class="lg declined">ปฏิเสธ {{ offer.progress.declined }}</span>
                <span class="lg total">/ {{ offer.progress.total }} การจอง</span>
              </div>
            </div>

            <div class="offer-meta-row">
              <span v-if="offer.is_open" class="offer-deadline live">
                <span class="material-symbols-rounded">timer</span>
                ปิดรับตอบใน {{ countdown(offer.respond_by) }}
              </span>
              <span v-else class="offer-deadline">
                <span class="material-symbols-rounded">timer_off</span>
                กำหนดตอบ {{ formatDateTime(offer.respond_by) }}
              </span>
              <span v-if="offer.creator_name" class="offer-by">โดย {{ offer.creator_name }}</span>
            </div>

            <p v-if="offer.reason" class="offer-reason">“{{ offer.reason }}”</p>

            <div class="offer-actions">
              <button class="btn-secondary compact" @click="toggleConsents(offer.id)">
                <span class="material-symbols-rounded">{{ expanded.has(offer.id) ? 'expand_less' : 'expand_more' }}</span>
                {{ expanded.has(offer.id) ? 'ซ่อนรายชื่อ' : 'ดูรายชื่อผู้ตอบรับ' }}
              </button>
              <button
                v-if="offer.status === 'pending'"
                class="btn-danger compact"
                :disabled="cancellingId === offer.id"
                @click="askCancel(offer)"
              >
                <span class="material-symbols-rounded">cancel</span>
                ยกเลิกข้อเสนอ
              </button>
            </div>

            <div v-if="expanded.has(offer.id)" class="consent-table-wrap">
              <table class="consent-table">
                <thead>
                  <tr>
                    <th>ผู้จอง</th>
                    <th>เลขจอง</th>
                    <th class="num">ผู้เดินทาง</th>
                    <th class="num">ส่วนต่าง</th>
                    <th>สถานะ</th>
                    <th>ตอบเมื่อ</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="c in offer.consents" :key="c.id">
                    <td>{{ c.customer_name }}</td>
                    <td><span class="ref-chip">{{ c.booking_ref }}</span></td>
                    <td class="num">{{ c.pax }}</td>
                    <td class="num">{{ formatCurrency(c.surcharge_total) }}</td>
                    <td>
                      <span class="consent-badge" :class="`consent-${c.status}`">
                        {{ consentLabels[c.status] || c.status }}
                      </span>
                    </td>
                    <td>{{ c.responded_at ? formatDateTime(c.responded_at) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </section>
    </template>

    <!-- ── Modal: สร้างข้อเสนอ ─────────────────────────── -->
    <div class="modal-overlay" v-if="createModal.open" @click.self="closeCreate">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">
              <span class="material-symbols-rounded" style="vertical-align:-4px">campaign</span>
              ยื่นข้อเสนอ Flexi-Price
            </h2>
            <p class="modal-subtitle">
              {{ createModal.candidate?.trip_title }} · {{ createModal.candidate?.departure_label }}
            </p>
          </div>
          <button class="modal-close" @click="closeCreate">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <div class="cand-recap">
            <div>
              <span>จองแล้ว</span>
              <strong>{{ createModal.candidate?.booked_seats }}/{{ createModal.candidate?.total_seats }} ที่</strong>
            </div>
            <div>
              <span>การจองยืนยัน</span>
              <strong>{{ createModal.candidate?.confirmed_bookings }} รายการ</strong>
            </div>
            <div>
              <span>ขาดอีก</span>
              <strong>{{ createModal.candidate?.seats_short }} ที่</strong>
            </div>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label>ส่วนต่างค่ารถต่อท่าน (บาท)</label>
              <input v-model.number="form.surcharge_per_person" type="number" min="1" step="1" placeholder="เช่น 300" />
            </div>
            <div class="form-group">
              <label>ปิดรับการตอบภายใน</label>
              <input v-model="form.respond_by" type="datetime-local" :min="minRespondBy" />
            </div>
            <div class="form-group full-width">
              <label>เหตุผล / ข้อความถึงลูกค้า (ไม่บังคับ)</label>
              <textarea
                v-model="form.reason"
                rows="3"
                maxlength="500"
                placeholder="เช่น รอบนี้มีผู้ร่วมเดินทางไม่ครบ หากช่วยกันจ่ายส่วนต่างค่ารถ ทริปจะออกเดินทางได้ตามกำหนดเดิม"
              ></textarea>
            </div>
          </div>

          <div v-if="estimatedTotal > 0" class="est-note">
            <span class="material-symbols-rounded">calculate</span>
            ประเมินส่วนต่างรวมทั้งรอบราว
            <strong>{{ formatCurrency(estimatedTotal) }}</strong>
            (ท่านละ {{ formatCurrency(form.surcharge_per_person) }})
          </div>

          <p class="modal-hint">
            <span class="material-symbols-rounded">info</span>
            ระบบจะส่งการแจ้งเตือนหาเจ้าของทุกการจองที่ยืนยันแล้ว ทริปจะไปต่อได้เมื่อ
            <strong>ทุกคน</strong> กดยอมรับ ถ้ามีคนปฏิเสธหรือหมดเวลา ข้อเสนอจะปิดลง
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn-secondary" @click="closeCreate">ยกเลิก</button>
          <button class="btn-primary" :disabled="!canSubmit || submitting" @click="submitOffer">
            <span class="material-symbols-rounded">{{ submitting ? 'progress_activity' : 'send' }}</span>
            {{ submitting ? 'กำลังส่ง...' : 'ส่งข้อเสนอ' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal: ยืนยันยกเลิก ─────────────────────────── -->
    <div class="modal-overlay" v-if="cancelModal.open" @click.self="cancelModal.open = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2 class="modal-title">ยกเลิกข้อเสนอ Flexi-Price</h2>
          <button class="modal-close" @click="cancelModal.open = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">
            ยืนยันยกเลิกข้อเสนอของรอบ
            <strong>{{ cancelModal.offer?.schedule?.trip_title }}</strong>
            ({{ cancelModal.offer?.schedule?.departure_label }})?
          </p>
          <p class="confirm-warning">
            <span class="material-symbols-rounded">warning</span>
            ระบบจะแจ้งลูกค้าทุกคนว่าข้อเสนอถูกยกเลิก การตอบรับที่มีอยู่จะเป็นโมฆะ
          </p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="cancelModal.open = false">ไม่ยกเลิก</button>
          <button class="btn-danger" :disabled="cancellingId" @click="confirmCancel">
            <span class="material-symbols-rounded">cancel</span>
            ยืนยันยกเลิกข้อเสนอ
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';

const toast = useToast();

const loading = ref(false);
const submitting = ref(false);
const cancellingId = ref(null);
const error = ref('');
const lastUpdated = ref('');

const offers = ref([]);
const candidates = ref([]);
const expanded = ref(new Set());
const statusFilter = ref('all');

const createModal = reactive({ open: false, candidate: null });
const cancelModal = reactive({ open: false, offer: null });
const form = reactive({ surcharge_per_person: null, respond_by: '', reason: '' });

const statusMeta = {
  pending: { label: 'เปิดรับตอบ', icon: 'hourglass_top' },
  confirmed: { label: 'ยืนยันไปต่อ', icon: 'celebration' },
  declined: { label: 'มีผู้ปฏิเสธ', icon: 'do_not_disturb_on' },
  expired: { label: 'หมดเวลา', icon: 'timer_off' },
  cancelled: { label: 'ยกเลิกแล้ว', icon: 'cancel' },
};

const consentLabels = { pending: 'รอตอบ', accepted: 'ยอมรับ', declined: 'ปฏิเสธ' };

const stats = computed(() => ({
  open: offers.value.filter((o) => o.status === 'pending').length,
  confirmed: offers.value.filter((o) => o.status === 'confirmed').length,
  pool: offers.value
    .filter((o) => o.status === 'confirmed')
    .reduce((sum, o) => sum + (o.surcharge_pool || 0), 0),
}));

const statusFilters = computed(() => {
  const count = (s) => offers.value.filter((o) => o.status === s).length;
  return [
    { value: 'all', label: 'ทั้งหมด', count: offers.value.length },
    { value: 'pending', label: 'เปิดอยู่', count: count('pending') },
    { value: 'confirmed', label: 'ไปต่อ', count: count('confirmed') },
    { value: 'closed', label: 'ปิดแล้ว', count: offers.value.filter((o) => ['declined', 'expired', 'cancelled'].includes(o.status)).length },
  ];
});

const filteredOffers = computed(() => {
  if (statusFilter.value === 'all') return offers.value;
  if (statusFilter.value === 'closed') {
    return offers.value.filter((o) => ['declined', 'expired', 'cancelled'].includes(o.status));
  }
  return offers.value.filter((o) => o.status === statusFilter.value);
});

const minRespondBy = computed(() => {
  const d = new Date(Date.now() + 5 * 60 * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
});

const estimatedTotal = computed(() => {
  const per = Number(form.surcharge_per_person) || 0;
  const pax = createModal.candidate?.booked_seats || 0;
  return per * pax;
});

const canSubmit = computed(
  () => Number(form.surcharge_per_person) > 0 && !!form.respond_by && !!createModal.candidate,
);

async function fetchData() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/admin/flexi-offers');
    offers.value = res.data.data.offers || [];
    candidates.value = res.data.data.candidates || [];
    lastUpdated.value = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
  } catch (e) {
    error.value = e.response?.data?.message || 'โหลดข้อมูล Flexi-Price ไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function openCreate(candidate) {
  createModal.candidate = candidate;
  form.surcharge_per_person = null;
  form.reason = '';
  // default deadline: 2 days from now at 20:00
  const d = new Date(Date.now() + 2 * 86400000);
  d.setHours(20, 0, 0, 0);
  const pad = (n) => String(n).padStart(2, '0');
  form.respond_by = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  createModal.open = true;
}

function closeCreate() {
  createModal.open = false;
  createModal.candidate = null;
}

async function submitOffer() {
  if (!canSubmit.value) return;
  submitting.value = true;
  try {
    const res = await api.post(`/admin/schedules/${createModal.candidate.schedule_id}/flexi-offer`, {
      surcharge_per_person: Number(form.surcharge_per_person),
      respond_by: new Date(form.respond_by).toISOString(),
      reason: form.reason || null,
    });
    offers.value.unshift(res.data.data);
    candidates.value = candidates.value.filter((c) => c.schedule_id !== createModal.candidate.schedule_id);
    toast.success(res.data.message || 'ส่งข้อเสนอ Flexi-Price แล้ว');
    closeCreate();
  } catch (e) {
    toast.error(e.response?.data?.message || 'ส่งข้อเสนอไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

function askCancel(offer) {
  cancelModal.offer = offer;
  cancelModal.open = true;
}

async function confirmCancel() {
  const offer = cancelModal.offer;
  if (!offer) return;
  cancellingId.value = offer.id;
  try {
    const res = await api.post(`/admin/flexi-offers/${offer.id}/cancel`);
    const idx = offers.value.findIndex((o) => o.id === offer.id);
    if (idx !== -1) offers.value[idx] = res.data.data;
    toast.success(res.data.message || 'ยกเลิกข้อเสนอแล้ว');
    cancelModal.open = false;
  } catch (e) {
    toast.error(e.response?.data?.message || 'ยกเลิกไม่สำเร็จ');
  } finally {
    cancellingId.value = null;
  }
}

function toggleConsents(id) {
  const next = new Set(expanded.value);
  next.has(id) ? next.delete(id) : next.add(id);
  expanded.value = next;
}

function seatPercent(c) {
  if (!c.total_seats) return 0;
  return Math.min(100, Math.round((c.booked_seats / c.total_seats) * 100));
}

function pct(part, total) {
  if (!total) return 0;
  return (part / total) * 100;
}

function formatCurrency(value) {
  const n = Number(value) || 0;
  return `฿${n.toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
}

function formatDateTime(value) {
  if (!value) return '-';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '-';
  return d.toLocaleString('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function countdown(value) {
  if (!value) return '-';
  const diff = new Date(value).getTime() - Date.now();
  if (diff <= 0) return 'หมดเวลาแล้ว';
  const days = Math.floor(diff / 86400000);
  const hours = Math.floor((diff % 86400000) / 3600000);
  const mins = Math.floor((diff % 3600000) / 60000);
  if (days > 0) return `${days} วัน ${hours} ชม.`;
  if (hours > 0) return `${hours} ชม. ${mins} นาที`;
  return `${mins} นาที`;
}

onMounted(fetchData);
</script>

<style scoped src="./admin-shared.css"></style>
<style scoped>
.flexi-page { display: flex; flex-direction: column; gap: 24px; }

.header-actions { display: flex; align-items: center; gap: 12px; }
.last-updated { font-size: 12px; color: #9ca3af; }

/* ── Stat cards ── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
.stat-card {
  display: flex; align-items: center; gap: 14px;
  background: #fff; border: 1px solid #eceef1; border-radius: 14px; padding: 16px 18px;
}
.stat-icon { font-size: 30px; width: 48px; height: 48px; display: grid; place-items: center; border-radius: 12px; }
.stat-icon.open { background: #fef9c3; color: #a16207; }
.stat-icon.ok { background: #dcfce7; color: #15803d; }
.stat-icon.warn { background: #ffedd5; color: #c2410c; }
.stat-icon.money { background: #e0f2fe; color: #0369a1; }
.stat-label { display: block; font-size: 13px; color: #6b7280; }
.stat-value { display: block; font-size: 24px; font-weight: 800; color: #111827; line-height: 1.2; }
.stat-sub { display: block; font-size: 11px; color: #9ca3af; }

/* ── Section ── */
.flexi-section { display: flex; flex-direction: column; gap: 14px; }
.section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.section-title { display: flex; align-items: center; gap: 8px; font-size: 17px; font-weight: 800; color: #111827; margin: 0; }
.section-title .material-symbols-rounded { font-size: 22px; color: var(--color-accent); }
.section-count { font-size: 13px; color: #6b7280; font-weight: 600; }

.empty-card {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  background: #fff; border: 1px dashed #d8dbe0; border-radius: 14px; padding: 32px; text-align: center;
}
.empty-card .material-symbols-rounded { font-size: 34px; color: #cbd0d6; }
.empty-card p { margin: 0; font-size: 14px; color: #6b7280; max-width: 460px; }

/* ── Candidates ── */
.candidate-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.candidate-card {
  display: flex; flex-direction: column; gap: 12px;
  background: #fff; border: 1px solid #eceef1; border-radius: 14px; padding: 16px;
}
.cand-head { display: flex; flex-direction: column; gap: 2px; }
.cand-title { font-size: 15px; font-weight: 800; color: #111827; margin: 0; }
.cand-date { font-size: 13px; color: #6b7280; }
.cand-meter-track { height: 8px; background: #f1f3f5; border-radius: 999px; overflow: hidden; }
.cand-meter-fill { height: 100%; background: #f59e0b; border-radius: 999px; }
.cand-meter-text { display: block; margin-top: 6px; font-size: 12px; color: #6b7280; }
.cand-facts { display: flex; flex-wrap: wrap; gap: 12px; }
.cand-facts span { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: #374151; }
.cand-facts .material-symbols-rounded { font-size: 17px; color: #9ca3af; }
.btn-primary.full { justify-content: center; width: 100%; }

/* ── Offer cards ── */
.offer-list { display: flex; flex-direction: column; gap: 14px; }
.offer-card {
  display: flex; flex-direction: column; gap: 14px;
  background: #fff; border: 1px solid #eceef1; border-radius: 14px; padding: 18px;
}
.offer-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.offer-head-info { display: flex; flex-direction: column; gap: 6px; }
.offer-title { font-size: 16px; font-weight: 800; color: #111827; margin: 0; }
.offer-date { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: #6b7280; }
.offer-date .material-symbols-rounded { font-size: 17px; }
.offer-head-figures { display: flex; gap: 22px; }
.fig { display: flex; flex-direction: column; text-align: right; }
.fig span { font-size: 12px; color: #9ca3af; }
.fig strong { font-size: 16px; font-weight: 800; color: #111827; }
.fig strong.money { color: #0369a1; }

.flexi-badge {
  display: inline-flex; align-items: center; gap: 5px; align-self: flex-start;
  font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px;
}
.flexi-badge .material-symbols-rounded { font-size: 15px; }
.flexi-pending { background: #fef9c3; color: #a16207; }
.flexi-confirmed { background: #dcfce7; color: #15803d; }
.flexi-declined { background: #fee2e2; color: #b91c1c; }
.flexi-expired { background: #eceef1; color: #6b7280; }
.flexi-cancelled { background: #f3e8ff; color: #7e22ce; }

.offer-progress { display: flex; flex-direction: column; gap: 8px; }
.prog-track { display: flex; height: 10px; background: #f1f3f5; border-radius: 999px; overflow: hidden; }
.prog-seg { height: 100%; }
.prog-seg.accepted { background: #22c55e; }
.prog-seg.declined { background: #ef4444; }
.prog-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; }
.lg { display: inline-flex; align-items: center; gap: 5px; color: #6b7280; }
.lg::before { content: ''; width: 8px; height: 8px; border-radius: 50%; }
.lg.accepted::before { background: #22c55e; }
.lg.pending::before { background: #d1d5db; }
.lg.declined::before { background: #ef4444; }
.lg.total { color: #9ca3af; }
.lg.total::before { display: none; }

.offer-meta-row { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; }
.offer-deadline { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: #6b7280; }
.offer-deadline .material-symbols-rounded { font-size: 17px; }
.offer-deadline.live { color: #c2410c; font-weight: 700; }
.offer-by { font-size: 12px; color: #9ca3af; }
.offer-reason { margin: 0; font-size: 13px; color: #4b5563; font-style: italic; padding: 10px 14px; background: #f9fafb; border-radius: 10px; }

.offer-actions { display: flex; gap: 10px; flex-wrap: wrap; }

.consent-table-wrap { overflow-x: auto; border: 1px solid #eceef1; border-radius: 10px; }
.consent-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.consent-table th { text-align: left; padding: 9px 12px; background: #f9fafb; color: #6b7280; font-weight: 700; white-space: nowrap; }
.consent-table td { padding: 9px 12px; border-top: 1px solid #f1f3f5; color: #374151; }
.consent-table .num { text-align: right; }
.ref-chip { font-family: monospace; font-size: 12px; background: #f1f3f5; color: #374151; padding: 2px 7px; border-radius: 6px; }
.consent-badge { font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.consent-pending { background: #f1f3f5; color: #6b7280; }
.consent-accepted { background: #dcfce7; color: #15803d; }
.consent-declined { background: #fee2e2; color: #b91c1c; }

/* ── Segmented filter ── */
.segmented { display: inline-flex; gap: 4px; background: #f1f3f5; padding: 4px; border-radius: 10px; }
.segment {
  display: inline-flex; align-items: center; gap: 6px; border: none; background: transparent;
  padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #6b7280; cursor: pointer;
  font-family: inherit;
}
.segment.active { background: #fff; color: #111827; }
.seg-count { font-size: 11px; background: #e5e7eb; color: #6b7280; padding: 0 6px; border-radius: 999px; min-width: 18px; text-align: center; }
.segment.active .seg-count { background: var(--color-accent); color: #fff; }

/* ── Modal extras ── */
.modal-subtitle { margin: 4px 0 0; font-size: 13px; color: #6b7280; }
.cand-recap { display: flex; gap: 10px; margin-bottom: 16px; }
.cand-recap > div { flex: 1; background: #f9fafb; border-radius: 10px; padding: 10px 12px; text-align: center; }
.cand-recap span { display: block; font-size: 12px; color: #9ca3af; }
.cand-recap strong { display: block; font-size: 15px; font-weight: 800; color: #111827; margin-top: 2px; }
.est-note {
  display: flex; align-items: center; gap: 8px; margin-top: 14px;
  font-size: 13px; color: #0369a1; background: #e0f2fe; border-radius: 10px; padding: 10px 14px;
}
.est-note .material-symbols-rounded { font-size: 18px; }
.modal-hint {
  display: flex; align-items: flex-start; gap: 8px; margin: 14px 0 0;
  font-size: 12.5px; color: #6b7280; line-height: 1.5;
}
.modal-hint .material-symbols-rounded { font-size: 17px; color: #9ca3af; flex-shrink: 0; }

.btn-secondary.compact, .btn-danger.compact { padding: 7px 12px; font-size: 13px; }
.animate-spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
