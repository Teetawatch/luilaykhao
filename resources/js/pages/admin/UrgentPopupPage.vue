<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">local_fire_department</span>
          ป๊อปอัพทริปด่วน
        </h1>
        <p class="page-subtitle">ป๊อปอัพเด้งตอนลูกค้าเข้าเว็บ รวมทริป Flash Sale และทริปที่นั่งใกล้เต็ม</p>
      </div>
      <button class="btn-primary" :disabled="saving || loading" @click="save">
        <span class="material-symbols-rounded">save</span>
        {{ saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
      </button>
    </div>

    <div class="table-card" style="padding:24px;">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

      <div v-else class="popup-settings">
        <!-- master switch -->
        <label class="switch-row" :class="{ 'switch-on': form.enabled }">
          <div>
            <p class="switch-title">เปิดใช้งานป๊อปอัพ</p>
            <p class="switch-desc">ปิดสวิตช์นี้เพื่อซ่อนป๊อปอัพจากหน้าเว็บทั้งหมดทันที</p>
          </div>
          <input type="checkbox" v-model="form.enabled" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </label>

        <label class="switch-row" :class="{ 'switch-on': form.show_flash_sale }">
          <div>
            <p class="switch-title">แสดงทริป Flash Sale</p>
            <p class="switch-desc">ทริปที่มีรอบลดราคาแบบจับเวลา พร้อมนับถอยหลังในป๊อปอัพ</p>
          </div>
          <input type="checkbox" v-model="form.show_flash_sale" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </label>

        <label class="switch-row" :class="{ 'switch-on': form.show_almost_full }">
          <div>
            <p class="switch-title">แสดงทริปที่นั่งใกล้เต็ม</p>
            <p class="switch-desc">ทริปที่มีรอบเปิดขายเหลือที่นั่งไม่เกินเกณฑ์ด้านล่าง</p>
          </div>
          <input type="checkbox" v-model="form.show_almost_full" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </label>

        <div class="form-grid" style="margin-top:16px;">
          <div class="form-group">
            <label>เกณฑ์ที่นั่งใกล้เต็ม (เหลือไม่เกิน N ที่)</label>
            <input type="number" min="1" max="20" v-model.number="form.seat_threshold" />
          </div>
          <div class="form-group">
            <label>หัวข้อป๊อปอัพ (เว้นว่าง = ใช้ข้อความมาตรฐาน)</label>
            <input type="text" maxlength="120" v-model.trim="form.title"
              placeholder="ทริปฮอต กำลังจะเต็ม รีบจองด่วน!" />
          </div>
        </div>
      </div>
    </div>

    <!-- live preview of what customers currently match -->
    <div class="table-card" style="padding:24px;margin-top:20px;">
      <h2 class="preview-heading">
        <span class="material-symbols-rounded" style="font-size:18px;color:#f97316;">visibility</span>
        ทริปที่จะแสดงตอนนี้
      </h2>
      <div class="loading-state" v-if="previewLoading"><div class="spinner"></div></div>
      <template v-else>
        <p v-if="!preview.enabled" class="preview-empty">ป๊อปอัพถูกปิดอยู่ — ลูกค้าจะไม่เห็นป๊อปอัพ</p>
        <p v-else-if="!previewTrips.length" class="preview-empty">
          ยังไม่มีทริปเข้าเงื่อนไข (ไม่มี Flash Sale ที่กำลังลด และไม่มีรอบที่นั่งเหลือ ≤ {{ form.seat_threshold }})
          — ป๊อปอัพจะไม่เด้ง
        </p>
        <div v-else class="preview-list">
          <div v-for="row in previewTrips" :key="`${row.kind}-${row.trip.id}`" class="preview-row">
            <img v-if="row.trip.thumbnail_image || row.trip.cover_image"
              :src="row.trip.thumbnail_image || row.trip.cover_image" class="preview-thumb" />
            <div v-else class="preview-thumb preview-thumb-empty"></div>
            <div style="flex:1;min-width:0;">
              <p class="preview-title">{{ row.trip.title }}</p>
              <span class="status-pill" :class="row.kind === 'flash' ? 'pill-flash' : 'pill-seats'">
                {{ row.kind === 'flash' ? '⚡ Flash Sale' : `เหลือ ${row.trip.seats_left} ที่` }}
              </span>
            </div>
            <p class="preview-price">฿{{ Number(row.trip.min_price || 0).toLocaleString('th-TH') }}</p>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../../js/lib/axios';
import { useToast } from '../../lib/toast';

const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const previewLoading = ref(true);
const preview = ref({ enabled: false, flash_sale: [], almost_full: [] });
const previewTrips = ref([]);

const form = reactive({
  enabled: true,
  show_flash_sale: true,
  show_almost_full: true,
  seat_threshold: 5,
  title: '',
});

async function fetchSettings() {
  loading.value = true;
  try {
    const res = await api.get('/admin/settings/urgent-popup');
    const s = res.data.data;
    form.enabled = !!s.enabled;
    form.show_flash_sale = !!s.show_flash_sale;
    form.show_almost_full = !!s.show_almost_full;
    form.seat_threshold = s.seat_threshold ?? 5;
    form.title = s.title || '';
  } finally {
    loading.value = false;
  }
}

async function fetchPreview() {
  previewLoading.value = true;
  try {
    const res = await api.get('/trips/urgent-popup');
    preview.value = res.data.data;
    previewTrips.value = [
      ...(preview.value.flash_sale || []).map((trip) => ({ kind: 'flash', trip })),
      ...(preview.value.almost_full || []).map((trip) => ({ kind: 'almost', trip })),
    ];
  } catch {
    previewTrips.value = [];
  } finally {
    previewLoading.value = false;
  }
}

async function save() {
  if (!form.seat_threshold || form.seat_threshold < 1 || form.seat_threshold > 20) {
    toast.error('เกณฑ์ที่นั่งต้องอยู่ระหว่าง 1–20');
    return;
  }
  saving.value = true;
  try {
    await api.put('/admin/settings/urgent-popup', {
      enabled: form.enabled,
      show_flash_sale: form.show_flash_sale,
      show_almost_full: form.show_almost_full,
      seat_threshold: form.seat_threshold,
      title: form.title || null,
    });
    toast.success('บันทึกการตั้งค่าป๊อปอัพแล้ว');
    fetchPreview();
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  fetchSettings();
  fetchPreview();
});
</script>

<style scoped>
.popup-settings {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-width: 720px;
}
.switch-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 16px;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.switch-row.switch-on {
  border-color: #fdba74;
  background: #fff7ed;
}
.switch-title {
  font-weight: 700;
  font-size: 14px;
  color: #111827;
}
.switch-desc {
  font-size: 12px;
  color: #6b7280;
  margin-top: 2px;
}
.switch-row input {
  display: none;
}
.switch-track {
  width: 44px;
  height: 24px;
  border-radius: 999px;
  background: #d1d5db;
  position: relative;
  flex-shrink: 0;
  transition: background 0.2s ease;
}
.switch-row.switch-on .switch-track {
  background: #f97316;
}
.switch-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
  transition: transform 0.2s ease;
}
.switch-row.switch-on .switch-thumb {
  transform: translateX(20px);
}
.preview-heading {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 700;
  color: #374151;
  margin-bottom: 14px;
}
.preview-empty {
  font-size: 13px;
  color: #6b7280;
  padding: 8px 0;
}
.preview-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-width: 720px;
}
.preview-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid #f3f4f6;
  border-radius: 12px;
}
.preview-thumb {
  width: 52px;
  height: 52px;
  border-radius: 10px;
  object-fit: cover;
  flex-shrink: 0;
}
.preview-thumb-empty {
  background: #f3f4f6;
}
.preview-title {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 4px;
}
.preview-price {
  font-size: 13px;
  font-weight: 800;
  color: #111827;
  flex-shrink: 0;
}
.pill-flash {
  background: #fee2e2;
  color: #b91c1c;
}
.pill-seats {
  background: #fef3c7;
  color: #92400e;
}
</style>
