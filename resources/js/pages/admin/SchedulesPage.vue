<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">calendar_month</span> รอบเดินทาง</h1>
        <p class="page-subtitle">จัดการรอบเดินทางและตารางวันเดินทาง</p>
      </div>
      <div class="page-actions">
        <div class="page-actions-secondary">
          <button class="btn-secondary" @click="openBulkJoinTripModal()">
            <span class="material-symbols-rounded">group_add</span> จัดการจอยทริป
          </button>
          <button class="btn-secondary" @click="openTemplateManager()">
            <span class="material-symbols-rounded">bookmark</span> เทมเพลตจุดรับ
          </button>
          <button class="btn-secondary" @click="openBatchForm()">
            <span class="material-symbols-rounded">layers</span> สร้างหลายรอบพร้อมกัน
          </button>
        </div>
        <button class="btn-primary" @click="openForm()">
          <span class="material-symbols-rounded">add</span> เพิ่มรอบใหม่
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="filters.search" placeholder="ค้นหาชื่อทริป..." @input="debouncedFetch" />
      </div>
      <select v-model="filters.status" @change="fetchData()">
        <option value="">ทุกสถานะ</option>
        <option value="open">เปิด</option>
        <option value="closed">ปิด</option>
        <option value="full">เต็ม</option>
        <option value="cancelled">ยกเลิก</option>
      </select>
      <label class="checkbox-filter">
        <input type="checkbox" v-model="filters.upcoming" @change="fetchData()" />
        <span>เฉพาะที่กำลังจะถึง</span>
      </label>
    </div>

    <!-- Trip cards -->
    <div class="accordion-loading" v-if="admin.loading"><div class="spinner"></div></div>
    <template v-else>
      <div v-if="!groupedByTrip.length" class="empty-card">ไม่พบรอบเดินทาง</div>

      <div v-else class="trip-cards-grid">
        <div v-for="group in groupedByTrip" :key="group.trip_id" class="trip-card" @click="openTripSchedules(group)">
          <div class="trip-card-img">
            <img v-if="group.trip_image" :src="group.trip_image" :alt="group.trip_title" loading="lazy" />
            <div v-else class="trip-card-img--ph"><span class="material-symbols-rounded">image</span></div>
            <span class="trip-card-count"><span class="material-symbols-rounded icon-xs">event</span> {{ group.schedules.length }} รอบ</span>
          </div>
          <div class="trip-card-body">
            <h3 class="trip-card-title">{{ group.trip_title }}</h3>
            <div class="trip-card-next">
              <span class="material-symbols-rounded icon-xs">calendar_month</span>
              <span v-if="group.nextDate">รอบถัดไป {{ group.nextDate }}</span>
              <span v-else class="text-muted-sm">ไม่มีรอบที่กำลังจะถึง</span>
            </div>
            <div class="trip-card-stats">
              <span class="tc-stat tc-open">เปิด {{ group.openCount }}</span>
              <span v-if="group.fullCount" class="tc-stat tc-full">เต็ม {{ group.fullCount }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Schedules Modal (per-trip) -->
    <div class="modal-overlay" v-if="activeGroup" @click.self="closeSchedulesModal">
      <div class="modal-card modal-xl schedules-modal">
        <div class="modal-header">
          <div class="smh-left">
            <img v-if="activeGroup.trip_image" :src="activeGroup.trip_image" :alt="activeGroup.trip_title" class="smh-thumb" />
            <div>
              <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">calendar_month</span>{{ activeGroup.trip_title }}</h2>
              <p class="modal-subtitle">{{ activeGroup.schedules.length }} รอบ<span v-if="activeGroup.nextDate"> · รอบถัดไป {{ activeGroup.nextDate }}</span></p>
            </div>
          </div>
          <button class="modal-close" @click="closeSchedulesModal"><span class="material-symbols-rounded">close</span></button>
        </div>

        <div class="schedules-modal-toolbar">
          <button class="btn-sm btn-secondary" @click="openBatchFormForTrip(activeGroup.trip_id)" title="สร้างหลายรอบ">
            <span class="material-symbols-rounded">layers</span> เพิ่มหลายรอบ
          </button>
          <button class="btn-sm btn-secondary" @click="openForm({ trip_id: activeGroup.trip_id })" title="เพิ่มรอบเดียว">
            <span class="material-symbols-rounded">add</span> เพิ่มรอบ
          </button>
          <button class="btn-sm btn-danger-sm" @click="deleteTripGroup(activeGroup)" title="ลบทุกรอบในทริปนี้">
            <span class="material-symbols-rounded">delete</span> ลบทั้งหมด
          </button>
        </div>

        <div class="modal-body schedules-modal-body">
          <div class="schedule-table-wrap">
            <table class="data-table schedule-inner-table">
              <thead>
                <tr>
                  <th>วันเดินทาง</th>
                  <th>วันกลับ</th>
                  <th>พาหนะ</th>
                  <th>ที่นั่ง</th>
                  <th>จุดรับ</th>
                  <th>สถานะ</th>
                  <th>การจัดการ</th>
                </tr>
              </thead>
              <tbody v-for="m in groupSchedulesByMonth(activeGroup.schedules)" :key="m.key">
                <tr class="month-sep-row">
                  <td :colspan="7">
                    <span class="material-symbols-rounded month-sep-icon">event</span>
                    <span class="month-sep-label">{{ m.label }}</span>
                    <span class="month-sep-count">{{ m.schedules.length }} รอบ</span>
                  </td>
                </tr>
                <tr v-for="sch in m.schedules" :key="sch.id">
                  <td class="date">
                    {{ sch.departure_date }}
                    <div v-if="sch.departs_at" class="text-muted-sm">
                      <span class="material-symbols-rounded icon-xs">schedule</span>
                      {{ departsTimeLabel(sch) }}
                    </div>
                  </td>
                  <td class="date">
                    <span v-if="isDayTrip(sch)" class="daytrip-pill">
                      <span class="material-symbols-rounded icon-xs">wb_sunny</span> เดย์ทริป
                    </span>
                    <template v-else>{{ sch.return_date }}</template>
                  </td>
                  <td>
                    <span class="type-tag" :class="`type-${sch.transport_type === 'van' ? 'trekking' : 'diving'}`">
                      <span class="material-symbols-rounded" style="font-size:14px;">{{ sch.transport_type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
                      {{ sch.vehicle?.name || sch.transport_type }}
                    </span>
                  </td>
                  <td>
                    <div class="seats-info">
                      <div class="seats-bar">
                        <div class="seats-fill" :style="{ width: Math.min(100, ((sch.booked_seats || 0) / sch.total_seats) * 100) + '%' }"></div>
                      </div>
                      <span class="seats-text">{{ sch.booked_seats || 0 }}/{{ sch.total_seats }}</span>
                    </div>
                  </td>
                  <td>
                    <div v-if="sch.pickup_points?.length" style="display:flex;flex-wrap:wrap;gap:3px;">
                      <span v-for="pt in sch.pickup_points" :key="pt.id" class="region-pill">{{ pt.region_label }}</span>
                    </div>
                    <span v-else class="text-muted-sm">—</span>
                  </td>
                  <td>
                    <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                      <span class="status-badge" :class="`status-${sch.status}`">{{ statusLabels[sch.status] }}</span>
                      <span v-if="sch.is_charter" class="status-badge badge-charter">
                        <span class="material-symbols-rounded icon-xs">lock</span> รอบเหมา
                      </span>
                      <span v-if="sch.join_trip_enabled" class="status-badge badge-join-trip">
                        <span class="material-symbols-rounded icon-xs">group_add</span> จอยทริป ฿{{ Number(sch.join_trip_price || 0).toLocaleString() }}
                      </span>
                      <span v-if="sch.flash_sale" class="status-badge badge-flash-sale" :class="{ 'badge-flash-inactive': !sch.flash_sale.active }">
                        <span class="material-symbols-rounded icon-xs">bolt</span>
                        Flash ฿{{ Number(sch.flash_sale.price || 0).toLocaleString() }}
                        <template v-if="sch.flash_sale.ends_at"> · ถึง {{ flashEndLabel(sch.flash_sale.ends_at) }}</template>
                      </span>
                    </div>
                  </td>
                  <td>
                    <div class="action-btns">
                      <button class="btn-icon btn-pickup" @click="openPickupManager(sch)" title="จัดการจุดรับ">
                        <span class="material-symbols-rounded">location_on</span>
                      </button>
                      <button class="btn-sm btn-secondary btn-manifest-text" @click="openManifest(sch)" title="รายชื่อผู้โดยสาร">
                        <span class="material-symbols-rounded">group</span> รายชื่อ
                      </button>
                      <span class="action-divider"></span>
                      <button class="btn-icon"
                        :class="sch.join_trip_enabled ? 'btn-active' : 'btn-inactive'"
                        @click="toggleJoinTrip(sch)"
                        :title="sch.join_trip_enabled ? 'ปิดจอยทริป' : 'เปิดจอยทริป'">
                        <span class="material-symbols-rounded">group_add</span>
                      </button>
                      <button class="btn-icon"
                        :class="sch.is_charter ? 'btn-charter-active' : 'btn-inactive'"
                        @click="toggleCharter(sch)"
                        :title="sch.is_charter ? 'ยกเลิกรอบเหมา' : 'ตั้งเป็นรอบเหมา'">
                        <span class="material-symbols-rounded">lock</span>
                      </button>
                      <span class="action-divider"></span>
                      <button class="btn-icon btn-clone" @click="openCopyScheduleModal(sch)" title="คัดลอกรอบเดินทาง">
                        <span class="material-symbols-rounded">file_copy</span>
                      </button>
                      <button v-if="sch.booked_seats > 0" class="btn-icon btn-move" @click="openMoveBookingsModal(sch)" title="ย้ายการจองไปยังรอบอื่น">
                        <span class="material-symbols-rounded">swap_horiz</span>
                      </button>
                      <span class="action-divider"></span>
                      <button class="btn-icon btn-edit" @click="openForm(sch)" title="แก้ไข"><span class="material-symbols-rounded">edit</span></button>
                      <button class="btn-icon btn-delete" @click="confirmDelete(sch)" title="ลบ"><span class="material-symbols-rounded">delete</span></button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Form Modal -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขรอบเดินทาง' : 'เพิ่มรอบใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ทริป *</label>
              <select v-model.number="form.trip_id" required>
                <option value="" disabled>เลือกทริป</option>
                <option v-for="t in tripOptions" :key="t.id" :value="t.id">{{ t.title }}</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label class="daytrip-toggle">
                <input type="checkbox" v-model="form.is_day_trip" />
                <span class="material-symbols-rounded icon-xs">wb_sunny</span>
                <span>เดย์ทริป — ไป-กลับวันเดียว (ไม่ต้องเลือกวันกลับ)</span>
              </label>
            </div>
            <div class="form-group" :class="{ 'full-width': form.is_day_trip }">
              <label>วันเดินทาง *</label>
              <input v-model="form.departure_date" type="date" required />
            </div>
            <div class="form-group" v-if="!form.is_day_trip">
              <label>วันกลับ *</label>
              <input v-model="form.return_date" type="date" required />
            </div>
            <div class="form-group full-width">
              <label>เวลาออกรถจริง (ไม่บังคับ)</label>
              <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <input v-model="form.departs_time" type="time" style="max-width:140px;" />
                <label class="daytrip-toggle" style="margin:0;" v-if="form.departs_time">
                  <input type="checkbox" v-model="form.departs_night_before" />
                  <span class="material-symbols-rounded icon-xs">dark_mode</span>
                  <span>รถออกคืนก่อนวันทริป</span>
                </label>
                <button type="button" class="btn-sm btn-secondary" v-if="form.departs_time"
                  @click="form.departs_time = ''; form.departs_night_before = false;">ล้าง</button>
              </div>
              <p class="form-toggle-hint" v-if="form.departs_time && form.departure_date" style="margin-top:6px;">
                ลูกค้าจะเห็นว่าออกเดินทาง
                {{ form.departs_night_before ? shiftDateStr(form.departure_date, -1) : form.departure_date }}
                เวลา {{ form.departs_time }} น.
              </p>
            </div>
            <div class="form-group">
              <label>จำนวนที่นั่ง *</label>
              <input v-model.number="form.total_seats" type="number" min="1" required />
            </div>
            <div class="form-group">
              <label>ประเภทพาหนะ *</label>
              <select v-model="form.transport_type" required>
                <option value="van">รถตู้</option>
                <option value="boat">เรือ</option>
                <option value="bus">รถบัส</option>
              </select>
            </div>
            <div class="form-group">
              <label>ยานพาหนะ</label>
              <select v-model="form.vehicle_id">
                <option :value="null">ไม่ระบุ</option>
                <option v-for="v in vehicleOptions" :key="v.id" :value="v.id">{{ v.name }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>ราคาพิเศษ (฿)</label>
              <input v-model.number="form.price_override" type="number" min="0" placeholder="ไม่ระบุใช้ราคาทริป" />
            </div>
            <div class="form-group" v-if="editing">
              <label>สถานะ</label>
              <select v-model="form.status">
                <option value="open">เปิด</option>
                <option value="closed">ปิด</option>
                <option value="full">เต็ม</option>
                <option value="cancelled">ยกเลิก</option>
              </select>
            </div>
          </div>

          
          <!-- Installment Settings -->
          <div class="form-toggle-section">
            <div class="form-toggle-header">
              <label class="form-toggle-label">
                <input type="checkbox" v-model="form.installment_enabled" class="check-installment" />
                <span>เปิดใช้ระบบผ่อนชำระ</span>
              </label>
            </div>
            <div v-if="form.installment_enabled" class="form-grid">
              <div class="form-group">
                <label>จำนวนงวดสูงสุด (2–6)</label>
                <input v-model.number="form.installment_count" type="number" min="2" max="6" required />
              </div>
              <div class="form-group">
                <label>ระยะห่างระหว่างงวด (วัน)</label>
                <input v-model.number="form.installment_interval_days" type="number" min="1" required />
              </div>
            </div>
          </div>

          <!-- Deposit Settings -->
          <div class="form-toggle-section">
            <div class="form-toggle-header">
              <label class="form-toggle-label">
                <input type="checkbox" v-model="form.deposit_enabled" class="check-deposit" />
                <span>เปิดใช้ระบบจ่ายมัดจำ</span>
              </label>
            </div>
            <p class="form-toggle-hint">
              <span class="material-symbols-rounded hint-icon hint-deposit">info</span>
              ลูกค้าจะจ่ายมัดจำในการจอง และต้องชำระยอดส่วนที่เหลือก่อนเดินทาง 15 วัน ระบบจะส่งอีเมล/SMS แจ้งเตือนอัตโนมัติ
            </p>
            <div v-if="form.deposit_enabled" class="form-grid">
              <div class="form-group">
                <label>รูปแบบมัดจำ</label>
                <select v-model="form.deposit_type">
                  <option value="amount">จำนวนเงิน (บาท)</option>
                  <option value="percent">เปอร์เซ็นต์ (%)</option>
                </select>
              </div>
              <div v-if="form.deposit_type === 'amount'" class="form-group">
                <label>ยอดมัดจำ (฿)</label>
                <input v-model.number="form.deposit_amount" type="number" min="0" step="1" placeholder="เช่น 2000" required />
              </div>
              <div v-else class="form-group">
                <label>เปอร์เซ็นต์มัดจำ (%)</label>
                <input v-model.number="form.deposit_percent" type="number" min="1" max="99" placeholder="เช่น 30" required />
              </div>
            </div>
          </div>

          <!-- Join Trip Settings -->
          <div class="form-toggle-section">
            <div class="form-toggle-header">
              <label class="form-toggle-label">
                <input type="checkbox" v-model="form.join_trip_enabled" class="check-join-trip" />
                <span>เปิดใช้ระบบ "จอยทริป" (Join Trip)</span>
              </label>
            </div>
            <div v-if="form.join_trip_enabled" class="form-grid">
              <div class="form-group">
                <label>ราคาจอยทริป (฿) *</label>
                <input v-model.number="form.join_trip_price" type="number" min="0" placeholder="ระบุราคาต่อท่าน" required />
              </div>
              <div class="form-group form-group-hint-cell">
                <p class="form-toggle-hint">
                  <span class="material-symbols-rounded hint-icon hint-join-trip">info</span>
                  ระบบจอยทริปจะข้ามการเลือกที่นั่งและไม่มีระบบผ่อนชำระ
                </p>
              </div>
            </div>
          </div>

          <!-- Charter Settings -->
          <div class="form-toggle-section">
            <div class="form-toggle-header">
              <label class="form-toggle-label">
                <input type="checkbox" v-model="form.is_charter" class="check-charter" />
                <span>กำหนดเป็น "รอบเหมา"</span>
              </label>
            </div>
            <p class="form-toggle-hint">
              <span class="material-symbols-rounded hint-icon hint-charter">info</span>
              รอบเหมาจะแสดงในแอปลูกค้าพร้อมป้าย "รอบเหมา" แต่ลูกค้าทั่วไปจะไม่สามารถกดจองได้
            </p>
          </div>

          <!-- Flash Sale Settings -->
          <div class="form-toggle-section">
            <div class="form-toggle-header">
              <label class="form-toggle-label">
                <input type="checkbox" v-model="form.flash_sale_enabled" class="check-flash-sale" />
                <span>⚡ เปิด Flash Sale (ราคาพิเศษช่วงใกล้ออกทริป)</span>
              </label>
            </div>
            <div v-if="form.flash_sale_enabled" class="form-grid">
              <div class="form-group">
                <label>ราคา Flash Sale (฿) *</label>
                <input v-model.number="form.flash_sale_price" type="number" min="0" placeholder="เช่น 1990" required />
              </div>
              <div class="form-group">
                <label>สิ้นสุด Flash Sale</label>
                <input v-model="form.flash_sale_ends_at_local" type="datetime-local" />
              </div>
              <div class="form-group form-group-hint-cell full-width">
                <p class="form-toggle-hint">
                  <span class="material-symbols-rounded hint-icon hint-flash-sale">bolt</span>
                  เมื่อเปิด ระบบจะส่ง Push แจ้งลูกค้าทันที · ราคาพิเศษมีผลกับการจอง/มัดจำจนกว่าจะหมดเวลา ที่ว่างเต็ม หรือถึงวันออกเดินทาง · เว้นเวลาว่างได้ (ไม่มีนับถอยหลัง)
                </p>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span class="material-symbols-rounded" :class="{ 'animate-spin': submitting }" v-if="submitting">sync</span>
              {{ editing ? 'บันทึก' : 'สร้างรอบ' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Pickup Points Manager Modal (Region-grouped) -->
    <div class="modal-overlay" v-if="showPickupManager">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">location_on</span>จุดรับผู้โดยสาร</h2>
            <p class="modal-subtitle" v-if="pickupSchedule">
              {{ pickupSchedule.trip?.title }} — {{ pickupSchedule.departure_date }}
              <span v-if="pickupSchedule.vehicle?.license_plate" style="color:var(--color-accent); font-weight:700; margin-left:4px;">
                ({{ pickupSchedule.vehicle.license_plate }})
              </span>
            </p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;">
            <button v-if="pickupTemplates.length" class="btn-sm btn-secondary" @click="showApplyTemplateDropdown = !showApplyTemplateDropdown" style="position:relative;">
              <span class="material-symbols-rounded">bookmark_add</span> ใช้เทมเพลต
              <div v-if="showApplyTemplateDropdown" class="template-dropdown" @click.stop>
                <div v-for="tpl in pickupTemplates" :key="tpl.id" class="template-dropdown-item" @click="applyTemplateToSchedule(tpl)">
                  <span class="material-symbols-rounded" style="font-size:15px;">bookmark</span>
                  <span>{{ tpl.name }}</span>
                  <span class="tpl-count">{{ tpl.points.length }} จุด</span>
                </div>
              </div>
            </button>
            <button class="btn-sm btn-secondary" @click="saveCurrentAsTemplate()">
              <span class="material-symbols-rounded">bookmark</span> บันทึกเป็นเทมเพลต
            </button>
            <button class="btn-sm btn-primary" @click="copyPickupPoints(pickupSchedule)" :disabled="!pickupPoints.length">
              <span class="material-symbols-rounded">content_copy</span> คัดลอกไปรอบอื่น
            </button>
            <button class="modal-close" @click="showPickupManager = false"><span class="material-symbols-rounded">close</span></button>
          </div>
        </div>
        <div class="modal-body">
          <div v-if="pickupLoading" class="pickup-loading"><div class="spinner"></div></div>
          <template v-else>

            <!-- Region sections -->
            <div v-for="r in REGIONS" :key="r.value" class="pickup-region-section">
              <div class="pickup-region-header">
                <div class="pickup-region-title">
                  <span class="region-dot"></span>
                  <span>{{ r.label }}</span>
                  <span class="pickup-region-count">{{ pickupPointsByRegion[r.value]?.length || 0 }} จุด</span>
                </div>
                <button type="button" class="btn-sm btn-secondary" @click="startAddInRegion(r.value)">
                  <span class="material-symbols-rounded">add</span> เพิ่มจุด
                </button>
              </div>

              <!-- Points in this region -->
              <div class="pickup-region-body">
                <!-- Existing points -->
                <div v-if="pickupPointsByRegion[r.value]?.length" class="pickup-region-list">
                  <div v-for="pt in pickupPointsByRegion[r.value]" :key="pt.id" class="pickup-item">
                    <template v-if="editingPickup?.id === pt.id">
                      <!-- Inline edit form -->
                      <div class="pickup-item-edit">
                        <div class="pif-row">
                          <input v-model="pickupForm.pickup_location" placeholder="จุดขึ้นรถ *" class="pif-location" />
                          <input v-model="pickupForm.notes" placeholder="หมายเหตุ (ไม่บังคับ)" class="pif-notes" />
                          <div class="pif-price-wrap">
                            <span class="pif-baht">฿</span>
                            <input v-model.number="pickupForm.price" type="number" min="0" placeholder="ราคา" class="pif-price" />
                          </div>
                        </div>
                        <div class="pif-row">
                          <label class="pif-time-wrap" title="เวลาขึ้นรถ">
                            <span class="material-symbols-rounded">schedule</span>
                            <input v-model="pickupForm.pickup_time" type="time" class="pif-time" />
                          </label>
                          <input v-model="pickupForm.map_url" placeholder="Google Maps URL (ไม่บังคับ)" class="pif-map" />
                          <input v-model.number="pickupForm.sort_order" type="number" min="0" placeholder="ลำดับ" class="pif-order" />
                        </div>
                        <div class="pif-row pif-image-row">
                          <div class="pif-image-preview" v-if="pickupForm.image_url">
                            <img :src="pickupForm.image_url" alt="รูปจุดรับ" />
                            <button type="button" class="pif-image-remove" @click="pickupForm.image_url = ''" title="ลบรูป">
                              <span class="material-symbols-rounded">close</span>
                            </button>
                          </div>
                          <label class="pif-image-upload">
                            <input type="file" accept="image/*" @change="uploadPickupImage" hidden :disabled="pickupImageUploading" />
                            <span class="material-symbols-rounded" :class="{ 'animate-spin': pickupImageUploading }">{{ pickupImageUploading ? 'sync' : 'add_photo_alternate' }}</span>
                            {{ pickupForm.image_url ? 'เปลี่ยนรูป' : 'อัปโหลดรูปจุดรับ' }}
                          </label>
                        </div>
                        <div class="pif-actions">
                          <button type="button" class="btn-sm btn-secondary" @click="cancelEditPickup">ยกเลิก</button>
                          <button type="button" class="btn-sm btn-primary" @click="submitPickupForm" :disabled="pickupSubmitting">
                            <span class="material-symbols-rounded" :class="{ 'animate-spin': pickupSubmitting }" v-if="pickupSubmitting">sync</span> บันทึก
                          </button>
                        </div>
                      </div>
                    </template>
                    <template v-else>
                      <!-- Display row -->
                      <div class="pickup-item-display">
                        <img v-if="pt.image_url" :src="pt.image_url" class="pid-thumb" alt="รูปจุดรับ" />
                        <div class="pid-left">
                          <span class="pid-location"><span class="material-symbols-rounded" style="font-size:16px;">push_pin</span> {{ pt.pickup_location }}</span>
                          <span class="pid-notes" v-if="pt.notes"><span class="material-symbols-rounded" style="font-size:16px;">sticky_note_2</span> {{ pt.notes }}</span>
                        </div>
                        <div class="pid-right">
                          <span class="pid-time" v-if="pt.pickup_time"><span class="material-symbols-rounded">schedule</span> {{ pt.pickup_time }} น.</span>
                          <a :href="pt.map_url" target="_blank" class="pid-map" v-if="pt.map_url" title="ดูแผนที่">
                            <span class="material-symbols-rounded">map</span>
                          </a>
                          <span class="pid-price">฿{{ Number(pt.price).toLocaleString() }}</span>
                          <button class="btn-icon btn-edit" @click="editPickupPoint(pt)" title="แก้ไข"><span class="material-symbols-rounded">edit</span></button>
                          <button class="btn-icon btn-delete" @click="deletePickupPoint(pt)" title="ลบ"><span class="material-symbols-rounded">delete</span></button>
                        </div>
                      </div>
                    </template>
                  </div>
                </div>

                <!-- Inline add form for this region -->
                <div v-if="addingInRegion === r.value" class="pickup-item-edit pickup-item-add">
                  <div class="pif-row">
                    <input v-model="pickupForm.pickup_location" placeholder="จุดขึ้นรถ *" class="pif-location" />
                    <input v-model="pickupForm.notes" placeholder="เวลานัด / หมายเหตุ" class="pif-notes" />
                    <div class="pif-price-wrap">
                      <span class="pif-baht">฿</span>
                      <input v-model.number="pickupForm.price" type="number" min="0" placeholder="ราคา" class="pif-price" />
                    </div>
                  </div>
                  <div class="pif-row">
                    <label class="pif-time-wrap" title="เวลาขึ้นรถ">
                      <span class="material-symbols-rounded">schedule</span>
                      <input v-model="pickupForm.pickup_time" type="time" class="pif-time" />
                    </label>
                    <input v-model="pickupForm.map_url" placeholder="Google Maps URL (ไม่บังคับ)" class="pif-map" />
                    <input v-model.number="pickupForm.sort_order" type="number" min="0" placeholder="ลำดับ" class="pif-order" />
                  </div>
                  <div class="pif-row pif-image-row">
                    <div class="pif-image-preview" v-if="pickupForm.image_url">
                      <img :src="pickupForm.image_url" alt="รูปจุดรับ" />
                      <button type="button" class="pif-image-remove" @click="pickupForm.image_url = ''" title="ลบรูป">
                        <span class="material-symbols-rounded">close</span>
                      </button>
                    </div>
                    <label class="pif-image-upload">
                      <input type="file" accept="image/*" @change="uploadPickupImage" hidden :disabled="pickupImageUploading" />
                      <span class="material-symbols-rounded" :class="{ 'animate-spin': pickupImageUploading }">{{ pickupImageUploading ? 'sync' : 'add_photo_alternate' }}</span>
                      {{ pickupForm.image_url ? 'เปลี่ยนรูป' : 'อัปโหลดรูปจุดรับ' }}
                    </label>
                  </div>
                  <div class="pif-actions">
                    <button type="button" class="btn-sm btn-secondary" @click="addingInRegion = null">ยกเลิก</button>
                    <button type="button" class="btn-sm btn-primary" @click="submitPickupForm" :disabled="pickupSubmitting">
                      <span class="material-symbols-rounded" :class="{ 'animate-spin': pickupSubmitting }" v-if="pickupSubmitting">sync</span> เพิ่มจุดรับ
                    </button>
                  </div>
                </div>

                <!-- Empty state for region -->
                <div v-if="!pickupPointsByRegion[r.value]?.length && addingInRegion !== r.value" class="pickup-region-empty">
                  <span class="material-symbols-rounded">wrong_location</span> ยังไม่มีจุดรับในภาคนี้
                </div>
              </div>
            </div>

          </template>
        </div>
      </div>
    </div>

    <div class="modal-overlay" v-if="showManifest">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">group</span>รายชื่อผู้โดยสาร</h2>
            <p class="modal-subtitle" v-if="manifestData">
              {{ manifestData.schedule.trip.title }} — {{ manifestData.schedule.departure_date }}
            </p>
          </div>
          <div style="display:flex;gap:8px;">
            <button v-if="manifestData?.passengers?.length" class="btn-sm btn-secondary" @click="printManifest">
              <span class="material-symbols-rounded">print</span> พิมพ์รายชื่อ
            </button>
            <button class="modal-close" @click="showManifest = false"><span class="material-symbols-rounded">close</span></button>
          </div>
        </div>
        <div class="modal-body">
          <div v-if="manifestLoading" class="pickup-loading"><div class="spinner"></div></div>
          <template v-else-if="manifestData">
            <div class="manifest-summary">
              <div class="ms-item">
                <span class="ms-label">ผู้โดยสารปกติ</span>
                <span class="ms-value">{{ manifestData.regular_passengers_count }}</span>
              </div>
              <div class="ms-item ms-join">
                <span class="ms-label">ผู้โดยสารจอยทริป</span>
                <span class="ms-value">{{ manifestData.join_trip_passengers_count }}</span>
              </div>
              <div class="ms-item ms-total">
                <span class="ms-label">ทั้งหมด</span>
                <span class="ms-value">{{ manifestData.total_passengers }}</span>
              </div>
            </div>

            <div class="manifest-table-wrap">
              <table class="data-table manifest-table">
                <thead>
                  <tr>
                    <th>ชื่อ - นามสกุล</th>
                    <th>ประเภท</th>
                    <th>ที่นั่ง / จุดรับ</th>
                    <th>เบอร์โทร</th>
                    <th>การชำระเงิน</th>
                    <th style="text-align:right;">การจัดการ</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="p in manifestData.passengers" :key="p.id">
                    <td>
                      <div class="passenger-name-cell">
                        <span class="p-name">{{ p.name }}</span>
                        <span v-if="p.nickname" class="p-nickname">({{ p.nickname }})</span>
                      </div>
                    </td>
                    <td>
                      <span v-if="p.is_join_trip" class="status-badge badge-join-trip">จอยทริป</span>
                      <span v-else class="status-badge badge-normal">ปกติ</span>
                    </td>
                    <td>
                      <div class="p-seats-pickup">
                        <div class="p-seats">
                          <span class="material-symbols-rounded" style="font-size:14px;color:#6b7280;">chair</span>
                          <span v-if="p.is_join_trip" class="text-muted-sm">ไม่ระบุ</span>
                          <span v-else>{{ p.booking?.seats?.map(s => s.seat_id).join(', ') || '—' }}</span>
                        </div>
                        <div class="p-pickup" v-if="p.booking?.pickupPoint">
                          <span class="material-symbols-rounded" style="font-size:14px;color:var(--color-accent);">location_on</span>
                          {{ p.booking.pickupPoint.pickup_location }}
                        </div>
                      </div>
                    </td>
                    <td>{{ p.phone || p.booking?.user?.phone || '—' }}</td>
                    <td>
                      <div class="p-payment-info" v-if="p.booking">
                         <span class="status-badge" :class="`status-${p.booking.status}`">{{ statusLabels[p.booking.status] || p.booking.status }}</span>
                         <span class="p-amount">฿{{ Number(p.booking.paid_amount).toLocaleString() }} / ฿{{ Number(p.booking.total_amount).toLocaleString() }}</span>
                      </div>
                    </td>
                    <td style="text-align:right;">
                      <button class="btn-icon btn-view-details" @click="viewPassengerDetails(p)" title="ดูรายละเอียดเพิ่มเติม">
                        <span class="material-symbols-rounded">visibility</span>
                      </button>
                    </td>
                  </tr>
                  <tr v-if="!manifestData.passengers.length">
                    <td colspan="6" class="empty-state">ยังไม่มีผู้โดยสาร</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Passenger Details Modal -->
    <div class="modal-overlay" v-if="selectedPassenger" style="z-index: 1001;">
      <div class="modal-card">
        <div class="modal-header">
          <h2>รายละเอียดผู้โดยสาร</h2>
          <button class="modal-close" @click="selectedPassenger = null"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body passenger-details-body">
          <div class="pd-section">
            <h3 class="pd-title">ข้อมูลทั่วไป</h3>
            <div class="pd-grid">
              <div class="pd-item"><span class="pd-label">ชื่อ-นามสกุล</span><span class="pd-value">{{ selectedPassenger.name }}</span></div>
              <div class="pd-item"><span class="pd-label">ชื่อเล่น</span><span class="pd-value">{{ selectedPassenger.nickname || '—' }}</span></div>
              <div class="pd-item"><span class="pd-label">เบอร์โทรศัพท์</span><span class="pd-value">{{ selectedPassenger.phone || selectedPassenger.booking?.user?.phone || '—' }}</span></div>
              <div class="pd-item"><span class="pd-label">เลขบัตรประชาชน/พาสปอร์ต</span><span class="pd-value">{{ selectedPassenger.id_card || '—' }}</span></div>
            </div>
          </div>

          <div class="pd-section">
            <h3 class="pd-title">ข้อมูลสุขภาพและอาหาร</h3>
            <div class="pd-grid">
              <div class="pd-item"><span class="pd-label">กรุ๊ปเลือด</span><span class="pd-value">{{ selectedPassenger.blood_group || '—' }}</span></div>
              <div class="pd-item"><span class="pd-label">แพ้อาหาร/แพ้ยา</span><span class="pd-value">{{ selectedPassenger.allergies || 'ไม่มี' }}</span></div>
              <div class="pd-item"><span class="pd-label">อาหารฮาลาล</span><span class="pd-value">{{ selectedPassenger.halal_food ? 'ต้องการ' : 'ไม่ต้องการ' }}</span></div>
              <div class="pd-item full"><span class="pd-label">ประวัติสุขภาพ/โรคประจำตัว</span><span class="pd-value">{{ selectedPassenger.health_notes || '—' }}</span></div>
            </div>
          </div>

          <div class="pd-section" v-if="selectedPassenger.emergency_contact">
            <h3 class="pd-title">ติดต่อฉุกเฉิน</h3>
            <div class="pd-grid">
              <div class="pd-item"><span class="pd-label">ชื่อผู้ติดต่อ</span><span class="pd-value">{{ selectedPassenger.emergency_contact }}</span></div>
              <div class="pd-item"><span class="pd-label">เบอร์ติดต่อ</span><span class="pd-value">{{ selectedPassenger.emergency_phone }}</span></div>
            </div>
          </div>

          <div class="pd-section" v-if="selectedPassenger.dive_cert_level">
            <h3 class="pd-title">ข้อมูลดำน้ำ</h3>
            <div class="pd-grid">
              <div class="pd-item"><span class="pd-label">ระดับบัตรดำน้ำ</span><span class="pd-value">{{ selectedPassenger.dive_cert_level }}</span></div>
              <div class="pd-item"><span class="pd-label">เลขบัตรดำน้ำ</span><span class="pd-value">{{ selectedPassenger.cert_number || '—' }}</span></div>
              <div class="pd-item"><span class="pd-label">น้ำหนัก (kg)</span><span class="pd-value">{{ selectedPassenger.weight || '—' }}</span></div>
            </div>
          </div>

          <div class="pd-section">
            <h3 class="pd-title">การจอง</h3>
            <div class="pd-grid">
              <div class="pd-item"><span class="pd-label">เลขที่การจอง</span><span class="pd-value">{{ selectedPassenger.booking?.booking_ref }}</span></div>
              <div class="pd-item"><span class="pd-label">ที่นั่ง</span><span class="pd-value">{{ selectedPassenger.booking?.seats?.map(s => s.seat_id).join(', ') || '—' }}</span></div>
              <div class="pd-item"><span class="pd-label">จุดรับ</span><span class="pd-value">{{ selectedPassenger.booking?.pickupPoint?.pickup_location || '—' }}</span></div>
              <div class="pd-item"><span class="pd-label">สถานะการจอง</span><span class="pd-value status-badge" :class="`status-${selectedPassenger.booking?.status}`">{{ statusLabels[selectedPassenger.booking?.status] }}</span></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-primary" @click="selectedPassenger = null">ปิด</button>
        </div>
      </div>
    </div>
    <div class="modal-overlay" v-if="showBatchForm">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">layers</span>สร้างหลายรอบพร้อมกัน</h2>
            <p class="modal-subtitle">กำหนดพาหนะ + จุดขึ้นรถครั้งเดียว แล้วเพิ่มวันเดินทางได้หลายรอบ</p>
          </div>
          <button class="modal-close" @click="showBatchForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitBatchForm" class="modal-body">

          <!-- Section 1: Trip + Vehicle -->
          <div class="batch-section">
            <h3 class="section-label"><span class="material-symbols-rounded">info</span> ข้อมูลพื้นฐาน</h3>
            <div class="form-grid">
              <div class="form-group full-width">
                <label>ทริป *</label>
                <select v-model.number="batchForm.trip_id" required>
                  <option value="" disabled>เลือกทริป</option>
                  <option v-for="t in tripOptions" :key="t.id" :value="t.id">{{ t.title }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>ประเภทพาหนะ *</label>
                <select v-model="batchForm.transport_type" required>
                  <option value="van">รถตู้</option>
                  <option value="boat">เรือ</option>
                  <option value="bus">รถบัส</option>
                </select>
              </div>
              <div class="form-group">
                <label>ยานพาหนะ</label>
                <select v-model="batchForm.vehicle_id">
                  <option :value="null">ไม่ระบุ</option>
                  <option v-for="v in vehicleOptions" :key="v.id" :value="v.id">{{ v.name }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>จำนวนที่นั่ง / รอบ *</label>
                <input v-model.number="batchForm.total_seats" type="number" min="1" required />
              </div>
              <div class="form-group">
                <label>ราคาพิเศษ (฿)</label>
                <input v-model.number="batchForm.price_override" type="number" min="0" placeholder="ไม่ระบุใช้ราคาทริป" />
              </div>
              <div class="form-group full-width">
                <label>เวลาออกรถจริง (ไม่บังคับ — ใช้กับทุกรอบ)</label>
                <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                  <input v-model="batchForm.departs_time" type="time" style="max-width:140px;" />
                  <label class="daytrip-toggle" style="margin:0;" v-if="batchForm.departs_time">
                    <input type="checkbox" v-model="batchForm.departs_night_before" />
                    <span class="material-symbols-rounded icon-xs">dark_mode</span>
                    <span>รถออกคืนก่อนวันทริป</span>
                  </label>
                  <button type="button" class="btn-sm btn-secondary" v-if="batchForm.departs_time"
                    @click="batchForm.departs_time = ''; batchForm.departs_night_before = false;">ล้าง</button>
                </div>
              </div>
            </div>

            <!-- Join Trip + Installment in batch -->
            <div class="batch-features-row">
              <div class="batch-feature-item">
                <label class="form-toggle-label form-toggle-label--sm">
                  <input type="checkbox" v-model="batchForm.join_trip_enabled" class="check-join-trip" />
                  <span>เปิดจอยทริป</span>
                </label>
                <div v-if="batchForm.join_trip_enabled" class="batch-feature-input">
                  <span class="input-label-inline">ราคา ฿</span>
                  <input v-model.number="batchForm.join_trip_price" type="number" min="0" placeholder="ราคา" class="input-sm-100" />
                </div>
              </div>
              <div class="batch-feature-item">
                <label class="form-toggle-label form-toggle-label--sm">
                  <input type="checkbox" v-model="batchForm.installment_enabled" class="check-installment" />
                  <span>เปิดผ่อนชำระ</span>
                </label>
                <div v-if="batchForm.installment_enabled" class="batch-feature-input">
                  <input v-model.number="batchForm.installment_count" type="number" min="2" max="6" placeholder="งวด" class="input-sm-60" />
                  <span class="input-label-inline">งวด ·</span>
                  <input v-model.number="batchForm.installment_interval_days" type="number" min="1" placeholder="วัน" class="input-sm-60" />
                  <span class="input-label-inline">วัน</span>
                </div>
              </div>
              <div class="batch-feature-item">
                <label class="form-toggle-label form-toggle-label--sm">
                  <input type="checkbox" v-model="batchForm.deposit_enabled" class="check-deposit" />
                  <span>เปิดจ่ายมัดจำ</span>
                </label>
                <div v-if="batchForm.deposit_enabled" class="batch-feature-input">
                  <select v-model="batchForm.deposit_type" class="select-sm">
                    <option value="amount">บาท</option>
                    <option value="percent">%</option>
                  </select>
                  <input v-if="batchForm.deposit_type === 'amount'" v-model.number="batchForm.deposit_amount" type="number" min="0" placeholder="ยอด" class="input-sm-80" />
                  <input v-else v-model.number="batchForm.deposit_percent" type="number" min="1" max="99" placeholder="%" class="input-sm-60" />
                </div>
              </div>
            </div>
          </div>

          <!-- Section 2: Dates -->
          <div class="batch-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
              <h3 class="section-label" style="margin:0;">
                <span class="material-symbols-rounded">calendar_month</span> วันเดินทาง ({{ batchForm.dates.length }} รอบ)
                <span v-if="batchMonthsCovered > 1" class="batch-months-chip">{{ batchMonthsCovered }} เดือน</span>
              </h3>
              <label class="daytrip-toggle">
                <input type="checkbox" v-model="batchForm.is_day_trip" />
                <span class="material-symbols-rounded icon-xs">wb_sunny</span>
                <span>เดย์ทริป — ไป-กลับวันเดียว</span>
              </label>
            </div>

            <!-- Auto-generate helper -->
            <div class="date-gen">
              <span class="date-gen-label"><span class="material-symbols-rounded icon-xs">auto_awesome</span> สร้างอัตโนมัติ</span>
              <div class="date-gen-field">
                <span>เริ่ม</span>
                <input v-model="batchGen.start" type="date" />
              </div>
              <div class="date-gen-field">
                <span>จำนวน</span>
                <input v-model.number="batchGen.count" type="number" min="1" class="input-sm-60" />
                <span>รอบ</span>
              </div>
              <div class="date-gen-field">
                <span>ทุกๆ</span>
                <input v-model.number="batchGen.every" type="number" min="1" class="input-sm-60" />
                <span>วัน</span>
              </div>
              <div class="date-gen-field" v-if="!batchForm.is_day_trip">
                <span>ค้าง</span>
                <input v-model.number="batchGen.nights" type="number" min="0" class="input-sm-60" />
                <span>คืน</span>
              </div>
              <button type="button" class="btn-sm btn-primary" @click="generateDates">
                <span class="material-symbols-rounded">playlist_add</span> สร้างวัน
              </button>
            </div>

            <div class="date-rows">
              <div v-for="(d, i) in batchForm.dates" :key="i" class="date-row">
                <span class="date-row-num">{{ i + 1 }}</span>
                <div class="form-group" style="flex:1;margin:0;">
                  <input v-model="d.departure_date" type="date" required placeholder="วันเดินทาง" />
                </div>
                <template v-if="!batchForm.is_day_trip">
                  <span style="color:#9ca3af;font-size:13px;">→</span>
                  <div class="form-group" style="flex:1;margin:0;">
                    <input v-model="d.return_date" type="date" required placeholder="วันกลับ" />
                  </div>
                </template>
                <span v-else class="daytrip-pill"><span class="material-symbols-rounded icon-xs">wb_sunny</span> ไป-กลับวันเดียว</span>
                <span v-if="rowMonthLabel(d)" class="date-row-month">{{ rowMonthLabel(d) }}</span>
                <button type="button" class="btn-icon btn-delete" @click="removeDateRow(i)" :disabled="batchForm.dates.length <= 1">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Section 3: Pickup Points -->
          <div class="batch-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
              <h3 class="section-label" style="margin:0;"><span class="material-symbols-rounded">location_on</span> จุดขึ้นรถแต่ละภูมิภาค</h3>
              <button type="button" class="btn-sm btn-secondary" @click="addPickupRow">
                <span class="material-symbols-rounded">add</span> เพิ่มภูมิภาค
              </button>
            </div>
            <div class="pickup-inline-list">
              <div v-for="(pt, i) in batchForm.pickups" :key="i" class="pickup-inline-row">
                <div class="pickup-inline-left">
                  <select v-model="pt.region" @change="onBatchRegionChange(pt)" style="min-width:130px;">
                    <option value="" disabled>ภูมิภาค</option>
                    <option v-for="r in REGIONS" :key="r.value" :value="r.value">{{ r.label }}</option>
                  </select>
                  <input v-model="pt.pickup_location" placeholder="จุดขึ้นรถ เช่น ปั้ม PTT เชียงใหม่" style="flex:2;" />
                  <input v-model="pt.notes" placeholder="เวลานัด เช่น 05:30 น." style="flex:1;" />
                </div>
                <div class="pickup-inline-right">
                  <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:12px;color:#6b7280;">฿</span>
                    <input v-model.number="pt.price" type="number" min="0" placeholder="ราคา" style="width:90px;" />
                  </div>
                  <input v-model="pt.map_url" placeholder="Maps URL (ไม่บังคับ)" style="flex:1;" />
                  <div class="pif-image-preview" v-if="pt.image_url">
                    <img :src="pt.image_url" alt="รูปจุดรับ" />
                    <button type="button" class="pif-image-remove" @click="pt.image_url = ''" title="ลบรูป">
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                  <label class="pif-image-upload">
                    <input type="file" accept="image/*" @change="uploadPickupImage($event, pt)" hidden :disabled="pickupImageUploading" />
                    <span class="material-symbols-rounded" :class="{ 'animate-spin': pickupImageUploading }">{{ pickupImageUploading ? 'sync' : 'add_photo_alternate' }}</span>
                    {{ pt.image_url ? 'เปลี่ยนรูป' : 'รูป' }}
                  </label>
                  <button type="button" class="btn-icon btn-delete" @click="removePickupRow(i)">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
              </div>
              <div v-if="!batchForm.pickups.length" class="pickup-inline-empty">
                <span class="material-symbols-rounded">wrong_location</span> ไม่มีจุดขึ้นรถ (เพิ่มได้ภายหลัง)
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showBatchForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="batchSubmitting">
              <span class="material-symbols-rounded" :class="{ 'animate-spin': batchSubmitting }" v-if="batchSubmitting">sync</span>
              {{ batchSubmitting ? 'กำลังสร้าง...' : `สร้าง ${batchForm.dates.length} รอบ` }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Copy Schedule Modal -->
    <div class="modal-overlay" v-if="showCopyScheduleModal">
      <div class="modal-card">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">file_copy</span>คัดลอกรอบเดินทาง</h2>
            <p class="modal-subtitle" v-if="copyScheduleSource">ต้นฉบับ: {{ copyScheduleSource.trip?.title }} — {{ copyScheduleSource.departure_date }}</p>
          </div>
          <button class="modal-close" @click="showCopyScheduleModal = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ทริปปลายทาง *</label>
              <select v-model.number="copyScheduleForm.trip_id" required>
                <option value="" disabled>เลือกทริป</option>
                <option v-for="t in tripOptions" :key="t.id" :value="t.id">{{ t.title }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>วันเดินทางใหม่ *</label>
              <input v-model="copyScheduleForm.departure_date" type="date" required />
            </div>
            <div class="form-group">
              <label>วันกลับใหม่ *</label>
              <input v-model="copyScheduleForm.return_date" type="date" required />
            </div>
          </div>
          <label class="checkbox-filter" style="margin-top:4px;">
            <input type="checkbox" v-model="copyScheduleForm.include_pickups" />
            <span>คัดลอกจุดรับพร้อมกัน</span>
          </label>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showCopyScheduleModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="doCopySchedule" :disabled="copyScheduleSubmitting || !copyScheduleForm.trip_id || !copyScheduleForm.departure_date || !copyScheduleForm.return_date">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': copyScheduleSubmitting }" v-if="copyScheduleSubmitting">sync</span>
            คัดลอกรอบ
          </button>
        </div>
      </div>
    </div>

    <!-- Move Bookings Modal -->
    <div class="modal-overlay" v-if="showMoveBookingsModal">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">swap_horiz</span>ย้ายรายการจอง</h2>
            <p class="modal-subtitle" v-if="moveSource">
              ต้นทาง: {{ moveSource.trip?.title }} — {{ moveSource.departure_date }}
              <span v-if="movePassengers.length">· เลือกแล้ว {{ selectedMovePassengerCount }} / {{ movePassengers.length }} คน</span>
            </p>
          </div>
          <button class="modal-close" @click="closeMoveBookingsModal"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div v-if="moveLoading" class="pickup-loading"><div class="spinner"></div></div>
          <template v-else>
          <div class="apply-section">
            <div class="move-selection-head">
              <div class="apply-section-title"><span class="material-symbols-rounded">groups</span> เลือกผู้โดยสารที่ต้องการย้าย</div>
              <div class="move-selection-actions">
                <button type="button" class="btn-sm btn-secondary" @click="selectAllMovePassengers">เลือกทั้งหมด</button>
                <button type="button" class="btn-sm btn-secondary" @click="moveSelectedPassengerIds = []">ยกเลิกทั้งหมด</button>
              </div>
            </div>

            <div v-if="movePassengerGroups.length" class="move-passenger-list">
              <div v-for="group in movePassengerGroups" :key="group.booking_id" class="move-booking-group">
                <label class="move-booking-head">
                  <input
                    type="checkbox"
                    :checked="isMoveBookingGroupSelected(group)"
                    :indeterminate.prop="isMoveBookingGroupPartial(group)"
                    @change="toggleMoveBookingGroup(group)"
                  />
                  <div>
                    <strong>{{ group.booking_ref }}</strong>
                    <span>{{ group.passengers.length }} คน · {{ group.is_join_trip ? 'จอยทริป' : 'จองปกติ' }}</span>
                  </div>
                  <span class="status-badge" :class="`status-${group.status}`">{{ statusLabels[group.status] || group.status }}</span>
                </label>

                <label v-for="p in group.passengers" :key="p.id" class="move-passenger-item">
                  <input type="checkbox" v-model="moveSelectedPassengerIds" :value="p.id" />
                  <span class="move-passenger-name">{{ p.name }}</span>
                  <span class="move-passenger-meta">
                    {{ p.phone || p.booking?.user?.phone || 'ไม่มีเบอร์' }}
                    <template v-if="!p.is_join_trip"> · ที่นั่ง {{ passengerSeatLabels(p).join(', ') || '—' }}</template>
                  </span>
                </label>
              </div>
            </div>
            <div v-else class="empty-state">ไม่พบผู้โดยสารที่สามารถย้ายได้</div>
          </div>

          <div class="apply-section">
            <div class="apply-section-title"><span class="material-symbols-rounded">calendar_month</span> เลือกรอบเดินทางปลายทาง</div>
            <div class="copy-target-list">
              <div v-for="group in moveTargetGroups" :key="group.trip_id" style="margin-bottom:8px;">
                <div style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;padding:4px 0;">{{ group.trip_title }}</div>
                <label v-for="sch in group.schedules" :key="sch.id" class="copy-target-item" :class="{ disabled: isMoveTargetDisabled(sch) }">
                  <input type="radio" v-model="moveTargetId" :value="sch.id" :disabled="isMoveTargetDisabled(sch)" />
                  <div style="flex:1;">
                    <div style="display:flex;justify-content:space-between;">
                       <span>{{ sch.departure_date }}<span v-if="sch.return_date"> → {{ sch.return_date }}</span></span>
                       <span class="status-badge" :class="`status-${sch.status}`">{{ statusLabels[sch.status] }}</span>
                    </div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                      <span v-if="sch.departs_at">{{ departsTimeLabel(sch) }} · </span>ว่าง {{ sch.available_seats }} ที่นั่ง · {{ sch.vehicle?.name || sch.transport_type }}
                      <span v-if="sch.id !== moveSource.id && sch.available_seats < selectedMoveSeatCount" style="color:#dc2626;font-weight:700;">
                        · ที่นั่งไม่พอสำหรับ {{ selectedMoveSeatCount }} คน
                      </span>
                    </div>
                  </div>
                </label>
              </div>
            </div>
          </div>
          <div v-if="moveTargetId" style="margin-top:16px;padding:12px;background:#fffbeb;border:1px solid #fef3c7;border-radius:8px;font-size:13px;color:#92400e;">
             <span class="material-symbols-rounded" style="font-size:16px;vertical-align:middle;margin-right:4px;">warning</span>
             ย้ายเฉพาะผู้โดยสารที่เลือก หากเลือกไม่ครบทั้งใบจอง ระบบจะแยกรายการจองใหม่ให้อัตโนมัติ และพยายามจับคู่จุดรับให้ตรงกับรอบปลายทาง
          </div>

          <div v-if="moveTargetId && selectedMoveSeatPassengers.length" class="apply-section move-seat-section">
            <div class="move-selection-head">
              <div class="apply-section-title"><span class="material-symbols-rounded">airline_seat_recline_normal</span> เลือกที่นั่งปลายทาง</div>
              <div v-if="moveSeatMapLoading" class="text-muted-sm">กำลังโหลดที่นั่ง...</div>
              <div v-else class="text-muted-sm">ว่าง {{ availableMoveTargetSeats.length }} ที่นั่ง</div>
            </div>

            <div v-if="moveSeatMapError" class="alert-card" style="margin:0;">
              <span class="material-symbols-rounded">error</span>
              <span>{{ moveSeatMapError }}</span>
            </div>

            <div v-else class="move-seat-layout">
              <div class="move-seat-passengers">
                <button
                  v-for="row in moveSeatAssignmentRows"
                  :key="row.passenger.id"
                  type="button"
                  class="move-seat-person-card"
                  :class="{ active: activeMoveSeatPassengerId === row.passenger.id, assigned: row.assignedSeatId }"
                  @click="activeMoveSeatPassengerId = row.passenger.id"
                >
                  <span class="move-seat-person-name">{{ row.passenger.name }}</span>
                  <span class="move-seat-person-meta">
                    เดิม {{ row.originalSeatId || '—' }}
                    <template v-if="row.originalSeatId && !row.originalSeatAvailable"> · เดิมไม่ว่าง</template>
                  </span>
                  <strong>{{ row.assignedSeatId || 'ยังไม่เลือก' }}</strong>
                </button>
              </div>

              <div class="move-seat-map-panel">
                <div class="move-seat-map-legend">
                  <span><i class="legend-box available"></i>ว่าง</span>
                  <span><i class="legend-box selected"></i>เลือกอยู่</span>
                  <span><i class="legend-box booked"></i>จองแล้ว/ล็อก</span>
                </div>

                <div class="move-seat-vehicle">
                  <div class="move-seat-front">
                    <span>{{ moveTargetSeatMap?.front_label || 'หน้ารถ' }}</span>
                    <span v-if="moveTargetSeatMap?.show_driver !== false" class="move-driver">
                      <span class="material-symbols-rounded">{{ moveTargetSeatMap?.driver_icon || 'directions_car' }}</span>
                      คนขับ
                    </span>
                  </div>

                  <div class="move-seat-grid" :style="moveSeatGridStyle">
                    <template v-for="cell in moveSeatCells" :key="cell.key">
                      <div v-if="cell.type === 'aisle'" class="move-seat-aisle"></div>
                      <button
                        v-else-if="cell.seat"
                        type="button"
                        class="move-seat-button"
                        :class="moveSeatButtonClass(cell.seat)"
                        :disabled="!canSelectMoveSeat(cell.seat)"
                        :title="moveSeatTitle(cell.seat)"
                        @click="assignMoveSeat(cell.seat)"
                      >
                        <span class="material-symbols-rounded">airline_seat_recline_normal</span>
                        <strong>{{ cell.seat.label || cell.seat.id }}</strong>
                        <small v-if="moveSeatAssignedPassengerName(cell.seat.id)">{{ moveSeatAssignedPassengerName(cell.seat.id) }}</small>
                      </button>
                      <div v-else class="move-seat-empty"></div>
                    </template>
                  </div>

                  <div class="move-seat-rear">{{ moveTargetSeatMap?.rear_label || 'ท้ายรถ' }}</div>
                </div>
              </div>
            </div>

            <p v-if="moveSeatAssignmentError" class="move-seat-error">
              <span class="material-symbols-rounded">warning</span>
              {{ moveSeatAssignmentError }}
            </p>
          </div>
          </template>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="closeMoveBookingsModal">ยกเลิก</button>
          <button class="btn-primary" @click="doMoveBookings" :disabled="moveSubmitting || moveLoading || moveSeatMapLoading || !moveTargetId || !selectedMovePassengerCount || Boolean(moveSeatAssignmentError)">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': moveSubmitting }" v-if="moveSubmitting">sync</span>
            ย้าย {{ selectedMovePassengerCount }} คน
          </button>
        </div>
      </div>
    </div>

    <!-- Pickup Template Manager Modal -->
    <div class="modal-overlay" v-if="showTemplateManager">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">bookmark</span>เทมเพลตจุดรับ</h2>
            <p class="modal-subtitle">จัดการชุดจุดรับที่ใช้บ่อย แก้ครั้งเดียวนำไปใช้ได้หลายรอบ</p>
          </div>
          <button class="modal-close" @click="showTemplateManager = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div style="display:flex;gap:12px;margin-bottom:16px;">
            <button class="btn-sm btn-primary" @click="startNewTemplate()">
              <span class="material-symbols-rounded">add</span> สร้างเทมเพลตใหม่
            </button>
          </div>

          <div v-if="!pickupTemplates.length" class="pickup-inline-empty">
            <span class="material-symbols-rounded">bookmark_border</span> ยังไม่มีเทมเพลต
          </div>

          <div v-for="tpl in pickupTemplates" :key="tpl.id" class="tpl-card">
            <div class="tpl-card-header">
              <div class="tpl-card-title">
                <span class="material-symbols-rounded" style="color:var(--color-accent);">bookmark</span>
                <template v-if="editingTemplate?.id === tpl.id">
                  <input v-model="editingTemplate.name" class="tpl-name-input" placeholder="ชื่อเทมเพลต" />
                </template>
                <template v-else>
                  <span>{{ tpl.name }}</span>
                  <span class="tpl-count">{{ tpl.points.length }} จุด</span>
                </template>
              </div>
              <div style="display:flex;gap:6px;">
                <button v-if="editingTemplate?.id !== tpl.id" class="btn-sm btn-secondary" @click="openApplyTemplateModal(tpl)">
                  <span class="material-symbols-rounded">rocket_launch</span> นำไปใช้
                </button>
                <button v-if="editingTemplate?.id !== tpl.id" class="btn-icon btn-edit" @click="startEditTemplate(tpl)"><span class="material-symbols-rounded">edit</span></button>
                <button v-if="editingTemplate?.id !== tpl.id" class="btn-icon btn-delete" @click="deleteTemplate(tpl.id)"><span class="material-symbols-rounded">delete</span></button>
                <template v-if="editingTemplate?.id === tpl.id">
                  <button class="btn-sm btn-secondary" @click="cancelEditTemplate">ยกเลิก</button>
                  <button class="btn-sm btn-primary" @click="saveEditTemplate">บันทึก</button>
                </template>
              </div>
            </div>
            <div class="tpl-card-body">
              <div v-if="editingTemplate?.id === tpl.id">
                <div v-for="r in REGIONS" :key="r.value" class="tpl-region-section">
                  <div class="tpl-region-label">
                    <span class="region-dot"></span>{{ r.label }}
                    <button type="button" class="btn-sm btn-secondary" style="margin-left:auto;" @click="addTplPoint(r.value)">
                      <span class="material-symbols-rounded">add</span>
                    </button>
                  </div>
                  <div v-for="(pt, pi) in editingTemplate.points.filter(p => p.region === r.value)" :key="pi" class="tpl-point-row">
                    <input v-model="pt.pickup_location" placeholder="จุดขึ้นรถ *" style="flex:2;" />
                    <input v-model="pt.notes" placeholder="เวลา / หมายเหตุ" style="flex:1;" />
                    <div style="display:flex;align-items:center;gap:4px;">
                      <span style="font-size:12px;color:#6b7280;">฿</span>
                      <input v-model.number="pt.price" type="number" min="0" placeholder="ราคา" style="width:80px;" />
                    </div>
                    <input v-model="pt.map_url" placeholder="Maps URL" style="flex:1.5;" />
                    <div class="pif-image-preview" v-if="pt.image_url">
                      <img :src="pt.image_url" alt="รูปจุดรับ" />
                      <button type="button" class="pif-image-remove" @click="pt.image_url = ''" title="ลบรูป">
                        <span class="material-symbols-rounded">close</span>
                      </button>
                    </div>
                    <label class="pif-image-upload">
                      <input type="file" accept="image/*" @change="uploadPickupImage($event, pt)" hidden :disabled="pickupImageUploading" />
                      <span class="material-symbols-rounded" :class="{ 'animate-spin': pickupImageUploading }">{{ pickupImageUploading ? 'sync' : 'add_photo_alternate' }}</span>
                      {{ pt.image_url ? 'เปลี่ยนรูป' : 'รูป' }}
                    </label>
                    <button type="button" class="pif-image-pick" @click="openImagePicker(pt)" title="เลือกจากรูปที่มีอยู่">
                      <span class="material-symbols-rounded">photo_library</span>
                    </button>
                    <button type="button" class="btn-icon btn-delete" @click="removeTplPoint(r.value, pi)"><span class="material-symbols-rounded">close</span></button>
                  </div>
                  <div v-if="!editingTemplate.points.filter(p => p.region === r.value).length" class="tpl-region-empty">ไม่มีจุดรับในภาคนี้</div>
                </div>
              </div>
              <div v-else class="tpl-points-preview">
                <div v-for="(pt, pi) in tpl.points" :key="pi" class="tpl-preview-item">
                  <img v-if="pt.image_url" :src="pt.image_url" class="pid-thumb" alt="รูปจุดรับ" />
                  <span class="region-pill">{{ REGIONS.find(r=>r.value===pt.region)?.label || pt.region }}</span>
                  <span>{{ pt.pickup_location }}</span>
                  <span style="color:#6b7280;font-size:12px;">{{ pt.notes }}</span>
                  <span style="font-weight:700;color:var(--color-text-dark);white-space:nowrap;">฿{{ Number(pt.price||0).toLocaleString() }}</span>
                </div>
                <div v-if="!tpl.points.length" style="font-size:12px;color:#9ca3af;padding:8px;">ไม่มีจุดรับในเทมเพลตนี้</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Apply Template to Schedules Modal -->
    <div class="modal-overlay" v-if="showApplyTemplateModal">
      <div class="modal-card">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">rocket_launch</span>นำเทมเพลตไปใช้</h2>
            <p class="modal-subtitle">เทมเพลตที่เลือก: <strong>{{ applyTemplateIds.length }}</strong> เทมเพลต · {{ applyTotalPoints }} จุด</p>
          </div>
          <button class="modal-close" @click="showApplyTemplateModal = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">

          <!-- Step 1: เลือกเทมเพลต -->
          <div class="apply-section">
            <div class="apply-section-title"><span class="material-symbols-rounded">bookmark</span> เลือกเทมเพลต (เลือกได้หลายอัน)</div>
            <div class="apply-tpl-list">
              <label v-for="tpl in pickupTemplates" :key="tpl.id" class="apply-tpl-item" :class="{ selected: applyTemplateIds.includes(tpl.id) }">
                <input type="checkbox" v-model="applyTemplateIds" :value="tpl.id" style="accent-color:var(--color-accent);width:14px;height:14px;flex-shrink:0;" />
                <span class="material-symbols-rounded" style="font-size:15px;color:var(--color-accent);">bookmark</span>
                <span style="flex:1;">{{ tpl.name }}</span>
                <span class="tpl-count">{{ tpl.points.length }} จุด</span>
              </label>
            </div>
          </div>

          <!-- Step 2: mode -->
          <div class="apply-section">
            <div class="apply-section-title"><span class="material-symbols-rounded">tune</span> วิธีใช้งาน</div>
            <div class="apply-mode-wrap">
              <label class="apply-mode-option" :class="{ active: applyMode === 'append' }">
                <input type="radio" v-model="applyMode" value="append" />
                <span class="material-symbols-rounded">add_circle</span>
                <div>
                  <div style="font-weight:700;"> เพิ่มเข้าไป</div>
                  <div style="font-size:11px;color:#6b7280;">จุดรับเดิมยังคงอยู่ เพิ่มจากเทมเพลตที่เลือก</div>
                </div>
              </label>
              <label class="apply-mode-option" :class="{ active: applyMode === 'replace' }">
                <input type="radio" v-model="applyMode" value="replace" />
                <span class="material-symbols-rounded">sync</span>
                <div>
                  <div style="font-weight:700;">เขียนทับทั้งหมด</div>
                  <div style="font-size:11px;color:#ef4444;">ลบจุดรับเดิมทั้งหมดแล้วเขียนใหม่</div>
                </div>
              </label>
            </div>
          </div>

          <!-- Step 3: เลือกรอบ -->
          <div class="apply-section">
            <div class="apply-section-title"><span class="material-symbols-rounded">calendar_month</span> เลือกรอบเดินทาง</div>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
              <button class="btn-sm btn-secondary" @click="applySelectAll()">เลือกทั้งหมด</button>
              <button class="btn-sm btn-secondary" @click="applySelectedScheduleIds = []">ยกเลิกทั้งหมด</button>
            </div>
            <div class="copy-target-list">
              <div v-for="group in groupedByTrip" :key="group.trip_id" style="margin-bottom:8px;">
                <div style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;padding:4px 0;">{{ group.trip_title }}</div>
                <label v-for="sch in group.schedules" :key="sch.id" class="copy-target-item">
                  <input type="checkbox" v-model="applySelectedScheduleIds" :value="sch.id" />
                  <span>
                    {{ sch.departure_date }}<span v-if="sch.return_date"> → {{ sch.return_date }}</span>
                    <span v-if="sch.vehicle?.license_plate" style="margin-left:8px; color:var(--color-accent); font-weight:700;">
                      ({{ sch.vehicle.license_plate }})
                    </span>
                  </span>
                  <span class="status-badge" :class="`status-${sch.status}`" style="margin-left:auto;">{{ statusLabels[sch.status] }}</span>
                </label>
              </div>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showApplyTemplateModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="doApplyTemplate" :disabled="!applySelectedScheduleIds.length || !applyTemplateIds.length || applyTemplateSubmitting">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': applyTemplateSubmitting }" v-if="applyTemplateSubmitting">sync</span>
            {{ applyMode === 'replace' ? 'เขียนทับ' : 'เพิ่มจุดรับ' }}ใน {{ applySelectedScheduleIds.length }} รอบ
          </button>
        </div>
      </div>
    </div>

    <!-- Copy Pickup Modal -->
    <div class="modal-overlay" v-if="showCopyModal">
      <div class="modal-card">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">content_copy</span>คัดลอกจุดรับไปยังรอบอื่น</h2>
            <p class="modal-subtitle">
              จาก <strong>{{ copySource?.trip?.title }} — {{ copySource?.departure_date }}</strong>
              <span v-if="copySource?.vehicle?.license_plate">({{ copySource.vehicle.license_plate }})</span>
              · {{ copySourceCount }} จุด
            </p>
          </div>
          <button class="modal-close" @click="showCopyModal = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">

          <!-- mode -->
          <div class="apply-section">
            <div class="apply-section-title"><span class="material-symbols-rounded">tune</span> วิธีใช้งาน</div>
            <div class="apply-mode-wrap">
              <label class="apply-mode-option" :class="{ active: copyMode === 'images' }">
                <input type="radio" v-model="copyMode" value="images" />
                <span class="material-symbols-rounded">image</span>
                <div>
                  <div style="font-weight:700;">เฉพาะรูป <span style="font-size:10px;color:#059669;font-weight:700;">· ปลอดภัยกับใบจอง</span></div>
                  <div style="font-size:11px;color:#6b7280;">อัปเดตเฉพาะรูปให้จุดที่ชื่อตรงกัน ไม่ลบ/ไม่เพิ่มจุด</div>
                </div>
              </label>
              <label class="apply-mode-option" :class="{ active: copyMode === 'append' }">
                <input type="radio" v-model="copyMode" value="append" />
                <span class="material-symbols-rounded">add_circle</span>
                <div>
                  <div style="font-weight:700;">เพิ่มเข้าไป</div>
                  <div style="font-size:11px;color:#6b7280;">จุดรับเดิมยังคงอยู่ ข้ามจุดที่ซ้ำ</div>
                </div>
              </label>
              <label class="apply-mode-option" :class="{ active: copyMode === 'replace' }">
                <input type="radio" v-model="copyMode" value="replace" />
                <span class="material-symbols-rounded">sync</span>
                <div>
                  <div style="font-weight:700;">เขียนทับทั้งหมด</div>
                  <div style="font-size:11px;color:#ef4444;">ลบจุดรับเดิมทั้งหมดแล้วเขียนใหม่ (ใบจองอาจเสียจุดรับ)</div>
                </div>
              </label>
            </div>
          </div>

          <!-- target select -->
          <div class="apply-section">
            <div class="apply-section-title"><span class="material-symbols-rounded">calendar_month</span> เลือกรอบปลายทาง ({{ copySelectedIds.length }} รอบ)</div>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
              <button class="btn-sm btn-secondary" @click="copySelectAll()">เลือกทั้งหมด</button>
              <button class="btn-sm btn-secondary" @click="copySelectedIds = []">ยกเลิกทั้งหมด</button>
            </div>
            <div class="copy-target-list">
              <div v-for="group in copyTargetGroups" :key="group.trip_id" style="margin-bottom:8px;">
                <div style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;padding:4px 0;">{{ group.trip_title }}</div>
                <label v-for="sch in group.schedules" :key="sch.id" class="copy-target-item">
                  <input type="checkbox" v-model="copySelectedIds" :value="sch.id" />
                  <span>
                    {{ sch.departure_date }}<span v-if="sch.return_date"> → {{ sch.return_date }}</span>
                    <span v-if="sch.vehicle?.license_plate" style="margin-left:8px; color:var(--color-accent); font-weight:700;">
                      ({{ sch.vehicle.license_plate }})
                    </span>
                  </span>
                  <span class="status-badge" :class="`status-${sch.status}`" style="margin-left:auto;">{{ statusLabels[sch.status] }}</span>
                </label>
              </div>
              <div v-if="!copyTargets.length" class="pickup-inline-empty">
                <span class="material-symbols-rounded">event_busy</span> ไม่มีรอบอื่นให้คัดลอก
              </div>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showCopyModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="doCopyPickups" :disabled="!copySelectedIds.length || copySubmitting">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': copySubmitting }" v-if="copySubmitting">sync</span>
            {{ copyMode === 'images' ? 'ซิงค์รูป' : copyMode === 'replace' ? 'เขียนทับ' : 'คัดลอก' }}ไป {{ copySelectedIds.length }} รอบ
          </button>
        </div>
      </div>
    </div>

    <!-- Existing Pickup Image Picker Modal -->
    <div class="modal-overlay" v-if="showImagePicker" @click.self="showImagePicker = false">
      <div class="modal-card">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">photo_library</span>เลือกรูปที่มีอยู่</h2>
            <p class="modal-subtitle">รูปจุดรับที่เคยอัปโหลดไว้ · กดเพื่อใช้รูปนั้น</p>
          </div>
          <button class="modal-close" @click="showImagePicker = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div v-if="imagePickerLoading" class="pickup-loading"><div class="spinner"></div></div>
          <div v-else-if="!existingImages.length" class="pickup-inline-empty">
            <span class="material-symbols-rounded">image_not_supported</span> ยังไม่มีรูปที่เคยอัปโหลด
          </div>
          <div v-else class="img-picker-grid">
            <button
              v-for="(img, i) in existingImages" :key="i"
              type="button"
              class="img-picker-item"
              :class="{ active: imagePickerTarget?.image_url === img.url }"
              @click="pickExistingImage(img.url)"
            >
              <img :src="img.url" :alt="img.label" loading="lazy" />
              <span class="img-picker-label">{{ img.label }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Bulk Join Trip Modal -->
    <div class="modal-overlay" v-if="showBulkJoinTrip">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:#059669;margin-right:8px;">group_add</span>จัดการจอยทริปแบบกลุ่ม</h2>
            <p class="modal-subtitle">เลือกรอบเดินทางที่ต้องการ แล้วเปิด/ปิดจอยทริปพร้อมกัน</p>
          </div>
          <button class="modal-close" @click="showBulkJoinTrip = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">

          <!-- Settings -->
          <div class="batch-section">
            <h3 class="section-label"><span class="material-symbols-rounded">tune</span> ตั้งค่าจอยทริป</h3>
            <div class="form-grid">
              <div class="form-group">
                <label>สถานะจอยทริป</label>
                <select v-model="bulkJoinTripForm.enabled">
                  <option :value="true">เปิดใช้งาน</option>
                  <option :value="false">ปิดใช้งาน</option>
                </select>
              </div>
              <div class="form-group" v-if="bulkJoinTripForm.enabled">
                <label>ราคาจอยทริป (฿) *</label>
                <input v-model.number="bulkJoinTripForm.price" type="number" min="0" placeholder="ราคาต่อท่าน" />
              </div>
            </div>
          </div>

          <!-- Schedule Selection -->
          <div class="batch-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
              <h3 class="section-label" style="margin:0;"><span class="material-symbols-rounded">calendar_month</span> เลือกรอบเดินทาง ({{ bulkJoinTripSelectedIds.length }} รอบ)</h3>
              <div style="display:flex;gap:8px;">
                <button type="button" class="btn-sm btn-secondary" @click="bulkJoinTripSelectAll()">เลือกทั้งหมด</button>
                <button type="button" class="btn-sm btn-secondary" @click="bulkJoinTripSelectedIds = []">ยกเลิกทั้งหมด</button>
              </div>
            </div>
            <div class="copy-target-list" style="max-height:340px;overflow-y:auto;">
              <div v-for="group in groupedByTrip" :key="group.trip_id" style="margin-bottom:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:4px 0;">
                  <span style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;">{{ group.trip_title }}</span>
                  <button type="button" style="font-size:10px;color:var(--color-accent);font-weight:700;cursor:pointer;background:none;border:none;text-decoration:underline;" @click="bulkJoinTripToggleGroup(group)">เลือกทั้งกลุ่ม</button>
                </div>
                <label v-for="sch in group.schedules" :key="sch.id" class="copy-target-item">
                  <input type="checkbox" v-model="bulkJoinTripSelectedIds" :value="sch.id" />
                  <span style="flex:1;">
                    {{ sch.departure_date }}<span v-if="sch.return_date"> → {{ sch.return_date }}</span>
                    <span v-if="sch.vehicle?.license_plate" style="margin-left:8px; color:var(--color-accent); font-weight:700;">({{ sch.vehicle.license_plate }})</span>
                  </span>
                  <span v-if="sch.join_trip_enabled" style="font-size:10px;font-weight:700;color:#059669;background:#ecfdf5;border:1px solid #a7f3d0;padding:2px 8px;border-radius:8px;">จอยทริป ฿{{ Number(sch.join_trip_price || 0).toLocaleString() }}</span>
                  <span v-else style="font-size:10px;color:#9ca3af;">—</span>
                </label>
              </div>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showBulkJoinTrip = false">ยกเลิก</button>
          <button class="btn-primary" @click="doBulkJoinTrip" :disabled="!bulkJoinTripSelectedIds.length || bulkJoinTripSubmitting">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': bulkJoinTripSubmitting }" v-if="bulkJoinTripSubmitting">sync</span>
            {{ bulkJoinTripForm.enabled ? 'เปิดจอยทริป' : 'ปิดจอยทริป' }}ใน {{ bulkJoinTripSelectedIds.length }} รอบ
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div class="modal-overlay" v-if="showDeleteConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบรอบเดินทางนี้ใช่หรือไม่?</p>
          <p class="confirm-warning"><span class="material-symbols-rounded">warning</span> การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบรอบ</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useAdminStore } from '../../stores/admin';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
const toast = useToast();

const REGIONS = [
  { value: 'bangkok',   label: 'กรุงเทพมหานคร' },
  { value: 'north',     label: 'ภาคเหนือ' },
  { value: 'northeast', label: 'ภาคอีสาน' },
  { value: 'central',   label: 'ภาคกลาง' },
  { value: 'east',      label: 'ภาคตะวันออก' },
  { value: 'west',      label: 'ภาคตะวันตก' },
  { value: 'south',     label: 'ภาคใต้' },
];

const admin = useAdminStore();
const filters = reactive({ search: '', status: '', upcoming: false });

// Group all schedules by trip, sorted by departure_date
const groupedByTrip = computed(() => {
  const all = admin.schedules.data || [];
  const map = new Map();
  for (const sch of all) {
    const tid = sch.trip_id;
    if (!map.has(tid)) {
      map.set(tid, {
        trip_id: tid,
        trip_title: sch.trip?.title || 'N/A',
        trip_image: sch.trip?.thumbnail_image || sch.trip?.cover_image || null,
        schedules: [],
        nextDate: null,
        openCount: 0,
        fullCount: 0,
      });
    }
    map.get(tid).schedules.push(sch);
  }
  const today = new Date().toISOString().split('T')[0];
  const groups = [];
  for (const g of map.values()) {
    g.schedules.sort((a, b) => a.departure_date > b.departure_date ? 1 : -1);
    const next = g.schedules.find(s => s.departure_date >= today && s.status === 'open');
    g.nextDate = next?.departure_date || null;
    g.openCount = g.schedules.filter(s => s.status === 'open').length;
    g.fullCount = g.schedules.filter(s => s.status === 'full').length;
    groups.push(g);
  }
  groups.sort((a, b) => (a.nextDate || '9999') > (b.nextDate || '9999') ? 1 : -1);
  return groups;
});

// คลิกการ์ดทริปเพื่อเปิด modal ดูรอบเดินทางทั้งหมดของทริปนั้น
// เก็บเป็น trip_id แล้ว derive group จาก groupedByTrip เพื่อให้ข้อมูลใน modal
// อัปเดตตามอัตโนมัติเมื่อมีการเพิ่ม/แก้ไข/ลบรอบ
const activeTripId = ref(null);
const activeGroup = computed(() =>
  groupedByTrip.value.find(g => g.trip_id === activeTripId.value) || null
);
const openTripSchedules = (group) => { activeTripId.value = group.trip_id; };
const closeSchedulesModal = () => { activeTripId.value = null; };

// ─── Month grouping & day-trip helpers ─────────────────────
const MONTH_TH = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
const monthKeyOf = (dateStr) => (dateStr ? String(dateStr).slice(0, 7) : '');
const monthLabelOf = (dateStr) => {
  if (!dateStr) return '';
  const [y, m] = String(dateStr).split('-');
  return `${MONTH_TH[parseInt(m, 10) - 1]} ${parseInt(y, 10) + 543}`;
};
// Group a trip's schedules into [{ key, label, schedules }] ordered by month
const groupSchedulesByMonth = (schedules) => {
  const map = new Map();
  for (const sch of schedules) {
    const k = monthKeyOf(sch.departure_date);
    if (!map.has(k)) map.set(k, { key: k, label: monthLabelOf(sch.departure_date), schedules: [] });
    map.get(k).schedules.push(sch);
  }
  return [...map.values()].sort((a, b) => (a.key > b.key ? 1 : -1));
};
const isDayTrip = (sch) => !!sch.departure_date && sch.departure_date === sch.return_date;
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
const tripOptions = ref([]);
const vehicleOptions = ref([]);

const form = reactive({
  trip_id: '', departure_date: '', return_date: '', is_day_trip: false,
  departs_time: '', departs_night_before: false,
  total_seats: 10, transport_type: 'van', vehicle_id: null,
  price_override: null, status: 'open',
  installment_enabled: false, installment_count: 2, installment_interval_days: 30,
  deposit_enabled: false, deposit_type: 'amount', deposit_amount: null, deposit_percent: null,
  join_trip_enabled: false, join_trip_price: null,
  is_charter: false,
  flash_sale_enabled: false, flash_sale_price: null, flash_sale_ends_at_local: '',
});

// datetime-local (local time) → เก็บ/แสดงเทียบกับ ISO (UTC) ที่ backend ส่งมา
const toLocalInput = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const flashEndLabel = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

// ─── เวลาออกรถจริง (departs_at) ─────────────────────────────
// รถอาจออกคืนก่อนวันทริป เช่น ทริปเสาร์ที่ 13 แต่รถออกศุกร์ที่ 12 เวลา 23:30

const shiftDateStr = (dateStr, days) => {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + days);
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${m}-${day}`;
};

const buildDepartsAt = (departureDate, time, nightBefore) => {
  if (!time || !departureDate) return null;
  const date = nightBefore ? shiftDateStr(departureDate, -1) : departureDate;
  return `${date} ${time}:00`;
};

const departsTimeLabel = (sch) => {
  if (!sch.departs_at) return '';
  const time = sch.departs_at.slice(11, 16);
  const nightBefore = sch.departs_at.slice(0, 10) < sch.departure_date;
  return `ออกรถ ${time} น.${nightBefore ? ' (คืนก่อนวันทริป)' : ''}`;
};

const statusLabels = { 
  open: 'เปิด', closed: 'ปิด', full: 'เต็ม', cancelled: 'ยกเลิก',
  pending: 'รอยืนยัน', confirmed: 'ยืนยันแล้ว', refunded: 'คืนเงินแล้ว'
};

let debounceTimer = null;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(), 300);
};

const fetchData = () => {
  const params = { per_page: 500 };
  if (filters.search) params.search = filters.search;
  if (filters.status) params.status = filters.status;
  if (filters.upcoming) params.upcoming = 1;
  // เปิดหน้ามาให้ทุกหัวข้อ "ปิด" ไว้ก่อน แล้วค่อยกดเปิดเฉพาะที่ต้องการ
  admin.fetchSchedules(params);
};

const loadOptions = async () => {
  try {
    const [tripsRes, vehiclesRes] = await Promise.all([
      api.get('/admin/trips', { params: { per_page: 100 } }),
      api.get('/admin/vehicles', { params: { per_page: 100 } }),
    ]);
    tripOptions.value = tripsRes.data.data;
    vehicleOptions.value = vehiclesRes.data.data;
  } catch {}
};

const openForm = (item = null) => {
  // item can be a full schedule (edit) or { trip_id } (new schedule for trip)
  editing.value = item?.departure_date ? item : null;
  if (item?.departure_date) {
    Object.assign(form, {
      trip_id: item.trip_id,
      departure_date: item.departure_date,
      return_date: item.return_date,
      is_day_trip: item.departure_date === item.return_date,
      departs_time: item.departs_at ? item.departs_at.slice(11, 16) : '',
      departs_night_before: !!item.departs_at && item.departs_at.slice(0, 10) < item.departure_date,
      total_seats: item.total_seats,
      transport_type: item.transport_type,
      vehicle_id: item.vehicle?.id || null,
      price_override: item.price || null,
      status: item.status,
      installment_enabled: !!item.installment_enabled,
      installment_count: item.installment_count || 2,
      installment_interval_days: item.installment_interval_days || 30,
      deposit_enabled: !!item.deposit_enabled,
      deposit_type: item.deposit_type || 'amount',
      deposit_amount: item.deposit_amount ? Number(item.deposit_amount) : null,
      deposit_percent: item.deposit_percent || null,
      join_trip_enabled: !!item.join_trip_enabled,
      join_trip_price: item.join_trip_price || null,
      is_charter: !!item.is_charter,
      flash_sale_enabled: !!item.flash_sale,
      flash_sale_price: item.flash_sale?.price || null,
      flash_sale_ends_at_local: toLocalInput(item.flash_sale?.ends_at),
    });
  } else {
    Object.assign(form, {
      trip_id: item?.trip_id || '',
      departure_date: '', return_date: '', is_day_trip: false,
      departs_time: '', departs_night_before: false,
      total_seats: 10, transport_type: 'van', vehicle_id: null,
      price_override: null, status: 'open',
      installment_enabled: false, installment_count: 2, installment_interval_days: 30,
      deposit_enabled: false, deposit_type: 'amount', deposit_amount: null, deposit_percent: null,
      join_trip_enabled: false, join_trip_price: null,
      is_charter: false,
      flash_sale_enabled: false, flash_sale_price: null, flash_sale_ends_at_local: '',
    });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  try {
    const data = { ...form };
    if (data.is_day_trip) data.return_date = data.departure_date;
    delete data.is_day_trip;
    data.departs_at = buildDepartsAt(data.departure_date, data.departs_time, data.departs_night_before);
    delete data.departs_time;
    delete data.departs_night_before;
    if (!data.price_override) data.price_override = null;
    if (!data.deposit_enabled) {
      data.deposit_type = null;
      data.deposit_amount = null;
      data.deposit_percent = null;
    } else if (data.deposit_type === 'amount') {
      data.deposit_percent = null;
    } else if (data.deposit_type === 'percent') {
      data.deposit_amount = null;
    }
    // Flash sale: send end time as UTC ISO; clear price/end when turned off.
    if (data.flash_sale_enabled) {
      data.flash_sale_ends_at = data.flash_sale_ends_at_local
        ? new Date(data.flash_sale_ends_at_local).toISOString()
        : null;
    } else {
      data.flash_sale_price = null;
      data.flash_sale_ends_at = null;
    }
    delete data.flash_sale_ends_at_local;
    if (editing.value) {
      await admin.updateSchedule(editing.value.id, data);
    } else {
      await admin.createSchedule(data);
    }
    showForm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (item) => { deleting.value = item; showDeleteConfirm.value = true; };

// ─── Batch Create ──────────────────────────────────────────
const showBatchForm = ref(false);
const batchSubmitting = ref(false);

const batchForm = reactive({
  trip_id: '',
  transport_type: 'van',
  vehicle_id: null,
  total_seats: 10,
  price_override: null,
  is_day_trip: false,
  departs_time: '',
  departs_night_before: false,
  dates: [{ departure_date: '', return_date: '' }],
  pickups: [],
  join_trip_enabled: false,
  join_trip_price: null,
  installment_enabled: false,
  installment_count: 2,
  installment_interval_days: 30,
  deposit_enabled: false,
  deposit_type: 'amount',
  deposit_amount: null,
  deposit_percent: null,
});

// ─── Move Bookings ────────────────────────────────────────
const showMoveBookingsModal = ref(false);
const moveSubmitting = ref(false);
const moveLoading = ref(false);
const moveSource = ref(null);
const moveTargetId = ref(null);
const movePassengers = ref([]);
const moveSelectedPassengerIds = ref([]);
const moveTargetSeatMap = ref(null);
const moveSeatMapLoading = ref(false);
const moveSeatMapError = ref('');
const moveSeatAssignments = reactive({});
const activeMoveSeatPassengerId = ref(null);

// รอบปลายทางแสดงเฉพาะทริปเดียวกับต้นทางเท่านั้น
const moveTargetGroups = computed(() => {
  if (!moveSource.value) return [];
  const tid = moveSource.value.trip_id ?? moveSource.value.trip?.id;
  return groupedByTrip.value.filter((g) => g.trip_id === tid);
});

const selectedMovePassengerCount = computed(() => moveSelectedPassengerIds.value.length);
const selectedMovePassengers = computed(() => {
  const selected = new Set(moveSelectedPassengerIds.value);
  return movePassengers.value.filter((p) => selected.has(p.id));
});
const selectedMoveSeatCount = computed(() => selectedMovePassengers.value.filter((p) => !p.is_join_trip).length);
const selectedMoveSeatPassengers = computed(() => selectedMovePassengers.value.filter((p) => !p.is_join_trip && passengerSeatLabels(p).length));
const isSameScheduleMove = computed(() => moveSource.value && moveTargetId.value && Number(moveTargetId.value) === Number(moveSource.value.id));
const selectedOriginalMoveSeatIds = computed(() => {
  const seatIds = selectedMoveSeatPassengers.value.flatMap((passenger) => passengerSeatLabels(passenger));
  return new Set(seatIds.filter(Boolean));
});
const movePassengerGroups = computed(() => {
  const map = new Map();

  for (const passenger of movePassengers.value) {
    const booking = passenger.booking || {};
    const key = booking.id || passenger.booking_id || passenger.id;

    if (!map.has(key)) {
      map.set(key, {
        booking_id: key,
        booking_ref: booking.booking_ref || '-',
        status: booking.status || passenger.status || '',
        is_join_trip: Boolean(passenger.is_join_trip || booking.is_join_trip),
        passengers: [],
      });
    }

    map.get(key).passengers.push(passenger);
  }

  return [...map.values()];
});
const moveTargetSeats = computed(() => moveTargetSeatMap.value?.seats || []);
const availableMoveTargetSeats = computed(() => moveTargetSeats.value.filter((seat) => isMoveTargetSeatAvailable(seat.id)));
const moveSeatAssignmentRows = computed(() => selectedMoveSeatPassengers.value.map((passenger) => {
  const originalSeatId = passengerSeatLabels(passenger)[0] || '';
  return {
    passenger,
    originalSeatId,
    originalSeatAvailable: originalSeatId ? isMoveTargetSeatAvailable(originalSeatId) : false,
    assignedSeatId: moveSeatAssignments[passenger.id] || '',
  };
}));
const moveSeatGridColumns = computed(() => moveTargetSeatMap.value?.columns || []);
const moveSeatGridStyle = computed(() => ({
  gridTemplateColumns: moveSeatGridColumns.value.map((column) => column === '' ? '34px' : '58px').join(' '),
}));
const moveSeatCells = computed(() => {
  if (!moveTargetSeatMap.value) return [];

  const rows = moveTargetSeatMap.value.rows || 0;
  const columns = moveSeatGridColumns.value;
  const seatsById = new Map(moveTargetSeats.value.map((seat) => [seat.id, seat]));
  const cells = [];

  for (let row = 1; row <= rows; row += 1) {
    columns.forEach((column, columnIndex) => {
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
const moveSeatAssignmentError = computed(() => {
  if (!moveTargetId.value || !selectedMoveSeatPassengers.value.length) return '';
  if (moveSeatMapLoading.value) return 'กำลังโหลดข้อมูลที่นั่งปลายทาง';
  if (moveSeatMapError.value) return moveSeatMapError.value;

  const assignedSeats = selectedMoveSeatPassengers.value.map((p) => moveSeatAssignments[p.id]).filter(Boolean);
  if (assignedSeats.length < selectedMoveSeatPassengers.value.length) {
    return 'กรุณาเลือกที่นั่งปลายทางให้ครบทุกคนที่มีที่นั่งเดิม';
  }

  const duplicateSeats = assignedSeats.filter((seatId, index) => assignedSeats.indexOf(seatId) !== index);
  if (duplicateSeats.length) {
    return `มีการเลือกที่นั่งซ้ำ: ${[...new Set(duplicateSeats)].join(', ')}`;
  }

  const unavailableSeats = assignedSeats.filter((seatId) => !isMoveTargetSeatAvailable(seatId));
  if (unavailableSeats.length) {
    return `ที่นั่ง ${[...new Set(unavailableSeats)].join(', ')} ไม่ว่างในรอบปลายทาง`;
  }

  return '';
});

const openMoveBookingsModal = async (sch) => {
  moveSource.value = sch;
  moveTargetId.value = null;
  movePassengers.value = [];
  moveSelectedPassengerIds.value = [];
  resetMoveSeatSelection();
  showMoveBookingsModal.value = true;
  moveLoading.value = true;

  try {
    const res = await admin.fetchManifest(sch.id);
    movePassengers.value = res.data?.passengers || [];
    selectAllMovePassengers();
  } catch (e) {
    toast.error('ไม่สามารถดึงรายชื่อผู้โดยสารสำหรับย้ายรอบได้');
    closeMoveBookingsModal();
  } finally {
    moveLoading.value = false;
  }
};

const doMoveBookings = async () => {
  if (!moveTargetId.value || !selectedMovePassengerCount.value) return;
  if (!confirm(`ยืนยันการย้ายผู้โดยสาร ${selectedMovePassengerCount.value} คนจากวันที่ ${moveSource.value.departure_date} ใช่หรือไม่?\n\nการดำเนินการนี้จะเปลี่ยนข้อมูลถาวร`)) return;
  
  moveSubmitting.value = true;
  try {
    const res = await api.post('/admin/schedules/move-bookings', {
      source_schedule_id: moveSource.value.id,
      target_schedule_id: moveTargetId.value,
      passenger_ids: moveSelectedPassengerIds.value,
      seat_assignments: buildMoveSeatAssignmentsPayload(),
    });
    toast.success(res.data?.message || 'ย้ายการจองสำเร็จ');
    closeMoveBookingsModal();
    fetchData();
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการย้ายการจอง');
  } finally {
    moveSubmitting.value = false;
  }
};

const closeMoveBookingsModal = () => {
  showMoveBookingsModal.value = false;
  moveSource.value = null;
  moveTargetId.value = null;
  movePassengers.value = [];
  moveSelectedPassengerIds.value = [];
  resetMoveSeatSelection();
  moveLoading.value = false;
};

const selectAllMovePassengers = () => {
  moveSelectedPassengerIds.value = movePassengers.value.map((p) => p.id);
};

const isMoveBookingGroupSelected = (group) => {
  if (!group.passengers.length) return false;
  return group.passengers.every((p) => moveSelectedPassengerIds.value.includes(p.id));
};

const isMoveBookingGroupPartial = (group) => {
  const selectedCount = group.passengers.filter((p) => moveSelectedPassengerIds.value.includes(p.id)).length;
  return selectedCount > 0 && selectedCount < group.passengers.length;
};

const toggleMoveBookingGroup = (group) => {
  const selected = new Set(moveSelectedPassengerIds.value);
  const allSelected = group.passengers.every((p) => selected.has(p.id));

  group.passengers.forEach((p) => {
    if (allSelected) selected.delete(p.id);
    else selected.add(p.id);
  });

  moveSelectedPassengerIds.value = [...selected];
};

const passengerSeatLabels = (passenger) => {
  const passengers = movePassengers.value.filter((p) => p.booking?.id === passenger.booking?.id);
  const passengerIndex = passengers.findIndex((p) => p.id === passenger.id);
  const seats = passenger.booking?.seats || [];
  const byName = seats.filter((seat) => seat.passenger_name && seat.passenger_name === passenger.name);

  if (byName.length) return byName.map((seat) => seat.seat_id);
  return seats[passengerIndex]?.seat_id ? [seats[passengerIndex].seat_id] : [];
};

const resetMoveSeatSelection = () => {
  moveTargetSeatMap.value = null;
  moveSeatMapError.value = '';
  moveSeatMapLoading.value = false;
  activeMoveSeatPassengerId.value = null;
  Object.keys(moveSeatAssignments).forEach((key) => delete moveSeatAssignments[key]);
};

const isMoveTargetSeatAvailable = (seatId) => {
  const seat = moveTargetSeats.value.find((item) => item.id === seatId);
  return Boolean(seat && (seat.status === 'available' || (isSameScheduleMove.value && selectedOriginalMoveSeatIds.value.has(seatId))));
};

const initializeMoveSeatAssignments = () => {
  const usedSeats = new Set();

  selectedMoveSeatPassengers.value.forEach((passenger) => {
    const current = moveSeatAssignments[passenger.id];
    const originalSeatId = passengerSeatLabels(passenger)[0] || '';
    if (current && isMoveTargetSeatAvailable(current) && !usedSeats.has(current) && !(isSameScheduleMove.value && current === originalSeatId)) {
      usedSeats.add(current);
      return;
    }

    if (!isSameScheduleMove.value && originalSeatId && isMoveTargetSeatAvailable(originalSeatId) && !usedSeats.has(originalSeatId)) {
      moveSeatAssignments[passenger.id] = originalSeatId;
      usedSeats.add(originalSeatId);
    } else {
      moveSeatAssignments[passenger.id] = '';
    }
  });

  Object.keys(moveSeatAssignments).forEach((passengerId) => {
    if (!selectedMoveSeatPassengers.value.some((passenger) => String(passenger.id) === String(passengerId))) {
      delete moveSeatAssignments[passengerId];
    }
  });

  if (!selectedMoveSeatPassengers.value.some((passenger) => passenger.id === activeMoveSeatPassengerId.value)) {
    activeMoveSeatPassengerId.value = selectedMoveSeatPassengers.value.find((passenger) => !moveSeatAssignments[passenger.id])?.id
      || selectedMoveSeatPassengers.value[0]?.id
      || null;
  }
};

const buildMoveSeatAssignmentsPayload = () => {
  const payload = {};
  selectedMoveSeatPassengers.value.forEach((passenger) => {
    if (moveSeatAssignments[passenger.id]) {
      payload[passenger.id] = moveSeatAssignments[passenger.id];
    }
  });
  return payload;
};

const activeMoveSeatPassenger = computed(() => selectedMoveSeatPassengers.value.find((passenger) => passenger.id === activeMoveSeatPassengerId.value) || null);

const moveSeatAssignedPassengerName = (seatId) => {
  const passengerId = Object.entries(moveSeatAssignments).find(([, assignedSeatId]) => assignedSeatId === seatId)?.[0];
  if (!passengerId) return '';
  return selectedMoveSeatPassengers.value.find((passenger) => String(passenger.id) === String(passengerId))?.name || '';
};

const canSelectMoveSeat = (seat) => {
  if (!seat || !isMoveTargetSeatAvailable(seat.id) || !activeMoveSeatPassenger.value) return false;
  const assignedPassengerName = moveSeatAssignedPassengerName(seat.id);
  return !assignedPassengerName || moveSeatAssignments[activeMoveSeatPassenger.value.id] === seat.id;
};

const moveSeatButtonClass = (seat) => {
  const assignedPassengerName = moveSeatAssignedPassengerName(seat.id);
  const available = isMoveTargetSeatAvailable(seat.id);

  return {
    available: available && !assignedPassengerName,
    booked: !available,
    selected: Boolean(assignedPassengerName),
    active: activeMoveSeatPassenger.value && moveSeatAssignments[activeMoveSeatPassenger.value.id] === seat.id,
  };
};

const moveSeatTitle = (seat) => {
  if (!isMoveTargetSeatAvailable(seat.id)) {
    return seat.passenger_name ? `จองแล้วโดย ${seat.passenger_name}` : 'ที่นั่งไม่ว่าง';
  }

  const assignedPassengerName = moveSeatAssignedPassengerName(seat.id);
  if (assignedPassengerName) return `เลือกให้ ${assignedPassengerName}`;
  if (activeMoveSeatPassenger.value) return `เลือกที่นั่ง ${seat.label || seat.id} ให้ ${activeMoveSeatPassenger.value.name}`;
  return 'เลือกผู้โดยสารก่อน';
};

const assignMoveSeat = (seat) => {
  if (!canSelectMoveSeat(seat)) return;

  moveSeatAssignments[activeMoveSeatPassenger.value.id] = seat.id;
  activeMoveSeatPassengerId.value = selectedMoveSeatPassengers.value.find((passenger) => !moveSeatAssignments[passenger.id])?.id
    || activeMoveSeatPassenger.value.id;
};

const fetchMoveTargetSeatMap = async () => {
  if (!moveTargetId.value) {
    resetMoveSeatSelection();
    return;
  }

  moveSeatMapLoading.value = true;
  moveSeatMapError.value = '';

  try {
    const res = await api.get(`/schedules/${moveTargetId.value}/seats`);
    moveTargetSeatMap.value = res.data?.data || null;
    initializeMoveSeatAssignments();
  } catch (e) {
    moveTargetSeatMap.value = null;
    moveSeatMapError.value = e.response?.data?.message || 'ไม่สามารถโหลดที่นั่งปลายทางได้';
  } finally {
    moveSeatMapLoading.value = false;
  }
};

const isMoveTargetDisabled = (sch) => {
  if (!moveSource.value) return true;
  if (!selectedMovePassengerCount.value) return true;
  if (Number(sch.id) === Number(moveSource.value.id)) return !selectedMoveSeatPassengers.value.length;
  return Number(sch.available_seats || 0) < selectedMoveSeatCount.value;
};

watch(moveTargetId, () => {
  fetchMoveTargetSeatMap();
});

watch(moveSelectedPassengerIds, () => {
  initializeMoveSeatAssignments();
}, { deep: true });

const openBatchForm = (presetTripId = '') => {
  Object.assign(batchForm, {
    trip_id: presetTripId || '',
    transport_type: 'van',
    vehicle_id: null,
    total_seats: 10,
    price_override: null,
    is_day_trip: false,
    departs_time: '',
    departs_night_before: false,
    dates: [{ departure_date: '', return_date: '' }],
    pickups: [],
    join_trip_enabled: false,
    join_trip_price: null,
    installment_enabled: false,
    installment_count: 2,
    installment_interval_days: 30,
    deposit_enabled: false,
    deposit_type: 'amount',
    deposit_amount: null,
    deposit_percent: null,
  });
  showBatchForm.value = true;
};

const openBatchFormForTrip = (tripId) => openBatchForm(tripId);

const deleteTripGroup = async (group) => {
  const count = group.schedules.length;
  if (!confirm(`ลบรอบเดินทางทั้งหมด ${count} รอบ ของ "${group.trip_title}" ใช่หรือไม่?\n\nการดำเนินการนี้ไม่สามารถย้อนกลับได้`)) return;
  try {
    await Promise.all(group.schedules.map(sch => admin.deleteSchedule(sch.id)));
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  }
};

const addDateRow = () => batchForm.dates.push({ departure_date: '', return_date: '' });
const removeDateRow = (i) => { if (batchForm.dates.length > 1) batchForm.dates.splice(i, 1); };

// Live month label for a batch date row (updates as the admin types)
const rowMonthLabel = (d) => monthLabelOf(d.departure_date);
// Distinct months covered by the current date list, for the summary chip
const batchMonthsCovered = computed(() => {
  const set = new Set(batchForm.dates.map(d => monthKeyOf(d.departure_date)).filter(Boolean));
  return set.size;
});

// ─── Auto-generate dates ───────────────────────────────────
const batchGen = reactive({ start: '', count: 4, every: 7, nights: 1 });
const addDays = (dateStr, days) => {
  const dt = new Date(dateStr + 'T00:00:00');
  dt.setDate(dt.getDate() + days);
  const y = dt.getFullYear();
  const m = String(dt.getMonth() + 1).padStart(2, '0');
  const day = String(dt.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};
const generateDates = () => {
  if (!batchGen.start) { toast.error('กรุณาเลือกวันเริ่มต้น'); return; }
  const count = Math.max(1, Number(batchGen.count) || 1);
  const every = Math.max(1, Number(batchGen.every) || 1);
  const nights = batchForm.is_day_trip ? 0 : Math.max(0, Number(batchGen.nights) || 0);
  const rows = [];
  for (let i = 0; i < count; i++) {
    const dep = addDays(batchGen.start, i * every);
    rows.push({ departure_date: dep, return_date: addDays(dep, nights) });
  }
  // Drop any leading blank row, then append generated rows
  const existing = batchForm.dates.filter(d => d.departure_date);
  batchForm.dates = [...existing, ...rows];
  toast.success(`สร้าง ${rows.length} วันเดินทางแล้ว`);
};

// Keep return_date in sync with departure when day-trip mode is on
watch(() => batchForm.is_day_trip, (on) => {
  if (on) batchForm.dates.forEach(d => { d.return_date = d.departure_date; });
});

const addPickupRow = () => batchForm.pickups.push({
  region: '', region_label: '', pickup_location: '', price: '', notes: '', pickup_time: '', map_url: '', image_url: '',
});
const removePickupRow = (i) => batchForm.pickups.splice(i, 1);

const onBatchRegionChange = (pt) => {
  const found = REGIONS.find(r => r.value === pt.region);
  if (found) pt.region_label = found.label;
};

const submitBatchForm = async () => {
  batchSubmitting.value = true;
  try {
    const results = [];
    for (const d of batchForm.dates) {
      if (!d.departure_date) continue;
      const returnDate = batchForm.is_day_trip ? d.departure_date : d.return_date;
      const scheduleRes = await api.post('/admin/schedules', {
        trip_id: batchForm.trip_id,
        transport_type: batchForm.transport_type,
        vehicle_id: batchForm.vehicle_id,
        total_seats: batchForm.total_seats,
        price_override: batchForm.price_override || null,
        departure_date: d.departure_date,
        departs_at: buildDepartsAt(d.departure_date, batchForm.departs_time, batchForm.departs_night_before),
        return_date: returnDate,
        status: 'open',
        join_trip_enabled: batchForm.join_trip_enabled || false,
        join_trip_price: batchForm.join_trip_enabled ? batchForm.join_trip_price : null,
        installment_enabled: batchForm.installment_enabled || false,
        installment_count: batchForm.installment_count || 2,
        installment_interval_days: batchForm.installment_interval_days || 30,
        deposit_enabled: batchForm.deposit_enabled || false,
        deposit_type: batchForm.deposit_enabled ? batchForm.deposit_type : null,
        deposit_amount: batchForm.deposit_enabled && batchForm.deposit_type === 'amount' ? batchForm.deposit_amount : null,
        deposit_percent: batchForm.deposit_enabled && batchForm.deposit_type === 'percent' ? batchForm.deposit_percent : null,
      });
      const scheduleId = scheduleRes.data.data.id;
      for (const pt of batchForm.pickups) {
        if (!pt.region || !pt.pickup_location || pt.price === '') continue;
        await api.post(`/admin/schedules/${scheduleId}/pickup-points`, {
          region: pt.region,
          region_label: pt.region_label,
          pickup_location: pt.pickup_location,
          price: pt.price,
          notes: pt.notes || null,
          pickup_time: pt.pickup_time || null,
          map_url: pt.map_url || null,
          image_url: pt.image_url || null,
        });
      }
      results.push(scheduleId);
    }
    showBatchForm.value = false;
    fetchData();
    alert(`สร้าง ${results.length} รอบสำเร็จ`);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    batchSubmitting.value = false;
  }
};

// ─── Copy Schedule ─────────────────────────────────────────
const showCopyScheduleModal = ref(false);
const copyScheduleSource = ref(null);
const copyScheduleSubmitting = ref(false);
const copyScheduleForm = reactive({
  trip_id: '',
  departure_date: '',
  return_date: '',
  include_pickups: true,
});

const openCopyScheduleModal = (sch) => {
  copyScheduleSource.value = sch;
  Object.assign(copyScheduleForm, {
    trip_id: sch.trip_id,
    departure_date: '',
    return_date: '',
    include_pickups: true,
  });
  showCopyScheduleModal.value = true;
};

const doCopySchedule = async () => {
  copyScheduleSubmitting.value = true;
  try {
    const src = copyScheduleSource.value;
    // คงเวลาออกรถจริงของต้นฉบับ (รวม offset คืนก่อนวันทริป) เทียบกับวันใหม่
    const srcNightBefore = !!src.departs_at && src.departs_at.slice(0, 10) < src.departure_date;
    const newScheduleRes = await api.post('/admin/schedules', {
      trip_id: copyScheduleForm.trip_id,
      departure_date: copyScheduleForm.departure_date,
      departs_at: src.departs_at
        ? buildDepartsAt(copyScheduleForm.departure_date, src.departs_at.slice(11, 16), srcNightBefore)
        : null,
      return_date: copyScheduleForm.return_date,
      total_seats: src.total_seats,
      transport_type: src.transport_type,
      vehicle_id: src.vehicle?.id || null,
      price_override: src.price || null,
      status: 'open',
      installment_enabled: src.installment_enabled || false,
      installment_count: src.installment_count || 2,
      installment_interval_days: src.installment_interval_days || 30,
      deposit_enabled: src.deposit_enabled || false,
      deposit_type: src.deposit_enabled ? (src.deposit_type || 'amount') : null,
      deposit_amount: src.deposit_enabled && src.deposit_type !== 'percent' ? (src.deposit_amount ? Number(src.deposit_amount) : null) : null,
      deposit_percent: src.deposit_enabled && src.deposit_type === 'percent' ? src.deposit_percent : null,
      join_trip_enabled: src.join_trip_enabled || false,
      join_trip_price: src.join_trip_price || null,
    });
    const newId = newScheduleRes.data.data.id;
    if (copyScheduleForm.include_pickups) {
      const ptRes = await api.get(`/admin/schedules/${src.id}/pickup-points`);
      for (const pt of ptRes.data.data) {
        await api.post(`/admin/schedules/${newId}/pickup-points`, {
          region: pt.region,
          region_label: pt.region_label,
          pickup_location: pt.pickup_location,
          price: pt.price,
          notes: pt.notes || null,
          pickup_time: pt.pickup_time || null,
          map_url: pt.map_url || null,
          image_url: pt.image_url || null,
          latitude: pt.latitude || null,
          longitude: pt.longitude || null,
          sort_order: pt.sort_order || 0,
        });
      }
    }
    showCopyScheduleModal.value = false;
    fetchData();
    const destTrip = tripOptions.value.find(t => t.id === copyScheduleForm.trip_id);
    alert(`คัดลอกรอบเดินทางสำเร็จ${destTrip ? ` ไปยัง ${destTrip.title}` : ''}`);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    copyScheduleSubmitting.value = false;
  }
};

// ─── Copy Pickup Points ────────────────────────────────────
const showCopyModal = ref(false);
const copySource = ref(null);
const copySelectedIds = ref([]);
const copySubmitting = ref(false);
const copyMode = ref('append'); // 'append' | 'replace'
const copySourceCount = ref(0);

const copyTargets = computed(() =>
  (admin.schedules.data || []).filter(s => s.id !== copySource.value?.id)
);

// Group target rounds by trip, putting the source's trip first for quick access
const copyTargetGroups = computed(() => {
  const map = new Map();
  for (const sch of copyTargets.value) {
    const tid = sch.trip_id;
    if (!map.has(tid)) {
      map.set(tid, { trip_id: tid, trip_title: sch.trip?.title || 'N/A', schedules: [] });
    }
    map.get(tid).schedules.push(sch);
  }
  const groups = [...map.values()];
  groups.sort((a, b) => {
    if (a.trip_id === copySource.value?.trip_id) return -1;
    if (b.trip_id === copySource.value?.trip_id) return 1;
    return a.trip_title.localeCompare(b.trip_title);
  });
  for (const g of groups) g.schedules.sort((a, b) => a.departure_date > b.departure_date ? 1 : -1);
  return groups;
});

const copySelectAll = () => {
  copySelectedIds.value = copyTargets.value.map(s => s.id);
};

const copyPickupPoints = async (sch) => {
  if (!sch) return;
  copySource.value = sch;
  copySelectedIds.value = [];
  copyMode.value = 'images';
  // Prefer already-loaded points when copying from the open pickup manager
  copySourceCount.value = (sch.id === pickupSchedule.value?.id ? pickupPoints.value.length : (sch.pickup_points?.length || 0));
  showCopyModal.value = true;
};

const doCopyPickups = async () => {
  copySubmitting.value = true;
  try {
    // Image-only sync: update image_url on matching points in target rounds.
    // Booking-safe — no points are created or deleted.
    if (copyMode.value === 'images') {
      const syncRes = await api.post(`/admin/schedules/${copySource.value.id}/pickup-points/sync-images`, {
        schedule_ids: copySelectedIds.value,
      });
      showCopyModal.value = false;
      fetchData();
      const d = syncRes.data.data || {};
      alert(`ซิงค์รูปไป ${d.updated_schedules ?? 0} รอบ (${d.updated_points ?? 0} จุด)\nจุดที่ชื่อไม่ตรงกันจะถูกข้าม`);
      return;
    }
    const res = await api.get(`/admin/schedules/${copySource.value.id}/pickup-points`);
    const points = res.data.data;
    let skippedCount = 0;
    for (const targetId of copySelectedIds.value) {
      if (copyMode.value === 'replace') {
        // Wipe existing points first — no duplicate handling needed
        const existingRes = await api.get(`/admin/schedules/${targetId}/pickup-points`);
        for (const pt of (existingRes.data.data || [])) {
          await api.delete(`/admin/schedules/${targetId}/pickup-points/${pt.id}`);
        }
      }
      // In append mode, skip points that already exist (region + location)
      let existingKeys = new Set();
      if (copyMode.value === 'append') {
        const existingRes = await api.get(`/admin/schedules/${targetId}/pickup-points`);
        existingKeys = new Set((existingRes.data.data || []).map(p => `${p.region}::${p.pickup_location}`));
      }
      for (const pt of points) {
        const key = `${pt.region}::${pt.pickup_location}`;
        if (existingKeys.has(key)) {
          skippedCount++;
          continue; // skip duplicate
        }
        await api.post(`/admin/schedules/${targetId}/pickup-points`, {
          region: pt.region,
          region_label: pt.region_label,
          pickup_location: pt.pickup_location,
          price: pt.price,
          notes: pt.notes || null,
          pickup_time: pt.pickup_time || null,
          map_url: pt.map_url || null,
          image_url: pt.image_url || null,
          latitude: pt.latitude || null,
          longitude: pt.longitude || null,
          sort_order: pt.sort_order || 0,
        });
        existingKeys.add(key);
      }
    }
    showCopyModal.value = false;
    fetchData();
    const verb = copyMode.value === 'replace' ? 'เขียนทับ' : 'คัดลอก';
    const msg = `${verb}จุดรับไป ${copySelectedIds.value.length} รอบสำเร็จ`;
    alert(skippedCount ? `${msg}\n(ข้ามจุดรับที่ซ้ำ ${skippedCount} จุด)` : msg);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    copySubmitting.value = false;
  }
};

// ─── Pickup Templates (localStorage) ─────────────────────
const TEMPLATES_KEY = 'luilaykhao_pickup_templates';
const loadTemplates = () => {
  try { return JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]'); } catch { return []; }
};
const saveTemplates = (list) => localStorage.setItem(TEMPLATES_KEY, JSON.stringify(list));

const pickupTemplates = ref(loadTemplates());
const showTemplateManager = ref(false);
const editingTemplate = ref(null);

const openTemplateManager = () => {
  // Only reload from storage if currently empty to avoid wiping unsaved changes
  if (pickupTemplates.value.length === 0) {
    pickupTemplates.value = loadTemplates();
  }
  editingTemplate.value = null;
  showTemplateManager.value = true;
};

const startNewTemplate = () => {
  editingTemplate.value = {
    id: Date.now().toString(),
    name: 'เทมเพลตใหม่',
    points: [],
    _isNew: true,
  };
  pickupTemplates.value = [editingTemplate.value, ...pickupTemplates.value];
};

const startEditTemplate = (tpl) => {
  editingTemplate.value = JSON.parse(JSON.stringify(tpl));
};

const cancelEditTemplate = () => {
  if (editingTemplate.value?._isNew) {
    pickupTemplates.value = pickupTemplates.value.filter(t => t.id !== editingTemplate.value.id);
    saveTemplates(pickupTemplates.value);
  }
  editingTemplate.value = null;
};

const saveEditTemplate = () => {
  const idx = pickupTemplates.value.findIndex(t => t.id === editingTemplate.value.id);
  const saved = { ...editingTemplate.value };
  delete saved._isNew;
  if (idx >= 0) pickupTemplates.value[idx] = saved;
  else pickupTemplates.value.unshift(saved);
  saveTemplates(pickupTemplates.value);
  editingTemplate.value = null;
};

const deleteTemplate = (id) => {
  if (!confirm('ลบเทมเพลตนี้ใช่หรือไม่?')) return;
  pickupTemplates.value = pickupTemplates.value.filter(t => t.id !== id);
  saveTemplates(pickupTemplates.value);
};

const addTplPoint = (regionValue) => {
  const found = REGIONS.find(r => r.value === regionValue);
  editingTemplate.value.points.push({
    region: regionValue,
    region_label: found?.label || regionValue,
    pickup_location: '',
    notes: '',
    price: 0,
    map_url: '',
    image_url: '',
  });
};

const removeTplPoint = (regionValue, displayIndex) => {
  const regionPoints = editingTemplate.value.points.filter(p => p.region === regionValue);
  const target = regionPoints[displayIndex];
  const actualIndex = editingTemplate.value.points.indexOf(target);
  if (actualIndex >= 0) editingTemplate.value.points.splice(actualIndex, 1);
};

const saveCurrentAsTemplate = async () => {
  const name = prompt('ชื่อเทมเพลต:', `${pickupSchedule.value?.trip?.title || 'เทมเพลต'} - ${pickupSchedule.value?.departure_date || ''}`);
  if (!name) return;
  const points = pickupPoints.value.map(pt => ({
    region: pt.region,
    region_label: pt.region_label,
    pickup_location: pt.pickup_location,
    notes: pt.notes || '',
    price: pt.price,
    pickup_time: pt.pickup_time || '',
    map_url: pt.map_url || '',
    image_url: pt.image_url || '',
  }));
  const newTpl = { id: Date.now().toString(), name, points };
  pickupTemplates.value = loadTemplates();
  pickupTemplates.value.unshift(newTpl);
  saveTemplates(pickupTemplates.value);
  alert(`บันทึกเทมเพลต "${name}" สำเร็จ (${points.length} จุด)`);
};

// dropdown flag inside pickup manager
const showApplyTemplateDropdown = ref(false);

const applyTemplateToSchedule = async (tpl) => {
  showApplyTemplateDropdown.value = false;
  const mode = confirm(`ใช้เทมเพลต "${tpl.name}" กับรอบนี้\n\n[OK] = เพิ่มเข้าไป (จุดรับเดิมยังอยู่)\n[Cancel] = ยกเลิก`);
  if (!mode) return;
  pickupSubmitting.value = true;
  try {
    const scheduleId = pickupSchedule.value.id;
    // Build set of existing pickup keys to prevent duplicates
    const existingKeys = new Set(pickupPoints.value.map(p => `${p.region}::${p.pickup_location}`));
    let skippedCount = 0;
    for (const pt of tpl.points) {
      if (!pt.pickup_location) continue;
      const key = `${pt.region}::${pt.pickup_location}`;
      if (existingKeys.has(key)) {
        skippedCount++;
        continue; // skip duplicate
      }
      await api.post(`/admin/schedules/${scheduleId}/pickup-points`, {
        region: pt.region,
        region_label: pt.region_label,
        pickup_location: pt.pickup_location,
        price: pt.price,
        notes: pt.notes || null,
        pickup_time: pt.pickup_time || null,
        map_url: pt.map_url || null,
        image_url: pt.image_url || null,
      });
      existingKeys.add(key); // track newly added
    }
    await loadPickupPoints(scheduleId);
    if (skippedCount) alert(`เพิ่มจุดรับสำเร็จ (ข้ามจุดรับที่ซ้ำ ${skippedCount} จุด)`);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

// Apply template to many schedules at once
const showApplyTemplateModal = ref(false);
const applyTemplateIds = ref([]);     // เลือกได้หลาย template
const applyMode = ref('append');      // 'append' | 'replace'
const applySelectedScheduleIds = ref([]);
const applyTemplateSubmitting = ref(false);

const applyTotalPoints = computed(() =>
  applyTemplateIds.value.reduce((sum, id) => {
    const tpl = pickupTemplates.value.find(t => t.id === id);
    return sum + (tpl?.points?.length || 0);
  }, 0)
);

const openApplyTemplateModal = (tpl) => {
  applyTemplateIds.value = tpl ? [tpl.id] : [];
  applyMode.value = 'append';
  applySelectedScheduleIds.value = [];
  // showTemplateManager.value = false; // Don't close manager, let apply modal be on top
  showApplyTemplateModal.value = true;
};

const applySelectAll = () => {
  applySelectedScheduleIds.value = (admin.schedules.data || []).map(s => s.id);
};

const doApplyTemplate = async () => {
  const selectedTemplates = pickupTemplates.value.filter(t => applyTemplateIds.value.includes(t.id));
  const allPoints = selectedTemplates.flatMap(t => t.points);
  applyTemplateSubmitting.value = true;
  let totalSkipped = 0;
  try {
    for (const scheduleId of applySelectedScheduleIds.value) {
      if (applyMode.value === 'replace') {
        // In replace mode, delete everything first — no duplicate issue
        const ptRes = await api.get(`/admin/schedules/${scheduleId}/pickup-points`);
        for (const pt of ptRes.data.data) {
          await api.delete(`/admin/schedules/${scheduleId}/pickup-points/${pt.id}`);
        }
      }
      // Fetch existing to prevent duplicates (in append mode)
      let existingKeys = new Set();
      if (applyMode.value === 'append') {
        const existingRes = await api.get(`/admin/schedules/${scheduleId}/pickup-points`);
        existingKeys = new Set((existingRes.data.data || []).map(p => `${p.region}::${p.pickup_location}`));
      }
      for (const pt of allPoints) {
        if (!pt.pickup_location) continue;
        const key = `${pt.region}::${pt.pickup_location}`;
        if (existingKeys.has(key)) {
          totalSkipped++;
          continue; // skip duplicate
        }
        await api.post(`/admin/schedules/${scheduleId}/pickup-points`, {
          region: pt.region,
          region_label: pt.region_label,
          pickup_location: pt.pickup_location,
          price: pt.price,
          notes: pt.notes || null,
          pickup_time: pt.pickup_time || null,
          map_url: pt.map_url || null,
          image_url: pt.image_url || null,
        });
        existingKeys.add(key); // track newly added
      }
    }
    showApplyTemplateModal.value = false;
    fetchData();
    const base = `${applyMode.value === 'replace' ? 'เขียนทับ' : 'เพิ่มจุดรับ'}${allPoints.length} จุด (จาก ${selectedTemplates.length} เทมเพลต) ใน ${applySelectedScheduleIds.value.length} รอบสำเร็จ`;
    alert(totalSkipped ? `${base}\n(ข้ามจุดรับที่ซ้ำ ${totalSkipped} จุด)` : base);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    applyTemplateSubmitting.value = false;
  }
};

// ─── Pickup Points Manager ───────────────────────────────
const showPickupManager = ref(false);
const pickupSchedule = ref(null);
const pickupPoints = ref([]);
const pickupLoading = ref(false);
const pickupSubmitting = ref(false);
const editingPickup = ref(null);
const addingInRegion = ref(null); // which region's inline-add form is open

// ─── Manifest ──────────────────────────────────────────────
const showManifest = ref(false);
const manifestData = ref(null);
const manifestLoading = ref(false);
const selectedPassenger = ref(null);

const viewPassengerDetails = (p) => {
  selectedPassenger.value = p;
};

const printManifest = () => {
  window.print();
};

const openManifest = async (sch) => {
  showManifest.value = true;
  manifestLoading.value = true;
  manifestData.value = null;
  try {
    const res = await admin.fetchManifest(sch.id);
    manifestData.value = res.data;
  } catch (e) {
    toast.error('ไม่สามารถดึงข้อมูลรายชื่อผู้โดยสารได้');
    showManifest.value = false;
  } finally {
    manifestLoading.value = false;
  }
};


const pickupForm = reactive({
  region: '', region_label: '', pickup_location: '',
  price: '', map_url: '', image_url: '', latitude: null, longitude: null,
  notes: '', pickup_time: '', sort_order: 0,
});
const pickupImageUploading = ref(false);

const uploadPickupImage = async (event, target = pickupForm) => {
  const file = event.target.files?.[0];
  if (!file) return;
  pickupImageUploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const res = await api.post('/admin/pickup-points/image', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    target.image_url = res.data.data.url;
  } catch (e) {
    alert(e.response?.data?.message || 'อัปโหลดรูปไม่สำเร็จ');
  } finally {
    pickupImageUploading.value = false;
    event.target.value = '';
  }
};

// ─── Existing image picker (reuse previously uploaded pickup images) ───
const showImagePicker = ref(false);
const imagePickerTarget = ref(null);
const existingImages = ref([]);
const imagePickerLoading = ref(false);
const imagePickerLoaded = ref(false);

const openImagePicker = async (target) => {
  imagePickerTarget.value = target;
  showImagePicker.value = true;
  if (imagePickerLoaded.value) return;
  imagePickerLoading.value = true;
  try {
    const res = await api.get('/admin/pickup-points/images');
    existingImages.value = res.data.data || [];
    imagePickerLoaded.value = true;
  } catch (e) {
    alert(e.response?.data?.message || 'โหลดรูปไม่สำเร็จ');
  } finally {
    imagePickerLoading.value = false;
  }
};

const pickExistingImage = (url) => {
  if (imagePickerTarget.value) imagePickerTarget.value.image_url = url;
  showImagePicker.value = false;
};

// Group pickup points by region value
const pickupPointsByRegion = computed(() => {
  const map = {};
  for (const pt of pickupPoints.value) {
    if (!map[pt.region]) map[pt.region] = [];
    map[pt.region].push(pt);
  }
  return map;
});

const resetPickupForm = () => {
  Object.assign(pickupForm, {
    region: '', region_label: '', pickup_location: '',
    price: '', map_url: '', image_url: '', latitude: null, longitude: null,
    notes: '', pickup_time: '', sort_order: 0,
  });
  editingPickup.value = null;
};

const onRegionChange = () => {
  const found = REGIONS.find(r => r.value === pickupForm.region);
  if (found && !editingPickup.value) {
    pickupForm.region_label = found.label;
  }
};

const openPickupManager = async (sch) => {
  pickupSchedule.value = sch;
  resetPickupForm();
  addingInRegion.value = null;
  showPickupManager.value = true;
  await loadPickupPoints(sch.id);
};

const loadPickupPoints = async (scheduleId) => {
  pickupLoading.value = true;
  try {
    const res = await api.get(`/admin/schedules/${scheduleId}/pickup-points`);
    pickupPoints.value = res.data.data;
  } catch {
    pickupPoints.value = [];
  } finally {
    pickupLoading.value = false;
  }
};

// Open inline-add form for a specific region
const startAddInRegion = (regionValue) => {
  editingPickup.value = null;
  const found = REGIONS.find(r => r.value === regionValue);
  Object.assign(pickupForm, {
    region: regionValue,
    region_label: found?.label || regionValue,
    pickup_location: '', price: '', map_url: '', image_url: '',
    latitude: null, longitude: null, notes: '', pickup_time: '', sort_order: 0,
  });
  addingInRegion.value = regionValue;
};

const submitPickupForm = async () => {
  if (!pickupForm.pickup_location || pickupForm.price === '') {
    alert('กรุณากรอกจุดขึ้นรถและราคา');
    return;
  }
  pickupSubmitting.value = true;
  try {
    const scheduleId = pickupSchedule.value.id;
    const payload = { ...pickupForm };
    if (!payload.map_url) payload.map_url = null;
    if (!payload.image_url) payload.image_url = null;
    if (!payload.latitude) payload.latitude = null;
    if (!payload.longitude) payload.longitude = null;
    if (!payload.pickup_time) payload.pickup_time = null;

    if (editingPickup.value) {
      await api.put(`/admin/schedules/${scheduleId}/pickup-points/${editingPickup.value.id}`, payload);
    } else {
      await api.post(`/admin/schedules/${scheduleId}/pickup-points`, payload);
    }
    resetPickupForm();
    addingInRegion.value = null;
    await loadPickupPoints(scheduleId);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

const editPickupPoint = (pt) => {
  addingInRegion.value = null;
  editingPickup.value = pt;
  Object.assign(pickupForm, {
    region: pt.region,
    region_label: pt.region_label,
    pickup_location: pt.pickup_location,
    price: pt.price,
    map_url: pt.map_url || '',
    image_url: pt.image_url || '',
    latitude: pt.latitude || null,
    longitude: pt.longitude || null,
    notes: pt.notes || '',
    pickup_time: pt.pickup_time || '',
    sort_order: pt.sort_order || 0,
  });
};

const cancelEditPickup = () => {
  resetPickupForm();
  addingInRegion.value = null;
};

const deletePickupPoint = async (pt) => {
  if (!confirm(`ลบจุดรับ "${pt.pickup_location}" ใช่หรือไม่?`)) return;
  try {
    await api.delete(`/admin/schedules/${pickupSchedule.value.id}/pickup-points/${pt.id}`);
    await loadPickupPoints(pickupSchedule.value.id);
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  }
};

const doDelete = async () => {
  submitting.value = true;
  try {
    await admin.deleteSchedule(deleting.value.id);
    showDeleteConfirm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};
// ─── Bulk Join Trip ────────────────────────────────────────
const showBulkJoinTrip = ref(false);
const bulkJoinTripSubmitting = ref(false);
const bulkJoinTripSelectedIds = ref([]);
const bulkJoinTripForm = reactive({
  enabled: true,
  price: null,
});

const openBulkJoinTripModal = () => {
  bulkJoinTripForm.enabled = true;
  bulkJoinTripForm.price = null;
  bulkJoinTripSelectedIds.value = [];
  showBulkJoinTrip.value = true;
};

const bulkJoinTripSelectAll = () => {
  bulkJoinTripSelectedIds.value = (admin.schedules.data || []).map(s => s.id);
};

const bulkJoinTripToggleGroup = (group) => {
  const ids = group.schedules.map(s => s.id);
  const allSelected = ids.every(id => bulkJoinTripSelectedIds.value.includes(id));
  if (allSelected) {
    bulkJoinTripSelectedIds.value = bulkJoinTripSelectedIds.value.filter(id => !ids.includes(id));
  } else {
    const set = new Set(bulkJoinTripSelectedIds.value);
    ids.forEach(id => set.add(id));
    bulkJoinTripSelectedIds.value = [...set];
  }
};

const doBulkJoinTrip = async () => {
  if (bulkJoinTripForm.enabled && !bulkJoinTripForm.price && bulkJoinTripForm.price !== 0) {
    alert('กรุณาระบุราคาจอยทริป');
    return;
  }
  bulkJoinTripSubmitting.value = true;
  try {
    await admin.bulkUpdateSchedules(bulkJoinTripSelectedIds.value, {
      join_trip_enabled: bulkJoinTripForm.enabled,
      join_trip_price: bulkJoinTripForm.enabled ? bulkJoinTripForm.price : null,
    });
    showBulkJoinTrip.value = false;
    fetchData();
    toast.success(`${bulkJoinTripForm.enabled ? 'เปิด' : 'ปิด'}จอยทริปใน ${bulkJoinTripSelectedIds.value.length} รอบสำเร็จ`);
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    bulkJoinTripSubmitting.value = false;
  }
};

const toggleJoinTrip = async (sch) => {
  try {
    await admin.updateSchedule(sch.id, {
      join_trip_enabled: !sch.join_trip_enabled,
      join_trip_price: !sch.join_trip_enabled ? (sch.join_trip_price || sch.price || 0) : null
    });
    fetchData();
    toast.success(`${!sch.join_trip_enabled ? 'เปิด' : 'ปิด'}จอยทริปสำเร็จ`);
  } catch (e) {
    toast.error('ไม่สามารถเปลี่ยนสถานะจอยทริปได้');
  }
};

const toggleCharter = async (sch) => {
  try {
    await admin.updateSchedule(sch.id, { is_charter: !sch.is_charter });
    fetchData();
    toast.success(`${!sch.is_charter ? 'ตั้งเป็น' : 'ยกเลิก'}รอบเหมาสำเร็จ`);
  } catch (e) {
    toast.error('ไม่สามารถเปลี่ยนสถานะรอบเหมาได้');
  }
};

onMounted(() => {
  fetchData();
  loadOptions();
});
</script>

<style scoped>
@import url('./admin-shared.css');

.btn-active {
  color: #059669 !important;
  background: #ecfdf5 !important;
  border-color: #a7f3d0 !important;
}
.btn-charter-active {
  color: #7c3aed !important;
  background: #f5f3ff !important;
  border-color: #ddd6fe !important;
}
.btn-inactive {
  color: #9ca3af !important;
  background: #f9fafb !important;
  border-color: #e5e7eb !important;
}

.manifest-summary {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 12px;
  margin-bottom: 20px;
}
.ms-item {
  background: #f9fafb;
  padding: 12px;
  border-radius: 10px;
  text-align: center;
  border: 1px solid #f3f4f6;
}
.ms-label {
  display: block;
  font-size: 11px;
  color: #6b7280;
  font-weight: 600;
  margin-bottom: 2px;
}
.ms-value {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}
.ms-join { background: #ecfdf5; border-color: #d1fae5; }
.ms-join .ms-value { color: #059669; }
.ms-total { background: #eff6ff; border-color: #dbeafe; }
.ms-total .ms-value { color: #2563eb; }

.manifest-table-wrap {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}
.manifest-table th { padding: 10px 16px; background: #f9fafb; }
.manifest-table td { padding: 10px 16px; font-size: 13px; }

.btn-manifest-text {
  color: #6366f1 !important;
  border-color: #c7d2fe !important;
  background: #f5f3ff !important;
  white-space: nowrap;
}
.btn-manifest-text:hover {
  background: #eef2ff !important;
  border-color: #a5b4fc !important;
}

.btn-manifest { color: #6366f1; }
.btn-manifest:hover { background: #eef2ff; border-color: #c7d2fe; }

.passenger-name-cell {
  display: flex;
  flex-direction: column;
}
.p-name { font-weight: 700; color: var(--color-text-dark); }
.p-nickname { font-size: 11px; color: var(--color-text-muted); }

.p-seats-pickup {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.p-seats, .p-pickup {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
}
.p-pickup { color: var(--color-accent); font-weight: 600; }

.p-payment-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.p-amount { font-size: 11px; color: var(--color-text-muted); }

.btn-view-details { color: var(--color-accent); }
.btn-view-details:hover { background: #e8f5ec; }

.passenger-details-body {
  padding: 20px;
}
.pd-section {
  margin-bottom: 24px;
}
.pd-section:last-child { margin-bottom: 0; }
.pd-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-muted);
  text-transform: uppercase;
  margin-bottom: 12px;
  padding-bottom: 6px;
  border-bottom: 1px solid #f3f4f6;
}
.pd-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.pd-item { display: flex; flex-direction: column; gap: 4px; }
.pd-item.full { grid-column: span 2; }
.pd-label { font-size: 11px; color: #6b7280; font-weight: 600; }
.pd-value { font-size: 14px; color: var(--color-text-dark); font-weight: 600; }

@media print {
  .admin-page > *:not(.modal-overlay),
  .modal-overlay:not(.show-manifest-overlay), /* We need a specific class for the open manifest modal during print */
  .modal-header button,
  .modal-footer,
  .action-btns,
  .btn-icon,
  .btn-sm,
  .page-header {
    display: none !important;
  }
  .modal-overlay { position: static; background: none; }
  .modal-card { width: 100% !important; max-width: none !important; box-shadow: none !important; border: none !important; }
  .modal-body { padding: 0 !important; }
  .data-table { border: 1px solid #000 !important; }
  .data-table th, .data-table td { border: 1px solid #000 !important; color: #000 !important; }
}

/* ── Accordion loading / empty ── */
.accordion-loading {
  display: flex;
  justify-content: center;
  padding: 48px;
}

.empty-card {
  text-align: center;
  padding: 48px;
  color: var(--color-text-muted);
  font-size: 14px;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 16px;
}

/* ── Trip group ── */
/* ── Trip cards grid ── */
.trip-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 16px;
}

.trip-card {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 16px;
  overflow: hidden;
  cursor: pointer;
  box-shadow: 0 4px 6px rgba(0,0,0,0.02);
  transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
  display: flex;
  flex-direction: column;
}

.trip-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 22px rgba(0,0,0,0.08);
  border-color: var(--color-accent);
}

.trip-card-img {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 10;
  background: var(--color-sand);
  overflow: hidden;
}

.trip-card-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.3s;
}

.trip-card:hover .trip-card-img img {
  transform: scale(1.05);
}

.trip-card-img--ph {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-muted);
}

.trip-card-img--ph .material-symbols-rounded {
  font-size: 44px;
  opacity: 0.4;
}

.trip-card-count {
  position: absolute;
  top: 10px;
  right: 10px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(0,0,0,0.6);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  backdrop-filter: blur(4px);
}

.trip-card-body {
  padding: 14px 16px 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
}

.trip-card-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--color-text-dark);
  margin: 0;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.trip-card-next {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--color-accent);
}

.trip-card-stats {
  display: flex;
  gap: 6px;
  margin-top: auto;
}

.tc-stat {
  font-size: 11.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
}

.tc-open { background: #dcfce7; color: #15803d; }
.tc-full { background: #fee2e2; color: #b91c1c; }

/* ── Schedules modal (per-trip) ── */
/* selector ต้องชนะ .modal-xl (max-width 960px) ที่ประกาศทีหลังในไฟล์นี้ */
.modal-card.schedules-modal {
  max-width: 1600px;
  width: 97vw;
}

.smh-left {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.smh-thumb {
  width: 52px;
  height: 52px;
  border-radius: 12px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid var(--color-sand-dark);
}

.schedules-modal-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 14px 24px;
  border-bottom: 1px solid var(--color-sand-dark);
  background: var(--color-sand);
}

.schedules-modal-body {
  padding: 0 !important;
  max-height: 70vh;
  overflow-y: auto;
}

.btn-danger-sm {
  padding: 5px 12px;
  font-size: 12px;
  border-radius: 6px;
  border: 1px solid #fecaca;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  background: var(--color-white);
  color: #dc2626;
}
.btn-danger-sm:hover {
  background: #fef2f2;
  border-color: #fca5a5;
}

/* ── Inner schedule table ── */
.schedule-table-wrap {
  overflow-x: auto;
}

.schedule-inner-table {
  margin: 0;
  border-radius: 0;
  box-shadow: none;
  border: none;
}

.schedule-inner-table thead tr {
  background: #f9fafb;
}

.schedule-inner-table thead th {
  font-size: 11px;
  padding: 8px 12px;
  color: var(--color-text-muted);
}

.schedule-inner-table tbody td {
  padding: 10px 12px;
  font-size: 13px;
}

.text-muted-sm {
  font-size: 12px;
  color: #d1d5db;
}

.region-pill {
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  color: var(--color-accent);
  background: #e8f5ec;
  border: 1px solid #b7dfc5;
  border-radius: 20px;
  padding: 1px 7px;
}

.btn-copy {
  color: #2563eb;
}
.btn-copy:hover {
  background: #eff6ff;
  border-color: #bfdbfe;
}

.btn-clone {
  color: #7c3aed;
}
.btn-clone:hover {
  background: #f5f3ff;
  border-color: #c4b5fd;
}

.btn-move {
  color: #f59e0b;
}
.btn-move:hover {
  background: #fffbeb;
  border-color: #fcd34d;
}

.move-selection-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.move-selection-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.move-passenger-list {
  display: grid;
  gap: 10px;
  max-height: 320px;
  overflow-y: auto;
  padding-right: 4px;
}

.move-booking-group {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
  background: #ffffff;
}

.move-booking-head,
.move-passenger-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
}

.move-booking-head {
  background: #f8fafc;
  border-bottom: 1px solid #e5e7eb;
  cursor: pointer;
}

.move-booking-head strong,
.move-passenger-name {
  color: #111827;
  font-size: 13px;
  font-weight: 800;
}

.move-booking-head span,
.move-passenger-meta {
  color: #6b7280;
  font-size: 11px;
  font-weight: 600;
}

.move-passenger-item {
  grid-template-columns: auto minmax(120px, 0.8fr) minmax(0, 1fr);
  border-bottom: 1px solid #f3f4f6;
  cursor: pointer;
}

.move-passenger-item:last-child {
  border-bottom: none;
}

.move-passenger-item:hover {
  background: #fafafa;
}

.move-seat-section {
  margin-top: 16px;
}

.move-seat-list {
  display: grid;
  gap: 8px;
}

.move-seat-layout {
  display: grid;
  grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
  gap: 14px;
  align-items: start;
}

.move-seat-passengers {
  display: grid;
  gap: 8px;
}

.move-seat-person-card {
  display: grid;
  gap: 3px;
  width: 100%;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #ffffff;
  color: #374151;
  cursor: pointer;
  padding: 10px 12px;
  text-align: left;
  transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
}

.move-seat-person-card:hover,
.move-seat-person-card.active {
  background: #f0faf4;
  border-color: #b7dfc5;
  box-shadow: 0 0 0 3px rgba(45, 122, 79, 0.06);
}

.move-seat-person-card.assigned:not(.active) {
  background: #f8fafc;
}

.move-seat-person-name {
  color: #111827;
  font-size: 13px;
  font-weight: 900;
}

.move-seat-person-meta {
  color: #6b7280;
  font-size: 11px;
  font-weight: 700;
}

.move-seat-person-card strong {
  color: var(--color-accent);
  font-size: 18px;
  font-weight: 900;
}

.move-seat-map-panel {
  min-width: 0;
}

.move-seat-map-legend {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 10px;
  color: #6b7280;
  font-size: 11px;
  font-weight: 800;
}

.move-seat-map-legend span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.legend-box {
  width: 14px;
  height: 14px;
  border-radius: 4px;
  border: 1px solid #d1d5db;
}

.legend-box.available {
  background: #ffffff;
}

.legend-box.selected {
  background: #2d7a4f;
  border-color: #2d7a4f;
}

.legend-box.booked {
  background: #d1d5db;
}

.move-seat-vehicle {
  overflow-x: auto;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #ffffff;
  padding: 16px;
}

.move-seat-front,
.move-seat-rear {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #6b7280;
  font-size: 11px;
  font-weight: 900;
  text-transform: uppercase;
}

.move-seat-front {
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px dashed #d1d5db;
}

.move-seat-rear {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px dashed #d1d5db;
}

.move-driver {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 3px 8px;
}

.move-driver .material-symbols-rounded {
  font-size: 15px;
}

.move-seat-grid {
  display: grid;
  gap: 8px;
  justify-content: center;
  min-width: max-content;
}

.move-seat-button {
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
  transition: transform 0.12s, border-color 0.12s, background 0.12s, color 0.12s;
}

.move-seat-button:hover:not(:disabled) {
  transform: translateY(-1px);
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.move-seat-button .material-symbols-rounded {
  font-size: 20px;
}

.move-seat-button strong {
  font-size: 11px;
  font-weight: 900;
  line-height: 1;
}

.move-seat-button small {
  max-width: 48px;
  overflow: hidden;
  color: inherit;
  font-size: 8px;
  font-weight: 800;
  line-height: 1.1;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.move-seat-button.selected {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #ffffff;
}

.move-seat-button.active {
  box-shadow: 0 0 0 3px rgba(45, 122, 79, 0.18);
}

.move-seat-button.booked,
.move-seat-button:disabled {
  background: #e5e7eb;
  border-color: #d1d5db;
  color: #9ca3af;
  cursor: not-allowed;
}

.move-seat-aisle {
  width: 34px;
  min-height: 62px;
  border-radius: 999px;
  background: linear-gradient(to bottom, transparent, #e5e7eb, transparent);
  opacity: 0.7;
}

.move-seat-empty {
  width: 58px;
  min-height: 62px;
}

.move-seat-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 180px;
  gap: 10px;
  align-items: center;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 10px 12px;
  background: #ffffff;
}

.move-seat-person {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.move-seat-person strong {
  color: #111827;
  font-size: 13px;
  font-weight: 800;
}

.move-seat-person span {
  color: #6b7280;
  font-size: 11px;
  font-weight: 600;
}

.move-seat-row select {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  color: #111827;
  font-size: 13px;
  outline: none;
}

.move-seat-row select:focus {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(45, 122, 79, 0.08);
}

.move-seat-error,
.alert-card {
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 12px;
  font-weight: 700;
  margin: 10px 0 0;
  padding: 10px 12px;
}

.move-seat-error .material-symbols-rounded,
.alert-card .material-symbols-rounded {
  font-size: 17px;
}

@media (max-width: 768px) {
  .move-seat-layout {
    grid-template-columns: 1fr;
  }

  .move-seat-passengers {
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  }
}

.copy-target-item.disabled {
  opacity: 0.5;
  cursor: not-allowed;
  background: #f3f4f6;
}

.modal-xl {
  max-width: 960px;
  width: 96vw;
}

.batch-section {
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 18px;
  margin-bottom: 18px;
  background: var(--color-white);
}

.date-rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.date-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.date-row-num {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--color-sand-dark);
  color: var(--color-text-mid);
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* Month separator row inside schedule table */
.month-sep-row td {
  background: var(--color-sand);
  padding: 7px 14px !important;
  border-top: 2px solid var(--color-sand-dark);
}
.month-sep-icon {
  font-size: 16px;
  vertical-align: middle;
  color: var(--color-accent);
  margin-right: 6px;
}
.month-sep-label {
  font-size: 13px;
  font-weight: 800;
  color: var(--color-text-dark);
  letter-spacing: .2px;
}
.month-sep-count {
  font-size: 11px;
  font-weight: 700;
  color: var(--color-text-muted);
  margin-left: 8px;
}

/* Day-trip pill & toggle */
.daytrip-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 999px;
  background: #fff7ed;
  color: #c2410c;
  border: 1px solid #fed7aa;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}
.daytrip-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #c2410c;
  cursor: pointer;
}
.daytrip-toggle input { accent-color: #ea580c; }

/* Batch: months-covered chip, generator, per-row month label */
.batch-months-chip {
  font-size: 11px;
  font-weight: 700;
  color: var(--color-accent);
  background: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  padding: 1px 8px;
  border-radius: 999px;
  margin-left: 6px;
}
.date-gen {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 12px;
  margin-bottom: 12px;
  background: var(--color-sand);
  border: 1px dashed var(--color-sand-dark);
  border-radius: 8px;
}
.date-gen-label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 800;
  color: var(--color-text-dark);
}
.date-gen-field {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--color-text-mid);
}
.date-gen-field input[type="date"] { padding: 5px 8px; }
.date-row-month {
  font-size: 11px;
  font-weight: 700;
  color: var(--color-text-muted);
  white-space: nowrap;
  min-width: 96px;
}

.pickup-inline-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pickup-inline-row {
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  padding: 10px 12px;
  background: var(--color-white);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pickup-inline-left,
.pickup-inline-right {
  display: flex;
  gap: 8px;
  align-items: center;
}

.pickup-inline-left input,
.pickup-inline-right input {
  min-width: 0;
}

.pickup-inline-empty {
  text-align: center;
  padding: 20px;
  color: var(--color-text-muted);
  font-size: 13px;
  border: 1px dashed #e5e7eb;
  border-radius: 8px;
}

.btn-sm {
  padding: 5px 12px;
  font-size: 12px;
  border-radius: 6px;
  border: 1px solid;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}

.btn-sm.btn-secondary {
  background: var(--color-white);
  border-color: var(--color-sand-dark);
  color: var(--color-text-mid);
}
.btn-sm.btn-secondary:hover {
  background: #f9fafb;
}

.copy-target-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 280px;
  overflow-y: auto;
}

.copy-target-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--color-text-mid);
  cursor: pointer;
  padding: 8px 10px;
  border-radius: 6px;
  border: 1px solid var(--color-sand-dark);
  transition: background 0.15s;
}

.copy-target-item:hover {
  background: var(--color-sand);
  border-color: #b7dfc5;
}

.copy-target-item input {
  accent-color: var(--color-accent);
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}

.checkbox-filter {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: var(--color-text-mid);
  cursor: pointer;
  padding: 0 4px;
}

.checkbox-filter input[type="checkbox"] {
  accent-color: var(--color-accent);
  width: 15px;
  height: 15px;
}

.seats-info {
  display: flex;
  align-items: center;
  gap: 10px;
}

.seats-bar {
  width: 60px;
  height: 5px;
  background: var(--color-sand-dark);
  border-radius: 3px;
  overflow: hidden;
}

.seats-fill {
  height: 100%;
  background: var(--color-accent);
  border-radius: 3px;
  transition: width 0.3s;
}

.seats-text {
  font-size: 13px;
  color: var(--color-text-muted);
  font-weight: 600;
}

.btn-pickup {
  color: #7c3aed;
}
.btn-pickup:hover {
  background: #f5f3ff;
  border-color: #c4b5fd;
}

.modal-lg {
  max-width: 860px;
}

.modal-subtitle {
  font-size: 13px;
  color: var(--color-text-muted);
  margin: 2px 0 0;
}

.section-label {
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-mid);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin: 0 0 14px;
}

.pickup-loading {
  display: flex;
  justify-content: center;
  padding: 30px;
}

/* ── Region-grouped pickup manager ── */
.pickup-region-section {
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 12px;
}

.pickup-region-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: var(--color-sand);
  border-bottom: 1px solid var(--color-sand-dark);
}

.pickup-region-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: var(--color-accent);
}

.region-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-accent);
  flex-shrink: 0;
}

.pickup-region-count {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-muted);
  background: var(--color-sand-dark);
  border-radius: 20px;
  padding: 1px 7px;
}

.pickup-region-body {
  background: var(--color-white);
}

.pickup-region-list {
  display: flex;
  flex-direction: column;
}

.pickup-region-empty {
  padding: 12px 16px;
  font-size: 12px;
  color: var(--color-text-muted);
  display: flex;
  align-items: center;
  gap: 6px;
}

/* ── Pickup item display row ── */
.pickup-item {
  border-bottom: 1px solid #f3f4f6;
}
.pickup-item:last-child {
  border-bottom: none;
}

.pickup-item-display {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  gap: 12px;
}

.pid-thumb {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid var(--color-border, #e5e7eb);
}

.pid-left {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

/* Pickup point image upload */
.pif-image-row {
  align-items: center;
  gap: 10px;
}
.pif-image-preview {
  position: relative;
  width: 56px;
  height: 56px;
  flex-shrink: 0;
}
.pif-image-preview img {
  width: 56px;
  height: 56px;
  border-radius: 8px;
  object-fit: cover;
  border: 1px solid var(--color-border, #e5e7eb);
}
.pif-image-remove {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: none;
  background: #dc2626;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
}
.pif-image-remove .material-symbols-rounded { font-size: 14px; }
.pif-image-upload {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 8px;
  border: 1px dashed var(--color-accent, #2563eb);
  color: var(--color-accent, #2563eb);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  background: rgba(37, 99, 235, 0.04);
}
.pif-image-upload .material-symbols-rounded { font-size: 18px; }
.pif-image-pick { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; flex-shrink: 0; border-radius: 8px; border: 1px dashed var(--color-accent, #2563eb); color: var(--color-accent, #2563eb); background: rgba(37, 99, 235, 0.04); cursor: pointer; padding: 0; }
.pif-image-pick .material-symbols-rounded { font-size: 18px; }

/* Existing image picker grid */
.img-picker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
.img-picker-item { position: relative; display: flex; flex-direction: column; padding: 0; border: 2px solid var(--color-sand-dark, #e5e7eb); border-radius: 12px; overflow: hidden; cursor: pointer; background: #fff; transition: border-color 0.15s, transform 0.15s; }
.img-picker-item:hover { transform: translateY(-2px); border-color: var(--color-accent, #2563eb); }
.img-picker-item.active { border-color: var(--color-accent, #2563eb); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
.img-picker-item img { width: 100%; height: 100px; object-fit: cover; display: block; }
.img-picker-label { font-size: 12px; font-weight: 600; color: var(--color-text-dark, #374151); padding: 6px 8px; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.pid-location {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-dark);
  display: flex;
  align-items: center;
  gap: 6px;
}
.pid-location i { color: #dc2626; font-size: 11px; flex-shrink: 0; }

.pid-notes {
  font-size: 12px;
  color: var(--color-text-muted);
  display: flex;
  align-items: center;
  gap: 5px;
}
.pid-notes i { color: #f59e0b; font-size: 11px; flex-shrink: 0; }

.pid-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.pid-price {
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-dark);
  white-space: nowrap;
}

.pid-time {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 12.5px;
  font-weight: 800;
  color: var(--color-accent);
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  padding: 2px 8px;
  border-radius: 999px;
  white-space: nowrap;
}
.pid-time .material-symbols-rounded { font-size: 15px; }

.pid-map {
  color: var(--color-accent);
  font-size: 13px;
  text-decoration: none;
  padding: 4px;
  border-radius: 4px;
  transition: background 0.1s;
}
.pid-map:hover { background: #e8f5ec; }

/* ── Inline add/edit form (inside region body) ── */
.pickup-item-edit {
  padding: 12px 14px;
  background: var(--color-white);
  border-top: 1px dashed #d1fae5;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pickup-item-add {
  border-top: 1px dashed #d1fae5;
}

.pif-row {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}

.pif-location {
  flex: 2;
  min-width: 160px;
}

.pif-notes {
  flex: 1.5;
  min-width: 130px;
}

.pif-price-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}

.pif-baht {
  font-size: 13px;
  color: var(--color-text-muted);
  font-weight: 600;
}

.pif-price {
  width: 90px;
}

.pif-time-wrap {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 0 10px;
  border: 1px solid var(--color-border, #d1d5db);
  border-radius: 8px;
  background: var(--color-white);
  flex-shrink: 0;
}
.pif-time-wrap .material-symbols-rounded {
  font-size: 17px;
  color: var(--color-accent);
}
.pif-time {
  border: none !important;
  padding: 7px 0 !important;
  width: 96px;
  background: transparent;
}

.pif-map {
  flex: 2;
  min-width: 160px;
}

.pif-order {
  width: 70px;
  flex-shrink: 0;
}

.pif-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

/* ── Template dropdown inside pickup manager header ── */
.template-dropdown {
  position: absolute;
  top: 100%;
  right: 0;
  z-index: 200;
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.1);
  min-width: 220px;
  margin-top: 4px;
  overflow: hidden;
}

.template-dropdown-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  font-size: 13px;
  color: var(--color-text-mid);
  cursor: pointer;
  transition: background 0.15s;
}
.template-dropdown-item:hover {
  background: var(--color-sand);
}

/* ── Template manager modal ── */
.tpl-card {
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 12px;
}

.tpl-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  background: var(--color-sand);
  border-bottom: 1px solid var(--color-sand-dark);
  gap: 12px;
}

.tpl-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: var(--color-text-dark);
  min-width: 0;
  flex: 1;
}

.tpl-name-input {
  flex: 1;
  min-width: 0;
  font-weight: 700;
  font-size: 14px;
}

.tpl-count {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-muted);
  background: var(--color-sand-dark);
  border-radius: 20px;
  padding: 1px 7px;
}

.tpl-card-body {
  background: var(--color-white);
  padding: 12px 14px;
}

.tpl-region-section {
  margin-bottom: 10px;
}

.tpl-region-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-accent);
  text-transform: uppercase;
  margin-bottom: 6px;
}

.tpl-point-row {
  display: flex;
  gap: 6px;
  align-items: center;
  margin-bottom: 6px;
  flex-wrap: wrap;
}

.tpl-region-empty {
  font-size: 12px;
  color: #9ca3af;
  padding: 4px 0 4px 16px;
}

.tpl-points-preview {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.tpl-preview-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--color-text-mid);
  padding: 6px 8px;
  border-radius: 6px;
  background: #f9fafb;
}

/* ── Apply Template Modal sections ── */
.apply-section {
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  padding: 14px;
  margin-bottom: 14px;
}

.apply-section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  margin-bottom: 10px;
}

.apply-tpl-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.apply-tpl-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-text-mid);
  padding: 8px 10px;
  border-radius: 7px;
  border: 1px solid var(--color-sand-dark);
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.apply-tpl-item:hover {
  background: var(--color-sand);
}
.apply-tpl-item.selected {
  background: #e8f5ec;
  border-color: #b7dfc5;
}

.apply-mode-wrap {
  display: flex;
  gap: 10px;
}

.apply-mode-option {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid var(--color-sand-dark);
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
  font-size: 13px;
}
.apply-mode-option input { display: none; }
.apply-mode-option:hover { background: var(--color-sand); }
.apply-mode-option.active {
  background: #e8f5ec;
  border-color: var(--color-accent);
}
.apply-mode-option .material-symbols-rounded {
  font-size: 20px;
  color: var(--color-accent);
}

/* ── Page header action groups ── */
.page-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.page-actions-secondary {
  display: flex;
  gap: 8px;
  align-items: center;
  padding-right: 12px;
  border-right: 1px solid #e5e7eb;
}

/* ── Named status badges ── */
.badge-charter {
  background: #f5f3ff;
  color: #7c3aed;
  border: 1px solid #ddd6fe;
  font-size: 9px;
}

.badge-join-trip {
  background: #ecfdf5;
  color: #059669;
  border: 1px solid #a7f3d0;
  font-size: 9px;
}

.badge-flash-sale {
  background: #fff7ed;
  color: #ea580c;
  border: 1px solid #fed7aa;
  font-size: 9px;
}

.badge-flash-inactive {
  background: #f3f4f6;
  color: #9ca3af;
  border-color: #e5e7eb;
}

.icon-xs {
  font-size: 11px !important;
}

/* ── Action column dividers ── */
.action-divider {
  width: 1px;
  height: 18px;
  background: #e5e7eb;
  display: inline-block;
  flex-shrink: 0;
  border-radius: 1px;
}

/* ── Form toggle sections ── */
.form-toggle-section {
  border-top: 1px solid #e5e7eb;
  padding-top: 18px;
  margin-top: 18px;
}

.form-toggle-header {
  margin-bottom: 12px;
}

.form-toggle-label {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  user-select: none;
}

.form-toggle-label span {
  font-weight: 600;
  font-size: 14px;
  color: #1a1c1c;
}

.form-toggle-label input[type="checkbox"] {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
}

.form-toggle-label--sm span {
  font-size: 13px;
}

.check-installment { accent-color: #006565; }
.check-deposit     { accent-color: #0d9488; }
.check-join-trip   { accent-color: #0f766e; }
.check-charter     { accent-color: #7c3aed; }
.check-flash-sale  { accent-color: #ea580c; }

.form-toggle-hint {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  font-size: 11px;
  color: #6b7280;
  margin: 0 0 12px;
  line-height: 1.5;
}

.hint-icon { font-size: 14px !important; flex-shrink: 0; margin-top: 1px; }
.hint-deposit  { color: #0d9488; }
.hint-join-trip { color: #0f766e; }
.hint-flash-sale { color: #ea580c; }
.hint-charter  { color: #7c3aed; }

.form-group-hint-cell {
  display: flex;
  align-items: flex-end;
  padding-bottom: 12px;
}

.form-group-hint-cell .form-toggle-hint {
  margin: 0;
}

/* ── Batch feature toggle cards ── */
.batch-features-row {
  display: flex;
  flex-wrap: wrap;
  margin-top: 14px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 10px;
  overflow: hidden;
}

.batch-feature-item {
  flex: 1;
  min-width: 170px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 14px;
  border-right: 1px solid var(--color-sand-dark);
  background: #fafafa;
}

.batch-feature-item:last-child {
  border-right: none;
}

.batch-feature-input {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.input-label-inline {
  font-size: 12px;
  color: #6b7280;
  white-space: nowrap;
}

.input-sm-100 { width: 100px; }
.input-sm-80  { width: 80px; }
.input-sm-60  { width: 60px; }

.select-sm {
  font-size: 12px;
  padding: 5px 8px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  color: #374151;
  background: #fff;
  outline: none;
}

.select-sm:focus {
  border-color: var(--color-accent);
}

.badge-normal {
  background: #f3f4f6;
  color: #374151;
  font-size: 10px;
}
</style>
