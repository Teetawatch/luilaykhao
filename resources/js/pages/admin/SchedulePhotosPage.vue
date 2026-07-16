<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">photo_camera</span>
          ภาพให้ลูกค้าโหลด
        </h1>
        <p class="page-subtitle">
          อัปโหลดรูปกิจกรรมที่ถ่ายให้ลูกค้าในแต่ละรอบเดินทาง — ลูกค้าที่จองรอบนั้นจะเปิดดู/ดาวน์โหลดได้ในแอป
        </p>
      </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
      <div class="filter-field">
        <label>ทริป</label>
        <select v-model="filters.tripId" @change="fetchSchedules">
          <option value="">— ทั้งหมด —</option>
          <option v-for="t in trips" :key="t.id" :value="t.id">{{ t.title }}</option>
        </select>
      </div>
      <div class="filter-field">
        <label>ตั้งแต่วันที่</label>
        <input v-model="filters.from" type="date" @change="fetchSchedules" />
      </div>
      <div class="filter-field">
        <label>ถึงวันที่</label>
        <input v-model="filters.to" type="date" @change="fetchSchedules" />
      </div>
      <div class="filter-field" style="flex: 1; min-width: 200px;">
        <label>ค้นหา</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="ชื่อทริป / รอบเดินทาง"
          @input="onSearchInput"
        />
      </div>
    </div>

    <!-- Schedules list -->
    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div v-else>
        <div v-if="!filteredSchedules.length" class="empty-state">
          <span class="material-symbols-rounded" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">
            event_busy
          </span>
          ไม่พบรอบเดินทางที่ตรงเงื่อนไข
        </div>

        <div v-else class="schedule-list">
          <div
            v-for="sch in filteredSchedules"
            :key="sch.id"
            class="schedule-row"
          >
            <div class="schedule-info">
              <div class="schedule-trip">
                <span class="round-badge">รอบ #{{ sch.id }}</span>
                {{ sch.trip?.title || '—' }}
              </div>
              <div class="schedule-meta">
                <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px;">event</span>
                {{ formatDate(sch.departure_date) }}
                <template v-if="sch.return_date && sch.return_date !== sch.departure_date">
                  → {{ formatDate(sch.return_date) }}
                </template>
                <template v-if="sch.vehicle">
                  <span class="dot">·</span>
                  <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px;">directions_bus</span>
                  {{ sch.vehicle.name }}<template v-if="sch.vehicle.license_plate"> ({{ sch.vehicle.license_plate }})</template>
                </template>
                <span class="dot">·</span>
                <span :class="['status-pill', `pill-${sch.status}`]">{{ statusLabel(sch.status) }}</span>
                <span class="dot">·</span>
                ที่นั่ง {{ sch.booked_seats }}/{{ sch.total_seats }}
              </div>
            </div>

            <div class="schedule-photos-count">
              <span class="material-symbols-rounded">image</span>
              <span>{{ photoCounts[sch.id] ?? '—' }} รูป</span>
            </div>

            <div class="action-btns">
              <button class="btn-primary btn-sm" @click="openManager(sch)">
                <span class="material-symbols-rounded" style="font-size:16px">add_a_photo</span>
                จัดการรูป
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Photo Manager Modal -->
    <div class="modal-overlay" v-if="manager.open" @click.self="closeManager">
      <div class="modal-card" style="max-width: 960px">
        <div class="modal-header">
          <div>
            <h2>
              <span class="material-symbols-rounded heading-icon" style="font-size:22px;vertical-align:-4px;">
                photo_library
              </span>
              {{ manager.schedule?.trip?.title }}
              <span class="round-badge" v-if="manager.schedule">รอบ #{{ manager.schedule.id }}</span>
            </h2>
            <p class="modal-subtitle">
              {{ formatDate(manager.schedule?.departure_date) }}
              <template v-if="manager.schedule?.return_date && manager.schedule.return_date !== manager.schedule.departure_date">
                → {{ formatDate(manager.schedule.return_date) }}
              </template>
              <template v-if="manager.schedule?.vehicle">
                · {{ manager.schedule.vehicle.name }}<template v-if="manager.schedule.vehicle.license_plate"> ({{ manager.schedule.vehicle.license_plate }})</template>
              </template>
            </p>
          </div>
          <button class="modal-close" @click="closeManager">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <!-- Public album share link -->
          <div class="share-panel">
            <div class="share-head">
              <span class="material-symbols-rounded" style="font-size:18px;color:var(--color-accent,#2d7a4f)">link</span>
              <span style="font-weight:700;font-size:13px;">ลิงก์อัลบั้มสาธารณะ</span>
              <span class="share-hint">ใครมีลิงก์ก็เปิดดู/ดาวน์โหลดได้ ไม่ต้องล็อกอิน</span>
            </div>

            <div v-if="share.loading" style="font-size:13px;color:#6b7280;padding:4px 0">กำลังโหลด…</div>

            <template v-else-if="share.url">
              <div class="share-row">
                <input class="share-input" :value="share.url" readonly @focus="$event.target.select()" />
                <button class="btn-primary btn-sm" @click="copyShareLink">
                  <span class="material-symbols-rounded" style="font-size:16px">{{ copied ? 'check' : 'content_copy' }}</span>
                  {{ copied ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                </button>
              </div>
              <div class="share-actions">
                <button class="link-btn" @click="rotateShareLink" :disabled="share.busy">สร้างลิงก์ใหม่ (ปิดลิงก์เดิม)</button>
                <span class="dot">·</span>
                <button class="link-btn danger" @click="revokeShareLink" :disabled="share.busy">ปิดการแชร์</button>
              </div>
            </template>

            <template v-else>
              <button class="btn-secondary btn-sm" @click="enableShareLink" :disabled="share.busy">
                <span class="material-symbols-rounded" style="font-size:16px;vertical-align:-3px">add_link</span>
                สร้างลิงก์สำหรับแชร์
              </button>
            </template>
          </div>

          <!-- Drop zone -->
          <div
            class="upload-zone large"
            @click="filePicker.click()"
            @dragover.prevent
            @drop.prevent="onDrop"
          >
            <input
              ref="filePicker"
              type="file"
              accept="image/jpeg,image/jpg,image/png,image/webp,image/heic,image/heif"
              multiple
              style="display:none"
              @change="onFileSelect"
            />
            <div class="upload-placeholder">
              <span class="material-symbols-rounded" style="font-size:36px">cloud_upload</span>
              <p style="font-size:14px;font-weight:700;color:#374151;margin-top:4px">
                คลิกหรือลากรูปมาวางที่นี่ (เลือกได้หลายไฟล์)
              </p>
              <p style="font-size:12px;color:#9ca3af">
                JPG, PNG, WebP, HEIC — เลือกได้หลายร้อยรูป ระบบจะย่อและทยอยอัปให้เอง · ไฟล์ละไม่เกิน 15 MB
              </p>
            </div>
          </div>

          <!-- Upload progress -->
          <div v-if="uploading" class="upload-status">
            <div class="upload-progress">
              <div class="progress-bar" :style="{ width: uploadProgress + '%' }"></div>
            </div>
            <p style="font-size:12px;color:#6b7280;margin-top:6px">
              กำลังอัปโหลด {{ uploadDone }} / {{ uploadTotal }} รูป
              <template v-if="uploadFailed"> · ไม่สำเร็จ {{ uploadFailed }}</template>
            </p>
          </div>

          <!-- Photo grid -->
          <div v-if="manager.loadingPhotos" class="loading-state"><div class="spinner"></div></div>
          <div v-else-if="!manager.photos.length" class="empty-state" style="padding:32px 0">
            ยังไม่มีรูปในรอบนี้ — อัปโหลดรูปแรกได้เลย
          </div>
          <div v-else class="photo-grid">
            <div
              v-for="photo in manager.photos"
              :key="photo.id"
              class="photo-tile"
            >
              <img :src="photo.thumb_url || photo.url" :alt="`photo-${photo.id}`" loading="lazy" />
              <button
                class="photo-delete"
                :disabled="deletingId === photo.id"
                @click="confirmDeletePhoto(photo)"
                :title="'ลบรูปนี้'"
              >
                <span class="material-symbols-rounded" style="font-size:16px">
                  {{ deletingId === photo.id ? 'sync' : 'delete' }}
                </span>
              </button>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <span style="font-size:13px;color:#6b7280">
            ทั้งหมด {{ manager.photos.length }} รูป
          </span>
          <div style="display:flex;gap:8px;">
            <button
              class="btn-danger"
              :disabled="!manager.photos.length || deletingAll"
              @click="openDeleteAllConfirm"
            >
              <span class="material-symbols-rounded" style="font-size:16px;vertical-align:-3px;">
                {{ deletingAll ? 'sync' : 'delete_sweep' }}
              </span>
              {{ deletingAll ? 'กำลังลบ…' : 'ลบรูปทั้งหมด' }}
            </button>
            <button
              class="btn-secondary"
              :disabled="!manager.photos.length"
              @click="openApplyPicker"
            >
              <span class="material-symbols-rounded" style="font-size:16px;vertical-align:-3px;">content_copy</span>
              ใช้รูปชุดนี้กับรอบอื่น
            </button>
            <button class="btn-secondary" @click="closeManager">ปิด</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete-all confirmation -->
    <div class="modal-overlay" v-if="deleteAll.open" @click.self="closeDeleteAllConfirm">
      <div class="modal-card" style="max-width: 460px">
        <div class="modal-header">
          <div>
            <h2>
              <span class="material-symbols-rounded heading-icon" style="font-size:22px;vertical-align:-4px;color:#dc2626">
                delete_sweep
              </span>
              ลบรูปทั้งหมด
            </h2>
            <p class="modal-subtitle">
              รูปทั้ง {{ manager.photos.length }} รูปของรอบนี้จะถูกลบออก และไฟล์จะถูกลบจาก Cloudflare R2 ด้วย
              (รูปที่ใช้ร่วมกับรอบอื่นจะยังคงอยู่กับรอบนั้น) — <strong>กู้คืนไม่ได้</strong>
            </p>
          </div>
          <button class="modal-close" @click="closeDeleteAllConfirm">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <label class="confirm-label" for="delete-all-confirm">
            พิมพ์จำนวนรูป <strong>{{ manager.photos.length }}</strong> เพื่อยืนยัน
          </label>
          <input
            id="delete-all-confirm"
            v-model="deleteAll.typed"
            class="confirm-input"
            inputmode="numeric"
            autocomplete="off"
            :placeholder="String(manager.photos.length)"
            @keyup.enter="deleteAllConfirmed && confirmDeleteAllPhotos()"
          />
        </div>

        <div class="modal-footer">
          <span style="font-size:12px;color:#9ca3af">
            รอบ #{{ manager.schedule?.id }}
          </span>
          <div style="display:flex;gap:8px;">
            <button class="btn-secondary" @click="closeDeleteAllConfirm">ยกเลิก</button>
            <button
              class="btn-danger"
              :disabled="!deleteAllConfirmed || deletingAll"
              @click="confirmDeleteAllPhotos"
            >
              <span class="material-symbols-rounded" style="font-size:16px;vertical-align:-3px;">
                {{ deletingAll ? 'sync' : 'delete_sweep' }}
              </span>
              {{ deletingAll ? 'กำลังลบ…' : 'ลบทั้งหมด' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Apply-to-other-rounds picker -->
    <div class="modal-overlay" v-if="applyPicker.open" @click.self="closeApplyPicker">
      <div class="modal-card" style="max-width: 560px">
        <div class="modal-header">
          <div>
            <h2>
              <span class="material-symbols-rounded heading-icon" style="font-size:22px;vertical-align:-4px;">content_copy</span>
              ใช้รูปชุดนี้กับรอบอื่น
            </h2>
            <p class="modal-subtitle">
              เลือกรอบเดินทางของทริปเดียวกันที่จะใช้รูปทั้ง {{ manager.photos.length }} รูปนี้ร่วมกัน
              (ไม่อัปโหลดไฟล์ซ้ำ — ใช้ไฟล์เดิมบน R2)
            </p>
          </div>
          <button class="modal-close" @click="closeApplyPicker">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <div v-if="!applyTargets.length" class="empty-state" style="padding:24px 0">
            ไม่มีรอบเดินทางอื่นของทริปนี้ในรายการ
          </div>
          <div v-else class="round-pick-list">
            <label
              v-for="sch in applyTargets"
              :key="sch.id"
              class="round-pick-item"
            >
              <input type="checkbox" :value="sch.id" v-model="applyPicker.selected" />
              <div class="round-pick-info">
                <div class="round-pick-title">
                  <span class="round-badge">รอบ #{{ sch.id }}</span>
                  {{ formatDate(sch.departure_date) }}
                  <span :class="['status-pill', `pill-${sch.status}`]" style="margin-left:6px">
                    {{ statusLabel(sch.status) }}
                  </span>
                </div>
                <div class="round-pick-meta">
                  มีอยู่แล้ว {{ photoCounts[sch.id] ?? '—' }} รูป
                  <template v-if="sch.vehicle"> · {{ sch.vehicle.name }}</template>
                </div>
              </div>
            </label>
          </div>
        </div>

        <div class="modal-footer">
          <span style="font-size:13px;color:#6b7280">
            เลือกแล้ว {{ applyPicker.selected.length }} รอบ
          </span>
          <div style="display:flex;gap:8px;">
            <button class="btn-secondary" @click="closeApplyPicker">ยกเลิก</button>
            <button
              class="btn-primary"
              :disabled="!applyPicker.selected.length || applyPicker.submitting"
              @click="applyToRounds"
            >
              {{ applyPicker.submitting ? 'กำลังนำไปใช้…' : 'ยืนยัน' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../../js/lib/axios';

const loading = ref(true);
const schedules = ref([]);
const trips = ref([]);
const photoCounts = reactive({});
let searchDebounce = null;

const filters = reactive({
  tripId: '',
  from: '',
  to: '',
  search: '',
});

const filePicker = ref(null);
const uploading = ref(false);
const uploadTotal = ref(0);
const uploadDone = ref(0);
const uploadFailed = ref(0);
const uploadProgress = computed(() =>
  uploadTotal.value
    ? Math.round(((uploadDone.value + uploadFailed.value) / uploadTotal.value) * 100)
    : 0
);
const deletingId = ref(null);
const deletingAll = ref(false);

const manager = reactive({
  open: false,
  schedule: null,
  photos: [],
  loadingPhotos: false,
});

const applyPicker = reactive({
  open: false,
  selected: [],
  submitting: false,
});

// Deleting a whole album is unrecoverable, so make the operator type the count
// rather than let a muscle-memory Enter on a confirm() wipe a few hundred photos.
const deleteAll = reactive({ open: false, typed: '' });

const deleteAllConfirmed = computed(
  () => manager.photos.length > 0 && deleteAll.typed.trim() === String(manager.photos.length)
);

const share = reactive({
  token: null,
  url: null,
  loading: false,
  busy: false,
});
const copied = ref(false);

// Other rounds of the same trip the current photo set can be shared with.
const applyTargets = computed(() => {
  if (!manager.schedule) return [];
  const tripId = manager.schedule.trip?.id ?? manager.schedule.trip_id;
  return schedules.value.filter(
    (s) => s.id !== manager.schedule.id &&
      (s.trip?.id ?? s.trip_id) === tripId
  );
});

const filteredSchedules = computed(() => {
  const q = filters.search.trim().toLowerCase();
  if (!q) return schedules.value;
  return schedules.value.filter((s) =>
    (s.trip?.title || '').toLowerCase().includes(q) ||
    String(s.id).includes(q)
  );
});

const fetchTrips = async () => {
  try {
    const res = await api.get('/admin/trips', { params: { per_page: 200 } });
    trips.value = res.data.data ?? res.data;
  } catch {
    trips.value = [];
  }
};

const fetchSchedules = async () => {
  loading.value = true;
  try {
    const baseParams = { per_page: 100 };
    if (filters.tripId) baseParams.trip_id = filters.tripId;
    if (filters.from) baseParams.from = filters.from;
    if (filters.to) baseParams.to = filters.to;

    // The admin schedules endpoint is paginated. One trip can have many rounds,
    // so walk every page instead of showing only the first.
    const all = [];
    let page = 1;
    let lastPage = 1;
    do {
      const res = await api.get('/admin/schedules', {
        params: { ...baseParams, page },
      });
      const list = res.data.data ?? res.data ?? [];
      all.push(...list);
      lastPage = res.data.meta?.last_page ?? 1;
      page += 1;
    } while (page <= lastPage);

    schedules.value = all;
    // photos_count rides along on the schedule payload. This page used to fetch
    // every round's *full* photo list just to read .length off it — 60 requests
    // on every page load.
    for (const s of all) {
      if (typeof s.photos_count === 'number') photoCounts[s.id] = s.photos_count;
    }
  } catch (e) {
    schedules.value = [];
    alert(e.response?.data?.message ?? 'โหลดรอบเดินทางไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

// Only used to refresh the handful of rounds touched by "ใช้รูปชุดนี้กับรอบอื่น",
// where the response can't tell us each target's new total. The list view itself
// reads photos_count straight off the schedule payload.
const loadPhotoCount = async (scheduleId) => {
  try {
    const res = await api.get(`/admin/schedules/${scheduleId}/photos`);
    const list = res.data.data ?? res.data ?? [];
    photoCounts[scheduleId] = list.length;
  } catch {
    photoCounts[scheduleId] = 0;
  }
};

const onSearchInput = () => {
  if (searchDebounce) clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    // Local filter only — no need to refetch on search.
  }, 200);
};

const openManager = async (schedule) => {
  manager.schedule = schedule;
  manager.open = true;
  manager.photos = [];
  share.token = null;
  share.url = null;
  copied.value = false;
  await Promise.all([loadManagerPhotos(), loadShareLink()]);
};

const loadShareLink = async () => {
  if (!manager.schedule) return;
  share.loading = true;
  try {
    const res = await api.get(`/admin/schedules/${manager.schedule.id}/photos/share`);
    share.token = res.data?.data?.token ?? null;
    share.url = res.data?.data?.url ?? null;
  } catch {
    share.token = null;
    share.url = null;
  } finally {
    share.loading = false;
  }
};

const enableShareLink = async (rotate = false) => {
  if (!manager.schedule) return;
  share.busy = true;
  try {
    const res = await api.post(
      `/admin/schedules/${manager.schedule.id}/photos/share`,
      { rotate }
    );
    share.token = res.data?.data?.token ?? null;
    share.url = res.data?.data?.url ?? null;
    copied.value = false;
  } catch (e) {
    alert(e.response?.data?.message ?? 'สร้างลิงก์ไม่สำเร็จ');
  } finally {
    share.busy = false;
  }
};

const rotateShareLink = async () => {
  if (!confirm('สร้างลิงก์ใหม่? ลิงก์เดิมจะใช้ไม่ได้อีกต่อไป')) return;
  await enableShareLink(true);
};

const revokeShareLink = async () => {
  if (!manager.schedule) return;
  if (!confirm('ปิดการแชร์อัลบั้มนี้? ลิงก์เดิมจะเปิดไม่ได้อีก')) return;
  share.busy = true;
  try {
    await api.delete(`/admin/schedules/${manager.schedule.id}/photos/share`);
    share.token = null;
    share.url = null;
  } catch (e) {
    alert(e.response?.data?.message ?? 'ปิดการแชร์ไม่สำเร็จ');
  } finally {
    share.busy = false;
  }
};

const copyShareLink = async () => {
  if (!share.url) return;
  try {
    await navigator.clipboard.writeText(share.url);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1800);
  } catch {
    // Fallback: select the input so the user can copy manually.
    alert('คัดลอกอัตโนมัติไม่สำเร็จ — กรุณาเลือกลิงก์แล้วคัดลอกเอง');
  }
};

const closeManager = () => {
  manager.open = false;
  manager.schedule = null;
  manager.photos = [];
};

const loadManagerPhotos = async () => {
  if (!manager.schedule) return;
  manager.loadingPhotos = true;
  try {
    const res = await api.get(`/admin/schedules/${manager.schedule.id}/photos`);
    manager.photos = res.data.data ?? res.data ?? [];
    photoCounts[manager.schedule.id] = manager.photos.length;
  } catch (e) {
    alert(e.response?.data?.message ?? 'โหลดรูปไม่สำเร็จ');
  } finally {
    manager.loadingPhotos = false;
  }
};

const onFileSelect = (e) => uploadFiles(Array.from(e.target.files || []));
const onDrop = (e) => uploadFiles(Array.from(e.dataTransfer.files || []));

const isHeic = (file) =>
  /image\/heic|image\/heif/i.test(file.type) || /\.(heic|heif)$/i.test(file.name);

// Load heic2any from a CDN on demand instead of bundling it (it pulls in a large
// libheif WASM blob and would otherwise be a hard build dependency on the server).
const HEIC2ANY_CDN = 'https://unpkg.com/heic2any@0.0.4/dist/heic2any.min.js';
let heicLibPromise = null;
const loadHeic2any = () => {
  if (window.heic2any) return Promise.resolve(window.heic2any);
  if (heicLibPromise) return heicLibPromise;
  heicLibPromise = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = HEIC2ANY_CDN;
    s.onload = () => resolve(window.heic2any);
    s.onerror = () => { heicLibPromise = null; reject(new Error('โหลดตัวแปลง HEIC ไม่สำเร็จ')); };
    document.head.appendChild(s);
  });
  return heicLibPromise;
};

// HEIC isn't viewable in most browsers, so convert to full-resolution JPEG in the
// admin's browser before upload (the server can't decode HEIC). Download stays sharp.
const convertHeic = async (file) => {
  try {
    const heic2any = await loadHeic2any();
    const blob = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.92 });
    const out = Array.isArray(blob) ? blob[0] : blob;
    const name = file.name.replace(/\.(heic|heif)$/i, '') + '.jpg';
    return new File([out], name, { type: 'image/jpeg' });
  } catch (e) {
    // Fall back to the original file; the server will store it without a thumbnail.
    return file;
  }
};

// Phone photos run 3–12 MB at 12MP, far past what the album ever displays. Shrink
// them in the browser so we ship ~5–10× fewer bytes and the server never has to
// decode a full-resolution image.
const MAX_UPLOAD_EDGE = 2400;
const DOWNSCALE_QUALITY = 0.85;
const SKIP_DOWNSCALE_BYTES = 1.5 * 1024 * 1024;

// How many chunk uploads may be in flight at once. Kept low so a big album can't
// saturate the admin's uplink or the server's PHP-FPM workers.
const UPLOAD_CONCURRENCY = 3;

const downscaleImage = async (file) => {
  if (!/^image\/(jpeg|jpg|png|webp)$/i.test(file.type)) return file;
  if (file.size <= SKIP_DOWNSCALE_BYTES) return file;

  let bitmap;
  try {
    // from-image so an EXIF-rotated photo doesn't get baked in sideways.
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch {
    return file; // unsupported browser or undecodable file — let the server have it
  }

  const scale = Math.min(1, MAX_UPLOAD_EDGE / Math.max(bitmap.width, bitmap.height));
  if (scale === 1) {
    bitmap.close?.();
    return file;
  }

  const w = Math.max(1, Math.round(bitmap.width * scale));
  const h = Math.max(1, Math.round(bitmap.height * scale));
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(bitmap, 0, 0, w, h);
  bitmap.close?.();

  const blob = await new Promise((resolve) =>
    canvas.toBlob(resolve, 'image/jpeg', DOWNSCALE_QUALITY)
  );
  canvas.width = canvas.height = 0; // let the tab reclaim the backing store

  if (!blob || blob.size >= file.size) return file;

  return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
};

// HEIC first (the canvas can't decode it), then shrink whatever came out.
const prepareFile = async (file) => {
  const decoded = isHeic(file) ? await convertHeic(file) : file;
  return downscaleImage(decoded);
};

// Turn an upload error into a short, human-readable reason so the operator can
// tell a body-size limit (413) from a validation reject (422) at a glance.
const describeUploadError = (e) => {
  const status = e?.response?.status;
  const serverMsg = e?.response?.data?.message;
  if (status === 413) return 'ไฟล์ชุดนี้ใหญ่เกินที่เซิร์ฟเวอร์รับ (413) — ปรับ client_max_body_size / post_max_size';
  if (status === 422) return serverMsg || 'ไฟล์ไม่ผ่านการตรวจ (422) — เกิน 15 MB หรือชนิดไฟล์ไม่รองรับ';
  if (status === 504 || status === 408) return 'เซิร์ฟเวอร์ใช้เวลานานเกินไป (timeout)';
  if (status) return serverMsg ? `${serverMsg} (${status})` : `เซิร์ฟเวอร์ตอบกลับ ${status}`;
  return 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ (เน็ตหลุด/CORS/หมดเวลา)';
};

// Upload one chunk, retrying a few times on failure (flaky network / transient
// 5xx) before giving up. Returns the last error on failure, or null on success.
const uploadChunk = async (scheduleId, chunk, attempts = 3) => {
  let lastError = null;
  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      const fd = new FormData();
      for (const f of chunk) fd.append('files[]', f);
      await api.post(
        `/admin/schedules/${scheduleId}/photos`,
        fd,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      );
      return null;
    } catch (e) {
      lastError = e;
      // A 4xx (too big / invalid) won't fix itself on retry — fail fast.
      const status = e?.response?.status;
      if (status >= 400 && status < 500) break;
      if (attempt === attempts) break;
      // Linear backoff before the next attempt.
      await new Promise((r) => setTimeout(r, attempt * 800));
    }
  }
  console.error('upload chunk failed', lastError);
  return lastError;
};

const uploadFiles = async (files) => {
  if (!manager.schedule || !files.length) return;

  // Pin the target round up front — closing the manager mid-upload nulls
  // manager.schedule, and in-flight chunks must still land on the right round.
  const scheduleId = manager.schedule.id;

  uploading.value = true;
  uploadTotal.value = files.length;
  uploadDone.value = 0;
  uploadFailed.value = 0;
  try {
    // Chunk into small batches so each request body stays well under the server's
    // post_max_size / nginx client_max_body_size. Each chunk is retried
    // independently — one failed batch no longer aborts the whole upload.
    const CHUNK_SIZE = 5;
    const chunks = [];
    for (let i = 0; i < files.length; i += CHUNK_SIZE) chunks.push(files.slice(i, i + CHUNK_SIZE));

    let lastError = null;
    let next = 0;

    // Pull chunks off a shared cursor with a few workers, so several uploads are
    // in flight at once instead of strictly one after another. Chunks may land
    // out of order — both photo relations tiebreak on id, so the grid still shows
    // them in upload order even when parallel requests collide on sort_order.
    const worker = async () => {
      while (next < chunks.length) {
        const chunk = chunks[next++];

        // Convert + shrink a chunk right before its own upload, so at most
        // UPLOAD_CONCURRENCY chunks of decoded bitmaps are live. Preparing every
        // file up front would blow the tab's memory on a few hundred photos.
        let prepared = chunk;
        try {
          prepared = await Promise.all(chunk.map(prepareFile));
        } catch {
          prepared = chunk; // preparation is an optimisation — send the originals
        }

        const err = await uploadChunk(scheduleId, prepared);
        if (err) {
          uploadFailed.value += chunk.length;
          lastError = err;
        } else {
          uploadDone.value += chunk.length;
        }
      }
    };

    await Promise.all(
      Array.from({ length: Math.min(UPLOAD_CONCURRENCY, chunks.length) }, worker)
    );

    await loadManagerPhotos();

    if (uploadFailed.value > 0) {
      alert(
        `อัปโหลดสำเร็จ ${uploadDone.value} รูป · ไม่สำเร็จ ${uploadFailed.value} รูป\n` +
        `สาเหตุ: ${describeUploadError(lastError)}\n` +
        'ลองเลือกเฉพาะรูปที่ยังไม่ขึ้นมาอัปใหม่อีกครั้งได้เลย'
      );
    }
  } catch (e) {
    alert(e.response?.data?.message ?? 'อัปโหลดไม่สำเร็จ');
  } finally {
    uploading.value = false;
    uploadTotal.value = 0;
    uploadDone.value = 0;
    uploadFailed.value = 0;
    if (filePicker.value) filePicker.value.value = '';
  }
};

const confirmDeletePhoto = async (photo) => {
  if (!confirm('ลบรูปนี้ใช่หรือไม่? การลบจะลบไฟล์จาก Cloudflare R2 ด้วย')) return;
  deletingId.value = photo.id;
  try {
    await api.delete(`/admin/schedules/${manager.schedule.id}/photos/${photo.id}`);
    manager.photos = manager.photos.filter((p) => p.id !== photo.id);
    photoCounts[manager.schedule.id] = manager.photos.length;
  } catch (e) {
    alert(e.response?.data?.message ?? 'ลบรูปไม่สำเร็จ');
  } finally {
    deletingId.value = null;
  }
};

const openDeleteAllConfirm = () => {
  deleteAll.typed = '';
  deleteAll.open = true;
};

const closeDeleteAllConfirm = () => {
  deleteAll.open = false;
  deleteAll.typed = '';
};

const confirmDeleteAllPhotos = async () => {
  if (!manager.schedule || !deleteAllConfirmed.value) return;
  deletingAll.value = true;
  try {
    await api.delete(`/admin/schedules/${manager.schedule.id}/photos`);
    manager.photos = [];
    photoCounts[manager.schedule.id] = 0;
    closeDeleteAllConfirm();
  } catch (e) {
    alert(e.response?.data?.message ?? 'ลบรูปทั้งหมดไม่สำเร็จ');
  } finally {
    deletingAll.value = false;
  }
};

const openApplyPicker = () => {
  applyPicker.selected = [];
  applyPicker.open = true;
};

const closeApplyPicker = () => {
  applyPicker.open = false;
  applyPicker.selected = [];
};

const applyToRounds = async () => {
  if (!manager.schedule || !applyPicker.selected.length) return;
  applyPicker.submitting = true;
  try {
    const res = await api.post(
      `/admin/schedules/${manager.schedule.id}/photos/apply`,
      { schedule_ids: applyPicker.selected }
    );
    // Refresh photo counts for the rounds we just updated.
    await Promise.all(applyPicker.selected.map((id) => loadPhotoCount(id)));
    const n = res.data?.data?.schedules ?? applyPicker.selected.length;
    alert(`นำรูปไปใช้กับ ${n} รอบเดินทางแล้ว`);
    closeApplyPicker();
  } catch (e) {
    alert(e.response?.data?.message ?? 'นำรูปไปใช้กับรอบอื่นไม่สำเร็จ');
  } finally {
    applyPicker.submitting = false;
  }
};

const formatDate = (d) => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return d;
  }
};

const statusLabel = (s) => ({
  open: 'เปิดจอง',
  closed: 'ปิดจอง',
  cancelled: 'ยกเลิก',
  completed: 'จบทริป',
})[s] || s;

onMounted(async () => {
  await Promise.all([fetchTrips(), fetchSchedules()]);
});
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
  min-width: 160px;
}

.filter-field label {
  font-size: 11px;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.filter-field input,
.filter-field select {
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 14px;
}

.schedule-list {
  display: flex;
  flex-direction: column;
}

.schedule-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
  border-bottom: 1px solid #f0f0f0;
  transition: background 0.15s;
}

.schedule-row:last-child { border-bottom: none; }
.schedule-row:hover { background: #fafafa; }

.schedule-info { flex: 1; min-width: 0; }

.schedule-trip {
  font-weight: 800;
  font-size: 15px;
  color: #111827;
  margin-bottom: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.round-badge {
  display: inline-block;
  font-size: 11px;
  font-weight: 800;
  color: var(--color-accent, #2d7a4f);
  background: rgba(45, 122, 79, 0.1);
  padding: 2px 8px;
  border-radius: 6px;
  margin-right: 6px;
  vertical-align: 1px;
  letter-spacing: 0.02em;
}

.schedule-meta {
  font-size: 13px;
  color: #6b7280;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.schedule-meta .dot { color: #d1d5db; }

.schedule-photos-count {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 13px;
  color: var(--color-accent, #2d7a4f);
  background: rgba(45, 122, 79, 0.08);
  padding: 6px 12px;
  border-radius: 999px;
  flex-shrink: 0;
}

.schedule-photos-count .material-symbols-rounded { font-size: 16px; }

.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}

.pill-open { background: #d1fae5; color: #065f46; }
.pill-closed { background: #fee2e2; color: #991b1b; }
.pill-cancelled { background: #f3f4f6; color: #6b7280; }
.pill-completed { background: #dbeafe; color: #1e40af; }

.btn-sm {
  font-size: 13px;
  padding: 8px 14px;
}

.upload-zone.large {
  min-height: 140px;
  border: 2px dashed #cbd5e1;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  background: #f8fafc;
  transition: border-color 0.15s, background 0.15s;
  margin-bottom: 16px;
}

.upload-zone.large:hover {
  border-color: var(--color-accent, #2d7a4f);
  background: #f0fdf4;
}

.upload-placeholder {
  text-align: center;
  color: #6b7280;
}

.upload-placeholder .material-symbols-rounded {
  color: var(--color-accent, #2d7a4f);
}

.upload-status { margin-bottom: 16px; }

.upload-progress {
  height: 6px;
  background: #e5e7eb;
  border-radius: 999px;
  overflow: hidden;
}

.progress-bar {
  height: 100%;
  background: var(--color-accent, #2d7a4f);
  transition: width 0.2s;
}

.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 10px;
  margin-top: 4px;
}

.photo-tile {
  position: relative;
  aspect-ratio: 1;
  border-radius: 10px;
  overflow: hidden;
  background: #f3f4f6;
}

.photo-tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.photo-delete {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.55);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
}

.photo-delete:hover { background: rgba(220, 38, 38, 0.85); }
.photo-delete:disabled { opacity: 0.6; cursor: progress; }

.modal-subtitle {
  margin: 4px 0 0;
  font-size: 13px;
  color: #6b7280;
}

.round-pick-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 360px;
  overflow-y: auto;
}

.round-pick-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}

.round-pick-item:hover {
  border-color: var(--color-accent, #2d7a4f);
  background: #f0fdf4;
}

.round-pick-item input {
  margin-top: 3px;
  width: 16px;
  height: 16px;
  accent-color: var(--color-accent, #2d7a4f);
}

.round-pick-info { flex: 1; min-width: 0; }

.confirm-label {
  display: block;
  font-size: 13px;
  color: #374151;
  margin-bottom: 6px;
}

.confirm-input {
  width: 100%;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 9px 12px;
  font-size: 14px;
  font-family: inherit;
}

.confirm-input:focus {
  outline: none;
  border-color: #dc2626;
}

.round-pick-title {
  font-weight: 700;
  font-size: 14px;
  color: #111827;
}

.round-pick-meta {
  font-size: 12px;
  color: #6b7280;
  margin-top: 2px;
}

.share-panel {
  border: 1px solid #e5e7eb;
  background: #f8fafc;
  border-radius: 12px;
  padding: 12px 14px;
  margin-bottom: 16px;
}

.share-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.share-hint {
  font-size: 12px;
  color: #9ca3af;
  font-weight: 500;
}

.share-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.share-input {
  flex: 1;
  min-width: 0;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 13px;
  background: #fff;
  color: #374151;
}

.share-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
}

.link-btn {
  background: none;
  border: none;
  padding: 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-accent, #2d7a4f);
  cursor: pointer;
  font-family: inherit;
}

.link-btn.danger { color: #dc2626; }
.link-btn:disabled { opacity: 0.5; cursor: default; }
.share-actions .dot { color: #d1d5db; }
</style>
