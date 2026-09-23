/* ของที่ตามเว็บ/แอปเข้ามาหลัง Phase 6: ชื่อไทยตามบัตร, วันขึ้นรถของรอบที่ออกก่อนวันทริป,
 * ปุ่มบอกสถานะที่จุดนัด, การ์ดหารถ และลิงก์ใบเดินทาง */
import { makeWorld, wait, step, assert, finish } from './support.mjs';

const REF = 'LLK-20261205-0009';
const EARLY = 'ขึ้นรถ ศุกร์ที่ 4 ธันวาคม 2569 เวลา 20:00 น. — รถออกก่อนวันทริป 1 วัน';

const TRIP = { id: 7, slug: 'pai', title: 'ปาย 3 วัน', location: 'แม่ฮ่องสอน', destination_type: 'domestic' };
const SCHEDULE = {
  id: 21, trip_id: 7, departure_date: '2026-12-05', return_date: '2026-12-07',
  departs_at: '2026-12-04 20:00:00', departs_before_trip_day: true, early_departure_label: EARLY,
  status: 'open', transport_type: 'van', pickup_points: [], vehicle_options: [],
};

const booking = (extra = {}) => ({
  id: 9, booking_ref: REF, status: 'confirmed', viewer_is_owner: true,
  total_amount: 5200, paid_amount: 5200, payment_type: 'full', qr_code: 'LLKQR9', checked_in: false,
  schedule: { ...SCHEDULE, trip: TRIP },
  pickup_point: { id: 8, pickup_location: 'อนุสาวรีย์ชัยฯ', pickup_time: '20:00', arrived_at: null },
  seats: [{ seat_id: 'A2' }], passengers: [], installment_payments: [], assigned_staff: [],
  split: { enabled: false }, payment_gateway: { provider: 'manual', methods: [] },
  pickup_status: null, pickup_status_at: null, pickup_status_eta_minutes: null, pickup_status_label: null,
  pickup_status_open: true,
  brief_url: 'https://llk.test/t/brief123', brief_ack_at: null,
  ...extra,
});

const baseRoutes = (b) => ({
  'POST /auth/line/liff': { data: { token: 'x' } },
  'GET /sale-campaign/active': { data: null },
  'GET /referral': { data: { enabled: false } },
  'GET /me/claimable-bookings': { data: { count: 0, trips: [] } },
  'GET /waitlist': { data: [] },
  'GET /trips': { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
  'GET /bookings': { data: [b], meta: { upcoming_count: 1, past_count: 0 } },
  [`GET /bookings/${REF}`]: { data: b },
  [`GET /bookings/${REF}/check-in-qr`]: { data: { code: 'LLKQR9', qr_data_uri: 'data:image/svg+xml;base64,PC8+', checked_in: false } },
  [`GET /bookings/${REF}/announcements`]: { data: [] },
  [`GET /bookings/${REF}/tracking`]: {
    data: {
      share_url: 'https://llk.test/track/abc', license_plate: 'ฮข 4521 กทม', vehicle_name: 'Toyota Commuter',
      vehicle_color: 'ขาว', vehicle_photo: 'https://cdn/van.jpg', driver_name: 'ลุงชัย', driver_phone: '0811111111',
      pickup_arrived_at: '2026-12-04T12:42:00Z', pickup_arrival_note: 'จอดหน้าเซเว่น', pickup_arrival_photo_url: 'https://cdn/spot.jpg',
    },
  },
  [`POST /bookings/${REF}/pickup-status`]: {
    data: { booking_ref: REF, pickup_status: 'late', pickup_status_at: '2026-12-04T12:30:00Z', pickup_status_eta_minutes: 15, label: 'อาจสาย ~15 นาที' },
  },
});

/* ═══════════ ชื่อไทยตามบัตร ═══════════ */
console.log('\n▶ ชื่อไทยตามบัตรประชาชน');
{
  const { w } = makeWorld(baseRoutes(booking()), { quiet: true });
  await wait();
  const NOT_THAI = 'กรุณากรอกชื่อ-นามสกุลเป็นภาษาไทยตามบัตรประชาชน (ใช้ส่งทำประกันการเดินทาง)';
  const NO_SURNAME = 'กรุณากรอกทั้งชื่อและนามสกุล เว้นวรรคระหว่างชื่อกับนามสกุล';

  step('ข้อความตรงกับ App\\Rules\\ThaiName ทุกตัวอักษร', () => {
    assert(w.thaiNameError('Somchai Jaidee') === NOT_THAI, 'ชื่ออังกฤษ');
    assert(w.thaiNameError('สมชาย') === NO_SURNAME, 'ไม่มีนามสกุล');
  });
  step('ตัวเลขและอักษรละตินปนไม่ผ่าน', () => {
    assert(w.thaiNameError('สมชาย ใจดี 2') === NOT_THAI, 'มีตัวเลข');
    assert(w.thaiNameError('สมชาย Jaidee') === NOT_THAI, 'ปนอังกฤษ');
    assert(w.thaiNameError('ๆๆ ๆๆ') === NOT_THAI, 'ไม่มีพยัญชนะไทยสักตัว');
  });
  step('ชื่อไทยตามบัตรผ่าน รวมชื่อย่อ/นามสกุลมีขีด/เว้นวรรคซ้ำ', () => {
    assert(w.thaiNameError('สมชาย ใจดี') === '', 'ชื่อปกติ');
    assert(w.thaiNameError('ม.ล. สมชาย ใจ-ดี') === '', 'ชื่อย่อ + ขีด');
    assert(w.thaiNameError('  สมชาย   ใจดี ') === '', 'เว้นวรรคซ้ำ');
  });
}

/* ═══════════ วันเดินทาง ═══════════ */
console.log('\n▶ วันเดินทาง: หารถ + บอกสถานะ + ใบเดินทาง');
{
  const world = makeWorld(baseRoutes(booking()), { quiet: true });
  const { w, text, $, $$, opened, bodies, sheetText } = world;
  await wait();

  w.eval('showMyBookings()');
  await wait(80);
  step('รายการการจองบอกวันขึ้นรถจริงของรอบที่ออกก่อนวันทริป', () => {
    assert($('.early-departure'), 'ไม่มีบรรทัดวันขึ้นรถ');
    assert(text().includes(EARLY), 'ประโยคไม่ตรงกับที่เซิร์ฟเวอร์ส่งมา');
  });

  w.eval(`showBookingDetail("${REF}")`);
  await wait(120);

  step('หัวการ์ดการจองบอกวันขึ้นรถ', () => assert(text().includes('รถออกก่อนวันทริป 1 วัน'), 'ไม่มีบรรทัดวันขึ้นรถ'));

  step('การ์ดหารถ: ทะเบียนตัวใหญ่ สี รูปจุดจอด', () => {
    const card = $('.van-card');
    assert(card && !card.hidden, 'ไม่มีการ์ดหารถ');
    assert($('.van-plate')?.textContent === 'ฮข 4521 กทม', 'ไม่มีทะเบียน');
    assert(card.textContent.includes('สีขาว'), 'ไม่มีสีรถ');
    assert(card.textContent.includes('รถถึงจุดรับแล้ว · 19:42 น.'), 'เวลาถึงต้องเป็นเวลาไทย: ' + card.textContent);
    assert(card.textContent.includes('จอดหน้าเซเว่น'), 'ไม่มีโน้ตจุดจอด');
    assert(card.querySelector('.van-spot img'), 'ไม่มีรูปจุดจอด');
  });

  step('ปุ่มดูตำแหน่งรถสดเปิดลิงก์ติดตาม', () => {
    world.click($$('.van-actions .btn').find((b) => b.textContent.includes('ดูตำแหน่งรถสด')));
    assert(opened.includes('https://llk.test/track/abc'), 'ไม่ได้เปิดลิงก์ติดตาม');
  });

  step('ลิงก์ใบเดินทาง', () => {
    world.click('.brief-link');
    assert(opened.includes('https://llk.test/t/brief123'), 'ไม่ได้เปิดใบเดินทาง');
  });

  step('ปุ่มบอกสถานะ 3 ปุ่ม + บอกว่าไม่ใช่การเช็คอิน', () => {
    const chips = $$('.pickup-status .status-row .chip');
    assert(chips.length === 3, 'ปุ่มไม่ครบ 3');
    assert($('.pickup-status').textContent.includes('ไม่ใช่การเช็คอิน'), 'ไม่มีคำเตือนเรื่องเช็คอิน');
  });

  world.click($$('.pickup-status .status-row .chip').find((b) => b.textContent.includes('อาจสาย')));
  await wait(20);
  step('อาจสาย ถามว่ากี่นาที', () => assert(sheetText().includes('สายประมาณเท่าไหร่'), 'ไม่มีแผ่นถามนาที'));

  world.click([...w.document.querySelectorAll('.sheet-overlay .chip')].find((b) => b.textContent === '15 นาที'));
  await wait(60);
  step('ส่ง late + 15 นาที แล้วการ์ดขึ้นสถานะจากเซิร์ฟเวอร์', () => {
    const sent = bodies.find((b) => b.key === `POST /bookings/${REF}/pickup-status`);
    assert(sent, 'ไม่ได้ส่งสถานะ');
    const body = JSON.parse(sent.body);
    assert(body.status === 'late' && body.eta_minutes === 15, 'ส่งผิด: ' + sent.body);
    assert($('.pickup-status').textContent.includes('อาจสาย ~15 นาที'), 'ไม่แสดงสถานะใหม่');
    assert($('.pickup-status .chip.on')?.textContent.includes('อาจสาย'), 'ปุ่มที่เลือกไม่ติดสี');
  });
}

/* ═══════════ ปิดเมื่อไม่ใช่เวลา ═══════════ */
console.log('\n▶ นอกวันเดินทาง / รอบบิน');
{
  const world = makeWorld(baseRoutes(booking({ pickup_status_open: false, brief_url: null })), { quiet: true });
  const { w, $, calls } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}")`);
  await wait(100);
  step('นอกหน้าต่างเวลา: ไม่มีปุ่มสถานะ ไม่มีการ์ดหารถ ไม่ยิง /tracking', () => {
    assert(!$('.pickup-status'), 'ไม่ควรมีปุ่มสถานะ');
    assert(!$('.van-card'), 'ไม่ควรมีการ์ดหารถ');
    assert(!calls.includes(`GET /bookings/${REF}/tracking`), 'ไม่ควรโหลด /tracking');
  });
  step('ยังไม่ได้ส่งใบเดินทาง: ไม่มีลิงก์', () => assert(!$('.brief-link'), 'ไม่ควรมีลิงก์ใบเดินทาง'));
}
{
  const flight = booking();
  flight.schedule = { ...flight.schedule, transport_type: 'flight' };
  const world = makeWorld(baseRoutes(flight), { quiet: true });
  const { w, $ } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}")`);
  await wait(100);
  step('รอบบิน: มีปุ่มสถานะ แต่ไม่มีการ์ดหารถ', () => {
    assert($('.pickup-status'), 'ควรมีปุ่มสถานะ');
    assert(!$('.van-card'), 'รอบบินไม่มีรถให้หา');
  });
}

finish();
