/* ช่องว่างที่ LIFF เคยขาดเทียบกับเว็บ/แอป: แคมเปญวันพิเศษ, เงื่อนไขจากเซิร์ฟเวอร์,
 * เคลมใบจองที่ทีมงานจองให้, ประกาศจากผู้จัด, Flexi-Price, ลิงก์ให้เพื่อนกรอกเอง
 * และหน้าชวนเพื่อน */
import { makeWorld, wait, step, assert, finish } from './support.mjs';

const TRIP = {
  id: 4, slug: 'doi', title: 'ดอยหลวง 2 วัน', location: 'เชียงราย', duration_days: 2,
  price_per_person: 3000, min_price: 2400, max_price: 2400, min_original_price: 3000,
  type: 'trekking', destination_type: 'domestic', rating: 4.9, review_count: 8,
  must_know: { items: [] }, rental_items: [], document_requirements: [],
  itinerary: [], preparations: [], faqs: [],
  campaign: { id: 2, name: '12.12', badge_label: '12.12', discount_label: 'ลด 20%', theme_color: '#e11d48', ends_at: new Date(Date.now() + 3600000).toISOString() },
};

const SCHEDULE = {
  id: 31, trip_id: 4, departure_date: '2026-12-20', status: 'open',
  price: 2400, original_price: 3000, transport_type: 'van',
  available_seats: 5, bookable_seats: 5, total_seats: 10,
  campaign: { id: 2, name: '12.12', badge_label: '12.12', discount_label: 'ลด 20%', theme_color: '#e11d48' },
  pickup_points: [], vehicle_options: [], join_trip_enabled: false,
};

const CAMPAIGN = {
  id: 2, name: '12.12', badge_label: '12.12', tagline: 'ลดทุกทริปวันเดียว',
  discount_label: 'ลด 20%', theme_color: '#e11d48',
  ends_at: new Date(Date.now() + 3600000).toISOString(),
};

const BOOKING = {
  id: 9, booking_ref: 'LLK-20261220-0009', status: 'confirmed', viewer_is_owner: true,
  total_amount: 4800, paid_amount: 4800, payment_type: 'full',
  can_modify: false, can_reschedule: false, installment_payments: [],
  schedule: { ...SCHEDULE, trip: TRIP },
  passengers: [
    { id: 71, name: 'สมชาย ใจดี', phone: '0812345678' },
    { id: 72, name: 'สมหญิง ใจงาม', phone: '0898765432' },
  ],
  split: { enabled: false }, payment_gateway: { provider: 'manual', methods: [] },
};

const baseRoutes = {
  'POST /auth/line/liff': { data: { token: 'x' } },
  'GET /trips': { data: [TRIP], meta: { current_page: 1, last_page: 1, total: 1 } },
  'GET /trips/doi': { data: TRIP },
  'GET /trips/doi/schedules': { data: [SCHEDULE] },
  'GET /trips/doi/related': { data: [] },
  'GET /schedules/31': { data: SCHEDULE },
  'GET /categories': { data: [] },
  'GET /trips/destinations': { data: { domestic: { regions: [] }, international: { countries: [] } } },
  'GET /countries': { data: [] },
  'GET /waitlist': { data: [] },
  'GET /reviews': { data: [], meta: {} },
  'GET /sale-campaign/active': { data: CAMPAIGN },
};

/* ═══════════ แคมเปญวันพิเศษ ═══════════ */
console.log('\n▶ แคมเปญวันพิเศษ');
{
  const { w, text, click, $ } = makeWorld(baseRoutes, { quiet: true });
  await wait(80);

  step('แถบแคมเปญขึ้นบนหน้ารวมทริป พร้อมนับถอยหลัง', () => {
    const bar = $('.campaign-bar');
    assert(bar, 'ไม่มีแถบแคมเปญ');
    assert(bar.textContent.includes('ลดทุกทริปวันเดียว'), 'ไม่ได้ใช้ tagline ของแคมเปญ: ' + bar.textContent);
    assert($('.campaign-clock')?.textContent.match(/\d\d:\d\d:\d\d/), 'ไม่มีเวลาที่เหลือ');
  });

  step('การ์ดทริปติดป้ายแคมเปญ + ขีดฆ่าราคาก่อนลด', () => {
    assert($('.campaign-tag'), 'ไม่มีป้ายแคมเปญบนการ์ดทริป');
    assert($('.card .strike')?.textContent.includes('3,000'), 'ไม่ได้ขีดฆ่าราคาก่อนลด');
    assert($('.card .price.sale')?.textContent.includes('2,400'), 'ราคาที่ขายจริงไม่ได้เน้น');
  });

  click('.card');
  await wait(80);
  step('รอบเดินทางบอกว่าลดจากแคมเปญไหน', () => {
    const price = $('.schedule-price');
    assert(price.textContent.includes('3,000'), 'ไม่มีราคาก่อนลดในแถวรอบ');
    assert(price.textContent.includes('ลด 20%'), 'ไม่มีป้ายส่วนลดของแคมเปญ: ' + price.textContent);
  });
}

/* ═══════════ แคมเปญที่หมดเวลาแล้ว ═══════════ */
console.log('\n▶ แคมเปญที่หมดเวลาระหว่างทาง');
{
  const expired = { ...CAMPAIGN, ends_at: new Date(Date.now() - 1000).toISOString() };
  const staleTrip = { ...TRIP, campaign: { ...TRIP.campaign, ends_at: expired.ends_at } };
  const { $ } = makeWorld({
    ...baseRoutes,
    'GET /sale-campaign/active': { data: expired },
    'GET /trips': { data: [staleTrip], meta: { current_page: 1, last_page: 1, total: 1 } },
  }, { quiet: true });
  await wait(80);

  step('แคมเปญที่หมดเวลาแล้วไม่ติดป้ายอะไรเลย', () => {
    assert(!$('.campaign-bar'), 'ยังมีแถบแคมเปญที่หมดเวลาแล้ว');
    assert(!$('.campaign-tag'), 'ยังมีป้ายแคมเปญที่หมดเวลาแล้ว');
  });
}

/* ═══════════ ทริปที่ถูกยกเว้นจากแคมเปญ ═══════════ */
console.log('\n▶ ทริปที่ถูกยกเว้นจากแคมเปญ');
{
  const plain = { ...TRIP, min_price: 3000, min_original_price: 3000, campaign: null };
  const { $ } = makeWorld({ ...baseRoutes, 'GET /trips': { data: [plain], meta: { current_page: 1, last_page: 1, total: 1 } } }, { quiet: true });
  await wait(80);

  step('ราคาไม่ได้ลด จึงไม่ติดป้ายและไม่ขีดฆ่า', () => {
    assert($('.campaign-bar'), 'แถบแคมเปญยังต้องขึ้น (แคมเปญยังเปิดอยู่)');
    assert(!$('.campaign-tag'), 'ทริปที่ถูกยกเว้นไม่ควรติดป้ายลดราคา');
    assert(!$('.card .strike'), 'ไม่ควรมีราคาขีดฆ่า');
  });
}

/* ═══════════ เคลมใบจองที่ทีมงานจองให้ ═══════════ */
console.log('\n▶ เคลมใบจองที่ทีมงานจองให้');
{
  const { w, calls, bodies, text, click, $ } = makeWorld({
    ...baseRoutes,
    'GET /bookings': { data: [], meta: {} },
    'GET /me/claimable-bookings': { data: { count: 1, trips: [{ trip_title: 'ดอยหลวง 2 วัน', departure_date: '2026-12-20' }] } },
    'POST /bookings/claim': { data: BOOKING },
    'GET /bookings/LLK-20261220-0009': { data: BOOKING },
  }, { quiet: true });
  await wait(80);

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('การจองของฉัน')));
  await wait(80);

  step('หน้าว่างยังบอกทางให้คนที่ทีมงานจองให้', () => {
    assert(text().includes('ทีมงานจองให้แล้วแต่ไม่เห็นใบจอง'), 'ไม่มีทางเข้าเคลมใบจอง: ' + text());
  });
  step('ใบที่ระบบเดาได้ถูกยกขึ้นมาเป็นการ์ด', () => {
    assert(calls.includes('GET /me/claimable-bookings'), 'ไม่ได้ถามใบจองที่รอเคลม');
    assert(text().includes('มี 1 การจองที่น่าจะเป็นของคุณ'), 'ไม่ได้บอกว่ามีใบรอเคลม: ' + text());
    assert(text().includes('ดอยหลวง 2 วัน'), 'ไม่ได้บอกว่าเป็นทริปอะไร');
  });

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ผูกเข้าบัญชีของฉัน')));
  await wait(40);

  step('กรอกไม่ครบแล้วไม่ยิง API', () => {
    $('#claimRef').value = 'LLK-20261220-0009';
    $('#claimPhone').value = '56';
    click([...w.document.querySelectorAll('.sheet-foot .btn')].find((b) => b.textContent.includes('ผูกการจอง')));
    assert(!calls.includes('POST /bookings/claim'), 'ไม่ควรยิงเมื่อเบอร์สั้นกว่า 4 ตัว');
    assert($('#claimBanner').textContent.includes('4 ตัวท้าย'), 'ไม่ได้บอกว่าต้องกรอกอะไร');
  });

  $('#claimPhone').value = '5678';
  click([...w.document.querySelectorAll('.sheet-foot .btn')].find((b) => b.textContent.includes('ผูกการจอง')));
  await wait(120);

  step('ผูกสำเร็จแล้วพาไปที่ใบจองนั้นเลย', () => {
    const body = JSON.parse(bodies.find((b) => b.key === 'POST /bookings/claim').body);
    assert(body.booking_ref === 'LLK-20261220-0009', 'เลขที่จองผิด: ' + body.booking_ref);
    assert(body.phone === '5678', 'เบอร์ผิด: ' + body.phone);
    assert(!w.document.querySelector('.sheet-overlay'), 'แผ่นยังค้างอยู่');
    assert(text().includes('LLK-20261220-0009'), 'ไม่ได้เปิดหน้าใบจอง: ' + text().slice(0, 100));
  });
}

/* ═══════════ ประกาศจากผู้จัด + Flexi + ลิงก์ให้เพื่อนกรอก ═══════════ */
console.log('\n▶ หน้ารายละเอียดการจอง');
{
  const { w, calls, bodies, alerts, text, click } = makeWorld({
    ...baseRoutes,
    'GET /bookings': { data: [BOOKING], meta: {} },
    'GET /me/claimable-bookings': { data: { count: 0, trips: [] } },
    'GET /bookings/LLK-20261220-0009': { data: BOOKING },
    'GET /bookings/LLK-20261220-0009/check-in-qr': { data: { qr_data_uri: 'data:image/svg+xml;base64,PC8+', code: 'LLKQR', checked_in: false } },
    'GET /schedules/31/announcements': {
      data: {
        unread_count: 1,
        can_moderate: false,
        announcements: [
          { id: 5, category: 'schedule_change', title: 'เลื่อนเวลาออกรถ', body: 'ออก 05:30 แทน 05:00\nรบกวนมาถึงก่อน 15 นาที', is_pinned: true, author_name: 'พี่ตูน', created_at: '2026-12-01T10:00:00Z' },
        ],
      },
    },
    'POST /schedules/31/announcements/read': { data: { unread_count: 0 } },
    'GET /bookings/LLK-20261220-0009/flexi-offer': {
      data: {
        id: 3, status: 'open', is_open: true, surcharge_per_person: 350,
        my_surcharge_total: 700, reason: 'รอบนี้ได้ 6 จาก 10 ที่', respond_by: '2026-12-10T12:00:00Z',
        my_consent: 'pending', progress: { total: 4, accepted: 2, declined: 0, pending: 2 },
      },
    },
    'POST /bookings/LLK-20261220-0009/flexi-offer/respond': { data: { status: 'confirmed' } },
    'GET /bookings/LLK-20261220-0009/documents': { data: [] },
    'POST /bookings/LLK-20261220-0009/passengers/71/invite': {
      data: { passenger_id: 71, name: 'สมชาย ใจดี', url: 'https://llk.test/fill/abc', expires_in_days: 14 },
    },
    'POST /bookings/LLK-20261220-0009/invites': {
      data: { id: 1, invite_token: 'zzz', invite_url: 'https://llk.test/join/zzz', invite_label: null },
    },
  }, { quiet: true, shareTargetPicker: true });
  await wait(80);

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('การจองของฉัน')));
  await wait(80);
  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ดูรายละเอียด')));
  await wait(120);

  step('ประกาศจากผู้จัดขึ้นในแท็บทริป', () => {
    assert(text().includes('ประกาศจากผู้จัด'), 'ไม่มีหัวข้อประกาศ: ' + text().slice(0, 160));
    assert(text().includes('เลื่อนเวลาออกรถ'), 'ไม่มีเนื้อประกาศ');
    assert(text().includes('เปลี่ยนเวลา'), 'ไม่ได้แปลชื่อหมวดเป็นไทย');
    assert(text().includes('ใหม่ 1'), 'ไม่ได้บอกว่ามีประกาศที่ยังไม่อ่าน');
  });
  step('เปิดอ่านแล้วทำเครื่องหมายว่าอ่าน', () => {
    assert(calls.includes('POST /schedules/31/announcements/read'), 'ไม่ได้ทำเครื่องหมายว่าอ่าน');
  });

  step('ข้อเสนอไปต่อกันไหม ขึ้นบนสุดพร้อมยอดของเรา', () => {
    assert(text().includes('ไปต่อกันไหม'), 'ไม่มีการ์ด Flexi: ' + text().slice(0, 200));
    assert(text().includes('350'), 'ไม่มีส่วนต่างต่อคน');
    assert(text().includes('700'), 'ไม่มีส่วนต่างรวมของใบนี้');
    assert(text().includes('2 / 4 คน'), 'ไม่มีความคืบหน้าการตอบรับ');
  });

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent === 'ยินดีไปต่อ'));
  await wait(120);
  step('ตอบรับแล้วยิง accept: true', () => {
    const body = JSON.parse(bodies.find((b) => b.key === 'POST /bookings/LLK-20261220-0009/flexi-offer/respond').body);
    assert(body.accept === true, 'ไม่ได้ส่ง accept: true');
  });

  // แท็บผู้เดินทาง — ลิงก์ให้เพื่อนกรอกเอง
  click([...w.document.querySelectorAll('.tab')].find((t) => t.textContent === 'ผู้เดินทาง'));
  await wait(80);
  step('มีลิงก์ให้เพื่อนกรอกข้อมูลเองรายคน', () => {
    assert(text().includes('ให้เพื่อนกรอกข้อมูลเอง'), 'ไม่มีหัวข้อ: ' + text().slice(0, 200));
    assert(text().includes('ชวนเพื่อนเข้าใบจองนี้'), 'ไม่มีปุ่มเชิญเข้าใบจอง');
  });

  click([...w.document.querySelectorAll('.pick')].find((p) => p.textContent.includes('คนที่ 1')));
  await wait(40);
  step('เตือนก่อนว่าลิงก์เดิมจะใช้ไม่ได้', () => {
    const sheet = w.document.querySelector('.sheet-overlay');
    assert(sheet && sheet.textContent.includes('ลิงก์เดิมจะใช้ไม่ได้อีก'), 'ไม่ได้เตือนเรื่องลิงก์เดิม');
  });
  click([...w.document.querySelectorAll('.sheet-overlay .btn')].find((b) => b.textContent.includes('สร้างและส่งลิงก์')));
  await wait(120);
  step('ออกลิงก์ของผู้โดยสารคนนั้น แล้วส่งเข้า LINE', () => {
    assert(calls.includes('POST /bookings/LLK-20261220-0009/passengers/71/invite'), 'ไม่ได้ออกลิงก์ของคนที่ 1');
    assert(alerts.some((a) => a.includes('ส่งลิงก์ให้เพื่อนแล้ว')), 'ไม่ได้ส่งผ่าน shareTargetPicker: ' + alerts.join(' | '));
  });

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ชวนเพื่อนเข้าใบจองนี้')));
  await wait(120);
  step('คำเชิญเข้าใบจองใช้ invite_url จากเซิร์ฟเวอร์', () => {
    assert(calls.includes('POST /bookings/LLK-20261220-0009/invites'), 'ไม่ได้สร้างคำเชิญ');
    assert(alerts.some((a) => a.includes('ส่งคำเชิญแล้ว')), 'ไม่ได้ส่งคำเชิญ: ' + alerts.join(' | '));
  });
}

/* ═══════════ ชวนเพื่อน ═══════════ */
console.log('\n▶ ชวนเพื่อนรับแต้ม');
{
  const { w, alerts, text, click } = makeWorld({
    ...baseRoutes,
    'GET /referral': {
      data: {
        enabled: true, code: 'LUIABCD', share_url: 'https://llk.test/r/LUIABCD',
        share_message: 'ใช้โค้ด LUIABCD ตอนสมัคร รับแต้มไปเที่ยวกันครับ',
        referrer_points: 100, referee_points: 50,
        summary: { invited: 3, rewarded: 1, pending: 2, points_earned: 100 },
        friends: [{ name: 'สมช***', status: 'rewarded', points: 100, joined_at: '2026-10-01T00:00:00Z' }],
      },
    },
  }, { quiet: true, shareTargetPicker: true });
  await wait(80);

  step('ปุ่มทางเข้าบอกแต้มที่จะได้', () => {
    const entry = [...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ชวนเพื่อน'));
    assert(entry && entry.textContent.includes('100 แต้ม'), 'ปุ่มไม่ได้บอกแต้ม: ' + entry?.textContent);
  });

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ชวนเพื่อน')));
  await wait(80);

  step('หน้าชวนเพื่อนแสดงโค้ด แต้ม และเพื่อนที่ชวนมา', () => {
    assert(text().includes('LUIABCD'), 'ไม่มีโค้ด: ' + text().slice(0, 150));
    assert(text().includes('3 คน'), 'ไม่มียอดคนที่ชวน');
    assert(text().includes('100 แต้ม'), 'ไม่มีแต้มที่ได้');
    assert(text().includes('สมช***'), 'ไม่มีรายชื่อเพื่อน');
  });

  click([...w.document.querySelectorAll('.btn')].find((b) => b.textContent.includes('ส่งโค้ดให้เพื่อนใน LINE')));
  await wait(80);
  step('ส่งข้อความชวนจากเซิร์ฟเวอร์เข้า LINE', () => {
    assert(alerts.some((a) => a.includes('ส่งโค้ดให้เพื่อนแล้ว')), 'ไม่ได้ส่งเข้า LINE: ' + alerts.join(' | '));
  });
}

/* ═══════════ ชวนเพื่อนตอนระบบปิดอยู่ ═══════════ */
console.log('\n▶ ชวนเพื่อนตอนระบบปิดอยู่');
{
  const { w, text, click } = makeWorld({
    ...baseRoutes,
    'GET /referral': { data: { enabled: false, code: null } },
  }, { quiet: true });
  await wait(80);
  step('ปิดอยู่แล้วไม่มีปุ่มให้กดตั้งแต่แรก', () => {
    assert(!text().includes('ชวนเพื่อน'), 'ยังมีปุ่มชวนเพื่อนทั้งที่ระบบปิดอยู่: ' + text().slice(0, 120));
  });
  // เข้าตรงจาก Rich Menu (?page=referral) ยังต้องบอกตรง ๆ ว่าปิดอยู่
  w.eval('showReferral()');
  await wait(80);
  step('เข้าตรงแล้วบอกตรง ๆ ไม่โชว์โค้ดว่าง', () => {
    assert(text().includes('ยังไม่เปิดให้ชวนเพื่อน'), 'ไม่ได้บอกว่าปิดอยู่: ' + text().slice(0, 120));
  });
}

finish();
