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
        <button class="btn-secondary" :disabled="!visibleManifestCount" @click="exportVisibleInsurancePdf">
          <span class="material-symbols-rounded">picture_as_pdf</span>
          PDF รายชื่อประกัน
        </button>
        <button class="btn-secondary" :disabled="!visibleManifestCount" @click="exportVisibleCsv">
          <span class="material-symbols-rounded">table_view</span>
          Excel รายชื่อ
        </button>
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
        <span class="summary-icon people material-symbols-rounded">groups</span>
        <div>
          <span class="summary-label">ผู้เดินทางรวม</span>
          <strong class="summary-value">{{ visibleStats.totalPassengers }} คน</strong>
          <span class="summary-subvalue">{{ formatCurrency(visibleStats.totalAmount) }}</span>
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
          <span class="summary-label">ต้องติดตาม</span>
          <strong class="summary-value">{{ visibleStats.attentionSchedules }}</strong>
          <span class="summary-subvalue">ใกล้เต็มหรือเต็ม</span>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon money material-symbols-rounded">payments</span>
        <div>
          <span class="summary-label">ทริปปกติ</span>
          <strong class="summary-value">{{ visibleStats.regularPassengers }} คน</strong>
          <span class="summary-subvalue">{{ formatCurrency(visibleStats.regularAmount) }}</span>
        </div>
      </div>
      <div class="summary-card">
        <span class="summary-icon join material-symbols-rounded">group_add</span>
        <div>
          <span class="summary-label">จอยทริป</span>
          <strong class="summary-value">{{ visibleStats.joinTripPassengers }} คน</strong>
          <span class="summary-subvalue">{{ formatCurrency(visibleStats.joinTripAmount) }}</span>
        </div>
      </div>
    </div>

    <div class="filters-panel">
      <div class="filters-bar overview-filters">
        <div class="filter-field search-field">
          <span class="filter-label">ค้นหา</span>
          <div class="search-box wide">
          <span class="material-symbols-rounded">search</span>
          <input v-model.trim="filters.search" placeholder="ค้นหาชื่อทริป ยานพาหนะ หรือจุดรับ..." />
          </div>
        </div>

        <label class="filter-field">
          <span class="filter-label">สถานะ</span>
          <select v-model="filters.status" aria-label="สถานะรอบเดินทาง">
            <option value="">ทุกสถานะ</option>
            <option value="open">เปิดรับจอง</option>
            <option value="full">เต็ม</option>
            <option value="closed">ปิด</option>
            <option value="cancelled">ยกเลิก</option>
          </select>
        </label>

        <label class="filter-field">
          <span class="filter-label">ภูมิภาค</span>
          <select v-model="filters.region" aria-label="ภูมิภาค">
            <option value="">ทุกภูมิภาค</option>
            <option v-for="region in regionOptions" :key="region.value" :value="region.value">
              {{ region.label }}
            </option>
          </select>
        </label>

        <label class="filter-field">
          <span class="filter-label">ช่วงวันที่</span>
          <select v-model="filters.dateRange" aria-label="ช่วงวันที่">
            <option value="upcoming">กำลังจะถึง</option>
            <option value="today">วันนี้</option>
            <option value="week">7 วันข้างหน้า</option>
            <option value="month">30 วันข้างหน้า</option>
            <option value="all">ทั้งหมดที่โหลดมา</option>
          </select>
        </label>

        <label class="filter-field">
          <span class="filter-label">เรียงตาม</span>
          <select v-model="filters.sortBy" aria-label="เรียงลำดับ">
            <option value="date">วันเดินทางเร็วสุด</option>
            <option value="available">ที่นั่งว่างน้อยสุด</option>
            <option value="booked">จองมากสุด</option>
            <option value="price">ราคาต่ำสุด</option>
          </select>
        </label>

        <label class="toggle-filter" :class="{ active: filters.attentionOnly }">
          <input v-model="filters.attentionOnly" type="checkbox" />
          <span class="material-symbols-rounded">priority_high</span>
          ต้องติดตาม
        </label>

        <label class="toggle-filter" :class="{ active: filters.withPassengersOnly }">
          <input v-model="filters.withPassengersOnly" type="checkbox" />
          <span class="material-symbols-rounded">shield_person</span>
          มีรายชื่อ
        </label>

        <button class="btn-secondary compact" :disabled="!hasActiveFilters" @click="resetFilters">
          <span class="material-symbols-rounded">filter_alt_off</span>
          ล้างตัวกรอง
        </button>
      </div>

      <div class="filter-footnote">
        แสดง {{ visibleStats.totalSchedules }} จาก {{ allStats.totalSchedules }} รอบ
        <span v-if="visibleStats.totalSchedules">
          รวม {{ visibleStats.totalTrips }} ทริปใน {{ visibleStats.totalRegions }} ภูมิภาค · เปิดรับจอง {{ visibleStats.openSchedules }} รอบ
        </span>
      </div>

      <div v-if="activeFilterChips.length" class="active-filter-list" aria-label="ตัวกรองที่ใช้งานอยู่">
        <button
          v-for="chip in activeFilterChips"
          :key="chip.key"
          class="filter-chip"
          type="button"
          @click="removeFilter(chip.key)"
        >
          <span>{{ chip.label }}</span>
          <span class="material-symbols-rounded">close</span>
        </button>
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

      <div v-if="!groupedDaySchedules.length" class="empty-card overview-empty">
        <span class="material-symbols-rounded">event_busy</span>
        <p v-if="hasActiveFilters">ไม่พบรอบเดินทางที่ตรงกับเงื่อนไข</p>
        <p v-else>ไม่พบข้อมูลรอบเดินทางในระบบขณะนี้</p>
        <button v-if="hasActiveFilters" class="btn-secondary compact" type="button" @click="resetFilters">
          <span class="material-symbols-rounded">filter_alt_off</span>
          ล้างตัวกรอง
        </button>
      </div>

      <section v-for="day in groupedDaySchedules" :key="day.date_key" class="day-block">
        <div class="day-header">
          <div class="day-title">
            <span class="material-symbols-rounded">calendar_month</span>
            <div>
              <h2>{{ day.date_label }}</h2>
              <p>{{ day.trips.length }} ทริป, {{ day.schedule_count }} รอบ, {{ day.region_count }} ภูมิภาค</p>
            </div>
          </div>
          <div class="day-metrics">
            <span>รวม {{ day.total_passengers }} คน</span>
            <span>ปกติ {{ day.regular_passengers }} คน</span>
            <span>จอยทริป {{ day.join_trip_passengers }} คน</span>
            <span>ว่าง {{ day.available_seats }} ที่</span>
          </div>
        </div>

        <div v-for="trip in day.trips" :key="`${day.date_key}-${trip.trip_id}`" class="trip-section">
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
              <span>ไป {{ trip.total_passengers }} คน · {{ formatCurrency(trip.total_amount) }}</span>
              <button
                class="btn-export-mini"
                :disabled="!trip.total_passengers"
                title="ส่งออกรายชื่อทุกรอบของทริปนี้เป็น Excel"
                @click="exportTripCsv(trip)"
              >
                <span class="material-symbols-rounded">table_view</span>
                Excel
              </button>
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
                <div class="card-badges">
                  <span class="status-badge" :class="`status-${sch.status}`">
                    {{ statusLabels[sch.status] || sch.status || '-' }}
                  </span>
                  <span class="availability-badge" :class="availabilityClass(sch)">
                    {{ availabilityLabel(sch) }}
                  </span>
                </div>
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
                  <div class="progress-fill" :class="progressClass(sch)" :style="{ width: seatFillWidth(sch) }"></div>
                </div>
                <div class="seat-breakdown">
                  <span>จองแล้ว {{ safeNumber(sch.booked_seats) }}</span>
                  <span>ยืนยัน {{ safeNumber(sch.confirmed_bookings) }}</span>
                  <span>รอดำเนินการ {{ safeNumber(sch.pending_bookings) }}</span>
                </div>
              </div>

              <div class="booking-summary-grid">
                <div class="booking-summary-card">
                  <span class="booking-summary-label">ทริปปกติ</span>
                  <strong>{{ getRegularPassengers(sch) }} คน</strong>
                  <span>{{ formatCurrency(getRegularAmount(sch)) }}</span>
                </div>
                <div class="booking-summary-card join">
                  <span class="booking-summary-label">จอยทริป</span>
                  <strong>{{ getJoinTripPassengers(sch) }} คน</strong>
                  <span>{{ formatCurrency(getJoinTripAmount(sch)) }}</span>
                </div>
              </div>

              <div class="manifest-preview">
                <div class="manifest-preview-head">
                  <div>
                    <span class="manifest-kicker">รายชื่อผู้เดินทาง</span>
                    <strong>{{ schedulePassengerCount(sch) }} คน</strong>
                  </div>
                  <div class="manifest-preview-actions">
                    <button
                      class="btn-export-mini"
                      :disabled="!schedulePassengerCount(sch)"
                      @click="exportScheduleInsurancePdf(sch)"
                    >
                      <span class="material-symbols-rounded">picture_as_pdf</span>
                      PDF ประกัน
                    </button>
                    <button
                      class="btn-export-mini"
                      :disabled="!schedulePassengerCount(sch)"
                      @click="exportScheduleCsv(sch)"
                    >
                      <span class="material-symbols-rounded">table_view</span>
                      Excel
                    </button>
                  </div>
                </div>

                <div v-if="schedulePassengers(sch).length" class="manifest-name-list">
                  <div v-for="person in schedulePassengers(sch).slice(0, 6)" :key="person.id" class="manifest-name-row">
                    <span class="manifest-type-dot" :class="{ join: person.booking_type === 'join_trip' }"></span>
                    <span class="manifest-person-name">{{ fullPassengerName(person) }}</span>
                    <span class="manifest-person-meta">
                      {{ person.booking_type_label }}
                      <template v-if="person.seat_labels?.length"> · ที่นั่ง {{ person.seat_labels.join(', ') }}</template>
                    </span>
                  </div>
                  <div v-if="schedulePassengerCount(sch) > 6" class="manifest-more">
                    และอีก {{ schedulePassengerCount(sch) - 6 }} คน
                  </div>
                </div>
                <div v-else class="manifest-empty">
                  ยังไม่มีรายชื่อผู้เดินทางในรอบนี้
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
                  ดูรายละเอียด
                </button>
                <button class="btn-view-seats" :disabled="sch.status === 'cancelled'" @click="viewSeatLayout(sch)">
                  <span class="material-symbols-rounded">grid_view</span>
                  ดูผัง
                </button>
              </div>
            </article>
          </div>
        </div>
      </section>
    </div>

    <div class="modal-overlay" v-if="selectedSchedule && activeModal === 'details'" @click.self="closeModal">
      <div class="modal-card detail-modal">
        <div class="detail-header">
          <div class="detail-heading">
            <div class="detail-badges">
              <span class="status-badge" :class="`status-${selectedSchedule.status}`">
                {{ statusLabels[selectedSchedule.status] || '-' }}
              </span>
              <span class="availability-badge" :class="availabilityClass(selectedSchedule)">
                {{ availabilityLabel(selectedSchedule) }}
              </span>
              <span class="tsh-badge" :class="`badge-${selectedSchedule.trip_type || 'other'}`">
                {{ tripTypeLabels[selectedSchedule.trip_type] || 'อื่น ๆ' }}
              </span>
            </div>
            <h2 class="modal-title">{{ selectedSchedule.trip_title }}</h2>
            <div class="detail-meta">
              <span>
                <span class="material-symbols-rounded">calendar_month</span>
                {{ scheduleDateRange(selectedSchedule) }}
              </span>
              <span>
                <span class="material-symbols-rounded">{{ transportIcon(selectedSchedule.transport_type) }}</span>
                {{ selectedSchedule.vehicle || transportLabels[selectedSchedule.transport_type] || 'ไม่ระบุพาหนะ' }}
              </span>
              <span>
                <span class="material-symbols-rounded">public</span>
                {{ regionLabels[selectedSchedule.trip_region] || 'ไม่ระบุภาค' }}
              </span>
              <span>
                <span class="material-symbols-rounded">sell</span>
                {{ formatCurrency(selectedSchedule.price) }} / คน
              </span>
              <span class="detail-meta-id">รหัสรอบ #{{ selectedSchedule.id }}</span>
            </div>
          </div>
          <button class="modal-close" @click="closeModal">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="detail-kpis">
          <div class="kpi-tile">
            <span class="kpi-label">ที่นั่งว่าง</span>
            <strong class="kpi-value" :class="seatTextClass(selectedSchedule)">
              {{ safeNumber(selectedSchedule.available_seats) }}<em>/ {{ safeNumber(selectedSchedule.total_seats) }}</em>
            </strong>
            <div class="progress-track">
              <div class="progress-fill" :class="progressClass(selectedSchedule)" :style="{ width: seatFillWidth(selectedSchedule) }"></div>
            </div>
          </div>
          <div class="kpi-tile">
            <span class="kpi-label">ผู้เดินทาง</span>
            <strong class="kpi-value">{{ getTotalPassengers(selectedSchedule) }}<em>คน</em></strong>
            <span class="kpi-sub">
              ปกติ {{ getRegularPassengers(selectedSchedule) }} · จอยทริป {{ getJoinTripPassengers(selectedSchedule) }}
            </span>
          </div>
          <div class="kpi-tile">
            <span class="kpi-label">การจอง</span>
            <strong class="kpi-value">
              {{ safeNumber(selectedSchedule.confirmed_bookings) + safeNumber(selectedSchedule.pending_bookings) }}<em>รายการ</em>
            </strong>
            <span class="kpi-sub">
              ยืนยัน {{ safeNumber(selectedSchedule.confirmed_bookings) }} · รอ {{ safeNumber(selectedSchedule.pending_bookings) }}
            </span>
          </div>
          <div class="kpi-tile">
            <span class="kpi-label">ยอดเงินรวม</span>
            <strong class="kpi-value money-value">{{ formatCurrency(getTotalAmount(selectedSchedule)) }}</strong>
            <span class="kpi-sub">ชำระแล้ว {{ formatCurrency(detailPayments.paid) }}</span>
          </div>
          <div class="kpi-tile">
            <span class="kpi-label">ค้างชำระ</span>
            <strong class="kpi-value" :class="{ 'text-low': detailPayments.outstanding > 0 }">
              {{ formatCurrency(detailPayments.outstanding) }}
            </strong>
            <span class="kpi-sub">
              {{ detailPayments.unpaidBookings ? `${detailPayments.unpaidBookings} รายการยังไม่ครบ` : 'ชำระครบทุกรายการ' }}
            </span>
          </div>
        </div>

        <nav class="detail-tabs" role="tablist">
          <button
            v-for="tab in detailTabs"
            :key="tab.key"
            class="detail-tab"
            :class="{ active: detailTab === tab.key }"
            type="button"
            role="tab"
            :aria-selected="detailTab === tab.key"
            @click="detailTab = tab.key"
          >
            <span class="material-symbols-rounded">{{ tab.icon }}</span>
            {{ tab.label }}
            <span v-if="tab.count !== null" class="tab-count">{{ tab.count }}</span>
          </button>
        </nav>

        <div class="detail-body">
          <!-- ── ภาพรวม ─────────────────────────────────────────── -->
          <section v-if="detailTab === 'overview'" class="detail-panel overview-panel">
            <div class="panel-card">
              <h3 class="panel-title">
                <span class="material-symbols-rounded">info</span>
                ข้อมูลรอบเดินทาง
              </h3>
              <dl class="spec-list">
                <div class="spec-row">
                  <dt>ทริป</dt>
                  <dd>{{ selectedSchedule.trip_title }}</dd>
                </div>
                <div class="spec-row">
                  <dt>ประเภททริป</dt>
                  <dd>{{ tripTypeLabels[selectedSchedule.trip_type] || 'อื่น ๆ' }}</dd>
                </div>
                <div class="spec-row">
                  <dt>ภูมิภาค</dt>
                  <dd>{{ regionLabels[selectedSchedule.trip_region] || 'ไม่ระบุภาค' }}</dd>
                </div>
                <div class="spec-row">
                  <dt>สถานะรอบ</dt>
                  <dd>
                    <span class="status-badge" :class="`status-${selectedSchedule.status}`">
                      {{ statusLabels[selectedSchedule.status] || '-' }}
                    </span>
                  </dd>
                </div>
                <div class="spec-row">
                  <dt>วันเดินทาง</dt>
                  <dd>{{ formatDate(selectedSchedule.start, 'long') }}</dd>
                </div>
                <div class="spec-row">
                  <dt>วันกลับ</dt>
                  <dd>{{ selectedSchedule.end ? formatDate(selectedSchedule.end, 'long') : '-' }}</dd>
                </div>
                <div class="spec-row">
                  <dt>ระยะเวลา</dt>
                  <dd>{{ tripDurationDays(selectedSchedule) }} วัน</dd>
                </div>
                <div class="spec-row">
                  <dt>ประเภทยานพาหนะ</dt>
                  <dd>{{ transportLabels[selectedSchedule.transport_type] || '-' }}</dd>
                </div>
                <div class="spec-row">
                  <dt>ยานพาหนะ</dt>
                  <dd>{{ selectedSchedule.vehicle || 'ยังไม่กำหนด' }}</dd>
                </div>
                <div class="spec-row">
                  <dt>ราคาต่อคน</dt>
                  <dd class="money-value">{{ formatCurrency(selectedSchedule.price) }}</dd>
                </div>
                <div class="spec-row">
                  <dt>จอยทริป</dt>
                  <dd v-if="selectedSchedule.join_trip_enabled">
                    เปิดรับ ·
                    {{ selectedSchedule.join_trip_price ? `${formatCurrency(selectedSchedule.join_trip_price)} / คน` : 'ใช้ราคาปกติ' }}
                  </dd>
                  <dd v-else class="muted-value">ปิดรับ</dd>
                </div>
                <div class="spec-row">
                  <dt>รหัสรอบ</dt>
                  <dd class="muted-value">#{{ selectedSchedule.id }}</dd>
                </div>
              </dl>
            </div>

            <div class="panel-card">
              <h3 class="panel-title">
                <span class="material-symbols-rounded">event_seat</span>
                ที่นั่งและการจอง
              </h3>
              <div class="seat-figure">
                <div class="seat-figure-head">
                  <strong :class="seatTextClass(selectedSchedule)">
                    ว่าง {{ safeNumber(selectedSchedule.available_seats) }} ที่
                  </strong>
                  <span>จองแล้ว {{ seatFillPercent(selectedSchedule) }}% ของความจุ</span>
                </div>
                <div class="progress-track tall">
                  <div class="progress-fill" :class="progressClass(selectedSchedule)" :style="{ width: seatFillWidth(selectedSchedule) }"></div>
                </div>
              </div>
              <div class="mini-stats">
                <div class="mini-stat">
                  <span>ที่นั่งทั้งหมด</span>
                  <strong>{{ safeNumber(selectedSchedule.total_seats) }}</strong>
                </div>
                <div class="mini-stat">
                  <span>จองแล้ว</span>
                  <strong>{{ safeNumber(selectedSchedule.booked_seats) }}</strong>
                </div>
                <div class="mini-stat">
                  <span>ที่นั่งว่าง</span>
                  <strong class="seats-avail">{{ safeNumber(selectedSchedule.available_seats) }}</strong>
                </div>
                <div class="mini-stat">
                  <span>ยืนยันแล้ว</span>
                  <strong>{{ safeNumber(selectedSchedule.confirmed_bookings) }} รายการ</strong>
                </div>
                <div class="mini-stat">
                  <span>รอดำเนินการ</span>
                  <strong :class="{ 'text-low': safeNumber(selectedSchedule.pending_bookings) > 0 }">
                    {{ safeNumber(selectedSchedule.pending_bookings) }} รายการ
                  </strong>
                </div>
                <div class="mini-stat">
                  <span>เช็คอินแล้ว</span>
                  <strong>{{ detailCheckedInCount }} รายการ</strong>
                </div>
              </div>
            </div>

            <div class="panel-card span-full">
              <h3 class="panel-title">
                <span class="material-symbols-rounded">payments</span>
                สรุปยอดเงิน
              </h3>
              <table class="money-table">
                <thead>
                  <tr>
                    <th>ประเภทการจอง</th>
                    <th>ผู้เดินทาง</th>
                    <th>ยอดเงิน</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><span class="manifest-type-dot"></span>จองปกติ</td>
                    <td>{{ getRegularPassengers(selectedSchedule) }} คน</td>
                    <td>{{ formatCurrency(getRegularAmount(selectedSchedule)) }}</td>
                  </tr>
                  <tr>
                    <td><span class="manifest-type-dot join"></span>จอยทริป</td>
                    <td>{{ getJoinTripPassengers(selectedSchedule) }} คน</td>
                    <td>{{ formatCurrency(getJoinTripAmount(selectedSchedule)) }}</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <td>รวมทั้งหมด</td>
                    <td>{{ getTotalPassengers(selectedSchedule) }} คน</td>
                    <td class="money-value">{{ formatCurrency(getTotalAmount(selectedSchedule)) }}</td>
                  </tr>
                </tfoot>
              </table>
              <div class="pay-split">
                <div class="pay-chip paid">
                  <span>ชำระแล้ว</span>
                  <strong>{{ formatCurrency(detailPayments.paid) }}</strong>
                  <em>จาก {{ detailBookings.length }} การจอง</em>
                </div>
                <div class="pay-chip" :class="detailPayments.outstanding > 0 ? 'due' : 'paid'">
                  <span>ค้างชำระ</span>
                  <strong>{{ formatCurrency(detailPayments.outstanding) }}</strong>
                  <em>
                    {{ detailPayments.unpaidBookings ? `${detailPayments.unpaidBookings} การจองยังชำระไม่ครบ` : 'ไม่มียอดค้าง' }}
                  </em>
                </div>
                <div class="pay-chip">
                  <span>รายการเสริม</span>
                  <strong>{{ formatCurrency(scheduleAddonsTotal(selectedSchedule)) }}</strong>
                  <em>{{ scheduleAddonsItemCount(selectedSchedule) }} ชิ้น · รวมอยู่ในยอดจองแล้ว</em>
                </div>
              </div>
            </div>
          </section>

          <!-- ── ผู้เดินทาง ─────────────────────────────────────── -->
          <section v-else-if="detailTab === 'passengers'" class="detail-panel">
            <div class="manifest-toolbar">
              <div class="search-box">
                <span class="material-symbols-rounded">search</span>
                <input v-model.trim="manifestSearch" placeholder="ค้นหาชื่อ เลขจอง เบอร์โทร หรือเลขบัตร..." />
              </div>
              <div class="segmented">
                <button
                  v-for="option in manifestTypeOptions"
                  :key="option.value"
                  class="segment"
                  :class="{ active: manifestType === option.value }"
                  type="button"
                  @click="manifestType = option.value"
                >
                  {{ option.label }}
                  <span class="tab-count">{{ option.count }}</span>
                </button>
              </div>
              <div class="manifest-toolbar-actions">
                <button
                  class="btn-secondary compact"
                  :disabled="!schedulePassengerCount(selectedSchedule)"
                  @click="exportScheduleInsurancePdf(selectedSchedule)"
                >
                  <span class="material-symbols-rounded">picture_as_pdf</span>
                  PDF ประกัน
                </button>
                <button
                  class="btn-secondary compact"
                  :disabled="!schedulePassengerCount(selectedSchedule)"
                  @click="exportScheduleCsv(selectedSchedule)"
                >
                  <span class="material-symbols-rounded">table_view</span>
                  Excel รายชื่อ
                </button>
              </div>
            </div>

            <div v-if="filteredManifest.length" class="data-table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th class="col-index">#</th>
                    <th class="col-person">ผู้เดินทาง</th>
                    <th>ประเภท / ที่นั่ง</th>
                    <th>เลขบัตร/พาสปอร์ต</th>
                    <th>วันเกิด / อายุ</th>
                    <th>ติดต่อ</th>
                    <th>เลือด / น้ำหนัก</th>
                    <th v-if="showDiveColumn">ใบรับรองดำน้ำ</th>
                    <th>แพ้อาหาร / โรคประจำตัว</th>
                    <th>ติดต่อฉุกเฉิน</th>
                    <th>จุดรับ</th>
                    <th>การชำระเงิน</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(person, index) in filteredManifest" :key="person.id">
                    <td class="col-index">{{ index + 1 }}</td>
                    <td class="col-person">
                      <strong>
                        {{ fullPassengerName(person) }}
                        <em v-if="person.nickname" class="cell-nickname">({{ person.nickname }})</em>
                      </strong>
                      <div class="cell-chips">
                        <span class="ref-chip">{{ person.booking_ref }}</span>
                        <span class="status-badge" :class="`status-${person.booking_status}`">
                          {{ bookingStatusLabels[person.booking_status] || person.booking_status }}
                        </span>
                        <span v-if="person.checked_in" class="tiny-chip check">เช็คอินแล้ว</span>
                      </div>
                      <span v-if="person.customer_name" class="cell-owner">ผู้จอง: {{ person.customer_name }}</span>
                    </td>
                    <td>
                      <strong :class="{ 'join-text': person.booking_type === 'join_trip' }">{{ person.booking_type_label }}</strong>
                      <span v-if="person.seat_labels?.length">ที่นั่ง {{ person.seat_labels.join(', ') }}</span>
                      <span v-else-if="person.booking_type === 'join_trip'">ไม่ระบุที่นั่ง</span>
                      <span v-else>ยังไม่เลือกที่นั่ง</span>
                    </td>
                    <td>{{ person.id_card || '-' }}</td>
                    <td>
                      <template v-if="person.birth_date">
                        <strong>{{ formatBirthDate(person.birth_date) }}</strong>
                        <span v-if="person.age != null" class="age-pill">{{ person.age }} ปี</span>
                      </template>
                      <input
                        v-else
                        type="date"
                        class="birth-date-input"
                        :disabled="savingBirthId === person.id"
                        @change="saveBirthDate(person, $event)"
                      />
                    </td>
                    <td>
                      <strong>{{ person.phone || '-' }}</strong>
                      <span v-if="person.customer_phone && person.customer_phone !== person.phone">
                        ผู้จอง {{ person.customer_phone }}
                      </span>
                    </td>
                    <td>
                      <strong>{{ person.blood_group || '-' }}</strong>
                      <span>{{ person.weight ? `${person.weight} กก.` : 'ไม่ระบุน้ำหนัก' }}</span>
                    </td>
                    <td v-if="showDiveColumn">
                      <strong>{{ person.dive_cert_level || '-' }}</strong>
                      <span v-if="person.cert_number">เลขที่ {{ person.cert_number }}</span>
                    </td>
                    <td>
                      <span class="wrap-text">{{ healthSummary(person) }}</span>
                      <span v-if="person.halal_food" class="tiny-chip halal">อาหารฮาลาล</span>
                    </td>
                    <td><span class="wrap-text">{{ emergencySummary(person) }}</span></td>
                    <td>
                      <strong>{{ person.pickup_region || 'ยังไม่เลือกจุดรับ' }}</strong>
                      <span class="wrap-text">{{ person.pickup_location || '-' }}</span>
                      <span v-if="person.custom_pickup_note" class="wrap-text">หมายเหตุ: {{ person.custom_pickup_note }}</span>
                      <a
                        v-if="person.custom_pickup_map_url"
                        class="map-link"
                        :href="person.custom_pickup_map_url"
                        target="_blank"
                        rel="noopener"
                      >
                        <span class="material-symbols-rounded">map</span>
                        เปิดแผนที่
                      </a>
                    </td>
                    <td>
                      <strong>{{ paymentTypeLabels[person.payment_type] || person.payment_type || '-' }}</strong>
                      <span>{{ paymentMethodLabels[person.payment_method] || person.payment_method || 'ยังไม่ระบุช่องทาง' }}</span>
                      <span :class="{ 'text-low': safeNumber(person.total_amount) - safeNumber(person.paid_amount) > 0 }">
                        {{ formatCurrency(person.paid_amount) }} / {{ formatCurrency(person.total_amount) }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="panel-empty">
              <span class="material-symbols-rounded">person_off</span>
              <p v-if="schedulePassengerCount(selectedSchedule)">ไม่พบผู้เดินทางที่ตรงกับเงื่อนไข</p>
              <p v-else>ยังไม่มีรายชื่อผู้เดินทางสำหรับรอบนี้</p>
            </div>
          </section>

          <!-- ── จุดรับ ─────────────────────────────────────────── -->
          <section v-else-if="detailTab === 'pickups'" class="detail-panel">
            <div v-if="detailPickupPoints.length" class="pickup-card-grid">
              <article v-for="pt in detailPickupPoints" :key="pt.id" class="pickup-card">
                <div class="pickup-card-head">
                  <div>
                    <span class="pickup-card-region">{{ pt.region_label || regionLabels[pt.region] || 'ไม่ระบุภาค' }}</span>
                    <strong class="pickup-card-loc">{{ pt.pickup_location || '-' }}</strong>
                  </div>
                  <span class="pickup-card-price">{{ formatCurrency(pt.price) }}</span>
                </div>
                <div class="pickup-card-facts">
                  <span v-if="pt.pickup_time">
                    <span class="material-symbols-rounded">schedule</span>
                    {{ formatTime(pt.pickup_time) }} น.
                  </span>
                  <span>
                    <span class="material-symbols-rounded">group</span>
                    {{ pt.passengers.length }} คน
                  </span>
                  <a v-if="pt.map_url" class="map-link" :href="pt.map_url" target="_blank" rel="noopener">
                    <span class="material-symbols-rounded">map</span>
                    เปิดแผนที่
                  </a>
                </div>
                <p v-if="pt.notes" class="pickup-card-notes">{{ pt.notes }}</p>
                <div v-if="pt.passengers.length" class="pickup-card-people">
                  <span v-for="person in pt.passengers" :key="person.id" class="people-chip">
                    {{ fullPassengerName(person) }}
                  </span>
                </div>
                <p v-else class="pickup-card-empty">ยังไม่มีผู้เดินทางเลือกจุดรับนี้</p>
              </article>
            </div>
            <div v-else class="panel-empty">
              <span class="material-symbols-rounded">location_off</span>
              <p>ยังไม่มีข้อมูลจุดรับสำหรับรอบนี้</p>
            </div>

            <div v-if="customPickupPassengers.length" class="panel-card span-full">
              <h3 class="panel-title">
                <span class="material-symbols-rounded">where_to_vote</span>
                จุดรับที่ลูกค้าปักหมุดเอง
                <span class="tab-count">{{ customPickupPassengers.length }}</span>
              </h3>
              <div class="custom-pickup-list">
                <div v-for="person in customPickupPassengers" :key="person.id" class="custom-pickup-item">
                  <div>
                    <strong>{{ fullPassengerName(person) }}</strong>
                    <span class="ref-chip">{{ person.booking_ref }}</span>
                  </div>
                  <span class="custom-pickup-loc">{{ person.pickup_location }}</span>
                  <span v-if="person.custom_pickup_note" class="custom-pickup-note">{{ person.custom_pickup_note }}</span>
                  <a class="map-link" :href="person.custom_pickup_map_url" target="_blank" rel="noopener">
                    <span class="material-symbols-rounded">map</span>
                    {{ person.custom_pickup_lat }}, {{ person.custom_pickup_lng }}
                  </a>
                </div>
              </div>
            </div>

            <div v-if="noPickupPassengers.length" class="panel-note">
              <span class="material-symbols-rounded">help</span>
              มีผู้เดินทาง {{ noPickupPassengers.length }} คนที่ยังไม่ได้เลือกจุดรับ
            </div>
          </section>

          <!-- ── การชำระเงิน ────────────────────────────────────── -->
          <section v-else-if="detailTab === 'payments'" class="detail-panel">
            <div v-if="loadingPayments" class="panel-empty">
              <div class="spinner"></div>
              <p>กำลังโหลดข้อมูลการชำระเงิน...</p>
            </div>

            <div v-else-if="paymentsError" class="alert-card">
              <span class="material-symbols-rounded">error</span>
              <span>{{ paymentsError }}</span>
            </div>

            <div v-else-if="!schedulePaymentBookings.length" class="panel-empty">
              <span class="material-symbols-rounded">receipt_long</span>
              <p>ยังไม่มีการจองในรอบนี้</p>
            </div>

            <template v-else>
              <article v-for="booking in schedulePaymentBookings" :key="booking.id" class="payment-card">
                <div class="payment-card-head">
                  <div class="payment-card-who">
                    <strong>{{ booking.customer_name || 'ไม่ระบุชื่อผู้จอง' }}</strong>
                    <div class="cell-chips">
                      <span class="ref-chip">{{ booking.booking_ref }}</span>
                      <span class="status-badge" :class="`status-${booking.status}`">
                        {{ bookingStatusLabels[booking.status] || booking.status }}
                      </span>
                      <span class="tiny-chip type">{{ paymentTypeLabels[booking.payment_type] || booking.payment_type }}</span>
                      <span v-if="booking.is_join_trip" class="tiny-chip join">จอยทริป</span>
                    </div>
                    <span v-if="booking.customer_phone" class="payment-card-phone">{{ booking.customer_phone }}</span>
                  </div>
                  <div class="payment-card-figures">
                    <div>
                      <span>ยอดรวม</span>
                      <strong>{{ formatCurrency(booking.total_amount) }}</strong>
                    </div>
                    <div>
                      <span>ชำระแล้ว</span>
                      <strong class="money-value">{{ formatCurrency(booking.paid_amount) }}</strong>
                    </div>
                    <div>
                      <span>ค้างชำระ</span>
                      <strong :class="{ 'text-low': booking.outstanding_amount > 0 }">
                        {{ formatCurrency(booking.outstanding_amount) }}
                      </strong>
                    </div>
                  </div>
                </div>

                <div class="slip-row-list">
                  <div v-for="entry in booking.entries" :key="entry.key" class="slip-row">
                    <button
                      v-if="entry.slip_url"
                      class="slip-thumb"
                      type="button"
                      title="ดูสลิปขนาดเต็ม"
                      @click="openSlip(entry, booking)"
                    >
                      <img :src="entry.slip_url" :alt="`สลิป${entry.label}`" loading="lazy" />
                    </button>
                    <div v-else class="slip-thumb empty">
                      <span class="material-symbols-rounded">image_not_supported</span>
                    </div>

                    <div class="slip-row-info">
                      <div class="slip-row-title">
                        <strong>{{ entry.label }}</strong>
                        <span class="tiny-chip" :class="entry.status === 'paid' ? 'check' : 'wait'">
                          {{ entry.status === 'paid' ? 'ชำระแล้ว' : 'รอชำระ' }}
                        </span>
                        <span v-if="entry.slip_url" class="ocr-badge" :class="`ocr-${entry.slip_ocr_status || 'none'}`">
                          {{ ocrLabel(entry.slip_ocr_status) }}
                        </span>
                      </div>
                      <div class="slip-row-meta">
                        <span v-if="entry.due_date">ครบกำหนด {{ formatDate(entry.due_date) }}</span>
                        <span v-if="entry.transfer_datetime">โอนเมื่อ {{ formatDateTime(entry.transfer_datetime) }}</span>
                        <span v-else-if="entry.paid_at">บันทึกเมื่อ {{ formatDateTime(entry.paid_at) }}</span>
                        <span v-if="entry.payment_method">
                          {{ paymentMethodLabels[entry.payment_method] || entry.payment_method }}
                        </span>
                        <span v-if="!entry.slip_url" class="slip-missing">ไม่มีสลิปแนบ</span>
                      </div>
                    </div>

                    <div class="slip-row-actions">
                      <strong class="slip-row-amount">{{ formatCurrency(entry.amount) }}</strong>
                      <button v-if="entry.slip_url" class="btn-secondary compact" @click="openSlip(entry, booking)">
                        <span class="material-symbols-rounded">visibility</span>
                        ดูสลิป
                      </button>
                    </div>
                  </div>
                </div>
              </article>
            </template>
          </section>

          <!-- ── รายการเสริม ────────────────────────────────────── -->
          <section v-else class="detail-panel">
            <div v-if="scheduleAddons(selectedSchedule).length" class="addons-summary-block">
              <div class="addons-summary-head">
                <span class="material-symbols-rounded">add_shopping_cart</span>
                <div>
                  <span class="manifest-kicker">รายการเสริมที่ลูกค้าเลือก</span>
                  <strong>{{ scheduleAddonsItemCount(selectedSchedule) }} ชิ้น · รวม {{ formatCurrency(scheduleAddonsTotal(selectedSchedule)) }}</strong>
                </div>
              </div>
              <div class="addons-summary-list">
                <div v-for="addon in selectedSchedule.addons_summary" :key="addon.name" class="addons-summary-item">
                  <div class="addons-summary-row">
                    <div class="addons-summary-info">
                      <strong>{{ addon.name }}</strong>
                      <span class="addons-summary-meta">
                        {{ formatCurrency(addon.unit_price) }}
                        {{ addon.price_type === 'per_person' ? '/ คน' : '/ การจอง' }}
                      </span>
                    </div>
                    <span class="addons-summary-qty">× {{ addon.total_quantity }}</span>
                    <strong class="addons-summary-price">{{ formatCurrency(addon.total_price) }}</strong>
                  </div>
                  <div v-if="addon.customers?.length" class="addons-summary-customers">
                    <span
                      v-for="c in addon.customers"
                      :key="`${addon.name}-${c.booking_ref}`"
                      class="addons-customer-chip"
                    >
                      <span class="addons-customer-name">{{ c.name || '-' }}</span>
                      <span class="addons-customer-ref">{{ c.booking_ref }}</span>
                      <span v-if="c.quantity > 1" class="addons-customer-qty">× {{ c.quantity }}</span>
                    </span>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="panel-empty">
              <span class="material-symbols-rounded">remove_shopping_cart</span>
              <p>ยังไม่มีลูกค้าเลือกรายการเสริมในรอบนี้</p>
            </div>
          </section>
        </div>

        <div class="detail-actions">
          <span class="detail-actions-hint">
            ข้อมูลอัปเดตล่าสุด {{ lastUpdated || '-' }}
          </span>
          <div class="detail-actions-buttons">
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

    <div class="modal-overlay slip-overlay" v-if="slipPreview" @click.self="closeSlip">
      <div class="modal-card slip-viewer">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">สลิป{{ slipPreview.label }}</h2>
            <p class="modal-subtitle">
              {{ slipPreview.booking_ref }} · {{ slipPreview.customer_name || 'ไม่ระบุชื่อผู้จอง' }} · {{ formatCurrency(slipPreview.amount) }}
            </p>
          </div>
          <button class="modal-close" @click="closeSlip">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="slip-viewer-body">
          <img :src="slipPreview.slip_url" :alt="`สลิป${slipPreview.label}`" />
        </div>

        <div class="detail-actions">
          <span class="detail-actions-hint">
            <span v-if="slipPreview.transfer_datetime">โอนเมื่อ {{ formatDateTime(slipPreview.transfer_datetime) }}</span>
            <span v-else>ไม่ระบุเวลาโอน</span>
          </span>
          <div class="detail-actions-buttons">
            <a :href="slipPreview.slip_url" target="_blank" rel="noopener" class="btn-secondary">
              <span class="material-symbols-rounded">open_in_new</span>
              เปิดในแท็บใหม่
            </a>
            <button class="btn-primary" @click="closeSlip">ปิด</button>
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
            <span>จอยทริป {{ getJoinTripPassengers(selectedSchedule) }} คน</span>
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

    <div class="modal-overlay" v-if="exportPicker.open" @click.self="closeExportPicker">
      <div class="modal-card" style="max-width: 520px">
        <div class="modal-header">
          <div>
            <h2 class="modal-title">
              <span class="material-symbols-rounded" style="vertical-align:-4px;">table_view</span>
              ส่งออกรายชื่อเป็น Excel
            </h2>
            <p class="modal-subtitle">
              {{ exportPicker.title }} · {{ exportPickerPassengerCount() }} คน · เลือกรูปแบบคอลัมน์
            </p>
          </div>
          <button class="modal-close" @click="closeExportPicker">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="modal-body">
          <label class="export-format-option" :class="{ active: exportPicker.format === 1 }">
            <input type="radio" :value="1" v-model="exportPicker.format" />
            <div>
              <strong>แบบที่ 1 — ชื่อ</strong>
              <span>คำนำหน้า · ชื่อ-นามสกุล</span>
            </div>
          </label>
          <label class="export-format-option" :class="{ active: exportPicker.format === 2 }">
            <input type="radio" :value="2" v-model="exportPicker.format" />
            <div>
              <strong>แบบที่ 2 — ชื่อ + วันเกิด + เลขบัตร</strong>
              <span>คำนำหน้า · ชื่อ-นามสกุล · วันเกิด · เลขบัตรประชาชน</span>
            </div>
          </label>
          <label class="export-format-option" :class="{ active: exportPicker.format === 3 }">
            <input type="radio" :value="3" v-model="exportPicker.format" />
            <div>
              <strong>แบบที่ 3 — ชื่อ + อายุ</strong>
              <span>คำนำหน้า · ชื่อ-นามสกุล · อายุ</span>
            </div>
          </label>
          <p class="export-note">
            หมายเหตุ: รูปแบบที่มีวันเกิด/อายุ จะแสดง “ว่าง” สำหรับผู้เดินทางที่ยังไม่มีข้อมูลวันเกิด —
            กรอกเพิ่มได้ในตารางรายชื่อของแต่ละรอบ
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn-secondary" @click="closeExportPicker">ยกเลิก</button>
          <button class="btn-primary" :disabled="!exportPickerPassengerCount()" @click="exportSelectedCsv">
            <span class="material-symbols-rounded">download</span>
            ดาวน์โหลด CSV
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
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
  attentionOnly: false,
  withPassengersOnly: false,
});

const selectedSchedule = ref(null);
const activeModal = ref(null);
const seatData = ref(null);
const seatError = ref('');
const loadingSeats = ref(false);
const lastUpdated = ref('');

// Detail modal — tabbed so the full manifest can be shown without a scroll marathon.
const detailTab = ref('overview');
const manifestSearch = ref('');
const manifestType = ref('all');

// Payments tab — slip URLs are signed and short-lived, so they are fetched when
// the tab is first opened rather than shipped with the calendar payload.
const schedulePaymentBookings = ref([]);
const loadingPayments = ref(false);
const paymentsError = ref('');
const slipPreview = ref(null);

const statusLabels = {
  open: 'เปิดรับจอง',
  closed: 'ปิด',
  full: 'เต็ม',
  cancelled: 'ยกเลิก',
};

const bookingStatusLabels = {
  pending: 'รอดำเนินการ',
  confirmed: 'ยืนยันแล้ว',
  cancelled: 'ยกเลิก',
  refunded: 'คืนเงินแล้ว',
};

const paymentTypeLabels = {
  full: 'ชำระเต็มจำนวน',
  deposit: 'มัดจำ',
  installment: 'ผ่อนชำระ',
};

const paymentMethodLabels = {
  promptpay: 'พร้อมเพย์',
  mobile_banking: 'โอนผ่านแอปธนาคาร',
  bank_transfer: 'โอนธนาคาร',
  cash: 'เงินสด',
  card: 'บัตรเครดิต',
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
  return Boolean(
    filters.search ||
    filters.status ||
    filters.region ||
    filters.dateRange !== 'upcoming' ||
    filters.sortBy !== 'date' ||
    filters.attentionOnly ||
    filters.withPassengersOnly
  );
});

const allStats = computed(() => buildStats(schedules.value));
const visibleManifestCount = computed(() => filteredSchedules.value.reduce((total, sch) => total + schedulePassengerCount(sch), 0));
const activeFilterChips = computed(() => {
  const chips = [];

  if (filters.search) chips.push({ key: 'search', label: `ค้นหา: ${filters.search}` });
  if (filters.status) chips.push({ key: 'status', label: `สถานะ: ${statusLabels[filters.status] || filters.status}` });
  if (filters.region) chips.push({ key: 'region', label: `ภูมิภาค: ${regionLabels[filters.region] || filters.region}` });
  if (filters.dateRange !== 'upcoming') chips.push({ key: 'dateRange', label: `ช่วงวันที่: ${dateRangeLabels[filters.dateRange] || filters.dateRange}` });
  if (filters.sortBy !== 'date') chips.push({ key: 'sortBy', label: `เรียงตาม: ${sortLabels[filters.sortBy] || filters.sortBy}` });
  if (filters.attentionOnly) chips.push({ key: 'attentionOnly', label: 'ต้องติดตาม' });
  if (filters.withPassengersOnly) chips.push({ key: 'withPassengersOnly', label: 'มีรายชื่อผู้เดินทาง' });

  return chips;
});

const filteredSchedules = computed(() => {
  const query = normalizeText(filters.search);
  const today = startOfDay(new Date());

  return schedules.value
    .filter((sch) => {
      if (filters.status && sch.status !== filters.status) return false;
      if (filters.region && (sch.trip_region || 'other') !== filters.region) return false;
      if (filters.attentionOnly && !needsAttention(sch)) return false;
      if (filters.withPassengersOnly && !schedulePassengerCount(sch)) return false;

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

const groupedDaySchedules = computed(() => {
  const dayMap = {};

  filteredSchedules.value.forEach((sch) => {
    const dayKey = sch.start || 'unknown';
    const tripId = sch.trip_id || `trip-${sch.trip_title || 'unknown'}`;
    const regionKey = sch.trip_region || 'other';

    if (!dayMap[dayKey]) {
      dayMap[dayKey] = {
        date_key: dayKey,
        date_label: formatDate(dayKey, 'long'),
        trips: {},
        regions: new Set(),
        schedule_count: 0,
        available_seats: 0,
        booked_seats: 0,
        regular_passengers: 0,
        join_trip_passengers: 0,
        total_passengers: 0,
        total_amount: 0,
      };
    }

    if (!dayMap[dayKey].trips[tripId]) {
      dayMap[dayKey].trips[tripId] = {
        trip_id: tripId,
        trip_title: sch.trip_title || 'ไม่ระบุชื่อทริป',
        trip_type: sch.trip_type || 'other',
        trip_type_label: tripTypeLabels[sch.trip_type] || sch.trip_type || 'อื่น ๆ',
        schedules: [],
        available_seats: 0,
        booked_seats: 0,
        total_seats: 0,
        regular_passengers: 0,
        regular_amount: 0,
        join_trip_passengers: 0,
        join_trip_amount: 0,
        total_passengers: 0,
        total_amount: 0,
      };
    }

    const regularPassengers = getRegularPassengers(sch);
    const joinTripPassengers = getJoinTripPassengers(sch);
    const regularAmount = getRegularAmount(sch);
    const joinTripAmount = getJoinTripAmount(sch);

    const trip = dayMap[dayKey].trips[tripId];
    trip.schedules.push(sch);
    trip.available_seats += safeNumber(sch.available_seats);
    trip.booked_seats += safeNumber(sch.booked_seats);
    trip.total_seats += safeNumber(sch.total_seats);
    trip.regular_passengers += regularPassengers;
    trip.regular_amount += regularAmount;
    trip.join_trip_passengers += joinTripPassengers;
    trip.join_trip_amount += joinTripAmount;
    trip.total_passengers += regularPassengers + joinTripPassengers;
    trip.total_amount += regularAmount + joinTripAmount;

    dayMap[dayKey].regions.add(regionKey);
    dayMap[dayKey].schedule_count += 1;
    dayMap[dayKey].available_seats += safeNumber(sch.available_seats);
    dayMap[dayKey].booked_seats += safeNumber(sch.booked_seats);
    dayMap[dayKey].regular_passengers += regularPassengers;
    dayMap[dayKey].join_trip_passengers += joinTripPassengers;
    dayMap[dayKey].total_passengers += regularPassengers + joinTripPassengers;
    dayMap[dayKey].total_amount += regularAmount + joinTripAmount;
  });

  return Object.values(dayMap)
    .map((day) => ({
      ...day,
      region_count: day.regions.size,
      regions: [...day.regions],
      trips: Object.values(day.trips)
        .map((trip) => ({
          ...trip,
          schedules: [...trip.schedules].sort(sortSchedules),
        }))
        .sort((a, b) => a.trip_title.localeCompare(b.trip_title, 'th')),
    }))
    .sort((a, b) => dateValue(a.date_key) - dateValue(b.date_key));
});

/* ───── Detail modal derivations ──────────────────────────────────────────
   The manifest is one row per passenger, so booking-level figures (paid,
   total, check-in) must be de-duplicated by booking before being summed. */

const detailPassengers = computed(() => schedulePassengers(selectedSchedule.value));

const detailBookings = computed(() => {
  const bookings = new Map();

  detailPassengers.value.forEach((person) => {
    if (!bookings.has(person.booking_id)) {
      bookings.set(person.booking_id, person);
    }
  });

  return [...bookings.values()];
});

const detailCheckedInCount = computed(() => detailBookings.value.filter((booking) => booking.checked_in).length);

const detailPayments = computed(() => {
  return detailBookings.value.reduce((totals, booking) => {
    const total = safeNumber(booking.total_amount);
    const paid = safeNumber(booking.paid_amount);

    totals.total += total;
    totals.paid += paid;
    totals.outstanding += Math.max(0, total - paid);
    totals.unpaidBookings += total - paid > 0 ? 1 : 0;

    return totals;
  }, { total: 0, paid: 0, outstanding: 0, unpaidBookings: 0 });
});

const manifestTypeOptions = computed(() => [
  { value: 'all', label: 'ทั้งหมด', count: detailPassengers.value.length },
  { value: 'regular', label: 'จองปกติ', count: detailPassengers.value.filter((p) => p.booking_type === 'regular').length },
  { value: 'join_trip', label: 'จอยทริป', count: detailPassengers.value.filter((p) => p.booking_type === 'join_trip').length },
]);

const filteredManifest = computed(() => {
  const query = normalizeText(manifestSearch.value);

  return detailPassengers.value.filter((person) => {
    if (manifestType.value !== 'all' && person.booking_type !== manifestType.value) return false;
    if (!query) return true;

    const haystack = normalizeText([
      person.title,
      person.name,
      person.nickname,
      person.booking_ref,
      person.phone,
      person.customer_name,
      person.id_card,
      person.pickup_location,
    ].filter(Boolean).join(' '));

    return haystack.includes(query);
  });
});

const showDiveColumn = computed(() => detailPassengers.value.some((p) => p.dive_cert_level || p.cert_number));

const detailPickupPoints = computed(() => {
  const points = selectedSchedule.value?.pickup_points || [];

  return points.map((point) => ({
    ...point,
    passengers: detailPassengers.value.filter((p) => !p.is_custom_pickup && p.pickup_location === point.pickup_location),
  }));
});

const customPickupPassengers = computed(() => detailPassengers.value.filter((p) => p.is_custom_pickup));
const noPickupPassengers = computed(() => detailPassengers.value.filter((p) => !p.is_custom_pickup && !p.pickup_location));

const detailTabs = computed(() => [
  { key: 'overview', label: 'ภาพรวม', icon: 'dashboard', count: null },
  { key: 'passengers', label: 'ผู้เดินทาง', icon: 'groups', count: detailPassengers.value.length },
  { key: 'payments', label: 'การชำระเงิน', icon: 'receipt_long', count: detailBookings.value.length },
  { key: 'pickups', label: 'จุดรับ', icon: 'location_on', count: detailPickupPoints.value.length + customPickupPassengers.value.length },
  { key: 'addons', label: 'รายการเสริม', icon: 'add_shopping_cart', count: scheduleAddons(selectedSchedule.value).length },
]);

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
    stats.attentionSchedules += needsAttention(sch) ? 1 : 0;
    stats.regularPassengers += getRegularPassengers(sch);
    stats.regularAmount += getRegularAmount(sch);
    stats.joinTripPassengers += getJoinTripPassengers(sch);
    stats.joinTripAmount += getJoinTripAmount(sch);
    stats.totalPassengers += getTotalPassengers(sch);
    stats.totalAmount += getTotalAmount(sch);
    stats.totalTrips = tripIds.size;
    stats.totalRegions = regionIds.size;

    return stats;
  }, {
    totalSchedules: 0,
    openSchedules: 0,
    availableSeats: 0,
    bookedSeats: 0,
    attentionSchedules: 0,
    regularPassengers: 0,
    regularAmount: 0,
    joinTripPassengers: 0,
    joinTripAmount: 0,
    totalPassengers: 0,
    totalAmount: 0,
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
  filters.attentionOnly = false;
  filters.withPassengersOnly = false;
}

async function fetchData() {
  try {
    admin.error = null;
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

function removeFilter(key) {
  if (key === 'search') filters.search = '';
  if (key === 'status') filters.status = '';
  if (key === 'region') filters.region = '';
  if (key === 'dateRange') filters.dateRange = 'upcoming';
  if (key === 'sortBy') filters.sortBy = 'date';
  if (key === 'attentionOnly') filters.attentionOnly = false;
  if (key === 'withPassengersOnly') filters.withPassengersOnly = false;
}

function openDetails(sch) {
  selectedSchedule.value = sch;
  activeModal.value = 'details';
  detailTab.value = 'overview';
  manifestSearch.value = '';
  manifestType.value = 'all';
  schedulePaymentBookings.value = [];
  paymentsError.value = '';
  seatData.value = null;
  seatError.value = '';
}

async function loadSchedulePayments(scheduleId) {
  loadingPayments.value = true;
  paymentsError.value = '';

  try {
    const res = await api.get(`/admin/calendar/schedules/${scheduleId}/payments`);
    schedulePaymentBookings.value = res.data?.data || [];
  } catch (e) {
    console.error('Failed to fetch schedule payments', e);
    paymentsError.value = e.response?.data?.message || 'ไม่สามารถโหลดข้อมูลการชำระเงินได้';
  } finally {
    loadingPayments.value = false;
  }
}

function openSlip(entry, booking) {
  slipPreview.value = {
    ...entry,
    booking_ref: booking.booking_ref,
    customer_name: booking.customer_name,
  };
}

function closeSlip() {
  slipPreview.value = null;
}

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
  slipPreview.value = null;
  schedulePaymentBookings.value = [];
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

function needsAttention(sch) {
  const availableSeats = safeNumber(sch.available_seats);
  const totalSeats = safeNumber(sch.total_seats);
  const activeStatus = ['open', 'full'].includes(sch?.status);

  return activeStatus && totalSeats > 0 && (availableSeats <= 3 || sch.status === 'full');
}

function availabilityLabel(sch) {
  if (sch?.status === 'cancelled') return 'ยกเลิกแล้ว';
  if (sch?.status === 'closed') return 'ปิดรับจอง';
  if (sch?.status === 'full' || safeNumber(sch.available_seats) === 0) return 'เต็มแล้ว';
  if (safeNumber(sch.available_seats) <= 3) return 'ใกล้เต็ม';
  return 'พร้อมขาย';
}

function availabilityClass(sch) {
  return {
    'availability-full': sch?.status === 'full' || safeNumber(sch.available_seats) === 0,
    'availability-low': sch?.status === 'open' && safeNumber(sch.available_seats) > 0 && safeNumber(sch.available_seats) <= 3,
    'availability-open': sch?.status === 'open' && safeNumber(sch.available_seats) > 3,
    'availability-muted': ['closed', 'cancelled'].includes(sch?.status),
  };
}

function progressClass(sch) {
  return {
    'progress-full': sch?.status === 'full' || safeNumber(sch.available_seats) === 0,
    'progress-low': sch?.status === 'open' && safeNumber(sch.available_seats) > 0 && safeNumber(sch.available_seats) <= 3,
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

function seatFillPercent(sch) {
  const totalSeats = safeNumber(sch.total_seats);
  if (!totalSeats) return 0;
  return Math.min(100, Math.round((safeNumber(sch.booked_seats) / totalSeats) * 100));
}

function getRegularPassengers(sch) {
  if (sch?.regular_passengers_count !== undefined && sch?.regular_passengers_count !== null) {
    return safeNumber(sch.regular_passengers_count);
  }

  return safeNumber(sch?.booked_seats);
}

function getRegularAmount(sch) {
  if (sch?.regular_total_amount !== undefined && sch?.regular_total_amount !== null) {
    return safeNumber(sch.regular_total_amount);
  }

  return getRegularPassengers(sch) * safeNumber(sch?.price);
}

function getJoinTripPassengers(sch) {
  return safeNumber(sch?.join_trip_passengers_count);
}

function getJoinTripAmount(sch) {
  return safeNumber(sch?.join_trip_total_amount);
}

function getTotalPassengers(sch) {
  if (sch?.total_passengers !== undefined && sch?.total_passengers !== null) {
    return safeNumber(sch.total_passengers);
  }

  return getRegularPassengers(sch) + getJoinTripPassengers(sch);
}

function getTotalAmount(sch) {
  if (sch?.total_amount !== undefined && sch?.total_amount !== null) {
    return safeNumber(sch.total_amount);
  }

  return getRegularAmount(sch) + getJoinTripAmount(sch);
}

function schedulePassengers(sch) {
  return Array.isArray(sch?.passenger_manifest) ? sch.passenger_manifest : [];
}

function schedulePassengerCount(sch) {
  return schedulePassengers(sch).length;
}

function scheduleAddons(sch) {
  return Array.isArray(sch?.addons_summary) ? sch.addons_summary : [];
}

function scheduleAddonsItemCount(sch) {
  return scheduleAddons(sch).reduce((sum, addon) => sum + (Number(addon?.total_quantity) || 0), 0);
}

function scheduleAddonsTotal(sch) {
  return scheduleAddons(sch).reduce((sum, addon) => sum + (Number(addon?.total_price) || 0), 0);
}

function fullPassengerName(person) {
  return [person?.title, person?.name].filter(Boolean).join(' ') || '-';
}

function healthSummary(person) {
  return [
    person?.allergies ? `แพ้: ${person.allergies}` : '',
    person?.health_notes ? `สุขภาพ: ${person.health_notes}` : '',
    person?.halal_food ? 'อาหารฮาลาล' : '',
  ].filter(Boolean).join(' / ') || '-';
}

function emergencySummary(person) {
  return [person?.emergency_contact, person?.emergency_phone].filter(Boolean).join(' / ') || '-';
}

function exportScheduleInsurancePdf(sch) {
  if (!sch || !schedulePassengerCount(sch)) return;
  exportInsurancePdf([sch], `รายชื่อส่งประกัน - ${sch.trip_title || 'ทริป'} - ${formatDate(sch.start)}`);
}

function exportVisibleInsurancePdf() {
  const schedulesWithPassengers = filteredSchedules.value.filter((sch) => schedulePassengerCount(sch));
  if (!schedulesWithPassengers.length) return;
  exportInsurancePdf(schedulesWithPassengers, 'รายชื่อส่งประกันตามรอบที่แสดง');
}

function exportInsurancePdf(scheduleItems, title) {
  const generatedAt = new Date().toLocaleString('th-TH');
  const totalPassengers = scheduleItems.reduce((total, sch) => total + schedulePassengerCount(sch), 0);
  const scheduleSections = scheduleItems.map((sch) => {
    const passengers = schedulePassengers(sch);
    const rows = passengers.map((person, index) => `
      <tr>
        <td>${index + 1}</td>
        <td>
          <strong>${escapeHtml(fullPassengerName(person))}</strong>
          <span>${escapeHtml(person.nickname || '')}</span>
        </td>
        <td>${escapeHtml(person.booking_type_label || '-')}</td>
        <td>${escapeHtml(person.booking_ref || '-')}</td>
        <td>${escapeHtml(person.id_card || '-')}</td>
        <td>${escapeHtml(person.phone || '-')}</td>
        <td>${escapeHtml(person.blood_group || '-')}</td>
        <td>${escapeHtml(person.weight || '-')}</td>
        <td>${escapeHtml(healthSummary(person))}</td>
        <td>${escapeHtml(emergencySummary(person))}</td>
        <td>${escapeHtml((person.seat_labels || []).join(', ') || (person.booking_type === 'join_trip' ? 'จอยทริป' : '-'))}</td>
        <td>${escapeHtml([person.pickup_region, person.pickup_location].filter(Boolean).join(' / ') || '-')}${person.is_custom_pickup && person.custom_pickup_lat != null ? `<br><span style="font-size:11px;color:#666">${escapeHtml(`${person.custom_pickup_lat}, ${person.custom_pickup_lng}`)}</span>` : ''}</td>
      </tr>
    `).join('');

    return `
      <section class="schedule-section">
        <div class="schedule-head">
          <div>
            <h2>${escapeHtml(sch.trip_title || '-')}</h2>
            <p>${escapeHtml(formatDate(sch.start, 'long'))}${sch.end && sch.end !== sch.start ? ` - ${escapeHtml(formatDate(sch.end, 'long'))}` : ''}</p>
          </div>
          <div class="schedule-meta">
            <span>${escapeHtml(sch.vehicle || transportLabels[sch.transport_type] || '-')}</span>
            <span>ทั้งหมด ${passengers.length} คน</span>
            <span>ปกติ ${getRegularPassengers(sch)} / จอยทริป ${getJoinTripPassengers(sch)}</span>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>ชื่อผู้เดินทาง</th>
              <th>ประเภท</th>
              <th>เลขจอง</th>
              <th>เลขบัตร/พาสปอร์ต</th>
              <th>โทรศัพท์</th>
              <th>เลือด</th>
              <th>น้ำหนัก</th>
              <th>แพ้/สุขภาพ/อาหาร</th>
              <th>ติดต่อฉุกเฉิน</th>
              <th>ที่นั่ง</th>
              <th>จุดรับ</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </section>
    `;
  }).join('');

  const html = `<!DOCTYPE html>
    <html>
      <head>
        <meta charset="utf-8">
        <title>${escapeHtml(title)}</title>
        <style>
          @page { size: A4 landscape; margin: 10mm; }
          * { box-sizing: border-box; }
          body { font-family: Arial, "Tahoma", sans-serif; color: #111827; margin: 0; }
          h1 { color: #166534; font-size: 22px; margin: 0 0 6px; }
          h2 { color: #111827; font-size: 15px; margin: 0 0 4px; }
          p { margin: 0; }
          .doc-head { border-bottom: 2px solid #166534; padding-bottom: 10px; margin-bottom: 14px; }
          .doc-meta { display: flex; gap: 8px; flex-wrap: wrap; color: #4b5563; font-size: 11px; font-weight: 700; }
          .doc-meta span { border: 1px solid #d1d5db; border-radius: 999px; padding: 4px 8px; }
          .schedule-section { break-inside: avoid; margin-top: 16px; }
          .schedule-head { display: flex; justify-content: space-between; gap: 16px; background: #f8fafc; border: 1px solid #d1d5db; padding: 8px; }
          .schedule-head p { color: #4b5563; font-size: 11px; font-weight: 700; }
          .schedule-meta { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; align-content: flex-start; }
          .schedule-meta span { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; border-radius: 999px; padding: 3px 7px; font-size: 10px; font-weight: 700; }
          table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8px; }
          th { background: #166534; color: #fff; padding: 5px; text-align: left; font-size: 9px; }
          td { border: 1px solid #d1d5db; padding: 5px; vertical-align: top; font-size: 9px; overflow-wrap: anywhere; }
          td strong, td span { display: block; }
          td span { color: #6b7280; margin-top: 2px; }
          tr:nth-child(even) td { background: #f9fafb; }
          .footer { color: #9ca3af; font-size: 9px; margin-top: 18px; text-align: center; }
        </style>
      </head>
      <body>
        <div class="doc-head">
          <h1>${escapeHtml(title)}</h1>
          <div class="doc-meta">
            <span>สร้างเมื่อ ${escapeHtml(generatedAt)}</span>
            <span>จำนวนรอบ ${scheduleItems.length}</span>
            <span>ผู้เดินทางทั้งหมด ${totalPassengers} คน</span>
            <span>รวมทั้งจองปกติและจอยทริป</span>
          </div>
        </div>
        ${scheduleSections}
        <div class="footer">Luilaykhao Admin - รายชื่อสำหรับส่งประกัน</div>
      </body>
    </html>`;

  const printWindow = window.open('', '_blank');
  if (!printWindow) return;
  printWindow.document.write(html);
  printWindow.document.close();
  printWindow.onload = () => printWindow.print();
}

/* ───── Excel (CSV) export of the passenger name list ─────────────────────
   Three column sets the parks/insurers ask for, chosen in a small modal.
   CSV with a UTF-8 BOM opens cleanly in Excel with Thai text intact (same
   approach as ReportsPage). */

const exportPicker = reactive({
  open: false,
  schedules: [],
  title: '',
  format: 1,
});
const savingBirthId = ref(null);

function openExportPicker(scheduleItems, title) {
  const withPassengers = scheduleItems.filter((sch) => schedulePassengerCount(sch));
  if (!withPassengers.length) return;
  exportPicker.schedules = withPassengers;
  exportPicker.title = title;
  exportPicker.format = 1;
  exportPicker.open = true;
}

function closeExportPicker() {
  exportPicker.open = false;
  exportPicker.schedules = [];
}

function exportPickerPassengerCount() {
  return exportPicker.schedules.reduce((total, sch) => total + schedulePassengerCount(sch), 0);
}

function exportSelectedCsv() {
  const fmt = exportPicker.format;
  const headers = {
    1: ['ลำดับ', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'ทริป', 'วันเดินทาง', 'ประเภท'],
    2: ['ลำดับ', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'วันเกิด', 'เลขบัตรประชาชน', 'ทริป', 'วันเดินทาง', 'ประเภท'],
    3: ['ลำดับ', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'อายุ', 'ทริป', 'วันเดินทาง', 'ประเภท'],
  }[fmt];

  const rows = [];
  let index = 0;
  exportPicker.schedules.forEach((sch) => {
    schedulePassengers(sch).forEach((person) => {
      index += 1;
      const head = [index, person.title || '', person.name || ''];
      const tail = [sch.trip_title || '', formatDate(sch.start), person.booking_type_label || ''];
      if (fmt === 1) {
        rows.push([...head, ...tail]);
      } else if (fmt === 2) {
        rows.push([...head, person.birth_date || '', person.id_card || '', ...tail]);
      } else {
        rows.push([...head, person.age ?? '', ...tail]);
      }
    });
  });

  downloadCsv(headers, rows, `${exportPicker.title || 'รายชื่อผู้เดินทาง'} - แบบ${fmt}`);
  closeExportPicker();
}

function downloadCsv(headers, rows, filename) {
  const BOM = '﻿';
  const esc = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
  const csv = BOM + [headers.map(esc).join(','), ...rows.map((row) => row.map(esc).join(','))].join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${filename}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

// Scope helpers — a round, a whole trip, or everything currently shown.
function exportScheduleCsv(sch) {
  openExportPicker([sch], `รายชื่อ - ${sch.trip_title || 'ทริป'} - ${formatDate(sch.start)}`);
}

function exportTripCsv(trip) {
  openExportPicker(trip.schedules, `รายชื่อ - ${trip.trip_title || 'ทริป'}`);
}

function exportVisibleCsv() {
  openExportPicker(filteredSchedules.value, 'รายชื่อผู้เดินทางตามรอบที่แสดง');
}

// Backfill / correct a passenger's birth date from the manifest table.
async function saveBirthDate(person, event) {
  const value = event?.target?.value;
  if (!value) return;
  savingBirthId.value = person.id;
  try {
    const res = await api.patch(`/admin/passengers/${person.id}`, { birth_date: value });
    person.birth_date = res.data?.data?.birth_date ?? value;
    person.age = res.data?.data?.age ?? null;
  } catch (e) {
    alert(e.response?.data?.message ?? 'บันทึกวันเกิดไม่สำเร็จ');
  } finally {
    savingBirthId.value = null;
  }
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
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

function formatBirthDate(value) {
  const date = scheduleDate(value);
  if (!date) return '-';

  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatTime(value) {
  return String(value ?? '').slice(0, 5);
}

function formatDateTime(value) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  return date.toLocaleString('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function scheduleDateRange(sch) {
  const start = formatDate(sch?.start);
  if (!sch?.end || sch.end === sch.start) return start;
  return `${start} - ${formatDate(sch.end)}`;
}

function tripDurationDays(sch) {
  const start = scheduleDate(sch?.start);
  const end = scheduleDate(sch?.end);
  if (!start) return 0;
  if (!end) return 1;
  return Math.max(1, Math.round((end - start) / 86400000) + 1);
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
  return scheduleDate(value)?.getTime() || Number.MAX_SAFE_INTEGER;
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

const dateRangeLabels = {
  upcoming: 'กำลังจะถึง',
  today: 'วันนี้',
  week: '7 วันข้างหน้า',
  month: '30 วันข้างหน้า',
  all: 'ทั้งหมดที่โหลดมา',
};

const sortLabels = {
  date: 'วันเดินทางเร็วสุด',
  available: 'ที่นั่งว่างน้อยสุด',
  booked: 'จองมากสุด',
  price: 'ราคาต่ำสุด',
};

function handleKeyDown(event) {
  if (event.key !== 'Escape') return;
  if (slipPreview.value) return closeSlip();
  if (activeModal.value) closeModal();
}

watch(activeModal, (modal) => {
  document.body.style.overflow = modal ? 'hidden' : '';
});

// Fetch slips the first time the payments tab is opened for a schedule.
watch(detailTab, (tab) => {
  if (tab !== 'payments' || !selectedSchedule.value) return;
  if (schedulePaymentBookings.value.length || loadingPayments.value) return;
  loadSchedulePayments(selectedSchedule.value.id);
});

onMounted(fetchData);
onMounted(() => window.addEventListener('keydown', handleKeyDown));
onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown);
  document.body.style.overflow = '';
});
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
  background: #f3f4f6;
  color: #4b5563;
  flex-shrink: 0;
}

.summary-icon.open {
  background: #ecfdf5;
  color: #059669;
}

.summary-icon.people {
  background: #eef2ff;
  color: #4f46e5;
}

.summary-icon.seats {
  background: #eff6ff;
  color: #2563eb;
}

.summary-icon.warning {
  background: #fffbeb;
  color: #d97706;
}

.summary-icon.money {
  background: #f0fdf4;
  color: #15803d;
}

.summary-icon.join {
  background: #ecfdf5;
  color: #059669;
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
  font-size: 20px;
  line-height: 1.2;
}

.summary-subvalue {
  display: block;
  color: var(--color-accent);
  font-size: 12px;
  font-weight: 800;
  margin-top: 2px;
}

.filters-panel {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 20px;
}

.overview-filters {
  align-items: flex-end;
  margin-bottom: 8px;
}

.filter-field {
  display: grid;
  gap: 5px;
  min-width: 132px;
}

.filter-field select {
  width: 100%;
}

.filter-label {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.search-field {
  flex: 1 1 280px;
  min-width: 260px;
}

.search-box.wide {
  min-width: 0;
}

.compact {
  padding-inline: 12px;
  white-space: nowrap;
}

.filter-footnote {
  color: var(--color-text-muted);
  font-size: 12px;
}

.toggle-filter {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 38px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #ffffff;
  color: var(--color-text-mid);
  cursor: pointer;
  font-size: 13px;
  font-weight: 800;
  padding: 8px 12px;
  white-space: nowrap;
}

.toggle-filter input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.toggle-filter .material-symbols-rounded {
  color: var(--color-text-muted);
  font-size: 18px;
}

.toggle-filter.active {
  background: #e8f5ec;
  border-color: #b7dfc5;
  color: var(--color-accent);
}

.toggle-filter.active .material-symbols-rounded {
  color: var(--color-accent);
}

.active-filter-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 1px solid #b7dfc5;
  border-radius: 999px;
  background: #f0faf4;
  color: var(--color-accent);
  cursor: pointer;
  font-size: 12px;
  font-weight: 800;
  padding: 4px 8px 4px 10px;
}

.filter-chip .material-symbols-rounded {
  font-size: 15px;
}

.filter-chip:hover {
  background: #e8f5ec;
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

.overview-empty .btn-secondary {
  margin-top: 12px;
}

.overview-empty .material-symbols-rounded {
  display: block;
  font-size: 48px;
  color: #cbd5e1;
  margin-bottom: 12px;
}

.day-block {
  margin-bottom: 30px;
}

.day-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
  padding: 14px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
}

.day-title {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.day-title .material-symbols-rounded {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #e8f5ec;
  color: var(--color-accent);
  flex-shrink: 0;
}

.day-title h2 {
  margin: 0;
  color: var(--color-primary);
  font-size: 20px;
  font-weight: 900;
}

.day-title p {
  margin: 2px 0 0;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.day-metrics {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.day-metrics span {
  background: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  border-radius: 999px;
  padding: 4px 10px;
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

.card-badges {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  min-width: 0;
}

.availability-badge {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 900;
  padding: 3px 9px;
  white-space: nowrap;
}

.availability-open {
  background: #ecfdf5;
  color: #047857;
}

.availability-low {
  background: #fffbeb;
  color: #b45309;
}

.availability-full {
  background: #fef2f2;
  color: #b91c1c;
}

.availability-muted {
  background: #f3f4f6;
  color: #6b7280;
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

.progress-fill.progress-low {
  background: #f59e0b;
}

.progress-fill.progress-full {
  background: #ef4444;
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

.booking-summary-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.booking-summary-card {
  display: grid;
  gap: 2px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px;
  background: #fafafa;
}

.booking-summary-card.join {
  background: #ecfdf5;
  border-color: #a7f3d0;
}

.booking-summary-label {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.booking-summary-card strong {
  color: var(--color-text-dark);
  font-size: 17px;
  line-height: 1.1;
}

.booking-summary-card span:last-child {
  color: var(--color-accent);
  font-size: 12px;
  font-weight: 800;
}

.manifest-preview {
  display: grid;
  gap: 8px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px;
  background: #f8fafc;
}

.manifest-preview-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.manifest-preview-head > div {
  display: grid;
  gap: 2px;
}

.manifest-kicker {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.manifest-preview-head strong {
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 900;
}

.btn-export-mini {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  min-height: 30px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  color: #b91c1c;
  cursor: pointer;
  font-size: 12px;
  font-weight: 900;
  padding: 6px 9px;
  white-space: nowrap;
}

.btn-export-mini:hover {
  background: #fee2e2;
}

.btn-export-mini:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.btn-export-mini .material-symbols-rounded {
  font-size: 16px;
}

.manifest-name-list {
  display: grid;
  gap: 5px;
}

.manifest-name-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 7px;
  min-width: 0;
  color: var(--color-text-mid);
  font-size: 12px;
}

.manifest-type-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: #9ca3af;
}

.manifest-type-dot.join {
  background: #059669;
}

.manifest-person-name {
  color: var(--color-text-dark);
  font-weight: 800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.manifest-person-meta {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 700;
  text-align: right;
}

.manifest-more,
.manifest-empty {
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
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

.money-value {
  color: var(--color-accent);
}

.seats-avail {
  color: var(--color-accent);
}

/* ─── Detail modal shell ──────────────────────────────────────────────── */
.detail-modal {
  display: flex;
  flex-direction: column;
  max-width: 1180px;
  max-height: 92vh;
  overflow: hidden;
}

.detail-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px 16px;
  border-bottom: 1px solid #eeeeee;
}

.detail-heading {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.detail-badges {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.detail-modal .modal-title {
  color: var(--color-text-dark);
  font-size: 21px;
  font-weight: 900;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.detail-meta {
  display: flex;
  align-items: center;
  gap: 6px 16px;
  flex-wrap: wrap;
  color: var(--color-text-muted);
  font-size: 13px;
  font-weight: 700;
}

.detail-meta > span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.detail-meta .material-symbols-rounded {
  font-size: 17px;
  color: var(--color-accent);
}

.detail-meta-id {
  color: #9ca3af;
  font-weight: 600;
}

/* ─── KPI strip ───────────────────────────────────────────────────────── */
.detail-kpis {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px;
  padding: 16px 24px;
  background: #fafafa;
  border-bottom: 1px solid #eeeeee;
}

.kpi-tile {
  display: grid;
  align-content: start;
  gap: 5px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 12px;
  min-width: 0;
}

.kpi-label {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.kpi-value {
  display: flex;
  align-items: baseline;
  gap: 4px;
  color: var(--color-text-dark);
  font-size: 22px;
  font-weight: 900;
  line-height: 1.1;
  overflow-wrap: anywhere;
}

.kpi-value em {
  color: var(--color-text-muted);
  font-size: 12px;
  font-style: normal;
  font-weight: 700;
}

.kpi-sub {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 700;
}

.kpi-tile .progress-track {
  margin-top: 3px;
}

.progress-track.tall {
  height: 10px;
}

/* ─── Tabs ────────────────────────────────────────────────────────────── */
.detail-tabs {
  display: flex;
  gap: 4px;
  padding: 0 24px;
  border-bottom: 1px solid #eeeeee;
  overflow-x: auto;
}

.detail-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: none;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 13px;
  font-weight: 800;
  padding: 12px 12px 10px;
  white-space: nowrap;
  transition: color 0.15s, border-color 0.15s;
}

.detail-tab .material-symbols-rounded {
  font-size: 18px;
}

.detail-tab:hover {
  color: var(--color-text-dark);
}

.detail-tab.active {
  color: var(--color-accent);
  border-bottom-color: var(--color-accent);
}

.tab-count {
  border-radius: 999px;
  background: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  color: var(--color-text-mid);
  font-size: 11px;
  font-weight: 800;
  padding: 1px 7px;
}

.detail-tab.active .tab-count {
  background: #e8f5ec;
  border-color: #b7dfc5;
  color: var(--color-accent);
}

/* ─── Panels ──────────────────────────────────────────────────────────── */
.detail-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 20px 24px;
  background: #fafafa;
}

.detail-panel {
  display: grid;
  gap: 14px;
}

.overview-panel {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  align-items: start;
}

.panel-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 14px 16px;
}

.panel-card.span-full {
  grid-column: 1 / -1;
}

.panel-title {
  display: flex;
  align-items: center;
  gap: 7px;
  margin: 0 0 12px;
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 900;
}

.panel-title .material-symbols-rounded {
  color: var(--color-accent);
  font-size: 18px;
}

.spec-list {
  display: grid;
  margin: 0;
}

.spec-row {
  display: grid;
  grid-template-columns: 130px minmax(0, 1fr);
  gap: 12px;
  align-items: baseline;
  padding: 8px 0;
  border-bottom: 1px solid #f3f4f6;
}

.spec-row:last-child {
  border-bottom: none;
}

.spec-row dt {
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.spec-row dd {
  margin: 0;
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.muted-value {
  color: var(--color-text-muted) !important;
  font-weight: 600 !important;
}

.seat-figure {
  display: grid;
  gap: 6px;
  margin-bottom: 14px;
}

.seat-figure-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-muted);
}

.seat-figure-head strong {
  font-size: 16px;
  font-weight: 900;
}

.mini-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.mini-stat {
  display: grid;
  gap: 2px;
  background: #fafafa;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 9px 10px;
}

.mini-stat span {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 700;
}

.mini-stat strong {
  color: var(--color-text-dark);
  font-size: 15px;
  font-weight: 900;
}

.money-table {
  width: 100%;
  border-collapse: collapse;
}

.money-table th {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
  text-align: left;
  padding: 6px 8px;
  border-bottom: 1px solid var(--color-sand-dark);
}

.money-table th:not(:first-child),
.money-table td:not(:first-child) {
  text-align: right;
}

.money-table td {
  color: var(--color-text-mid);
  font-size: 13px;
  font-weight: 700;
  padding: 9px 8px;
  border-bottom: 1px solid #f3f4f6;
}

.money-table td:first-child {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--color-text-dark);
}

.money-table tfoot td {
  border-bottom: none;
  border-top: 1px solid var(--color-sand-dark);
  color: var(--color-text-dark);
  font-weight: 900;
}

.pay-split {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-top: 12px;
}

.pay-chip {
  display: grid;
  gap: 2px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: #fafafa;
  padding: 10px;
}

.pay-chip span {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.pay-chip strong {
  color: var(--color-text-dark);
  font-size: 16px;
  font-weight: 900;
}

.pay-chip em {
  color: var(--color-text-muted);
  font-size: 11px;
  font-style: normal;
  font-weight: 600;
}

.pay-chip.paid {
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.pay-chip.paid strong {
  color: #166534;
}

.pay-chip.due {
  background: #fffbeb;
  border-color: #fde68a;
}

.pay-chip.due strong {
  color: #b45309;
}

.panel-empty {
  display: grid;
  justify-items: center;
  gap: 8px;
  padding: 48px 16px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  color: var(--color-text-muted);
  font-size: 13px;
  font-weight: 700;
  text-align: center;
}

.panel-empty p {
  margin: 0;
}

.panel-empty .material-symbols-rounded {
  color: #cbd5e1;
  font-size: 42px;
}

.panel-note {
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid #fde68a;
  background: #fffbeb;
  border-radius: 8px;
  color: #92400e;
  font-size: 12px;
  font-weight: 700;
  padding: 10px 12px;
}

.panel-note .material-symbols-rounded {
  font-size: 18px;
}

/* ─── Manifest table ──────────────────────────────────────────────────── */
.manifest-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.manifest-toolbar .search-box {
  flex: 1 1 260px;
  min-width: 0;
}

.manifest-toolbar-actions {
  display: flex;
  gap: 8px;
  margin-left: auto;
}

.segmented {
  display: inline-flex;
  gap: 2px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 3px;
}

.segment {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 12px;
  font-weight: 800;
  padding: 6px 10px;
  white-space: nowrap;
}

.segment:hover {
  color: var(--color-text-dark);
}

.segment.active {
  background: #e8f5ec;
  color: var(--color-accent);
}

.data-table-wrap {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  overflow: auto;
  max-height: 58vh;
}

.data-table {
  width: 100%;
  min-width: 1280px;
  border-collapse: separate;
  border-spacing: 0;
}

.data-table th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: #f8fafc;
  color: var(--color-text-dark);
  font-size: 11px;
  font-weight: 900;
  padding: 10px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-sand-dark);
}

.data-table td {
  color: var(--color-text-mid);
  font-size: 12px;
  font-weight: 700;
  padding: 10px;
  border-bottom: 1px solid #f3f4f6;
  vertical-align: top;
}

.data-table tbody tr:hover td {
  background: #fafafa;
}

.data-table td > strong,
.data-table td > span {
  display: block;
}

.data-table td > strong {
  color: var(--color-text-dark);
}

.data-table td > span {
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 600;
  margin-top: 2px;
}

.col-index {
  width: 40px;
  color: var(--color-text-muted);
}

.col-person {
  position: sticky;
  left: 0;
  z-index: 1;
  background: var(--color-white);
  min-width: 210px;
  box-shadow: 1px 0 0 #f3f4f6;
}

.data-table thead .col-person {
  z-index: 3;
  background: #f8fafc;
}

.data-table tbody tr:hover .col-person {
  background: #fafafa;
}

.cell-chips {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 5px;
}

.cell-chips .status-badge {
  font-size: 10px;
  padding: 2px 8px;
}

.cell-owner {
  margin-top: 4px;
}

.ref-chip {
  border-radius: 6px;
  background: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  color: var(--color-text-mid);
  font-size: 10px;
  font-weight: 800;
  padding: 2px 6px;
}

.tiny-chip,
.data-table td > .tiny-chip {
  display: inline-block;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 7px;
  margin-top: 3px;
}

.cell-nickname {
  color: var(--color-text-muted);
  font-size: 11px;
  font-style: normal;
  font-weight: 700;
}

.tiny-chip.check {
  background: #dcfce7;
  color: #166534;
}

.tiny-chip.halal {
  background: #eef2ff;
  color: #4338ca;
}

.join-text {
  color: #047857 !important;
}

.wrap-text {
  display: block;
  min-width: 150px;
  white-space: normal;
  overflow-wrap: anywhere;
}

.map-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 4px;
  color: var(--color-accent);
  font-size: 11px;
  font-weight: 800;
  text-decoration: none;
}

.map-link:hover {
  text-decoration: underline;
}

.map-link .material-symbols-rounded {
  font-size: 15px;
}

/* ─── Payments panel ──────────────────────────────────────────────────── */
.payment-card {
  display: grid;
  gap: 12px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 14px 16px;
}

.payment-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.payment-card-who strong {
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 900;
}

.payment-card-phone {
  display: block;
  margin-top: 4px;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 600;
}

.payment-card-figures {
  display: flex;
  gap: 20px;
  margin-left: auto;
  text-align: right;
}

.payment-card-figures span {
  display: block;
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.payment-card-figures strong {
  display: block;
  margin-top: 2px;
  color: var(--color-text-dark);
  font-size: 15px;
  font-weight: 900;
}

.slip-row-list {
  display: grid;
  gap: 8px;
}

.slip-row {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fafafa;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px 12px;
}

.slip-thumb {
  width: 52px;
  height: 52px;
  flex-shrink: 0;
  border: 1px solid var(--color-sand-dark);
  border-radius: 6px;
  background: var(--color-white);
  overflow: hidden;
  padding: 0;
  cursor: pointer;
  transition: border-color 0.15s, transform 0.15s;
}

.slip-thumb:hover {
  border-color: var(--color-accent);
  transform: scale(1.04);
}

.slip-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.slip-thumb.empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f3f4f6;
  cursor: default;
}

.slip-thumb.empty .material-symbols-rounded {
  color: #cbd5e1;
  font-size: 22px;
}

.slip-row-info {
  display: grid;
  gap: 4px;
  min-width: 0;
  flex: 1;
}

.slip-row-title {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.slip-row-title strong {
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 900;
}

.slip-row-meta {
  display: flex;
  align-items: center;
  gap: 4px 14px;
  flex-wrap: wrap;
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 700;
}

.slip-missing {
  color: #b45309;
}

.slip-row-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}

.slip-row-amount {
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 900;
  white-space: nowrap;
}

.tiny-chip.type {
  background: var(--color-sand);
  color: var(--color-text-mid);
}

.tiny-chip.join {
  background: #ecfdf5;
  color: #047857;
}

.tiny-chip.wait {
  background: #fef3c7;
  color: #92400e;
}

.ocr-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 8px;
  white-space: nowrap;
}

.ocr-pending { background: #fef3c7; color: #92400e; }
.ocr-verified { background: #d1fae5; color: #065f46; }
.ocr-failed { background: #fee2e2; color: #991b1b; }
.ocr-manually_approved { background: #d1fae5; color: #065f46; }
.ocr-rejected { background: #f3f4f6; color: #6b7280; }
.ocr-none { background: #f3f4f6; color: #6b7280; }

/* ─── Slip viewer ─────────────────────────────────────────────────────── */
.slip-overlay {
  z-index: 260;
  background: rgba(17, 24, 39, 0.66);
}

.slip-viewer {
  display: flex;
  flex-direction: column;
  max-width: 640px;
  max-height: 92vh;
  overflow: hidden;
}

.slip-viewer-body {
  flex: 1;
  min-height: 0;
  overflow: auto;
  padding: 16px;
  background: #fafafa;
  text-align: center;
}

.slip-viewer-body img {
  max-width: 100%;
  border-radius: 8px;
  border: 1px solid var(--color-sand-dark);
}

/* ─── Pickup panel ────────────────────────────────────────────────────── */
.pickup-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}

.pickup-card {
  display: grid;
  align-content: start;
  gap: 10px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 14px;
}

.pickup-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.pickup-card-region {
  display: block;
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 800;
}

.pickup-card-loc {
  display: block;
  color: var(--color-text-dark);
  font-size: 14px;
  font-weight: 800;
  margin-top: 2px;
  overflow-wrap: anywhere;
}

.pickup-card-price {
  color: var(--color-accent);
  font-size: 14px;
  font-weight: 900;
  white-space: nowrap;
}

.pickup-card-facts {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.pickup-card-facts > span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.pickup-card-facts .material-symbols-rounded {
  font-size: 16px;
}

.pickup-card-notes {
  margin: 0;
  background: var(--color-sand);
  border-radius: 6px;
  color: var(--color-text-mid);
  font-size: 12px;
  padding: 8px 10px;
}

.pickup-card-people {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.people-chip {
  border-radius: 999px;
  background: #f0faf4;
  border: 1px solid #b7dfc5;
  color: var(--color-accent);
  font-size: 11px;
  font-weight: 700;
  padding: 3px 9px;
}

.pickup-card-empty {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 700;
}

.custom-pickup-list {
  display: grid;
  gap: 8px;
}

.custom-pickup-item {
  display: grid;
  gap: 4px;
  background: #fafafa;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px 12px;
}

.custom-pickup-item > div {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.custom-pickup-item strong {
  color: var(--color-text-dark);
  font-size: 13px;
  font-weight: 800;
}

.custom-pickup-loc,
.custom-pickup-note {
  color: var(--color-text-mid);
  font-size: 12px;
  font-weight: 600;
}

.custom-pickup-note {
  color: var(--color-text-muted);
}

/* ─── Detail footer ───────────────────────────────────────────────────── */
.detail-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 14px 24px;
  border-top: 1px solid #eeeeee;
  background: var(--color-white);
}

.detail-actions-hint {
  color: var(--color-text-muted);
  font-size: 12px;
  font-weight: 600;
}

.detail-actions-buttons {
  display: flex;
  gap: 10px;
  margin-left: auto;
}

.addons-summary-block {
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  overflow: hidden;
  background: var(--color-white);
}

.addons-summary-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: #f0fdf4;
  border-bottom: 1px solid #bbf7d0;
  color: #166534;
}

.addons-summary-head .material-symbols-rounded {
  font-size: 20px;
}

.addons-summary-head > div {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.addons-summary-head strong {
  font-size: 13px;
  color: #14532d;
}

.addons-summary-list {
  display: grid;
}

.addons-summary-item {
  border-bottom: 1px solid #eeeeee;
}

.addons-summary-item:last-child {
  border-bottom: none;
}

.addons-summary-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 12px;
  align-items: center;
  padding: 10px 12px;
  font-size: 13px;
}

.addons-summary-customers {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 0 12px 10px;
}

.addons-customer-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  color: #166534;
  font-size: 12px;
  font-weight: 700;
}

.addons-customer-ref {
  color: #15803d;
  font-weight: 600;
  font-size: 11px;
  opacity: 0.8;
}

.addons-customer-qty {
  color: #14532d;
  font-weight: 900;
}

.addons-summary-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.addons-summary-info strong {
  color: var(--color-text-dark);
  font-weight: 800;
}

.addons-summary-meta {
  font-size: 12px;
  color: var(--color-text-muted);
}

.addons-summary-qty {
  font-size: 12px;
  color: var(--color-text-mid);
  font-weight: 800;
}

.addons-summary-price {
  color: #166534;
  font-weight: 800;
  white-space: nowrap;
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
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .detail-kpis {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .overview-panel {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .header-actions {
    justify-content: flex-start;
    width: 100%;
  }

  .filter-field,
  .toggle-filter,
  .filters-panel .btn-secondary {
    width: 100%;
  }

  .card-header {
    align-items: flex-start;
  }

  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .day-header,
  .region-header,
  .trip-section-header {
    align-items: stretch;
    flex-direction: column;
  }

  .day-metrics,
  .region-metrics,
  .tsh-count {
    align-items: flex-start;
    justify-content: flex-start;
  }

  .manifest-name-row {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .manifest-person-meta {
    grid-column: 2;
    text-align: left;
  }

  .detail-header,
  .detail-kpis,
  .detail-body,
  .detail-actions {
    padding-inline: 16px;
  }

  .detail-tabs {
    padding-inline: 8px;
  }

  .spec-row {
    grid-template-columns: 1fr;
    gap: 2px;
  }

  .mini-stats,
  .pay-split {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .manifest-toolbar-actions {
    margin-left: 0;
    width: 100%;
  }

  .col-person {
    position: static;
    box-shadow: none;
  }
}

@media (max-width: 640px) {
  .schedule-grid,
  .card-actions,
  .booking-summary-grid {
    grid-template-columns: 1fr;
  }

  .detail-kpis {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.manifest-preview-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

/* Birth-date / age cell in the manifest table */
.age-pill {
  display: inline-block;
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 999px;
  background: #dbeafe;
  color: #1e40af;
  font-size: 10px;
  font-weight: 800;
}

.birth-date-input {
  border: 1px solid #d1d5db;
  border-radius: 6px;
  padding: 4px 6px;
  font-size: 12px;
  font-family: inherit;
}

.birth-date-input:disabled {
  opacity: 0.5;
  cursor: progress;
}

/* Export format chooser */
.export-format-option {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  cursor: pointer;
  margin-bottom: 10px;
  transition: border-color 0.15s, background 0.15s;
}

.export-format-option:hover {
  border-color: var(--color-accent, #2d7a4f);
  background: #f0fdf4;
}

.export-format-option.active {
  border-color: var(--color-accent, #2d7a4f);
  background: #f0fdf4;
}

.export-format-option input {
  margin-top: 3px;
  width: 16px;
  height: 16px;
  accent-color: var(--color-accent, #2d7a4f);
}

.export-format-option strong {
  display: block;
  font-size: 14px;
  color: #111827;
}

.export-format-option span {
  display: block;
  font-size: 12px;
  color: #6b7280;
  margin-top: 2px;
}

.export-note {
  font-size: 12px;
  color: #6b7280;
  line-height: 1.5;
  margin: 4px 0 0;
}
</style>
