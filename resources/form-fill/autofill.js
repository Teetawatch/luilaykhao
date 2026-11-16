// กรอกแบบฟอร์มลงทะเบียนอัตโนมัติจาก data.json ที่ดาวน์โหลดจากหลังบ้าน
// (ตารางที่นั่งว่าง → เอกสาร → ข้อมูลกรอกฟอร์ม) วิธีใช้อยู่ใน README.txt
//
// ใช้ได้ทุกเว็บ ไม่ใช่แค่ Google Form: เปิดหน้าลงทะเบียนในหน้าต่าง Chrome ของสคริปต์
// แล้วพิมพ์เลขคน — สคริปต์กรอกลงหน้าที่เปิดอยู่ตรงนั้นเลย ไม่เปิดหน้าใหม่
//
// หา data.json แบบนี้ (โฟลเดอร์ที่รัน / โฟลเดอร์ของ exe / โฟลเดอร์ของสคริปต์):
//   - มี data.json อยู่ตรงนั้น  → ใช้ไฟล์นั้น (ชั้นบนสุดของ ZIP = ทั้งรอบ, ในโฟลเดอร์คน = คนเดียว)
//   - ไม่มี                     → รวม data.json จากทุกโฟลเดอร์ย่อย (ให้คนช่วยหลายคนในครั้งเดียว)

// เริ่มกรอกฟอร์ม.bat ติดตั้ง playwright-core ให้เอง · เครื่องที่ติดตั้ง playwright ตัวเต็มไว้แล้วก็ใช้ได้
const { chromium } = (() => {
  try {
    return require('playwright-core');
  } catch {
    return require('playwright');
  }
})();
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

// ====== ตั้งค่า ======
// พยายามอัปโหลดรูปบัตร/แบบฟอร์มอุทยานให้เอง (Google Form ต้องล็อกอิน Google ใน Chrome ก่อน)
// ถ้าอัปโหลดไม่ได้ สคริปต์จะบอกให้ลากวางเองในหน้านั้น
const AUTO_UPLOAD = true;
const DEBUG_PORT = 9222;
// 127.0.0.1 ไม่ใช่ localhost — บางเครื่องแปล localhost เป็น IPv6 (::1) แต่ Chrome ฟังแค่ IPv4
const CHROME_URL = `http://127.0.0.1:${DEBUG_PORT}`;
// โปรไฟล์ Chrome แยกของสคริปต์ ล็อกอินครั้งเดียวแล้วจำไว้ — Chrome ไม่ยอมเปิดพอร์ต
// 9222 กับโปรไฟล์หลักของเครื่อง · ที่เดียวกับคำสั่งเปิด Chrome เองใน README รุ่นก่อน
const PROFILE_DIR =
  process.env.AUTOFILL_PROFILE_DIR ||
  (process.platform === 'win32' ? 'C:\\chrome-autofill' : path.join(os.homedir(), '.chrome-autofill'));
const LAST_FORM_FILE = path.join(PROFILE_DIR, 'last-form-url.txt');

// คำในหัวข้อคำถาม — ลองทีละคำตามลำดับ เจอคำไหนก่อนใช้คำนั้น (คำเจาะจงไว้หน้า คำกว้างไว้ท้าย)
const UPLOAD_KEYWORDS = {
  idCard: ['บัตรประชาชน', 'บัตรประจำตัว', 'บัตรประจําตัว', 'สำเนาบัตร', 'สําเนาบัตร', 'id card'],
  permit: ['แบบฟอร์มอุทยาน', 'แบบคำขอ', 'แบบคําขอ', 'แบบฟอร์ม', 'คำขอ', 'คําขอ', 'อุทยาน'],
};
const PHONE_KEYWORDS = ['เบอร์โทร', 'เบอร์มือถือ', 'โทรศัพท์', 'มือถือ', 'เบอร์ติดต่อ', 'หมายเลขโทร', 'เบอร์', 'phone', 'mobile', 'telephone'];
const BIRTH_KEYWORDS = [
  'วันเกิด', 'วันเดือนปีเกิด', 'วัน เดือน ปี เกิด', 'วัน/เดือน/ปีเกิด', 'วัน/เดือน/ปี เกิด', 'ว/ด/ป เกิด',
  'ว/ด/ปเกิด', 'ปีเกิด', 'เกิด', 'date of birth', 'birth date', 'birthday', 'birth', 'dob',
];
const BIRTH_EXCLUDE = ['สถานที่', 'จังหวัด', 'place'];

const TH_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
const TH_MON = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const EN_MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

// คำเดียวกันที่ตัวเลือกบนเว็บอาจเขียนต่างกัน
const ALIASES = [
  ['นาย', 'mr', 'mister'],
  ['นาง', 'mrs'],
  ['นางสาว', 'น.ส.', 'นส', 'miss', 'ms'],
  ['เด็กชาย', 'ด.ช.', 'ดช', 'master'],
  ['เด็กหญิง', 'ด.ญ.', 'ดญ'],
  ['ชาย', 'ผู้ชาย', 'male', 'm'],
  ['หญิง', 'ผู้หญิง', 'female', 'f'],
  ['ไทย', 'thai', 'thailand', 'สัญชาติไทย'],
];

// ====== อ่าน data.json ======

function readJson(file) {
  // Notepad บางรุ่นเซฟเป็น UTF-8 มี BOM ซึ่ง JSON.parse ไม่รับ
  const text = fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, '');
  const rows = JSON.parse(text);
  if (!Array.isArray(rows)) throw new Error(`${file} ไม่ใช่รายชื่อ (ต้องขึ้นต้นด้วย [ )`);
  return rows;
}

function candidateDirs() {
  const dirs = [process.cwd(), path.dirname(process.execPath), __dirname];
  return [...new Set(dirs.map((d) => path.resolve(d)))].filter((d) => {
    try {
      return fs.statSync(d).isDirectory();
    } catch {
      return false;
    }
  });
}

/** @returns {{ source: string, people: Array<object & { _dir: string }> } | null} */
function loadPeople() {
  for (const dir of candidateDirs()) {
    const own = path.join(dir, 'data.json');
    if (fs.existsSync(own)) {
      return { source: own, people: readJson(own).map((p) => ({ ...p, _dir: dir })) };
    }
  }

  // ไม่มี data.json ตรงนี้ — รวมจากโฟลเดอร์ย่อยชั้นเดียว เรียงตามชื่อโฟลเดอร์ (01-, 02-, ...)
  for (const dir of candidateDirs()) {
    let entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
      continue;
    }
    const subs = entries
      .filter((e) => e.isDirectory() && fs.existsSync(path.join(dir, e.name, 'data.json')))
      .map((e) => e.name)
      .sort((a, b) => a.localeCompare(b, 'th', { numeric: true }));
    if (subs.length === 0) continue;

    const people = subs.flatMap((name) => {
      const sub = path.join(dir, name);
      return readJson(path.join(sub, 'data.json')).map((p) => ({ ...p, _dir: sub }));
    });
    return { source: `${subs.length} โฟลเดอร์ใน ${dir}`, people };
  }

  return null;
}

// ====== อ่านหน้าเว็บ ======

/**
 * รันในหน้าเว็บ (ห้ามอ้างตัวแปรข้างนอกฟังก์ชันนี้) — หาทุกข้อที่ตอบได้ในหน้า แล้วติดป้าย
 * data-llk-* ให้ฝั่งสคริปต์ชี้ช่องได้ถูกตัว คืน [{ id, title, kind, upload, done, options }]
 *
 *   Google Form  กล่องคำถามละกล่อง หัวข้อจาก role=heading (แบบเดิมที่ใช้มาแล้ว)
 *   เว็บอื่น      ทีละช่อง (radio/checkbox ชื่อเดียวกันรวมเป็นข้อเดียว) หัวข้อหาตามลำดับ:
 *                aria-labelledby / aria-label → <label> → <legend> → ข้อความในกรอบของช่อง
 *                → ข้อความก่อนหน้าช่อง (ฟอร์มแบบตาราง) → placeholder → name
 */
function scanForm() {
  const Q = 'data-llk-q';
  const C = 'data-llk-c';
  const O = 'data-llk-o';
  const DONE = 'data-llk-done';
  for (const a of [Q, C, O]) document.querySelectorAll(`[${a}]`).forEach((el) => el.removeAttribute(a));

  const clean = (s) => String(s || '').replace(/[*＊]/g, '').replace(/\s+/g, ' ').trim();
  const out = [];
  let seq = 0;

  if (/docs\.google\.com\/forms\//.test(location.href)) {
    for (const item of document.querySelectorAll('div[role="listitem"]')) {
      const heading = item.querySelector('[role="heading"]');
      if (!heading) continue;
      const answerable = !!item.querySelector(
        'input:not([type="hidden"]), textarea, [role="radio"], [role="checkbox"], [role="listbox"]'
      );
      const upload = !answerable && /เพิ่มไฟล์|add file|อัปโหลด|upload/i.test(item.innerText);
      if (!answerable && !upload) continue;
      const id = String(seq++);
      item.setAttribute(Q, id);
      out.push({ id, title: clean(heading.innerText), kind: 'gform', upload, done: item.hasAttribute(DONE), options: [] });
    }
    return out;
  }

  const SEL = 'input, textarea, select, [role="radio"], [role="checkbox"], [role="combobox"], [role="listbox"]';
  const SKIP_TYPES = new Set(['hidden', 'submit', 'button', 'image', 'reset', 'password', 'search', 'color', 'range']);
  const SKIP_TEXT = 'select, option, textarea, script, style, noscript, button';
  const typeOf = (el) => (el.tagName === 'INPUT' ? (el.getAttribute('type') || 'text').toLowerCase() : '');
  const shown = (el) => {
    if (!el) return false;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 || r.height > 0;
  };
  const labelOf = (el) => (el.labels && el.labels[0]) || el.closest('label');

  let controls = [...document.querySelectorAll(SEL)].filter((el) => {
    if (el.disabled || el.getAttribute('aria-disabled') === 'true') return false;
    const t = typeOf(el);
    if (el.tagName === 'INPUT' && SKIP_TYPES.has(t)) return false;
    // ปุ่มอัปโหลดมักซ่อน input ตัวจริงไว้หลังปุ่มสวย ๆ
    if (t === 'file') return true;
    // radio/checkbox ที่แต่งหน้าตาเองซ่อน input ไว้ แต่ป้ายยังเห็น
    if (t === 'radio' || t === 'checkbox') return shown(el) || shown(labelOf(el));
    // ช่องเลือกที่ทำด้วย select2/choices ซ่อน select ตัวจริงไว้ข้างกล่องที่เห็น
    if (el.tagName === 'SELECT') return shown(el) || shown(el.parentElement);
    if (el.getAttribute('role') === 'listbox' && el.closest('[role="combobox"]')) return false;
    return shown(el);
  });
  // กล่องหน้าตาของ select2 — ใช้ select ตัวจริงข้างกันแทน ไม่นับซ้ำเป็นอีกข้อ
  const selects = controls.filter((el) => el.tagName === 'SELECT');
  controls = controls.filter((el) => {
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)) return true;
    if (!['combobox', 'listbox'].includes(el.getAttribute('role'))) return true;
    let p = el.parentElement;
    for (let i = 0; i < 4 && p; i++, p = p.parentElement) {
      if (selects.some((s) => p.contains(s))) return false;
    }
    return true;
  });
  const set = new Set(controls);

  // รวม radio ชื่อเดียวกัน / checkbox ชื่อเดียวกันเป็นข้อเดียว
  const groups = [];
  const byKey = new Map();
  const formNo = (el) => (el.form ? [...document.forms].indexOf(el.form) : -1);
  for (const el of controls) {
    const t = typeOf(el);
    const role = el.tagName === 'INPUT' ? '' : el.getAttribute('role') || '';
    let kind;
    let key = null;
    if (t === 'radio' || role === 'radio') {
      kind = 'radio';
      key = t === 'radio' ? (el.name ? `r${formNo(el)}:${el.name}` : el) : el.closest('[role="radiogroup"]') || el.parentElement;
    } else if (t === 'checkbox' || role === 'checkbox') {
      kind = 'checkbox';
      key = t === 'checkbox' && el.name ? `c${formNo(el)}:${el.name}` : el.closest('[role="group"], fieldset') || el;
    } else if (t === 'file') kind = 'file';
    else if (t === 'date') kind = 'date';
    else if (el.tagName === 'SELECT') kind = 'select';
    else if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') kind = 'text';
    else kind = 'combobox';

    if (key === null) {
      groups.push({ kind, els: [el] });
    } else if (byKey.has(key)) {
      byKey.get(key).els.push(el);
    } else {
      const g = { kind, els: [el] };
      byKey.set(key, g);
      groups.push(g);
    }
  }

  const ownText = (root) => {
    if (!root) return '';
    if (root.nodeType === 3) return clean(root.textContent);
    const parts = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = walker.nextNode())) {
      const p = n.parentElement;
      if (!p) continue;
      const skip = p.closest(SKIP_TEXT);
      if (skip && root.contains(skip)) continue;
      // ข้อความเตือนที่ซ่อนอยู่ ("กรุณากรอก...") ไม่ใช่หัวข้อ
      if (p.checkVisibility && !p.checkVisibility()) continue;
      parts.push(n.textContent);
    }
    return clean(parts.join(' '));
  };
  const byIds = (ids) =>
    clean(
      String(ids || '')
        .split(/\s+/)
        .filter(Boolean)
        .map((id) => {
          const e = document.getElementById(id);
          return e ? e.innerText || e.textContent : '';
        })
        .join(' ')
    );
  const nextText = (el) => {
    let s = '';
    for (let n = el.nextSibling; n && s.length < 60; n = n.nextSibling) {
      if (n.nodeType === 1 && (n.matches(SEL) || n.querySelector(SEL) || n.tagName === 'BR')) break;
      s += ' ' + (n.nodeType === 3 ? n.textContent : n.innerText || '');
    }
    return clean(s);
  };
  const optionLabel = (el) =>
    clean(
      byIds(el.getAttribute('aria-labelledby')) ||
        el.getAttribute('aria-label') ||
        (el.labels && el.labels[0] ? ownText(el.labels[0]) : '') ||
        (el.tagName !== 'INPUT' ? ownText(el) : '') ||
        nextText(el) ||
        (el.closest('label') ? ownText(el.closest('label')) : '') ||
        el.value ||
        ''
    );

  // กรอบของช่อง = ขยายขึ้นไปเรื่อย ๆ ตราบที่ยังไม่มีช่องของข้ออื่นปน
  const common = (els) => {
    let a = els[0];
    while (a && !els.every((e) => a.contains(e))) a = a.parentElement;
    return a || document.body;
  };
  const wrapperOf = (els) => {
    let w = els.length === 1 ? els[0] : common(els);
    for (let i = 0; i < 6; i++) {
      const p = w.parentElement;
      if (!p || p === document.body || p.tagName === 'FORM') break;
      if ([...p.querySelectorAll(SEL)].some((c) => set.has(c) && !els.includes(c))) break;
      if ((p.textContent || '').length > 400) break;
      w = p;
    }
    return w;
  };
  // ข้อความหลังช่องสุดท้ายในก้อนนี้ — null = ก้อนนี้จบด้วยช่องของข้ออื่น (หยุดหา)
  const textAfterLastControl = (el) => {
    if (set.has(el)) return null;
    const inner = [...el.querySelectorAll(SEL)].filter((c) => set.has(c));
    if (!inner.length) return ownText(el);
    const range = document.createRange();
    range.setStartAfter(inner[inner.length - 1]);
    range.setEnd(el, el.childNodes.length);
    return clean(range.cloneContents().textContent) || null;
  };
  const precedingText = (w) => {
    let node = w;
    for (let up = 0; up < 4 && node && node !== document.body; up++) {
      for (let s = node.previousSibling; s; s = s.previousSibling) {
        if (s.nodeType === 3) {
          const t = clean(s.textContent);
          if (t) return t.slice(-100);
          continue;
        }
        if (s.nodeType !== 1) continue;
        const t = textAfterLastControl(s);
        if (t === null) return '';
        if (t) return t.slice(-100);
      }
      node = node.parentElement;
    }
    return '';
  };

  const titleOf = (g, w) => {
    const el = g.els[0];
    const grouped = g.kind === 'radio' || g.kind === 'checkbox';
    const holder = grouped ? el.closest('[role="radiogroup"], [role="group"]') : el;
    let t = holder ? byIds(holder.getAttribute('aria-labelledby')) || clean(holder.getAttribute('aria-label')) : '';
    if (!t && !grouped && el.labels && el.labels.length) t = ownText(el.labels[0]);
    if (!t && grouped) {
      const fs = el.closest('fieldset');
      const legend = fs && fs.querySelector('legend');
      if (legend && [...fs.querySelectorAll(SEL)].every((c) => !set.has(c) || g.els.includes(c))) t = ownText(legend);
    }
    if (!t) {
      let text = ownText(w);
      for (const o of g.options) if (o) text = text.split(o).join(' ');
      t = clean(text);
    }
    if (!t) t = precedingText(w);
    if (!t && !grouped) t = clean(el.getAttribute('placeholder') || el.getAttribute('title'));
    if (!t) t = clean(String(el.getAttribute('name') || el.id || '').replace(/[_\-[\]]+/g, ' '));
    return t;
  };

  for (const g of groups) {
    const id = String(seq++);
    if (g.kind === 'radio' || g.kind === 'checkbox') g.options = g.els.map(optionLabel);
    else if (g.kind === 'select') g.options = [...g.els[0].options].map((o) => clean(o.text));
    else g.options = [];
    g.els.forEach((el, i) => {
      el.setAttribute(C, id);
      el.setAttribute(O, `${id}:${i}`);
    });
    out.push({
      id,
      title: titleOf(g, wrapperOf(g.els)).slice(0, 200),
      kind: g.kind,
      upload: g.kind === 'file',
      done: g.els.some((el) => el.hasAttribute(DONE)),
      options: g.options.slice(0, 80),
    });
  }
  return out;
}

/** ทุกข้อในทุกเฟรมของหน้า (บางเว็บวางฟอร์มไว้ใน iframe) */
async function scanAll(page) {
  const out = [];
  for (const frame of page.frames()) {
    let found;
    try {
      found = await frame.evaluate(scanForm);
    } catch {
      continue; // เฟรมที่กำลังโหลด/ปิดไปแล้ว
    }
    for (const q of found) out.push({ ...q, frame, page });
  }
  return out;
}

const asList = (v) => (Array.isArray(v) ? v : v ? [v] : []);

// เทียบหัวข้อคำถามแบบไม่สนตัวพิมพ์เล็ก/ใหญ่ (Email/email) และให้ "ํา" (นิคหิต+สระอา) เท่ากับ "ำ"
// — สองแบบนี้หน้าตาเหมือนกันแต่คนละตัวอักษร ฟอร์มที่พิมพ์จากบางเครื่องเป็นแบบแรก
const norm = (s) => String(s).replace(/\u0E4D\u0E32/g, '\u0E33').toLowerCase();
const titleHas = (title, k) => title.includes(norm(k));

/**
 * เลือกข้อที่ตรงที่สุดจากหัวข้อคำถาม
 *   keywords  ลองทีละคำตามลำดับ — คำแรกที่เจอชนะ ในคำเดียวกันเอาหัวข้อสั้นที่สุด (= ตรงที่สุด)
 *   require   ต้องมีทุกคำ (เช่น เบอร์ฉุกเฉิน = คำว่าเบอร์ + "ฉุกเฉิน")
 *   exclude   ห้ามมีคำเหล่านี้
 *   upload    true = เฉพาะข้ออัปโหลดไฟล์ · false = เฉพาะข้อที่มีช่องให้ตอบ
 * ข้ามข้อที่กรอกไปแล้วในรอบนี้ — คนถัดไปในหน้าเดียวกันจึงลงช่องชุดถัดไป
 */
function pick(questions, keywords, { require = [], exclude = [], upload = false } = {}) {
  for (const k of asList(keywords)) {
    let best = null;
    for (const q of questions) {
      if (q.done || !q.title) continue;
      if (upload ? !q.upload : q.upload) continue;
      const title = norm(q.title);
      if (!titleHas(title, k)) continue;
      if (!asList(require).every((w) => titleHas(title, w))) continue;
      if (asList(exclude).some((w) => titleHas(title, w))) continue;
      if (!best || title.length < norm(best.title).length) best = q;
    }
    if (best) return best;
  }
  return null;
}

async function markDone(q) {
  await q.frame
    .evaluate((id) => {
      document
        .querySelectorAll(`[data-llk-q="${id}"], [data-llk-c="${id}"]`)
        .forEach((el) => el.setAttribute('data-llk-done', '1'));
    }, q.id)
    .catch(() => {});
}

/** เริ่มคนใหม่บนหน้าเดิม (เช่น ส่งแล้วกด "ลงทะเบียนอีกคน") — ลืมว่าช่องไหนเคยกรอก */
async function clearDone(page) {
  for (const frame of page.frames()) {
    await frame
      .evaluate(() => document.querySelectorAll('[data-llk-done]').forEach((el) => el.removeAttribute('data-llk-done')))
      .catch(() => {});
  }
}

// ====== เทียบตัวเลือก ======

const squash = (s) =>
  norm(String(s || ''))
    .normalize('NFC')
    .replace(/[\s.()（）\-_/:]/g, '');
const isThai = (s) => /[\u0E00-\u0E7F]/.test(s);

function variantsOf(value) {
  const v = squash(value);
  const out = new Set([v]);
  for (const group of ALIASES) {
    if (group.map(squash).includes(v)) group.forEach((g) => out.add(squash(g)));
  }
  return [...out].filter(Boolean);
}

/** ตำแหน่งตัวเลือกที่ตรงกับค่า: ตรงทั้งคำก่อน แล้วค่อยตัวเลือกที่สั้นที่สุดที่มีคำนั้นอยู่ ("ไทย (Thai)") */
function optionIndex(labels, value) {
  const want = variantsOf(value);
  const list = labels.map((l) => squash(l));
  const exact = list.findIndex((l) => l && want.includes(l));
  if (exact !== -1) return exact;
  let best = -1;
  list.forEach((l, i) => {
    if (!l) return;
    // คำอังกฤษสั้น ๆ (mr, m, f) ห้ามหาแบบ "มีอยู่ในคำ" — mr อยู่ใน mrs
    if (!want.some((w) => (w.length >= 3 || isThai(w)) && l.includes(w))) return;
    if (best === -1 || l.length < list[best].length) best = i;
  });
  return best;
}

function monthIndex(text) {
  const t = squash(text);
  if (!t) return -1;
  for (let i = 0; i < 12; i++) {
    if ([TH_MONTHS[i], TH_MON[i], EN_MONTHS[i], EN_MONTHS[i].slice(0, 3)].map(squash).includes(t)) return i;
  }
  return -1;
}

// ====== ใส่ค่าลงช่อง (เว็บทั่วไป) ======

const ctl = (q) => q.frame.locator(`[data-llk-c="${q.id}"]`).first();
const optionAt = (q, i) => q.frame.locator(`[data-llk-o="${q.id}:${i}"]`);

/** พิมพ์ลงช่อง — ช่องที่ล็อกให้เลือกจากปฏิทินเท่านั้น (readonly) ใส่ค่าตรง ๆ แล้วบอกหน้าเว็บว่าเปลี่ยนแล้ว */
async function setValue(loc, value) {
  try {
    await loc.fill(value, { timeout: 4000 });
    return true;
  } catch {
    try {
      await loc.evaluate((el, v) => {
        const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
        Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, v);
        for (const type of ['input', 'change', 'blur']) el.dispatchEvent(new Event(type, { bubbles: true }));
      }, value);
      return true;
    } catch {
      return false;
    }
  }
}

/** ช่องพิมพ์ที่มีรายการให้เลือกเด้งขึ้นมา (เช่น จังหวัด) — เลือกตัวที่ตรงให้ */
async function pickSuggestion(q, value) {
  const el = ctl(q);
  const isAuto = await el
    .evaluate((e) => e.getAttribute('role') === 'combobox' || e.hasAttribute('aria-autocomplete') || e.hasAttribute('list'))
    .catch(() => false);
  if (!isAuto) return;
  const options = q.frame.locator('[role="option"]:visible');
  await options.first().waitFor({ state: 'visible', timeout: 1500 }).catch(() => {});
  const texts = await options.allInnerTexts().catch(() => []);
  const i = optionIndex(texts, value);
  if (i !== -1) await options.nth(i).click({ timeout: 3000 }).catch(() => {});
}

async function selectIndex(loc, index) {
  if (await loc.isVisible().catch(() => false)) {
    try {
      await loc.selectOption({ index }, { timeout: 3000 });
      return true;
    } catch {
      // ตกไปใส่ค่าตรง ๆ ด้านล่าง
    }
  }
  try {
    await loc.evaluate((el, i) => {
      el.selectedIndex = i;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, index);
    return true;
  } catch {
    return false;
  }
}

async function chooseSelect(loc, value) {
  const options = await loc.evaluate((el) => [...el.options].map((o) => ({ text: o.text, value: o.value })));
  let i = optionIndex(
    options.map((o) => o.text),
    value
  );
  if (i === -1) i = optionIndex(options.map((o) => o.value), value);
  return i !== -1 && selectIndex(loc, i);
}

async function chooseOption(q, value) {
  const i = optionIndex(q.options, value);
  if (i === -1) return false;
  const loc = optionAt(q, i);
  const checked = await loc
    .evaluate((el) => el.checked === true || el.getAttribute('aria-checked') === 'true')
    .catch(() => false);
  if (checked) return true; // รันซ้ำหน้าเดิมไม่ติ๊กออก
  if (await loc.isVisible().catch(() => false)) {
    try {
      await loc.click({ timeout: 3000 });
      return true;
    } catch {
      // มีอะไรบังอยู่ — คลิกป้ายแทน
    }
  }
  try {
    await loc.evaluate((el) => ((el.labels && el.labels[0]) || el.closest('label') || el).click());
    return true;
  } catch {
    return false;
  }
}

/** ช่องเลือกที่ทำเอง (ไม่ใช่ <select>) — เปิดแล้วคลิกตัวเลือก */
async function chooseCombo(q, value) {
  await ctl(q).click({ timeout: 3000 });
  const options = q.frame.locator('[role="option"]:visible');
  await options.first().waitFor({ state: 'visible', timeout: 3000 }).catch(() => {});
  const texts = await options.allInnerTexts().catch(() => []);
  const i = optionIndex(texts, value);
  if (i === -1) {
    await q.page.keyboard.press('Escape').catch(() => {});
    return false;
  }
  await options.nth(i).click({ timeout: 3000 });
  return true;
}

async function answerGeneric(q, value) {
  switch (q.kind) {
    case 'text':
      if (!(await setValue(ctl(q), value))) return false;
      await pickSuggestion(q, value);
      return true;
    case 'select':
      return chooseSelect(ctl(q), value);
    case 'radio':
    case 'checkbox':
      return chooseOption(q, value);
    case 'combobox':
      return chooseCombo(q, value);
    case 'date':
      return /^\d{4}-\d{2}-\d{2}$/.test(value) && setValue(ctl(q), value);
    default:
      return false;
  }
}

// ====== Google Form (แบบเดิม) ======

const TEXT_INPUT = 'input[type="text"], input[type="email"], input[type="number"], input[type="tel"], input[type="url"], textarea';

async function fillText(box, value) {
  const input = box.locator(TEXT_INPUT).first();
  if ((await input.count()) === 0) return false;
  await input.fill(value);
  return true;
}

function escapeRegex(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** ตัวเลือกแบบวงกลม / ช่องติ๊ก / dropdown — รันซ้ำแท็บเดิมไม่ติ๊กออก */
async function pickChoice(page, box, value) {
  for (const role of ['radio', 'checkbox']) {
    const option = box.getByRole(role, { name: value, exact: true }).first();
    if ((await option.count()) > 0) {
      if ((await option.getAttribute('aria-checked')) !== 'true') await option.click();
      return true;
    }
  }

  const dropdown = box.locator('[role="listbox"]').first();
  if ((await dropdown.count()) > 0) {
    await dropdown.click();
    const option = page
      .locator('[role="option"]:visible')
      .filter({ hasText: new RegExp(`^\\s*${escapeRegex(value)}\\s*$`) })
      .first();
    try {
      await option.waitFor({ state: 'visible', timeout: 3000 });
      await option.click();
      return true;
    } catch {
      await page.keyboard.press('Escape');
    }
  }

  return false;
}

const gbox = (q) => q.frame.locator(`[data-llk-q="${q.id}"]`);

/** text = ช่องพิมพ์ก่อน แล้วค่อยตัวเลือก · choice = กลับกัน — บนฟอร์มเป็นอีกแบบก็ลองให้ */
async function answer(q, value, prefer) {
  let ok = false;
  try {
    if (q.kind === 'gform') {
      const box = gbox(q);
      ok =
        prefer === 'text'
          ? (await fillText(box, value)) || (await pickChoice(q.page, box, value))
          : (await pickChoice(q.page, box, value)) || (await fillText(box, value));
    } else {
      ok = await answerGeneric(q, value);
    }
  } catch {
    ok = false;
  }
  if (ok) await markDone(q);
  return ok;
}

// ====== วันเกิด ======

const pad = (n) => String(n).padStart(2, '0');

/** ปี พ.ศ. หรือ ค.ศ. — ดูจากคำใบ้บนช่องก่อน ไม่มีคำใบ้: หัวข้อภาษาไทยใช้ พ.ศ. */
function yearStyle(hint, title) {
  if (/พ\.?\s?ศ|ปปปป|b\.?e\./i.test(hint)) return { be: true, sure: true };
  if (/ค\.?\s?ศ|yyyy|a\.?d\./i.test(hint)) return { be: false, sure: true };
  return { be: isThai(title), sure: false };
}

/**
 * รันในหน้าเว็บ — วันเกิดที่แยกเป็น 3 ช่อง (วัน/เดือน/ปี) ข้างช่องที่หาเจอ ติดป้าย data-llk-p
 * คืน null เมื่อไม่ใช่แบบนั้น (เจอช่องอื่นปนหรือจำนวนไม่ใช่ 3)
 */
function markDateParts(el) {
  const SEL = 'select, input[type="text"], input[type="number"], input[type="tel"], input:not([type])';
  document.querySelectorAll('[data-llk-p]').forEach((e) => e.removeAttribute('data-llk-p'));
  let a = el.parentElement;
  for (let up = 0; up < 3 && a; up++, a = a.parentElement) {
    const found = [...a.querySelectorAll(SEL)].filter((e) => !e.disabled);
    if (found.length === 1) continue;
    if (found.length !== 3) return null;
    const parts = found.map((e) => ({
      select: e.tagName === 'SELECT',
      options: e.tagName === 'SELECT' ? [...e.options].map((o) => ({ text: o.text.trim(), value: o.value })) : null,
      hint: [e.name, e.id, e.getAttribute('aria-label'), e.getAttribute('placeholder'), e.labels && e.labels[0] && e.labels[0].innerText]
        .filter(Boolean)
        .join(' ')
        .toLowerCase(),
      maxLength: e.maxLength,
    }));
    // ช่องยาวปกติสามช่องในแถวเดียวกัน (วันเกิด/อายุ/เบอร์) ไม่ใช่วันที่แยกช่อง
    const short = (p) => p.select || (p.maxLength > 0 && p.maxLength <= 4) || /day|month|year|วัน|เดือน|ปี|dd|mm|yy/.test(p.hint);
    if (!parts.every(short)) return null;
    found.forEach((e, i) => e.setAttribute('data-llk-p', String(i)));
    return parts;
  }
  return null;
}

function partRole(p) {
  if (p.options) {
    const texts = p.options.map((o) => o.text);
    if (texts.some((t) => /^\d{4}$/.test(t))) return 'y';
    if (texts.some((t) => monthIndex(t) !== -1)) return 'm';
    const nums = texts.map((t) => parseInt(t, 10)).filter((n) => !Number.isNaN(n));
    if (nums.length >= 28 && Math.max(...nums) === 31) return 'd';
    if (nums.length >= 12 && Math.max(...nums) === 12) return 'm';
  }
  if (/year|ปี|yyyy|yy|พ\.ศ/.test(p.hint)) return 'y';
  if (/month|เดือน|mm/.test(p.hint)) return 'm';
  if (/day|วัน|dd/.test(p.hint)) return 'd';
  return null;
}

async function fillDateParts(q, parts, [y, m, d], title) {
  let roles = parts.map(partRole);
  for (const r of ['d', 'm', 'y']) {
    if (!roles.includes(r)) roles[roles.indexOf(null)] = r;
  }
  if (new Set(roles).size !== 3) roles = ['d', 'm', 'y'];

  const style = yearStyle(parts.map((p) => p.hint).join(' '), title);
  for (const [i, part] of parts.entries()) {
    const loc = q.frame.locator(`[data-llk-p="${i}"]`);
    const role = roles[i];
    if (part.select) {
      const idx = part.options.findIndex(({ text, value }) => {
        const n = parseInt(value, 10) || parseInt(text, 10);
        if (role === 'd') return n === d;
        if (role === 'm') return monthIndex(text) === m - 1 || n === m;
        return n === y || n === y + 543;
      });
      if (idx === -1 || !(await selectIndex(loc, idx))) return false;
    } else {
      const text = role === 'd' ? pad(d) : role === 'm' ? pad(m) : String(style.be ? y + 543 : y);
      if (!(await setValue(loc, text))) return false;
    }
  }
  return true;
}

/** Google Form: ช่องวันที่ของ Chrome หรือ 3 ช่อง วัน/เดือน/ปี (ค.ศ.) */
async function fillGoogleBirthDate(box, birthDate) {
  const dateInput = box.locator('input[type="date"]').first();
  if ((await dateInput.count()) > 0) {
    await dateInput.fill(birthDate); // input date ของ Chrome รับ YYYY-MM-DD เสมอ ไม่ว่าจะแสดงแบบไหน
    return null;
  }

  // บางเครื่องแสดงช่องวัน/เดือน/ปีแยกกัน 3 ช่อง — อ่านป้ายกำกับของแต่ละช่องเพื่อไม่สลับลำดับ
  const parts = box.locator('input[type="text"], input[type="number"]');
  if ((await parts.count()) >= 3) {
    const [y, m, d] = birthDate.split('-');
    const labels = await parts.evaluateAll((els) =>
      els.map((el) => `${el.getAttribute('aria-label') || ''} ${el.getAttribute('placeholder') || ''}`.toLowerCase())
    );
    const find = (re, fallback) => {
      const i = labels.findIndex((l) => re.test(l));
      return i === -1 ? fallback : i;
    };
    await parts.nth(find(/วัน|day|dd/, 0)).fill(d);
    await parts.nth(find(/เดือน|month|mm/, 1)).fill(m);
    await parts.nth(find(/ปี|year|yyyy/, 2)).fill(y);
    return null;
  }

  if (await fillText(box, birthDate)) return null;
  return 'คำถามวันเกิดไม่มีช่องให้กรอก';
}

/** @returns {Promise<string|null>} คำเตือน หรือ null = กรอกแล้ว */
async function fillBirthDate(page, birthDate, warnings) {
  const q = pick(await scanAll(page), BIRTH_KEYWORDS, { exclude: BIRTH_EXCLUDE });
  if (!q) return 'ไม่พบคำถามวันเกิด';

  if (q.kind === 'gform') {
    const w = await fillGoogleBirthDate(gbox(q), birthDate);
    if (!w) await markDone(q);
    return w;
  }

  const [y, m, d] = birthDate.split('-').map(Number);
  const el = ctl(q);

  if (q.kind === 'date') {
    if (!(await setValue(el, birthDate))) return 'ใส่วันเกิดไม่ได้ — กรอกเอง';
    await markDone(q);
    return null;
  }

  const parts = await el.evaluate(markDateParts).catch(() => null);
  if (parts) {
    if (!(await fillDateParts(q, parts, [y, m, d], q.title))) return 'วันเกิดแยกช่อง วัน/เดือน/ปี แต่เลือกให้ไม่ได้ — กรอกเอง';
    await q.frame
      .evaluate(() => document.querySelectorAll('[data-llk-p]').forEach((e) => e.setAttribute('data-llk-done', '1')))
      .catch(() => {});
    await markDone(q);
    return null;
  }

  if (q.kind !== 'text') return 'วันเกิดเป็นช่องเลือกแบบที่สคริปต์ไม่รู้จัก — เลือกเอง';

  const placeholder = (await el.getAttribute('placeholder').catch(() => '')) || '';
  const hint = `${q.title} ${placeholder}`.toLowerCase();
  let text;
  if (/yyyy-mm-dd|ปปปป-ดด-วว/.test(hint)) {
    text = birthDate;
  } else {
    const style = yearStyle(hint, q.title);
    text = `${pad(d)}/${pad(m)}/${style.be ? y + 543 : y}`;
    if (!style.sure) warnings.push(`วันเกิดกรอกเป็น ${text} (${style.be ? 'พ.ศ.' : 'ค.ศ.'}) — ตรวจว่าตรงกับรูปแบบที่เว็บต้องการ`);
  }
  if (!(await setValue(el, text))) return 'ใส่วันเกิดไม่ได้ — กรอกเอง';
  await markDone(q);
  return null;
}

// ====== อัปโหลดไฟล์ ======

/**
 * Google Form: อัปโหลดผ่านหน้าต่างเลือกไฟล์ของ Google (ใน iframe) — Google เปลี่ยนหน้าตาได้ทุกเมื่อ
 * จึงทำแบบพยายาม ไม่สำเร็จภายใน 20 วินาทีก็ปล่อยให้ลากวางเอง
 */
async function uploadGoogle(page, box, filePath) {
  // รันซ้ำแท็บเดิม — มีไฟล์ชื่อนี้อยู่แล้วไม่อัปซ้ำ
  if ((await box.innerText()).includes(path.basename(filePath))) return null;

  const addButton = box.getByRole('button').filter({ hasText: /เพิ่มไฟล์|Add file/i }).first();
  if ((await addButton.count()) === 0) return 'ไม่พบปุ่ม "เพิ่มไฟล์" (ล็อกอิน Google หรือยัง?)';

  await addButton.click();

  const picker = page.frameLocator('iframe[src*="docs.google.com/picker"]');
  const browse = picker.getByRole('button', { name: /เรียกดู|Browse/i }).first();

  try {
    const [chooser] = await Promise.all([
      page.waitForEvent('filechooser', { timeout: 20000 }),
      browse.click({ timeout: 20000 }),
    ]);
    await chooser.setFiles(filePath);
    // รอให้หน้าต่างเลือกไฟล์ปิดเอง = อัปโหลดเสร็จ
    await page.locator('iframe[src*="docs.google.com/picker"]').waitFor({ state: 'detached', timeout: 60000 });
    return null;
  } catch {
    await page.keyboard.press('Escape').catch(() => {});
    return 'อัปโหลดอัตโนมัติไม่สำเร็จ — ลากไฟล์วางเองในหน้านี้';
  }
}

async function uploadFile(q, filePath) {
  if (q.kind === 'gform') return uploadGoogle(q.page, gbox(q), filePath);
  try {
    await ctl(q).setInputFiles(filePath, { timeout: 10000 });
    return null;
  } catch {
    return 'แนบไฟล์อัตโนมัติไม่สำเร็จ — แนบเองในหน้านี้';
  }
}

// ====== กรอกหนึ่งคน ======

async function fillPerson(page, data) {
  const warnings = [];
  const has = (key) => Object.prototype.hasOwnProperty.call(data, key);
  const find = async (keywords, rule) => pick(await scanAll(page), keywords, rule);

  // A. ช่องข้อความ
  for (const item of data.textFields || []) {
    if (!item.value) {
      warnings.push(`ไม่มี "${item.keyword}" ในระบบ — กรอกเอง`);
      continue;
    }
    const q = await find(item.keywords || item.keyword, { require: item.require, exclude: item.exclude });
    if (!q) warnings.push(`ไม่พบคำถาม "${item.keyword}"`);
    else if (!(await answer(q, item.value, 'text'))) warnings.push(`คำถาม "${item.keyword}" ใส่ค่าไม่ได้ — กรอกเอง`);
  }

  // B. เบอร์โทร — ของผู้สมัครห้ามมีคำว่าฉุกเฉิน, ของฉุกเฉินต้องมีทั้งคำว่าเบอร์และฉุกเฉิน
  const phones = data.phoneFields || {};
  const phoneSlots = [
    ['applicantPhone', 'เบอร์โทรผู้สมัคร', { exclude: ['ฉุกเฉิน', 'บ้าน', 'emergency', 'แฟกซ์', 'fax'] }],
    ['emergencyPhone', 'เบอร์ผู้ติดต่อฉุกเฉิน', { require: ['ฉุกเฉิน'] }],
  ];
  for (const [key, label, rule] of phoneSlots) {
    if (!(key in phones)) continue; // ไม่ได้เลือกตอนดาวน์โหลด
    if (!phones[key]) {
      warnings.push(`ไม่มี${label}ในระบบ — กรอกเอง`);
      continue;
    }
    const q = await find(PHONE_KEYWORDS, rule);
    if (!q || !(await answer(q, phones[key], 'text'))) warnings.push(`ไม่พบช่อง${label}`);
  }

  // C. วันเกิด — ไม่มี key = ไม่ได้เลือก, null = ไม่มีในระบบ
  if (has('birthDate')) {
    if (data.birthDate) {
      const w = await fillBirthDate(page, data.birthDate, warnings);
      if (w) warnings.push(w);
    } else {
      warnings.push('ไม่มีวันเกิดในระบบ — กรอกเอง');
    }
  }

  // D. ตัวเลือก (คำนำหน้า เพศ ...)
  for (const choice of data.choiceFields || []) {
    if (!choice.value) {
      warnings.push(`ไม่มี "${choice.keyword}" ในระบบ — เลือกเอง`);
      continue;
    }
    const q = await find(choice.keywords || choice.keyword, { require: choice.require, exclude: choice.exclude });
    if (!q) warnings.push(`ไม่พบคำถาม "${choice.keyword}"`);
    else if (!(await answer(q, choice.value, 'choice'))) warnings.push(`"${choice.keyword}" ไม่มีตัวเลือก "${choice.value}"`);
  }

  // E. ไฟล์แนบ
  if (data.permitSigned === false) warnings.push('⚠️ ยังไม่ได้เซ็นแบบฟอร์มอุทยาน — อย่าเพิ่งส่ง');

  const uploads = [
    ['idCard', 'idCardFilePath', 'รูปบัตรประชาชน'],
    ['permit', 'permitFilePath', 'แบบฟอร์มอุทยาน'],
  ];
  for (const [kind, key, label] of uploads) {
    if (!has(key)) continue; // ไม่ได้เลือกตอนดาวน์โหลด
    if (!data[key]) {
      if (kind === 'idCard') warnings.push('ไม่มีรูปบัตรประชาชนในระบบ');
      continue; // แบบฟอร์มอุทยาน null = ทริปไม่ได้ผูกแบบฟอร์ม
    }
    if (kind === 'permit' && data.permitSigned === false) continue;

    const file = path.resolve(data._dir, data[key]);
    if (!fs.existsSync(file)) {
      warnings.push(`ไม่พบไฟล์ ${data[key]} (แตก ZIP ครบหรือยัง?)`);
      continue;
    }
    const q = await find(UPLOAD_KEYWORDS[kind], { upload: true });
    if (!q) continue; // ฟอร์มนี้ไม่ได้ขอไฟล์นี้
    if (!AUTO_UPLOAD) {
      warnings.push(`ลาก ${file} (${label}) วางเอง`);
      continue;
    }
    const w = await uploadFile(q, file);
    if (w) warnings.push(`${label}: ${w} — ไฟล์อยู่ที่ ${file}`);
    else await markDone(q);
  }

  return warnings;
}

// ====== หน้าต่างดำ ======

/** ถามหนึ่งบรรทัด — ปิดหน้าต่าง/สิ้นสุด input = "q" */
function ask(message) {
  return new Promise((resolve) => {
    if (!process.stdin.isTTY && process.stdin.readableEnded) return resolve('q');
    process.stdout.write(message);
    process.stdin.resume();
    const onData = (chunk) => {
      process.stdin.off('end', onEnd);
      process.stdin.pause();
      resolve(String(chunk).trim());
    };
    const onEnd = () => {
      process.stdin.off('data', onData);
      resolve('q');
    };
    process.stdin.once('data', onData);
    process.stdin.once('end', onEnd);
  });
}

function waitEnter(message = '\nกด Enter เพื่อปิดหน้าต่างนี้...') {
  return ask(message);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const who = (data, i) =>
  `${i + 1}. ${data.name || `คนที่ ${data.id}`}${data.isStaff ? ' (ทีมงาน)' : ''}${data.bookingRef ? ` · ${data.bookingRef}` : ''}`;

function printPeople(people, done) {
  console.log('');
  people.forEach((p, i) => console.log(`   ${done.has(i) ? '✅' : '  '} ${who(p, i)}`));
}

function printHelp() {
  console.log('\n👉 เปิดหน้าลงทะเบียนในหน้าต่าง Chrome ที่สคริปต์เปิดไว้ (เว็บไหนก็ได้ ไม่ต้องเป็น Google Form)');
  console.log('   แล้วพิมพ์ที่หน้าต่างดำนี้:');
  console.log('     เลขคน เช่น 3      = กรอกคนนั้นลงหน้าที่เปิดอยู่ (Enter เฉย ๆ = คนถัดไป)');
  console.log('     หลายคน เช่น 1-4   = กรอกต่อกันในหน้าเดียว (เว็บที่มีช่องของหลายคนในหน้าเดียว)');
  console.log('     ลิงก์ https://...  = เปิดลิงก์นั้นในแท็บที่เปิดอยู่');
  console.log('     a = เปิดแท็บละคน ทุกคน (Google Form) · l = ดูรายชื่อ · q = ออก');
}

/** "3" "1-4" "1,3,5" → ตำแหน่งในรายชื่อ (เริ่ม 0) · null = อ่านไม่ออก */
function parseSelection(answer, count) {
  const out = [];
  for (const part of answer.split(/[,\s]+/).filter(Boolean)) {
    const m = part.match(/^(\d+)(?:-(\d+))?$/);
    if (!m) return null;
    const from = Number(m[1]);
    const to = m[2] ? Number(m[2]) : from;
    if (from < 1 || to > count || from > to) return null;
    for (let n = from; n <= to; n++) out.push(n - 1);
  }
  return out.length ? out : null;
}

// ====== เปิด Chrome ให้เอง ======

/** Chrome ก่อน ไม่มีค่อยใช้ Edge — Edge เป็น Chromium และมีในทุก Windows 10/11 */
function browserCandidates() {
  if (process.platform === 'win32') {
    const env = process.env;
    const roots = [env.PROGRAMFILES, env['PROGRAMFILES(X86)'], env['ProgramFiles(x86)'], env.LOCALAPPDATA].filter(Boolean);
    return [
      ...roots.map((r) => path.join(r, 'Google', 'Chrome', 'Application', 'chrome.exe')),
      ...roots.map((r) => path.join(r, 'Microsoft', 'Edge', 'Application', 'msedge.exe')),
    ];
  }
  if (process.platform === 'darwin') {
    return [
      '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
    ];
  }
  return ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
}

async function debugPortUp() {
  try {
    const res = await fetch(`${CHROME_URL}/json/version`, { signal: AbortSignal.timeout(1500) });
    return res.ok;
  } catch {
    return false;
  }
}

/** มี Chrome ที่เปิดพอร์ตไว้แล้วก็ใช้ตัวนั้น ไม่มีก็เปิดหน้าต่างใหม่ด้วยโปรไฟล์ของสคริปต์ */
async function ensureBrowser() {
  if (await debugPortUp()) return true;

  const exe = browserCandidates().find((p) => fs.existsSync(p));
  if (!exe) {
    console.log('❌ ไม่พบ Chrome หรือ Edge ในเครื่องนี้ — ติดตั้ง Google Chrome ก่อน');
    return false;
  }
  console.log(`🌐 กำลังเปิด ${path.basename(exe)} ...`);
  fs.mkdirSync(PROFILE_DIR, { recursive: true });
  spawn(
    exe,
    [`--remote-debugging-port=${DEBUG_PORT}`, `--user-data-dir=${PROFILE_DIR}`, '--no-first-run', '--no-default-browser-check'],
    { detached: true, stdio: 'ignore' }
  ).unref();

  for (let i = 0; i < 40; i++) {
    if (await debugPortUp()) return true;
    await sleep(500);
  }
  console.log('❌ เปิดเบราว์เซอร์แล้วแต่ต่อไม่ได้ — ปิดหน้าต่าง Chrome ที่สคริปต์เปิดไว้ แล้วลองใหม่');
  return false;
}

function lastFormUrl() {
  try {
    return fs.readFileSync(LAST_FORM_FILE, 'utf8').trim();
  } catch {
    return '';
  }
}

function rememberFormUrl(url) {
  try {
    fs.mkdirSync(PROFILE_DIR, { recursive: true });
    fs.writeFileSync(LAST_FORM_FILE, url);
  } catch {
    // จำไม่ได้ก็แค่ต้องวางลิงก์ใหม่ครั้งหน้า
  }
}

// ====== แท็บ ======

// แท็บเปล่า/หน้าตั้งค่าของ Chrome ไม่ใช่หน้าที่จะกรอก
const isWebPage = (p) => /^(https?|file):/i.test(p.url());

/** แท็บที่กำลังเปิดดูอยู่ — มีหลายหน้าต่างก็ถามว่าแท็บไหน */
async function activePage(context) {
  const pages = context.pages().filter(isWebPage);
  if (pages.length <= 1) return pages[0] || null;

  const states = await Promise.all(
    pages.map((p) =>
      p.evaluate(() => [document.visibilityState === 'visible', document.hasFocus()]).catch(() => [false, false])
    )
  );
  const focused = pages.filter((_, i) => states[i][1]);
  if (focused.length === 1) return focused[0];
  const visible = pages.filter((_, i) => states[i][0]);
  if (visible.length === 1) return visible[0];

  const list = visible.length ? visible : pages;
  console.log('\nเปิดอยู่หลายแท็บ — จะกรอกแท็บไหน');
  for (const [i, p] of list.entries()) {
    console.log(`   ${i + 1}. ${(await p.title().catch(() => '')) || p.url()}`);
  }
  const n = Number(await ask('เลขแท็บ > '));
  return list[n - 1] || null;
}

/** แท็บที่ยังไม่มีใครกรอกอะไร — แท็บที่กรอกค้างไว้ไม่ยุ่ง เปิดแท็บใหม่แทน */
async function isBlank(page) {
  try {
    return await page.evaluate(
      () =>
        ![...document.querySelectorAll('input, textarea')].some(
          (el) => !['hidden', 'radio', 'checkbox', 'submit', 'button'].includes(el.type) && el.value
        ) && !document.querySelector('[aria-checked="true"], input:checked')
    );
  } catch {
    return false;
  }
}

/** รอจนหน้าเว็บมีช่องให้กรอก (หน้าเพิ่งโหลด / เว็บที่วาดฟอร์มทีหลัง) */
async function waitForQuestions(page, timeout = 30000) {
  await page.waitForLoadState('domcontentloaded', { timeout }).catch(() => {});
  const until = Date.now() + timeout;
  while (Date.now() < until) {
    if ((await scanAll(page)).length > 0) return true;
    await sleep(500);
  }
  return false;
}

async function fillOne(page, data, i) {
  try {
    page.setDefaultTimeout(15000);
    const warnings = await fillPerson(page, data);
    console.log(`   ${warnings.length ? '🟡' : '✅'} ${who(data, i)}`);
    warnings.forEach((w) => console.log(`        - ${w}`));
    return true;
  } catch (e) {
    const msg = String(e?.message || e).split('\n')[0];
    console.log(`   ❌ ${who(data, i)}: ${msg}`);
    return false;
  }
}

/** กรอกลงหน้าที่เปิดอยู่ — หลายคนลงหน้าเดียวกันต่อกัน (ช่องที่กรอกแล้วถูกข้ามไปชุดถัดไป) */
async function fillHere(context, people, indices, done) {
  const page = await activePage(context);
  if (!page) {
    console.log('❌ ยังไม่เห็นหน้าเว็บ — เปิดหน้าลงทะเบียนในหน้าต่าง Chrome ของสคริปต์ก่อน (หรือวางลิงก์ที่นี่)');
    return;
  }
  await page.bringToFront().catch(() => {});
  if (!(await waitForQuestions(page, 10000))) {
    console.log(`❌ หน้านี้ไม่มีช่องให้กรอก (${page.url()})`);
    console.log('   ยังไม่ถึงหน้ากรอกข้อมูล? บางเว็บต้องกด "เพิ่มผู้เดินทาง" / "ถัดไป" ก่อน');
    return;
  }
  rememberFormUrl(page.url());
  await clearDone(page);

  console.log(`🚀 กรอกลงหน้า: ${(await page.title().catch(() => '')) || page.url()}`);
  for (const i of indices) {
    if (await fillOne(page, people[i], i)) done.add(i);
  }
  console.log('💡 ตรวจในหน้าเว็บ แล้วกด "ส่ง"/"บันทึก" เอง (สคริปต์ไม่กดส่งให้)');
  console.log('   คนที่ขึ้น "ยังไม่ได้เซ็นแบบฟอร์มอุทยาน" อย่าเพิ่งส่ง');
}

/** แบบเดิมของ Google Form — แท็บละคน ฟอร์มเดียวกับแท็บที่เปิดอยู่ */
async function fillInTabs(context, people, indices, done) {
  const base = await activePage(context);
  if (!base) {
    console.log('❌ เปิดหน้าฟอร์มไว้หนึ่งแท็บก่อน แล้วพิมพ์ a อีกครั้ง');
    return;
  }
  const url = base.url();
  rememberFormUrl(url);

  const pages = [];
  for (const tab of context.pages()) {
    if (pages.length < indices.length && tab.url() === url && (await isBlank(tab))) pages.push(tab);
  }
  while (pages.length < indices.length) {
    const tab = await context.newPage();
    await tab.goto(url).catch(() => {});
    pages.push(tab);
  }

  console.log(`🚀 กำลังกรอก ${indices.length} แท็บ...`);
  // ทีละแท็บ โดยดึงแท็บนั้นขึ้นหน้าสุด — Chrome พักแท็บที่ไม่ได้เปิดดูอยู่
  // คลิกตัวเลือกในแท็บเบื้องหลังจึงค้างได้ ทีละแท็บช้ากว่านิดเดียวแต่ไม่พลาด
  for (const [t, page] of pages.entries()) {
    const i = indices[t];
    await page.bringToFront().catch(() => {});
    if (!(await waitForQuestions(page))) {
      console.log(`   ❌ ${who(people[i], i)}: หน้าฟอร์มโหลดไม่ขึ้น/ต้องล็อกอินก่อน?`);
      continue;
    }
    await clearDone(page);
    if (await fillOne(page, people[i], i)) done.add(i);
  }
  console.log('💡 ตรวจแต่ละแท็บ แล้วกด "ส่ง" เอง (สคริปต์ไม่กดส่งให้)');
}

async function openUrl(context, url) {
  const page = (await activePage(context)) || (await context.newPage());
  await page.goto(url, { timeout: 30000 }).catch(() => {});
  await page.bringToFront().catch(() => {});
  rememberFormUrl(url);
  console.log('   เปิดแล้ว — ถ้าต้องล็อกอิน ให้ล็อกอินในหน้าต่าง Chrome ให้เสร็จก่อน แล้วค่อยพิมพ์เลขคน');
}

async function main() {
  // 1. โหลดข้อมูล
  let loaded;
  try {
    loaded = loadPeople();
  } catch (e) {
    console.log(`❌ อ่าน data.json ไม่ได้: ${e.message}`);
    return;
  }
  if (!loaded || loaded.people.length === 0) {
    console.log('❌ ไม่พบ data.json — วางสคริปต์/exe ไว้ในโฟลเดอร์ที่แตก ZIP');
    console.log('   (ชั้นบนสุด = ทั้งรอบ · ในโฟลเดอร์ของคน = คนเดียว · รวมหลายโฟลเดอร์ไว้ด้วยกัน = หลายคน)');
    return;
  }
  const people = loaded.people;
  const staffCount = people.filter((p) => p.isStaff).length;
  console.log(`📄 ${people.length} คน${staffCount ? ` (ทีมงาน ${staffCount})` : ''} จาก ${loaded.source}`);

  // 2. เชื่อมต่อกับ Chrome พอร์ต 9222 — ยังไม่ได้เปิดไว้ สคริปต์เปิดให้เอง
  if (!(await ensureBrowser())) return;
  let browser;
  try {
    browser = await chromium.connectOverCDP(CHROME_URL);
  } catch {
    console.log('❌ ต่อ Chrome ไม่ได้ — ปิดหน้าต่าง Chrome ที่สคริปต์เปิดไว้ แล้วลองใหม่');
    return;
  }

  try {
    const context = browser.contexts()[0];
    const done = new Set();
    printPeople(people, done);
    printHelp();
    const last = lastFormUrl();
    if (last && !context.pages().some(isWebPage)) console.log(`     u = เปิดฟอร์มที่ใช้ครั้งก่อน (${last})`);

    // 3. รับคำสั่งทีละบรรทัด จนกว่าจะพิมพ์ q
    for (;;) {
      const next = people.findIndex((_, i) => !done.has(i));
      const prompt = next === -1 ? '\nครบทุกคนแล้ว (q = ออก)' : `\nEnter = ${who(people[next], next)}`;
      const answer = (await ask(`${prompt}\n> `)).trim();
      const cmd = answer.toLowerCase();

      try {
        if (cmd === 'q') return 'quit';
        if (cmd === 'l') {
          printPeople(people, done);
          printHelp();
        } else if (cmd === 'a') {
          const left = people.map((_, i) => i).filter((i) => !done.has(i));
          await fillInTabs(context, people, left.length ? left : people.map((_, i) => i), done);
        } else if (cmd === 'u' && lastFormUrl()) {
          await openUrl(context, lastFormUrl());
        } else if (/^https?:\/\//i.test(answer)) {
          await openUrl(context, answer);
        } else if (answer === '') {
          if (next === -1) console.log('   ครบทุกคนแล้ว — พิมพ์เลขคนเพื่อกรอกซ้ำ หรือ q = ออก');
          else await fillHere(context, people, [next], done);
        } else {
          const picked = parseSelection(answer, people.length);
          if (!picked) console.log(`   ไม่เข้าใจ "${answer}" — พิมพ์เลข 1-${people.length} หรือ l ดูวิธีใช้`);
          else await fillHere(context, people, picked, done);
        }
      } catch (e) {
        console.log(`❌ ${String(e?.message || e).split('\n')[0]}`);
      }
    }
  } finally {
    // ตัดการเชื่อมต่อ ปล่อยแท็บทั้งหมดค้างไว้ใน Chrome
    await browser.close().catch(() => {});
  }
}

// พิมพ์ q ออกเอง = ปิดเลย · จบเพราะมีปัญหา = ค้างหน้าต่างไว้ให้อ่านข้อความก่อน
main()
  .catch((e) => console.log(`❌ ${e?.message || e}`))
  .then((result) => (result === 'quit' ? process.exit(0) : waitEnter().then(() => process.exit(0))));
