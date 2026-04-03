<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-qrcode"></i> เช็คอิน QR Code</h1>
        <p class="page-subtitle">สแกน QR Code หรือค้นหารหัสจองเพื่อเช็คอิน</p>
      </div>
    </div>

    <!-- Check-in Input -->
    <div class="checkin-card">
      <div class="checkin-tabs">
        <button :class="{ active: mode === 'qr' }" @click="mode = 'qr'">
          <i class="fas fa-qrcode"></i> สแกน QR Code
        </button>
        <button :class="{ active: mode === 'ref' }" @click="mode = 'ref'">
          <i class="fas fa-search"></i> ค้นหารหัสจอง
        </button>
      </div>

      <div class="checkin-input-area">
        <div v-if="mode === 'qr'" class="qr-scan-area">
          <div class="qr-icon-wrapper">
            <i class="fas fa-camera"></i>
          </div>
          <p class="qr-instruction">วาง QR Code หน้ากล้อง หรือพิมพ์รหัส QR ด้านล่าง</p>
          <div class="input-group">
            <input
              v-model="qrInput"
              placeholder="พิมพ์รหัส QR Code เช่น QR-ABCDEF1234567890"
              @keyup.enter="doCheckInQr"
              class="checkin-input"
            />
            <button class="btn-primary btn-checkin" @click="doCheckInQr" :disabled="!qrInput || processing">
              <i class="fas fa-spinner fa-spin" v-if="processing"></i>
              <i class="fas fa-check" v-else></i>
              เช็คอิน
            </button>
          </div>
        </div>

        <div v-else class="ref-search-area">
          <div class="input-group">
            <input
              v-model="refInput"
              placeholder="พิมพ์รหัสจอง เช่น TRD-20260330-0001"
              @keyup.enter="doCheckInRef"
              class="checkin-input"
            />
            <button class="btn-primary btn-checkin" @click="doCheckInRef" :disabled="!refInput || processing">
              <i class="fas fa-spinner fa-spin" v-if="processing"></i>
              <i class="fas fa-check" v-else></i>
              เช็คอิน
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Result -->
    <div class="result-card success" v-if="result && result.success">
      <div class="result-icon success-icon">
        <i class="fas fa-check-circle"></i>
      </div>
      <h2>เช็คอินสำเร็จ!</h2>
      <div class="result-details">
        <div class="rd-row">
          <span class="rd-label">รหัสจอง</span>
          <span class="booking-ref">{{ result.booking.booking_ref }}</span>
        </div>
        <div class="rd-row">
          <span class="rd-label">ลูกค้า</span>
          <span>{{ result.booking.user?.name }}</span>
        </div>
        <div class="rd-row">
          <span class="rd-label">ทริป</span>
          <span>{{ result.booking.schedule?.trip?.title }}</span>
        </div>
        <div class="rd-row">
          <span class="rd-label">ผู้โดยสาร</span>
          <span>{{ result.booking.passengers?.length || 0 }} คน</span>
        </div>
        <div class="rd-row">
          <span class="rd-label">เช็คอินเมื่อ</span>
          <span>{{ formatDateTime(result.booking.checked_in_at) }}</span>
        </div>
      </div>
      <button class="btn-secondary" @click="resetResult"><i class="fas fa-redo"></i> เช็คอินคนถัดไป</button>
    </div>

    <div class="result-card error" v-if="result && !result.success">
      <div class="result-icon error-icon">
        <i class="fas fa-times-circle"></i>
      </div>
      <h2>ไม่สามารถเช็คอินได้</h2>
      <p class="error-msg">{{ result.message }}</p>
      <button class="btn-secondary" @click="resetResult"><i class="fas fa-redo"></i> ลองใหม่</button>
    </div>

    <!-- Recent Check-ins -->
    <div class="recent-checkins" v-if="recentCheckins.length">
      <h3><i class="fas fa-history"></i> เช็คอินล่าสุด</h3>
      <div class="checkin-list">
        <div v-for="c in recentCheckins" :key="c.booking_ref" class="checkin-item">
          <div class="ci-icon"><i class="fas fa-check"></i></div>
          <div class="ci-info">
            <span class="ci-ref">{{ c.booking_ref }}</span>
            <span class="ci-name">{{ c.user?.name }} — {{ c.schedule?.trip?.title }}</span>
          </div>
          <span class="ci-time">{{ formatTime(c.checked_in_at) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();
const mode = ref('qr');
const qrInput = ref('');
const refInput = ref('');
const processing = ref(false);
const result = ref(null);
const recentCheckins = ref([]);

function formatDateTime(d) {
  if (!d) return '-';
  return new Date(d).toLocaleString('th-TH');
}

function formatTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

async function doCheckInQr() {
  if (!qrInput.value || processing.value) return;
  processing.value = true;
  result.value = null;
  try {
    const res = await admin.checkInByQr(qrInput.value.trim());
    result.value = { success: true, booking: res.data };
    recentCheckins.value.unshift(res.data);
    if (recentCheckins.value.length > 10) recentCheckins.value.pop();
    qrInput.value = '';
  } catch (e) {
    result.value = { success: false, message: e.response?.data?.message || 'เกิดข้อผิดพลาด' };
  } finally {
    processing.value = false;
  }
}

async function doCheckInRef() {
  if (!refInput.value || processing.value) return;
  processing.value = true;
  result.value = null;
  try {
    const res = await admin.checkInByRef(refInput.value.trim());
    result.value = { success: true, booking: res.data };
    recentCheckins.value.unshift(res.data);
    if (recentCheckins.value.length > 10) recentCheckins.value.pop();
    refInput.value = '';
  } catch (e) {
    result.value = { success: false, message: e.response?.data?.message || 'เกิดข้อผิดพลาด' };
  } finally {
    processing.value = false;
  }
}

function resetResult() {
  result.value = null;
}
</script>

<style scoped>
@import url('./admin-shared.css');

.checkin-card {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  overflow: hidden;
  margin-bottom: 24px;
}

.checkin-tabs {
  display: flex;
  border-bottom: 1px solid #e5e7eb;
}

.checkin-tabs button {
  flex: 1;
  padding: 14px;
  border: none;
  background: transparent;
  font-size: 14px;
  font-weight: 500;
  color: #6b7280;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.15s;
}

.checkin-tabs button:hover { background: #FAFAFA; }

.checkin-tabs button.active {
  color: #2d7a4f;
  font-weight: 600;
  border-bottom: 2px solid #2d7a4f;
  background: #f0faf4;
}

.checkin-input-area {
  padding: 30px;
}

.qr-scan-area {
  text-align: center;
}

.qr-icon-wrapper {
  width: 80px;
  height: 80px;
  border-radius: 20px;
  background: #f0faf4;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
}

.qr-icon-wrapper i {
  font-size: 32px;
  color: #2d7a4f;
}

.qr-instruction {
  color: #6b7280;
  font-size: 14px;
  margin-bottom: 20px;
}

.input-group {
  display: flex;
  gap: 10px;
  max-width: 600px;
  margin: 0 auto;
}

.checkin-input {
  flex: 1;
  padding: 12px 16px;
  border: 2px solid #d1d5db;
  border-radius: 10px;
  font-size: 16px;
  color: #111827;
  outline: none;
  transition: border-color 0.15s;
  font-family: monospace;
}

.checkin-input:focus {
  border-color: #2d7a4f;
  box-shadow: 0 0 0 4px rgba(45, 122, 79, 0.1);
}

.btn-checkin {
  padding: 12px 24px;
  font-size: 15px;
  white-space: nowrap;
}

/* Results */
.result-card {
  text-align: center;
  padding: 30px;
  border-radius: 12px;
  margin-bottom: 24px;
}

.result-card.success {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
}

.result-card.error {
  background: #fef2f2;
  border: 1px solid #fecaca;
}

.result-icon {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
  margin-bottom: 12px;
}

.success-icon { background: #dcfce7; color: #16a34a; }
.error-icon { background: #fee2e2; color: #dc2626; }

.result-card h2 {
  font-size: 20px;
  margin: 0 0 16px;
  color: #111827;
}

.result-details {
  display: inline-block;
  text-align: left;
  margin-bottom: 20px;
}

.rd-row {
  display: flex;
  gap: 16px;
  padding: 6px 0;
  font-size: 14px;
}

.rd-label {
  width: 100px;
  color: #6b7280;
  font-weight: 500;
}

.error-msg {
  color: #dc2626;
  font-size: 15px;
  margin: 0 0 16px;
}

/* Recent */
.recent-checkins {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  overflow: hidden;
}

.recent-checkins h3 {
  padding: 14px 20px;
  border-bottom: 1px solid #f3f4f6;
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #111827;
  display: flex;
  align-items: center;
  gap: 8px;
}

.recent-checkins h3 i {
  color: #2d7a4f;
}

.checkin-list {
  max-height: 350px;
  overflow-y: auto;
}

.checkin-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 20px;
  border-bottom: 1px solid #f3f4f6;
}

.checkin-item:last-child { border-bottom: none; }

.ci-icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #dcfce7;
  color: #16a34a;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  flex-shrink: 0;
}

.ci-info {
  flex: 1;
}

.ci-ref {
  display: block;
  font-family: monospace;
  font-size: 13px;
  color: #2d7a4f;
  font-weight: 700;
}

.ci-name {
  font-size: 12px;
  color: #6b7280;
}

.ci-time {
  font-size: 12px;
  color: #9ca3af;
  white-space: nowrap;
}
</style>
