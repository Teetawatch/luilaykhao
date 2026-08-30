import { JSDOM } from 'jsdom';
import fs from 'fs';

// ไฟล์ LIFF จริง — เทสต์รันโค้ดชุดเดียวกับที่ deploy ไม่มีสำเนา
const ROOT = new URL('../../public/liff/', import.meta.url).pathname;

// ---- ข้อมูลจำลองจาก API จริง (ย่อ) ----
const TRIP = {
  id: 1, slug: 'khao-yai', title: 'เขาใหญ่ 2 วัน', location: 'นครราชสีมา',
  duration_days: 2, cover_image: '', rating: 4.8, review_count: 12,
  destination_type: 'domestic', min_price: 2500, max_price: 2900, price_per_person: 2500,
  is_women_only: false, is_international: false, type: 'trekking',
  must_know: { items: [{ name: 'เช่าถุงนอน', price: 200, price_type: 'per_person' }, { name: 'ประกันเพิ่ม', price: 100, price_type: 'per_booking' }] },
  rental_items: [{ name: 'ไม้เท้า', price: 100, description: 'คู่ละ' }],
  document_requirements: [{ key: 'id-copy', label: 'สำเนาบัตรประชาชน', note: '', required: true }],
  seats_left: 6, is_almost_full: false,
};
const SCHEDULE = {
  id: 9, trip_id: 1, departure_date: '2026-10-10', return_date: '2026-10-11',
  status: 'open', price: 2500, original_price: 2900, transport_type: 'van',
  available_seats: 6, bookable_seats: 6, total_seats: 10, departure_status: 'almost_ready',
  seats_to_guarantee: 2, is_charter: false,
  flash_sale: { active: true, upcoming: false, price: 2500, discount_percent: 14 },
  join_trip_enabled: true, join_trip_price: 1500, join_trip_available_seats: 5,
  pickup_points: [
    { id: 3, pickup_location: 'อนุสาวรีย์ชัยฯ', region_label: 'กรุงเทพ', price: 2500, pickup_time: '05:00', latitude: 13.76, longitude: 100.53 },
    { id: 4, pickup_location: 'รังสิต', region_label: 'ปทุมธานี', price: 2700, pickup_time: '05:40', latitude: 14.0, longitude: 100.6 },
  ],
  vehicle_options: [
    { id: 11, label: 'รถตู้', price_adjustment: 0, available_seats: 6, is_sold_out: false, uses_seat_map: true, is_active: true },
    { id: 12, label: 'รถบัส', price_adjustment: 300, available_seats: 20, is_sold_out: false, uses_seat_map: true, is_active: true },
  ],
};
const SEATS = {
  has_seat_map: true, vehicle_option_id: 11, rows: 4, columns: ['A', 'B', '', 'C'],
  front_seat: 'A1', last_row_center: ['B4'], layout_kind: 'van',
  front_label: 'หน้ารถ', rear_label: 'ท้ายรถ', show_driver: true, driver_icon: 'directions_car',
  total_seats: 10, available_seats: 6, is_bookable_now: true,
  seats: ['A1','A2','A3','A4','B2','B3','B4','C1','C2','C3'].map((id, i) => ({
    id, label: id, row: Number(id[1]), column: id[0],
    status: i === 2 ? 'booked' : 'available',
    locked_by_current_user: false, booked_by_current_user: false,
  })),
};
const BOOKING = {
  booking_ref: 'LLK-20261010-0001', status: 'pending', total_amount: 5200, paid_amount: 0,
  payment_type: 'full', expires_at: new Date(Date.now() + 600000).toISOString(),
  schedule: { ...SCHEDULE, trip: TRIP }, seats: [{ seat_id: 'A2' }, { seat_id: 'A3' }],
  passengers: [{ id: 1 }, { id: 2 }],
  payment_gateway: { provider: 'beam', methods: ['QR_PROMPT_PAY', 'KPLUS'] },
  payment_options: {
    total_amount: 5200, passenger_count: 2,
    full: { available: true, amount: 5200 },
    deposit: { available: true, amount: 2000, balance: 3200, percent_of_total: 38, balance_due_at: '2026-09-25T00:00:00Z', tier_discount_percent: 0 },
    installment: { available: true, options: [{ count: 2, per_amount: 2600, last_amount: 2600, interval_days: 20, due_dates: ['2026-08-30', '2026-09-25'] }] },
    split: { available: false },
  },
};

const routes = {
  'POST /auth/line/liff': { data: { token: 'tok' } },
  'GET /trips': { data: [TRIP], meta: { current_page: 1, last_page: 2, total: 15 } },
  'GET /trips/khao-yai': { data: TRIP },
  'GET /trips/khao-yai/schedules': { data: [SCHEDULE] },
  'GET /schedules/9': { data: SCHEDULE },
  'GET /schedules/9/seats': { data: SEATS },
  'GET /categories': { data: [{ slug: 'trekking', name: 'เดินป่า' }] },
  'GET /trips/destinations': { data: { domestic: { regions: [{ key: 'north', label: 'ภาคเหนือ', count: 3 }] }, international: { countries: [{ code: 'JP', name: 'ญี่ปุ่น', flag: '🇯🇵' }] } } },
  'GET /pickup-vehicle-classes': { data: [{ id: 1, label: 'รถเก๋ง', min_pax: 1, max_pax: 3, pax_label: '1-3 ท่าน' }] },
  'GET /countries': { data: [{ code: 'TH', name: 'ไทย', flag: '🇹🇭' }] },
  'GET /saved-travellers': { data: [] },
  'GET /auth/me': { data: { name: 'ทดสอบ', title: 'นาย', phone: '0812345678' } },
  'POST /schedules/9/seats/lock': { data: { locked: true, seats: ['A2'], expires_at: new Date(Date.now() + 900000).toISOString() } },
  'POST /bookings': { data: BOOKING },
  'POST /bookings/LLK-20261010-0001/documents': { data: { id: 1 } },
  'POST /bookings/LLK-20261010-0001/save-travellers': { data: null },
  'GET /bookings': { data: [BOOKING] },
  'GET /bookings/LLK-20261010-0001': { data: BOOKING },
  'POST /payments/beam/charge': { data: { payment_id: 5, status: 'pending', amount: 5200, qr_image_base64: 'iVBOR', expires_at: new Date(Date.now() + 600000).toISOString() } },
  'GET /payments/beam/5': { data: { payment_id: 5, status: 'pending' } },
  'GET /payments/LLK-20261010-0001/promptpay': { data: { amount: 5200, qr_data_uri: 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', promptpay_id: '004-9', merchant_name: 'LUILAYKHAO', bank_name: 'กสิกร', bank_account: '230-1', bank_holder: 'นาย ก' } },
};

const dom = new JSDOM(fs.readFileSync(ROOT + 'index.html', 'utf8'), { url: 'https://example.com/liff/', runScripts: 'outside-only', pretendToBeVisual: true });
const w = dom.window;

const calls = [];
w.fetch = async (url, opts = {}) => {
  const path = String(url).replace('https://example.com/api/v1', '');
  const key = `${opts.method || 'GET'} ${path.split('?')[0]}`;
  calls.push(key);
  const hit = routes[key];
  if (!hit) { console.log('  ⚠ ไม่มี route จำลอง:', key); return { ok: true, json: async () => ({ data: null }) }; }
  return { ok: true, status: 200, json: async () => hit };
};
w.liff = {
  init: async () => {}, isLoggedIn: () => true, getAccessToken: () => 'line-token',
  getProfile: async () => ({ displayName: 'คุณลูกค้า', pictureUrl: '' }),
  getOS: () => 'ios', openWindow: () => {},
};
w.alert = (m) => console.log('  [alert]', String(m).slice(0, 70));
w.confirm = () => false;
w.scrollTo = () => {};
w.LIFF_CONFIG = { liffId: '1234-abc', apiBaseUrl: 'https://example.com/api/v1' };
w.Element.prototype.scrollIntoView = function () {};

const errors = [];
w.addEventListener('error', (e) => errors.push('window error: ' + e.message));
process.on('unhandledRejection', (e) => errors.push('unhandled rejection: ' + (e?.message || e)));

// สคริปต์หลายไฟล์ในเบราว์เซอร์ใช้ global lexical scope ร่วมกัน — eval ทีละไฟล์ไม่ใช่
// จึงรวมเป็นก้อนเดียวให้เหมือนตอนโหลดจริง
w.eval(['app.js', 'booking.js', 'payment.js', 'bookings.js'].map((f) => fs.readFileSync(ROOT + f, 'utf8')).join('\n;\n'));

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const $ = (sel) => w.document.querySelector(sel);
const text = () => w.document.getElementById('app').textContent.replace(/\s+/g, ' ').trim();
const step = (name, fn) => { try { fn(); console.log('✓', name); } catch (e) { errors.push(name + ': ' + e.message); console.log('✗', name, '—', e.message); } };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };

await wait(60);
step('หน้ารวมทริป', () => {
  assert(text().includes('เขาใหญ่'), 'ไม่เห็นชื่อทริป: ' + text().slice(0, 120));
  assert(text().includes('พบ 15 ทริป'), 'ไม่เห็นจำนวนผลลัพธ์');
  assert($('#tripSearch'), 'ไม่มีช่องค้นหา');
  assert(text().includes('โหลดทริปเพิ่ม'), 'ไม่มีปุ่มโหลดเพิ่ม (มี 2 หน้า)');
});

$('.card').dispatchEvent(new w.Event('click'));
await wait(60);
step('หน้ารายละเอียดทริป', () => {
  assert(text().includes('รอบเดินทางที่เปิดจอง'), 'ไม่เห็นหัวข้อรอบเดินทาง');
  assert(text().includes('จอยทริป'), 'ไม่มีปุ่มจอยทริป');
  assert(text().includes('ใกล้ออกเดินทาง'), 'ไม่มีป้ายสถานะการันตี');
  assert(text().includes('-14%'), 'ไม่มีป้ายแฟลชเซล');
});

[...w.document.querySelectorAll('.schedule-actions .btn')][0].dispatchEvent(new w.Event('click'));
await wait(80);
step('ขั้น 1 — ผังที่นั่ง/รถ/จุดรับ', () => {
  assert(text().includes('เลือกประเภทรถ'), 'ไม่มีตัวเลือกประเภทรถ');
  assert(w.document.querySelectorAll('.seat').length >= 9, 'ที่นั่งไม่ครบ: ' + w.document.querySelectorAll('.seat').length);
  assert($('.seat-driver'), 'ไม่มีที่คนขับ');
  assert($('.seat-aisle'), 'ไม่มีทางเดิน');
  assert(text().includes('จุดขึ้นรถ'), 'ไม่มีจุดขึ้นรถ');
  assert(text().includes('ปักหมุดเอง'), 'ไม่มีปุ่มปักหมุดเอง');
  assert($('#next').disabled, 'ปุ่มถัดไปควรถูกปิดตอนยังไม่เลือกที่นั่ง');
});

const freeSeats = [...w.document.querySelectorAll('.seat:not([disabled])')];
freeSeats[0].dispatchEvent(new w.Event('click'));
freeSeats[1].dispatchEvent(new w.Event('click'));
await wait(20);
step('เลือกที่นั่ง 2 ที่ + คิดราคา', () => {
  assert(!$('#next').disabled, 'ปุ่มถัดไปยังปิดอยู่');
  assert($('#estTotal').textContent.includes('5,000'), 'ยอดผิด: ' + $('#estTotal').textContent);
});

$('#next').dispatchEvent(new w.Event('click'));
await wait(80);
step('ขั้น 2 — ผู้เดินทาง', () => {
  assert(text().includes('ผู้เดินทางคนที่ 1'), 'ไม่มีฟอร์มคนที่ 1');
  assert(text().includes('ผู้เดินทางคนที่ 2'), 'ไม่มีฟอร์มคนที่ 2');
  assert(text().includes('กันที่นั่งไว้ให้อีก'), 'ไม่มีนาฬิกานับถอยหลัง');
  assert(text().includes('สำเนาบัตรประชาชน'), 'ไม่มีช่องแนบเอกสาร');
  assert(text().includes('หมายเหตุสุขภาพ'), 'ไม่มีช่องหมายเหตุสุขภาพ');
  assert(text().includes('จุดขึ้นรถของคนนี้'), 'ไม่มีจุดขึ้นรถรายคน');
  assert(text().includes('สมุดผู้ร่วมเดินทาง'), 'ไม่มีปุ่มสมุดผู้ร่วมเดินทาง');
});

// กรอกข้อมูลครบทั้งสองคน
const fill = (i, values) => {
  const card = w.document.getElementById('pax-' + i);
  for (const [k, v] of Object.entries(values)) {
    const input = card.querySelector(`[data-f="${k}"]`);
    if (!input) throw new Error('ไม่มีช่อง ' + k);
    input.value = v;
    input.dispatchEvent(new w.Event('input'));
    input.dispatchEvent(new w.Event('change'));
  }
};
const person = (n) => ({
  title: 'นาย', name: 'สมชาย ใจดี ' + n, nickname: 'ชาย', id_card: '1101700207251',
  birth_date: '1990-01-01', phone: '0812345678', blood_group: 'O',
  emergency_contact: 'แม่', emergency_phone: '0898765432',
  allergies: 'ไม่มี', health_notes: 'ไม่มี', pickup_point_id: '3',
});
step('กรอกข้อมูลผู้เดินทาง', () => { fill(0, person(1)); fill(1, person(2)); });
await wait(20);
step('ตรวจความครบถ้วน (เหลือเฉพาะเอกสารบังคับ)', () => {
  const miss = w.document.getElementById('missCount').textContent;
  assert(miss.includes('2 ช่อง'), 'ควรเหลือเอกสารบังคับ 2 ช่อง แต่ได้: ' + miss);
});

// เอกสารบังคับ
step('แนบเอกสาร', () => {
  for (let i = 0; i < 2; i++) {
    const input = w.document.querySelector(`#pax-${i} .doc-block input[type=file]`);
    if (!input) throw new Error('ไม่มีช่องแนบไฟล์ของคนที่ ' + (i + 1));
    Object.defineProperty(input, 'files', {
      value: [new w.File(['x'], 'id.png', { type: 'image/png' })], configurable: true,
    });
    input.dispatchEvent(new w.Event('change'));
  }
});
await wait(30);
step('ครบแล้ว', () => {
  assert(w.document.getElementById('missCount').textContent.includes('ครบ'),
    'ยังไม่ครบ: ' + w.document.getElementById('missCount').textContent);
});

$('#toSummary').dispatchEvent(new w.Event('click'));
await wait(40);
step('ขั้น 3 — สรุป', () => {
  assert(text().includes('บริการเสริม'), 'ไม่มีบริการเสริม');
  assert(text().includes('อุปกรณ์ให้เช่า'), 'ไม่มีอุปกรณ์ให้เช่า');
  assert(text().includes('โค้ดส่วนลด'), 'ไม่มีช่องโค้ด');
  assert(text().includes('จองเป็นกลุ่ม'), 'ไม่มีตัวเลือกจองเป็นกลุ่ม');
  assert(text().includes('5,000'), 'ยอดรวมผิด');
});

$('#confirm').dispatchEvent(new w.Event('click'));
await wait(20);
step('แผ่นเงื่อนไข', () => {
  assert($('#termsOk'), 'ไม่มีแผ่นเงื่อนไข');
  assert($('#termsOk').disabled, 'ปุ่มยืนยันต้องปิดจนกว่าจะติ๊กยอมรับ');
});
$('#agree').checked = true;
$('#agree').dispatchEvent(new w.Event('change'));
$('#termsOk').dispatchEvent(new w.Event('click'));
await wait(150);

// จองหลายคน ระบบถามว่าจะเก็บผู้ร่วมเดินทางไว้ใช้รอบหน้าไหม
step('ถามเก็บผู้ร่วมเดินทางหลังจองเสร็จ', () => {
  const sheet = w.document.querySelector('.sheet-overlay');
  assert(sheet && sheet.textContent.includes('เก็บผู้ร่วมเดินทาง'), 'ไม่ได้ถามเรื่องสมุดผู้ร่วมเดินทาง');
  sheet.querySelector('[data-role="yes"]').dispatchEvent(new w.Event('click'));
});
await wait(150);

step('หน้าชำระเงิน (Beam)', () => {
  assert(text().includes('ชำระเงิน'), 'ไม่ได้เข้าหน้าชำระเงิน: ' + text().slice(0, 120));
  assert(text().includes('มัดจำ'), 'ไม่มีตัวเลือกมัดจำ');
  assert(text().includes('ผ่อนชำระ'), 'ไม่มีตัวเลือกผ่อน');
  assert(text().includes('K PLUS'), 'ไม่มีปุ่มแอปธนาคาร');
  assert(calls.includes('POST /payments/beam/charge'), 'ไม่ได้ออก QR ให้อัตโนมัติ');
  assert(text().includes('จ่ายเงินแล้ว'), 'ไม่มีปุ่มยืนยันว่าจ่ายแล้ว');
});

const payload = calls.filter((c) => c === 'POST /bookings');
step('ยิง POST /bookings แล้ว', () => assert(payload.length === 1, 'จำนวนครั้งผิด: ' + payload.length));


// ── หน้าการจองของฉัน ──
w.eval('showMyBookings()');
await wait(60);
step('การจองของฉัน', () => {
  assert(text().includes('LLK-20261010-0001'), 'ไม่เห็นเลขที่จอง');
  assert(text().includes('ชำระเงิน'), 'ไม่มีปุ่มชำระเงินของใบที่ค้าง');
});

// ── ตัวกรองหน้ารวมทริป ──
w.eval('showTrips()');
await wait(60);
$('#openFilters').dispatchEvent(new w.Event('click'));
await wait(60);
step('แผ่นตัวกรอง', () => {
  const sheet = w.document.querySelector('.sheet-overlay');
  assert(sheet, 'ไม่มีแผ่นตัวกรอง');
  const t = sheet.textContent;
  assert(t.includes('ปลายทาง') && t.includes('ระดับความยาก') && t.includes('เรียงตาม'),
    'ตัวกรองไม่ครบ: ' + t.slice(0, 150));
  assert(t.includes('เดินป่า'), 'ไม่มีประเภททริปจาก /categories');
  assert(t.includes('ญี่ปุ่น') || t.includes('ภาคเหนือ'), 'ไม่มี facet ปลายทาง');
});

console.log('\n--- calls ---');
console.log([...new Set(calls)].join('\n'));
if (errors.length) { console.log('\n❌ ปัญหา:'); errors.forEach((e) => console.log(' -', e)); process.exit(1); }
console.log('\n✅ ผ่านทั้งหมด');
