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

    <!-- Summary -->
    <div class="stat-strip">
      <button class="stat-tile" :class="{ 'is-active': isAllView }" @click="showAll">
        <span class="stat-value">{{ schedules.length }}</span>
        <span class="stat-label">รอบเดินทางทั้งหมด</span>
      </button>
      <button class="stat-tile accent-warn" :class="{ 'is-active': isPendingView }" @click="showPendingOnly">
        <span class="stat-value">{{ pendingCount }}</span>
        <span class="stat-label">ออกเดินทางแล้วยังไม่มีรูป</span>
      </button>
      <div class="stat-tile static">
        <span class="stat-value">{{ totalPhotos.toLocaleString('th-TH') }}</span>
        <span class="stat-label">รูปทั้งหมดในระบบ</span>
      </div>
      <div class="stat-tile static">
        <span class="stat-value">{{ sharedCount }}</span>
        <span class="stat-label">อัลบั้มที่เปิดลิงก์แชร์</span>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-row">
        <div class="search-box grow">
          <span class="material-symbols-rounded">search</span>
          <input
            v-model="filters.search"
            type="text"
            placeholder="ค้นหาชื่อทริป วันที่ (เช่น 12 ก.ค. หรือ 2026-07-12) หรือเลขรอบ"
          />
          <button v-if="filters.search" class="clear-btn" @click="filters.search = ''" title="ล้างคำค้น">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <!-- Searchable trip picker: a 200-option <select> is unusable to scan -->
        <div class="trip-picker" ref="tripPickerEl">
          <button class="picker-trigger" :class="{ 'has-value': !!filters.tripId }" @click="toggleTripPicker">
            <span class="material-symbols-rounded">hiking</span>
            <span class="picker-label">{{ selectedTripTitle || 'ทุกทริป' }}</span>
            <span class="material-symbols-rounded chevron">{{ tripPicker.open ? 'expand_less' : 'expand_more' }}</span>
          </button>
          <div v-if="tripPicker.open" class="picker-menu">
            <div class="picker-search">
              <span class="material-symbols-rounded">search</span>
              <input
                ref="tripSearchEl"
                v-model="tripPicker.query"
                type="text"
                placeholder="พิมพ์ชื่อทริป…"
                @keydown.esc.stop="closeTripPicker"
              />
            </div>
            <div class="picker-list">
              <button class="picker-option" :class="{ selected: !filters.tripId }" @click="pickTrip('')">
                ทุกทริป
                <span class="picker-count">{{ schedules.length }} รอบ</span>
              </button>
              <button
                v-for="t in pickerTrips"
                :key="t.id"
                class="picker-option"
                :class="{ selected: String(filters.tripId) === String(t.id) }"
                @click="pickTrip(t.id)"
              >
                <span class="picker-option-title">{{ t.title }}</span>
                <span class="picker-count" v-if="tripRoundCounts[t.id]">{{ tripRoundCounts[t.id] }} รอบ</span>
              </button>
              <div v-if="!pickerTrips.length" class="picker-empty">ไม่พบทริปที่ตรงกับคำค้น</div>
            </div>
          </div>
        </div>

        <select v-model="sortDir" class="sort-select" title="ลำดับการเรียง">
          <option value="desc">วันเดินทาง: ใหม่ → เก่า</option>
          <option value="asc">วันเดินทาง: เก่า → ใหม่</option>
        </select>
      </div>

      <div class="toolbar-row secondary">
        <div class="segmented">
          <button
            v-for="t in timeTabs"
            :key="t.value"
            class="segment"
            :class="{ active: timeTab === t.value }"
            @click="timeTab = t.value"
          >
            {{ t.label }}
            <span class="segment-count">{{ timeTabCounts[t.value] }}</span>
          </button>
        </div>

        <div class="chip-group">
          <button
            v-for="c in photoChips"
            :key="c.value"
            class="chip"
            :class="{ active: photoFilter === c.value }"
            @click="photoFilter = c.value"
          >
            <span class="material-symbols-rounded">{{ c.icon }}</span>
            {{ c.label }}
          </button>
        </div>

        <button class="ghost-btn" :class="{ active: showDateRange || hasDateRange }" @click="showDateRange = !showDateRange">
          <span class="material-symbols-rounded">date_range</span>
          ช่วงวันที่<template v-if="hasDateRange"> · ใช้อยู่</template>
        </button>
      </div>

      <div v-if="showDateRange" class="toolbar-row range-row">
        <label class="range-field">
          <span>ตั้งแต่</span>
          <input v-model="filters.from" type="date" @change="fetchSchedules" />
        </label>
        <label class="range-field">
          <span>ถึง</span>
          <input v-model="filters.to" type="date" @change="fetchSchedules" />
        </label>
        <button v-if="hasDateRange" class="ghost-btn" @click="clearDateRange">ล้างช่วงวันที่</button>
      </div>
    </div>

    <!-- Schedules list -->
    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>
      <div v-else>
        <div v-if="!visibleSchedules.length" class="empty-state">
          <span class="material-symbols-rounded empty-icon">event_busy</span>
          <div>ไม่พบรอบเดินทางที่ตรงเงื่อนไข</div>
          <button v-if="hasAnyFilter" class="btn-secondary btn-sm reset-btn" @click="resetFilters">
            ล้างตัวกรองทั้งหมด
          </button>
        </div>

        <div v-else class="schedule-list">
          <template v-for="group in groupedSchedules" :key="group.key">
            <div class="month-head">
              <span class="month-name">{{ group.label }}</span>
              <span class="month-count">{{ group.items.length }} รอบ</span>
            </div>

            <div
              v-for="sch in group.items"
              :key="sch.id"
              class="schedule-row"
              role="button"
              tabindex="0"
              @click="openManager(sch)"
              @keydown.enter.prevent="openManager(sch)"
              @keydown.space.prevent="openManager(sch)"
            >
              <div class="date-chip" :class="{ past: isPast(sch) }">
                <span class="date-day">{{ dayOf(sch.departure_date) }}</span>
                <span class="date-month">{{ shortMonthOf(sch.departure_date) }}</span>
                <span class="date-year">{{ buddhistYearOf(sch.departure_date) }}</span>
              </div>

              <div class="schedule-info">
                <div class="schedule-trip">
                  {{ sch.trip?.title || '—' }}
                  <span class="round-badge">รอบ #{{ sch.id }}</span>
                </div>
                <div class="schedule-meta">
                  <span class="meta-item">
                    <span class="material-symbols-rounded">event</span>
                    {{ dateRangeText(sch) }}
                  </span>
                  <span class="meta-item" v-if="sch.vehicle">
                    <span class="material-symbols-rounded">directions_bus</span>
                    {{ sch.vehicle.name }}<template v-if="sch.vehicle.license_plate"> ({{ sch.vehicle.license_plate }})</template>
                  </span>
                  <span class="meta-item">
                    <span class="material-symbols-rounded">group</span>
                    {{ sch.booked_seats }}/{{ sch.total_seats }} ที่นั่ง
                  </span>
                  <span :class="['status-pill', `pill-${sch.status}`]">{{ statusLabel(sch.status) }}</span>
                </div>
              </div>

              <div class="row-tags">
                <span v-if="sch.photos_shared" class="share-tag" title="เปิดลิงก์อัลบั้มสาธารณะอยู่">
                  <span class="material-symbols-rounded">link</span>
                  แชร์อยู่
                </span>
                <span
                  class="photo-count"
                  :class="{ empty: !photoCount(sch), 'needs-photos': !photoCount(sch) && isPast(sch) }"
                >
                  <span class="material-symbols-rounded">{{ photoCount(sch) ? 'image' : 'no_photography' }}</span>
                  {{ photoCount(sch) ? `${photoCount(sch)} รูป` : 'ยังไม่มีรูป' }}
                </span>
              </div>

              <div class="action-btns" @click.stop>
                <button class="btn-primary btn-sm" @click="openManager(sch)">
                  <span class="material-symbols-rounded">add_a_photo</span>
                  จัดการรูป
                </button>
              </div>
            </div>
          </template>
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
              {{ dateRangeText(manager.schedule) }}
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

          <!-- อายุการเก็บรูป — ลบอัตโนมัติหลังอัปโหลดครบกำหนด -->
          <div class="retention-panel" :class="{ urgent: hoursToExpiry !== null && hoursToExpiry <= 24 }">
            <span class="material-symbols-rounded">schedule</span>
            <div>
              <strong>ระบบลบรูปอัตโนมัติหลังอัปโหลด {{ RETENTION_DAYS }} วัน</strong>
              <template v-if="albumExpiryText">
                — ชุดนี้จะเริ่มถูกลบ {{ albumExpiryText }} (ลบไฟล์ออกจาก R2 ด้วย)
              </template>
              <div class="retention-hint">แจ้งลูกค้าให้ดาวน์โหลดก่อนถึงกำหนด — ลิงก์อัลบั้มจะว่างเปล่าหลังรูปถูกลบ</div>
            </div>
          </div>

          <!-- Drop zone -->
          <div
            class="upload-zone large"
            :class="{ dragging: dragOver }"
            @click="filePicker.click()"
            @dragover.prevent="dragOver = true"
            @dragleave="dragOver = false"
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
                · เก็บไว้ {{ RETENTION_DAYS }} วันแล้วลบอัตโนมัติ
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
              <span v-if="expiryBadge(photo)" class="expiry-badge" :class="expiryBadge(photo).tone">
                {{ expiryBadge(photo).label }}
              </span>
              <a
                class="photo-open"
                :href="photo.url"
                target="_blank"
                rel="noopener"
                title="เปิดรูปเต็ม"
                @click.stop
              >
                <span class="material-symbols-rounded">open_in_new</span>
              </a>
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
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import api from '../../../js/lib/axios';
import { bangkokToday } from '../../lib/bangkokDate';

const loading = ref(true);
const schedules = ref([]);
const trips = ref([]);
const photoCounts = reactive({});

const filters = reactive({
  tripId: '',
  from: '',
  to: '',
  search: '',
});

// ผ่านมาแล้วมาก่อน — รูปจะถูกอัปหลังจบทริปเสมอ รอบที่ยังไม่ออกเดินทางจึงไม่ใช่งานที่ต้องทำ
const timeTab = ref('past');
const photoFilter = ref('all');
const sortDir = ref('desc');
const showDateRange = ref(false);
const dragOver = ref(false);

const timeTabs = [
  { value: 'past', label: 'ออกเดินทางแล้ว' },
  { value: 'upcoming', label: 'ยังไม่ออกเดินทาง' },
  { value: 'all', label: 'ทั้งหมด' },
];

const photoChips = [
  { value: 'all', label: 'ทุกสถานะรูป', icon: 'filter_list' },
  { value: 'none', label: 'ยังไม่มีรูป', icon: 'no_photography' },
  { value: 'has', label: 'มีรูปแล้ว', icon: 'image' },
];

const tripPicker = reactive({ open: false, query: '' });
const tripPickerEl = ref(null);
const tripSearchEl = ref(null);

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

// ─── วันที่ ────────────────────────────────────────────────
// departure_date มาเป็นสตริง YYYY-MM-DD (เวลาไทย) จึงอ่านทีละส่วนตรง ๆ
// แทนที่จะโยนเข้า new Date() ที่ตีความเป็น UTC แล้วเลื่อนวันในบางไทม์โซน

const THAI_MONTHS_SHORT = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const THAI_MONTHS_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

const dateParts = (d) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d ?? ''));
  if (!m) return null;
  return { y: Number(m[1]), m: Number(m[2]), d: Number(m[3]) };
};

const dayOf = (d) => dateParts(d)?.d ?? '—';
const shortMonthOf = (d) => {
  const p = dateParts(d);
  return p ? THAI_MONTHS_SHORT[p.m - 1] : '';
};
const buddhistYearOf = (d) => {
  const p = dateParts(d);
  return p ? String(p.y + 543).slice(-2) : '';
};

const formatDate = (d) => {
  const p = dateParts(d);
  if (!p) return '—';
  return `${p.d} ${THAI_MONTHS_SHORT[p.m - 1]} ${p.y + 543}`;
};

// "12 – 13 ก.ค. 2569" เมื่ออยู่เดือนเดียวกัน ไม่งั้นเขียนเต็มทั้งสองฝั่ง
const dateRangeText = (sch) => {
  if (!sch) return '—';
  const start = dateParts(sch.departure_date);
  const end = dateParts(sch.return_date);
  if (!start) return '—';
  if (!end || sch.return_date === sch.departure_date) return formatDate(sch.departure_date);
  if (start.y === end.y && start.m === end.m) {
    return `${start.d} – ${end.d} ${THAI_MONTHS_SHORT[start.m - 1]} ${start.y + 543}`;
  }
  return `${formatDate(sch.departure_date)} – ${formatDate(sch.return_date)}`;
};

const isPast = (sch) => (sch?.departure_date ?? '') <= bangkokToday();

// ─── อายุการเก็บรูป ────────────────────────────────────────
// ต้องตรงกับ SchedulePhoto::RETENTION_DAYS ฝั่ง Laravel (ใช้แค่ตอนอัลบั้มยังว่าง
// อยู่ — พอมีรูปแล้วเราอ่านเวลาหมดอายุจริงจาก expires_at ของแต่ละรูป)
const RETENTION_DAYS = 7;

const HOUR_MS = 60 * 60 * 1000;

// รูปที่จะหมดอายุก่อนใครในรอบนี้ = เส้นตายของอัลบั้ม
const albumExpiry = computed(() => {
  const stamps = manager.photos
    .map((p) => Date.parse(p.expires_at ?? ''))
    .filter((t) => !Number.isNaN(t));
  return stamps.length ? Math.min(...stamps) : null;
});

const hoursToExpiry = computed(
  () => (albumExpiry.value === null ? null : (albumExpiry.value - Date.now()) / HOUR_MS)
);

const albumExpiryText = computed(() => {
  if (albumExpiry.value === null) return '';
  const d = new Date(albumExpiry.value);
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  return `${d.getDate()} ${THAI_MONTHS_SHORT[d.getMonth()]} ${d.getFullYear() + 543} เวลา ${hh}:${mm} น.`;
});

// ป้ายบนรูป — ขึ้นเฉพาะช่วง 48 ชม. สุดท้าย เพื่อไม่ให้กริดรกโดยไม่จำเป็น
const expiryBadge = (photo) => {
  const at = Date.parse(photo.expires_at ?? '');
  if (Number.isNaN(at)) return null;
  const hours = (at - Date.now()) / HOUR_MS;
  if (hours > 48) return null;
  if (hours <= 0) return { label: 'กำลังถูกลบ', tone: 'danger' };
  if (hours <= 24) return { label: `ลบใน ${Math.max(1, Math.round(hours))} ชม.`, tone: 'danger' };
  return { label: 'ลบพรุ่งนี้', tone: 'warn' };
};

const photoCount = (sch) => photoCounts[sch.id] ?? sch.photos_count ?? 0;

// ─── ตัวกรอง / การเรียง ────────────────────────────────────

const selectedTripTitle = computed(
  () => trips.value.find((t) => String(t.id) === String(filters.tripId))?.title ?? ''
);

const tripRoundCounts = computed(() => {
  const counts = {};
  for (const s of schedules.value) {
    const id = s.trip?.id ?? s.trip_id;
    if (id) counts[id] = (counts[id] ?? 0) + 1;
  }
  return counts;
});

const pickerTrips = computed(() => {
  const q = tripPicker.query.trim().toLowerCase();
  const list = q
    ? trips.value.filter((t) => (t.title || '').toLowerCase().includes(q))
    : trips.value;
  // ทริปที่มีรอบอยู่ในรายการขึ้นก่อน แล้วค่อยเรียงตามชื่อ
  return [...list].sort((a, b) => {
    const ca = tripRoundCounts.value[a.id] ?? 0;
    const cb = tripRoundCounts.value[b.id] ?? 0;
    if ((ca > 0) !== (cb > 0)) return cb - ca;
    return (a.title || '').localeCompare(b.title || '', 'th');
  });
});

const matchesSearch = (s, q) => {
  if (!q) return true;
  const haystack = [
    s.trip?.title || '',
    String(s.id),
    s.departure_date || '',
    s.return_date || '',
    formatDate(s.departure_date),
    s.vehicle?.name || '',
    s.vehicle?.license_plate || '',
  ].join(' ').toLowerCase();
  return haystack.includes(q);
};

const byTimeTab = (s) => {
  if (timeTab.value === 'all') return true;
  return timeTab.value === 'past' ? isPast(s) : !isPast(s);
};

const timeTabCounts = computed(() => ({
  past: schedules.value.filter(isPast).length,
  upcoming: schedules.value.filter((s) => !isPast(s)).length,
  all: schedules.value.length,
}));

const pendingCount = computed(
  () => schedules.value.filter((s) => isPast(s) && !photoCount(s)).length
);

const totalPhotos = computed(
  () => schedules.value.reduce((sum, s) => sum + photoCount(s), 0)
);

const sharedCount = computed(() => schedules.value.filter((s) => s.photos_shared).length);

const visibleSchedules = computed(() => {
  const q = filters.search.trim().toLowerCase();
  const list = schedules.value.filter((s) => {
    if (!byTimeTab(s)) return false;
    if (photoFilter.value === 'none' && photoCount(s) > 0) return false;
    if (photoFilter.value === 'has' && photoCount(s) === 0) return false;
    return matchesSearch(s, q);
  });

  // เรียงจากสตริง YYYY-MM-DD ได้ตรง ๆ (เทียบเรียงตามตัวอักษร = เรียงตามวัน)
  // และตัดสินเสมอด้วย id เพื่อให้ลำดับนิ่งไม่สลับไปมาระหว่างโหลด
  const dir = sortDir.value === 'asc' ? 1 : -1;
  return list.sort((a, b) => {
    const da = a.departure_date || '';
    const db = b.departure_date || '';
    if (da !== db) return da < db ? -dir : dir;
    return (b.id - a.id) * dir;
  });
});

const groupedSchedules = computed(() => {
  const groups = [];
  let current = null;
  for (const s of visibleSchedules.value) {
    const p = dateParts(s.departure_date);
    const key = p ? `${p.y}-${String(p.m).padStart(2, '0')}` : 'unknown';
    if (!current || current.key !== key) {
      current = {
        key,
        label: p ? `${THAI_MONTHS_FULL[p.m - 1]} ${p.y + 543}` : 'ไม่ระบุวันเดินทาง',
        items: [],
      };
      groups.push(current);
    }
    current.items.push(s);
  }
  return groups;
});

const hasDateRange = computed(() => Boolean(filters.from || filters.to));

const hasAnyFilter = computed(
  () => Boolean(filters.search || filters.tripId || hasDateRange.value)
    || photoFilter.value !== 'all'
    || timeTab.value !== 'all'
);

const isAllView = computed(
  () => timeTab.value === 'all' && photoFilter.value === 'all'
);

const isPendingView = computed(
  () => timeTab.value === 'past' && photoFilter.value === 'none'
);

const showAll = () => {
  timeTab.value = 'all';
  photoFilter.value = 'all';
};

const showPendingOnly = () => {
  timeTab.value = 'past';
  photoFilter.value = 'none';
};

const resetFilters = () => {
  filters.search = '';
  filters.tripId = '';
  photoFilter.value = 'all';
  timeTab.value = 'all';
  if (hasDateRange.value) clearDateRange();
};

const clearDateRange = () => {
  filters.from = '';
  filters.to = '';
  fetchSchedules();
};

// ─── ตัวเลือกทริป ──────────────────────────────────────────

const toggleTripPicker = async () => {
  tripPicker.open = !tripPicker.open;
  if (tripPicker.open) {
    tripPicker.query = '';
    await nextTick();
    tripSearchEl.value?.focus();
  }
};

const closeTripPicker = () => {
  tripPicker.open = false;
};

const pickTrip = (id) => {
  filters.tripId = id === '' ? '' : id;
  closeTripPicker();
  fetchSchedules();
};

const onDocumentClick = (e) => {
  if (tripPicker.open && tripPickerEl.value && !tripPickerEl.value.contains(e.target)) {
    closeTripPicker();
  }
};

// Esc ปิดชั้นบนสุดทีละชั้น — ไม่ให้ปิดโมดัลหลักทิ้งทั้งที่ยังยืนยันการลบค้างอยู่
const onKeydown = (e) => {
  if (e.key !== 'Escape') return;
  if (tripPicker.open) return closeTripPicker();
  if (deleteAll.open) return closeDeleteAllConfirm();
  if (applyPicker.open) return closeApplyPicker();
  if (manager.open && !uploading.value) return closeManager();
};

// ─── ข้อมูล ────────────────────────────────────────────────

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

// Other rounds of the same trip the current photo set can be shared with.
const applyTargets = computed(() => {
  if (!manager.schedule) return [];
  const tripId = manager.schedule.trip?.id ?? manager.schedule.trip_id;
  return schedules.value.filter(
    (s) => s.id !== manager.schedule.id &&
      (s.trip?.id ?? s.trip_id) === tripId
  );
});

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
    syncShareFlag();
  } catch {
    share.token = null;
    share.url = null;
  } finally {
    share.loading = false;
  }
};

// ให้ป้าย "แชร์อยู่" ในลิสต์ตรงกับความจริงทันทีโดยไม่ต้องโหลดรอบทั้งหมดใหม่
const syncShareFlag = () => {
  if (!manager.schedule) return;
  const row = schedules.value.find((s) => s.id === manager.schedule.id);
  if (row) row.photos_shared = Boolean(share.url);
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
    syncShareFlag();
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
    syncShareFlag();
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
  dragOver.value = false;
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
const onDrop = (e) => {
  dragOver.value = false;
  uploadFiles(Array.from(e.dataTransfer.files || []));
};

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

const statusLabel = (s) => ({
  open: 'เปิดจอง',
  closed: 'ปิดจอง',
  cancelled: 'ยกเลิก',
  completed: 'จบทริป',
})[s] || s;

onMounted(async () => {
  document.addEventListener('click', onDocumentClick);
  document.addEventListener('keydown', onKeydown);
  await Promise.all([fetchTrips(), fetchSchedules()]);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick);
  document.removeEventListener('keydown', onKeydown);
});
</script>

<style scoped>
@import url('./admin-shared.css');

.heading-icon {
  color: var(--color-accent);
  font-size: 28px;
}

/* ─── สรุปยอด ─────────────────────────── */
.stat-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.stat-tile {
  display: flex;
  flex-direction: column;
  gap: 2px;
  align-items: flex-start;
  text-align: left;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 14px 16px;
  cursor: pointer;
  font-family: inherit;
  transition: border-color 0.15s, background 0.15s;
}

.stat-tile.static { cursor: default; }
.stat-tile:not(.static):hover { border-color: #9ca3af; }

.stat-tile.is-active {
  border-color: var(--color-accent, #2d7a4f);
  background: #f0faf4;
}

.stat-tile.accent-warn.is-active {
  border-color: #d97706;
  background: #fffbeb;
}

.stat-value {
  font-size: 24px;
  font-weight: 800;
  color: #111827;
  line-height: 1.1;
}

.stat-tile.accent-warn .stat-value { color: #b45309; }

.stat-label {
  font-size: 12px;
  color: #6b7280;
}

/* ─── แถบเครื่องมือ ───────────────────── */
.toolbar {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 12px 14px;
  margin-bottom: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.toolbar-row.secondary {
  border-top: 1px solid #f3f4f6;
  padding-top: 10px;
}

.search-box.grow { flex: 1; min-width: 240px; }

.search-box .clear-btn {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  border: none;
  background: none;
  color: #9ca3af;
  cursor: pointer;
  display: flex;
  padding: 2px;
}

.search-box .clear-btn .material-symbols-rounded {
  position: static;
  transform: none;
  font-size: 18px;
}

.search-box .clear-btn:hover { color: #374151; }

.sort-select {
  padding: 9px 12px;
  background: #fff;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  color: #111827;
  cursor: pointer;
  font-family: inherit;
}

/* ─── ตัวเลือกทริปแบบค้นหาได้ ─────────── */
.trip-picker { position: relative; }

.picker-trigger {
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 280px;
  padding: 9px 12px;
  background: #fff;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  color: #111827;
  cursor: pointer;
  transition: border-color 0.15s;
}

.picker-trigger:hover { border-color: #9ca3af; }

.picker-trigger.has-value {
  border-color: var(--color-accent, #2d7a4f);
  background: #f0faf4;
  color: #14532d;
  font-weight: 600;
}

.picker-trigger .material-symbols-rounded { font-size: 18px; color: #6b7280; }
.picker-trigger.has-value .material-symbols-rounded { color: var(--color-accent, #2d7a4f); }

.picker-label {
  max-width: 190px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.picker-menu {
  position: absolute;
  z-index: 40;
  top: calc(100% + 6px);
  left: 0;
  width: 340px;
  max-width: 90vw;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  overflow: hidden;
}

.picker-search {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  border-bottom: 1px solid #f3f4f6;
}

.picker-search .material-symbols-rounded { font-size: 18px; color: #9ca3af; }

.picker-search input {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  font-size: 14px;
  font-family: inherit;
  color: #111827;
  background: transparent;
}

.picker-list {
  max-height: 320px;
  overflow-y: auto;
  padding: 4px;
}

.picker-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  text-align: left;
  padding: 9px 10px;
  border: none;
  background: none;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  color: #374151;
  cursor: pointer;
}

.picker-option:hover { background: #f5f5f5; }

.picker-option.selected {
  background: #f0faf4;
  color: #14532d;
  font-weight: 700;
}

.picker-option-title {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.picker-count {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 600;
  color: #9ca3af;
}

.picker-empty {
  padding: 18px 10px;
  text-align: center;
  font-size: 13px;
  color: #9ca3af;
}

/* ─── แท็บ / ชิป ──────────────────────── */
.segmented {
  display: flex;
  background: #f3f4f6;
  border-radius: 9px;
  padding: 3px;
  gap: 2px;
}

.segment {
  display: flex;
  align-items: center;
  gap: 6px;
  border: none;
  background: none;
  padding: 6px 12px;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  color: #6b7280;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.segment:hover { color: #374151; }

.segment.active {
  background: #fff;
  color: #111827;
}

.segment-count {
  font-size: 11px;
  font-weight: 700;
  color: #9ca3af;
}

.segment.active .segment-count { color: var(--color-accent, #2d7a4f); }

.chip-group { display: flex; gap: 6px; flex-wrap: wrap; }

.chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 11px;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.15s;
}

.chip .material-symbols-rounded { font-size: 16px; }
.chip:hover { border-color: #9ca3af; color: #374151; }

.chip.active {
  background: var(--color-accent, #2d7a4f);
  border-color: var(--color-accent, #2d7a4f);
  color: #fff;
}

.ghost-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-left: auto;
  padding: 6px 11px;
  border: 1px solid transparent;
  background: none;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  color: #6b7280;
  cursor: pointer;
}

.ghost-btn .material-symbols-rounded { font-size: 16px; }
.ghost-btn:hover { background: #f5f5f5; color: #374151; }
.ghost-btn.active { color: var(--color-accent, #2d7a4f); border-color: #e5e7eb; }

.range-row { border-top: 1px solid #f3f4f6; padding-top: 10px; }

.range-field {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #6b7280;
}

.range-field input {
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 7px 10px;
  font-size: 14px;
  font-family: inherit;
  color: #111827;
}

/* ─── รายการรอบเดินทาง ────────────────── */
.schedule-list {
  display: flex;
  flex-direction: column;
}

.month-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 20px;
  background: #FAFAFA;
  border-bottom: 1px solid #eee;
  border-top: 1px solid #eee;
}

.schedule-list > .month-head:first-child { border-top: none; }

.month-name {
  font-size: 13px;
  font-weight: 800;
  color: #374151;
  letter-spacing: 0.01em;
}

.month-count {
  font-size: 12px;
  color: #9ca3af;
  font-weight: 600;
}

.schedule-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 20px;
  border-bottom: 1px solid #f0f0f0;
  cursor: pointer;
  transition: background 0.15s;
}

.schedule-row:last-child { border-bottom: none; }
.schedule-row:hover { background: #FAFAFA; }
.schedule-row:focus-visible {
  outline: 2px solid var(--color-accent, #2d7a4f);
  outline-offset: -2px;
}

.date-chip {
  flex-shrink: 0;
  width: 58px;
  padding: 7px 0;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  line-height: 1.15;
}

.date-chip.past { background: #FAFAFA; }

.date-day {
  font-size: 19px;
  font-weight: 800;
  color: #111827;
}

.date-month {
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent, #2d7a4f);
}

.date-year {
  font-size: 10px;
  color: #9ca3af;
  font-weight: 600;
}

.schedule-info { flex: 1; min-width: 0; }

.schedule-trip {
  font-weight: 700;
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
  margin-left: 6px;
  vertical-align: 1px;
  letter-spacing: 0.02em;
}

.schedule-meta {
  font-size: 13px;
  color: #6b7280;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
}

.meta-item .material-symbols-rounded { font-size: 15px; color: #9ca3af; }

.row-tags {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.share-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: #1d4ed8;
  background: #eff6ff;
  padding: 5px 10px;
  border-radius: 999px;
}

.share-tag .material-symbols-rounded { font-size: 15px; }

.photo-count {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 13px;
  color: var(--color-accent, #2d7a4f);
  background: rgba(45, 122, 79, 0.08);
  padding: 6px 12px;
  border-radius: 999px;
  white-space: nowrap;
}

.photo-count .material-symbols-rounded { font-size: 16px; }

.photo-count.empty {
  color: #9ca3af;
  background: #f3f4f6;
}

/* รอบที่จบไปแล้วแต่ยังไม่มีรูป = งานค้าง จึงเน้นให้เห็น */
.photo-count.needs-photos {
  color: #b45309;
  background: #fef3c7;
}

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

.btn-sm .material-symbols-rounded { font-size: 16px; }

/* ต้องเจาะจงพอที่จะชนะกฎ .admin-page .material-symbols-rounded { font-size: 24px } */
.empty-state .empty-icon.material-symbols-rounded {
  font-size: 48px;
  color: #d1d5db;
  display: block;
  margin-bottom: 12px;
}

.reset-btn { margin-top: 14px; }

/* ─── โมดัลจัดการรูป ──────────────────── */
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

.upload-zone.large:hover,
.upload-zone.large.dragging {
  border-color: var(--color-accent, #2d7a4f);
  background: #f0fdf4;
}

.upload-placeholder {
  text-align: center;
  color: #6b7280;
  /* ไม่ให้ dragleave เด้งตอนลากผ่านข้อความข้างใน */
  pointer-events: none;
}

.upload-placeholder .material-symbols-rounded {
  color: var(--color-accent, #2d7a4f);
}

.retention-panel {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  border: 1px solid #fde68a;
  background: #fffbeb;
  border-radius: 12px;
  padding: 11px 14px;
  margin-bottom: 16px;
  font-size: 13px;
  line-height: 1.5;
  color: #92400e;
}

.retention-panel .material-symbols-rounded {
  font-size: 18px;
  color: #b45309;
  margin-top: 1px;
}

.retention-panel.urgent {
  border-color: #fecaca;
  background: #fef2f2;
  color: #991b1b;
}

.retention-panel.urgent .material-symbols-rounded { color: #dc2626; }

.retention-hint {
  margin-top: 2px;
  font-size: 12px;
  opacity: 0.8;
}

.expiry-badge {
  position: absolute;
  left: 6px;
  bottom: 6px;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  color: #fff;
}

.expiry-badge.warn { background: rgba(180, 83, 9, 0.9); }
.expiry-badge.danger { background: rgba(220, 38, 38, 0.92); }

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

.photo-delete,
.photo-open {
  position: absolute;
  top: 6px;
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
  opacity: 0;
  transition: background 0.15s, opacity 0.15s;
}

.photo-delete { right: 6px; }
.photo-open { left: 6px; text-decoration: none; }
.photo-open .material-symbols-rounded { font-size: 16px; }

.photo-tile:hover .photo-delete,
.photo-tile:hover .photo-open,
.photo-delete:focus-visible,
.photo-open:focus-visible { opacity: 1; }

.photo-delete:hover { background: rgba(220, 38, 38, 0.85); }
.photo-open:hover { background: rgba(0, 0, 0, 0.8); }
.photo-delete:disabled { opacity: 1; cursor: progress; }

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

/* ─── จอแคบ ───────────────────────────── */
@media (max-width: 860px) {
  .schedule-row {
    flex-wrap: wrap;
    align-items: flex-start;
  }

  .schedule-info { flex-basis: calc(100% - 74px); }

  .row-tags,
  .action-btns { margin-left: 74px; }

  .ghost-btn { margin-left: 0; }
}
</style>
