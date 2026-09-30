/* รอบเดิมถูกยกเลิกเพราะเหตุสุดวิสัย (น้ำป่า) — ลูกค้าจาก LINE ต้องเลือกรอบใหม่เองได้
 * และคนที่ยังไม่มีแอปเห็นคำชวนโหลดแอป (คนที่มีแล้วต้องไม่เห็น) */
import { makeWorld, wait, step, assert, finish } from './support.mjs';

const REF = 'LLK-20260927-0003';
const TRIP = { id: 3, slug: 'thi-lo-su', title: 'น้ำตกทีลอซู', location: 'ตาก', destination_type: 'domestic' };
const FLOODED = { id: 30, trip_id: 3, departure_date: '2026-09-27', return_date: '2026-09-29', status: 'cancelled', transport_type: 'van', pickup_points: [], vehicle_options: [] };

const FM = {
  reason: 'น้ำป่าไหลหลาก อุทยานประกาศปิด', original_departure_date: '2026-09-27',
  original_departure_label: '27 กันยายน 2569', until: '2027-03-27', until_label: '27 มีนาคม 2570',
  awaiting: true, can_choose: true, expired: false, days_left: 177, resolved_at: null,
};

const booking = (extra = {}) => ({
  id: 3, booking_ref: REF, status: 'confirmed', viewer_is_owner: true,
  total_amount: 7000, paid_amount: 7000, payment_type: 'full', qr_code: 'Q3', checked_in: false,
  schedule: { ...FLOODED, trip: TRIP }, pickup_point: null,
  seats: [{ seat_id: 'A1' }, { seat_id: 'A2' }],
  passengers: [{ id: 1, name: 'สมชาย ใจดี' }, { id: 2, name: 'สมหญิง ใจดี' }],
  installment_payments: [], assigned_staff: [], split: { enabled: false },
  payment_gateway: { provider: 'manual', methods: [] },
  can_modify: false, can_reschedule: true, reschedule_mode: 'force_majeure',
  reschedule_latest_departure: '2027-03-27', reschedule_deadline: '2027-03-27T16:59:59Z',
  force_majeure: FM, pickup_status_open: false, brief_url: 'https://llk.test/t/old',
  ...extra,
});

const IN_WINDOW = { id: 31, trip_id: 3, departure_date: '2026-11-08', return_date: '2026-11-10', status: 'open', bookable_seats: 6, pickup_points: [], vehicle_options: [] };
const TOO_SMALL = { ...IN_WINDOW, id: 32, departure_date: '2026-12-06', return_date: '2026-12-08', bookable_seats: 1 };
const TOO_LATE = { ...IN_WINDOW, id: 33, departure_date: '2027-04-03', return_date: '2027-04-05', bookable_seats: 8 };

const routes = (b, me = { has_app: false, app_links: { ios: 'https://apps.apple.com/x', android: 'https://play.google.com/x' } }) => ({
  'POST /auth/line/liff': { data: { token: 'x' } },
  'GET /auth/me': { data: me },
  'GET /sale-campaign/active': { data: null },
  'GET /referral': { data: { enabled: false } },
  'GET /me/claimable-bookings': { data: { count: 0, trips: [] } },
  'GET /waitlist': { data: [] },
  'GET /trips': { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
  'GET /bookings': { data: [b], meta: { upcoming_count: 1, past_count: 0 } },
  [`GET /bookings/${REF}`]: { data: b },
  [`GET /bookings/${REF}/announcements`]: { data: [] },
  [`GET /bookings/${REF}/check-in-qr`]: { data: { code: 'Q3', qr_data_uri: 'data:image/svg+xml;base64,PC8+', checked_in: false } },
  'GET /trips/thi-lo-su/schedules': { data: [IN_WINDOW, TOO_SMALL, TOO_LATE] },
  [`POST /bookings/${REF}/reschedule`]: { data: { ...b, schedule: { ...IN_WINDOW, trip: TRIP }, force_majeure: { ...FM, awaiting: false } } },
});

/* ═══════════ รายการจอง ═══════════ */
console.log('\n▶ การ์ดในรายการจอง');
{
  const world = makeWorld(routes(booking()), { quiet: true });
  const { w } = world;
  await wait();
  w.eval('showMyBookings("upcoming")');
  await wait(100);
  step('ป้าย "รอเลือกรอบใหม่" แทน "ยืนยันแล้ว"', () => assert(world.text().includes('รอเลือกรอบใหม่'), world.text().slice(0, 200)));
  step('บอกเหตุผลและเส้นตาย', () => {
    assert(world.text().includes('น้ำป่าไหลหลาก'), 'ไม่มีเหตุผล');
    assert(world.text().includes('27 มีนาคม 2570'), 'ไม่มีเส้นตาย');
  });
  step('ปุ่มหลักคือ "เลือกรอบใหม่"', () => {
    assert([...w.document.querySelectorAll('.card .btn')].some((b) => b.textContent.includes('เลือกรอบใหม่')), 'ไม่มีปุ่ม');
  });
}

/* ═══════════ หน้ารายละเอียด ═══════════ */
console.log('\n▶ หน้ารายละเอียดการจอง');
{
  const world = makeWorld(routes(booking()), { quiet: true });
  const { w, calls } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  step('การ์ดเหตุสุดวิสัยอยู่บนสุด', () => assert(w.document.querySelector('.fm-card'), 'ไม่มีการ์ด'));
  step('ไม่มี QR เช็คอินและใบเดินทางของรอบที่ยกเลิก', () => {
    assert(!calls.includes(`GET /bookings/${REF}/check-in-qr`), 'ยังโหลด QR ของรอบที่ยกเลิก');
    assert(!world.text().includes('ใบเดินทาง'), 'ยังโชว์ใบเดินทาง');
  });
  step('ชวนโหลดแอปคนที่ยังไม่มี', () => assert(w.document.querySelector('.app-invite'), 'ไม่มีคำชวน'));
}

console.log('\n▶ คนที่มีแอปแล้วไม่เห็นคำชวน');
{
  const world = makeWorld(routes(booking(), { has_app: true, app_links: { ios: 'x', android: 'y' } }), { quiet: true });
  const { w } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  step('ไม่มีคำชวนโหลดแอป', () => assert(!w.document.querySelector('.app-invite'), 'ยังชวนคนที่มีแอป'));
}

/* ═══════════ เลือกรอบใหม่ ═══════════ */
console.log('\n▶ เลือกรอบใหม่');
{
  const world = makeWorld(routes(booking()), { quiet: true });
  const { w, click } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  click([...w.document.querySelectorAll('.fm-card .btn')].find((b) => b.textContent.includes('เลือกรอบใหม่')));
  await wait(100);
  step('เห็นเฉพาะรอบในกรอบเวลาที่ที่นั่งพอ', () => {
    const picks = [...w.document.querySelectorAll('.sheet .pick')];
    assert(picks.length === 1, 'ควรเหลือ 1 รอบ แต่ได้ ' + picks.length + ': ' + world.sheetText().slice(0, 160));
  });
  step('บอกว่าราคาเดิมและไม่กินสิทธิ์ปกติ', () => assert(world.sheetText().includes('ราคาเดิม') && world.sheetText().includes('ไม่นับรวม'), world.sheetText().slice(0, 160)));
  click([...w.document.querySelectorAll('.sheet .pick')][0]);
  await wait(40);
  click([...w.document.querySelectorAll('.sheet-overlay')].pop().querySelector('[data-role="yes"]'));
  await wait(100);
  step('ยิงเลือกรอบโดยไม่ต้องส่งที่นั่ง (เซิร์ฟเวอร์จัดให้)', () => {
    const sent = world.bodies.find((b) => b.key === `POST /bookings/${REF}/reschedule`);
    assert(sent, 'ไม่ได้ยิง reschedule');
    const body = JSON.parse(sent.body);
    assert(body.target_schedule_id === 31, 'รอบผิด');
    assert(!('seat_ids' in body), 'ไม่ควรส่ง seat_ids');
  });
  step('แจ้งผลสำเร็จ', () => assert(world.alerts.some((a) => a.includes('ได้รอบใหม่แล้ว')), world.alerts.join(' | ')));
}

console.log('\n▶ เพื่อนร่วมทริป (ไม่ใช่ผู้จอง)');
{
  const world = makeWorld(routes(booking({ viewer_is_owner: false })), { quiet: true });
  const { w } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  step('ไม่มีปุ่มเลือก บอกว่าผู้จองเป็นคนเลือก', () => {
    assert(![...w.document.querySelectorAll('.fm-card .btn')].some((b) => b.textContent.includes('เลือกรอบใหม่')), 'ยังมีปุ่ม');
    assert(world.text().includes('ผู้จองเป็นคนเลือกรอบใหม่'), 'ไม่มีคำอธิบาย');
  });
}

console.log('\n▶ เลยกำหนดแล้ว');
{
  const expired = booking({ can_reschedule: false, force_majeure: { ...FM, can_choose: false, expired: true, days_left: -3 } });
  const world = makeWorld(routes(expired), { quiet: true });
  const { w } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  step('บอกให้ทักทีมงาน ไม่มีปุ่มเลือก', () => {
    assert(world.text().includes('เลยกำหนดเลือกรอบใหม่แล้ว'), world.text().slice(0, 200));
    assert(![...w.document.querySelectorAll('.fm-card .btn')].length, 'ยังมีปุ่ม');
  });
}

finish();
