<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">event_repeat</span>
          ลูกค้าที่ถูกเลื่อนรอบ
        </h1>
        <p class="page-subtitle">
          ใบจองที่รอบเดิมถูกยกเลิกเพราะเหตุสุดวิสัยหรือคนไม่ครบ — ย้ายไปรอบไหนแล้ว
          และรอบใหม่กันที่นั่งไว้ให้ใครอยู่บ้าง
        </p>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" :disabled="loading" @click="fetchData">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': loading }">refresh</span>
          {{ loading ? 'กำลังโหลด' : 'รีเฟรช' }}
        </button>
      </div>
    </div>

    <div v-if="counts" class="summary-row">
      <div class="stat" :class="{ warn: counts.awaiting }">
        <span class="stat-num">{{ counts.awaiting }}</span>
        <span class="stat-label">รอเลือกรอบ</span>
      </div>
      <div class="stat ok">
        <span class="stat-num">{{ counts.moved }}</span>
        <span class="stat-label">เลือกรอบแล้ว</span>
      </div>
      <div class="stat" :class="{ warn: counts.refund_requested }">
        <span class="stat-num">{{ counts.refund_requested }}</span>
        <span class="stat-label">รอโอนเงินคืน</span>
      </div>
      <div class="stat" :class="{ bad: counts.expired }">
        <span class="stat-num">{{ counts.expired }}</span>
        <span class="stat-label">หมดสิทธิ์</span>
      </div>
      <div class="stat">
        <span class="stat-num">{{ counts.active_holds }}</span>
        <span class="stat-label">ที่นั่งที่กันอยู่ (รายการ)</span>
      </div>
    </div>

    <div class="loading-state" v-if="loading && !rows.length"><div class="spinner"></div></div>

    <template v-else>
      <!-- รอบที่กันที่นั่งไว้ — ตอบว่า "ทำไมรอบนี้เต็ม" ก่อนอย่างอื่น -->
      <section v-if="rounds.length" class="section">
        <h2 class="section-title">รอบที่กันที่นั่งไว้ให้ลูกค้าที่ถูกเลื่อน</h2>
        <div class="round-grid">
          <div v-for="round in rounds" :key="round.schedule_id" class="table-card round-card" :class="{ 'is-over': round.overcommitted }">
            <div class="round-head">
              <div style="min-width:0">
                <div class="group-title">{{ round.trip_title || '—' }}</div>
                <div class="cell-sub">{{ round.departure_label }}</div>
              </div>
              <div class="seats">
                <div class="seats-main">ว่าง {{ round.available_seats }} · กัน {{ round.held_seats }}</div>
                <div class="cell-sub">จองแล้ว {{ round.booked_seats }} / {{ round.total_seats }}</div>
              </div>
            </div>
            <div v-if="round.overcommitted" class="alert-line">
              <span class="material-symbols-rounded inline-icon">error</span>
              กันไว้เกินที่ว่างจริง {{ round.held_seats - round.available_seats }} ที่ — หน้าจองขึ้นว่าเต็ม
              และรับลูกค้าที่ถูกกันไว้ให้ได้ไม่ครบทุกกลุ่ม ติดต่อลูกค้าแล้วปล่อยที่กันของกลุ่มที่ไม่มา
            </div>
            <ul class="hold-list">
              <li v-for="hold in round.holds" :key="hold.id">
                <div style="min-width:0">
                  <router-link :to="bookingLink(hold.booking_ref)" class="ref-link">{{ hold.booking_ref }}</router-link>
                  <span class="tag">{{ hold.seat_count }} ที่</span>
                  <div class="cell-sub">{{ hold.customer_name || '—' }} · {{ hold.customer_phone || 'ไม่มีเบอร์' }}</div>
                  <div class="cell-sub" :class="{ 'text-bad': hold.expires_after_departure }">
                    กันถึง {{ hold.expires_label }}
                    <template v-if="hold.expires_after_departure"> · หมดเวลาหลังรถออก</template>
                  </div>
                </div>
                <button class="btn-secondary btn-sm" :disabled="releasing === hold.id" @click="releaseHold(hold, round)">
                  {{ releasing === hold.id ? 'กำลังปล่อย…' : 'ปล่อยที่กัน' }}
                </button>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">ใบจองที่ถูกเลื่อน</h2>
        <div class="filter-bar">
          <div class="state-tabs">
            <button v-for="tab in tabs" :key="tab.key" type="button" class="state-tab"
              :class="{ active: stateFilter === tab.key }" @click="stateFilter = tab.key">
              {{ tab.label }}
            </button>
          </div>
          <div class="filter-field" style="flex:1;min-width:220px;">
            <input v-model.trim="search" type="text" placeholder="ค้นหา ทริป / รหัสจอง / ชื่อลูกค้า / เบอร์โทร" />
          </div>
        </div>

        <div v-if="!filteredRows.length" class="table-card">
          <div class="empty-state">ไม่มีใบจองในหมวดนี้</div>
        </div>

        <div v-else class="table-card">
          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>ใบจอง / ลูกค้า</th>
                  <th>รอบเดิม</th>
                  <th>ตอนนี้</th>
                  <th>ที่นั่งที่กันไว้</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredRows" :key="row.id">
                  <td>
                    <router-link :to="bookingLink(row.booking_ref)" class="ref-link">{{ row.booking_ref }}</router-link>
                    <div class="cell-sub">{{ row.customer_name || '—' }} · {{ row.customer_phone || 'ไม่มีเบอร์' }}</div>
                    <div class="cell-sub">{{ row.passengers_count }} ท่าน · ชำระแล้ว {{ money(row.paid_amount) }}</div>
                  </td>
                  <td>
                    <div class="cell-strong">{{ row.trip_title || '—' }}</div>
                    <div class="cell-sub">{{ row.original?.label || '—' }}</div>
                    <div class="cell-sub">{{ row.kind === 'underfilled' ? 'คนไม่ครบ' : 'เหตุสุดวิสัย' }} · {{ row.reason || '—' }}</div>
                  </td>
                  <td>
                    <span class="tag" :class="stateClass(row.state)">{{ stateLabel(row.state) }}</span>
                    <div v-if="row.moved_to" class="cell-strong moved">→ {{ row.moved_to.label }}</div>
                    <div v-if="row.moved_to?.moved_at" class="cell-sub">เลือกเมื่อ {{ formatDateTime(row.moved_to.moved_at) }}</div>
                    <div v-else-if="row.state === 'awaiting'" class="cell-sub">
                      เลือกรอบที่ออกได้ถึง {{ row.until_label || '—' }}
                      <template v-if="row.decide_by_label"><br />ตัดสินใจภายใน {{ row.decide_by_label }}</template>
                    </div>
                  </td>
                  <td>
                    <div v-if="!row.holds.length" class="cell-sub">—</div>
                    <div v-for="hold in row.holds" :key="hold.id" class="hold-line">
                      <span class="tag" :class="holdClass(hold.state)">{{ holdLabel(hold.state) }}</span>
                      {{ hold.departure_label }} · {{ hold.seat_count }} ที่
                      <div v-if="hold.state === 'active'" class="cell-sub" :class="{ 'text-bad': hold.expires_after_departure }">
                        ถึง {{ hold.expires_label }}<template v-if="hold.expires_after_departure"> · หลังรถออก</template>
                      </div>
                    </div>
                  </td>
                  <td class="actions">
                    <button v-if="row.choose_url" class="btn-secondary btn-sm" @click="copyLink(row)">
                      <span class="material-symbols-rounded" style="font-size:16px">{{ copiedId === row.id ? 'check' : 'link' }}</span>
                      {{ copiedId === row.id ? 'คัดลอกแล้ว' : 'ลิงก์เลือกรอบ' }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../lib/axios';

const loading = ref(true);
const rows = ref([]);
const rounds = ref([]);
const counts = ref(null);
const search = ref('');
const stateFilter = ref('open');
const releasing = ref(null);
const copiedId = ref(null);

const tabs = [
  { key: 'open', label: 'ยังไม่จบ' },
  { key: 'awaiting', label: 'รอเลือกรอบ' },
  { key: 'moved', label: 'เลือกรอบแล้ว' },
  { key: 'refund_requested', label: 'รอโอนเงินคืน' },
  { key: 'expired', label: 'หมดสิทธิ์' },
  { key: 'all', label: 'ทั้งหมด' },
];

const OPEN_STATES = ['awaiting', 'refund_requested', 'expired'];

const filteredRows = computed(() => {
  const q = search.value.toLowerCase();
  const matches = (v) => v != null && String(v).toLowerCase().includes(q);

  return rows.value.filter((row) => {
    if (stateFilter.value === 'open' && !OPEN_STATES.includes(row.state)) return false;
    if (!['open', 'all'].includes(stateFilter.value) && row.state !== stateFilter.value) return false;
    if (!q) return true;
    return [row.booking_ref, row.customer_name, row.customer_phone, row.trip_title].some(matches);
  });
});

const fetchData = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/force-majeure/bookings');
    rows.value = res.data.data?.bookings ?? [];
    rounds.value = res.data.data?.rounds_with_holds ?? [];
    counts.value = res.data.data?.counts ?? null;
  } catch (e) {
    alert(e.response?.data?.message ?? 'โหลดข้อมูลไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const releaseHold = async (hold, round) => {
  const question = `ปล่อยที่นั่ง ${hold.seat_count} ที่ที่กันไว้ให้ ${hold.booking_ref} ในรอบ ${round.departure_label}?\n`
    + 'ลูกค้ายังเลือกรอบอื่น หรือรอบนี้ถ้ายังมีที่ว่างได้ตามสิทธิ์เดิม แต่จะไม่มีที่นั่งรอไว้ให้แล้ว';
  if (!window.confirm(question)) return;

  releasing.value = hold.id;
  try {
    await api.post(`/admin/force-majeure/holds/${hold.id}/release`);
    await fetchData();
  } catch (e) {
    alert(e.response?.data?.message ?? 'ปล่อยที่กันไม่สำเร็จ');
  } finally {
    releasing.value = null;
  }
};

const copyLink = async (row) => {
  try {
    await navigator.clipboard.writeText(row.choose_url);
    copiedId.value = row.id;
    setTimeout(() => { copiedId.value = null; }, 1800);
  } catch {
    window.prompt('คัดลอกลิงก์นี้', row.choose_url);
  }
};

const bookingLink = (ref) => ({ path: '/admin/bookings', query: { search: ref } });

const STATE = {
  awaiting: ['รอเลือกรอบ', 'tag-warn'],
  moved: ['เลือกรอบแล้ว', 'tag-ok'],
  expired: ['หมดสิทธิ์', 'tag-bad'],
  refund_requested: ['รอโอนเงินคืน', 'tag-warn'],
  refunded: ['คืนเงินแล้ว', ''],
  cancelled: ['ยกเลิกแล้ว', ''],
};
const stateLabel = (s) => STATE[s]?.[0] ?? s;
const stateClass = (s) => STATE[s]?.[1] ?? '';

const HOLD = {
  active: ['กันอยู่', 'tag-warn'],
  used: ['ใช้ที่นี้แล้ว', 'tag-ok'],
  released: ['ปล่อยแล้ว', ''],
  expired: ['หมดเวลา', ''],
  void: ['ไม่นับแล้ว', ''],
};
const holdLabel = (s) => HOLD[s]?.[0] ?? s;
const holdClass = (s) => HOLD[s]?.[1] ?? '';

const money = (n) => `฿${Number(n || 0).toLocaleString('th-TH')}`;

const formatDateTime = (d) => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleString('th-TH', {
      timeZone: 'Asia/Bangkok', year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit',
    }) + ' น.';
  } catch {
    return d;
  }
};

onMounted(fetchData);
</script>

<style scoped>
@import url('./admin-shared.css');

.heading-icon { color: var(--color-accent); font-size: 28px; }
.header-actions { display: flex; gap: 8px; }

.summary-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 10px;
  margin-bottom: 20px;
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

.section { margin-bottom: 24px; }
.section-title { font-size: 16px; font-weight: 800; color: #111827; margin: 0 0 10px; }

.round-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
}
.round-card.is-over { border-color: #fca5a5; }
.round-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid #e5e7eb;
}
.group-title { font-size: 15px; font-weight: 800; color: #111827; }
.seats { text-align: right; flex-shrink: 0; }
.seats-main { font-size: 14px; font-weight: 800; color: #111827; }
.is-over .seats-main { color: #dc2626; }

.alert-line {
  background: #fef2f2;
  color: #b91c1c;
  font-size: 13px;
  padding: 10px 16px;
  border-bottom: 1px solid #fee2e2;
}

.hold-list { list-style: none; margin: 0; padding: 0; }
.hold-list li {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 16px;
}
.hold-list li + li { border-top: 1px solid #f0f0f0; }

.filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 12px 16px;
}
.state-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
.state-tab {
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 999px;
  padding: 6px 12px;
  font-size: 13px;
  font-weight: 700;
  color: #4b5563;
  cursor: pointer;
}
.state-tab.active { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
.filter-field input {
  width: 100%;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 14px;
}

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
  white-space: nowrap;
}
.data-table td {
  padding: 12px 16px;
  border-bottom: 1px solid #f0f0f0;
  font-size: 14px;
  color: #111827;
  vertical-align: top;
}
.data-table tr:last-child td { border-bottom: none; }
.actions { text-align: right; white-space: nowrap; }

.ref-link { font-weight: 800; color: var(--color-primary); text-decoration: none; }
.ref-link:hover { text-decoration: underline; }
.cell-strong { font-weight: 700; }
.cell-strong.moved { color: #15803d; margin-top: 4px; }
.cell-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
.text-bad { color: #dc2626; font-weight: 700; }
.inline-icon { font-size: 15px; vertical-align: -3px; }
.hold-line + .hold-line { margin-top: 8px; }
.btn-sm { font-size: 13px; padding: 6px 10px; flex-shrink: 0; }

.tag {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  background: #f3f4f6;
  color: #4b5563;
  margin-right: 4px;
}
.tag-ok { background: #dcfce7; color: #15803d; }
.tag-warn { background: #fef3c7; color: #b45309; }
.tag-bad { background: #fee2e2; color: #dc2626; }

@media (max-width: 640px) {
  .round-grid { grid-template-columns: 1fr; }
  .round-head { flex-direction: column; }
  .seats { text-align: left; }
}
</style>
