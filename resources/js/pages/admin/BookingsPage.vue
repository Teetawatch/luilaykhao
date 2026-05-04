<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded">confirmation_number</span>
          จัดการการจอง
        </h1>
        <p class="page-subtitle">
          ตรวจสอบลูกค้า ผู้โดยสาร ที่นั่ง จุดรับ สถานะชำระเงิน สลิป และ QR เช็คอินในหน้าเดียว
        </p>
      </div>
      <div class="header-actions">
        <button class="btn-secondary" :disabled="admin.loading" @click="fetchData(currentPage)">
          <span class="material-symbols-rounded" :class="{ 'animate-spin': admin.loading }">refresh</span>
          รีเฟรช
        </button>
        <button class="btn-primary" @click="openManualBookingModal">
          <span class="material-symbols-rounded">add_circle</span>
          เพิ่มการจองด้วยตนเอง
        </button>
      </div>
    </div>

    <div class="summary-grid">
      <div class="summary-card">
        <span class="summary-icon material-symbols-rounded">receipt_long</span>
        <div>
          <span class="summary-label">รายการในหน้านี้</span>
          <strong class="summary-value">{{ pageStats.bookings }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon people material-symbols-rounded">groups</span>
        <div>
          <span class="summary-label">ผู้เดินทาง</span>
          <strong class="summary-value">{{ pageStats.passengers }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon money material-symbols-rounded">payments</span>
        <div>
          <span class="summary-label">ยอดจองรวม</span>
          <strong class="summary-value money">{{ formatMoney(pageStats.totalAmount) }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon paid material-symbols-rounded">verified</span>
        <div>
          <span class="summary-label">ชำระแล้ว</span>
          <strong class="summary-value money">{{ formatMoney(pageStats.paidAmount) }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon pending material-symbols-rounded">pending_actions</span>
        <div>
          <span class="summary-label">รอดำเนินการ</span>
          <strong class="summary-value">{{ pageStats.pending }}</strong>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon join material-symbols-rounded">group_add</span>
        <div>
          <span class="summary-label">จอยทริป</span>
          <strong class="summary-value">{{ pageStats.joinTrip }}</strong>
        </div>
      </div>
    </div>

    <div class="filters-panel">
      <div class="filters-bar booking-filters">
        <div class="search-box">
          <span class="material-symbols-rounded">search</span>
          <input
            v-model.trim="filters.search"
            placeholder="ค้นหารหัสจอง ชื่อลูกค้า เบอร์โทร ผู้โดยสาร หรือทริป..."
            @input="debouncedFetch"
          />
        </div>

        <select v-model="filters.status" @change="fetchData()">
          <option value="">ทุกสถานะ</option>
          <option value="pending">รอดำเนินการ</option>
          <option value="confirmed">ยืนยันแล้ว</option>
          <option value="cancelled">ยกเลิก</option>
          <option value="refunded">คืนเงินแล้ว</option>
        </select>

        <select v-model="filters.booking_type" @change="fetchData()">
          <option value="">ทุกประเภท</option>
          <option value="regular">จองปกติ</option>
          <option value="join_trip">จอยทริป</option>
        </select>

        <select v-model="filters.payment_type" @change="fetchData()">
          <option value="">ทุกการชำระ</option>
          <option value="full">ชำระเต็ม</option>
          <option value="installment">ผ่อนชำระ</option>
        </select>

        <input v-model="filters.date" type="date" @change="fetchData()" />

        <button class="btn-secondary compact" :disabled="!hasActiveFilters" @click="resetFilters">
          <span class="material-symbols-rounded">filter_alt_off</span>
          ล้างตัวกรอง
        </button>
      </div>

      <div class="filter-footnote">
        แสดง {{ bookings.length }} รายการ
        <span v-if="admin.bookings.meta">จากทั้งหมด {{ admin.bookings.meta.total || bookings.length }} รายการ</span>
      </div>
    </div>

    <div class="table-card bookings-table-card">
      <div v-if="admin.loading && !bookings.length" class="loading-state">
        <div class="spinner"></div>
      </div>

      <div v-else class="table-container">
        <table class="data-table booking-table">
          <thead>
            <tr>
              <th>การจอง</th>
              <th>ผู้จอง</th>
              <th>ทริป / รอบ</th>
              <th>ผู้โดยสาร</th>
              <th>ชำระเงิน</th>
              <th>สถานะ</th>
              <th>เช็คอิน</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="booking in bookings" :key="booking.id">
              <td>
                <div class="booking-ref-cell">
                  <button class="booking-ref-link" @click="openDetail(booking)">
                    {{ booking.booking_ref }}
                  </button>
                  <span class="table-subtext">{{ formatDateTime(booking.created_at) }}</span>
                  <div class="mini-badges">
                    <span v-if="booking.is_join_trip" class="mini-badge join">จอยทริป</span>
                    <span v-if="booking.is_group" class="mini-badge group">กรุ๊ป</span>
                  </div>
                </div>
              </td>

              <td>
                <div class="customer-cell">
                  <strong>{{ booking.user?.name || '-' }}</strong>
                  <span>{{ booking.user?.phone || booking.passengers?.[0]?.phone || '-' }}</span>
                  <span>{{ booking.user?.email || '-' }}</span>
                </div>
              </td>

              <td>
                <div class="trip-cell-text">
                  <strong>{{ booking.schedule?.trip?.title || '-' }}</strong>
                  <span>
                    {{ formatDate(booking.schedule?.departure_date) }}
                    <template v-if="booking.schedule?.return_date">- {{ formatDate(booking.schedule.return_date) }}</template>
                  </span>
                  <span>{{ vehicleName(booking) }}</span>
                </div>
              </td>

              <td>
                <div class="passenger-cell">
                  <strong>{{ passengerCount(booking) }} คน</strong>
                  <span v-if="seatLabels(booking)">ที่นั่ง {{ seatLabels(booking) }}</span>
                  <span v-else-if="booking.is_join_trip">ไม่ระบุที่นั่ง</span>
                  <span v-else>ยังไม่มีที่นั่ง</span>
                </div>
              </td>

              <td>
                <div class="payment-cell">
                  <strong>{{ formatMoney(booking.total_amount) }}</strong>
                  <span>จ่ายแล้ว {{ formatMoney(booking.paid_amount) }}</span>
                  <div class="payment-progress">
                    <div :style="{ width: paymentProgress(booking) + '%' }"></div>
                  </div>
                  <span class="payment-type" :class="booking.payment_type === 'installment' ? 'installment' : 'full'">
                    {{ paymentTypeLabel(booking) }}
                  </span>
                </div>
              </td>

              <td>
                <span class="status-badge" :class="`status-${booking.status}`">
                  {{ statusLabels[booking.status] || booking.status || '-' }}
                </span>
              </td>

              <td>
                <span v-if="booking.checked_in" class="checkin-badge checked">
                  <span class="material-symbols-rounded">task_alt</span>
                  {{ formatDate(booking.checked_in_at) }}
                </span>
                <span v-else class="checkin-badge">
                  <span class="material-symbols-rounded">qr_code_scanner</span>
                  ยังไม่เช็คอิน
                </span>
              </td>

              <td>
                <div class="action-btns">
                  <button class="btn-icon btn-view" title="รายละเอียด" @click="openDetail(booking)">
                    <span class="material-symbols-rounded">visibility</span>
                  </button>
                  <button class="btn-icon btn-edit" title="เปลี่ยนสถานะ" @click="openStatusModal(booking)">
                    <span class="material-symbols-rounded">swap_horiz</span>
                  </button>
                  <button class="btn-icon btn-delete" title="ลบการจอง" @click="confirmDelete(booking)">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="!bookings.length">
              <td colspan="8" class="empty-state">
                <span class="material-symbols-rounded empty-icon">inbox</span>
                ไม่พบข้อมูลการจอง
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="admin.bookings.meta?.last_page > 1" class="pagination">
        <button :disabled="admin.bookings.meta.current_page <= 1" @click="goPage(admin.bookings.meta.current_page - 1)">
          <span class="material-symbols-rounded">chevron_left</span>
        </button>
        <span class="page-info">
          หน้า {{ admin.bookings.meta.current_page }} / {{ admin.bookings.meta.last_page }}
        </span>
        <button :disabled="admin.bookings.meta.current_page >= admin.bookings.meta.last_page" @click="goPage(admin.bookings.meta.current_page + 1)">
          <span class="material-symbols-rounded">chevron_right</span>
        </button>
      </div>
    </div>

    <div v-if="showDetail" class="modal-overlay" @click.self="closeDetail">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2>รายละเอียดการจอง</h2>
            <p class="modal-subtitle">
              {{ detailBooking?.booking_ref || '-' }} · {{ detailBooking?.schedule?.trip?.title || '-' }}
            </p>
          </div>
          <button class="modal-close" @click="closeDetail">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div v-if="loadingDetail" class="modal-body loading-state">
          <div class="spinner"></div>
        </div>

        <div v-else-if="detailBooking" class="modal-body detail-body">
          <div class="detail-hero">
            <div>
              <span class="detail-ref">{{ detailBooking.booking_ref }}</span>
              <div class="detail-hero-title">{{ detailBooking.user?.name || '-' }}</div>
              <div class="detail-hero-subtitle">
                {{ passengerCount(detailBooking) }} ผู้เดินทาง · {{ formatMoney(detailBooking.total_amount) }}
              </div>
            </div>
            <div class="detail-hero-badges">
              <span class="status-badge" :class="`status-${detailBooking.status}`">
                {{ statusLabels[detailBooking.status] || '-' }}
              </span>
              <span v-if="detailBooking.is_join_trip" class="type-badge join">จอยทริป</span>
              <span v-if="detailBooking.is_group" class="type-badge">การจองกลุ่ม</span>
            </div>
          </div>

          <div class="detail-summary-grid">
            <div class="detail-stat">
              <span>ยอดรวม</span>
              <strong>{{ formatMoney(detailBooking.total_amount) }}</strong>
            </div>
            <div class="detail-stat">
              <span>ชำระแล้ว</span>
              <strong class="text-green">{{ formatMoney(detailBooking.paid_amount) }}</strong>
            </div>
            <div class="detail-stat">
              <span>คงเหลือ</span>
              <strong :class="paymentBalance(detailBooking) > 0 ? 'text-warn' : 'text-green'">
                {{ formatMoney(paymentBalance(detailBooking)) }}
              </strong>
            </div>
            <div class="detail-stat">
              <span>เช็คอิน</span>
              <strong>{{ detailBooking.checked_in ? 'เช็คอินแล้ว' : 'ยังไม่เช็คอิน' }}</strong>
            </div>
          </div>

          <section class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">event_note</span>
              ข้อมูลการจอง
            </div>
            <div class="detail-grid">
              <InfoItem label="วันที่จอง" :value="formatDateTime(detailBooking.created_at)" />
              <InfoItem label="ทริป" :value="detailBooking.schedule?.trip?.title || '-'" />
              <InfoItem label="วันเดินทาง" :value="formatScheduleRange(detailBooking.schedule)" />
              <InfoItem label="พาหนะ" :value="vehicleName(detailBooking)" />
              <InfoItem label="ประเภทการจอง" :value="detailBooking.is_join_trip ? 'จอยทริป' : 'จองปกติ'" />
              <InfoItem label="QR เช็คอิน" :value="detailBooking.qr_code || '-'" />
              <InfoItem v-if="detailBooking.cancelled_at" label="วันที่ยกเลิก" :value="formatDateTime(detailBooking.cancelled_at)" />
              <InfoItem v-if="detailBooking.cancellation_reason" label="เหตุผลยกเลิก" :value="detailBooking.cancellation_reason" wide />
            </div>
          </section>

          <section class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">person</span>
              ข้อมูลผู้จอง
            </div>
            <div class="detail-grid">
              <InfoItem label="ชื่อ" :value="detailBooking.user?.name || '-'" />
              <InfoItem label="อีเมล" :value="detailBooking.user?.email || '-'" />
              <InfoItem label="เบอร์โทร" :value="detailBooking.user?.phone || detailBooking.passengers?.[0]?.phone || '-'" />
              <InfoItem v-if="detailBooking.is_group" label="ชื่อกลุ่ม" :value="detailBooking.group_name || '-'" />
              <InfoItem v-if="detailBooking.group_notes" label="หมายเหตุกลุ่ม" :value="detailBooking.group_notes" wide />
            </div>
          </section>

          <section class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">payments</span>
              การชำระเงิน
            </div>
            <div class="detail-grid">
              <InfoItem label="ประเภทการชำระ" :value="paymentTypeLabel(detailBooking)" />
              <InfoItem label="ช่องทางชำระ" :value="paymentMethodLabel(detailBooking.payment_method)" />
              <InfoItem label="รหัสอ้างอิง" :value="detailBooking.payment_ref || '-'" />
              <InfoItem label="ชำระเมื่อ" :value="formatDateTime(detailBooking.paid_at)" />
              <InfoItem label="วันเวลาโอนเงิน" :value="formatDateTime(detailBooking.transfer_datetime)" />
              <InfoItem label="จำนวนงวด" :value="installmentSummary(detailBooking)" />
            </div>

            <div v-if="detailBooking.slip_url" class="slip-box">
              <a :href="detailBooking.slip_url" target="_blank">
                <img :src="detailBooking.slip_url" alt="สลิปโอนเงิน" />
              </a>
              <a :href="detailBooking.slip_url" target="_blank" class="btn-secondary compact">
                <span class="material-symbols-rounded">open_in_new</span>
                เปิดสลิป
              </a>
            </div>

            <div v-if="detailBooking.payment_type === 'installment' && detailBooking.installment_payments?.length" class="installment-list">
              <div
                v-for="payment in detailBooking.installment_payments"
                :key="payment.id"
                class="installment-row"
                :class="{ paid: payment.status === 'paid' }"
              >
                <span class="installment-no">{{ payment.installment_no }}</span>
                <div>
                  <strong>{{ formatMoney(payment.amount) }}</strong>
                  <span>ครบกำหนด {{ formatDate(payment.due_date) }}</span>
                </div>
                <div class="installment-meta">
                  <span>{{ payment.status === 'paid' ? 'ชำระแล้ว' : 'รอชำระ' }}</span>
                  <span v-if="payment.paid_at">{{ formatDateTime(payment.paid_at) }}</span>
                  <a v-if="payment.slip_url" :href="payment.slip_url" target="_blank">สลิป</a>
                </div>
              </div>
            </div>
          </section>

          <section class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">location_on</span>
              จุดรับและทีมงาน
            </div>
            <div class="pickup-card" v-if="pickupInfo(detailBooking)">
              <strong>{{ pickupInfo(detailBooking).regionLabel }}</strong>
              <span>{{ pickupInfo(detailBooking).location }}</span>
              <span v-if="pickupInfo(detailBooking).notes">{{ pickupInfo(detailBooking).notes }}</span>
              <a v-if="pickupInfo(detailBooking).mapUrl" :href="pickupInfo(detailBooking).mapUrl" target="_blank">
                เปิดแผนที่
              </a>
            </div>
            <div v-else class="empty-inline">ไม่มีข้อมูลจุดรับ</div>

            <div v-if="detailBooking.assigned_staff?.length" class="staff-list">
              <span v-for="staff in detailBooking.assigned_staff" :key="staff.id" class="staff-chip">
                <span class="material-symbols-rounded">badge</span>
                {{ staff.name }}
              </span>
            </div>
          </section>

          <section class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">group</span>
              ผู้โดยสาร ({{ passengerCount(detailBooking) }})
            </div>
            <div v-if="detailBooking.passengers?.length" class="passenger-list">
              <div v-for="(passenger, index) in detailBooking.passengers" :key="passenger.id || index" class="passenger-card">
                <div class="passenger-head">
                  <span class="passenger-index">{{ index + 1 }}</span>
                  <div>
                    <strong>{{ passenger.title }} {{ passenger.name }}</strong>
                    <span v-if="passenger.nickname">({{ passenger.nickname }})</span>
                  </div>
                  <span v-if="passenger.halal_food === true" class="food-badge halal">ฮาลาล</span>
                  <span v-else-if="passenger.halal_food === false" class="food-badge">ไม่ฮาลาล</span>
                </div>
                <div class="passenger-info-grid">
                  <InfoItem label="โทร" :value="passenger.phone || '-'" />
                  <InfoItem label="บัตรประชาชน" :value="passenger.id_card || '-'" />
                  <InfoItem label="กรุ๊ปเลือด" :value="passenger.blood_group || '-'" />
                  <InfoItem label="น้ำหนัก" :value="passenger.weight ? `${passenger.weight} กก.` : '-'" />
                  <InfoItem label="ผู้ติดต่อฉุกเฉิน" :value="passenger.emergency_contact || '-'" />
                  <InfoItem label="เบอร์ฉุกเฉิน" :value="passenger.emergency_phone || '-'" />
                  <InfoItem label="ระดับใบดำน้ำ" :value="certLabel(passenger)" />
                  <InfoItem label="การแพ้ / อาหาร" :value="passenger.allergies || '-'" wide />
                  <InfoItem label="หมายเหตุสุขภาพ" :value="passenger.health_notes || '-'" wide />
                </div>
              </div>
            </div>
            <div v-else class="empty-inline">ยังไม่มีข้อมูลผู้โดยสาร</div>
          </section>

          <section class="detail-section two-column">
            <div>
              <div class="section-heading">
                <span class="material-symbols-rounded">chair</span>
                ที่นั่ง
              </div>
              <div v-if="detailBooking.seats?.length" class="seat-list">
                <span v-for="seat in detailBooking.seats" :key="seat.id || seat.seat_id">{{ seat.seat_id }}</span>
              </div>
              <div v-else class="empty-inline">{{ detailBooking.is_join_trip ? 'จอยทริปไม่ระบุที่นั่ง' : 'ยังไม่มีที่นั่ง' }}</div>
            </div>

            <div v-if="detailBooking.qr_code">
              <div class="section-heading">
                <span class="material-symbols-rounded">qr_code_2</span>
                QR เช็คอิน
              </div>
              <div class="qr-box">
                <img :src="qrCodeUrl(detailBooking.qr_code)" alt="Booking QR Code" />
                <span>{{ detailBooking.qr_code }}</span>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>

    <div v-if="showStatusModal" class="modal-overlay" @click.self="showStatusModal = false">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <div>
            <h2>เปลี่ยนสถานะ</h2>
            <p class="modal-subtitle">{{ statusBooking?.booking_ref }}</p>
          </div>
          <button class="modal-close" @click="showStatusModal = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>สถานะใหม่</label>
            <select v-model="statusForm.status">
              <option value="pending">รอดำเนินการ</option>
              <option value="confirmed">ยืนยันแล้ว</option>
              <option value="cancelled">ยกเลิก</option>
              <option value="refunded">คืนเงินแล้ว</option>
            </select>
          </div>
          <div v-if="statusForm.status === 'cancelled' || statusForm.status === 'refunded'" class="form-group">
            <label>เหตุผล</label>
            <textarea v-model="statusForm.reason" rows="3" placeholder="ระบุเหตุผลที่เปลี่ยนสถานะ..."></textarea>
          </div>
          <p v-if="statusForm.status === 'cancelled' || statusForm.status === 'refunded'" class="confirm-warning">
            <span class="material-symbols-rounded">warning</span>
            การเปลี่ยนเป็นยกเลิกหรือคืนเงินจะปล่อยที่นั่งและอัปเดตจำนวนที่นั่งของรอบเดินทาง
          </p>
          <div class="modal-footer">
            <button class="btn-secondary" @click="showStatusModal = false">ยกเลิก</button>
            <button class="btn-primary" :disabled="submitting" @click="doUpdateStatus">
              <span v-if="submitting" class="material-symbols-rounded animate-spin">sync</span>
              <span v-else class="material-symbols-rounded">save</span>
              บันทึก
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showManualModal" class="modal-overlay" @click.self="showManualModal = false">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <div>
            <h2>เพิ่มการจองด้วยตนเอง</h2>
            <p class="modal-subtitle">ใช้สำหรับบันทึกลูกค้าที่จองผ่านช่องทางอื่น</p>
          </div>
          <button class="modal-close" @click="showManualModal = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <form class="modal-body" @submit.prevent="doCreateManualBooking">
          <div class="form-grid">
            <div class="form-group">
              <label>ทริป *</label>
              <select v-model="manualForm.trip_id" required @change="onTripChange">
                <option value="">เลือกทริป</option>
                <option v-for="trip in allTrips" :key="trip.id" :value="trip.id">{{ trip.title }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>รอบเดินทาง *</label>
              <select v-model="manualForm.schedule_id" :disabled="!manualForm.trip_id" required>
                <option value="">เลือกรอบเดินทาง</option>
                <option v-for="schedule in availableSchedules" :key="schedule.id" :value="schedule.id">
                  {{ formatDate(schedule.departure_date) }} · ว่าง {{ schedule.available_seats }} ที่
                </option>
              </select>
            </div>
            <div class="form-group">
              <label>ชื่อ *</label>
              <input v-model.trim="manualForm.name" type="text" required placeholder="ชื่อ" />
            </div>
            <div class="form-group">
              <label>นามสกุล *</label>
              <input v-model.trim="manualForm.surname" type="text" required placeholder="นามสกุล" />
            </div>
            <div class="form-group">
              <label>เบอร์โทรศัพท์ *</label>
              <input v-model.trim="manualForm.phone" type="tel" required placeholder="0XXXXXXXXX" />
            </div>
            <div class="form-group">
              <label>จำนวนคน *</label>
              <input v-model.number="manualForm.passenger_count" type="number" min="1" required />
            </div>
          </div>

          <div class="manual-status-options">
            <label :class="{ active: manualForm.status === 'pending' }">
              <input v-model="manualForm.status" type="radio" value="pending" />
              <span class="material-symbols-rounded">pending_actions</span>
              <strong>รอดำเนินการ</strong>
              <small>ยังไม่จ่ายเงิน</small>
            </label>
            <label :class="{ active: manualForm.status === 'confirmed' }">
              <input v-model="manualForm.status" type="radio" value="confirmed" />
              <span class="material-symbols-rounded">task_alt</span>
              <strong>ยืนยันแล้ว</strong>
              <small>จ่ายเงินครบแล้ว</small>
            </label>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showManualModal = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span v-if="submitting" class="material-symbols-rounded animate-spin">sync</span>
              <span v-else class="material-symbols-rounded">check_circle</span>
              สร้างการจอง
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue';
import { useAdminStore } from '../../stores/admin';
import api from '../../lib/axios';

const admin = useAdminStore();

const filters = reactive({
  search: '',
  status: '',
  date: '',
  booking_type: '',
  payment_type: '',
});

const showDetail = ref(false);
const showStatusModal = ref(false);
const showManualModal = ref(false);
const detailBooking = ref(null);
const statusBooking = ref(null);
const submitting = ref(false);
const loadingDetail = ref(false);
const currentPage = ref(1);
const statusForm = reactive({ status: '', reason: '' });

const allTrips = ref([]);
const availableSchedules = ref([]);
const manualForm = reactive({
  trip_id: '',
  schedule_id: '',
  name: '',
  surname: '',
  phone: '',
  passenger_count: 1,
  status: 'pending',
});

const bookings = computed(() => Array.isArray(admin.bookings.data) ? admin.bookings.data : []);
const hasActiveFilters = computed(() => Boolean(filters.search || filters.status || filters.date || filters.booking_type || filters.payment_type));

const pageStats = computed(() => bookings.value.reduce((stats, booking) => {
  stats.bookings += 1;
  stats.passengers += passengerCount(booking);
  stats.totalAmount += moneyNumber(booking.total_amount);
  stats.paidAmount += moneyNumber(booking.paid_amount);
  stats.pending += booking.status === 'pending' ? 1 : 0;
  stats.joinTrip += booking.is_join_trip ? 1 : 0;
  return stats;
}, {
  bookings: 0,
  passengers: 0,
  totalAmount: 0,
  paidAmount: 0,
  pending: 0,
  joinTrip: 0,
}));

const statusLabels = {
  pending: 'รอดำเนินการ',
  confirmed: 'ยืนยันแล้ว',
  cancelled: 'ยกเลิก',
  refunded: 'คืนเงินแล้ว',
};

let debounceTimer = null;

function debouncedFetch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(), 300);
}

async function fetchData(page = 1) {
  currentPage.value = page;
  await admin.fetchBookings({
    ...filters,
    page,
  });
}

function goPage(page) {
  fetchData(page);
}

function resetFilters() {
  filters.search = '';
  filters.status = '';
  filters.date = '';
  filters.booking_type = '';
  filters.payment_type = '';
  fetchData();
}

async function openDetail(booking) {
  detailBooking.value = booking;
  showDetail.value = true;
  loadingDetail.value = true;

  try {
    const res = await api.get(`/admin/bookings/${booking.booking_ref}`);
    detailBooking.value = res.data?.data || booking;
  } catch (e) {
    alert(e.response?.data?.message || 'ไม่สามารถโหลดรายละเอียดการจองได้');
  } finally {
    loadingDetail.value = false;
  }
}

function closeDetail() {
  showDetail.value = false;
  detailBooking.value = null;
}

function openStatusModal(booking) {
  statusBooking.value = booking;
  statusForm.status = booking.status;
  statusForm.reason = '';
  showStatusModal.value = true;
}

async function doUpdateStatus() {
  if (!statusBooking.value) return;

  submitting.value = true;
  try {
    await admin.updateBookingStatus(statusBooking.value.booking_ref, statusForm.status, statusForm.reason);
    showStatusModal.value = false;
    if (detailBooking.value?.booking_ref === statusBooking.value.booking_ref) {
      await openDetail(statusBooking.value);
    }
    await fetchData(currentPage.value);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
}

async function confirmDelete(booking) {
  const ok = confirm(`คุณแน่ใจหรือไม่ว่าต้องการลบการจองเลขที่ ${booking.booking_ref}?\nการดำเนินการนี้จะลบข้อมูลผู้โดยสาร ที่นั่ง และสลิปที่เกี่ยวข้อง`);
  if (!ok) return;

  submitting.value = true;
  try {
    await admin.deleteBooking(booking.booking_ref);
    await fetchData(currentPage.value);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาดในการลบการจอง');
  } finally {
    submitting.value = false;
  }
}

async function openManualBookingModal() {
  resetManualForm();
  showManualModal.value = true;

  if (allTrips.value.length) return;

  try {
    const res = await api.get('/admin/trips', { params: { per_page: 100, status: 'active' } });
    allTrips.value = res.data?.data || [];
  } catch (e) {
    console.error('Failed to fetch trips', e);
  }
}

async function onTripChange() {
  manualForm.schedule_id = '';
  availableSchedules.value = [];
  if (!manualForm.trip_id) return;

  try {
    const res = await api.get('/admin/schedules', {
      params: { trip_id: manualForm.trip_id, upcoming: 1, per_page: 100 },
    });
    availableSchedules.value = res.data?.data || [];
  } catch (e) {
    console.error('Failed to fetch schedules', e);
  }
}

async function doCreateManualBooking() {
  submitting.value = true;
  try {
    const res = await admin.createManualBooking(manualForm);
    showManualModal.value = false;
    await fetchData();
    const newBooking = res.data?.data || res.data;
    if (newBooking?.booking_ref) {
      await openDetail(newBooking);
    }
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาดในการสร้างการจอง');
  } finally {
    submitting.value = false;
  }
}

function resetManualForm() {
  manualForm.trip_id = '';
  manualForm.schedule_id = '';
  manualForm.name = '';
  manualForm.surname = '';
  manualForm.phone = '';
  manualForm.passenger_count = 1;
  manualForm.status = 'pending';
  availableSchedules.value = [];
}

function passengerCount(booking) {
  return Array.isArray(booking?.passengers) ? booking.passengers.length : 0;
}

function seatLabels(booking) {
  const labels = (booking?.seats || []).map((seat) => seat.seat_id).filter(Boolean);
  return labels.join(', ');
}

function vehicleName(booking) {
  const vehicle = booking?.schedule?.vehicle;
  if (typeof vehicle === 'string') return vehicle;
  return vehicle?.name || booking?.schedule?.transport_type || '-';
}

function formatScheduleRange(schedule) {
  if (!schedule) return '-';
  const start = formatDate(schedule.departure_date);
  const end = schedule.return_date && schedule.return_date !== schedule.departure_date ? ` - ${formatDate(schedule.return_date)}` : '';
  return `${start}${end}`;
}

function pickupInfo(booking) {
  if (booking?.pickup_point) {
    return {
      regionLabel: booking.pickup_point.region_label || booking.pickup_point.region || '-',
      location: booking.pickup_point.pickup_location || '-',
      notes: booking.pickup_point.notes || '',
      mapUrl: booking.pickup_point.map_url || '',
    };
  }

  const pickupRegion = booking?.pickup_region;
  const point = (booking?.schedule?.pickup_points || []).find((item) => item.region === pickupRegion);
  if (!point && !pickupRegion) return null;

  return {
    regionLabel: point?.region_label || pickupRegion,
    location: point?.pickup_location || pickupRegion,
    notes: point?.notes || '',
    mapUrl: point?.map_url || '',
  };
}

function paymentProgress(booking) {
  const total = moneyNumber(booking?.total_amount);
  if (!total) return 0;
  return Math.min(100, Math.round((moneyNumber(booking?.paid_amount) / total) * 100));
}

function paymentBalance(booking) {
  return Math.max(0, moneyNumber(booking?.total_amount) - moneyNumber(booking?.paid_amount));
}

function paidInstallmentCount(booking) {
  return (booking?.installment_payments || []).filter((payment) => payment.status === 'paid').length;
}

function installmentSummary(booking) {
  if (booking?.payment_type !== 'installment') return '-';
  return `${paidInstallmentCount(booking)} / ${booking.installment_count || booking.installment_payments?.length || 0} งวด`;
}

function paymentTypeLabel(booking) {
  if (booking?.payment_type === 'installment') {
    return `ผ่อนชำระ ${installmentSummary(booking)}`;
  }

  return 'ชำระเต็มจำนวน';
}

function paymentMethodLabel(method) {
  const labels = {
    promptpay: 'พร้อมเพย์',
    mobile_banking: 'Mobile Banking',
    credit_card: 'บัตรเครดิต',
    manual: 'แอดมินสร้างให้',
  };
  return labels[method] || method || '-';
}

function certLabel(passenger) {
  if (!passenger?.dive_cert_level && !passenger?.cert_number) return '-';
  return [passenger.dive_cert_level, passenger.cert_number].filter(Boolean).join(' / ');
}

function moneyNumber(amount) {
  const value = Number(amount);
  return Number.isFinite(value) ? value : 0;
}

function formatMoney(amount) {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    maximumFractionDigits: 0,
  }).format(moneyNumber(amount));
}

function formatDate(date) {
  if (!date) return '-';
  const parsed = new Date(date);
  if (Number.isNaN(parsed.getTime())) return '-';
  return parsed.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatDateTime(date) {
  if (!date) return '-';
  const parsed = new Date(date);
  if (Number.isNaN(parsed.getTime())) return '-';
  return parsed.toLocaleString('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function qrCodeUrl(value) {
  return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(value)}`;
}

const InfoItem = (props) => h('div', {
  class: ['info-item', props.wide ? 'wide' : ''],
}, [
  h('span', { class: 'info-label' }, props.label),
  h('span', { class: 'info-value' }, props.value || '-'),
]);
InfoItem.props = {
  label: String,
  value: [String, Number],
  wide: Boolean,
};

onMounted(() => fetchData());
</script>

<style scoped>
@import url('./admin-shared.css');

.header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
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
  flex-shrink: 0;
  background: #f3f4f6;
  color: #4b5563;
}

.summary-icon.people { background: #eff6ff; color: #2563eb; }
.summary-icon.money { background: #f0fdf4; color: #15803d; }
.summary-icon.paid { background: #ecfdf5; color: #059669; }
.summary-icon.pending { background: #fffbeb; color: #d97706; }
.summary-icon.join { background: #ecfdf5; color: #047857; }

.summary-label {
  display: block;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.summary-value {
  display: block;
  color: var(--color-text-dark);
  font-size: 20px;
  line-height: 1.2;
  white-space: nowrap;
}

.summary-value.money {
  color: var(--color-accent);
  font-size: 17px;
}

.filters-panel {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 20px;
}

.booking-filters {
  margin-bottom: 8px;
}

.booking-filters .search-box {
  min-width: 280px;
}

.booking-filters input[type="date"] {
  min-width: 150px;
  padding: 9px 14px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  color: #111827;
  font-size: 14px;
}

.compact {
  padding-inline: 12px;
  white-space: nowrap;
}

.filter-footnote {
  color: var(--color-text-muted);
  font-size: 12px;
}

.booking-table {
  min-width: 1120px;
}

.booking-table th,
.booking-table td {
  vertical-align: top;
}

.booking-ref-cell,
.customer-cell,
.trip-cell-text,
.passenger-cell,
.payment-cell {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.booking-ref-link {
  color: var(--color-accent);
  background: transparent;
  border: none;
  padding: 0;
  font-weight: 800;
  cursor: pointer;
  text-align: left;
}

.booking-ref-link:hover {
  text-decoration: underline;
}

.table-subtext,
.customer-cell span,
.trip-cell-text span,
.passenger-cell span,
.payment-cell span {
  color: var(--color-text-muted);
  font-size: 12px;
}

.customer-cell strong,
.trip-cell-text strong,
.passenger-cell strong,
.payment-cell strong {
  color: var(--color-text-dark);
  font-size: 13px;
}

.mini-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 2px;
}

.mini-badge,
.type-badge {
  display: inline-flex;
  align-items: center;
  width: fit-content;
  border-radius: 999px;
  padding: 2px 7px;
  font-size: 10px;
  font-weight: 800;
  background: #f3f4f6;
  color: #4b5563;
}

.mini-badge.join,
.type-badge.join {
  background: #ecfdf5;
  color: #059669;
  border: 1px solid #a7f3d0;
}

.mini-badge.group {
  background: #eff6ff;
  color: #2563eb;
  border: 1px solid #bfdbfe;
}

.payment-progress {
  height: 5px;
  width: 95px;
  background: var(--color-sand-dark);
  border-radius: 999px;
  overflow: hidden;
}

.payment-progress div {
  height: 100%;
  background: var(--color-accent);
}

.payment-type {
  width: fit-content;
  border-radius: 999px;
  padding: 2px 7px;
  font-weight: 800;
}

.payment-type.full {
  background: #ecfdf5;
  color: #059669;
}

.payment-type.installment {
  background: #eff6ff;
  color: #2563eb;
}

.checkin-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.checkin-badge .material-symbols-rounded {
  font-size: 16px;
}

.checkin-badge.checked {
  color: #059669;
}

.btn-view {
  color: #2563eb;
}

.btn-view:hover {
  background: #eff6ff;
  border-color: #bfdbfe;
}

.empty-icon {
  display: block;
  color: #cbd5e1;
  font-size: 42px;
  margin-bottom: 8px;
}

.modal-xl {
  max-width: 1040px;
}

.modal-lg {
  max-width: 780px;
}

.modal-subtitle {
  color: var(--color-text-muted);
  font-size: 13px;
  margin: 4px 0 0;
}

.detail-body {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.detail-hero {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  border: 1px solid var(--color-sand-dark);
  background: var(--color-sand);
  border-radius: 8px;
  padding: 16px;
}

.detail-ref {
  display: inline-block;
  color: var(--color-accent);
  font-size: 12px;
  font-weight: 900;
  margin-bottom: 4px;
}

.detail-hero-title {
  color: var(--color-text-dark);
  font-size: 20px;
  font-weight: 900;
}

.detail-hero-subtitle {
  color: var(--color-text-muted);
  font-size: 13px;
  font-weight: 700;
}

.detail-hero-badges {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  align-content: flex-start;
  justify-content: flex-end;
}

.detail-summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.detail-stat {
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
}

.detail-stat span {
  display: block;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 800;
}

.detail-stat strong {
  display: block;
  color: var(--color-text-dark);
  font-size: 16px;
  margin-top: 2px;
}

.text-green {
  color: #059669 !important;
}

.text-warn {
  color: #d97706 !important;
}

.detail-section {
  border-top: 1px solid #eeeeee;
  padding-top: 16px;
}

.section-heading {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 900;
  margin-bottom: 12px;
}

.section-heading .material-symbols-rounded {
  color: var(--color-accent);
  font-size: 20px;
}

.detail-grid,
.passenger-info-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

:deep(.info-item) {
  display: flex;
  flex-direction: column;
  gap: 3px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px;
  min-width: 0;
}

:deep(.info-item.wide) {
  grid-column: 1 / -1;
}

:deep(.info-label) {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

:deep(.info-value) {
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.slip-box {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  margin-top: 14px;
  flex-wrap: wrap;
}

.slip-box img {
  max-height: 220px;
  max-width: 220px;
  object-fit: contain;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: white;
}

.installment-list {
  display: grid;
  gap: 8px;
  margin-top: 14px;
}

.installment-row {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 10px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px;
  background: var(--color-white);
}

.installment-row.paid {
  border-color: #a7f3d0;
  background: #ecfdf5;
}

.installment-no {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--color-sand-dark);
  color: var(--color-text-mid);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 900;
}

.installment-row.paid .installment-no {
  color: white;
  background: #059669;
}

.installment-row strong,
.installment-row span,
.installment-row a {
  display: block;
  font-size: 12px;
}

.installment-row a {
  color: #2563eb;
  font-weight: 800;
}

.installment-meta {
  text-align: right;
  color: var(--color-text-muted);
}

.pickup-card {
  display: flex;
  flex-direction: column;
  gap: 3px;
  border: 1px solid #bfdbfe;
  background: #eff6ff;
  border-radius: 8px;
  padding: 12px;
  font-size: 13px;
}

.pickup-card strong {
  color: #1d4ed8;
}

.pickup-card a {
  color: #2563eb;
  font-weight: 800;
  width: fit-content;
}

.staff-list {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 10px;
}

.staff-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 999px;
  padding: 4px 10px;
  color: var(--color-text-mid);
  font-size: 12px;
  font-weight: 800;
}

.staff-chip .material-symbols-rounded {
  font-size: 15px;
  color: var(--color-accent);
}

.passenger-list {
  display: grid;
  gap: 12px;
}

.passenger-card {
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
  background: var(--color-white);
}

.passenger-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  padding-bottom: 10px;
  border-bottom: 1px solid #eeeeee;
  flex-wrap: wrap;
}

.passenger-index {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--color-accent);
  color: white;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 900;
}

.passenger-head strong {
  color: var(--color-text-dark);
}

.passenger-head span {
  color: var(--color-text-muted);
  font-size: 12px;
}

.food-badge {
  margin-left: auto;
  border-radius: 999px;
  padding: 3px 8px;
  font-size: 11px;
  font-weight: 800;
  color: #4b5563;
  background: #f3f4f6;
}

.food-badge.halal {
  color: #059669;
  background: #ecfdf5;
}

.two-column {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}

.seat-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.seat-list span {
  border: 1px solid #b7dfc5;
  border-radius: 8px;
  background: #e8f5ec;
  color: var(--color-accent);
  padding: 7px 12px;
  font-size: 13px;
  font-weight: 900;
}

.qr-box {
  width: fit-content;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
  text-align: center;
}

.qr-box img {
  width: 150px;
  height: 150px;
}

.qr-box span {
  display: block;
  margin-top: 6px;
  color: var(--color-text-muted);
  font-size: 10px;
  font-family: monospace;
}

.empty-inline {
  color: var(--color-text-muted);
  font-size: 13px;
  border: 1px dashed var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
}

.manual-status-options {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-top: 18px;
}

.manual-status-options label {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 10px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
  cursor: pointer;
}

.manual-status-options label.active {
  border-color: var(--color-accent);
  background: #e8f5ec;
}

.manual-status-options input {
  display: none;
}

.manual-status-options .material-symbols-rounded {
  color: var(--color-accent);
  grid-row: span 2;
}

.manual-status-options strong {
  color: var(--color-text-dark);
  font-size: 13px;
}

.manual-status-options small {
  color: var(--color-text-muted);
  font-size: 12px;
}

@media (max-width: 1200px) {
  .summary-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .summary-grid,
  .detail-summary-grid,
  .detail-grid,
  .passenger-info-grid,
  .two-column,
  .manual-status-options {
    grid-template-columns: 1fr;
  }

  .detail-hero {
    flex-direction: column;
  }

  .detail-hero-badges {
    justify-content: flex-start;
  }

  .installment-row {
    grid-template-columns: auto 1fr;
  }

  .installment-meta {
    grid-column: 1 / -1;
    text-align: left;
  }
}
</style>
