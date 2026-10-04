<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">mark_email_read</span>
          หลักฐานแจ้งคนไม่ครบ
        </h1>
        <p class="page-subtitle">
          อีเมลที่ระบบส่งอัตโนมัติ {{ daysBefore }} วันก่อนเดินทาง ถึงลูกค้าในรอบที่ผู้ร่วมทริปยังไม่ครบขั้นต่ำ
          — ส่งถึงที่อยู่ไหน เมื่อไหร่ และเนื้อหาที่ลูกค้าได้รับจริง พร้อมสถานะแจ้งเตือนในแอป
        </p>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" :disabled="loading" @click="fetchData">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span>
          {{ loading ? 'กำลังโหลด' : 'รีเฟรช' }}
        </button>
      </div>
    </div>

    <div class="filter-bar">
      <div class="filter-field">
        <label>ส่งตั้งแต่</label>
        <input v-model="from" type="date" @change="fetchData" />
      </div>
      <div class="filter-field">
        <label>ถึง</label>
        <input v-model="to" type="date" @change="fetchData" />
      </div>
      <div class="filter-field" style="flex:1;min-width:220px;">
        <label>ค้นหา</label>
        <input v-model.trim="search" type="text" placeholder="ชื่อทริป / รหัสจอง / ชื่อลูกค้า / อีเมล / เบอร์โทร" />
      </div>
    </div>

    <div v-if="summary" class="summary-row">
      <div class="stat">
        <span class="stat-num">{{ summary.schedules }}</span>
        <span class="stat-label">รอบที่แจ้ง</span>
      </div>
      <div class="stat">
        <span class="stat-num">{{ summary.bookings }}</span>
        <span class="stat-label">ใบจอง</span>
      </div>
      <div class="stat ok">
        <span class="stat-num">{{ summary.emails_sent }}</span>
        <span class="stat-label">อีเมลส่งสำเร็จ</span>
      </div>
      <div class="stat" :class="{ warn: summary.emails_queued }">
        <span class="stat-num">{{ summary.emails_queued }}</span>
        <span class="stat-label">รอส่ง</span>
      </div>
      <div class="stat" :class="{ bad: summary.emails_failed }">
        <span class="stat-num">{{ summary.emails_failed }}</span>
        <span class="stat-label">ส่งไม่สำเร็จ</span>
      </div>
      <div class="stat" :class="{ warn: summary.no_email }">
        <span class="stat-num">{{ summary.no_email }}</span>
        <span class="stat-label">ไม่มีอีเมล</span>
      </div>
      <div class="stat">
        <span class="stat-num">{{ summary.in_app_read }}</span>
        <span class="stat-label">เปิดอ่านในแอป</span>
      </div>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <div v-else-if="!filteredGroups.length" class="table-card">
      <div class="empty-state">
        <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">
          mail
        </span>
        ไม่มีการแจ้งคนไม่ครบในช่วงวันที่นี้
      </div>
    </div>

    <div v-else class="groups">
      <div v-for="group in filteredGroups" :key="group.schedule_id ?? group.trip_title" class="table-card group-card">
        <div class="group-head">
          <div>
            <div class="group-title">
              {{ group.trip_title || '—' }}
              <span v-if="group.schedule_status === 'cancelled'" class="tag tag-bad">รอบยกเลิก</span>
            </div>
            <div class="cell-sub">
              <span class="material-symbols-rounded inline-icon">event</span>
              เดินทาง {{ formatDate(group.departure_date) }}
              <span class="dot">·</span>
              <span class="material-symbols-rounded inline-icon">send</span>
              แจ้งเมื่อ {{ formatDateTime(group.warned_at) }}
            </div>
          </div>
          <div v-if="group.booked_seats_at_warning != null" class="seats">
            <div class="seats-main">{{ group.booked_seats_at_warning }} / {{ group.min_seats }} ท่าน</div>
            <div class="cell-sub">
              ตอนแจ้ง
              <template v-if="group.booked_seats_now != null && group.booked_seats_now !== group.booked_seats_at_warning">
                · ตอนนี้ {{ group.booked_seats_now }} ท่าน
              </template>
            </div>
          </div>
        </div>

        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>ใบจอง / ลูกค้า</th>
                <th>อีเมล</th>
                <th>แจ้งในแอป</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in group.bookings" :key="row.booking_ref">
                <td>
                  <strong>{{ row.booking_ref }}</strong>
                  <span v-if="row.booking_status && row.booking_status !== 'confirmed'" class="tag">
                    {{ bookingStatusLabel(row.booking_status) }}
                  </span>
                  <div class="cell-sub">{{ row.customer_name || '—' }} · {{ row.customer_phone || 'ไม่มีเบอร์' }}</div>
                </td>
                <td>
                  <div v-if="row.email_unrecorded" class="cell-sub unrecorded">
                    ไม่มีบันทึกอีเมล — แจ้งก่อนระบบเริ่มเก็บหลักฐาน
                  </div>
                  <div v-for="mail in row.emails" :key="mail.id" class="mail-line">
                    <span class="tag" :class="statusClass(mail.status)">{{ statusLabel(mail.status) }}</span>
                    <span v-if="mail.recipient" class="recipient">{{ mail.recipient }}</span>
                    <span v-else class="cell-sub">{{ mail.error_message }}</span>
                    <div class="cell-sub">
                      <template v-if="mail.status === 'sent'">ส่งถึงเซิร์ฟเวอร์อีเมล {{ formatDateTime(mail.sent_at) }}</template>
                      <template v-else-if="mail.status === 'failed'">
                        {{ formatDateTime(mail.failed_at) }} · {{ mail.error_message }}
                      </template>
                      <template v-else-if="mail.status === 'queued'">เข้าคิว {{ formatDateTime(mail.queued_at) }}</template>
                      <template v-else>ต้องโทรหรือทักแจ้งเอง</template>
                    </div>
                    <button v-if="mail.has_body" class="btn-secondary btn-sm" @click="openEmail(mail)">
                      <span class="material-symbols-rounded" style="font-size:16px">visibility</span>
                      ดูอีเมลที่ส่ง
                    </button>
                  </div>
                </td>
                <td>
                  <template v-if="row.in_app">
                    <span class="tag" :class="row.in_app.is_read ? 'tag-ok' : ''">
                      {{ row.in_app.is_read ? 'อ่านแล้ว' : 'ยังไม่อ่าน' }}
                    </span>
                    <div class="cell-sub">
                      ส่ง {{ formatDateTime(row.in_app.sent_at) }}
                      <template v-if="row.in_app.read_at"><br />อ่าน {{ formatDateTime(row.in_app.read_at) }}</template>
                    </div>
                  </template>
                  <span v-else class="cell-sub">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="viewer.open" class="modal-overlay" @click.self="viewer.open = false">
      <div class="modal-card viewer-card">
        <div class="modal-header">
          <div style="min-width:0">
            <h2>อีเมลฉบับที่ส่งจริง</h2>
            <p class="cell-sub" style="margin:2px 0 0">{{ viewer.data?.booking_ref }}</p>
          </div>
          <button class="modal-close" @click="viewer.open = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="viewer-body">
          <div v-if="viewer.loading" class="loading-state"><div class="spinner"></div></div>
          <template v-else-if="viewer.data">
            <dl class="evidence">
              <dt>ถึง</dt><dd>{{ viewer.data.recipient }}</dd>
              <dt>หัวเรื่อง</dt><dd>{{ viewer.data.subject }}</dd>
              <dt>ส่งเมื่อ</dt><dd>{{ formatDateTime(viewer.data.sent_at) }}</dd>
              <dt>Message-ID</dt><dd class="mono">{{ viewer.data.message_id }}</dd>
            </dl>
            <iframe ref="frame" class="email-frame" sandbox="allow-same-origin allow-modals" :srcdoc="viewer.data.html"></iframe>
          </template>
        </div>

        <div class="viewer-footer">
          <button class="btn-secondary" :disabled="!viewer.data" @click="copyEvidence">
            <span class="material-symbols-rounded" style="font-size:18px">{{ copied ? 'check' : 'content_copy' }}</span>
            {{ copied ? 'คัดลอกแล้ว' : 'คัดลอกข้อมูลการส่ง' }}
          </button>
          <button class="btn-primary" :disabled="!viewer.data" @click="printEmail">
            <span class="material-symbols-rounded" style="font-size:18px">print</span>
            พิมพ์ / บันทึก PDF
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../lib/axios';
import { bangkokToday } from '../../lib/bangkokDate';

const daysBefore = 7;

const loading = ref(true);
const groups = ref([]);
const summary = ref(null);
const search = ref('');
const to = ref(bangkokToday());
const from = ref(shiftDate(bangkokToday(), -60));
const frame = ref(null);
const copied = ref(false);
const viewer = reactive({ open: false, loading: false, data: null });

function shiftDate(ymd, days) {
  const d = new Date(`${ymd}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

const filteredGroups = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return groups.value;

  const matches = (v) => v != null && String(v).toLowerCase().includes(q);

  return groups.value
    .map((g) => {
      if (matches(g.trip_title)) return g;
      const bookings = g.bookings.filter((b) =>
        [b.booking_ref, b.customer_name, b.customer_phone].some(matches)
        || b.emails.some((m) => matches(m.recipient))
      );
      return bookings.length ? { ...g, bookings } : null;
    })
    .filter(Boolean);
});

const fetchData = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/underfilled-emails', { params: { from: from.value, to: to.value } });
    groups.value = res.data.data?.groups ?? [];
    summary.value = res.data.data?.summary ?? null;
  } catch (e) {
    groups.value = [];
    summary.value = null;
    alert(e.response?.data?.message ?? 'โหลดข้อมูลไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const openEmail = async (mail) => {
  viewer.open = true;
  viewer.loading = true;
  viewer.data = null;
  copied.value = false;
  try {
    const res = await api.get(`/admin/underfilled-emails/${mail.id}`);
    viewer.data = res.data.data;
  } catch (e) {
    viewer.open = false;
    alert(e.response?.data?.message ?? 'เปิดอีเมลไม่สำเร็จ');
  } finally {
    viewer.loading = false;
  }
};

const printEmail = () => {
  frame.value?.contentWindow?.print();
};

const copyEvidence = async () => {
  const d = viewer.data;
  if (!d) return;
  const text = [
    `รหัสจอง: ${d.booking_ref}`,
    `ส่งถึง: ${d.recipient}`,
    `หัวเรื่อง: ${d.subject}`,
    `ส่งเมื่อ: ${formatDateTime(d.sent_at)}`,
    `Message-ID: ${d.message_id}`,
  ].join('\n');
  try {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1800);
  } catch {
    prompt('คัดลอกข้อมูลนี้:', text);
  }
};

const STATUS = {
  sent: ['ส่งแล้ว', 'tag-ok'],
  queued: ['รอส่ง', 'tag-warn'],
  failed: ['ส่งไม่สำเร็จ', 'tag-bad'],
  skipped: ['ไม่มีอีเมล', 'tag-warn'],
};
const statusLabel = (s) => STATUS[s]?.[0] ?? s;
const statusClass = (s) => STATUS[s]?.[1] ?? '';

const bookingStatusLabel = (s) => ({
  cancelled: 'ยกเลิกแล้ว',
  pending: 'รอชำระ',
  completed: 'เดินทางแล้ว',
  refunded: 'คืนเงินแล้ว',
}[s] ?? s);

const formatDate = (d) => {
  if (!d) return '—';
  try {
    return new Date(`${String(d).slice(0, 10)}T00:00:00+07:00`).toLocaleDateString('th-TH', {
      timeZone: 'Asia/Bangkok', year: 'numeric', month: 'short', day: 'numeric',
    });
  } catch {
    return d;
  }
};

const formatDateTime = (d) => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleString('th-TH', {
      timeZone: 'Asia/Bangkok', year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
    }) + ' น.';
  } catch {
    return d;
  }
};

onMounted(fetchData);
</script>

<style scoped>
@import url('./admin-shared.css');

.heading-icon {
  color: var(--color-accent);
  font-size: 28px;
}

.header-actions { display: flex; gap: 8px; }

.filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  margin-bottom: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 14px 16px;
}

.filter-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-field label {
  font-size: 11px;
  font-weight: 700;
  color: #6b7280;
  letter-spacing: 0.04em;
}

.filter-field input {
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 14px;
}

.summary-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}

.stat {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.stat-num { font-size: 22px; font-weight: 800; color: #111827; }
.stat-label { font-size: 12px; color: #6b7280; }
.stat.ok .stat-num { color: #15803d; }
.stat.warn .stat-num { color: #b45309; }
.stat.bad .stat-num { color: #dc2626; }

.groups { display: flex; flex-direction: column; gap: 16px; }

.group-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  border-bottom: 1px solid #e5e7eb;
}

.group-title { font-size: 16px; font-weight: 800; color: #111827; }

.seats { text-align: right; flex-shrink: 0; }
.seats-main { font-size: 16px; font-weight: 800; color: #b45309; }

.table-scroll { overflow-x: auto; }

.data-table { width: 100%; border-collapse: collapse; }

.data-table th {
  text-align: left;
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  padding: 10px 16px;
  border-bottom: 1px solid #e5e7eb;
  background: #fafafa;
}

.data-table td {
  padding: 12px 16px;
  border-bottom: 1px solid #f0f0f0;
  font-size: 14px;
  color: #111827;
  vertical-align: top;
}

.data-table tr:last-child td { border-bottom: none; }

.cell-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
.inline-icon { font-size: 13px; vertical-align: -2px; }
.dot { margin: 0 6px; }
.unrecorded { font-style: italic; }

.mail-line + .mail-line { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e5e7eb; }
.btn-sm { font-size: 13px; padding: 6px 10px; }
.mail-line .btn-sm { margin-top: 6px; }
.recipient { font-weight: 600; margin-left: 6px; word-break: break-all; }

.tag {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  background: #f3f4f6;
  color: #4b5563;
  margin-left: 4px;
}

.mail-line > .tag:first-child { margin-left: 0; }
.tag-ok { background: #dcfce7; color: #15803d; }
.tag-warn { background: #fef3c7; color: #b45309; }
.tag-bad { background: #fee2e2; color: #dc2626; }

.viewer-card {
  max-width: 760px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.viewer-body {
  padding: 16px 24px;
  overflow-y: auto;
  flex: 1;
}

.evidence {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 4px 12px;
  margin: 0 0 12px;
  font-size: 13px;
}

.evidence dt { color: #6b7280; font-weight: 600; }
.evidence dd { margin: 0; color: #111827; word-break: break-all; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }

.email-frame {
  width: 100%;
  height: 60vh;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
}

.viewer-footer {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
  padding: 14px 24px;
  border-top: 1px solid #eeeeee;
}

@media (max-width: 640px) {
  .group-head { flex-direction: column; }
  .seats { text-align: left; }
}
</style>
