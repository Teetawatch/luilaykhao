/* หน้ารายละเอียดทริป, หน้ารายละเอียดการจอง, การชำระรายการที่ค้าง และ deep link */
import { makeWorld, wait, step, assert, finish } from './support.mjs';

const TRIP = {
  id: 7, slug: 'pai', title: 'ปาย 3 วัน', location: 'แม่ฮ่องสอน', duration_days: 3,
  difficulty: 'moderate', distance_km: 12.5, elevation_gain_m: 800,
  rating: 4.6, review_count: 24, price_per_person: 5200, min_price: 5200,
  destination_type: 'domestic', type: 'trekking', is_women_only: true,
  description: 'ทริปเดินป่าปายสามวัน',
  highlights: [
    { icon: 'landscape', title: 'ทะเลหมอกยามเช้า', desc: 'ตื่นตี 5 ขึ้นจุดชมวิว' },
    'น้ำตกลับที่คนไม่ค่อยรู้จัก',
  ],
  inclusions: ['รถตู้ไป-กลับ', 'อาหาร 5 มื้อ'], exclusions: ['ค่าใช้จ่ายส่วนตัว'],
  gallery: ['https://cdn/x1.jpg', 'https://cdn/x2.jpg'],
  videos: ['https://cdn/clip.mp4'],
  itinerary: [{ sector: 'ช่วงที่ 1', items: [{ day: 1, title: 'ออกเดินทาง', description: 'เจอกันที่จุดนัดพบ' }] }],
  preparations: ['รองเท้าเดินป่า', 'ไฟฉาย'],
  faqs: [{ question: 'มีสัญญาณโทรศัพท์ไหม', answer: 'มีบางจุด' }],
  cancellation_policy: { tiers: [{ range: 'ก่อน 45 วัน', detail: 'คืนเต็มจำนวน', percent: 100 }], note: 'คืนเงินภายใน 7-14 วัน' },
  must_know: { items: [], remarks: 'พกยาประจำตัวมาด้วยครับ' },
  rental_items: [], document_requirements: [{ key: 'id-copy', label: 'สำเนาบัตรประชาชน', required: true, note: '' }],
};

const SCHEDULE = {
  id: 21, trip_id: 7, departure_date: '2026-12-05', return_date: '2026-12-07',
  status: 'open', price: 5200, transport_type: 'van',
  available_seats: 4, bookable_seats: 4, total_seats: 10,
  join_trip_enabled: false, pickup_points: [
    { id: 8, pickup_location: 'อนุสาวรีย์ชัยฯ', region_label: 'กรุงเทพ', price: 5200, pickup_time: '20:00', map_url: 'https://maps/x' },
    { id: 9, pickup_location: 'รังสิต', region_label: 'ปทุมธานี', price: 5400, pickup_time: '20:40' },
  ],
  vehicle_options: [], weather: [{ temp_max: 24, summary: 'มีเมฆบางส่วน' }],
};

const BOOKING = {
  id: 3, booking_ref: 'LLK-20261205-0007', status: 'confirmed', viewer_is_owner: true,
  total_amount: 10400, paid_amount: 4000, payment_type: 'deposit',
  deposit_amount: 4000, balance_amount: 6400, balance_due_at: '2026-11-20T00:00:00Z',
  balance_paid_at: null, qr_code: 'LLKQR123', checked_in: false,
  can_modify: true, can_reschedule: true,
  modification_deadline: '2026-11-20T00:00:00Z', reschedule_deadline: '2026-11-05T00:00:00Z',
  schedule: { ...SCHEDULE, trip: TRIP },
  pickup_point: { id: 8, pickup_location: 'อนุสาวรีย์ชัยฯ', region_label: 'กรุงเทพ', pickup_time: '20:00', map_url: 'https://maps/x' },
  seats: [{ seat_id: 'A2' }, { seat_id: 'A3' }],
  passengers: [
    { id: 51, title: 'นางสาว', name: 'สมหญิง ใจดี', nickname: 'หญิง', phone: '0812345678', blood_group: 'A', seat_id: 'A2' },
    { id: 52, title: 'นางสาว', name: 'สมศรี ใจงาม', nickname: 'ศรี', phone: '0898765432', blood_group: 'B', seat_id: 'A3' },
  ],
  installment_payments: [],
  assigned_staff: [{ id: 2, name: 'พี่ตูน', nickname: 'ตูน', phone: '0800000000' }],
  split: { enabled: false, total_shares: 0, paid_shares: 0 },
  payment_gateway: { provider: 'manual', methods: [] },
};

const routes = {
  'POST /auth/line/liff': { data: { token: 'x' } },
  'GET /trips': { data: [TRIP], meta: { current_page: 1, last_page: 1, total: 1 } },
  'GET /trips/pai': { data: TRIP },
  'GET /trips/pai/schedules': { data: [SCHEDULE] },
  'GET /trips/pai/related': { data: [{ slug: 'chiangdao', title: 'เชียงดาว 2 วัน', min_price: 3900, cover_image: '' }] },
  'GET /reviews': { data: [{ id: 1, rating: 5, comment: 'สนุกมาก', user: { name: 'มายด์' }, images: [], reply: 'ขอบคุณครับ' }], meta: {} },
  'GET /bookings': { data: [BOOKING], meta: { upcoming_count: 1, past_count: 0 } },
  'GET /bookings/LLK-20261205-0007': { data: BOOKING },
  'GET /bookings/LLK-20261205-0007/check-in-qr': { data: { code: 'LLKQR123', qr_data_uri: 'data:image/svg+xml;base64,PC8+', checked_in: false } },
  'GET /bookings/LLK-20261205-0007/documents': { data: [{ id: 1, passenger_id: 51, requirement_key: 'id-copy', original_name: 'id.jpg' }] },
  'GET /bookings/LLK-20261205-0007/receipts': { data: [{ receipt_no: 'RC-1', kind_label: 'ใบเสร็จมัดจำ', amount: 4000, pdf_url: 'https://x/pdf' }] },
  'GET /bookings/LLK-20261205-0007/split': { data: { enabled: false, outstanding_amount: 6400, is_owner: true, shares: [] } },
  'GET /bookings/LLK-20261205-0007/tracking': { data: { share_url: 'https://llk/track/abc' } },
  'POST /bookings/LLK-20261205-0007/change-pickup': { data: BOOKING },
  'POST /bookings/LLK-20261205-0007/reschedule': { data: BOOKING },
  'POST /bookings/LLK-20261205-0007/cancel': { data: BOOKING },
  'POST /bookings/LLK-20261205-0007/split': { data: { enabled: true } },
  'GET /payments/LLK-20261205-0007/promptpay': { data: { amount: 6400, qr_data_uri: 'data:image/svg+xml;base64,PC8+', promptpay_id: '004-9', bank_name: 'กสิกรไทย', bank_account: '230-1', bank_holder: 'นาย ก' } },
  'POST /payments/charge-balance': { data: { status: 'confirmed' } },
};

/* ═══════════ หน้ารายละเอียดทริป ═══════════ */
console.log('\n▶ หน้ารายละเอียดทริป');
{
  const world = makeWorld(routes);
  const { w, click, text, $, $$ } = world;
  await wait();
  click('.card');
  await wait(80);

  step('หัวเรื่อง + ป้าย + รอบเดินทาง', () => {
    assert(text().includes('ปาย 3 วัน'), 'ไม่มีชื่อทริป');
    assert(text().includes('ปานกลาง'), 'ไม่มีระดับความยาก');
    assert(text().includes('12.5 กม.'), 'ไม่มีระยะทาง');
    assert(text().includes('⭐ 4.6'), 'ไม่มีคะแนนรีวิว');
    assert(text().includes('ทริปผู้หญิงล้วน'), 'ไม่มีป้ายทริปผู้หญิง');
    assert(text().includes('มีเมฆบางส่วน'), 'ไม่มีพยากรณ์อากาศของรอบ');
    assert(text().includes('ส่งทริปนี้ให้เพื่อนใน LINE'), 'ไม่มีปุ่มแชร์');
  });

  step('ไฮไลต์เป็นอ็อบเจ็กต์ ต้องไม่ออกมาเป็น [object Object]', () => {
    assert(!text().includes('[object Object]'), 'ยังมี [object Object] อยู่');
    assert(text().includes('ทะเลหมอกยามเช้า'), 'ไม่มีหัวข้อไฮไลต์');
    assert(text().includes('ตื่นตี 5'), 'ไม่มีคำอธิบายไฮไลต์');
    assert(text().includes('น้ำตกลับ'), 'ไฮไลต์แบบสตริงเดิมต้องยังแสดงได้');
  });

  step('แท็บภาพรวม: รวมอะไร/ไม่รวม, รูป, นโยบายยกเลิก', () => {
    assert(text().includes('รถตู้ไป-กลับ'), 'ไม่มีรายการที่รวม');
    assert(text().includes('ค่าใช้จ่ายส่วนตัว'), 'ไม่มีรายการที่ไม่รวม');
    assert($$('.photo-row img').length === 2, 'รูปแกลเลอรีไม่ครบ');
    assert($('.trip-video'), 'ไม่มีวิดีโอ');
    assert(text().includes('คืนเต็มจำนวน'), 'ไม่มีนโยบายยกเลิก');
  });

  await wait(60);
  step('ทริปที่คล้ายกันโหลดตามมา', () => assert(text().includes('เชียงดาว'), 'ไม่มีทริปที่คล้ายกัน'));

  click($$('.tab')[1]);
  await wait(40);
  step('แท็บกำหนดการ', () => {
    assert(text().includes('ช่วงที่ 1'), 'ไม่มีช่วงกำหนดการ');
    assert(text().includes('วันที่ 1 · ออกเดินทาง'), 'ไม่มีรายวัน');
  });

  click($$('.tab')[2]);
  await wait(40);
  step('แท็บเตรียมตัว + FAQ พับได้', () => {
    assert(text().includes('รองเท้าเดินป่า'), 'ไม่มีสิ่งที่ต้องเตรียม');
    assert(text().includes('พกยาประจำตัว'), 'ไม่มีสิ่งที่ควรรู้');
    assert(text().includes('มีสัญญาณโทรศัพท์ไหม'), 'ไม่มีคำถาม');
    assert(w.document.querySelector('.faq-a').hidden, 'คำตอบต้องพับไว้ก่อน');
    click('.faq-q');
    assert(!w.document.querySelector('.faq-a').hidden, 'กดแล้วคำตอบต้องเปิด');
  });

  click($$('.tab')[3]);
  await wait(80);
  step('แท็บรีวิว', () => {
    assert(text().includes('4.6'), 'ไม่มีคะแนนเฉลี่ย');
    assert(text().includes('สนุกมาก'), 'ไม่มีรีวิว');
    assert(text().includes('ทีมงานตอบกลับ'), 'ไม่มีคำตอบจากทีมงาน');
  });
}

/* ═══════════ หน้ารายละเอียดการจอง ═══════════ */
console.log('\n▶ หน้ารายละเอียดการจอง');
{
  const world = makeWorld(routes, { quiet: true });
  const { w, click, text, $, $$, alerts, opened, calls } = world;
  await wait();
  w.eval('showBookingDetail("LLK-20261205-0007")');
  await wait(100);

  step('แท็บทริป: QR เช็คอิน + จุดขึ้นรถ + ทีมงาน', () => {
    assert(text().includes('LLKQR123'), 'ไม่มีรหัสเช็คอิน');
    assert($('.qr-wrap img'), 'ไม่มีรูป QR');
    assert(text().includes('อนุสาวรีย์ชัยฯ'), 'ไม่มีจุดขึ้นรถ');
    assert(text().includes('พี่ตูน') || text().includes('ตูน'), 'ไม่มีทีมงาน');
    assert(text().includes('เพิ่มลงปฏิทิน'), 'ไม่มีปุ่มปฏิทิน');
  });

  step('ยอดคงเหลือขึ้นบนสุด', () => {
    assert(text().includes('ยอดคงเหลือ ฿6,400'), 'ไม่เห็นยอดคงเหลือ: ' + text().slice(0, 160));
  });

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('เพิ่มลงปฏิทิน')));
  step('ไฟล์ปฏิทินเป็น .ics ของรอบนี้', () => {
    const ics = opened.find((u) => u.startsWith('data:text/calendar'));
    assert(ics, 'ไม่ได้เปิดไฟล์ปฏิทิน');
    assert(decodeURIComponent(ics).includes('DTSTART;VALUE=DATE:20261205'), 'วันเริ่มผิด');
    assert(decodeURIComponent(ics).includes('DTEND;VALUE=DATE:20261208'), 'วันจบต้องเป็นวันถัดจากวันกลับ');
  });

  click($$('.tab')[1]);
  await wait(80);
  step('แท็บผู้เดินทาง + เอกสารที่แนบแล้ว', () => {
    assert(text().includes('สมหญิง ใจดี'), 'ไม่มีผู้เดินทาง');
    assert(text().includes('สำเนาบัตรประชาชน'), 'ไม่มีรายการเอกสาร');
    assert(text().includes('id.jpg'), 'ไม่แสดงไฟล์ที่แนบแล้ว');
    assert(text().includes('ยังไม่ได้แนบ'), 'คนที่ยังไม่แนบต้องขึ้นว่ายังไม่ได้แนบ');
  });

  click($$('.tab')[2]);
  await wait(100);
  step('แท็บการเงิน: สรุปยอด + แบ่งจ่าย + ใบเสร็จ', () => {
    assert(text().includes('มัดจำ'), 'ไม่มีรูปแบบการชำระ');
    assert(text().includes('฿10,400'), 'ไม่มียอดรวม');
    assert(text().includes('แบ่งจ่ายกับเพื่อน'), 'ไม่มีส่วนแบ่งจ่าย');
    assert(text().includes('ใบเสร็จมัดจำ'), 'ไม่มีใบเสร็จ');
  });

  click($$('.tab')[3]);
  await wait(40);
  step('แท็บจัดการมีครบ 4 อย่าง', () => {
    ['เปลี่ยนจุดขึ้นรถ', 'เลื่อนไปรอบอื่น', 'แชร์ลิงก์ติดตาม', 'ยกเลิกการจอง']
      .forEach((label) => assert(text().includes(label), 'ไม่มีเมนู ' + label));
  });

  click([...w.document.querySelectorAll('.pick')].find((p) => p.textContent.includes('เปลี่ยนจุดขึ้นรถ')));
  await wait(40);
  step('แผ่นเปลี่ยนจุดขึ้นรถแสดงทุกจุดของรอบ', () => {
    assert(world.sheetText().includes('รังสิต'), 'ไม่มีจุดอื่นให้เลือก');
  });
  const radio = [...w.document.querySelectorAll('.sheet input[name="newpickup"]')][1];
  radio.checked = true;
  radio.dispatchEvent(new w.Event('change'));
  await wait(80);
  step('เลือกแล้วยิงเปลี่ยนจุดจริง', () => assert(calls.includes('POST /bookings/LLK-20261205-0007/change-pickup'), 'ไม่ได้ยิง change-pickup'));

  await wait(60);
  click($$('.tab')[3]);
  await wait(40);
  click([...w.document.querySelectorAll('.pick')].find((p) => p.textContent.includes('แชร์ลิงก์ติดตาม')));
  await wait(80);
  step('ขอลิงก์ติดตามจากเซิร์ฟเวอร์', () => {
    assert(calls.includes('GET /bookings/LLK-20261205-0007/tracking'), 'ไม่ได้ขอลิงก์');
    assert(alerts.some((a) => a.includes('llk/track/abc') || a.includes('คัดลอก')), 'ไม่ได้ส่งลิงก์ให้ผู้ใช้');
  });
}

/* ═══════════ ชำระยอดคงเหลือ ═══════════ */
console.log('\n▶ ชำระยอดคงเหลือ (โอน+สลิป)');
{
  const world = makeWorld(routes, { quiet: true });
  const { w, click, text, $, bodies, calls } = world;
  await wait();
  w.eval('showBookingDetail("LLK-20261205-0007")');
  await wait(100);
  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ชำระยอดคงเหลือ')));
  await wait(100);

  step('QR ของยอดคงเหลือมาจากเซิร์ฟเวอร์', () => {
    assert(text().includes('ยอดคงเหลือ'), 'ไม่ได้เข้าหน้าชำระยอดคงเหลือ');
    assert(text().includes('฿6,400'), 'ยอดผิด');
    assert($('.qr-wrap img'), 'ไม่มี QR');
    const q = bodies.length ? '' : '';
    assert(calls.includes('GET /payments/LLK-20261205-0007/promptpay'), 'ไม่ได้ขอ QR');
  });

  const slip = $('#outArea input[type=file]');
  Object.defineProperty(slip, 'files', { value: [new w.File(['x'], 's.png', { type: 'image/png' })], configurable: true });
  slip.dispatchEvent(new w.Event('change'));
  await wait(20);
  click([...w.document.querySelectorAll('#outArea .btn')].find((b) => b.textContent.includes('ยืนยันการชำระเงิน')));
  await wait(80);
  step('ส่งสลิปไปที่ charge-balance', () => {
    const sent = bodies.find((b) => b.key === 'POST /payments/charge-balance');
    assert(sent, 'ไม่ได้ยิง charge-balance');
    assert(typeof sent.body.get === 'function' && sent.body.get('slip_image'), 'ไม่ได้แนบสลิป');
  });
}

/* ═══════════ deep link ═══════════ */
console.log('\n▶ deep link');
{
  const world = makeWorld(routes, { quiet: true, search: '?trip=pai' });
  await wait(100);
  step('เปิดด้วย ?trip=slug ลงหน้าทริปเลย', () => {
    assert(world.text().includes('ปาย 3 วัน'), 'ไม่ได้ลงหน้าทริป: ' + world.text().slice(0, 90));
  });
}
{
  const world = makeWorld(routes, { quiet: true, search: '?liff.state=%3Fbooking%3DLLK-20261205-0007' });
  await wait(120);
  step('เปิดด้วย liff.state ลงหน้าการจองนั้น', () => {
    assert(world.text().includes('LLK-20261205-0007'), 'ไม่ได้ลงหน้าการจอง: ' + world.text().slice(0, 90));
  });
}

/* ═══════════ แบ่งจ่ายกลุ่ม + ค่างวดผ่าน Beam + ยกเลิก/เลื่อนรอบ ═══════════ */
console.log('\n▶ แบ่งจ่ายกลุ่ม / ค่างวด / ยกเลิก');
{
  const beamBooking = {
    ...BOOKING,
    payment_type: 'installment',
    payment_gateway: { provider: 'beam', methods: ['QR_PROMPT_PAY'] },
    installment_payments: [
      { id: 91, installment_no: 1, amount: 5200, due_date: '2026-10-01', status: 'paid' },
      { id: 92, installment_no: 2, amount: 5200, due_date: '2026-11-01', status: 'pending' },
    ],
    balance_amount: 0,
    split: { enabled: true, total_shares: 2, paid_shares: 0 },
  };
  const beamRoutes = {
    ...routes,
    'GET /bookings/LLK-20261205-0007': { data: beamBooking },
    'GET /bookings/LLK-20261205-0007/split': {
      data: {
        enabled: true, total_shares: 2, paid_shares: 0, outstanding_amount: 6400, is_owner: true,
        shares: [
          { id: 71, name: 'ฉัน', amount: 3200, status: 'pending', is_mine: true, pay_url: 'https://llk/pay-share/a' },
          { id: 72, name: 'เพื่อน ก', amount: 3200, status: 'pending', is_mine: false, pay_url: 'https://llk/pay-share/b' },
        ],
      },
    },
    'POST /payments/beam/charge': { data: { payment_id: 44, status: 'pending', amount: 5200, qr_image_base64: 'iVBOR' } },
    'GET /payments/beam/44': { data: { payment_id: 44, status: 'pending' } },
  };

  const world = makeWorld(beamRoutes, { quiet: true, shareTargetPicker: true });
  const { w, click, text, $$, bodies, calls, alerts } = world;
  await wait();
  w.eval('showBookingDetail("LLK-20261205-0007", "money")');
  await wait(120);

  step('ตารางงวดผ่อน + ปุ่มจ่ายเฉพาะงวดที่ยังไม่จ่าย', () => {
    assert(text().includes('งวดที่ 1'), 'ไม่มีงวดที่ 1');
    assert(text().includes('จ่ายแล้ว'), 'งวดที่จ่ายแล้วต้องมีป้าย');
    assert(text().includes('ชำระงวดนี้'), 'ไม่มีปุ่มจ่ายงวดที่ 2');
  });

  step('ส่วนแบ่งของกลุ่มแสดงครบ + ส่งลิงก์ให้เพื่อนได้', () => {
    assert(text().includes('เพื่อน ก'), 'ไม่มีรายชื่อส่วนแบ่ง');
    assert(text().includes('จ่ายส่วนนี้'), 'ไม่มีปุ่มจ่ายส่วนของตัวเอง');
    assert(text().includes('ส่งลิงก์จ่ายให้'), 'ไม่มีปุ่มส่งลิงก์');
  });

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ส่งลิงก์จ่ายให้ เพื่อน ก')));
  await wait(60);
  step('ส่งลิงก์ผ่าน shareTargetPicker', () => assert(alerts.some((a) => a.includes('ส่งลิงก์แล้ว')), 'ไม่ได้ส่งลิงก์'));

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ชำระงวดนี้')));
  await wait(120);
  step('ค่างวดผ่าน Beam ส่ง installment_id ของงวดนั้น', () => {
    assert(text().includes('ค่างวดที่ 2'), 'ไม่ได้เข้าหน้าจ่ายค่างวด');
    const sent = bodies.find((b) => b.key === 'POST /payments/beam/charge');
    assert(sent, 'ไม่ได้ยิง beam charge');
    const body = JSON.parse(sent.body);
    assert(body.purpose === 'installment_due', 'purpose ผิด: ' + body.purpose);
    assert(body.installment_id === 92, 'installment_id ผิด: ' + body.installment_id);
    assert(w.document.querySelector('.qr-wrap img'), 'ไม่มี QR จาก Beam');
  });

  // ยกเลิกการจอง
  w.eval('showBookingDetail("LLK-20261205-0007", "manage")');
  await wait(80);
  click([...w.document.querySelectorAll('.pick')].find((p) => p.textContent.includes('ยกเลิกการจอง')));
  await wait(40);
  step('แผ่นยกเลิกแสดงนโยบายก่อน', () => assert(world.sheetText().includes('คืนเต็มจำนวน'), 'ไม่มีนโยบายในแผ่นยกเลิก'));
  click([...w.document.querySelectorAll('.sheet .btn')].find((b) => b.textContent.includes('ยืนยันยกเลิกการจอง')));
  await wait(40);
  // ถามซ้ำอีกชั้นก่อนยิงจริง — ยกเลิกแล้วย้อนไม่ได้
  click([...w.document.querySelectorAll('.sheet-overlay')].pop().querySelector('[data-role="yes"]'));
  await wait(80);
  step('ยิงยกเลิกจริง', () => assert(calls.includes('POST /bookings/LLK-20261205-0007/cancel'), 'ไม่ได้ยิง cancel'));
}

/* ═══════════ เลื่อนรอบ ═══════════ */
console.log('\n▶ เลื่อนไปรอบอื่น');
{
  const other = { ...SCHEDULE, id: 22, departure_date: '2027-01-09', bookable_seats: 6 };
  const world = makeWorld({ ...routes, 'GET /trips/pai/schedules': { data: [SCHEDULE, other] } }, { quiet: true });
  const { w, click, calls } = world;
  await wait();
  w.eval('showBookingDetail("LLK-20261205-0007", "manage")');
  await wait(100);
  click([...w.document.querySelectorAll('.pick')].find((p) => p.textContent.includes('เลื่อนไปรอบอื่น')));
  await wait(100);
  step('เห็นเฉพาะรอบอื่นที่ยังว่าง', () => {
    const t = world.sheetText();
    assert(t.includes('9 ม.ค.') || t.includes('ม.ค.'), 'ไม่มีรอบใหม่ให้เลือก: ' + t.slice(0, 120));
    assert(!t.includes('5 ธ.ค.'), 'ไม่ควรเสนอรอบเดิม');
  });
  click([...w.document.querySelectorAll('.sheet .pick')][0]);
  await wait(40);
  click([...w.document.querySelectorAll('.sheet-overlay')].pop().querySelector('[data-role="yes"]'));
  await wait(80);
  step('ยิงเลื่อนรอบพร้อม target_schedule_id', () => {
    const sent = world.bodies.find((b) => b.key === 'POST /bookings/LLK-20261205-0007/reschedule');
    assert(sent, 'ไม่ได้ยิง reschedule');
    assert(JSON.parse(sent.body).target_schedule_id === 22, 'ส่งรอบปลายทางผิด');
  });
}

finish();
