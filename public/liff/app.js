/* Luilaykhao LIFF booking app — framework-free.
 *
 * Flow: liff.init → grab the LINE access token → exchange it at
 * POST /auth/line/liff for a Sanctum token → browse trips → book.
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
    throw new Error(msg);
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
  node.appendChild(appbar(trip.title, showTrips));
  const content = el(`<div class="content"></div>`);

  if (trip.cover_image) content.appendChild(el(`<img class="cover" style="border-radius:16px" src="${esc(trip.cover_image)}" alt="">`));
  content.appendChild(el(`<p class="muted" style="margin-top:12px">${esc(trip.description || '')}</p>`));

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
    row.querySelector('button').onclick = () => showBooking(trip, s);
    content.appendChild(row);
  }

  node.appendChild(content);
  render(node);
}

/* -------------------------- screen: booking --------------------------- */

function showBooking(trip, schedule) {
  const node = el(`<div></div>`);
  node.appendChild(appbar('ข้อมูลผู้เดินทาง', () => showTrip(trip.slug)));
  const content = el(`<div class="content"></div>`);

  content.appendChild(el(`<div class="card"><div class="body">
    <p class="title">${esc(trip.title)}</p>
    <div class="meta"><span>${thaiDate(schedule.departure_date)}</span><span class="price">${baht(schedule.price)}</span></div>
  </div></div>`));

  const womenOnly = !!trip.is_women_only;
  const titleOpts = womenOnly
    ? ['นาง', 'นางสาว']
    : ['นาย', 'นาง', 'นางสาว'];

  const form = el(`<form id="bk"></form>`);
  form.innerHTML = `
    <label class="field"><span>คำนำหน้า</span>
      <select name="title" required>${titleOpts.map((t) => `<option>${t}</option>`).join('')}</select>
    </label>
    <label class="field"><span>ชื่อ-นามสกุล</span>
      <input name="name" required value="${esc(state.profile?.displayName || '')}" placeholder="ชื่อจริง นามสกุล">
    </label>
    <label class="field"><span>ชื่อเล่น</span>
      <input name="nickname" required placeholder="ชื่อเล่น">
    </label>
    <label class="field"><span>เลขบัตรประชาชน (13 หลัก)</span>
      <input name="id_card" required inputmode="numeric" maxlength="13" placeholder="x xxxx xxxxx xx x">
    </label>
    <div class="row2">
      <label class="field"><span>เบอร์โทร</span>
        <input name="phone" required inputmode="numeric" maxlength="10" placeholder="08xxxxxxxx">
      </label>
      <label class="field"><span>กรุ๊ปเลือด</span>
        <select name="blood_group" required>
          <option value="">เลือก</option><option>A</option><option>B</option><option>O</option><option>AB</option>
        </select>
      </label>
    </div>
    <label class="field"><span>วันเกิด (ไม่บังคับ)</span>
      <input name="birth_date" type="date">
    </label>
    <div class="row2">
      <label class="field"><span>ผู้ติดต่อฉุกเฉิน</span>
        <input name="emergency_contact" required placeholder="ชื่อผู้ติดต่อ">
      </label>
      <label class="field"><span>เบอร์ฉุกเฉิน</span>
        <input name="emergency_phone" required inputmode="numeric" maxlength="10" placeholder="08xxxxxxxx">
      </label>
    </div>
    <label class="field"><span>อาหารฮาลาล</span>
      <select name="halal_food"><option value="0">ไม่</option><option value="1">ใช่</option></select>
    </label>
    <label class="field"><span>แพ้อาหาร/ยา (ถ้ามี)</span>
      <textarea name="allergies" rows="2" placeholder="ระบุหากมี"></textarea>
    </label>
  `;
  content.appendChild(form);

  const banner = el(`<div></div>`);
  content.appendChild(banner);
  node.appendChild(content);

  const cta = el(`<div class="sticky-cta"><button class="btn" id="submit">ยืนยันการจอง</button></div>`);
  node.appendChild(cta);
  render(node);

  cta.querySelector('#submit').onclick = async () => {
    banner.innerHTML = '';
    const fd = new FormData(form);
    if (!form.reportValidity()) return;

    const passenger = {
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
    const bd = fd.get('birth_date');
    if (bd) passenger.birth_date = bd;

    const btn = cta.querySelector('#submit');
    btn.disabled = true;
    btn.textContent = 'กำลังจอง…';
    try {
      const res = await api('/bookings', {
        method: 'POST',
        body: {
          schedule_id: schedule.id,
          booking_for: 'self',
          passengers: [passenger],
        },
      });
      showConfirmation(trip, schedule, res.data);
    } catch (e) {
      banner.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
      btn.disabled = false;
      btn.textContent = 'ยืนยันการจอง';
    }
  };
}

/* ----------------------- screen: confirmation ------------------------- */

function showConfirmation(trip, schedule, booking) {
  const ref = booking?.booking_ref || booking?.ref || '-';
  const total = booking?.total_amount ?? booking?.amount ?? schedule.price;

  const node = el(`<div></div>`);
  node.appendChild(appbar('จองสำเร็จ'));
  const content = el(`<div class="content"></div>`);
  content.appendChild(el(`<div class="banner success">จองสำเร็จแล้ว! กรุณาชำระเงินเพื่อยืนยันที่นั่ง</div>`));
  content.appendChild(el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">ทริป</span><span class="v">${esc(trip.title)}</span></div>
    <div class="kv"><span class="k">วันเดินทาง</span><span class="v">${thaiDate(schedule.departure_date)}</span></div>
    <div class="kv"><span class="k">เลขที่จอง</span><span class="v">${esc(ref)}</span></div>
    <div class="kv"><span class="k">ยอดที่ต้องชำระ</span><span class="v price">${baht(total)}</span></div>
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
