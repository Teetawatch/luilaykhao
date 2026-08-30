import { JSDOM } from 'jsdom';
import fs from 'fs';
// ไฟล์ LIFF จริง — เทสต์รันโค้ดชุดเดียวกับที่ deploy ไม่มีสำเนา
const ROOT = new URL('../../public/liff/', import.meta.url).pathname;

function makeWorld(routes, opts = {}) {
  const dom = new JSDOM(fs.readFileSync(ROOT + 'index.html', 'utf8'),
    { url: 'https://example.com/liff/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  const calls = [];
  const bodies = [];
  w.fetch = async (url, o = {}) => {
    const path = String(url).replace('https://example.com/api/v1', '');
    const key = `${o.method || 'GET'} ${path.split('?')[0]}`;
    calls.push(key);
    if (o.body) bodies.push({ key, body: o.body });
    const hit = routes[key];
    if (!hit) { console.log('  ⚠ ไม่มี route จำลอง:', key); return { ok: true, json: async () => ({ data: null }) }; }
    return { ok: true, status: 200, json: async () => hit };
  };
  w.liff = { init: async () => {}, isLoggedIn: () => true, getAccessToken: () => 't',
    getProfile: async () => ({ displayName: 'ลูกค้า' }), getOS: () => 'android', openWindow: () => {} };
  w.alert = (m) => console.log('  [alert]', String(m).slice(0, 80));
  w.confirm = () => opts.confirm ?? false;
  w.scrollTo = () => {};
  w.LIFF_CONFIG = { liffId: '1-a', apiBaseUrl: 'https://example.com/api/v1' };
  w.Element.prototype.scrollIntoView = function () {};
  w.eval(['app.js', 'booking.js', 'payment.js', 'bookings.js'].map((f) => fs.readFileSync(ROOT + f, 'utf8')).join('\n;\n'));
  return { w, calls, bodies, text: () => w.document.getElementById('app').textContent.replace(/\s+/g, ' ').trim() };
}

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let failed = 0;
const step = (name, fn) => { try { fn(); console.log('✓', name); } catch (e) { failed++; console.log('✗', name, '—', e.message); } };
const assert = (c, m) => { if (!c) throw new Error(m); };

const TRIP = (over = {}) => ({
  id: 1, slug: 't', title: 'ทริปทดสอบ', location: 'เชียงใหม่', duration_days: 2,
  price_per_person: 2000, min_price: 2000, type: 'trekking',
  is_women_only: false, is_international: false, must_know: { items: [] },
  rental_items: [], document_requirements: [], ...over,
});
const SCHEDULE = (over = {}) => ({
  id: 9, trip_id: 1, departure_date: '2026-11-01', status: 'open', price: 2000,
  transport_type: 'van', available_seats: 8, bookable_seats: 8, total_seats: 10,
  join_trip_enabled: true, join_trip_price: 1200, join_trip_available_seats: 4,
  pickup_points: [], vehicle_options: [], ...over,
});

/* ═══════════ ฉาก 1: จอยทริป (ไม่มีที่นั่ง ไม่มีจุดรับ ราคาคนละราคา) ═══════════ */
console.log('\n▶ จอยทริป');
{
  const trip = TRIP(), schedule = SCHEDULE();
  const { w, calls, bodies, text } = makeWorld({
    'POST /auth/line/liff': { data: { token: 'x' } },
    'GET /trips': { data: [trip], meta: { current_page: 1, last_page: 1, total: 1 } },
    'GET /trips/t': { data: trip },
    'GET /trips/t/schedules': { data: [schedule] },
    'GET /schedules/9': { data: schedule },
    'GET /countries': { data: [] },
    'POST /bookings': { data: { booking_ref: 'R1', status: 'pending', total_amount: 1200, schedule, payment_gateway: { provider: 'manual', methods: [] }, payment_options: { total_amount: 1200, full: { available: true, amount: 1200 }, deposit: { available: false }, installment: { options: [] } }, passengers: [{ id: 1 }] } },
    'GET /payments/R1/promptpay': { data: { amount: 1200, qr_data_uri: 'data:image/svg+xml;base64,PC8+', promptpay_id: '004', bank_name: 'กสิกร', bank_account: '230', bank_holder: 'ก' } },
  });
  await wait(60);
  w.document.querySelector('.card').dispatchEvent(new w.Event('click'));
  await wait(60);
  const joinBtn = [...w.document.querySelectorAll('.schedule-actions .btn')].find((b) => b.textContent.includes('จอยทริป'));
  step('มีปุ่มจอยทริปพร้อมราคาจอย', () => assert(joinBtn && joinBtn.textContent.includes('1,200'), 'ปุ่มจอยผิด: ' + joinBtn?.textContent));
  joinBtn.dispatchEvent(new w.Event('click'));
  await wait(80);
  step('จอยทริปไม่เรียกผังที่นั่ง', () => assert(!calls.includes('GET /schedules/9/seats'), 'ไม่ควรขอผังที่นั่ง'));
  step('ถามแค่จำนวนคน + ไม่มีจุดรับ', () => {
    assert(text().includes('จำนวนผู้เดินทาง'), 'ไม่มีตัวนับจำนวน');
    assert(!text().includes('จุดขึ้นรถ'), 'จอยทริปไม่ควรมีจุดขึ้นรถ');
    assert(text().includes('จอยทริป'), 'ไม่มีคำอธิบายจอยทริป');
  });
  step('ราคาใช้ join_trip_price', () => assert(w.document.getElementById('estTotal').textContent.includes('1,200'), 'ยอดผิด: ' + w.document.getElementById('estTotal').textContent));
  w.document.getElementById('next').dispatchEvent(new w.Event('click'));
  await wait(60);
  step('ข้ามการล็อกที่นั่ง', () => assert(!calls.includes('POST /schedules/9/seats/lock'), 'ไม่ควรล็อกที่นั่ง'));
  step('ไม่มีนาฬิกาที่นั่งในขั้นผู้เดินทาง', () => assert(!text().includes('กันที่นั่งไว้ให้อีก'), 'ไม่ควรมีนาฬิกา'));
}

/* ═══════════ ฉาก 2: โอนเอง + แนบสลิป (provider = manual) ═══════════ */
console.log('\n▶ ชำระเงินแบบโอน+สลิป');
{
  const trip = TRIP(), schedule = SCHEDULE();
  const booking = {
    booking_ref: 'R2', status: 'pending', total_amount: 4000, paid_amount: 0, payment_type: 'full',
    expires_at: new Date(Date.now() + 600000).toISOString(),
    schedule: { ...schedule, trip }, seats: [], passengers: [],
    payment_gateway: { provider: 'manual', methods: [] },
    payment_options: { total_amount: 4000, full: { available: true, amount: 4000 },
      deposit: { available: true, amount: 1500, balance: 2500, percent_of_total: 38, balance_due_at: '2026-10-17T00:00:00Z' },
      installment: { options: [] }, split: { available: false } },
  };
  const { w, calls, bodies, text } = makeWorld({
    'POST /auth/line/liff': { data: { token: 'x' } },
    'GET /trips': { data: [trip], meta: { current_page: 1, last_page: 1, total: 1 } },
    'GET /bookings/R2': { data: booking },
    'GET /payments/R2/promptpay': { data: { amount: 4000, qr_data_uri: 'data:image/svg+xml;base64,PC8+', promptpay_id: '004-99', merchant_name: 'LLK', bank_name: 'กสิกรไทย', bank_account: '230-1', bank_holder: 'นาย ก' } },
    'POST /payments/charge': { data: { status: 'confirmed', booking: { ...booking, status: 'confirmed', paid_amount: 4000 } }, message: 'ชำระเงินสำเร็จ' },
  });
  await wait(60);
  w.eval('openPaymentFor("R2")');
  await wait(120);
  step('เห็น QR พร้อมเพย์ + เลขบัญชี', () => {
    assert(w.document.querySelector('.qr-wrap img'), 'ไม่มีรูป QR');
    assert(text().includes('กสิกรไทย') && text().includes('230-1'), 'ไม่มีข้อมูลบัญชี');
    assert(text().includes('แนบสลิปการโอน'), 'ไม่มีช่องแนบสลิป');
  });
  step('เลือกมัดจำได้ และยอดเปลี่ยนตาม quote', () => {
    assert(text().includes('มัดจำก่อน 38%'), 'ไม่มีตัวเลือกมัดจำ');
    assert(text().includes('4,000'), 'ยอดเต็มหาย');
  });
  step('ไม่มีปุ่ม Beam ในโหมดโอนเอง', () => assert(!text().includes('สร้าง QR พร้อมเพย์'), 'ไม่ควรมีปุ่มของ Beam'));

  const slip = w.document.getElementById('slip');
  Object.defineProperty(slip, 'files', { value: [new w.File(['x'], 's.png', { type: 'image/png' })], configurable: true });
  w.URL.createObjectURL = () => 'blob:x';
  slip.dispatchEvent(new w.Event('change'));
  await wait(20);
  const submit = [...w.document.querySelectorAll('#payArea .btn')].find((b) => b.textContent.includes('ยืนยันการชำระเงิน'));
  step('ปุ่มยืนยันเปิดหลังแนบสลิป', () => assert(submit && !submit.disabled, 'ปุ่มยังปิดอยู่'));
  submit.dispatchEvent(new w.Event('click'));
  await wait(80);
  step('ส่งสลิปแบบ multipart พร้อมยอดจากเซิร์ฟเวอร์', () => {
    const body = bodies.find((b) => b.key === 'POST /payments/charge')?.body;
    assert(body, 'ไม่ได้ยิง charge');
    assert(typeof body.get === 'function', 'ต้องเป็น FormData');
    assert(body.get('amount') === '4000', 'ยอดผิด: ' + body.get('amount'));
    assert(body.get('payment_type') === 'full', 'payment_type ผิด');
    assert(body.get('slip_image'), 'ไม่ได้แนบไฟล์');
  });
  step('ขึ้นหน้าชำระสำเร็จ', () => assert(text().includes('ชำระเงินสำเร็จ'), 'ไม่ขึ้นผลสำเร็จ: ' + text().slice(0, 100)));
}

/* ═══════════ ฉาก 3: ทริปต่างประเทศ + รอบบิน ═══════════ */
console.log('\n▶ ทริปต่างประเทศ (รอบบิน)');
{
  const trip = TRIP({ is_international: true, destination_type: 'international', country_label: 'ญี่ปุ่น' });
  const schedule = SCHEDULE({ transport_type: 'flight', pickup_points: [{ id: 1, pickup_location: 'เก่า', price: 2000 }],
    flight_plan: { meeting_point: 'สนามบินสุวรรณภูมิ ประตู 4', meeting_time: '05:30', legs: [] } });
  const { w, calls, text } = makeWorld({
    'POST /auth/line/liff': { data: { token: 'x' } },
    'GET /trips': { data: [trip], meta: { current_page: 1, last_page: 1, total: 1 } },
    'GET /trips/t': { data: trip },
    'GET /trips/t/schedules': { data: [schedule] },
    'GET /schedules/9': { data: schedule },
    'GET /schedules/9/seats': { data: { has_seat_map: false, seat_selection_disabled_reason: 'ที่นั่งบนเครื่องบินจัดโดยสายการบิน', available_seats: 8, total_seats: 10 } },
    'GET /countries': { data: [{ code: 'TH', name: 'ไทย', flag: '🇹🇭' }, { code: 'JP', name: 'ญี่ปุ่น', flag: '🇯🇵' }] },
  });
  await wait(60);
  w.document.querySelector('.card').dispatchEvent(new w.Event('click'));
  await wait(60);
  step('ป้ายเดินทางโดยเครื่องบิน', () => assert(text().includes('เดินทางโดยเครื่องบิน'), 'ไม่มีป้ายบิน'));
  [...w.document.querySelectorAll('.schedule-actions .btn')][0].dispatchEvent(new w.Event('click'));
  await wait(80);
  step('รอบบิน: ไม่มีผัง ไม่มีจุดรับ มีจุดนัดพบ', () => {
    assert(!w.document.querySelector('.seat'), 'ไม่ควรมีผังที่นั่ง');
    assert(!text().includes('จุดขึ้นรถ'), 'รอบบินไม่ควรมีจุดขึ้นรถ');
    assert(text().includes('สนามบินสุวรรณภูมิ'), 'ไม่มีจุดนัดพบ');
    assert(text().includes('05:30'), 'ไม่มีเวลานัดพบ');
  });
  w.document.getElementById('next').dispatchEvent(new w.Event('click'));
  await wait(60);
  step('บังคับเอกสารเดินทาง', () => {
    assert(text().includes('เลขที่พาสปอร์ต'), 'ไม่มีช่องพาสปอร์ต');
    assert(text().includes('สัญชาติ'), 'ไม่มีช่องสัญชาติ');
  });
  await wait(40);
  step('เลือกสัญชาติอื่นแล้วเลขบัตรประชาชนหายไป', () => {
    const nat = w.document.querySelector('#pax-0 [data-f="nationality"]');
    assert(nat && nat.options.length === 2, 'ตัวเลือกสัญชาติไม่ครบ');
    nat.value = 'JP';
    nat.dispatchEvent(new w.Event('change'));
    assert(!w.document.querySelector('#pax-0 [data-f="id_card"]'), 'ชาวต่างชาติไม่ควรถูกบังคับเลขบัตรไทย');
  });
}

/* ═══════════ ฉาก 4: รอบเต็ม → คิวรอที่นั่ง ═══════════ */
console.log('\n▶ รอบเต็ม / คิวรอ');
{
  const trip = TRIP();
  const schedule = SCHEDULE({ available_seats: 0, bookable_seats: 0, join_trip_enabled: false });
  const { w, calls, text } = makeWorld({
    'POST /auth/line/liff': { data: { token: 'x' } },
    'GET /trips': { data: [trip], meta: { current_page: 1, last_page: 1, total: 1 } },
    'GET /trips/t': { data: trip },
    'GET /trips/t/schedules': { data: [schedule] },
    'GET /schedules/9/waitlist/status': { data: { in_waitlist: false } },
    'POST /schedules/9/waitlist': { data: { position: 2, seat_count: 2 } },
  });
  await wait(60);
  w.document.querySelector('.card').dispatchEvent(new w.Event('click'));
  await wait(60);
  step('รอบเต็มไม่มีปุ่มจอง แต่มีปุ่มคิวรอ', () => {
    assert(text().includes('ที่นั่งเต็มแล้ว'), 'ไม่บอกว่าเต็ม');
    assert(text().includes('ขอคิวรอที่นั่ง'), 'ไม่มีปุ่มคิวรอ');
    assert(!text().includes('จองที่นั่ง'), 'ไม่ควรมีปุ่มจอง');
  });
  [...w.document.querySelectorAll('.schedule-actions .btn')][0].dispatchEvent(new w.Event('click'));
  await wait(60);
  step('เปิดแผ่นขอคิว + เลือกจำนวนได้', () => {
    const sheet = w.document.querySelector('.sheet-overlay');
    assert(sheet, 'ไม่มีแผ่นขอคิว');
    w.document.getElementById('wlPlus').dispatchEvent(new w.Event('click'));
    assert(w.document.getElementById('wlNum').textContent === '2', 'ตัวนับไม่ขยับ');
  });
  w.document.getElementById('wlJoin').dispatchEvent(new w.Event('click'));
  await wait(60);
  step('ยิงเข้าคิวจริง', () => assert(calls.includes('POST /schedules/9/waitlist'), 'ไม่ได้ยิงเข้าคิว'));
}

console.log(failed ? `\n❌ ล้มเหลว ${failed} ข้อ` : '\n✅ ผ่านทั้งหมด');
process.exit(failed ? 1 : 0);
