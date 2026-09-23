/* Luilaykhao LIFF — การจองของฉัน + รายละเอียดการจอง
 *
 * โหลดต่อจาก app.js/booking.js/payment.js และใช้ helper ตัวเดียวกัน
 *
 * หน้านี้คือทุกอย่างที่ลูกค้าทำกับใบจองได้หลังจองแล้ว: ดู QR เช็คอิน, จ่ายส่วนที่
 * เหลือ/ค่างวด/ส่วนแบ่งกลุ่ม, เปลี่ยนจุดรับ, เลื่อนรอบ, ยกเลิก, ใบเสร็จ, แนบเอกสาร
 * เพิ่ม และแชร์ลิงก์ติดตามรถ — เดิมต้องออกจาก LINE ไปทำในแอปหรือเว็บทั้งหมด
 */

/* -------------------------- การจองของฉัน -------------------------- */

const BOOKING_SCOPES = ['upcoming', 'past'];
let myBookingScope = 'upcoming';

async function showMyBookings(scope) {
  stopPaymentTimers();
  // รับเฉพาะ scope ที่มีจริง — ฟังก์ชันนี้ถูกใช้เป็น handler หลายที่ และตัว event
  // ที่หลุดเข้ามาเคยกลายเป็น ?scope=[object Event] ที่ไม่มีแท็บไหนถูกเลือกเลย
  if (BOOKING_SCOPES.includes(scope)) myBookingScope = scope;
  loading('กำลังโหลดการจอง…');

  let bookings;
  let meta;
  try {
    const res = await api('/bookings?scope=' + myBookingScope);
    bookings = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);
    meta = res.meta || {};
  } catch (e) {
    return errorScreen(e.message, () => showMyBookings());
  }

  const node = el(`<div></div>`);
  node.appendChild(appbar('การจองของฉัน', backToTrips));
  const content = el(`<div class="content"></div>`);

  const tabs = el(`<div class="chip-row"></div>`);
  [
    ['upcoming', 'กำลังจะถึง', meta.upcoming_count],
    ['past', 'ที่ผ่านมา', meta.past_count],
  ].forEach(([value, label, count]) => {
    const chip = el(`<button type="button" class="chip ${myBookingScope === value ? 'on' : ''}">${label}${count != null ? ` (${count})` : ''}</button>`);
    chip.onclick = () => showMyBookings(value);
    tabs.appendChild(chip);
  });
  content.appendChild(tabs);

  // คิวรอที่นั่ง — ไม่ใช่การจอง แต่เป็นของที่ค้างอยู่ในมือลูกค้าเหมือนกัน
  if (myBookingScope === 'upcoming') content.appendChild(waitlistBlock());

  if (!bookings.length) {
    content.appendChild(el(`<div class="empty">${myBookingScope === 'upcoming' ? 'ยังไม่มีการจองที่กำลังจะถึง' : 'ยังไม่มีการจองที่ผ่านมา'}</div>`));
  }

  bookings.forEach((booking) => content.appendChild(bookingCard(booking)));

  // ใบจองที่ทีมงานจองให้ยังไม่อยู่ในบัญชีนี้ — อยู่ท้ายรายการเมื่อมีใบจองแล้ว
  // แต่คนที่เปิดมาแล้วเห็นหน้าว่าง คือคนที่ต้องการมันที่สุด
  if (myBookingScope === 'upcoming') content.appendChild(claimBlock());

  node.appendChild(content);
  render(node);
}

function bookingStatusTag(booking) {
  if (booking.status === 'confirmed') return '<span class="tag ok">ยืนยันแล้ว</span>';
  if (booking.status === 'cancelled') return '<span class="tag">ยกเลิกแล้ว</span>';
  if (booking.slip_ocr_status === 'failed') return '<span class="tag warn">กำลังตรวจสอบยอด</span>';
  return '<span class="tag warn">รอชำระเงิน</span>';
}

function bookingCard(booking) {
  const schedule = booking.schedule || {};
  const outstanding = Math.max(0, Number(booking.total_amount || 0) - Number(booking.paid_amount || 0));
  const card = el(`<div class="card"><div class="body">
    <p class="title">${esc(schedule.trip?.title || 'ทริป')}</p>
    <div class="meta">
      <span>${thaiDate(schedule.departure_date)}</span>
      <span>${esc(booking.booking_ref)}</span>
      ${bookingStatusTag(booking)}
    </div>
    ${booking.status !== 'cancelled' ? earlyDepartureHtml(schedule) : ''}
    <div class="kv" style="margin-top:8px"><span class="k">ยอดรวม</span><span class="v">${baht(booking.total_amount)}</span></div>
    ${outstanding > 0 ? `<div class="kv"><span class="k">ค้างชำระ</span><span class="v price">${baht(outstanding)}</span></div>` : ''}
  </div></div>`);

  card.querySelector('.body').onclick = () => showBookingDetail(booking.booking_ref);

  // ปุ่มจ่ายมีเฉพาะใบที่ "ยังจ่ายครั้งแรกไม่ได้จบ" — ใบที่ส่งสลิปแล้วรอตรวจไม่ต้องจ่ายซ้ำ
  if (booking.status === 'pending' && !booking.slip_ocr_status) {
    const btn = el(`<button class="btn" style="margin:0 14px 14px">ชำระเงิน · ${baht(outstanding)}</button>`);
    btn.onclick = () => openPaymentFor(booking.booking_ref);
    card.appendChild(btn);
  } else {
    const btn = el(`<button class="btn secondary" style="margin:0 14px 14px">ดูรายละเอียด</button>`);
    btn.onclick = () => showBookingDetail(booking.booking_ref);
    card.appendChild(btn);
  }

  return card;
}

/* ------------------- ใบจองที่ทีมงานจองให้ (เคลม) ------------------- */

/* ลูกค้าส่วนใหญ่ที่นี่ทักเข้ามาในไลน์แล้วให้ทีมงานจองให้ ใบจองนั้นถูกสร้างไว้ใน
 * "บัญชีเงา" ที่ผูกกับเบอร์ ไม่ใช่บัญชี LINE ของเขา — ถ้าไม่มีทางผูกเข้าบัญชี
 * ตัวเอง เขาจะเปิด LIFF มาแล้วเห็นหน้าว่าง แล้วต้องกลับไปถามทีมงานทุกครั้ง
 *
 * บัญชีที่ล็อกอินด้วย LINE ไม่มีเบอร์ติดมาด้วย (LINE ไม่ให้) รายการที่ระบบเดาให้
 * จึงมักว่าง — ประตูหลักคือกรอกเลขที่จอง + เบอร์ ซึ่งเปิดให้ใช้เสมอ */

function claimBlock() {
  const wrap = el(`<div></div>`);

  const manual = el(`<div class="pick">
    <div class="pick-body">
      <div class="pick-name">ทีมงานจองให้แล้วแต่ไม่เห็นใบจอง?</div>
      <div class="pick-sub">กรอกเลขที่จองเพื่อผูกเข้าบัญชีนี้</div>
    </div>
    <span class="tag">›</span>
  </div>`);
  manual.onclick = openClaimBooking;

  api('/me/claimable-bookings')
    .then((res) => {
      const count = Number(res.data?.count || 0);
      if (!count || !wrap.isConnected) return;

      const trips = (res.data?.trips || [])
        .map((t) => [t.trip_title, t.departure_date ? thaiDate(t.departure_date) : ''].filter(Boolean).join(' · '))
        .filter(Boolean);

      const card = el(`<div class="card"><div class="body">
        <p class="title">มี ${count} การจองที่น่าจะเป็นของคุณ</p>
        <p class="muted">${trips.length ? esc(trips.join(' / ')) : 'ทีมงานจองไว้ให้ด้วยเบอร์เดียวกับบัญชีนี้'}</p>
      </div></div>`);
      const btn = el(`<button class="btn" style="margin:0 14px 14px">ผูกเข้าบัญชีของฉัน</button>`);
      btn.onclick = openClaimBooking;
      card.appendChild(btn);
      wrap.prepend(card);
    })
    .catch(() => { /* เดาให้ไม่ได้ก็ไม่เป็นไร ประตูกรอกเองยังอยู่ */ });

  wrap.appendChild(manual);
  return wrap;
}

/** กรอกเลขที่จอง + เบอร์ (4 ตัวท้ายก็พอ) — กติกาเดียวกับหน้าค้นหาการจอง */
function openClaimBooking() {
  const sheet = openSheet('ผูกการจองเข้าบัญชี');
  sheet.body.appendChild(el(`<p class="muted">ใส่เลขที่การจองที่ทีมงานแจ้งไว้ กับเบอร์โทรที่ใช้จอง (กรอก 4 ตัวท้ายก็ได้) แล้วใบจองจะขึ้นในบัญชีนี้ทันที</p>`));

  const form = el(`<div style="margin-top:12px">
    ${field('เลขที่การจอง', '<input id="claimRef" placeholder="LLK-20261010-0001" autocapitalize="characters">')}
    ${field('เบอร์โทรที่ใช้จอง', '<input id="claimPhone" inputmode="tel" placeholder="0812345678 หรือ 5678">')}
    <div id="claimBanner"></div>
  </div>`);
  sheet.body.appendChild(form);

  const submit = el(`<button class="btn">ผูกการจอง</button>`);
  submit.onclick = async () => {
    const bookingRef = form.querySelector('#claimRef').value.trim().toUpperCase();
    const phone = form.querySelector('#claimPhone').value.trim();
    const banner = form.querySelector('#claimBanner');

    if (!bookingRef || phone.length < 4) {
      banner.innerHTML = '<div class="banner error">กรอกเลขที่การจอง และเบอร์โทรอย่างน้อย 4 ตัวท้ายครับ</div>';
      return;
    }

    submit.disabled = true;
    submit.textContent = 'กำลังตรวจสอบ…';
    try {
      const res = await api('/bookings/claim', { method: 'POST', body: { booking_ref: bookingRef, phone } });
      sheet.close();
      alert('ผูกการจองเข้าบัญชีของคุณแล้ว');
      showBookingDetail(res.data?.booking_ref || bookingRef);
    } catch (e) {
      banner.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
      submit.disabled = false;
      submit.textContent = 'ผูกการจอง';
    }
  };
  sheet.foot.appendChild(submit);
}

/* --------------------------- คิวรอของฉัน --------------------------- */

function waitlistBlock() {
  const wrap = el(`<div></div>`);
  api('/waitlist')
    .then((res) => {
      const entries = (res.data || []).filter((e) => e.status !== 'cancelled' && e.status !== 'converted');
      if (!entries.length || !wrap.isConnected) return;

      wrap.appendChild(el(`<div class="section-heading">คิวรอที่นั่ง</div>`));
      entries.forEach((entry) => {
        const schedule = entry.schedule || {};
        const offered = entry.status === 'offered';
        const card = el(`<div class="card"><div class="body">
          <p class="title">${esc(schedule.trip?.title || 'ทริป')}</p>
          <div class="meta">
            <span>${thaiDate(schedule.departure_date)}</span>
            <span>${entry.seat_count || 1} ที่นั่ง</span>
            ${offered ? '<span class="tag ok">ได้สิทธิ์แล้ว</span>' : `<span class="tag">ลำดับที่ ${entry.position ?? '-'}</span>`}
          </div>
          ${offered && entry.expires_at ? `<p class="muted">สิทธิ์หมดอายุ ${esc(new Date(entry.expires_at).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }))}</p>` : ''}
        </div></div>`);

        if (offered && schedule.trip?.slug) {
          const book = el(`<button class="btn" style="margin:0 14px 14px">จองที่นั่งที่ได้สิทธิ์</button>`);
          book.onclick = () => showTrip(schedule.trip.slug);
          card.appendChild(book);
        }

        const leave = el(`<button class="btn secondary" style="margin:0 14px 14px">ออกจากคิว</button>`);
        leave.onclick = async () => {
          const ok = await askConfirm('ออกจากคิวรอ', 'ออกจากคิวรอของรอบนี้ไหมครับ', 'ออกจากคิว', 'อยู่ต่อ');
          if (!ok) return;
          try {
            await api('/schedules/' + (entry.schedule_id || schedule.id) + '/waitlist', { method: 'DELETE' });
            showMyBookings();
          } catch (e) { alert(e.message); }
        };
        card.appendChild(leave);
        wrap.appendChild(card);
      });
    })
    .catch(() => { /* ไม่มีคิวก็ไม่ต้องบอกอะไร */ });
  return wrap;
}

/* ======================= รายละเอียดการจอง ======================= */

let detail = { booking: null, tab: 'trip' };

async function showBookingDetail(ref, tab) {
  stopPaymentTimers();
  if (tab) detail.tab = tab;

  if (!detail.booking || detail.booking.booking_ref !== ref) {
    loading('กำลังโหลดการจอง…');
    try {
      detail = { booking: (await api('/bookings/' + encodeURIComponent(ref))).data, tab: tab || 'trip' };
    } catch (e) {
      return errorScreen(e.message, () => showBookingDetail(ref));
    }
  }

  const booking = detail.booking;
  const schedule = booking.schedule || {};
  const trip = schedule.trip || {};

  const node = el(`<div></div>`);
  node.appendChild(appbar('การจอง ' + booking.booking_ref, () => showMyBookings()));
  const content = el(`<div class="content"></div>`);

  content.appendChild(el(`<div class="card"><div class="body">
    <p class="title">${esc(trip.title || 'ทริป')}</p>
    <div class="meta">
      <span>${thaiDate(schedule.departure_date)}</span>
      ${bookingStatusTag(booking)}
    </div>
    ${earlyDepartureHtml(schedule)}
  </div></div>`));

  // สิ่งที่ต้องทำต่อ (ถ้ามี) อยู่บนสุดเสมอ — เงินค้างคือเรื่องที่ต้องเห็นก่อนอย่างอื่น
  const todo = outstandingBlock(booking);
  if (todo) content.appendChild(todo);

  // ข้อเสนอ "ไปต่อกันไหม?" มีเส้นตาย และรอบจะออกหรือไม่ออกขึ้นกับคำตอบนี้
  content.appendChild(flexiOfferBlock(booking));

  const tabs = [
    { key: 'trip', label: 'ทริป' },
    { key: 'people', label: 'ผู้เดินทาง' },
    { key: 'money', label: 'การเงิน' },
    { key: 'manage', label: 'จัดการ' },
  ];
  if (!tabs.some((t) => t.key === detail.tab)) detail.tab = 'trip';

  const tabBar = el(`<div class="tabbar"></div>`);
  tabs.forEach((t) => {
    const btn = el(`<button type="button" class="tab ${detail.tab === t.key ? 'on' : ''}">${esc(t.label)}</button>`);
    btn.onclick = () => showBookingDetail(ref, t.key);
    tabBar.appendChild(btn);
  });
  content.appendChild(tabBar);

  const pane = el(`<div class="tabpane"></div>`);
  content.appendChild(pane);
  node.appendChild(content);
  render(node);

  if (detail.tab === 'trip') renderBookingTrip(pane, booking);
  else if (detail.tab === 'people') renderBookingPeople(pane, booking);
  else if (detail.tab === 'money') renderBookingMoney(pane, booking);
  else renderBookingManage(pane, booking);
}

/** โหลดใบจองใหม่แล้ววาดหน้าเดิม — ใช้หลังทุกการกระทำที่เปลี่ยนใบจอง */
async function reloadBookingDetail() {
  const ref = detail.booking.booking_ref;
  detail.booking = null;
  await showBookingDetail(ref, detail.tab);
}

/* --------- ยอดที่ยังค้าง --------- */

function outstandingBlock(booking) {
  // ยังไม่ได้จ่ายครั้งแรก — ส่งไปหน้าชำระเงินเดิม
  if (booking.status === 'pending' && !booking.slip_ocr_status) {
    const wrap = el(`<div class="banner warn">ยังไม่ได้ชำระเงิน — ที่นั่งจะถูกปล่อยคืนเมื่อหมดเวลา</div>`);
    const btn = el(`<button class="btn">ชำระเงิน</button>`);
    btn.onclick = () => showPayment(booking);
    const box = el(`<div></div>`);
    box.appendChild(wrap);
    box.appendChild(btn);
    return box;
  }
  if (booking.status === 'pending' && booking.slip_ocr_status) {
    return el(`<div class="banner warn">ได้รับสลิปแล้ว ทีมงานกำลังตรวจสอบยอดโอน — ที่นั่งยังถูกกันไว้ให้</div>`);
  }

  const box = el(`<div></div>`);
  let has = false;

  // ยอดคงเหลือของการจองแบบมัดจำ (แบ่งจ่ายกลุ่มยืม payment_type='deposit' ไปใช้
  // จึงต้องแยกด้วย split.enabled ไม่งั้นเจ้าของจะเห็นปุ่มจ่ายเต็มยอดซ้อนกับส่วนแบ่ง)
  const balance = Number(booking.balance_amount || 0);
  if (booking.payment_type === 'deposit' && !booking.split?.enabled && !booking.balance_paid_at && balance > 0) {
    has = true;
    const due = booking.balance_due_at ? ' · ภายใน ' + thaiDate(booking.balance_due_at.slice(0, 10)) : '';
    box.appendChild(el(`<div class="banner warn">ยอดคงเหลือ ${baht(balance)}${esc(due)}</div>`));
    const btn = el(`<button class="btn">ชำระยอดคงเหลือ</button>`);
    btn.onclick = () => showOutstandingPayment(booking, { purpose: 'balance', amount: balance, label: 'ยอดคงเหลือ' });
    box.appendChild(btn);
  }

  // งวดถัดไปที่ถึงกำหนด
  const nextInstallment = (booking.installment_payments || []).find((i) => i.status !== 'paid' && i.installment_no > 1);
  if (nextInstallment) {
    has = true;
    box.appendChild(el(`<div class="banner warn">งวดที่ ${nextInstallment.installment_no} ${baht(nextInstallment.amount)}${nextInstallment.due_date ? ' · ครบกำหนด ' + thaiDate(nextInstallment.due_date) : ''}</div>`));
    const btn = el(`<button class="btn">ชำระงวดที่ ${nextInstallment.installment_no}</button>`);
    btn.onclick = () => showOutstandingPayment(booking, {
      purpose: 'installment_due',
      amount: Number(nextInstallment.amount),
      installmentNo: nextInstallment.installment_no,
      installmentId: nextInstallment.id,
      label: `ค่างวดที่ ${nextInstallment.installment_no}`,
    });
    box.appendChild(btn);
  }

  return has ? box : null;
}

/* --------- Flexi-Price: "ไปต่อกันไหม?" --------- */

/**
 * รอบที่คนไม่ครบ ผู้จัดขอเก็บส่วนต่างค่ารถท่านละ X ในวันเดินทาง เพื่อให้ทริป
 * ออกได้ตามกำหนดเดิม เจ้าของใบจองเป็นคนตอบรับ/สละสิทธิ์
 *
 * ข้อความชุดเดียวกับการ์ดในแอป (booking_detail.part.dart) และเช่นเดียวกับที่นั่น
 * ข้อเสนอที่ถูกปฏิเสธ/หมดอายุไม่ต้องรกในหน้าใบจอง ปล่อยให้แจ้งเตือนเป็นคนบอก
 */
function flexiOfferBlock(booking) {
  const wrap = el(`<div></div>`);

  api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/flexi-offer')
    .then((res) => {
      const offer = res.data;
      if (!offer || !wrap.isConnected) return;

      const confirmed = offer.status === 'confirmed';
      if (!offer.is_open && !confirmed) return;

      const progress = offer.progress || {};
      const perPerson = baht(offer.surcharge_per_person);
      const myTotal = baht(offer.my_surcharge_total ?? offer.surcharge_per_person);

      wrap.appendChild(el(`<div class="card"><div class="body">
        <p class="title">${confirmed ? 'ทริปไปต่อแน่นอน! 🎉' : 'ไปต่อกันไหม?'}</p>
        <p class="announce-body">${confirmed
          ? `ทุกท่านยินดีไปต่อแล้ว รอบนี้ออกเดินทางตามกำหนดเดิม ส่วนต่างค่ารถ ${esc(myTotal)} จะเก็บในวันเดินทาง`
          : `รอบนี้มีผู้เดินทางไม่ครบ หากช่วยกันจ่ายส่วนต่างค่ารถเพิ่มท่านละ ${esc(perPerson)} ทริปจะออกเดินทางได้ตามกำหนดเดิม (เก็บในวันเดินทาง)`}</p>
        ${!confirmed && offer.reason ? `<p class="muted" style="margin:8px 0 0">“${esc(offer.reason)}”</p>` : ''}
        <div class="kv" style="margin-top:10px"><span class="k">ส่วนต่างของคุณ</span><span class="v price">${esc(myTotal)}</span></div>
        ${progress.total ? `<div class="kv"><span class="k">ตอบรับแล้ว</span><span class="v">${progress.accepted || 0} / ${progress.total} คน</span></div>` : ''}
        ${offer.respond_by && !confirmed ? `<div class="kv"><span class="k">ตอบภายใน</span><span class="v">${esc(new Date(offer.respond_by).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }))}</span></div>` : ''}
      </div></div>`));

      if (!offer.is_open) return;

      // เพื่อนร่วมใบจองเห็นข้อเสนอได้ แต่เซิร์ฟเวอร์ให้เฉพาะเจ้าของเป็นคนตอบ —
      // ถ้าโชว์ปุ่มให้ทุกคนกด คนที่ไม่ใช่เจ้าของจะได้ 403 กลับมาเฉย ๆ
      if (booking.viewer_is_owner === false) {
        wrap.appendChild(el(`<p class="muted">ผู้จองหลักเป็นคนตอบรับข้อเสนอนี้ครับ</p>`));
        return;
      }

      if (offer.my_consent === 'accepted') {
        wrap.appendChild(el(`<div class="banner success">คุณยืนยันไปต่อแล้ว กำลังรอเพื่อนร่วมทริปตอบรับให้ครบ</div>`));
        return;
      }
      if (offer.my_consent === 'declined') return;

      const respond = async (accept, button) => {
        if (!accept) {
          const ok = await askConfirm(
            'ยืนยันการสละสิทธิ์?',
            'หากคุณไม่ไปต่อ ทริปรอบนี้อาจไม่สามารถออกเดินทางได้ และทีมงานจะติดต่อกลับเรื่องการคืนเงิน ยืนยันหรือไม่?',
            'ยืนยันสละสิทธิ์', 'ยกเลิก',
          );
          if (!ok) return;
        }
        button.disabled = true;
        button.textContent = 'กำลังส่ง…';
        try {
          await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/flexi-offer/respond', {
            method: 'POST', body: { accept },
          });
          alert(accept ? 'ยืนยันการไปต่อแล้ว ขอบคุณครับ 🙌' : 'รับทราบการตอบกลับแล้ว');
          reloadBookingDetail();
        } catch (e) {
          alert(e.message);
          button.disabled = false;
          button.textContent = accept ? 'ยินดีไปต่อ' : 'ขอสละสิทธิ์';
        }
      };

      const yes = el(`<button class="btn">ยินดีไปต่อ</button>`);
      yes.onclick = () => respond(true, yes);
      const no = el(`<button class="btn secondary" style="margin-top:8px">ขอสละสิทธิ์</button>`);
      no.onclick = () => respond(false, no);
      wrap.appendChild(yes);
      wrap.appendChild(no);
    })
    .catch(() => { /* ไม่ใช่เจ้าของ หรือรอบนี้ไม่มีข้อเสนอ */ });

  return wrap;
}

/* --------- ประกาศจากผู้จัด --------- */

/* ป้ายชื่อหมวดชุดเดียวกับที่ทีมงานเลือกตอนโพสต์ (AnnouncementsPage.vue) */
const ANNOUNCEMENT_CATEGORIES = {
  general: 'ทั่วไป',
  meeting_point: 'จุดนัดพบ',
  schedule_change: 'เปลี่ยนเวลา',
  packing: 'ของที่ต้องเตรียม',
  weather: 'สภาพอากาศ',
  urgent: 'ด่วน',
};

/**
 * ประกาศของรอบนี้ — เลื่อนเวลา เปลี่ยนจุดนัดพบ ของที่ต้องเตรียม
 *
 * เซิร์ฟเวอร์เป็นคนตัดสินว่าใครอ่านได้ (ต้องเป็นสมาชิกของรอบ) ที่นี่จึงแค่ขอ
 * แล้วเงียบไปเมื่อถูกปฏิเสธ — ใบจองที่ยังไม่ยืนยันยังไม่ใช่สมาชิกของรอบ
 */
function announcementsBlock(booking) {
  const scheduleId = booking.schedule?.id;
  if (!scheduleId) return el(`<div></div>`);

  const wrap = el(`<div></div>`);

  api('/schedules/' + scheduleId + '/announcements')
    .then((res) => {
      const list = res.data?.announcements || [];
      if (!list.length || !wrap.isConnected) return;

      const unread = Number(res.data?.unread_count || 0);
      wrap.appendChild(el(`<div class="section-heading">ประกาศจากผู้จัด${unread ? ` · ใหม่ ${unread}` : ''}</div>`));

      list.forEach((a) => {
        const posted = a.created_at
          ? new Date(a.created_at).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
          : '';
        wrap.appendChild(el(`<div class="card"><div class="body">
          <div class="meta" style="margin-bottom:6px">
            <span class="tag${a.category === 'urgent' ? ' warn' : ''}">${esc(ANNOUNCEMENT_CATEGORIES[a.category] || 'ทั่วไป')}</span>
            ${a.is_pinned ? '<span class="tag">📌 ปักหมุด</span>' : ''}
          </div>
          <p class="title">${esc(a.title)}</p>
          <p class="announce-body">${esc(a.body)}</p>
          <p class="muted" style="margin:6px 0 0">${esc(a.author_name || 'ทีมงาน')}${posted ? ' · ' + esc(posted) : ''}</p>
        </div></div>`));
      });

      // เปิดอ่านแล้วถือว่าอ่าน — ตัวนับที่ค้างไว้จะไปโผล่เป็นแจ้งเตือนซ้ำในแอป
      if (unread) api('/schedules/' + scheduleId + '/announcements/read', { method: 'POST' }).catch(() => {});
    })
    .catch(() => { /* ยังไม่ใช่สมาชิกของรอบ หรือรอบนี้ไม่มีประกาศ */ });

  return wrap;
}

/* --------- แท็บ: ทริป --------- */

function renderBookingTrip(pane, booking) {
  const schedule = booking.schedule || {};
  const trip = schedule.trip || {};
  const pickup = booking.pickup_point;

  // วันเดินทาง: "คันไหนคือคันของเรา" กับปุ่มบอกทีมงานว่าอยู่ตรงไหน มาก่อนทุกอย่าง
  // เซิร์ฟเวอร์ตัดสินหน้าต่างเวลา (pickup_status_open) — ไม่คิดวันเวลาไทยเองที่นี่
  if (booking.pickup_status_open) {
    if (schedule.transport_type !== 'flight') pane.appendChild(findMyVanBlock(booking));
    pane.appendChild(pickupStatusBlock(booking));
  }

  // QR เช็คอิน — ของที่ต้องเปิดหน้างาน ควรหาเจอในสองแตะ
  if (booking.status === 'confirmed') {
    const qrBox = el(`<div class="qr-wrap"><div class="loading-inline"><div class="spinner"></div></div></div>`);
    pane.appendChild(qrBox);
    api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/check-in-qr')
      .then((res) => {
        if (!qrBox.isConnected) return;
        const data = res.data;
        qrBox.innerHTML = '';
        qrBox.appendChild(el(`<img src="${esc(data.qr_data_uri)}" alt="QR เช็คอิน">`));
        qrBox.appendChild(el(`<div class="muted">${esc(data.code)}</div>`));
        qrBox.appendChild(el(`<div class="${data.checked_in ? 'tag ok' : 'muted'}">${data.checked_in ? '✓ เช็คอินแล้ว' : 'ให้ทีมงานสแกนตอนขึ้นรถ'}</div>`));
      })
      .catch(() => { qrBox.remove(); });
  }

  // ใบเดินทาง — ส่งให้ก่อนเดินทาง 2 วัน รวมทุกอย่างของรอบไว้หน้าเดียว
  // (ปฏิทิน ประกาศ ข้อมูลที่ยังขาด ปุ่มรับทราบ) ลิงก์มีเมื่อระบบส่งไปแล้วเท่านั้น
  if (booking.brief_url && booking.status === 'confirmed') {
    const brief = el(`<button type="button" class="pick brief-link">
      <div class="pick-body">
        <div class="pick-name">📋 ใบเดินทาง</div>
        <div class="pick-sub">${booking.brief_ack_at ? 'คุณกดรับทราบแล้ว · เปิดดูอีกครั้งได้' : 'สรุปทุกอย่างที่ต้องรู้ก่อนออกเดินทาง อ่านแล้วกดรับทราบด้วยนะครับ'}</div>
      </div>
      <span class="brief-go">›</span>
    </button>`);
    brief.onclick = () => liff.openWindow({ url: booking.brief_url, external: false });
    pane.appendChild(brief);
  }

  // ประกาศมาก่อนรายละเอียดที่ลูกค้าอ่านไปแล้วตอนจอง — มันคือสิ่งที่เปลี่ยน
  pane.appendChild(announcementsBlock(booking));

  const rows = [
    ['ทริป', trip.title],
    ['วันเดินทาง', thaiDate(schedule.departure_date)],
    schedule.return_date && schedule.return_date !== schedule.departure_date ? ['วันกลับ', thaiDate(schedule.return_date)] : null,
    booking.is_join_trip ? ['รูปแบบ', 'จอยทริป (ไม่ใช้ที่นั่งบนรถ)'] : null,
    (booking.seats || []).length ? ['ที่นั่ง', booking.seats.map((s) => s.seat_id).join(', ')] : null,
    booking.vehicle_option ? ['ประเภทรถ', booking.vehicle_option.label] : null,
  ].filter(Boolean);
  pane.appendChild(el(`<div class="card"><div class="body">
    ${rows.map(([k, v]) => `<div class="kv"><span class="k">${esc(k)}</span><span class="v">${esc(v || '-')}</span></div>`).join('')}
  </div></div>`));

  // จุดขึ้นรถ
  if (pickup) {
    pane.appendChild(el(`<div class="section-heading">จุดขึ้นรถ</div>`));
    const card = el(`<div class="card"><div class="body">
      <div class="pick-name">${esc(pickup.pickup_location || pickup.region_label || 'จุดรับ')}</div>
      ${pickup.pickup_time ? `<div class="pick-sub">🕖 ${esc(pickup.pickup_time)} น.</div>` : ''}
      ${pickup.notes ? `<div class="pick-sub">${esc(pickup.notes)}</div>` : ''}
    </div></div>`);
    if (pickup.map_url) {
      const map = el(`<button class="btn secondary" style="margin-top:8px">เปิดแผนที่จุดขึ้นรถ</button>`);
      map.onclick = () => liff.openWindow({ url: pickup.map_url, external: true });
      card.appendChild(map);
    }
    pane.appendChild(card);
  } else if (booking.custom_pickup) {
    const cp = booking.custom_pickup;
    const statusLabel = { pending: 'รอทีมงานยืนยัน', approved: 'ยืนยันแล้ว', rejected: 'ไม่สามารถรับได้' }[cp.status] || cp.status;
    pane.appendChild(el(`<div class="section-heading">จุดรับที่คุณปักหมุด</div>`));
    pane.appendChild(el(`<div class="card"><div class="body">
      <div class="pick-name">${esc(cp.label)}</div>
      <div class="pick-sub">${esc(statusLabel)}${cp.price ? ' · ' + baht(cp.price) + ' / คน' : ''}</div>
      ${cp.reject_reason ? `<div class="pick-sub">${esc(cp.reject_reason)}</div>` : ''}
    </div></div>`));
  }

  // จุดนัดพบของรอบที่บินไป
  const plan = schedule.flight_plan;
  if (plan?.meeting_point) {
    pane.appendChild(el(`<div class="section-heading">จุดนัดพบ</div>`));
    pane.appendChild(el(`<p class="muted">${esc((plan.meeting_time ? `นัดพบ ${plan.meeting_time} น. · ` : '') + plan.meeting_point)}</p>`));
  }

  // ทีมงานประจำรอบ
  const staff = booking.assigned_staff || [];
  if (staff.length) {
    pane.appendChild(el(`<div class="section-heading">ทีมงานประจำรอบ</div>`));
    staff.forEach((person) => {
      const row = el(`<div class="pick">
        <div class="pick-body">
          <div class="pick-name">${esc(person.nickname || person.name)}</div>
          <div class="pick-sub">${esc(person.phone || '')}</div>
        </div>
      </div>`);
      if (person.phone) row.onclick = () => liff.openWindow({ url: 'tel:' + person.phone, external: true });
      pane.appendChild(row);
    });
  }

  const calendar = el(`<button class="btn secondary" style="margin-top:14px">เพิ่มลงปฏิทิน</button>`);
  calendar.onclick = () => addBookingToCalendar(booking);
  pane.appendChild(calendar);
}

/* --------- วันเดินทาง: หารถ + บอกสถานะที่จุดนัด --------- */

/**
 * การ์ด "คันไหนคือคันของเรา" — ทะเบียนตัวใหญ่ สี รูปรถ และรูปจุดจอดเมื่อสตาฟกด
 * ว่ารถถึงแล้ว (ของชิ้นเดียวกับ FindMyVanCard ในแอป)
 *
 * ลานจอดตอนตีห้ามีรถตู้ขาวสิบคัน ทะเบียนที่ซ่อนอยู่ในแท็บอื่นไม่ช่วยใคร
 * ข้อมูลรถอยู่ที่ /tracking เท่านั้น จึงโหลดแยก — ไม่มีอะไรให้โชว์ก็หายไปเงียบ ๆ
 */
function findMyVanBlock(booking) {
  const wrap = el(`<div class="van-card" hidden></div>`);
  api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/tracking')
    .then((res) => {
      if (!wrap.isConnected) return; // สลับแท็บไปแล้ว
      const t = res.data || {};
      const point = booking.pickup_point || {};
      const arrivedAt = t.pickup_arrived_at || point.arrived_at;
      const arrivalPhoto = t.pickup_arrival_photo_url || point.arrival_photo_url;
      const arrivalNote = t.pickup_arrival_note || point.arrival_note;
      const looks = [t.vehicle_name, t.vehicle_color ? 'สี' + t.vehicle_color : null].filter(Boolean).join(' · ');
      if (!t.license_plate && !looks && !arrivedAt) return;

      wrap.innerHTML = `
        ${arrivedAt ? `<div class="van-arrived">🚐 รถถึงจุดรับแล้ว · ${esc(clockTime(arrivedAt))} น.</div>` : '<div class="van-label">รถของรอบนี้</div>'}
        ${t.license_plate ? `<div class="van-plate">${esc(t.license_plate)}</div>` : ''}
        ${looks ? `<div class="van-looks">${esc(looks)}</div>` : ''}
        ${arrivalPhoto || arrivalNote ? `<div class="van-spot">
          ${arrivalPhoto ? `<img src="${esc(arrivalPhoto)}" alt="จุดที่รถจอด">` : ''}
          ${arrivalNote ? `<div class="pick-sub">📍 ${esc(arrivalNote)}</div>` : ''}
        </div>` : ''}
        ${t.vehicle_photo ? `<img class="van-photo" src="${esc(t.vehicle_photo)}" alt="รูปรถ">` : ''}
        <div class="van-actions"></div>`;

      const actions = wrap.querySelector('.van-actions');
      if (t.share_url) {
        const live = el(`<button class="btn secondary">ดูตำแหน่งรถสด</button>`);
        live.onclick = () => liff.openWindow({ url: t.share_url, external: false });
        actions.appendChild(live);
      }
      if (t.driver_phone) {
        const call = el(`<button class="btn secondary">โทรหาคนขับ${t.driver_name ? ' (' + esc(t.driver_name) + ')' : ''}</button>`);
        call.onclick = () => liff.openWindow({ url: 'tel:' + t.driver_phone, external: true });
        actions.appendChild(call);
      }
      wrap.hidden = false;
    })
    .catch(() => { wrap.remove(); });
  return wrap;
}

/** "07:42" ตามเวลาไทย จาก ISO timestamp */
function clockTime(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Bangkok' });
}

const PICKUP_STATUS_OPTIONS = [
  ['on_the_way', '🚶', 'กำลังไป'],
  ['arrived', '📍', 'ถึงแล้ว'],
  ['late', '⏳', 'อาจสาย'],
];

/**
 * ปุ่ม "กำลังไป / ถึงแล้ว / อาจสาย" — เช้าวันเดินทางทีมงานจะได้ไม่ต้องโทรไล่ทีละคน
 *
 * ไม่ใช่การเช็คอิน: กด "ถึงแล้ว" ไม่ทำให้ใครขึ้นรถ คนยืนยันยังเป็นทีมงานเสมอ
 * ข้อความสถานะ (pickup_status_label) มาจาก PickupStatusService::label ตัวเดียวกับที่สตาฟเห็น
 */
function pickupStatusBlock(booking) {
  const card = el(`<div class="card pickup-status"><div class="body"></div></div>`);
  const body = card.querySelector('.body');

  const paint = (error) => {
    const current = booking.pickup_status;
    body.innerHTML = `
      <div class="pick-name">${current ? 'บอกทีมงานไว้ว่า · ' + esc(booking.pickup_status_label || '') : 'บอกทีมงานว่าคุณอยู่ตรงไหน'}</div>
      <div class="pick-sub">${current && booking.pickup_status_at
        ? 'เมื่อ ' + esc(clockTime(booking.pickup_status_at)) + ' น. · กดใหม่ได้ถ้าสถานการณ์เปลี่ยน'
        : 'กดบอกได้เลย ทีมงานจะได้ไม่ต้องโทรตาม และรู้ว่าต้องรอใครอยู่'}</div>
      ${error ? `<div class="banner error" style="margin:8px 0 0">${esc(error)}</div>` : ''}
      <div class="chip-row status-row"></div>
      <div class="pick-sub">สถานะนี้ไม่ใช่การเช็คอิน — ทีมงานยังเป็นคนยืนยันตอนขึ้นรถ</div>`;
    const row = body.querySelector('.status-row');
    PICKUP_STATUS_OPTIONS.forEach(([value, emoji, label]) => {
      const btn = el(`<button type="button" class="chip ${current === value ? 'on' : ''}">${emoji} ${label}</button>`);
      btn.onclick = () => (value === 'late' ? askHowLate() : send(value, null));
      row.appendChild(btn);
    });
  };

  const send = async (status, etaMinutes) => {
    body.querySelectorAll('.status-row .chip').forEach((b) => { b.disabled = true; });
    try {
      const res = await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/pickup-status', {
        method: 'POST',
        body: { status, eta_minutes: etaMinutes },
      });
      const data = res.data || {};
      Object.assign(booking, {
        pickup_status: data.pickup_status,
        pickup_status_at: data.pickup_status_at,
        pickup_status_eta_minutes: data.pickup_status_eta_minutes,
        pickup_status_label: data.label,
      });
      paint();
    } catch (e) {
      paint(e.message);
    }
  };

  // ตัวเลขคือสิ่งเดียวที่ทำให้ทีมงานตัดสินใจได้ว่าจะรอต่อหรือให้ไปขึ้นจุดถัดไป
  const askHowLate = () => {
    const sheet = openSheet('สายประมาณเท่าไหร่');
    sheet.body.appendChild(el(`<p class="muted" style="margin-top:0">บอกคร่าว ๆ ได้เลย ทีมงานจะได้วางแผนรอถูก</p>`));
    const row = el(`<div class="chip-row"></div>`);
    [5, 10, 15, 30, 45, 60].forEach((minutes) => {
      const chip = el(`<button type="button" class="chip">${minutes} นาที</button>`);
      chip.onclick = () => { sheet.close(); send('late', minutes); };
      row.appendChild(chip);
    });
    const unsure = el(`<button type="button" class="chip">ไม่แน่ใจ</button>`);
    unsure.onclick = () => { sheet.close(); send('late', null); };
    row.appendChild(unsure);
    sheet.body.appendChild(row);
  };

  paint();
  return card;
}

/**
 * ไฟล์ .ics ของรอบนี้
 *
 * ประกอบเองในเครื่องแทนที่จะขอจากเซิร์ฟเวอร์ เพราะเป็นข้อมูลที่หน้านี้มีครบอยู่แล้ว
 * ทั้งไฟล์ และเปิดผ่าน data: URI ได้เลยโดยไม่ต้องมี endpoint ใหม่
 */
function addBookingToCalendar(booking) {
  const schedule = booking.schedule || {};
  const trip = schedule.trip || {};
  const start = String(schedule.departure_date || '').replace(/-/g, '');
  if (!start) return alert('รอบนี้ยังไม่มีวันเดินทางที่แน่นอน');

  const endDate = new Date((schedule.return_date || schedule.departure_date) + 'T00:00:00');
  endDate.setDate(endDate.getDate() + 1); // ปฏิทินนับวันสิ้นสุดแบบไม่รวมวันนั้น
  const end = `${endDate.getFullYear()}${String(endDate.getMonth() + 1).padStart(2, '0')}${String(endDate.getDate()).padStart(2, '0')}`;

  const ics = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//luilaykhao//liff//TH', 'BEGIN:VEVENT',
    'UID:' + booking.booking_ref + '@luilaykhao',
    'DTSTART;VALUE=DATE:' + start,
    'DTEND;VALUE=DATE:' + end,
    'SUMMARY:' + (trip.title || 'ทริปลุยเลเขา'),
    'DESCRIPTION:เลขที่จอง ' + booking.booking_ref,
    'LOCATION:' + (trip.location || ''),
    'END:VEVENT', 'END:VCALENDAR',
  ].join('\r\n');

  liff.openWindow({ url: 'data:text/calendar;charset=utf-8,' + encodeURIComponent(ics), external: true });
}

/* --------- แท็บ: ผู้เดินทาง --------- */

function renderBookingPeople(pane, booking) {
  const passengers = booking.passengers || [];
  if (!passengers.length) {
    pane.appendChild(el(`<div class="empty">ยังไม่มีข้อมูลผู้เดินทาง</div>`));
    return;
  }

  passengers.forEach((p, i) => {
    pane.appendChild(el(`<div class="card"><div class="body">
      <div class="pax-head">คนที่ ${i + 1}${p.seat_id ? ` <span class="tag">ที่นั่ง ${esc(p.seat_id)}</span>` : ''}</div>
      <div class="kv"><span class="k">ชื่อ</span><span class="v">${esc([p.title, p.name].filter(Boolean).join(''))}${p.nickname ? ` (${esc(p.nickname)})` : ''}</span></div>
      ${p.phone ? `<div class="kv"><span class="k">เบอร์โทร</span><span class="v">${esc(p.phone)}</span></div>` : ''}
      ${p.blood_group ? `<div class="kv"><span class="k">กรุ๊ปเลือด</span><span class="v">${esc(p.blood_group)}</span></div>` : ''}
      ${p.pickup_point ? `<div class="kv"><span class="k">จุดขึ้นรถ</span><span class="v">${esc(p.pickup_point.pickup_location || p.pickup_point.region_label || '-')}</span></div>` : ''}
    </div></div>`));
  });

  // เอกสารแนบ — ทริปที่ขอเอกสารไว้ ยังตามส่งทีหลังได้จากที่นี่
  const requirements = booking.schedule?.trip?.document_requirements || [];
  if (requirements.length) {
    pane.appendChild(el(`<div class="section-heading">เอกสารแนบ</div>`));
    pane.appendChild(bookingDocuments(booking, requirements, passengers));
  }

  if (booking.viewer_is_owner !== false && booking.status !== 'cancelled') {
    pane.appendChild(el(`<div class="section-heading">ให้เพื่อนกรอกข้อมูลเอง</div>`));
    pane.appendChild(el(`<p class="muted">คนจองไม่ต้องไปถามเลขบัตรหรือโรคประจำตัวของใคร ส่งลิงก์ให้เจ้าตัวกรอกเองในแชทได้เลย</p>`));

    passengers.forEach((p, i) => {
      const row = el(`<div class="pick">
        <div class="pick-body">
          <div class="pick-name">คนที่ ${i + 1}${p.name ? ' · ' + esc(p.name) : ''}</div>
          <div class="pick-sub">ส่งลิงก์ให้กรอกข้อมูลเอง</div>
        </div>
        <span class="tag">ส่ง ›</span>
      </div>`);
      row.onclick = () => sendPassengerFillLink(booking, p, i, row);
      pane.appendChild(row);
    });

    // คำเชิญระดับใบจอง — เพื่อนที่รับคำเชิญจะเห็นใบจองนี้ในบัญชีตัวเอง
    // (คนละเรื่องกับลิงก์กรอกข้อมูลด้านบน ซึ่งไม่ต้องมีบัญชีเลย)
    const invite = el(`<button class="btn secondary" style="margin-top:10px">💬 ชวนเพื่อนเข้าใบจองนี้</button>`);
    invite.onclick = () => sendBookingInvite(booking, invite);
    pane.appendChild(invite);
  }
}

/**
 * ลิงก์ให้ผู้เดินทางคนหนึ่งกรอกข้อมูลของตัวเอง
 *
 * เซิร์ฟเวอร์ออกโทเคนใหม่ทุกครั้ง ลิงก์เก่าที่เคยส่งไปจะใช้ไม่ได้อีก — จึงต้องถาม
 * ก่อน ไม่งั้นการกดซ้ำเพราะคิดว่ายังไม่ได้ส่ง จะทำลิงก์ที่เพื่อนกำลังกรอกอยู่พัง
 */
async function sendPassengerFillLink(booking, passenger, index, row) {
  const ok = await askConfirm(
    'ส่งลิงก์ให้กรอกเอง',
    `สร้างลิงก์สำหรับคนที่ ${index + 1}${passenger.name ? ' (' + passenger.name + ')' : ''} — ถ้าเคยส่งลิงก์ของคนนี้ไปแล้ว ลิงก์เดิมจะใช้ไม่ได้อีก`,
    'สร้างและส่งลิงก์', 'ยกเลิก',
  );
  if (!ok) return;

  row.style.opacity = '.5';
  let invite;
  try {
    invite = (await api(
      '/bookings/' + encodeURIComponent(booking.booking_ref) + '/passengers/' + passenger.id + '/invite',
      { method: 'POST' },
    )).data;
  } catch (e) {
    row.style.opacity = '';
    return alert(e.message);
  }
  row.style.opacity = '';

  const tripTitle = booking.schedule?.trip?.title || 'ทริป';
  const text = `ฝากกรอกข้อมูลผู้เดินทางสำหรับ ${tripTitle} ให้หน่อยครับ (ลิงก์ใช้ได้ ${invite.expires_in_days || 14} วัน)\n${invite.url}`;
  await shareToLine(text, invite.url, 'ส่งลิงก์ให้เพื่อนแล้ว', 'คัดลอกลิงก์กรอกข้อมูลแล้ว');
}

/** คำเชิญให้เพื่อนเข้ามาเห็นใบจองนี้ในบัญชีของตัวเอง */
async function sendBookingInvite(booking, btn) {
  btn.disabled = true;
  btn.textContent = 'กำลังสร้างคำเชิญ…';

  let invite;
  try {
    invite = (await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/invites', { method: 'POST', body: {} })).data;
  } catch (e) {
    btn.disabled = false;
    btn.textContent = '💬 ชวนเพื่อนเข้าใบจองนี้';
    return alert(e.message);
  }

  btn.disabled = false;
  btn.textContent = '💬 ชวนเพื่อนเข้าใบจองนี้';

  const tripTitle = booking.schedule?.trip?.title || 'ทริป';
  const text = `มาเที่ยว ${tripTitle} ด้วยกันครับ กดลิงก์นี้เพื่อเข้าใบจองเดียวกัน\n${invite.invite_url}`;
  await shareToLine(text, invite.invite_url, 'ส่งคำเชิญแล้ว', 'คัดลอกลิงก์คำเชิญแล้ว');
}

function bookingDocuments(booking, requirements, passengers) {
  const wrap = el(`<div></div>`);
  wrap.appendChild(el(`<div class="loading-inline"><div class="spinner"></div></div>`));

  api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/documents')
    .then((res) => {
      if (!wrap.isConnected) return;
      const documents = Array.isArray(res.data) ? res.data : (res.data?.documents ?? []);
      wrap.innerHTML = '';

      passengers.forEach((p, i) => {
        const card = el(`<div class="card"><div class="body">
          <div class="pax-head">คนที่ ${i + 1} · ${esc(p.nickname || p.name || '')}</div>
        </div></div>`);
        const body = card.querySelector('.body');

        requirements.forEach((doc) => {
          const mine = documents.filter((d) => d.passenger_id === p.id && d.requirement_key === doc.key);
          const block = el(`<div class="doc-block">
            <div class="doc-head">${esc(doc.label)}${doc.required ? ' <span class="req">*</span>' : ''}</div>
            <div class="doc-list"></div>
          </div>`);
          const list = block.querySelector('.doc-list');
          mine.forEach((d) => list.appendChild(el(`<div class="doc-file"><span>${esc(d.original_name || d.file_name || 'ไฟล์')}</span></div>`)));
          if (!mine.length) list.appendChild(el(`<p class="muted">ยังไม่ได้แนบ</p>`));

          const input = el(`<input type="file" accept="image/*,.pdf,application/pdf">`);
          input.onchange = async () => {
            const file = input.files?.[0];
            input.value = '';
            if (!file) return;
            if (file.size > 10 * 1024 * 1024) return alert(`"${file.name}" ใหญ่เกิน 10 MB`);
            const form = new FormData();
            form.append('passenger_id', p.id);
            form.append('requirement_key', doc.key);
            form.append('file', file);
            try {
              await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/documents', { method: 'POST', body: form });
              reloadBookingDetail();
            } catch (e) {
              alert(e.message);
            }
          };
          block.appendChild(input);
          body.appendChild(block);
        });
        wrap.appendChild(card);
      });
    })
    .catch(() => { wrap.innerHTML = '<p class="muted">โหลดรายการเอกสารไม่สำเร็จ</p>'; });

  return wrap;
}

/* --------- แท็บ: การเงิน --------- */

function renderBookingMoney(pane, booking) {
  const total = Number(booking.total_amount || 0);
  const paid = Number(booking.paid_amount || 0);
  const typeLabel = { full: 'ชำระเต็มจำนวน', deposit: 'มัดจำ', installment: 'ผ่อนชำระ' }[booking.payment_type] || booking.payment_type;

  pane.appendChild(el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">รูปแบบ</span><span class="v">${esc(booking.split?.enabled ? 'แบ่งจ่ายกลุ่ม' : typeLabel)}</span></div>
    <div class="kv"><span class="k">ยอดรวม</span><span class="v">${baht(total)}</span></div>
    <div class="kv"><span class="k">ชำระแล้ว</span><span class="v">${baht(paid)}</span></div>
    ${booking.addons_total ? `<div class="kv"><span class="k">บริการเสริม</span><span class="v">${baht(booking.addons_total)}</span></div>` : ''}
    ${booking.rentals_total ? `<div class="kv"><span class="k">อุปกรณ์เช่า</span><span class="v">${baht(booking.rentals_total)}</span></div>` : ''}
    <div class="kv total"><span class="k">คงเหลือ</span><span class="v price">${baht(Math.max(0, total - paid))}</span></div>
  </div></div>`));

  // ตารางงวดผ่อน
  const installments = booking.installment_payments || [];
  if (installments.length) {
    pane.appendChild(el(`<div class="section-heading">งวดผ่อนชำระ</div>`));
    installments.forEach((ip) => {
      const paidRow = ip.status === 'paid';
      const row = el(`<div class="pick">
        <div class="pick-body">
          <div class="pick-name">งวดที่ ${ip.installment_no} · ${baht(ip.amount)}</div>
          <div class="pick-sub">${ip.due_date ? 'ครบกำหนด ' + thaiDate(ip.due_date) : ''}${paidRow ? ' · ชำระแล้ว' : ''}</div>
        </div>
        ${paidRow ? '<span class="tag ok">จ่ายแล้ว</span>' : ''}
      </div>`);
      if (!paidRow && ip.installment_no > 1) {
        const btn = el(`<button class="btn" style="margin:0 0 10px">ชำระงวดนี้</button>`);
        btn.onclick = () => showOutstandingPayment(booking, {
          purpose: 'installment_due', amount: Number(ip.amount),
          installmentNo: ip.installment_no, installmentId: ip.id,
          label: `ค่างวดที่ ${ip.installment_no}`,
        });
        pane.appendChild(row);
        pane.appendChild(btn);
        return;
      }
      pane.appendChild(row);
    });
  }

  // แบ่งจ่ายกลุ่ม
  pane.appendChild(splitBlock(booking));

  // ใบเสร็จ
  const receipts = el(`<div></div>`);
  pane.appendChild(receipts);
  api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/receipts')
    .then((res) => {
      const items = res.data || [];
      if (!items.length || !receipts.isConnected) return;
      receipts.appendChild(el(`<div class="section-heading">ใบเสร็จ</div>`));
      items.forEach((r) => {
        const row = el(`<div class="pick">
          <div class="pick-body">
            <div class="pick-name">${esc(r.kind_label || r.receipt_no)}</div>
            <div class="pick-sub">${esc(r.receipt_no)} · ${baht(r.amount)}</div>
          </div>
          <span class="tag">เปิด</span>
        </div>`);
        row.onclick = () => liff.openWindow({ url: r.pdf_url || r.verify_url, external: true });
        receipts.appendChild(row);
      });
    })
    .catch(() => { /* ยังไม่มีใบเสร็จก็ไม่ต้องบอกอะไร */ });
}

/* --------- แบ่งจ่ายกลุ่ม --------- */

function splitBlock(booking) {
  const wrap = el(`<div></div>`);
  const balance = Number(booking.balance_amount || 0);
  const canStart = booking.viewer_is_owner !== false
    && booking.status === 'confirmed'
    && balance > 0
    && !booking.balance_paid_at
    && (booking.passengers || []).length > 1;

  if (!booking.split?.enabled && !canStart) return wrap;

  wrap.appendChild(el(`<div class="section-heading">แบ่งจ่ายกับเพื่อน</div>`));
  const host = el(`<div><div class="loading-inline"><div class="spinner"></div></div></div>`);
  wrap.appendChild(host);

  api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/split')
    .then((res) => {
      if (!host.isConnected) return;
      const split = res.data || {};
      host.innerHTML = '';

      if (!split.enabled) {
        host.appendChild(el(`<p class="muted">แบ่งยอดคงเหลือ ${baht(split.outstanding_amount || balance)} ให้เพื่อนช่วยจ่าย ระบบจะสร้างลิงก์จ่ายของแต่ละคนให้</p>`));
        const start = el(`<button class="btn secondary">แบ่งจ่ายเท่า ๆ กัน</button>`);
        start.onclick = async () => {
          start.disabled = true;
          start.textContent = 'กำลังสร้าง…';
          try {
            await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/split', { method: 'POST', body: {} });
            reloadBookingDetail();
          } catch (e) {
            start.disabled = false;
            start.textContent = 'แบ่งจ่ายเท่า ๆ กัน';
            alert(e.message);
          }
        };
        host.appendChild(start);
        return;
      }

      host.appendChild(el(`<p class="muted">จ่ายแล้ว ${split.paid_shares}/${split.total_shares} ส่วน${split.balance_due_at ? ' · ครบกำหนด ' + thaiDate(split.balance_due_at.slice(0, 10)) : ''}</p>`));

      (split.shares || []).forEach((share) => {
        const paid = share.status === 'paid';
        const row = el(`<div class="pick">
          <div class="pick-body">
            <div class="pick-name">${esc(share.name)}${share.is_mine ? ' (ของคุณ)' : ''}</div>
            <div class="pick-sub">${baht(share.amount)}${paid ? ' · จ่ายแล้ว' : ''}</div>
          </div>
          ${paid ? '<span class="tag ok">จ่ายแล้ว</span>' : ''}
        </div>`);
        host.appendChild(row);

        if (paid) return;

        if (share.is_mine || (split.is_owner && !share.member_id)) {
          const pay = el(`<button class="btn" style="margin:0 0 10px">จ่ายส่วนนี้ · ${baht(share.amount)}</button>`);
          pay.onclick = () => showOutstandingPayment(booking, {
            purpose: 'split_share', amount: Number(share.amount),
            shareId: share.id, label: 'ส่วนแบ่งของ ' + share.name,
          });
          host.appendChild(pay);
        }
        if (split.is_owner && share.pay_url) {
          const send = el(`<button class="btn secondary" style="margin:0 0 10px">ส่งลิงก์จ่ายให้ ${esc(share.name)}</button>`);
          send.onclick = () => shareSplitLink(share);
          host.appendChild(send);
        }
      });

      if (split.is_owner) {
        const cancel = el(`<button class="btn secondary">ยกเลิกการแบ่งจ่าย</button>`);
        cancel.onclick = async () => {
          const ok = await askConfirm('ยกเลิกการแบ่งจ่าย',
            'ยกเลิกการแบ่งจ่ายทั้งหมดไหมครับ ส่วนที่จ่ายไปแล้วจะไม่ถูกลบ', 'ยกเลิกการแบ่งจ่าย', 'ไม่');
          if (!ok) return;
          try {
            await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/split', { method: 'DELETE' });
            reloadBookingDetail();
          } catch (e) { alert(e.message); }
        };
        host.appendChild(cancel);
      }
    })
    .catch(() => { host.innerHTML = '<p class="muted">โหลดข้อมูลการแบ่งจ่ายไม่สำเร็จ</p>'; });

  return wrap;
}

async function shareSplitLink(share) {
  const text = `ช่วยจ่ายค่าทริปส่วนของ ${share.name} ${baht(share.amount)} ได้ที่ลิงก์นี้เลยครับ\n${share.pay_url}`;

  await shareToLine(text, share.pay_url, 'ส่งลิงก์แล้ว', 'คัดลอกลิงก์จ่ายแล้ว');
}

/* --------- แท็บ: จัดการ --------- */

function renderBookingManage(pane, booking) {
  const actions = [];

  if (booking.can_modify) {
    actions.push(['เปลี่ยนจุดขึ้นรถ', () => openChangePickup(booking),
      booking.modification_deadline ? 'เปลี่ยนได้ถึง ' + thaiDate(booking.modification_deadline.slice(0, 10)) : '']);
  }
  if (booking.can_reschedule) {
    actions.push(['เลื่อนไปรอบอื่น', () => openReschedule(booking),
      booking.reschedule_deadline ? 'เลื่อนได้ถึง ' + thaiDate(booking.reschedule_deadline.slice(0, 10)) : '']);
  }
  if (booking.share_token || booking.status === 'confirmed') {
    actions.push(['แชร์ลิงก์ติดตามให้ที่บ้าน', () => shareTracking(booking), 'ครอบครัวดูตำแหน่งรถได้โดยไม่ต้องล็อกอิน']);
  }
  if (['pending', 'confirmed'].includes(booking.status)) {
    actions.push(['ยกเลิกการจอง', () => openCancel(booking), 'เงื่อนไขคืนเงินเป็นไปตามนโยบายของทริป']);
  }

  if (!actions.length) {
    pane.appendChild(el(`<div class="empty">การจองนี้ไม่มีรายการที่แก้ไขได้แล้ว</div>`));
  }

  actions.forEach(([label, onClick, note]) => {
    const row = el(`<div class="pick">
      <div class="pick-body">
        <div class="pick-name">${esc(label)}</div>
        ${note ? `<div class="pick-sub">${esc(note)}</div>` : ''}
      </div>
      <span class="tag">›</span>
    </div>`);
    row.onclick = onClick;
    pane.appendChild(row);
  });

  pane.appendChild(el(`<p class="muted center" style="margin-top:16px">ต้องการความช่วยเหลือ ทักแชททีมงานในห้องแชทนี้ได้เลยครับ</p>`));
}

function openChangePickup(booking) {
  const points = booking.schedule?.pickup_points || [];
  if (!points.length) return alert('รอบนี้ไม่มีจุดขึ้นรถให้เลือก');

  const sheet = openSheet('เปลี่ยนจุดขึ้นรถ');
  points.forEach((pt) => {
    const row = el(`<label class="pick${booking.pickup_point?.id === pt.id ? ' on' : ''}">
      <input type="radio" name="newpickup" ${booking.pickup_point?.id === pt.id ? 'checked' : ''}>
      <div class="pick-body">
        <div class="pick-name">${esc(pt.pickup_location || pt.region_label || 'จุดรับ')}</div>
        <div class="pick-sub">${pt.pickup_time ? '🕖 ' + esc(pt.pickup_time) + ' · ' : ''}${baht(pt.price)}</div>
      </div>
    </label>`);
    row.querySelector('input').onchange = async () => {
      sheet.busy('กำลังเปลี่ยนจุดขึ้นรถ…');
      try {
        await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/change-pickup', {
          method: 'POST', body: { pickup_point_id: pt.id },
        });
        sheet.close();
        reloadBookingDetail();
      } catch (e) {
        sheet.error(e.message);
      }
    };
    sheet.body.appendChild(row);
  });
  sheet.body.appendChild(el(`<p class="muted">ราคาต่างกันระหว่างจุด ทีมงานจะแจ้งส่วนต่าง (ถ้ามี) ให้อีกครั้ง</p>`));
}

async function openReschedule(booking) {
  const slug = booking.schedule?.trip?.slug;
  if (!slug) return alert('ไม่พบข้อมูลทริปของการจองนี้');

  const sheet = openSheet('เลื่อนไปรอบอื่น');
  sheet.busy('กำลังโหลดรอบที่เปิดจอง…');

  let schedules;
  try {
    const res = await api('/trips/' + encodeURIComponent(slug) + '/schedules');
    schedules = (Array.isArray(res.data) ? res.data : (res.data?.data ?? []))
      .filter((s) => s.id !== booking.schedule.id && s.status === 'open'
        && (s.bookable_seats == null || s.bookable_seats > 0));
  } catch (e) {
    return sheet.error(e.message);
  }

  sheet.body.innerHTML = '';
  if (!schedules.length) {
    sheet.body.appendChild(el(`<div class="empty">ยังไม่มีรอบอื่นที่ว่างให้เลื่อนไป</div>`));
    return;
  }

  sheet.body.appendChild(el(`<p class="muted">เลื่อนได้ 1 ครั้ง · ที่นั่งของรอบใหม่ทีมงานจะจัดให้ ถ้าอยากเลือกเองแจ้งทีมงานได้ครับ</p>`));
  schedules.forEach((s) => {
    const row = el(`<div class="pick">
      <div class="pick-body">
        <div class="pick-name">${thaiDate(s.departure_date)}</div>
        <div class="pick-sub">${s.bookable_seats != null ? 'เหลือ ' + s.bookable_seats + ' ที่ · ' : ''}${baht(s.price)}</div>
      </div>
      <span class="tag">เลือก</span>
    </div>`);
    row.onclick = async () => {
      const ok = await askConfirm('ยืนยันการเลื่อนรอบ',
        `เลื่อนการจองไปวันที่ ${thaiDate(s.departure_date)} ใช่ไหมครับ`, 'เลื่อนเลย');
      if (!ok) return;
      sheet.busy('กำลังเลื่อนรอบ…');
      try {
        await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/reschedule', {
          method: 'POST', body: { target_schedule_id: s.id },
        });
        sheet.close();
        alert('เลื่อนรอบสำเร็จแล้ว');
        reloadBookingDetail();
      } catch (e) {
        sheet.error(e.message);
      }
    };
    sheet.body.appendChild(row);
  });
}

function openCancel(booking) {
  const policy = booking.schedule?.trip?.cancellation_policy;
  const sheet = openSheet('ยกเลิกการจอง');
  if (policy?.tiers?.length) {
    sheet.body.appendChild(el(`<div class="card"><div class="body">
      ${policy.tiers.map((t) => `<div class="kv"><span class="k">${esc(t.range)}</span><span class="v">${esc(t.detail)}</span></div>`).join('')}
    </div></div>`));
  }
  sheet.body.appendChild(el(`<label class="field"><span>เหตุผล (ไม่บังคับ)</span>
    <textarea id="cancelReason" rows="2" maxlength="500"></textarea></label>`));

  const confirmBtn = el(`<button class="btn">ยืนยันยกเลิกการจอง</button>`);
  confirmBtn.onclick = async () => {
    const reason = sheet.body.querySelector('#cancelReason')?.value.trim() || null;
    const ok = await askConfirm('ยืนยันยกเลิกการจอง',
      'ยกเลิกแล้วย้อนกลับไม่ได้ และการคืนเงินเป็นไปตามนโยบายด้านบนครับ', 'ยืนยันยกเลิก', 'ไม่ยกเลิก');
    if (!ok) return;
    sheet.busy('กำลังยกเลิก…');
    try {
      await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/cancel', {
        method: 'POST', body: { reason },
      });
      sheet.close();
      alert('ยกเลิกการจองแล้ว ทีมงานจะติดต่อกลับเรื่องการคืนเงินตามนโยบาย');
      reloadBookingDetail();
    } catch (e) {
      sheet.error(e.message);
    }
  };
  sheet.foot.appendChild(confirmBtn);
}

async function shareTracking(booking) {
  // token ถูกสร้างฝั่งเซิร์ฟเวอร์เมื่อเรียกครั้งแรก จึงต้องถามทุกครั้ง ไม่ใช่เดา URL เอง
  let url;
  try {
    const res = await api('/bookings/' + encodeURIComponent(booking.booking_ref) + '/tracking');
    url = res.data?.share_url || null;
  } catch (e) {
    return alert(e.message);
  }
  if (!url) return alert('ยังไม่มีลิงก์ติดตามสำหรับการจองนี้ครับ');

  const text = `ติดตามการเดินทางของเราได้ที่ลิงก์นี้ครับ\n${url}`;

  await shareToLine(text, url, 'ส่งลิงก์แล้ว', 'คัดลอกลิงก์ติดตามแล้ว');
}

/* --------------------------- แผ่นเลื่อนกลาง --------------------------- */

/** แผ่นเลื่อนขึ้นมาตรฐาน คืน { body, foot, busy, error, close } ให้ผู้เรียกใช้ต่อ */
function openSheet(title) {
  const node = el(`<div class="sheet-overlay"><div class="sheet">
    <div class="sheet-head"><strong>${esc(title)}</strong><button class="sheet-close" aria-label="ปิด">✕</button></div>
    <div class="sheet-body"></div>
    <div class="sheet-foot"></div>
  </div></div>`);
  const close = () => node.remove();
  node.onclick = (e) => { if (e.target === node) close(); };
  node.querySelector('.sheet-close').onclick = close;
  document.body.appendChild(node);

  const body = node.querySelector('.sheet-body');
  const foot = node.querySelector('.sheet-foot');
  return {
    body,
    foot,
    close,
    busy: (text) => { body.innerHTML = `<div class="loading-inline"><div class="spinner"></div></div><p class="muted center">${esc(text || '')}</p>`; },
    error: (message) => {
      const banner = el(`<div class="banner error">${esc(message)}</div>`);
      body.prepend(banner);
    },
  };
}
