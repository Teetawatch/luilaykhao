/* ส่งต่อที่นั่ง — คนที่ไปไม่ได้ส่งลิงก์ให้เพื่อนในไลน์ เพื่อนกดแล้วเข้า LIFF รับที่นั่งเลย
 * สิ่งที่ต้องถูก: ปุ่มขึ้นตามที่เซิร์ฟเวอร์บอก, ลิงก์ที่แชร์ชี้เข้า LIFF, ข้อผิดพลาด
 * รายช่องจากเซิร์ฟเวอร์ขึ้นใต้ช่องนั้น */
import { makeWorld, wait, step, assert, finish } from './support.mjs';

const REF = 'LLK-20261003-0001';
const TRIP = { id: 5, slug: 'phu-kradueng', title: 'ภูกระดึง', location: 'เลย', destination_type: 'domestic' };
const SCHEDULE = { id: 50, trip_id: 5, departure_date: '2026-10-03', return_date: '2026-10-05', status: 'open', transport_type: 'van', pickup_points: [], vehicle_options: [] };

const booking = (handover) => ({
  id: 7, booking_ref: REF, status: 'confirmed', viewer_is_owner: true,
  total_amount: 4000, paid_amount: 4000, payment_type: 'full', qr_code: 'Q7', checked_in: false,
  schedule: { ...SCHEDULE, trip: TRIP }, pickup_point: null, seats: [],
  passengers: [{ id: 1, name: 'สมชาย ใจดี' }, { id: 2, name: 'สมหญิง รักดี' }],
  installment_payments: [], assigned_staff: [], split: { enabled: false },
  payment_gateway: { provider: 'manual', methods: [] },
  can_modify: false, can_reschedule: false, reschedule_mode: 'standard',
  force_majeure: null, pickup_status_open: false,
  seat_handover: handover,
});

const OVERVIEW = {
  booking_ref: REF, available: true, blocked_reason: null,
  deadline_label: 'สิ้นวันที่ 2 ตุลาคม 2569', viewer_role: 'owner',
  can_transfer_ownership: true, ownership_blocked_reason: null, open_ownership_passenger_id: null,
  seats: [
    { passenger_id: 1, name: 'สมชาย ใจดี', seat_label: 'A1', is_owner_seat_guess: true, can_hand_over: true, open_handover: null },
    { passenger_id: 2, name: 'สมหญิง รักดี', seat_label: 'A2', is_owner_seat_guess: false, can_hand_over: true, open_handover: null },
  ],
  history: [],
};

const CREATED = { id: 3, passenger_id: 2, status: 'pending', token: 'tok123', url: 'https://luilaykhao.com/handover/tok123', expires_at: '2026-10-02T17:00:00Z' };

const PREVIEW = {
  token: 'tok123', status: 'pending', claimable: true, blocked_reason: null,
  from_name: 'ต้น', note: 'ฝากด้วยนะ', transfers_ownership: false,
  trip: { title: 'ภูกระดึง', is_international: false, is_women_only: false },
  schedule: { id: 50, departure_label: '3 ตุลาคม 2569' },
  seat_label: 'A2', pickup: { label: 'หมอชิต', time: '21:00' },
  terms: { version: '2026-09-29', url: 'https://luilaykhao.com/terms' },
  prefill: { name: 'มานะ ขยันดี', nickname: 'มานะ', phone: '0833333333' },
};

const base = (extra = {}) => ({
  'POST /auth/line/liff': { data: { token: 'x' } },
  'GET /auth/me': { data: { has_app: true, app_links: {} } },
  'GET /sale-campaign/active': { data: null },
  'GET /referral': { data: { enabled: false } },
  'GET /me/claimable-bookings': { data: { count: 0, trips: [] } },
  'GET /waitlist': { data: [] },
  'GET /trips': { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
  [`GET /bookings/${REF}/announcements`]: { data: [] },
  ...extra,
});

/* ═══════════ ปุ่มในแท็บจัดการ ═══════════ */
console.log('\n▶ ปุ่มส่งต่อที่นั่งในแท็บจัดการ');
{
  const world = makeWorld(base({ [`GET /bookings/${REF}`]: { data: booking({ available: true, open_count: 0, deadline_label: 'สิ้นวันที่ 2 ตุลาคม 2569' }) } }), { quiet: true });
  await wait();
  world.w.eval(`showBookingDetail("${REF}", "manage")`);
  await wait(100);
  step('ขึ้นปุ่มพร้อมเส้นตาย', () => {
    assert(world.text().includes('ไปไม่ได้? ส่งต่อที่นั่ง'), world.text().slice(0, 300));
    assert(world.text().includes('สิ้นวันที่ 2 ตุลาคม 2569'), 'ไม่มีเส้นตาย');
  });
}
{
  const world = makeWorld(base({ [`GET /bookings/${REF}`]: { data: booking({ available: false, blocked_reason: 'เลยเวลาแล้ว' }) } }), { quiet: true });
  await wait();
  world.w.eval(`showBookingDetail("${REF}", "manage")`);
  await wait(100);
  step('ส่งต่อไม่ได้ = ไม่มีปุ่ม', () => assert(!world.text().includes('ส่งต่อที่นั่ง'), 'ยังมีปุ่ม'));
}

/* ═══════════ สร้างลิงก์แล้วแชร์เข้าไลน์ ═══════════ */
console.log('\n▶ สร้างลิงก์ส่งต่อที่นั่ง');
{
  const world = makeWorld(base({
    [`GET /bookings/${REF}`]: { data: booking({ available: true, open_count: 0 }) },
    [`GET /bookings/${REF}/handovers`]: { data: OVERVIEW },
    [`POST /bookings/${REF}/handovers`]: { data: CREATED },
  }), { quiet: true, shareTargetPicker: true });
  const { w } = world;
  let shared = null;
  w.liff.shareTargetPicker = async (msgs) => { shared = msgs[0].text; return { status: 'success' }; };
  await wait();
  w.eval(`showSeatHandover({ booking_ref: "${REF}", schedule: { trip: { title: "ภูกระดึง" } } })`);
  await wait(100);

  step('เห็นทุกที่นั่งและเส้นตาย', () => {
    assert(world.text().includes('สมหญิง รักดี'), world.text().slice(0, 300));
    assert(world.text().includes('สิ้นวันที่ 2 ตุลาคม 2569'), 'ไม่มีเส้นตาย');
  });

  const buttons = world.$$('.handover-seat .btn');
  world.click(buttons[1]);
  await wait();
  step('ชีตของที่นั่งเพื่อน — สวิตช์ "ที่นั่งของฉัน" ไม่ติ๊กไว้ก่อน', () => {
    const box = world.$('.sheet-overlay input[name="ownership"]');
    assert(box && !box.checked, 'ติ๊กไว้ทั้งที่ไม่ใช่ที่นั่งเจ้าของ');
  });
  world.$('.sheet-overlay textarea[name="note"]').value = 'ฝากด้วยนะ';
  world.click(world.$('.sheet-overlay .sheet-foot .btn'));
  await wait(120);

  step('ส่ง passenger_id + ข้อความ ไม่โอนสิทธิ์ดูแล', () => {
    const sent = world.bodies.find((b) => b.key === `POST /bookings/${REF}/handovers`);
    assert(sent, 'ไม่ได้ยิง POST');
    const body = JSON.parse(sent.body);
    assert(body.passenger_id === 2 && body.transfers_ownership === false && body.note === 'ฝากด้วยนะ', sent.body);
  });
  step('ลิงก์ที่แชร์ชี้เข้า LIFF', () => {
    assert(shared && shared.includes('https://liff.line.me/1234-abcd?handover=tok123'), shared);
  });
}

/* ═══════════ คนรับเปิดลิงก์ในไลน์ ═══════════ */
console.log('\n▶ คนรับเปิดลิงก์ ?handover=');
{
  const world = makeWorld(base({
    'GET /seat-handovers/tok123': { data: PREVIEW },
    'POST /seat-handovers/tok123/claim': {
      __status: 422, message: 'ข้อมูลไม่ถูกต้อง',
      errors: { id_card: ['เลขบัตรประชาชนไม่ถูกต้อง ลองตรวจสอบอีกครั้งครับ'] },
    },
  }), { quiet: true, search: '?handover=tok123' });
  await wait(120);

  step('ลงหน้ารับที่นั่งเลย พร้อมข้อมูลทริปและโปรไฟล์', () => {
    assert(world.text().includes('ต้น ส่งที่นั่งให้คุณ'), world.text().slice(0, 200));
    assert(world.text().includes('ที่นั่ง A2'), 'ไม่บอกที่นั่ง');
    assert(world.$('input[name="name"]').value === 'มานะ ขยันดี', 'ไม่เติมชื่อจากโปรไฟล์');
  });

  world.$('select[name="title"]').value = 'นาย';
  world.$('input[name="id_card"]').value = '1101700230700';
  world.$('select[name="halal_food"]').value = '0';
  world.$('input[name="accept_terms"]').checked = true;
  world.$('form.handover-form').dispatchEvent(new world.w.Event('submit', { cancelable: true }));
  await wait(150);

  step('ส่ง channel=liff + ฉบับเงื่อนไข', () => {
    const sent = world.bodies.find((b) => b.key === 'POST /seat-handovers/tok123/claim');
    assert(sent, 'ไม่ได้ยิง claim');
    const body = JSON.parse(sent.body);
    assert(body.channel === 'liff' && body.terms_version === '2026-09-29', sent.body);
    assert(body.halal_food === false && body.accept_terms === true && body.nationality === 'TH', sent.body);
  });
  step('ข้อผิดพลาดของเลขบัตรขึ้นใต้ช่อง และค่าที่กรอกไว้ไม่หาย', () => {
    assert(world.text().includes('เลขบัตรประชาชนไม่ถูกต้อง'), world.text().slice(-400));
    assert(world.$('.field.has-error input[name="id_card"]'), 'ช่องไม่ถูกไฮไลต์');
    assert(world.$('input[name="id_card"]').value === '1101700230700', 'ค่าที่กรอกหาย');
  });
}
{
  const world = makeWorld(base({
    'GET /seat-handovers/tok123': { data: PREVIEW },
    'POST /seat-handovers/tok123/claim': { data: { booking_ref: REF, schedule_id: 50, trip_title: 'ภูกระดึง' } },
    [`GET /bookings/${REF}`]: { data: booking({ available: false }) },
  }), { quiet: true, search: '?handover=tok123' });
  await wait(120);
  world.$('select[name="title"]').value = 'นาย';
  world.$('input[name="id_card"]').value = '1101700230708';
  world.$('select[name="halal_food"]').value = '1';
  world.$('input[name="accept_terms"]').checked = true;
  world.$('form.handover-form').dispatchEvent(new world.w.Event('submit', { cancelable: true }));
  await wait(200);
  step('รับสำเร็จแล้วพาไปหน้าการจองที่ได้มา', () => {
    assert(world.calls.includes(`GET /bookings/${REF}`), world.calls.join('|'));
    assert(world.alerts.some((a) => a.includes('รับที่นั่งเรียบร้อย')), world.alerts.join('|'));
  });
}
{
  const world = makeWorld(base({
    'GET /seat-handovers/tok123': { data: { ...PREVIEW, claimable: false, status: 'claimed', blocked_reason: 'ที่นั่งนี้มีคนรับไปแล้ว' } },
  }), { quiet: true, search: '?handover=tok123' });
  await wait(120);
  step('ลิงก์ที่มีคนรับไปแล้ว บอกเหตุผลและไม่มีฟอร์ม', () => {
    assert(world.text().includes('ที่นั่งนี้มีคนรับไปแล้ว'), world.text().slice(0, 300));
    assert(!world.$('form.handover-form'), 'ยังมีฟอร์ม');
  });
}

finish();
