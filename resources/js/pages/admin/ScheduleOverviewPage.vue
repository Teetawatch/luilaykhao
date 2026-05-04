<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">event_seat</span>
          ตารางรอบและที่นั่งว่าง
        </h1>
        <p class="page-subtitle">
          ดูภาพรวมรอบเดินทาง สถานะการจอง ที่นั่งว่าง จุดรับ และผังที่นั่งสำหรับแจ้งลูกค้า
        </p>
      </div>
      <div class="header-actions">
        <span v-if="lastUpdated" class="last-updated">อัปเดตล่าสุด {{ lastUpdated }}</span>
        <button class="btn-secondary" :disabled="admin.loading" @click="fetchData">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': admin.loading }">refresh</span>
          {{ admin.loading ? 'กำลังโหลด' : 'รีเฟรช' }}
        </button>
      </div>
    </div>

    <div class="summary-grid">
      <div class="summary-card">
        <span class="summary-icon material-symbols-rounded">calendar_month</span>
        <div>
          <span class="summary-label">รอบที่แสดง</span>
          <strong class="summary-value">{{ visibleStats.totalSchedules }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon open material-symbols-rounded">event_available</span>
        <div>
          <span class="summary-label">เปิดรับจอง</span>
          <strong class="summary-value">{{ visibleStats.openSchedules }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon seats material-symbols-rounded">airline_seat_recline_normal</span>
        <div>
          <span class="summary-label">ที่นั่งว่างรวม</span>
          <strong class="summary-value">{{ visibleStats.availableSeats }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon warning material-symbols-rounded">priority_high</span>
        <div>
          <span class="summary-label">ใกล้เต็มหรือเต็ม</span>
          <strong class="summary-value">{{ visibleStats.attentionSchedules }}</strong>
        </div>
      </div>
    </div>

    <div class="filters-panel">
      <div class="filters-bar overview-filters">
        <div class="search-box wide">
          <span class="material-symbols-rounded">search</span>
          <input v-model.trim="filters.search" placeholder="ค้นหาชื่อทริป ยานพาหนะ หรือจุดรับ..." />
        </div>

        <select v-model="filters.status" aria-label="สถานะรอบเดินทาง">
          <option value="">ทุกสถานะ</option>
          <option value="open">เปิดรับจอง</option>
          <option value="full">เต็ม</option>
          <option value="closed">ปิด</option>
          <option value="cancelled">ยกเลิก</option>
        </select>

        <select v-model="filters.region" aria-label="ภูมิภาค">
          <option value="">ทุกภูมิภาค</option>
          <option v-for="region in regionOptions" :key="region.value" :value="region.value">
            {{ region.label }}
          </option>
        </select>

        <select v-model="filters.dateRange" aria-label="ช่วงวันที่">
          <option value="upcoming">รอบที่กำลังจะถึง</option>
          <option value="today">วันนี้</option>
          <option value="week">7 วันข้างหน้า</option>
          <option value="month">30 วันข้างหน้า</option>
          <option value="all">ทั้งหมดที่โหลดมา</option>
        </select>

        <select v-model="filters.sortBy" aria-label="เรียงลำดับ">
          <option value="date">วันเดินทางเร็วสุด</option>
          <option value="available">ที่นั่งว่างน้อยสุด</option>
          <option value="booked">จองมากสุด</option>
          <option value="price">ราคาต่ำสุด</option>
        </select>

        <button class="btn-secondary compact" :disabled="!hasActiveFilters" @click="resetFilters">
          <span class="material-symbols-rounded">filter_alt_off</span>
          ล้างตัวกรอง
        </button>
      </div>

      <div class="filter-footnote">
        แสดง {{ visibleStats.totalSchedules }} จาก {{ allStats.totalSchedules }} รอบ
        <span v-if="visibleStats.totalSchedules">รวม {{ visibleStats.totalTrips }} ทริปใน {{ visibleStats.totalRegions }} ภูมิภาค</span>
      </div>
    </div>

    <div v-if="admin.loading && !schedules.length" class="loading-state">
      <div class="spinner"></div>
    </div>

    <div v-else class="overview-container">
      <div v-if="admin.error" class="alert-card">
        <span class="material-symbols-rounded">error</span>
        <span>{{ admin.error }}</span>
      </div>

      <div v-if="!groupedSchedules.length" class="empty-card overview-empty">
        <span class="material-symbols-rounded">event_busy</span>
        <p v-if="hasActiveFilters">ไม่พบรอบเดินทางที่ตรงกับเงื่อนไข</p>
        <p v-else>ไม่พบข้อมูลรอบเดินทางในระบบขณะนี้</p>
      </div>

      <section v-for="region in groupedSchedules" :key="region.region_key" class="region-block">
        <div class="region-header">
          <div class="region-title">
            <span class="material-symbols-rounded">map</span>
            <div>
              <h2>{{ region.region_label }}</h2>
              <p>{{ region.trips.length }} ทริป, {{ region.schedule_count }} รอบ</p>
            </div>
          </div>
          <div class="region-metrics">
            <span>ว่าง {{ region.available_seats }} ที่</span>
            <span>จองแล้ว {{ region.booked_seats }} ที่</span>
          </div>
        </div>

        <div v-for="trip in region.trips" :key="trip.trip_id" class="trip-section">
          <div class="trip-section-header">
            <div class="tsh-info">
              <h3 class="tsh-title">{{ trip.trip_title }}</h3>
              <span class="tsh-badge" :class="`badge-${trip.trip_type || 'other'}`">
                {{ trip.trip_type_label }}
              </span>
            </div>
            <div class="tsh-count">
              {{ trip.schedules.length }} รอบ
              <span>ว่าง {{ trip.available_seats }}/{{ trip.total_seats }}</span>
            </div>
          </div>

          <div class="schedule-grid">
            <article
              v-for="sch in trip.schedules"
              :key="sch.id"
              class="schedule-card"
              :class="cardClasses(sch)"
            >
              <div class="card-header">
                <span class="status-badge" :class="`status-${sch.status}`">
                  {{ statusLabels[sch.status] || sch.status || '-' }}
                </span>
                <span class="sch-price">{{ formatCurrency(sch.price) }}</span>
              </div>

              <div class="sch-dates">
                <div class="date-item">
                  <span class="material-symbols-rounded">calendar_today</span>
                  <div class="date-info">
                    <span class="d-label">วันเดินทาง</span>
                    <span class="d-value">{{ formatDate(sch.start) }}</span>
                  </div>
                </div>
                <div class="date-item">
                  <span class="material-symbols-rounded">event_repeat</span>
                  <div class="date-info">
                    <span class="d-label">วันกลับ</span>
                    <span class="d-value">{{ sch.end && sch.end !== sch.start ? formatDate(sch.end) : 'วันเดียวกัน' }}</span>
                  </div>
                </div>
              </div>

              <div class="info-row">
                <span class="material-symbols-rounded">{{ transportIcon(sch.transport_type) }}</span>
                <span>{{ sch.vehicle || transportLabels[sch.transport_type] || 'ไม่ระบุพาหนะ' }}</span>
              </div>

              <div class="seats-box">
                <div class="seats-header">
                  <span>ที่นั่ง</span>
                  <strong :class="seatTextClass(sch)">
                    ว่าง {{ safeNumber(sch.available_seats) }} / {{ safeNumber(sch.total_seats) }}
                  </strong>
                </div>
                <div class="progress-track" :aria-label="`จองแล้ว ${safeNumber(sch.booked_seats)} จาก ${safeNumber(sch.total_seats)} ที่นั่ง`">
                  <div class="progress-fill" :style="{ width: seatFillWidth(sch) }"></div>
                </div>
                <div class="seat-breakdown">
                  <span>จองแล้ว {{ safeNumber(sch.booked_seats) }}</span>
                  <span>ยืนยัน {{ safeNumber(sch.confirmed_bookings) }}</span>
                  <span>รอดำเนินการ {{ safeNumber(sch.pending_bookings) }}</span>
                </div>
              </div>

              <div v-if="sch.pickup_points?.length" class="pickup-preview">
                <div class="pickup-title">
                  <span class="material-symbols-rounded">location_on</span>
                  จุดรับ {{ sch.pickup_points.length }} จุด
                </div>
                <div class="pickup-list">
                  <span v-for="pt in sch.pickup_points.slice(0, 3)" :key="pt.id" class="pickup-pill">
                    {{ pt.region_label || regionLabels[pt.region] || pt.pickup_location }}
                  </span>
                  <span v-if="sch.pickup_points.length > 3" class="pickup-more">
                    +{{ sch.pickup_points.length - 3 }}
                  </span>
                </div>
              </div>

              <div v-else class="pickup-preview muted">
                <span class="material-symbols-rounded">location_off</span>
                ยังไม่มีจุดรับ
              </div>

              <div class="card-actions">
                <button class="btn-view-details" @click="openDetails(sch)">
                  <span class="material-symbols-rounded">info</span>
                  รายละเอียด
                </button>
                <button class="btn-view-seats" :disabled="sch.status === 'cancelled'" @click="viewSeatLayout(sch)">
                  <span class="material-symbols-rounded">grid_view</span>
                  ผังที่นั่ง
                </button>
              </div>
            </article>
          </div>
        </div>
      </section>
    </div>

    <div class="modal-overlay" v-if="selectedSchedule && activeModal === 'details'" @click.self="closeModal">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">{{ selectedSchedule.trip_title }}</h2>
            <p class="modal-subtitle">
              {{ formatDate(selectedSchedule.start) }} | {{ selectedSchedule.vehicle || transportLabels[selectedSchedule.transport_type] || 'ไม่ระบุพาหนะ' }}
            </p>
          </div>
          <button class="modal-close" @click="closeModal">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <div class="detail-grid">
            <div class="detail-item">
              <span class="detail-label">สถานะ</span>
              <span class="status-badge" :class="`status-${selectedSchedule.status}`">
                {{ statusLabels[selectedSchedule.status] || '-' }}
              </span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ราคา</span>
              <span class="detail-value">{{ formatCurrency(selectedSchedule.price) }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">วันเดินทาง</span>
              <span class="detail-value">{{ formatDate(selectedSchedule.start, 'long') }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">วันกลับ</span>
              <span class="detail-value">{{ selectedSchedule.end ? formatDate(selectedSchedule.end, 'long') : '-' }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ประเภทยานพาหนะ</span>
              <span class="detail-value">{{ transportLabels[selectedSchedule.transport_type] || '-' }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ยานพาหนะ</span>
              <span class="detail-value">{{ selectedSchedule.vehicle || '-' }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">จำนวนที่นั่งทั้งหมด</span>
              <span class="detail-value">{{ safeNumber(selectedSchedule.total_seats) }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">ที่นั่งว่าง</span>
              <span class="detail-value seats-avail">{{ safeNumber(selectedSchedule.available_seats) }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">จองแล้ว</span>
              <span class="detail-value">{{ safeNumber(selectedSchedule.booked_seats) }}</span>
            </div>
            <div class="detail-item">
              <span class="detail-label">รอดำเนินการ</span>
              <span class="detail-value">{{ safeNumber(selectedSchedule.pending_bookings) }}</span>
            </div>
          </div>

          <div class="pickup-summary">
            <div class="pickup-summary-title">
              <span class="material-symbols-rounded">location_on</span>
              จุดรับลูกค้า
            </div>
            <div v-if="selectedSchedule.pickup_points?.length" class="pickup-summary-list">
              <div v-for="pt in selectedSchedule.pickup_points" :key="pt.id" class="pickup-summary-item">
                <span class="pickup-summary-region">{{ pt.region_label || regionLabels[pt.region] || '-' }}</span>
                <span class="pickup-summary-loc">
                  {{ pt.pickup_location || '-' }}
                  <span v-if="pt.notes" class="pickup-summary-notes">· {{ pt.notes }}</span>
                </span>
                <span class="pickup-summary-price">{{ formatCurrency(pt.price) }}</span>
              </div>
            </div>
            <div v-else class="pickup-summary-empty">ยังไม่มีข้อมูลจุดรับสำหรับรอบนี้</div>
          </div>

          <div class="modal-footer">
            <button class="btn-secondary" @click="viewSeatLayout(selectedSchedule)">
              <span class="material-symbols-rounded">grid_view</span>
              ดูผังที่นั่ง
            </button>
            <router-link to="/admin/schedules" class="btn-primary">
              <span class="material-symbols-rounded">edit</span>
              จัดการรอบเดินทาง
            </router-link>
          </div>
        </div>
      </div>
    </div>

    <div class="modal-overlay" v-if="selectedSchedule && activeModal === 'seats'" @click.self="closeModal">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">ผังที่นั่ง: {{ selectedSchedule.trip_title }}</h2>
            <p class="modal-subtitle">
              {{ formatDate(selectedSchedule.start) }} | {{ selectedSchedule.vehicle || transportLabels[selectedSchedule.transport_type] || 'ไม่ระบุพาหนะ' }}
            </p>
          </div>
          <button class="modal-close" @click="closeModal">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body seat-map-body">
          <div class="seat-modal-summary">
            <span>ทั้งหมด {{ safeNumber(selectedSchedule.total_seats) }}</span>
            <span>จองแล้ว {{ safeNumber(selectedSchedule.booked_seats) }}</span>
            <strong>ว่าง {{ safeNumber(selectedSchedule.available_seats) }}</strong>
          </div>

          <div v-if="loadingSeats" class="loading-seats">
            <div class="spinner"></div>
            <span>กำลังโหลดผังที่นั่ง...</span>
          </div>

          <template v-else>
            <div v-if="seatError" class="alert-card">
              <span class="material-symbols-rounded">error</span>
              <span>{{ seatError }}</span>
            </div>

            <div v-else-if="!seatData" class="no-seat-map">
              <span class="material-symbols-rounded">info</span>
              <p>รอบนี้ไม่มีข้อมูลผังที่นั่งแบบกราฟิก</p>
            </div>

            <div v-else class="seat-map-container">
              <SeatMap :seat-map="seatData" :show-names="true" readonly />
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useAdminStore } from '../../stores/admin';
import api from '../../lib/axios';
import SeatMap from '../../components/SeatMap.vue';

const admin = useAdminStore();

const schedules = computed(() => Array.isArray(admin.calendarEvents) ? admin.calendarEvents : []);
const filters = reactive({
  search: '',
  status: '',
  region: '',
  dateRange: 'upcoming',
  sortBy: 'date',
});

const selectedSchedule = ref(null);
const activeModal = ref(null);
const seatData = ref(null);
const seatError = ref('');
const loadingSeats = ref(false);
const lastUpdated = ref('');

const statusLabels = {
  open: 'เปิดรับจอง',
  closed: 'ปิด',
  full: 'เต็ม',
  cancelled: 'ยกเลิก',
};

const tripTypeLabels = {
  trekking: 'เดินป่า',
  diving: 'ดำน้ำ',
  snorkeling: 'ดำน้ำตื้น',
  climbing: 'ปีนผา',
};

const transportLabels = {
  van: 'รถตู้',
  boat: 'เรือ',
  bus: 'รถบัส',
};

const regionLabels = {
  bangkok: 'กรุงเทพมหานคร',
  north: 'ภาคเหนือ',
  central: 'ภาคกลาง',
  south: 'ภาคใต้',
  east: 'ภาคตะวันออก',
  northeast: 'ภาคอีสาน',
  west: 'ภาคตะวันตก',
  other: 'ไม่ระบุภาค',
};

const regionOrder = ['bangkok', 'central', 'north', 'northeast', 'east', 'west', 'south', 'other'];

const regionOptions = computed(() => {
  const keys = new Set(schedules.value.map((sch) => sch.trip_region || 'other'));
  return [...keys]
    .sort((a, b) => regionSortValue(a) - regionSortValue(b))
    .map((key) => ({ value: key, label: regionLabels[key] || key }));
});

const hasActiveFilters = computed(() => {
  return Boolean(filters.search || filters.status || filters.region || filters.dateRange !== 'upcoming' || filters.sortBy !== 'date');
});

const allStats = computed(() => buildStats(schedules.value));

const filteredSchedules = computed(() => {
  const query = normalizeText(filters.search);
  const today = startOfDay(new Date());

  return schedules.value
    .filter((sch) => {
      if (filters.status && sch.status !== filters.status) return false;
      if (filters.region && (sch.trip_region || 'other') !== filters.region) return false;

      if (query) {
        const pickupText = (sch.pickup_points || [])
          .map((pt) => `${pt.region_label || ''} ${pt.pickup_location || ''} ${pt.notes || ''}`)
          .join(' ');
        const haystack = normalizeText(`${sch.trip_title || ''} ${sch.vehicle || ''} ${transportLabels[sch.transport_type] || ''} ${pickupText}`);
        if (!haystack.includes(query)) return false;
      }

      return inSelectedDateRange(sch, today);
    })
    .sort(sortSchedules);
});

const visibleStats = computed(() => buildStats(filteredSchedules.value));

const groupedSchedules = computed(() => {
  const regionMap = {};

  filteredSchedules.value.forEach((sch) => {
    const regionKey = sch.trip_region || 'other';
    const tripId = sch.trip_id || `trip-${sch.trip_title || 'unknown'}`;

    if (!regionMap[regionKey]) {
      regionMap[regionKey] = {
        region_key: regionKey,
        region_label: regionLabels[regionKey] || regionLabels.other,
        trips: {},
        schedule_count: 0,
        available_seats: 0,
        booked_seats: 0,
      };
    }

    if (!regionMap[regionKey].trips[tripId]) {
      regionMap[regionKey].trips[tripId] = {
        trip_id: tripId,
        trip_title: sch.trip_title || 'ไม่ระบุชื่อทริป',
        trip_type: sch.trip_type || 'other',
        trip_type_label: tripTypeLabels[sch.trip_type] || sch.trip_type || 'อื่น ๆ',
        schedules: [],
        available_seats: 0,
        booked_seats: 0,
        total_seats: 0,
      };
    }

    const trip = regionMap[regionKey].trips[tripId];
    trip.schedules.push(sch);
    trip.available_seats += safeNumber(sch.available_seats);
    trip.booked_seats += safeNumber(sch.booked_seats);
    trip.total_seats += safeNumber(sch.total_seats);

    regionMap[regionKey].schedule_count += 1;
    regionMap[regionKey].available_seats += safeNumber(sch.available_seats);
    regionMap[regionKey].booked_seats += safeNumber(sch.booked_seats);
  });

  return Object.values(regionMap)
    .map((region) => ({
      ...region,
      trips: Object.values(region.trips)
        .map((trip) => ({
          ...trip,
          schedules: [...trip.schedules].sort(sortSchedules),
        }))
        .sort((a, b) => a.trip_title.localeCompare(b.trip_title, 'th')),
    }))
    .sort((a, b) => regionSortValue(a.region_key) - regionSortValue(b.region_key));
});

function buildStats(items) {
  const tripIds = new Set();
  const regionIds = new Set();

  return items.reduce((stats, sch) => {
    tripIds.add(sch.trip_id || sch.trip_title);
    regionIds.add(sch.trip_region || 'other');

    const availableSeats = safeNumber(sch.available_seats);

    stats.totalSchedules += 1;
    stats.openSchedules += sch.status === 'open' ? 1 : 0;
    stats.availableSeats += availableSeats;
    stats.bookedSeats += safeNumber(sch.booked_seats);
    stats.attentionSchedules += availableSeats <= 3 || sch.status === 'full' ? 1 : 0;
    stats.totalTrips = tripIds.size;
    stats.totalRegions = regionIds.size;

    return stats;
  }, {
    totalSchedules: 0,
    openSchedules: 0,
    availableSeats: 0,
    bookedSeats: 0,
    attentionSchedules: 0,
    totalTrips: 0,
    totalRegions: 0,
  });
}

function inSelectedDateRange(sch, today) {
  if (filters.dateRange === 'all') return true;

  const start = startOfDay(scheduleDate(sch.start));
  if (!start) return false;

  if (filters.dateRange === 'today') {
    return start.getTime() === today.getTime();
  }

  if (filters.dateRange === 'week') {
    return start >= today && start <= addDays(today, 7);
  }

  if (filters.dateRange === 'month') {
    return start >= today && start <= addDays(today, 30);
  }

  return start >= today;
}

function sortSchedules(a, b) {
  if (filters.sortBy === 'available') {
    return safeNumber(a.available_seats) - safeNumber(b.available_seats) || dateValue(a.start) - dateValue(b.start);
  }

  if (filters.sortBy === 'booked') {
    return safeNumber(b.booked_seats) - safeNumber(a.booked_seats) || dateValue(a.start) - dateValue(b.start);
  }

  if (filters.sortBy === 'price') {
    return safeNumber(a.price) - safeNumber(b.price) || dateValue(a.start) - dateValue(b.start);
  }

  return dateValue(a.start) - dateValue(b.start);
}

function resetFilters() {
  filters.search = '';
  filters.status = '';
  filters.region = '';
  filters.dateRange = 'upcoming';
  filters.sortBy = 'date';
}

async function fetchData() {
  try {
    const start = new Date();
    start.setMonth(start.getMonth() - 1);

    const end = new Date();
    end.setFullYear(end.getFullYear() + 1);

    await admin.fetchCalendarSchedules({
      start: toDateKey(start),
      end: toDateKey(end),
    });

    lastUpdated.value = new Date().toLocaleTimeString('th-TH', {
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch (e) {
    console.error('Failed to fetch schedules', e);
  }
}

function openDetails(sch) {
  selectedSchedule.value = sch;
  activeModal.value = 'details';
  seatData.value = null;
  seatError.value = '';
}

async function viewSeatLayout(sch) {
  selectedSchedule.value = sch;
  activeModal.value = 'seats';
  loadingSeats.value = true;
  seatData.value = null;
  seatError.value = '';

  try {
    const res = await api.get(`/schedules/${sch.id}/seats`);
    seatData.value = res.data?.data || null;
  } catch (e) {
    console.error('Failed to fetch seat layout', e);
    seatError.value = e.response?.data?.message || 'ไม่สามารถโหลดผังที่นั่งได้';
  } finally {
    loadingSeats.value = false;
  }
}

function closeModal() {
  selectedSchedule.value = null;
  activeModal.value = null;
  seatData.value = null;
  seatError.value = '';
}

function cardClasses(sch) {
  return {
    'card-full': safeNumber(sch.available_seats) === 0 || sch.status === 'full',
    'card-low': safeNumber(sch.available_seats) > 0 && safeNumber(sch.available_seats) <= 3,
    'card-closed': ['closed', 'cancelled'].includes(sch.status),
  };
}

function seatTextClass(sch) {
  const availableSeats = safeNumber(sch.available_seats);
  return {
    'text-full': availableSeats === 0,
    'text-low': availableSeats > 0 && availableSeats <= 3,
    'text-accent': availableSeats > 3,
  };
}

function seatFillWidth(sch) {
  const totalSeats = safeNumber(sch.total_seats);
  if (!totalSeats) return '0%';
  return `${Math.min(100, (safeNumber(sch.booked_seats) / totalSeats) * 100)}%`;
}

function transportIcon(type) {
  if (type === 'van') return 'airport_shuttle';
  if (type === 'boat') return 'directions_boat';
  if (type === 'bus') return 'directions_bus';
  return 'commute';
}

function formatDate(value, style = 'short') {
  const date = scheduleDate(value);
  if (!date) return '-';

  return date.toLocaleDateString('th-TH', {
    day: 'numeric',
    month: style === 'long' ? 'long' : 'short',
    year: 'numeric',
    weekday: style === 'long' ? 'long' : 'short',
  });
}

function formatCurrency(value) {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    maximumFractionDigits: 0,
  }).format(safeNumber(value));
}

function safeNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
}

function normalizeText(value) {
  return String(value || '').trim().toLowerCase();
}

function scheduleDate(value) {
  if (!value) return null;
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function startOfDay(value) {
  if (!value) return null;
  const date = new Date(value);
  date.setHours(0, 0, 0, 0);
  return date;
}

function addDays(value, days) {
  const date = new Date(value);
  date.setDate(date.getDate() + days);
  return date;
}

function dateValue(value) {
  return scheduleDate(value)?.getTime() || 0;
}

function toDateKey(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function regionSortValue(key) {
  const index = regionOrder.indexOf(key);
  return index === -1 ? 99 : index;
}

onMounted(fetchData);
</script>

<style scoped>
@import url('./admin-shared.css');

.header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.last-updated {
  color: var(--color-text-muted);
  font-size: 12px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 18px;
}

.summary-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 14px;
}

.summary-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #f3f4f6;
  color: #4b5563;
  flex-shrink: 0;
}

.summary-icon.open {
  background: #ecfdf5;
  color: #059669;
}

.summary-icon.seats {
  background: #eff6ff;
  color: #2563eb;
}

.summary-icon.warning {
  background: #fffbeb;
  color: #d97706;
}

.summary-label {
  display: block;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 600;
}

.summary-value {
  display: block;
  color: var(--color-text-dark);
  font-size: 22px;
  line-height: 1.2;
}

.filters-panel {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 20px;
}

.overview-filters {
  margin-bottom: 8px;
}

.search-box.wide {
  min-width: 260px;
}

.compact {
  padding-inline: 12px;
  white-space: nowrap;
}

.filter-footnote {
  color: var(--color-text-muted);
  font-size: 12px;
}

.overview-container {
  display: block;
}

.alert-card {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 14px;
  margin-bottom: 16px;
  border-radius: 8px;
  border: 1px solid #fecaca;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 13px;
  font-weight: 600;
}

.overview-empty {
  text-align: center;
  padding: 48px;
  color: var(--color-text-muted);
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
}

.overview-empty .material-symbols-rounded {
  display: block;
  font-size: 48px;
  color: #cbd5e1;
  margin-bottom: 12px;
}

.region-block {
  margin-bottom: 30px;
}

.region-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 2px solid var(--color-sand-dark);
}

.region-title {
  display: flex;
  align-items: center;
  gap: 12px;
}

.region-title .material-symbols-rounded {
  color: var(--color-accent);
}

.region-title h2 {
  margin: 0;
  color: var(--color-primary);
  font-size: 20px;
  font-weight: 800;
}

.region-title p {
  margin: 2px 0 0;
  color: var(--color-text-muted);
  font-size: 12px;
}

.region-metrics {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 600;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.region-metrics span {
  background: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  border-radius: 999px;
  padding: 4px 10px;
}

.trip-section {
  margin-bottom: 22px;
}

.trip-section-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
}

.tsh-info {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.tsh-title {
  color: var(--color-text-dark);
  font-size: 17px;
  font-weight: 800;
  margin: 0;
  overflow-wrap: anywhere;
}

.tsh-badge {
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 9px;
  white-space: nowrap;
}

.badge-trekking { background: #dcfce7; color: #166534; }
.badge-diving { background: #dbeafe; color: #1e40af; }
.badge-snorkeling { background: #e0f2fe; color: #075985; }
.badge-climbing { background: #fef3c7; color: #92400e; }
.badge-other { background: #f3f4f6; color: #4b5563; }

.tsh-count {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.schedule-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
  gap: 14px;
}

.schedule-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 14px;
  transition: box-shadow 0.15s, transform 0.15s, border-color 0.15s;
}

.schedule-card:hover {
  transform: translateY(-1px);
  border-color: #b7dfc5;
  box-shadow: 0 8px 20px rgba(17, 24, 39, 0.05);
}

.card-full {
  border-left: 4px solid #ef4444;
}

.card-low {
  border-left: 4px solid #f59e0b;
}

.card-closed {
  opacity: 0.75;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.sch-price {
  color: var(--color-accent);
  font-weight: 800;
  white-space: nowrap;
}

.sch-dates {
  display: grid;
  gap: 8px;
  background: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px;
}

.date-item,
.info-row {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
}

.date-item .material-symbols-rounded,
.info-row .material-symbols-rounded {
  color: var(--color-text-muted);
  font-size: 19px;
  flex-shrink: 0;
}

.date-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.d-label {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 700;
}

.d-value {
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 700;
}

.info-row {
  color: var(--color-text-mid);
  font-size: 13px;
  font-weight: 600;
}

.seats-box {
  display: grid;
  gap: 7px;
}

.seats-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.progress-track {
  height: 7px;
  background: var(--color-sand-dark);
  border-radius: 999px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: var(--color-accent);
  border-radius: inherit;
  transition: width 0.25s;
}

.seat-breakdown {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  color: var(--color-text-muted);
  font-size: 11px;
  flex-wrap: wrap;
}

.text-accent { color: var(--color-accent); }
.text-full { color: #dc2626; }
.text-low { color: #d97706; }

.pickup-preview {
  display: grid;
  gap: 7px;
  color: var(--color-text-mid);
  font-size: 12px;
}

.pickup-preview.muted {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--color-text-muted);
}

.pickup-preview .material-symbols-rounded {
  color: var(--color-accent);
  font-size: 16px;
}

.pickup-title {
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: 700;
}

.pickup-list {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.pickup-pill,
.pickup-more {
  border: 1px solid #b7dfc5;
  background: #e8f5ec;
  color: var(--color-accent);
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 700;
}

.card-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: auto;
}

.btn-view-details,
.btn-view-seats {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  min-height: 36px;
  border-radius: 8px;
  border: 1px solid var(--color-sand-dark);
  background: var(--color-white);
  color: var(--color-text-mid);
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
  transition: all 0.15s;
}

.btn-view-details:hover,
.btn-view-seats:hover {
  background: var(--color-sand);
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.btn-view-seats:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.modal-lg {
  max-width: 860px;
}

.modal-xl {
  max-width: 980px;
}

.modal-title {
  margin: 0;
}

.modal-subtitle {
  margin: 4px 0 0;
  color: var(--color-text-muted);
  font-size: 13px;
}

.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.detail-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
}

.detail-label {
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.detail-value {
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 700;
}

.seats-avail {
  color: var(--color-accent);
}

.pickup-summary {
  margin-top: 18px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  overflow: hidden;
}

.pickup-summary-title {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 10px 12px;
  background: var(--color-sand);
  border-bottom: 1px solid var(--color-sand-dark);
  color: var(--color-accent);
  font-size: 13px;
  font-weight: 800;
}

.pickup-summary-title .material-symbols-rounded {
  font-size: 17px;
}

.pickup-summary-list {
  display: grid;
}

.pickup-summary-item {
  display: grid;
  grid-template-columns: 120px 1fr auto;
  gap: 10px;
  align-items: baseline;
  padding: 10px 12px;
  border-bottom: 1px solid var(--color-sand-dark);
  font-size: 13px;
}

.pickup-summary-item:last-child {
  border-bottom: none;
}

.pickup-summary-region,
.pickup-summary-price {
  color: var(--color-text-dark);
  font-weight: 800;
}

.pickup-summary-loc {
  color: var(--color-text-mid);
}

.pickup-summary-notes {
  color: var(--color-text-muted);
}

.pickup-summary-empty {
  padding: 16px;
  color: var(--color-text-muted);
  font-size: 13px;
}

.seat-map-body {
  min-height: 320px;
}

.seat-modal-summary {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

.seat-modal-summary span,
.seat-modal-summary strong {
  border-radius: 999px;
  background: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  color: var(--color-text-mid);
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 700;
}

.seat-modal-summary strong {
  color: var(--color-accent);
  background: #e8f5ec;
  border-color: #b7dfc5;
}

.loading-seats,
.no-seat-map {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  min-height: 260px;
  color: var(--color-text-muted);
  text-align: center;
}

.no-seat-map .material-symbols-rounded {
  color: #cbd5e1;
  font-size: 44px;
}

.seat-map-container {
  overflow-x: auto;
}

@media (max-width: 1024px) {
  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .summary-grid,
  .detail-grid {
    grid-template-columns: 1fr;
  }

  .region-header,
  .trip-section-header {
    align-items: stretch;
    flex-direction: column;
  }

  .region-metrics,
  .tsh-count {
    align-items: flex-start;
    justify-content: flex-start;
  }

  .pickup-summary-item {
    grid-template-columns: 1fr;
    gap: 4px;
  }
}

@media (max-width: 640px) {
  .schedule-grid,
  .card-actions {
    grid-template-columns: 1fr;
  }
}
</style>
