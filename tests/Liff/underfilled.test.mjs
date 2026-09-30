/* รอบไม่ได้ออกเพราะผู้ร่วมทริปไม่ครบ — ลูกค้าจาก LINE เลือกรอบใหม่เอง หรือขอรับเงินคืน
 * เต็มจำนวนพร้อมกรอกบัญชี และข้อความต้องไม่พูดว่าเหตุสุดวิสัย/น้ำป่า */
import { makeWorld, wait, step, assert, finish } from './support.mjs';

const REF = 'LLK-20261010-0007';
const TRIP = { id: 5, slug: 'phu-kradueng', title: 'ภูกระดึง', location: 'เลย', destination_type: 'domestic' };
const THIN = { id: 50, trip_id: 5, departure_date: '2026-10-10', return_date: '2026-10-12', status: 'cancelled', transport_type: 'van', pickup_points: [], vehicle_options: [] };
const BANKS = ['พร้อมเพย์', 'กสิกรไทย', 'ไทยพาณิชย์', 'อื่น ๆ'];

const FM = {
  kind: 'underfilled', state: 'awaiting',
  reason: 'ผู้ร่วมเดินทางไม่ครบตามจำนวนขั้นต่ำ', original_departure_date: '2026-10-10',
  original_departure_label: '10 ตุลาคม 2569', until: '2027-04-10', until_label: '10 เมษายน 2570',
  decide_by: '2026-10-15', decide_by_label: '15 ตุลาคม 2569',
  awaiting: true, can_choose: true, expired: false, days_left: 14, resolved_at: null,
  can_request_refund: true, refund_amount: 7000, refund_account_label: null, refund_banks: BANKS, holds: [],
};

const booking = (extra = {}) => ({
  id: 7, booking_ref: REF, status: 'confirmed', viewer_is_owner: true,
  total_amount: 7000, paid_amount: 7000, payment_type: 'full', qr_code: 'Q7', checked_in: false,
  schedule: { ...THIN, trip: TRIP }, pickup_point: null, seats: [],
  passengers: [{ id: 1, name: 'สมชาย ใจดี' }, { id: 2, name: 'สมหญิง ใจดี' }],
  installment_payments: [], assigned_staff: [], split: { enabled: false },
  payment_gateway: { provider: 'manual', methods: [] },
  can_modify: false, can_reschedule: true, reschedule_mode: 'force_majeure',
  reschedule_latest_departure: '2027-04-10', reschedule_deadline: '2026-10-15T16:59:59Z',
  force_majeure: FM, pickup_status_open: false,
  ...extra,
});

const NEXT = { id: 51, trip_id: 5, departure_date: '2026-11-07', return_date: '2026-11-09', status: 'open', bookable_seats: 6, pickup_points: [], vehicle_options: [] };

const REQUESTED = {
  ...FM, state: 'refund_requested', awaiting: false, can_choose: false, can_request_refund: false,
  refund_account_label: 'กสิกรไทย ••••7890', refund_banks: [],
};

const routes = (b, extra = {}) => ({
  'POST /auth/line/liff': { data: { token: 'x' } },
  'GET /auth/me': { data: { has_app: true, app_links: {} } },
  'GET /sale-campaign/active': { data: null },
  'GET /referral': { data: { enabled: false } },
  'GET /me/claimable-bookings': { data: { count: 0, trips: [] } },
  'GET /waitlist': { data: [] },
  'GET /trips': { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
  'GET /bookings': { data: [b], meta: { upcoming_count: 1, past_count: 0 } },
  [`GET /bookings/${REF}`]: { data: b },
  [`GET /bookings/${REF}/announcements`]: { data: [] },
  'GET /trips/phu-kradueng/schedules': { data: [NEXT] },
  [`POST /bookings/${REF}/postponement/refund`]: { data: { ...b, status: 'cancelled', refund_status: 'requested', force_majeure: REQUESTED } },
  ...extra,
});

const refundButton = (w, scope = '') => [...w.document.querySelectorAll(`${scope} .btn`)]
  .find((b) => b.textContent.includes('ขอรับเงินคืน'));

/* ═══════════ รายการจอง ═══════════ */
console.log('\n▶ การ์ดในรายการจอง');
{
  const world = makeWorld(routes(booking()), { quiet: true });
  const { w } = world;
  await wait();
  w.eval('showMyBookings("upcoming")');
  await wait(100);
  step('บอกว่าไม่ได้ออกเพราะคนไม่ครบ ไม่ใช่เหตุสุดวิสัย', () => {
    assert(world.text().includes('รอบนี้ไม่ได้ออกเดินทาง'), world.text().slice(0, 200));
    assert(world.text().includes('ผู้ร่วมเดินทางไม่ครบ'), 'ไม่มีเหตุผล');
    assert(!world.text().includes('⛈️') && !world.text().includes('เหตุสุดวิสัย'), 'ยังพูดถึงเหตุสุดวิสัย');
  });
  step('บอกกำหนดตัดสินใจ (ไม่ใช่กรอบ 6 เดือน)', () => assert(world.text().includes('15 ตุลาคม 2569'), world.text().slice(0, 300)));
  step('มีทั้งปุ่มเลือกรอบใหม่และขอรับเงินคืน', () => {
    const labels = [...w.document.querySelectorAll('.card .btn')].map((b) => b.textContent);
    assert(labels.some((t) => t.includes('เลือกรอบใหม่')), labels.join('|'));
    assert(labels.some((t) => t.includes('ขอรับเงินคืน')), labels.join('|'));
  });
}

/* ═══════════ หน้ารายละเอียด + ขอรับเงินคืน ═══════════ */
console.log('\n▶ ขอรับเงินคืนจากหน้ารายละเอียด');
{
  const world = makeWorld(routes(booking()), { quiet: true });
  const { w, click } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  step('การ์ดบอกยอดคืนเต็มจำนวน', () => {
    assert(w.document.querySelector('.fm-card'), 'ไม่มีการ์ด');
    assert(world.text().includes('7,000'), world.text().slice(0, 300));
  });

  click(refundButton(w, '.fm-card'));
  await wait(40);
  step('แผ่นขอคืนเงินมีรายการธนาคารจากเซิร์ฟเวอร์', () => {
    const options = [...w.document.querySelectorAll('.sheet #refundBank option')].map((o) => o.value).filter(Boolean);
    assert(options.join() === BANKS.join(), options.join());
    assert(world.sheetText().includes('รวมมัดจำ'), world.sheetText().slice(0, 200));
  });

  click([...w.document.querySelectorAll('.sheet-foot .btn')].pop());
  await wait(40);
  step('กรอกไม่ครบ ไม่ยิง API และบอกให้กรอก', () => {
    assert(!world.bodies.some((b) => b.key === `POST /bookings/${REF}/postponement/refund`), 'ยิงทั้งที่ยังไม่กรอก');
    assert(world.sheetText().includes('กรอกธนาคาร เลขบัญชี และชื่อบัญชี'), world.sheetText().slice(0, 200));
  });

  const bank = w.document.querySelector('#refundBank');
  bank.value = 'พร้อมเพย์';
  bank.dispatchEvent(new w.Event('change'));
  step('เลือกพร้อมเพย์แล้วช่องเลขเปลี่ยนคำอธิบาย', () => assert(w.document.querySelector('#refundNumberLabel').textContent.includes('เบอร์มือถือ'), 'ป้ายไม่เปลี่ยน'));
  w.document.querySelector('#refundNumber').value = '081-234-5678';
  w.document.querySelector('#refundName').value = 'สมชาย ใจดี';
  click([...w.document.querySelectorAll('.sheet-foot .btn')].pop());
  await wait(40);
  click([...w.document.querySelectorAll('.sheet-overlay')].pop().querySelector('[data-role="yes"]'));
  await wait(100);
  step('ยิงขอคืนเงินพร้อมบัญชี', () => {
    const sent = world.bodies.find((b) => b.key === `POST /bookings/${REF}/postponement/refund`);
    assert(sent, 'ไม่ได้ยิง');
    const body = JSON.parse(sent.body);
    assert(body.bank === 'พร้อมเพย์' && body.account_number === '081-234-5678' && body.account_name === 'สมชาย ใจดี', sent.body);
  });
  step('แจ้งผลพร้อมยอด', () => assert(world.alerts.some((a) => a.includes('รับเรื่องแล้ว') && a.includes('7,000')), world.alerts.join(' | ')));
}

console.log('\n▶ เซิร์ฟเวอร์ปฏิเสธเลขบัญชี — ฟอร์มยังอยู่ให้แก้');
{
  const world = makeWorld(routes(booking(), {
    [`POST /bookings/${REF}/postponement/refund`]: { __status: 422, message: 'เลขบัญชีไม่ถูกต้อง' },
  }), { quiet: true });
  const { w, click } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  click(refundButton(w, '.fm-card'));
  await wait(40);
  w.document.querySelector('#refundBank').value = 'กสิกรไทย';
  w.document.querySelector('#refundNumber').value = '12';
  w.document.querySelector('#refundName').value = 'สมชาย ใจดี';
  click([...w.document.querySelectorAll('.sheet-foot .btn')].pop());
  await wait(40);
  click([...w.document.querySelectorAll('.sheet-overlay')].pop().querySelector('[data-role="yes"]'));
  await wait(100);
  step('บอกเหตุผลและฟอร์มเดิมยังอยู่', () => {
    assert(world.sheetText().includes('เลขบัญชีไม่ถูกต้อง'), world.sheetText().slice(0, 200));
    assert(w.document.querySelector('#refundNumber')?.value === '12', 'ฟอร์มหาย');
    assert(!w.document.querySelector('.sheet-foot .btn').disabled, 'ปุ่มค้าง');
  });
}

console.log('\n▶ เลือกรอบใหม่แทน — แผ่นเลือกรอบมีทางไปขอคืนเงิน');
{
  const world = makeWorld(routes(booking()), { quiet: true });
  const { w, click } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  click([...w.document.querySelectorAll('.fm-card .btn')].find((b) => b.textContent.includes('เลือกรอบใหม่')));
  await wait(100);
  step('บอกกำหนดตัดสินใจในแผ่น', () => assert(world.sheetText().includes('ตัดสินใจได้ถึง 15 ตุลาคม 2569'), world.sheetText().slice(0, 300)));
  step('มีรอบใหม่ให้เลือก', () => assert(w.document.querySelectorAll('.sheet .pick').length === 1, 'ไม่มีรอบ'));
  click(refundButton(w, '.sheet'));
  await wait(40);
  step('สลับไปแผ่นขอคืนเงินได้', () => assert(w.document.querySelector('.sheet #refundBank'), world.sheetText().slice(0, 200)));
}

console.log('\n▶ ยังไม่ได้จ่าย — ยกเลิกได้เลยไม่ต้องกรอกบัญชี');
{
  const unpaid = booking({ status: 'pending', paid_amount: 0, force_majeure: { ...FM, refund_amount: 0 } });
  const world = makeWorld(routes(unpaid), { quiet: true });
  const { w, click } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  click([...w.document.querySelectorAll('.fm-card .btn')].find((b) => b.textContent.includes('ยกเลิกการจอง')));
  await wait(40);
  step('ไม่มีฟอร์มบัญชี', () => assert(!w.document.querySelector('#refundBank'), 'ยังถามบัญชี'));
  click([...w.document.querySelectorAll('.sheet-foot .btn')].pop());
  await wait(40);
  click([...w.document.querySelectorAll('.sheet-overlay')].pop().querySelector('[data-role="yes"]'));
  await wait(100);
  step('ยิงโดยไม่มีบัญชี', () => {
    const sent = world.bodies.find((b) => b.key === `POST /bookings/${REF}/postponement/refund`);
    assert(sent && sent.body === '{}', sent?.body);
  });
}

console.log('\n▶ แท็บจัดการ');
{
  const world = makeWorld(routes(booking()), { quiet: true });
  const { w } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "manage")`);
  await wait(120);
  step('มีขอรับเงินคืนแทนยกเลิกตามนโยบายปกติ', () => {
    assert(world.text().includes('ขอรับเงินคืนเต็มจำนวน'), world.text().slice(0, 300));
    assert(!world.text().includes('เงื่อนไขคืนเงินเป็นไปตามนโยบายของทริป'), 'ยังเสนอยกเลิกแบบปกติ');
  });
}

console.log('\n▶ ขอคืนเงินแล้ว');
{
  const done = booking({ status: 'cancelled', refund_status: 'requested', can_reschedule: false, force_majeure: REQUESTED });
  const world = makeWorld(routes(done), { quiet: true });
  const { w } = world;
  await wait();
  w.eval('showMyBookings("upcoming")');
  await wait(100);
  step('ป้ายรอโอนคืนในรายการ', () => assert(world.text().includes('รอโอนคืน'), world.text().slice(0, 200)));
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  step('บอกบัญชีที่จะโอนเข้า และไม่มีปุ่มให้กดซ้ำ', () => {
    assert(world.text().includes('รับเรื่องคืนเงินแล้ว'), world.text().slice(0, 300));
    assert(world.text().includes('กสิกรไทย ••••7890'), world.text().slice(0, 300));
    assert(!w.document.querySelectorAll('.fm-card .btn').length, 'ยังมีปุ่ม');
    assert(!world.text().includes('ย้ายมารอบนี้'), 'บอกผิดว่าย้ายรอบแล้ว');
  });
}

console.log('\n▶ เลยกำหนดตัดสินใจ — ยังกรอกบัญชีรับเงินคืนได้');
{
  const late = booking({ can_reschedule: false, force_majeure: { ...FM, can_choose: false, expired: true, days_left: -1 } });
  const world = makeWorld(routes(late), { quiet: true });
  const { w } = world;
  await wait();
  w.eval(`showBookingDetail("${REF}", "trip")`);
  await wait(120);
  step('บอกว่าจะคืนเงินให้ และมีปุ่มกรอกบัญชี', () => {
    assert(world.text().includes('เราจะคืนเงินเต็มจำนวนให้'), world.text().slice(0, 300));
    assert([...w.document.querySelectorAll('.fm-card .btn')].some((b) => b.textContent.includes('กรอกบัญชีรับเงินคืน')), 'ไม่มีปุ่ม');
    assert(![...w.document.querySelectorAll('.fm-card .btn')].some((b) => b.textContent.includes('เลือกรอบใหม่')), 'ยังให้เลือกรอบ');
  });
}

finish();
