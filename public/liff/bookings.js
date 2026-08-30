/* Luilaykhao LIFF — การจองของฉัน + รายละเอียดการจอง
 *
 * โหลดต่อจาก app.js/booking.js/payment.js และใช้ helper ตัวเดียวกัน
 *
 * หน้านี้คือทุกอย่างที่ลูกค้าทำกับใบจองได้หลังจองแล้ว: ดู QR เช็คอิน, จ่ายส่วนที่
 * เหลือ/ค่างวด/ส่วนแบ่งกลุ่ม, เปลี่ยนจุดรับ, เลื่อนรอบ, ยกเลิก, ใบเสร็จ, แนบเอกสาร
 * เพิ่ม และแชร์ลิงก์ติดตามรถ — เดิมต้องออกจาก LINE ไปทำในแอปหรือเว็บทั้งหมด
 */

/* -------------------------- การจองของฉัน -------------------------- */

let myBookingScope = 'upcoming';

async function showMyBookings(scope) {
  stopPaymentTimers();
  if (scope) myBookingScope = scope;
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
  node.appendChild(appbar('การจองของฉัน', showTrips));
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
  </div></div>`));

  // สิ่งที่ต้องทำต่อ (ถ้ามี) อยู่บนสุดเสมอ — เงินค้างคือเรื่องที่ต้องเห็นก่อนอย่างอื่น
  const todo = outstandingBlock(booking);
  if (todo) content.appendChild(todo);

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

/* --------- แท็บ: ทริป --------- */

function renderBookingTrip(pane, booking) {
  const schedule = booking.schedule || {};
  const trip = schedule.trip || {};
  const pickup = booking.pickup_point;

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
  try {
    if (liff.isApiAvailable && liff.isApiAvailable('shareTargetPicker')) {
      const res = await liff.shareTargetPicker([{ type: 'text', text }]);
      if (res) return alert('ส่งลิงก์แล้ว');
    }
  } catch (_) { /* ยกเลิก หรือใช้ไม่ได้ */ }
  try {
    await navigator.clipboard.writeText(share.pay_url);
    alert('คัดลอกลิงก์จ่ายแล้ว');
  } catch (_) {
    alert(share.pay_url);
  }
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
  try {
    if (liff.isApiAvailable && liff.isApiAvailable('shareTargetPicker')) {
      const res = await liff.shareTargetPicker([{ type: 'text', text }]);
      if (res) return alert('ส่งลิงก์แล้ว');
    }
  } catch (_) { /* ยกเลิก */ }
  try {
    await navigator.clipboard.writeText(url);
    alert('คัดลอกลิงก์ติดตามแล้ว');
  } catch (_) {
    alert(url);
  }
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
