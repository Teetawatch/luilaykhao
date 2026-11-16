<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><span class="material-symbols-rounded heading-icon">cottage</span> ที่พักชุมชน</h1>
        <p class="page-subtitle">
          โฮมสเตย์/ที่พักใกล้ทริป — ลูกค้าจองผ่านเว็บและแอป เงินส่วนใหญ่โอนให้เจ้าของหลังแขกเข้าพัก
        </p>
      </div>
      <div class="header-actions">
        <router-link to="/admin/stay-bookings" class="btn-secondary">
          <span class="material-symbols-rounded">receipt_long</span> ใบจองที่พัก
        </router-link>
        <button class="btn-primary" @click="openCreate">
          <span class="material-symbols-rounded">add</span> เพิ่มที่พัก
        </button>
      </div>
    </div>

    <div class="table-card guide-card">
      <h3><span class="material-symbols-rounded">tips_and_updates</span> เปิดขายที่พักใหม่ใน 4 ขั้น</h3>
      <ol>
        <li>กด <strong>เพิ่มที่พัก</strong> กรอกชื่อ ชุมชน จังหวัด รูป และเรื่องเล่าของชุมชน</li>
        <li>แท็บ <strong>ห้อง</strong> — เพิ่มประเภทห้อง จำนวนห้อง และราคาต่อคืน (ต้องมีอย่างน้อย 1 แบบก่อนเปิดขาย)</li>
        <li>ส่ง <strong>ลิงก์เจ้าของที่พัก</strong> ให้เจ้าของทาง LINE — เขาใช้ยืนยันคำขอ ปิดห้องที่ขายที่อื่นไปแล้ว และดูยอดที่จะได้รับ</li>
        <li>ตั้งสถานะเป็น <strong>เปิดขาย</strong> แล้วผูกทริปใกล้เคียง ที่พักจะขึ้นในหน้าทริปนั้นด้วย</li>
      </ol>
      <p class="guide-foot">หน้าลูกค้า: <a href="/stays" target="_blank">/stays</a></p>
    </div>

    <div class="table-card">
      <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

      <div class="empty-state" v-else-if="!stays.length">
        <span class="material-symbols-rounded">cottage</span>
        <p>ยังไม่มีที่พัก</p>
        <button class="btn-primary" @click="openCreate">
          <span class="material-symbols-rounded">add</span> เพิ่มที่พักแรก
        </button>
      </div>

      <div class="table-container" v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>ที่พัก</th>
              <th>โหมดจอง</th>
              <th>ถึงชุมชน</th>
              <th>คำขอรอตอบ</th>
              <th>รอเข้าพัก</th>
              <th>รอโอนเจ้าของ</th>
              <th>สถานะ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in stays" :key="s.id">
              <td>
                <div class="trip-cell">
                  <img :src="s.cover_image || '/images/placeholder.jpg'" class="trip-thumb" />
                  <div>
                    <span class="trip-name">{{ s.name }}</span>
                    <div class="muted-sub">
                      {{ s.type_label }}<span v-if="s.province"> · {{ s.province }}</span>
                      <span v-if="s.rating_count"> · ★ {{ s.rating_avg?.toFixed(1) }} ({{ s.rating_count }})</span>
                    </div>
                  </div>
                </div>
              </td>
              <td>{{ s.booking_mode === 'instant' ? 'จองทันที' : 'ส่งคำขอ' }}</td>
              <td>{{ (100 - s.commission_percent).toFixed(0) }}%</td>
              <td>
                <span v-if="s.open_requests_count" class="warn-pill">{{ s.open_requests_count }}</span>
                <span v-else class="muted-sub">—</span>
              </td>
              <td>{{ s.upcoming_count || '—' }}</td>
              <td>{{ s.pending_payout ? baht(s.pending_payout) : '—' }}</td>
              <td>
                <span class="status-badge" :class="statusClass(s.status)">{{ STATUS_LABEL[s.status] || s.status }}</span>
              </td>
              <td>
                <div class="action-btns">
                  <a v-if="s.status === 'active'" class="btn-icon" :href="'/stays/' + encodeURIComponent(s.slug)" target="_blank" title="ดูหน้าเว็บ">
                    <span class="material-symbols-rounded">open_in_new</span>
                  </a>
                  <button class="btn-icon" title="แก้ไข" @click="openEdit(s.id)">
                    <span class="material-symbols-rounded">edit</span>
                  </button>
                  <button class="btn-icon btn-danger" title="ลบ" @click="confirmDelete(s)">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ตัวแก้ไข -->
    <div class="modal-overlay" v-if="showForm" @click.self="closeForm">
      <div class="modal-card stay-modal">
        <div class="modal-header">
          <h3>{{ form.id ? form.name || 'แก้ไขที่พัก' : 'เพิ่มที่พัก' }}</h3>
          <button class="modal-close" @click="closeForm"><span class="material-symbols-rounded">close</span></button>
        </div>

        <div class="tab-row" v-if="form.id">
          <button v-for="t in TABS" :key="t.key" type="button" class="tab-btn" :class="{ active: tab === t.key }" @click="switchTab(t.key)">
            <span class="material-symbols-rounded">{{ t.icon }}</span>{{ t.label }}
          </button>
        </div>

        <!-- ── ข้อมูลที่พัก ── -->
        <form v-if="tab === 'info'" @submit.prevent="saveStay" class="modal-body">
          <div class="form-grid">
            <div class="form-group full-width">
              <label>ชื่อที่พัก *</label>
              <input v-model="form.name" required maxlength="160" placeholder="เช่น บ้านดินริมดอย" />
            </div>
            <div class="form-group">
              <label>ประเภท</label>
              <select v-model="form.type">
                <option v-for="t in options.types" :key="t.key" :value="t.key">{{ t.label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>ชุมชน / วิสาหกิจ</label>
              <input v-model="form.community_name" placeholder="เช่น ชุมชนบ้านแม่กลางหลวง" />
            </div>
            <div class="form-group">
              <label>จังหวัด</label>
              <input v-model="form.province" />
            </div>
            <div class="form-group">
              <label>อำเภอ</label>
              <input v-model="form.district" />
            </div>
            <div class="form-group full-width">
              <label>ที่อยู่ <span class="hint">(ลูกค้าเห็นหลังยืนยันการจองแล้วเท่านั้น)</span></label>
              <input v-model="form.address" />
            </div>
            <div class="form-group">
              <label>ละติจูด</label>
              <input v-model="form.latitude" type="number" step="any" />
            </div>
            <div class="form-group">
              <label>ลองจิจูด</label>
              <input v-model="form.longitude" type="number" step="any" />
            </div>
            <div class="form-group full-width">
              <label>ลิงก์ Google Maps</label>
              <input v-model="form.map_url" placeholder="https://maps.app.goo.gl/..." />
            </div>

            <div class="form-group full-width">
              <label>สรุปสั้น (ขึ้นบนการ์ด)</label>
              <textarea v-model="form.summary" rows="2" maxlength="500"></textarea>
            </div>
            <div class="form-group full-width">
              <label>รายละเอียดที่พัก</label>
              <textarea v-model="form.description" rows="5"></textarea>
            </div>
            <div class="form-group full-width">
              <label>เรื่องเล่าของชุมชน <span class="hint">(ใครเป็นเจ้าของ เงินไปช่วยอะไร — ลูกค้าอ่านแล้วอยากจอง)</span></label>
              <textarea v-model="form.community_story" rows="5"></textarea>
            </div>
            <div class="form-group full-width">
              <label>ไฮไลต์ <span class="hint">(บรรทัดละ 1 ข้อ)</span></label>
              <textarea v-model="highlightsText" rows="3"></textarea>
            </div>
            <div class="form-group full-width">
              <label>สิ่งอำนวยความสะดวก</label>
              <div class="chip-grid">
                <button v-for="a in options.amenities" :key="a.key" type="button" class="chip"
                  :class="{ 'chip--on': form.amenities.includes(a.key) }" @click="toggle(form.amenities, a.key)">
                  <span class="material-symbols-rounded">{{ a.icon }}</span>{{ a.label }}
                </button>
              </div>
            </div>
            <div class="form-group full-width">
              <label>กติกาที่พัก</label>
              <textarea v-model="form.house_rules" rows="3" placeholder="เช่น งดส่งเสียงดังหลัง 22:00 น."></textarea>
            </div>
            <div class="form-group">
              <label>เวลาเช็คอิน</label>
              <input v-model="form.check_in_time" type="time" />
            </div>
            <div class="form-group">
              <label>เวลาเช็คเอาต์</label>
              <input v-model="form.check_out_time" type="time" />
            </div>

            <div class="form-group full-width">
              <label>รูปหน้าปก</label>
              <div class="upload-row">
                <img v-if="form.cover_image" :src="form.cover_image" class="thumb" />
                <input v-model="form.cover_image" placeholder="https://..." />
                <label class="btn-secondary upload-btn">
                  <span class="material-symbols-rounded">upload</span> อัปโหลด
                  <input type="file" accept="image/*" hidden @change="uploadInto($event, url => form.cover_image = url)" />
                </label>
              </div>
            </div>
            <div class="form-group full-width">
              <label>อัลบั้มภาพ</label>
              <div class="gallery-grid">
                <div v-for="(url, i) in form.gallery" :key="url + i" class="gallery-item">
                  <img :src="url" />
                  <button type="button" class="gallery-remove" @click="form.gallery.splice(i, 1)">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
                <label class="gallery-add">
                  <span class="material-symbols-rounded">add_photo_alternate</span>
                  <input type="file" accept="image/*" multiple hidden @change="uploadInto($event, url => form.gallery.push(url))" />
                </label>
              </div>
              <p v-if="uploading" class="hint">กำลังอัปโหลด…</p>
            </div>

            <h4 class="section-title full-width">การจองและเงิน</h4>
            <div class="form-group">
              <label>โหมดการจอง</label>
              <select v-model="form.booking_mode">
                <option value="request">ส่งคำขอ — เจ้าของ/ทีมงานยืนยันห้องก่อนจ่าย</option>
                <option value="instant">จองทันที — มีปฏิทินห้องว่างแม่นยำแล้ว</option>
              </select>
            </div>
            <div class="form-group">
              <label>นโยบายยกเลิก</label>
              <select v-model="form.cancellation_policy">
                <option v-for="p in options.policies" :key="p.key" :value="p.key">{{ p.label }} — {{ p.lines[0] }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>ค่าคอมบริษัท (%)</label>
              <input v-model.number="form.commission_percent" type="number" min="0" max="50" step="0.5" />
              <p class="hint">ถึงมือชุมชน {{ (100 - Number(form.commission_percent || 0)).toFixed(1) }}% — ตัวเลขนี้โชว์ให้ลูกค้าเห็น</p>
            </div>
            <div class="form-group">
              <label>สถานะ</label>
              <select v-model="form.status">
                <option value="draft">ฉบับร่าง</option>
                <option value="active">เปิดขาย</option>
                <option value="paused">พักการขาย</option>
              </select>
            </div>

            <h4 class="section-title full-width">เจ้าของที่พัก</h4>
            <div class="form-group">
              <label>ชื่อเจ้าของ</label>
              <input v-model="form.host_name" />
            </div>
            <div class="form-group">
              <label>เบอร์โทร <span class="hint">(ลูกค้าเห็นหลังยืนยัน)</span></label>
              <input v-model="form.host_phone" />
            </div>
            <div class="form-group">
              <label>LINE ID</label>
              <input v-model="form.host_line_id" />
            </div>
            <div class="form-group">
              <label>บัญชีในระบบของเจ้าของ <span class="hint">(ไม่บังคับ — ได้แจ้งเตือน)</span></label>
              <div class="host-search">
                <input v-model="hostQuery" @input="searchHosts" :placeholder="form.host_user ? form.host_user.name + ' (' + (form.host_user.phone || form.host_user.email) + ')' : 'ค้นชื่อ/เบอร์/อีเมล'" />
                <button v-if="form.host_user_id" type="button" class="btn-icon" title="เลิกผูก" @click="form.host_user_id = null; form.host_user = null">
                  <span class="material-symbols-rounded">link_off</span>
                </button>
                <ul v-if="hostResults.length" class="host-results">
                  <li v-for="u in hostResults" :key="u.id" @click="pickHost(u)">{{ u.name }} · {{ u.phone || u.email }}</li>
                </ul>
              </div>
            </div>
            <div class="form-group">
              <label>ใบอนุญาต</label>
              <select v-model="form.license_type">
                <option :value="null">— ไม่ระบุ —</option>
                <option v-for="l in options.license_types" :key="l.key" :value="l.key">{{ l.label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>เลขที่ใบอนุญาต</label>
              <input v-model="form.license_no" />
            </div>
            <div class="form-group">
              <label>บัญชีรับเงิน: ธนาคาร</label>
              <input v-model="form.payout_account.bank" />
            </div>
            <div class="form-group">
              <label>เลขบัญชี / พร้อมเพย์</label>
              <input v-model="form.payout_account.number" />
            </div>
            <div class="form-group full-width">
              <label>ชื่อบัญชี</label>
              <input v-model="form.payout_account.name" />
            </div>

            <div v-if="form.host_url" class="form-group full-width host-link">
              <label>ลิงก์เจ้าของที่พัก <span class="hint">(ส่งทาง LINE — ไม่ต้องสมัคร ไม่ต้องลงแอป)</span></label>
              <div class="upload-row">
                <input :value="form.host_url" readonly />
                <button type="button" class="btn-secondary" @click="copy(form.host_url)">
                  <span class="material-symbols-rounded">content_copy</span> คัดลอก
                </button>
                <button type="button" class="btn-secondary" @click="regenerateToken">
                  <span class="material-symbols-rounded">autorenew</span> สร้างใหม่
                </button>
              </div>
            </div>

            <h4 class="section-title full-width">ทริปใกล้เคียง</h4>
            <div class="form-group full-width">
              <div v-for="(t, i) in form.trips" :key="t.id" class="trip-link-row">
                <span class="trip-name">{{ tripTitle(t.id) }}</span>
                <input v-model="t.distance_note" placeholder="เช่น 15 นาทีจากจุดเริ่มเดิน" />
                <button type="button" class="btn-icon btn-danger" @click="form.trips.splice(i, 1)">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
              <select @change="addTrip($event)">
                <option value="">+ ผูกทริป…</option>
                <option v-for="t in tripsNotLinked" :key="t.id" :value="t.id">{{ t.title }}</option>
              </select>
            </div>

            <div class="form-group full-width">
              <label>บันทึกภายใน</label>
              <textarea v-model="form.admin_note" rows="2"></textarea>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="closeForm">ปิด</button>
            <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'กำลังบันทึก…' : 'บันทึก' }}</button>
          </div>
        </form>

        <!-- ── ห้อง ── -->
        <div v-else-if="tab === 'rooms'" class="modal-body">
          <div v-for="room in form.rooms" :key="room.id" class="sub-card" :class="{ 'sub-card--off': !room.is_active }">
            <div class="form-grid">
              <div class="form-group full-width"><label>ชื่อห้อง</label><input v-model="room.name" /></div>
              <div class="form-group"><label>จำนวนห้อง</label><input v-model.number="room.units" type="number" min="1" /></div>
              <div class="form-group"><label>ราคาต่อคืน (บาท)</label><input v-model.number="room.price_per_night" type="number" min="0" /></div>
              <div class="form-group"><label>ราคาคืนศุกร์/เสาร์ <span class="hint">(ว่าง = เท่าวันธรรมดา)</span></label><input v-model="room.weekend_price" type="number" min="0" /></div>
              <div class="form-group"><label>คนที่รวมในราคา</label><input v-model.number="room.base_guests" type="number" min="1" /></div>
              <div class="form-group"><label>คนสูงสุดต่อห้อง</label><input v-model.number="room.max_guests" type="number" min="1" /></div>
              <div class="form-group"><label>เพิ่มคนละ/คืน (บาท)</label><input v-model.number="room.extra_guest_price" type="number" min="0" /></div>
              <div class="form-group full-width"><label>รายละเอียด</label><textarea v-model="room.description" rows="2"></textarea></div>
              <div class="form-group full-width">
                <label>รูปห้อง</label>
                <div class="gallery-grid">
                  <div v-for="(url, i) in room.photos_raw" :key="url + i" class="gallery-item">
                    <img :src="url" />
                    <button type="button" class="gallery-remove" @click="room.photos_raw.splice(i, 1)"><span class="material-symbols-rounded">close</span></button>
                  </div>
                  <label class="gallery-add">
                    <span class="material-symbols-rounded">add_photo_alternate</span>
                    <input type="file" accept="image/*" multiple hidden @change="uploadInto($event, url => room.photos_raw.push(url))" />
                  </label>
                </div>
              </div>
              <label class="check-row"><input type="checkbox" v-model="room.breakfast_included" /> รวมอาหารเช้า</label>
              <label class="check-row"><input type="checkbox" v-model="room.is_active" /> เปิดขายห้องนี้</label>
            </div>
            <div class="sub-actions">
              <button type="button" class="btn-icon btn-danger" title="ลบ" @click="deleteRoom(room)"><span class="material-symbols-rounded">delete</span></button>
              <button type="button" class="btn-primary" @click="saveRoom(room)">บันทึกห้อง</button>
            </div>
          </div>
          <button type="button" class="btn-secondary" @click="addRoom">
            <span class="material-symbols-rounded">add</span> เพิ่มประเภทห้อง
          </button>
        </div>

        <!-- ── กิจกรรมชุมชน ── -->
        <div v-else-if="tab === 'experiences'" class="modal-body">
          <p class="hint">กิจกรรมที่ลูกค้าจองพ่วงกับที่พักได้ เช่น ทำอาหารพื้นถิ่น ไกด์เดินป่าในชุมชน ทอผ้า — คิดราคาต่อคน รายได้เข้าเจ้าของตามสัดส่วนเดียวกับค่าห้อง</p>
          <div v-for="x in form.experiences" :key="x.id" class="sub-card" :class="{ 'sub-card--off': !x.is_active }">
            <div class="form-grid">
              <div class="form-group full-width"><label>ชื่อกิจกรรม</label><input v-model="x.title" /></div>
              <div class="form-group"><label>ราคาต่อคน (บาท)</label><input v-model.number="x.price_per_person" type="number" min="0" /></div>
              <div class="form-group"><label>ระยะเวลา</label><input v-model="x.duration_label" placeholder="เช่น 2 ชั่วโมง" /></div>
              <div class="form-group full-width"><label>รายละเอียด</label><textarea v-model="x.description" rows="2"></textarea></div>
              <div class="form-group full-width">
                <label>รูป</label>
                <div class="upload-row">
                  <img v-if="x.image_raw" :src="x.image_raw" class="thumb" />
                  <input v-model="x.image_raw" placeholder="https://..." />
                  <label class="btn-secondary upload-btn">
                    <span class="material-symbols-rounded">upload</span>
                    <input type="file" accept="image/*" hidden @change="uploadInto($event, url => x.image_raw = url)" />
                  </label>
                </div>
              </div>
              <label class="check-row"><input type="checkbox" v-model="x.is_active" /> เปิดให้จอง</label>
            </div>
            <div class="sub-actions">
              <button type="button" class="btn-icon btn-danger" @click="deleteExperience(x)"><span class="material-symbols-rounded">delete</span></button>
              <button type="button" class="btn-primary" @click="saveExperience(x)">บันทึก</button>
            </div>
          </div>
          <button type="button" class="btn-secondary" @click="addExperience">
            <span class="material-symbols-rounded">add</span> เพิ่มกิจกรรม
          </button>
        </div>

        <!-- ── ปฏิทิน ── -->
        <div v-else-if="tab === 'calendar'" class="modal-body">
          <StayCalendarEditor :load="loadCalendar" :save="saveNights" />
        </div>

        <!-- ── เงินและรีวิว ── -->
        <div v-else-if="tab === 'money'" class="modal-body">
          <div v-if="payouts" class="money">
            <div class="money-head">
              <div>
                <p class="hint">ยอดรอโอนให้เจ้าของ</p>
                <p class="money-big">{{ baht(payouts.pending_amount) }}</p>
                <p class="hint" v-if="payouts.payout_account?.number">
                  โอนเข้า {{ payouts.payout_account.bank }} {{ payouts.payout_account.number }} · {{ payouts.payout_account.name }}
                </p>
                <p class="hint warn" v-else>ยังไม่มีบัญชีรับเงิน — กรอกในแท็บข้อมูลก่อนโอน</p>
              </div>
              <div class="payout-form" v-if="payouts.pending_amount > 0">
                <input v-model="payoutRef" placeholder="เลขอ้างอิงการโอน" />
                <button class="btn-primary" :disabled="paying" @click="doPayout">บันทึกว่าโอนแล้ว</button>
              </div>
            </div>

            <table class="data-table" v-if="payouts.payable.length">
              <thead><tr><th>ใบจอง</th><th>แขก</th><th>วันที่</th><th>ยอดลูกค้า</th><th>ส่วนเจ้าของ</th></tr></thead>
              <tbody>
                <tr v-for="b in payouts.payable" :key="b.ref">
                  <td>{{ b.ref }}</td><td>{{ b.guest_name }}</td>
                  <td>{{ shortDate(b.check_in) }} – {{ shortDate(b.check_out) }}</td>
                  <td>{{ baht(b.total_amount) }}</td><td><strong>{{ baht(b.host_amount) }}</strong></td>
                </tr>
              </tbody>
            </table>

            <h4 class="section-title">ประวัติการโอน</h4>
            <p v-if="!payouts.history.length" class="hint">ยังไม่เคยโอน</p>
            <table class="data-table" v-else>
              <thead><tr><th>วันที่</th><th>ยอด</th><th>ใบจอง</th><th>อ้างอิง</th><th>โดย</th></tr></thead>
              <tbody>
                <tr v-for="p in payouts.history" :key="p.id">
                  <td>{{ shortDate(p.paid_at) }}</td><td>{{ baht(p.amount) }}</td><td>{{ p.booking_count }}</td>
                  <td>{{ p.reference || '—' }}</td><td>{{ p.paid_by || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <h4 class="section-title">รีวิว</h4>
          <p v-if="!form.reviews?.length" class="hint">ยังไม่มีรีวิว</p>
          <div v-for="r in form.reviews" :key="r.id" class="review-row" :class="{ 'review-row--hidden': r.is_hidden }">
            <div>
              <strong>{{ '★'.repeat(r.rating) }}</strong> <span class="hint">{{ r.author }} · {{ shortDate(r.created_at) }}</span>
              <p>{{ r.comment }}</p>
              <p v-if="r.host_reply" class="hint">เจ้าของตอบ: {{ r.host_reply }}</p>
            </div>
            <button class="btn-secondary" @click="toggleReview(r)">{{ r.is_hidden ? 'แสดง' : 'ซ่อน' }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';
import { uploadMedia } from '../../lib/mediaUpload';
import StayCalendarEditor from '../../components/StayCalendarEditor.vue';

const STATUS_LABEL = { draft: 'ฉบับร่าง', active: 'เปิดขาย', paused: 'พักการขาย' };
const TABS = [
  { key: 'info', label: 'ข้อมูล', icon: 'info' },
  { key: 'rooms', label: 'ห้อง', icon: 'bed' },
  { key: 'experiences', label: 'กิจกรรมชุมชน', icon: 'diversity_3' },
  { key: 'calendar', label: 'ปฏิทินห้องว่าง', icon: 'calendar_month' },
  { key: 'money', label: 'โอนเงิน & รีวิว', icon: 'payments' },
];

const toast = useToast();
const swal = useSwal();

const loading = ref(true);
const saving = ref(false);
const uploading = ref(false);
const stays = ref([]);
const trips = ref([]);
const options = ref({ types: [], license_types: [], policies: [], amenities: [] });
const showForm = ref(false);
const tab = ref('info');
const form = ref(emptyForm());
const payouts = ref(null);
const payoutRef = ref('');
const paying = ref(false);
const hostQuery = ref('');
const hostResults = ref([]);

const highlightsText = computed({
  get: () => (form.value.highlights || []).join('\n'),
  set: (v) => { form.value.highlights = v.split('\n').map(l => l.trim()).filter(Boolean); },
});

const tripsNotLinked = computed(() => {
  const linked = new Set(form.value.trips.map(t => t.id));
  return trips.value.filter(t => !linked.has(t.id));
});

function emptyForm() {
  return {
    id: null, name: '', type: 'homestay', community_name: '', province: '', district: '', address: '',
    latitude: '', longitude: '', map_url: '', summary: '', description: '', community_story: '',
    highlights: [], amenities: [], house_rules: '', check_in_time: '14:00', check_out_time: '11:00',
    cover_image: '', gallery: [], booking_mode: 'request', cancellation_policy: 'moderate',
    commission_percent: options.value?.default_commission_percent ?? 12, status: 'draft',
    host_user_id: null, host_user: null, host_name: '', host_phone: '', host_line_id: '',
    license_type: null, license_no: '', payout_account: { bank: '', number: '', name: '' },
    admin_note: '', trips: [], rooms: [], experiences: [], reviews: [], host_url: null,
  };
}

function baht(n) {
  return '฿' + Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });
}

function shortDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'Asia/Bangkok' });
}

function statusClass(status) {
  return status === 'active' ? 'status-active' : 'status-inactive';
}

function toggle(list, key) {
  const i = list.indexOf(key);
  if (i >= 0) list.splice(i, 1); else list.push(key);
}

function tripTitle(id) {
  return trips.value.find(t => t.id === id)?.title || `ทริป #${id}`;
}

function addTrip(event) {
  const id = Number(event.target.value);
  if (id) form.value.trips.push({ id, distance_note: '' });
  event.target.value = '';
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast.success('คัดลอกแล้ว');
  } catch {
    toast.error('คัดลอกไม่สำเร็จ');
  }
}

async function uploadInto(event, apply) {
  const files = Array.from(event.target.files || []);
  if (!files.length) return;
  uploading.value = true;
  try {
    for (const file of files) apply(await uploadMedia(file));
  } catch (err) {
    toast.error(err.response?.data?.message || 'อัปโหลดไม่สำเร็จ');
  } finally {
    uploading.value = false;
    event.target.value = '';
  }
}

let hostTimer = null;
function searchHosts() {
  clearTimeout(hostTimer);
  const q = hostQuery.value.trim();
  if (q.length < 2) { hostResults.value = []; return; }
  hostTimer = setTimeout(async () => {
    try {
      const res = await api.get('/admin/partners/candidates', { params: { q } });
      hostResults.value = res.data?.data || [];
    } catch {
      hostResults.value = [];
    }
  }, 300);
}

function pickHost(user) {
  form.value.host_user_id = user.id;
  form.value.host_user = user;
  if (!form.value.host_name) form.value.host_name = user.name;
  if (!form.value.host_phone) form.value.host_phone = user.phone || '';
  hostQuery.value = '';
  hostResults.value = [];
}

function openCreate() {
  form.value = emptyForm();
  tab.value = 'info';
  showForm.value = true;
}

function hydrate(data) {
  form.value = {
    ...emptyForm(),
    ...data,
    highlights: data.highlights || [],
    amenities: data.amenities || [],
    gallery: data.gallery || [],
    payout_account: { bank: '', number: '', name: '', ...(data.payout_account || {}) },
    trips: (data.trips || []).map(t => ({ id: t.id, distance_note: t.distance_note || '' })),
    rooms: (data.rooms || []).map(r => ({ ...r, weekend_price: r.weekend_price ?? '', photos_raw: [...(r.photos_raw || [])] })),
    experiences: (data.experiences || []).map(x => ({ ...x, image_raw: x.image_raw || '' })),
  };
}

async function openEdit(id) {
  try {
    const res = await api.get(`/admin/stays/${id}`);
    hydrate(res.data.data);
    tab.value = 'info';
    showForm.value = true;
  } catch (err) {
    toast.error(err.response?.data?.message || 'โหลดข้อมูลไม่สำเร็จ');
  }
}

function closeForm() {
  showForm.value = false;
  payouts.value = null;
  fetchStays();
}

async function switchTab(key) {
  tab.value = key;
  if (key === 'money') await loadPayouts();
}

function stayPayload() {
  const f = form.value;
  const body = {};
  ['name', 'type', 'community_name', 'province', 'district', 'address', 'map_url', 'summary', 'description',
    'community_story', 'house_rules', 'check_in_time', 'check_out_time', 'cover_image', 'booking_mode',
    'cancellation_policy', 'status', 'host_user_id', 'host_name', 'host_phone', 'host_line_id', 'license_type',
    'license_no', 'admin_note'].forEach((k) => { body[k] = f[k] === '' ? null : f[k]; });
  body.latitude = f.latitude === '' || f.latitude === null ? null : Number(f.latitude);
  body.longitude = f.longitude === '' || f.longitude === null ? null : Number(f.longitude);
  body.commission_percent = Number(f.commission_percent || 0);
  body.highlights = f.highlights;
  body.amenities = f.amenities;
  body.gallery = f.gallery;
  body.trips = f.trips;
  const acc = f.payout_account || {};
  body.payout_account = acc.bank || acc.number || acc.name ? acc : null;
  return body;
}

async function saveStay() {
  saving.value = true;
  try {
    const res = form.value.id
      ? await api.put(`/admin/stays/${form.value.id}`, stayPayload())
      : await api.post('/admin/stays', stayPayload());
    const created = !form.value.id;
    hydrate(res.data.data);
    toast.success(res.data.message || 'บันทึกแล้ว');
    // สร้างใหม่ — พาไปเพิ่มห้องต่อเลย เพราะเปิดขายไม่ได้จนกว่าจะมีห้อง
    if (created) tab.value = 'rooms';
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

async function regenerateToken() {
  const ok = await swal.confirm({ title: 'สร้างลิงก์ใหม่?', text: 'ลิงก์เดิมที่ส่งให้เจ้าของจะใช้ไม่ได้ทันที', confirmText: 'สร้างใหม่' });
  if (!ok.isConfirmed) return;
  try {
    const res = await api.post(`/admin/stays/${form.value.id}/host-token`);
    form.value.host_url = res.data.data.host_url;
    toast.success(res.data.message);
  } catch (err) {
    toast.error(err.response?.data?.message || 'ไม่สำเร็จ');
  }
}

function addRoom() {
  form.value.rooms.push({ id: null, name: '', units: 1, base_guests: 2, max_guests: 2, price_per_night: 0, weekend_price: '', extra_guest_price: 0, description: '', breakfast_included: false, is_active: true, photos_raw: [] });
}

async function saveRoom(room) {
  const body = {
    name: room.name, units: room.units, base_guests: room.base_guests, max_guests: room.max_guests,
    price_per_night: room.price_per_night, weekend_price: room.weekend_price === '' ? null : Number(room.weekend_price),
    extra_guest_price: room.extra_guest_price || 0, description: room.description || null,
    breakfast_included: !!room.breakfast_included, is_active: !!room.is_active, photos: room.photos_raw,
  };
  try {
    const res = room.id
      ? await api.put(`/admin/stay-rooms/${room.id}`, body)
      : await api.post(`/admin/stays/${form.value.id}/rooms`, body);
    room.id = res.data.data.id;
    toast.success(res.data.message);
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกห้องไม่สำเร็จ');
  }
}

async function deleteRoom(room) {
  if (!room.id) { form.value.rooms.splice(form.value.rooms.indexOf(room), 1); return; }
  const ok = await swal.confirm({ title: `ลบ "${room.name}"?`, text: 'ห้องที่มีประวัติการจองจะถูกปิดการขายแทน', confirmText: 'ลบ' });
  if (!ok.isConfirmed) return;
  try {
    const res = await api.delete(`/admin/stay-rooms/${room.id}`);
    toast.success(res.data.message);
    await openEditKeepTab('rooms');
  } catch (err) {
    toast.error(err.response?.data?.message || 'ลบไม่สำเร็จ');
  }
}

function addExperience() {
  form.value.experiences.push({ id: null, title: '', price_per_person: 0, duration_label: '', description: '', image_raw: '', is_active: true });
}

async function saveExperience(x) {
  const body = {
    title: x.title, price_per_person: x.price_per_person || 0, duration_label: x.duration_label || null,
    description: x.description || null, image: x.image_raw || null, is_active: !!x.is_active,
  };
  try {
    const res = x.id
      ? await api.put(`/admin/stay-experiences/${x.id}`, body)
      : await api.post(`/admin/stays/${form.value.id}/experiences`, body);
    x.id = res.data.data.id;
    toast.success(res.data.message);
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  }
}

async function deleteExperience(x) {
  if (!x.id) { form.value.experiences.splice(form.value.experiences.indexOf(x), 1); return; }
  const ok = await swal.confirm({ title: `ลบ "${x.title}"?`, confirmText: 'ลบ' });
  if (!ok.isConfirmed) return;
  try {
    await api.delete(`/admin/stay-experiences/${x.id}`);
    form.value.experiences.splice(form.value.experiences.indexOf(x), 1);
  } catch (err) {
    toast.error(err.response?.data?.message || 'ลบไม่สำเร็จ');
  }
}

async function openEditKeepTab(key) {
  const res = await api.get(`/admin/stays/${form.value.id}`);
  hydrate(res.data.data);
  tab.value = key;
}

async function loadCalendar(month) {
  const res = await api.get(`/admin/stays/${form.value.id}/calendar`, { params: { month } });
  return res.data.data;
}

async function saveNights(body) {
  const res = await api.post(`/admin/stays/${form.value.id}/nights`, body);
  return res.data;
}

async function loadPayouts() {
  try {
    const res = await api.get(`/admin/stays/${form.value.id}/payouts`);
    payouts.value = res.data.data;
  } catch (err) {
    toast.error(err.response?.data?.message || 'โหลดยอดไม่สำเร็จ');
  }
}

async function doPayout() {
  const ok = await swal.confirm({
    title: `บันทึกว่าโอน ${baht(payouts.value.pending_amount)} แล้ว?`,
    text: 'ระบบจะแจ้งเจ้าของที่พัก และปิดยอดใบจองทั้งหมดในรอบนี้',
    confirmText: 'โอนแล้ว',
  });
  if (!ok.isConfirmed) return;
  paying.value = true;
  try {
    const res = await api.post(`/admin/stays/${form.value.id}/payouts`, { reference: payoutRef.value || null });
    toast.success(res.data.message);
    payoutRef.value = '';
    await loadPayouts();
  } catch (err) {
    toast.error(err.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    paying.value = false;
  }
}

async function toggleReview(review) {
  try {
    const res = await api.post(`/admin/stay-reviews/${review.id}/toggle`);
    review.is_hidden = res.data.data.is_hidden;
  } catch (err) {
    toast.error(err.response?.data?.message || 'ไม่สำเร็จ');
  }
}

async function confirmDelete(stay) {
  const ok = await swal.confirm({ title: `ลบ "${stay.name}"?`, text: 'ถ้ามีประวัติการจอง ระบบจะพักการขายแทนการลบ', confirmText: 'ลบ' });
  if (!ok.isConfirmed) return;
  try {
    const res = await api.delete(`/admin/stays/${stay.id}`);
    toast.success(res.data.message);
    await fetchStays();
  } catch (err) {
    toast.error(err.response?.data?.message || 'ลบไม่สำเร็จ');
  }
}

async function fetchStays() {
  loading.value = true;
  try {
    const res = await api.get('/admin/stays');
    stays.value = res.data?.data?.stays || [];
    options.value = res.data?.data?.options || options.value;
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  await fetchStays();
  try {
    const res = await api.get('/trips', { params: { per_page: 200 } });
    trips.value = (res.data?.data || []).map(t => ({ id: t.id, title: t.title }));
  } catch {
    trips.value = [];
  }
});
</script>

<style scoped>
.header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.stay-modal { max-width: 860px; width: 100%; }
.guide-card { padding: 18px; margin-bottom: 16px; }
.guide-card h3 { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #111827; margin: 0 0 10px; }
.guide-card h3 .material-symbols-rounded { color: #006565; }
.guide-card ol { margin: 0; padding-left: 20px; display: grid; gap: 6px; }
.guide-card li { font-size: 13px; color: #6b7280; line-height: 1.6; }
.guide-card strong { color: #111827; }
.guide-foot { font-size: 12px; color: #9ca3af; margin: 12px 0 0; }
.guide-foot a { color: #006565; font-weight: 700; }
.hint { font-size: 12px; font-weight: 400; color: #9ca3af; }
.hint.warn { color: #b54708; }
.warn-pill { display: inline-flex; font-size: 12px; font-weight: 700; color: #b54708; background: #fffaeb; border: 1px solid #fedf89; border-radius: 999px; padding: 2px 10px; }
.tab-row { display: flex; gap: 4px; padding: 0 20px; border-bottom: 1px solid #f3f4f6; overflow-x: auto; }
.tab-btn { display: inline-flex; align-items: center; gap: 6px; padding: 12px 12px; font-size: 13px; font-weight: 700; color: #6b7280; border: 0; background: none; border-bottom: 2px solid transparent; cursor: pointer; white-space: nowrap; }
.tab-btn .material-symbols-rounded { font-size: 18px; }
.tab-btn.active { color: #006565; border-bottom-color: #006565; }
.section-title { font-size: 13px; font-weight: 800; color: #111827; margin: 18px 0 4px; grid-column: 1 / -1; }
.chip-grid { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; font-size: 12px; font-weight: 700; color: #6b7280; cursor: pointer; }
.chip .material-symbols-rounded { font-size: 16px; }
.chip--on { background: #006565; border-color: #006565; color: #fff; }
.upload-row { display: flex; gap: 8px; align-items: center; }
.upload-row input { flex: 1; min-width: 0; }
.upload-btn { cursor: pointer; white-space: nowrap; }
.thumb { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; }
.gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 8px; }
.gallery-item { position: relative; aspect-ratio: 1; }
.gallery-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
.gallery-remove { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 999px; border: 0; background: rgba(0,0,0,.6); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.gallery-remove .material-symbols-rounded { font-size: 14px; }
.gallery-add { aspect-ratio: 1; border: 2px dashed #e5e7eb; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #9ca3af; cursor: pointer; }
.host-search { position: relative; display: flex; gap: 6px; }
.host-search input { flex: 1; }
.host-results { position: absolute; top: 100%; left: 0; right: 0; z-index: 5; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; margin: 4px 0 0; padding: 4px; list-style: none; max-height: 220px; overflow: auto; }
.host-results li { padding: 8px 10px; border-radius: 8px; font-size: 13px; cursor: pointer; }
.host-results li:hover { background: #f3f4f6; }
.host-link input { background: #f9fafb; }
.trip-link-row { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
.trip-link-row .trip-name { flex: 0 0 40%; font-size: 13px; font-weight: 700; }
.trip-link-row input { flex: 1; min-width: 0; }
.sub-card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px; margin-bottom: 12px; }
.sub-card--off { opacity: .6; }
.sub-actions { display: flex; justify-content: space-between; margin-top: 10px; }
.check-row { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: #374151; }
.money-head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 12px; }
.money-big { font-size: 28px; font-weight: 900; color: #006565; margin: 2px 0; }
.payout-form { display: flex; gap: 8px; flex-wrap: wrap; }
.review-row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
.review-row p { margin: 4px 0 0; font-size: 13px; }
.review-row--hidden { opacity: .5; }
@media (max-width: 640px) {
  .trip-link-row { flex-wrap: wrap; }
  .trip-link-row .trip-name { flex-basis: 100%; }
}
</style>
