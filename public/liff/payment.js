/* Luilaykhao LIFF — ชำระเงิน + การจองของฉัน
 *
 * โหลดต่อจาก app.js และใช้ helper ตัวเดียวกัน (api/el/esc/baht/render/appbar/mmss)
 *
 * กติกาที่ห้ามแตะ: ยอดที่ต้องโอนมาจากหลังบ้านเสมอ — `booking.payment_options`
 * (PaymentQuote) สำหรับตัวเลข และ `/payments/{ref}/promptpay` สำหรับ QR ที่นี่ไม่มี
 * สูตรคิดมัดจำ/ค่างวดของตัวเองสักบรรทัด เพราะเคยมีตอนที่เว็บกับแอปคิดเอง แล้ว
 * ลูกค้าโอนมาไม่เท่ากันจนสลิปไม่ผ่าน OCR
 */

const pay = {
  booking: null,
  plan: 'full',
  installmentCount: null,
  beam: null, // แถวชำระเงินจาก /payments/beam/charge
  settling: false, // ลูกค้ากดว่า "จ่ายแล้ว" กำลังรอเงินเข้า
  poll: null,
  clock: null,
  slipFile: null,
  manualFallback: false, // เกตเวย์ล่ม เลยกลับไปโอนเอง+แนบสลิป
  render: 0, // นับรอบการวาด — ผลลัพธ์ที่กลับมาช้ากว่าหน้าจอปัจจุบันต้องถูกทิ้ง
  notice: null, // ข้อความแดงเหนือช่องทางจ่าย (เกตเวย์ล่ม / QR ใช้ไม่ได้แล้ว)
  settlingSeconds: 0, // รอผลมานานแค่ไหนแล้ว — เกินเกณฑ์ต้องบอกลูกค้า
  awayAt: 0, // ออกจากหน้านี้ไปตอนไหน (ไปเปิดแอปธนาคาร)
  autoCharged: false, // ออก QR ให้อัตโนมัติแล้วหรือยัง (ต่อหนึ่งแผนการชำระ)
};

/** รอเกินกี่วินาทีถึงจะบอกว่า "ช้ากว่าปกติ" แทนที่จะปล่อยให้เดาเอง */
const SLOW_AFTER_SECONDS = 45;

/**
 * ออกจากหน้านี้ไปนานแค่ไหนถึงนับว่า "ไปจ่ายมา"
 *
 * บนมือถือ การเปิดแอปธนาคาร/กล้องเพื่อสแกน ทำให้หน้านี้ถูกซ่อนเสมอ ขากลับจึงเป็น
 * สัญญาณที่ดีที่สุดที่เรามีว่าลูกค้าเพิ่งจ่ายมา — 3 วินาทีเพื่อไม่ให้การปัดดู
 * notification แวบเดียวไปสลับหน้าจอเขา
 */
const AWAY_LONG_ENOUGH_MS = 3000;

/** แอปธนาคารที่ Beam รองรับ — กรองด้วยรายการที่เซิร์ฟเวอร์บอกมา ไม่ hardcode */
const ALL_BANK_APPS = [
  { type: 'KPLUS', label: 'K PLUS', bank: 'กสิกรไทย' },
  { type: 'SCB_EASY', label: 'SCB EASY', bank: 'ไทยพาณิชย์' },
  { type: 'KRUNGSRI_APP', label: 'Krungsri', bank: 'กรุงศรีอยุธยา' },
  { type: 'BANGKOK_BANK_APP', label: 'Bualuang', bank: 'กรุงเทพ' },
];

function bankApps() {
  const methods = pay.booking?.payment_gateway?.methods || [];
  return ALL_BANK_APPS.filter((app) => methods.includes(app.type));
}

// Beam ต้องรู้ว่าเป็นเครื่องไหนถึงจะเปิดแอปธนาคารได้ถูกตัว
function deviceType() {
  try {
    const os = liff.getOS && liff.getOS();
    if (os === 'ios') return 'IOS';
    if (os === 'android') return 'ANDROID';
  } catch (_) { /* นอกแอป LINE */ }
  return /iPhone|iPad|iPod/i.test(navigator.userAgent) ? 'IOS'
    : /Android/i.test(navigator.userAgent) ? 'ANDROID' : null;
}

function stopPaymentTimers() {
  if (pay.poll) clearInterval(pay.poll);
  if (pay.clock) clearInterval(pay.clock);
  pay.poll = null;
  pay.clock = null;
}

/* ------------------------- ตัวเลือกการชำระเงิน ------------------------- */

function payOptions() {
  return pay.booking?.payment_options || null;
}

function usesBeam() {
  return !pay.manualFallback && pay.booking?.payment_gateway?.provider === 'beam';
}

function installmentChoices() {
  return payOptions()?.installment?.options || [];
}

function installmentChoice() {
  const choices = installmentChoices();
  return choices.find((o) => o.count === pay.installmentCount) || choices[0] || null;
}

/** ยอดที่ต้องโอน "ตอนนี้" ของแผนที่เลือกอยู่ — อ่านจาก quote ไม่ได้คิดเอง */
function amountDueNow() {
  const options = payOptions();
  if (!options) return Number(pay.booking?.total_amount || 0);
  if (pay.plan === 'deposit') return Number(options.deposit?.amount || 0);
  if (pay.plan === 'installment') return Number(installmentChoice()?.per_amount || 0);
  return Number(options.full?.amount ?? pay.booking?.total_amount ?? 0);
}

/**
 * แผนที่การจองนี้ "ตั้งใจจะจ่าย" ไว้แล้ว
 *
 * ใบที่ค้างอยู่ (ออก QR มัดจำไว้แล้วยังไม่จ่าย / แอดมินสร้างแบบมัดจำให้) ต้องเปิดมา
 * ที่แผนเดิม ไม่ใช่เด้งกลับไป "ชำระเต็มจำนวน" พร้อมยอดเต็ม
 */
function restorePlan(booking) {
  const options = booking.payment_options || {};
  const saved = booking.payment_type || 'full';
  if (saved === 'deposit' && options.deposit?.available && !booking.split?.enabled) return 'deposit';
  if (saved === 'installment' && (options.installment?.options || []).length) return 'installment';
  return 'full';
}

/* ---------------------------- หน้าชำระเงิน ---------------------------- */

async function showPayment(booking) {
  stopPaymentTimers();
  pay.booking = booking;
  pay.plan = restorePlan(booking);
  pay.installmentCount = booking.installment_count
    || installmentChoices()[0]?.count
    || (booking.payment_options?.installment?.options || [])[0]?.count
    || null;
  pay.beam = null;
  pay.settling = false;
  pay.slipFile = null;
  pay.manualFallback = false;
  pay.notice = null;
  pay.autoCharged = false;
  pay.settlingSeconds = 0;
  qrCache.clear();
  renderPaymentScreen();
}

/** ดึงใบจองล่าสุดจากหลังบ้านก่อนเปิดหน้าจ่าย (ยอด/สถานะอาจเปลี่ยนไปแล้ว) */
async function openPaymentFor(ref) {
  loading('กำลังเปิดหน้าชำระเงิน…');
  try {
    const res = await api('/bookings/' + encodeURIComponent(ref));
    showPayment(res.data);
  } catch (e) {
    errorScreen(e.message, () => openPaymentFor(ref));
  }
}

function renderPaymentScreen() {
  stopPaymentTimers();
  const booking = pay.booking;
  pay.render += 1;

  if (booking.status === 'confirmed') return showBookingDone(booking);

  const node = el(`<div></div>`);
  node.appendChild(appbar('ชำระเงิน', showMyBookings));
  const content = el(`<div class="content"></div>`);

  content.appendChild(el(`<div class="banner success">จองสำเร็จ · เลขที่จอง ${esc(booking.booking_ref)}<br>ชำระเงินเพื่อยืนยันที่นั่ง</div>`));

  // แบ่งจ่ายกลุ่มมีหน้าจัดการส่วนแบ่งของตัวเองอยู่แล้ว — ที่นี่ไม่พยายามทำซ้ำ
  if (booking.split?.enabled) {
    content.appendChild(el(`<div class="banner error">การจองนี้ตั้งค่าแบ่งจ่ายกับเพื่อนไว้ กรุณาชำระผ่านแอปลุยเลเขาหรือเว็บไซต์</div>`));
    node.appendChild(content);
    return render(node);
  }

  if (booking.slip_ocr_status === 'failed') {
    content.appendChild(el(`<div class="banner warn">ได้รับสลิปแล้ว ทีมงานกำลังตรวจสอบยอดโอน — ที่นั่งยังถูกกันไว้ให้</div>`));
  }

  // เส้นตายที่ระบบจะคืนที่นั่ง (หลังบ้านส่งมาให้ ไม่ได้นับเอง)
  if (booking.expires_at) {
    content.appendChild(el(`<div class="lock-bar">⏳ ชำระภายใน <b id="payLeft">--:--</b> ไม่งั้นที่นั่งจะถูกปล่อยคืน</div>`));
  }

  // QR มาก่อนทุกอย่าง — คนที่เพิ่งกรอกข้อมูลเสร็จมาที่หน้านี้เพื่อสแกนจ่าย
  // ไม่ใช่เพื่ออ่านสรุป ตัวเลือกรูปแบบการชำระจึงอยู่ใต้ QR
  content.appendChild(el(`<div class="section-heading">ยอดที่ต้องชำระตอนนี้</div>`));
  content.appendChild(el(`<div class="card"><div class="body">
    ${planSummaryLines()}
    <div class="kv total"><span class="k">โอนตอนนี้</span><span class="v price">${baht(amountDueNow())}</span></div>
  </div></div>`));

  content.appendChild(el(`<div id="payArea"></div>`));
  content.appendChild(buildPlanChooser());
  node.appendChild(content);
  render(node);

  startPayClock();
  renderPayArea();
}

function buildPlanChooser() {
  const options = payOptions() || {};
  const wrap = el(`<div></div>`);
  const rows = [];

  rows.push({
    plan: 'full',
    name: 'ชำระเต็มจำนวน',
    sub: baht(options.full?.amount ?? pay.booking.total_amount) + ' · จบในครั้งเดียว',
  });

  if (options.deposit?.available) {
    const due = options.deposit.balance_due_at ? thaiDate(options.deposit.balance_due_at.slice(0, 10)) : null;
    rows.push({
      plan: 'deposit',
      name: 'มัดจำก่อน' + (options.deposit.percent_of_total ? ` ${options.deposit.percent_of_total}%` : ''),
      sub: `โอนตอนนี้ ${baht(options.deposit.amount)} · ที่เหลือ ${baht(options.deposit.balance)}${due ? ' ภายใน ' + due : ''}`,
    });
  }

  const choices = installmentChoices();
  if (choices.length) {
    rows.push({
      plan: 'installment',
      name: 'ผ่อนชำระ',
      sub: `งวดแรก ${baht(choices[0].per_amount)} · เลือกได้ ${choices.map((c) => c.count).join('/')} งวด`,
    });
  }

  if (rows.length < 2) return wrap;

  wrap.appendChild(el(`<div class="section-heading">เปลี่ยนรูปแบบการชำระ</div>`));
  rows.forEach((row) => {
    const opt = el(`<label class="pick ${pay.plan === row.plan ? 'on' : ''}">
      <input type="radio" name="plan" ${pay.plan === row.plan ? 'checked' : ''}>
      <div class="pick-body">
        <div class="pick-name">${esc(row.name)}</div>
        <div class="pick-sub">${esc(row.sub)}</div>
      </div>
    </label>`);
    opt.querySelector('input').onchange = () => {
      pay.plan = row.plan;
      pay.beam = null;
      pay.settling = false;
      pay.notice = null;
      pay.autoCharged = false;
      renderPaymentScreen();
    };
    wrap.appendChild(opt);
  });

  // จำนวนงวด
  if (pay.plan === 'installment' && choices.length > 1) {
    const picker = el(`<div class="chip-row"></div>`);
    choices.forEach((c) => {
      const chip = el(`<button type="button" class="chip ${c.count === pay.installmentCount ? 'on' : ''}">${c.count} งวด · ${baht(c.per_amount)}</button>`);
      chip.onclick = () => {
        pay.installmentCount = c.count;
        pay.beam = null;
        pay.autoCharged = false;
        renderPaymentScreen();
      };
      picker.appendChild(chip);
    });
    wrap.appendChild(picker);
  }

  return wrap;
}

function planSummaryLines() {
  const options = payOptions() || {};
  const total = Number(options.total_amount ?? pay.booking.total_amount ?? 0);
  let extra = '';

  if (pay.plan === 'deposit' && options.deposit?.available) {
    const due = options.deposit.balance_due_at ? thaiDate(options.deposit.balance_due_at.slice(0, 10)) : null;
    extra = `<div class="kv"><span class="k">ยอดคงเหลือ</span><span class="v">${baht(options.deposit.balance)}${due ? ' · ภายใน ' + esc(due) : ''}</span></div>`;
  }
  if (pay.plan === 'installment') {
    const choice = installmentChoice();
    if (choice) {
      const dates = (choice.due_dates || []).map((d) => thaiDate(String(d).slice(0, 10))).join(', ');
      extra = `<div class="kv"><span class="k">แผนผ่อน</span><span class="v">${choice.count} งวด งวดละ ${baht(choice.per_amount)}</span></div>`
        + (dates ? `<div class="kv"><span class="k">กำหนดแต่ละงวด</span><span class="v">${esc(dates)}</span></div>` : '');
    }
  }

  return `<div class="kv"><span class="k">ยอดรวมทั้งหมด</span><span class="v">${baht(total)}</span></div>${extra}`;
}

// นาฬิกาเรือนเดียวของหน้านี้ — เดินทั้งเส้นตายของการจองและอายุ QR พร้อมกัน
function startPayClock() {
  if (pay.clock) clearInterval(pay.clock);
  pay.clock = setInterval(tickPayClocks, 1000);
  tickPayClocks();
}

function tickPayClocks() {
  const deadline = document.getElementById('payLeft');
  const qrLeft = document.getElementById('beamLeft');
  const settleSecs = document.getElementById('settleSecs');
  if (!deadline && !qrLeft && !settleSecs) {
    if (pay.clock) clearInterval(pay.clock);
    pay.clock = null;
    return;
  }

  if (settleSecs) {
    pay.settlingSeconds += 1;
    settleSecs.textContent = pay.settlingSeconds + ' วินาที';
    if (pay.settlingSeconds >= SLOW_AFTER_SECONDS) {
      const note = document.getElementById('settleNote');
      if (note) note.textContent = 'ใช้เวลานานกว่าปกติเล็กน้อย เงินที่จ่ายแล้วไม่หายไปไหน ระบบยังตามผลให้อยู่ — ถ้ายังไม่ขึ้นภายใน 2-3 นาที ทักทีมงานได้เลยครับ';
    }
  }

  if (deadline) {
    // จ่ายแล้วกำลังรอเงินเข้า — เงินที่มาช้ายังลงได้ ห้ามขึ้นว่าหมดเวลาให้ตกใจ
    if (pay.settling) {
      deadline.textContent = 'กำลังตรวจสอบ';
    } else {
      const left = Math.floor((new Date(pay.booking.expires_at).getTime() - Date.now()) / 1000);
      deadline.textContent = left > 0 ? mmss(left) : 'หมดเวลา';
      deadline.parentElement.classList.toggle('warn', left <= 120);
    }
  }

  if (qrLeft && pay.beam?.expires_at) {
    const left = Math.floor((new Date(pay.beam.expires_at).getTime() - Date.now()) / 1000);
    if (left > 0) {
      qrLeft.textContent = 'QR หมดอายุใน ' + mmss(left);
    } else {
      // หมดอายุแล้วต้องสลับเป็นปุ่ม "สร้าง QR ใหม่" ไม่ใช่ค้าง QR ที่สแกนไม่ได้ไว้
      qrLeft.textContent = 'QR หมดอายุแล้ว';
      renderPayArea();
    }
  }
}

/* ----------------------- ช่องทางจ่าย: Beam / สลิป ---------------------- */

function renderPayArea() {
  const host = document.getElementById('payArea');
  if (!host) return;
  host.innerHTML = '';
  if (usesBeam()) renderBeamArea(host);
  else renderManualArea(host);
}

// ข้อความเตือนของช่องทางจ่าย — วาดใหม่ทุกครั้งที่พื้นที่นั้นถูกล้าง ไม่งั้นข้อความ
// จะหายไปพร้อมกับของเดิมตอนสลับไปทางสำรอง
function paintPayNotice(host) {
  if (pay.notice) host.prepend(el(`<div class="banner error">${esc(pay.notice)}</div>`));
}

/* --- Beam: เกตเวย์ออก QR แล้วบอกเราเองว่าเงินเข้า ไม่ต้องแนบสลิป --- */

function beamExpired() {
  // ระหว่างรอผลไม่ถือว่าหมดอายุ — เงินที่จ่ายวินาทีสุดท้ายก็ยังเข้าได้ ถ้าขึ้นว่า
  // "หมดอายุ" ตอนนั้น ลูกค้าที่จ่ายไปแล้วจะกดจ่ายซ้ำอีกใบ
  if (!pay.beam?.expires_at || pay.settling) return false;
  return new Date(pay.beam.expires_at).getTime() - Date.now() <= 0;
}

function renderBeamArea(host) {
  paintPayNotice(host);

  if (pay.settling) {
    const panel = el(`<div class="settle">
      <div class="spinner"></div>
      <p class="settle-title">กำลังตรวจสอบการชำระเงิน</p>
      <p class="muted center" id="settleNote">ไม่ต้องโอนซ้ำ — ธนาคารกำลังยืนยันให้ ระบบจะอัปเดตให้เองภายในไม่กี่วินาที</p>
      <div class="muted" id="settleSecs"></div>
    </div>`);
    host.appendChild(panel);
    // เดาผิดได้ (ลูกค้าออกไปทำอย่างอื่นแล้วกลับมา) — ต้องมีทางกลับไปหน้า QR
    const back = el(`<button class="btn secondary" style="margin-top:12px">ยังไม่ได้จ่าย · กลับไปที่ QR</button>`);
    back.onclick = resumeWaiting;
    host.appendChild(back);
    return;
  }

  if (!pay.beam || beamExpired()) {
    if (beamExpired()) {
      host.appendChild(el(`<div class="banner warn">QR หมดอายุแล้ว กรุณาสร้างใหม่</div>`));
    }
    const btn = el(`<button class="btn">${pay.beam ? 'สร้าง QR ใหม่' : 'สร้าง QR พร้อมเพย์'} · ${baht(amountDueNow())}</button>`);
    btn.onclick = () => startBeamCharge(btn, 'QR_PROMPT_PAY');
    host.appendChild(btn);
    host.appendChild(el(`<p class="muted center" style="margin-top:8px">สแกนจ่ายผ่านแอปธนาคาร ระบบยืนยันให้อัตโนมัติ ไม่ต้องแนบสลิป</p>`));
    appendBankApps(host);
    appendSlipFallback(host);
    // QR คือสิ่งที่ลูกค้ามาทำ ไม่ใช่สิ่งที่ต้องกดขอก่อน — ออกให้เลยรอบแรก
    // (เหมือนหน้าเว็บ) ล้มเหลวจะตกไปทางสลิปเอง จึงไม่วนซ้ำ
    if (!pay.autoCharged && !pay.beam) {
      pay.autoCharged = true;
      startBeamCharge(btn, 'QR_PROMPT_PAY');
    }
    return;
  }

  if (pay.beam.qr_image_base64) {
    host.appendChild(el(`<div class="qr-wrap">
      <img src="data:image/png;base64,${esc(pay.beam.qr_image_base64)}" alt="QR พร้อมเพย์">
      <div class="qr-amount">${baht(pay.beam.amount ?? amountDueNow())}</div>
      ${pay.beam.expires_at ? '<div class="muted" id="beamLeft"></div>' : ''}
    </div>`));
  } else if (pay.beam.redirect_url) {
    const open = el(`<button class="btn">เปิดแอปธนาคารเพื่อจ่าย</button>`);
    open.onclick = () => {
      markSettling();
      liff.openWindow({ url: pay.beam.redirect_url, external: true });
    };
    host.appendChild(open);
  }

  const done = el(`<button class="btn secondary" style="margin-top:12px">จ่ายเงินแล้ว · ตรวจสอบให้ฉัน</button>`);
  done.onclick = markSettling;
  host.appendChild(done);

  appendBankApps(host);
  appendSlipFallback(host);

  startBeamPolling();
  startPayClock();
}

function appendBankApps(host) {
  const apps = bankApps();
  if (!apps.length) return;
  host.appendChild(el(`<div class="section-heading">หรือจ่ายผ่านแอปธนาคาร</div>`));
  const row = el(`<div class="chip-row"></div>`);
  apps.forEach((app) => {
    const chip = el(`<button type="button" class="chip">${esc(app.label)} · ${esc(app.bank)}</button>`);
    chip.onclick = () => startBeamCharge(chip, app.type);
    row.appendChild(chip);
  });
  host.appendChild(row);
}

// ทางสำรองที่ต้องมีเสมอ — เกตเวย์ล่มแล้วลูกค้าต้องยังจ่ายได้ ไม่ใช่รอ
function appendSlipFallback(host) {
  const link = el(`<button class="btn secondary" style="margin-top:10px">โอนเองแล้วแนบสลิปแทน</button>`);
  link.onclick = () => {
    pay.manualFallback = true;
    pay.notice = null;
    stopPaymentTimers();
    renderPayArea();
  };
  host.appendChild(link);
}

/** ลูกค้าบอกว่าจ่ายแล้ว (หรือเพิ่งกลับมาจากแอปธนาคาร) — เข้าโหมดรอผล */
function markSettling() {
  if (pay.settling || !pay.beam) return;
  pay.settling = true;
  pay.settlingSeconds = 0;
  renderPayArea();
  startBeamPolling();
  startPayClock();
  pollBeamStatus();
}

/** เดาผิด — พากลับไปหน้า QR (หมดอายุไปแล้วจะได้ปุ่มสร้างใหม่เอง) */
function resumeWaiting() {
  if (!pay.settling) return;
  pay.settling = false;
  pay.settlingSeconds = 0;
  renderPayArea();
  startBeamPolling();
  startPayClock();
}

/**
 * ออกไปนานพอที่จะเป็นการไปจ่ายเงินมา — สลับเป็นโหมดรอผลให้เอง
 *
 * เหมือนหน้าเว็บ: คนที่สแกนด้วยมือถืออีกเครื่อง (ไม่เคยออกจากหน้านี้) ก็ยังจบด้วย
 * webhook ตามปกติ แค่ไม่เห็นจอ "กำลังตรวจสอบ" ระหว่างทาง
 */
function onPaymentVisibilityChange() {
  if (!pay.beam || pay.settling || beamExpired()) return;
  if (!document.getElementById('payArea')) return;

  if (document.hidden) {
    pay.awayAt = Date.now();
    return;
  }
  const wasAway = pay.awayAt && Date.now() - pay.awayAt >= AWAY_LONG_ENOUGH_MS;
  pay.awayAt = 0;
  if (wasAway) markSettling();
}

document.addEventListener('visibilitychange', onPaymentVisibilityChange);

async function startBeamCharge(btn, methodType) {
  btn.disabled = true;
  const label = btn.textContent;
  btn.textContent = 'กำลังสร้างรายการ…';
  try {
    const body = {
      booking_ref: pay.booking.booking_ref,
      purpose: pay.plan,
      payment_method_type: methodType,
      installment_count: pay.plan === 'installment' ? pay.installmentCount : null,
    };
    // แอปธนาคารต้องรู้ว่าเครื่องไหน ถึงจะเด้งเข้าแอปได้ถูก
    if (methodType !== 'QR_PROMPT_PAY') body.device_type = deviceType();

    const res = await api('/payments/beam/charge', { method: 'POST', body });
    pay.beam = res.data;
    pay.notice = null;
    renderPayArea();

    // ตอบมาเป็นลิงก์แอปธนาคาร ไม่ใช่ QR — พาไปเลยแล้วรอผลตอนกลับมา
    if (pay.beam?.redirect_url && methodType !== 'QR_PROMPT_PAY') {
      markSettling();
      liff.openWindow({ url: pay.beam.redirect_url, external: true });
    }
  } catch (e) {
    // เกตเวย์มีปัญหา ไม่ใช่ลูกค้า — เปิดทางโอนเอง+แนบสลิปแทน ไม่ใช่ให้ตัน
    pay.manualFallback = true;
    pay.notice = e.message;
    btn.disabled = false;
    btn.textContent = label;
    renderPayArea();
  }
}

function startBeamPolling() {
  if (pay.poll) clearInterval(pay.poll);
  // นั่งรออยู่หน้าจอ = ถามถี่ขึ้นและถาม Beam ตรงๆ แทนที่จะรอ webhook
  pay.poll = setInterval(pollBeamStatus, pay.settling ? 2000 : 3000);
}

async function pollBeamStatus() {
  if (!pay.beam?.payment_id) return;
  // ออกจากหน้านี้ไปแล้ว
  if (!document.getElementById('payArea')) {
    stopPaymentTimers();
    return;
  }
  // QR หมดอายุแล้วก็ไม่ต้องถามต่อ ต้องออกใบใหม่อยู่ดี — ยกเว้นตอนรอผลการจ่าย
  if (beamExpired()) {
    stopPaymentTimers();
    renderPayArea();
    return;
  }
  try {
    const res = await api('/payments/beam/' + pay.beam.payment_id + (pay.settling ? '?sync=1' : ''));
    const status = res.data?.status;
    // "จ่ายแล้ว" ต้องดูจากสถานะของใบชำระเงินใบนี้เท่านั้น ไม่ใช่สถานะการจอง
    if (status === 'succeeded') {
      stopPaymentTimers();
      pay.settling = false;
      return refreshBookingThen(showBookingDone);
    }
    if (status === 'failed' || status === 'expired') {
      stopPaymentTimers();
      pay.beam = null;
      pay.settling = false;
      pay.notice = 'รายการชำระเงินนี้ใช้ไม่ได้แล้ว กรุณาสร้าง QR ใหม่';
      renderPayArea();
    }
  } catch (_) { /* เน็ตสะดุด — รอบหน้าค่อยถามใหม่ */ }
}

/* --- โอนเอง + แนบสลิป (วิธีเดิม ยังเป็นทางสำรองเสมอ) --- */

// QR ต่อแผนของการจองที่กำลังเปิดอยู่ — ล้างทุกครั้งที่เปลี่ยนใบจอง
const qrCache = new Map();

async function renderManualArea(host) {
  const token = pay.render;
  host.appendChild(el(`<div class="loading-inline"><div class="spinner"></div></div>`));

  // QR ของแผนเดิมใช้ซ้ำได้ — ยอดไม่เปลี่ยนจนกว่าจะสลับรูปแบบการชำระ
  const key = pay.plan + ':' + (pay.installmentCount || '');
  let qr = qrCache.get(key);
  try {
    if (!qr) {
      const query = '?purpose=' + pay.plan
        + (pay.plan === 'installment' && pay.installmentCount ? '&installment_count=' + pay.installmentCount : '');
      qr = (await api('/payments/' + encodeURIComponent(pay.booking.booking_ref) + '/promptpay' + query)).data;
      qrCache.set(key, qr);
    }
  } catch (e) {
    if (token !== pay.render) return;
    host.innerHTML = '';
    host.appendChild(el(`<div class="banner error">${esc(e.message)}</div>`));
    return;
  }

  // ผู้ใช้กดเปลี่ยนแผน (หรือออกจากหน้านี้) ระหว่างที่รอ QR — ผลลัพธ์เก่าทิ้งไป
  if (token !== pay.render) return;

  host.innerHTML = '';
  paintPayNotice(host);
  host.appendChild(el(`<div class="qr-wrap">
    <img src="${esc(qr.qr_data_uri)}" alt="QR พร้อมเพย์">
    <div class="qr-amount">${baht(qr.amount)}</div>
    <div class="muted">พร้อมเพย์ ${esc(qr.promptpay_id)} · ${esc(qr.merchant_name || '')}</div>
  </div>`));

  const bank = el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">ธนาคาร</span><span class="v">${esc(qr.bank_name || '-')}</span></div>
    <div class="kv"><span class="k">เลขบัญชี</span><span class="v" id="bankAcc">${esc(qr.bank_account || '-')}</span></div>
    <div class="kv"><span class="k">ชื่อบัญชี</span><span class="v">${esc(qr.bank_holder || '-')}</span></div>
  </div></div>`);
  host.appendChild(bank);

  const copy = el(`<button class="btn secondary" style="margin-top:10px">คัดลอกเลขบัญชี</button>`);
  copy.onclick = async () => {
    try {
      await navigator.clipboard.writeText(String(qr.bank_account || ''));
      copy.textContent = 'คัดลอกแล้ว ✓';
      setTimeout(() => { copy.textContent = 'คัดลอกเลขบัญชี'; }, 1500);
    } catch (_) { copy.textContent = 'คัดลอกไม่สำเร็จ'; }
  };
  host.appendChild(copy);

  host.appendChild(el(`<div class="section-heading">แนบสลิปการโอน</div>`));
  const file = el(`<input type="file" accept="image/*" id="slip">`);
  const preview = el(`<div id="slipPreview"></div>`);
  const submit = el(`<button class="btn" disabled style="margin-top:12px">ยืนยันการชำระเงิน</button>`);
  const banner = el(`<div style="margin-top:10px"></div>`);

  file.onchange = () => {
    pay.slipFile = file.files && file.files[0] ? file.files[0] : null;
    submit.disabled = !pay.slipFile;
    preview.innerHTML = '';
    if (pay.slipFile) {
      const img = el(`<img class="slip-preview" alt="สลิปที่แนบ">`);
      img.src = URL.createObjectURL(pay.slipFile);
      preview.appendChild(img);
    }
  };
  submit.onclick = () => submitSlip(submit, banner, qr.amount);

  host.appendChild(file);
  host.appendChild(preview);
  host.appendChild(banner);
  host.appendChild(submit);
  host.appendChild(el(`<p class="muted center" style="margin-top:8px">โอนตามยอดให้ตรงแล้วแนบสลิป ระบบจะตรวจยอดให้อัตโนมัติ</p>`));
}

async function submitSlip(btn, banner, amount) {
  if (!pay.slipFile) return;
  btn.disabled = true;
  btn.textContent = 'กำลังส่งสลิป…';
  banner.innerHTML = '';

  const form = new FormData();
  form.append('booking_ref', pay.booking.booking_ref);
  form.append('payment_type', pay.plan);
  form.append('payment_method', 'promptpay');
  form.append('amount', String(amount));
  if (pay.plan === 'installment' && pay.installmentCount) {
    form.append('installment_count', String(pay.installmentCount));
  }
  form.append('slip_image', pay.slipFile);

  try {
    const res = await api('/payments/charge', { method: 'POST', body: form });
    stopPaymentTimers();
    const booking = res.data?.booking || pay.booking;
    if (res.data?.status === 'pending_review') return showSlipUnderReview(booking, res.message);
    return showBookingDone(booking, res.message);
  } catch (e) {
    banner.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
    btn.disabled = false;
    btn.textContent = 'ยืนยันการชำระเงิน';
  }
}

/* ---------------------------- ปลายทางของหน้า --------------------------- */

async function refreshBookingThen(next) {
  try {
    const res = await api('/bookings/' + encodeURIComponent(pay.booking.booking_ref));
    next(res.data);
  } catch (_) {
    next(pay.booking);
  }
}

function bookingHeadlines(booking) {
  const schedule = booking.schedule || {};
  const seats = (booking.seats || []).map((s) => s.seat_id || s.seat_number || s.label).filter(Boolean);
  return `
    <div class="kv"><span class="k">ทริป</span><span class="v">${esc(schedule.trip?.title || '-')}</span></div>
    <div class="kv"><span class="k">วันเดินทาง</span><span class="v">${thaiDate(schedule.departure_date)}</span></div>
    ${seats.length ? `<div class="kv"><span class="k">ที่นั่ง</span><span class="v">${esc(seats.join(', '))}</span></div>` : ''}
    <div class="kv"><span class="k">เลขที่จอง</span><span class="v">${esc(booking.booking_ref)}</span></div>`;
}

function showBookingDone(booking, message) {
  stopPaymentTimers();

  // จ่ายเสร็จแล้วค่อยถามเรื่องสมุดผู้ร่วมเดินทาง — ตอนจองเสร็จมี QR รออยู่
  if (pendingSaveTravellers) {
    const ref = pendingSaveTravellers;
    pendingSaveTravellers = null;
    setTimeout(() => offerToSaveTravellers(ref), 400);
  }

  const paid = Number(booking.paid_amount || 0);
  const outstanding = Math.max(0, Number(booking.total_amount || 0) - paid);

  const node = el(`<div></div>`);
  node.appendChild(appbar('ชำระเงินสำเร็จ'));
  const content = el(`<div class="content"></div>`);
  content.appendChild(el(`<div class="banner success">${esc(message || 'ชำระเงินสำเร็จ ที่นั่งของคุณได้รับการยืนยันแล้ว')}</div>`));
  content.appendChild(el(`<div class="card"><div class="body">
    ${bookingHeadlines(booking)}
    <div class="kv"><span class="k">ชำระแล้ว</span><span class="v">${baht(paid)}</span></div>
    ${outstanding > 0 ? `<div class="kv total"><span class="k">ยอดคงเหลือ</span><span class="v price">${baht(outstanding)}</span></div>` : ''}
  </div></div>`));

  if (outstanding > 0) {
    content.appendChild(el(`<p class="muted center" style="margin-top:8px">ยอดคงเหลือชำระได้จากลิงก์ที่ทีมงานส่งให้ทางอีเมล หรือในแอปลุยเลเขา</p>`));
  }

  const mine = el(`<button class="btn" style="margin-top:16px">ดูการจองของฉัน</button>`);
  mine.onclick = showMyBookings;
  content.appendChild(mine);

  const back = el(`<button class="btn secondary" style="margin-top:10px">กลับไปหน้าทริป</button>`);
  back.onclick = showTrips;
  content.appendChild(back);

  node.appendChild(content);
  render(node);
}

function showSlipUnderReview(booking, message) {
  stopPaymentTimers();
  const node = el(`<div></div>`);
  node.appendChild(appbar('กำลังตรวจสอบยอด'));
  const content = el(`<div class="content"></div>`);
  content.appendChild(el(`<div class="banner warn">${esc(message || 'ได้รับสลิปแล้ว — อยู่ระหว่างตรวจสอบยอดโอน')}</div>`));
  content.appendChild(el(`<div class="card"><div class="body">${bookingHeadlines(booking)}</div></div>`));
  content.appendChild(el(`<p class="muted center" style="margin-top:10px">ที่นั่งยังถูกกันไว้ให้ ไม่ต้องโอนซ้ำ ทีมงานจะยืนยันให้เร็วที่สุด</p>`));

  const mine = el(`<button class="btn" style="margin-top:16px">ดูการจองของฉัน</button>`);
  mine.onclick = showMyBookings;
  content.appendChild(mine);

  node.appendChild(content);
  render(node);
}


/* ============ ชำระรายการที่ค้างบนใบจองที่ยืนยันแล้ว ============ */

/*
 * ยอดคงเหลือ / ค่างวดที่ 2 เป็นต้นไป / ส่วนแบ่งกลุ่ม
 *
 * ต่างจากการชำระครั้งแรกสามอย่าง: ยอดถูกตรึงไว้แล้ว (ไม่ผ่าน PaymentQuote),
 * ไม่มีให้เลือกรูปแบบการชำระ และ endpoint ฝั่งสลิปเป็นคนละตัวของแต่ละแบบ
 * ส่วนทาง Beam ใช้ตัวเดียวกันหมด ต่างแค่ purpose
 */

const outstanding = {
  booking: null,
  item: null, // { purpose, amount, label, installmentNo?, shareId? }
  beam: null,
  settling: false,
  poll: null,
  clock: null,
  slipFile: null,
  manualFallback: false,
  notice: null,
  render: 0,
};

function showOutstandingPayment(booking, item) {
  stopPaymentTimers();
  stopOutstandingTimers();
  Object.assign(outstanding, {
    booking, item, beam: null, settling: false, slipFile: null,
    manualFallback: false, notice: null, autoCharged: false,
  });
  renderOutstandingScreen();
}

function stopOutstandingTimers() {
  if (outstanding.poll) clearInterval(outstanding.poll);
  if (outstanding.clock) clearInterval(outstanding.clock);
  outstanding.poll = null;
  outstanding.clock = null;
}

function outstandingUsesBeam() {
  return !outstanding.manualFallback
    && outstanding.booking?.payment_gateway?.provider === 'beam';
}

function renderOutstandingScreen() {
  stopOutstandingTimers();
  outstanding.render += 1;
  const { booking, item } = outstanding;

  const node = el(`<div></div>`);
  node.appendChild(appbar(item.label, () => showBookingDetail(booking.booking_ref, 'money')));
  const content = el(`<div class="content"></div>`);

  content.appendChild(el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">ทริป</span><span class="v">${esc(booking.schedule?.trip?.title || '-')}</span></div>
    <div class="kv"><span class="k">เลขที่จอง</span><span class="v">${esc(booking.booking_ref)}</span></div>
    <div class="kv"><span class="k">รายการ</span><span class="v">${esc(item.label)}</span></div>
    <div class="kv total"><span class="k">โอนตอนนี้</span><span class="v price">${baht(item.amount)}</span></div>
  </div></div>`));

  content.appendChild(el(`<div id="outArea"></div>`));
  node.appendChild(content);
  render(node);

  renderOutstandingArea();
}

function renderOutstandingArea() {
  const host = document.getElementById('outArea');
  if (!host) return;
  host.innerHTML = '';
  if (outstanding.notice) host.prepend(el(`<div class="banner error">${esc(outstanding.notice)}</div>`));
  if (outstandingUsesBeam()) renderOutstandingBeam(host);
  else renderOutstandingManual(host);
}

/* --- Beam --- */

function renderOutstandingBeam(host) {
  if (outstanding.settling) {
    host.appendChild(el(`<div class="settle">
      <div class="spinner"></div>
      <p class="settle-title">กำลังตรวจสอบการชำระเงิน</p>
      <p class="muted center">ไม่ต้องโอนซ้ำ — ธนาคารกำลังยืนยันให้ ระบบจะอัปเดตให้เองภายในไม่กี่วินาที</p>
    </div>`));
    const back = el(`<button class="btn secondary" style="margin-top:12px">ยังไม่ได้จ่าย · กลับไปที่ QR</button>`);
    back.onclick = () => { outstanding.settling = false; renderOutstandingArea(); };
    host.appendChild(back);
    return;
  }

  if (!outstanding.beam) {
    const btn = el(`<button class="btn">สร้าง QR พร้อมเพย์ · ${baht(outstanding.item.amount)}</button>`);
    btn.onclick = () => startOutstandingCharge(btn);
    host.appendChild(btn);
    host.appendChild(outstandingSlipFallback());
    // QR คือสิ่งที่ลูกค้ามาทำ ออกให้เลยรอบแรกเหมือนหน้าชำระครั้งแรก
    if (!outstanding.autoCharged) {
      outstanding.autoCharged = true;
      startOutstandingCharge(btn);
    }
    return;
  }

  if (outstanding.beam.qr_image_base64) {
    host.appendChild(el(`<div class="qr-wrap">
      <img src="data:image/png;base64,${esc(outstanding.beam.qr_image_base64)}" alt="QR พร้อมเพย์">
      <div class="qr-amount">${baht(outstanding.beam.amount ?? outstanding.item.amount)}</div>
    </div>`));
  } else if (outstanding.beam.redirect_url) {
    const open = el(`<button class="btn">เปิดแอปธนาคารเพื่อจ่าย</button>`);
    open.onclick = () => {
      outstanding.settling = true;
      liff.openWindow({ url: outstanding.beam.redirect_url, external: true });
      renderOutstandingArea();
    };
    host.appendChild(open);
  }

  const done = el(`<button class="btn secondary" style="margin-top:12px">จ่ายเงินแล้ว · ตรวจสอบให้ฉัน</button>`);
  done.onclick = () => {
    outstanding.settling = true;
    renderOutstandingArea();
    startOutstandingPolling();
  };
  host.appendChild(done);
  host.appendChild(outstandingSlipFallback());
  startOutstandingPolling();
}

function outstandingSlipFallback() {
  const link = el(`<button class="btn secondary" style="margin-top:10px">โอนเองแล้วแนบสลิปแทน</button>`);
  link.onclick = () => {
    outstanding.manualFallback = true;
    outstanding.notice = null;
    stopOutstandingTimers();
    renderOutstandingArea();
  };
  return link;
}

async function startOutstandingCharge(btn) {
  btn.disabled = true;
  btn.textContent = 'กำลังสร้าง QR…';
  const { booking, item } = outstanding;
  try {
    const res = await api('/payments/beam/charge', {
      method: 'POST',
      body: {
        booking_ref: booking.booking_ref,
        purpose: item.purpose,
        payment_method_type: 'QR_PROMPT_PAY',
        // งวดที่ 2+ กับส่วนแบ่งกลุ่มชี้แถวปลายทางเฉพาะเจาะจง ไม่ใช่ทั้งใบจอง
        installment_id: item.installmentId ?? null,
        share_id: item.shareId ?? null,
      },
    });
    outstanding.beam = res.data;
    outstanding.notice = null;
    renderOutstandingArea();
  } catch (e) {
    outstanding.manualFallback = true;
    outstanding.notice = e.message;
    renderOutstandingArea();
  }
}

function startOutstandingPolling() {
  if (outstanding.poll) clearInterval(outstanding.poll);
  outstanding.poll = setInterval(pollOutstandingStatus, outstanding.settling ? 2000 : 3000);
}

async function pollOutstandingStatus() {
  if (!outstanding.beam?.payment_id) return;
  if (!document.getElementById('outArea')) return stopOutstandingTimers();
  try {
    const res = await api('/payments/beam/' + outstanding.beam.payment_id + (outstanding.settling ? '?sync=1' : ''));
    const status = res.data?.status;
    if (status === 'succeeded') {
      stopOutstandingTimers();
      alert('ชำระเงินสำเร็จแล้ว ขอบคุณครับ');
      detail.booking = null;
      showBookingDetail(outstanding.booking.booking_ref, 'money');
    } else if (status === 'failed' || status === 'expired') {
      stopOutstandingTimers();
      outstanding.beam = null;
      outstanding.settling = false;
      outstanding.autoCharged = false;
      outstanding.notice = 'รายการชำระเงินนี้ใช้ไม่ได้แล้ว กรุณาสร้าง QR ใหม่';
      renderOutstandingArea();
    }
  } catch (_) { /* เน็ตสะดุด รอบหน้าค่อยถาม */ }
}

/* --- โอนเอง + แนบสลิป --- */

async function renderOutstandingManual(host) {
  const token = outstanding.render;
  host.appendChild(el(`<div class="loading-inline"><div class="spinner"></div></div>`));

  const { booking, item } = outstanding;
  let qr;
  try {
    const params = new URLSearchParams({ purpose: item.purpose });
    if (item.installmentNo) params.set('installment_no', item.installmentNo);
    if (item.shareId) params.set('share_id', item.shareId);
    qr = (await api('/payments/' + encodeURIComponent(booking.booking_ref) + '/promptpay?' + params.toString())).data;
  } catch (e) {
    if (token !== outstanding.render) return;
    host.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
    return;
  }
  if (token !== outstanding.render) return;

  host.innerHTML = '';
  if (outstanding.notice) host.appendChild(el(`<div class="banner error">${esc(outstanding.notice)}</div>`));
  host.appendChild(el(`<div class="qr-wrap">
    <img src="${esc(qr.qr_data_uri)}" alt="QR พร้อมเพย์">
    <div class="qr-amount">${baht(qr.amount)}</div>
    <div class="muted">พร้อมเพย์ ${esc(qr.promptpay_id)}</div>
  </div>`));
  host.appendChild(el(`<div class="card"><div class="body">
    <div class="kv"><span class="k">ธนาคาร</span><span class="v">${esc(qr.bank_name || '-')}</span></div>
    <div class="kv"><span class="k">เลขบัญชี</span><span class="v">${esc(qr.bank_account || '-')}</span></div>
    <div class="kv"><span class="k">ชื่อบัญชี</span><span class="v">${esc(qr.bank_holder || '-')}</span></div>
  </div></div>`));

  host.appendChild(el(`<div class="section-heading">แนบสลิปการโอน</div>`));
  const file = el(`<input type="file" accept="image/*">`);
  const preview = el(`<div></div>`);
  const submit = el(`<button class="btn" disabled style="margin-top:12px">ยืนยันการชำระเงิน</button>`);
  const banner = el(`<div style="margin-top:10px"></div>`);

  file.onchange = () => {
    outstanding.slipFile = file.files?.[0] || null;
    submit.disabled = !outstanding.slipFile;
    preview.innerHTML = '';
    if (outstanding.slipFile) {
      const img = el(`<img class="slip-preview" alt="สลิปที่แนบ">`);
      img.src = URL.createObjectURL(outstanding.slipFile);
      preview.appendChild(img);
    }
  };
  submit.onclick = () => submitOutstandingSlip(submit, banner);

  host.appendChild(file);
  host.appendChild(preview);
  host.appendChild(banner);
  host.appendChild(submit);
}

async function submitOutstandingSlip(btn, banner) {
  if (!outstanding.slipFile) return;
  btn.disabled = true;
  btn.textContent = 'กำลังส่งสลิป…';
  banner.innerHTML = '';

  const { booking, item } = outstanding;
  const form = new FormData();
  form.append('booking_ref', booking.booking_ref);
  form.append('payment_method', 'promptpay');
  form.append('slip_image', outstanding.slipFile);

  // ปลายทางของสลิปเป็นคนละ endpoint ของแต่ละแบบ (คนละ service ที่ต้องอัปเดต)
  let path = '/payments/charge-balance';
  if (item.purpose === 'installment_due') {
    path = '/payments/charge-installment';
    form.append('installment_no', String(item.installmentNo));
  } else if (item.purpose === 'split_share') {
    path = `/bookings/${encodeURIComponent(booking.booking_ref)}/split/shares/${item.shareId}/pay`;
  }

  try {
    await api(path, { method: 'POST', body: form });
    stopOutstandingTimers();
    alert('ส่งสลิปเรียบร้อย ทีมงานจะตรวจสอบและยืนยันให้ครับ');
    detail.booking = null;
    showBookingDetail(booking.booking_ref, 'money');
  } catch (e) {
    banner.innerHTML = `<div class="banner error">${esc(e.message)}</div>`;
    btn.disabled = false;
    btn.textContent = 'ยืนยันการชำระเงิน';
  }
}
