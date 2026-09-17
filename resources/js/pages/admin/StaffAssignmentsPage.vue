<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">badge</span> จัดการสตาฟประจำรอบ</h1>
        <p class="page-subtitle">กำหนดว่าสตาฟคนไหนดูแลทริปไหนในแต่ละรอบเดินทาง</p>
      </div>
      <div class="header-actions">
        <div class="tab-switcher">
          <button :class="['tab-btn', { active: activeTab === 'assign' }]" @click="activeTab = 'assign'">
            <span class="material-symbols-rounded">assignment_ind</span> มอบหมายสตาฟ
          </button>
          <button :class="['tab-btn', { active: activeTab === 'roster' }]" @click="activeTab = 'roster'; loadRoster()">
            <span class="material-symbols-rounded">calendar_month</span> ตารางงาน
          </button>
        </div>
        <button class="btn-secondary" @click="loadData" :disabled="loading">
          <span class="material-symbols-rounded">refresh</span> รีเฟรช
        </button>
      </div>
    </div>

    <!-- ─── TODAY'S TRIPS PANEL ─────────────────────────────── -->
    <div v-if="todaySchedules.length" class="today-panel">
      <div class="today-header">
        <span class="material-symbols-rounded">today</span>
        <strong>รอบเดินทางวันนี้ — {{ todayLabel }}</strong>
        <span class="today-count">{{ todaySchedules.length }} รอบ</span>
      </div>
      <div class="today-grid">
        <div v-for="sch in todaySchedules" :key="sch.id" class="today-card">
          <div class="today-trip-name">{{ sch.trip?.title || 'ไม่ระบุทริป' }}</div>
          <div class="today-trip-meta">
            <span class="material-symbols-rounded">place</span>{{ sch.trip?.location || '-' }}
            <span class="material-symbols-rounded" style="margin-left:6px;">directions_car</span>{{ vehicleLabel(sch.vehicle, sch.transport_type) }}
          </div>
          <div class="today-trip-meta">
            <span class="material-symbols-rounded">schedule</span>{{ scheduleTimeLabel(sch) ? `ออก ${scheduleTimeLabel(sch)} น.` : 'ยังไม่ระบุเวลาออก' }}
            <span class="material-symbols-rounded" style="margin-left:6px;">event_seat</span>{{ sch.booked_seats ?? 0 }}/{{ sch.total_seats ?? 0 }} ที่นั่ง
          </div>
          <div v-if="sch.assignedStaff && sch.assignedStaff.length" class="today-staff-row">
            <div v-for="st in sch.assignedStaff" :key="st.id" class="today-staff-chip">
              <img v-if="st.avatar_url" :src="st.avatar_url" :alt="st.name" class="today-avatar" />
              <div v-else class="today-avatar fallback">{{ st.name?.charAt(0)?.toUpperCase() }}</div>
              <div class="today-staff-info">
                <span class="today-staff-name">{{ st.name }}<span v-if="st.nickname" class="today-staff-nick"> ({{ st.nickname }})</span></span>
                <a v-if="st.phone" :href="`tel:${st.phone}`" class="today-phone">
                  <span class="material-symbols-rounded">call</span>{{ st.phone }}
                </a>
              </div>
            </div>
          </div>
          <div v-else class="today-no-staff">
            <span class="material-symbols-rounded">person_off</span> ยังไม่มีสตาฟ
          </div>
        </div>
      </div>
    </div>

    <!-- ─── ASSIGN TAB ──────────────────────────────────────── -->
    <template v-if="activeTab === 'assign'">
      <div class="summary-grid">
        <div class="summary-card">
          <span class="material-symbols-rounded">event_note</span>
          <div>
            <p>รอบที่แสดง</p>
            <strong>{{ visibleSchedulesCount }}</strong>
          </div>
        </div>
        <div class="summary-card">
          <span class="material-symbols-rounded">badge</span>
          <div>
            <p>สตาฟทั้งหมด</p>
            <strong>{{ staffUsers.length }}</strong>
          </div>
        </div>
        <div class="summary-card">
          <span class="material-symbols-rounded">assignment_ind</span>
          <div>
            <p>มอบหมายในรอบนี้</p>
            <strong>{{ selectedStaffIds.length }}</strong>
          </div>
        </div>
        <button type="button" class="summary-card warning" :class="{ active: onlyMissing }" @click="onlyMissing = !onlyMissing">
          <span class="material-symbols-rounded">person_off</span>
          <div>
            <p>รอบที่ยังไม่มีสตาฟ <span class="tap-hint">{{ onlyMissing ? '· แสดงเฉพาะนี้' : '· แตะเพื่อกรอง' }}</span></p>
            <strong>{{ unassignedSchedulesCount }}</strong>
          </div>
        </button>
      </div>

      <div class="assign-layout">
        <!-- ── LEFT: flat round picker ── -->
        <aside class="picker">
          <div class="picker-head">
            <div class="seg">
              <button type="button" :class="{ active: scheduleScope === 'upcoming' }" @click="setScope('upcoming')" :disabled="loading">
                กำลังจะมาถึง
              </button>
              <button type="button" :class="{ active: scheduleScope === 'all' }" @click="setScope('all')" :disabled="loading">
                ทั้งหมด
              </button>
            </div>
            <div class="search-box">
              <span class="material-symbols-rounded">search</span>
              <input v-model="scheduleSearch" placeholder="ค้นหาทริป / สถานที่ / วันที่" />
              <button v-if="scheduleSearch" type="button" class="search-clear" @click="scheduleSearch = ''" title="ล้างคำค้นหา">
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
            <button type="button" class="missing-toggle" :class="{ active: onlyMissing }" @click="onlyMissing = !onlyMissing">
              <span class="material-symbols-rounded">{{ onlyMissing ? 'filter_alt' : 'person_off' }}</span>
              เฉพาะรอบที่ยังไม่มีสตาฟ
              <span v-if="unassignedSchedulesCount" class="mt-count">{{ unassignedSchedulesCount }}</span>
            </button>
          </div>

          <div v-if="loading" class="loading-state"><div class="spinner"></div></div>

          <div v-else-if="!groupedTrips.length" class="picker-empty">
            <span class="material-symbols-rounded">{{ onlyMissing ? 'task_alt' : 'search_off' }}</span>
            <p>{{ onlyMissing ? 'ทุกรอบมีสตาฟครบแล้ว 🎉' : 'ไม่พบรอบเดินทาง' }}</p>
          </div>

          <div v-else class="trip-list">
            <section v-for="group in groupedTrips" :key="group.id" class="trip-block">
              <header class="trip-block-head">
                <div class="tbh-text">
                  <h3 class="tbh-title">{{ group.title }}</h3>
                  <span v-if="group.location" class="tbh-loc">
                    <span class="material-symbols-rounded">place</span>{{ group.location }}
                  </span>
                </div>
                <span v-if="group.missingStaff" class="tbh-flag" title="รอบที่ยังไม่มีสตาฟ">
                  <span class="material-symbols-rounded">error</span>{{ group.missingStaff }}
                </span>
              </header>

              <div class="round-cards">
                <button
                  v-for="round in group.rounds"
                  :key="round.id"
                  type="button"
                  class="round-card"
                  :class="{ active: Number(round.id) === Number(selectedScheduleId), missing: !Number(round.assigned_staff_count || 0) }"
                  @click="selectSchedule(round.id)"
                >
                  <div class="rc-top">
                    <span class="rc-date">
                      <span class="material-symbols-rounded">event</span>{{ scheduleDateRange(round) }}
                      <span v-if="scheduleTimeLabel(round)" class="rc-time">{{ scheduleTimeLabel(round) }} น.</span>
                    </span>
                    <span class="rc-status" :class="`st-${round.status || 'unknown'}`">{{ statusLabel(round.status) }}</span>
                  </div>
                  <div class="rc-bottom">
                    <span class="rc-seats">
                      <span class="material-symbols-rounded">event_seat</span>{{ round.booked_seats ?? 0 }}/{{ round.total_seats ?? 0 }} ที่นั่ง
                    </span>
                    <span class="rc-staff" :class="{ none: !Number(round.assigned_staff_count || 0) }">
                      <span class="material-symbols-rounded">{{ Number(round.assigned_staff_count || 0) ? 'group' : 'person_off' }}</span>
                      {{ Number(round.assigned_staff_count || 0) ? `${round.assigned_staff_count} สตาฟ` : 'ยังไม่มีสตาฟ' }}
                    </span>
                  </div>
                </button>
              </div>
            </section>
          </div>
        </aside>

        <!-- ── RIGHT: staff assignment for selected round ── -->
        <section class="detail-panel" ref="detailPanel">
          <div v-if="!selectedScheduleId" class="table-card detail-empty">
            <span class="material-symbols-rounded">touch_app</span>
            <p>เลือกรอบเดินทางจากรายการด้านซ้าย<br />เพื่อจัดสตาฟประจำรอบ</p>
          </div>

          <template v-else>
            <!-- Round header -->
            <div class="table-card round-header-card" v-if="activeScheduleMeta">
              <div class="round-header-top">
                <div>
                  <h2 class="round-title">{{ activeScheduleMeta.trip_title }}</h2>
                  <p class="round-subtitle">
                    <span class="material-symbols-rounded">event</span>{{ roundDetails?.departure?.date_label || scheduleDateRange(activeScheduleMeta) }}
                  </p>
                </div>
                <div class="round-header-pills">
                  <span v-if="countdownLabel" class="meta-pill countdown">
                    <span class="material-symbols-rounded">hourglass_top</span>{{ countdownLabel }}
                  </span>
                  <span class="meta-pill status" :class="`status-${activeScheduleMeta.status || 'unknown'}`">
                    <span class="material-symbols-rounded">radio_button_checked</span>{{ statusLabel(activeScheduleMeta.status) }}
                  </span>
                </div>
              </div>
              <div class="schedule-meta">
                <span class="meta-pill"><span class="material-symbols-rounded">place</span>{{ activeScheduleMeta.trip_location || '-' }}</span>
                <span class="meta-pill"><span class="material-symbols-rounded">directions_car</span>{{ vehicleLabel(activeScheduleMeta.vehicle, activeScheduleMeta.transport_type) }}</span>
                <span class="meta-pill"><span class="material-symbols-rounded">groups</span>จองแล้ว {{ activeScheduleMeta.active_bookings_count ?? activeScheduleMeta.booked_seats ?? 0 }} รายการ</span>
                <span class="meta-pill"><span class="material-symbols-rounded">event_seat</span>{{ activeScheduleMeta.booked_seats ?? 0 }}/{{ activeScheduleMeta.total_seats ?? 0 }} ที่นั่ง</span>
                <span v-if="roundDetails?.departure?.is_charter" class="meta-pill"><span class="material-symbols-rounded">workspace_premium</span>เหมาคัน</span>
              </div>
              <!-- รถหลายรอบออกคืนก่อนวันทริป — คนจัดสตาฟต้องเห็นวัน-เวลาที่รถออกจริง -->
              <p v-if="departureLine" class="depart-line" :class="{ warn: roundDetails?.departure?.departs_before_trip_day }">
                <span class="material-symbols-rounded">{{ roundDetails?.departure?.is_flight ? 'flight_takeoff' : 'schedule' }}</span>
                {{ departureLine }}
              </p>
            </div>

            <!-- Round detail -->
            <div class="table-card round-detail-card" v-if="roundDetails">
              <div class="section-head">
                <span class="material-symbols-rounded">info</span>
                <strong>รายละเอียดรอบเดินทาง</strong>
                <button type="button" class="collapse-btn" @click="detailsOpen = !detailsOpen">
                  <span class="material-symbols-rounded">{{ detailsOpen ? 'expand_less' : 'expand_more' }}</span>
                  {{ detailsOpen ? 'ย่อ' : 'ดูรายละเอียด' }}
                </button>
              </div>

              <template v-if="detailsOpen">
                <div class="stat-row">
                  <div class="stat-tile">
                    <span class="material-symbols-rounded">group</span>
                    <div>
                      <p>ผู้โดยสาร</p>
                      <strong>{{ roundDetails.people.passengers_count }} คน</strong>
                      <span v-if="roundDetails.people.join_trip_passengers" class="stat-sub">จอยทริป {{ roundDetails.people.join_trip_passengers }} คน</span>
                    </div>
                  </div>
                  <div class="stat-tile">
                    <span class="material-symbols-rounded">receipt_long</span>
                    <div>
                      <p>ใบจอง</p>
                      <strong>{{ roundDetails.people.confirmed_bookings + roundDetails.people.pending_bookings }} ใบ</strong>
                      <span v-if="roundDetails.people.pending_bookings" class="stat-sub warn">รอชำระ {{ roundDetails.people.pending_bookings }} ใบ</span>
                      <span v-else class="stat-sub">ยืนยันครบแล้ว</span>
                    </div>
                  </div>
                  <div class="stat-tile">
                    <span class="material-symbols-rounded">how_to_reg</span>
                    <div>
                      <p>เช็คอินแล้ว</p>
                      <strong>{{ roundDetails.people.checked_in_passengers }}/{{ roundDetails.people.passengers_count }}</strong>
                      <span class="stat-sub">{{ roundDetails.people.checked_in_bookings }} ใบจอง</span>
                    </div>
                  </div>
                  <div class="stat-tile">
                    <span class="material-symbols-rounded">hourglass_empty</span>
                    <div>
                      <p>คิวรอที่นั่ง</p>
                      <strong>{{ roundDetails.people.waitlist_waiting + roundDetails.people.waitlist_offered }} คน</strong>
                      <span v-if="roundDetails.people.waitlist_offered" class="stat-sub warn">ยื่นสิทธิ์อยู่ {{ roundDetails.people.waitlist_offered }}</span>
                      <span v-else class="stat-sub">ยังไม่ได้ยื่นสิทธิ์</span>
                    </div>
                  </div>
                </div>

                <div class="fact-grid">
                  <div v-if="guaranteeInfo" class="fact">
                    <span class="fact-label">การันตีออกเดินทาง</span>
                    <span class="fact-value">
                      <span class="guarantee-dot" :class="roundDetails.people.departure_status"></span>
                      {{ guaranteeInfo }}
                    </span>
                  </div>
                  <div class="fact">
                    <span class="fact-label">ราคาต่อคน</span>
                    <span class="fact-value">{{ money(roundDetails.money.price_per_person) }} บาท</span>
                  </div>
                  <div class="fact">
                    <span class="fact-label">ยอดขายรอบนี้</span>
                    <span class="fact-value">{{ money(roundDetails.money.total_amount) }} บาท</span>
                  </div>
                  <div class="fact">
                    <span class="fact-label">ค้างชำระ</span>
                    <span class="fact-value" :class="{ warn: roundDetails.money.outstanding_amount > 0 }">
                      {{ money(roundDetails.money.outstanding_amount) }} บาท
                      <small v-if="roundDetails.money.unpaid_bookings">({{ roundDetails.money.unpaid_bookings }} ใบ)</small>
                    </span>
                  </div>
                  <div v-if="roundDetails.vehicle?.driver_name" class="fact">
                    <span class="fact-label">คนขับ</span>
                    <span class="fact-value">
                      {{ roundDetails.vehicle.driver_name }}
                      <a v-if="roundDetails.vehicle.driver_phone" :href="`tel:${roundDetails.vehicle.driver_phone}`" class="phone-link">
                        <span class="material-symbols-rounded">call</span>{{ roundDetails.vehicle.driver_phone }}
                      </a>
                    </span>
                  </div>
                  <div v-if="roundDetails.vehicle?.capacity" class="fact">
                    <span class="fact-label">ความจุรถ</span>
                    <span class="fact-value">{{ roundDetails.vehicle.capacity }} ที่นั่ง<small v-if="roundDetails.vehicle.color"> · สี{{ roundDetails.vehicle.color }}</small></span>
                  </div>
                  <div v-if="roundDetails.people.join_trip_enabled" class="fact">
                    <span class="fact-label">โควตาจอยทริป</span>
                    <span class="fact-value">
                      {{ roundDetails.people.join_trip_booked_seats }}<template v-if="roundDetails.people.join_trip_seats !== null">/{{ roundDetails.people.join_trip_seats }}</template>
                      <small v-if="roundDetails.people.join_trip_seats === null"> (ไม่จำกัด)</small>
                    </span>
                  </div>
                  <div class="fact">
                    <span class="fact-label">กำหนดการ</span>
                    <span class="fact-value" v-if="roundDetails.itinerary.total">
                      ผ่านแล้ว {{ roundDetails.itinerary.reached }}/{{ roundDetails.itinerary.total }}
                      <small v-if="roundDetails.itinerary.next"> · ถัดไป {{ roundDetails.itinerary.next }}</small>
                    </span>
                    <span class="fact-value muted" v-else>ยังไม่ได้ลงกำหนดการ</span>
                  </div>
                  <div v-if="roundDetails.trip.difficulty || roundDetails.trip.duration_days" class="fact">
                    <span class="fact-label">ลักษณะทริป</span>
                    <span class="fact-value">
                      {{ difficultyLabel(roundDetails.trip.difficulty) }}
                      <small v-if="roundDetails.trip.duration_days"> · {{ roundDetails.trip.duration_days }} วัน</small>
                    </span>
                  </div>
                </div>

                <!-- Flight rounds meet at the airport, they have no pickup run -->
                <div v-if="roundDetails.departure.is_flight" class="sub-block">
                  <div class="sub-head"><span class="material-symbols-rounded">flight</span> จุดนัดพบและขาบิน</div>
                  <div class="fact-grid">
                    <div class="fact">
                      <span class="fact-label">จุดนัดพบ</span>
                      <span class="fact-value">
                        {{ roundDetails.departure.meeting_point || '—' }}
                        <a v-if="roundDetails.departure.meeting_map_url" :href="roundDetails.departure.meeting_map_url" target="_blank" rel="noopener" class="map-link">
                          <span class="material-symbols-rounded">map</span>แผนที่
                        </a>
                      </span>
                    </div>
                    <div class="fact">
                      <span class="fact-label">เวลานัดพบ</span>
                      <span class="fact-value">{{ roundDetails.departure.meeting_time ? `${roundDetails.departure.meeting_time} น.` : '—' }}</span>
                    </div>
                    <div v-if="roundDetails.departure.baggage_allowance" class="fact">
                      <span class="fact-label">น้ำหนักกระเป๋า</span>
                      <span class="fact-value">{{ roundDetails.departure.baggage_allowance }}</span>
                    </div>
                  </div>
                  <div v-if="flightLegs.length" class="flight-legs">
                    <span v-for="(leg, i) in flightLegs" :key="i" class="flight-chip">
                      <span class="material-symbols-rounded">{{ leg.direction === 'return' ? 'flight_land' : 'flight_takeoff' }}</span>
                      {{ leg.text }}
                    </span>
                  </div>
                </div>

                <!-- Pickup run: who gets on where, so the round can be split between staff -->
                <div v-else class="sub-block">
                  <div class="sub-head">
                    <span class="material-symbols-rounded">pin_drop</span> จุดขึ้นรถ
                    <span class="sub-count">{{ roundDetails.pickups.points.length }} จุด</span>
                  </div>
                  <div v-if="roundDetails.pickups.points.length" class="pickup-list">
                    <div v-for="point in roundDetails.pickups.points" :key="point.id" class="pickup-row" :class="{ empty: !point.passengers_count }">
                      <div class="pickup-main">
                        <span class="pickup-label">{{ point.label }}</span>
                        <span class="pickup-meta">
                          <template v-if="point.region_label">{{ point.region_label }}</template>
                          <template v-if="point.pickup_time"> · {{ point.pickup_time }} น.</template>
                        </span>
                      </div>
                      <span class="pickup-count" :class="{ zero: !point.passengers_count }">
                        <span class="material-symbols-rounded">person</span>{{ point.passengers_count }}
                      </span>
                    </div>
                  </div>
                  <p v-else class="sub-empty">รอบนี้ยังไม่ได้ตั้งจุดขึ้นรถ</p>

                  <div v-if="roundDetails.pickups.custom.length" class="custom-pickups">
                    <span v-for="(item, i) in roundDetails.pickups.custom" :key="i" class="custom-chip" :class="item.status">
                      <span class="material-symbols-rounded">add_location_alt</span>
                      {{ item.label }} · {{ item.passengers_count }} คน
                      <small>{{ customPickupStatusLabel(item.status) }}</small>
                    </span>
                  </div>
                  <p v-if="roundDetails.pickups.unassigned_passengers" class="sub-warn">
                    <span class="material-symbols-rounded">help</span>
                    ยังไม่ระบุจุดขึ้นรถ {{ roundDetails.pickups.unassigned_passengers }} คน
                  </p>
                </div>

                <!-- Counts only — the names behind them live on the passenger manifest -->
                <div class="sub-block">
                  <div class="sub-head"><span class="material-symbols-rounded">health_and_safety</span> ข้อควรดูแล</div>
                  <div v-if="careChips.length" class="care-row">
                    <span v-for="chip in careChips" :key="chip.label" class="care-chip" :class="chip.tone">
                      <span class="material-symbols-rounded">{{ chip.icon }}</span>{{ chip.label }} {{ chip.count }} คน
                    </span>
                  </div>
                  <p v-else class="sub-empty">ไม่มีข้อมูลที่ต้องดูแลเป็นพิเศษในรอบนี้</p>
                </div>
              </template>
            </div>

            <!-- Assigned staff -->
            <div class="table-card assigned-card">
              <div class="section-head">
                <span class="material-symbols-rounded">how_to_reg</span>
                <strong>สตาฟประจำรอบนี้</strong>
                <span class="section-count">{{ selectedStaff.length }} คน</span>
                <button
                  v-if="selectedStaff.length"
                  type="button"
                  class="btn-release"
                  @click="releaseAllStaff"
                  :disabled="releasing"
                >
                  <span class="material-symbols-rounded">{{ releasing ? 'sync' : 'restart_alt' }}</span>
                  รีเซ็ตสตาฟรอบนี้
                </button>
              </div>
              <p v-if="isFinishedRound && selectedStaff.length" class="release-hint">
                <span class="material-symbols-rounded">schedule</span>
                รอบนี้จบแล้ว — ระบบจะปลดสตาฟให้อัตโนมัติตอนตี 3 หรือกดรีเซ็ตเองได้เลย
              </p>
              <div v-if="selectedStaff.length" class="assigned-list">
                <div v-for="staff in selectedStaff" :key="staff.id" class="assigned-chip">
                  <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="chip-avatar" />
                  <div v-else class="chip-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
                  <div class="chip-info">
                    <span class="chip-name">{{ staff.name }}<span v-if="staff.nickname" class="chip-nick"> ({{ staff.nickname }})</span></span>
                    <span v-if="staff.has_staff_role === false" class="chip-warn" title="บัญชีนี้ไม่ได้มีสิทธิ์สตาฟแล้ว — ตั้งสิทธิ์ใหม่ที่หน้าผู้ใช้งาน หรือนำออกจากรอบนี้">
                      <span class="material-symbols-rounded">warning</span> ไม่มีสิทธิ์สตาฟแล้ว
                    </span>
                    <a v-if="staff.phone" :href="`tel:${staff.phone}`" class="phone-link">
                      <span class="material-symbols-rounded">call</span>{{ staff.phone }}
                    </a>
                  </div>
                  <button type="button" class="chip-remove" @click="removeStaff(staff.id)" title="นำออกจากรอบนี้">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
              </div>
              <div v-else class="assigned-empty">
                <span class="material-symbols-rounded">person_off</span>
                ยังไม่มีสตาฟในรอบนี้ — เลือกจากรายชื่อด้านล่าง
              </div>

              <!-- Staff released after the round ended — kept as a record of who worked it. -->
              <div v-if="releasedStaff.length" class="released-block">
                <div class="released-head">
                  <span class="material-symbols-rounded">history</span>
                  ปลดหลังจบทริปแล้ว {{ releasedStaff.length }} คน
                </div>
                <div class="released-list">
                  <span v-for="staff in releasedStaff" :key="staff.id" class="released-chip">
                    {{ staff.nickname || staff.name }}
                    <span v-if="staff.released_at" class="released-date">· {{ formatDate(staff.released_at) }}</span>
                  </span>
                </div>
              </div>
            </div>

            <!-- Staff picker -->
            <div class="table-card pick-card">
              <div class="section-head">
                <span class="material-symbols-rounded">person_add</span>
                <strong>เลือกสตาฟเพิ่ม</strong>
                <div class="search-box pick-search">
                  <span class="material-symbols-rounded">search</span>
                  <input v-model="search" placeholder="ค้นหาสตาฟ ชื่อ / ชื่อเล่น / เบอร์โทร" />
                  <button v-if="search" type="button" class="search-clear" @click="search = ''" title="ล้างคำค้นหา">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
              </div>
              <div class="staff-pick-list">
                <div v-for="staff in availableStaff" :key="staff.id" class="staff-pick-row">
                  <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="staff-avatar" />
                  <div v-else class="staff-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
                  <div class="pick-info">
                    <span class="pick-name">{{ staff.name }}<span v-if="staff.nickname" class="chip-nick"> ({{ staff.nickname }})</span></span>
                    <div class="pick-meta">
                      <a v-if="staff.phone" :href="`tel:${staff.phone}`" class="phone-link">
                        <span class="material-symbols-rounded">call</span>{{ staff.phone }}
                      </a>
                      <span class="count-pill">{{ staff.assigned_schedules_count || 0 }} รอบ</span>
                      <span class="rating-pill" :class="{ empty: !staff.avg_staff_rating }">
                        <span class="material-symbols-rounded">star</span>{{ formatRating(staff.avg_staff_rating) }}
                        <span v-if="staff.total_staff_reviews" class="review-count">({{ staff.total_staff_reviews }})</span>
                      </span>
                    </div>
                  </div>
                  <button type="button" class="btn-add" @click="addStaff(staff.id)">
                    <span class="material-symbols-rounded">add</span> เพิ่ม
                  </button>
                </div>
                <div v-if="!availableStaff.length" class="picker-empty small">
                  <span class="material-symbols-rounded">{{ search ? 'search_off' : 'done_all' }}</span>
                  <p>{{ search ? 'ไม่พบสตาฟที่ค้นหา' : 'สตาฟทุกคนถูกเลือกแล้ว' }}</p>
                </div>
              </div>
            </div>

            <!-- Save bar -->
            <div class="save-bar" :class="{ dirty: isDirty }">
              <span v-if="isDirty" class="dirty-note">
                <span class="material-symbols-rounded">warning</span> มีการแก้ไขที่ยังไม่บันทึก
              </span>
              <span v-else class="saved-note">
                <span class="material-symbols-rounded">check_circle</span> ข้อมูลล่าสุดถูกบันทึกแล้ว
              </span>
              <button v-if="isDirty" class="btn-secondary" @click="resetSelection" :disabled="saving">
                <span class="material-symbols-rounded">undo</span> ยกเลิกการแก้ไข
              </button>
              <button class="btn-primary" @click="saveAssignments" :disabled="saving || !isDirty">
                <span class="material-symbols-rounded">{{ saving ? 'sync' : 'save' }}</span>
                บันทึกการมอบหมายสตาฟ
              </button>
            </div>
          </template>
        </section>
      </div>
    </template>

    <!-- ─── ROSTER TAB ──────────────────────────────────────── -->
    <template v-if="activeTab === 'roster'">
      <div class="table-card filters-card">
        <div class="filters-row">
          <div class="form-group">
            <label>วันเริ่มต้น</label>
            <input type="date" v-model="rosterFrom" @change="loadRoster" />
          </div>
          <div class="form-group">
            <label>วันสิ้นสุด</label>
            <input type="date" v-model="rosterTo" @change="loadRoster" />
          </div>
          <button class="btn-secondary" @click="loadRoster" :disabled="rosterLoading">
            <span class="material-symbols-rounded">refresh</span> โหลด
          </button>
        </div>
      </div>

      <div v-if="rosterLoading" class="table-card"><div class="loading-state"><div class="spinner"></div></div></div>

      <div v-else-if="!rosterSchedules.length" class="table-card empty-state" style="padding:40px;text-align:center;">
        <span class="material-symbols-rounded" style="font-size:40px;color:#94a3b8;">calendar_month</span>
        <p style="color:#64748b;margin-top:8px;">ไม่มีรอบเดินทางในช่วงนี้</p>
      </div>

      <div v-else class="roster-wrapper">
        <!-- Staff legend -->
        <div class="roster-legend">
          <div v-for="staff in rosterStaff" :key="staff.id" class="legend-item">
            <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="legend-avatar" />
            <div v-else class="legend-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
            <div class="legend-info">
              <span class="legend-name">{{ staff.name }}</span>
              <span v-if="staff.nickname" class="legend-nick">{{ staff.nickname }}</span>
              <a v-if="staff.phone" :href="`tel:${staff.phone}`" class="legend-phone">
                <span class="material-symbols-rounded">call</span>{{ staff.phone }}
              </a>
            </div>
          </div>
        </div>

        <!-- Schedule timetable -->
        <div class="table-card roster-table-card">
          <div class="table-container">
            <table class="data-table roster-table">
              <thead>
                <tr>
                  <th class="roster-schedule-col">รอบเดินทาง</th>
                  <th class="roster-date-col">วันที่</th>
                  <th v-for="staff in rosterStaff" :key="staff.id" class="roster-staff-col">
                    <div class="roster-th-staff">
                      <img v-if="staff.avatar_url" :src="staff.avatar_url" :alt="staff.name" class="roster-th-avatar" />
                      <div v-else class="roster-th-avatar fallback">{{ staff.name?.charAt(0)?.toUpperCase() }}</div>
                      <span>{{ staff.nickname || staff.name }}</span>
                    </div>
                  </th>
                  <th v-if="!rosterStaff.length" class="empty-state" style="padding:16px;">ไม่มีสตาฟในช่วงนี้</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="sch in rosterSchedules" :key="sch.id">
                  <td>
                    <div class="roster-trip-cell">
                      <span class="roster-trip-name">{{ sch.trip_title }}</span>
                      <span v-if="sch.trip_location" class="roster-trip-loc">
                        <span class="material-symbols-rounded">place</span>{{ sch.trip_location }}
                      </span>
                    </div>
                  </td>
                  <td>
                    <div class="roster-date-cell">
                      <span>{{ formatDate(sch.departure_date) }}</span>
                      <span v-if="sch.return_date && sch.return_date !== sch.departure_date" class="muted">→ {{ formatDate(sch.return_date) }}</span>
                    </div>
                  </td>
                  <td v-for="staff in rosterStaff" :key="staff.id" class="roster-cell">
                    <span v-if="isAssigned(sch.id, staff.id)" class="assigned-badge">
                      <span class="material-symbols-rounded">check_circle</span>
                    </span>
                    <span v-else class="unassigned-dot">—</span>
                  </td>
                  <td v-if="!rosterStaff.length"></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();

const activeTab = ref('assign');
const loading = ref(false);
const saving = ref(false);
const search = ref('');
const scheduleSearch = ref('');
const scheduleScope = ref('upcoming');
const onlyMissing = ref(false);
const selectedScheduleId = ref('');
const detailsOpen = ref(true);
const detailPanel = ref(null);

const releasing = ref(false);

const schedules = ref([]);
const staffUsers = ref([]);
const selectedScheduleMeta = ref(null);
const selectedStaffIds = ref([]);
const originalStaffIds = ref([]);
const releasedStaff = ref([]);
const assignedStaffDetails = ref([]);

// Roster state
const rosterLoading = ref(false);
const rosterFrom = ref(todayIso());
const rosterTo = ref(addDays(todayIso(), 29));
const rosterStaff = ref([]);
const rosterSchedules = ref([]);
const rosterAssignments = ref({});

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function addDays(isoDate, days) {
  const d = new Date(isoDate);
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

const todayLabel = computed(() => {
  return new Date().toLocaleDateString('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
});

const todaySchedules = computed(() => {
  const today = todayIso();
  return schedules.value.filter((s) => s.departure_date === today || (s.departure_date <= today && s.return_date >= today));
});

const scheduleOptions = computed(() => {
  const keyword = scheduleSearch.value.trim().toLowerCase();
  const list = schedules.value || [];
  if (!keyword) return list;
  return list.filter((schedule) => {
    const haystack = [
      schedule.trip?.title,
      schedule.trip?.location,
      schedule.departure_date,
      schedule.return_date,
      schedule.vehicle?.name,
      schedule.vehicle?.license_plate,
      schedule.transport_type,
      schedule.status,
    ].filter(Boolean).join(' ').toLowerCase();
    return haystack.includes(keyword);
  });
});

// Apply the "ยังไม่มีสตาฟ" toggle on top of the search results.
const filteredSchedules = computed(() => {
  if (!onlyMissing.value) return scheduleOptions.value;
  return scheduleOptions.value.filter((schedule) => Number(schedule.assigned_staff_count || 0) === 0);
});

// Group filtered schedules into Trip → rounds, trips ordered by earliest round.
const groupedTrips = computed(() => {
  const groups = new Map();
  for (const schedule of filteredSchedules.value) {
    const tripId = schedule.trip?.id ?? `none-${schedule.id}`;
    if (!groups.has(tripId)) {
      groups.set(tripId, {
        id: tripId,
        title: schedule.trip?.title || 'ไม่ระบุทริป',
        location: schedule.trip?.location || '',
        rounds: [],
      });
    }
    groups.get(tripId).rounds.push(schedule);
  }
  const list = [...groups.values()];
  for (const group of list) {
    group.rounds.sort((a, b) => String(a.departure_date).localeCompare(String(b.departure_date)));
    group.missingStaff = group.rounds.filter((r) => !Number(r.assigned_staff_count || 0)).length;
  }
  list.sort((a, b) => String(a.rounds[0]?.departure_date).localeCompare(String(b.rounds[0]?.departure_date)));
  return list;
});

const visibleSchedulesCount = computed(() => filteredSchedules.value.length);

const selectedSchedule = computed(() => {
  const id = Number(selectedScheduleId.value);
  return schedules.value.find((schedule) => Number(schedule.id) === id) || null;
});

const activeScheduleMeta = computed(() => {
  // ระหว่างที่รอบใหม่ยังโหลดไม่เสร็จ meta ของรอบเก่ายังค้างอยู่ — ต้องไม่เอา
  // รายละเอียดของรอบหนึ่งไปแปะหัวข้อของอีกรอบ
  if (selectedScheduleMeta.value && Number(selectedScheduleMeta.value.id) === Number(selectedScheduleId.value)) {
    return selectedScheduleMeta.value;
  }
  if (!selectedSchedule.value) return null;
  return {
    id: selectedSchedule.value.id,
    trip_title: selectedSchedule.value.trip?.title || 'ไม่ระบุทริป',
    trip_location: selectedSchedule.value.trip?.location,
    departure_date: selectedSchedule.value.departure_date,
    return_date: selectedSchedule.value.return_date,
    status: selectedSchedule.value.status,
    transport_type: selectedSchedule.value.transport_type,
    vehicle: selectedSchedule.value.vehicle,
    total_seats: selectedSchedule.value.total_seats,
    booked_seats: selectedSchedule.value.booked_seats,
    available_seats: selectedSchedule.value.available_seats,
    active_bookings_count: selectedSchedule.value.active_bookings_count,
    assigned_staff_count: selectedSchedule.value.assigned_staff_count,
  };
});

const selectedStaff = computed(() => {
  const ids = new Set(selectedStaffIds.value.map(Number));
  return staffUsers.value.filter((staff) => ids.has(Number(staff.id)));
});

const isFinishedRound = computed(() => {
  const meta = activeScheduleMeta.value;
  if (!meta) return false;
  const endsOn = meta.return_date || meta.departure_date;
  return meta.status === 'cancelled' || (!!endsOn && String(endsOn) < todayIso());
});

const unassignedSchedulesCount = computed(() => {
  return scheduleOptions.value.filter((schedule) => Number(schedule.assigned_staff_count || 0) === 0).length;
});

const filteredStaff = computed(() => {
  const keyword = search.value.trim().toLowerCase();
  if (!keyword) return staffUsers.value;
  return staffUsers.value.filter((staff) => {
    const haystack = `${staff.name || ''} ${staff.nickname || ''} ${staff.email || ''} ${staff.phone || ''}`.toLowerCase();
    return haystack.includes(keyword);
  });
});

const availableStaff = computed(() => filteredStaff.value.filter(
  (staff) => !isStaffSelected(staff.id) && staff.has_staff_role !== false,
));

const isDirty = computed(() => {
  const current = [...selectedStaffIds.value.map(Number)].sort((a, b) => a - b);
  const original = [...originalStaffIds.value.map(Number)].sort((a, b) => a - b);
  return JSON.stringify(current) !== JSON.stringify(original);
});

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
};

const scheduleDateRange = (schedule) => {
  if (!schedule) return '-';
  const start = formatDate(schedule.departure_date);
  const end = formatDate(schedule.return_date);
  return schedule.return_date && schedule.return_date !== schedule.departure_date ? `${start} - ${end}` : start;
};

// departs_at มาเป็นสตริงเวลาไทย 'Y-m-d H:i:s' — ตัดเอาเฉพาะ H:i ห้ามแปลงโซนเวลา
const scheduleTimeLabel = (schedule) => String(schedule?.departs_at || '').slice(11, 16);

const vehicleLabel = (vehicle, fallbackType = '') => {
  if (!vehicle) return fallbackType || '-';
  const plate = vehicle.license_plate ? ` (${vehicle.license_plate})` : '';
  return `${vehicle.name || vehicle.type || fallbackType || '-'}${plate}`;
};

const statusLabel = (status) => {
  const labels = { open: 'เปิดรับจอง', closed: 'ปิดรับจอง', full: 'เต็มแล้ว', cancelled: 'ยกเลิก' };
  return labels[status] || status || '-';
};

const formatRating = (rating) => {
  if (!rating) return '-';
  return Number(rating).toFixed(2).replace(/\.?0+$/, '');
};

// รายละเอียดรอบมาจาก endpoint ของรอบเท่านั้น — รายการฝั่งซ้ายไม่มีข้อมูลชุดนี้
const roundDetails = computed(() => activeScheduleMeta.value?.details || null);

const countdownLabel = computed(() => {
  const days = roundDetails.value?.departure?.days_until;
  if (days === null || days === undefined) return '';
  if (days === 0) return 'วันนี้';
  if (days === 1) return 'พรุ่งนี้';
  if (days < 0) return `ผ่านมาแล้ว ${Math.abs(days)} วัน`;
  return `อีก ${days} วัน`;
});

// departs_at เก็บ "เวลาไทย" ไว้ในคอลัมน์ชนิด UTC — อ่านวันกับเวลาจากค่าที่เซิร์ฟเวอร์
// ตัดมาให้แล้วเท่านั้น ห้ามโยนเข้า new Date() แล้วฟอร์แมต ไม่งั้นเพี้ยนไป 7 ชั่วโมง
const departureLine = computed(() => {
  const dep = roundDetails.value?.departure;
  if (!dep) return '';
  const time = dep.depart_time_label ? `เวลา ${dep.depart_time_label} น.` : '';
  if (dep.departs_before_trip_day) {
    const date = formatDate(String(dep.departs_at).slice(0, 10));
    const early = dep.days_departing_early > 1 ? ` (ก่อนวันทริป ${dep.days_departing_early} วัน)` : ' (คืนก่อนวันทริป)';
    return `${dep.is_flight ? 'บินออก' : 'รถออก'} ${date} ${time}${early}`.replace(/\s+/g, ' ');
  }
  if (time) return `${dep.is_flight ? 'บินออก' : 'ออกเดินทาง'}${time.replace('เวลา', ' เวลา')}`;
  return 'ยังไม่ได้ระบุเวลาออกเดินทาง';
});

const guaranteeInfo = computed(() => {
  const people = roundDetails.value?.people;
  if (!people?.departure_status) return '';
  const labels = {
    guaranteed: 'ออกเดินทางแน่นอน',
    almost_ready: 'ใกล้ครบแล้ว',
    waiting: 'ยังรอเพื่อนร่วมทาง',
  };
  const base = labels[people.departure_status] || people.departure_status;
  return people.seats_to_guarantee
    ? `${base} · ขาดอีก ${people.seats_to_guarantee} ที่นั่ง`
    : base;
});

const flightLegs = computed(() => {
  const legs = roundDetails.value?.departure?.flights;
  if (!legs) return [];
  // depart_at เป็นสตริงเวลาไทยใน JSON ไม่ใช่ timestamp — ตัดอ่านตรง ๆ ห้ามแปลงโซน
  const legTime = (value) => {
    if (!value) return '';
    const raw = String(value).replace('T', ' ');
    const time = raw.slice(11, 16);
    return time ? `${formatDate(raw.slice(0, 10))} ${time} น.` : formatDate(raw.slice(0, 10));
  };
  const describe = (leg, direction) => ({
    direction,
    text: [leg.airline, leg.flight_no, [leg.from, leg.to].filter(Boolean).join(' → '), legTime(leg.depart_at)]
      .filter(Boolean).join(' · '),
  });
  return [
    ...(legs.outbound || []).map((leg) => describe(leg, 'outbound')),
    ...(legs.return || []).map((leg) => describe(leg, 'return')),
  ].filter((leg) => leg.text);
});

const careChips = computed(() => {
  const care = roundDetails.value?.care;
  if (!care) return [];
  return [
    { key: 'allergies', label: 'แพ้อาหาร/ยา', icon: 'e911_emergency', tone: 'danger' },
    { key: 'health_notes', label: 'มีโรคประจำตัว', icon: 'medical_information', tone: 'danger' },
    { key: 'halal_food', label: 'อาหารฮาลาล', icon: 'restaurant', tone: 'info' },
    { key: 'missing_emergency_contact', label: 'ไม่มีเบอร์ฉุกเฉิน', icon: 'phone_missed', tone: 'warn' },
  ].filter((chip) => Number(care[chip.key] || 0) > 0)
    .map((chip) => ({ ...chip, count: care[chip.key] }));
});

const money = (amount) => Number(amount || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });

const difficultyLabel = (difficulty) => {
  const labels = { easy: 'ง่าย', moderate: 'ปานกลาง', hard: 'ยาก', extreme: 'ยากมาก' };
  return labels[difficulty] || difficulty || 'ไม่ระบุ';
};

const customPickupStatusLabel = (status) => {
  const labels = { pending: 'รออนุมัติ', approved: 'อนุมัติแล้ว', rejected: 'ไม่อนุมัติ' };
  return labels[status] || '';
};

const normalizeStaffFromUsersApi = (users = []) => {
  return users.map((user) => ({
    id: user.id,
    name: user.name,
    nickname: user.nickname,
    email: user.email,
    phone: user.phone,
    avatar_url: user.avatar_url,
    assigned_schedules_count: user.assigned_schedules_count || 0,
    total_staff_reviews: user.total_staff_reviews || 0,
    avg_staff_rating: user.avg_staff_rating ?? null,
  }));
};

const loadStaffUsers = async () => {
  try {
    const staffRes = await admin.fetchStaffUsers({ per_page: 200 });
    const primaryData = staffRes?.data || [];
    if (primaryData.length) {
      staffUsers.value = primaryData;
      return;
    }
    await admin.fetchUsers({ role: 'staff', per_page: 200 });
    staffUsers.value = normalizeStaffFromUsersApi(admin.users.data || []);
  } catch (e) {
    await admin.fetchUsers({ role: 'staff', per_page: 200 });
    staffUsers.value = normalizeStaffFromUsersApi(admin.users.data || []);
  }
};

const loadData = async () => {
  loading.value = true;
  try {
    const params = { per_page: 500 };
    if (scheduleScope.value === 'upcoming') params.upcoming = 1;

    await admin.fetchSchedules(params);
    schedules.value = admin.schedules.data || [];

    await loadStaffUsers();

    // Attach today's staff to today's schedules for the panel
    await attachTodayStaff();

    const selectedStillExists = schedules.value.some((schedule) => Number(schedule.id) === Number(selectedScheduleId.value));
    if ((!selectedScheduleId.value || !selectedStillExists) && schedules.value.length) {
      selectedScheduleId.value = schedules.value[0].id;
    }

    if (selectedScheduleId.value) {
      await loadAssignedStaff();
    }
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดข้อมูลสตาฟไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const setScope = (scope) => {
  if (scope === scheduleScope.value) return;
  scheduleScope.value = scope;
  loadData();
};

const attachTodayStaff = async () => {
  const today = todayIso();
  const todayIds = schedules.value
    .filter((s) => s.departure_date === today || (s.departure_date <= today && s.return_date >= today))
    .map((s) => s.id);

  for (const id of todayIds) {
    try {
      const res = await admin.fetchScheduleStaff(id);
      const staffList = res.data?.staff || [];
      const sch = schedules.value.find((s) => s.id === id);
      if (sch) sch.assignedStaff = staffList;
    } catch {
      // ignore
    }
  }
};

const selectSchedule = async (scheduleId) => {
  if (Number(scheduleId) === Number(selectedScheduleId.value)) return;
  if (isDirty.value && !confirm('มีการแก้ไขที่ยังไม่บันทึก ต้องการละทิ้งการแก้ไขหรือไม่?')) return;
  selectedScheduleId.value = scheduleId;
  await loadAssignedStaff();
  // On the stacked (mobile) layout the detail panel sits below the list —
  // bring it into view so the chosen round's staff editor is immediately visible.
  if (typeof window !== 'undefined' && window.innerWidth <= 1024) {
    await nextTick();
    detailPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
};

const loadAssignedStaff = async () => {
  if (!selectedScheduleId.value) {
    selectedScheduleMeta.value = null;
    selectedStaffIds.value = [];
    originalStaffIds.value = [];
    releasedStaff.value = [];
    return;
  }
  loading.value = true;
  try {
    const res = await admin.fetchScheduleStaff(selectedScheduleId.value);
    applyStaffPayload(res.data);
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดรายการสตาฟของรอบไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

const applyStaffPayload = (payload) => {
  selectedScheduleMeta.value = payload?.schedule || selectedScheduleMeta.value;
  selectedStaffIds.value = (payload?.staff || []).map((s) => s.id);
  originalStaffIds.value = [...selectedStaffIds.value];
  releasedStaff.value = payload?.released_staff || [];
  assignedStaffDetails.value = payload?.staff || [];
  mergeAssignedStaffStats(assignedStaffDetails.value);
};

const addStaff = (staffId) => {
  if (!selectedScheduleId.value || isStaffSelected(staffId)) return;
  selectedStaffIds.value = [...selectedStaffIds.value, Number(staffId)];
};

const removeStaff = (staffId) => {
  selectedStaffIds.value = selectedStaffIds.value.filter((id) => Number(id) !== Number(staffId));
};

const resetSelection = () => {
  selectedStaffIds.value = [...originalStaffIds.value];
};

const isStaffSelected = (staffId) => selectedStaffIds.value.map(Number).includes(Number(staffId));

// Someone on the round who no longer holds the staff role is missing from the
// staff list, so append them instead of dropping them: otherwise they are
// invisible on screen yet still ride along in every save of this round.
const mergeAssignedStaffStats = (assignedStaff = []) => {
  if (!assignedStaff.length) return;
  const statsById = new Map(assignedStaff.map((staff) => [Number(staff.id), staff]));
  const merged = staffUsers.value.map((staff) => {
    const updated = statsById.get(Number(staff.id));
    if (updated) statsById.delete(Number(staff.id));
    return updated ? { ...staff, ...updated } : staff;
  });
  staffUsers.value = [...merged, ...statsById.values()];
};

const saveAssignments = async () => {
  if (!selectedScheduleId.value) return;
  saving.value = true;
  try {
    const res = await admin.syncScheduleStaff(selectedScheduleId.value, selectedStaffIds.value.map(Number));
    applyStaffPayload(res.data);
    await refreshAfterStaffChange();
    alert('บันทึกการมอบหมายสตาฟสำเร็จ');
  } catch (e) {
    alert(e?.response?.data?.message || 'บันทึกข้อมูลไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
};

const releaseAllStaff = async () => {
  if (!selectedScheduleId.value) return;
  if (!confirm('ปลดสตาฟทุกคนออกจากรอบนี้? ประวัติว่าใครดูแลรอบนี้จะยังถูกเก็บไว้')) return;
  releasing.value = true;
  try {
    const res = await admin.releaseScheduleStaff(selectedScheduleId.value);
    applyStaffPayload(res.data);
    await refreshAfterStaffChange();
    alert(res.message || 'รีเซ็ตสตาฟรอบนี้แล้ว');
  } catch (e) {
    alert(e?.response?.data?.message || 'รีเซ็ตสตาฟไม่สำเร็จ');
  } finally {
    releasing.value = false;
  }
};

const refreshAfterStaffChange = async () => {
  await loadStaffUsers();
  mergeAssignedStaffStats(assignedStaffDetails.value);
  await admin.fetchSchedules({ per_page: 500, ...(scheduleScope.value === 'upcoming' ? { upcoming: 1 } : {}) });
  schedules.value = admin.schedules.data || [];
  await attachTodayStaff();
};

// ─── ROSTER ────────────────────────────────────────────────
const loadRoster = async () => {
  rosterLoading.value = true;
  try {
    const data = await admin.fetchStaffRoster({ from: rosterFrom.value, to: rosterTo.value });
    rosterStaff.value = data.staff || [];
    rosterSchedules.value = data.schedules || [];
    rosterAssignments.value = data.assignments || {};
  } catch (e) {
    alert(e?.response?.data?.message || 'โหลดตารางงานไม่สำเร็จ');
  } finally {
    rosterLoading.value = false;
  }
};

const isAssigned = (scheduleId, staffId) => {
  const ids = rosterAssignments.value[scheduleId] || [];
  return ids.map(Number).includes(Number(staffId));
};

onMounted(loadData);
</script>

<style scoped>
@import url('./admin-shared.css');

.chip-warn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  color: #b45309;
}

.chip-warn .material-symbols-rounded {
  font-size: 14px;
}

/* ── Header ──────────────────────────────────────────────── */
.header-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}

.tab-switcher {
  display: flex;
  background: #f1f5f9;
  border-radius: 12px;
  padding: 3px;
  gap: 2px;
}

.tab-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: #64748b;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}

.tab-btn .material-symbols-rounded { font-size: 17px; }

.tab-btn.active {
  background: #fff;
  color: var(--color-accent);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

/* ── Today Panel ──────────────────────────────────────────── */
.today-panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  padding: 16px 20px;
  margin-bottom: 16px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}

.today-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 800;
  color: #0f172a;
  margin-bottom: 14px;
}

.today-header .material-symbols-rounded { color: var(--color-accent); font-size: 20px; }

.today-count {
  margin-left: auto;
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
  background: #f1f5f9;
  border-radius: 999px;
  padding: 3px 10px;
}

.today-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}

.today-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px;
}

.today-trip-name { font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }

.today-trip-meta {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 10px;
}

.today-trip-meta .material-symbols-rounded { font-size: 14px; color: #94a3b8; }

.today-staff-row { display: flex; flex-direction: column; gap: 8px; }

.today-staff-chip {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 10px;
}

.today-avatar {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}

.today-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 14px;
}

.today-staff-info { display: flex; flex-direction: column; gap: 2px; }
.today-staff-name { font-size: 13px; font-weight: 700; color: #0f172a; }
.today-staff-nick { color: #64748b; font-weight: 600; }

.today-phone {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent);
  text-decoration: none;
}

.today-phone:hover { text-decoration: underline; }
.today-phone .material-symbols-rounded { font-size: 14px; }

.today-no-staff {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #94a3b8;
}

.today-no-staff .material-symbols-rounded { font-size: 16px; }

/* ── Summary grid ─────────────────────────────────────────── */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.summary-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 14px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.summary-card > .material-symbols-rounded {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ecfdf5;
  color: #047857;
}

.summary-card.warning > .material-symbols-rounded { background: #fffbeb; color: #b45309; }
.summary-card p { margin: 0; color: #64748b; font-size: 12px; font-weight: 600; }
.summary-card strong { display: block; color: #0f172a; font-size: 22px; line-height: 1; margin-top: 4px; }

/* warning card doubles as a filter toggle */
button.summary-card {
  width: 100%;
  text-align: left;
  cursor: pointer;
  font: inherit;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}

button.summary-card:hover { border-color: #fcd34d; }

button.summary-card.active {
  background: #fffbeb;
  border-color: #f59e0b;
  box-shadow: 0 0 0 1px #f59e0b;
}

.tap-hint { font-weight: 600; color: #b45309; font-size: 11px; }

/* ── Assign layout (master–detail, natural page flow) ─────── */
.assign-layout {
  display: grid;
  grid-template-columns: minmax(320px, 400px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

/* ── Left: picker (flows in the page, no internal scroll) ── */
.picker {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.picker-head {
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 12px;
}

.seg {
  display: flex;
  background: #f1f5f9;
  border-radius: 10px;
  padding: 3px;
  gap: 3px;
}

.seg button {
  flex: 1;
  padding: 8px 10px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #64748b;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}

.seg button:disabled { cursor: default; }

.seg button.active {
  background: #fff;
  color: var(--color-accent);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.search-box { position: relative; }

.search-clear {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: #94a3b8;
  cursor: pointer;
  transition: all 0.15s;
}

.search-clear:hover { background: #f1f5f9; color: #475569; }
.search-clear .material-symbols-rounded { font-size: 16px; }

.missing-toggle {
  display: flex;
  align-items: center;
  gap: 7px;
  width: 100%;
  padding: 9px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
  color: #475569;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}

.missing-toggle:hover { background: #f8fafc; border-color: #cbd5e1; }
.missing-toggle .material-symbols-rounded { font-size: 17px; }

.missing-toggle.active {
  background: #fffbeb;
  border-color: #f59e0b;
  color: #b45309;
}

.mt-count {
  margin-left: auto;
  background: #94a3b8;
  color: #fff;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  padding: 1px 8px;
  min-width: 18px;
  text-align: center;
}

.missing-toggle.active .mt-count { background: #b45309; }

/* Trip list — flat, always visible */
.trip-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.trip-block {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 12px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.trip-block-head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 2px 4px 10px;
  border-bottom: 1px solid #f1f5f9;
  margin-bottom: 10px;
}

.tbh-text { min-width: 0; flex: 1; }

.tbh-title {
  margin: 0;
  font-size: 14px;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.3;
  word-break: break-word;
}

.tbh-loc {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  margin-top: 3px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}

.tbh-loc .material-symbols-rounded { font-size: 13px; }

.tbh-flag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 800;
  color: #b45309;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 999px;
  padding: 3px 9px;
  white-space: nowrap;
}

.tbh-flag .material-symbols-rounded { font-size: 14px; }

.round-cards {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.round-card {
  display: flex;
  flex-direction: column;
  gap: 7px;
  width: 100%;
  padding: 11px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 11px;
  background: #fff;
  cursor: pointer;
  text-align: left;
  transition: all 0.15s;
}

.round-card:hover { border-color: #cbd5e1; background: #f8fafc; }

.round-card.active {
  border-color: var(--color-accent);
  background: #ecfdf5;
  box-shadow: 0 0 0 1px var(--color-accent);
}

.round-card.missing { border-left: 3px solid #f59e0b; }
.round-card.missing.active { border-left-color: var(--color-accent); }

.rc-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.rc-date {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
}

.rc-date .material-symbols-rounded { font-size: 16px; color: #94a3b8; }
.round-card.active .rc-date .material-symbols-rounded { color: var(--color-accent); }

.rc-status {
  font-size: 11px;
  font-weight: 700;
  color: #475569;
  background: #f1f5f9;
  border-radius: 999px;
  padding: 2px 9px;
  white-space: nowrap;
}

.rc-status.st-open { color: #047857; background: #ecfdf5; }
.rc-status.st-closed, .rc-status.st-full { color: #92400e; background: #fffbeb; }
.rc-status.st-cancelled { color: #b91c1c; background: #fef2f2; }

.rc-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.rc-seats {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}

.rc-seats .material-symbols-rounded { font-size: 14px; color: #94a3b8; }

.rc-staff {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 700;
  color: #047857;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 999px;
  padding: 3px 9px;
  white-space: nowrap;
}

.rc-staff.none { color: #b45309; background: #fffbeb; border-color: #fde68a; }
.rc-staff .material-symbols-rounded { font-size: 13px; }

/* Empty states */
.picker-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 44px 16px;
  background: #fff;
  border: 1px dashed #e2e8f0;
  border-radius: 14px;
  color: #94a3b8;
}

.picker-empty .material-symbols-rounded { font-size: 38px; }
.picker-empty p { margin: 0; font-size: 13px; font-weight: 600; color: #64748b; text-align: center; }
.picker-empty.small { padding: 28px 16px; border: none; }
.picker-empty.small .material-symbols-rounded { font-size: 30px; }

/* ── Right: detail panel ── */
.detail-panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

.detail-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 80px 24px;
  color: #94a3b8;
  text-align: center;
}

.detail-empty .material-symbols-rounded { font-size: 44px; }
.detail-empty p { margin: 0; font-size: 14px; font-weight: 600; color: #64748b; line-height: 1.6; }

.round-header-card { padding: 16px 18px; }

.round-header-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.round-title { margin: 0; font-size: 17px; font-weight: 800; color: #0f172a; }

.round-subtitle {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 4px 0 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--color-accent);
}

.round-subtitle .material-symbols-rounded { font-size: 16px; }

.schedule-meta { display: flex; flex-wrap: wrap; gap: 8px; }

.meta-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #0f172a;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 5px 10px;
}

.meta-pill.status-open { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
.meta-pill.status-closed, .meta-pill.status-full { color: #92400e; background: #fffbeb; border-color: #fde68a; }
.meta-pill.status-cancelled { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
.meta-pill .material-symbols-rounded { font-size: 15px; }

.rc-time { font-weight: 800; color: var(--color-accent); }

.round-header-pills { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.meta-pill.countdown { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }

.depart-line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 10px 0 0;
  font-size: 12.5px;
  font-weight: 700;
  color: #475569;
}

.depart-line .material-symbols-rounded { font-size: 16px; }
.depart-line.warn { color: #b45309; }

/* ── Round detail ────────────────────────────────────────── */
.collapse-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: auto;
  padding: 5px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  background: #fff;
  color: #475569;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.collapse-btn .material-symbols-rounded { font-size: 16px; }
.collapse-btn:hover { background: #f8fafc; }

.stat-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  padding: 14px 16px;
}

.stat-tile {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  padding: 11px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #f8fafc;
}

.stat-tile > .material-symbols-rounded { font-size: 20px; color: var(--color-accent); }
.stat-tile p { margin: 0; font-size: 11px; font-weight: 700; color: #64748b; }
.stat-tile strong { display: block; margin-top: 2px; font-size: 16px; font-weight: 800; color: #0f172a; }

.stat-sub { display: block; margin-top: 2px; font-size: 11px; font-weight: 600; color: #94a3b8; }
.stat-sub.warn { color: #b45309; }

.fact-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(215px, 1fr));
  gap: 10px;
  padding: 0 16px 14px;
}

.fact {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 9px 11px;
  border: 1px solid #f1f5f9;
  border-radius: 10px;
  background: #fff;
}

.fact-label { font-size: 11px; font-weight: 700; color: #94a3b8; }

.fact-value {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
}

.fact-value small { font-size: 11.5px; font-weight: 600; color: #64748b; }
.fact-value.warn { color: #b91c1c; }
.fact-value.muted { color: #94a3b8; font-weight: 600; }

.guarantee-dot { width: 9px; height: 9px; border-radius: 50%; background: #94a3b8; }
.guarantee-dot.guaranteed { background: #059669; }
.guarantee-dot.almost_ready { background: #d97706; }
.guarantee-dot.waiting { background: #dc2626; }

.sub-block { padding: 12px 16px 14px; border-top: 1px solid #f1f5f9; }

.sub-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
  font-size: 12.5px;
  font-weight: 800;
  color: #334155;
}

.sub-head .material-symbols-rounded { font-size: 17px; color: var(--color-accent); }

.sub-count {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
  background: #f1f5f9;
  border-radius: 999px;
  padding: 2px 8px;
}

.sub-empty { margin: 0; font-size: 12.5px; font-weight: 600; color: #94a3b8; }

.sub-warn {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 10px 0 0;
  font-size: 12.5px;
  font-weight: 700;
  color: #b45309;
}

.sub-warn .material-symbols-rounded { font-size: 16px; }

.pickup-list { display: flex; flex-direction: column; gap: 6px; }

.pickup-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 11px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #f8fafc;
}

.pickup-row.empty { background: #fff; border-style: dashed; }
.pickup-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.pickup-label { font-size: 13px; font-weight: 700; color: #0f172a; }
.pickup-meta { font-size: 11.5px; font-weight: 600; color: #64748b; }

.pickup-count {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  flex-shrink: 0;
  font-size: 12.5px;
  font-weight: 800;
  color: #0f172a;
  background: #e2e8f0;
  border-radius: 999px;
  padding: 3px 10px;
}

.pickup-count .material-symbols-rounded { font-size: 15px; }
.pickup-count.zero { color: #94a3b8; background: #f1f5f9; }

.custom-pickups { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }

.custom-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 700;
  color: #0f172a;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 4px 10px;
}

.custom-chip .material-symbols-rounded { font-size: 15px; }
.custom-chip small { font-size: 11px; font-weight: 600; color: #64748b; }
.custom-chip.pending { color: #92400e; background: #fffbeb; border-color: #fde68a; }
.custom-chip.pending small { color: #b45309; }
.custom-chip.rejected { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
.custom-chip.rejected small { color: #dc2626; }

.flight-legs { display: flex; flex-wrap: wrap; gap: 6px; }

.flight-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 700;
  color: #1e3a8a;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  padding: 4px 10px;
}

.flight-chip .material-symbols-rounded { font-size: 15px; }

.map-link {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--color-accent);
  text-decoration: none;
}

.map-link .material-symbols-rounded { font-size: 14px; }

.care-row { display: flex; flex-wrap: wrap; gap: 6px; }

.care-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 700;
  border-radius: 999px;
  padding: 4px 10px;
  color: #0f172a;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
}

.care-chip .material-symbols-rounded { font-size: 15px; }
.care-chip.danger { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
.care-chip.warn { color: #b45309; background: #fffbeb; border-color: #fde68a; }
.care-chip.info { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }

@media (max-width: 1280px) {
  .stat-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 640px) {
  .stat-row { grid-template-columns: 1fr; }
  .fact-grid { grid-template-columns: 1fr; }
  .round-header-pills { justify-content: flex-start; }
}

/* Section heads */
.section-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 14px 16px;
  border-bottom: 1px solid #f1f5f9;
  font-size: 14px;
  color: #0f172a;
  flex-wrap: wrap;
}

.section-head .material-symbols-rounded { font-size: 19px; color: var(--color-accent); }

.section-count {
  font-size: 12px;
  font-weight: 700;
  color: #047857;
  background: #ecfdf5;
  border-radius: 999px;
  padding: 3px 10px;
}

.pick-search { margin-left: auto; min-width: 240px; flex: 1; max-width: 340px; }

/* Assigned chips */
.assigned-list { display: flex; flex-wrap: wrap; gap: 10px; padding: 14px 16px; }

.assigned-chip {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 12px;
  padding: 8px 10px;
}

.chip-avatar {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  object-fit: cover;
  border: 1px solid #d1fae5;
  flex-shrink: 0;
}

.chip-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 13px;
}

.chip-info { display: flex; flex-direction: column; gap: 1px; }
.chip-name { font-size: 13px; font-weight: 700; color: #065f46; }
.chip-nick { color: #64748b; font-weight: 600; }

.chip-remove {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #059669;
  cursor: pointer;
  transition: all 0.15s;
  flex-shrink: 0;
}

.chip-remove:hover { background: #fee2e2; color: #b91c1c; }
.chip-remove .material-symbols-rounded { font-size: 17px; }

.btn-release {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: auto;
  padding: 6px 12px;
  border: 1px solid #fecaca;
  border-radius: 10px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-release:hover { background: #fee2e2; }
.btn-release:disabled { opacity: 0.6; cursor: default; }
.btn-release .material-symbols-rounded { font-size: 16px; color: #b91c1c; }

.release-hint {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  padding: 10px 16px 0;
  font-size: 12px;
  font-weight: 600;
  color: #b45309;
}

.release-hint .material-symbols-rounded { font-size: 15px; }

.released-block {
  border-top: 1px solid #f1f5f9;
  padding: 12px 16px;
}

.released-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 8px;
}

.released-head .material-symbols-rounded { font-size: 15px; color: #94a3b8; }

.released-list { display: flex; flex-wrap: wrap; gap: 6px; }

.released-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  background: #f8fafc;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
}

.released-date { color: #94a3b8; font-weight: 600; }

.assigned-empty {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 18px 16px;
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
}

.assigned-empty .material-symbols-rounded { font-size: 18px; }

/* Staff picker list */
.staff-pick-list { max-height: 460px; overflow-y: auto; }

.staff-pick-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  border-top: 1px solid #f8fafc;
  transition: background 0.15s;
}

.staff-pick-row:hover { background: #f8fafc; }

.staff-avatar {
  width: 36px;
  height: 36px;
  border-radius: 9px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}

.staff-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
}

.pick-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.pick-name { font-size: 13px; font-weight: 700; color: #0f172a; }
.pick-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.phone-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent);
  text-decoration: none;
}

.phone-link:hover { text-decoration: underline; }
.phone-link .material-symbols-rounded { font-size: 14px; }

.count-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #334155;
  font-weight: 600;
  font-size: 11px;
}

.rating-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px;
  border-radius: 999px;
  background: #fff7ed;
  color: #9a3412;
  font-weight: 600;
  font-size: 11px;
}

.rating-pill.empty { background: #f3f4f6; color: #6b7280; }
.rating-pill .material-symbols-rounded { font-size: 13px; }
.review-count { color: #b45309; font-weight: 600; }

.btn-add {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 7px 14px;
  border: 1px solid #a7f3d0;
  border-radius: 10px;
  background: #ecfdf5;
  color: #047857;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
  flex-shrink: 0;
}

.btn-add:hover { background: #d1fae5; }
.btn-add .material-symbols-rounded { font-size: 16px; }

/* Save bar */
.save-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 12px 16px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  position: sticky;
  bottom: 12px;
}

.save-bar.dirty { border-color: #fde68a; }

.dirty-note,
.saved-note {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
  margin-right: auto;
}

.dirty-note { color: #b45309; }
.saved-note { color: #047857; }
.dirty-note .material-symbols-rounded,
.saved-note .material-symbols-rounded { font-size: 17px; }

/* ── Roster ───────────────────────────────────────────────── */
.filters-card { padding: 16px; margin-bottom: 16px; }

.filters-row { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }

.roster-wrapper { display: flex; flex-direction: column; gap: 16px; }

.roster-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  padding: 16px;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 14px;
}

.legend-avatar {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}

.legend-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 15px;
}

.legend-info { display: flex; flex-direction: column; gap: 2px; }
.legend-name { font-size: 13px; font-weight: 800; color: #0f172a; }
.legend-nick { font-size: 11px; font-weight: 700; color: #64748b; }

.legend-phone {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent);
  text-decoration: none;
}

.legend-phone:hover { text-decoration: underline; }
.legend-phone .material-symbols-rounded { font-size: 13px; }

.roster-table-card { overflow: auto; }

.roster-table .roster-schedule-col { min-width: 180px; max-width: 240px; }
.roster-table .roster-date-col { min-width: 130px; white-space: nowrap; }
.roster-table .roster-staff-col { text-align: center; min-width: 100px; }

.roster-th-staff {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 700;
  color: #334155;
}

.roster-th-avatar {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
}

.roster-th-avatar.fallback {
  background: var(--color-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 12px;
}

.roster-trip-cell { display: flex; flex-direction: column; gap: 2px; }
.roster-trip-name { font-size: 13px; font-weight: 700; color: #0f172a; }
.roster-trip-loc {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
}

.roster-trip-loc .material-symbols-rounded { font-size: 12px; }

.roster-date-cell { display: flex; flex-direction: column; gap: 2px; font-size: 12px; font-weight: 600; }

.roster-cell { text-align: center; vertical-align: middle; }

.assigned-badge { display: inline-flex; align-items: center; justify-content: center; color: var(--color-accent); }
.assigned-badge .material-symbols-rounded { font-size: 20px; }
.unassigned-dot { color: #cbd5e1; font-size: 16px; }

/* ── Responsive ───────────────────────────────────────────── */
@media (max-width: 1024px) {
  .assign-layout { grid-template-columns: 1fr; }
}

@media (max-width: 900px) {
  .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .today-grid { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
  .summary-grid { grid-template-columns: 1fr; }
  .tab-switcher { width: 100%; }
  .tab-btn { flex: 1; justify-content: center; }
  .pick-search { min-width: 100%; max-width: 100%; }
  .save-bar { flex-direction: column; align-items: stretch; }
  .dirty-note, .saved-note { margin-right: 0; justify-content: center; }
}
</style>
