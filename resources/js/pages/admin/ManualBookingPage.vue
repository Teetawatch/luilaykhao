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

    <div v-if="intakeSources.length" class="intake-banner">
      <span class="material-symbols-rounded">contact_mail</span>
      <div>
        <strong>
          ดึงข้อมูลจากลิงก์ที่ลูกค้ากรอกเองมาแล้ว
          <template v-if="intakeSources.length > 1">· {{ intakeSources.length }} กลุ่ม รวมเป็นการจองใบเดียว</template>
        </strong>
        <p v-for="source in intakeSources" :key="source.id">
          {{ source.contact_name }} · กรอกครบ {{ source.people.length }}/{{ source.party_size }} คน
          <template v-if="source.people.length < source.party_size">
            — ยังรอเพื่อนอีก {{ source.party_size - source.people.length }} คน จองตอนนี้จะได้เท่าที่กรอกมา
          </template>
        </p>
        <p>
          ผู้เดินทางในฟอร์มด้านล่าง {{ passengers.length }} คน
          <template v-if="intakeDroppedCount">(ตัดชื่อที่ซ้ำกันออก {{ intakeDroppedCount }} คน)</template>
          · เมื่อบันทึกการจองแล้ว กลุ่มเหล่านี้จะถูกปิดโดยอัตโนมัติ
        </p>
        <p v-if="intakeScheduleMixed" class="intake-warn">
          <span class="material-symbols-rounded">warning</span>
          กลุ่มที่เลือกมาอยู่คนละรอบเดินทาง — ตั้งรอบให้ตามกลุ่มแรก ตรวจอีกครั้งก่อนบันทึก
        </p>
      </div>
      <router-link to="/admin/intakes" class="btn-secondary">กลับไปรายการ</router-link>
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

            <label v-if="vehicleOptions.length > 1 && !form.is_join_trip" class="form-field full">
              <span>ประเภทรถ</span>
              <select v-model.number="form.vehicle_option_id" @change="onVehicleOptionChange">
                <option value="">ไม่ระบุ (ราคาปกติ)</option>
                <option v-for="option in vehicleOptions" :key="option.id" :value="option.id"
                  :disabled="option.is_sold_out">
                  {{ option.label }}
                  · {{ Number(option.price_adjustment) === 0 ? 'ราคาปกติ' : `${Number(option.price_adjustment) > 0 ? '+' : '-'}${formatCurrency(Math.abs(Number(option.price_adjustment)))}/คน` }}
                  <template v-if="option.is_sold_out"> · เต็มแล้ว</template>
                  <template v-else-if="option.available_seats !== null"> · เหลือ {{ option.available_seats }} ที่</template>
                </option>
              </select>
              <small class="field-note">ส่วนต่างคิดต่อคน บวกจากราคาที่ได้จากจุดรับ</small>
            </label>

            <label v-if="pickupPoints.length && !form.is_join_trip" class="form-field full">
              <span>จุดรับหลัก</span>
              <select v-model.number="form.pickup_point_id">
                <option value="">ไม่ระบุจุดรับ</option>
                <option v-for="point in pickupPoints" :key="point.id" :value="point.id">
                  {{ point.region_label || point.region }} · {{ point.pickup_location }} · {{ formatCurrency(point.price) }}
                </option>
              </select>
              <small class="field-note">ใช้กับทุกคนที่ไม่ได้เลือกจุดของตัวเอง — เลือกรายคนได้ในขั้นที่ 3</small>
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
                <label v-if="pickupPoints.length && !form.is_join_trip" class="form-field full">
                  <span>จุดรับ</span>
                  <select v-model.number="passenger.pickup_point_id">
                    <option value="">{{ selectedPickup ? `ใช้จุดรับหลัก · ${selectedPickup.pickup_location}` : 'ใช้จุดรับหลัก (ยังไม่ได้เลือก)' }}</option>
                    <option v-for="point in pickupPoints" :key="point.id" :value="point.id">
                      {{ point.region_label || point.region }} · {{ point.pickup_location }} · {{ formatCurrency(point.price) }}
                    </option>
                  </select>
                  <small class="field-note">ราคาต่อคนคิดตามจุดที่คนนี้ขึ้น</small>
                </label>
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
          <div v-else-if="seatMap && seatMap.has_seat_map === false" class="seat-empty-state">
            <span class="material-symbols-rounded">flight</span>
            <strong>รอบนี้เดินทางโดยเครื่องบิน</strong>
            <p>{{ seatMap.seat_selection_disabled_reason || 'ที่นั่งจัดโดยสายการบิน ไม่ต้องเลือกที่นั่งในขั้นตอนนี้' }}</p>
            <p>สร้างการจองได้เลย แล้วค่อยกรอกเลขที่นั่งจริงให้แต่ละคนที่หน้าแก้ไขการจอง</p>
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
              <h2>{{ isHoldMode ? 'ล็อกที่นั่งไว้ก่อน' : 'การชำระเงิน' }}</h2>
              <p v-if="isHoldMode">ข้ามขั้นชำระเงินไปก่อน กันที่นั่งไว้ให้ลูกค้าจนถึงเวลาที่กำหนด</p>
              <p v-else>เลือกเงื่อนไขจ่ายเงิน ช่องทางชำระ แนบสลิป และระบุวันเวลาที่โอนจริง</p>
            </div>
          </div>

          <div class="payment-grid">
            <div class="payment-block">
              <div class="block-label">จะจัดการเรื่องเงินยังไง</div>
              <div class="payment-method-grid mode-grid">
                <label
                  v-for="mode in bookingModeOptions"
                  :key="mode.value"
                  class="payment-method-card"
                  :class="{ active: form.mode === mode.value }"
                >
                  <input v-model="form.mode" type="radio" :value="mode.value" @change="onModeChange" />
                  <span class="material-symbols-rounded">{{ mode.icon }}</span>
                  <strong>{{ mode.label }}</strong>
                  <small>{{ mode.description }}</small>
                </label>
              </div>
              <p v-if="form.mode === 'await'" class="field-note">
                ที่นั่งจะถูกคืนอัตโนมัติภายใน {{ pendingTtlMinutes }} นาทีถ้าลูกค้ายังไม่ชำระ —
                ถ้าลูกค้ายังไม่พร้อมจ่ายตอนนี้ ให้เลือก "ล็อกที่นั่งไว้ก่อน"
              </p>
            </div>

            <div v-if="isHoldMode" class="payment-block hold-panel">
              <div class="block-label">กันที่นั่งไว้ให้ถึงเมื่อไหร่</div>
              <div class="hold-quick">
                <button
                  v-for="option in holdQuickOptions"
                  :key="option.days"
                  type="button"
                  class="hold-chip"
                  :class="{ active: holdQuickDays === option.days }"
                  @click="setHoldDays(option.days)"
                >
                  {{ option.label }}
                </button>
              </div>
              <div class="form-grid">
                <label class="form-field">
                  <span>ล็อกถึงวันและเวลา *</span>
                  <input
                    v-model="form.hold_until"
                    type="datetime-local"
                    :min="holdMinInput"
                    :max="holdMaxInput"
                    required
                    @input="holdQuickDays = null"
                  />
                </label>
                <label class="form-field">
                  <span>บันทึกภายใน (เห็นเฉพาะทีมงาน)</span>
                  <input v-model.trim="form.hold_note" maxlength="255" placeholder="เช่น ลูกค้าโทรมาจอง ขอโอนวันศุกร์" />
                </label>
              </div>
              <p class="field-note">{{ holdHint }}</p>
            </div>

            <div class="payment-block">
              <div class="block-label">รูปแบบการจ่าย</div>
              <div class="radio-choice-group">
                <label class="radio-card" :class="{ active: form.payment_type === 'full' }">
                  <input v-model="form.payment_type" type="radio" value="full" />
                  <span class="radio-dot"></span>
                  จ่ายเต็ม
                </label>
                <label class="radio-card" :class="{ active: form.payment_type === 'deposit', disabled: !depositAllowed }">
                  <input v-model="form.payment_type" type="radio" value="deposit" :disabled="!depositAllowed" />
                  <span class="radio-dot"></span>
                  มัดจำ
                </label>
                <label class="radio-card" :class="{ active: form.payment_type === 'installment', disabled: !installmentAllowed }">
                  <input v-model="form.payment_type" type="radio" value="installment" :disabled="!installmentAllowed" />
                  <span class="radio-dot"></span>
                  ผ่อนชำระ
                </label>
              </div>
              <p v-if="!depositAllowed && !installmentAllowed" class="field-note">รอบนี้รับชำระเต็มจำนวนเท่านั้น (ยังไม่เปิดมัดจำ หรือใกล้วันเดินทางเกินกว่าจะผ่อน)</p>
              <p v-else-if="!depositAllowed && form.payment_type !== 'installment'" class="field-note">รอบนี้ยังไม่เปิดมัดจำ หรือเป็นจอยทริป</p>
              <p v-else-if="!installmentAllowed && form.payment_type !== 'deposit'" class="field-note">รอบนี้ใกล้วันเดินทางเกินกว่าจะผ่อนชำระได้ หรือเป็นจอยทริป</p>
            </div>

            <label v-if="form.payment_type === 'installment'" class="form-field">
              <span>จำนวนงวด (ระบบแบ่งให้ถึงวันปิดยอด)</span>
              <select v-model.number="form.installment_count">
                <option v-for="count in installmentCountOptions" :key="count" :value="count">{{ count }} งวด</option>
              </select>
            </label>

            <div v-if="form.payment_type === 'deposit'" class="installment-plan full">
              <div class="installment-row">
                <span>ยอดมัดจำ (รับตอนนี้)</span>
                <strong>{{ formatCurrency(depositAmount) }}</strong>
                <small>ชำระทันที</small>
              </div>
              <div class="installment-row">
                <span>ยอดส่วนที่เหลือ</span>
                <strong>{{ formatCurrency(balanceAmount) }}</strong>
                <small v-if="balanceDueDate">ครบกำหนด {{ formatDate(balanceDueDate) }}</small>
              </div>
              <p class="deposit-note">
                ยอดก่อนหักส่วนลดมัดจำตามระดับสมาชิก — ถ้าลูกค้าคนนี้มีสิทธิ์ ระบบจะหักให้ตอนบันทึก
              </p>
            </div>

            <div v-if="!isHoldMode" class="payment-block">
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
              <div v-if="isHoldMode" class="pay-visual">
                <span class="material-symbols-rounded pay-hold-icon">event_seat</span>
                <span>กันที่นั่งไว้ก่อน — ยังไม่เก็บเงินตอนนี้</span>
              </div>
              <div v-else-if="form.payment_method === 'promptpay'" class="pay-visual">
                <img src="/images/qr_promptpay.webp" alt="QR PromptPay" />
                <span>QR พร้อมเพย์</span>
              </div>
              <div v-else class="pay-visual">
                <img src="/images/pay_bank.webp" alt="โอนผ่านบัญชีธนาคาร" />
                <span>โอนผ่านบัญชีธนาคาร</span>
              </div>
              <div class="pay-amount">
                <span>{{ payableNowLabel }}</span>
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

            <div v-if="!isHoldMode" class="slip-uploader full" :class="{ hasFile: slipPreview }" @click="slipInputRef?.click()">
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

            <template v-if="!isHoldMode">
              <label class="form-field">
                <span>วันที่โอน{{ paymentEvidenceRequired ? ' *' : '' }}</span>
                <input v-model="form.transfer_date" :required="paymentEvidenceRequired" type="date" />
              </label>
              <label class="form-field">
                <span>เวลาที่โอน{{ paymentEvidenceRequired ? ' *' : '' }}</span>
                <input v-model="form.transfer_time" :required="paymentEvidenceRequired" type="time" />
              </label>
            </template>
          </div>
        </section>

        <div class="submit-bar">
          <button class="btn-primary" type="submit" :disabled="submitting || !canSubmit">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': submitting }">{{ submitting ? 'sync' : submitIcon }}</span>
            {{ submitLabel }}
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
          <div v-if="pickupBreakdown.length" class="summary-row summary-row--stack">
            <span>จุดรับ</span>
            <div class="pickup-breakdown">
              <strong v-for="bucket in pickupBreakdown" :key="bucket.id">
                {{ bucket.label }} · {{ bucket.count }} คน
              </strong>
            </div>
          </div>
          <div class="summary-row">
            <span>ที่นั่ง</span>
            <strong>{{ seatSelectionRequired ? (selectedSeatIds.join(', ') || '-') : 'ไม่ต้องเลือก' }}</strong>
          </div>
          <div class="summary-row">
            <span>การจ่าย</span>
            <strong>{{ paymentPlanLabel }}</strong>
          </div>
          <div class="summary-row">
            <span>รับตอนนี้</span>
            <strong>{{ form.status === 'confirmed' ? formatCurrency(payableNow) : 'ยังไม่รับชำระ' }}</strong>
          </div>
          <div v-if="isHoldMode" class="summary-row">
            <span>ล็อกที่นั่งถึง</span>
            <strong>{{ form.hold_until ? formatDateTime(form.hold_until) : '-' }}</strong>
          </div>
          <div class="summary-total">
            <span>ยอดรวม</span>
            <strong>{{ formatCurrency(totalAmount) }}</strong>
          </div>
        </div>

        <div v-if="createdBooking" class="success-panel">
          <span class="material-symbols-rounded">{{ createdBooking.hold_until ? 'event_seat' : 'task_alt' }}</span>
          <h2>{{ createdBooking.hold_until ? 'ล็อกที่นั่งให้แล้ว' : 'สร้างการจองสำเร็จ' }}</h2>
          <p>เลขจอง {{ createdBooking.booking_ref }}</p>
          <p v-if="createdBooking.hold_until">กันที่นั่งไว้ถึง {{ formatDateTime(createdBooking.hold_until) }}</p>
          <router-link :to="`/payment/${createdBooking.booking_ref}`" class="btn-secondary">เปิดหน้าชำระเงิน</router-link>
        </div>

        <!-- QR ให้ลูกค้าสแกนจ่าย — ลูกค้าคุยอยู่ในแชท ไม่ได้เปิดแอป จึงต้องมี QR
             ที่ทีมงานแคปส่งต่อได้ทันทีที่เปิดการจองเสร็จ -->
        <div v-if="createdBooking && !isPaidMode" class="qr-panel">
          <div class="qr-head">
            <div>
              <strong>QR ให้ลูกค้าสแกนจ่าย</strong>
              <span v-if="qrData">{{ qrData.purpose_label }} · {{ formatCurrency(qrData.amount) }}</span>
              <span v-else>ออก QR แล้วส่งให้ลูกค้าในแชทได้เลย</span>
            </div>
            <button
              type="button"
              class="btn-secondary btn-sm"
              :disabled="qrLoading"
              @click="createQr"
            >
              <span class="material-symbols-rounded" style="font-size:16px">qr_code_2</span>
              {{ qrData ? 'ออก QR ใหม่' : 'ออก QR' }}
            </button>
          </div>

          <div v-if="qrPaid" class="qr-paid">
            <span class="material-symbols-rounded">task_alt</span>
            <div>
              <strong>ลูกค้าชำระเงินแล้ว</strong>
              <p>ยอด {{ formatCurrency(qrData?.amount) }} เข้าระบบเรียบร้อย</p>
            </div>
          </div>

          <template v-else-if="qrImageSrc">
            <div class="qr-image-wrap">
              <img :src="qrImageSrc" alt="QR ชำระเงิน" />
              <div v-if="qrExpired" class="qr-expired">
                <span class="material-symbols-rounded">timer_off</span>
                QR หมดอายุแล้ว
              </div>
            </div>
            <p class="qr-meta">
              <template v-if="qrData?.provider === 'promptpay'">
                QR พร้อมเพย์ของบริษัท — ลูกค้าโอนแล้วให้ส่งสลิปมาแนบในใบจองนี้
              </template>
              <template v-else-if="!qrExpired">
                รอลูกค้าสแกน · QR หมดอายุใน <strong>{{ qrCountdownText }}</strong>
              </template>
              <template v-else>กด "ออก QR ใหม่" เพื่อออกใบใหม่ให้ลูกค้า</template>
            </p>
          </template>

          <p v-if="qrError" class="qr-error">{{ qrError }}</p>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
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
// ต้องตรงกับ Booking::PENDING_TTL_MINUTES / HOLD_DEFAULT_DAYS / HOLD_MAX_DAYS ฝั่งเซิร์ฟเวอร์
const pendingTtlMinutes = 10;
const HOLD_DEFAULT_DAYS = 3;
const HOLD_MAX_DAYS = 30;
const bookingModeOptions = [
  {
    value: 'paid',
    label: 'รับชำระแล้ว',
    description: 'ลูกค้าโอนมาแล้ว — แนบสลิปและวันเวลาที่โอน',
    icon: 'task_alt',
  },
  {
    value: 'await',
    label: 'รอลูกค้าชำระ',
    description: `ส่งให้ลูกค้าไปจ่ายเอง ภายใน ${pendingTtlMinutes} นาที`,
    icon: 'hourglass_top',
  },
  {
    value: 'hold',
    label: 'ล็อกที่นั่งไว้ก่อน',
    description: 'ข้ามหน้าชำระเงิน กันที่นั่งไว้ให้ตามเวลาที่กำหนด',
    icon: 'event_seat',
  },
];
const holdQuickOptions = [
  { days: 1, label: '1 วัน' },
  { days: 3, label: '3 วัน' },
  { days: 7, label: '7 วัน' },
];
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

const holdQuickDays = ref(null);

// ข้อมูลลูกค้าที่ถูกดึงมาจากลิงก์กรอกเอง — โชว์แถบบอกที่มา แล้วส่ง id กลับตอนบันทึก
// เป็นรายการเพราะกลุ่มที่มาด้วยกันอาจถูกกรอกแยกกันมาหลายกลุ่ม แต่ต้องจองใบเดียว
const intakeIds = ref([]);
const intakeSources = ref([]);
const intakeDroppedCount = ref(0);
const intakeScheduleMixed = ref(false);

// ── QR ให้ลูกค้าสแกนจ่าย (หลังเปิดการจองเสร็จ) ──
const qrData = ref(null);
const qrLoading = ref(false);
const qrError = ref('');
const qrSecondsLeft = ref(0);
let qrPollTimer = null;
let qrTickTimer = null;

const route = useRoute();

const form = reactive({
  trip_id: '',
  schedule_id: '',
  customer_name: '',
  email: '',
  phone: '',
  // mode = paid | await | hold — status เป็นผลของ mode ไม่ให้ตั้งสวนกันได้
  mode: 'await',
  status: 'pending',
  hold_until: '',
  hold_note: '',
  payment_type: 'full',
  payment_method: 'promptpay',
  installment_count: 2,
  transfer_date: '',
  transfer_time: '',
  is_join_trip: false,
  pickup_point_id: '',
  vehicle_option_id: '',
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
// ประเภทรถของรอบ (บัส/ตู้ คนละราคา) — ส่วนต่างคิดต่อคนบนราคาที่ได้จากจุดรับ
const vehicleOptions = computed(() => selectedSchedule.value?.vehicle_options || []);
const selectedVehicleOption = computed(
  () => vehicleOptions.value.find((option) => option.id === Number(form.vehicle_option_id)) || null,
);
const vehicleAdjustment = computed(() => Number(selectedVehicleOption.value?.price_adjustment || 0));
const selectedPickup = computed(() => pickupPoints.value.find((point) => point.id === Number(form.pickup_point_id)) || null);
const requiresDiveInfo = computed(() => ['diving', 'snorkeling'].includes(selectedTrip.value?.type || selectedSchedule.value?.trip?.type));
const installmentAllowed = computed(() => Boolean(selectedSchedule.value?.installment_enabled && !form.is_join_trip));
const depositAllowed = computed(() => Boolean(selectedSchedule.value?.deposit_enabled && !form.is_join_trip));
// เพดานมาจากเซิร์ฟเวอร์ (PaymentQuote::maxInstallmentCount) ไม่ต้องกำหนดซ้ำที่นี่
// ไม่งั้นวันที่เจ้าของเปลี่ยนกติกา หน้านี้จะเป็นที่เดียวที่ยังจำเลขเก่า
const maxInstallmentCount = computed(() => Math.max(Number(selectedSchedule.value?.installment_count || 2), 2));
const installmentCountOptions = computed(() => Array.from({ length: maxInstallmentCount.value - 1 }, (_, index) => index + 2));
const pricePerPerson = computed(() => {
  if (!selectedSchedule.value) return 0;
  if (form.is_join_trip) return Number(selectedSchedule.value.join_trip_price || selectedSchedule.value.price || 0);
  if (selectedPickup.value) return Number(selectedPickup.value.price || 0);
  return Number(selectedSchedule.value.price || 0);
});

/** ราคาของคนคนหนึ่ง — จุดของตัวเองก่อน แล้วค่อยจุดรับหลัก/ราคารอบ */
const priceForPassenger = (passenger) => {
  if (form.is_join_trip) return pricePerPerson.value;
  const own = pickupPoints.value.find((point) => point.id === Number(passenger.pickup_point_id));

  return (own ? Number(own.price || 0) : pricePerPerson.value) + vehicleAdjustment.value;
};

// บวกทีละคน ไม่ใช่คูณราคาเดียว — ในใบเดียวกันแต่ละคนขึ้นคนละจุดได้ และราคาจุดรับ
// คือราคาต่อคนของโซนนั้น ไม่ใช่ค่าบริการที่บวกเพิ่ม
const totalAmount = computed(
  () => passengers.value.reduce((sum, passenger) => sum + priceForPassenger(passenger), 0),
);

/** จุดรับที่ใช้จริงในใบนี้ พร้อมจำนวนคน — ไว้เตือนตาก่อนกดบันทึก */
const pickupBreakdown = computed(() => {
  if (form.is_join_trip || !pickupPoints.value.length) return [];

  const buckets = new Map();
  passengers.value.forEach((passenger) => {
    const own = pickupPoints.value.find((point) => point.id === Number(passenger.pickup_point_id))
      || selectedPickup.value;
    const key = own?.id ?? 0;
    const current = buckets.get(key)
      || { id: key, label: own ? (own.pickup_location || own.region_label || own.region) : 'ยังไม่ระบุจุดรับ', count: 0 };
    current.count += 1;
    buckets.set(key, current);
  });

  return [...buckets.values()];
});
// งวดถูกหารจาก "วันนี้ → วันปิดยอด" (วันเดินทาง - 15 วัน) แบบเดียวกับ PaymentQuote
// ฝั่งเซิร์ฟเวอร์ ไม่ใช่เดินทีละ 30 วันแล้วไปตกหลังวันเดินทาง
const installmentDueDates = computed(() => {
  const count = Math.max(1, Number(form.installment_count || 2));
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const closingRaw = selectedSchedule.value?.installment_final_due_date;
  const closing = closingRaw ? new Date(`${closingRaw}T00:00:00`) : today;
  const windowDays = Math.max(0, Math.round((closing - today) / 86400000));

  return Array.from({ length: count }, (_, index) => {
    const dueDate = new Date(today);
    dueDate.setDate(today.getDate() + (count > 1 ? Math.round((index * windowDays) / (count - 1)) : 0));
    return formatInputDate(dueDate);
  });
});
const installmentPlan = computed(() => {
  const count = Number(form.installment_count || 2);
  const perInstallment = Math.round((totalAmount.value / count) * 100) / 100;
  const dueDates = installmentDueDates.value;

  return Array.from({ length: count }, (_, index) => ({
    no: index + 1,
    amount: index === count - 1
      ? Math.round((totalAmount.value - (perInstallment * (count - 1))) * 100) / 100
      : perInstallment,
    due_date: dueDates[index],
  }));
});
// ยอดมัดจำ — คำนวณฝั่ง client ให้ตรงกับ TripSchedule::resolveDepositAmount() ฝั่งเซิร์ฟเวอร์
const depositAmount = computed(() => {
  const schedule = selectedSchedule.value;
  if (!schedule || !schedule.deposit_enabled) return 0;
  let deposit = 0;
  if (schedule.deposit_type === 'percent' && schedule.deposit_percent) {
    deposit = Math.round((totalAmount.value * Number(schedule.deposit_percent) / 100) * 100) / 100;
  } else if (schedule.deposit_type === 'amount' && schedule.deposit_amount) {
    deposit = Math.round(Number(schedule.deposit_amount) * Math.max(1, passengers.value.length) * 100) / 100;
  }
  return Math.max(0, Math.min(deposit, totalAmount.value));
});
const balanceAmount = computed(() => Math.max(0, Math.round((totalAmount.value - depositAmount.value) * 100) / 100));
const balanceDueDate = computed(() => {
  const departure = selectedSchedule.value?.departure_date;
  if (!departure) return '';
  const due = new Date(departure);
  due.setDate(due.getDate() - 15);
  return formatInputDate(due);
});
const payableNow = computed(() => {
  if (form.payment_type === 'installment') return installmentPlan.value[0]?.amount || 0;
  if (form.payment_type === 'deposit') return depositAmount.value;
  return totalAmount.value;
});
const payableNowLabel = computed(() => {
  if (form.payment_type === 'installment') return 'ยอดรับงวดแรก';
  if (form.payment_type === 'deposit') return 'ยอดมัดจำ';
  return 'ยอดที่ต้องชำระ';
});
const paymentPlanLabel = computed(() => {
  if (form.payment_type === 'installment') return `ผ่อน ${form.installment_count} งวด`;
  if (form.payment_type === 'deposit') return 'มัดจำ';
  return 'จ่ายเต็ม';
});
const paymentEvidenceRequired = computed(() => form.mode === 'paid');
const isPaidMode = computed(() => form.mode === 'paid');

const qrImageSrc = computed(() => {
  if (!qrData.value) return '';
  // Beam ส่ง PNG มาเป็น base64 ส่วน QR พร้อมเพย์ของเราเองเป็น SVG data URI
  return qrData.value.qr_image_base64
    ? `data:image/png;base64,${qrData.value.qr_image_base64}`
    : (qrData.value.qr_data_uri || '');
});
const qrPaid = computed(() => qrData.value?.status === 'succeeded');
// QR พร้อมเพย์ของเราเองไม่มีวันหมดอายุ — มีแต่ QR ของเกตเวย์ที่นับถอยหลัง
const qrExpired = computed(
  () => Boolean(qrData.value?.expires_at) && qrSecondsLeft.value <= 0 && !qrPaid.value,
);
const qrCountdownText = computed(() => {
  const total = Math.max(0, qrSecondsLeft.value);
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
});
const isHoldMode = computed(() => form.mode === 'hold');

// เวลารถออกจริงของรอบนี้ในเวลาไทย — ล็อกที่นั่งข้ามวันเดินทางไม่มีความหมาย
const holdLimitDate = computed(() => {
  const schedule = selectedSchedule.value;
  if (!schedule?.departure_date) return null;
  const raw = schedule.departs_at || `${schedule.departure_date}T00:00:00`;
  const parsed = new Date(`${raw.slice(0, 10)}T${raw.slice(11, 16) || '00:00'}:00`);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
});
const holdCeilingDate = computed(() => {
  const cap = new Date();
  cap.setDate(cap.getDate() + HOLD_MAX_DAYS);
  const limit = holdLimitDate.value;
  return limit && limit < cap ? limit : cap;
});
const holdMinInput = computed(() => formatInputDateTime(new Date()));
const holdMaxInput = computed(() => formatInputDateTime(holdCeilingDate.value));
const holdUntilDate = computed(() => {
  if (!form.hold_until) return null;
  const parsed = new Date(form.hold_until);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
});
const isHoldValid = computed(() => {
  const until = holdUntilDate.value;
  if (!until) return false;
  return until > new Date() && until <= holdCeilingDate.value;
});
const holdHint = computed(() => {
  const until = holdUntilDate.value;
  if (!until) return `เลือกวันและเวลาที่จะกันที่นั่งไว้ให้ ล็อกได้ไม่เกิน ${HOLD_MAX_DAYS} วัน`;
  if (until <= new Date()) return 'เวลาที่เลือกผ่านมาแล้ว กรุณาเลือกใหม่';
  if (until > holdCeilingDate.value) {
    return holdLimitDate.value && holdCeilingDate.value === holdLimitDate.value
      ? `ล็อกได้ถึงเวลาออกเดินทางเท่านั้น (${formatDateTime(holdLimitDate.value)})`
      : `ล็อกที่นั่งได้ไม่เกิน ${HOLD_MAX_DAYS} วัน`;
  }
  return `กันที่นั่งไว้ให้ถึง ${formatDateTime(until)} (${holdRemainingLabel(until)}) — เลยเวลานี้ระบบจะคืนที่นั่งให้รอบเดินทางอัตโนมัติ`;
});
const submitLabel = computed(() => {
  if (isHoldMode.value) return 'ล็อกที่นั่งให้ลูกค้า';
  return form.send_email ? 'สร้างการจองและส่งอีเมล' : 'สร้างการจอง';
});
const submitIcon = computed(() => (isHoldMode.value ? 'event_seat' : 'send'));
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
// รอบที่บินไปไม่มีผังที่นั่งให้เลือก (สายการบินจัดที่นั่งเอง) — ถ้ายังบังคับอยู่
// จะสร้างการจองให้ลูกค้าไม่ได้เลย
const seatSelectionRequired = computed(
  () => !form.is_join_trip && seatMap.value?.has_seat_map !== false,
);

const canSubmit = computed(() => {
  if (!form.schedule_id || !form.customer_name || !form.email || !form.phone) return false;
  if (passengers.value.some((passenger) => !isPassengerComplete(passenger))) return false;
  if (seatSelectionRequired.value && selectedSeatIds.value.length !== passengers.value.length) return false;
  if (form.payment_type === 'installment' && !installmentAllowed.value) return false;
  if (form.payment_type === 'deposit' && !depositAllowed.value) return false;
  if (paymentEvidenceRequired.value && (!slipFile.value || !form.transfer_date || !form.transfer_time)) return false;
  if (isHoldMode.value && !isHoldValid.value) return false;
  return true;
});
const submitHint = computed(() => {
  if (!selectedSchedule.value) return 'เลือกรอบเดินทางก่อน';
  if (!form.customer_name || !form.email || !form.phone) return 'กรอกข้อมูลลูกค้าให้ครบ';
  if (passengers.value.some((passenger) => !isPassengerComplete(passenger))) return 'กรอกข้อมูลผู้เดินทางที่มี * ให้ครบ';
  if (!form.is_join_trip && seatsLoading.value) return 'กำลังโหลดผังที่นั่ง';
  if (!form.is_join_trip && !seatMap.value) return 'โหลดผังที่นั่งก่อน';
  if (seatSelectionRequired.value && selectedSeatIds.value.length !== passengers.value.length) {
    return `เลือกที่นั่ง ${selectedSeatIds.value.length}/${passengers.value.length}`;
  }
  if (form.payment_type === 'installment' && !installmentAllowed.value) return 'รอบนี้ใกล้วันเดินทางเกินกว่าจะผ่อนชำระได้';
  if (form.payment_type === 'deposit' && !depositAllowed.value) return 'รอบนี้ยังไม่เปิดมัดจำ';
  if (paymentEvidenceRequired.value && !slipFile.value) return 'แนบไฟล์สลิปก่อน';
  if (paymentEvidenceRequired.value && (!form.transfer_date || !form.transfer_time)) return 'ระบุวันที่และเวลาที่โอน';
  if (isHoldMode.value && !isHoldValid.value) return 'ระบุวันและเวลาที่จะล็อกที่นั่งถึง';
  return '';
});

onMounted(async () => {
  await fetchTrips();
  await prefillFromIntake(route.query.intake);
});

onBeforeUnmount(stopQrTimers);

watch(() => passengers.value.length, () => {
  selectedSeatIds.value = selectedSeatIds.value.slice(0, passengers.value.length);
});

watch(holdCeilingDate, (ceiling) => {
  // เปลี่ยนรอบแล้วเส้นตายเดิมเลยวันเดินทางของรอบใหม่ — ดึงกลับมาให้อยู่ในกรอบ
  if (holdUntilDate.value && ceiling && holdUntilDate.value > ceiling) {
    form.hold_until = formatInputDateTime(ceiling);
    holdQuickDays.value = null;
  }
});

watch([selectedSchedule, () => form.is_join_trip], () => {
  // รีเซ็ตเฉพาะเมื่อรูปแบบที่เลือกอยู่ใช้ไม่ได้กับรอบใหม่ ไม่ทับรูปแบบที่ยังใช้ได้
  if (form.payment_type === 'installment' && !installmentAllowed.value) form.payment_type = 'full';
  if (form.payment_type === 'deposit' && !depositAllowed.value) form.payment_type = 'full';
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
    pickup_point_id: '',
  };
}

/**
 * เติมฟอร์มจากข้อมูลที่ลูกค้ากรอกมาเองผ่านลิงก์ (/admin/intakes)
 *
 * รับได้ทีละหลายกลุ่ม ("3,7,9") เพราะลูกค้าที่มาด้วยกันมักกดลิงก์ทีมงานคนละครั้ง
 * จนกลายเป็นคนละกลุ่ม แต่ต้องขึ้นรถคันเดียวกันและเลือกที่นั่งพร้อมกัน
 *
 * เดินตามลำดับเดียวกับที่แอดมินกดเอง — เลือกทริปก่อน แล้วรอรายการรอบโหลดเสร็จ
 * ค่อยเลือกรอบ ไม่งั้น onTripChange จะล้าง schedule_id ที่เพิ่งตั้งไปทิ้ง
 */
async function prefillFromIntake(intakeParam) {
  const ids = String(intakeParam ?? '')
    .split(',')
    .map((value) => Number(value.trim()))
    .filter((value) => Number.isInteger(value) && value > 0);

  if (!ids.length) return;

  try {
    const responses = await Promise.all(ids.map((id) => api.get(`/admin/intakes/${id}`)));
    const sources = responses.map((res) => res.data?.data).filter(Boolean);
    if (!sources.length) return;

    intakeIds.value = sources.map((source) => source.id);
    intakeSources.value = sources;

    // ผู้ติดต่อคือคนของกลุ่มแรก — ใบจองมีเจ้าของได้คนเดียว และคนที่ทีมงานคุยด้วย
    // คือคนที่กดลิงก์มาก่อน
    const [lead] = sources;
    form.customer_name = lead.contact_name || '';
    form.phone = lead.contact_phone || '';
    form.email = lead.contact_email || '';

    // รอบเดินทางยึดกลุ่มแรกที่ระบุรอบไว้ ถ้ากลุ่มอื่นคนละรอบ แถบด้านบนจะเตือนให้ตรวจ
    const scheduled = sources.find((source) => source.trip_id) || null;
    intakeScheduleMixed.value = new Set(
      sources.map((source) => source.trip_schedule_id ?? null),
    ).size > 1;

    // ตัวเลขล้วน ไม่ใช่สตริง — select ผูกด้วย v-model.number และ :value เป็น id ตัวเลข
    // ถ้าใส่เป็นสตริงช่องจะขึ้นว่างทั้งที่ค่าถูกตั้งไว้แล้ว
    if (scheduled) {
      form.trip_id = Number(scheduled.trip_id);
      await onTripChange();
      if (scheduled.trip_schedule_id) {
        form.schedule_id = Number(scheduled.trip_schedule_id);
        await onScheduleChange();
      }
    }

    const incoming = sources.flatMap((source) => source.passengers || []);
    const merged = [];
    const seen = new Set();
    let dropped = 0;

    for (const passenger of incoming) {
      // คนเดียวกันกรอกทั้งลิงก์ทีมงานและลิงก์กลุ่มก็เกิดขึ้นจริง — เบอร์คือตัวชี้ขาด
      // เหมือนฝั่งเซิร์ฟเวอร์ ไม่มีเบอร์ค่อยถอยไปเทียบชื่อแบบตัดช่องว่าง
      const key = (passenger.phone || '').replace(/\D/g, '')
        || (passenger.name || '').replace(/\s+/g, '').toLowerCase();

      if (key && seen.has(key)) {
        dropped += 1;
        continue;
      }
      if (key) seen.add(key);

      merged.push({
        ...newPassenger(),
        ...passenger,
        // ค่าที่เซิร์ฟเวอร์ส่งมาเป็น null ต้องกลายเป็นค่าว่าง ไม่งั้น input แสดง "null"
        ...Object.fromEntries(
          Object.entries(passenger).filter(([, value]) => value === null).map(([field]) => [field, '']),
        ),
        halal_food: passenger.halal_food ?? null,
        weight: passenger.weight ?? null,
      });
    }

    intakeDroppedCount.value = dropped;

    if (merged.length) {
      passengers.value = merged;
    }
  } catch (error) {
    toast.error(error.response?.data?.message || 'ดึงข้อมูลลูกค้าไม่สำเร็จ');
  }
}

function clearPassengerPickups() {
  passengers.value.forEach((passenger) => {
    passenger.pickup_point_id = '';
  });
}

async function fetchTrips() {
  const res = await api.get('/admin/trips', { params: { per_page: 500 } });
  trips.value = res.data?.data || [];
}

async function onTripChange() {
  form.schedule_id = '';
  form.pickup_point_id = '';
  form.vehicle_option_id = '';
  clearPassengerPickups();
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
  form.vehicle_option_id = '';
  // จุดรับเป็นของรอบ ไม่ใช่ของทริป — ค้างไว้ข้ามรอบคือส่ง id ของรอบอื่นไปให้เซิร์ฟเวอร์
  clearPassengerPickups();
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
    // ผังเป็นของคันที่เลือกให้ลูกค้า — รอบที่วิ่งทั้งบัสและตู้มีคนละผัง
    const res = await api.get(`/schedules/${form.schedule_id}/seats`, {
      params: form.vehicle_option_id ? { vehicle_option_id: form.vehicle_option_id } : {},
    });
    seatMap.value = res.data?.data || null;
  } catch (error) {
    seatError.value = error.response?.data?.message || 'โหลดผังที่นั่งไม่สำเร็จ';
    seatMap.value = null;
  } finally {
    seatsLoading.value = false;
  }
}

// ที่นั่งผูกกับคัน — เปลี่ยนคันแล้วผังและที่นั่งที่เลือกไว้ต้องเริ่มใหม่
async function onVehicleOptionChange() {
  selectedSeatIds.value = [];
  seatError.value = '';
  if (!form.is_join_trip && form.schedule_id) await fetchSeatMap();
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

function formatInputDateTime(date) {
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${formatInputDate(date)}T${hours}:${minutes}`;
}

// สลับโหมดเรื่องเงิน — status เป็นผลของ mode เสมอ และโหมดล็อกที่นั่งไม่ส่งอีเมลเอง
// (บางครั้งยังคุยกับลูกค้าไม่จบ อีเมลยืนยันจะกลายเป็นคำสัญญาไปก่อน)
function onModeChange() {
  form.status = form.mode === 'paid' ? 'confirmed' : 'pending';

  if (form.mode === 'hold') {
    form.send_email = false;
    if (!form.hold_until) setHoldDays(HOLD_DEFAULT_DAYS);
    return;
  }

  form.send_email = true;
  form.hold_until = '';
  form.hold_note = '';
  holdQuickDays.value = null;
}

function setHoldDays(days) {
  const until = new Date();
  until.setDate(until.getDate() + days);
  until.setSeconds(0, 0);
  const ceiling = holdCeilingDate.value;
  form.hold_until = formatInputDateTime(until > ceiling ? ceiling : until);
  holdQuickDays.value = days;
}

// "อีก 3 วัน" / "อีก 5 ชั่วโมง" — บอกความยาวของล็อกให้เห็นภาพ ไม่ต้องนับวันเอง
function holdRemainingLabel(until) {
  const minutes = Math.max(0, Math.round((until.getTime() - Date.now()) / 60000));
  if (minutes < 60) return `อีก ${minutes} นาที`;
  const hours = Math.round(minutes / 60);
  if (hours < 48) return `อีก ${hours} ชั่วโมง`;
  return `อีก ${Math.round(hours / 24)} วัน`;
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
  appendFormValue(fd, 'vehicle_option_id', form.vehicle_option_id || null);
  appendFormValue(fd, 'passenger_count', passengers.value.length);
  appendFormValue(fd, 'send_email', form.send_email);
  // มาจากหน้า "ข้อมูลลูกค้าจากลิงก์" — เซิร์ฟเวอร์ปิดทุกกลุ่มที่รวมมาให้เองเมื่อจองสำเร็จ
  intakeIds.value.forEach((id) => appendFormValue(fd, 'intake_ids[]', id));

  if (isHoldMode.value && holdUntilDate.value) {
    // ส่งเป็น ISO ที่มีโซนเวลาติดไป เซิร์ฟเวอร์จะได้ไม่ต้องเดาว่าเป็นเวลาไทยหรือ UTC
    appendFormValue(fd, 'hold_until', holdUntilDate.value.toISOString());
    appendFormValue(fd, 'hold_note', form.hold_note);
  }

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
  stopQrTimers();
  qrData.value = null;
  qrError.value = '';
  try {
    const res = await api.post('/admin/bookings/manual', buildBookingPayload());
    createdBooking.value = res.data?.data || null;
    toast.success(res.data?.message || (form.send_email ? 'สร้างการจองและส่งอีเมลแล้ว' : 'สร้างการจองแล้ว'));

    // ยังไม่ได้รับเงิน = ทีมงานต้องส่ง QR ให้ลูกค้าต่อทันที ออกให้เลยไม่ต้องกดอีกที
    if (createdBooking.value && !isPaidMode.value) {
      await createQr();
    }
  } catch (error) {
    toast.error(error.response?.data?.message || 'สร้างการจองไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

/**
 * ออก QR ให้ลูกค้าสแกนจ่าย — ยอดและชนิดของหนี้เซิร์ฟเวอร์เป็นคนตัดสิน หน้านี้แค่แสดง
 *
 * ไม่มีใครกด "จ่ายแล้ว" ที่ฝั่งลูกค้าได้ (เขาอยู่ในแชท ไม่ได้เปิดแอป) จึงถามสถานะเอง
 * เป็นระยะ แล้วสลับการ์ดเป็น "ชำระเงินแล้ว" ทันทีที่ webhook ของ Beam เข้า
 */
async function createQr() {
  if (!createdBooking.value || qrLoading.value) return;

  stopQrTimers();
  qrLoading.value = true;
  qrError.value = '';
  try {
    const res = await api.post(`/admin/payments/${createdBooking.value.booking_ref}/qr`);
    qrData.value = res.data?.data || null;
    watchQr();
  } catch (error) {
    qrData.value = null;
    qrError.value = error.response?.data?.message || 'ออก QR ไม่สำเร็จ';
  } finally {
    qrLoading.value = false;
  }
}

function stopQrTimers() {
  if (qrPollTimer) { clearInterval(qrPollTimer); qrPollTimer = null; }
  if (qrTickTimer) { clearInterval(qrTickTimer); qrTickTimer = null; }
}

function watchQr() {
  const expiresAt = qrData.value?.expires_at ? new Date(qrData.value.expires_at).getTime() : 0;

  const tick = () => {
    qrSecondsLeft.value = expiresAt ? Math.max(0, Math.round((expiresAt - Date.now()) / 1000)) : 0;
    // หมดอายุแล้วก็ไม่ต้องถามต่อ ทีมงานต้องกดออกใบใหม่อยู่ดี
    if (expiresAt && qrSecondsLeft.value <= 0) stopQrTimers();
  };
  tick();
  qrTickTimer = setInterval(tick, 1000);

  // QR พร้อมเพย์ (ไม่มี payment_id) ไม่มีอะไรให้ถาม — เงินเข้าเมื่อลูกค้าส่งสลิปมา
  if (!qrData.value?.payment_id) return;

  qrPollTimer = setInterval(pollQrOnce, 3000);
}

async function pollQrOnce() {
  const paymentId = qrData.value?.payment_id;
  if (!paymentId || !createdBooking.value) return;

  try {
    const res = await api.get(`/admin/payments/${createdBooking.value.booking_ref}/qr/${paymentId}`);
    const fresh = res.data?.data;
    if (!fresh) return;

    qrData.value = { ...qrData.value, status: fresh.status };
    if (['succeeded', 'failed', 'expired'].includes(fresh.status)) {
      stopQrTimers();
    }
  } catch {
    // เน็ตสะดุดรอบเดียวไม่ใช่เรื่องต้องแจ้ง รอบหน้าค่อยถามใหม่
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

function formatDateTime(value) {
  if (!value) return '-';
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return `${date.toLocaleDateString('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })} ${date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })} น.`;
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

.intake-banner {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  background: #ecfdf5;
  border: 1px solid #6ee7b7;
  border-radius: 12px;
  padding: 14px 16px;
  margin-bottom: 16px;
}

.intake-banner > div { flex: 1; }
.intake-banner strong { font-size: 14px; color: #065f46; }
.intake-banner p { font-size: 12.5px; color: #047857; margin-top: 2px; }
.intake-banner .material-symbols-rounded { color: #059669; }
.intake-warn { display: flex; align-items: center; gap: 4px; color: #b45309 !important; }
.intake-warn .material-symbols-rounded { font-size: 16px; color: #b45309; }

/* ── QR ให้ลูกค้าสแกนจ่าย ─────────────────────────────────────── */
.qr-panel {
  margin-top: 14px;
  padding: 16px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 14px;
  background: #fff;
}
.qr-head { display: flex; align-items: flex-start; gap: 12px; }
.qr-head > div { flex: 1; display: flex; flex-direction: column; gap: 2px; }
.qr-head strong { font-size: 14px; color: #111827; }
.qr-head span { font-size: 12.5px; color: #6b7280; }
.qr-image-wrap { position: relative; margin: 14px auto 0; width: 220px; }
.qr-image-wrap img { width: 100%; display: block; border-radius: 12px; background: #fff; }
.qr-expired {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-radius: 12px;
  background: rgba(255, 255, 255, .93);
  font-size: 13px;
  font-weight: 700;
  color: #b45309;
}
.qr-meta { margin-top: 10px; font-size: 12.5px; color: #6b7280; text-align: center; }
.qr-meta strong { color: #059669; font-variant-numeric: tabular-nums; }
.qr-error { margin-top: 10px; font-size: 12.5px; font-weight: 700; color: #dc2626; }
.qr-paid {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 14px;
  padding: 12px 14px;
  border: 1px solid #bbf7d0;
  border-radius: 12px;
  background: #f0fdf4;
}
.qr-paid .material-symbols-rounded { color: #059669; }
.qr-paid strong { font-size: 14px; color: #065f46; }
.qr-paid p { font-size: 12.5px; color: #047857; }

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

.pickup-breakdown {
  display: grid;
  gap: 2px;
  text-align: right;
}

.summary-row--stack {
  align-items: flex-start;
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

.mode-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.hold-panel {
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #f9fafb;
  padding: 14px;
}

.hold-quick {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.hold-chip {
  border: 1px solid #d1d5db;
  border-radius: 999px;
  background: #ffffff;
  color: #374151;
  cursor: pointer;
  font-size: 12px;
  font-weight: 800;
  padding: 6px 14px;
}

.hold-chip.active {
  border-color: var(--color-accent);
  background: #eef8f1;
  color: var(--color-accent);
}

.pay-hold-icon {
  color: var(--color-accent);
  font-size: 30px;
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

.deposit-note {
  margin: 0;
  color: #92400e;
  font-size: 11px;
  font-weight: 700;
  line-height: 1.5;
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
