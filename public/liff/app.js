/* Luilaykhao LIFF booking app — framework-free.
 *
 * Flow: liff.init → grab the LINE access token → exchange it at
 * POST /auth/line/liff for a Sanctum token → browse trips → book (seat map,
 * pickup, passengers, add-ons, promo) against the existing booking API.
 * The Sanctum token is kept in memory (and sessionStorage) and sent as a
 * Bearer header on every authenticated API call. */

const CFG = window.LIFF_CONFIG;
const API = CFG.apiBaseUrl;
const app = document.getElementById('app');

const state = {
  token: sessionStorage.getItem('llk_token') || null,
  profile: null, // { displayName, pictureUrl }
};

/* ----------------------------- API helper ----------------------------- */

async function api(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (auth && state.token) headers['Authorization'] = 'Bearer ' + state.token;

  const res = await fetch(API + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  let json = null;
  try { json = await res.json(); } catch (_) { /* non-JSON */ }

  if (!res.ok) {
    const msg = (json && (json.message || firstError(json))) || 'เกิดข้อผิดพลาด (' + res.status + ')';
    const err = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return json;
}

function firstError(json) {
  if (json && json.errors) {
    const first = Object.values(json.errors)[0];
    return Array.isArray(first) ? first[0] : first;
  }
  return null;
}

/* ----------------------------- helpers -------------------------------- */

const el = (html) => {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
};
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const baht = (n) => '฿' + Number(n || 0).toLocaleString('th-TH');
const thaiDate = (iso) => {
  if (!iso) return '-';
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
};

function render(node) {
  app.innerHTML = '';
  app.appendChild(node);
  window.scrollTo(0, 0);
}

function appbar(title, onBack) {
  const bar = el(`<div class="appbar"></div>`);
  if (onBack) {
    const b = el(`<button class="back" aria-label="ย้อนกลับ">‹</button>`);
    b.onclick = onBack;
    bar.appendChild(b);
  }
  bar.appendChild(el(`<h1>${esc(title)}</h1>`));
  if (state.profile) {
    const p = el(`<div class="profile" style="margin-left:auto">
      ${state.profile.pictureUrl ? `<img src="${esc(state.profile.pictureUrl)}" alt="">` : ''}
      <span class="name">${esc(state.profile.displayName || '')}</span>
    </div>`);
    bar.appendChild(p);
  }
  return bar;
}

function loading(text = 'กำลังโหลด…') {
  render(el(`<div class="screen-center"><div class="spinner"></div><p class="muted">${esc(text)}</p></div>`));
}

function errorScreen(message, retry) {
  const node = el(`<div class="screen-center">
    <p class="banner error">${esc(message)}</p>
  </div>`);
  if (retry) {
    const b = el(`<button class="btn" style="max-width:240px">ลองใหม่</button>`);
    b.onclick = retry;
    node.appendChild(b);
  }
  render(node);
}

/* ------------------------------- boot --------------------------------- */

async function boot() {
  if (!CFG.liffId || CFG.liffId.includes('__PUT_YOUR_LIFF_ID')) {
    return errorScreen('ยังไม่ได้ตั้งค่า LIFF ID — แก้ไขในไฟล์ config.js ก่อนใช้งาน');
  }
  try {
    await liff.init({ liffId: CFG.liffId });
  } catch (e) {
    return errorScreen('เริ่มต้น LIFF ไม่สำเร็จ: ' + e.message);
  }

  if (!liff.isLoggedIn()) {
    liff.login({ redirectUri: location.href });
    return; // browser redirects to LINE login
  }

  try {
    await authenticate();
  } catch (e) {
    return errorScreen('เข้าสู่ระบบไม่สำเร็จ: ' + e.message, boot);
  }

  showTrips();
}

async function authenticate() {
  // Cache the LINE profile for the appbar.
  try { state.profile = await liff.getProfile(); } catch (_) { /* non-fatal */ }

  const accessToken = liff.getAccessToken();
  if (!accessToken) throw new Error('ไม่พบ access token จาก LINE');

  const res = await api('/auth/line/liff', {
    method: 'POST',
    auth: false,
    body: { access_token: accessToken },
  });
  state.token = res.data.token;
  sessionStorage.setItem('llk_token', state.token);
}

/* --------------------------- screen: trips ---------------------------- */

async function showTrips() {
  loading('กำลังโหลดทริป…');
  let trips;
  try {
    const res = await api('/trips');
    trips = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);
  } catch (e) {
    return errorScreen(e.message, showTrips);
  }

  const node = el(`<div></div>`);
  node.appendChild(appbar('จองทริป'));
  const content = el(`<div class="content"></div>`);

  if (!trips.length) {
    content.appendChild(el(`<div class="empty">ยังไม่มีทริปเปิดจองในขณะนี้</div>`));
  }
  for (const t of trips) {
    const seatsTag = (t.seats_left != null && t.is_almost_full)
      ? `<span class="tag warn">เหลือ ${t.seats_left} ที่</span>` : '';
    const card = el(`<div class="card">
      ${t.cover_image ? `<img class="cover" src="${esc(t.cover_image)}" alt="">` : ''}
      <div class="body">
        <p class="title">${esc(t.title)}</p>
        <div class="meta">
          <span>📍 ${esc(t.location || '')}</span>
          <span>${t.duration_days || 1} วัน</span>
          ${seatsTag}
        </div>
        <div class="meta" style="margin-top:6px">
          <span class="price">${baht(t.min_price ?? t.price_per_person)}${(t.max_price && t.max_price !== t.min_price) ? ' +' : ''}</span>
        </div>
      </div>
    </div>`);
    card.onclick = () => showTrip(t.slug);
    content.appendChild(card);
  }
  node.appendChild(content);
  render(node);
}

/* ------------------------- screen: trip detail ------------------------ */

async function showTrip(slug) {
  loading();
  let trip, schedules;
  try {
    const tripRes = await api('/trips/' + encodeURIComponent(slug));
    trip = tripRes.data;
    const schRes = await api('/trips/' + encodeURIComponent(slug) + '/schedules');
    schedules = Array.isArray(schRes.data) ? schRes.data : (schRes.data?.data ?? []);
  } catch (e) {
    return errorScreen(e.message, () => showTrip(slug));
  }

  const node = el(`<div></div>`);
  node.appendChild(appbar('รายละเอียดทริป', showTrips));
  const content = el(`<div class="content"></div>`);

  if (trip.cover_image) content.appendChild(el(`<img class="hero" src="${esc(trip.cover_image)}" alt="">`));

  content.appendChild(el(`<h2 class="trip-title">${esc(trip.title)}</h2>`));
  content.appendChild(el(`<div class="meta" style="margin-bottom:4px">
    <span>📍 ${esc(trip.location || '')}</span>
    <span>${trip.duration_days || 1} วัน</span>
    ${trip.seats_left != null ? `<span>เหลือ ${trip.seats_left} ที่</span>` : ''}
  </div>`));

  if (trip.description) {
    content.appendChild(el(`<p class="trip-desc">${esc(trip.description)}</p>`));
  }

  if (Array.isArray(trip.highlights) && trip.highlights.length) {
    content.appendChild(el(`<div class="section-heading">ไฮไลต์</div>`));
    const ul = el(`<ul class="bullets"></ul>`);
    trip.highlights.forEach((h) => ul.appendChild(el(`<li>${esc(h)}</li>`)));
    content.appendChild(ul);
  }

  content.appendChild(el(`<div class="section-heading">รอบเดินทางที่เปิดจอง</div>`));
  const bookable = schedules.filter((s) => (s.available_seats == null || s.available_seats > 0) && s.status !== 'closed');
  if (!bookable.length) {
    content.appendChild(el(`<div class="empty">ยังไม่มีรอบที่เปิดจอง</div>`));
  }
  for (const s of bookable) {
    const row = el(`<div class="schedule">
      <div>
        <div class="date">${thaiDate(s.departure_date)}</div>
        <div class="sub">${s.available_seats != null ? 'เหลือ ' + s.available_seats + ' ที่' : ''} · ${baht(s.price)}</div>
      </div>
      <button class="btn" style="width:auto;padding:9px 16px;font-size:14px">จอง</button>
    </div>`);
    // Re-fetch the full schedule (pickup points + seats) when booking starts.
    row.querySelector('button').onclick = () => startBooking(trip, s.id);
    content.appendChild(row);
  }

  node.appendChild(content);
  render(node);
}

/* ===================== booking wizard (3 steps) ====================== */

let bk = null; // active booking draft

async function startBooking(trip, scheduleId) {
  loading('กำลังเตรียมข้อมูลการจอง…');
  try {
    const schedule = (await api('/schedules/' + scheduleId)).data;
    const seatData = (await api('/schedules/' + scheduleId + '/seats')).data;
    bk = {
      trip,
      schedule,
      seatData,
      selected: [],
      count: seatData.has_seat_map === false ? 1 : 0,
      pickupId: null,
      addons: new Set(),
      rentals: new Map(), // index ใน trip.rental_items -> จำนวนชิ้น
      promo: '',
      passengers: {}, // index -> passenger object
    };
    renderSeatStep();
  } catch (e) {
    errorScreen(e.message, () => startBooking(trip, scheduleId));
  }
}

function maxSelectable() {
  const avail = bk.seatData.available_seats;
  return avail == null ? 9 : Math.min(avail, 9);
}

// รอบที่บินไปไม่มีผังที่นั่ง — สายการบินจัดที่นั่งเอง แล้วทีมงานกรอกเลขที่นั่ง
// จริงกลับเข้าการจองทีหลัง รอบแบบนี้จึงถามแค่ "ไปกี่คน"
function hasSeatMap() {
  return bk.seatData.has_seat_map !== false;
}

// จำนวนผู้เดินทางของการจองนี้ — มาจากจำนวนที่นั่งที่เลือก หรือจากตัวนับเมื่อไม่มีผัง
function paxCount() {
  return hasSeatMap() ? bk.selected.length : bk.count;
}

/* --------- step 1: seat map + pickup point --------- */

function renderSeatStep() {
  const node = el(`<div></div>`);
  node.appendChild(appbar(hasSeatMap() ? 'เลือกที่นั่ง' : 'จำนวนผู้เดินทาง', () => showTrip(bk.trip.slug)));
  const content = el(`<div class="content"></div>`);

  content.appendChild(el(`<div class="step-dots"><span class="on"></span><span></span><span></span></div>`));

  if (hasSeatMap()) {
    // Seat map
    content.appendChild(buildSeatMap());

    // Legend
    content.appendChild(el(`<div class="legend">
      <span><i class="sw avail"></i> ว่าง</span>
      <span><i class="sw sel"></i> ที่เลือก</span>
      <span><i class="sw taken"></i> ไม่ว่าง</span>
    </div>`));
  } else {
    content.appendChild(buildPaxStepper());
  }

  // Pickup points — รอบที่บินไปไม่มีรถวิ่งรับ จุดนัดพบที่สนามบินมาแทน
  // (จุดรับที่ค้างไว้จากตอนรอบยังเป็นรถตู้ถูกมองข้าม backend ก็ทิ้งเหมือนกัน)
  const isFlightRound = bk.schedule.transport_type === 'flight';
  const points = isFlightRound ? [] : (bk.schedule.pickup_points || []);
  if (isFlightRound) {
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
  if (points.length) {
    content.appendChild(el(`<div class="section-heading">จุดรับ</div>`));
    const list = el(`<div></div>`);
    list.appendChild(pickupOption(null, 'ขึ้นที่จุดนัดหมายหลัก', bk.schedule.price, null));
    points.forEach((p) => list.appendChild(
      pickupOption(p.id, p.pickup_location || p.region_label || 'จุดรับ', p.price, p.pickup_time)
    ));
    content.appendChild(list);
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

// Column position of a seat, tolerating different layout key names.
const seatCol = (s) => Number(s.column ?? s.col ?? s.x ?? 0);
const seatRow = (s) => (s.row ?? s.y ?? null);

function buildSeatMap() {
  const wrap = el(`<div class="seatmap"></div>`);
  wrap.appendChild(el(`<div class="seatmap-head">${esc(bk.seatData.front_label || 'หน้ารถ')}</div>`));

  const seats = bk.seatData.seats || [];
  if (!seats.length) {
    wrap.appendChild(el(`<p class="muted center" style="padding:12px 0">ไม่พบผังที่นั่งของรอบนี้</p>`));
    return wrap;
  }

  // Render row-by-row so we never depend on a strict column grid (real vehicle
  // layouts use varying keys / gaps). When no row info exists, flow seats in
  // order. Each row centers its seats; the source order preserves the aisle.
  const grid = el(`<div class="seat-rows"></div>`);
  const hasRows = seats.some((s) => seatRow(s) != null);

  if (hasRows) {
    [...new Set(seats.map((s) => seatRow(s)))]
      .sort((a, b) => Number(a) - Number(b))
      .forEach((r) => {
        const rowEl = el(`<div class="seat-row"></div>`);
        seats.filter((s) => seatRow(s) === r)
          .sort((a, b) => seatCol(a) - seatCol(b))
          .forEach((s) => rowEl.appendChild(seatCell(s)));
        grid.appendChild(rowEl);
      });
  } else {
    const rowEl = el(`<div class="seat-row wrap"></div>`);
    seats.forEach((s) => rowEl.appendChild(seatCell(s)));
    grid.appendChild(rowEl);
  }

  wrap.appendChild(grid);
  return wrap;
}

function seatAvailable(seat) {
  if (seat.status === 'booked') return false;
  if (seat.status === 'locked' && !seat.locked_by_current_user) return false;
  return true;
}

function seatCell(seat) {
  const usable = seatAvailable(seat);
  // ที่นั่งที่ตัวเองถืออยู่ (ล็อกไว้ / อยู่ในใบจองของตัวเอง) ต้องไม่อ่านว่าเป็นของคนอื่น
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
  const cell = el(`<button class="seat${mine ? ' mine' : ''}" title="${esc(title)}" ${usable ? '' : 'disabled'}>${esc(seat.label || seat.id)}</button>`);
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
      return; // at limit
    }
    bk.selected.push(id);
  }
  cell.classList.toggle('selected', bk.selected.includes(id));
  refreshSeatStep();
}

function selectedPickup() {
  if (bk.pickupId == null) return null;
  return (bk.schedule.pickup_points || []).find((p) => p.id === bk.pickupId) || null;
}

function basePerPax() {
  const p = selectedPickup();
  return p && p.price ? Number(p.price) : Number(bk.schedule.price || 0);
}

function estimateTotal() {
  const n = paxCount();
  let total = basePerPax() * n;
  addonItems().forEach((item) => {
    if (!bk.addons.has(item.index)) return;
    const qty = (item.price_type === 'per_person') ? n : 1;
    total += Number(item.price || 0) * qty;
  });
  rentalItems().forEach((item) => {
    total += Number(item.price || 0) * rentalQty(item.index);
  });
  return total;
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
}

// ตัวนับจำนวนผู้เดินทางสำหรับรอบที่ไม่มีผังที่นั่ง
function buildPaxStepper() {
  const wrap = el(`<div class="card"><div class="body">
    <p class="muted" id="noSeatNote"></p>
    <div class="cta-row" style="margin-top:10px">
      <strong id="paxLabel"></strong>
      <span>
        <button class="btn secondary" id="paxMinus" style="width:auto;padding:6px 14px">−</button>
        <button class="btn secondary" id="paxPlus" style="width:auto;padding:6px 14px">+</button>
      </span>
    </div>
  </div></div>`);

  wrap.querySelector('#noSeatNote').textContent = bk.seatData.seat_selection_disabled_reason
    || 'รอบนี้ไม่ต้องเลือกที่นั่ง';

  const label = wrap.querySelector('#paxLabel');
  const sync = () => { label.textContent = `${bk.count} คน`; refreshSeatStep(); };
  wrap.querySelector('#paxMinus').onclick = () => {
    bk.count = Math.max(1, bk.count - 1);
    sync();
  };
  wrap.querySelector('#paxPlus').onclick = () => {
    bk.count = Math.min(maxSelectable(), bk.count + 1);
    sync();
  };
  sync();
  return wrap;
}

function pickupOption(id, label, price, time) {
  const opt = el(`<label class="pick">
    <input type="radio" name="pickup" ${id === bk.pickupId ? 'checked' : ''}>
    <div class="pick-body">
      <div class="pick-name">${esc(label)}</div>
      <div class="pick-sub">${time ? '🕖 ' + esc(time) + ' · ' : ''}${baht(price ?? bk.schedule.price)}</div>
    </div>
  </label>`);
  opt.querySelector('input').onchange = () => { bk.pickupId = id; refreshSeatStep(); };
  return opt;
}

/* --------- step 2: passengers (one per seat) --------- */

async function proceedToPassengers() {
  const next = document.getElementById('next');

  // ไม่มีผังที่นั่ง = ไม่มีอะไรให้ล็อก ข้ามไปกรอกข้อมูลผู้เดินทางได้เลย
  if (!hasSeatMap()) {
    renderPassengerStep();
    return;
  }

  next.disabled = true;
  next.textContent = 'กำลังจองที่นั่ง…';
  try {
    await api('/schedules/' + bk.schedule.id + '/seats/lock', {
      method: 'POST',
      body: { seat_ids: bk.selected, pickup_point_id: bk.pickupId },
    });
  } catch (e) {
    next.disabled = false;
    next.textContent = 'ถัดไป';
    alert(e.message); // seat taken / lock failed
    return;
  }
  renderPassengerStep();
}

function renderPassengerStep() {
  const node = el(`<div></div>`);
  node.appendChild(appbar('ข้อมูลผู้เดินทาง', renderSeatStep));
  const content = el(`<div class="content"></div>`);
  content.appendChild(el(`<div class="step-dots"><span></span><span class="on"></span><span></span></div>`));

  const womenOnly = !!bk.trip.is_women_only;
  for (let idx = 0; idx < paxCount(); idx += 1) {
    content.appendChild(passengerForm(bk.selected[idx] || null, idx, womenOnly));
  }

  node.appendChild(content);
  const cta = el(`<div class="sticky-cta"><button class="btn" id="toSummary">ถัดไป</button></div>`);
  node.appendChild(cta);
  render(node);

  cta.querySelector('#toSummary').onclick = () => {
    if (!collectPassengers()) return;
    renderSummaryStep();
  };
}

function passengerForm(seatId, idx, womenOnly) {
  // ทริปต่างประเทศต้องเก็บเอกสารเดินทางเพิ่ม ไม่งั้น API ตีกลับตอนกดจอง
  const international = !!bk.trip?.is_international;
  const titleOpts = womenOnly ? ['นาง', 'นางสาว'] : ['นาย', 'นาง', 'นางสาว'];
  const saved = bk.passengers[idx] || {};
  const prefillName = idx === 0 ? (saved.name || state.profile?.displayName || '') : (saved.name || '');
  const card = el(`<form class="card pax" data-idx="${idx}"><div class="body"></div></form>`);
  card.querySelector('.body').innerHTML = `
    <div class="pax-head">ผู้เดินทางคนที่ ${idx + 1}${seatId ? ` <span class="tag">ที่นั่ง ${esc(seatId)}</span>` : ''}</div>
    <label class="field"><span>คำนำหน้า</span>
      <select name="title">${titleOpts.map((t) => `<option ${saved.title === t ? 'selected' : ''}>${t}</option>`).join('')}</select>
    </label>
    <label class="field"><span>ชื่อ-นามสกุล</span>
      <input name="name" required value="${esc(prefillName)}" placeholder="ชื่อจริง นามสกุล"></label>
    <label class="field"><span>ชื่อเล่น</span>
      <input name="nickname" required value="${esc(saved.nickname || '')}" placeholder="ชื่อเล่น"></label>
    <label class="field"><span>เลขบัตรประชาชน (13 หลัก)</span>
      <input name="id_card" required inputmode="numeric" maxlength="13" value="${esc(saved.id_card || '')}"></label>
    ${international ? `
    <div class="pax-head">เอกสารเดินทาง</div>
    <label class="field"><span>ชื่อ-สกุลภาษาอังกฤษ (ตามพาสปอร์ต)</span>
      <input name="name_en" required maxlength="255" style="text-transform:uppercase" placeholder="SOMCHAI JAIDEE" value="${esc(saved.name_en || '')}"></label>
    <label class="field"><span>เลขที่พาสปอร์ต</span>
      <input name="passport_no" required maxlength="20" style="text-transform:uppercase" placeholder="AA1234567" value="${esc(saved.passport_no || '')}"></label>
    <label class="field"><span>วันหมดอายุพาสปอร์ต</span>
      <input name="passport_expires_at" type="date" required value="${esc(saved.passport_expires_at || '')}"></label>
    ` : ''}
    <div class="row2">
      <label class="field"><span>เบอร์โทร</span>
        <input name="phone" required inputmode="numeric" maxlength="10" value="${esc(saved.phone || '')}"></label>
      <label class="field"><span>กรุ๊ปเลือด</span>
        <select name="blood_group" required>
          <option value="">เลือก</option>
          ${['A', 'B', 'O', 'AB'].map((b) => `<option ${saved.blood_group === b ? 'selected' : ''}>${b}</option>`).join('')}
        </select></label>
    </div>
    <label class="field"><span>วันเกิด (ไม่บังคับ)</span>
      <input name="birth_date" type="date" value="${esc(saved.birth_date || '')}"></label>
    <div class="row2">
      <label class="field"><span>ผู้ติดต่อฉุกเฉิน</span>
        <input name="emergency_contact" required value="${esc(saved.emergency_contact || '')}"></label>
      <label class="field"><span>เบอร์ฉุกเฉิน</span>
        <input name="emergency_phone" required inputmode="numeric" maxlength="10" value="${esc(saved.emergency_phone || '')}"></label>
    </div>
    <label class="field"><span>อาหารฮาลาล</span>
      <select name="halal_food"><option value="0">ไม่</option><option value="1" ${saved.halal_food ? 'selected' : ''}>ใช่</option></select></label>
    <label class="field"><span>แพ้อาหาร/ยา (ถ้ามี)</span>
      <textarea name="allergies" rows="2">${esc(saved.allergies || '')}</textarea></label>
  `;
  return card;
}

function collectPassengers() {
  const forms = [...document.querySelectorAll('form.pax')];
  for (const form of forms) {
    if (!form.reportValidity()) return false;
  }
  forms.forEach((form) => {
    const idx = Number(form.dataset.idx);
    const fd = new FormData(form);
    const p = {
      title: fd.get('title'),
      name: fd.get('name'),
      nickname: fd.get('nickname'),
      id_card: fd.get('id_card'),
      phone: fd.get('phone'),
      blood_group: fd.get('blood_group'),
      halal_food: fd.get('halal_food') === '1',
      emergency_contact: fd.get('emergency_contact'),
      emergency_phone: fd.get('emergency_phone'),
      allergies: fd.get('allergies') || null,
    };
    if (bk.trip?.is_international) {
      p.name_en = String(fd.get('name_en') || '').trim().toUpperCase();
      p.passport_no = String(fd.get('passport_no') || '').trim().toUpperCase();
      p.passport_expires_at = fd.get('passport_expires_at') || null;
    }
    const bd = fd.get('birth_date');
    if (bd) p.birth_date = bd;
    bk.passengers[idx] = p;
  });
  return true;
}

/* --------- step 3: add-ons + promo + summary --------- */

function addonItems() {
  const items = bk.trip?.must_know?.items;
  if (!Array.isArray(items)) return [];
  // Treat only priced entries as bookable add-ons (plain info rows have no price),
  // but keep each entry's ORIGINAL index — that's what the API validates against.
  return items
    .map((it, index) => ({ ...it, index }))
    .filter((it) => it && it.name && Number(it.price) > 0);
}

// อุปกรณ์ให้เช่า (trip.rental_items) — คิดเป็นชิ้น เลือกจำนวนได้
function rentalItems() {
  const items = bk.trip?.rental_items;
  if (!Array.isArray(items)) return [];
  return items
    .map((it, index) => ({ ...it, index }))
    .filter((it) => it && it.name);
}

const RENTAL_MAX_QTY = 20;

function rentalQty(index) {
  return bk.rentals.get(index) || 0;
}

function setRentalQty(index, qty) {
  const clamped = Math.max(0, Math.min(qty, RENTAL_MAX_QTY));
  if (clamped > 0) bk.rentals.set(index, clamped);
  else bk.rentals.delete(index);
  renderSummaryStep();
}

function openImageLightbox(url) {
  if (!url) return;
  const ov = el(`<div class="img-lightbox"><img src="${esc(url)}" alt=""><button type="button" class="img-lightbox-close" aria-label="ปิด">✕</button></div>`);
  ov.onclick = () => ov.remove();
  document.body.appendChild(ov);
}

function renderSummaryStep() {
  const node = el(`<div></div>`);
  node.appendChild(appbar('ตรวจสอบ & ยืนยัน', renderPassengerStep));
  const content = el(`<div class="content"></div>`);
  content.appendChild(el(`<div class="step-dots"><span></span><span></span><span class="on"></span></div>`));

  // Add-ons
  const addons = addonItems();
  if (addons.length) {
    content.appendChild(el(`<div class="section-heading">บริการเสริม</div>`));
    addons.forEach((item) => {
      const idx = item.index;
      const checked = bk.addons.has(idx);
      const imageUrl = (item.image_url || '').toString().trim();
      const thumb = imageUrl
        ? `<div class="pick-thumb"><img src="${esc(imageUrl)}" alt="" loading="lazy"><button type="button" class="pick-zoom" aria-label="ดูรูปใหญ่">⤢</button></div>`
        : '';
      const opt = el(`<label class="pick ${imageUrl ? 'pick-card' : ''} ${checked ? 'on' : ''}">
        <input type="checkbox" ${checked ? 'checked' : ''}>
        ${thumb}
        <div class="pick-body">
          <div class="pick-name">${esc(item.name)}</div>
          <div class="pick-sub">${baht(item.price)} ${item.price_type === 'per_person' ? '/ คน' : '/ การจอง'}</div>
        </div>
      </label>`);
      opt.querySelector('input').onchange = (e) => {
        e.target.checked ? bk.addons.add(idx) : bk.addons.delete(idx);
        renderSummaryStep();
      };
      const zoom = opt.querySelector('.pick-zoom');
      if (zoom) {
        zoom.onclick = (e) => { e.preventDefault(); e.stopPropagation(); openImageLightbox(imageUrl); };
      }
      content.appendChild(opt);
    });
  }

  // อุปกรณ์ให้เช่า
  const rentals = rentalItems();
  if (rentals.length) {
    content.appendChild(el(`<div class="section-heading">อุปกรณ์ให้เช่า</div>`));
    rentals.forEach((item) => {
      const idx = item.index;
      const qty = rentalQty(idx);
      const imageUrl = (item.image_url || '').toString().trim();
      const thumb = imageUrl
        ? `<div class="pick-thumb"><img src="${esc(imageUrl)}" alt="" loading="lazy"><button type="button" class="pick-zoom" aria-label="ดูรูปใหญ่">⤢</button></div>`
        : '';
      const row = el(`<div class="pick pick-rental ${qty > 0 ? 'on' : ''}">
        ${thumb}
        <div class="pick-body">
          <div class="pick-name">${esc(item.name)}</div>
          <div class="pick-sub">${baht(item.price)} / ชิ้น${item.description ? ' · ' + esc(item.description) : ''}</div>
        </div>
        <div class="qty">
          <button type="button" class="qty-btn" data-step="-1" ${qty <= 0 ? 'disabled' : ''} aria-label="ลดจำนวน">−</button>
          <span class="qty-num">${qty}</span>
          <button type="button" class="qty-btn" data-step="1" ${qty >= RENTAL_MAX_QTY ? 'disabled' : ''} aria-label="เพิ่มจำนวน">+</button>
        </div>
      </div>`);
      row.querySelectorAll('.qty-btn').forEach((btn) => {
        btn.onclick = () => setRentalQty(idx, rentalQty(idx) + Number(btn.dataset.step));
      });
      const zoom = row.querySelector('.pick-zoom');
      if (zoom) {
        zoom.onclick = (e) => { e.preventDefault(); e.stopPropagation(); openImageLightbox(imageUrl); };
      }
      content.appendChild(row);
    });
    content.appendChild(el(`<p class="muted">รับอุปกรณ์จากทีมงานในวันเดินทาง</p>`));
  }

  // Promotion
  content.appendChild(el(`<div class="section-heading">โค้ดส่วนลด</div>`));
  const promo = el(`<input id="promo" placeholder="ใส่โค้ด (ถ้ามี)" value="${esc(bk.promo)}">`);
  promo.oninput = (e) => { bk.promo = e.target.value.trim(); };
  content.appendChild(promo);

  // Price summary
  const n = paxCount();
  content.appendChild(el(`<div class="section-heading">สรุปการจอง</div>`));
  const sum = el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">ทริป</span><span class="v">${esc(bk.trip.title)}</span></div>
    <div class="kv"><span class="k">วันเดินทาง</span><span class="v">${thaiDate(bk.schedule.departure_date)}</span></div>
    ${bk.selected.length ? `<div class="kv"><span class="k">ที่นั่ง</span><span class="v">${esc(bk.selected.join(', '))}</span></div>` : ''}
    <div class="kv"><span class="k">ค่าทริป (${n} คน)</span><span class="v">${baht(basePerPax() * n)}</span></div>
    ${addonLines()}
    ${rentalLines()}
    <div class="kv total"><span class="k">ยอดประมาณ</span><span class="v price">${baht(estimateTotal())}</span></div>
  </div></div>`);
  content.appendChild(sum);
  if (bk.promo) content.appendChild(el(`<p class="muted center" style="margin-top:4px">* ส่วนลดจากโค้ดจะคำนวณตอนยืนยัน</p>`));

  const banner = el(`<div></div>`);
  content.appendChild(banner);
  node.appendChild(content);

  const cta = el(`<div class="sticky-cta"><button class="btn" id="confirm">ยืนยันการจอง · ${baht(estimateTotal())}</button></div>`);
  node.appendChild(cta);
  render(node);

  cta.querySelector('#confirm').onclick = () => submitBooking(banner, cta.querySelector('#confirm'));
}

function addonLines() {
  const n = paxCount();
  return addonItems().map((item) => {
    if (!bk.addons.has(item.index)) return '';
    const qty = item.price_type === 'per_person' ? n : 1;
    return `<div class="kv"><span class="k">${esc(item.name)}</span><span class="v">${baht(Number(item.price) * qty)}</span></div>`;
  }).join('');
}

function rentalLines() {
  return rentalItems().map((item) => {
    const qty = rentalQty(item.index);
    if (!qty) return '';
    return `<div class="kv"><span class="k">${esc(item.name)} ×${qty}</span><span class="v">${baht(Number(item.price || 0) * qty)}</span></div>`;
  }).join('');
}

async function submitBooking(banner, btn) {
  banner.innerHTML = '';
  btn.disabled = true;
  btn.textContent = 'กำลังจอง…';

  const passengers = Array.from({ length: paxCount() }, (_, idx) => ({
    ...bk.passengers[idx],
    pickup_point_id: bk.pickupId || undefined,
  }));

  const payload = {
    schedule_id: bk.schedule.id,
    seat_ids: bk.selected,
    booking_for: 'self',
    passengers,
  };
  if (bk.pickupId) payload.pickup_point_id = bk.pickupId;
  if (bk.promo) payload.promotion_code = bk.promo;
  const addons = [...bk.addons].map((index) => ({ index }));
  if (addons.length) payload.selected_addons = addons;
  const rentals = [...bk.rentals.entries()]
    .filter(([, quantity]) => quantity > 0)
    .map(([index, quantity]) => ({ index, quantity }));
  if (rentals.length) payload.selected_rentals = rentals;

  try {
    const res = await api('/bookings', { method: 'POST', body: payload });
    showConfirmation(res.data);
  } catch (e) {
    banner.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
    btn.disabled = false;
    btn.textContent = 'ยืนยันการจอง · ' + baht(estimateTotal());
  }
}

/* ----------------------- screen: confirmation ------------------------- */

function showConfirmation(booking) {
  const ref = booking?.booking_ref || '-';
  const total = booking?.total_amount ?? estimateTotal();

  const node = el(`<div></div>`);
  node.appendChild(appbar('จองสำเร็จ'));
  const content = el(`<div class="content"></div>`);
  content.appendChild(el(`<div class="banner success">จองสำเร็จแล้ว! กรุณาชำระเงินเพื่อยืนยันที่นั่ง</div>`));
  content.appendChild(el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">ทริป</span><span class="v">${esc(bk.trip.title)}</span></div>
    <div class="kv"><span class="k">วันเดินทาง</span><span class="v">${thaiDate(bk.schedule.departure_date)}</span></div>
    ${bk.selected.length ? `<div class="kv"><span class="k">ที่นั่ง</span><span class="v">${esc(bk.selected.join(', '))}</span></div>` : ''}
    <div class="kv"><span class="k">เลขที่จอง</span><span class="v">${esc(ref)}</span></div>
    <div class="kv total"><span class="k">ยอดที่ต้องชำระ</span><span class="v price">${baht(total)}</span></div>
  </div></div>`));
  content.appendChild(el(`<p class="muted center" style="margin-top:8px">โอนเงินแล้วแนบสลิปในหน้า "การจองของฉัน" เพื่อให้ทีมงานยืนยัน</p>`));

  const back = el(`<button class="btn secondary" style="margin-top:16px">กลับไปหน้าทริป</button>`);
  back.onclick = showTrips;
  content.appendChild(back);

  node.appendChild(content);
  render(node);
}

/* ------------------------------- start -------------------------------- */
boot();
