<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded">calendar_month</span> รอบเดินทาง</h1>
        <p class="page-subtitle">จัดการรอบเดินทางและตารางวันเดินทาง</p>
      </div>
      <div style="display:flex;gap:10px;">
        <button class="btn-secondary" @click="openBulkJoinTripModal()">
          <span class="material-symbols-rounded">group_add</span> จัดการจอยทริป
        </button>
        <button class="btn-secondary" @click="openTemplateManager()">
          <span class="material-symbols-rounded">bookmark</span> เทมเพลตจุดรับ
        </button>
        <button class="btn-secondary" @click="openBatchForm()">
          <span class="material-symbols-rounded">layers</span> สร้างหลายรอบพร้อมกัน
        </button>
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

    <!-- Trip-grouped Accordion -->
    <div class="accordion-loading" v-if="admin.loading"><div class="spinner"></div></div>
    <template v-else>
      <div v-if="!groupedByTrip.length" class="empty-card">ไม่พบรอบเดินทาง</div>

      <div v-for="group in groupedByTrip" :key="group.trip_id" class="trip-group">
        <!-- Trip header -->
        <div class="trip-group-header" @click="toggleGroup(group.trip_id)">
          <div class="tgh-left">
            <span class="material-symbols-rounded tgh-chevron" :class="{ expanded: openGroups.has(group.trip_id) }">chevron_right</span>
            <div class="tgh-info">
              <span class="tgh-title">{{ group.trip_title }}</span>
              <span class="tgh-meta">
                {{ group.schedules.length }} รอบ
                <span v-if="group.nextDate" class="tgh-next"> · รอบถัดไป {{ group.nextDate }}</span>
              </span>
            </div>
          </div>
          <div class="tgh-actions" @click.stop>
            <button class="btn-sm btn-secondary" @click="openBatchFormForTrip(group.trip_id)" title="สร้างหลายรอบ">
              <span class="material-symbols-rounded">layers</span> เพิ่มหลายรอบ
            </button>
            <button class="btn-sm btn-secondary" @click="openForm({ trip_id: group.trip_id })" title="เพิ่มรอบเดียว">
              <span class="material-symbols-rounded">add</span> เพิ่มรอบ
            </button>
            <button class="btn-sm btn-danger-sm" @click="deleteTripGroup(group)" title="ลบทุกรอบในทริปนี้">
              <span class="material-symbols-rounded">delete</span> ลบทั้งหมด
            </button>
          </div>
        </div>

        <!-- Schedule rows -->
        <div v-if="openGroups.has(group.trip_id)" class="trip-group-body">
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
              <tbody>
                <tr v-for="sch in group.schedules" :key="sch.id">
                  <td class="date">{{ sch.departure_date }}</td>
                  <td class="date">{{ sch.return_date }}</td>
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
                      <span v-if="sch.join_trip_enabled" class="status-badge" style="background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;font-size:9px;">
                        <span class="material-symbols-rounded" style="font-size:11px;">group_add</span> จอยทริป ฿{{ Number(sch.join_trip_price || 0).toLocaleString() }}
                      </span>
                    </div>
                  </td>
                  <td>
                    <div class="action-btns">
                      <button class="btn-icon btn-pickup" @click="openPickupManager(sch)" title="จัดการจุดรับ">
                        <span class="material-symbols-rounded">location_on</span>
                      </button>
                      <button class="btn-sm btn-secondary btn-manifest-text" @click="openManifest(sch)" title="รายชื่อผู้โดยสาร">
                        <span class="material-symbols-rounded" style="font-size:16px;">group</span> รายชื่อ
                      </button>
                      <button class="btn-icon" 
                        :class="sch.join_trip_enabled ? 'btn-active' : 'btn-inactive'"
                        @click="toggleJoinTrip(sch)" 
                        :title="sch.join_trip_enabled ? 'ปิดจอยทริป' : 'เปิดจอยทริป'">
                        <span class="material-symbols-rounded">group_add</span>
                      </button>
                      <button class="btn-icon btn-clone" @click="openCopyScheduleModal(sch)" title="คัดลอกรอบเดินทาง">
                        <span class="material-symbols-rounded">file_copy</span>
                      </button>
                      <button v-if="sch.booked_seats > 0" class="btn-icon btn-move" @click="openMoveBookingsModal(sch)" title="ย้ายการจองไปยังรอบอื่น">
                        <span class="material-symbols-rounded">swap_horiz</span>
                      </button>
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
    </template>

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
            <div class="form-group">
              <label>วันเดินทาง *</label>
              <input v-model="form.departure_date" type="date" required />
            </div>
            <div class="form-group">
              <label>วันกลับ *</label>
              <input v-model="form.return_date" type="date" required />
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
          <div style="border-top:1px solid #e5e7eb;padding-top:18px;margin-top:4px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                <input type="checkbox" v-model="form.installment_enabled" style="width:16px;height:16px;accent-color:#006565;" />
                <span style="font-weight:600;font-size:14px;color:#1a1c1c;">เปิดใช้ระบบผ่อนชำระ</span>
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
          
          <!-- Join Trip Settings -->
          <div style="border-top:1px solid #e5e7eb;padding-top:18px;margin-top:18px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                <input type="checkbox" v-model="form.join_trip_enabled" style="width:16px;height:16px;accent-color:#0f766e;" />
                <span style="font-weight:600;font-size:14px;color:#1a1c1c;">เปิดใช้ระบบ "จอยทริป" (Join Trip)</span>
              </label>
            </div>
            <div v-if="form.join_trip_enabled" class="form-grid">
              <div class="form-group">
                <label>ราคาจอยทริป (฿) *</label>
                <input v-model.number="form.join_trip_price" type="number" min="0" placeholder="ระบุราคาต่อท่าน" required />
              </div>
              <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:12px;">
                <p style="font-size:11px;color:#6b7280;line-height:1.4;">
                  <span class="material-symbols-rounded" style="font-size:14px;vertical-align:middle;margin-right:2px;color:#0f766e;">info</span>
                  ระบบจอยทริปจะข้ามการเลือกที่นั่งและไม่มีระบบผ่อนชำระ
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
                          <input v-model="pickupForm.notes" placeholder="เวลานัด / หมายเหตุ" class="pif-notes" />
                          <div class="pif-price-wrap">
                            <span class="pif-baht">฿</span>
                            <input v-model.number="pickupForm.price" type="number" min="0" placeholder="ราคา" class="pif-price" />
                          </div>
                        </div>
                        <div class="pif-row">
                          <input v-model="pickupForm.map_url" placeholder="Google Maps URL (ไม่บังคับ)" class="pif-map" />
                          <input v-model.number="pickupForm.sort_order" type="number" min="0" placeholder="ลำดับ" class="pif-order" />
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
                        <div class="pid-left">
                          <span class="pid-location"><span class="material-symbols-rounded" style="font-size:16px;">push_pin</span> {{ pt.pickup_location }}</span>
                          <span class="pid-notes" v-if="pt.notes"><span class="material-symbols-rounded" style="font-size:16px;">schedule</span> {{ pt.notes }}</span>
                        </div>
                        <div class="pid-right">
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
                    <input v-model="pickupForm.map_url" placeholder="Google Maps URL (ไม่บังคับ)" class="pif-map" />
                    <input v-model.number="pickupForm.sort_order" type="number" min="0" placeholder="ลำดับ" class="pif-order" />
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
                      <span v-if="p.is_join_trip" class="status-badge" style="background:#ecfdf5;color:#059669;font-size:10px;">Enjoy Trip</span>
                      <span v-else class="status-badge" style="background:#f3f4f6;color:#374151;font-size:10px;">ปกติ</span>
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
            </div>

            <!-- Join Trip + Installment in batch -->
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:8px;">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                <input type="checkbox" v-model="batchForm.join_trip_enabled" style="width:16px;height:16px;accent-color:#0f766e;" />
                <span style="font-weight:600;font-size:13px;color:#1a1c1c;">เปิดจอยทริป</span>
              </label>
              <div v-if="batchForm.join_trip_enabled" style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;color:#6b7280;">ราคาจอยทริป ฿</span>
                <input v-model.number="batchForm.join_trip_price" type="number" min="0" placeholder="ราคา" style="width:100px;" />
              </div>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                <input type="checkbox" v-model="batchForm.installment_enabled" style="width:16px;height:16px;accent-color:#006565;" />
                <span style="font-weight:600;font-size:13px;color:#1a1c1c;">เปิดผ่อนชำระ</span>
              </label>
              <div v-if="batchForm.installment_enabled" style="display:flex;align-items:center;gap:8px;">
                <input v-model.number="batchForm.installment_count" type="number" min="2" max="6" placeholder="งวด" style="width:60px;" />
                <span style="font-size:12px;color:#6b7280;">งวด ·</span>
                <input v-model.number="batchForm.installment_interval_days" type="number" min="1" placeholder="วัน" style="width:60px;" />
                <span style="font-size:12px;color:#6b7280;">วัน</span>
              </div>
            </div>
          </div>

          <!-- Section 2: Dates -->
          <div class="batch-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
              <h3 class="section-label" style="margin:0;"><span class="material-symbols-rounded">calendar_month</span> วันเดินทาง ({{ batchForm.dates.length }} รอบ)</h3>
              <button type="button" class="btn-sm btn-secondary" @click="addDateRow">
                <span class="material-symbols-rounded">add</span> เพิ่มวัน
              </button>
            </div>
            <div class="date-rows">
              <div v-for="(d, i) in batchForm.dates" :key="i" class="date-row">
                <span class="date-row-num">{{ i + 1 }}</span>
                <div class="form-group" style="flex:1;margin:0;">
                  <input v-model="d.departure_date" type="date" required placeholder="วันเดินทาง" />
                </div>
                <span style="color:#9ca3af;font-size:13px;">→</span>
                <div class="form-group" style="flex:1;margin:0;">
                  <input v-model="d.return_date" type="date" required placeholder="วันกลับ" />
                </div>
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
      <div class="modal-card">
        <div class="modal-header">
          <div>
            <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">swap_horiz</span>ย้ายรายการจอง</h2>
            <p class="modal-subtitle" v-if="moveSource">ต้นทาง: {{ moveSource.trip?.title }} — {{ moveSource.departure_date }} ({{ moveSource.booked_seats }} ที่นั่ง)</p>
          </div>
          <button class="modal-close" @click="showMoveBookingsModal = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="apply-section">
            <div class="apply-section-title"><span class="material-symbols-rounded">calendar_month</span> เลือกรอบเดินทางปลายทาง</div>
            <div class="copy-target-list">
              <div v-for="group in groupedByTrip" :key="group.trip_id" style="margin-bottom:8px;">
                <div style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;padding:4px 0;">{{ group.trip_title }}</div>
                <label v-for="sch in group.schedules" :key="sch.id" class="copy-target-item" :class="{ disabled: sch.id === moveSource.id || (!sch.join_trip_enabled && sch.available_seats < moveSource.booked_seats) }">
                  <input type="radio" v-model="moveTargetId" :value="sch.id" :disabled="sch.id === moveSource.id || (!sch.join_trip_enabled && sch.available_seats < moveSource.booked_seats)" />
                  <div style="flex:1;">
                    <div style="display:flex;justify-content:space-between;">
                       <span>{{ sch.departure_date }}<span v-if="sch.return_date"> → {{ sch.return_date }}</span></span>
                       <span class="status-badge" :class="`status-${sch.status}`">{{ statusLabels[sch.status] }}</span>
                    </div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                      ว่าง {{ sch.available_seats }} ที่นั่ง · {{ sch.vehicle?.name || sch.transport_type }}
                    </div>
                  </div>
                </label>
              </div>
            </div>
          </div>
          <div v-if="moveTargetId" style="margin-top:16px;padding:12px;background:#fffbeb;border:1px solid #fef3c7;border-radius:8px;font-size:13px;color:#92400e;">
             <span class="material-symbols-rounded" style="font-size:16px;vertical-align:middle;margin-right:4px;">warning</span>
             การย้ายจะเปลี่ยนรอบเดินทางของทุกใบจองในรอบนี้ และพยายามจับคู่จุดรับให้อัตโนมัติ
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showMoveBookingsModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="doMoveBookings" :disabled="moveSubmitting || !moveTargetId">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': moveSubmitting }" v-if="moveSubmitting">sync</span>
            ยืนยันการย้าย
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
                    <button type="button" class="btn-icon btn-delete" @click="removeTplPoint(r.value, pi)"><span class="material-symbols-rounded">close</span></button>
                  </div>
                  <div v-if="!editingTemplate.points.filter(p => p.region === r.value).length" class="tpl-region-empty">ไม่มีจุดรับในภาคนี้</div>
                </div>
              </div>
              <div v-else class="tpl-points-preview">
                <div v-for="(pt, pi) in tpl.points" :key="pi" class="tpl-preview-item">
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
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2><span class="material-symbols-rounded" style="color:var(--color-accent);margin-right:8px;">content_copy</span>คัดลอกจุดรับ</h2>
          <button class="modal-close" @click="showCopyModal = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;color:#374151;margin-bottom:12px;">
            คัดลอกจุดรับจาก <strong>{{ copySource?.departure_date }} <span v-if="copySource?.vehicle?.license_plate">({{ copySource.vehicle.license_plate }})</span></strong> ไปยังรอบ:
          </p>
          <div class="copy-target-list">
            <label v-for="sch in copyTargets" :key="sch.id" class="copy-target-item">
              <input type="checkbox" v-model="copySelectedIds" :value="sch.id" />
              <span>
                {{ sch.departure_date }} — {{ sch.trip?.title }}
                <span v-if="sch.vehicle?.license_plate" style="margin-left:8px; color:var(--color-accent); font-weight:700;">
                  ({{ sch.vehicle.license_plate }})
                </span>
              </span>
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showCopyModal = false">ยกเลิก</button>
          <button class="btn-primary" @click="doCopyPickups" :disabled="!copySelectedIds.length || copySubmitting">
            <span class="material-symbols-rounded" :class="{ 'animate-spin': copySubmitting }" v-if="copySubmitting">sync</span>
            คัดลอกไป {{ copySelectedIds.length }} รอบ
          </button>
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
import { ref, reactive, computed, onMounted } from 'vue';
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
const openGroups = ref(new Set());

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
        schedules: [],
        nextDate: null,
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
    groups.push(g);
  }
  groups.sort((a, b) => (a.nextDate || '9999') > (b.nextDate || '9999') ? 1 : -1);
  return groups;
});

const toggleGroup = (tripId) => {
  const s = new Set(openGroups.value);
  if (s.has(tripId)) s.delete(tripId);
  else s.add(tripId);
  openGroups.value = s;
};
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
const tripOptions = ref([]);
const vehicleOptions = ref([]);

const form = reactive({
  trip_id: '', departure_date: '', return_date: '',
  total_seats: 10, transport_type: 'van', vehicle_id: null,
  price_override: null, status: 'open',
  installment_enabled: false, installment_count: 2, installment_interval_days: 30,
  join_trip_enabled: false, join_trip_price: null,
});

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
  admin.fetchSchedules(params).then(() => {
    // Auto-expand all groups on first load
    if (openGroups.value.size === 0) {
      openGroups.value = new Set((admin.schedules.data || []).map(s => s.trip_id));
    }
  });
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
      total_seats: item.total_seats,
      transport_type: item.transport_type,
      vehicle_id: item.vehicle?.id || null,
      price_override: item.price || null,
      status: item.status,
      installment_enabled: !!item.installment_enabled,
      installment_count: item.installment_count || 2,
      installment_interval_days: item.installment_interval_days || 30,
      join_trip_enabled: !!item.join_trip_enabled,
      join_trip_price: item.join_trip_price || null,
    });
  } else {
    Object.assign(form, {
      trip_id: item?.trip_id || '',
      departure_date: '', return_date: '',
      total_seats: 10, transport_type: 'van', vehicle_id: null,
      price_override: null, status: 'open',
      installment_enabled: false, installment_count: 2, installment_interval_days: 30,
      join_trip_enabled: false, join_trip_price: null,
    });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  try {
    const data = { ...form };
    if (!data.price_override) data.price_override = null;
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
  dates: [{ departure_date: '', return_date: '' }],
  pickups: [],
  join_trip_enabled: false,
  join_trip_price: null,
  installment_enabled: false,
  installment_count: 2,
  installment_interval_days: 30,
});

// ─── Move Bookings ────────────────────────────────────────
const showMoveBookingsModal = ref(false);
const moveSubmitting = ref(false);
const moveSource = ref(null);
const moveTargetId = ref(null);

const openMoveBookingsModal = (sch) => {
  moveSource.value = sch;
  moveTargetId.value = null;
  showMoveBookingsModal.value = true;
};

const doMoveBookings = async () => {
  if (!moveTargetId.value) return;
  if (!confirm(`ยืนยันการย้ายการจองทั้งหมดจากวันที่ ${moveSource.value.departure_date} ใช่หรือไม่?\n\nการดำเนินการนี้จะเปลี่ยนข้อมูลถาวร`)) return;
  
  moveSubmitting.value = true;
  try {
    await api.post('/admin/schedules/move-bookings', {
      source_schedule_id: moveSource.value.id,
      target_schedule_id: moveTargetId.value,
    });
    toast.success('ย้ายการจองสำเร็จ');
    showMoveBookingsModal.value = false;
    fetchData();
  } catch (e) {
    toast.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการย้ายการจอง');
  } finally {
    moveSubmitting.value = false;
  }
};

const openBatchForm = (presetTripId = '') => {
  Object.assign(batchForm, {
    trip_id: presetTripId || '',
    transport_type: 'van',
    vehicle_id: null,
    total_seats: 10,
    price_override: null,
    dates: [{ departure_date: '', return_date: '' }],
    pickups: [],
    join_trip_enabled: false,
    join_trip_price: null,
    installment_enabled: false,
    installment_count: 2,
    installment_interval_days: 30,
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

const addPickupRow = () => batchForm.pickups.push({
  region: '', region_label: '', pickup_location: '', price: '', notes: '', map_url: '',
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
      const scheduleRes = await api.post('/admin/schedules', {
        trip_id: batchForm.trip_id,
        transport_type: batchForm.transport_type,
        vehicle_id: batchForm.vehicle_id,
        total_seats: batchForm.total_seats,
        price_override: batchForm.price_override || null,
        departure_date: d.departure_date,
        return_date: d.return_date,
        status: 'open',
        join_trip_enabled: batchForm.join_trip_enabled || false,
        join_trip_price: batchForm.join_trip_enabled ? batchForm.join_trip_price : null,
        installment_enabled: batchForm.installment_enabled || false,
        installment_count: batchForm.installment_count || 2,
        installment_interval_days: batchForm.installment_interval_days || 30,
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
          map_url: pt.map_url || null,
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
    const newScheduleRes = await api.post('/admin/schedules', {
      trip_id: copyScheduleForm.trip_id,
      departure_date: copyScheduleForm.departure_date,
      return_date: copyScheduleForm.return_date,
      total_seats: src.total_seats,
      transport_type: src.transport_type,
      vehicle_id: src.vehicle?.id || null,
      price_override: src.price || null,
      status: 'open',
      installment_enabled: src.installment_enabled || false,
      installment_count: src.installment_count || 2,
      installment_interval_days: src.installment_interval_days || 30,
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
          map_url: pt.map_url || null,
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

const copyTargets = computed(() =>
  (admin.schedules.data || []).filter(s => s.id !== copySource.value?.id)
);

const copyPickupPoints = async (sch) => {
  copySource.value = sch;
  copySelectedIds.value = [];
  showCopyModal.value = true;
};

const doCopyPickups = async () => {
  copySubmitting.value = true;
  try {
    const res = await api.get(`/admin/schedules/${copySource.value.id}/pickup-points`);
    const points = res.data.data;
    let skippedCount = 0;
    for (const targetId of copySelectedIds.value) {
      // Fetch existing pickup points to prevent duplicates
      const existingRes = await api.get(`/admin/schedules/${targetId}/pickup-points`);
      const existingPoints = existingRes.data.data || [];
      const existingKeys = new Set(existingPoints.map(p => `${p.region}::${p.pickup_location}`));
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
          map_url: pt.map_url || null,
          latitude: pt.latitude || null,
          longitude: pt.longitude || null,
          sort_order: pt.sort_order || 0,
        });
      }
    }
    showCopyModal.value = false;
    fetchData();
    const msg = `คัดลอกจุดรับไป ${copySelectedIds.value.length} รอบสำเร็จ`;
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
    map_url: pt.map_url || '',
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
        map_url: pt.map_url || null,
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
          map_url: pt.map_url || null,
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
  price: '', map_url: '', latitude: null, longitude: null,
  notes: '', sort_order: 0,
});

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
    price: '', map_url: '', latitude: null, longitude: null,
    notes: '', sort_order: 0,
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
    pickup_location: '', price: '', map_url: '',
    latitude: null, longitude: null, notes: '', sort_order: 0,
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
    if (!payload.latitude) payload.latitude = null;
    if (!payload.longitude) payload.longitude = null;

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
    latitude: pt.latitude || null,
    longitude: pt.longitude || null,
    notes: pt.notes || '',
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
.trip-group {
  background: var(--color-white);
  border: 1px solid var(--color-sand-dark);
  border-radius: 16px;
  margin-bottom: 10px;
  overflow: hidden;
  box-shadow: 0 4px 6px rgba(0,0,0,0.02);
}

.trip-group-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  cursor: pointer;
  user-select: none;
  background: var(--color-white);
  transition: background 0.15s;
  gap: 12px;
}

.trip-group-header:hover {
  background: var(--color-sand);
}

.tgh-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.tgh-chevron {
  color: var(--color-text-muted);
  font-size: 12px;
  transition: transform 0.2s;
  flex-shrink: 0;
}

.tgh-chevron.expanded {
  transform: rotate(90deg);
  color: var(--color-accent);
}

.tgh-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.tgh-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--color-text-dark);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tgh-meta {
  font-size: 12px;
  color: var(--color-text-muted);
}

.tgh-next {
  color: var(--color-accent);
  font-weight: 600;
}

.tgh-actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
  align-items: center;
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
.trip-group-body {
  border-top: 1px solid var(--color-sand-dark);
}

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

.pid-left {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

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
</style>
