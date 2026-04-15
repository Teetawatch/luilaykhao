<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">qr_code_scanner</span> เช็คอิน QR Code</h1>
        <p class="page-subtitle">สแกน QR Code หรือค้นหารหัสจองเพื่อเช็คอิน</p>
      </div>
    </div>

    <!-- Check-in Input -->
    <div class="checkin-card">
      <div class="checkin-tabs">
        <button :class="{ active: mode === 'qr' }" @click="mode = 'qr'">
          <span class="material-symbols-rounded">qr_code_scanner</span> สแกน QR Code
        </button>
        <button :class="{ active: mode === 'ref' }" @click="mode = 'ref'">
          <span class="material-symbols-rounded">search</span> ค้นหารหัสจอง
        </button>
      </div>

      <div class="checkin-input-area">
        <div v-if="mode === 'qr'" class="qr-scan-area">
          <div class="qr-icon-wrapper">
            <span class="material-symbols-rounded text-accent" style="font-size:32px; color:var(--color-accent);">photo_camera</span>
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
              <span class="material-symbols-rounded animate-spin" v-if="processing">sync</span>
              <span class="material-symbols-rounded" v-else>check</span>
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
              <span class="material-symbols-rounded animate-spin" v-if="processing">sync</span>
              <span class="material-symbols-rounded" v-else>check</span>
              เช็คอิน
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Result -->
    <div class="result-card success" v-if="result && result.success">
      <div class="result-icon success-icon">
        <span class="material-symbols-rounded" style="font-size:32px;">check_circle</span>
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
      <button class="btn-secondary" @click="resetResult"><span class="material-symbols-rounded" style="font-size:18px;">refresh</span> เช็คอินคนถัดไป</button>
    </div>

    <div class="result-card error" v-if="result && !result.success">
      <div class="result-icon error-icon">
        <span class="material-symbols-rounded" style="font-size:32px;">cancel</span>
      </div>
      <h2>ไม่สามารถเช็คอินได้</h2>
      <p class="error-msg">{{ result.message }}</p>
      <button class="btn-secondary" @click="resetResult"><span class="material-symbols-rounded" style="font-size:18px;">refresh</span> ลองใหม่</button>
    </div>

    <!-- Recent Check-ins -->
    <div class="recent-checkins" v-if="recentCheckins.length">
      <h3><span class="material-symbols-rounded" style="color:var(--color-accent); margin-right:8px;">history</span> เช็คอินล่าสุด</h3>
      <div class="checkin-list">
        <div v-for="c in recentCheckins" :key="c.booking_ref" class="checkin-item">
          <div class="ci-icon"><span class="material-symbols-rounded" style="font-size:16px;">check</span></div>
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
  background: var(--color-white);
  border-radius: 16px;
  border: 1px solid var(--color-sand-dark);
  overflow: hidden;
  margin-bottom: 24px;
  box-shadow: 0 4px 6px rgba(0,0,0,0.02);
}

.checkin-tabs {
  display: flex;
  border-bottom: 1px solid var(--color-sand-dark);
}

.checkin-tabs button {
  flex: 1;
  padding: 14px;
  border: none;
  background: transparent;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-muted);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.15s;
}

.checkin-tabs button:hover { background: var(--color-sand); }

.checkin-tabs button.active {
  color: var(--color-accent);
  font-weight: 600;
  border-bottom: 2px solid var(--color-accent);
  background: var(--color-white);
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
  background: var(--color-sand);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
}

.qr-instruction {
  color: var(--color-text-muted);
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
  border: 2px solid var(--color-sand-dark);
  border-radius: 10px;
  font-size: 16px;
  color: var(--color-text-dark);
  outline: none;
  transition: border-color 0.15s;
  font-family: monospace;
}

.checkin-input:focus {
  border-color: var(--color-accent);
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
  border-radius: 16px;
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
  margin-bottom: 12px;
}

.success-icon { background: #dcfce7; color: #16a34a; }
.error-icon { background: #fee2e2; color: #dc2626; }

.result-card h2 {
  font-size: 20px;
  margin: 0 0 16px;
  color: var(--color-text-dark);
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
  color: var(--color-text-muted);
  font-weight: 500;
}

.error-msg {
  color: #dc2626;
  font-size: 15px;
  margin: 0 0 16px;
}

/* Recent */
.recent-checkins {
  background: var(--color-white);
  border-radius: 16px;
  border: 1px solid var(--color-sand-dark);
  overflow: hidden;
  box-shadow: 0 4px 6px rgba(0,0,0,0.02);
}

.recent-checkins h3 {
  padding: 14px 20px;
  border-bottom: 1px solid var(--color-sand-dark);
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-dark);
  display: flex;
  align-items: center;
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
  border-bottom: 1px solid var(--color-sand-dark);
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
  flex-shrink: 0;
}

.ci-info {
  flex: 1;
}

.ci-ref {
  display: block;
  font-family: monospace;
  font-size: 13px;
  color: var(--color-accent);
  font-weight: 700;
}

.ci-name {
  font-size: 12px;
  color: var(--color-text-muted);
}

.ci-time {
  font-size: 12px;
  color: var(--color-text-muted);
  white-space: nowrap;
}
</style>
