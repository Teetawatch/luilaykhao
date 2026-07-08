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
          <option value="deposit">มัดจำ</option>
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

      <div v-else-if="groupedBookings.length" class="booking-groups">
        <section v-for="group in groupedBookings" :key="group.key" class="trip-booking-group">
          <div class="trip-group-header">
            <div class="trip-group-title-block">
              <span class="trip-group-icon material-symbols-rounded">map</span>
              <div>
                <h2>{{ group.tripTitle }}</h2>
                <p>{{ group.scheduleRanges.join(' • ') }}</p>
              </div>
            </div>
            <div class="trip-group-stats">
              <div>
                <span>รายการ</span>
                <strong>{{ group.bookings.length }}</strong>
              </div>
              <div>
                <span>ผู้โดยสาร</span>
                <strong>{{ group.passengers }}</strong>
              </div>
              <div>
                <span>ยอดจอง</span>
                <strong>{{ formatMoney(group.totalAmount) }}</strong>
              </div>
              <div>
                <span>ชำระแล้ว</span>
                <strong class="text-green">{{ formatMoney(group.paidAmount) }}</strong>
              </div>
            </div>
          </div>

          <div class="trip-booking-list">
            <article v-for="booking in group.bookings" :key="booking.id" class="booking-detail-card">
              <div class="booking-card-head">
                <div class="booking-ref-cell">
                  <button class="booking-ref-link" @click="openDetail(booking)">
                    {{ booking.booking_ref }}
                  </button>
                  <span class="table-subtext">จองเมื่อ {{ formatDateTime(booking.created_at) }}</span>
                  <div class="mini-badges">
                    <span class="mini-badge">{{ booking.is_join_trip ? 'จอยทริป' : 'จองปกติ' }}</span>
                    <span v-if="booking.is_group" class="mini-badge group">กรุ๊ป</span>
                    <span v-if="booking.payment_type === 'installment'" class="mini-badge installment">ผ่อนชำระ</span>
                    <span v-else-if="booking.payment_type === 'deposit'" class="mini-badge deposit">มัดจำ</span>
                  </div>
                </div>
                <div class="booking-card-status">
                  <span class="status-badge" :class="`status-${booking.status}`">
                    {{ statusLabels[booking.status] || booking.status || '-' }}
                  </span>
                  <span v-if="booking.checked_in" class="checkin-badge checked">
                    <span class="material-symbols-rounded">task_alt</span>
                    {{ formatDate(booking.checked_in_at) }}
                  </span>
                  <span v-else class="checkin-badge">
                    <span class="material-symbols-rounded">qr_code_scanner</span>
                    ยังไม่เช็คอิน
                  </span>
                </div>
              </div>

              <div class="booking-info-grid">
                <div class="booking-info-panel">
                  <div class="booking-panel-title">
                    <span class="material-symbols-rounded">person</span>
                    ผู้จอง
                  </div>
                  <div class="info-lines">
                    <strong>{{ booking.user?.name || '-' }}</strong>
                    <span>{{ booking.user?.phone || booking.passengers?.[0]?.phone || '-' }}</span>
                    <span>{{ booking.user?.email || '-' }}</span>
                    <span v-if="booking.is_group">กลุ่ม: {{ booking.group_name || '-' }}</span>
                    <span v-if="booking.group_notes">หมายเหตุกรุ๊ป: {{ booking.group_notes }}</span>
                  </div>
                </div>

                <div class="booking-info-panel">
                  <div class="booking-panel-title">
                    <span class="material-symbols-rounded">event</span>
                    รอบเดินทาง
                  </div>
                  <div class="info-lines">
                    <strong>{{ formatScheduleRange(booking.schedule) }}</strong>
                    <span>{{ vehicleName(booking) }}</span>
                    <span v-if="booking.schedule?.transport_type">ประเภทเดินทาง: {{ booking.schedule.transport_type }}</span>
                    <span v-if="pickupInfo(booking)">จุดรับ: {{ pickupInfo(booking).regionLabel }} • {{ pickupInfo(booking).location }}</span>
                    <span v-if="booking.custom_pickup" class="cp-list-flag">
                      <span class="material-symbols-rounded">add_location_alt</span>
                      จุดรับปักหมุดเอง
                    </span>
                  </div>
                </div>

                <div class="booking-info-panel">
                  <div class="booking-panel-title">
                    <span class="material-symbols-rounded">groups</span>
                    ผู้โดยสาร / ที่นั่ง
                  </div>
                  <div class="info-lines">
                    <strong>{{ passengerCount(booking) }} คน</strong>
                    <span v-if="seatLabels(booking)">ที่นั่ง {{ seatLabels(booking) }}</span>
                    <span v-else-if="booking.is_join_trip">ไม่ระบุที่นั่ง</span>
                    <span v-else>ยังไม่มีที่นั่ง</span>
                    <span v-if="passengerNames(booking)">{{ passengerNames(booking) }}</span>
                  </div>
                </div>

                <div class="booking-info-panel payment-panel">
                  <div class="booking-panel-title">
                    <span class="material-symbols-rounded">payments</span>
                    การชำระเงิน
                  </div>
                  <div class="payment-cell">
                    <strong>{{ formatMoney(booking.total_amount) }}</strong>
                    <span>จ่ายแล้ว {{ formatMoney(booking.paid_amount) }}</span>
                    <span>คงเหลือ {{ formatMoney(paymentBalance(booking)) }}</span>
                    <div class="payment-progress">
                      <div :style="{ width: paymentProgress(booking) + '%' }"></div>
                    </div>
                    <span class="payment-type" :class="booking.payment_type || 'full'">
                      {{ paymentTypeLabel(booking) }}
                    </span>
                    <span v-if="booking.payment_type === 'deposit' && !booking.balance_paid_at && booking.balance_due_at">
                      ครบกำหนดส่วนที่เหลือ {{ formatDate(booking.balance_due_at) }}
                    </span>
                    <span v-if="booking.payment_method">ช่องทาง: {{ paymentMethodLabel(booking.payment_method) }}</span>
                  </div>
                </div>
              </div>

              <div class="booking-card-foot">
                <div class="booking-extra-list">
                  <span v-if="booking.qr_code">QR: {{ booking.qr_code }}</span>
                  <span v-if="booking.cancelled_at">ยกเลิกเมื่อ {{ formatDateTime(booking.cancelled_at) }}</span>
                  <span v-if="booking.cancellation_reason">เหตุผลยกเลิก: {{ booking.cancellation_reason }}</span>
                  <span v-if="booking.installment_payments?.length">งวดชำระ {{ paidInstallmentCount(booking) }} / {{ booking.installment_payments.length }}</span>
                </div>
                <div class="action-btns">
                  <button class="btn-icon btn-view" title="รายละเอียด" @click="openDetail(booking)">
                    <span class="material-symbols-rounded">visibility</span>
                  </button>
                  <button class="btn-icon btn-edit" title="แก้ไขข้อมูล" @click="openEditModal(booking)">
                    <span class="material-symbols-rounded">edit_note</span>
                  </button>
                  <button class="btn-icon btn-edit" title="เปลี่ยนสถานะ" @click="openStatusModal(booking)">
                    <span class="material-symbols-rounded">swap_horiz</span>
                  </button>
                  <button
                    class="btn-icon btn-transfer"
                    title="ย้ายเจ้าของการจอง"
                    :disabled="['cancelled','refunded'].includes(booking.status)"
                    @click="openTransferModal(booking)"
                  >
                    <span class="material-symbols-rounded">move_item</span>
                  </button>
                  <button class="btn-icon btn-delete" title="ลบการจอง" @click="confirmDelete(booking)">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </div>
            </article>
          </div>
        </section>
      </div>

      <div v-else class="empty-state grouped-empty">
        <span class="material-symbols-rounded empty-icon">inbox</span>
        ไม่พบข้อมูลการจอง
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
          <div class="modal-header-actions">
            <button v-if="detailBooking" class="btn-secondary compact" @click="openEditModal(detailBooking)">
              <span class="material-symbols-rounded">edit_note</span>
              แก้ไข
            </button>
            <button
              v-if="detailBooking && !['cancelled','refunded'].includes(detailBooking.status)"
              class="btn-secondary compact"
              @click="openTransferModal(detailBooking)"
            >
              <span class="material-symbols-rounded">move_item</span>
              ย้ายเจ้าของ
            </button>
            <button class="modal-close" @click="closeDetail">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
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

          <!-- จุดรับที่ลูกค้าปักหมุดเอง (ข้อมูลสำหรับจัดเส้นทางรับ) -->
          <section v-if="detailBooking.custom_pickup" class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">add_location_alt</span>
              จุดรับที่ลูกค้าปักหมุดเอง
            </div>
            <div class="detail-grid">
              <InfoItem label="ชื่อจุดรับ" :value="detailBooking.custom_pickup.label" />
              <InfoItem label="พิกัด" :value="`${detailBooking.custom_pickup.lat}, ${detailBooking.custom_pickup.lng}`" />
              <InfoItem v-if="detailBooking.custom_pickup.note" label="รายละเอียด" :value="detailBooking.custom_pickup.note" wide />
            </div>

            <a :href="`https://www.google.com/maps/search/?api=1&query=${detailBooking.custom_pickup.lat},${detailBooking.custom_pickup.lng}`"
              target="_blank" class="cp-map-link">
              <span class="material-symbols-rounded">map</span> เปิดดูบนแผนที่
            </a>
          </section>

          <section v-if="detailBooking.selected_addons?.length" class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">add_shopping_cart</span>
              รายการเสริมที่ลูกค้าเลือก ({{ detailBooking.selected_addons.length }})
            </div>
            <div class="addon-list">
              <div v-for="(addon, idx) in detailBooking.selected_addons" :key="idx" class="addon-row">
                <span class="addon-icon">
                  <span class="material-symbols-rounded">check_circle</span>
                </span>
                <div class="addon-info">
                  <strong>{{ addon.name }}</strong>
                  <span class="addon-meta">
                    {{ formatMoney(addon.unit_price) }}
                    {{ addon.price_type === 'per_person' ? '/ คน' : '/ การจอง' }}
                    <span v-if="addon.quantity > 1"> × {{ addon.quantity }}</span>
                  </span>
                </div>
                <strong class="addon-total">{{ formatMoney(addon.total_price) }}</strong>
              </div>
              <div class="addon-summary">
                <span>รวมรายการเสริม</span>
                <strong>{{ formatMoney(detailBooking.addons_total) }}</strong>
              </div>
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
              <InfoItem v-if="detailBooking.payment_type === 'installment'" label="จำนวนงวด" :value="installmentSummary(detailBooking)" />
              <template v-if="detailBooking.payment_type === 'deposit'">
                <InfoItem label="ยอดมัดจำ" :value="formatMoney(detailBooking.deposit_amount)" />
                <InfoItem label="ยอดส่วนที่เหลือ" :value="formatMoney(detailBooking.balance_amount)" />
                <InfoItem label="กำหนดชำระส่วนที่เหลือ" :value="formatDate(detailBooking.balance_due_at)" />
                <InfoItem label="รหัสอ้างอิง (ส่วนที่เหลือ)" :value="detailBooking.balance_payment_ref || '-'" />
                <InfoItem label="ชำระส่วนที่เหลือเมื่อ" :value="formatDateTime(detailBooking.balance_paid_at)" />
                <InfoItem label="วันเวลาโอน (ส่วนที่เหลือ)" :value="formatDateTime(detailBooking.balance_transfer_datetime)" />
              </template>
            </div>

            <div v-if="detailBooking.slip_url" class="slip-box">
              <a :href="detailBooking.slip_url" target="_blank">
                <img :src="detailBooking.slip_url" alt="สลิปโอนเงิน" />
              </a>
              <div class="slip-actions">
                <a :href="detailBooking.slip_url" target="_blank" class="btn-secondary compact">
                  <span class="material-symbols-rounded">open_in_new</span>
                  เปิดสลิป
                </a>
                <span class="ocr-badge" :class="`ocr-${detailBooking.slip_ocr_status || 'none'}`">
                  {{ ocrLabel(detailBooking.slip_ocr_status) }}
                </span>
                <button
                  v-if="['failed','pending',null,undefined].includes(detailBooking.slip_ocr_status)"
                  class="btn-success compact"
                  @click="approveSlip(detailBooking.booking_ref, 'main')"
                >อนุมัติ</button>
                <button
                  v-if="['failed','pending'].includes(detailBooking.slip_ocr_status)"
                  class="btn-danger compact"
                  @click="rejectSlip(detailBooking.booking_ref, 'main')"
                >ปฏิเสธ</button>
                <button
                  v-if="['failed','rejected'].includes(detailBooking.slip_ocr_status)"
                  class="btn-secondary compact"
                  @click="reverifySlip(detailBooking.booking_ref, 'main')"
                >ตรวจสอบใหม่</button>
              </div>
            </div>

            <div v-if="detailBooking.balance_slip_url" class="slip-box">
              <p class="slip-label">สลิปยอดส่วนที่เหลือ</p>
              <a :href="detailBooking.balance_slip_url" target="_blank">
                <img :src="detailBooking.balance_slip_url" alt="สลิปยอดส่วนที่เหลือ" />
              </a>
              <div class="slip-actions">
                <a :href="detailBooking.balance_slip_url" target="_blank" class="btn-secondary compact">
                  <span class="material-symbols-rounded">open_in_new</span>
                  เปิดสลิป
                </a>
                <span class="ocr-badge" :class="`ocr-${detailBooking.balance_slip_ocr_status || 'none'}`">
                  {{ ocrLabel(detailBooking.balance_slip_ocr_status) }}
                </span>
                <button
                  v-if="['failed','pending',null,undefined].includes(detailBooking.balance_slip_ocr_status)"
                  class="btn-success compact"
                  @click="approveSlip(detailBooking.booking_ref, 'balance')"
                >อนุมัติ</button>
                <button
                  v-if="['failed','pending'].includes(detailBooking.balance_slip_ocr_status)"
                  class="btn-danger compact"
                  @click="rejectSlip(detailBooking.booking_ref, 'balance')"
                >ปฏิเสธ</button>
              </div>
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
                  <span v-if="payment.slip_url" class="ocr-badge" :class="`ocr-${payment.slip_ocr_status || 'none'}`">
                    {{ ocrLabel(payment.slip_ocr_status) }}
                  </span>
                  <button
                    v-if="payment.slip_url && ['failed','pending',null,undefined].includes(payment.slip_ocr_status)"
                    class="btn-success compact xs"
                    @click="approveSlip(detailBooking.booking_ref, 'installment', payment.installment_no)"
                  >อนุมัติ</button>
                  <button
                    v-if="payment.slip_url && ['failed','pending'].includes(payment.slip_ocr_status)"
                    class="btn-danger compact xs"
                    @click="rejectSlip(detailBooking.booking_ref, 'installment', payment.installment_no)"
                  >ปฏิเสธ</button>
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
                  <InfoItem label="อีเมล" :value="passenger.email || '-'" />
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

    <div v-if="showEditModal" class="modal-overlay" @click.self="closeEditModal">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2>แก้ไขข้อมูลการจอง</h2>
            <p class="modal-subtitle">{{ editBooking?.booking_ref || '-' }}</p>
          </div>
          <button class="modal-close" @click="closeEditModal">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <form class="modal-body edit-booking-form" @submit.prevent="doUpdateBooking">
          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">event_note</span>
              ข้อมูลการจอง
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>สถานะ</label>
                <select v-model="editForm.status">
                  <option value="pending">รอดำเนินการ</option>
                  <option value="confirmed">ยืนยันแล้ว</option>
                  <option value="cancelled">ยกเลิก</option>
                  <option value="refunded">คืนเงินแล้ว</option>
                </select>
              </div>
              <div class="form-group">
                <label>รอบเดินทาง</label>
                <select v-model.number="editForm.schedule_id" @change="onEditScheduleChange">
                  <option v-for="schedule in editScheduleOptions" :key="schedule.id" :value="schedule.id">
                    {{ formatScheduleRange(schedule) }}
                    <template v-if="schedule.available_seats != null"> · ว่าง {{ schedule.available_seats }} ที่</template>
                  </option>
                </select>
                <small v-if="editSchedulesLoading" class="field-hint">กำลังโหลดรอบเดินทาง...</small>
              </div>
              <div class="form-group">
                <label>ประเภทการจอง</label>
                <select v-model="editForm.is_join_trip">
                  <option :value="false">จองปกติ</option>
                  <option :value="true">จอยทริป</option>
                </select>
              </div>
              <div class="form-group">
                <label>QR เช็คอิน</label>
                <input v-model.trim="editForm.qr_code" type="text" />
              </div>
              <label class="check-row">
                <input v-model="editForm.is_group" type="checkbox" />
                <span>การจองแบบกลุ่ม</span>
              </label>
              <label class="check-row">
                <input v-model="editForm.checked_in" type="checkbox" />
                <span>เช็คอินแล้ว</span>
              </label>
              <div class="form-group">
                <label>ชื่อกลุ่ม</label>
                <input v-model.trim="editForm.group_name" type="text" />
              </div>
              <div class="form-group">
                <label>เวลาเช็คอิน</label>
                <input v-model="editForm.checked_in_at" type="datetime-local" />
              </div>
              <div class="form-group full-span">
                <label>หมายเหตุกลุ่ม</label>
                <textarea v-model="editForm.group_notes" rows="2"></textarea>
              </div>
              <div class="form-group full-span" v-if="editForm.status === 'cancelled' || editForm.status === 'refunded'">
                <label>เหตุผลยกเลิก/คืนเงิน</label>
                <textarea v-model="editForm.cancellation_reason" rows="2"></textarea>
              </div>
            </div>
          </section>

          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">person</span>
              ผู้จอง
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>ชื่อผู้จอง</label>
                <input v-model.trim="editForm.user.name" type="text" />
              </div>
              <div class="form-group">
                <label>อีเมล</label>
                <input v-model.trim="editForm.user.email" type="email" />
              </div>
              <div class="form-group">
                <label>เบอร์โทร</label>
                <input v-model.trim="editForm.user.phone" type="tel" />
              </div>
            </div>
          </section>

          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">payments</span>
              การชำระเงินและสลิปหลัก
            </div>

            <div class="pay-type-tabs">
              <label v-for="opt in paymentTypeOptions" :key="opt.value" class="pay-type-tab" :class="{ active: editForm.payment_type === opt.value }">
                <input type="radio" v-model="editForm.payment_type" :value="opt.value" />
                <span class="material-symbols-rounded">{{ opt.icon }}</span>
                {{ opt.label }}
              </label>
            </div>

            <div class="form-grid">
              <div class="form-group">
                <label>ยอดรวม</label>
                <input v-model.number="editForm.total_amount" type="number" min="0" step="0.01" />
              </div>
              <div class="form-group">
                <label>ชำระแล้ว</label>
                <input v-model.number="editForm.paid_amount" type="number" min="0" step="0.01" />
              </div>
              <div class="form-group">
                <label>คงเหลือ</label>
                <input :value="formatMoney(editRemaining)" type="text" readonly class="readonly-field" />
              </div>
              <div class="form-group">
                <label>ช่องทางชำระ</label>
                <select v-model="editForm.payment_method">
                  <option value="">— ไม่ระบุ —</option>
                  <option value="promptpay">พร้อมเพย์</option>
                  <option value="mobile_banking">Mobile Banking</option>
                  <option value="bank_transfer">โอนผ่านธนาคาร</option>
                  <option value="credit_card">บัตรเครดิต</option>
                  <option value="cash">เงินสด</option>
                  <option value="manual">แอดมินสร้างให้</option>
                </select>
              </div>
              <div class="form-group">
                <label>รหัสอ้างอิง</label>
                <input v-model.trim="editForm.payment_ref" type="text" />
              </div>
              <div class="form-group">
                <label>ชำระเมื่อ</label>
                <input v-model="editForm.paid_at" type="datetime-local" />
              </div>
              <div class="form-group">
                <label>วันเวลาโอน</label>
                <input v-model="editForm.transfer_datetime" type="datetime-local" />
              </div>
              <div class="form-group full-span">
                <label>{{ editForm.payment_type === 'deposit' ? 'แนบสลิปมัดจำใหม่' : 'แนบสลิปหลักใหม่' }}</label>
                <input type="file" accept="image/*,.pdf" @change="onMainSlipChange" />
                <div class="slip-edit-row">
                  <a v-if="editForm.current_slip_url" :href="editForm.current_slip_url" target="_blank">เปิดสลิปปัจจุบัน</a>
                  <label v-if="editForm.current_slip_url" class="check-row inline">
                    <input v-model="editForm.delete_slip" type="checkbox" />
                    <span>ลบสลิปเดิม</span>
                  </label>
                  <span v-if="editForm.slip_image">{{ editForm.slip_image.name }}</span>
                </div>
              </div>
            </div>

            <!-- ผ่อนชำระ: ตั้งค่าจำนวนงวด -->
            <div v-if="editForm.payment_type === 'installment'" class="pay-subsection">
              <p class="pay-subsection-title">ตั้งค่าการผ่อน</p>
              <div class="form-grid">
                <div class="form-group">
                  <label>จำนวนงวด</label>
                  <input v-model.number="editForm.installment_count" type="number" min="1" max="12" />
                </div>
                <div class="form-group">
                  <label>ระยะห่างงวด (วัน)</label>
                  <input v-model.number="editForm.installment_interval_days" type="number" min="1" />
                </div>
              </div>
            </div>

            <!-- มัดจำ: ยอดมัดจำ + ยอดส่วนที่เหลือ + สลิปยอดคงเหลือ -->
            <div v-if="editForm.payment_type === 'deposit'" class="pay-subsection">
              <p class="pay-subsection-title">ยอดมัดจำและส่วนที่เหลือ</p>
              <div class="form-grid">
                <div class="form-group">
                  <label>ยอดมัดจำ</label>
                  <input v-model.number="editForm.deposit_amount" type="number" min="0" step="0.01" />
                </div>
                <div class="form-group">
                  <label>ยอดส่วนที่เหลือ</label>
                  <input v-model.number="editForm.balance_amount" type="number" min="0" step="0.01" />
                  <small class="field-hint">ยอดที่ลูกค้าต้องชำระเพิ่มหลังมัดจำ</small>
                </div>
                <div class="form-group">
                  <label>กำหนดชำระส่วนที่เหลือ</label>
                  <input v-model="editForm.balance_due_at" type="date" />
                </div>
                <div class="form-group">
                  <label>รหัสอ้างอิง (ส่วนที่เหลือ)</label>
                  <input v-model.trim="editForm.balance_payment_ref" type="text" />
                </div>
                <div class="form-group">
                  <label>ชำระส่วนที่เหลือเมื่อ</label>
                  <input v-model="editForm.balance_paid_at" type="datetime-local" />
                </div>
                <div class="form-group">
                  <label>วันเวลาโอน (ส่วนที่เหลือ)</label>
                  <input v-model="editForm.balance_transfer_datetime" type="datetime-local" />
                </div>
                <div class="form-group full-span">
                  <label>แนบสลิปยอดส่วนที่เหลือใหม่</label>
                  <input type="file" accept="image/*,.pdf" @change="onBalanceSlipChange" />
                  <div class="slip-edit-row">
                    <a v-if="editForm.current_balance_slip_url" :href="editForm.current_balance_slip_url" target="_blank">เปิดสลิปปัจจุบัน</a>
                    <label v-if="editForm.current_balance_slip_url" class="check-row inline">
                      <input v-model="editForm.delete_balance_slip" type="checkbox" />
                      <span>ลบสลิปเดิม</span>
                    </label>
                    <span v-if="editForm.balance_slip_image">{{ editForm.balance_slip_image.name }}</span>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">location_on</span>
              จุดรับ / ที่นั่ง
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>จุดรับ</label>
                <select v-model.number="editForm.pickup_point_id" @change="onEditPickupChange" :disabled="!editPickupPoints.length">
                  <option value="">— ไม่ระบุจุดรับ —</option>
                  <option v-for="point in editPickupPoints" :key="point.id" :value="point.id">
                    {{ point.region_label || point.region }} · {{ point.pickup_location }}
                  </option>
                </select>
                <small v-if="!editPickupPoints.length" class="field-hint">รอบเดินทางนี้ยังไม่มีจุดรับให้เลือก</small>
              </div>
              <div class="form-group" v-if="editForm.is_join_trip">
                <label>ภูมิภาคจุดรับ (จอยทริป)</label>
                <input v-model.trim="editForm.pickup_region" type="text" placeholder="เช่น กรุงเทพฯ" />
                <small class="field-hint">ใช้กรณีจอยทริปที่ระบุเฉพาะภูมิภาค ไม่มีจุดรับตายตัว</small>
              </div>
              <div class="form-group full-span">
                <label>ที่นั่ง</label>
                <input v-model.trim="editForm.seat_ids_text" type="text" placeholder="A1, A2, B1" :disabled="editForm.is_join_trip" />
                <small class="field-hint">
                  {{ editForm.is_join_trip ? 'จอยทริปไม่ต้องระบุที่นั่ง' : 'พิมพ์รหัสที่นั่งคั่นด้วยเครื่องหมายจุลภาค จำนวนต้องเท่ากับผู้โดยสาร' }}
                </small>
              </div>

              <!-- จุดรับปักหมุดเอง — แอดมินปักหมุด/แก้ไข/ลบจากแผนที่ได้ -->
              <div class="form-group full-span">
                <label>จุดรับปักหมุดเอง (จากแผนที่)</label>
                <div v-if="editCustomPickup" class="cp-edit-card">
                  <div class="cp-edit-body">
                    <span class="material-symbols-rounded cp-edit-icon">add_location_alt</span>
                    <div class="cp-edit-text">
                      <p class="cp-edit-label">{{ editCustomPickup.label }}</p>
                      <p v-if="editCustomPickup.note" class="cp-edit-note">{{ editCustomPickup.note }}</p>
                      <p class="cp-edit-coords">{{ Number(editCustomPickup.lat).toFixed(5) }}, {{ Number(editCustomPickup.lng).toFixed(5) }}</p>
                    </div>
                  </div>
                  <div class="cp-edit-actions">
                    <button type="button" class="cp-edit-btn" @click="openEditCustomPickup">
                      <span class="material-symbols-rounded">edit_location_alt</span> แก้ไขหมุด
                    </button>
                    <button type="button" class="cp-edit-btn danger" @click="clearEditCustomPickup">
                      <span class="material-symbols-rounded">delete</span> ลบ
                    </button>
                  </div>
                </div>
                <button v-else type="button" class="cp-edit-add" @click="openEditCustomPickup">
                  <span class="material-symbols-rounded">add_location_alt</span> ปักหมุดจุดรับจากแผนที่
                </button>
                <small class="field-hint">ลูกค้าปักหมุดเองในหน้าจอง แอดมินปักหมุด/แก้ไขได้จากที่นี่</small>
              </div>
            </div>
          </section>

          <section class="edit-section">
            <div class="section-heading with-action">
              <span class="material-symbols-rounded">group</span>
              ผู้โดยสาร
              <button type="button" class="btn-secondary compact" @click="addPassenger">
                <span class="material-symbols-rounded">person_add</span>
                เพิ่มผู้โดยสาร
              </button>
            </div>
            <div class="edit-list">
              <div v-for="(passenger, index) in editForm.passengers" :key="passenger.local_key" class="edit-list-card">
                <div class="edit-list-head">
                  <strong>ผู้โดยสาร {{ index + 1 }}</strong>
                  <button type="button" class="btn-icon btn-delete" @click="removePassenger(index)">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
                <div class="form-grid">
                  <div class="form-group">
                    <label>คำนำหน้า</label>
                    <input v-model.trim="passenger.title" type="text" />
                  </div>
                  <div class="form-group">
                    <label>ชื่อ-นามสกุล *</label>
                    <input v-model.trim="passenger.name" type="text" required />
                  </div>
                  <div class="form-group">
                    <label>ชื่อเล่น</label>
                    <input v-model.trim="passenger.nickname" type="text" />
                  </div>
                  <div class="form-group">
                    <label>เบอร์โทร</label>
                    <input v-model.trim="passenger.phone" type="tel" />
                  </div>
                  <div class="form-group">
                    <label>อีเมล</label>
                    <input v-model.trim="passenger.email" type="email" />
                  </div>
                  <div class="form-group">
                    <label>บัตรประชาชน/พาสปอร์ต</label>
                    <input v-model.trim="passenger.id_card" type="text" />
                  </div>
                  <div class="form-group">
                    <label>กรุ๊ปเลือด</label>
                    <input v-model.trim="passenger.blood_group" type="text" />
                  </div>
                  <div class="form-group">
                    <label>น้ำหนัก</label>
                    <input v-model.number="passenger.weight" type="number" min="0" step="0.01" />
                  </div>
                  <div class="form-group">
                    <label>อาหารฮาลาล</label>
                    <select v-model="passenger.halal_food">
                      <option :value="null">ไม่ระบุ</option>
                      <option :value="true">ใช่</option>
                      <option :value="false">ไม่ใช่</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>ผู้ติดต่อฉุกเฉิน</label>
                    <input v-model.trim="passenger.emergency_contact" type="text" />
                  </div>
                  <div class="form-group">
                    <label>เบอร์ฉุกเฉิน</label>
                    <input v-model.trim="passenger.emergency_phone" type="tel" />
                  </div>
                  <div class="form-group">
                    <label>ระดับใบดำน้ำ</label>
                    <input v-model.trim="passenger.dive_cert_level" type="text" />
                  </div>
                  <div class="form-group">
                    <label>เลขใบรับรอง</label>
                    <input v-model.trim="passenger.cert_number" type="text" />
                  </div>
                  <div class="form-group full-span">
                    <label>การแพ้ / อาหาร</label>
                    <textarea v-model="passenger.allergies" rows="2"></textarea>
                  </div>
                  <div class="form-group full-span">
                    <label>หมายเหตุสุขภาพ</label>
                    <textarea v-model="passenger.health_notes" rows="2"></textarea>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section v-if="editForm.payment_type === 'installment'" class="edit-section">
            <div class="section-heading with-action">
              <span class="material-symbols-rounded">pending_actions</span>
              งวดผ่อนชำระ
              <button type="button" class="btn-secondary compact" @click="addInstallment">
                <span class="material-symbols-rounded">add</span>
                เพิ่มงวด
              </button>
            </div>
            <div class="edit-list">
              <div v-for="(payment, index) in editForm.installments" :key="payment.local_key" class="edit-list-card">
                <div class="edit-list-head">
                  <strong>งวดที่ {{ payment.installment_no || index + 1 }}</strong>
                  <button type="button" class="btn-icon btn-delete" @click="removeInstallment(index)">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
                <div class="form-grid">
                  <div class="form-group">
                    <label>เลขงวด</label>
                    <input v-model.number="payment.installment_no" type="number" min="1" max="12" />
                  </div>
                  <div class="form-group">
                    <label>ยอดงวด</label>
                    <input v-model.number="payment.amount" type="number" min="0" step="0.01" />
                  </div>
                  <div class="form-group">
                    <label>ครบกำหนด</label>
                    <input v-model="payment.due_date" type="date" />
                  </div>
                  <div class="form-group">
                    <label>สถานะ</label>
                    <select v-model="payment.status">
                      <option value="pending">รอชำระ</option>
                      <option value="paid">ชำระแล้ว</option>
                      <option value="failed">ไม่สำเร็จ</option>
                      <option value="cancelled">ยกเลิก</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>ช่องทาง</label>
                    <input v-model.trim="payment.payment_method" type="text" />
                  </div>
                  <div class="form-group">
                    <label>รหัสอ้างอิง</label>
                    <input v-model.trim="payment.payment_ref" type="text" />
                  </div>
                  <div class="form-group">
                    <label>ชำระเมื่อ</label>
                    <input v-model="payment.paid_at" type="datetime-local" />
                  </div>
                  <div class="form-group">
                    <label>วันเวลาโอน</label>
                    <input v-model="payment.transfer_datetime" type="datetime-local" />
                  </div>
                  <div class="form-group full-span">
                    <label>สลิปงวดนี้</label>
                    <input type="file" accept="image/*,.pdf" @change="onInstallmentSlipChange(index, $event)" />
                    <div class="slip-edit-row">
                      <a v-if="payment.current_slip_url" :href="payment.current_slip_url" target="_blank">เปิดสลิปปัจจุบัน</a>
                      <label v-if="payment.current_slip_url" class="check-row inline">
                        <input v-model="payment.delete_slip" type="checkbox" />
                        <span>ลบสลิปเดิม</span>
                      </label>
                      <span v-if="payment.slip_image">{{ payment.slip_image.name }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="closeEditModal">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span v-if="submitting" class="material-symbols-rounded animate-spin">sync</span>
              <span v-else class="material-symbols-rounded">save</span>
              บันทึกข้อมูลการจอง
            </button>
          </div>
        </form>
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
          <template v-if="statusForm.status === 'refunded'">
            <div class="form-group">
              <label>ยอดคืนเงิน (บาท)</label>
              <input v-model.number="statusForm.refundAmount" type="number" min="0" step="0.01" placeholder="0.00" />
              <p v-if="refundPreview" class="field-hint">
                นโยบาย: {{ refundPreview.policy_note }} — แนะนำคืน ฿{{ Number(refundPreview.refund_amount).toLocaleString() }}
                (ชำระมาแล้ว ฿{{ Number(refundPreview.paid_amount).toLocaleString() }})
              </p>
            </div>
            <div class="form-group">
              <label>หลักฐานการโอนคืน (สลิป)</label>
              <input type="file" accept="image/*" @change="onRefundSlipPick" />
              <p class="field-hint">แนบสลิปการโอนเงินคืนให้ลูกค้า (ไม่บังคับ) — ลูกค้าจะเห็นในหน้าสถานะการคืนเงิน</p>
            </div>
          </template>
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

    <div v-if="showTransferModal" class="modal-overlay" @click.self="closeTransferModal">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <div>
            <h2>ย้ายเจ้าของการจอง</h2>
            <p class="modal-subtitle">{{ transferBookingRef }}</p>
          </div>
          <button class="modal-close" @click="closeTransferModal">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <p class="transfer-desc">
            ค้นหาบัญชีที่ต้องการย้ายการจองไปให้ด้วยอีเมลหรือเบอร์โทรศัพท์
          </p>
          <div class="transfer-search-row">
            <input
              v-model.trim="transferQuery"
              type="text"
              placeholder="อีเมล หรือ เบอร์โทร..."
              @keydown.enter="searchTransferUser"
            />
            <button class="btn-secondary compact" :disabled="!transferQuery || transferSearching" @click="searchTransferUser">
              <span v-if="transferSearching" class="material-symbols-rounded animate-spin">sync</span>
              <span v-else class="material-symbols-rounded">search</span>
              ค้นหา
            </button>
          </div>

          <div v-if="transferError" class="transfer-error">
            <span class="material-symbols-rounded">error</span>
            {{ transferError }}
          </div>

          <div v-if="transferTargetUser" class="transfer-user-card">
            <div class="transfer-user-avatar">
              <span class="material-symbols-rounded">account_circle</span>
            </div>
            <div class="transfer-user-info">
              <strong>{{ transferTargetUser.name }}</strong>
              <span>{{ transferTargetUser.email || '-' }}</span>
              <span>{{ transferTargetUser.phone || '-' }}</span>
            </div>
            <span class="transfer-user-check material-symbols-rounded">check_circle</span>
          </div>

          <p v-if="transferTargetUser" class="confirm-warning">
            <span class="material-symbols-rounded">warning</span>
            การจองจะถูกย้ายไปยังบัญชีด้านบน และผู้ใช้นี้จะได้รับการแจ้งเตือน
          </p>

          <div class="modal-footer">
            <button class="btn-secondary" @click="closeTransferModal">ยกเลิก</button>
            <button
              class="btn-primary"
              :disabled="!transferTargetUser || submitting"
              @click="doTransferBooking"
            >
              <span v-if="submitting" class="material-symbols-rounded animate-spin">sync</span>
              <span v-else class="material-symbols-rounded">move_item</span>
              ยืนยันการย้าย
            </button>
          </div>
        </div>
      </div>
    </div>

    <CustomPickupModal
      v-if="showEditCustomPickupModal"
      :center-lat="editPickupMapCenter.lat"
      :center-lng="editPickupMapCenter.lng"
      :initial="editCustomPickup"
      @confirm="onEditCustomPickupConfirm"
      @close="showEditCustomPickupModal = false"
    />
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { useAdminStore } from '../../stores/admin';
import { useToast } from '../../lib/toast';
import api from '../../lib/axios';
import CustomPickupModal from '../../components/CustomPickupModal.vue';

const admin = useAdminStore();
const toast = useToast();

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
const showEditModal = ref(false);
const showTransferModal = ref(false);
// จุดรับปักหมุดเองในฟอร์มแก้ไข ({ label, lat, lng, note } หรือ null)
const editCustomPickup = ref(null);
const showEditCustomPickupModal = ref(false);
const detailBooking = ref(null);
const statusBooking = ref(null);
const editBooking = ref(null);
const transferBookingRef = ref('');
const transferQuery = ref('');
const transferTargetUser = ref(null);
const transferSearching = ref(false);
const transferError = ref('');
const submitting = ref(false);
const loadingDetail = ref(false);
const currentPage = ref(1);
const statusForm = reactive({ status: '', reason: '', refundAmount: null, refundSlip: null });
const refundPreview = ref(null);
const editForm = reactive({
  status: 'pending',
  schedule_id: '',
  is_join_trip: false,
  is_group: false,
  group_name: '',
  group_notes: '',
  qr_code: '',
  checked_in: false,
  checked_in_at: '',
  cancellation_reason: '',
  pickup_point_id: '',
  pickup_region: '',
  seat_ids_text: '',
  user: { name: '', email: '', phone: '' },
  total_amount: 0,
  paid_amount: 0,
  payment_type: 'full',
  payment_method: '',
  payment_ref: '',
  paid_at: '',
  transfer_datetime: '',
  installment_count: '',
  installment_interval_days: '',
  deposit_amount: '',
  balance_amount: '',
  balance_due_at: '',
  balance_payment_ref: '',
  balance_paid_at: '',
  balance_transfer_datetime: '',
  current_balance_slip_url: '',
  delete_balance_slip: false,
  balance_slip_image: null,
  current_slip_url: '',
  delete_slip: false,
  slip_image: null,
  passengers: [],
  installments: [],
});

const allTrips = ref([]);
const availableSchedules = ref([]);
const editSchedules = ref([]);
const editSchedulesLoading = ref(false);
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

// ตัวเลือกประเภทการชำระในฟอร์มแก้ไข (full / deposit / installment)
const paymentTypeOptions = [
  { value: 'full', label: 'ชำระเต็มจำนวน', icon: 'paid' },
  { value: 'deposit', label: 'มัดจำ', icon: 'savings' },
  { value: 'installment', label: 'ผ่อนชำระ', icon: 'calendar_month' },
];

// ยอดคงเหลือที่คำนวณสดในฟอร์มแก้ไข (ยอดรวม − ชำระแล้ว)
const editRemaining = computed(() => {
  const total = moneyNumber(editForm.total_amount);
  const paid = moneyNumber(editForm.paid_amount);
  return Math.max(total - paid, 0);
});

// รอบเดินทางที่เลือกได้ใน modal แก้ไข — รวมรอบปัจจุบันของการจองไว้เสมอ
// แม้จะเป็นรอบในอดีตที่ไม่ถูกดึงมาในรายการ
const editScheduleOptions = computed(() => {
  const list = [...editSchedules.value];
  const current = editBooking.value?.schedule;
  if (current?.id && !list.some((s) => s.id === current.id)) {
    list.unshift(current);
  }
  return list;
});

// จุดรับของรอบเดินทางที่กำลังเลือกอยู่ใน modal แก้ไข
const editPickupPoints = computed(() => {
  const schedule = editScheduleOptions.value.find((s) => s.id === editForm.schedule_id);
  return schedule?.pickup_points || editBooking.value?.schedule?.pickup_points || [];
});

// จุดกึ่งกลางแผนที่สำหรับปักหมุดจุดรับเอง — ใช้จุดรับที่มีพิกัดของรอบนี้ มิฉะนั้น center กรุงเทพฯ
const editPickupMapCenter = computed(() => {
  const withCoords = editPickupPoints.value.find((pt) => pt.latitude && pt.longitude);
  return withCoords
    ? { lat: Number(withCoords.latitude), lng: Number(withCoords.longitude) }
    : { lat: 13.7563, lng: 100.5018 };
});

const groupedBookings = computed(() => {
  const groups = new Map();

  bookings.value.forEach((booking) => {
    const tripId = booking.schedule?.trip?.id || booking.schedule?.trip_id || booking.schedule_id || 'unknown';
    const tripTitle = booking.schedule?.trip?.title || 'ไม่ระบุทริป';
    const key = `trip-${tripId}-${tripTitle}`;

    if (!groups.has(key)) {
      groups.set(key, {
        key,
        tripTitle,
        bookings: [],
        passengers: 0,
        totalAmount: 0,
        paidAmount: 0,
        scheduleRanges: [],
      });
    }

    const group = groups.get(key);
    const scheduleRange = formatScheduleRange(booking.schedule);

    group.bookings.push(booking);
    group.passengers += passengerCount(booking);
    group.totalAmount += moneyNumber(booking.total_amount);
    group.paidAmount += moneyNumber(booking.paid_amount);

    if (scheduleRange && scheduleRange !== '-' && !group.scheduleRanges.includes(scheduleRange)) {
      group.scheduleRanges.push(scheduleRange);
    }
  });

  return [...groups.values()].map((group) => ({
    ...group,
    scheduleRanges: group.scheduleRanges.length ? group.scheduleRanges : ['ไม่ระบุรอบเดินทาง'],
  }));
});

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
    toast.error(e.response?.data?.message || 'ไม่สามารถโหลดรายละเอียดการจองได้');
  } finally {
    loadingDetail.value = false;
  }
}

function closeDetail() {
  showDetail.value = false;
  detailBooking.value = null;
}

async function openEditModal(booking) {
  editBooking.value = booking;
  showEditModal.value = true;
  editSchedules.value = [];

  try {
    const res = await api.get(`/admin/bookings/${booking.booking_ref}`);
    const fullBooking = res.data?.data || booking;
    editBooking.value = fullBooking;
    fillEditForm(fullBooking);
    loadEditSchedules(fullBooking);
  } catch (e) {
    fillEditForm(booking);
    loadEditSchedules(booking);
    toast.error(e.response?.data?.message || 'โหลดข้อมูลล่าสุดไม่ได้ จะแสดงข้อมูลเท่าที่มีอยู่');
  }
}

// ดึงรอบเดินทางทั้งหมดของทริปนี้ มาให้เลือกใน dropdown แทนการพิมพ์ ID
async function loadEditSchedules(booking) {
  const tripId = booking?.schedule?.trip?.id || booking?.schedule?.trip_id;
  if (!tripId) return;

  editSchedulesLoading.value = true;
  try {
    const res = await api.get('/admin/schedules', { params: { trip_id: tripId, per_page: 100 } });
    editSchedules.value = res.data?.data || [];
  } catch (e) {
    console.error('Failed to fetch schedules for edit', e);
  } finally {
    editSchedulesLoading.value = false;
  }
}

// เมื่อเปลี่ยนรอบเดินทาง: ล้างจุดรับเดิมถ้าไม่อยู่ในรอบใหม่
function onEditScheduleChange() {
  const valid = editPickupPoints.value.some((p) => p.id === editForm.pickup_point_id);
  if (!valid) {
    editForm.pickup_point_id = '';
    editForm.pickup_region = '';
  }
}

// เมื่อเลือกจุดรับจาก dropdown: เติม region ให้อัตโนมัติ
function onEditPickupChange() {
  const point = editPickupPoints.value.find((p) => p.id === editForm.pickup_point_id);
  editForm.pickup_region = point?.region || '';
  // จุดรับตายตัวกับหมุดของลูกค้าใช้ร่วมกันไม่ได้ — เลือกจุดตายตัวแล้วล้างหมุดออก
  if (editForm.pickup_point_id) editCustomPickup.value = null;
}

// ── จุดรับปักหมุดเอง (ปักหมุดจากแผนที่) ──
function openEditCustomPickup() {
  showEditCustomPickupModal.value = true;
}

function onEditCustomPickupConfirm(payload) {
  editCustomPickup.value = payload;
  // ปักหมุดแล้วล้างจุดรับตายตัว เพื่อให้หมุดของลูกค้าแสดงในหน้าสตาฟ
  editForm.pickup_point_id = '';
  editForm.pickup_region = '';
  showEditCustomPickupModal.value = false;
}

function clearEditCustomPickup() {
  editCustomPickup.value = null;
}

function closeEditModal() {
  showEditModal.value = false;
  editBooking.value = null;
  editSchedules.value = [];
  resetEditForm();
}

function resetEditForm() {
  editCustomPickup.value = null;
  showEditCustomPickupModal.value = false;
  Object.assign(editForm, {
    status: 'pending',
    schedule_id: '',
    is_join_trip: false,
    is_group: false,
    group_name: '',
    group_notes: '',
    qr_code: '',
    checked_in: false,
    checked_in_at: '',
    cancellation_reason: '',
    pickup_point_id: '',
    pickup_region: '',
    seat_ids_text: '',
    user: { name: '', email: '', phone: '' },
    total_amount: 0,
    paid_amount: 0,
    payment_type: 'full',
    payment_method: '',
    payment_ref: '',
    paid_at: '',
    transfer_datetime: '',
    installment_count: '',
    installment_interval_days: '',
    deposit_amount: '',
    balance_amount: '',
    balance_due_at: '',
    balance_payment_ref: '',
    balance_paid_at: '',
    balance_transfer_datetime: '',
    current_balance_slip_url: '',
    delete_balance_slip: false,
    balance_slip_image: null,
    current_slip_url: '',
    delete_slip: false,
    slip_image: null,
    passengers: [],
    installments: [],
  });
}

function fillEditForm(booking) {
  resetEditForm();
  editCustomPickup.value = booking.custom_pickup
    ? {
        label: booking.custom_pickup.label,
        lat: Number(booking.custom_pickup.lat),
        lng: Number(booking.custom_pickup.lng),
        note: booking.custom_pickup.note || null,
      }
    : null;
  Object.assign(editForm, {
    status: booking.status || 'pending',
    schedule_id: booking.schedule?.id || '',
    is_join_trip: Boolean(booking.is_join_trip),
    is_group: Boolean(booking.is_group),
    group_name: booking.group_name || '',
    group_notes: booking.group_notes || '',
    qr_code: booking.qr_code || '',
    checked_in: Boolean(booking.checked_in),
    checked_in_at: toDatetimeInput(booking.checked_in_at),
    cancellation_reason: booking.cancellation_reason || '',
    pickup_point_id: booking.pickup_point?.id || '',
    pickup_region: booking.pickup_region || booking.pickup_point?.region || '',
    seat_ids_text: seatLabels(booking),
    user: {
      name: booking.user?.name || '',
      email: booking.user?.email || '',
      phone: booking.user?.phone || '',
    },
    total_amount: moneyNumber(booking.total_amount),
    paid_amount: moneyNumber(booking.paid_amount),
    payment_type: booking.payment_type || 'full',
    payment_method: booking.payment_method || '',
    payment_ref: booking.payment_ref || '',
    paid_at: toDatetimeInput(booking.paid_at),
    transfer_datetime: toDatetimeInput(booking.transfer_datetime),
    installment_count: booking.installment_count || '',
    installment_interval_days: booking.installment_interval_days || '',
    deposit_amount: booking.deposit_amount != null ? moneyNumber(booking.deposit_amount) : '',
    balance_amount: booking.balance_amount != null ? moneyNumber(booking.balance_amount) : '',
    balance_due_at: toDateInput(booking.balance_due_at),
    balance_payment_ref: booking.balance_payment_ref || '',
    balance_paid_at: toDatetimeInput(booking.balance_paid_at),
    balance_transfer_datetime: toDatetimeInput(booking.balance_transfer_datetime),
    current_balance_slip_url: booking.balance_slip_url || '',
    delete_balance_slip: false,
    balance_slip_image: null,
    current_slip_url: booking.slip_url || '',
    delete_slip: false,
    slip_image: null,
    passengers: (booking.passengers || []).map(mapPassengerToForm),
    installments: (booking.installment_payments || []).map(mapInstallmentToForm),
  });
}

function mapPassengerToForm(passenger = {}) {
  return {
    local_key: passenger.id || `new-${Date.now()}-${Math.random()}`,
    id: passenger.id || '',
    title: passenger.title || '',
    name: passenger.name || '',
    nickname: passenger.nickname || '',
    id_card: passenger.id_card || '',
    phone: passenger.phone || '',
    email: passenger.email || '',
    blood_group: passenger.blood_group || '',
    allergies: passenger.allergies || '',
    health_notes: passenger.health_notes || '',
    emergency_contact: passenger.emergency_contact || '',
    emergency_phone: passenger.emergency_phone || '',
    dive_cert_level: passenger.dive_cert_level || '',
    cert_number: passenger.cert_number || '',
    weight: passenger.weight || '',
    halal_food: passenger.halal_food === true ? true : passenger.halal_food === false ? false : null,
  };
}

function mapInstallmentToForm(payment = {}) {
  return {
    local_key: payment.id || `new-${Date.now()}-${Math.random()}`,
    id: payment.id || '',
    installment_no: payment.installment_no || '',
    amount: moneyNumber(payment.amount),
    due_date: toDateInput(payment.due_date),
    status: payment.status || 'pending',
    payment_method: payment.payment_method || '',
    payment_ref: payment.payment_ref || '',
    paid_at: toDatetimeInput(payment.paid_at),
    transfer_datetime: toDatetimeInput(payment.transfer_datetime),
    current_slip_url: payment.slip_url || '',
    delete_slip: false,
    slip_image: null,
  };
}

function addPassenger() {
  editForm.passengers.push(mapPassengerToForm());
}

function removePassenger(index) {
  editForm.passengers.splice(index, 1);
}

function addInstallment() {
  editForm.installments.push(mapInstallmentToForm({
    installment_no: editForm.installments.length + 1,
    amount: editForm.installments.length ? 0 : editForm.total_amount,
    status: 'pending',
  }));
  editForm.installment_count = editForm.installments.length;
}

function removeInstallment(index) {
  editForm.installments.splice(index, 1);
  editForm.installment_count = editForm.installments.length || '';
}

function onMainSlipChange(event) {
  editForm.slip_image = event.target.files?.[0] || null;
  if (editForm.slip_image) editForm.delete_slip = false;
}

function onBalanceSlipChange(event) {
  editForm.balance_slip_image = event.target.files?.[0] || null;
  if (editForm.balance_slip_image) editForm.delete_balance_slip = false;
}

function onInstallmentSlipChange(index, event) {
  const file = event.target.files?.[0] || null;
  editForm.installments[index].slip_image = file;
  if (file) editForm.installments[index].delete_slip = false;
}

async function doUpdateBooking() {
  if (!editBooking.value) return;

  submitting.value = true;
  try {
    const formData = buildEditFormData();
    const res = await admin.updateBooking(editBooking.value.booking_ref, formData);
    const updated = res.data || res;
    editBooking.value = updated;
    if (detailBooking.value?.booking_ref === updated.booking_ref) {
      detailBooking.value = updated;
    }
    showEditModal.value = false;
    await fetchData(currentPage.value);
    if (detailBooking.value?.booking_ref === updated.booking_ref) {
      await openDetail(updated);
    }
    toast.success('บันทึกข้อมูลการจองเรียบร้อยแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูลการจอง');
  } finally {
    submitting.value = false;
  }
}

function buildEditFormData() {
  const fd = new FormData();
  appendForm(fd, 'status', editForm.status);
  appendForm(fd, 'schedule_id', editForm.schedule_id);
  appendForm(fd, 'is_join_trip', editForm.is_join_trip ? 1 : 0);
  appendForm(fd, 'is_group', editForm.is_group ? 1 : 0);
  appendForm(fd, 'group_name', editForm.group_name);
  appendForm(fd, 'group_notes', editForm.group_notes);
  appendForm(fd, 'qr_code', editForm.qr_code);
  appendForm(fd, 'checked_in', editForm.checked_in ? 1 : 0);
  appendForm(fd, 'checked_in_at', editForm.checked_in_at);
  appendForm(fd, 'cancellation_reason', editForm.cancellation_reason);
  appendForm(fd, 'pickup_point_id', editForm.pickup_point_id);
  appendForm(fd, 'pickup_region', editForm.pickup_region);
  if (editCustomPickup.value) {
    appendForm(fd, 'custom_pickup_label', editCustomPickup.value.label);
    appendForm(fd, 'custom_pickup_lat', editCustomPickup.value.lat);
    appendForm(fd, 'custom_pickup_lng', editCustomPickup.value.lng);
    appendForm(fd, 'custom_pickup_note', editCustomPickup.value.note || '');
  } else {
    appendForm(fd, 'clear_custom_pickup', 1);
  }
  appendForm(fd, 'user[name]', editForm.user.name);
  appendForm(fd, 'user[email]', editForm.user.email);
  appendForm(fd, 'user[phone]', editForm.user.phone);
  appendForm(fd, 'total_amount', editForm.total_amount);
  appendForm(fd, 'paid_amount', editForm.paid_amount);
  appendForm(fd, 'payment_type', editForm.payment_type);
  appendForm(fd, 'payment_method', editForm.payment_method);
  appendForm(fd, 'payment_ref', editForm.payment_ref);
  appendForm(fd, 'paid_at', editForm.paid_at);
  appendForm(fd, 'transfer_datetime', editForm.transfer_datetime);
  appendForm(fd, 'installment_count', editForm.installment_count);
  appendForm(fd, 'installment_interval_days', editForm.installment_interval_days);
  appendForm(fd, 'delete_slip', editForm.delete_slip ? 1 : 0);
  if (editForm.slip_image) fd.append('slip_image', editForm.slip_image);

  if (editForm.payment_type === 'deposit') {
    appendForm(fd, 'deposit_amount', editForm.deposit_amount);
    appendForm(fd, 'balance_amount', editForm.balance_amount);
    appendForm(fd, 'balance_due_at', editForm.balance_due_at);
    appendForm(fd, 'balance_payment_ref', editForm.balance_payment_ref);
    appendForm(fd, 'balance_paid_at', editForm.balance_paid_at);
    appendForm(fd, 'balance_transfer_datetime', editForm.balance_transfer_datetime);
    appendForm(fd, 'delete_balance_slip', editForm.delete_balance_slip ? 1 : 0);
    if (editForm.balance_slip_image) fd.append('balance_slip_image', editForm.balance_slip_image);
  }

  const seatIds = editForm.seat_ids_text
    .split(',')
    .map((seat) => seat.trim())
    .filter(Boolean);
  if (seatIds.length) {
    seatIds.forEach((seat) => fd.append('seat_ids[]', seat));
  } else {
    fd.append('seat_ids[]', '');
  }

  editForm.passengers.forEach((passenger, index) => {
    Object.entries(passenger).forEach(([key, value]) => {
      if (key === 'local_key') return;
      appendForm(fd, `passengers[${index}][${key}]`, value);
    });
  });

  if (editForm.payment_type === 'installment') {
    editForm.installments.forEach((payment, index) => {
      Object.entries(payment).forEach(([key, value]) => {
        if (['local_key', 'current_slip_url', 'slip_image'].includes(key)) return;
        appendForm(fd, `installments[${index}][${key}]`, value);
      });
      if (payment.slip_image) {
        fd.append(`installments[${index}][slip_image]`, payment.slip_image);
      }
    });
  }

  return fd;
}

function appendForm(fd, key, value) {
  if (typeof value === 'boolean') {
    fd.append(key, value ? 1 : 0);
    return;
  }
  fd.append(key, value === null || value === undefined ? '' : value);
}

function openStatusModal(booking) {
  statusBooking.value = booking;
  statusForm.status = booking.status;
  statusForm.reason = '';
  statusForm.refundAmount = null;
  statusForm.refundSlip = null;
  refundPreview.value = null;
  showStatusModal.value = true;
}

function onRefundSlipPick(e) {
  statusForm.refundSlip = e.target.files?.[0] ?? null;
}

// When the admin switches the target status to "refunded", pull the policy
// preview so we can prefill the recommended amount.
watch(
  () => statusForm.status,
  async (status) => {
    if (status !== 'refunded' || !statusBooking.value) return;
    if (statusBooking.value.status === 'refunded') return;
    try {
      const preview = await admin.refundPreview(statusBooking.value.booking_ref);
      refundPreview.value = preview;
      if (statusForm.refundAmount === null) {
        statusForm.refundAmount = Number(preview.refund_amount ?? 0);
      }
    } catch {
      refundPreview.value = null;
    }
  },
);

async function doUpdateStatus() {
  if (!statusBooking.value) return;

  // Route an actual refund through the dedicated endpoint so refund fields,
  // seat release, notifications and the transfer slip are all handled.
  if (statusForm.status === 'refunded' && statusBooking.value.status !== 'refunded') {
    submitting.value = true;
    try {
      await admin.processRefund(statusBooking.value.booking_ref, {
        amount: statusForm.refundAmount ?? 0,
        note: statusForm.reason || null,
        slip: statusForm.refundSlip,
      });
      showStatusModal.value = false;
      if (detailBooking.value?.booking_ref === statusBooking.value.booking_ref) {
        await openDetail(statusBooking.value);
      }
      await fetchData(currentPage.value);
      toast.success('บันทึกการคืนเงินแล้ว');
    } catch (e) {
      toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการคืนเงิน');
    } finally {
      submitting.value = false;
    }
    return;
  }

  submitting.value = true;
  try {
    await admin.updateBookingStatus(statusBooking.value.booking_ref, statusForm.status, statusForm.reason);
    showStatusModal.value = false;
    if (detailBooking.value?.booking_ref === statusBooking.value.booking_ref) {
      await openDetail(statusBooking.value);
    }
    await fetchData(currentPage.value);
    toast.success(`เปลี่ยนสถานะเป็น "${statusLabels[statusForm.status] || statusForm.status}" แล้ว`);
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการเปลี่ยนสถานะ');
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
    if (detailBooking.value?.booking_ref === booking.booking_ref) closeDetail();
    await fetchData(currentPage.value);
    toast.success(`ลบการจอง ${booking.booking_ref} แล้ว`);
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการลบการจอง');
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
    toast.success('สร้างการจองเรียบร้อยแล้ว');
    if (newBooking?.booking_ref) {
      await openDetail(newBooking);
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการสร้างการจอง');
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

function passengerNames(booking) {
  return (booking?.passengers || [])
    .map((passenger) => [passenger.name, passenger.surname].filter(Boolean).join(' ').trim())
    .filter(Boolean)
    .join(', ');
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
  // มัดจำ: ยอดคงเหลือ = ยอดส่วนที่เหลือ (balance_amount) ให้ตรงกับหน้าชำระเงินของลูกค้า
  // ชำระส่วนที่เหลือแล้ว (balance_paid_at) ถือว่าเหลือ 0
  if (booking?.payment_type === 'deposit') {
    if (booking.balance_paid_at) return 0;
    const balance = moneyNumber(booking.balance_amount);
    if (balance > 0) return balance;
  }
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
  if (booking?.payment_type === 'deposit') {
    return Number(booking.balance_amount) > 0 ? 'มัดจำ (ค้างยอดส่วนที่เหลือ)' : 'มัดจำ (ชำระครบแล้ว)';
  }

  return 'ชำระเต็มจำนวน';
}

function paymentMethodLabel(method) {
  const labels = {
    promptpay: 'พร้อมเพย์',
    mobile_banking: 'Mobile Banking',
    bank_transfer: 'โอนผ่านธนาคาร',
    credit_card: 'บัตรเครดิต',
    cash: 'เงินสด',
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

function toDateInput(date) {
  if (!date) return '';
  const parsed = new Date(date);
  if (Number.isNaN(parsed.getTime())) return '';
  return parsed.toISOString().slice(0, 10);
}

function toDatetimeInput(date) {
  if (!date) return '';
  const parsed = new Date(date);
  if (Number.isNaN(parsed.getTime())) return '';
  const local = new Date(parsed.getTime() - parsed.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 16);
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

function openTransferModal(booking) {
  transferBookingRef.value = booking.booking_ref;
  transferQuery.value = '';
  transferTargetUser.value = null;
  transferError.value = '';
  showTransferModal.value = true;
}

function closeTransferModal() {
  showTransferModal.value = false;
}

async function searchTransferUser() {
  if (!transferQuery.value) return;

  transferSearching.value = true;
  transferTargetUser.value = null;
  transferError.value = '';

  try {
    const res = await api.get('/admin/users', { params: { search: transferQuery.value, per_page: 5 } });
    const users = res.data?.data || [];
    if (!users.length) {
      transferError.value = 'ไม่พบบัญชีผู้ใช้ที่ตรงกัน';
    } else {
      transferTargetUser.value = users[0];
    }
  } catch (e) {
    transferError.value = e.response?.data?.message || 'เกิดข้อผิดพลาดในการค้นหา';
  } finally {
    transferSearching.value = false;
  }
}

async function doTransferBooking() {
  if (!transferTargetUser.value || !transferBookingRef.value) return;

  submitting.value = true;
  try {
    await api.post(`/admin/bookings/${transferBookingRef.value}/transfer`, {
      user_id: transferTargetUser.value.id,
    });
    showTransferModal.value = false;
    if (detailBooking.value?.booking_ref === transferBookingRef.value) {
      await openDetail({ booking_ref: transferBookingRef.value });
    }
    await fetchData(currentPage.value);
    toast.success(`ย้ายการจองไปยัง ${transferTargetUser.value.name} แล้ว`);
  } catch (e) {
    transferError.value = e.response?.data?.message || 'เกิดข้อผิดพลาดในการย้ายการจอง';
  } finally {
    submitting.value = false;
  }
}

onMounted(() => fetchData());

// ── Slip OCR ──────────────────────────────────────────────────────
function ocrLabel(status) {
  const labels = {
    pending: '⏳ กำลังตรวจสอบ',
    verified: '✅ ผ่านอัตโนมัติ',
    failed: '❌ ต้องตรวจสอบ',
    manually_approved: '✅ อนุมัติแล้ว',
    rejected: '🚫 ปฏิเสธแล้ว',
  };
  return labels[status] || '— ยังไม่ตรวจ';
}

async function approveSlip(bookingRef, slipType, installmentNo = null) {
  const payload = { slip_type: slipType };
  if (installmentNo) payload.installment_no = installmentNo;
  try {
    await api.post(`/admin/bookings/${bookingRef}/slip/approve`, payload);
    await openDetail({ booking_ref: bookingRef });
    await fetchData(currentPage.value);
    toast.success('อนุมัติสลิปแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการอนุมัติสลิป');
  }
}

async function rejectSlip(bookingRef, slipType, installmentNo = null) {
  const reason = prompt('ระบุเหตุผลที่ปฏิเสธ (ไม่บังคับ):');
  if (reason === null) return; // ผู้ใช้กดยกเลิก
  const payload = { slip_type: slipType, reason };
  if (installmentNo) payload.installment_no = installmentNo;
  try {
    await api.post(`/admin/bookings/${bookingRef}/slip/reject`, payload);
    await openDetail({ booking_ref: bookingRef });
    toast.success('ปฏิเสธสลิปแล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการปฏิเสธสลิป');
  }
}

async function reverifySlip(bookingRef, slipType) {
  try {
    await api.post(`/admin/bookings/${bookingRef}/slip/reverify`, { slip_type: slipType });
    await openDetail({ booking_ref: bookingRef });
    toast.info('ส่งสลิปตรวจสอบใหม่แล้ว');
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการตรวจสอบสลิป');
  }
}
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

.booking-groups {
  display: grid;
  gap: 16px;
}

.trip-booking-group {
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
  overflow: hidden;
}

.trip-group-header {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 16px;
  align-items: center;
  padding: 14px;
  background: #f8fafc;
  border-bottom: 1px solid var(--color-sand-dark);
}

.trip-group-title-block {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.trip-group-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: #e8f5ec;
  color: var(--color-accent);
}

.trip-group-title-block h2 {
  color: var(--color-text-dark);
  font-size: 16px;
  font-weight: 900;
  line-height: 1.35;
  margin: 0;
  overflow-wrap: anywhere;
}

.trip-group-title-block p {
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
  margin: 2px 0 0;
}

.trip-group-stats {
  display: grid;
  grid-template-columns: repeat(4, max-content);
  gap: 8px;
}

.trip-group-stats div {
  min-width: 82px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
  padding: 8px 10px;
}

.trip-group-stats span {
  display: block;
  color: var(--color-text-muted);
  font-size: 10px;
  font-weight: 800;
}

.trip-group-stats strong {
  display: block;
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 900;
  white-space: nowrap;
}

.trip-booking-list {
  display: grid;
  gap: 12px;
  padding: 12px;
}

.booking-detail-card {
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
  padding: 12px;
}

.booking-card-head,
.booking-card-foot {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.booking-card-status {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  flex-direction: column;
  flex-shrink: 0;
}

.booking-info-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #eeeeee;
}

.booking-info-panel {
  min-width: 0;
  padding-left: 10px;
  border-left: 3px solid #e5e7eb;
}

.payment-panel {
  border-left-color: #a7f3d0;
}

.booking-panel-title {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--color-text-dark);
  font-size: 12px;
  font-weight: 900;
  margin-bottom: 6px;
}

.booking-panel-title .material-symbols-rounded {
  color: var(--color-accent);
  font-size: 17px;
}

.info-lines {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.info-lines strong {
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 900;
  overflow-wrap: anywhere;
}

.info-lines span {
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.booking-extra-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 0;
}

.booking-extra-list span {
  border-radius: 999px;
  background: #f3f4f6;
  color: #4b5563;
  padding: 4px 8px;
  font-size: 11px;
  font-weight: 800;
  overflow-wrap: anywhere;
}

.booking-card-foot {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #eeeeee;
}

.grouped-empty {
  padding: 42px 16px;
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

.mini-badge.installment {
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fde68a;
}

.mini-badge.deposit {
  background: #f5f3ff;
  color: #7c3aed;
  border: 1px solid #ddd6fe;
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

.payment-type.deposit {
  background: #f5f3ff;
  color: #7c3aed;
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

.modal-header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
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

/* จุดรับแบบ custom */
.cp-map-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 10px;
  font-size: 13px;
  font-weight: 700;
  color: var(--color-accent);
}
.cp-map-link .material-symbols-rounded { font-size: 18px; }

/* จุดรับปักหมุดเองในฟอร์มแก้ไข */
.cp-edit-add {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border: 1.5px dashed var(--color-border, #d1d5db);
  border-radius: 12px;
  background: transparent;
  color: var(--color-accent);
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.cp-edit-add:hover {
  background: rgba(20, 184, 166, 0.06);
  border-color: var(--color-accent);
}
.cp-edit-add .material-symbols-rounded { font-size: 20px; }

.cp-edit-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid #fcd34d;
  border-radius: 14px;
  background: rgba(251, 191, 36, 0.08);
}
.cp-edit-body { display: flex; gap: 12px; }
.cp-edit-icon { color: #d97706; font-size: 24px; }
.cp-edit-text { min-width: 0; }
.cp-edit-label { font-weight: 800; color: var(--color-text, #111827); line-height: 1.3; }
.cp-edit-note { font-size: 13px; color: var(--color-text-muted, #6b7280); margin-top: 2px; }
.cp-edit-coords { font-size: 12px; color: var(--color-text-muted, #6b7280); margin-top: 4px; font-variant-numeric: tabular-nums; }
.cp-edit-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.cp-edit-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 12px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 10px;
  background: #fff;
  color: var(--color-text, #374151);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s;
}
.cp-edit-btn:hover { background: #f9fafb; }
.cp-edit-btn.danger { color: #dc2626; }
.cp-edit-btn.danger:hover { background: #fef2f2; }
.cp-edit-btn .material-symbols-rounded { font-size: 17px; }

.cp-list-flag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #b45309 !important;
  font-weight: 800;
}
.cp-list-flag .material-symbols-rounded { font-size: 15px; }

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

.slip-label {
  width: 100%;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-muted);
  margin-bottom: -4px;
}

.slip-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.ocr-badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 99px;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}
.ocr-pending    { background: #fef3c7; color: #92400e; }
.ocr-verified   { background: #d1fae5; color: #065f46; }
.ocr-failed     { background: #fee2e2; color: #991b1b; }
.ocr-manually_approved { background: #d1fae5; color: #065f46; }
.ocr-rejected   { background: #f3f4f6; color: #6b7280; }
.ocr-none       { background: #f3f4f6; color: #6b7280; }

.btn-success {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  background: #059669;
  color: white;
  border: none;
  transition: background 0.15s;
}
.btn-success:hover { background: #047857; }
.btn-success.compact { padding: 4px 10px; }
.btn-success.xs { padding: 2px 8px; font-size: 11px; }

.btn-danger {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  background: #dc2626;
  color: white;
  border: none;
  transition: background 0.15s;
}
.btn-danger:hover { background: #b91c1c; }
.btn-danger.compact { padding: 4px 10px; }
.btn-danger.xs { padding: 2px 8px; font-size: 11px; }

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

.addon-list {
  display: grid;
  gap: 8px;
  margin-top: 14px;
}

.addon-row {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 12px;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  padding: 10px 12px;
  background: #f0fdf4;
}

.addon-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #16a34a;
  color: white;
}

.addon-icon .material-symbols-rounded {
  font-size: 18px;
}

.addon-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.addon-info strong {
  font-size: 13px;
  color: #14532d;
}

.addon-meta {
  font-size: 12px;
  color: var(--color-text-muted);
}

.addon-total {
  font-size: 13px;
  color: #166534;
  white-space: nowrap;
}

.addon-summary {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--color-sand);
  font-size: 13px;
  color: var(--color-text-mid);
}

.addon-summary strong {
  font-size: 14px;
  color: var(--color-text-strong);
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

.edit-booking-form {
  display: grid;
  gap: 18px;
}

.edit-section {
  border-top: 1px solid #eeeeee;
  padding-top: 16px;
}

.edit-section:first-child {
  border-top: none;
  padding-top: 0;
}

.full-span {
  grid-column: 1 / -1;
}

/* ── ตัวเลือกประเภทการชำระแบบ segmented (full / deposit / installment) ── */
.pay-type-tabs {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 16px;
}

.pay-type-tab {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 10px 8px;
  border: 1.5px solid var(--color-sand-dark);
  border-radius: 12px;
  background: #fff;
  font-size: 13px;
  font-weight: 800;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: all 0.15s ease;
  text-align: center;
}

.pay-type-tab:hover {
  border-color: var(--color-accent-light);
}

.pay-type-tab.active {
  border-color: var(--color-accent);
  background: #ecfdf5;
  color: var(--color-accent-mid);
}

.pay-type-tab input {
  display: none;
}

.pay-type-tab .material-symbols-rounded {
  font-size: 18px;
}

.pay-subsection {
  margin-top: 14px;
  padding: 14px;
  border: 1px solid #eeeeee;
  border-radius: 12px;
  background: #fafafa;
}

.pay-subsection-title {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 800;
  color: var(--color-text-mid);
}

.readonly-field {
  background: #f5f5f5 !important;
  color: var(--color-text-muted);
  font-weight: 800;
}

.field-hint {
  display: block;
  margin-top: 4px;
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
}

.check-row {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-mid);
  font-size: 13px;
  font-weight: 800;
  min-height: 38px;
}

.check-row input {
  width: 16px;
  height: 16px;
  accent-color: var(--color-accent);
}

.check-row.inline {
  min-height: auto;
}

.section-heading.with-action {
  justify-content: space-between;
  flex-wrap: wrap;
}

.edit-list {
  display: grid;
  gap: 12px;
}

.edit-list-card {
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
  background: var(--color-white);
}

.edit-list-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}

.edit-list-head strong {
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 900;
}

.slip-edit-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 8px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.slip-edit-row a {
  color: #2563eb;
  font-weight: 900;
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

  .trip-group-header,
  .booking-info-grid {
    grid-template-columns: 1fr;
  }

  .trip-group-stats {
    grid-template-columns: repeat(4, minmax(0, 1fr));
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

  .trip-group-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .trip-group-stats div {
    min-width: 0;
  }

  .booking-card-head,
  .booking-card-foot {
    flex-direction: column;
  }

  .booking-card-status {
    align-items: flex-start;
  }

  .installment-row {
    grid-template-columns: auto 1fr;
  }

  .installment-meta {
    grid-column: 1 / -1;
    text-align: left;
  }
}

.btn-transfer {
  color: #7c3aed;
}

.btn-transfer:hover:not(:disabled) {
  background: #f5f3ff;
}

.btn-transfer:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.transfer-desc {
  color: var(--color-text-muted);
  font-size: 13px;
  margin-bottom: 14px;
}

.transfer-search-row {
  display: flex;
  gap: 8px;
  margin-bottom: 14px;
}

.transfer-search-row input {
  flex: 1;
  padding: 9px 14px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  color: #111827;
}

.transfer-error {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  padding: 10px 12px;
  color: #b91c1c;
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 14px;
}

.transfer-user-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 12px;
}

.transfer-user-avatar .material-symbols-rounded {
  font-size: 36px;
  color: var(--color-accent);
}

.transfer-user-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.transfer-user-info strong {
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 900;
}

.transfer-user-info span {
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.transfer-user-check {
  color: #059669;
  font-size: 22px;
}
</style>
