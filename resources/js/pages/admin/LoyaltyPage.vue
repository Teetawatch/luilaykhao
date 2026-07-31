<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">star</span> ระบบสะสมแต้ม</h1>
        <p class="page-subtitle">จัดการของรางวัลและดูสถิติโปรแกรมสมาชิก</p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <span class="material-symbols-rounded">add</span> เพิ่มของรางวัล
      </button>
    </div>

    <!-- Stats -->
    <div class="stats-grid mb-20" v-if="loyaltyStats">
      <div class="stat-card">
        <div class="stat-icon bg-blue-50 text-blue-600"><span class="material-symbols-rounded">group</span></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.total_accounts?.toLocaleString() }}</span>
          <span class="stat-label">สมาชิกทั้งหมด</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon bg-green-50 text-green-600"><span class="material-symbols-rounded">monetization_on</span></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.total_points_issued?.toLocaleString() }}</span>
          <span class="stat-label">แต้มที่ออกทั้งหมด</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon bg-purple-50 text-purple-600"><span class="material-symbols-rounded">card_giftcard</span></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.total_points_redeemed?.toLocaleString() }}</span>
          <span class="stat-label">แต้มที่แลกไปแล้ว</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon bg-amber-50 text-amber-600"><span class="material-symbols-rounded">workspace_premium</span></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.tier_counts?.gold || 0 }}</span>
          <span class="stat-label">Gold / {{ loyaltyStats.tier_counts?.silver || 0 }} Silver</span>
        </div>
      </div>
    </div>

    <!-- Tier Overview -->
    <div class="table-card mb-20" v-if="loyaltyStats">
      <div class="card-header border-b border-[var(--color-sand-dark)] pb-4 mb-4">
        <h3 class="flex items-center gap-2 m-0 text-[15px] font-semibold text-[var(--color-text-dark)]"><span class="material-symbols-rounded text-[var(--color-accent)]">layers</span> สัดส่วนระดับสมาชิก</h3>
      </div>
      <div class="tier-bars">
        <div v-for="tier in tierList" :key="tier.key" class="tier-bar-row">
          <span class="material-symbols-rounded tier-icon" :class="tier.colorClass">{{ tier.icon }}</span>
          <span class="tier-name">{{ tier.label }}</span>
          <div class="tier-track">
            <div
              class="tier-fill"
              :class="tier.cls"
              :style="{ width: getTierPercent(tier.key) + '%' }"></div>
          </div>
          <span class="tier-count">{{ loyaltyStats.tier_counts?.[tier.key] ?? 0 }}</span>
        </div>
      </div>
    </div>

    <!-- Rewards Table -->
    <div class="table-card">
      <div class="card-header border-b border-[var(--color-sand-dark)] pb-4 mb-4">
        <h3 class="flex items-center gap-2 m-0 text-[15px] font-semibold text-[var(--color-text-dark)]"><span class="material-symbols-rounded text-[var(--color-accent)]">card_giftcard</span> ของรางวัลทั้งหมด</h3>
      </div>

      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

      <template v-else>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>ชื่อ</th>
                <th>ประเภท</th>
                <th>แต้มที่ต้องใช้</th>
                <th>มูลค่า</th>
                <th>สต็อก</th>
                <th>แลกแล้ว</th>
                <th>สถานะ</th>
                <th>จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rewards" :key="r.id">
                <td>
                  <strong class="text-[var(--color-text-dark)]">{{ r.name }}</strong>
                  <p v-if="r.description" class="text-[11px] text-[var(--color-text-muted)] mt-1 mb-0">{{ r.description }}</p>
                </td>
                <td><span class="type-badge" :class="`type-${r.type}`">{{ typeLabels[r.type] }}</span></td>
                <td><strong>{{ r.points_required?.toLocaleString() }}</strong></td>
                <td>{{ rewardValue(r) }}</td>
                <td>{{ r.stock !== null ? r.stock : '∞' }}</td>
                <td>{{ r.redemptions_count }}</td>
                <td>
                  <span class="status-badge" :class="r.is_active ? 'status-confirmed' : 'status-cancelled'">
                    {{ r.is_active ? 'เปิดใช้' : 'ปิดใช้' }}
                  </span>
                </td>
                <td>
                  <div class="action-btns">
                    <button class="btn-icon btn-edit" @click="openEdit(r)" title="แก้ไข">
                      <span class="material-symbols-rounded" style="font-size:16px;">edit</span>
                    </button>
                    <button class="btn-icon btn-delete" @click="deleteReward(r.id)" title="ลบ">
                      <span class="material-symbols-rounded" style="font-size:16px;">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="rewards.length === 0">
                <td colspan="8" class="empty-state">ยังไม่มีของรางวัล</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="modal-overlay">
      <div class="modal-box modal-card">
        <div class="modal-header">
          <h3>{{ editingId ? 'แก้ไขของรางวัล' : 'เพิ่มของรางวัล' }}</h3>
          <button class="modal-close" @click="showModal = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อ *</label>
              <input v-model="form.name" type="text" class="form-input" />
            </div>
            <div class="form-group full-width">
              <label>คำอธิบาย</label>
              <textarea v-model="form.description" rows="2" class="form-input"></textarea>
            </div>
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" class="form-input">
                <option value="discount_fixed">ส่วนลดคงที่ (บาท)</option>
                <option value="discount_percent">ส่วนลด %</option>
                <option value="free_rental">เช่าอุปกรณ์ฟรี</option>
              </select>
            </div>
            <div class="form-group">
              <label>แต้มที่ต้องใช้ *</label>
              <input v-model.number="form.points_required" type="number" min="1" class="form-input" />
              <p class="field-hint">ลูกค้าได้ราว 35 แต้มต่อทริป — ตั้งสูงเกินไปจะไม่มีใครแลกได้</p>
            </div>
            <div class="form-group">
              <label>{{ valueFieldLabel }}</label>
              <input v-model.number="form.discount_value" type="number" min="0" step="0.01" class="form-input" />
              <p class="field-hint">{{ valueFieldHint }}</p>
            </div>
            <div class="form-group">
              <label>สต็อก (ว่างไว้ = ไม่จำกัด)</label>
              <input v-model.number="form.stock" type="number" min="0" class="form-input" placeholder="ไม่จำกัด" />
            </div>
            <div class="form-group full-width">
              <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" v-model="form.is_active" style="width:16px; height:16px;" />
                เปิดใช้งาน
              </label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="saveReward" :disabled="saving">
            <span class="material-symbols-rounded animate-spin" v-if="saving">sync</span>
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../lib/axios';

const rewards = ref([]);
const loyaltyStats = ref(null);
const loading = ref(false);
const showModal = ref(false);
const saving = ref(false);
const editingId = ref(null);

const form = reactive({
  name: '', description: '', type: 'discount_fixed',
  points_required: 100, discount_value: null, stock: null, is_active: true,
});

const typeLabels = {
  discount_percent: 'ส่วนลด %',
  discount_fixed: 'ส่วนลดคงที่',
  free_rental: 'เช่าอุปกรณ์ฟรี',
  // ของรางวัลที่ต้องส่งมอบของยังไม่มี flow รองรับ — เหลือไว้อ่านข้อมูลเก่าเท่านั้น
  free_item: 'ของรางวัล (ยังใช้ไม่ได้)',
};

const tierList = [
  { key: 'regular', label: 'Regular', icon: 'local_florist', colorClass: 'tier-color-regular', cls: 'fill-regular' },
  { key: 'silver',  label: 'Silver',  icon: 'military_tech', colorClass: 'tier-color-silver', cls: 'fill-silver' },
  { key: 'gold',    label: 'Gold',    icon: 'emoji_events', colorClass: 'tier-color-gold', cls: 'fill-gold' },
];

function getTierPercent(key) {
  if (!loyaltyStats.value) return 0;
  const total = loyaltyStats.value.total_accounts || 1;
  return Math.round((loyaltyStats.value.tier_counts?.[key] ?? 0) / total * 100);
}

function rewardValue(r) {
  if (r.type === 'discount_percent') return r.discount_value ? `${r.discount_value}%` : '-';
  if (r.type === 'discount_fixed') return r.discount_value ? `฿${Number(r.discount_value).toLocaleString()}` : '-';
  if (r.type === 'free_rental') {
    return r.discount_value ? `ค่าเช่าไม่เกิน ฿${Number(r.discount_value).toLocaleString()}` : 'ค่าเช่าทั้งหมด';
  }
  return '-';
}

/** ช่อง "มูลค่า" มีความหมายต่างกันตามประเภท — บอกให้ชัดว่ากรอกอะไรอยู่ */
const valueFieldLabel = computed(() => ({
  discount_percent: 'ส่วนลด (%)',
  free_rental: 'เพดานค่าเช่าที่ยกเว้น (บาท)',
}[form.type] || 'มูลค่าส่วนลด (บาท)'));

const valueFieldHint = computed(() => ({
  discount_percent: 'คิดเป็นเปอร์เซ็นต์ของยอดจองทั้งใบ',
  free_rental: 'หักเฉพาะค่าเช่าอุปกรณ์ ไม่ลดค่าทริป — ใส่ 0 = ฟรีทั้งหมด',
}[form.type] || 'หักออกจากยอดจองตรง ๆ'));

async function loadData() {
  loading.value = true;
  try {
    const [rewardsRes, statsRes] = await Promise.all([
      api.get('/admin/loyalty/rewards'),
      api.get('/admin/loyalty/stats'),
    ]);
    rewards.value = rewardsRes.data.data;
    loyaltyStats.value = statsRes.data.data;
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editingId.value = null;
  Object.assign(form, { name: '', description: '', type: 'discount_fixed', points_required: 100, discount_value: null, stock: null, is_active: true });
  showModal.value = true;
}

function openEdit(r) {
  editingId.value = r.id;
  Object.assign(form, {
    name: r.name, description: r.description || '', type: r.type,
    points_required: r.points_required, discount_value: r.discount_value,
    stock: r.stock, is_active: r.is_active,
  });
  showModal.value = true;
}

async function saveReward() {
  if (!form.name || !form.points_required) return;
  saving.value = true;
  try {
    const payload = { ...form };
    if (!payload.stock && payload.stock !== 0) payload.stock = null;
    if (editingId.value) {
      await api.put(`/admin/loyalty/rewards/${editingId.value}`, payload);
    } else {
      await api.post('/admin/loyalty/rewards', payload);
    }
    showModal.value = false;
    await loadData();
  } catch (e) {
    alert(e?.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function deleteReward(id) {
  if (!confirm('ต้องการลบของรางวัลนี้หรือไม่?')) return;
  try {
    await api.delete(`/admin/loyalty/rewards/${id}`);
    await loadData();
  } catch {
    alert('ลบไม่สำเร็จ');
  }
}

onMounted(loadData);
</script>

<style scoped>
@import url('./admin-shared.css');

.mb-20 { margin-bottom: 20px; }

/* ─── Stats Grid ─── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
}

.stat-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 16px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
}

.stat-icon {
  width: 52px;
  height: 52px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat-icon .material-symbols-rounded {
  font-size: 28px;
}

.stat-content {
  display: flex;
  flex-direction: column;
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-text-dark);
  line-height: 1.2;
}

.stat-label {
  font-size: 13px;
  color: var(--color-text-muted);
  margin-top: 4px;
}

.bg-blue-50 { background-color: #eff6ff; }
.text-blue-600 { color: #2563eb; }

.bg-green-50 { background-color: #f0fdf4; }
.text-green-600 { color: #16a34a; }

.bg-purple-50 { background-color: #faf5ff; }
.text-purple-600 { color: #9333ea; }

.bg-amber-50 { background-color: #fffbeb; }
.text-amber-600 { color: #d97706; }

/* ─── Tier Bars ─── */
.tier-bars {
  padding: 0 20px 20px 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.tier-bar-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.tier-icon { 
  font-size: 20px; 
  width: 24px; 
  text-align: center;
}

.tier-color-regular { color: var(--color-accent); }
.tier-color-silver { color: #9ca3af; }
.tier-color-gold { color: #d97706; }

.tier-name {
  width: 65px;
  font-size: 13px;
  color: var(--color-text-mid);
  font-weight: 600;
}

.tier-track {
  flex: 1;
  height: 8px;
  background: var(--color-sand-dark);
  border-radius: 4px;
  overflow: hidden;
}

.tier-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.6s ease;
}

.fill-regular { background: var(--color-accent); opacity: 0.9; }
.fill-silver  { background: #9ca3af; }
.fill-gold    { background: #f59e0b; }

.tier-count {
  width: 30px;
  text-align: right;
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-mid);
}

/* ─── Type Badge ─── */
.type-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
}

.type-discount_percent { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.type-discount_fixed   { background: #fefce8; color: #a16207; border: 1px solid #fef08a; }
.type-free_rental      { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.type-free_item        { background: #faf5ff; color: #9333ea; border: 1px solid #e9d5ff; }

.field-hint { font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.4; }

/* ─── Shared ─── */
.table-card {
  padding-top: 16px;
}

.form-input {
  width: 100%;
  padding: 9px 13px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  color: var(--color-text-dark);
  font-size: 14px;
  outline: none;
  transition: border-color 0.15s;
  font-family: inherit;
}

.form-input:focus {
  border-color: var(--color-accent);
}
</style>
