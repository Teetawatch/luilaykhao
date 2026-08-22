<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">e911_emergency</span> ศูนย์เฝ้าระวัง SOS</h1>
        <p class="page-subtitle">สัญญาณขอความช่วยเหลือจากลูกค้าระหว่างทริป — เปิดหน้านี้ค้างไว้ ระบบเช็คให้ทุก 20 วินาที</p>
      </div>
      <div class="head-actions">
        <label class="sound-toggle" :class="{ on: soundOn }">
          <input type="checkbox" v-model="soundOn" />
          <span class="material-symbols-rounded">{{ soundOn ? 'volume_up' : 'volume_off' }}</span>
          เสียงเตือน
        </label>
        <button class="btn-secondary" :disabled="loading" @click="load">
          <span class="material-symbols-rounded" :class="{ spin: loading }">refresh</span> รีเฟรช
        </button>
      </div>
    </div>

    <!-- แถบเตือนใหญ่เมื่อมีเคสค้าง — ต้องเห็นจากอีกฝั่งห้อง -->
    <div v-if="activeCount > 0" class="sos-alarm">
      <span class="material-symbols-rounded pulse">emergency_home</span>
      <div>
        <strong>มีสัญญาณ SOS ที่ยังไม่ปิดเคส {{ activeCount }} รายการ</strong>
        <p>ติดต่อลูกค้าและสตาฟในรอบนั้นทันที แล้วกด "ปิดเคส" เมื่อจัดการเรียบร้อย</p>
      </div>
    </div>

    <div class="filters-bar">
      <button
        v-for="f in filters"
        :key="f.value"
        class="filter-chip"
        :class="{ active: status === f.value }"
        @click="setStatus(f.value)"
      >
        {{ f.label }}
        <span v-if="f.value === 'active' && activeCount" class="count-badge">{{ activeCount }}</span>
      </button>
    </div>

    <div v-if="loading && !alerts.length" class="loading-state"><div class="spinner"></div></div>

    <div v-else-if="!alerts.length" class="empty-state">
      <span class="material-symbols-rounded">check_circle</span>
      <p>{{ status === 'resolved' ? 'ยังไม่มีเคสที่ปิดแล้ว' : 'ไม่มีสัญญาณ SOS ค้างอยู่' }}</p>
    </div>

    <div v-else class="sos-list">
      <article
        v-for="a in alerts"
        :key="a.id"
        class="sos-card"
        :class="{ resolved: a.status === 'resolved' }"
      >
        <div class="sos-side"></div>

        <div class="sos-body">
          <div class="sos-top">
            <span class="status-pill" :class="a.status">
              {{ a.status === 'resolved' ? 'ปิดเคสแล้ว' : 'ยังไม่ปิดเคส' }}
            </span>
            <span class="sos-time">{{ formatDateTime(a.occurred_at || a.created_at) }}</span>
            <span v-if="a.status !== 'resolved'" class="sos-elapsed">{{ elapsed(a.occurred_at || a.created_at) }}</span>
          </div>

          <!--
            เคสที่ค้างอยู่ในเครื่องลูกค้าเพราะไม่มีสัญญาณ แล้วเพิ่งส่งออกมาได้
            ต้องบอกให้ชัด: พิกัดที่เห็นข้างล่างคือที่ที่เขา "เคยอยู่" ตอนกด
            ไม่ใช่ที่ที่เขาอยู่ตอนนี้ — คนที่ออกไปค้นหาต้องรู้ข้อนี้ก่อนออกรถ
          -->
          <p v-if="a.source === 'offline_queue'" class="delayed-note">
            <span class="material-symbols-rounded">cloud_off</span>
            <span>
              <strong>ส่งถึงระบบช้า {{ delayLabel(a.delay_minutes) }}</strong>
              — ลูกค้ากดตอนไม่มีสัญญาณ พิกัดด้านล่างคือตำแหน่งตอนกด
              (ระบบได้รับ {{ formatDateTime(a.created_at) }})
            </span>
          </p>

          <h3 class="sos-person">
            <span class="material-symbols-rounded">person_alert</span>
            {{ a.user_name || 'ลูกค้า' }}
            <a v-if="a.contact_phone" class="call-btn" :href="`tel:${a.contact_phone}`">
              <span class="material-symbols-rounded">call</span> {{ a.contact_phone }}
            </a>
          </h3>

          <p v-if="a.message" class="sos-message">{{ a.message }}</p>

          <!-- โน้ตของทีมงานตอนปิดเคส เก็บแยกจากข้อความที่ลูกค้าพิมพ์ -->
          <p v-if="a.admin_note" class="admin-note">
            <span class="material-symbols-rounded">edit_note</span>
            <span><strong>บันทึกทีมงาน:</strong> {{ a.admin_note }}</span>
          </p>

          <!-- ข้อมูลสุขภาพ: สำคัญที่สุดตอนประสานหน่วยกู้ภัย -->
          <div v-if="a.allergies || a.health_notes" class="health-box">
            <span class="material-symbols-rounded">medical_information</span>
            <div>
              <p v-if="a.allergies"><strong>แพ้:</strong> {{ a.allergies }}</p>
              <p v-if="a.health_notes"><strong>ข้อมูลสุขภาพ:</strong> {{ a.health_notes }}</p>
            </div>
          </div>

          <div class="sos-grid">
            <div class="info-cell">
              <span class="cell-label">ทริป / รอบเดินทาง</span>
              <span class="cell-value">{{ a.trip_title || '-' }}</span>
              <span class="cell-sub" v-if="a.departure_date">{{ formatDate(a.departure_date) }}</span>
            </div>
            <div class="info-cell">
              <span class="cell-label">เลขการจอง</span>
              <span class="cell-value">{{ a.booking_ref || '-' }}</span>
            </div>
            <div class="info-cell">
              <span class="cell-label">รถ / คนขับ</span>
              <span class="cell-value">{{ a.vehicle_plate || '-' }}</span>
              <a v-if="a.driver_phone" class="cell-link" :href="`tel:${a.driver_phone}`">
                {{ a.driver_name || 'คนขับ' }} · {{ a.driver_phone }}
              </a>
            </div>
            <div class="info-cell">
              <span class="cell-label">พิกัดที่กดขอความช่วยเหลือ</span>
              <a
                v-if="a.latitude != null"
                class="cell-link"
                :href="mapLink(a.latitude, a.longitude)"
                target="_blank"
              >เปิดแผนที่ ({{ a.latitude.toFixed(4) }}, {{ a.longitude.toFixed(4) }})</a>
              <span v-else class="cell-value muted">ไม่ได้ส่งพิกัดมา</span>
            </div>
          </div>

          <div v-if="a.vehicle_latitude != null" class="vehicle-hint">
            <span class="material-symbols-rounded">directions_bus</span>
            ตำแหน่งรถล่าสุด
            <a :href="mapLink(a.vehicle_latitude, a.vehicle_longitude)" target="_blank">ดูบนแผนที่</a>
            <span class="muted">({{ formatDateTime(a.vehicle_located_at) }})</span>
            <a
              v-if="a.latitude != null"
              class="route-link"
              :href="`https://www.google.com/maps/dir/${a.vehicle_latitude},${a.vehicle_longitude}/${a.latitude},${a.longitude}`"
              target="_blank"
            >เส้นทางจากรถไปหาลูกค้า</a>
          </div>

          <a v-if="a.photo_url" :href="a.photo_url" target="_blank" class="sos-photo">
            <img :src="a.photo_url" alt="รูปจากที่เกิดเหตุ" />
          </a>

          <div class="sos-foot">
            <span v-if="a.status === 'resolved' && a.resolved_by_name" class="resolver">
              <span class="material-symbols-rounded">task_alt</span>
              ปิดโดย {{ a.resolved_by_name }} · {{ formatDateTime(a.resolved_at) }}
            </span>
            <button
              v-if="a.status !== 'resolved'"
              class="btn-primary resolve-btn"
              :disabled="resolvingId === a.id"
              @click="resolve(a)"
            >
              <span class="material-symbols-rounded">check</span> ปิดเคส
            </button>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';
import './admin-shared.css';

const toast = useToast();
const swal = useSwal();

const filters = [
  { value: 'active', label: 'ยังไม่ปิดเคส' },
  { value: 'resolved', label: 'ปิดเคสแล้ว' },
  { value: '', label: 'ทั้งหมด' },
];

const alerts = ref([]);
const activeCount = ref(0);
const loading = ref(false);
const status = ref('active');
const resolvingId = ref(null);
const soundOn = ref(true);

// จำ id ล่าสุดที่เคยเห็น เพื่อรู้ว่ามีเคสใหม่เข้ามาระหว่างเปิดหน้าค้างไว้
let lastSeenId = 0;
let pollTimer = null;

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/** ช่องว่างระหว่างเวลาที่ลูกค้ากด กับเวลาที่ระบบได้รับ */
function delayLabel(minutes) {
  const mins = Number(minutes) || 0;
  if (mins < 60) return `${mins} นาที`;
  const hours = Math.floor(mins / 60);
  const rest = mins % 60;
  return rest ? `${hours} ชม. ${rest} นาที` : `${hours} ชม.`;
}

function elapsed(d) {
  if (!d) return '';
  const mins = Math.floor((Date.now() - new Date(d).getTime()) / 60000);
  if (mins < 1) return 'เพิ่งกดเมื่อครู่';
  if (mins < 60) return `ผ่านมาแล้ว ${mins} นาที`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `ผ่านมาแล้ว ${hours} ชม.`;
  return `ผ่านมาแล้ว ${Math.floor(hours / 24)} วัน`;
}

function mapLink(lat, lng) {
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
}

/**
 * เสียงเตือนสั้น ๆ ด้วย Web Audio — ไม่ต้องพึ่งไฟล์เสียงภายนอกให้เสี่ยงโหลดไม่ขึ้น
 */
function playAlarm() {
  if (!soundOn.value) return;
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    [0, 0.35, 0.7].forEach((offset) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = 880;
      gain.gain.setValueAtTime(0.001, ctx.currentTime + offset);
      gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + offset + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + offset + 0.25);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(ctx.currentTime + offset);
      osc.stop(ctx.currentTime + offset + 0.3);
    });
  } catch {
    // เบราว์เซอร์ยังไม่อนุญาตให้เล่นเสียง — ปล่อยผ่าน แถบเตือนบนจอยังทำงานปกติ
  }
}

async function load(silent = false) {
  if (!silent) loading.value = true;
  try {
    const res = await api.get('/admin/sos', { params: status.value ? { status: status.value } : {} });
    alerts.value = res.data.data.alerts || [];
    activeCount.value = res.data.data.active_count || 0;
  } catch {
    if (!silent) toast.error('โหลดรายการ SOS ไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
}

/**
 * ระหว่างเปิดหน้าค้างไว้ เช็คแค่ตัวนับ (endpoint เบา) แล้วค่อยโหลดเต็มเมื่อมีของใหม่
 */
async function poll() {
  try {
    const res = await api.get('/admin/sos/active-count');
    const { count, latest_id: latestId } = res.data.data;

    if (latestId && latestId > lastSeenId) {
      if (lastSeenId > 0) {
        playAlarm();
        toast.error('มีสัญญาณ SOS ใหม่เข้ามา!');
      }
      lastSeenId = latestId;
      await load(true);
      return;
    }

    if (count !== activeCount.value) await load(true);
  } catch {
    // เน็ตสะดุดชั่วคราว — รอบหน้าค่อยลองใหม่
  }
}

function setStatus(value) {
  if (status.value === value) return;
  status.value = value;
  load();
}

async function resolve(alert) {
  const result = await swal.confirm({
    title: 'ปิดเคส SOS นี้?',
    text: `${alert.user_name || 'ลูกค้า'} — ${alert.trip_title || 'ทริป'}`,
    icon: 'question',
    confirmText: 'ปิดเคส',
    input: 'text',
    inputLabel: 'บันทึกว่าจัดการอย่างไร (ไม่บังคับ)',
    inputPlaceholder: 'เช่น ประสานกู้ภัยรับตัวแล้ว',
  });
  if (!result.isConfirmed) return;

  resolvingId.value = alert.id;
  try {
    await api.post(`/admin/sos/${alert.id}/resolve`, { note: result.value || null });
    toast.success('ปิดเคสแล้ว');
    await load(true);
  } catch (e) {
    toast.error(e.response?.data?.message || 'ปิดเคสไม่สำเร็จ');
  } finally {
    resolvingId.value = null;
  }
}

onMounted(async () => {
  await load();
  lastSeenId = Math.max(0, ...alerts.value.filter((a) => a.status === 'active').map((a) => a.id));
  pollTimer = setInterval(poll, 20000);
});

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer);
});
</script>

<style scoped>
.head-actions { display: flex; align-items: center; gap: 10px; }
.sound-toggle {
  display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
  font-size: 13px; font-weight: 600; color: #6b7280;
  border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 14px; background: #fff;
}
.sound-toggle.on { color: #b91c1c; border-color: #fecaca; background: #fef2f2; }
.sound-toggle input { display: none; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.sos-alarm {
  display: flex; align-items: center; gap: 16px;
  background: #fef2f2; border: 1px solid #fecaca; border-left: 5px solid #dc2626;
  border-radius: 12px; padding: 16px 20px; margin-bottom: 20px;
}
.sos-alarm .material-symbols-rounded { font-size: 34px !important; color: #dc2626; }
.sos-alarm strong { font-size: 16px; color: #991b1b; }
.sos-alarm p { margin: 2px 0 0; font-size: 13px; color: #b91c1c; }
.pulse { animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

.filter-chip {
  border: 1px solid #e5e7eb; background: #fff; color: #4b5563;
  border-radius: 999px; padding: 7px 16px; font-size: 13px; font-weight: 600;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.filter-chip.active { background: #1f2937; border-color: #1f2937; color: #fff; }
.count-badge { background: #dc2626; color: #fff; border-radius: 999px; font-size: 11px; padding: 1px 7px; font-weight: 700; }

.empty-state { padding: 70px 16px; text-align: center; color: #9ca3af; }
.empty-state .material-symbols-rounded { font-size: 46px !important; color: #d1d5db; }
.empty-state p { margin: 10px 0 0; font-size: 14px; }

.sos-list { display: flex; flex-direction: column; gap: 14px; }
.sos-card {
  display: flex; background: #fff; border: 1px solid #fecaca;
  border-radius: 14px; overflow: hidden;
}
.sos-card .sos-side { width: 5px; background: #dc2626; flex-shrink: 0; }
.sos-card.resolved { border-color: #e5e7eb; opacity: 0.8; }
.sos-card.resolved .sos-side { background: #cbd5e1; }
.sos-body { flex: 1; min-width: 0; padding: 16px 20px; }

.sos-top { display: flex; align-items: center; gap: 10px; }
.status-pill { font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.status-pill.active { background: #fee2e2; color: #b91c1c; }
.status-pill.resolved { background: #dcfce7; color: #15803d; }
.sos-time { font-size: 12px; color: #9ca3af; }
.sos-elapsed { margin-left: auto; font-size: 12px; font-weight: 700; color: #dc2626; }

.sos-person {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  margin: 12px 0 0; font-size: 17px; font-weight: 700; color: #111827;
}
.sos-person .material-symbols-rounded { color: #dc2626; font-size: 22px !important; }
.call-btn {
  display: inline-flex; align-items: center; gap: 5px;
  background: #16a34a; color: #fff; text-decoration: none;
  border-radius: 8px; padding: 5px 12px; font-size: 13px; font-weight: 700;
}
.call-btn .material-symbols-rounded { color: #fff !important; font-size: 16px !important; }

.sos-message {
  margin: 10px 0 0; font-size: 14px; line-height: 1.6; color: #374151;
  white-space: pre-wrap; word-break: break-word;
}

.admin-note {
  display: flex; gap: 8px; align-items: flex-start; margin: 10px 0 0;
  background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 12px;
  font-size: 13px; line-height: 1.6; color: #475569;
  white-space: pre-wrap; word-break: break-word;
}
.admin-note .material-symbols-rounded { color: #94a3b8; font-size: 18px !important; }

/* เคสที่มาถึงช้าเพราะลูกค้าอยู่นอกสัญญาณ — สีเดียวกับกล่องข้อมูลสุขภาพ
   เพราะเป็นข้อมูลที่ต้องอ่านก่อนออกรถเหมือนกัน ไม่ใช่โน้ตประกอบ */
.delayed-note {
  display: flex; gap: 8px; align-items: flex-start; margin: 10px 0 0;
  background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 8px 12px;
  font-size: 13px; line-height: 1.6; color: #78350f;
}
.delayed-note .material-symbols-rounded { color: #b45309; font-size: 18px !important; }

.health-box {
  display: flex; gap: 10px; margin-top: 12px;
  background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 14px;
}
.health-box .material-symbols-rounded { color: #b45309; font-size: 20px !important; }
.health-box p { margin: 0; font-size: 13px; color: #78350f; }

.sos-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 14px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #f3f4f6;
}
.info-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.cell-label { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.4px; }
.cell-value { font-size: 14px; font-weight: 600; color: #1f2937; word-break: break-word; }
.cell-value.muted { color: #9ca3af; font-weight: 500; }
.cell-sub { font-size: 12px; color: #6b7280; }
.cell-link { font-size: 13px; font-weight: 600; color: #2563eb; text-decoration: none; word-break: break-all; }
.cell-link:hover { text-decoration: underline; }

.vehicle-hint {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  margin-top: 12px; font-size: 13px; color: #374151;
}
.vehicle-hint .material-symbols-rounded { font-size: 19px !important; color: #2563eb; }
.vehicle-hint a { color: #2563eb; font-weight: 600; text-decoration: none; }
.vehicle-hint a:hover { text-decoration: underline; }
.vehicle-hint .muted { color: #9ca3af; }
.route-link { border-left: 1px solid #e5e7eb; padding-left: 10px; }

.sos-photo { display: inline-block; margin-top: 12px; }
.sos-photo img { max-height: 180px; border-radius: 10px; border: 1px solid #e5e7eb; }

.sos-foot { display: flex; align-items: center; gap: 14px; margin-top: 14px; flex-wrap: wrap; }
.resolver { font-size: 12.5px; font-weight: 600; color: #15803d; display: inline-flex; align-items: center; gap: 5px; }
.resolver .material-symbols-rounded { font-size: 17px !important; color: #16a34a; }
.resolve-btn { margin-left: auto; background: #dc2626; }
.resolve-btn:hover { background: #b91c1c; }
</style>
