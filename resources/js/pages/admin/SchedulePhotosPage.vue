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
                JPG, PNG, WebP, HEIC — ครั้งละสูงสุด 20 ไฟล์ · ไฟล์ละไม่เกิน 15 MB
              </p>
            </div>
          </div>

          <!-- Upload progress -->
          <div v-if="uploading" class="upload-status">
            <div class="upload-progress">
              <div class="progress-bar" :style="{ width: uploadProgress + '%' }"></div>
            </div>
            <p style="font-size:12px;color:#6b7280;margin-top:6px">
              กำลังอัปโหลด {{ uploadProgress }}%
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
const uploadProgress = ref(0);
const deletingId = ref(null);

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
    // Fetch photo counts in parallel — cap at 60 to avoid bursting the API.
    await Promise.all(
      schedules.value.slice(0, 60).map((s) => loadPhotoCount(s.id))
    );
  } catch (e) {
    schedules.value = [];
    alert(e.response?.data?.message ?? 'โหลดรอบเดินทางไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

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

// HEIC isn't viewable in most browsers, so convert to full-resolution JPEG in the
// admin's browser before upload (the server can't decode HEIC). Download stays sharp.
const convertHeic = async (file) => {
  try {
    const { default: heic2any } = await import('heic2any');
    const blob = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.92 });
    const out = Array.isArray(blob) ? blob[0] : blob;
    const name = file.name.replace(/\.(heic|heif)$/i, '') + '.jpg';
    return new File([out], name, { type: 'image/jpeg' });
  } catch (e) {
    // Fall back to the original file; the server will store it without a thumbnail.
    return file;
  }
};

const uploadFiles = async (files) => {
  if (!manager.schedule || !files.length) return;

  uploading.value = true;
  uploadProgress.value = 0;
  try {
    // Convert any HEIC/HEIF files first.
    if (files.some(isHeic)) {
      files = await Promise.all(files.map((f) => (isHeic(f) ? convertHeic(f) : f)));
    }

    // Chunk into batches of 10 so the backend's max:20-per-request stays comfortable
    // and progress feedback stays responsive on slow links.
    const chunks = [];
    for (let i = 0; i < files.length; i += 10) chunks.push(files.slice(i, i + 10));

    for (let i = 0; i < chunks.length; i++) {
      const fd = new FormData();
      for (const f of chunks[i]) fd.append('files[]', f);
      await api.post(
        `/admin/schedules/${manager.schedule.id}/photos`,
        fd,
        {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (ev) => {
            const chunkPct = Math.round((ev.loaded / (ev.total || 1)) * 100);
            uploadProgress.value = Math.round(
              ((i + chunkPct / 100) / chunks.length) * 100
            );
          },
        }
      );
    }
    await loadManagerPhotos();
  } catch (e) {
    alert(e.response?.data?.message ?? 'อัปโหลดไม่สำเร็จ');
  } finally {
    uploading.value = false;
    uploadProgress.value = 0;
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
