<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <span class="material-symbols-rounded heading-icon">airport_shuttle</span> ยานพาหนะ
        </h1>
        <p class="page-subtitle">จัดการรถตู้และเรือ พร้อมรอบทริปที่ได้รับมอบหมาย</p>
      </div>
      <button class="btn-primary" @click="openForm()">
        <span class="material-symbols-rounded">add</span> เพิ่มยานพาหนะ
      </button>
    </div>

    <!-- Stats -->
    <div class="vehicle-stats">
      <div class="stat-card">
        <div class="stat-icon stat-all"><span class="material-symbols-rounded">directions_car</span></div>
        <div class="stat-info">
          <span class="stat-value">{{ allVehicles.length }}</span>
          <span class="stat-label">ยานพาหนะทั้งหมด</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon stat-van"><span class="material-symbols-rounded">airport_shuttle</span></div>
        <div class="stat-info">
          <span class="stat-value">{{ allVehicles.filter(v => v.type === 'van').length }}</span>
          <span class="stat-label">รถตู้</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon stat-boat"><span class="material-symbols-rounded">directions_boat</span></div>
        <div class="stat-info">
          <span class="stat-value">{{ allVehicles.filter(v => v.type === 'boat').length }}</span>
          <span class="stat-label">เรือ</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon stat-schedule"><span class="material-symbols-rounded">event</span></div>
        <div class="stat-info">
          <span class="stat-value">{{ upcomingSchedules.length }}</span>
          <span class="stat-label">รอบที่กำลังจะมาถึง</span>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="search-box">
        <span class="material-symbols-rounded">search</span>
        <input v-model="searchQuery" placeholder="ค้นหาชื่อ, ทะเบียน, คนขับ..." />
      </div>
      <div class="type-tabs">
        <button
          v-for="tab in typeTabs"
          :key="tab.value"
          class="type-tab"
          :class="{ active: filters.type === tab.value }"
          @click="filters.type = tab.value"
        >
          <span class="material-symbols-rounded">{{ tab.icon }}</span>
          {{ tab.label }}
          <span class="tab-count">{{ tab.count }}</span>
        </button>
      </div>
    </div>

    <!-- Content -->
    <div class="loading-state" v-if="admin.loading"><div class="spinner"></div></div>
    <template v-else>
      <!-- Grouped by type when showing all -->
      <template v-if="!filters.type">
        <template v-for="group in vehicleGroups" :key="group.type">
          <div class="type-section" v-if="group.vehicles.length">
            <div class="type-section-header">
              <span class="material-symbols-rounded">{{ group.type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
              <span>{{ group.label }}</span>
              <span class="section-count">{{ group.vehicles.length }} คัน</span>
            </div>
            <div class="vehicles-grid">
              <div class="vehicle-card" v-for="v in group.vehicles" :key="v.id">
                <div class="vc-top" :class="'vtype-bg-' + v.type">
                  <div class="vc-type-icon">
                    <span class="material-symbols-rounded">{{ v.type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
                  </div>
                  <div class="vc-header-info">
                    <h3>{{ v.name }}</h3>
                    <div class="vc-badges">
                      <span class="type-tag" :class="'type-' + (v.type === 'van' ? 'trekking' : 'diving')">
                        {{ v.type === 'van' ? 'รถตู้' : 'เรือ' }}
                      </span>
                      <span class="capacity-chip">
                        <span class="material-symbols-rounded">groups</span> {{ v.capacity }} ที่นั่ง
                      </span>
                      <span class="plate-chip" v-if="v.license_plate">{{ v.license_plate }}</span>
                    </div>
                  </div>
                  <div class="vc-actions">
                    <button class="btn-icon btn-edit" @click="openForm(v)" title="แก้ไข"><span class="material-symbols-rounded">edit</span></button>
                    <button class="btn-icon btn-layout" @click="openLayoutEditor(v)" title="ผังที่นั่ง"><span class="material-symbols-rounded">grid_view</span></button>
                    <button class="btn-icon btn-pickup" @click="openPickupManager(v)" title="จุดรับผู้โดยสาร"><span class="material-symbols-rounded">location_on</span></button>
                    <button class="btn-icon btn-delete" @click="confirmDelete(v)" title="ลบ"><span class="material-symbols-rounded">delete</span></button>
                  </div>
                </div>
                <div class="vc-body">
                  <div class="driver-row" v-if="v.driver_name">
                    <div class="driver-avatar" v-if="v.driver_photo"><img :src="v.driver_photo" /></div>
                    <div class="driver-placeholder" v-else><span class="material-symbols-rounded">person</span></div>
                    <div class="driver-info">
                      <span class="driver-name">{{ v.driver_name }}</span>
                      <span class="driver-phone" v-if="v.driver_phone">
                        <span class="material-symbols-rounded">phone</span> {{ v.driver_phone }}
                      </span>
                      <span class="gps-pin-badge" :class="v.has_driver_pin ? 'on' : 'off'">
                        <span class="material-symbols-rounded">my_location</span>
                        {{ v.has_driver_pin ? 'รหัส GPS พร้อม' : 'ยังไม่ตั้งรหัส GPS' }}
                      </span>
                    </div>
                    <div class="color-dot" v-if="v.color"
                      :style="{ background: colorHex(v.color), border: v.color === 'ขาว' ? '1px solid #d1d5db' : 'none' }"
                      :title="v.color"
                    ></div>
                  </div>
                  <div class="schedule-section" v-if="vehicleSchedules(v.id).length">
                    <div class="schedule-section-title">
                      <span class="material-symbols-rounded">event</span>
                      รอบที่กำลังจะมาถึง
                      <span class="schedule-count-badge">{{ vehicleSchedules(v.id).length }}</span>
                    </div>
                    <div class="schedule-list">
                      <div class="schedule-item" v-for="s in vehicleSchedules(v.id).slice(0, 3)" :key="s.id">
                        <div class="s-date-box">
                          <span class="s-month">{{ formatMonth(s.departure_date) }}</span>
                          <span class="s-day">{{ formatDay(s.departure_date) }}</span>
                        </div>
                        <div class="s-detail">
                          <span class="s-trip-name">{{ s.trip?.title || '—' }}</span>
                          <div class="s-meta">
                            <span class="s-seats" :class="seatClass(s)">
                              <span class="material-symbols-rounded">chair</span>
                              {{ s.booked_seats }}/{{ s.total_seats }}
                            </span>
                            <span class="s-status-badge" :class="'s-status-' + s.status">
                              {{ statusLabels[s.status] || s.status }}
                            </span>
                          </div>
                        </div>
                      </div>
                      <div class="schedule-more" v-if="vehicleSchedules(v.id).length > 3">
                        <span class="material-symbols-rounded">more_horiz</span>
                        อีก {{ vehicleSchedules(v.id).length - 3 }} รอบ
                      </div>
                    </div>
                  </div>
                  <div class="no-schedule" v-else>
                    <span class="material-symbols-rounded">event_available</span>
                    ยังไม่มีรอบที่กำลังจะมาถึง
                  </div>
                  <div class="vc-footer">
                    <div class="footer-chip" v-if="v.pickup_points?.length" @click="openPickupManager(v)">
                      <span class="material-symbols-rounded">location_on</span>
                      {{ v.pickup_points.length }} จุดรับ
                    </div>
                    <div class="footer-chip" v-if="v.seat_layout" @click="openLayoutEditor(v)">
                      <span class="material-symbols-rounded">grid_view</span>
                      {{ v.seat_layout.rows }} แถว · {{ v.seat_layout.seats?.length || 0 }} ที่
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </template>

      <!-- Flat grid when type is filtered -->
      <div class="vehicles-grid" v-else>
        <div class="vehicle-card" v-for="v in filteredVehicles" :key="v.id">
          <div class="vc-top" :class="'vtype-bg-' + v.type">
            <div class="vc-type-icon">
              <span class="material-symbols-rounded">{{ v.type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
            </div>
            <div class="vc-header-info">
              <h3>{{ v.name }}</h3>
              <div class="vc-badges">
                <span class="type-tag" :class="'type-' + (v.type === 'van' ? 'trekking' : 'diving')">
                  {{ v.type === 'van' ? 'รถตู้' : 'เรือ' }}
                </span>
                <span class="capacity-chip">
                  <span class="material-symbols-rounded">groups</span> {{ v.capacity }} ที่นั่ง
                </span>
                <span class="plate-chip" v-if="v.license_plate">{{ v.license_plate }}</span>
              </div>
            </div>
            <div class="vc-actions">
              <button class="btn-icon btn-edit" @click="openForm(v)" title="แก้ไข"><span class="material-symbols-rounded">edit</span></button>
              <button class="btn-icon btn-layout" @click="openLayoutEditor(v)" title="ผังที่นั่ง"><span class="material-symbols-rounded">grid_view</span></button>
              <button class="btn-icon btn-pickup" @click="openPickupManager(v)" title="จุดรับผู้โดยสาร"><span class="material-symbols-rounded">location_on</span></button>
              <button class="btn-icon btn-delete" @click="confirmDelete(v)" title="ลบ"><span class="material-symbols-rounded">delete</span></button>
            </div>
          </div>
          <div class="vc-body">
            <div class="driver-row" v-if="v.driver_name">
              <div class="driver-avatar" v-if="v.driver_photo"><img :src="v.driver_photo" /></div>
              <div class="driver-placeholder" v-else><span class="material-symbols-rounded">person</span></div>
              <div class="driver-info">
                <span class="driver-name">{{ v.driver_name }}</span>
                <span class="driver-phone" v-if="v.driver_phone">
                  <span class="material-symbols-rounded">phone</span> {{ v.driver_phone }}
                </span>
              </div>
              <div class="color-dot" v-if="v.color"
                :style="{ background: colorHex(v.color), border: v.color === 'ขาว' ? '1px solid #d1d5db' : 'none' }"
                :title="v.color"
              ></div>
            </div>
            <div class="schedule-section" v-if="vehicleSchedules(v.id).length">
              <div class="schedule-section-title">
                <span class="material-symbols-rounded">event</span>
                รอบที่กำลังจะมาถึง
                <span class="schedule-count-badge">{{ vehicleSchedules(v.id).length }}</span>
              </div>
              <div class="schedule-list">
                <div class="schedule-item" v-for="s in vehicleSchedules(v.id).slice(0, 3)" :key="s.id">
                  <div class="s-date-box">
                    <span class="s-month">{{ formatMonth(s.departure_date) }}</span>
                    <span class="s-day">{{ formatDay(s.departure_date) }}</span>
                  </div>
                  <div class="s-detail">
                    <span class="s-trip-name">{{ s.trip?.title || '—' }}</span>
                    <div class="s-meta">
                      <span class="s-seats" :class="seatClass(s)">
                        <span class="material-symbols-rounded">chair</span>
                        {{ s.booked_seats }}/{{ s.total_seats }}
                      </span>
                      <span class="s-status-badge" :class="'s-status-' + s.status">
                        {{ statusLabels[s.status] || s.status }}
                      </span>
                    </div>
                  </div>
                </div>
                <div class="schedule-more" v-if="vehicleSchedules(v.id).length > 3">
                  <span class="material-symbols-rounded">more_horiz</span>
                  อีก {{ vehicleSchedules(v.id).length - 3 }} รอบ
                </div>
              </div>
            </div>
            <div class="no-schedule" v-else>
              <span class="material-symbols-rounded">event_available</span>
              ยังไม่มีรอบที่กำลังจะมาถึง
            </div>
            <div class="vc-footer">
              <div class="footer-chip" v-if="v.pickup_points?.length" @click="openPickupManager(v)">
                <span class="material-symbols-rounded">location_on</span>
                {{ v.pickup_points.length }} จุดรับ
              </div>
              <div class="footer-chip" v-if="v.seat_layout" @click="openLayoutEditor(v)">
                <span class="material-symbols-rounded">grid_view</span>
                {{ v.seat_layout.rows }} แถว · {{ v.seat_layout.seats?.length || 0 }} ที่
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="empty-state-card" v-if="filteredVehicles.length === 0">
        <span class="material-symbols-rounded">directions_car</span>
        <p>ไม่พบยานพาหนะ</p>
      </div>
    </template>

    <!-- ─── Vehicle Form Modal ─────────────────────── -->
    <div class="modal-overlay" v-if="showForm">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2>{{ editing ? 'แก้ไขยานพาหนะ' : 'เพิ่มยานพาหนะใหม่' }}</h2>
          <button class="modal-close" @click="showForm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <form @submit.prevent="submitForm" class="modal-body">
          <div class="form-section-title">
            <span class="material-symbols-rounded">directions_car</span> ข้อมูลยานพาหนะ
          </div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อ *</label>
              <input v-model="form.name" required placeholder="เช่น รถตู้ VIP-01" class="form-input" />
            </div>
            <div class="form-group">
              <label>ประเภท *</label>
              <select v-model="form.type" required class="form-input">
                <option value="van">รถตู้</option>
                <option value="boat">เรือ</option>
              </select>
            </div>
            <div class="form-group">
              <label>ความจุ (ที่นั่ง) *</label>
              <input v-model.number="form.capacity" type="number" min="1" required class="form-input" />
            </div>
            <div class="form-group">
              <label>เลขทะเบียนรถ</label>
              <input v-model="form.license_plate" placeholder="เช่น กข 1234 กรุงเทพ" class="form-input" />
            </div>
            <div class="form-group">
              <label>สีรถ</label>
              <input v-model="form.color" placeholder="เช่น ขาว, เทา, น้ำเงิน" class="form-input" />
            </div>
          </div>
          <div class="form-section-title">
            <span class="material-symbols-rounded">person</span> ข้อมูลคนขับ
          </div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>เลือกจากผู้ใช้งาน (ถ้ามี)</label>
              <div class="select-with-icon">
                <span class="material-symbols-rounded">person_search</span>
                <select @change="onDriverSelect" class="form-input">
                  <option value="">-- เลือกผู้ใช้งานเพื่อดึงข้อมูล --</option>
                  <option v-for="u in staffUsers" :key="u.id" :value="u.id">
                    {{ u.name }} {{ u.phone ? `(${u.phone})` : '' }}
                  </option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>ชื่อคนขับ</label>
              <input v-model="form.driver_name" placeholder="ชื่อ-นามสกุลคนขับ" class="form-input" />
            </div>
            <div class="form-group">
              <label>เบอร์โทรศัพท์คนขับ</label>
              <input v-model="form.driver_phone" placeholder="08x-xxx-xxxx" class="form-input" />
            </div>
            <div class="form-group full-width gps-pin-box">
              <label>
                <span class="material-symbols-rounded">my_location</span>
                รหัสส่ง GPS (PIN) — สำหรับหน้า /driver/track
              </label>
              <div v-if="editing && editing.has_driver_pin" class="gps-pin-status">
                <span class="pin-set"><span class="material-symbols-rounded">check_circle</span> ตั้งรหัสไว้แล้ว</span>
                <button type="button" class="btn-clear-pin" @click="clearDriverPin" :disabled="pinBusy">ลบรหัส</button>
              </div>
              <input
                v-model="form.driver_pin"
                type="text" inputmode="numeric" maxlength="8" autocomplete="off"
                :placeholder="editing && editing.has_driver_pin ? 'กรอกรหัสใหม่เพื่อเปลี่ยน (เว้นว่างถ้าไม่เปลี่ยน)' : 'ตั้งรหัส 4-8 หลัก'"
                class="form-input"
                @input="form.driver_pin = form.driver_pin.replace(/\D/g, '')"
              />
              <p class="gps-pin-hint">
                คนขับนำรหัสนี้ไปกรอกที่หน้า
                <a href="/driver/track" target="_blank" rel="noopener">/driver/track</a>
                เพื่อส่งพิกัด GPS ของรถคันนี้ — รหัสคนขับแยกต่างหากจากบัญชีผู้ใช้งานระบบ
              </p>
            </div>
            <div class="form-group full-width">
              <label>รูปคนขับประจำรถ</label>
              <div class="media-upload-row">
                <div class="media-preview-sm" v-if="form.driver_photo">
                  <img :src="form.driver_photo" />
                  <button type="button" class="remove-btn" @click="form.driver_photo = ''">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <div class="upload-placeholder" v-else @click="triggerUpload(driverPhotoInput)">
                  <span class="material-symbols-rounded animate-spin" v-if="uploadState.driver">sync</span>
                  <span class="material-symbols-rounded" v-else>photo_camera</span>
                  <span>อัปโหลดรูปคนขับ</span>
                </div>
                <input ref="driverPhotoInput" type="file" hidden accept="image/*" @change="handleMediaUpload($event, 'driver')" />
              </div>
            </div>
          </div>
          <div class="form-section-title">
            <span class="material-symbols-rounded">photo_library</span> รูปภาพและวิดีโอ
          </div>
          <div class="form-grid">
            <div class="form-group full-width">
              <label>รูปภาพภายในรถ (สูงสุด 10 รูป)</label>
              <div class="gallery-grid-editor">
                <div v-for="(img, idx) in form.images" :key="idx" class="gallery-item-preview">
                  <img :src="img" />
                  <button type="button" class="remove-btn" @click="removeItem('images', idx)">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <div class="gallery-add-btn" v-if="form.images.length < 10" @click="triggerUpload(galleryInput)">
                  <span class="material-symbols-rounded animate-spin" v-if="uploadState.gallery">sync</span>
                  <span class="material-symbols-rounded" v-else>add</span>
                  <span>เพิ่มรูป</span>
                </div>
              </div>
              <input ref="galleryInput" type="file" hidden multiple accept="image/*" @change="handleMediaUpload($event, 'gallery')" />
            </div>
            <div class="form-group full-width">
              <label>วิดีโอภายในรถ</label>
              <div class="media-upload-row">
                <div class="video-preview" v-if="form.interior_video">
                  <video :src="form.interior_video" controls></video>
                  <button type="button" class="remove-btn" @click="form.interior_video = ''">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <div class="upload-placeholder" v-else @click="triggerUpload(videoInput)">
                  <span class="material-symbols-rounded animate-spin" v-if="uploadState.video">sync</span>
                  <span class="material-symbols-rounded" v-else>videocam</span>
                  <span>อัปโหลดวิดีโอ</span>
                </div>
                <input ref="videoInput" type="file" hidden accept="video/*" @change="handleMediaUpload($event, 'video')" />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="showForm = false">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span class="material-symbols-rounded animate-spin" v-if="submitting">sync</span>
              {{ editing ? 'บันทึก' : 'สร้าง' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ─── Pickup Points Manager Modal ─────────────── -->
    <div class="modal-overlay" v-if="showPickupManager">
      <div class="modal-card modal-xl">
        <div class="modal-header">
          <h2>
            <span class="material-symbols-rounded heading-icon">location_on</span>
            จุดรับผู้โดยสาร — {{ pickupVehicle?.name }}
          </h2>
          <button class="modal-close" @click="closePickupManager"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <div class="pickup-manager-list" v-if="pickupPoints.length">
            <div v-for="group in groupedPickups(pickupPoints)" :key="group.region" class="pickup-region-group">
              <div class="pickup-region-header">
                <span class="region-chip-lg">{{ group.region_label }}</span>
                <span class="region-code">({{ group.region }})</span>
              </div>
              <div class="pickup-manager-items">
                <div v-for="pt in group.locations" :key="pt.id" class="pickup-manager-item">
                  <img v-if="pt.image_url" :src="pt.image_url" class="pickup-item-thumb" alt="รูปจุดรับ" />
                  <div class="pickup-manager-item-info">
                    <span class="pickup-loc-name">
                      <span class="material-symbols-rounded">location_on</span> {{ pt.pickup_location }}
                    </span>
                    <span v-if="pt.notes" class="pickup-notes-text">
                      <span class="material-symbols-rounded">notes</span> {{ pt.notes }}
                    </span>
                    <a v-if="pt.map_url" :href="pt.map_url" target="_blank" class="map-link">
                      <span class="material-symbols-rounded">open_in_new</span> แผนที่
                    </a>
                  </div>
                  <div class="pickup-manager-item-actions">
                    <button class="btn-icon btn-edit btn-sm" @click="openPickupForm(pt)" title="แก้ไข">
                      <span class="material-symbols-rounded">edit</span>
                    </button>
                    <button class="btn-icon btn-delete btn-sm" @click="confirmDeletePickup(pt)" title="ลบ">
                      <span class="material-symbols-rounded">delete</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="pickup-empty" v-else>
            <span class="material-symbols-rounded">map</span>
            <p>ยังไม่มีจุดรับผู้โดยสาร</p>
          </div>

          <div class="pickup-add-section">
            <div class="form-section-title">
              {{ editingPickup ? 'แก้ไขจุดรับผู้โดยสาร' : 'เพิ่มจุดรับผู้โดยสาร' }}
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>ภูมิภาค *</label>
                <select v-model="pickupForm.region" @change="onRegionChange" class="form-input">
                  <option value="">-- เลือกภูมิภาค --</option>
                  <option value="north">north — ภาคเหนือ</option>
                  <option value="northeast">northeast — ภาคอีสาน</option>
                  <option value="central">central — ภาคกลาง</option>
                  <option value="east">east — ภาคตะวันออก</option>
                  <option value="west">west — ภาคตะวันตก</option>
                  <option value="south">south — ภาคใต้</option>
                </select>
              </div>
              <div class="form-group">
                <label>ชื่อภูมิภาค (ไทย) *</label>
                <input v-model="pickupForm.region_label" placeholder="เช่น ภาคเหนือ" required class="form-input" />
              </div>
              <div class="form-group full-width">
                <label>ชื่อจุดขึ้นรถ *</label>
                <input v-model="pickupForm.pickup_location" placeholder="เช่น ปั๊มน้ำมัน ปตท. แยกลาดพร้าว" required class="form-input" />
              </div>
              <div class="form-group full-width">
                <label>หมายเหตุ / เวลานัดพบ</label>
                <input v-model="pickupForm.notes" placeholder="เช่น นัดพบ 05:30 น." class="form-input" />
              </div>
              <div class="form-group full-width">
                <label>ลิงก์ Google Maps</label>
                <input v-model="pickupForm.map_url" placeholder="https://maps.google.com/..." class="form-input" />
              </div>
              <div class="form-group full-width">
                <label>รูปภาพประจำจุดรับ</label>
                <div class="pickup-image-field">
                  <div class="pickup-image-preview" v-if="pickupForm.image_url">
                    <img :src="pickupForm.image_url" alt="รูปจุดรับ" />
                    <button type="button" class="pickup-image-remove" @click="pickupForm.image_url = ''" title="ลบรูป">
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                  <label class="pickup-image-upload">
                    <input type="file" accept="image/*" @change="uploadPickupImage" hidden :disabled="pickupImageUploading" />
                    <span class="material-symbols-rounded" :class="{ 'animate-spin': pickupImageUploading }">{{ pickupImageUploading ? 'sync' : 'add_photo_alternate' }}</span>
                    {{ pickupForm.image_url ? 'เปลี่ยนรูป' : 'อัปโหลดรูป' }}
                  </label>
                </div>
              </div>
            </div>
            <div class="pickup-form-actions">
              <button v-if="editingPickup" type="button" class="btn-secondary btn-sm" @click="cancelPickupEdit">ยกเลิก</button>
              <button type="button" class="btn-primary btn-sm" @click="submitPickupForm" :disabled="pickupSubmitting">
                <span class="material-symbols-rounded animate-spin" v-if="pickupSubmitting">sync</span>
                {{ editingPickup ? 'บันทึกการแก้ไข' : 'เพิ่มจุดรับ' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── Delete Pickup Confirm ─────────────────── -->
    <div class="modal-overlay" v-if="showDeletePickupConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบจุดรับ</h2>
          <button class="modal-close" @click="showDeletePickupConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">ลบจุดรับ <strong>{{ deletingPickup?.pickup_location }}</strong>?</p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeletePickupConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDeletePickup" :disabled="pickupSubmitting">ลบ</button>
        </div>
      </div>
    </div>

    <!-- ─── Delete Vehicle Confirm ────────────────── -->
    <div class="modal-overlay" v-if="showDeleteConfirm">
      <div class="modal-card modal-sm">
        <div class="modal-header">
          <h2>ยืนยันการลบ</h2>
          <button class="modal-close" @click="showDeleteConfirm = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
          <p class="confirm-text">คุณต้องการลบ <strong>{{ deleting?.name }}</strong> ใช่หรือไม่?</p>
          <p class="confirm-warning">
            <span class="material-symbols-rounded" style="color:var(--color-gold);">warning</span>
            การดำเนินการนี้ไม่สามารถย้อนกลับได้
          </p>
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showDeleteConfirm = false">ยกเลิก</button>
          <button class="btn-danger" @click="doDelete" :disabled="submitting">ลบ</button>
        </div>
      </div>
    </div>

    <!-- ─── Seat Layout Editor Modal ──────────────── -->
    <div class="modal-overlay" v-if="showLayoutEditor">
      <div class="modal-card modal-lg">
        <div class="modal-header">
          <h2><span class="material-symbols-rounded heading-icon">grid_view</span> ผังที่นั่ง — {{ layoutVehicle?.name }}</h2>
          <button class="modal-close" @click="showLayoutEditor = false"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body p-0">
          <SeatMapEditor v-model="layoutForm" />
        </div>
        <div class="modal-footer">
          <button class="btn-secondary" @click="showLayoutEditor = false">ยกเลิก</button>
          <button class="btn-primary" @click="saveLayout" :disabled="submittingLayout">
            <span class="material-symbols-rounded animate-spin" v-if="submittingLayout">sync</span>
            บันทึกผังที่นั่ง
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import SeatMapEditor from '../../components/SeatMapEditor.vue';
import api from '../../lib/axios';

const admin = useAdminStore();
const filters = reactive({ type: '' });
const searchQuery = ref('');
const showForm = ref(false);
const showDeleteConfirm = ref(false);
const editing = ref(null);
const deleting = ref(null);
const submitting = ref(false);
const expandedPickups = ref(new Set());
const staffUsers = ref([]);
const upcomingSchedules = ref([]);

const form = reactive({
  name: '', type: 'van', capacity: 10,
  license_plate: '', color: '', driver_name: '', driver_phone: '',
  driver_photo: '', interior_video: '', images: [], driver_pin: '',
});
const pinBusy = ref(false);

const driverPhotoInput = ref(null);
const galleryInput = ref(null);
const videoInput = ref(null);
const uploadState = reactive({ driver: false, gallery: false, video: false });

const showPickupManager = ref(false);
const pickupVehicle = ref(null);
const pickupPoints = ref([]);
const editingPickup = ref(null);
const deletingPickup = ref(null);
const showDeletePickupConfirm = ref(false);
const pickupSubmitting = ref(false);
const pickupForm = reactive({
  region: '', region_label: '', pickup_location: '', notes: '', map_url: '', image_url: '',
});
const pickupImageUploading = ref(false);

const uploadPickupImage = async (event) => {
  const file = event.target.files?.[0];
  if (!file) return;
  pickupImageUploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const res = await api.post('/admin/pickup-points/image', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    pickupForm.image_url = res.data.data.url;
  } catch (e) {
    alert(e.response?.data?.message || 'อัปโหลดรูปไม่สำเร็จ');
  } finally {
    pickupImageUploading.value = false;
    event.target.value = '';
  }
};

const showLayoutEditor = ref(false);
const layoutVehicle = ref(null);
const layoutForm = ref({ rows: 4, columns: ['A','B','C','','D','E'], seats: [] });
const submittingLayout = ref(false);

const REGION_LABELS = {
  north: 'ภาคเหนือ', northeast: 'ภาคอีสาน', central: 'ภาคกลาง',
  east: 'ภาคตะวันออก', west: 'ภาคตะวันตก', south: 'ภาคใต้',
};

const statusLabels = {
  open: 'รับสมัคร', full: 'เต็ม', closed: 'ปิด', cancelled: 'ยกเลิก', completed: 'จบแล้ว',
};

const allVehicles = computed(() => admin.vehicles.data || []);

const filteredVehicles = computed(() => {
  let data = allVehicles.value;
  if (filters.type) data = data.filter(v => v.type === filters.type);
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    data = data.filter(v =>
      v.name.toLowerCase().includes(q) ||
      (v.license_plate || '').toLowerCase().includes(q) ||
      (v.driver_name || '').toLowerCase().includes(q)
    );
  }
  return data;
});

const vehicleGroups = computed(() => [
  { type: 'van', label: 'รถตู้', vehicles: filteredVehicles.value.filter(v => v.type === 'van') },
  { type: 'boat', label: 'เรือ', vehicles: filteredVehicles.value.filter(v => v.type === 'boat') },
]);

const typeTabs = computed(() => [
  { value: '', icon: 'directions_car', label: 'ทั้งหมด', count: allVehicles.value.length },
  { value: 'van', icon: 'airport_shuttle', label: 'รถตู้', count: allVehicles.value.filter(v => v.type === 'van').length },
  { value: 'boat', icon: 'directions_boat', label: 'เรือ', count: allVehicles.value.filter(v => v.type === 'boat').length },
]);

const vehicleSchedulesMap = computed(() => {
  const map = {};
  for (const s of upcomingSchedules.value) {
    const vid = s.vehicle?.id;
    if (!vid) continue;
    if (!map[vid]) map[vid] = [];
    map[vid].push(s);
  }
  return map;
});

const vehicleSchedules = (vehicleId) => vehicleSchedulesMap.value[vehicleId] || [];

const groupedPickups = (points) => {
  const map = {};
  for (const pt of points) {
    if (!map[pt.region]) {
      map[pt.region] = { region: pt.region, region_label: pt.region_label, locations: [] };
    }
    map[pt.region].locations.push(pt);
  }
  return Object.values(map);
};

const colorHex = (colorName) => {
  const map = {
    'ขาว': '#ffffff', 'ดำ': '#1f2937', 'เทา': '#9ca3af', 'แดง': '#ef4444',
    'น้ำเงิน': '#3b82f6', 'เขียว': '#22c55e', 'เหลือง': '#eab308',
    'ส้ม': '#f97316', 'ม่วง': '#a855f7', 'ชมพู': '#ec4899',
  };
  return map[colorName] || '#9ca3af';
};

const MONTHS_TH = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
const formatMonth = (d) => MONTHS_TH[new Date(d).getMonth()];
const formatDay = (d) => new Date(d).getDate();

const seatClass = (s) => {
  const ratio = s.booked_seats / (s.total_seats || 1);
  if (ratio >= 1) return 'seats-full';
  if (ratio >= 0.8) return 'seats-almost';
  return 'seats-ok';
};

const fetchData = () => {
  // per_page สูงเพื่อดึงรถมาครบทุกคัน — หน้านี้ไม่มี pagination UI
  // ถ้าไม่ส่ง backend จะ default ที่ 15 ทำให้รถที่เพิ่มใหม่ไม่ขึ้น และสถิติ/รายการนับไม่ครบ
  admin.fetchVehicles({ ...filters, per_page: 200 });
  fetchStaff();
  fetchUpcomingSchedules();
};

const fetchStaff = async () => {
  try {
    const res = await api.get('/admin/users', { params: { per_page: 100 } });
    staffUsers.value = res.data.data.filter(u =>
      u.roles.includes('staff') || u.roles.includes('operator') || u.roles.includes('admin')
    );
  } catch (e) {
    console.error('Failed to fetch staff:', e);
  }
};

const fetchUpcomingSchedules = async () => {
  try {
    const res = await api.get('/admin/schedules', {
      params: { per_page: 200, upcoming: 1 },
    });
    const data = res.data.data || [];
    upcomingSchedules.value = data.sort((a, b) =>
      new Date(a.departure_date) - new Date(b.departure_date)
    );
  } catch (e) {
    console.error('Failed to fetch schedules:', e);
  }
};

const onDriverSelect = (e) => {
  const userId = e.target.value;
  if (!userId) return;
  const user = staffUsers.value.find(u => u.id == userId);
  if (user) {
    form.driver_name = user.name;
    form.driver_phone = user.phone || '';
    form.driver_photo = user.avatar_url || '';
    e.target.value = '';
  }
};

const openForm = (v = null) => {
  editing.value = v;
  if (v) {
    Object.assign(form, {
      name: v.name, type: v.type, capacity: v.capacity,
      license_plate: v.license_plate || '', color: v.color || '',
      driver_name: v.driver_name || '', driver_phone: v.driver_phone || '',
      driver_photo: v.driver_photo || '', interior_video: v.interior_video || '',
      images: v.images || [], driver_pin: '',
    });
  } else {
    Object.assign(form, {
      name: '', type: 'van', capacity: 10, license_plate: '', color: '',
      driver_name: '', driver_phone: '', driver_photo: '', interior_video: '',
      images: [], driver_pin: '',
    });
  }
  showForm.value = true;
};

const submitForm = async () => {
  submitting.value = true;
  try {
    const { driver_pin, ...data } = form;

    let vehicleId = editing.value?.id;
    if (editing.value) {
      await admin.updateVehicle(editing.value.id, data);
    } else {
      const created = await admin.createVehicle(data);
      vehicleId = created?.data?.id;
    }

    // ตั้ง/เปลี่ยนรหัสส่ง GPS (ถ้ากรอกมา) — เป็น endpoint แยกต่างหาก
    const pin = (driver_pin || '').trim();
    if (pin && vehicleId) {
      if (pin.length < 4 || pin.length > 8) {
        alert('รหัสส่ง GPS ต้องเป็นตัวเลข 4-8 หลัก');
        submitting.value = false;
        return;
      }
      await api.put(`/admin/vehicles/${vehicleId}/driver-pin`, { driver_pin: pin });
    }

    showForm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const clearDriverPin = async () => {
  if (!editing.value?.id) return;
  if (!confirm('ลบรหัสส่ง GPS ของรถคันนี้?')) return;
  pinBusy.value = true;
  try {
    await api.delete(`/admin/vehicles/${editing.value.id}/driver-pin`);
    editing.value.has_driver_pin = false;
    form.driver_pin = '';
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'ลบรหัสไม่สำเร็จ');
  } finally {
    pinBusy.value = false;
  }
};

const triggerUpload = (input) => input?.click();

const handleMediaUpload = async (event, type) => {
  const file = event.target.files?.[0];
  if (!file) return;
  if (type === 'gallery') { handleGalleryUpload(Array.from(event.target.files)); return; }
  const maxSize = type === 'video' ? 50 * 1024 * 1024 : 10 * 1024 * 1024;
  if (file.size > maxSize) { alert(`ไฟล์มีขนาดเกินกำหนด (${type === 'video' ? '50MB' : '10MB'})`); return; }
  uploadState[type] = true;
  try {
    const formData = new FormData();
    formData.append('file', file);
    const res = await api.post('/admin/upload-image', formData);
    if (type === 'driver') form.driver_photo = res.data.data.url;
    else if (type === 'video') form.interior_video = res.data.data.url;
  } catch (e) {
    alert('อัปโหลดล้มเหลว');
  } finally {
    uploadState[type] = false;
    if (event.target) event.target.value = '';
  }
};

const handleGalleryUpload = async (files) => {
  const remaining = 10 - form.images.length;
  if (remaining <= 0) { alert('ครบโควตารูปภาพแล้ว (สูงสุด 10 รูป)'); return; }
  const filesToUpload = files.slice(0, remaining);
  uploadState.gallery = true;
  let errorCount = 0;
  try {
    for (const file of filesToUpload) {
      if (file.size > 10 * 1024 * 1024) { errorCount++; continue; }
      const formData = new FormData();
      formData.append('file', file);
      try {
        const res = await api.post('/admin/upload-image', formData);
        form.images.push(res.data.data.url);
      } catch { errorCount++; }
    }
    if (errorCount > 0) alert(`อัปโหลดล้มเหลว ${errorCount} รูป`);
  } finally {
    uploadState.gallery = false;
    if (galleryInput.value) galleryInput.value.value = '';
  }
};

const removeItem = (field, index) => {
  if (field === 'images') form.images.splice(index, 1);
};

const confirmDelete = (v) => { deleting.value = v; showDeleteConfirm.value = true; };

const doDelete = async () => {
  submitting.value = true;
  try {
    await admin.deleteVehicle(deleting.value.id);
    showDeleteConfirm.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const openPickupManager = async (v) => {
  pickupVehicle.value = v;
  pickupPoints.value = v.pickup_points || [];
  resetPickupForm();
  showPickupManager.value = true;
};

const closePickupManager = () => {
  showPickupManager.value = false;
  pickupVehicle.value = null;
  pickupPoints.value = [];
  resetPickupForm();
  fetchData();
};

const resetPickupForm = () => {
  editingPickup.value = null;
  Object.assign(pickupForm, { region: '', region_label: '', pickup_location: '', notes: '', map_url: '', image_url: '' });
};

const onRegionChange = () => {
  if (pickupForm.region && REGION_LABELS[pickupForm.region]) {
    pickupForm.region_label = REGION_LABELS[pickupForm.region];
  }
};

const openPickupForm = (pt) => {
  editingPickup.value = pt;
  Object.assign(pickupForm, {
    region: pt.region, region_label: pt.region_label,
    pickup_location: pt.pickup_location, notes: pt.notes || '', map_url: pt.map_url || '',
    image_url: pt.image_url || '',
  });
};

const cancelPickupEdit = () => resetPickupForm();

const submitPickupForm = async () => {
  if (!pickupForm.region || !pickupForm.region_label || !pickupForm.pickup_location) {
    alert('กรุณากรอกภูมิภาคและชื่อจุดรับให้ครบ');
    return;
  }
  pickupSubmitting.value = true;
  try {
    const data = { ...pickupForm };
    if (editingPickup.value) {
      await admin.updateVehiclePickupPoint(pickupVehicle.value.id, editingPickup.value.id, data);
    } else {
      await admin.createVehiclePickupPoint(pickupVehicle.value.id, data);
    }
    const res = await admin.fetchVehiclePickupPoints(pickupVehicle.value.id);
    pickupPoints.value = res.data;
    resetPickupForm();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

const confirmDeletePickup = (pt) => { deletingPickup.value = pt; showDeletePickupConfirm.value = true; };

const doDeletePickup = async () => {
  pickupSubmitting.value = true;
  try {
    await admin.deleteVehiclePickupPoint(pickupVehicle.value.id, deletingPickup.value.id);
    const res = await admin.fetchVehiclePickupPoints(pickupVehicle.value.id);
    pickupPoints.value = res.data;
    showDeletePickupConfirm.value = false;
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    pickupSubmitting.value = false;
  }
};

const openLayoutEditor = (v) => {
  layoutVehicle.value = v;
  layoutForm.value = v.seat_layout?.seats
    ? JSON.parse(JSON.stringify(v.seat_layout))
    : { rows: 4, columns: ['A','B','C','','D','E'], seats: [] };
  showLayoutEditor.value = true;
};

const saveLayout = async () => {
  submittingLayout.value = true;
  try {
    await admin.updateVehicle(layoutVehicle.value.id, {
      name: layoutVehicle.value.name,
      type: layoutVehicle.value.type,
      capacity: layoutVehicle.value.capacity,
      seat_layout: layoutForm.value,
      license_plate: layoutVehicle.value.license_plate,
      color: layoutVehicle.value.color,
      driver_name: layoutVehicle.value.driver_name,
      driver_phone: layoutVehicle.value.driver_phone,
    });
    showLayoutEditor.value = false;
    fetchData();
    alert('บันทึกผังที่นั่งสำเร็จ');
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกผังที่นั่ง');
  } finally {
    submittingLayout.value = false;
  }
};

onMounted(() => fetchData());
</script>

<style scoped>
@import url('./admin-shared.css');

/* ─── Stats ───────────────────────────────────────── */
.vehicle-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 22px;
}
@media (max-width: 768px) { .vehicle-stats { grid-template-columns: repeat(2, 1fr); } }

.stat-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px 20px;
  background: var(--color-white);
  border-radius: 14px;
  border: 1px solid var(--color-sand-dark);
  transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }

.stat-icon {
  width: 44px; height: 44px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.stat-icon .material-symbols-rounded { font-size: 22px; }
.stat-all  { background: linear-gradient(135deg, #f0f9ff, #e0f2fe); color: #0369a1; }
.stat-van  { background: linear-gradient(135deg, #fef9ee, #fef3c7); color: #b45309; }
.stat-boat { background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #1d4ed8; }
.stat-schedule { background: linear-gradient(135deg, #f0fdf4, #dcfce7); color: #15803d; }

.stat-info { display: flex; flex-direction: column; }
.stat-value { font-size: 26px; font-weight: 800; color: #111827; line-height: 1; }
.stat-label { font-size: 12px; color: #6b7280; margin-top: 2px; }

/* ─── Filters & Type Tabs ─────────────────────────── */
.filters-bar { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }

.type-tabs { display: flex; gap: 6px; }

.type-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border: 1px solid var(--color-sand-dark);
  border-radius: 8px;
  background: var(--color-white);
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-mid);
  cursor: pointer;
  transition: all 0.15s;
  font-family: 'Anuphan', sans-serif;
}
.type-tab .material-symbols-rounded { font-size: 16px; }
.type-tab:hover { border-color: var(--color-accent); color: var(--color-accent); }
.type-tab.active { background: var(--color-accent); color: white; border-color: var(--color-accent); }

.tab-count {
  background: rgba(255,255,255,0.25);
  border-radius: 10px;
  padding: 1px 7px;
  font-size: 11px;
  font-weight: 700;
  min-width: 20px;
  text-align: center;
}
.type-tab:not(.active) .tab-count { background: var(--color-sand); color: var(--color-text-mid); }

/* ─── Type Section Headers ────────────────────────── */
.type-section { margin-bottom: 28px; }

.type-section-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text-dark);
}
.type-section-header .material-symbols-rounded { font-size: 20px; color: var(--color-accent); }
.section-count {
  margin-left: 4px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-muted);
  background: var(--color-sand);
  padding: 2px 10px;
  border-radius: 10px;
}

/* ─── Vehicles Grid ───────────────────────────────── */
.vehicles-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 16px;
}

/* ─── Vehicle Card ────────────────────────────────── */
.vehicle-card {
  background: var(--color-white);
  border-radius: 16px;
  border: 1px solid var(--color-sand-dark);
  overflow: hidden;
  transition: box-shadow 0.2s, transform 0.2s;
  box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.vehicle-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.07); transform: translateY(-2px); }

/* Card top strip */
.vc-top {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-sand-dark);
}
.vtype-bg-van  { background: linear-gradient(135deg, #fffbeb, #fef9ee); }
.vtype-bg-boat { background: linear-gradient(135deg, #eff6ff, #f0f9ff); }

.vc-type-icon {
  width: 44px; height: 44px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.vtype-bg-van  .vc-type-icon { background: #fef3c7; color: #b45309; }
.vtype-bg-boat .vc-type-icon { background: #dbeafe; color: #1d4ed8; }
.vc-type-icon .material-symbols-rounded { font-size: 24px; }

.vc-header-info { flex: 1; min-width: 0; }
.vc-header-info h3 {
  margin: 0 0 5px;
  font-size: 15px;
  font-weight: 700;
  color: var(--color-text-dark);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.vc-badges { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

.capacity-chip {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 12px; color: var(--color-text-mid); font-weight: 500;
}
.capacity-chip .material-symbols-rounded { font-size: 14px; }

.plate-chip {
  font-size: 11px; font-weight: 700;
  background: rgba(255,255,255,0.7); color: var(--color-text-mid);
  padding: 2px 8px; border-radius: 6px;
  border: 1px solid var(--color-sand-dark);
  letter-spacing: 0.3px;
}

.vc-actions { display: flex; gap: 4px; flex-shrink: 0; }

/* Card body */
.vc-body { padding: 14px 16px; }

/* Driver row */
.driver-row {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px;
  background: var(--color-sand);
  border-radius: 10px;
  margin-bottom: 14px;
}
.driver-avatar {
  width: 32px; height: 32px;
  border-radius: 50%; overflow: hidden;
  border: 2px solid white;
  box-shadow: 0 1px 4px rgba(0,0,0,0.1);
  flex-shrink: 0;
}
.driver-avatar img { width: 100%; height: 100%; object-fit: cover; }
.driver-placeholder {
  width: 32px; height: 32px; border-radius: 50%;
  background: white; display: flex; align-items: center; justify-content: center;
  color: var(--color-text-mid); flex-shrink: 0;
}
.driver-placeholder .material-symbols-rounded { font-size: 18px; }
.driver-info { flex: 1; min-width: 0; }
.driver-name { display: block; font-size: 13px; font-weight: 600; color: var(--color-text-dark); }
.driver-phone {
  display: flex; align-items: center; gap: 3px;
  font-size: 12px; color: var(--color-ocean); margin-top: 2px;
}
.driver-phone .material-symbols-rounded { font-size: 13px; }
.gps-pin-badge {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 11px; font-weight: 600; margin-top: 4px;
  padding: 2px 8px; border-radius: 999px;
}
.gps-pin-badge .material-symbols-rounded { font-size: 13px; }
.gps-pin-badge.on { background: #dcfce7; color: #15803d; }
.gps-pin-badge.off { background: #f1f5f9; color: #94a3b8; }
/* ── GPS PIN box in form ── */
.gps-pin-box { background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 12px; padding: 12px 14px; }
.gps-pin-box > label { display: flex; align-items: center; gap: 5px; }
.gps-pin-box > label .material-symbols-rounded { font-size: 16px; color: #0d9488; }
.gps-pin-status {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.gps-pin-status .pin-set {
  display: inline-flex; align-items: center; gap: 4px;
  color: #15803d; font-size: 13px; font-weight: 600;
}
.gps-pin-status .pin-set .material-symbols-rounded { font-size: 16px; }
.btn-clear-pin {
  border: 1px solid #fca5a5; background: #fef2f2; color: #dc2626;
  border-radius: 8px; padding: 4px 10px; font-size: 12px; cursor: pointer;
}
.btn-clear-pin:disabled { opacity: 0.6; cursor: default; }
.gps-pin-hint { font-size: 11.5px; color: #64748b; margin-top: 6px; line-height: 1.5; }
.gps-pin-hint a { color: #0d9488; font-weight: 600; text-decoration: none; }
.color-dot {
  width: 18px; height: 18px; border-radius: 50%;
  flex-shrink: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}

/* Schedule section */
.schedule-section { margin-bottom: 12px; }

.schedule-section-title {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; font-weight: 700; color: var(--color-text-mid);
  text-transform: uppercase; letter-spacing: 0.5px;
  margin-bottom: 8px;
}
.schedule-section-title .material-symbols-rounded { font-size: 15px; color: var(--color-accent); }

.schedule-count-badge {
  margin-left: auto;
  background: var(--color-accent); color: white;
  font-size: 11px; font-weight: 700;
  padding: 1px 8px; border-radius: 10px;
  text-transform: none; letter-spacing: 0;
}

.schedule-list { display: flex; flex-direction: column; gap: 6px; }

.schedule-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px;
  background: var(--color-sand);
  border-radius: 8px;
  border: 1px solid var(--color-sand-dark);
  transition: background 0.15s;
}
.schedule-item:hover { background: #f0fdf4; border-color: #bbf7d0; }

.s-date-box {
  display: flex; flex-direction: column; align-items: center;
  width: 34px; flex-shrink: 0;
}
.s-month { font-size: 10px; font-weight: 700; color: var(--color-accent); text-transform: uppercase; }
.s-day { font-size: 18px; font-weight: 800; color: var(--color-text-dark); line-height: 1.1; }

.s-detail { flex: 1; min-width: 0; }
.s-trip-name {
  display: block; font-size: 13px; font-weight: 600; color: var(--color-text-dark);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.s-meta { display: flex; align-items: center; gap: 6px; margin-top: 3px; }

.s-seats {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 6px;
}
.s-seats .material-symbols-rounded { font-size: 12px; }
.seats-ok     { background: #dcfce7; color: #15803d; }
.seats-almost { background: #fef3c7; color: #b45309; }
.seats-full   { background: #fee2e2; color: #dc2626; }

.s-status-badge {
  font-size: 10px; font-weight: 700;
  padding: 1px 7px; border-radius: 6px;
  letter-spacing: 0.3px;
}
.s-status-open      { background: #dcfce7; color: #15803d; }
.s-status-full      { background: #fee2e2; color: #dc2626; }
.s-status-closed    { background: #f3f4f6; color: #6b7280; }
.s-status-cancelled { background: #fef2f2; color: #991b1b; }
.s-status-completed { background: #f3f4f6; color: #6b7280; }

.schedule-more {
  display: flex; align-items: center; gap: 4px;
  font-size: 12px; color: var(--color-text-muted);
  padding: 4px 10px; font-style: italic;
}
.schedule-more .material-symbols-rounded { font-size: 16px; }

.no-schedule {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; color: var(--color-text-muted);
  padding: 10px; background: var(--color-sand);
  border-radius: 8px; border: 1px dashed var(--color-sand-dark);
  margin-bottom: 12px;
}
.no-schedule .material-symbols-rounded { font-size: 16px; }

/* Footer chips */
.vc-footer {
  display: flex; gap: 8px;
  margin-top: 10px; padding-top: 10px;
  border-top: 1px solid var(--color-sand-dark);
  flex-wrap: wrap;
}
.footer-chip {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 12px; color: var(--color-text-mid);
  background: var(--color-sand); border: 1px solid var(--color-sand-dark);
  padding: 3px 10px; border-radius: 8px;
  cursor: pointer; transition: all 0.15s; font-weight: 500;
  font-family: 'Anuphan', sans-serif;
}
.footer-chip:hover { border-color: var(--color-accent); color: var(--color-accent); background: #f0fdf4; }
.footer-chip .material-symbols-rounded { font-size: 14px; }

/* ─── Action Buttons ─────────────────────────────── */
.btn-icon {
  width: 32px; height: 32px;
  border-radius: 8px; transition: all 0.15s;
  display: flex; align-items: center; justify-content: center;
}
.btn-icon .material-symbols-rounded { font-size: 15px; }

.btn-pickup { background: #f3e8ff; color: #7e22ce; border: none; }
.btn-pickup:hover { background: #e9d5ff; }
.btn-layout { background: #fce7f3; color: #be185d; border: none; }
.btn-layout:hover { background: #fbcfe8; }

/* ─── Empty state ─────────────────────────────────── */
.empty-state-card {
  text-align: center; padding: 80px 20px;
  color: var(--color-text-muted);
  background: var(--color-white);
  border-radius: 16px; border: 1px dashed var(--color-sand-dark);
}
.empty-state-card .material-symbols-rounded { font-size: 48px; }
.empty-state-card p { margin-top: 12px; font-size: 15px; }

/* ─── Modals ──────────────────────────────────────── */
.modal-lg { max-width: 620px; }
.modal-xl { max-width: 760px; }

.form-section-title {
  font-size: 14px; font-weight: 700; color: var(--color-text-dark);
  margin: 20px 0 12px;
  display: flex; align-items: center; gap: 8px;
}
.form-section-title .material-symbols-rounded { font-size: 16px; color: var(--color-accent); }

/* ─── Pickup Manager ─────────────────────────────── */
.pickup-manager-list { margin-bottom: 20px; }
.pickup-region-group { margin-bottom: 20px; }
.pickup-region-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.region-chip-lg {
  display: inline-block; background: #f3e8ff; color: #7e22ce;
  font-size: 13px; font-weight: 700; padding: 4px 14px; border-radius: 16px;
}
.region-code { font-size: 12px; color: var(--color-text-mid); }
.pickup-manager-items { display: flex; flex-direction: column; gap: 8px; }
.pickup-manager-item {
  display: flex; align-items: center; justify-content: space-between;
  background: var(--color-white); border: 1px solid var(--color-sand-dark);
  border-radius: 10px; padding: 12px 14px;
}
.pickup-item-thumb { width: 46px; height: 46px; border-radius: 8px; object-fit: cover; flex-shrink: 0; border: 1px solid var(--color-sand-dark); margin-right: 10px; }
.pickup-manager-item-info { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex: 1; }
.pickup-image-field { display: flex; align-items: center; gap: 12px; }
.pickup-image-preview { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
.pickup-image-preview img { width: 64px; height: 64px; border-radius: 8px; object-fit: cover; border: 1px solid var(--color-sand-dark); }
.pickup-image-remove { position: absolute; top: -6px; right: -6px; width: 22px; height: 22px; border-radius: 50%; border: none; background: #dc2626; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
.pickup-image-remove .material-symbols-rounded { font-size: 15px; }
.pickup-image-upload { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; border: 1px dashed var(--color-accent, #2563eb); color: var(--color-accent, #2563eb); font-size: 13px; font-weight: 600; cursor: pointer; background: rgba(37, 99, 235, 0.04); }
.pickup-image-upload .material-symbols-rounded { font-size: 18px; }
.pickup-loc-name { display: flex; align-items: center; gap: 4px; font-size: 14px; color: var(--color-text-dark); font-weight: 600; }
.pickup-loc-name .material-symbols-rounded { font-size: 14px; }
.pickup-notes-text { display: flex; align-items: center; gap: 4px; font-size: 13px; color: var(--color-text-muted); }
.pickup-notes-text .material-symbols-rounded { font-size: 14px; }
.map-link { display: flex; align-items: center; gap: 4px; font-size: 13px; color: var(--color-ocean); text-decoration: none; }
.map-link:hover { text-decoration: underline; }
.map-link .material-symbols-rounded { font-size: 14px; }
.pickup-manager-item-actions { display: flex; gap: 6px; }
.btn-sm { padding: 6px 12px !important; font-size: 13px !important; height: auto !important; border-radius: 8px; width: auto !important; }
.pickup-empty { text-align: center; padding: 40px; color: var(--color-text-muted); }
.pickup-empty .material-symbols-rounded { font-size: 40px; }
.pickup-empty p { margin-top: 12px; font-size: 15px; }
.pickup-add-section { border-top: 1px solid var(--color-sand-dark); padding-top: 20px; }
.pickup-form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }

/* ─── Select with icon ───────────────────────────── */
.select-with-icon { position: relative; display: flex; align-items: center; }
.select-with-icon .material-symbols-rounded { position: absolute; left: 12px; color: var(--color-text-muted); pointer-events: none; font-size: 20px; }
.select-with-icon .form-input { padding-left: 40px; }

/* ─── Media Upload ───────────────────────────────── */
.media-upload-row { display: flex; gap: 12px; margin-top: 8px; }
.media-preview-sm { position: relative; width: 120px; height: 120px; border-radius: 12px; overflow: hidden; border: 1px solid var(--color-sand-dark); }
.media-preview-sm img { width: 100%; height: 100%; object-fit: cover; }
.video-preview { position: relative; width: 100%; max-width: 320px; border-radius: 12px; overflow: hidden; border: 1px solid var(--color-sand-dark); }
.video-preview video { width: 100%; display: block; }
.upload-placeholder {
  width: 140px; height: 120px;
  border: 2px dashed var(--color-sand-dark); border-radius: 12px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  cursor: pointer; color: var(--color-text-mid); font-size: 13px;
  transition: all 0.2s; background: var(--color-white);
}
.upload-placeholder:hover { border-color: var(--color-ocean); color: var(--color-ocean); background: #eff6ff; }
.upload-placeholder .material-symbols-rounded { font-size: 28px; margin-bottom: 6px; }
.gallery-grid-editor { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 12px; margin-top: 8px; }
.gallery-item-preview { position: relative; height: 110px; border-radius: 10px; overflow: hidden; border: 1px solid var(--color-sand-dark); }
.gallery-item-preview img { width: 100%; height: 100%; object-fit: cover; }
.gallery-add-btn {
  height: 110px; border: 2px dashed var(--color-sand-dark); border-radius: 10px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  cursor: pointer; color: var(--color-text-mid); font-size: 12px; background: var(--color-white);
}
.gallery-add-btn:hover { border-color: var(--color-ocean); color: var(--color-ocean); background: #eff6ff; }
.gallery-add-btn .material-symbols-rounded { font-size: 24px; margin-bottom: 4px; }
.remove-btn {
  position: absolute; top: 6px; right: 6px;
  width: 22px; height: 22px;
  border-radius: 50%; background: rgba(255,255,255,0.95); color: #ef4444;
  border: 1px solid #fee2e2; display: flex; align-items: center; justify-content: center;
  cursor: pointer; box-shadow: 0 1px 4px rgba(0,0,0,0.1); transition: transform 0.15s;
}
.remove-btn:hover { transform: scale(1.1); }
.remove-btn .material-symbols-rounded { font-size: 12px; }
</style>
