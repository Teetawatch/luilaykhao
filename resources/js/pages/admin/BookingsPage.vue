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
                    <span v-if="booking.is_gift" class="mini-badge gift">
                      🎁 ของขวัญ{{ booking.gift?.claimed ? ' · รับแล้ว' : ' · รอรับ' }}
                    </span>
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
                  <button
                    v-if="canSplitBooking(booking)"
                    class="btn-icon btn-split"
                    title="แยก / ย้ายผู้โดยสารบางคน"
                    @click="openSplitModal(booking)"
                  >
                    <span class="material-symbols-rounded">call_split</span>
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
            <button
              v-if="detailBooking && canSplitBooking(detailBooking)"
              class="btn-secondary compact"
              @click="openSplitModal(detailBooking)"
            >
              <span class="material-symbols-rounded">call_split</span>
              แยกผู้โดยสาร
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
              <span v-if="detailBooking.is_gift" class="type-badge gift">🎁 ของขวัญ</span>
            </div>
          </div>

          <div v-if="detailBooking.is_gift" class="gift-note">
            <span class="material-symbols-rounded">card_giftcard</span>
            <div>
              การจองนี้เป็น<strong>ของขวัญ</strong>
              <template v-if="detailBooking.gift?.from_name">จาก “{{ detailBooking.gift.from_name }}”</template>
              —
              <template v-if="detailBooking.gift?.claimed">
                ผู้รับกดรับแล้ว เจ้าของปัจจุบันคือ <strong>{{ detailBooking.user?.name || '-' }}</strong>
              </template>
              <template v-else>
                <strong>ยังไม่ถูกกดรับ</strong> — ชื่อ/ข้อมูลผู้เดินทางจะถูกเติมเมื่อผู้รับกดรับในแอป
              </template>
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

          <section v-if="detailBooking.selected_rentals?.length" class="detail-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">camping</span>
              อุปกรณ์เช่า ({{ detailBooking.selected_rentals.length }})
            </div>
            <div class="addon-list">
              <div v-for="(rental, idx) in detailBooking.selected_rentals" :key="idx" class="addon-row">
                <span class="addon-icon">
                  <span class="material-symbols-rounded">backpack</span>
                </span>
                <div class="addon-info">
                  <strong>{{ rental.name }}</strong>
                  <span class="addon-meta">
                    {{ formatMoney(rental.unit_price) }} / ชิ้น
                    <span v-if="rental.quantity > 1"> × {{ rental.quantity }}</span>
                  </span>
                </div>
                <strong class="addon-total">{{ formatMoney(rental.total_price) }}</strong>
              </div>
              <div class="addon-summary">
                <span>รวมค่าเช่าอุปกรณ์</span>
                <strong>{{ formatMoney(detailBooking.rentals_total) }}</strong>
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
                  <InfoItem label="จุดรับ" :value="passengerPickupLabel(passenger)" />
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

          <!-- อุปกรณ์เช่า — แอดมินเพิ่มของที่ลูกค้าขอเช่าทีหลังได้ (เต็นท์ ถุงนอน หมอน) -->
          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">camping</span>
              อุปกรณ์เช่า
            </div>

            <div v-if="editRentalCatalog.length" class="rental-picker">
              <button
                v-for="item in editRentalCatalog"
                :key="item.key"
                type="button"
                class="rental-chip"
                @click="addRentalFromCatalog(item)"
              >
                <span class="material-symbols-rounded">add</span>
                {{ item.name }}
                <span class="rental-chip-price">{{ formatMoney(item.price) }}</span>
              </button>
            </div>
            <p v-else class="field-hint">ทริปนี้ยังไม่ได้ตั้งรายการอุปกรณ์ให้เช่า — เพิ่มรายการเองได้ด้านล่าง</p>

            <div v-if="editForm.rentals.length" class="rental-rows">
              <div v-for="(rental, index) in editForm.rentals" :key="rental.local_key" class="rental-row">
                <img v-if="rental.image_url" :src="rental.image_url" :alt="rental.name" class="rental-thumb" />
                <span v-else class="rental-thumb placeholder">
                  <span class="material-symbols-rounded">backpack</span>
                </span>

                <div class="rental-fields">
                  <input v-model.trim="rental.name" type="text" placeholder="ชื่ออุปกรณ์ เช่น เต็นท์ 2 คน" />
                  <div class="rental-price">
                    <label>ราคา/ชิ้น</label>
                    <input v-model.number="rental.unit_price" type="number" min="0" step="1" />
                  </div>
                </div>

                <div class="rental-qty">
                  <button type="button" @click="stepRental(index, -1)" :disabled="moneyNumber(rental.quantity) <= 1">
                    <span class="material-symbols-rounded">remove</span>
                  </button>
                  <input v-model.number="rental.quantity" type="number" min="1" max="50" />
                  <button type="button" @click="stepRental(index, 1)">
                    <span class="material-symbols-rounded">add</span>
                  </button>
                </div>

                <strong class="rental-line-total">
                  {{ formatMoney(moneyNumber(rental.unit_price) * moneyNumber(rental.quantity)) }}
                </strong>

                <button type="button" class="rental-remove" @click="removeRental(index)">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
            </div>
            <p v-else class="rental-empty">ยังไม่มีอุปกรณ์เช่าในการจองนี้</p>

            <div class="rental-footer">
              <button type="button" class="btn-secondary compact" @click="addCustomRental">
                <span class="material-symbols-rounded">add</span>
                เพิ่มรายการเอง
              </button>
              <div class="rental-total">
                <span>รวมค่าเช่าอุปกรณ์</span>
                <strong>{{ formatMoney(editRentalsTotal) }}</strong>
              </div>
            </div>
            <small class="field-hint">แก้จำนวนหรือเพิ่มรายการแล้ว ยอดรวม (และยอดคงเหลือกรณีมัดจำ) จะปรับให้อัตโนมัติ</small>
          </section>

          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">location_on</span>
              จุดรับ / ที่นั่ง
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>จุดรับของการจอง</label>
                <select v-model.number="editForm.pickup_point_id" @change="onEditPickupChange" :disabled="!editPickupPoints.length">
                  <option value="">— ไม่ระบุจุดรับ —</option>
                  <option v-for="point in editPickupPoints" :key="point.id" :value="point.id">
                    {{ point.region_label || point.region }} · {{ point.pickup_location }}
                  </option>
                </select>
                <small v-if="!editPickupPoints.length" class="field-hint">รอบเดินทางนี้ยังไม่มีจุดรับให้เลือก</small>
                <small v-else class="field-hint">ใช้กับคนที่ไม่ได้ระบุจุดของตัวเองไว้ด้านล่าง</small>
              </div>
              <div class="form-group" v-if="editForm.is_join_trip">
                <label>ภูมิภาคจุดรับ (จอยทริป)</label>
                <input v-model.trim="editForm.pickup_region" type="text" placeholder="เช่น กรุงเทพฯ" />
                <small class="field-hint">ใช้กรณีจอยทริปที่ระบุเฉพาะภูมิภาค ไม่มีจุดรับตายตัว</small>
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

            <!-- จุดรับ/ที่นั่งรายคน — คนในกลุ่มเดียวกันขึ้นคนละจุดได้
                 สตาฟกับคนขับอ่านจุดรายคนก่อนจุดของการจองเสมอ -->
            <div class="pax-pickup">
              <div class="pax-pickup-head">
                <strong>จุดรับและที่นั่งรายคน</strong>
                <button
                  v-if="editForm.passengers.length && editForm.pickup_point_id && !editCustomPickup"
                  type="button"
                  class="btn-secondary compact"
                  @click="applyBookingPickupToAll"
                >
                  <span class="material-symbols-rounded">group</span>
                  ใช้จุดของการจองกับทุกคน
                </button>
              </div>

              <p v-if="editCustomPickup" class="pax-pickup-note">
                <span class="material-symbols-rounded">info</span>
                การจองนี้ใช้หมุดที่ปักเอง — จุดรับรายคนจะถูกล้างทั้งหมดเมื่อบันทึก
              </p>

              <div v-if="!editForm.passengers.length" class="pax-pickup-empty">
                ยังไม่มีผู้โดยสารในการจองนี้ — เพิ่มผู้โดยสารในหัวข้อถัดไปก่อน
              </div>

              <div v-else class="pax-pickup-list">
                <div v-for="(passenger, index) in editForm.passengers" :key="passenger.local_key" class="pax-pickup-row">
                  <div class="pax-pickup-who">
                    <span class="pax-pickup-no">{{ index + 1 }}</span>
                    <span class="pax-pickup-name">
                      {{ passenger.name || `ผู้โดยสาร ${index + 1}` }}
                      <small v-if="passenger.nickname">({{ passenger.nickname }})</small>
                    </span>
                  </div>

                  <div class="form-group">
                    <label>จุดรับ</label>
                    <select
                      v-model.number="passenger.pickup_point_id"
                      :disabled="!editPickupPoints.length || Boolean(editCustomPickup)"
                    >
                      <option value="">— ตามจุดของการจอง —</option>
                      <option v-for="point in editPickupPoints" :key="point.id" :value="point.id">
                        {{ point.region_label || point.region }} · {{ point.pickup_location }}
                      </option>
                    </select>
                  </div>

                  <div class="form-group pax-pickup-seat">
                    <label>{{ editScheduleIsFlight ? 'ที่นั่งบนเครื่อง' : 'ที่นั่ง' }}</label>
                    <input
                      v-model.trim="passenger.seat_id"
                      type="text"
                      :placeholder="editScheduleIsFlight ? '12A' : 'A1'"
                      :disabled="editForm.is_join_trip"
                    />
                  </div>
                </div>
              </div>

              <small class="field-hint">
                {{ editForm.is_join_trip
                  ? 'จอยทริปไม่ต้องระบุที่นั่ง — ระบุเฉพาะจุดรับรายคนได้'
                  : editScheduleIsFlight
                    ? 'รอบนี้บินไป ลูกค้าเลือกที่นั่งเองไม่ได้ — กรอกเลขที่นั่งจากสายการบินให้ทีละคน แล้วลูกค้าจะเห็นในแอป'
                    : 'เว้นที่นั่งว่างไว้ได้ถ้ายังไม่ได้จัดผัง' }}
              </small>
              <small v-if="editSeatDuplicateWarning" class="pax-pickup-warning">
                <span class="material-symbols-rounded">warning</span>
                {{ editSeatDuplicateWarning }}
              </small>
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
            <p class="modal-subtitle">{{ transferBookingRef }}{{ transferTripTitle ? ` · ${transferTripTitle}` : '' }}</p>
          </div>
          <button class="modal-close" @click="closeTransferModal">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body transfer-body">
          <!-- From → to, so the admin can see what is actually moving. -->
          <div class="transfer-flow">
            <div class="transfer-party">
              <span class="transfer-party-label">เจ้าของปัจจุบัน</span>
              <div class="transfer-party-card">
                <img v-if="transferCurrentUser?.avatar_url" :src="transferCurrentUser.avatar_url" alt="" class="tu-avatar" />
                <div v-else class="tu-avatar fallback">{{ initial(transferCurrentUser?.name) }}</div>
                <div class="tu-info">
                  <strong>{{ transferCurrentUser?.name || '-' }}</strong>
                  <span>{{ transferCurrentUser?.email || transferCurrentUser?.phone || '-' }}</span>
                </div>
              </div>
            </div>

            <span class="transfer-arrow material-symbols-rounded">arrow_forward</span>

            <div class="transfer-party">
              <span class="transfer-party-label">เจ้าของใหม่</span>
              <div class="transfer-party-card" :class="{ chosen: transferTargetUser, pending: !transferTargetUser }">
                <template v-if="transferTargetUser">
                  <img v-if="transferTargetUser.avatar_url" :src="transferTargetUser.avatar_url" alt="" class="tu-avatar" />
                  <div v-else class="tu-avatar fallback accent">{{ initial(transferTargetUser.name) }}</div>
                  <div class="tu-info">
                    <strong>{{ transferTargetUser.name }}</strong>
                    <span>{{ transferTargetUser.email || transferTargetUser.phone || '-' }}</span>
                  </div>
                  <button type="button" class="tu-clear" title="เลือกใหม่" @click="transferTargetUser = null">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </template>
                <template v-else>
                  <div class="tu-avatar empty"><span class="material-symbols-rounded">person_search</span></div>
                  <div class="tu-info"><span class="tu-placeholder">ยังไม่ได้เลือกบัญชี</span></div>
                </template>
              </div>
            </div>
          </div>

          <label class="transfer-label" for="transfer-search">ค้นหาบัญชีปลายทาง</label>
          <div class="transfer-search-row">
            <span class="material-symbols-rounded search-icon">search</span>
            <input
              id="transfer-search"
              ref="transferInput"
              v-model.trim="transferQuery"
              type="text"
              autocomplete="off"
              placeholder="ชื่อ / อีเมล / เบอร์โทร"
            />
            <span v-if="transferSearching" class="material-symbols-rounded animate-spin search-spinner">progress_activity</span>
            <button v-else-if="transferQuery" type="button" class="search-clear" title="ล้างคำค้นหา" @click="clearTransferSearch">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>

          <div v-if="transferError" class="transfer-error">
            <span class="material-symbols-rounded">error</span>
            {{ transferError }}
          </div>

          <!-- Every match is listed: picking silently for the admin is how a
               booking ends up on the wrong account. -->
          <div v-if="transferResults.length" class="transfer-results">
            <button
              v-for="user in transferResults"
              :key="user.id"
              type="button"
              class="transfer-result"
              :class="{ active: transferTargetUser?.id === user.id, self: isCurrentOwner(user) }"
              :disabled="isCurrentOwner(user)"
              @click="selectTransferUser(user)"
            >
              <img v-if="user.avatar_url" :src="user.avatar_url" alt="" class="tu-avatar" />
              <div v-else class="tu-avatar fallback">{{ initial(user.name) }}</div>
              <div class="tu-info">
                <strong>{{ user.name }}</strong>
                <span>{{ user.email || '-' }}{{ user.phone ? ` · ${user.phone}` : '' }}</span>
              </div>
              <span v-if="isCurrentOwner(user)" class="tu-tag">เจ้าของปัจจุบัน</span>
              <span v-else-if="staffRoleLabel(user)" class="tu-tag warn">{{ staffRoleLabel(user) }}</span>
              <span v-else class="tu-tag muted">{{ user.bookings_count || 0 }} การจอง</span>
              <span v-if="transferTargetUser?.id === user.id" class="material-symbols-rounded tu-check">check_circle</span>
            </button>
          </div>

          <p v-else-if="transferSearched && !transferSearching && !transferError" class="transfer-hint empty">
            <span class="material-symbols-rounded">person_off</span>
            ไม่พบบัญชีที่ตรงกับ “{{ transferQuery }}”
          </p>

          <p v-else-if="!transferQuery" class="transfer-hint">
            <span class="material-symbols-rounded">info</span>
            พิมพ์ชื่อ อีเมล หรือเบอร์โทรของบัญชีปลายทาง แล้วเลือกจากรายการ
          </p>

          <p v-if="transferTargetUser" class="confirm-warning">
            <span class="material-symbols-rounded">warning</span>
            การจอง {{ transferBookingRef }} จะย้ายไปยังบัญชี {{ transferTargetUser.name }} และผู้ใช้จะได้รับการแจ้งเตือน
          </p>

          <div class="modal-footer">
            <button class="btn-secondary" :disabled="submitting" @click="closeTransferModal">ยกเลิก</button>
            <button
              class="btn-primary"
              :disabled="!transferTargetUser || submitting"
              @click="doTransferBooking"
            >
              <span v-if="submitting" class="material-symbols-rounded animate-spin">progress_activity</span>
              <span v-else class="material-symbols-rounded">move_item</span>
              ยืนยันการย้าย
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- แยกผู้โดยสารบางคนออกจากการจองกลุ่ม แล้วย้ายไปรอบอื่น (ข้ามทริปได้) -->
    <div v-if="showSplitModal" class="modal-overlay" @click.self="closeSplitModal">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <div>
            <h2>แยก / ย้ายผู้โดยสาร</h2>
            <p class="modal-subtitle">
              {{ splitBooking?.booking_ref || '-' }} · {{ splitBooking?.schedule?.trip?.title || '-' }}
              <template v-if="splitBooking?.schedule"> · {{ formatScheduleRange(splitBooking.schedule) }}</template>
            </p>
          </div>
          <button class="modal-close" @click="closeSplitModal">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body split-body">
          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">groups</span>
              เลือกผู้โดยสารที่ต้องการย้าย
            </div>
            <div class="split-passenger-list">
              <label v-for="p in splitPassengers" :key="p.id" class="split-passenger-item">
                <input type="checkbox" v-model="splitSelectedIds" :value="p.id" />
                <span class="split-passenger-name">{{ p.name }}</span>
                <span class="split-passenger-meta">
                  {{ p.phone || splitBooking?.user?.phone || 'ไม่มีเบอร์' }}
                  <template v-if="!splitBooking?.is_join_trip">
                    · ที่นั่ง {{ splitSeatOf(p) || '—' }}
                  </template>
                </span>
              </label>
            </div>
            <p class="field-hint">
              เลือก {{ splitSelectedIds.length }} / {{ splitPassengers.length }} คน
            </p>
          </section>

          <section class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">calendar_month</span>
              เลือกรอบเดินทางปลายทาง (ย้ายข้ามทริปได้)
            </div>
            <div class="split-target-search">
              <span class="material-symbols-rounded">search</span>
              <input v-model.trim="splitTargetSearch" type="text" placeholder="ค้นหาชื่อทริปปลายทาง..." />
            </div>
            <div v-if="splitTargetsLoading" class="loading-state"><div class="spinner"></div></div>
            <div v-else-if="!splitTargetGroups.length" class="empty-state">ไม่พบรอบเดินทางปลายทาง</div>
            <div v-else class="split-target-list">
              <div v-for="group in splitTargetGroups" :key="group.trip_id" class="split-target-group">
                <div class="split-target-trip">{{ group.title }}</div>
                <label
                  v-for="sch in group.schedules"
                  :key="sch.id"
                  class="split-target-item"
                  :class="{ disabled: isSplitTargetDisabled(sch) }"
                >
                  <input
                    type="radio"
                    v-model.number="splitTargetId"
                    :value="sch.id"
                    :disabled="isSplitTargetDisabled(sch)"
                  />
                  <span>{{ formatScheduleRange(sch) }}</span>
                  <span class="split-target-seats">ว่าง {{ sch.available_seats ?? '-' }} ที่</span>
                </label>
              </div>
            </div>
          </section>

          <section v-if="splitTargetId && splitSeatRows.length && splitTargetHasNoSeatMap" class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">flight</span>
              ที่นั่งในรอบปลายทาง
            </div>
            <p class="field-hint">
              รอบปลายทางเดินทางโดยเครื่องบิน ไม่มีผังที่นั่งให้จัด — ที่นั่งเดิมจะถูกปล่อยคืนรอบต้นทาง
              แล้วค่อยกรอกเลขที่นั่งจากสายการบินให้ทีหลังที่หน้าแก้ไขการจอง
            </p>
          </section>

          <section v-if="splitTargetId && splitSeatRows.length && !splitTargetHasNoSeatMap" class="edit-section">
            <div class="section-heading">
              <span class="material-symbols-rounded">airline_seat_recline_normal</span>
              ที่นั่งในรอบปลายทาง
            </div>
            <div v-if="splitSeatMapLoading" class="loading-state"><div class="spinner"></div></div>
            <p v-else-if="splitSeatMapError" class="field-error">{{ splitSeatMapError }}</p>
            <template v-else>
              <p class="field-hint">
                เลือกผู้โดยสารทางซ้าย แล้วกดที่นั่งบนผัง · ว่าง {{ splitAvailableSeats.length }} ที่นั่ง
              </p>

              <div class="split-seat-layout">
                <div class="split-seat-passengers">
                  <button
                    v-for="row in splitSeatRows"
                    :key="row.passenger.id"
                    type="button"
                    class="split-seat-person"
                    :class="{ active: activeSplitPassengerId === row.passenger.id, assigned: splitSeatAssignments[row.passenger.id] }"
                    @click="activeSplitPassengerId = row.passenger.id"
                  >
                    <span class="split-seat-person-name">{{ row.passenger.name }}</span>
                    <span class="split-seat-person-meta">
                      เดิม {{ row.originalSeatId || '—' }}
                      <template v-if="row.originalSeatId && !row.originalSeatAvailable"> · เดิมไม่ว่าง</template>
                    </span>
                    <strong>{{ splitSeatAssignments[row.passenger.id] || 'ยังไม่เลือก' }}</strong>
                  </button>
                </div>

                <div class="split-seat-map">
                  <div class="split-seat-legend">
                    <span><i class="split-legend-box available"></i>ว่าง</span>
                    <span><i class="split-legend-box selected"></i>เลือกอยู่</span>
                    <span><i class="split-legend-box booked"></i>จองแล้ว/ล็อก</span>
                  </div>

                  <div class="split-seat-vehicle">
                    <div class="split-seat-front">
                      <span>{{ splitSeatMap?.front_label || 'หน้ารถ' }}</span>
                      <span v-if="splitSeatMap?.show_driver !== false" class="split-seat-driver">
                        <span class="material-symbols-rounded">{{ splitSeatMap?.driver_icon || 'directions_car' }}</span>
                        คนขับ
                      </span>
                    </div>

                    <div class="split-seat-grid" :style="splitSeatGridStyle">
                      <template v-for="cell in splitSeatCells" :key="cell.key">
                        <div v-if="cell.type === 'aisle'" class="split-seat-aisle"></div>
                        <button
                          v-else-if="cell.seat"
                          type="button"
                          class="split-seat-button"
                          :class="splitSeatButtonClass(cell.seat)"
                          :disabled="!canSelectSplitSeat(cell.seat)"
                          :title="splitSeatTitle(cell.seat)"
                          @click="assignSplitSeat(cell.seat)"
                        >
                          <span class="material-symbols-rounded">airline_seat_recline_normal</span>
                          <strong>{{ cell.seat.label || cell.seat.id }}</strong>
                          <small v-if="splitSeatAssignedName(cell.seat.id)">{{ splitSeatAssignedName(cell.seat.id) }}</small>
                        </button>
                        <div v-else class="split-seat-empty"></div>
                      </template>
                    </div>

                    <div class="split-seat-rear">{{ splitSeatMap?.rear_label || 'ท้ายรถ' }}</div>
                  </div>
                </div>
              </div>

              <p v-if="splitSeatError" class="field-error">{{ splitSeatError }}</p>
            </template>
          </section>

          <div v-if="splitIsPartial" class="split-warning">
            <span class="material-symbols-rounded">info</span>
            <div>
              ย้ายเพียงบางคน — ระบบจะ<strong>แยกเป็นการจองใบใหม่</strong> (เลขที่จองและ QR ใหม่)
              พร้อมหารยอดเงิน ยอดที่ชำระแล้ว ส่วนลด และงวดผ่อนตามสัดส่วนจำนวนคน
              ส่วนคนที่เหลือยังอยู่การจองเดิม
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn-secondary" :disabled="splitSubmitting" @click="closeSplitModal">ยกเลิก</button>
          <button
            class="btn-primary"
            :disabled="!splitTargetId || !splitSelectedIds.length || splitSubmitting || splitSeatMapLoading || Boolean(splitSeatError)"
            @click="doSplitMove"
          >
            <span v-if="splitSubmitting" class="material-symbols-rounded animate-spin">progress_activity</span>
            <span v-else class="material-symbols-rounded">call_split</span>
            ย้าย {{ splitSelectedIds.length }} คน
          </button>
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
import { computed, h, nextTick, onMounted, reactive, ref, watch } from 'vue';
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
const showSplitModal = ref(false);
const splitBooking = ref(null);
const splitSelectedIds = ref([]);
const splitTargets = ref([]);
const splitTargetsLoading = ref(false);
const splitTargetSearch = ref('');
const splitTargetId = ref(null);
const splitSeatMap = ref(null);
const splitSeatMapLoading = ref(false);
const splitSeatMapError = ref('');
const splitSeatAssignments = reactive({});
const activeSplitPassengerId = ref(null);
const splitSubmitting = ref(false);

const splitPassengers = computed(() => splitBooking.value?.passengers || []);
const splitIsPartial = computed(
  () => splitSelectedIds.value.length > 0 && splitSelectedIds.value.length < splitPassengers.value.length,
);
const splitTargetGroups = computed(() => {
  const groups = new Map();
  splitTargets.value.forEach((sch) => {
    const tripId = sch.trip?.id || sch.trip_id || 'unknown';
    if (!groups.has(tripId)) {
      groups.set(tripId, { trip_id: tripId, title: sch.trip?.title || 'ไม่ระบุทริป', schedules: [] });
    }
    groups.get(tripId).schedules.push(sch);
  });
  return [...groups.values()];
});
// เฉพาะคนที่ถูกเลือกและมีที่นั่งเดิม — จองแบบจอยทริปไม่มีผังที่นั่ง
const splitSeatRows = computed(() => {
  if (splitBooking.value?.is_join_trip) return [];

  return splitPassengers.value
    .filter((p) => splitSelectedIds.value.includes(p.id) && splitSeatOf(p))
    .map((passenger) => {
      const originalSeatId = splitSeatOf(passenger);
      return {
        passenger,
        originalSeatId,
        originalSeatAvailable: isSplitSeatAvailable(originalSeatId),
      };
    });
});
const splitAvailableSeats = computed(() => (splitSeatMap.value?.seats || []).filter((seat) => seat.status === 'available'));

// ผังที่นั่งของรอบปลายทาง — คอลัมน์ว่าง ('') คือทางเดิน
const splitSeatColumns = computed(() => splitSeatMap.value?.columns || []);
const splitSeatGridStyle = computed(() => ({
  gridTemplateColumns: splitSeatColumns.value.map((column) => (column === '' ? '34px' : '58px')).join(' '),
}));
const splitSeatCells = computed(() => {
  if (!splitSeatMap.value) return [];

  const seatsById = new Map((splitSeatMap.value.seats || []).map((seat) => [seat.id, seat]));
  const cells = [];

  for (let row = 1; row <= (splitSeatMap.value.rows || 0); row += 1) {
    splitSeatColumns.value.forEach((column, columnIndex) => {
      if (column === '') {
        cells.push({ key: `aisle-${row}-${columnIndex}`, type: 'aisle' });
        return;
      }

      const seatId = `${column}${row}`;
      cells.push({ key: seatId, type: 'seat', seat: seatsById.get(seatId) || null });
    });
  }

  return cells;
});
const activeSplitPassenger = computed(
  () => splitSeatRows.value.find((row) => row.passenger.id === activeSplitPassengerId.value)?.passenger || null,
);
// รอบปลายทางที่บินไปไม่มีผังที่นั่ง — ข้ามขั้นตอนจัดที่นั่งไปเลย ไม่งั้นปุ่มยืนยัน
// จะติดอยู่ที่ "ที่นั่งเดิมไม่ว่างในรอบปลายทาง" ทั้งที่ไม่มีผังให้ชนกัน
const splitTargetHasNoSeatMap = computed(() => splitSeatMap.value?.has_seat_map === false);

const splitSeatError = computed(() => {
  if (splitTargetHasNoSeatMap.value) return '';

  const assigned = splitSeatRows.value
    .map((row) => splitSeatAssignments[row.passenger.id])
    .filter(Boolean);

  const duplicates = assigned.filter((seatId, index) => assigned.indexOf(seatId) !== index);
  if (duplicates.length) return `เลือกที่นั่งซ้ำ: ${[...new Set(duplicates)].join(', ')}`;

  // ไม่ระบุที่นั่งใหม่ = ใช้เบอร์เดิม ซึ่งต้องว่างในรอบปลายทาง
  const keptTaken = splitSeatRows.value
    .filter((row) => !splitSeatAssignments[row.passenger.id] && !row.originalSeatAvailable)
    .map((row) => row.originalSeatId);
  if (keptTaken.length) return `ที่นั่ง ${[...new Set(keptTaken)].join(', ')} ไม่ว่างในรอบปลายทาง กรุณาเลือกที่นั่งใหม่`;

  return '';
});

// จุดรับปักหมุดเองในฟอร์มแก้ไข ({ label, lat, lng, note } หรือ null)
const editCustomPickup = ref(null);
const showEditCustomPickupModal = ref(false);
const detailBooking = ref(null);
const statusBooking = ref(null);
const editBooking = ref(null);
const transferBookingRef = ref('');
const transferTripTitle = ref('');
const transferCurrentUser = ref(null);
const transferQuery = ref('');
const transferResults = ref([]);
const transferTargetUser = ref(null);
const transferSearching = ref(false);
const transferSearched = ref(false);
const transferError = ref('');
const transferInput = ref(null);
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
  rentals: [],
});

// จุดรับของการจองก่อนถูกแก้ — ใช้ดูว่าใคร "ยืนจุดเดียวกับหัวการจอง" อยู่
const editPickupPrevious = ref('');

// ค่าเช่าอุปกรณ์ล่าสุดที่สะท้อนอยู่ในช่องยอดรวมแล้ว — ใช้คิดส่วนต่างเวลาแอดมินแก้
const rentalsBaseline = ref(0);
const suppressRentalTotalSync = ref(false);

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

// รายการอุปกรณ์ให้เช่าของทริปนี้ (catalog) — ใช้เป็นปุ่มลัดเพิ่มเข้าการจอง
const editRentalCatalog = computed(() => {
  const items = editBooking.value?.schedule?.trip?.rental_items;
  if (!Array.isArray(items)) return [];

  return items
    .filter((item) => item && item.name)
    .map((item, index) => ({
      key: `${index}-${item.name}`,
      name: item.name,
      price: moneyNumber(item.price),
      image_url: item.image_url || '',
    }));
});

// เตือนที่นั่งซ้ำก่อนกดบันทึก — server ก็ปฏิเสธให้ แต่เห็นตั้งแต่ตอนพิมพ์ดีกว่า
const editSeatDuplicateWarning = computed(() => {
  const seats = editForm.passengers.map((p) => (p.seat_id || '').trim()).filter(Boolean);
  const duplicates = [...new Set(seats.filter((seat, index) => seats.indexOf(seat) !== index))];

  return duplicates.length ? `ที่นั่งซ้ำกัน: ${duplicates.join(', ')}` : '';
});

const editRentalsTotal = computed(() => editForm.rentals.reduce(
  (sum, rental) => sum + moneyNumber(rental.unit_price) * moneyNumber(rental.quantity),
  0,
));

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

// รอบที่กำลังแก้อยู่บินไปไหม — ที่นั่งเครื่องบินไม่ได้อยู่บนผังของเรา ทีมงาน
// กรอกเลขจริงจากสายการบิน (เช่น 12A) ให้ทีละคนตรงนี้
const editScheduleIsFlight = computed(() => {
  const schedule = editScheduleOptions.value.find((s) => s.id === editForm.schedule_id);
  return (schedule || editBooking.value?.schedule)?.transport_type === 'flight';
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

  // จุดรับเป็นของรอบเดินทาง — ย้ายรอบแล้วจุดของรอบเดิมใช้ไม่ได้อีก
  // (ฝั่ง server มี remapPassengerPickupPoints จับคู่จุดที่ชื่อตรงกันให้อยู่แล้ว)
  editForm.passengers.forEach((passenger) => {
    if (!editPickupPoints.value.some((p) => p.id === passenger.pickup_point_id)) {
      passenger.pickup_point_id = '';
    }
  });
}

// เมื่อเลือกจุดรับจาก dropdown: เติม region ให้อัตโนมัติ
function onEditPickupChange() {
  const point = editPickupPoints.value.find((p) => p.id === editForm.pickup_point_id);
  editForm.pickup_region = point?.region || '';
  // จุดรับตายตัวกับหมุดของลูกค้าใช้ร่วมกันไม่ได้ — เลือกจุดตายตัวแล้วล้างหมุดออก
  if (editForm.pickup_point_id) editCustomPickup.value = null;

  // คนที่ยังยืนจุดเดียวกับหัวการจอง (หรือยังไม่ได้เลือกเอง) ย้ายตามให้เลย —
  // เป็นพฤติกรรมเดิมที่ backend เคยทำให้เงียบ ๆ ตอนนี้เห็นผลก่อนกดบันทึก
  // ส่วนคนที่เลือกจุดของตัวเองไว้ต่างหากจะไม่ถูกแตะ
  editForm.passengers.forEach((passenger) => {
    if (!passenger.pickup_point_id || passenger.pickup_point_id === editPickupPrevious.value) {
      passenger.pickup_point_id = editForm.pickup_point_id;
    }
  });

  editPickupPrevious.value = editForm.pickup_point_id;
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
  editPickupPrevious.value = '';
  showEditCustomPickupModal.value = false;
  // fillEditForm() เติมค่าต่อทันทีแบบ synchronous — กันไม่ให้ยอดรวมถูกปรับ
  // ตามค่าอุปกรณ์ที่โหลดมาตั้งต้น (จะปรับเฉพาะตอนแอดมินแก้เองเท่านั้น)
  suppressRentalTotalSync.value = true;
  nextTick(() => {
    suppressRentalTotalSync.value = false;
    rentalsBaseline.value = editRentalsTotal.value;
  });
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
    rentals: [],
  });
}

function fillEditForm(booking) {
  resetEditForm();
  editPickupPrevious.value = booking.pickup_point?.id || '';
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
    // ที่นั่งผูกกับผู้โดยสารตามลำดับ (ฝั่ง server จับคู่ด้วย index เหมือนกัน)
    passengers: (booking.passengers || []).map(
      (passenger, index) => mapPassengerToForm(passenger, seatIdAt(booking, index)),
    ),
    installments: (booking.installment_payments || []).map(mapInstallmentToForm),
    rentals: (booking.selected_rentals || []).map(mapRentalToForm),
  });
}

function seatIdAt(booking, index) {
  return (booking?.seats || [])[index]?.seat_id || '';
}

function mapPassengerToForm(passenger = {}, seatId = '') {
  return {
    local_key: passenger.id || `new-${Date.now()}-${Math.random()}`,
    id: passenger.id || '',
    pickup_point_id: passenger.pickup_point_id || '',
    seat_id: seatId,
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

function mapRentalToForm(rental = {}) {
  return {
    local_key: `rental-${Date.now()}-${Math.random()}`,
    name: rental.name || '',
    unit_price: moneyNumber(rental.unit_price),
    quantity: Math.max(1, Math.round(moneyNumber(rental.quantity)) || 1),
    image_url: rental.image_url || '',
  };
}

// เพิ่มจาก catalog ของทริป — ถ้ามีรายการชื่อเดียวกันอยู่แล้วให้บวกจำนวนแทนแถวซ้ำ
// (ใบแจกอุปกรณ์ของสตาฟอ้างด้วยชื่อ ชื่อซ้ำสองแถวจะทำให้ติ๊กแจกสับสน)
function addRentalFromCatalog(item) {
  const existing = editForm.rentals.find((rental) => rental.name === item.name);
  if (existing) {
    existing.quantity = Math.min(50, Math.max(1, Math.round(moneyNumber(existing.quantity))) + 1);
    return;
  }

  editForm.rentals.push(mapRentalToForm({
    name: item.name,
    unit_price: item.price,
    quantity: 1,
    image_url: item.image_url,
  }));
}

function addCustomRental() {
  editForm.rentals.push(mapRentalToForm());
}

function stepRental(index, delta) {
  const rental = editForm.rentals[index];
  if (!rental) return;

  const next = Math.round(moneyNumber(rental.quantity)) + delta;
  rental.quantity = Math.min(50, Math.max(1, next));
}

function removeRental(index) {
  editForm.rentals.splice(index, 1);
}

// ค่าเช่าอุปกรณ์เป็นส่วนหนึ่งของยอดรวม — แอดมินเพิ่ม/ลดของ ยอดที่ต้องเก็บก็ต้องขยับตาม
// ปรับเป็นส่วนต่างเพื่อไม่ทับยอดที่แอดมินพิมพ์เองไว้ในช่องยอดรวม
watch(editRentalsTotal, (next) => {
  if (suppressRentalTotalSync.value) {
    rentalsBaseline.value = next;
    return;
  }

  const delta = round2(next - rentalsBaseline.value);
  rentalsBaseline.value = next;
  if (!delta) return;

  editForm.total_amount = Math.max(round2(moneyNumber(editForm.total_amount) + delta), 0);
  if (editForm.payment_type === 'deposit' && editForm.balance_amount !== '') {
    editForm.balance_amount = Math.max(round2(moneyNumber(editForm.balance_amount) + delta), 0);
  }
});

function round2(value) {
  return Math.round(value * 100) / 100;
}

function addPassenger() {
  editForm.passengers.push(mapPassengerToForm());
}

function removePassenger(index) {
  // ที่นั่งกับจุดรับเดินทางไปกับผู้โดยสารแล้ว ลบแถวเดียวจึงปล่อยที่นั่งคืนไปด้วย
  editForm.passengers.splice(index, 1);
}

// เซ็ตจุดรับของการจองให้ทุกคนรวดเดียว — กรณีปกติที่ทั้งกลุ่มขึ้นจุดเดียวกัน
function applyBookingPickupToAll() {
  editForm.passengers.forEach((passenger) => {
    passenger.pickup_point_id = editForm.pickup_point_id;
  });
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

  // ที่นั่งส่งเรียงตามผู้โดยสาร รวมช่องว่างด้วย เพื่อให้ index ตรงกันทั้งสองฝั่ง
  // (ต้องมีอย่างน้อยหนึ่งค่าเสมอ ไม่งั้น server จะถือว่าไม่ได้ส่ง seat_ids มา)
  if (editForm.passengers.length) {
    editForm.passengers.forEach((passenger) => fd.append('seat_ids[]', passenger.seat_id || ''));
  } else {
    fd.append('seat_ids[]', '');
  }

  editForm.passengers.forEach((passenger, index) => {
    Object.entries(passenger).forEach(([key, value]) => {
      if (['local_key', 'seat_id'].includes(key)) return;
      appendForm(fd, `passengers[${index}][${key}]`, value);
    });
  });

  // ส่งชุดอุปกรณ์เช่าทั้งชุดเสมอ (ชุดว่าง = ลบทั้งหมด) — ฝั่ง server คิดยอดรวมใหม่เอง
  appendForm(fd, 'sync_rentals', 1);
  editForm.rentals
    .filter((rental) => rental.name && moneyNumber(rental.quantity) >= 1)
    .forEach((rental, index) => {
      appendForm(fd, `selected_rentals[${index}][name]`, rental.name);
      appendForm(fd, `selected_rentals[${index}][unit_price]`, moneyNumber(rental.unit_price));
      appendForm(fd, `selected_rentals[${index}][quantity]`, Math.round(moneyNumber(rental.quantity)));
      appendForm(fd, `selected_rentals[${index}][image_url]`, rental.image_url || '');
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

// จุดรับของผู้โดยสารคนนี้ — ไม่ได้ระบุไว้เอง = ใช้จุดของการจอง
function passengerPickupLabel(passenger) {
  const point = passenger?.pickup_point;
  if (!point) return 'ตามจุดของการจอง';

  return [point.region_label || point.region, point.pickup_location].filter(Boolean).join(' · ');
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

const initial = (name) => (name || '?').charAt(0).toUpperCase();

// Moving a booking onto a staff/admin account is almost always a mistake —
// flag it in the list rather than letting it look like any other customer.
const staffRoleLabel = (user) => {
  const roles = user.roles || [];
  if (roles.includes('admin')) return 'แอดมิน';
  if (roles.includes('operator')) return 'ผู้ดูแล';
  if (roles.includes('staff')) return 'สตาฟ';
  return '';
};

const isCurrentOwner = (user) => Number(user.id) === Number(transferCurrentUser.value?.id);

function openTransferModal(booking) {
  transferBookingRef.value = booking.booking_ref;
  transferTripTitle.value = booking.schedule?.trip?.title || '';
  transferCurrentUser.value = booking.user || null;
  transferQuery.value = '';
  transferResults.value = [];
  transferTargetUser.value = null;
  transferSearched.value = false;
  transferError.value = '';
  showTransferModal.value = true;
  nextTick(() => transferInput.value?.focus());
}

function closeTransferModal() {
  clearTimeout(transferSearchTimer);
  showTransferModal.value = false;
}

function clearTransferSearch() {
  transferQuery.value = '';
  transferInput.value?.focus();
}

function selectTransferUser(user) {
  if (isCurrentOwner(user)) return;
  transferTargetUser.value = user;
  transferError.value = '';
}

let transferSearchTimer = null;
let transferSearchSeq = 0;

watch(transferQuery, (query) => {
  clearTimeout(transferSearchTimer);
  transferError.value = '';

  if (!query) {
    transferResults.value = [];
    transferSearched.value = false;
    transferSearching.value = false;
    return;
  }

  transferSearching.value = true;
  transferSearchTimer = setTimeout(() => searchTransferUsers(query), 350);
});

async function searchTransferUsers(query) {
  // A slow earlier request must not overwrite the results of a later one.
  const seq = ++transferSearchSeq;
  transferSearching.value = true;

  try {
    const res = await api.get('/admin/users', { params: { search: query, per_page: 8 } });
    if (seq !== transferSearchSeq) return;
    transferResults.value = res.data?.data || [];
    transferSearched.value = true;
  } catch (e) {
    if (seq !== transferSearchSeq) return;
    transferResults.value = [];
    transferError.value = e.response?.data?.message || 'ค้นหาบัญชีไม่สำเร็จ';
  } finally {
    if (seq === transferSearchSeq) transferSearching.value = false;
  }
}

async function doTransferBooking() {
  if (!transferTargetUser.value || !transferBookingRef.value) return;

  submitting.value = true;
  transferError.value = '';
  try {
    await api.post(`/admin/bookings/${transferBookingRef.value}/transfer`, {
      user_id: transferTargetUser.value.id,
    });
    const targetName = transferTargetUser.value.name;
    showTransferModal.value = false;
    if (detailBooking.value?.booking_ref === transferBookingRef.value) {
      await openDetail({ booking_ref: transferBookingRef.value });
    }
    await fetchData(currentPage.value);
    toast.success(`ย้ายการจองไปยัง ${targetName} แล้ว`);
  } catch (e) {
    transferError.value = e.response?.data?.message || 'เกิดข้อผิดพลาดในการย้ายการจอง';
  } finally {
    submitting.value = false;
  }
}

// ── แยก / ย้ายผู้โดยสารบางคน ────────────────────────────────────
// ใช้ API เดียวกับหน้าจัดการรอบเดินทาง (move-bookings) — ถ้าเลือกไม่ครบทุกคน
// backend จะแตกการจองใบใหม่ให้พร้อมหารยอดเงินตามสัดส่วน

function canSplitBooking(booking) {
  return passengerCount(booking) > 1 && !['cancelled', 'refunded'].includes(booking?.status);
}

async function openSplitModal(booking) {
  splitBooking.value = booking;
  splitSelectedIds.value = [];
  splitTargetId.value = null;
  splitTargetSearch.value = '';
  resetSplitSeatState();
  showSplitModal.value = true;

  // การจองในรายการอาจยังไม่มีผู้โดยสาร/ที่นั่งครบ — ดึงข้อมูลเต็มก่อน
  try {
    const res = await api.get(`/admin/bookings/${booking.booking_ref}`);
    splitBooking.value = res.data?.data || booking;
  } catch (e) {
    toast.error('โหลดข้อมูลการจองล่าสุดไม่ได้ จะแสดงข้อมูลเท่าที่มีอยู่');
  }

  fetchSplitTargets();
}

function closeSplitModal() {
  showSplitModal.value = false;
  splitBooking.value = null;
  splitSelectedIds.value = [];
  splitTargetId.value = null;
  splitTargetSearch.value = '';
  splitTargets.value = [];
  resetSplitSeatState();
}

function resetSplitSeatState() {
  splitSeatMap.value = null;
  splitSeatMapError.value = '';
  splitSeatMapLoading.value = false;
  activeSplitPassengerId.value = null;
  Object.keys(splitSeatAssignments).forEach((key) => delete splitSeatAssignments[key]);
}

// ที่นั่งของผู้โดยสารคนนี้ — จับจากชื่อก่อน ไม่เจอค่อยใช้ลำดับ (ตรงกับฝั่ง backend)
function splitSeatOf(passenger) {
  const seats = splitBooking.value?.seats || [];
  const byName = seats.find((seat) => seat.passenger_name && seat.passenger_name === passenger.name);
  if (byName) return byName.seat_id;

  const index = splitPassengers.value.findIndex((p) => p.id === passenger.id);
  return seats[index]?.seat_id || '';
}

async function fetchSplitTargets() {
  splitTargetsLoading.value = true;
  try {
    const res = await api.get('/admin/schedules', {
      params: { upcoming: 1, per_page: 100, search: splitTargetSearch.value || undefined },
    });
    splitTargets.value = res.data?.data || [];
  } catch (e) {
    splitTargets.value = [];
    toast.error('โหลดรอบเดินทางปลายทางไม่ได้');
  } finally {
    splitTargetsLoading.value = false;
  }
}

async function fetchSplitSeatMap() {
  if (!splitTargetId.value) {
    resetSplitSeatState();
    return;
  }

  splitSeatMapLoading.value = true;
  splitSeatMapError.value = '';
  try {
    const res = await api.get(`/schedules/${splitTargetId.value}/seats`);
    splitSeatMap.value = res.data?.data || null;
  } catch (e) {
    splitSeatMap.value = null;
    splitSeatMapError.value = e.response?.data?.message || 'โหลดผังที่นั่งของรอบปลายทางไม่ได้';
  } finally {
    splitSeatMapLoading.value = false;
  }

  initializeSplitSeatAssignments();
}

// ที่นั่งเดิมว่างในรอบใหม่ → จองเบอร์เดิมให้เลย ไม่งั้นปล่อยว่างให้แอดมินกดเลือกบนผัง
function initializeSplitSeatAssignments() {
  const used = new Set();

  splitSeatRows.value.forEach((row) => {
    const current = splitSeatAssignments[row.passenger.id];
    if (current && isSplitSeatAvailable(current) && !used.has(current)) {
      used.add(current);
      return;
    }

    if (row.originalSeatAvailable && !used.has(row.originalSeatId)) {
      splitSeatAssignments[row.passenger.id] = row.originalSeatId;
      used.add(row.originalSeatId);
    } else {
      splitSeatAssignments[row.passenger.id] = '';
    }
  });

  // ทิ้งที่นั่งของคนที่ถูกเอาออกจากรายการย้ายแล้ว
  Object.keys(splitSeatAssignments).forEach((passengerId) => {
    if (!splitSeatRows.value.some((row) => String(row.passenger.id) === String(passengerId))) {
      delete splitSeatAssignments[passengerId];
    }
  });

  // โฟกัสคนแรกที่ยังไม่มีที่นั่ง เพื่อให้กดผังได้เลย
  if (!splitSeatRows.value.some((row) => row.passenger.id === activeSplitPassengerId.value)) {
    activeSplitPassengerId.value = splitSeatRows.value.find((row) => !splitSeatAssignments[row.passenger.id])?.passenger.id
      || splitSeatRows.value[0]?.passenger.id
      || null;
  }
}

function isSplitSeatAvailable(seatId) {
  const seat = (splitSeatMap.value?.seats || []).find((item) => item.id === seatId);
  return Boolean(seat && seat.status === 'available');
}

function splitSeatAssignedName(seatId) {
  const passengerId = Object.entries(splitSeatAssignments).find(([, assigned]) => assigned === seatId)?.[0];
  if (!passengerId) return '';
  return splitSeatRows.value.find((row) => String(row.passenger.id) === String(passengerId))?.passenger.name || '';
}

function canSelectSplitSeat(seat) {
  if (!seat || !isSplitSeatAvailable(seat.id) || !activeSplitPassenger.value) return false;
  // ที่นั่งที่จองให้คนอื่นไปแล้วกดซ้ำไม่ได้ ยกเว้นเป็นที่นั่งของคนที่กำลังเลือกอยู่
  const takenBy = splitSeatAssignedName(seat.id);
  return !takenBy || splitSeatAssignments[activeSplitPassenger.value.id] === seat.id;
}

function splitSeatButtonClass(seat) {
  const takenBy = splitSeatAssignedName(seat.id);
  const available = isSplitSeatAvailable(seat.id);

  return {
    available: available && !takenBy,
    booked: !available,
    selected: Boolean(takenBy),
    active: Boolean(activeSplitPassenger.value) && splitSeatAssignments[activeSplitPassenger.value.id] === seat.id,
  };
}

function splitSeatTitle(seat) {
  if (!isSplitSeatAvailable(seat.id)) {
    return seat.passenger_name ? `จองแล้วโดย ${seat.passenger_name}` : 'ที่นั่งไม่ว่าง';
  }

  const takenBy = splitSeatAssignedName(seat.id);
  if (takenBy) return `เลือกให้ ${takenBy}`;
  if (activeSplitPassenger.value) return `เลือกที่นั่ง ${seat.label || seat.id} ให้ ${activeSplitPassenger.value.name}`;
  return 'เลือกผู้โดยสารก่อน';
}

function assignSplitSeat(seat) {
  if (!canSelectSplitSeat(seat)) return;

  splitSeatAssignments[activeSplitPassenger.value.id] = seat.id;
  // เลื่อนโฟกัสไปคนถัดไปที่ยังไม่มีที่นั่ง เพื่อกดรัวได้
  activeSplitPassengerId.value = splitSeatRows.value.find((row) => !splitSeatAssignments[row.passenger.id])?.passenger.id
    || activeSplitPassenger.value.id;
}

function isSplitTargetDisabled(sch) {
  if (!splitSelectedIds.value.length) return true;
  if (Number(sch.id) === Number(splitBooking.value?.schedule?.id)) return true;
  if (splitBooking.value?.is_join_trip) return false;
  return Number(sch.available_seats || 0) < splitSelectedIds.value.length;
}

async function doSplitMove() {
  const sourceScheduleId = splitBooking.value?.schedule?.id;
  if (!sourceScheduleId || !splitTargetId.value || !splitSelectedIds.value.length) return;

  const target = splitTargets.value.find((s) => Number(s.id) === Number(splitTargetId.value));
  const summary = `${splitSelectedIds.value.length} คน → ${target?.trip?.title || 'รอบที่เลือก'} ${formatScheduleRange(target)}`;
  const note = splitIsPartial.value ? '\n\nจะแยกเป็นการจองใบใหม่ (เลขที่จองและ QR ใหม่)' : '';
  if (!confirm(`ยืนยันการย้ายผู้โดยสาร ${summary} ใช่หรือไม่?${note}\n\nการดำเนินการนี้เปลี่ยนข้อมูลถาวร`)) return;

  const seatAssignments = {};
  splitSeatRows.value.forEach((row) => {
    const seatId = splitSeatAssignments[row.passenger.id];
    if (seatId) seatAssignments[row.passenger.id] = seatId;
  });

  splitSubmitting.value = true;
  try {
    const res = await api.post('/admin/schedules/move-bookings', {
      source_schedule_id: sourceScheduleId,
      target_schedule_id: splitTargetId.value,
      passenger_ids: splitSelectedIds.value,
      seat_assignments: seatAssignments,
    });
    const movedRef = splitBooking.value?.booking_ref;
    closeSplitModal();
    if (detailBooking.value?.booking_ref === movedRef) {
      await openDetail({ booking_ref: movedRef });
    }
    await fetchData(currentPage.value);
    toast.success(res.data?.message || 'ย้ายผู้โดยสารสำเร็จ');
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการย้ายผู้โดยสาร');
  } finally {
    splitSubmitting.value = false;
  }
}

watch(splitTargetId, fetchSplitSeatMap);
watch(splitSelectedIds, () => {
  // ลดจำนวนคนแล้วรอบปลายทางอาจที่นั่งไม่พอ — ล้างตัวเลือกที่ใช้ไม่ได้ทิ้ง
  const target = splitTargets.value.find((s) => Number(s.id) === Number(splitTargetId.value));
  if (target && isSplitTargetDisabled(target)) splitTargetId.value = null;
  else if (splitSeatMap.value) initializeSplitSeatAssignments();
  else if (splitTargetId.value) fetchSplitSeatMap();
});

let splitSearchTimer = null;
watch(splitTargetSearch, () => {
  clearTimeout(splitSearchTimer);
  splitSearchTimer = setTimeout(fetchSplitTargets, 300);
});

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

.mini-badge.gift,
.type-badge.gift {
  background: #fdf2f8;
  color: #be185d;
  border: 1px solid #fbcfe8;
}

.gift-note {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: 12px;
  background: #fdf2f8;
  border: 1px solid #fbcfe8;
  color: #9d174d;
  font-size: 13px;
  line-height: 1.6;
}

.gift-note .material-symbols-rounded {
  font-size: 20px;
  color: #be185d;
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

/* จุดรับ/ที่นั่งรายคน */
.pax-pickup {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--color-sand-dark);
}

.pax-pickup-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.pax-pickup-head strong {
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 900;
}

.pax-pickup-note {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 0 0 12px;
  padding: 8px 12px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-sand);
  color: var(--color-text-mid);
  font-size: 12px;
  font-weight: 700;
  line-height: 1.5;
}

.pax-pickup-note .material-symbols-rounded {
  font-size: 16px !important;
  color: var(--color-text-muted);
}

.pax-pickup-empty {
  padding: 14px;
  border: 1px dashed var(--color-sand-dark);
  border-radius: 8px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
  text-align: center;
}

.pax-pickup-list {
  display: grid;
  gap: 10px;
}

.pax-pickup-row {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) minmax(0, 2fr) 110px;
  align-items: end;
  gap: 10px;
  padding: 10px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
}

.pax-pickup-who {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  padding-bottom: 8px;
}

.pax-pickup-no {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-sand);
  color: var(--color-text-mid);
  font-size: 11px;
  font-weight: 900;
}

.pax-pickup-name {
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pax-pickup-name small {
  color: var(--color-text-muted);
  font-weight: 700;
}

.pax-pickup-warning {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  color: #dc2626;
  font-size: 12px;
  font-weight: 800;
}

.pax-pickup-warning .material-symbols-rounded {
  font-size: 16px !important;
}

@media (max-width: 720px) {
  .pax-pickup-row {
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .pax-pickup-who {
    padding-bottom: 0;
  }
}

/* อุปกรณ์เช่าในฟอร์มแก้ไขการจอง */
.rental-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.rental-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 999px;
  background: var(--color-white);
  color: var(--color-text-mid);
  font-size: 12px;
  font-weight: 800;
  cursor: pointer;
}

.rental-chip:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.rental-chip .material-symbols-rounded {
  font-size: 16px !important;
}

.rental-chip-price {
  color: var(--color-text-muted);
  font-weight: 700;
}

.rental-rows {
  display: grid;
  gap: 10px;
}

.rental-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
}

.rental-thumb {
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  border-radius: 6px;
  object-fit: cover;
  background: var(--color-sand);
}

.rental-thumb.placeholder {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-muted);
}

.rental-fields {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.rental-fields > input {
  flex: 1;
  min-width: 0;
}

.rental-price {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.rental-price label {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 700;
}

.rental-price input {
  width: 90px;
}

.rental-qty {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  overflow: hidden;
}

.rental-qty button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 32px;
  border: 0;
  background: var(--color-sand);
  color: var(--color-text-mid);
  cursor: pointer;
}

.rental-qty button:disabled {
  color: var(--color-text-muted);
  cursor: not-allowed;
}

.rental-qty .material-symbols-rounded {
  font-size: 16px !important;
}

.rental-qty input {
  width: 46px;
  height: 32px;
  border: 0 !important;
  border-radius: 0 !important;
  text-align: center;
  font-weight: 800;
}

.rental-line-total {
  flex-shrink: 0;
  min-width: 82px;
  text-align: right;
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 900;
}

.rental-remove {
  flex-shrink: 0;
  border: 0;
  background: none;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: 4px;
}

.rental-remove:hover {
  color: #dc2626;
}

.rental-empty {
  padding: 14px;
  border: 1px dashed var(--color-sand-dark);
  border-radius: 8px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
  text-align: center;
}

.rental-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 12px;
}

.rental-total {
  display: flex;
  align-items: baseline;
  gap: 8px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.rental-total strong {
  color: var(--color-text-dark);
  font-size: 15px;
  font-weight: 900;
}

@media (max-width: 640px) {
  .rental-row {
    flex-wrap: wrap;
  }

  .rental-fields {
    flex-basis: 100%;
    order: 1;
  }

  .rental-line-total {
    flex: 1;
    text-align: left;
  }
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

  .transfer-flow {
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .transfer-arrow {
    padding: 0;
    justify-self: center;
    transform: rotate(90deg);
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

.transfer-body {
  display: flex;
  flex-direction: column;
}

/* From → to summary */
.transfer-flow {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: end;
  gap: 10px;
  margin-bottom: 18px;
}

.transfer-party {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.transfer-party-label {
  font-size: 11px;
  font-weight: 800;
  color: var(--color-text-muted);
}

.transfer-party-card {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  padding: 10px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #f8fafc;
}

.transfer-party-card.pending {
  border-style: dashed;
  background: #fff;
}

.transfer-party-card.chosen {
  border-color: #6ee7b7;
  background: #f0fdf4;
}

.transfer-arrow {
  color: #94a3b8;
  font-size: 20px;
  padding-bottom: 20px;
}

.tu-avatar {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}

.tu-avatar.fallback {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #e2e8f0;
  color: #475569;
  font-size: 14px;
  font-weight: 800;
}

.tu-avatar.fallback.accent {
  background: var(--color-accent);
  color: #fff;
  border-color: transparent;
}

.tu-avatar.empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f1f5f9;
  color: #94a3b8;
}

.tu-avatar.empty .material-symbols-rounded { font-size: 20px; }

.tu-info {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
  flex: 1;
}

.tu-info strong {
  font-size: 13px;
  font-weight: 800;
  color: var(--color-text-dark);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tu-info span {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tu-placeholder { font-style: italic; }

.tu-clear {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: #059669;
  cursor: pointer;
  flex-shrink: 0;
}

.tu-clear:hover { background: #dcfce7; }
.tu-clear .material-symbols-rounded { font-size: 16px; }

/* Search */
.transfer-label {
  display: block;
  font-size: 12px;
  font-weight: 800;
  color: var(--color-text-dark);
  margin-bottom: 6px;
}

.transfer-search-row {
  position: relative;
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}

.transfer-search-row input {
  width: 100%;
  padding: 10px 38px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  font-size: 14px;
  color: #111827;
}

.transfer-search-row input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12);
}

.transfer-search-row .search-icon {
  position: absolute;
  left: 10px;
  font-size: 18px;
  color: #94a3b8;
  pointer-events: none;
}

.transfer-search-row .search-spinner {
  position: absolute;
  right: 11px;
  font-size: 18px;
  color: var(--color-accent);
}

.transfer-search-row .search-clear {
  position: absolute;
  right: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: #94a3b8;
  cursor: pointer;
}

.transfer-search-row .search-clear:hover { background: #f1f5f9; color: #475569; }
.transfer-search-row .search-clear .material-symbols-rounded { font-size: 16px; }

.transfer-error {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 10px;
  padding: 10px 12px;
  color: #b91c1c;
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 12px;
}

.transfer-error .material-symbols-rounded { font-size: 18px; }

/* Result list */
.transfer-results {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 260px;
  overflow-y: auto;
  margin-bottom: 12px;
}

.transfer-result {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 10px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}

.transfer-result:hover:not(:disabled) { background: #f8fafc; border-color: #cbd5e1; }

.transfer-result.active {
  border-color: var(--color-accent);
  background: #f0fdf4;
  box-shadow: 0 0 0 1px var(--color-accent);
}

.transfer-result.self { opacity: 0.55; cursor: not-allowed; }

.tu-tag {
  flex-shrink: 0;
  padding: 3px 9px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}

.tu-tag.muted { background: #f8fafc; color: #94a3b8; }
.tu-tag.warn { background: #fffbeb; color: #b45309; }

.tu-check {
  color: var(--color-accent);
  font-size: 20px;
  flex-shrink: 0;
}

.transfer-hint {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0 0 12px;
  padding: 12px;
  border: 1px dashed #e2e8f0;
  border-radius: 10px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 600;
}

.transfer-hint .material-symbols-rounded { font-size: 17px; color: #94a3b8; }
.transfer-hint.empty { color: #b45309; border-color: #fde68a; background: #fffbeb; }
.transfer-hint.empty .material-symbols-rounded { color: #d97706; }

/* ── แยก / ย้ายผู้โดยสารบางคน ─────────────────────────────── */
.btn-split {
  color: #0f766e;
}

.btn-split:hover:not(:disabled) {
  background: #f0fdfa;
}

.split-body {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.split-passenger-list,
.split-target-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 10px;
}

.split-target-list {
  max-height: 260px;
  overflow-y: auto;
}

.split-passenger-item,
.split-target-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.split-passenger-item:hover,
.split-target-item:not(.disabled):hover {
  border-color: var(--color-accent);
}

.split-target-item.disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.split-passenger-name {
  font-weight: 800;
}

.split-passenger-meta,
.split-target-seats {
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 12px;
}

.split-target-group {
  margin-bottom: 10px;
}

.split-target-trip {
  margin-bottom: 6px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 800;
}

.split-target-search {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  padding: 0 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.split-target-search .material-symbols-rounded {
  color: #94a3b8;
  font-size: 18px;
}

.split-target-search input {
  flex: 1;
  border: none;
  padding: 10px 0;
  font-size: 13px;
  font-weight: 600;
  outline: none;
  background: transparent;
}

/* ผังที่นั่งของรอบปลายทาง — เลือกคนทางซ้าย แล้วกดที่นั่งบนผัง */
.split-seat-layout {
  display: grid;
  grid-template-columns: minmax(200px, 260px) minmax(0, 1fr);
  gap: 14px;
  align-items: start;
  margin-top: 10px;
}

.split-seat-passengers {
  display: grid;
  gap: 8px;
}

.split-seat-person {
  display: grid;
  gap: 3px;
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #ffffff;
  text-align: left;
  cursor: pointer;
}

.split-seat-person:hover,
.split-seat-person.active {
  border-color: var(--color-accent);
  background: #f0faf4;
}

.split-seat-person.assigned:not(.active) {
  background: #f8fafc;
}

.split-seat-person-name {
  color: #111827;
  font-size: 13px;
  font-weight: 900;
}

.split-seat-person-meta {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 700;
}

.split-seat-person strong {
  color: var(--color-accent);
  font-size: 17px;
  font-weight: 900;
}

.split-seat-map {
  min-width: 0;
}

.split-seat-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 10px;
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.split-seat-legend span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.split-legend-box {
  width: 14px;
  height: 14px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
}

.split-legend-box.available { background: #ffffff; }
.split-legend-box.selected { background: var(--color-accent); border-color: var(--color-accent); }
.split-legend-box.booked { background: #d1d5db; }

.split-seat-vehicle {
  overflow-x: auto;
  padding: 16px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #ffffff;
}

.split-seat-front,
.split-seat-rear {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 900;
  text-transform: uppercase;
}

.split-seat-front {
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px dashed #d1d5db;
}

.split-seat-rear {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px dashed #d1d5db;
}

.split-seat-driver {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
}

.split-seat-driver .material-symbols-rounded { font-size: 15px; }

.split-seat-grid {
  display: grid;
  gap: 8px;
  justify-content: center;
  min-width: max-content;
}

.split-seat-button {
  display: grid;
  place-items: center;
  gap: 1px;
  width: 58px;
  min-height: 62px;
  padding: 6px 4px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #ffffff;
  color: #4b5563;
  cursor: pointer;
}

.split-seat-button:hover:not(:disabled) {
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.split-seat-button .material-symbols-rounded { font-size: 20px; }

.split-seat-button strong {
  font-size: 11px;
  font-weight: 900;
  line-height: 1;
}

.split-seat-button small {
  max-width: 48px;
  overflow: hidden;
  color: inherit;
  font-size: 8px;
  font-weight: 800;
  line-height: 1.1;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.split-seat-button.selected {
  border-color: var(--color-accent);
  background: var(--color-accent);
  color: #ffffff;
}

.split-seat-button.active {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}

.split-seat-button.booked,
.split-seat-button:disabled {
  border-color: #d1d5db;
  background: #e5e7eb;
  color: #9ca3af;
  cursor: not-allowed;
}

.split-seat-aisle {
  width: 34px;
  min-height: 62px;
}

.split-seat-empty {
  width: 58px;
  min-height: 62px;
}

@media (max-width: 720px) {
  .split-seat-layout {
    grid-template-columns: 1fr;
  }
}

.field-error {
  margin: 8px 0 0;
  color: #b91c1c;
  font-size: 12px;
  font-weight: 700;
}

.split-warning {
  display: flex;
  gap: 10px;
  padding: 12px;
  border: 1px solid #fef3c7;
  border-radius: 10px;
  background: #fffbeb;
  color: #92400e;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.6;
}

.split-warning .material-symbols-rounded {
  font-size: 18px;
  flex-shrink: 0;
}
</style>
