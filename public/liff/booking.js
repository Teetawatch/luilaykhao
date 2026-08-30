/* Luilaykhao LIFF — ขั้นตอนการจอง
 *
 * โหลดต่อจาก app.js และใช้ helper ตัวเดียวกัน (api/el/esc/baht/thaiDate/render/appbar/mmss)
 *
 * ตั้งใจให้ทำได้เท่าหน้าจองบนเว็บ (resources/js/pages/BookingPage.vue) — กติกาการ
 * ตรวจข้อมูลผู้เดินทาง สูตรราคา และรูปร่าง payload ต้องตรงกับที่นั่นเสมอ ถ้าที่นั่น
 * เปลี่ยน ที่นี่ต้องเปลี่ยนตาม ไม่งั้นลูกค้าที่จองผ่าน LINE จะได้ใบจองคนละแบบ
 *
 * ขั้นตอน: (1) รถ+ที่นั่ง/จำนวนคน+จุดรับ → (2) ข้อมูลผู้เดินทาง → (3) สรุป+ยืนยัน
 */

let bk = null; // ใบจองที่กำลังกรอกอยู่

const MAX_SEATS_PER_BOOKING = 10;
const MAX_JOIN_PAX = 50;
const ADDON_MAX_QTY = 20;
const RENTAL_MAX_QTY = 20;
const MAX_DOC_FILES = 5;
const MAX_DOC_MB = 10;

/* =========================== เริ่มการจอง ============================ */

async function startBooking(trip, scheduleId, opts = {}) {
  loading('กำลังเตรียมข้อมูลการจอง…');
  try {
    const schedule = (await api('/schedules/' + scheduleId)).data;
    bk = {
      trip,
      schedule,
      seatData: null,
      // จอยทริป = ไปกับรอบนี้แต่ไม่ใช้ที่นั่งบนรถ ไม่มีจุดรับ ไม่มีผังที่นั่ง
      joinTrip: !!opts.joinTrip && !!schedule.join_trip_enabled,
      vehicleOptionId: null,
      selected: [],
      count: 1,
      pickupId: null,
      customPickup: null, // { label, lat, lng, note }
      passengers: [],
      docs: [], // index ผู้เดินทาง -> { requirement_key: File[] }
      addons: new Map(), // index ใน must_know.items -> จำนวน
      rentals: new Map(), // index ใน trip.rental_items -> จำนวนชิ้น
      promoInput: '',
      promoCode: '',
      promoData: null,
      promoError: '',
      bookingFor: 'self',
      isGroup: false,
      groupName: '',
      groupNotes: '',
      lockExpiresAt: null,
      attempted: false, // กด "ถัดไป" แล้วหรือยัง — คุมว่าจะขึ้นสีแดงตอนไหน
      // ข้อมูลจากร่างที่กู้มา — เก็บแยกไว้เพราะตอนกู้ยังไม่รู้ว่าจะมีกี่คน
      // (ยังไม่ได้เลือกที่นั่ง) ถ้าเอาไปใส่ passengers ตรง ๆ จะถูกตัดทิ้งทันที
      draftPassengers: [],
    };
    bk.vehicleOptionId = defaultVehicleOptionId();
    await loadSeatData();
    syncPassengerCount();
    await restoreDraft();
    renderSeatStep();
  } catch (e) {
    errorScreen(e.message, () => startBooking(trip, scheduleId, opts));
  }
}

/* ========================= สถานะที่คำนวณได้ ========================= */

const isFlightRound = () => bk.schedule.transport_type === 'flight';
const isInternationalTrip = () => bk.trip?.is_international === true;
const isDivingTrip = () => ['diving', 'snorkeling'].includes(bk.trip?.type);

// ประเภทรถของรอบ — จอยทริปกับรอบที่บินไปไม่มีให้เลือก
function vehicleOptions() {
  if (bk.joinTrip || isFlightRound()) return [];
  return (bk.schedule.vehicle_options || []).filter((o) => o && o.is_active !== false);
}

// เริ่มที่คันราคาปกติ (ส่วนต่าง 0) — กติกาเดียวกับที่หลังบ้านใช้เมื่อไม่ได้ระบุคันมา
function defaultVehicleOptionId() {
  const options = vehicleOptions();
  if (!options.length) return null;
  const usable = options.filter((o) => !o.is_sold_out);
  const pool = usable.length ? usable : options;
  return (pool.find((o) => Number(o.price_adjustment || 0) === 0) || pool[0]).id;
}

function selectedVehicleOption() {
  if (bk.vehicleOptionId == null) return null;
  return vehicleOptions().find((o) => o.id === bk.vehicleOptionId) || null;
}

const vehicleAdjustment = () => Number(selectedVehicleOption()?.price_adjustment || 0);

// คันรองบางคันไม่มีผังของตัวเอง — ทีมงานจัดที่นั่งหน้างาน (เซิร์ฟเวอร์เป็นคนตัดสิน)
function vehicleAllowsSeatMap() {
  const option = selectedVehicleOption();
  return !option || option.uses_seat_map !== false;
}

function hasSeatMap() {
  if (bk.joinTrip) return false;
  if (!vehicleAllowsSeatMap()) return false;
  return bk.seatData?.has_seat_map !== false;
}

// จุดขึ้นรถของรอบ — จอยทริปเดินทางมาเจอกันเอง รอบที่บินไปนัดที่สนามบิน
function pickupPoints() {
  if (bk.joinTrip || isFlightRound()) return [];
  return bk.schedule.pickup_points || [];
}

function paxCount() {
  return hasSeatMap() ? bk.selected.length : bk.count;
}

function maxPassengers() {
  if (bk.joinTrip) {
    const left = bk.schedule.join_trip_available_seats;
    return left == null ? MAX_JOIN_PAX : Math.max(1, Math.min(left, MAX_JOIN_PAX));
  }
  const seats = bk.seatData?.available_seats;
  const bookable = bk.schedule.bookable_seats ?? bk.schedule.available_seats ?? seats;
  const limit = bookable != null ? bookable : MAX_SEATS_PER_BOOKING;
  return Math.max(1, Math.min(limit, MAX_SEATS_PER_BOOKING));
}

const maxSelectable = maxPassengers;

async function loadSeatData() {
  if (bk.joinTrip) {
    bk.seatData = { has_seat_map: false, seat_selection_disabled_reason: '' };
    bk.selected = [];
    return;
  }
  const query = bk.vehicleOptionId ? '?vehicle_option_id=' + bk.vehicleOptionId : '';
  bk.seatData = (await api('/schedules/' + bk.schedule.id + '/seats' + query)).data;
  // ที่นั่งผูกกับคัน — เปลี่ยนคันแล้วที่เลือกไว้ใช้ต่อไม่ได้
  bk.selected = [];
  bk.count = hasSeatMap() ? 0 : Math.min(Math.max(bk.count || 1, 1), maxPassengers());
}

/* --------------------------- ราคา --------------------------- */

const basePrice = () => Number(bk.schedule.price || 0);

// ระยะทางเส้นตรง (กม.) — ใช้จัดอันดับว่าจุดรับไหนใกล้หมุดกว่ากัน
function distanceKm(lat1, lng1, lat2, lng2) {
  const toRad = (deg) => (deg * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat / 2) ** 2
    + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(a)));
}

/** จุดรับที่ใกล้หมุดที่ลูกค้าปักที่สุด — คู่กับ CustomPickupPricing ฝั่ง Laravel */
function nearestPickupPoint() {
  const cp = bk.customPickup;
  if (!cp || cp.lat == null || cp.lng == null) return null;
  let nearest = null;
  let best = null;
  for (const pt of pickupPoints()) {
    if (pt.latitude == null || pt.longitude == null) continue;
    const d = distanceKm(Number(cp.lat), Number(cp.lng), Number(pt.latitude), Number(pt.longitude));
    if (best === null || d < best) { nearest = pt; best = d; }
  }
  return nearest;
}

// ปักหมุดเอง = ไม่มีจุดรับตายตัว จึงคิดเท่าราคาโซนที่ใกล้ที่สุด (ขั้นต่ำ = ราคารอบ)
function customPickupPrice() {
  return Math.max(basePrice(), Number(nearestPickupPoint()?.price || 0));
}

/** ราคาตั๋วต่อคนของผู้เดินทางคนหนึ่ง — ส่วนต่างของรถบวกท้าย ไม่ทับราคาโซน */
function passengerTicketPrice(passenger) {
  if (bk.joinTrip) return Number(bk.schedule.join_trip_price || bk.schedule.price || 0);

  if (bk.customPickup) return customPickupPrice() + vehicleAdjustment();

  const points = pickupPoints();
  if (points.length && passenger?.pickup_point_id) {
    const point = points.find((p) => p.id === passenger.pickup_point_id);
    if (point) return Number(point.price) + vehicleAdjustment();
  }
  return basePrice() + vehicleAdjustment();
}

function passengersSubtotal() {
  const n = paxCount();
  if (!bk.passengers.length) return passengerTicketPrice(null) * n;
  return bk.passengers.slice(0, n).reduce((sum, p) => sum + passengerTicketPrice(p), 0);
}

function subtotalAmount() {
  return passengersSubtotal() + addonsTotal() + rentalsTotal();
}

function discountAmount() {
  if (!bk.promoData) return 0;
  if (bk.promoData.type === 'percent') return subtotalAmount() * (Number(bk.promoData.value) / 100);
  return Number(bk.promoData.value || 0);
}

function estimateTotal() {
  return Math.max(0, subtotalAmount() - discountAmount());
}

/* ===================== ขั้นที่ 1: รถ / ที่นั่ง / จุดรับ ===================== */

function stepDots(active) {
  return el(`<div class="step-dots">
    <span class="${active === 1 ? 'on' : ''}"></span>
    <span class="${active === 2 ? 'on' : ''}"></span>
    <span class="${active === 3 ? 'on' : ''}"></span>
  </div>`);
}

function renderSeatStep() {
  const node = el(`<div></div>`);
  node.appendChild(appbar(
    hasSeatMap() ? 'เลือกที่นั่ง' : 'จำนวนผู้เดินทาง',
    () => leaveBooking(),
  ));
  const content = el(`<div class="content"></div>`);
  content.appendChild(stepDots(1));

  if (bk.joinTrip) {
    content.appendChild(el(`<div class="banner info">🎒 จอยทริป — เดินทางไปเจอกันที่จุดนัดหมายเอง ไม่ใช้ที่นั่งบนรถของทีมงาน</div>`));
  }

  const vehicleBlock = buildVehicleOptions();
  if (vehicleBlock) content.appendChild(vehicleBlock);

  if (hasSeatMap()) {
    content.appendChild(buildSeatMap());
    const legend = el(`<div class="legend">
      <span><i class="sw avail"></i> ว่าง</span>
      <span><i class="sw sel"></i> ที่เลือก</span>
      <span><i class="sw taken"></i> ไม่ว่าง</span>
    </div>`);
    content.appendChild(legend);
    // ผังไม่อัปเดตสดเอง — คนอื่นจองระหว่างที่เปิดค้างไว้ได้ ต้องมีทางดึงใหม่
    const refresh = el(`<button class="btn secondary" style="margin-bottom:4px">รีเฟรชผังที่นั่ง</button>`);
    refresh.onclick = async () => {
      refresh.disabled = true;
      refresh.textContent = 'กำลังโหลด…';
      try {
        await loadSeatData();
        syncPassengerCount();
        renderSeatStep();
      } catch (e) {
        refresh.disabled = false;
        refresh.textContent = 'รีเฟรชผังที่นั่ง';
        alert(e.message);
      }
    };
    content.appendChild(refresh);
  } else {
    content.appendChild(buildPaxStepper());
  }

  // จุดนัดพบของรอบที่บินไป (ไม่มีรถวิ่งรับ)
  if (isFlightRound() && !bk.joinTrip) {
    const plan = bk.schedule.flight_plan || {};
    const meetingPoint = (plan.meeting_point || '').trim();
    const meetingTime = (plan.meeting_time || '').trim();
    content.appendChild(el(`<div class="section-heading">จุดนัดพบ</div>`));
    content.appendChild(el(`<p class="muted">${esc(
      meetingPoint
        ? `${meetingTime ? `นัดพบ ${meetingTime} น. · ` : ''}${meetingPoint}`
        : 'รอบนี้เดินทางโดยเครื่องบิน ทีมงานจะแจ้งจุดนัดพบและเวลาที่สนามบินให้ก่อนวันเดินทาง'
    )}</p>`));
  }

  const points = pickupPoints();
  if (points.length) {
    content.appendChild(el(`<div class="section-heading">จุดขึ้นรถ</div>`));
    const list = el(`<div></div>`);
    points.forEach((p) => list.appendChild(
      pickupOption(p.id, p.pickup_location || p.region_label || 'จุดรับ', p.price, p.pickup_time)
    ));
    content.appendChild(list);
    content.appendChild(buildCustomPickupBlock());
    content.appendChild(el(`<div id="pickupVehicleGuide"></div>`));
    loadVehicleClasses().then(renderPickupVehicleGuide);
  }

  node.appendChild(content);

  const summary = el(`<div class="sticky-cta">
    <div class="cta-row"><span class="muted" id="selCount">ยังไม่ได้เลือกที่นั่ง</span><span class="price" id="estTotal"></span></div>
    <button class="btn" id="next" disabled>ถัดไป</button>
  </div>`);
  node.appendChild(summary);
  render(node);

  refreshSeatStep();
  summary.querySelector('#next').onclick = proceedToPassengers;
}

// ออกจากขั้นตอนการจอง — ที่นั่งที่ล็อกไว้ต้องถูกคืนก่อน ไม่ใช่ค้างจนหมดอายุเอง
async function leaveBooking() {
  await releaseSeatLock();
  showTrip(bk.trip.slug);
}

async function releaseSeatLock() {
  if (!bk?.lockExpiresAt || !bk.selected.length) return;
  stopLockCountdown();
  const seatIds = [...bk.selected];
  bk.lockExpiresAt = null;
  try {
    await api('/schedules/' + bk.schedule.id + '/seats/lock', {
      method: 'DELETE',
      body: { seat_ids: seatIds, vehicle_option_id: bk.vehicleOptionId },
    });
  } catch (_) { /* ปลดไม่ได้ก็ปล่อยให้หมดอายุเอง ไม่ใช่เรื่องที่ลูกค้าต้องรู้ */ }
}

/* --------- ประเภทรถ --------- */

function buildVehicleOptions() {
  const options = vehicleOptions();
  if (options.length < 2) return null;

  const wrap = el(`<div></div>`);
  wrap.appendChild(el(`<div class="section-heading">เลือกประเภทรถ</div>`));
  options.forEach((o) => {
    const diff = Number(o.price_adjustment || 0);
    const diffLabel = diff === 0
      ? 'ราคาปกติ'
      : (diff > 0 ? '+' + baht(diff) : '-' + baht(Math.abs(diff))) + ' / คน';
    const seatsLabel = o.available_seats != null ? ' · เหลือ ' + o.available_seats + ' ที่' : '';
    const opt = el(`<label class="pick${o.id === bk.vehicleOptionId ? ' on' : ''}">
      <input type="radio" name="vehopt" ${o.id === bk.vehicleOptionId ? 'checked' : ''} ${o.is_sold_out ? 'disabled' : ''}>
      ${o.image_url ? `<div class="pick-thumb"><img src="${esc(o.image_url)}" alt="" loading="lazy"></div>` : ''}
      <div class="pick-body">
        <div class="pick-name">${esc(o.label)}${o.is_sold_out ? ' <span class="tag warn">เต็มแล้ว</span>' : ''}</div>
        <div class="pick-sub">${esc(diffLabel)}${esc(seatsLabel)}${o.note ? ' · ' + esc(o.note) : ''}</div>
      </div>
    </label>`);
    opt.querySelector('input').onchange = () => switchVehicleOption(o.id);
    wrap.appendChild(opt);
  });
  return wrap;
}

async function switchVehicleOption(id) {
  if (id === bk.vehicleOptionId) return;
  // ที่นั่งเป็นของคันเดิม ต้องคืนก่อนดึงผังของคันใหม่
  await releaseSeatLock();
  bk.vehicleOptionId = id;
  loading('กำลังโหลดผังที่นั่ง…');
  try {
    await loadSeatData();
    syncPassengerCount();
  } catch (e) {
    return errorScreen(e.message, () => startBooking(bk.trip, bk.schedule.id, { joinTrip: bk.joinTrip }));
  }
  renderSeatStep();
}

/* --------- ผังที่นั่งจริงของคัน (โครงเดียวกับ SeatMap.vue บนเว็บ) --------- */

function seatById(id) {
  return (bk.seatData.seats || []).find((s) => s.id === id) || null;
}

function seatAvailable(seat) {
  if (!seat) return false;
  if (seat.locked_by_current_user) return true; // ล็อกของตัวเอง เลือกซ้ำได้
  return seat.status !== 'booked' && seat.status !== 'locked';
}

/**
 * แปลง rows × columns เป็นแถวจริงของรถ
 *
 * `columns` เป็นรายชื่อคอลัมน์เรียงจากซ้ายไปขวา โดยสตริงว่างคือ "ทางเดิน"
 * ที่นั่งคู่คนขับ (front_seat) ถูกวาดแยกไว้ที่หัวรถ และแถวหลังที่ระบุใน
 * last_row_center นั่งเรียงกลางไม่มีทางเดินคั่น
 */
function buildSeatRows() {
  const data = bk.seatData;
  const rows = Number(data.rows || 0);
  const columns = data.columns || [];
  const frontSeat = data.front_seat || null;
  const center = new Set(data.last_row_center || []);
  const result = [];

  for (let r = 1; r <= rows; r++) {
    const left = [], right = [], mid = [];
    let hasAisle = false, inRight = false;
    for (const col of columns) {
      if (col === '') { hasAisle = true; inRight = true; continue; }
      const id = col + r;
      if (id === frontSeat) continue;
      if (!seatById(id)) continue;
      if (center.has(id)) mid.push(id);
      else if (inRight) right.push(id);
      else left.push(id);
    }
    if (!left.length && !right.length && !mid.length) continue;
    result.push({ row: r, left, mid, right, hasAisle: hasAisle && right.length > 0 });
  }
  return result;
}

function buildSeatMap() {
  const data = bk.seatData;
  const seats = data.seats || [];
  const wrap = el(`<div class="seatmap ${data.layout_kind === 'bus' ? 'bus' : 'van'}"></div>`);

  if (!seats.length) {
    wrap.appendChild(el(`<p class="muted center" style="padding:12px 0">ไม่พบผังที่นั่งของรอบนี้</p>`));
    return wrap;
  }

  // หัวรถ: ที่นั่งคู่คนขับ (ถ้ามี) · ป้ายหน้ารถ · คนขับ
  const head = el(`<div class="seat-head"></div>`);
  const frontSeat = data.front_seat ? seatById(data.front_seat) : null;
  head.appendChild(frontSeat ? seatCell(frontSeat) : el(`<div class="seat-spacer"></div>`));
  head.appendChild(el(`<div class="seat-head-label">${esc(data.front_label || 'หน้ารถ')}</div>`));
  head.appendChild(data.show_driver === false
    ? el(`<div class="seat-spacer"></div>`)
    : el(`<div class="seat-driver">🚍<span>คนขับ</span></div>`));
  wrap.appendChild(head);

  const rows = buildSeatRows();
  const grid = el(`<div class="seat-rows"></div>`);

  if (rows.length) {
    const widest = rows.reduce((w, r) => Math.max(w, r.left.length + r.mid.length + r.right.length), 3);
    // ที่นั่งย่อลงเมื่อรถกว้าง (บัส 2+2 หรือแถวหลัง 5 ที่) ให้พอดีลำตัวรถ
    wrap.style.setProperty('--seat-size', widest >= 5 ? '38px' : '44px');
    wrap.style.setProperty('--seat-gap', widest >= 5 ? '5px' : '8px');
    wrap.style.setProperty('--seat-aisle', widest >= 5 ? '18px' : '28px');
    const showRowNumbers = rows.length >= 6;

    rows.forEach((rowDef) => {
      const rowEl = el(`<div class="seat-row"></div>`);
      rowEl.appendChild(el(`<span class="seat-rownum">${showRowNumbers ? rowDef.row : ''}</span>`));
      const body = el(`<div class="seat-row-body"></div>`);

      const group = (ids) => {
        const g = el(`<div class="seat-group"></div>`);
        ids.forEach((id) => g.appendChild(seatCell(seatById(id))));
        return g;
      };
      body.appendChild(group(rowDef.left));
      if (rowDef.mid.length) body.appendChild(group(rowDef.mid));
      if (rowDef.right.length) {
        body.appendChild(el(`<div class="seat-aisle">${rowDef.hasAisle ? '<i></i>' : ''}</div>`));
        body.appendChild(group(rowDef.right));
      }
      rowEl.appendChild(body);
      rowEl.appendChild(el(`<span class="seat-rownum"></span>`));
      grid.appendChild(rowEl);
    });
  } else {
    // ผังที่ไม่มี rows/columns (ข้อมูลเก่า) — เรียงตามลำดับที่ได้มา
    const rowEl = el(`<div class="seat-row-body wrap"></div>`);
    seats.forEach((s) => rowEl.appendChild(seatCell(s)));
    grid.appendChild(rowEl);
  }

  wrap.appendChild(grid);
  wrap.appendChild(el(`<div class="seat-rear">${esc(data.rear_label || 'ท้ายรถ (สำหรับเก็บสัมภาระ)')}</div>`));
  wrap.appendChild(el(`<div class="seat-avail">ว่าง <b>${data.available_seats ?? '-'}</b> / ${data.total_seats ?? '-'} ที่นั่ง</div>`));
  return wrap;
}

function seatCell(seat) {
  if (!seat) return el(`<div class="seat-spacer"></div>`);
  const usable = seatAvailable(seat);
  const mine = seat.locked_by_current_user || seat.booked_by_current_user;
  const title = seat.booked_by_current_user
    ? `อยู่ในการจอง${seat.booking_ref ? ' ' + seat.booking_ref : ''} ของคุณ`
    : seat.locked_by_current_user
      ? 'คุณล็อคที่นั่งนี้ไว้'
      : seat.status === 'booked'
        ? 'จองแล้ว'
        : seat.status === 'locked'
          ? 'มีผู้ใช้อื่นกำลังจอง'
          : 'แตะเพื่อเลือก';
  const cell = el(`<button class="seat${mine ? ' mine' : ''}${bk.selected.includes(seat.id) ? ' selected' : ''}" title="${esc(title)}" ${usable ? '' : 'disabled'}>${esc(seat.label || seat.id)}</button>`);
  cell.dataset.id = seat.id;
  cell.onclick = () => toggleSeat(seat.id, cell);
  return cell;
}

function toggleSeat(id, cell) {
  const i = bk.selected.indexOf(id);
  if (i >= 0) {
    bk.selected.splice(i, 1);
  } else {
    if (bk.selected.length >= maxSelectable()) {
      alert(`จองได้สูงสุด ${maxSelectable()} ที่นั่งต่อหนึ่งการจอง`);
      return;
    }
    bk.selected.push(id);
  }
  cell.classList.toggle('selected', bk.selected.includes(id));
  syncPassengerCount();
  refreshSeatStep();
}

/* --------- ตัวนับจำนวนผู้เดินทาง (รอบที่ไม่มีผังที่นั่ง / จอยทริป) --------- */

function buildPaxStepper() {
  const wrap = el(`<div class="card"><div class="body">
    <p class="muted" id="noSeatNote"></p>
    <div class="cta-row" style="margin-top:10px">
      <strong id="paxLabel"></strong>
      <span class="qty">
        <button class="qty-btn" id="paxMinus" aria-label="ลดจำนวน">−</button>
        <span class="qty-num" id="paxNum"></span>
        <button class="qty-btn" id="paxPlus" aria-label="เพิ่มจำนวน">+</button>
      </span>
    </div>
  </div></div>`);

  wrap.querySelector('#noSeatNote').textContent = bk.joinTrip
    ? 'จอยทริปไม่ต้องเลือกที่นั่ง ระบุแค่จำนวนผู้เดินทาง'
    : (bk.seatData.seat_selection_disabled_reason || 'รอบนี้ไม่ต้องเลือกที่นั่ง');

  const sync = () => {
    wrap.querySelector('#paxLabel').textContent = 'จำนวนผู้เดินทาง';
    wrap.querySelector('#paxNum').textContent = bk.count;
    wrap.querySelector('#paxMinus').disabled = bk.count <= 1;
    wrap.querySelector('#paxPlus').disabled = bk.count >= maxPassengers();
    syncPassengerCount();
    refreshSeatStep();
  };
  wrap.querySelector('#paxMinus').onclick = () => { bk.count = Math.max(1, bk.count - 1); sync(); };
  wrap.querySelector('#paxPlus').onclick = () => { bk.count = Math.min(maxPassengers(), bk.count + 1); sync(); };
  sync();
  return wrap;
}

/* --------- จุดขึ้นรถ --------- */

function pickupOption(id, label, price, time) {
  // ราคาที่โชว์รวมส่วนต่างของคันที่เลือกแล้ว ไม่งั้นตัวเลขบนแถวจะไม่ตรงกับยอดรวม
  const perPax = Number(price ?? bk.schedule.price ?? 0) + vehicleAdjustment();
  const active = id === bk.pickupId && !bk.customPickup;
  const opt = el(`<label class="pick${active ? ' on' : ''}">
    <input type="radio" name="pickup" ${active ? 'checked' : ''}>
    <div class="pick-body">
      <div class="pick-name">${esc(label)}</div>
      <div class="pick-sub">${time ? '🕖 ' + esc(time) + ' · ' : ''}${baht(perPax)}</div>
    </div>
  </label>`);
  opt.querySelector('input').onchange = () => {
    bk.pickupId = id;
    bk.customPickup = null; // เลือกจุดที่กำหนด = ยกเลิกหมุดที่ปักเอง
    // จุดรับเก็บทั้งระดับการจองและรายคน — ตั้งค่าเริ่มต้นให้ทุกคนตามที่เลือก
    bk.passengers.forEach((p) => { p.pickup_point_id = id; });
    renderSeatStep();
  };
  return opt;
}

function buildCustomPickupBlock() {
  const wrap = el(`<div class="custom-pickup"></div>`);
  if (bk.customPickup) {
    const zonePrice = customPickupPrice();
    wrap.appendChild(el(`<div class="card"><div class="body">
      <div class="pick-name">📍 จุดที่คุณปักหมุด</div>
      <div class="pick-sub">${esc(bk.customPickup.label)}</div>
      <div class="pick-sub">คิดราคาเท่าโซนที่ใกล้ที่สุด · ${baht(zonePrice + vehicleAdjustment())} / คน (รอทีมงานยืนยัน)</div>
    </div></div>`));
    const row = el(`<div class="chip-row"></div>`);
    const edit = el(`<button type="button" class="chip">แก้ไขจุด</button>`);
    edit.onclick = openCustomPickup;
    const clear = el(`<button type="button" class="chip">เอาออก</button>`);
    clear.onclick = () => { bk.customPickup = null; renderSeatStep(); };
    row.appendChild(edit);
    row.appendChild(clear);
    wrap.appendChild(row);
  } else {
    const btn = el(`<button class="btn secondary" style="margin-top:8px">📍 ไม่มีจุดที่สะดวก — ปักหมุดเอง</button>`);
    btn.onclick = openCustomPickup;
    wrap.appendChild(btn);
  }
  return wrap;
}

/* --------- ไกด์ประเภทรถรับ-ส่ง --------- */

let vehicleClasses = null;
let vehicleClassesPending = null;

function loadVehicleClasses() {
  if (vehicleClasses) return Promise.resolve(vehicleClasses);
  if (!vehicleClassesPending) {
    vehicleClassesPending = api('/pickup-vehicle-classes', { auth: false })
      .then((res) => { vehicleClasses = res.data || []; return vehicleClasses; })
      .catch(() => { vehicleClassesPending = null; return []; });
  }
  return vehicleClassesPending;
}

/**
 * จุดรับที่เลือกแพงกว่าราคารอบไหม
 *
 * `price` ของจุดรับคือราคาต่อคนเมื่อขึ้นจุดนั้น (ทับราคารอบ) ไม่ใช่ส่วนต่าง
 * จุดที่เท่าราคารอบจึงไม่ได้จ่ายเพิ่ม และไม่ต้องอธิบายเรื่องรถรับ-ส่ง
 */
function pickupHasSurcharge() {
  if (bk.customPickup) return customPickupPrice() > basePrice();
  const point = pickupPoints().find((p) => p.id === bk.pickupId);
  if (!point) return false;
  const price = Number(point.price || 0);
  return price > 0 && price > basePrice();
}

function renderPickupVehicleGuide() {
  const host = document.getElementById('pickupVehicleGuide');
  if (!host) return;

  host.innerHTML = '';
  if (!pickupHasSurcharge() || !vehicleClasses || !vehicleClasses.length) return;

  // ไฮไลต์ใบที่ตรงกับจำนวนคน แต่ยังโชว์ทั้งชุด — ตอนนี้ยังไม่รู้ว่าจุดนั้นจะมี
  // คนรวมกี่คน การโชว์ใบเดียวจึงเป็นคำสัญญาที่ผิดได้เมื่อวันจริงถูกรวมกลุ่ม
  const pax = paxCount();
  const match = vehicleClasses.find(
    (c) => pax >= c.min_pax && (c.max_pax === null || pax <= c.max_pax)
  );

  const wrap = el(`<div class="veh-guide">
    <div class="veh-title">🚐 รถรับ-ส่งมาที่จุดขึ้นรถ</div>
    ${match ? `<div class="veh-match">เดินทาง ${pax} ท่าน โดยประมาณจะใช้${esc(match.label)}</div>` : ''}
    <div class="veh-row"></div>
    <p class="veh-note">ค่าจุดรับที่จ่ายเพิ่มคือค่ารถรับ-ส่งมาที่จุดขึ้นรถจุดแรก ประเภทรถขึ้นกับจำนวนผู้โดยสารรวมที่จุดนั้นในวันเดินทาง</p>
  </div>`);

  const row = wrap.querySelector('.veh-row');
  vehicleClasses.forEach((c) => {
    row.appendChild(el(`<div class="veh-card${match && c.id === match.id ? ' on' : ''}">
      ${c.image_url
        ? `<img src="${esc(c.image_url)}" alt="${esc(c.label)}" loading="lazy">`
        : '<div class="veh-thumb-empty">🚗</div>'}
      <div class="veh-body">
        <div class="veh-label">${esc(c.label)}</div>
        <div class="veh-pax">${esc(c.pax_label || '')}</div>
      </div>
    </div>`));
  });

  host.appendChild(wrap);
}

function refreshSeatStep() {
  const n = paxCount();
  const count = document.getElementById('selCount');
  const est = document.getElementById('estTotal');
  const next = document.getElementById('next');
  if (count) {
    count.textContent = !n
      ? (hasSeatMap() ? 'ยังไม่ได้เลือกที่นั่ง' : 'ยังไม่ได้ระบุจำนวน')
      : hasSeatMap()
        ? `เลือกแล้ว ${n} ที่ (${bk.selected.join(', ')})`
        : `ผู้เดินทาง ${n} คน`;
  }
  if (est) est.textContent = n ? baht(estimateTotal()) : '';
  if (next) next.disabled = n === 0;
  renderPickupVehicleGuide();
}

/* --------- ปักหมุดจุดรับเอง (แผนที่ OpenStreetMap) --------- */

function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  return new Promise((resolve, reject) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(link);
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    script.onload = () => resolve(window.L);
    script.onerror = () => reject(new Error('โหลดแผนที่ไม่สำเร็จ กรุณาตรวจสอบอินเทอร์เน็ต'));
    document.head.appendChild(script);
  });
}

// หมุดตรึงกลางจอ เลื่อนแผนที่เอา (แบบเดียวกับ CustomPickupModal.vue)
async function openCustomPickup() {
  const sheet = el(`<div class="sheet-overlay"><div class="sheet">
    <div class="sheet-head"><strong>ปักหมุดจุดรับของคุณ</strong><button class="sheet-close" aria-label="ปิด">✕</button></div>
    <div class="sheet-body">
      <p class="muted">เลื่อนแผนที่ให้หมุดอยู่ตรงจุดที่สะดวก <b>ราคาคิดเท่าจุดรับที่ใกล้หมุดที่สุด</b> (ไม่ต่ำกว่าราคารอบเดินทาง) และทีมงานจะยืนยันอีกครั้ง</p>
      <input id="cpSearch" placeholder="ค้นหาสถานที่ เช่น เซ็นทรัลลาดพร้าว">
      <div id="cpResults"></div>
      <div class="map-wrap">
        <div id="cpMap"></div>
        <div class="map-pin">📍</div>
        <button type="button" class="map-locate" id="cpLocate" aria-label="ตำแหน่งฉัน">◎</button>
      </div>
      <label class="field"><span>ชื่อจุดรับ (ให้ทีมงานหาเจอ)</span>
        <input id="cpLabel" maxlength="255" placeholder="เช่น หน้าปั๊ม ปตท. ถนนรามอินทรา"></label>
      <label class="field"><span>หมายเหตุ (ไม่บังคับ)</span>
        <textarea id="cpNote" rows="2" maxlength="1000"></textarea></label>
    </div>
    <div class="sheet-foot">
      <button class="btn secondary" id="cpCancel">ยกเลิก</button>
      <button class="btn" id="cpConfirm" disabled>ใช้จุดนี้</button>
    </div>
  </div></div>`);
  sheet.onclick = (e) => { if (e.target === sheet) close(); };
  const close = () => { if (map) { map.remove(); map = null; } sheet.remove(); };
  sheet.querySelector('.sheet-close').onclick = close;
  sheet.querySelector('#cpCancel').onclick = close;
  document.body.appendChild(sheet);

  if (bk.customPickup) {
    sheet.querySelector('#cpLabel').value = bk.customPickup.label || '';
    sheet.querySelector('#cpNote').value = bk.customPickup.note || '';
  }

  let L, map = null;
  let coords = bk.customPickup
    ? { lat: Number(bk.customPickup.lat), lng: Number(bk.customPickup.lng) }
    : null;

  try {
    L = await loadLeaflet();
  } catch (e) {
    sheet.querySelector('#cpMap').innerHTML = `<p class="banner error">${esc(e.message)}</p>`;
    return;
  }
  if (!document.body.contains(sheet)) return;

  // เริ่มที่พิกัดจุดรับแรกของรอบ เพื่อให้เห็นบริเวณเส้นทางจริง
  const withCoords = pickupPoints().find((p) => p.latitude && p.longitude);
  const start = coords
    || (withCoords ? { lat: Number(withCoords.latitude), lng: Number(withCoords.longitude) } : { lat: 13.7563, lng: 100.5018 });

  map = L.map(sheet.querySelector('#cpMap'), { zoomControl: true }).setView([start.lat, start.lng], coords ? 15 : 11);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19,
  }).addTo(map);

  const confirmBtn = sheet.querySelector('#cpConfirm');
  const labelInput = sheet.querySelector('#cpLabel');
  const syncCenter = () => {
    const c = map.getCenter();
    coords = { lat: c.lat, lng: c.lng };
    confirmBtn.disabled = !labelInput.value.trim();
  };
  map.on('move', syncCenter);
  map.on('moveend', syncCenter);
  syncCenter();
  setTimeout(() => { if (map) { map.invalidateSize(); syncCenter(); } }, 200);

  labelInput.oninput = syncCenter;

  // ค้นหาสถานที่ตามชื่อ (Nominatim / OpenStreetMap) — ตัวเดียวกับที่เว็บใช้
  const results = sheet.querySelector('#cpResults');
  let searchTimer = null;
  let searchSeq = 0;
  sheet.querySelector('#cpSearch').oninput = (e) => {
    const q = e.target.value.trim();
    if (searchTimer) clearTimeout(searchTimer);
    if (q.length < 3) { results.innerHTML = ''; return; }
    searchTimer = setTimeout(async () => {
      const seq = ++searchSeq;
      try {
        const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=6&accept-language=th&countrycodes=th&q='
          + encodeURIComponent(q);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (seq !== searchSeq) return;
        results.innerHTML = '';
        (Array.isArray(data) ? data : []).forEach((r) => {
          const item = el(`<button type="button" class="search-hit">${esc(r.display_name)}</button>`);
          item.onclick = () => {
            results.innerHTML = '';
            map.setView([parseFloat(r.lat), parseFloat(r.lon)], 16);
            syncCenter();
            if (!labelInput.value.trim()) labelInput.value = String(r.display_name).split(',')[0];
            syncCenter();
          };
          results.appendChild(item);
        });
        if (!results.children.length) {
          results.appendChild(el(`<p class="muted">ไม่พบสถานที่ตามคำค้นหา ลองเลื่อนแผนที่ปักหมุดเองครับ</p>`));
        }
      } catch (_) { /* ค้นไม่ได้ก็ยังปักหมุดเองได้ */ }
    }, 600);
  };

  sheet.querySelector('#cpLocate').onclick = () => {
    if (!('geolocation' in navigator)) return alert('เบราว์เซอร์นี้ไม่รองรับการระบุตำแหน่ง');
    navigator.geolocation.getCurrentPosition(
      (pos) => { map.setView([pos.coords.latitude, pos.coords.longitude], 16); syncCenter(); },
      () => alert('ไม่สามารถระบุตำแหน่งได้ กรุณาอนุญาตการเข้าถึงตำแหน่งแล้วลองอีกครั้ง'),
      { enableHighAccuracy: true, timeout: 10000 },
    );
  };

  confirmBtn.onclick = () => {
    if (!coords || !labelInput.value.trim()) return;
    bk.customPickup = {
      label: labelInput.value.trim(),
      lat: coords.lat,
      lng: coords.lng,
      note: sheet.querySelector('#cpNote').value.trim() || null,
    };
    // ปักหมุดเอง = ไม่ผูกจุดรับตายตัวทั้งระดับการจองและรายคน
    bk.pickupId = null;
    bk.passengers.forEach((p) => { p.pickup_point_id = null; });
    close();
    renderSeatStep();
  };
}

/* ============== ขั้นที่ 2: ข้อมูลผู้เดินทาง ============== */

async function proceedToPassengers() {
  const next = document.getElementById('next');

  if (!hasSeatMap()) {
    syncPassengerCount();
    renderPassengerStep();
    return;
  }

  next.disabled = true;
  next.textContent = 'กำลังจองที่นั่ง…';
  try {
    const res = await api('/schedules/' + bk.schedule.id + '/seats/lock', {
      method: 'POST',
      body: {
        seat_ids: bk.selected,
        pickup_point_id: bk.pickupId,
        vehicle_option_id: bk.vehicleOptionId,
      },
    });
    // เส้นตายจริงมาจากหลังบ้าน (10 นาที + 5 นาทีต่อที่นั่ง + โบนัสระดับสมาชิก)
    // ห้ามคำนวณเองที่นี่ ไม่งั้นนาฬิกาสองเรือนจะเดินไม่ตรงกัน
    bk.lockExpiresAt = res.data?.expires_at || null;
  } catch (e) {
    next.disabled = false;
    next.textContent = 'ถัดไป';
    alert(e.message);
    return;
  }
  syncPassengerCount();
  renderPassengerStep();
}

/* --------- นับถอยหลังที่นั่งที่ล็อกไว้ --------- */

let lockTimer = null;

function lockCountdownBar() {
  if (!bk.lockExpiresAt) return null;
  const bar = el(`<div class="lock-bar">⏳ กันที่นั่งไว้ให้อีก <b id="lockLeft">--:--</b></div>`);
  setTimeout(startLockCountdown, 0);
  return bar;
}

function startLockCountdown() {
  if (lockTimer) clearInterval(lockTimer);
  lockTimer = setInterval(tickLock, 1000);
  tickLock();
}

function stopLockCountdown() {
  if (lockTimer) clearInterval(lockTimer);
  lockTimer = null;
}

function tickLock() {
  const host = document.getElementById('lockLeft');
  if (!host || !bk || !bk.lockExpiresAt) return stopLockCountdown();

  const left = Math.floor((new Date(bk.lockExpiresAt).getTime() - Date.now()) / 1000);
  if (left <= 0) {
    stopLockCountdown();
    bk.lockExpiresAt = null;
    // เก็บร่างไว้ให้ — คนที่หมดเวลาไม่ควรต้องพิมพ์ข้อมูลทุกคนใหม่
    saveDraft();
    askConfirm(
      'หมดเวลากันที่นั่งแล้ว',
      'ที่นั่งถูกปล่อยคืนให้คนอื่นแล้ว ข้อมูลที่กรอกไว้ถูกเก็บให้เรียบร้อย เลือกที่นั่งใหม่ได้เลยครับ',
      'เลือกที่นั่งใหม่', 'ปิด',
    ).then(() => startBooking(bk.trip, bk.schedule.id, { joinTrip: bk.joinTrip }));
    return;
  }
  host.textContent = mmss(left);
  host.parentElement.classList.toggle('warn', left <= 120);
}

/* --------- โครงข้อมูลผู้เดินทาง --------- */

function blankPassenger() {
  return {
    title: '', name: '', nickname: '', id_card: '',
    name_en: '', nationality: 'TH', passport_no: '', passport_expires_at: '',
    birth_date: '', phone: '', email: '', blood_group: '',
    allergies: '', health_notes: '', halal_food: false,
    emergency_contact: '', emergency_phone: '',
    dive_cert_level: '', cert_number: '', weight: '',
    pickup_point_id: bk?.pickupId ?? null,
  };
}

// จำนวนผู้เดินทางเปลี่ยนตามที่นั่ง/ตัวนับ — ต่อแถวเพิ่มหรือตัดส่วนเกินทิ้ง
// แถวที่เพิ่มเข้ามาดึงจากร่างก่อน ลูกค้าที่หมดเวลาแล้วเลือกที่นั่งใหม่จึงไม่ต้องพิมพ์ซ้ำ
function syncPassengerCount() {
  const n = Math.max(0, paxCount());
  while (bk.passengers.length < n) {
    bk.passengers.push(bk.draftPassengers[bk.passengers.length] || blankPassenger());
  }
  if (bk.passengers.length > n) bk.passengers.length = n;
  while (bk.docs.length > n) bk.docs.pop();
  clampAddonQuantities();
}

const digitsOnly = (v) => String(v || '').replace(/\D/g, '');
const hasText = (v) => String(v ?? '').trim().length > 0;
const hasExactDigits = (v, n) => digitsOnly(v).length === n;
const isValidEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || '').trim());

/** เลขบัตรประชาชนไทย 13 หลัก — ตรวจ checksum ไม่ใช่แค่ความยาว */
function isValidThaiId(value) {
  const digits = digitsOnly(value);
  if (digits.length !== 13) return false;
  let sum = 0;
  for (let i = 0; i < 12; i++) sum += Number(digits[i]) * (13 - i);
  return (11 - (sum % 11)) % 10 === Number(digits[12]);
}

const isThaiTraveller = (p) => (p.nationality || 'TH') === 'TH';

const todayDate = (() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

/**
 * วันหมดอายุพาสปอร์ตที่เร็วที่สุดที่ยังใช้เดินทางรอบนี้ได้ = วันเดินทาง + 6 เดือน
 * คิดเป็นเที่ยงคืน UTC ล้วน ๆ และหดวันให้พอดีปลายเดือนแบบเดียวกับ Carbon::addMonths
 */
function minPassportExpiry() {
  const departure = bk.schedule.departure_date;
  if (!departure) return '';
  const [year, month, day] = departure.split('-').map(Number);
  const target = new Date(Date.UTC(year, month - 1 + 6, 1));
  const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
  target.setUTCDate(Math.min(day, lastDay));
  return target.toISOString().slice(0, 10);
}

const documentRequirements = () => bk.trip?.document_requirements || [];

function docFiles(i, key) {
  return bk.docs[i]?.[key] || [];
}

/** ช่องที่ยังกรอกไม่ครบของผู้เดินทางคนหนึ่ง — กติกาเดียวกับ computePassengerErrors บนเว็บ */
function passengerErrors(p, i) {
  const errors = {};
  const womenOnly = !!bk.trip?.is_women_only;
  const international = isInternationalTrip();
  const isThai = isThaiTraveller(p);

  if (!p.title) errors.title = 'กรุณาเลือกคำนำหน้า';
  else if (womenOnly && !['นาง', 'นางสาว'].includes(p.title)) errors.title = 'ทริปนี้สำหรับผู้หญิงเท่านั้น';
  if (!hasText(p.name)) errors.name = 'กรุณากรอกชื่อ-นามสกุล';
  if (!hasText(p.nickname)) errors.nickname = 'กรุณากรอกชื่อเล่น';

  // ชาวต่างชาติที่ร่วมทริปยืนยันตัวด้วยพาสปอร์ตแทนบัตรประชาชนไทย
  if (isThai) {
    if (!hasExactDigits(p.id_card, 13)) errors.id_card = 'กรุณากรอกเลขบัตรประชาชน 13 หลัก';
    else if (!isValidThaiId(p.id_card)) errors.id_card = 'เลขบัตรประชาชนไม่ถูกต้อง ลองตรวจสอบอีกครั้งครับ';
  }
  if (international) {
    if (!hasText(p.name_en)) errors.name_en = 'กรุณากรอกชื่อ-สกุลภาษาอังกฤษตามพาสปอร์ต';
    else if (!/^[A-Za-z\s.'-]+$/.test(p.name_en)) errors.name_en = 'กรอกได้เฉพาะตัวอักษรภาษาอังกฤษ';
    if (!hasText(p.passport_no)) errors.passport_no = 'กรุณากรอกเลขที่พาสปอร์ต';
    else if (!/^[A-Za-z0-9]{5,20}$/.test(p.passport_no)) errors.passport_no = 'เลขที่พาสปอร์ตไม่ถูกต้อง';
    if (!hasText(p.passport_expires_at)) errors.passport_expires_at = 'กรุณาระบุวันหมดอายุพาสปอร์ต';
    else if (minPassportExpiry() && p.passport_expires_at < minPassportExpiry()) {
      errors.passport_expires_at = 'พาสปอร์ตต้องเหลืออายุอย่างน้อย 6 เดือนนับจากวันเดินทาง';
    }
  }

  if (!hasText(p.birth_date)) errors.birth_date = 'กรุณาเลือกวันเกิด';
  else if (p.birth_date >= todayDate) errors.birth_date = 'วันเกิดไม่ถูกต้อง';

  if (isThai) {
    if (!hasExactDigits(p.phone, 10)) errors.phone = 'กรุณากรอกเบอร์โทรศัพท์ 10 หลัก';
  } else if (!/^\+?[0-9][0-9 -]{7,19}$/.test(String(p.phone || ''))) {
    errors.phone = 'กรุณากรอกเบอร์โทรศัพท์พร้อมรหัสประเทศ';
  }
  if (bk.bookingFor === 'friend' && i === 0 && !isValidEmail(p.email)) {
    errors.email = 'กรุณากรอกอีเมลของเพื่อนให้ถูกต้อง';
  }
  if (!p.blood_group) errors.blood_group = 'กรุณาเลือกกรุ๊ปเลือด';
  if (!hasText(p.emergency_contact)) errors.emergency_contact = 'กรุณากรอกผู้ติดต่อฉุกเฉิน';
  if (isThai) {
    if (!hasExactDigits(p.emergency_phone, 10)) errors.emergency_phone = 'กรุณากรอกเบอร์ฉุกเฉิน 10 หลัก';
  } else if (!/^\+?[0-9][0-9 -]{7,19}$/.test(String(p.emergency_phone || ''))) {
    errors.emergency_phone = 'กรุณากรอกเบอร์ฉุกเฉินพร้อมรหัสประเทศ';
  }
  if (!hasText(p.allergies)) errors.allergies = 'กรุณากรอกข้อมูลการแพ้อาหาร (หากไม่มีให้พิมพ์ "ไม่มี")';
  if (!hasText(p.health_notes)) errors.health_notes = 'กรุณากรอกหมายเหตุสุขภาพ (หากไม่มีให้พิมพ์ "ไม่มี")';
  if (pickupPoints().length && !p.pickup_point_id && !bk.customPickup) {
    errors.pickup_point_id = 'กรุณาเลือกจุดขึ้นรถ';
  }
  for (const doc of documentRequirements()) {
    if (doc.required && !docFiles(i, doc.key).length) errors['doc:' + doc.key] = 'กรุณาแนบ' + doc.label;
  }
  return errors;
}

function allPassengerErrors() {
  return bk.passengers.map((p, i) => passengerErrors(p, i));
}

/* --------- หน้ากรอกข้อมูลผู้เดินทาง --------- */

let countriesCache = null;

async function loadCountries() {
  if (countriesCache) return countriesCache;
  try {
    countriesCache = (await api('/countries', { auth: false })).data || [];
  } catch (_) {
    // เลือกสัญชาติอื่นไม่ได้ชั่วคราว — ค่าเริ่มต้น TH ยังจองได้ตามปกติ
    countriesCache = [{ code: 'TH', name: 'ไทย', flag: '🇹🇭' }];
  }
  return countriesCache;
}

function renderPassengerStep() {
  // ฟอร์มยาว — วาดใหม่แล้วต้องอยู่ที่เดิม ไม่ใช่เด้งขึ้นหัวหน้า
  const scrollY = window.scrollY;
  const node = el(`<div></div>`);
  node.appendChild(appbar('ข้อมูลผู้เดินทาง', renderSeatStep));
  const content = el(`<div class="content"></div>`);
  content.appendChild(stepDots(2));
  const lockBar = lockCountdownBar();
  if (lockBar) content.appendChild(lockBar);

  // จองให้ตัวเองหรือให้เพื่อน — เพื่อนจะได้อีเมลเชิญให้เข้ามาดูการจอง
  const forRow = el(`<div class="chip-row"></div>`);
  [['self', 'จองให้ตัวเอง/กลุ่มของฉัน'], ['friend', 'จองให้เพื่อน']].forEach(([value, label]) => {
    const chip = el(`<button type="button" class="chip ${bk.bookingFor === value ? 'on' : ''}">${label}</button>`);
    chip.onclick = () => { bk.bookingFor = value; renderPassengerStep(); };
    forRow.appendChild(chip);
  });
  content.appendChild(forRow);

  bk.passengers.forEach((p, i) => content.appendChild(passengerCard(p, i)));

  node.appendChild(content);
  const cta = el(`<div class="sticky-cta">
    <div class="cta-row"><span class="muted" id="missCount"></span><span class="price">${baht(estimateTotal())}</span></div>
    <button class="btn" id="toSummary">ถัดไป</button>
  </div>`);
  node.appendChild(cta);
  render(node);
  if (scrollY) window.scrollTo(0, scrollY);

  refreshMissingCount();
  cta.querySelector('#toSummary').onclick = () => {
    bk.attempted = true;
    const errors = allPassengerErrors();
    const idx = errors.findIndex((e) => Object.keys(e).length > 0);
    if (idx >= 0) {
      const first = Object.values(errors[idx])[0];
      renderPassengerStep();
      alert(`ผู้เดินทางคนที่ ${idx + 1}: ${first}`);
      document.getElementById('pax-' + idx)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    saveDraft();
    renderSummaryStep();
  };
}

function refreshMissingCount() {
  const host = document.getElementById('missCount');
  if (!host) return;
  const missing = allPassengerErrors().reduce((sum, e) => sum + Object.keys(e).length, 0);
  host.textContent = missing ? `ยังขาดอีก ${missing} ช่อง` : 'กรอกครบแล้ว';
}

function field(label, inputHtml, error) {
  return `<label class="field${error ? ' has-error' : ''}"><span>${esc(label)}</span>${inputHtml}
    ${error ? `<em class="field-error">${esc(error)}</em>` : ''}</label>`;
}

function passengerCard(p, i) {
  const errors = passengerErrors(p, i);
  const show = (key) => (bk.attempted ? errors[key] : null);
  const womenOnly = !!bk.trip?.is_women_only;
  const international = isInternationalTrip();
  const titleOpts = womenOnly ? ['นาง', 'นางสาว'] : ['นาย', 'นาง', 'นางสาว'];
  const seatId = hasSeatMap() ? bk.selected[i] : null;
  const points = pickupPoints();

  const card = el(`<div class="card pax" id="pax-${i}"><div class="body"></div></div>`);
  const body = card.querySelector('.body');

  body.innerHTML = `
    <div class="pax-head">ผู้เดินทางคนที่ ${i + 1}${seatId ? ` <span class="tag">ที่นั่ง ${esc(seatId)}</span>` : ''}</div>
    <div class="chip-row" data-role="fill"></div>
    ${field('คำนำหน้า', `<select data-f="title">
      <option value="">เลือก</option>
      ${titleOpts.map((t) => `<option ${p.title === t ? 'selected' : ''}>${t}</option>`).join('')}
    </select>`, show('title'))}
    ${field('ชื่อ-นามสกุล', `<input data-f="name" value="${esc(p.name)}" placeholder="ชื่อจริง นามสกุล">`, show('name'))}
    ${field('ชื่อเล่น', `<input data-f="nickname" value="${esc(p.nickname)}" placeholder="ชื่อเล่น">`, show('nickname'))}
    ${international ? field('สัญชาติ', `<select data-f="nationality"><option value="TH">ไทย</option></select>`, null) : ''}
    ${isThaiTraveller(p) ? field('เลขบัตรประชาชน (13 หลัก)',
      `<input data-f="id_card" inputmode="numeric" maxlength="13" value="${esc(p.id_card)}">`,
      errors.id_card && (bk.attempted || hasText(p.id_card)) ? errors.id_card : null) : ''}
    ${international ? `
      <div class="pax-head">เอกสารเดินทาง</div>
      ${field('ชื่อ-สกุลภาษาอังกฤษ (ตามพาสปอร์ต)', `<input data-f="name_en" maxlength="255" style="text-transform:uppercase" placeholder="SOMCHAI JAIDEE" value="${esc(p.name_en)}">`, show('name_en'))}
      ${field('เลขที่พาสปอร์ต', `<input data-f="passport_no" maxlength="20" style="text-transform:uppercase" placeholder="AA1234567" value="${esc(p.passport_no)}">`, show('passport_no'))}
      ${field('วันหมดอายุพาสปอร์ต', `<input data-f="passport_expires_at" type="date" min="${esc(minPassportExpiry())}" value="${esc(p.passport_expires_at)}">`, show('passport_expires_at'))}
    ` : ''}
    ${field('วันเกิด', `<input data-f="birth_date" type="date" max="${esc(todayDate)}" value="${esc(p.birth_date)}">`, show('birth_date'))}
    <div class="row2">
      ${field('เบอร์โทร', `<input data-f="phone" inputmode="tel" maxlength="20" value="${esc(p.phone)}">`,
        errors.phone && (bk.attempted || hasText(p.phone)) ? errors.phone : null)}
      ${field('กรุ๊ปเลือด', `<select data-f="blood_group"><option value="">เลือก</option>
        ${['A', 'B', 'O', 'AB'].map((b) => `<option ${p.blood_group === b ? 'selected' : ''}>${b}</option>`).join('')}
      </select>`, show('blood_group'))}
    </div>
    ${bk.bookingFor === 'friend' && i === 0
      ? field('อีเมลของเพื่อน (ส่งคำเชิญให้)', `<input data-f="email" type="email" value="${esc(p.email)}">`,
        errors.email && (bk.attempted || hasText(p.email)) ? errors.email : null)
      : field('อีเมล (ไม่บังคับ)', `<input data-f="email" type="email" value="${esc(p.email)}">`, null)}
    <div class="row2">
      ${field('ผู้ติดต่อฉุกเฉิน', `<input data-f="emergency_contact" value="${esc(p.emergency_contact)}">`, show('emergency_contact'))}
      ${field('เบอร์ฉุกเฉิน', `<input data-f="emergency_phone" inputmode="tel" maxlength="20" value="${esc(p.emergency_phone)}">`,
        errors.emergency_phone && (bk.attempted || hasText(p.emergency_phone)) ? errors.emergency_phone : null)}
    </div>
    ${field('อาหารฮาลาล', `<select data-f="halal_food">
      <option value="0" ${p.halal_food ? '' : 'selected'}>ไม่</option>
      <option value="1" ${p.halal_food ? 'selected' : ''}>ใช่</option>
    </select>`, null)}
    ${field('แพ้อาหาร/ยา (ไม่มีให้พิมพ์ "ไม่มี")', `<textarea data-f="allergies" rows="2">${esc(p.allergies)}</textarea>`, show('allergies'))}
    ${field('หมายเหตุสุขภาพ (ไม่มีให้พิมพ์ "ไม่มี")', `<textarea data-f="health_notes" rows="2">${esc(p.health_notes)}</textarea>`, show('health_notes'))}
    ${isDivingTrip() ? `
      <div class="pax-head">ข้อมูลดำน้ำ</div>
      ${field('ระดับใบรับรอง (ไม่บังคับ)', `<input data-f="dive_cert_level" value="${esc(p.dive_cert_level)}" placeholder="เช่น Open Water">`, null)}
      ${field('เลขที่ใบรับรอง (ไม่บังคับ)', `<input data-f="cert_number" value="${esc(p.cert_number)}">`, null)}
      ${field('น้ำหนัก (กก.) (ไม่บังคับ)', `<input data-f="weight" inputmode="decimal" value="${esc(p.weight)}">`, null)}
    ` : ''}
    ${points.length && !bk.customPickup ? field('จุดขึ้นรถของคนนี้', `<select data-f="pickup_point_id">
      <option value="">เลือกจุดขึ้นรถ</option>
      ${points.map((pt) => `<option value="${pt.id}" ${p.pickup_point_id === pt.id ? 'selected' : ''}>${esc(pt.pickup_location || pt.region_label || 'จุดรับ')} · ${baht(Number(pt.price) + vehicleAdjustment())}</option>`).join('')}
    </select>`, show('pickup_point_id')) : ''}
    <div data-role="docs"></div>
  `;

  // ปุ่มช่วยกรอก
  const fill = body.querySelector('[data-role="fill"]');
  const profileBtn = el(`<button type="button" class="chip">ข้อมูลของฉัน</button>`);
  profileBtn.onclick = () => autoFillFromProfile(i);
  fill.appendChild(profileBtn);
  const bookBtn = el(`<button type="button" class="chip">สมุดผู้ร่วมเดินทาง</button>`);
  bookBtn.onclick = () => openTravellerPicker(i);
  fill.appendChild(bookBtn);
  if (i > 0) {
    const copyBtn = el(`<button type="button" class="chip">คัดลอกจากคนที่ 1</button>`);
    copyBtn.onclick = () => copyFromFirst(i);
    fill.appendChild(copyBtn);
  }

  // สัญชาติ (ทริปต่างประเทศ) — เติมรายการประเทศเมื่อโหลดเสร็จ
  const nationality = body.querySelector('[data-f="nationality"]');
  if (nationality) {
    loadCountries().then((countries) => {
      const live = document.querySelector(`#pax-${i} [data-f="nationality"]`);
      if (!live) return;
      live.innerHTML = countries
        .map((c) => `<option value="${esc(c.code)}" ${p.nationality === c.code ? 'selected' : ''}>${esc((c.flag ? c.flag + ' ' : '') + c.name)}</option>`)
        .join('');
    });
  }

  // เอกสารที่ทริปนี้ขอ
  const docsHost = body.querySelector('[data-role="docs"]');
  documentRequirements().forEach((doc) => docsHost.appendChild(docBlock(i, doc, show('doc:' + doc.key))));

  // เก็บค่าทุกครั้งที่พิมพ์ แล้วอัปเดตข้อความผิดพลาด "ในที่" — ห้ามเรนเดอร์ทั้งขั้น
  // ตอนพิมพ์/ออกจากช่อง ไม่งั้นคีย์บอร์ดบนมือถือจะปิดและหน้าจอเด้งขึ้นบนสุดทุกครั้ง
  // ที่แตะช่องถัดไป มีแค่สัญชาติที่เปลี่ยนโครงฟอร์ม (ซ่อน/โชว์เลขบัตร) จึงวาดใหม่
  body.querySelectorAll('[data-f]').forEach((input) => {
    const key = input.dataset.f;
    const commit = () => {
      let value = input.value;
      if (key === 'halal_food') value = input.value === '1';
      else if (key === 'pickup_point_id') value = input.value ? Number(input.value) : null;
      p[key] = value;
    };
    input.oninput = () => { commit(); paintCardErrors(card, i); refreshMissingCount(); };
    input.onchange = () => {
      commit();
      if (key === 'nationality') return renderPassengerStep();
      paintCardErrors(card, i);
      refreshMissingCount();
      // จุดขึ้นรถของแต่ละคนคนละราคา ยอดรวมท้ายจอต้องขยับตาม
      if (key === 'pickup_point_id') refreshPassengerTotal();
    };
    input.onblur = () => { commit(); paintCardErrors(card, i); refreshMissingCount(); saveDraft(); };
  });

  // ให้ข้อความผิดพลาดตอนวาดครั้งแรกใช้กติกาเดียวกับตอนพิมพ์ ไม่ใช่คนละชุด
  paintCardErrors(card, i);
  return card;
}

/**
 * วาดข้อความผิดพลาดของใบผู้เดินทางใบเดียว โดยไม่แตะโครงหน้า
 *
 * ก่อนกด "ถัดไป" จะเตือนเฉพาะช่องที่ผู้ใช้เริ่มพิมพ์แล้วและรูปแบบผิด (เลขบัตร/
 * เบอร์/อีเมล) — ช่องที่ยังว่างอยู่ไม่ควรขึ้นสีแดงใส่คนที่เพิ่งเปิดฟอร์ม
 */
const LIVE_ERROR_FIELDS = ['id_card', 'phone', 'emergency_phone', 'email'];

function paintCardErrors(card, i) {
  const p = bk.passengers[i];
  if (!p) return;
  const errors = passengerErrors(p, i);

  card.querySelectorAll('[data-f]').forEach((input) => {
    const key = input.dataset.f;
    const label = input.closest('.field');
    if (!label) return;
    const show = errors[key]
      && (bk.attempted || (LIVE_ERROR_FIELDS.includes(key) && hasText(p[key])));
    label.classList.toggle('has-error', !!show);
    let node = label.querySelector('.field-error');
    if (show) {
      if (!node) { node = el(`<em class="field-error"></em>`); label.appendChild(node); }
      node.textContent = errors[key];
    } else if (node) {
      node.remove();
    }
  });
}

function refreshPassengerTotal() {
  const host = document.querySelector('.sticky-cta .price');
  if (host) host.textContent = baht(estimateTotal());
}

function docBlock(i, doc, error) {
  const files = docFiles(i, doc.key);
  const wrap = el(`<div class="doc-block${error ? ' has-error' : ''}">
    <div class="doc-head">${esc(doc.label)}${doc.required ? ' <span class="req">*</span>' : ''}</div>
    ${doc.note ? `<p class="muted">${esc(doc.note)}</p>` : ''}
    <div class="doc-list"></div>
    ${error ? `<em class="field-error">${esc(error)}</em>` : ''}
  </div>`);

  const list = wrap.querySelector('.doc-list');
  files.forEach((file, index) => {
    const row = el(`<div class="doc-file"><span>${esc(file.name)}</span><button type="button" aria-label="ลบ">✕</button></div>`);
    row.querySelector('button').onclick = () => {
      const next = [...docFiles(i, doc.key)];
      next.splice(index, 1);
      bk.docs[i] = { ...(bk.docs[i] || {}), [doc.key]: next };
      renderPassengerStep();
    };
    list.appendChild(row);
  });

  if (files.length < MAX_DOC_FILES) {
    const input = el(`<input type="file" accept="image/*,.pdf,application/pdf" multiple>`);
    input.onchange = () => {
      const picked = Array.from(input.files || []);
      input.value = '';
      const current = [...docFiles(i, doc.key)];
      for (const file of picked) {
        if (current.length >= MAX_DOC_FILES) { alert(`แนบได้สูงสุด ${MAX_DOC_FILES} ไฟล์ต่อเอกสารหนึ่งรายการ`); break; }
        if (file.size > MAX_DOC_MB * 1024 * 1024) { alert(`"${file.name}" ใหญ่เกิน ${MAX_DOC_MB} MB`); continue; }
        if (!file.type.startsWith('image/') && file.type !== 'application/pdf') {
          alert(`"${file.name}" ไม่ใช่ไฟล์รูปภาพหรือ PDF`);
          continue;
        }
        current.push(file);
      }
      bk.docs[i] = { ...(bk.docs[i] || {}), [doc.key]: current };
      renderPassengerStep();
    };
    wrap.appendChild(input);
  }
  return wrap;
}

/* --------- ตัวช่วยกรอก --------- */

let profileCache = null;

async function autoFillFromProfile(i) {
  try {
    if (!profileCache) profileCache = (await api('/auth/me')).data;
  } catch (e) {
    return alert('ดึงข้อมูลโปรไฟล์ไม่สำเร็จ: ' + e.message);
  }
  const user = profileCache?.user || profileCache;
  if (!user) return;

  const womenOnly = !!bk.trip?.is_women_only;
  const titleBlocked = womenOnly && user.title === 'นาย';
  applyTravellerData(i, user, titleBlocked);
  renderPassengerStep();
  if (titleBlocked) alert('ทริปนี้สำหรับผู้หญิงเท่านั้น ระบบดึงข้อมูลส่วนอื่นให้แล้ว ยกเว้นคำนำหน้า');
}

function applyTravellerData(i, src, titleBlocked) {
  const p = bk.passengers[i];
  Object.assign(p, {
    title: titleBlocked ? '' : (src.title || ''),
    name: src.name || '',
    nickname: src.nickname || '',
    id_card: src.id_card || '',
    name_en: src.name_en || '',
    nationality: src.nationality || 'TH',
    passport_no: src.passport_no || '',
    passport_expires_at: src.passport_expires_at ? String(src.passport_expires_at).slice(0, 10) : '',
    birth_date: src.birth_date ? String(src.birth_date).slice(0, 10) : '',
    phone: src.phone || '',
    email: src.email || p.email || '',
    blood_group: src.blood_group || '',
    emergency_contact: src.emergency_contact || '',
    emergency_phone: src.emergency_phone || '',
    allergies: src.allergies || '',
    health_notes: src.health_notes || '',
    halal_food: src.halal_food ?? p.halal_food,
  });
}

// ครอบครัว/คู่เดินทางมักใช้ผู้ติดต่อฉุกเฉิน จุดขึ้นรถ และอาหารชุดเดียวกัน
// คัดลอกเฉพาะช่องที่ใช้ร่วมกันได้จริง — ไม่แตะชื่อ/เลขบัตร/เบอร์ส่วนตัว
function copyFromFirst(i) {
  const first = bk.passengers[0];
  if (!first || i === 0) return;
  Object.assign(bk.passengers[i], {
    emergency_contact: first.emergency_contact || '',
    emergency_phone: first.emergency_phone || '',
    halal_food: first.halal_food,
    allergies: bk.passengers[i].allergies || first.allergies || '',
    health_notes: bk.passengers[i].health_notes || first.health_notes || '',
    pickup_point_id: first.pickup_point_id ?? bk.passengers[i].pickup_point_id,
  });
  renderPassengerStep();
}

async function openTravellerPicker(i) {
  const sheet = el(`<div class="sheet-overlay"><div class="sheet">
    <div class="sheet-head"><strong>สมุดผู้ร่วมเดินทาง</strong><button class="sheet-close" aria-label="ปิด">✕</button></div>
    <div class="sheet-body"><div class="loading-inline"><div class="spinner"></div></div></div>
  </div></div>`);
  sheet.onclick = (e) => { if (e.target === sheet) sheet.remove(); };
  sheet.querySelector('.sheet-close').onclick = () => sheet.remove();
  document.body.appendChild(sheet);

  const body = sheet.querySelector('.sheet-body');
  let travellers = [];
  try {
    travellers = (await api('/saved-travellers')).data || [];
  } catch (e) {
    body.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
    return;
  }

  body.innerHTML = '';
  if (!travellers.length) {
    body.appendChild(el(`<div class="empty">ยังไม่มีใครในสมุด<br>จองครั้งนี้เสร็จแล้วระบบจะถามว่าจะเก็บไว้ไหมครับ</div>`));
    return;
  }
  travellers.forEach((t) => {
    const row = el(`<button type="button" class="pick" style="width:100%;text-align:left">
      <div class="pick-body">
        <div class="pick-name">${esc(t.label || t.nickname || t.name)}</div>
        <div class="pick-sub">${esc(t.name || '')}${t.phone ? ' · ' + esc(t.phone) : ''}</div>
      </div>
    </button>`);
    row.onclick = () => {
      const womenOnly = !!bk.trip?.is_women_only;
      const titleBlocked = womenOnly && t.title === 'นาย';
      applyTravellerData(i, t, titleBlocked);
      sheet.remove();
      renderPassengerStep();
      // จัดลำดับ "ใช้ล่าสุดอยู่บนสุด" ครั้งถัดไป — ล้มเหลวได้โดยไม่กระทบการจอง
      api(`/saved-travellers/${t.id}/used`, { method: 'POST' }).catch(() => {});
      if (titleBlocked) alert('ทริปนี้รับเฉพาะผู้หญิง กรุณาเลือกคำนำหน้าอีกครั้ง');
    };
    body.appendChild(row);
  });
}

/* ================= ขั้นที่ 3: บริการเสริม + สรุป ================= */

// รายการเสริมของทริป — เอาทุกแถวที่มีชื่อ (เหมือนหน้าเว็บ) ไม่ใช่เฉพาะที่มีราคา
function addonItems() {
  const items = bk.trip?.must_know?.items;
  if (!Array.isArray(items)) return [];
  return items
    .map((it, index) => ({
      index,
      name: String(it?.name || '').trim(),
      price: Number(it?.price || 0),
      price_type: it?.price_type === 'per_person' ? 'per_person' : 'per_booking',
      image_url: String(it?.image_url || '').trim(),
    }))
    .filter((it) => it.name);
}

function rentalItems() {
  const items = bk.trip?.rental_items;
  if (!Array.isArray(items)) return [];
  return items
    .map((it, index) => ({
      index,
      name: String(it?.name || '').trim(),
      price: Number(it?.price || 0),
      description: String(it?.description || '').trim(),
      image_url: String(it?.image_url || '').trim(),
    }))
    .filter((it) => it.name);
}

// เพดานของรายการต่อคนคือจำนวนผู้เดินทาง ไม่งั้นหลังบ้านตีกลับ
const addonMaxQty = (item) => (item.price_type === 'per_person' ? Math.max(1, paxCount()) : ADDON_MAX_QTY);
const addonQty = (index) => bk.addons.get(index) || 0;
const rentalQty = (index) => bk.rentals.get(index) || 0;

function setAddonQty(index, qty) {
  const item = addonItems().find((it) => it.index === index);
  const clamped = Math.max(0, Math.min(qty, item ? addonMaxQty(item) : ADDON_MAX_QTY));
  if (clamped > 0) bk.addons.set(index, clamped);
  else bk.addons.delete(index);
  renderSummaryStep();
}

function setRentalQty(index, qty) {
  const clamped = Math.max(0, Math.min(qty, RENTAL_MAX_QTY));
  if (clamped > 0) bk.rentals.set(index, clamped);
  else bk.rentals.delete(index);
  renderSummaryStep();
}

// ลดจำนวนผู้เดินทางแล้วย้อนกลับมา จำนวนของรายการต่อคนต้องหดตาม
function clampAddonQuantities() {
  addonItems().forEach((item) => {
    const qty = addonQty(item.index);
    if (qty && qty > addonMaxQty(item)) bk.addons.set(item.index, addonMaxQty(item));
  });
}

const addonsTotal = () => addonItems().reduce((sum, it) => sum + it.price * addonQty(it.index), 0);
const rentalsTotal = () => rentalItems().reduce((sum, it) => sum + it.price * rentalQty(it.index), 0);

function openImageLightbox(url) {
  if (!url) return;
  const ov = el(`<div class="img-lightbox"><img src="${esc(url)}" alt=""><button type="button" class="img-lightbox-close" aria-label="ปิด">✕</button></div>`);
  ov.onclick = () => ov.remove();
  document.body.appendChild(ov);
}

function qtyRow(item, qty, max, unitLabel, onStep, extraClass) {
  const imageUrl = item.image_url;
  const thumb = imageUrl
    ? `<div class="pick-thumb"><img src="${esc(imageUrl)}" alt="" loading="lazy"><button type="button" class="pick-zoom" aria-label="ดูรูปใหญ่">⤢</button></div>`
    : '';
  const row = el(`<div class="pick ${extraClass} ${imageUrl ? 'pick-card' : ''} ${qty > 0 ? 'on' : ''}">
    ${thumb}
    <div class="pick-body">
      <div class="pick-name">${esc(item.name)}</div>
      <div class="pick-sub">${item.price > 0 ? baht(item.price) + ' ' + esc(unitLabel) : 'ฟรี'}${item.description ? ' · ' + esc(item.description) : ''}</div>
    </div>
    <div class="qty">
      <button type="button" class="qty-btn" data-step="-1" ${qty <= 0 ? 'disabled' : ''} aria-label="ลดจำนวน">−</button>
      <span class="qty-num">${qty}</span>
      <button type="button" class="qty-btn" data-step="1" ${qty >= max ? 'disabled' : ''} aria-label="เพิ่มจำนวน">+</button>
    </div>
  </div>`);
  row.querySelectorAll('.qty-btn').forEach((btn) => {
    btn.onclick = () => onStep(qty + Number(btn.dataset.step));
  });
  const zoom = row.querySelector('.pick-zoom');
  if (zoom) zoom.onclick = (e) => { e.preventDefault(); e.stopPropagation(); openImageLightbox(imageUrl); };
  return row;
}

function renderSummaryStep() {
  clampAddonQuantities();

  const node = el(`<div></div>`);
  node.appendChild(appbar('ตรวจสอบ & ยืนยัน', renderPassengerStep));
  const content = el(`<div class="content"></div>`);
  content.appendChild(stepDots(3));
  const lockBar = lockCountdownBar();
  if (lockBar) content.appendChild(lockBar);

  const addons = addonItems();
  if (addons.length) {
    content.appendChild(el(`<div class="section-heading">บริการเสริม</div>`));
    addons.forEach((item) => content.appendChild(qtyRow(
      item, addonQty(item.index), addonMaxQty(item),
      item.price_type === 'per_person' ? '/ คน' : '/ การจอง',
      (qty) => setAddonQty(item.index, qty), 'pick-addon',
    )));
  }

  const rentals = rentalItems();
  if (rentals.length) {
    content.appendChild(el(`<div class="section-heading">อุปกรณ์ให้เช่า</div>`));
    rentals.forEach((item) => content.appendChild(qtyRow(
      item, rentalQty(item.index), RENTAL_MAX_QTY, '/ ชิ้น',
      (qty) => setRentalQty(item.index, qty), 'pick-rental',
    )));
    content.appendChild(el(`<p class="muted">รับอุปกรณ์จากทีมงานในวันเดินทาง</p>`));
  }

  // จองเป็นกลุ่ม
  content.appendChild(el(`<div class="section-heading">รายละเอียดการจอง</div>`));
  const groupToggle = el(`<label class="pick${bk.isGroup ? ' on' : ''}">
    <input type="checkbox" ${bk.isGroup ? 'checked' : ''}>
    <div class="pick-body">
      <div class="pick-name">จองเป็นกลุ่ม / คณะ</div>
      <div class="pick-sub">ใส่ชื่อกลุ่มและโน้ตให้ทีมงานจัดที่นั่งและดูแลได้ตรงขึ้น</div>
    </div>
  </label>`);
  groupToggle.querySelector('input').onchange = (e) => { bk.isGroup = e.target.checked; renderSummaryStep(); };
  content.appendChild(groupToggle);

  if (bk.isGroup) {
    const groupFields = el(`<div class="card"><div class="body">
      <label class="field"><span>ชื่อกลุ่ม</span><input id="groupName" maxlength="255" value="${esc(bk.groupName)}"></label>
      <label class="field"><span>โน้ตถึงทีมงาน</span><textarea id="groupNotes" rows="2" maxlength="1000">${esc(bk.groupNotes)}</textarea></label>
    </div></div>`);
    groupFields.querySelector('#groupName').oninput = (e) => { bk.groupName = e.target.value; };
    groupFields.querySelector('#groupNotes').oninput = (e) => { bk.groupNotes = e.target.value; };
    content.appendChild(groupFields);
  }

  // โค้ดส่วนลด — ตรวจกับหลังบ้านก่อน จะได้เห็นส่วนลดจริงตั้งแต่ก่อนกดยืนยัน
  content.appendChild(el(`<div class="section-heading">โค้ดส่วนลด</div>`));
  content.appendChild(buildPromoBlock());

  const n = paxCount();
  content.appendChild(el(`<div class="section-heading">สรุปการจอง</div>`));
  content.appendChild(el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">ทริป</span><span class="v">${esc(bk.trip.title)}</span></div>
    <div class="kv"><span class="k">วันเดินทาง</span><span class="v">${thaiDate(bk.schedule.departure_date)}</span></div>
    ${bk.joinTrip ? '<div class="kv"><span class="k">รูปแบบ</span><span class="v">จอยทริป (ไม่ใช้ที่นั่งบนรถ)</span></div>' : ''}
    ${bk.selected.length ? `<div class="kv"><span class="k">ที่นั่ง</span><span class="v">${esc(bk.selected.join(', '))}</span></div>` : ''}
    ${selectedVehicleOption() ? `<div class="kv"><span class="k">ประเภทรถ</span><span class="v">${esc(selectedVehicleOption().label)}</span></div>` : ''}
    ${bk.customPickup ? `<div class="kv"><span class="k">จุดรับ (ปักหมุด)</span><span class="v">${esc(bk.customPickup.label)}</span></div>` : ''}
    <div class="kv"><span class="k">ค่าทริป (${n} คน)</span><span class="v">${baht(passengersSubtotal())}</span></div>
    ${addonLines()}
    ${rentalLines()}
    ${bk.promoData ? `<div class="kv"><span class="k">ส่วนลด ${esc(bk.promoCode)}</span><span class="v">-${baht(discountAmount())}</span></div>` : ''}
    <div class="kv total"><span class="k">ยอดรวม</span><span class="v price">${baht(estimateTotal())}</span></div>
  </div></div>`));

  if (bk.customPickup) {
    content.appendChild(el(`<p class="muted center">* จุดรับที่ปักหมุดเองรอทีมงานยืนยันราคาอีกครั้ง ยอดจริงอาจเปลี่ยน</p>`));
  }

  const banner = el(`<div></div>`);
  content.appendChild(banner);
  node.appendChild(content);

  const cta = el(`<div class="sticky-cta"><button class="btn" id="confirm">ยืนยันการจอง · ${baht(estimateTotal())}</button></div>`);
  node.appendChild(cta);
  render(node);

  cta.querySelector('#confirm').onclick = () => confirmTerms(banner, cta.querySelector('#confirm'));
}

function buildPromoBlock() {
  const wrap = el(`<div></div>`);
  if (bk.promoData) {
    const card = el(`<div class="card"><div class="body">
      <div class="kv"><span class="k">โค้ด ${esc(bk.promoCode)}</span><span class="v price">-${baht(discountAmount())}</span></div>
    </div></div>`);
    wrap.appendChild(card);
    const remove = el(`<button class="btn secondary" style="margin-top:8px">เอาโค้ดออก</button>`);
    remove.onclick = () => {
      bk.promoData = null;
      bk.promoCode = '';
      bk.promoInput = '';
      renderSummaryStep();
    };
    wrap.appendChild(remove);
    return wrap;
  }

  const row = el(`<div class="search-row">
    <input id="promo" placeholder="ใส่โค้ด (ถ้ามี)" value="${esc(bk.promoInput)}">
    <button class="filter-btn" id="applyPromo">ใช้โค้ด</button>
  </div>`);
  row.querySelector('#promo').oninput = (e) => { bk.promoInput = e.target.value.trim(); };
  row.querySelector('#applyPromo').onclick = () => applyPromotion(row.querySelector('#applyPromo'));
  wrap.appendChild(row);
  if (bk.promoError) wrap.appendChild(el(`<p class="field-error">${esc(bk.promoError)}</p>`));
  return wrap;
}

async function applyPromotion(btn) {
  if (!bk.promoInput) return;
  btn.disabled = true;
  btn.textContent = 'กำลังตรวจ…';
  bk.promoError = '';
  try {
    const res = await api('/promotions/validate', {
      method: 'POST',
      body: { code: bk.promoInput, trip_id: bk.trip.id },
    });
    if (res.data?.valid) {
      bk.promoData = res.data.promotion;
      bk.promoCode = res.data.promotion.code;
    } else {
      bk.promoError = 'โค้ดส่วนลดไม่ถูกต้องหรือหมดอายุแล้ว';
    }
  } catch (e) {
    bk.promoData = null;
    bk.promoCode = '';
    bk.promoError = e.message || 'โค้ดส่วนลดไม่ถูกต้องหรือหมดอายุแล้ว';
  }
  renderSummaryStep();
}

function addonLines() {
  return addonItems().map((item) => {
    const qty = addonQty(item.index);
    if (!qty) return '';
    return `<div class="kv"><span class="k">${esc(item.name)} ×${qty}</span><span class="v">${baht(item.price * qty)}</span></div>`;
  }).join('');
}

function rentalLines() {
  return rentalItems().map((item) => {
    const qty = rentalQty(item.index);
    if (!qty) return '';
    return `<div class="kv"><span class="k">${esc(item.name)} ×${qty}</span><span class="v">${baht(item.price * qty)}</span></div>`;
  }).join('');
}

/* --------- เงื่อนไขก่อนยืนยัน (ข้อความชุดเดียวกับหน้าเว็บ) --------- */

function confirmTerms(banner, btn) {
  const sheet = el(`<div class="sheet-overlay"><div class="sheet">
    <div class="sheet-head"><strong>เงื่อนไขก่อนยืนยันการจอง</strong><button class="sheet-close" aria-label="ปิด">✕</button></div>
    <div class="sheet-body">
      <p class="terms-head">การสำรองที่นั่ง และการเปลี่ยนแปลง</p>
      <ol class="terms">
        <li>เมื่อยืนยันสิทธิ์การเดินทางแล้ว ทีมงานขอสงวนสิทธิ์ในการคืนเงินมัดจำ / ค่าทริป<b>ทุกกรณี</b></li>
        <li>หากไม่สะดวกในวันดังกล่าว แจ้งเลื่อนได้ <b>1 ครั้ง</b> ล่วงหน้าอย่างน้อย <b>30 วัน</b> ก่อนวันเดินทางเดิม</li>
        <li>เปลี่ยนตัวผู้เดินทางได้ โดยแจ้งทีมงานล่วงหน้าอย่างน้อย <b>15 วัน</b></li>
      </ol>
      <div class="banner success">สรุปการจอง: ${paxCount()} ท่าน · ${baht(estimateTotal())}</div>
      <label class="pick"><input type="checkbox" id="agree">
        <div class="pick-body"><div class="pick-name">ข้าพเจ้าได้อ่านและยอมรับเงื่อนไขข้างต้นทุกข้อแล้ว</div></div>
      </label>
    </div>
    <div class="sheet-foot">
      <button class="btn secondary" id="termsCancel">ยกเลิก</button>
      <button class="btn" id="termsOk" disabled>ยืนยันและชำระเงิน</button>
    </div>
  </div></div>`);
  sheet.onclick = (e) => { if (e.target === sheet) sheet.remove(); };
  sheet.querySelector('.sheet-close').onclick = () => sheet.remove();
  sheet.querySelector('#termsCancel').onclick = () => sheet.remove();
  sheet.querySelector('#agree').onchange = (e) => { sheet.querySelector('#termsOk').disabled = !e.target.checked; };
  sheet.querySelector('#termsOk').onclick = () => { sheet.remove(); submitBooking(banner, btn); };
  document.body.appendChild(sheet);
}

/* --------- ส่งการจอง --------- */

async function submitBooking(banner, btn) {
  banner.innerHTML = '';
  btn.disabled = true;
  btn.textContent = 'กำลังจอง…';

  const usingCustomPickup = !bk.pickupId && !!bk.customPickup;
  const international = isInternationalTrip();

  const payload = {
    schedule_id: bk.schedule.id,
    // ปักหมุดเองแล้วห้ามส่งจุดรับตายตัวมาด้วย ไม่งั้นหลังบ้านจะจับคู่จุดตายตัว
    // แล้วมองข้ามหมุด (ข้อมูลหมุดจะไม่ถูกบันทึก)
    pickup_point_id: usingCustomPickup ? null : (bk.pickupId || null),
    vehicle_option_id: bk.vehicleOptionId,
    booking_for: bk.bookingFor,
    is_group: bk.isGroup,
    group_name: bk.isGroup ? bk.groupName : null,
    group_notes: bk.isGroup ? bk.groupNotes : null,
    passengers: bk.passengers.map((p) => {
      const thai = isThaiTraveller(p);
      return {
        title: p.title || null,
        name: String(p.name || '').trim(),
        nickname: String(p.nickname || '').trim(),
        // digitsOnly ใช้ได้เฉพาะเบอร์ไทย — เบอร์ต่างประเทศมี + นำหน้าที่ตัดทิ้งไม่ได้
        id_card: thai ? digitsOnly(p.id_card) : null,
        name_en: international ? String(p.name_en || '').trim().toUpperCase() : null,
        nationality: international ? (p.nationality || 'TH') : 'TH',
        passport_no: international ? String(p.passport_no || '').trim().toUpperCase() : null,
        passport_expires_at: international ? (p.passport_expires_at || null) : null,
        birth_date: p.birth_date || null,
        phone: thai ? digitsOnly(p.phone) : String(p.phone || '').trim(),
        email: p.email ? String(p.email).trim() : null,
        blood_group: p.blood_group || null,
        allergies: String(p.allergies || '').trim(),
        halal_food: !!p.halal_food,
        health_notes: String(p.health_notes || '').trim(),
        emergency_contact: String(p.emergency_contact || '').trim(),
        emergency_phone: thai ? digitsOnly(p.emergency_phone) : String(p.emergency_phone || '').trim(),
        dive_cert_level: p.dive_cert_level || null,
        cert_number: p.cert_number || null,
        weight: p.weight ? Number(p.weight) : null,
        // ปักหมุดเองเป็นระดับการจอง ไม่ผูกจุดรับรายคน
        pickup_point_id: usingCustomPickup ? null : (p.pickup_point_id || null),
      };
    }),
  };

  if (usingCustomPickup) {
    payload.custom_pickup_label = bk.customPickup.label;
    payload.custom_pickup_lat = bk.customPickup.lat;
    payload.custom_pickup_lng = bk.customPickup.lng;
    payload.custom_pickup_note = bk.customPickup.note;
  }
  if (bk.promoCode) payload.promotion_code = bk.promoCode;
  if (bk.joinTrip) payload.is_join_trip = true;
  if (hasSeatMap()) payload.seat_ids = bk.selected;

  const addons = [...bk.addons.entries()]
    .filter(([, quantity]) => quantity > 0)
    .map(([index, quantity]) => ({ index, quantity }));
  if (addons.length) payload.selected_addons = addons;

  const rentals = [...bk.rentals.entries()]
    .filter(([, quantity]) => quantity > 0)
    .map(([index, quantity]) => ({ index, quantity }));
  if (rentals.length) payload.selected_rentals = rentals;

  let booking;
  try {
    booking = (await api('/bookings', { method: 'POST', body: payload })).data;
  } catch (e) {
    banner.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
    btn.disabled = false;
    btn.textContent = 'ยืนยันการจอง · ' + baht(estimateTotal());
    return;
  }

  // จองแล้ว = ที่นั่งอยู่ในใบจอง ไม่ได้อยู่ที่ล็อกชั่วคราวอีกต่อไป
  stopLockCountdown();
  bk.lockExpiresAt = null;
  clearDraft();

  btn.textContent = 'กำลังแนบเอกสาร…';
  await uploadBookingDocuments(booking.booking_ref, booking.passengers);

  // ถามเรื่องสมุดผู้ร่วมเดินทางหลังจ่ายเงินเสร็จ ไม่ใช่ตอนนี้ — ระหว่างที่นั่งยังถูก
  // นับถอยหลังอยู่ ห้ามมีอะไรมาคั่นระหว่างลูกค้ากับ QR
  pendingSaveTravellers = bk.passengers.length >= 2 ? booking.booking_ref : null;

  showPayment(booking);
}

/**
 * ส่งไฟล์ขึ้นหลังการจองถูกสร้างแล้ว (ต้องมี booking_ref ก่อน)
 *
 * ล้มเหลวแล้วไม่ย้อนการจอง — เน็ตหลุดตอนอัปโหลดไม่ควรทำให้เสียการจอง
 * ตามไปแนบใหม่ได้จากหน้ารายละเอียดการจอง
 */
async function uploadBookingDocuments(bookingRef, createdPassengers) {
  const requirements = documentRequirements();
  if (!requirements.length) return;

  let failed = 0;
  for (let i = 0; i < bk.passengers.length; i++) {
    const passengerId = createdPassengers?.[i]?.id;
    if (!passengerId) continue;
    for (const doc of requirements) {
      for (const file of docFiles(i, doc.key)) {
        const form = new FormData();
        form.append('passenger_id', passengerId);
        form.append('requirement_key', doc.key);
        form.append('file', file);
        try {
          await api(`/bookings/${encodeURIComponent(bookingRef)}/documents`, { method: 'POST', body: form });
        } catch (_) { failed++; }
      }
    }
  }
  if (failed > 0) alert(`แนบเอกสารไม่สำเร็จ ${failed} ไฟล์ — แนบใหม่ได้ที่หน้ารายละเอียดการจองในแอปหรือเว็บไซต์`);
}

// เลขที่จองที่รอถามเรื่องสมุดผู้ร่วมเดินทาง — ตั้งตอนจองเสร็จ ถามตอนจ่ายเงินเสร็จ
let pendingSaveTravellers = null;

// ข้อมูลบัตร/สุขภาพของคนอื่น เก็บได้ต่อเมื่อเจ้าของการจองกดยืนยัน ไม่เก็บเงียบ ๆ
async function offerToSaveTravellers(bookingRef) {
  if (!bookingRef) return;
  const ok = await askConfirm(
    'เก็บผู้ร่วมเดินทางไว้ใช้รอบหน้าไหมครับ',
    'ครั้งหน้าเลือกจากสมุดได้เลย ไม่ต้องกรอกใหม่ทุกคน',
    'เก็บไว้', 'ไม่ต้อง',
  );
  if (!ok) return;
  try {
    await api(`/bookings/${encodeURIComponent(bookingRef)}/save-travellers`, { method: 'POST' });
  } catch (_) { /* เก็บไม่สำเร็จไม่กระทบการจอง */ }
}

/* ============================ ร่างการจอง ============================ */

// เก็บลง sessionStorage — ไฟล์เอกสารเก็บไม่ได้ จึงไม่อยู่ในร่าง
const draftKey = () => `llk_draft_${bk.schedule.id}${bk.joinTrip ? '_join' : ''}`;
const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000;

function saveDraft() {
  if (!bk) return;
  try {
    sessionStorage.setItem(draftKey(), JSON.stringify({
      savedAt: Date.now(),
      passengers: bk.passengers,
      pickupId: bk.pickupId,
      customPickup: bk.customPickup,
      addons: [...bk.addons.entries()],
      rentals: [...bk.rentals.entries()],
      promoCode: bk.promoCode,
      bookingFor: bk.bookingFor,
      isGroup: bk.isGroup,
      groupName: bk.groupName,
      groupNotes: bk.groupNotes,
      count: bk.count,
    }));
  } catch (_) { /* โควตาเต็ม — ร่างเป็นของแถม ไม่ใช่ของจำเป็น */ }
}

function clearDraft() {
  try { sessionStorage.removeItem(draftKey()); } catch (_) { /* ไม่มีอะไรต้องทำ */ }
}

async function restoreDraft() {
  let draft;
  try {
    draft = JSON.parse(sessionStorage.getItem(draftKey()) || 'null');
  } catch (_) { return; }
  if (!draft || Date.now() - Number(draft.savedAt || 0) > DRAFT_TTL_MS) return clearDraft();
  if (!Array.isArray(draft.passengers) || !draft.passengers.some((p) => p.name)) return;

  const useDraft = await askConfirm(
    'พบข้อมูลที่กรอกค้างไว้',
    'ใช้ข้อมูลผู้เดินทางที่กรอกไว้ก่อนหน้านี้ต่อไหมครับ',
    'ใช้ข้อมูลเดิม', 'เริ่มใหม่',
  );
  if (!useDraft) return clearDraft();

  bk.draftPassengers = draft.passengers;
  bk.passengers = [];
  bk.pickupId = draft.pickupId ?? null;
  bk.customPickup = draft.customPickup || null;
  bk.addons = new Map(draft.addons || []);
  bk.rentals = new Map(draft.rentals || []);
  bk.promoInput = draft.promoCode || '';
  bk.bookingFor = draft.bookingFor || 'self';
  bk.isGroup = !!draft.isGroup;
  bk.groupName = draft.groupName || '';
  bk.groupNotes = draft.groupNotes || '';
  if (!hasSeatMap()) bk.count = Math.min(Math.max(Number(draft.count) || 1, 1), maxPassengers());
  syncPassengerCount();
}
