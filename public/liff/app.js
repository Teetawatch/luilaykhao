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
  // FormData ต้องปล่อยให้เบราว์เซอร์ใส่ boundary เอง — ตั้ง Content-Type เมื่อไหร่
  // ไฟล์สลิปจะไปไม่ถึงเซิร์ฟเวอร์โดยไม่มีข้อความบอก
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
  const headers = { Accept: 'application/json' };
  if (body && !isForm) headers['Content-Type'] = 'application/json';
  if (auth && state.token) headers['Authorization'] = 'Bearer ' + state.token;

  const res = await fetch(API + path, {
    method,
    headers,
    body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
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

// นาที:วินาที สำหรับตัวนับถอยหลังทุกตัวในแอป
const mmss = (seconds) => {
  const s = Math.max(0, Math.floor(seconds));
  return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
};

/**
 * กล่องยืนยันแบบแผ่นเลื่อน — แทน confirm() ที่เว็บวิวบางตัวบล็อกทิ้งเงียบ ๆ
 * และหน้าตาไม่เข้ากับที่เหลือทั้งแอป คืน Promise<boolean>
 */
function askConfirm(title, message, okLabel = 'ตกลง', cancelLabel = 'ยกเลิก') {
  return new Promise((resolve) => {
    const sheet = el(`<div class="sheet-overlay"><div class="sheet">
      <div class="sheet-head"><strong>${esc(title)}</strong><button class="sheet-close" aria-label="ปิด">✕</button></div>
      <div class="sheet-body"><p class="ask-text">${esc(message)}</p></div>
      <div class="sheet-foot">
        <button class="btn secondary" data-role="no">${esc(cancelLabel)}</button>
        <button class="btn" data-role="yes">${esc(okLabel)}</button>
      </div>
    </div></div>`);
    const done = (value) => { sheet.remove(); resolve(value); };
    sheet.onclick = (e) => { if (e.target === sheet) done(false); };
    sheet.querySelector('.sheet-close').onclick = () => done(false);
    sheet.querySelector('[data-role="no"]').onclick = () => done(false);
    sheet.querySelector('[data-role="yes"]').onclick = () => done(true);
    document.body.appendChild(sheet);
  });
}

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

  routeFromEntry();
}

/**
 * พารามิเตอร์ที่เปิดแอปมา — รองรับทั้งลิงก์ตรงและ Rich Menu
 *
 * เปิดผ่าน `liff.line.me/{id}?trip=slug` LINE จะยัดของเดิมมาที่ `liff.state`
 * อีกชั้นหนึ่ง ไม่ใช่ที่ query ของหน้า จึงต้องแกะสองชั้น
 */
function entryParams() {
  const params = new URLSearchParams(location.search);
  const state = params.get('liff.state');
  if (!state) return params;
  const query = state.includes('?') ? state.slice(state.indexOf('?') + 1) : state.replace(/^\?/, '');
  return new URLSearchParams(query);
}

// เข้ามาจากลิงก์ที่เพื่อนแชร์ / Rich Menu ต้องลงหน้าที่ตั้งใจ ไม่ใช่หน้ารวมทริปเสมอ
function routeFromEntry() {
  const params = entryParams();
  const bookingRef = params.get('booking');
  const tripSlug = params.get('trip');
  const page = params.get('page');

  if (bookingRef) return showBookingDetail(bookingRef);
  if (page === 'bookings') return showMyBookings();
  if (tripSlug) return showTrip(tripSlug);
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

/* ตัวกรองของหน้ารวมทริป — ชุดเดียวกับที่หน้าเว็บส่งไปที่ GET /trips
 * (destination/region/country/type/difficulty/min_days/max_days/search/sort)
 * เก็บไว้นอกฟังก์ชันเพื่อให้กดย้อนกลับจากหน้าทริปแล้วยังอยู่ที่ผลค้นหาเดิม */
const tripFilters = {
  search: '',
  destination: '',
  region: '',
  country: '',
  type: '',
  difficulty: '',
  min_days: '',
  max_days: '',
  sort: '',
};

const tripFeed = { items: [], page: 1, lastPage: 1, total: 0 };

const DIFFICULTIES = [
  { value: 'easy', label: 'ง่าย' },
  { value: 'moderate', label: 'ปานกลาง' },
  { value: 'hard', label: 'ยาก' },
  { value: 'expert', label: 'ท้าทาย' },
];

const DURATIONS = [
  { key: '1', label: 'ไปเช้า-เย็นกลับ', min_days: 1, max_days: 1 },
  { key: '2-3', label: '2-3 วัน', min_days: 2, max_days: 3 },
  { key: '4+', label: '4 วันขึ้นไป', min_days: 4, max_days: '' },
];

const SORTS = [
  { value: '', label: 'ยอดนิยม' },
  { value: 'price_asc', label: 'ราคาน้อย→มาก' },
  { value: 'price_desc', label: 'ราคามาก→น้อย' },
];

// โหลดครั้งเดียวต่อการเปิดแอป — ล้มก็แค่ไม่มีตัวกรองนั้นให้เลือก ยังค้นหาได้
let tripCategories = null;
let tripDestinations = null;

async function loadFilterOptions() {
  if (tripCategories && tripDestinations) return;
  const [cats, dests] = await Promise.all([
    api('/categories', { auth: false }).catch(() => null),
    api('/trips/destinations', { auth: false }).catch(() => null),
  ]);
  tripCategories = cats?.data || [];
  tripDestinations = dests?.data || null;
}

function activeFilterCount() {
  return ['destination', 'region', 'country', 'type', 'difficulty', 'min_days', 'sort']
    .filter((key) => tripFilters[key] !== '' && tripFilters[key] != null).length;
}

function tripQuery(page) {
  const params = new URLSearchParams();
  Object.entries(tripFilters).forEach(([key, value]) => {
    if (value !== '' && value != null) params.set(key, value);
  });
  params.set('page', page);
  params.set('per_page', 12);
  return '?' + params.toString();
}

async function fetchTrips(page) {
  const res = await api('/trips' + tripQuery(page));
  const items = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);
  return {
    items,
    page: res.meta?.current_page ?? page,
    lastPage: res.meta?.last_page ?? page,
    total: res.meta?.total ?? items.length,
  };
}

async function showTrips(keepFeed = false) {
  if (!keepFeed) {
    loading('กำลังโหลดทริป…');
    try {
      const feed = await fetchTrips(1);
      Object.assign(tripFeed, feed);
    } catch (e) {
      return errorScreen(e.message, () => showTrips());
    }
  }

  const node = el(`<div></div>`);
  node.appendChild(appbar('จองทริป'));
  const content = el(`<div class="content"></div>`);

  const mine = el(`<button class="btn secondary linkrow">📋 การจองของฉัน</button>`);
  mine.onclick = showMyBookings;
  content.appendChild(mine);

  // ค้นหา + ตัวกรอง
  const searchRow = el(`<div class="search-row">
    <input id="tripSearch" placeholder="ค้นหาทริป เช่น เขาใหญ่ ปาย" value="${esc(tripFilters.search)}">
    <button class="filter-btn" id="openFilters">ตัวกรอง${activeFilterCount() ? ` <span class="badge">${activeFilterCount()}</span>` : ''}</button>
  </div>`);
  const searchInput = searchRow.querySelector('#tripSearch');
  let searchTimer = null;
  searchInput.oninput = (e) => {
    tripFilters.search = e.target.value.trim();
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reloadTrips(true), 450);
  };
  searchRow.querySelector('#openFilters').onclick = openTripFilters;
  content.appendChild(searchRow);

  const chips = activeFilterChips();
  if (chips) content.appendChild(chips);

  content.appendChild(el(`<p class="muted result-count">พบ ${tripFeed.total} ทริป</p>`));

  const list = el(`<div id="tripList"></div>`);
  content.appendChild(list);
  renderTripCards(list);

  node.appendChild(content);
  render(node);

  // พิมพ์ค้นหาแล้วเรนเดอร์ใหม่ ต้องคืนเคอร์เซอร์กลับไปที่ช่องเดิม
  if (tripFilters.search && keepFeed) {
    const input = document.getElementById('tripSearch');
    if (input) {
      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
    }
  }
}

async function reloadTrips(keepFocus = false) {
  try {
    Object.assign(tripFeed, await fetchTrips(1));
  } catch (e) {
    return errorScreen(e.message, () => showTrips());
  }
  showTrips(keepFocus);
}

function renderTripCards(list) {
  list.innerHTML = '';
  if (!tripFeed.items.length) {
    list.appendChild(el(`<div class="empty">ไม่พบทริปที่ตรงกับที่ค้นหา<br>ลองลดตัวกรองลงดูครับ</div>`));
    return;
  }

  tripFeed.items.forEach((t) => list.appendChild(tripCard(t)));

  if (tripFeed.page < tripFeed.lastPage) {
    const more = el(`<button class="btn secondary" style="margin-top:6px">โหลดทริปเพิ่ม</button>`);
    more.onclick = async () => {
      more.disabled = true;
      more.textContent = 'กำลังโหลด…';
      try {
        const next = await fetchTrips(tripFeed.page + 1);
        tripFeed.items = tripFeed.items.concat(next.items);
        tripFeed.page = next.page;
        tripFeed.lastPage = next.lastPage;
        renderTripCards(list);
      } catch (e) {
        more.disabled = false;
        more.textContent = 'โหลดทริปเพิ่ม';
        alert(e.message);
      }
    };
    list.appendChild(more);
  }
}

function tripCard(t) {
  const seatsTag = (t.seats_left != null && t.is_almost_full)
    ? `<span class="tag warn">เหลือ ${t.seats_left} ที่</span>` : '';
  const abroadTag = t.destination_type === 'international'
    ? `<span class="tag">🌏 ${esc(t.country_label || 'ต่างประเทศ')}</span>` : '';
  const rating = Number(t.rating || 0);
  const card = el(`<div class="card">
    ${t.cover_image ? `<img class="cover" src="${esc(t.cover_image)}" alt="" loading="lazy">` : ''}
    <div class="body">
      <p class="title">${esc(t.title)}</p>
      <div class="meta">
        <span>📍 ${esc(t.location || '')}</span>
        <span>${t.duration_days || 1} วัน</span>
        ${rating > 0 ? `<span>⭐ ${rating.toFixed(1)}${t.reviews_count ? ` (${t.reviews_count})` : ''}</span>` : ''}
        ${abroadTag}
        ${seatsTag}
      </div>
      <div class="meta" style="margin-top:6px">
        <span class="price">${baht(t.min_price ?? t.price_per_person)}${(t.max_price && t.max_price !== t.min_price) ? ' +' : ''}</span>
      </div>
    </div>
  </div>`);
  card.onclick = () => showTrip(t.slug);
  return card;
}

function activeFilterChips() {
  const chips = [];
  const push = (label, clear) => chips.push({ label, clear });

  if (tripFilters.destination) {
    push(tripFilters.destination === 'domestic' ? 'ในประเทศ' : 'ต่างประเทศ', () => {
      tripFilters.destination = '';
      tripFilters.region = '';
      tripFilters.country = '';
    });
  }
  if (tripFilters.region) {
    const region = tripDestinations?.domestic?.regions?.find((r) => r.key === tripFilters.region);
    push(region?.label || tripFilters.region, () => { tripFilters.region = ''; });
  }
  if (tripFilters.country) {
    const country = tripDestinations?.international?.countries?.find((c) => c.code === tripFilters.country);
    push(country?.name || tripFilters.country, () => { tripFilters.country = ''; });
  }
  if (tripFilters.type) {
    const cat = (tripCategories || []).find((c) => (c.slug || c.value || c.key) === tripFilters.type);
    push(cat?.name || cat?.label || tripFilters.type, () => { tripFilters.type = ''; });
  }
  if (tripFilters.difficulty) {
    push(DIFFICULTIES.find((d) => d.value === tripFilters.difficulty)?.label || tripFilters.difficulty,
      () => { tripFilters.difficulty = ''; });
  }
  if (tripFilters.min_days !== '') {
    const dur = DURATIONS.find((d) => String(d.min_days) === String(tripFilters.min_days));
    push(dur?.label || 'ระยะเวลา', () => { tripFilters.min_days = ''; tripFilters.max_days = ''; });
  }
  if (tripFilters.sort) {
    push(SORTS.find((o) => o.value === tripFilters.sort)?.label || 'เรียง', () => { tripFilters.sort = ''; });
  }

  if (!chips.length) return null;

  const row = el(`<div class="chip-row"></div>`);
  chips.forEach((chip) => {
    const btn = el(`<button type="button" class="chip on">${esc(chip.label)} ✕</button>`);
    btn.onclick = () => { chip.clear(); reloadTrips(); };
    row.appendChild(btn);
  });
  const clearAll = el(`<button type="button" class="chip">ล้างทั้งหมด</button>`);
  clearAll.onclick = () => {
    Object.keys(tripFilters).forEach((key) => { if (key !== 'search') tripFilters[key] = ''; });
    reloadTrips();
  };
  row.appendChild(clearAll);
  return row;
}

/* --------- แผ่นตัวกรอง (เทียบเท่าแผงตัวกรองของหน้าเว็บ) --------- */

async function openTripFilters() {
  const sheet = el(`<div class="sheet-overlay"><div class="sheet">
    <div class="sheet-head"><strong>ตัวกรอง</strong><button class="sheet-close" aria-label="ปิด">✕</button></div>
    <div class="sheet-body"><div class="loading-inline"><div class="spinner"></div></div></div>
    <div class="sheet-foot">
      <button class="btn secondary" id="fClear">ล้าง</button>
      <button class="btn" id="fApply">ดูผลลัพธ์</button>
    </div>
  </div></div>`);
  sheet.onclick = (e) => { if (e.target === sheet) sheet.remove(); };
  sheet.querySelector('.sheet-close').onclick = () => sheet.remove();
  document.body.appendChild(sheet);

  await loadFilterOptions();

  const draft = { ...tripFilters };
  const body = sheet.querySelector('.sheet-body');
  const paint = () => {
    body.innerHTML = '';

    const group = (title, options, selected, onPick) => {
      body.appendChild(el(`<div class="section-heading">${esc(title)}</div>`));
      const row = el(`<div class="chip-row"></div>`);
      options.forEach((option) => {
        const chip = el(`<button type="button" class="chip ${option.value === selected ? 'on' : ''}">${esc(option.label)}</button>`);
        chip.onclick = () => { onPick(option.value === selected ? '' : option.value); paint(); };
        row.appendChild(chip);
      });
      body.appendChild(row);
    };

    group('ปลายทาง', [
      { value: 'domestic', label: 'ในประเทศ' },
      { value: 'international', label: 'ต่างประเทศ' },
    ], draft.destination, (value) => {
      draft.destination = value;
      draft.region = '';
      draft.country = '';
    });

    if (draft.destination !== 'international' && tripDestinations?.domestic?.regions?.length) {
      group('ภาค', tripDestinations.domestic.regions.map((r) => ({ value: r.key, label: r.label + (r.count ? ` (${r.count})` : '') })),
        draft.region, (value) => { draft.region = value; });
    }
    if (draft.destination !== 'domestic' && tripDestinations?.international?.countries?.length) {
      group('ประเทศ', tripDestinations.international.countries.map((c) => ({ value: c.code, label: `${c.flag || ''} ${c.name}`.trim() })),
        draft.country, (value) => { draft.country = value; });
    }
    if ((tripCategories || []).length) {
      group('ประเภททริป', tripCategories.map((c) => ({ value: c.slug || c.value || c.key, label: c.name || c.label })),
        draft.type, (value) => { draft.type = value; });
    }
    group('ระดับความยาก', DIFFICULTIES, draft.difficulty, (value) => { draft.difficulty = value; });
    group('ระยะเวลา', DURATIONS.map((d) => ({ value: String(d.min_days), label: d.label })), String(draft.min_days || ''), (value) => {
      const dur = DURATIONS.find((d) => String(d.min_days) === value);
      draft.min_days = dur ? dur.min_days : '';
      draft.max_days = dur ? dur.max_days : '';
    });
    group('เรียงตาม', SORTS.filter((o) => o.value), draft.sort, (value) => { draft.sort = value; });
  };
  paint();

  sheet.querySelector('#fClear').onclick = () => {
    Object.keys(draft).forEach((key) => { if (key !== 'search') draft[key] = ''; });
    paint();
  };
  sheet.querySelector('#fApply').onclick = () => {
    Object.assign(tripFilters, draft);
    sheet.remove();
    reloadTrips();
  };
}

/* ------------------------- screen: trip detail ------------------------ */

// ราคาที่ต้องโชว์ของรอบ + ป้ายลดราคา (แฟลชเซลของรอบ ดู TripScheduleResource)
function schedulePriceHtml(s) {
  const flash = s.flash_sale;
  if (flash?.active && Number(s.original_price || 0) > Number(s.price || 0)) {
    return `<span class="strike">${baht(s.original_price)}</span> <span class="price">${baht(s.price)}</span>`
      + (flash.discount_percent ? ` <span class="tag sale">-${flash.discount_percent}%</span>` : '');
  }
  return `<span class="price">${baht(s.price)}</span>`;
}

// การันตีออกเดินทาง — ป้ายเดียวกับที่หน้าเว็บใช้ (departure_status)
function scheduleStatusHtml(s) {
  if (s.is_charter || !s.departure_status) return '';
  if (s.departure_status === 'guaranteed') return '<span class="tag ok">✓ การันตีออกเดินทาง</span>';
  if (s.departure_status === 'almost_ready') {
    return `<span class="tag warn">ใกล้ออกเดินทาง${s.seats_to_guarantee ? ` · ขาดอีก ${s.seats_to_guarantee} ที่` : ''}</span>`;
  }
  return `<span class="tag">รอเพื่อนร่วมทาง${s.seats_to_guarantee ? ` อีก ${s.seats_to_guarantee} ที่` : ''}</span>`;
}

let tripTab = 'overview'; // ภาพรวม / กำหนดการ / เตรียมตัว / รีวิว
let tripCache = null; // ทริปที่กำลังเปิดอยู่ + รอบของมัน

async function showTrip(slug, tab) {
  if (tab) tripTab = tab;

  if (!tripCache || tripCache.trip.slug !== slug) {
    loading();
    try {
      const tripRes = await api('/trips/' + encodeURIComponent(slug));
      const schRes = await api('/trips/' + encodeURIComponent(slug) + '/schedules');
      tripCache = {
        trip: tripRes.data,
        schedules: Array.isArray(schRes.data) ? schRes.data : (schRes.data?.data ?? []),
      };
      tripTab = tab || 'overview';
    } catch (e) {
      return errorScreen(e.message, () => showTrip(slug));
    }
  }

  const { trip, schedules } = tripCache;
  const node = el(`<div></div>`);
  node.appendChild(appbar('รายละเอียดทริป', showTrips));
  const content = el(`<div class="content"></div>`);

  if (trip.cover_image) content.appendChild(el(`<img class="hero" src="${esc(trip.cover_image)}" alt="">`));

  content.appendChild(el(`<h2 class="trip-title">${esc(trip.title)}</h2>`));
  content.appendChild(el(`<div class="meta" style="margin-bottom:8px">
    <span>📍 ${esc(trip.location || '')}</span>
    <span>${trip.duration_days || 1} วัน</span>
    ${trip.difficulty ? `<span>${esc(difficultyLabel(trip.difficulty))}</span>` : ''}
    ${trip.distance_km ? `<span>${trip.distance_km} กม.</span>` : ''}
    ${trip.elevation_gain_m ? `<span>▲ ${trip.elevation_gain_m} ม.</span>` : ''}
    ${Number(trip.rating) > 0 ? `<span>⭐ ${Number(trip.rating).toFixed(1)}${trip.review_count ? ` (${trip.review_count})` : ''}</span>` : ''}
  </div>`));

  const badges = [];
  if (trip.is_women_only) badges.push('<span class="tag pink">ทริปผู้หญิงล้วน</span>');
  if (trip.destination_type === 'international') badges.push(`<span class="tag">🌏 ${esc(trip.country_label || 'ต่างประเทศ')}</span>`);
  if (badges.length) content.appendChild(el(`<div class="meta" style="margin-bottom:10px">${badges.join('')}</div>`));

  // แชร์ให้เพื่อนใน LINE — ข้อได้เปรียบเดียวที่ LIFF มีเหนือหน้าเว็บ ต้องใช้
  const share = el(`<button class="btn secondary linkrow">💬 ส่งทริปนี้ให้เพื่อนใน LINE</button>`);
  share.onclick = () => shareTrip(trip);
  content.appendChild(share);

  // รอบเดินทางอยู่บนสุดของเนื้อหา — เป็นสิ่งที่คนเปิดหน้านี้มาทำ
  content.appendChild(el(`<div class="section-heading">รอบเดินทางที่เปิดจอง</div>`));
  content.appendChild(scheduleList(trip, schedules));

  // แท็บเนื้อหา
  const tabs = [
    { key: 'overview', label: 'ภาพรวม' },
    { key: 'itinerary', label: 'กำหนดการ', hide: !itinerarySectors(trip).length },
    { key: 'prepare', label: 'เตรียมตัว', hide: !(trip.preparations || []).length && !(trip.faqs || []).length },
    { key: 'reviews', label: 'รีวิว' },
  ].filter((t) => !t.hide);
  if (!tabs.some((t) => t.key === tripTab)) tripTab = 'overview';

  const tabBar = el(`<div class="tabbar"></div>`);
  tabs.forEach((t) => {
    const btn = el(`<button type="button" class="tab ${tripTab === t.key ? 'on' : ''}">${esc(t.label)}</button>`);
    btn.onclick = () => showTrip(slug, t.key);
    tabBar.appendChild(btn);
  });
  content.appendChild(tabBar);

  const pane = el(`<div class="tabpane"></div>`);
  content.appendChild(pane);
  node.appendChild(content);
  render(node);

  if (tripTab === 'overview') renderTripOverview(pane, trip);
  else if (tripTab === 'itinerary') renderTripItinerary(pane, trip);
  else if (tripTab === 'prepare') renderTripPrepare(pane, trip);
  else renderTripReviews(pane, trip);
}

function difficultyLabel(value) {
  return ({ easy: 'ง่าย', moderate: 'ปานกลาง', hard: 'ยาก', expert: 'ท้าทาย' })[value] || value;
}

function itinerarySectors(trip) {
  const raw = trip.itinerary || [];
  if (!raw.length) return [];
  // รูปแบบใหม่แบ่งเป็นช่วง (sector) — ของเก่าเป็นรายวันแบน ๆ ห่อให้เป็นช่วงเดียว
  return Object.prototype.hasOwnProperty.call(raw[0], 'sector')
    ? raw
    : [{ sector: 'กำหนดการเดินทาง', items: raw }];
}

/* --------- แท็บ: ภาพรวม --------- */

function renderTripOverview(pane, trip) {
  if (trip.description) pane.appendChild(el(`<p class="trip-desc">${esc(trip.description)}</p>`));

  // ไฮไลต์เก็บเป็นอ็อบเจ็กต์ { icon, title, desc } ไม่ใช่สตริง (ดู TripDetailPage.vue)
  // ทริปรุ่นเก่าบางอันยังเป็นสตริงล้วน จึงต้องรับทั้งสองแบบ
  const highlights = (trip.highlights || []).map((h) => (
    typeof h === 'string' ? { title: h, desc: '' } : { title: h?.title || '', desc: h?.desc || h?.description || '' }
  )).filter((h) => h.title || h.desc);

  if (highlights.length) {
    pane.appendChild(el(`<div class="section-heading">ไฮไลต์</div>`));
    highlights.forEach((h, i) => pane.appendChild(el(`<div class="highlight">
      <span class="hl-no">${String(i + 1).padStart(2, '0')}</span>
      <div>
        ${h.title ? `<div class="hl-title">${esc(h.title)}</div>` : ''}
        ${h.desc ? `<p class="hl-desc">${esc(h.desc)}</p>` : ''}
      </div>
    </div>`)));
  }

  const inclusions = trip.inclusions || [];
  const exclusions = trip.exclusions || [];
  if (inclusions.length || exclusions.length) {
    pane.appendChild(el(`<div class="section-heading">ราคานี้รวมอะไรบ้าง</div>`));
    const card = el(`<div class="card"><div class="body"></div></div>`);
    const body = card.querySelector('.body');
    inclusions.forEach((i) => body.appendChild(el(`<div class="incl yes">✓ ${esc(i)}</div>`)));
    exclusions.forEach((i) => body.appendChild(el(`<div class="incl no">✕ ${esc(i)}</div>`)));
    pane.appendChild(card);
  }

  const gallery = [...(trip.gallery || []), ...((trip.photos || []).map((p) => p.thumb_url || p.url))].filter(Boolean);
  if (gallery.length) {
    pane.appendChild(el(`<div class="section-heading">ภาพจากทริป</div>`));
    const row = el(`<div class="photo-row"></div>`);
    gallery.slice(0, 12).forEach((url) => {
      const img = el(`<img src="${esc(url)}" alt="" loading="lazy">`);
      img.onclick = () => openImageLightbox(url);
      row.appendChild(img);
    });
    pane.appendChild(row);
  }

  const videos = trip.videos || [];
  if (videos.length) {
    pane.appendChild(el(`<div class="section-heading">วิดีโอ</div>`));
    videos.slice(0, 3).forEach((v) => {
      const url = typeof v === 'string' ? v : (v.url || v.src);
      if (!url) return;
      pane.appendChild(el(`<video class="trip-video" src="${esc(url)}" controls preload="metadata" playsinline></video>`));
    });
  }

  // ทริปต่างประเทศ: วีซ่า + เบอร์ฉุกเฉินท้องถิ่น (191 ของไทยใช้ที่นั่นไม่ได้)
  if (trip.destination_type === 'international') {
    pane.appendChild(el(`<div class="section-heading">ข้อมูลปลายทาง</div>`));
    const rows = [];
    if (trip.visa) {
      const visa = typeof trip.visa === 'string' ? trip.visa : (trip.visa.summary || trip.visa.note || '');
      const days = trip.visa?.days_allowed || trip.visa?.stay_days;
      if (visa || days) rows.push(`<div class="kv"><span class="k">วีซ่า</span><span class="v">${esc(visa || `พำนักได้ ${days} วัน`)}</span></div>`);
    }
    (trip.emergency_numbers || []).forEach((n) => {
      const label = n.label || n.name || 'เบอร์ฉุกเฉิน';
      const number = n.number || n.value || n;
      rows.push(`<div class="kv"><span class="k">${esc(label)}</span><span class="v">${esc(number)}</span></div>`);
    });
    if (rows.length) pane.appendChild(el(`<div class="card"><div class="body">${rows.join('')}</div></div>`));
  }

  // นโยบายยกเลิก — ต้องเห็นก่อนจ่ายเงิน (ทริปต่างประเทศใช้คนละชุด)
  const policy = trip.cancellation_policy;
  if (policy?.tiers?.length) {
    pane.appendChild(el(`<div class="section-heading">นโยบายยกเลิก / เลื่อนวัน</div>`));
    const card = el(`<div class="card"><div class="body">
      ${policy.tiers.map((t) => `<div class="kv"><span class="k">${esc(t.range)}</span><span class="v">${esc(t.detail)}</span></div>`).join('')}
    </div></div>`);
    pane.appendChild(card);
    if (policy.note) pane.appendChild(el(`<p class="muted">${esc(policy.note)}</p>`));
  }

  // ทริปที่คล้ายกัน
  const related = el(`<div></div>`);
  pane.appendChild(related);
  api('/trips/' + encodeURIComponent(trip.slug) + '/related', { auth: false })
    .then((res) => {
      const items = (Array.isArray(res.data) ? res.data : (res.data?.data ?? [])).slice(0, 6);
      if (!items.length || !related.isConnected) return;
      related.appendChild(el(`<div class="section-heading">ทริปที่คล้ายกัน</div>`));
      const row = el(`<div class="trip-rail"></div>`);
      items.forEach((t) => {
        const card = el(`<div class="rail-card">
          ${t.cover_image ? `<img src="${esc(t.cover_image)}" alt="" loading="lazy">` : '<div class="rail-thumb-empty">🏞️</div>'}
          <div class="rail-body">
            <div class="rail-title">${esc(t.title)}</div>
            <div class="rail-price">${baht(t.min_price ?? t.price_per_person)}</div>
          </div>
        </div>`);
        card.onclick = () => { tripCache = null; showTrip(t.slug); };
        row.appendChild(card);
      });
      related.appendChild(row);
    })
    .catch(() => { /* ของประกอบ ไม่มีก็ไม่เป็นไร */ });
}

/* --------- แท็บ: กำหนดการ --------- */

function renderTripItinerary(pane, trip) {
  itinerarySectors(trip).forEach((sector) => {
    if (sector.sector) pane.appendChild(el(`<div class="section-heading">${esc(sector.sector)}</div>`));
    (sector.items || []).forEach((item) => {
      pane.appendChild(el(`<div class="card"><div class="body">
        <div class="pax-head">${item.day ? `วันที่ ${esc(item.day)}` : ''}${item.title ? ` · ${esc(item.title)}` : ''}</div>
        ${item.description ? `<p class="trip-desc" style="margin:0">${esc(item.description)}</p>` : ''}
      </div></div>`));
    });
  });
}

/* --------- แท็บ: เตรียมตัว --------- */

function renderTripPrepare(pane, trip) {
  const preparations = trip.preparations || [];
  if (preparations.length) {
    pane.appendChild(el(`<div class="section-heading">สิ่งที่ต้องเตรียม</div>`));
    const ul = el(`<ul class="bullets"></ul>`);
    preparations.forEach((item) => ul.appendChild(el(`<li>${esc(item)}</li>`)));
    pane.appendChild(ul);
  }

  const remarks = String(trip.must_know?.remarks || '').trim();
  if (remarks) {
    pane.appendChild(el(`<div class="section-heading">สิ่งที่ควรรู้</div>`));
    pane.appendChild(el(`<p class="trip-desc">${esc(remarks)}</p>`));
  }

  const faqs = trip.faqs || [];
  if (faqs.length) {
    pane.appendChild(el(`<div class="section-heading">คำถามที่พบบ่อย</div>`));
    faqs.forEach((faq) => {
      const item = el(`<div class="faq">
        <button type="button" class="faq-q">${esc(faq.question)}<span>+</span></button>
        <div class="faq-a" hidden>${esc(faq.answer)}</div>
      </div>`);
      const answer = item.querySelector('.faq-a');
      item.querySelector('.faq-q').onclick = () => {
        answer.hidden = !answer.hidden;
        item.querySelector('.faq-q span').textContent = answer.hidden ? '+' : '−';
      };
      pane.appendChild(item);
    });
  }
}

/* --------- แท็บ: รีวิว --------- */

async function renderTripReviews(pane, trip) {
  pane.appendChild(el(`<div class="loading-inline"><div class="spinner"></div></div>`));

  let reviews;
  try {
    const res = await api('/reviews?per_page=10&trip_id=' + trip.id, { auth: false });
    reviews = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);
  } catch (e) {
    pane.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
    return;
  }
  if (!pane.isConnected) return;

  pane.innerHTML = '';
  const rating = Number(trip.rating || 0);
  if (rating > 0) {
    pane.appendChild(el(`<div class="rating-summary">
      <div class="rating-score">${rating.toFixed(1)}</div>
      <div>
        <div>${'★'.repeat(Math.round(rating))}${'☆'.repeat(5 - Math.round(rating))}</div>
        <div class="muted">จาก ${trip.review_count || reviews.length} รีวิว</div>
      </div>
    </div>`));
  }

  if (!reviews.length) {
    pane.appendChild(el(`<div class="empty">ยังไม่มีรีวิวของทริปนี้</div>`));
    return;
  }

  reviews.forEach((r) => {
    const images = (r.images || []).filter(Boolean);
    const card = el(`<div class="card"><div class="body">
      <div class="review-head">
        <strong>${esc(r.user?.name || r.user_name || 'ผู้เดินทาง')}</strong>
        <span class="stars">${'★'.repeat(Number(r.rating) || 0)}</span>
      </div>
      ${r.comment ? `<p class="trip-desc" style="margin:6px 0 0">${esc(r.comment)}</p>` : ''}
      ${r.reply ? `<div class="review-reply"><b>ทีมงานตอบกลับ</b><br>${esc(r.reply)}</div>` : ''}
    </div></div>`);
    if (images.length) {
      const row = el(`<div class="photo-row" style="padding:0 14px 14px"></div>`);
      images.slice(0, 6).forEach((url) => {
        const img = el(`<img src="${esc(url)}" alt="" loading="lazy">`);
        img.onclick = () => openImageLightbox(url);
        row.appendChild(img);
      });
      card.appendChild(row);
    }
    pane.appendChild(card);
  });
}

/* --------- รายการรอบเดินทาง --------- */

function scheduleList(trip, schedules) {
  const wrap = el(`<div></div>`);
  const open = schedules.filter((s) => s.status !== 'closed' && s.status !== 'cancelled');
  if (!open.length) {
    wrap.appendChild(el(`<div class="empty">ยังไม่มีรอบที่เปิดจอง</div>`));
    return wrap;
  }

  for (const s of open) {
    // ที่นั่งบนรถเต็มแล้วยังจอยทริปได้ ถ้ารอบนั้นเปิดโควตาจอยไว้ (คนละกองกับที่นั่ง)
    const seatsLeft = s.bookable_seats != null ? s.bookable_seats : s.available_seats;
    const seatsFull = seatsLeft != null && seatsLeft <= 0;
    const joinLeft = s.join_trip_available_seats;
    const joinOpen = !!s.join_trip_enabled && (joinLeft == null || joinLeft > 0);

    const row = el(`<div class="schedule-card">
      <div class="schedule-main">
        <div>
          <div class="date">${thaiDate(s.departure_date)}${s.return_date && s.return_date !== s.departure_date ? ' - ' + thaiDate(s.return_date) : ''}</div>
          <div class="sub">${seatsLeft != null ? (seatsFull ? 'ที่นั่งเต็มแล้ว' : 'เหลือ ' + seatsLeft + ' ที่') : ''}</div>
        </div>
        <div class="schedule-price">${schedulePriceHtml(s)}</div>
      </div>
      <div class="meta">${scheduleStatusHtml(s)}${s.transport_type === 'flight' ? '<span class="tag">✈️ เดินทางโดยเครื่องบิน</span>' : ''}${weatherTag(s)}</div>
      <div class="schedule-actions"></div>
    </div>`);

    const actions = row.querySelector('.schedule-actions');
    if (!seatsFull) {
      const book = el(`<button class="btn">จองที่นั่ง</button>`);
      book.onclick = () => startBooking(trip, s.id, { joinTrip: false });
      actions.appendChild(book);
    }
    if (joinOpen) {
      // จอยทริป = ไปกับทริปแต่ไม่ใช้ที่นั่งบนรถ (เดินทางไปเจอกันเอง)
      const join = el(`<button class="btn secondary">จอยทริป${s.join_trip_price ? ' · ' + baht(s.join_trip_price) : ''}${joinLeft != null ? ` (ว่าง ${joinLeft})` : ''}</button>`);
      join.onclick = () => startBooking(trip, s.id, { joinTrip: true });
      actions.appendChild(join);
    }
    // รอบที่ที่นั่งเต็ม — ขอคิวรอได้ ระบบจะเสนอที่นั่งให้เมื่อมีคนยกเลิก
    if (seatsFull) {
      const waitBtn = el(`<button class="btn secondary">ขอคิวรอที่นั่ง</button>`);
      waitBtn.onclick = () => openWaitlist(s, waitBtn);
      actions.appendChild(waitBtn);
    }

    wrap.appendChild(row);
  }
  return wrap;
}

// พยากรณ์อากาศของรอบ — มีเฉพาะตอนที่เซิร์ฟเวอร์แนบมาให้ (WeatherService)
function weatherTag(s) {
  const w = s.weather;
  const day = Array.isArray(w) ? w[0] : (w?.daily?.[0] || w?.forecast?.[0] || w);
  if (!day) return '';
  const max = day.temp_max ?? day.max_temp ?? day.temperature_max;
  const label = day.summary || day.description || day.condition;
  if (max == null && !label) return '';
  return `<span class="tag">🌤️ ${esc([label, max != null ? Math.round(max) + '°' : ''].filter(Boolean).join(' '))}</span>`;
}

/* --------------------------- แชร์ทริปใน LINE --------------------------- */

/**
 * ส่งการ์ดทริปให้เพื่อนผ่าน shareTargetPicker
 *
 * เป็นสิ่งเดียวที่ LIFF ทำได้แต่หน้าเว็บทำไม่ได้ — เพื่อนที่กดลิงก์จะเข้ามาที่หน้า
 * ทริปนั้นโดยตรงผ่าน deep link (ดู routeFromEntry)
 */
async function shareTrip(trip) {
  const url = `https://liff.line.me/${CFG.liffId}?trip=${encodeURIComponent(trip.slug)}`;
  const text = `${trip.title}\n📍 ${trip.location || ''} · ${trip.duration_days || 1} วัน\nเริ่มต้น ${baht(trip.min_price ?? trip.price_per_person)}\n${url}`;

  try {
    if (liff.isApiAvailable && liff.isApiAvailable('shareTargetPicker')) {
      const res = await liff.shareTargetPicker([{ type: 'text', text }]);
      if (res) alert('ส่งให้เพื่อนแล้ว');
      return;
    }
  } catch (e) {
    // ผู้ใช้กดยกเลิก หรือ LIFF ไม่อนุญาต — ตกไปที่คัดลอกลิงก์แทน
  }

  try {
    await navigator.clipboard.writeText(url);
    alert('คัดลอกลิงก์ทริปแล้ว วางส่งให้เพื่อนได้เลยครับ');
  } catch (_) {
    alert(url);
  }
}

/* --------------------------- คิวรอที่นั่ง --------------------------- */

/**
 * รอบเต็มแล้วยังจองได้ทางเดียวคือเข้าคิว — เมื่อมีคนยกเลิก ProcessWaitlistJob
 * จะเสนอที่นั่งให้คนแรกในคิว และสิทธิ์นั้นหมดอายุตามที่แอดมินตั้งไว้
 */
async function openWaitlist(schedule, btn) {
  btn.disabled = true;
  btn.textContent = 'กำลังตรวจสอบคิว…';

  let status;
  try {
    status = (await api('/schedules/' + schedule.id + '/waitlist/status')).data;
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'ขอคิวรอที่นั่ง';
    return alert(e.message);
  }

  btn.disabled = false;
  btn.textContent = 'ขอคิวรอที่นั่ง';

  if (status?.in_waitlist) {
    const position = status.position ? `คุณอยู่ลำดับที่ ${status.position} ในคิว` : 'คุณอยู่ในคิวรอแล้ว';
    const leave = await askConfirm('คุณอยู่ในคิวรอแล้ว', `${position} — ออกจากคิวรอไหมครับ`, 'ออกจากคิว', 'อยู่ต่อ');
    if (!leave) return;
    try {
      await api('/schedules/' + schedule.id + '/waitlist', { method: 'DELETE' });
      alert('ออกจากคิวรอแล้ว');
    } catch (e) {
      alert(e.message);
    }
    return;
  }

  // ถามจำนวนที่นั่งด้วยแผ่นเลื่อน ไม่ใช้ prompt() — เว็บวิวบางตัวบล็อกมันทิ้งเงียบ ๆ
  const sheet = el(`<div class="sheet-overlay"><div class="sheet">
    <div class="sheet-head"><strong>ขอคิวรอที่นั่ง</strong><button class="sheet-close" aria-label="ปิด">✕</button></div>
    <div class="sheet-body">
      <p class="muted">รอบ ${esc(thaiDate(schedule.departure_date))} ที่นั่งเต็มแล้ว เมื่อมีคนยกเลิก ระบบจะเสนอที่นั่งให้คนแรกในคิวก่อน และแจ้งเตือนคุณทาง LINE กับอีเมล</p>
      <div class="cta-row" style="margin-top:14px">
        <strong>จำนวนที่นั่งที่ต้องการ</strong>
        <span class="qty">
          <button class="qty-btn" id="wlMinus" aria-label="ลดจำนวน">−</button>
          <span class="qty-num" id="wlNum">1</span>
          <button class="qty-btn" id="wlPlus" aria-label="เพิ่มจำนวน">+</button>
        </span>
      </div>
      <div id="wlBanner"></div>
    </div>
    <div class="sheet-foot">
      <button class="btn secondary" id="wlCancel">ยกเลิก</button>
      <button class="btn" id="wlJoin">เข้าคิวรอ</button>
    </div>
  </div></div>`);
  sheet.onclick = (e) => { if (e.target === sheet) sheet.remove(); };
  sheet.querySelector('.sheet-close').onclick = () => sheet.remove();
  sheet.querySelector('#wlCancel').onclick = () => sheet.remove();
  document.body.appendChild(sheet);

  let seatCount = 1;
  const num = sheet.querySelector('#wlNum');
  sheet.querySelector('#wlMinus').onclick = () => { seatCount = Math.max(1, seatCount - 1); num.textContent = seatCount; };
  sheet.querySelector('#wlPlus').onclick = () => { seatCount = Math.min(10, seatCount + 1); num.textContent = seatCount; };

  const join = sheet.querySelector('#wlJoin');
  join.onclick = async () => {
    join.disabled = true;
    join.textContent = 'กำลังเข้าคิว…';
    try {
      const res = await api('/schedules/' + schedule.id + '/waitlist', {
        method: 'POST',
        body: { seat_count: seatCount },
      });
      const position = res.data?.position;
      sheet.remove();
      alert(`เข้าคิวรอสำเร็จ${position ? ` · คุณอยู่ลำดับที่ ${position}` : ''}`);
    } catch (e) {
      sheet.querySelector('#wlBanner').innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
      join.disabled = false;
      join.textContent = 'เข้าคิวรอ';
    }
  };
}

/* ------------------------------- start -------------------------------- */

// รอให้สคริปต์ทุกไฟล์โหลดครบก่อนเริ่ม — หน้าจอชำระเงินและการจองของฉันอยู่ใน
// payment.js ที่โหลดถัดจากไฟล์นี้ ถ้าเริ่มทันทีจะมีจังหวะที่ยังเรียกมันไม่ได้
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
