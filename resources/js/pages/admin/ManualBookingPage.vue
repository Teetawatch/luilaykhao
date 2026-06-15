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
                  {{ scheduleRegionLabel(schedule) }} · {{ formatDate(schedule.departure_date) }}
                  <template v-if="schedule.return_date"> - {{ formatDate(schedule.return_date) }}</template>
                  <template v-if="schedule.departs_at"> · ออกรถ {{ departsTimeLabel(schedule) }}</template>
                  · ว่าง {{ schedule.available_seats }} ที่
                </option>
              </select>
            </label>

            <label v-if="selectedSchedule?.join_trip_enabled" class="booking-type-toggle full">
              <input v-model="form.is_join_trip" type="checkbox" @change="onBookingTypeChange" />
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
                  <span>คำนำหน้า *</span>
                  <select v-model="passenger.title" required>
                    <option value="" disabled>เลือก...</option>
                    <option v-for="title in titleOptions" :key="title" :value="title">{{ title }}</option>
                  </select>
                </label>
                <label class="form-field">
                  <span>ชื่อ-นามสกุล *</span>
                  <input v-model.trim="passenger.name" required placeholder="ชื่อผู้เดินทาง" />
                </label>
                <label class="form-field">
                  <span>ชื่อเล่น</span>
                  <input v-model.trim="passenger.nickname" placeholder="ชื่อเล่น" />
                </label>
                <label class="form-field">
                  <span>โทรศัพท์ *</span>
                  <input v-model.trim="passenger.phone" required type="tel" placeholder="0XX-XXX-XXXX" />
                </label>
                <label class="form-field">
                  <span>เลขบัตรประชาชน *</span>
                  <input
                    v-model.trim="passenger.id_card"
                    required
                    maxlength="13"
                    inputmode="numeric"
                    placeholder="เลขบัตรประชาชน 13 หลัก"
                    @input="passenger.id_card = passenger.id_card.replace(/\D/g, '')"
                  />
                </label>
                <label class="form-field">
                  <span>วัน/เดือน/ปีเกิด</span>
                  <input v-model="passenger.birth_date" type="date" :max="todayDate" min="1900-01-01" />
                </label>
                <label class="form-field">
                  <span>กรุ๊ปเลือด</span>
                  <select v-model="passenger.blood_group">
                    <option value="">ไม่ระบุ</option>
                    <option v-for="group in bloodGroupOptions" :key="group" :value="group">{{ group }}</option>
                  </select>
                </label>
                <label class="form-field">
                  <span>ผู้ติดต่อฉุกเฉิน *</span>
                  <input v-model.trim="passenger.emergency_contact" required placeholder="ชื่อผู้ติดต่อ" />
                </label>
                <label class="form-field">
                  <span>เบอร์ฉุกเฉิน *</span>
                  <input v-model.trim="passenger.emergency_phone" required type="tel" placeholder="0XX-XXX-XXXX" />
                </label>
                <div class="form-field full">
                  <span>ต้องการอาหารฮาลาล *</span>
                  <div class="radio-choice-group">
                    <label class="radio-card" :class="{ active: passenger.halal_food === true }">
                      <input :name="`halal_food_${passenger.key}`" v-model="passenger.halal_food" type="radio" :value="true" />
                      <span class="radio-dot"></span>
                      ต้องการ
                    </label>
                    <label class="radio-card" :class="{ active: passenger.halal_food === false }">
                      <input :name="`halal_food_${passenger.key}`" v-model="passenger.halal_food" type="radio" :value="false" />
                      <span class="radio-dot"></span>
                      ไม่จำเป็น
                    </label>
                  </div>
                </div>
                <label class="form-field full">
                  <span>การแพ้อาหาร / อื่นๆ</span>
                  <input v-model.trim="passenger.allergies" placeholder="เช่น แพ้อาหารทะเล, ไม่ทานเนื้อ" />
                </label>
                <label class="form-field full">
                  <span>หมายเหตุสุขภาพ</span>
                  <textarea v-model.trim="passenger.health_notes" rows="2" placeholder="แพ้ยา, โรคประจำตัว ฯลฯ"></textarea>
                </label>
                <div v-if="requiresDiveInfo" class="dive-fields full">
                  <div class="dive-fields-title">
                    <span class="material-symbols-rounded">scuba_diving</span>
                    ข้อมูลดำน้ำ
                  </div>
                  <label class="form-field">
                    <span>ระดับใบรับรอง</span>
                    <input v-model.trim="passenger.dive_cert_level" placeholder="เช่น Open Water" />
                  </label>
                  <label class="form-field">
                    <span>เลขบัตรดำน้ำ</span>
                    <input v-model.trim="passenger.cert_number" placeholder="ถ้ามี" />
                  </label>
                  <label class="form-field">
                    <span>น้ำหนัก (กก.)</span>
                    <input v-model.number="passenger.weight" type="number" min="0" step="0.1" placeholder="สำหรับเตรียมอุปกรณ์" />
                  </label>
                </div>
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
            <button class="btn-secondary btn-small" type="button" @click="fetchSeatMap">โหลดผังที่นั่งอีกครั้ง</button>
          </div>
          <div v-else-if="seatMap" class="seat-picker">
            <div class="seat-legend">
              <span><i class="legend-box available"></i>ว่าง</span>
              <span><i class="legend-box selected"></i>เลือกแล้ว</span>
              <span><i class="legend-box booked"></i>จองแล้ว/ล็อก</span>
              <strong class="seat-count-pill">เลือก {{ selectedSeatIds.length }}/{{ passengers.length }}</strong>
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
          <div v-else class="seat-empty-state">
            <span class="material-symbols-rounded">airline_seat_recline_normal</span>
            <strong>ยังไม่ได้โหลดผังที่นั่ง</strong>
            <p>การจองปกติต้องเลือกที่นั่งให้ครบก่อนสร้างการจอง</p>
            <button class="btn-secondary" type="button" @click="fetchSeatMap">โหลดผังที่นั่ง</button>
          </div>
        </section>

        <section class="booking-section">
          <div class="section-head">
            <span class="section-step">{{ form.is_join_trip ? 4 : 5 }}</span>
            <div>
              <h2>การชำระเงิน</h2>
              <p>เลือกเงื่อนไขจ่ายเงิน ช่องทางชำระ แนบสลิป และระบุวันเวลาที่โอนจริง</p>
            </div>
          </div>

          <div class="payment-grid">
            <div class="payment-block">
              <div class="block-label">สถานะการชำระเงิน</div>
              <div class="radio-choice-group">
                <label class="radio-card" :class="{ active: form.status === 'pending' }">
                  <input v-model="form.status" type="radio" value="pending" />
                  <span class="radio-dot"></span>
                  รอชำระเงิน
                </label>
                <label class="radio-card" :class="{ active: form.status === 'confirmed' }">
                  <input v-model="form.status" type="radio" value="confirmed" />
                  <span class="radio-dot"></span>
                  รับชำระแล้ว
                </label>
              </div>
            </div>

            <div class="payment-block">
              <div class="block-label">รูปแบบการจ่าย</div>
              <div class="radio-choice-group">
                <label class="radio-card" :class="{ active: form.payment_type === 'full' }">
                  <input v-model="form.payment_type" type="radio" value="full" />
                  <span class="radio-dot"></span>
                  จ่ายเต็ม
                </label>
                <label class="radio-card" :class="{ active: form.payment_type === 'installment', disabled: !installmentAllowed }">
                  <input v-model="form.payment_type" type="radio" value="installment" :disabled="!installmentAllowed" />
                  <span class="radio-dot"></span>
                  ผ่อนชำระ
                </label>
              </div>
              <p v-if="!installmentAllowed" class="field-note">รอบนี้ยังไม่เปิดผ่อนชำระ หรือเป็นจอยทริป</p>
            </div>

            <label v-if="form.payment_type === 'installment'" class="form-field">
              <span>จำนวนงวด</span>
              <select v-model.number="form.installment_count">
                <option v-for="count in installmentCountOptions" :key="count" :value="count">{{ count }} งวด</option>
              </select>
            </label>

            <div class="payment-block">
              <div class="block-label">ช่องทางชำระ</div>
              <div class="payment-method-grid">
                <label
                  v-for="method in paymentMethodOptions"
                  :key="method.value"
                  class="payment-method-card"
                  :class="{ active: form.payment_method === method.value }"
                >
                  <input v-model="form.payment_method" type="radio" :value="method.value" />
                  <span class="material-symbols-rounded">{{ method.icon }}</span>
                  <strong>{{ method.label }}</strong>
                  <small>{{ method.description }}</small>
                </label>
              </div>
            </div>

            <div class="payment-preview">
              <div v-if="form.payment_method === 'promptpay'" class="pay-visual">
                <img src="/images/qr_promptpay.webp" alt="QR PromptPay" />
                <span>QR พร้อมเพย์</span>
              </div>
              <div v-else class="pay-visual">
                <img src="/images/pay_bank.webp" alt="โอนผ่านบัญชีธนาคาร" />
                <span>โอนผ่านบัญชีธนาคาร</span>
              </div>
              <div class="pay-amount">
                <span>{{ form.payment_type === 'installment' ? 'ยอดรับงวดแรก' : 'ยอดที่ต้องชำระ' }}</span>
                <strong>{{ formatCurrency(payableNow) }}</strong>
              </div>
            </div>

            <div v-if="form.payment_type === 'installment'" class="installment-plan full">
              <div v-for="item in installmentPlan" :key="item.no" class="installment-row">
                <span>งวดที่ {{ item.no }}</span>
                <strong>{{ formatCurrency(item.amount) }}</strong>
                <small>{{ formatDate(item.due_date) }}</small>
              </div>
            </div>

            <div class="slip-uploader full" :class="{ hasFile: slipPreview }" @click="slipInputRef?.click()">
              <input ref="slipInputRef" type="file" accept="image/*" class="hidden-input" @change="onSlipChange" />
              <template v-if="slipPreview">
                <img :src="slipPreview" alt="สลิปโอนเงิน" />
                <button class="btn-secondary btn-small" type="button" @click.stop="clearSlip">ลบสลิป</button>
              </template>
              <template v-else>
                <span class="material-symbols-rounded">upload_file</span>
                <strong>แนบไฟล์สลิป</strong>
                <small>{{ paymentEvidenceRequired ? 'ต้องแนบสลิปเมื่อรับชำระแล้ว' : 'แนบไว้ก่อนได้ ถ้าลูกค้าโอนมาแล้ว' }}</small>
              </template>
            </div>

            <label class="form-field">
              <span>วันที่โอน{{ paymentEvidenceRequired ? ' *' : '' }}</span>
              <input v-model="form.transfer_date" :required="paymentEvidenceRequired" type="date" />
            </label>
            <label class="form-field">
              <span>เวลาที่โอน{{ paymentEvidenceRequired ? ' *' : '' }}</span>
              <input v-model="form.transfer_time" :required="paymentEvidenceRequired" type="time" />
            </label>
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
          <div v-if="selectedSchedule?.departs_at" class="summary-row">
            <span>ออกรถจริง</span>
            <strong>{{ departsTimeLabel(selectedSchedule) }}</strong>
          </div>
          <div class="summary-row">
            <span>ภาค</span>
            <strong>{{ selectedSchedule ? scheduleRegionLabel(selectedSchedule) : '-' }}</strong>
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
          <div class="summary-row">
            <span>การจ่าย</span>
            <strong>{{ form.payment_type === 'installment' ? `ผ่อน ${form.installment_count} งวด` : 'จ่ายเต็ม' }}</strong>
          </div>
          <div class="summary-row">
            <span>รับตอนนี้</span>
            <strong>{{ form.status === 'confirmed' ? formatCurrency(payableNow) : 'ยังไม่รับชำระ' }}</strong>
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
const slipFile = ref(null);
const slipPreview = ref('');
const slipInputRef = ref(null);

const bloodGroupOptions = ['A', 'B', 'O', 'AB'];
const paymentMethodOptions = [
  {
    value: 'promptpay',
    label: 'QR PromptPay',
    description: 'ลูกค้าสแกนจ่ายผ่านพร้อมเพย์',
    icon: 'qr_code_2',
  },
  {
    value: 'mobile_banking',
    label: 'โอนผ่านบัญชีธนาคาร',
    description: 'บันทึกสลิปจาก Mobile Banking',
    icon: 'account_balance',
  },
];
const regionLabels = {
  north: 'ภาคเหนือ',
  northeast: 'ภาคอีสาน',
  central: 'ภาคกลาง',
  east: 'ภาคตะวันออก',
  west: 'ภาคตะวันตก',
  south: 'ภาคใต้',
  bangkok: 'กรุงเทพฯ',
};

const form = reactive({
  trip_id: '',
  schedule_id: '',
  customer_name: '',
  email: '',
  phone: '',
  status: 'pending',
  payment_type: 'full',
  payment_method: 'promptpay',
  installment_count: 2,
  transfer_date: '',
  transfer_time: '',
  is_join_trip: false,
  pickup_point_id: '',
  send_email: true,
});

const passengers = ref([newPassenger()]);

// Local YYYY-MM-DD today — caps the birth-date picker so no future date is picked.
const todayDate = (() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

const selectedTrip = computed(() => trips.value.find((trip) => trip.id === Number(form.trip_id)) || null);
const selectedSchedule = computed(() => schedules.value.find((schedule) => schedule.id === Number(form.schedule_id)) || null);
const isWomenOnlyTrip = computed(() => Boolean(selectedTrip.value?.is_women_only || selectedSchedule.value?.trip?.is_women_only));
const titleOptions = computed(() => isWomenOnlyTrip.value
  ? ['นาง', 'นางสาว']
  : ['นาย', 'นาง', 'นางสาว']);
const pickupPoints = computed(() => selectedSchedule.value?.pickup_points || []);
const selectedPickup = computed(() => pickupPoints.value.find((point) => point.id === Number(form.pickup_point_id)) || null);
const requiresDiveInfo = computed(() => ['diving', 'snorkeling'].includes(selectedTrip.value?.type || selectedSchedule.value?.trip?.type));
const installmentAllowed = computed(() => Boolean(selectedSchedule.value?.installment_enabled && !form.is_join_trip));
const maxInstallmentCount = computed(() => Math.min(Math.max(Number(selectedSchedule.value?.installment_count || 2), 2), 6));
const installmentCountOptions = computed(() => Array.from({ length: maxInstallmentCount.value - 1 }, (_, index) => index + 2));
const pricePerPerson = computed(() => {
  if (!selectedSchedule.value) return 0;
  if (form.is_join_trip) return Number(selectedSchedule.value.join_trip_price || selectedSchedule.value.price || 0);
  if (selectedPickup.value) return Number(selectedPickup.value.price || 0);
  return Number(selectedSchedule.value.price || 0);
});
const totalAmount = computed(() => pricePerPerson.value * passengers.value.length);
const installmentIntervalDays = computed(() => Number(selectedSchedule.value?.installment_interval_days || 30));
const installmentPlan = computed(() => {
  const count = Number(form.installment_count || 2);
  const perInstallment = Math.round((totalAmount.value / count) * 100) / 100;
  const today = new Date();

  return Array.from({ length: count }, (_, index) => {
    const dueDate = new Date(today);
    dueDate.setDate(today.getDate() + (index * installmentIntervalDays.value));
    const amount = index === count - 1
      ? Math.round((totalAmount.value - (perInstallment * (count - 1))) * 100) / 100
      : perInstallment;

    return {
      no: index + 1,
      amount,
      due_date: formatInputDate(dueDate),
    };
  });
});
const payableNow = computed(() => (form.payment_type === 'installment'
  ? installmentPlan.value[0]?.amount || 0
  : totalAmount.value));
const paymentEvidenceRequired = computed(() => form.status === 'confirmed');
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
  if (passengers.value.some((passenger) => !isPassengerComplete(passenger))) return false;
  if (!form.is_join_trip && selectedSeatIds.value.length !== passengers.value.length) return false;
  if (form.payment_type === 'installment' && !installmentAllowed.value) return false;
  if (paymentEvidenceRequired.value && (!slipFile.value || !form.transfer_date || !form.transfer_time)) return false;
  return true;
});
const submitHint = computed(() => {
  if (!selectedSchedule.value) return 'เลือกรอบเดินทางก่อน';
  if (!form.customer_name || !form.email || !form.phone) return 'กรอกข้อมูลลูกค้าให้ครบ';
  if (passengers.value.some((passenger) => !isPassengerComplete(passenger))) return 'กรอกข้อมูลผู้เดินทางที่มี * ให้ครบ';
  if (!form.is_join_trip && seatsLoading.value) return 'กำลังโหลดผังที่นั่ง';
  if (!form.is_join_trip && !seatMap.value) return 'โหลดผังที่นั่งก่อน';
  if (!form.is_join_trip && selectedSeatIds.value.length !== passengers.value.length) {
    return `เลือกที่นั่ง ${selectedSeatIds.value.length}/${passengers.value.length}`;
  }
  if (form.payment_type === 'installment' && !installmentAllowed.value) return 'รอบนี้ยังไม่เปิดผ่อนชำระ';
  if (paymentEvidenceRequired.value && !slipFile.value) return 'แนบไฟล์สลิปก่อน';
  if (paymentEvidenceRequired.value && (!form.transfer_date || !form.transfer_time)) return 'ระบุวันที่และเวลาที่โอน';
  return '';
});

onMounted(fetchTrips);

watch(() => passengers.value.length, () => {
  selectedSeatIds.value = selectedSeatIds.value.slice(0, passengers.value.length);
});

watch([selectedSchedule, () => form.is_join_trip], () => {
  if (!installmentAllowed.value) {
    form.payment_type = 'full';
  }
  form.installment_count = Math.min(Number(form.installment_count || 2), maxInstallmentCount.value);
});

watch(() => form.status, (status) => {
  if (status === 'confirmed') {
    fillTransferNow();
  }
});

function newPassenger() {
  return {
    key: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    title: '',
    name: '',
    nickname: '',
    phone: '',
    id_card: '',
    birth_date: '',
    blood_group: '',
    allergies: '',
    health_notes: '',
    emergency_contact: '',
    emergency_phone: '',
    dive_cert_level: '',
    cert_number: '',
    weight: null,
    halal_food: null,
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
  if (!form.schedule_id) return;
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

async function onBookingTypeChange() {
  selectedSeatIds.value = [];
  seatError.value = '';
  if (!form.is_join_trip && form.schedule_id && !seatMap.value) {
    await fetchSeatMap();
  }
}

function isPassengerComplete(passenger) {
  const hasRequiredFields = passenger.title
    && passenger.name?.trim()
    && passenger.phone?.trim()
    && passenger.id_card?.length === 13
    && passenger.emergency_contact?.trim()
    && passenger.emergency_phone?.trim()
    && passenger.halal_food !== null;

  const matchesWomenOnly = !isWomenOnlyTrip.value
    || ['นาง', 'นางสาว'].includes(passenger.title);

  return Boolean(hasRequiredFields && matchesWomenOnly);
}

function addPassenger() {
  const passenger = newPassenger();
  if (passengers.value.length === 0) {
    passenger.phone = form.phone;
  }
  passengers.value.push(passenger);
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

function scheduleRegionLabel(schedule) {
  const region = schedule?.trip?.region || selectedTrip.value?.region || schedule?.region || '';
  return regionLabels[region] || region || 'ไม่ระบุภาค';
}

function fillTransferNow() {
  const now = new Date();
  if (!form.transfer_date) {
    form.transfer_date = formatInputDate(now);
  }
  if (!form.transfer_time) {
    form.transfer_time = now.toTimeString().slice(0, 5);
  }
}

function formatInputDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function onSlipChange(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  slipFile.value = file;

  const reader = new FileReader();
  reader.onload = (readerEvent) => {
    slipPreview.value = readerEvent.target?.result || '';
  };
  reader.readAsDataURL(file);
}

function clearSlip() {
  slipFile.value = null;
  slipPreview.value = '';
  if (slipInputRef.value) {
    slipInputRef.value.value = '';
  }
}

function appendFormValue(fd, key, value) {
  if (value === undefined || value === null || value === '') return;
  fd.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
}

function buildBookingPayload() {
  const fd = new FormData();
  appendFormValue(fd, 'schedule_id', form.schedule_id);
  appendFormValue(fd, 'customer_name', form.customer_name);
  appendFormValue(fd, 'email', form.email);
  appendFormValue(fd, 'phone', form.phone);
  appendFormValue(fd, 'status', form.status);
  appendFormValue(fd, 'payment_type', form.payment_type);
  appendFormValue(fd, 'payment_method', form.payment_method);
  appendFormValue(fd, 'installment_count', form.installment_count);
  appendFormValue(fd, 'transfer_date', form.transfer_date);
  appendFormValue(fd, 'transfer_time', form.transfer_time);
  appendFormValue(fd, 'is_join_trip', form.is_join_trip);
  appendFormValue(fd, 'pickup_point_id', form.pickup_point_id || null);
  appendFormValue(fd, 'passenger_count', passengers.value.length);
  appendFormValue(fd, 'send_email', form.send_email);

  passengers.value.forEach(({ key, ...passenger }, index) => {
    Object.entries(passenger).forEach(([field, value]) => {
      appendFormValue(fd, `passengers[${index}][${field}]`, value);
    });
  });

  if (!form.is_join_trip) {
    selectedSeatIds.value.forEach((seatId) => appendFormValue(fd, 'seat_ids[]', seatId));
  }

  if (slipFile.value) {
    fd.append('slip_image', slipFile.value);
  }

  return fd;
}

async function submitBooking() {
  if (!canSubmit.value) return;

  submitting.value = true;
  createdBooking.value = null;
  try {
    const res = await api.post('/admin/bookings/manual', buildBookingPayload());
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

// เวลาออกรถจริง เช่น "23:30 น. (คืนก่อนวันทริป)" — รถอาจออกก่อนวันทริป
function departsTimeLabel(schedule) {
  if (!schedule?.departs_at) return '';
  const time = schedule.departs_at.slice(11, 16);
  const nightBefore = schedule.departs_at.slice(0, 10) < schedule.departure_date;
  return `${time} น.${nightBefore ? ' (คืนก่อนวันทริป)' : ''}`;
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

.field-note {
  margin: 6px 0 0;
  color: #6b7280;
  font-size: 11px;
  font-weight: 700;
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

.payment-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.payment-grid .full,
.payment-block {
  grid-column: 1 / -1;
}

.block-label {
  color: #374151;
  font-size: 12px;
  font-weight: 900;
  margin-bottom: 7px;
}

.radio-card.disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.payment-method-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.payment-method-card {
  position: relative;
  display: grid;
  gap: 4px;
  min-height: 86px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #ffffff;
  color: #374151;
  cursor: pointer;
  padding: 12px;
}

.payment-method-card input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.payment-method-card .material-symbols-rounded {
  color: var(--color-accent);
  font-size: 22px;
}

.payment-method-card strong {
  color: #111827;
  font-size: 13px;
}

.payment-method-card small {
  color: #6b7280;
  font-size: 11px;
  font-weight: 700;
}

.payment-method-card.active {
  border-color: var(--color-accent);
  background: #eef8f1;
}

.payment-preview {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  grid-column: 1 / -1;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #f9fafb;
  padding: 12px;
}

.pay-visual {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  color: #374151;
  font-size: 12px;
  font-weight: 900;
}

.pay-visual img {
  width: 58px;
  height: 58px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #ffffff;
  object-fit: contain;
  padding: 6px;
}

.pay-amount {
  display: grid;
  gap: 2px;
  text-align: right;
}

.pay-amount span {
  color: #6b7280;
  font-size: 11px;
  font-weight: 800;
}

.pay-amount strong {
  color: var(--color-accent);
  font-size: 20px;
  font-weight: 900;
}

.installment-plan {
  display: grid;
  gap: 8px;
}

.installment-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: 10px;
  align-items: center;
  border: 1px solid #fde68a;
  border-radius: 8px;
  background: #fffbeb;
  padding: 9px 10px;
}

.installment-row span,
.installment-row small {
  color: #92400e;
  font-size: 12px;
  font-weight: 800;
}

.installment-row strong {
  color: #78350f;
  font-size: 13px;
  font-weight: 900;
}

.slip-uploader {
  display: grid;
  justify-items: center;
  gap: 8px;
  min-height: 150px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
  color: #475569;
  cursor: pointer;
  padding: 16px;
  text-align: center;
}

.slip-uploader.hasFile {
  border-style: solid;
  background: #ffffff;
}

.slip-uploader img {
  max-height: 220px;
  max-width: 100%;
  border-radius: 8px;
  object-fit: contain;
}

.slip-uploader .material-symbols-rounded {
  color: #94a3b8;
  font-size: 38px;
}

.slip-uploader strong {
  color: #111827;
  font-size: 14px;
}

.slip-uploader small {
  color: #64748b;
  font-size: 12px;
  font-weight: 700;
}

.hidden-input {
  display: none;
}

.radio-choice-group {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.radio-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 42px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #ffffff;
  color: #374151;
  cursor: pointer;
  font-size: 13px;
  font-weight: 800;
  padding: 10px 12px;
}

.radio-card input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.radio-card.active {
  border-color: var(--color-accent);
  background: #eef8f1;
  color: var(--color-accent);
}

.radio-dot {
  flex-shrink: 0;
  width: 16px;
  height: 16px;
  border: 2px solid #d1d5db;
  border-radius: 999px;
  box-shadow: inset 0 0 0 3px #ffffff;
}

.radio-card.active .radio-dot {
  border-color: var(--color-accent);
  background: var(--color-accent);
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

.dive-fields {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  border: 1px solid #dbeafe;
  border-radius: 8px;
  background: #f8fbff;
  padding: 12px;
}

.dive-fields.full {
  grid-column: 1 / -1;
}

.dive-fields-title {
  display: flex;
  align-items: center;
  gap: 6px;
  grid-column: 1 / -1;
  color: #1d4ed8;
  font-size: 12px;
  font-weight: 900;
}

.dive-fields-title .material-symbols-rounded {
  font-size: 18px;
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
  flex-wrap: wrap;
  color: #b91c1c;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  padding: 10px 12px;
}

.btn-small {
  min-height: 34px;
  margin-left: auto;
  padding: 7px 10px;
}

.seat-legend {
  display: flex;
  align-items: center;
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

.seat-count-pill {
  margin-left: auto;
  border: 1px solid #bbf7d0;
  border-radius: 999px;
  background: #f0fdf4;
  color: var(--color-accent);
  font-size: 11px;
  padding: 5px 10px;
}

.seat-empty-state {
  display: grid;
  justify-items: center;
  gap: 8px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
  color: #475569;
  padding: 22px;
  text-align: center;
}

.seat-empty-state .material-symbols-rounded {
  color: #94a3b8;
  font-size: 38px;
}

.seat-empty-state strong {
  color: #111827;
  font-size: 14px;
}

.seat-empty-state p {
  margin: 0;
  color: #64748b;
  font-size: 12px;
  font-weight: 700;
}

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

  .payment-grid,
  .payment-method-grid,
  .radio-choice-group,
  .dive-fields {
    grid-template-columns: 1fr;
  }

  .payment-preview,
  .installment-row {
    grid-template-columns: 1fr;
    text-align: left;
  }

  .pay-amount {
    text-align: left;
  }

  .submit-bar {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
