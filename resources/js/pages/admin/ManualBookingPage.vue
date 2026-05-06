<template>
  <div class="admin-page manual-booking-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">support_agent</span>
          จองแทนลูกค้า
        </h1>
        <p class="page-subtitle">กรอกข้อมูลทริป ผู้เดินทาง จุดรับ ที่นั่ง และส่งอีเมลยืนยันให้ลูกค้าในขั้นตอนเดียว</p>
      </div>
      <router-link to="/admin/bookings" class="btn-secondary">
        <span class="material-symbols-rounded">confirmation_number</span>
        ดูรายการจอง
      </router-link>
    </div>

    <div class="manual-layout">
      <form class="booking-workspace" @submit.prevent="submitBooking">
        <section class="booking-section">
          <div class="section-head">
            <span class="section-step">1</span>
            <div>
              <h2>เลือกทริปและรอบเดินทาง</h2>
              <p>เลือกรอบที่ลูกค้าต้องการ แล้วระบบจะโหลดจุดรับและที่นั่งว่างให้ทันที</p>
            </div>
          </div>

          <div class="form-grid">
            <label class="form-field full">
              <span>ทริป *</span>
              <select v-model.number="form.trip_id" required @change="onTripChange">
                <option value="">เลือกทริป</option>
                <option v-for="trip in trips" :key="trip.id" :value="trip.id">{{ trip.title }}</option>
              </select>
            </label>

            <label class="form-field full">
              <span>รอบเดินทาง *</span>
              <select v-model.number="form.schedule_id" :disabled="!form.trip_id || schedulesLoading" required @change="onScheduleChange">
                <option value="">เลือกรอบเดินทาง</option>
                <option v-for="schedule in schedules" :key="schedule.id" :value="schedule.id">
                  {{ formatDate(schedule.departure_date) }}
                  <template v-if="schedule.return_date"> - {{ formatDate(schedule.return_date) }}</template>
                  · ว่าง {{ schedule.available_seats }} ที่
                </option>
              </select>
            </label>

            <label v-if="selectedSchedule?.join_trip_enabled" class="booking-type-toggle full">
              <input v-model="form.is_join_trip" type="checkbox" @change="resetSeats" />
              <span class="material-symbols-rounded">group_add</span>
              <strong>จองแบบจอยทริป</strong>
              <small>ไม่ต้องเลือกที่นั่ง ใช้ราคาจอยทริป {{ formatCurrency(selectedSchedule.join_trip_price || selectedSchedule.price) }}</small>
            </label>

            <label v-if="pickupPoints.length && !form.is_join_trip" class="form-field full">
              <span>จุดรับ</span>
              <select v-model.number="form.pickup_point_id">
                <option value="">ไม่ระบุจุดรับ</option>
                <option v-for="point in pickupPoints" :key="point.id" :value="point.id">
                  {{ point.region_label || point.region }} · {{ point.pickup_location }} · {{ formatCurrency(point.price) }}
                </option>
              </select>
            </label>
          </div>
        </section>

        <section class="booking-section">
          <div class="section-head">
            <span class="section-step">2</span>
            <div>
              <h2>ข้อมูลลูกค้า</h2>
              <p>อีเมลนี้จะใช้ส่งใบยืนยันการจองและรายละเอียดการชำระเงิน</p>
            </div>
          </div>

          <div class="form-grid">
            <label class="form-field">
              <span>ชื่อลูกค้า *</span>
              <input v-model.trim="form.customer_name" required placeholder="เช่น คุณสมชาย ใจดี" />
            </label>
            <label class="form-field">
              <span>เบอร์โทรศัพท์ *</span>
              <input v-model.trim="form.phone" required type="tel" placeholder="0XXXXXXXXX" />
            </label>
            <label class="form-field">
              <span>อีเมล *</span>
              <input v-model.trim="form.email" required type="email" placeholder="customer@email.com" />
            </label>
            <label class="form-field">
              <span>สถานะการชำระเงิน</span>
              <select v-model="form.status">
                <option value="pending">รอชำระเงิน</option>
                <option value="confirmed">ชำระแล้ว / ยืนยันเลย</option>
              </select>
            </label>
            <label class="form-field">
              <span>วิธีชำระเงิน</span>
              <select v-model="form.payment_method">
                <option value="admin_manual">แอดมินรับจอง</option>
                <option value="promptpay">PromptPay</option>
                <option value="bank_transfer">โอนบัญชี</option>
                <option value="cash">เงินสด</option>
              </select>
            </label>
            <label class="email-toggle">
              <input v-model="form.send_email" type="checkbox" />
              <span class="material-symbols-rounded">mail</span>
              ส่งอีเมลให้ลูกค้าทันที
            </label>
          </div>
        </section>

        <section class="booking-section">
          <div class="section-head">
            <span class="section-step">3</span>
            <div>
              <h2>ผู้เดินทาง</h2>
              <p>เพิ่มรายชื่อผู้เดินทางให้ครบ จำนวนนี้จะใช้คำนวณราคาและจำนวนที่นั่ง</p>
            </div>
          </div>

          <div class="passenger-list">
            <article v-for="(passenger, index) in passengers" :key="passenger.key" class="passenger-card">
              <div class="passenger-card-head">
                <strong>คนที่ {{ index + 1 }}</strong>
                <button v-if="passengers.length > 1" class="btn-icon btn-delete" type="button" @click="removePassenger(index)">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
              <div class="form-grid">
                <label class="form-field">
                  <span>ชื่อ-นามสกุล *</span>
                  <input v-model.trim="passenger.name" required placeholder="ชื่อผู้เดินทาง" />
                </label>
                <label class="form-field">
                  <span>ชื่อเล่น</span>
                  <input v-model.trim="passenger.nickname" placeholder="ชื่อเล่น" />
                </label>
                <label class="form-field">
                  <span>โทรศัพท์</span>
                  <input v-model.trim="passenger.phone" type="tel" placeholder="ถ้ามี" />
                </label>
                <label class="form-field">
                  <span>เลขบัตร/พาสปอร์ต</span>
                  <input v-model.trim="passenger.id_card" placeholder="สำหรับประกัน" />
                </label>
                <label class="form-field">
                  <span>กรุ๊ปเลือด</span>
                  <input v-model.trim="passenger.blood_group" placeholder="เช่น O, A" />
                </label>
                <label class="checkbox-inline">
                  <input v-model="passenger.halal_food" type="checkbox" />
                  อาหารฮาลาล
                </label>
                <label class="form-field full">
                  <span>แพ้อาหาร/โรคประจำตัว</span>
                  <textarea v-model.trim="passenger.health_notes" rows="2" placeholder="ระบุถ้ามี"></textarea>
                </label>
                <label class="form-field">
                  <span>ผู้ติดต่อฉุกเฉิน</span>
                  <input v-model.trim="passenger.emergency_contact" placeholder="ชื่อผู้ติดต่อ" />
                </label>
                <label class="form-field">
                  <span>เบอร์ฉุกเฉิน</span>
                  <input v-model.trim="passenger.emergency_phone" type="tel" placeholder="เบอร์ติดต่อ" />
                </label>
              </div>
            </article>
          </div>

          <button class="btn-secondary add-passenger-btn" type="button" @click="addPassenger">
            <span class="material-symbols-rounded">person_add</span>
            เพิ่มผู้เดินทาง
          </button>
        </section>

        <section v-if="selectedSchedule && !form.is_join_trip" class="booking-section">
          <div class="section-head">
            <span class="section-step">4</span>
            <div>
              <h2>เลือกที่นั่ง</h2>
              <p>เลือก {{ passengers.length }} ที่นั่งให้ครบตามจำนวนผู้เดินทาง</p>
            </div>
          </div>

          <div v-if="seatsLoading" class="inline-loading"><div class="spinner"></div><span>กำลังโหลดผังที่นั่ง...</span></div>
          <div v-else-if="seatError" class="alert-card">
            <span class="material-symbols-rounded">error</span>
            <span>{{ seatError }}</span>
          </div>
          <div v-else-if="seatMap" class="seat-picker">
            <div class="seat-legend">
              <span><i class="legend-box available"></i>ว่าง</span>
              <span><i class="legend-box selected"></i>เลือกแล้ว</span>
              <span><i class="legend-box booked"></i>จองแล้ว/ล็อก</span>
            </div>

            <div class="seat-vehicle">
              <div class="seat-front">
                <span>{{ seatMap.front_label || 'หน้ารถ' }}</span>
                <span v-if="seatMap.show_driver !== false" class="driver-pill">
                  <span class="material-symbols-rounded">{{ seatMap.driver_icon || 'directions_car' }}</span>
                  คนขับ
                </span>
              </div>

              <div class="seat-grid" :style="seatGridStyle">
                <template v-for="cell in seatCells" :key="cell.key">
                  <div v-if="cell.type === 'aisle'" class="seat-aisle"></div>
                  <button
                    v-else-if="cell.seat"
                    type="button"
                    class="seat-button"
                    :class="seatButtonClass(cell.seat)"
                    :disabled="!canToggleSeat(cell.seat)"
                    :title="seatTitle(cell.seat)"
                    @click="toggleSeat(cell.seat)"
                  >
                    <span class="material-symbols-rounded">airline_seat_recline_normal</span>
                    <strong>{{ cell.seat.label || cell.seat.id }}</strong>
                    <small v-if="cell.seat.passenger_name">{{ cell.seat.passenger_name }}</small>
                  </button>
                  <div v-else class="seat-empty"></div>
                </template>
              </div>

              <div class="seat-rear">{{ seatMap.rear_label || 'ท้ายรถ' }}</div>
            </div>
          </div>
        </section>

        <div class="submit-bar">
          <button class="btn-primary" type="submit" :disabled="submitting || !canSubmit">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': submitting }">{{ submitting ? 'sync' : 'send' }}</span>
            สร้างการจองและส่งอีเมล
          </button>
          <span v-if="submitHint" class="submit-hint">{{ submitHint }}</span>
        </div>
      </form>

      <aside class="booking-summary">
        <div class="summary-panel">
          <h2>สรุปการจอง</h2>
          <div class="summary-row">
            <span>ทริป</span>
            <strong>{{ selectedTrip?.title || '-' }}</strong>
          </div>
          <div class="summary-row">
            <span>รอบเดินทาง</span>
            <strong>{{ selectedSchedule ? formatDate(selectedSchedule.departure_date) : '-' }}</strong>
          </div>
          <div class="summary-row">
            <span>ประเภท</span>
            <strong>{{ form.is_join_trip ? 'จอยทริป' : 'จองปกติ' }}</strong>
          </div>
          <div class="summary-row">
            <span>ผู้เดินทาง</span>
            <strong>{{ passengers.length }} คน</strong>
          </div>
          <div class="summary-row">
            <span>ที่นั่ง</span>
            <strong>{{ form.is_join_trip ? 'ไม่ต้องเลือก' : selectedSeatIds.join(', ') || '-' }}</strong>
          </div>
          <div class="summary-total">
            <span>ยอดรวม</span>
            <strong>{{ formatCurrency(totalAmount) }}</strong>
          </div>
        </div>

        <div v-if="createdBooking" class="success-panel">
          <span class="material-symbols-rounded">task_alt</span>
          <h2>สร้างการจองสำเร็จ</h2>
          <p>เลขจอง {{ createdBooking.booking_ref }}</p>
          <router-link :to="`/payment/${createdBooking.booking_ref}`" class="btn-secondary">เปิดหน้าชำระเงิน</router-link>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';

const toast = useToast();

const trips = ref([]);
const schedules = ref([]);
const seatMap = ref(null);
const selectedSeatIds = ref([]);
const schedulesLoading = ref(false);
const seatsLoading = ref(false);
const submitting = ref(false);
const seatError = ref('');
const createdBooking = ref(null);

const form = reactive({
  trip_id: '',
  schedule_id: '',
  customer_name: '',
  email: '',
  phone: '',
  status: 'pending',
  payment_method: 'admin_manual',
  is_join_trip: false,
  pickup_point_id: '',
  send_email: true,
});

const passengers = ref([newPassenger()]);

const selectedTrip = computed(() => trips.value.find((trip) => trip.id === Number(form.trip_id)) || null);
const selectedSchedule = computed(() => schedules.value.find((schedule) => schedule.id === Number(form.schedule_id)) || null);
const pickupPoints = computed(() => selectedSchedule.value?.pickup_points || []);
const selectedPickup = computed(() => pickupPoints.value.find((point) => point.id === Number(form.pickup_point_id)) || null);
const pricePerPerson = computed(() => {
  if (!selectedSchedule.value) return 0;
  if (form.is_join_trip) return Number(selectedSchedule.value.join_trip_price || selectedSchedule.value.price || 0);
  if (selectedPickup.value) return Number(selectedPickup.value.price || 0);
  return Number(selectedSchedule.value.price || 0);
});
const totalAmount = computed(() => pricePerPerson.value * passengers.value.length);
const seatColumns = computed(() => seatMap.value?.columns || []);
const seatGridStyle = computed(() => ({
  gridTemplateColumns: seatColumns.value.map((column) => column === '' ? '34px' : '58px').join(' '),
}));
const seatCells = computed(() => {
  if (!seatMap.value) return [];

  const seatsById = new Map((seatMap.value.seats || []).map((seat) => [seat.id, seat]));
  const cells = [];
  for (let row = 1; row <= (seatMap.value.rows || 0); row += 1) {
    seatColumns.value.forEach((column, columnIndex) => {
      if (column === '') {
        cells.push({ key: `aisle-${row}-${columnIndex}`, type: 'aisle' });
      } else {
        const seatId = `${column}${row}`;
        cells.push({ key: seatId, type: 'seat', seat: seatsById.get(seatId) || null });
      }
    });
  }
  return cells;
});
const canSubmit = computed(() => {
  if (!form.schedule_id || !form.customer_name || !form.email || !form.phone) return false;
  if (passengers.value.some((passenger) => !passenger.name)) return false;
  if (!form.is_join_trip && seatMap.value && selectedSeatIds.value.length !== passengers.value.length) return false;
  return true;
});
const submitHint = computed(() => {
  if (!selectedSchedule.value) return 'เลือกรอบเดินทางก่อน';
  if (!form.customer_name || !form.email || !form.phone) return 'กรอกข้อมูลลูกค้าให้ครบ';
  if (passengers.value.some((passenger) => !passenger.name)) return 'กรอกชื่อผู้เดินทางให้ครบ';
  if (!form.is_join_trip && seatMap.value && selectedSeatIds.value.length !== passengers.value.length) {
    return `เลือกที่นั่ง ${selectedSeatIds.value.length}/${passengers.value.length}`;
  }
  return '';
});

onMounted(fetchTrips);

watch(() => passengers.value.length, () => {
  selectedSeatIds.value = selectedSeatIds.value.slice(0, passengers.value.length);
});

function newPassenger() {
  return {
    key: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    title: '',
    name: '',
    nickname: '',
    phone: '',
    id_card: '',
    blood_group: '',
    allergies: '',
    health_notes: '',
    emergency_contact: '',
    emergency_phone: '',
    halal_food: false,
  };
}

async function fetchTrips() {
  const res = await api.get('/admin/trips', { params: { per_page: 500 } });
  trips.value = res.data?.data || [];
}

async function onTripChange() {
  form.schedule_id = '';
  form.pickup_point_id = '';
  resetSeats();
  if (!form.trip_id) return;

  schedulesLoading.value = true;
  try {
    const res = await api.get('/admin/schedules', { params: { trip_id: form.trip_id, upcoming: 1, per_page: 500 } });
    schedules.value = res.data?.data || [];
  } finally {
    schedulesLoading.value = false;
  }
}

async function onScheduleChange() {
  form.pickup_point_id = '';
  form.is_join_trip = false;
  resetSeats();
  if (!form.schedule_id) return;

  await fetchSeatMap();
}

async function fetchSeatMap() {
  seatsLoading.value = true;
  seatError.value = '';
  try {
    const res = await api.get(`/schedules/${form.schedule_id}/seats`);
    seatMap.value = res.data?.data || null;
  } catch (error) {
    seatError.value = error.response?.data?.message || 'โหลดผังที่นั่งไม่สำเร็จ';
    seatMap.value = null;
  } finally {
    seatsLoading.value = false;
  }
}

function addPassenger() {
  passengers.value.push(newPassenger());
}

function removePassenger(index) {
  passengers.value.splice(index, 1);
}

function resetSeats() {
  seatMap.value = null;
  selectedSeatIds.value = [];
  seatError.value = '';
}

function canToggleSeat(seat) {
  if (!seat || seat.status !== 'available') return false;
  return selectedSeatIds.value.includes(seat.id) || selectedSeatIds.value.length < passengers.value.length;
}

function toggleSeat(seat) {
  if (!canToggleSeat(seat)) return;
  if (selectedSeatIds.value.includes(seat.id)) {
    selectedSeatIds.value = selectedSeatIds.value.filter((seatId) => seatId !== seat.id);
  } else {
    selectedSeatIds.value = [...selectedSeatIds.value, seat.id];
  }
}

function seatButtonClass(seat) {
  return {
    available: seat.status === 'available',
    selected: selectedSeatIds.value.includes(seat.id),
    booked: seat.status !== 'available',
  };
}

function seatTitle(seat) {
  if (seat.status !== 'available') return seat.passenger_name ? `จองแล้วโดย ${seat.passenger_name}` : 'ที่นั่งไม่ว่าง';
  return selectedSeatIds.value.includes(seat.id) ? 'คลิกเพื่อยกเลิก' : 'คลิกเพื่อเลือก';
}

async function submitBooking() {
  if (!canSubmit.value) return;

  submitting.value = true;
  createdBooking.value = null;
  try {
    const payload = {
      schedule_id: form.schedule_id,
      customer_name: form.customer_name,
      email: form.email,
      phone: form.phone,
      status: form.status,
      payment_method: form.payment_method,
      is_join_trip: form.is_join_trip,
      pickup_point_id: form.pickup_point_id || null,
      passengers: passengers.value.map(({ key, ...passenger }) => passenger),
      passenger_count: passengers.value.length,
      seat_ids: form.is_join_trip ? [] : selectedSeatIds.value,
      send_email: form.send_email,
    };

    const res = await api.post('/admin/bookings/manual', payload);
    createdBooking.value = res.data?.data || null;
    toast.success(form.send_email ? 'สร้างการจองและส่งอีเมลแล้ว' : 'สร้างการจองแล้ว');
  } catch (error) {
    toast.error(error.response?.data?.message || 'สร้างการจองไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

function formatDate(value) {
  if (!value) return '-';
  return new Date(`${value}T00:00:00`).toLocaleDateString('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

function formatCurrency(value) {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}
</script>

<style scoped>
@import url('./admin-shared.css');

.manual-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 18px;
  align-items: start;
}

.booking-workspace,
.booking-section,
.summary-panel,
.success-panel {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}

.booking-workspace {
  display: grid;
  gap: 14px;
  background: transparent;
  border: none;
}

.booking-section {
  padding: 18px;
}

.section-head {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.section-step {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  background: #e8f5ec;
  color: var(--color-accent);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 900;
  flex-shrink: 0;
}

.section-head h2 {
  color: #111827;
  font-size: 17px;
  font-weight: 900;
  margin: 0;
}

.section-head p {
  color: #6b7280;
  font-size: 12px;
  margin: 2px 0 0;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.form-field {
  display: grid;
  gap: 5px;
}

.form-field.full {
  grid-column: 1 / -1;
}

.form-field span,
.checkbox-inline {
  color: #374151;
  font-size: 12px;
  font-weight: 800;
}

.form-field input,
.form-field select,
.form-field textarea {
  width: 100%;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  color: #111827;
  font-size: 14px;
  outline: none;
  padding: 9px 12px;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(45, 122, 79, 0.08);
}

.booking-type-toggle,
.email-toggle,
.checkbox-inline {
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 10px 12px;
}

.booking-type-toggle.full {
  grid-column: 1 / -1;
}

.booking-type-toggle .material-symbols-rounded,
.email-toggle .material-symbols-rounded {
  color: var(--color-accent);
}

.booking-type-toggle strong {
  color: #111827;
  font-size: 13px;
}

.booking-type-toggle small {
  color: #6b7280;
  margin-left: auto;
}

.passenger-list {
  display: grid;
  gap: 12px;
}

.passenger-card {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 12px;
}

.passenger-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.passenger-card-head strong {
  color: #111827;
  font-size: 14px;
}

.add-passenger-btn {
  margin-top: 12px;
}

.inline-loading,
.alert-card {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #6b7280;
  font-size: 13px;
  font-weight: 700;
}

.alert-card {
  color: #b91c1c;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  padding: 10px 12px;
}

.seat-legend {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 10px;
  color: #6b7280;
  font-size: 11px;
  font-weight: 800;
}

.seat-legend span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.legend-box {
  width: 14px;
  height: 14px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
}

.legend-box.available { background: #ffffff; }
.legend-box.selected { background: var(--color-accent); border-color: var(--color-accent); }
.legend-box.booked { background: #d1d5db; }

.seat-vehicle {
  overflow-x: auto;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px;
}

.seat-front,
.seat-rear {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #6b7280;
  font-size: 11px;
  font-weight: 900;
  text-transform: uppercase;
}

.seat-front {
  border-bottom: 1px dashed #d1d5db;
  margin-bottom: 14px;
  padding-bottom: 12px;
}

.seat-rear {
  border-top: 1px dashed #d1d5db;
  margin-top: 14px;
  padding-top: 12px;
}

.driver-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 3px 8px;
}

.driver-pill .material-symbols-rounded {
  font-size: 15px;
}

.seat-grid {
  display: grid;
  gap: 8px;
  justify-content: center;
  min-width: max-content;
}

.seat-button {
  display: grid;
  place-items: center;
  gap: 1px;
  width: 58px;
  min-height: 62px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #ffffff;
  color: #4b5563;
  cursor: pointer;
  padding: 6px 4px;
}

.seat-button:hover:not(:disabled) {
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.seat-button .material-symbols-rounded {
  font-size: 20px;
}

.seat-button strong {
  font-size: 11px;
  font-weight: 900;
}

.seat-button small {
  max-width: 48px;
  overflow: hidden;
  font-size: 8px;
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.seat-button.selected {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #ffffff;
}

.seat-button.booked,
.seat-button:disabled {
  background: #e5e7eb;
  border-color: #d1d5db;
  color: #9ca3af;
  cursor: not-allowed;
}

.seat-aisle {
  width: 34px;
  min-height: 62px;
  border-radius: 999px;
  background: linear-gradient(to bottom, transparent, #e5e7eb, transparent);
}

.seat-empty {
  width: 58px;
  min-height: 62px;
}

.submit-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  position: sticky;
  bottom: 0;
  z-index: 2;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.94);
  padding: 12px;
}

.submit-hint {
  color: #6b7280;
  font-size: 12px;
  font-weight: 700;
}

.booking-summary {
  display: grid;
  gap: 12px;
  position: sticky;
  top: 84px;
}

.summary-panel,
.success-panel {
  padding: 16px;
}

.summary-panel h2,
.success-panel h2 {
  color: #111827;
  font-size: 17px;
  font-weight: 900;
  margin: 0 0 12px;
}

.summary-row,
.summary-total {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid #f3f4f6;
  padding: 9px 0;
}

.summary-row span,
.summary-total span {
  color: #6b7280;
  font-size: 12px;
  font-weight: 800;
}

.summary-row strong,
.summary-total strong {
  color: #111827;
  font-size: 13px;
  text-align: right;
}

.summary-total {
  border-bottom: none;
  padding-top: 14px;
}

.summary-total strong {
  color: var(--color-accent);
  font-size: 22px;
}

.success-panel {
  display: grid;
  gap: 8px;
  border-color: #a7f3d0;
  background: #ecfdf5;
  color: #047857;
}

.success-panel .material-symbols-rounded {
  font-size: 34px;
}

.success-panel p {
  margin: 0;
  font-weight: 800;
}

@media (max-width: 1024px) {
  .manual-layout {
    grid-template-columns: 1fr;
  }

  .booking-summary {
    position: static;
  }
}

@media (max-width: 640px) {
  .form-grid {
    grid-template-columns: 1fr;
  }

  .submit-bar {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
