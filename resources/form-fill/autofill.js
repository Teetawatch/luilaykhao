// กรอก Google Form อัตโนมัติจาก data.json ที่ดาวน์โหลดจากหลังบ้าน
// (ตารางที่นั่งว่าง → เอกสาร → ข้อมูลกรอกฟอร์ม) วิธีใช้อยู่ใน README.txt
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
// พยายามอัปโหลดรูปบัตร/แบบฟอร์มอุทยานให้เอง (ต้องล็อกอิน Google ใน Chrome ก่อน)
// ถ้า Google เปลี่ยนหน้าต่างเลือกไฟล์แล้วอัปโหลดไม่ได้ สคริปต์จะบอกให้ลากวางเองในแท็บนั้น
const AUTO_UPLOAD = true;
const DEBUG_PORT = 9222;
// 127.0.0.1 ไม่ใช่ localhost — บางเครื่องแปล localhost เป็น IPv6 (::1) แต่ Chrome ฟังแค่ IPv4
const CHROME_URL = `http://127.0.0.1:${DEBUG_PORT}`;
// โปรไฟล์ Chrome แยกของสคริปต์ ล็อกอิน Google ครั้งเดียวแล้วจำไว้ — Chrome ไม่ยอมเปิดพอร์ต
// 9222 กับโปรไฟล์หลักของเครื่อง · ที่เดียวกับคำสั่งเปิด Chrome เองใน README รุ่นก่อน
const PROFILE_DIR =
  process.env.AUTOFILL_PROFILE_DIR ||
  (process.platform === 'win32' ? 'C:\\chrome-autofill' : path.join(os.homedir(), '.chrome-autofill'));
const LAST_FORM_FILE = path.join(PROFILE_DIR, 'last-form-url.txt');
// คำในหัวข้อคำถาม — ใส่ส่วนที่ไม่ซ้ำกับคำถามอื่น
const UPLOAD_KEYWORDS = {
  idCard: ['บัตรประชาชน', 'บัตรประจำตัว'],
  permit: ['แบบฟอร์ม', 'คำขอ', 'อุทยาน'],
};
const PHONE_KEYWORDS = ['เบอร์โทร', 'โทรศัพท์', 'มือถือ', 'เบอร์ติดต่อ'];
const BIRTH_KEYWORDS = ['วันเกิด', 'วัน เดือน ปี เกิด', 'วันเดือนปีเกิด', 'วัน/เดือน/ปีเกิด'];

// ====== อ่าน data.json ======

function readJson(file) {
  // Notepad บางรุ่นเซฟเป็น UTF-8 มี BOM ซึ่ง JSON.parse ไม่รับ
  const text = fs.readFileSync(file, 'utf8').replace(/^﻿/, '');
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

// ====== หาคำถามบนฟอร์ม ======

const isFormTab = (p) => /\/forms\/.+\/viewform/.test(p.url());

const asList = (v) => (Array.isArray(v) ? v : v ? [v] : []);

// เทียบหัวข้อคำถามแบบไม่สนตัวพิมพ์เล็ก/ใหญ่ (Email/email) และให้ "ํา" (นิคหิต+สระอา) เท่ากับ "ำ"
// — สองแบบนี้หน้าตาเหมือนกันแต่คนละตัวอักษร ฟอร์มที่พิมพ์จากบางเครื่องเป็นแบบแรก
const norm = (s) => String(s).replace(/\u0E4D\u0E32/g, '\u0E33').toLowerCase();
const titleHas = (title, k) => title.includes(norm(k));

/**
 * หาคำถามจาก "หัวข้อคำถาม" เท่านั้น (ไม่ใช่ทั้งกล่องที่มีคำอธิบาย/ตัวเลือกปน)
 *   keywords  มีคำใดคำหนึ่ง
 *   require   ต้องมีทุกคำ (เช่น เบอร์ฉุกเฉิน = คำว่าเบอร์ + "ฉุกเฉิน")
 *   exclude   ห้ามมีคำเหล่านี้
 *   upload    true = เฉพาะคำถามอัปโหลดไฟล์ · false = เฉพาะคำถามที่มีช่องให้ตอบ
 *             (กัน "บัตรประชาชน" ของรูปบัตรไปเจอข้อ "เลขบัตรประชาชน" และกันไปเจอ
 *             กล่องหัวข้อ/คำอธิบายที่ไม่มีช่องตอบ)
 * เจอหลายข้อเอาข้อที่หัวข้อสั้นที่สุด = ตรงคำค้นที่สุด ไม่ขึ้นกับลำดับข้อบนฟอร์ม
 */
async function questionBox(page, keywords, { require = [], exclude = [], upload = false } = {}) {
  const list = asList(keywords);
  const items = await page.locator('div[role="listitem"]').evaluateAll((els) =>
    els.map((item) => {
      const heading = item.querySelector('[role="heading"]');
      const answerable = !!item.querySelector(
        'input:not([type="hidden"]), textarea, [role="radio"], [role="checkbox"], [role="listbox"]'
      );
      return {
        title: heading ? heading.innerText.replace(/\*/g, '').replace(/\s+/g, ' ').trim() : null,
        answerable,
        upload: !answerable && /เพิ่มไฟล์|add file|อัปโหลด|upload/i.test(item.innerText),
      };
    })
  );
  const titles = items.map((it) => it.title);

  let best = -1;
  items.forEach(({ title: raw, answerable, upload: isUpload }, i) => {
    if (!raw) return;
    const title = norm(raw);
    if (upload ? !isUpload : !answerable) return;
    if (!list.some((k) => titleHas(title, k))) return;
    if (!asList(require).every((k) => titleHas(title, k))) return;
    if (asList(exclude).some((k) => titleHas(title, k))) return;
    if (best === -1 || title.length < titles[best].length) best = i;
  });

  return best === -1 ? null : page.locator('div[role="listitem"]').nth(best);
}

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

/** ช่องข้อความก่อน — บนฟอร์มเป็นตัวเลือกก็ลองเลือกให้ */
async function setText(page, box, value) {
  return (await fillText(box, value)) || (await pickChoice(page, box, value));
}

/** ตัวเลือกก่อน — บนฟอร์มเป็นช่องพิมพ์ก็พิมพ์ให้ */
async function setChoice(page, box, value) {
  return (await pickChoice(page, box, value)) || (await fillText(box, value));
}

async function fillBirthDate(page, birthDate) {
  const box = await questionBox(page, BIRTH_KEYWORDS);
  if (!box) return 'ไม่พบคำถามวันเกิด';

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

/**
 * อัปโหลดผ่านหน้าต่างเลือกไฟล์ของ Google (ใน iframe) — Google เปลี่ยนหน้าตาได้ทุกเมื่อ
 * จึงทำแบบพยายาม ไม่สำเร็จภายใน 20 วินาทีก็ปล่อยให้ลากวางเอง
 */
async function uploadFile(page, box, filePath) {
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
    return 'อัปโหลดอัตโนมัติไม่สำเร็จ — ลากไฟล์วางเองในแท็บนี้';
  }
}

// ====== กรอกหนึ่งคน ======

async function fillPerson(page, data) {
  const warnings = [];
  const has = (key) => Object.prototype.hasOwnProperty.call(data, key);

  // A. ช่องข้อความ
  for (const item of data.textFields || []) {
    if (!item.value) {
      warnings.push(`ไม่มี "${item.keyword}" ในระบบ — กรอกเอง`);
      continue;
    }
    const box = await questionBox(page, item.keywords || item.keyword, { require: item.require, exclude: item.exclude });
    if (!box) warnings.push(`ไม่พบคำถาม "${item.keyword}"`);
    else if (!(await setText(page, box, item.value))) warnings.push(`คำถาม "${item.keyword}" ไม่มีช่องให้กรอก`);
  }

  // B. เบอร์โทร — ของผู้สมัครห้ามมีคำว่าฉุกเฉิน, ของฉุกเฉินต้องมีทั้งคำว่าเบอร์และฉุกเฉิน
  const phones = data.phoneFields || {};
  const phoneSlots = [
    ['applicantPhone', 'เบอร์โทรผู้สมัคร', { exclude: ['ฉุกเฉิน', 'บ้าน'] }],
    ['emergencyPhone', 'เบอร์ผู้ติดต่อฉุกเฉิน', { require: ['ฉุกเฉิน'] }],
  ];
  for (const [key, label, rule] of phoneSlots) {
    if (!(key in phones)) continue; // ไม่ได้เลือกตอนดาวน์โหลด
    if (!phones[key]) {
      warnings.push(`ไม่มี${label}ในระบบ — กรอกเอง`);
      continue;
    }
    const box = await questionBox(page, PHONE_KEYWORDS, rule);
    if (!box || !(await fillText(box, phones[key]))) warnings.push(`ไม่พบช่อง${label}`);
  }

  // C. วันเกิด — ไม่มี key = ไม่ได้เลือก, null = ไม่มีในระบบ
  if (has('birthDate')) {
    if (data.birthDate) {
      const w = await fillBirthDate(page, data.birthDate);
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
    const box = await questionBox(page, choice.keywords || choice.keyword, { require: choice.require, exclude: choice.exclude });
    if (!box) warnings.push(`ไม่พบคำถาม "${choice.keyword}"`);
    else if (!(await setChoice(page, box, choice.value))) warnings.push(`"${choice.keyword}" ไม่มีตัวเลือก "${choice.value}"`);
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
    const box = await questionBox(page, UPLOAD_KEYWORDS[kind], { upload: true });
    if (!box) continue; // ฟอร์มนี้ไม่ได้ขอไฟล์นี้
    if (!AUTO_UPLOAD) {
      warnings.push(`ลาก ${file} (${label}) วางเอง`);
      continue;
    }
    const w = await uploadFile(page, box, file);
    if (w) warnings.push(`${label}: ${w} — ไฟล์อยู่ที่ ${file}`);
  }

  return warnings;
}

// ====== เตรียมแท็บ ======

/** แท็บที่ยังไม่มีใครกรอกอะไร — แท็บที่กรอกค้างไว้ไม่ยุ่ง เปิดแท็บใหม่แทน */
async function isBlank(page) {
  try {
    return await page.evaluate(
      () =>
        ![...document.querySelectorAll('input, textarea')].some(
          (el) => !['hidden', 'radio', 'checkbox', 'submit', 'button'].includes(el.type) && el.value
        ) && !document.querySelector('[aria-checked="true"]')
    );
  } catch {
    return false;
  }
}

async function waitForQuestions(page) {
  await page.locator('div[role="listitem"]').first().waitFor({ timeout: 30000 });
}

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

/**
 * แท็บ Google Form ที่เปิดอยู่ — ไม่มีก็ถามลิงก์ (Enter เฉย ๆ = ฟอร์มที่ใช้ครั้งก่อน)
 * ฟอร์มที่ต้องล็อกอินจะเด้งไปหน้าล็อกอิน Google ก่อน — ล็อกอินในหน้าต่างนั้นแล้วกด Enter
 */
async function formTabs(context) {
  let tabs = context.pages().filter(isFormTab);
  while (tabs.length === 0) {
    const last = lastFormUrl();
    console.log('\n👉 วางลิงก์ Google Form ที่จะกรอก แล้วกด Enter');
    if (last) console.log(`   (กด Enter เฉย ๆ = ใช้ฟอร์มเดิม ${last})`);
    console.log('   ถ้าขึ้นหน้าล็อกอิน Google ให้ล็อกอินในหน้าต่าง Chrome ให้เสร็จ แล้วกลับมากด Enter · พิมพ์ q = ออก');
    const answer = await ask('> ');
    if (answer.toLowerCase() === 'q') return [];

    const url = /^https?:\/\//i.test(answer) ? answer : !answer && last ? last : '';
    if (url) {
      const open = context.pages().find((p) => p.url() === 'about:blank') || (await context.newPage());
      await open.goto(url, { timeout: 30000 }).catch(() => {});
      await open.bringToFront().catch(() => {});
    }

    tabs = context.pages().filter(isFormTab);
    if (tabs.length === 0) {
      console.log('   ยังไม่เห็นหน้ากรอกฟอร์ม (ลิงก์ผิด หรือรอล็อกอิน Google อยู่)');
    }
  }
  rememberFormUrl(tabs[0].url());
  return tabs;
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
  console.log(`📄 ${people.length} คน จาก ${loaded.source}`);

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
    const tabs = await formTabs(context);
    if (tabs.length === 0) return;
    const formUrl = tabs[0].url();

    const pages = [];
    for (const tab of tabs) {
      if (pages.length < people.length && (await isBlank(tab))) pages.push(tab);
    }
    while (pages.length < people.length) {
      const tab = await context.newPage();
      await tab.goto(formUrl);
      pages.push(tab);
    }

    console.log(`🚀 กำลังกรอก ${people.length} แท็บ...`);

    // 3. กรอกทีละแท็บ โดยดึงแท็บนั้นขึ้นหน้าสุด — Chrome พักแท็บที่ไม่ได้เปิดดูอยู่
    //    คลิกตัวเลือกในแท็บเบื้องหลังจึงค้างได้ ทีละแท็บช้ากว่านิดเดียวแต่ไม่พลาด
    const results = [];
    for (const [i, page] of pages.entries()) {
      const data = people[i];
      try {
        page.setDefaultTimeout(15000);
        await page.bringToFront();
        await waitForQuestions(page);
        const warnings = await fillPerson(page, data);
        results.push({ ok: true, warnings });
        console.log(`   ${warnings.length ? '🟡' : '✅'} แท็บที่ ${i + 1} · ${data.name || `คนที่ ${data.id}`}`);
      } catch (e) {
        const msg = String(e?.message || e).split('\n')[0];
        const hint = /Timeout/i.test(msg) ? ' (หน้าฟอร์มโหลดไม่ขึ้น/ต้องล็อกอิน Google?)' : '';
        results.push({ ok: false, error: msg + hint });
        console.log(`   ❌ แท็บที่ ${i + 1} · ${data.name || `คนที่ ${data.id}`}`);
      }
    }

    // 4. สรุป
    console.log('\n========================================');
    results.forEach((r, i) => {
      const data = people[i];
      const who = `${data.name || `คนที่ ${data.id}`}${data.bookingRef ? ` · ${data.bookingRef}` : ''}`;
      if (!r.ok) {
        console.log(`❌ แท็บที่ ${i + 1} (${who}): ${r.error}`);
        return;
      }
      console.log(`${r.warnings.length ? '🟡' : '✅'} แท็บที่ ${i + 1} (${who})`);
      r.warnings.forEach((w) => console.log(`     - ${w}`));
    });
    console.log('========================================');
    console.log('💡 ตรวจแต่ละแท็บ แล้วกด "ส่ง" เอง (สคริปต์ไม่กดส่งให้)');
  } finally {
    // ตัดการเชื่อมต่อ ปล่อยแท็บทั้งหมดค้างไว้ใน Chrome
    await browser.close().catch(() => {});
  }
}

main()
  .catch((e) => console.log(`❌ ${e?.message || e}`))
  .finally(() => waitEnter());
