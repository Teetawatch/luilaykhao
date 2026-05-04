<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">event_seat</span> ตารางรอบและที่นั่งว่าง</h1>
        <p class="page-subtitle">ดูภาพรวมรอบเดินทางเพื่อเช็คสถานะและแจ้งข้อมูลลูกค้า</p>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" @click="fetchData">
          <span class="material-symbols-rounded">refresh</span> รีเฟรช
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="searchQuery" placeholder="ค้นหาชื่อทริป..." />
      </div>
      <div class="filter-group">
        <select v-model="filterStatus">
          <option value="">ทุกสถานะ</option>
          <option value="open">เปิดรับจอง</option>
          <option value="full">เต็ม</option>
          <option value="closed">ปิด</option>
        </select>
      </div>
    </div>

    <!-- Content -->
    <div v-if="admin.loading && !schedules.length" class="loading-state">
      <div class="spinner"></div>
    </div>
    
    <div v-else class="overview-container">
      <div v-if="!groupedSchedules.length" class="empty-card">
        <span class="material-symbols-rounded" style="font-size: 48px; color: #cbd5e1; margin-bottom: 12px;">event_busy</span>
        <p v-if="searchQuery || filterStatus">ไม่พบข้อมูลรอบเดินทางที่ตรงกับเงื่อนไขการค้นหา</p>
        <p v-else>ไม่พบข้อมูลรอบเดินทางในระบบขณะนี้</p>
      </div>

      <div v-for="region in filteredGroups" :key="region.region_key" class="region-block">
        <div class="region-header">
          <span class="material-symbols-rounded">map</span>
          {{ region.region_label }}
          <span class="region-count">({{ region.trips.length }} ทริป)</span>
        </div>

        <div v-for="trip in region.trips" :key="trip.trip_id" class="trip-section">
          <div class="trip-section-header">
            <div class="tsh-info">
              <h2 class="tsh-title">{{ trip.trip_title }}</h2>
              <span class="tsh-badge" :class="`badge-${trip.trip_type}`">{{ trip.trip_type_label }}</span>
            </div>
            <span class="tsh-count">{{ trip.schedules.length }} รอบเดินทาง</span>
          </div>
          
          <div class="schedule-grid">
            <div v-for="sch in trip.schedules" :key="sch.id" class="schedule-card" :class="{ 'card-full': sch.available_seats === 0 }">
              <div class="card-header">
                <div class="sch-status">
                   <span class="status-dot" :class="`dot-${sch.status}`"></span>
                   <span class="status-label">{{ statusLabels[sch.status] }}</span>
                </div>
                <span class="sch-price">฿{{ Number(sch.price).toLocaleString() }}</span>
              </div>

              <div class="sch-dates">
                <div class="date-item">
                  <span class="material-symbols-rounded">calendar_today</span>
                  <div class="date-info">
                    <span class="d-label">ไป</span>
                    <span class="d-value">{{ formatDate(sch.start) }}</span>
                  </div>
                </div>
                <div class="date-item" v-if="sch.end && sch.end !== sch.start">
                  <span class="material-symbols-rounded">calendar_month</span>
                  <div class="date-info">
                    <span class="d-label">กลับ</span>
                    <span class="d-value">{{ formatDate(sch.end) }}</span>
                  </div>
                </div>
              </div>

              <div class="sch-vehicle" v-if="sch.vehicle || sch.transport_type">
                  <span v-if="sch.transport_type === 'van'" class="material-symbols-rounded" style="color:var(--color-accent);">airport_shuttle</span>
                  <span v-else-if="sch.transport_type === 'boat'" class="material-symbols-rounded" style="color:var(--color-accent);">directions_boat</span>
                  <span v-else class="material-symbols-rounded" style="color:var(--color-accent);">directions_bus</span>
                  <span class="v-name">{{ sch.vehicle || sch.transport_type }}</span>
              </div>

              <div class="sch-seats">
                <div class="seats-header">
                  <span class="sh-label">ที่นั่งว่าง</span>
                  <div class="sh-stats">
                    <span class="sh-avail" :class="{ 'text-full': sch.available_seats === 0, 'text-low': sch.available_seats > 0 && sch.available_seats <= 3 }">
                      {{ sch.available_seats }}
                    </span>
                    <span class="sh-total">/ {{ sch.total_seats }}</span>
                  </div>
                </div>
                <div class="seats-progress">
                  <div class="progress-track">
                    <div class="progress-fill" :style="{ width: (sch.booked_seats / sch.total_seats * 100) + '%' }"></div>
                  </div>
                </div>
              </div>

              <div class="card-actions">
                <button class="btn-view-seats" @click="viewSeatLayout(sch)" v-if="sch.status !== 'cancelled'">
                  <span class="material-symbols-rounded">grid_view</span> แผงผังที่นั่ง
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Seat Layout Modal -->
    <div class="modal-overlay" v-if="selectedSchedule" @click.self="selectedSchedule = null">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">ผังที่นั่ง: {{ selectedSchedule.trip_title }}</h2>
            <p class="modal-subtitle">{{ formatDate(selectedSchedule.start) }} | {{ selectedSchedule.vehicle || 'ไม่ระบุพาหนะ' }}</p>
          </div>
          <button class="modal-close" @click="selectedSchedule = null">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body seat-map-body">
          <div v-if="loadingSeats" class="loading-seats">
            <div class="spinner"></div>
            <span>กำลังโหลดผังที่นั่ง...</span>
          </div>
          <template v-else>
            <div v-if="!seatData" class="no-seat-map">
              <span class="material-symbols-rounded">info</span>
              <p>รอบนี้ไม่มีข้อมูลผังที่นั่งแบบกราฟิก</p>
              <div class="seat-stats-box">
                <p>จำนวนที่นั่งทั้งหมด: {{ selectedSchedule.total_seats }}</p>
                <p>จองแล้ว: {{ selectedSchedule.booked_seats }}</p>
                <p class="text-accent">ว่าง: {{ selectedSchedule.available_seats }}</p>
              </div>
            </div>
            
            <div v-else class="seat-map-container">
              <SeatMap :seat-map="seatData" :show-names="true" />
              
              <!-- Legend from component is already included, but we can keep the local one if we want specific admin colors -->
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import axios from 'axios';
import SeatMap from '../../components/SeatMap.vue';

const admin = useAdminStore();
const schedules = computed(() => admin.calendarEvents || []);
const searchQuery = ref('');
const filterStatus = ref('');
const selectedSchedule = ref(null);
const seatData = ref(null);
const loadingSeats = ref(false);

const statusLabels = { open: 'เปิดรับจอง', closed: 'ปิด', full: 'เต็ม', cancelled: 'ยกเลิก' };

const tripTypeLabels = {
  trekking: 'เดินป่า',
  diving: 'ดำน้ำ',
  snorkeling: 'ดำน้ำตื้น',
  climbing: 'ปีนผา'
};

const formatDate = (d) => {
  if (!d) return '-';
  try {
    return new Date(d).toLocaleDateString('th-TH', { 
      day: 'numeric', 
      month: 'short', 
      year: 'numeric',
      weekday: 'short'
    });
  } catch (e) {
    return d;
  }
};

const fetchData = async () => {
  try {
    // Use a wider range: from 1 month ago to 1 year in the future
    const start = new Date();
    start.setMonth(start.getMonth() - 1);
    const startStr = start.toISOString().split('T')[0];
    
    const end = new Date();
    end.setFullYear(end.getFullYear() + 1);
    const endStr = end.toISOString().split('T')[0];
    
    await admin.fetchCalendarSchedules({ 
      start: startStr, 
      end: endStr 
    });
  } catch (e) {
    console.error('Failed to fetch schedules', e);
  }
};

const regionLabels = {
  bangkok: 'กรุงเทพมหานคร',
  north: 'ภาคเหนือ',
  central: 'ภาคกลาง',
  south: 'ภาคใต้',
  east: 'ภาคตะวันออก',
  northeast: 'ภาคอีสาน',
  west: 'ภาคตะวันตก',
  other: 'ไม่ระบุภาค'
};

const groupedSchedules = computed(() => {
  const regionMap = {};
  const data = schedules.value;
  
  if (!Array.isArray(data)) return [];
  
  data.forEach(sch => {
    // Filter by search
    if (searchQuery.value && !sch.trip_title.toLowerCase().includes(searchQuery.value.toLowerCase())) {
      return;
    }
    
    // Filter by status
    if (filterStatus.value && sch.status !== filterStatus.value) {
      return;
    }

    const regionKey = sch.trip_region || 'other';
    if (!regionMap[regionKey]) {
      regionMap[regionKey] = {
        region_key: regionKey,
        region_label: regionLabels[regionKey] || 'ไม่ระบุภาค',
        trips: {}
      };
    }

    if (!regionMap[regionKey].trips[sch.trip_id]) {
      regionMap[regionKey].trips[sch.trip_id] = {
        trip_id: sch.trip_id,
        trip_title: sch.trip_title,
        trip_type: sch.trip_type,
        trip_type_label: tripTypeLabels[sch.trip_type] || sch.trip_type,
        schedules: []
      };
    }
    regionMap[regionKey].trips[sch.trip_id].schedules.push(sch);
  });

  // Convert to sorted array
  return Object.values(regionMap).map(r => ({
    ...r,
    trips: Object.values(r.trips).sort((a, b) => a.trip_title.localeCompare(b.trip_title))
  })).sort((a, b) => {
    const order = ['bangkok', 'central', 'north', 'northeast', 'east', 'west', 'south', 'other'];
    const idxA = order.indexOf(a.region_key);
    const idxB = order.indexOf(b.region_key);
    return (idxA === -1 ? 99 : idxA) - (idxB === -1 ? 99 : idxB);
  });
});

const filteredGroups = computed(() => groupedSchedules.value);

const viewSeatLayout = async (sch) => {
  selectedSchedule.value = sch;
  loadingSeats.value = true;
  seatData.value = null;
  
  try {
    const res = await axios.get(`/api/v1/schedules/${sch.id}/seats`);
    if (res.data.success) {
      seatData.value = res.data.data;
    }
  } catch (e) {
    console.error('Failed to fetch seat layout', e);
  } finally {
    loadingSeats.value = false;
  }
};

onMounted(fetchData);
</script>

<style scoped>
@import url('./admin-shared.css');

.overview-container {
  display: block;
}

.region-block {
  margin-bottom: 40px;
}

.region-header {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 20px;
  font-weight: 800;
  color: var(--color-primary);
  margin-bottom: 24px;
  padding-bottom: 12px;
  border-bottom: 2px solid var(--color-sand-dark);
}

.region-header .material-symbols-rounded {
  font-size: 24px;
  color: var(--color-accent);
}

.region-count {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-muted);
  margin-left: 8px;
}

.trip-section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--color-sand-dark);
}

.tsh-info {
  display: flex;
  align-items: center;
  gap: 12px;
}

.tsh-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-text-dark);
  margin: 0;
}

.tsh-badge {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 12px;
  font-weight: 600;
  text-transform: uppercase;
}

.badge-trekking { background: #dcfce7; color: #166534; }
.badge-diving { background: #dbeafe; color: #1e40af; }
.badge-snorkeling { background: #e0f2fe; color: #075985; }
.badge-climbing { background: #fef3c7; color: #92400e; }

.tsh-count {
  font-size: 13px;
  color: var(--color-text-muted);
}

.schedule-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
}

.schedule-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  transition: transform 0.2s, box-shadow 0.2s;
}

.schedule-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.card-full {
  border-left: 4px solid #f59e0b;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.sch-status {
  display: flex;
  align-items: center;
  gap: 6px;
}

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.dot-open { background: #10b981; }
.dot-full { background: #f59e0b; }
.dot-closed { background: #94a3b8; }

.status-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-mid);
}

.sch-price {
  font-weight: 700;
  color: var(--color-accent);
}

.sch-dates {
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: var(--color-sand);
  padding: 10px;
  border-radius: 8px;
}

.date-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.date-item i, .date-item .material-symbols-rounded {
  font-size: 18px;
  color: var(--color-text-muted);
}

.date-info {
  display: flex;
  flex-direction: column;
}

.d-label {
  font-size: 10px;
  color: var(--color-text-muted);
  text-transform: uppercase;
  font-weight: 700;
}

.d-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-dark);
}

.sch-vehicle {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-text-mid);
}

.sch-seats {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.seats-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}

.sh-label {
  font-size: 12px;
  color: var(--color-text-muted);
  font-weight: 600;
}

.sh-stats {
  display: flex;
  align-items: baseline;
  gap: 2px;
}

.sh-avail {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-accent);
}

.sh-total {
  font-size: 12px;
  color: var(--color-text-muted);
}

.text-full { color: #ef4444; }
.text-low { color: #f59e0b; }

.progress-track {
  height: 6px;
  background: var(--color-sand-dark);
  border-radius: 3px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: var(--color-accent);
  transition: width 0.3s ease;
}

.card-actions {
  margin-top: 4px;
}

.btn-view-seats {
  width: 100%;
  padding: 8px;
  background: transparent;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  color: var(--color-text-mid);
  font-size: 13px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-view-seats:hover {
  background: var(--color-sand);
  color: var(--color-accent);
  border-color: var(--color-accent);
}

/* Modal / Seat Map */
.seat-map-body {
  min-height: 300px;
}

.no-seat-map {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px;
  color: var(--color-text-muted);
  text-align: center;
}

.seat-stats-box {
  margin-top: 20px;
  padding: 16px;
  background: var(--color-sand);
  border-radius: 12px;
  width: 100%;
  max-width: 300px;
}

.seat-stats-box p {
  margin: 4px 0;
  font-weight: 600;
}

.text-accent { color: var(--color-accent); }



@media (max-width: 640px) {
  .schedule-grid {
    grid-template-columns: 1fr;
  }
}
</style>
