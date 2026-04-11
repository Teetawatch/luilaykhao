<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-star"></i> ระบบสะสมแต้ม</h1>
        <p class="page-subtitle">จัดการของรางวัลและดูสถิติโปรแกรมสมาชิก</p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <i class="fas fa-plus"></i> เพิ่มของรางวัล
      </button>
    </div>

    <!-- Stats -->
    <div class="stats-grid mb-20" v-if="loyaltyStats">
      <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.total_accounts?.toLocaleString() }}</span>
          <span class="stat-label">สมาชิกทั้งหมด</span>
        </div>
      </div>
      <div class="stat-card stat-success">
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.total_points_issued?.toLocaleString() }}</span>
          <span class="stat-label">แต้มที่ออกทั้งหมด</span>
        </div>
      </div>
      <div class="stat-card stat-revenue">
        <div class="stat-icon"><i class="fas fa-gift"></i></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.total_points_redeemed?.toLocaleString() }}</span>
          <span class="stat-label">แต้มที่แลกไปแล้ว</span>
        </div>
      </div>
      <div class="stat-card stat-info">
        <div class="stat-icon"><i class="fas fa-medal"></i></div>
        <div class="stat-content">
          <span class="stat-value">{{ loyaltyStats.tier_counts?.gold }}</span>
          <span class="stat-label">Gold / {{ loyaltyStats.tier_counts?.silver }} Silver</span>
        </div>
      </div>
    </div>

    <!-- Tier Overview -->
    <div class="table-card mb-20" v-if="loyaltyStats">
      <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> สัดส่วนระดับสมาชิก</h3>
      </div>
      <div class="tier-bars">
        <div v-for="tier in tierList" :key="tier.key" class="tier-bar-row">
          <div class="tier-icon">{{ tier.emoji }}</div>
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
      <div class="card-header">
        <h3><i class="fas fa-gift"></i> ของรางวัลทั้งหมด</h3>
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
                  <strong>{{ r.name }}</strong>
                  <p v-if="r.description" class="text-muted-small">{{ r.description }}</p>
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
                  <div class="action-group">
                    <button class="btn-icon" @click="openEdit(r)" title="แก้ไข">
                      <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-icon btn-danger" @click="deleteReward(r.id)" title="ลบ">
                      <i class="fas fa-trash"></i>
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
      <div class="modal-box">
        <div class="modal-header">
          <h3>{{ editingId ? 'แก้ไขของรางวัล' : 'เพิ่มของรางวัล' }}</h3>
          <button class="modal-close" @click="showModal = false">×</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>ชื่อ *</label>
            <input v-model="form.name" type="text" class="form-input" />
          </div>
          <div class="form-group">
            <label>คำอธิบาย</label>
            <textarea v-model="form.description" rows="2" class="form-input"></textarea>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" class="form-input">
                <option value="discount_percent">ส่วนลด %</option>
                <option value="discount_fixed">ส่วนลดคงที่ (บาท)</option>
                <option value="free_item">ของรางวัลพิเศษ</option>
              </select>
            </div>
            <div class="form-group">
              <label>แต้มที่ต้องใช้ *</label>
              <input v-model.number="form.points_required" type="number" min="1" class="form-input" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>มูลค่าส่วนลด</label>
              <input v-model.number="form.discount_value" type="number" min="0" step="0.01" class="form-input" />
            </div>
            <div class="form-group">
              <label>สต็อก (ว่างไว้ = ไม่จำกัด)</label>
              <input v-model.number="form.stock" type="number" min="0" class="form-input" placeholder="ไม่จำกัด" />
            </div>
          </div>
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" v-model="form.is_active" />
              เปิดใช้งาน
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-primary" @click="saveReward" :disabled="saving">
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
          <button class="btn-secondary" @click="showModal = false">ยกเลิก</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../lib/axios';

const rewards = ref([]);
const loyaltyStats = ref(null);
const loading = ref(false);
const showModal = ref(false);
const saving = ref(false);
const editingId = ref(null);

const form = reactive({
  name: '', description: '', type: 'discount_percent',
  points_required: 500, discount_value: null, stock: null, is_active: true,
});

const typeLabels = {
  discount_percent: 'ส่วนลด %',
  discount_fixed: 'ส่วนลดคงที่',
  free_item: 'ของรางวัล',
};

const tierList = [
  { key: 'regular', label: 'Regular', emoji: '🌿', cls: 'fill-regular' },
  { key: 'silver',  label: 'Silver',  emoji: '🥈', cls: 'fill-silver' },
  { key: 'gold',    label: 'Gold',    emoji: '🥇', cls: 'fill-gold' },
];

function getTierPercent(key) {
  if (!loyaltyStats.value) return 0;
  const total = loyaltyStats.value.total_accounts || 1;
  return Math.round((loyaltyStats.value.tier_counts?.[key] ?? 0) / total * 100);
}

function rewardValue(r) {
  if (r.type === 'discount_percent') return r.discount_value ? `${r.discount_value}%` : '-';
  if (r.type === 'discount_fixed') return r.discount_value ? `฿${Number(r.discount_value).toLocaleString()}` : '-';
  return '-';
}

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
  Object.assign(form, { name: '', description: '', type: 'discount_percent', points_required: 500, discount_value: null, stock: null, is_active: true });
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

.tier-bars {
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.tier-bar-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.tier-icon { font-size: 18px; width: 24px; text-align: center; }

.tier-name {
  width: 65px;
  font-size: 13px;
  color: #374151;
  font-weight: 500;
}

.tier-track {
  flex: 1;
  height: 8px;
  background: #f3f4f6;
  border-radius: 4px;
  overflow: hidden;
}

.tier-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.6s ease;
}

.fill-regular { background: #6b7280; }
.fill-silver  { background: #94a3b8; }
.fill-gold    { background: #d97706; }

.tier-count {
  width: 30px;
  text-align: right;
  font-size: 13px;
  font-weight: 700;
  color: #374151;
}

.type-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
}

.type-discount_percent { background: #dbeafe; color: #1d4ed8; }
.type-discount_fixed   { background: #fef9c3; color: #a16207; }
.type-free_item        { background: #ede9fe; color: #6d28d9; }

.text-muted-small {
  font-size: 11px;
  color: #9ca3af;
  margin: 2px 0 0;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
}
</style>
