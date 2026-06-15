<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">cake</span>
          ตามเก็บวันเกิด
        </h1>
        <p class="page-subtitle">
          การจองที่กำลังจะเดินทางและยังกรอกวันเกิดไม่ครบ — กดคัดลอกลิงก์แล้วส่งให้ลูกค้า (คนจอง)
          กรอกวันเกิดของผู้เดินทางทุกคนในการจองได้เอง
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
      <div class="filter-field" style="flex:1;min-width:220px;">
        <label>ค้นหา</label>
        <input v-model.trim="search" type="text" placeholder="ชื่อทริป / คนจอง / รหัสจอง / เบอร์โทร" />
      </div>
      <div class="summary-pill">
        <span class="material-symbols-rounded">groups</span>
        ค้างอยู่ {{ filtered.length }} การจอง · ขาดวันเกิดรวม {{ totalMissing }} คน
      </div>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div v-else>
        <div v-if="!filtered.length" class="empty-state">
          <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">
            task_alt
          </span>
          ไม่มีการจองที่ค้างวันเกิด — ครบทุกคนแล้ว 🎉
        </div>

        <table v-else class="data-table">
          <thead>
            <tr>
              <th>ทริป / วันเดินทาง</th>
              <th>คนจอง</th>
              <th>ความคืบหน้า</th>
              <th style="text-align:right;">ลิงก์กรอกวันเกิด</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filtered" :key="row.booking_id">
              <td>
                <strong>{{ row.trip_title || '—' }}</strong>
                <div class="cell-sub">
                  <span class="material-symbols-rounded" style="font-size:13px;vertical-align:-2px;">event</span>
                  {{ formatDate(row.departure_date) }}
                  <span class="dot">·</span>{{ row.booking_ref }}
                </div>
              </td>
              <td>
                {{ row.customer_name || '—' }}
                <div class="cell-sub">{{ row.customer_phone || 'ไม่มีเบอร์' }}</div>
              </td>
              <td>
                <span class="progress-pill" :class="{ done: row.missing_count === 0 }">
                  {{ row.filled_count }}/{{ row.total_passengers }} คน
                </span>
                <span class="missing-tag">ขาด {{ row.missing_count }}</span>
              </td>
              <td style="text-align:right;">
                <button class="btn-primary btn-sm" @click="copyLink(row)">
                  <span class="material-symbols-rounded" style="font-size:16px">
                    {{ copiedId === row.booking_id ? 'check' : 'content_copy' }}
                  </span>
                  {{ copiedId === row.booking_id ? 'คัดลอกแล้ว' : 'คัดลอกลิงก์' }}
                </button>
                <a class="btn-secondary btn-sm" :href="row.link" target="_blank" rel="noopener" title="เปิดดูหน้าฟอร์ม">
                  <span class="material-symbols-rounded" style="font-size:16px">open_in_new</span>
                </a>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../lib/axios';

const loading = ref(true);
const rows = ref([]);
const search = ref('');
const copiedId = ref(null);

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter((r) =>
    [r.trip_title, r.customer_name, r.booking_ref, r.customer_phone]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q))
  );
});

const totalMissing = computed(() =>
  filtered.value.reduce((sum, r) => sum + (r.missing_count || 0), 0)
);

const fetchData = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/birthdate-followup');
    rows.value = res.data.data ?? res.data ?? [];
  } catch (e) {
    rows.value = [];
    alert(e.response?.data?.message ?? 'โหลดข้อมูลไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const copyLink = async (row) => {
  try {
    await navigator.clipboard.writeText(row.link);
    copiedId.value = row.booking_id;
    setTimeout(() => {
      if (copiedId.value === row.booking_id) copiedId.value = null;
    }, 1800);
  } catch {
    // Fallback: show the link so the operator can copy it manually.
    prompt('คัดลอกลิงก์นี้:', row.link);
  }
};

const formatDate = (d) => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
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
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.filter-field input {
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 14px;
}

.summary-pill {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 13px;
  color: var(--color-accent, #2d7a4f);
  background: rgba(45, 122, 79, 0.08);
  padding: 8px 14px;
  border-radius: 999px;
}

.summary-pill .material-symbols-rounded { font-size: 16px; }

.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th {
  text-align: left;
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  padding: 12px 16px;
  border-bottom: 1px solid #e5e7eb;
  background: #fafafa;
}

.data-table td {
  padding: 14px 16px;
  border-bottom: 1px solid #f0f0f0;
  font-size: 14px;
  color: #111827;
  vertical-align: middle;
}

.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: #fafafa; }

.cell-sub {
  font-size: 12px;
  color: #6b7280;
  margin-top: 2px;
}

.cell-sub .dot { margin: 0 5px; color: #d1d5db; }

.progress-pill {
  display: inline-block;
  font-size: 12px;
  font-weight: 800;
  color: #b45309;
  background: #fef3c7;
  padding: 3px 10px;
  border-radius: 999px;
}

.progress-pill.done { color: #065f46; background: #d1fae5; }

.missing-tag {
  margin-left: 8px;
  font-size: 12px;
  font-weight: 700;
  color: #b91c1c;
}

.btn-sm {
  font-size: 13px;
  padding: 7px 12px;
}

.btn-secondary.btn-sm {
  margin-left: 6px;
  padding: 7px 9px;
}
</style>
