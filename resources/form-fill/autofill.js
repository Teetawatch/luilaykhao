const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// ====== ตั้งค่า ======
// พยายามอัปโหลดรูปบัตร/แบบฟอร์มอุทยานให้เอง (ต้องล็อกอิน Google ใน Chrome ก่อน)
// ถ้า Google เปลี่ยนหน้าต่างเลือกไฟล์แล้วอัปโหลดไม่ได้ สคริปต์จะบอกให้ลากวางเองในแท็บนั้น
const AUTO_UPLOAD = true;
// คำในหัวข้อคำถามอัปโหลด — ใส่ส่วนที่ไม่ซ้ำกับคำถามอื่น
const UPLOAD_KEYWORDS = {
  idCard: ['บัตรประชาชน', 'บัตรประจำตัว'],
  permit: ['แบบฟอร์ม', 'คำขอ', 'อุทยาน'],
};
const PHONE_KEYWORDS = ['เบอร์โทร', 'โทรศัพท์', 'มือถือ'];
const BIRTH_KEYWORDS = ['วันเกิด', 'วัน เดือน ปี เกิด', 'วันเดือนปีเกิด'];

// exe ที่ bun compile แล้ว ดับเบิลคลิกเปิด โฟลเดอร์ทำงานอาจไม่ใช่โฟลเดอร์ของ exe
// — หา data.json ทั้งในโฟลเดอร์ปัจจุบันและโฟลเดอร์ที่ exe อยู่
function findBaseDir() {
  const dirs = [process.cwd(), path.dirname(process.execPath)];
  return dirs.find((d) => fs.existsSync(path.join(d, 'data.json')));
}

function waitEnter(message = '\nกด Enter เพื่อปิดหน้าต่างนี้...') {
  return new Promise((resolve) => {
    process.stdout.write(message);
    process.stdin.resume();
    process.stdin.once('data', () => {
      process.stdin.pause();
      resolve();
    });
  });
}

const isFormTab = (p) => /docs\.google\.com\/forms\/.+\/viewform/.test(p.url());

/**
 * หาคำถามจาก "หัวข้อคำถาม" เท่านั้น (ไม่ใช่ทั้งกล่อง ที่มีคำอธิบาย/ตัวเลือกปนอยู่)
 * ถ้าเจอหลายข้อ เช่น "นามสกุล" กับ "นามสกุลผู้ติดต่อฉุกเฉิน" เอาข้อที่หัวข้อสั้นที่สุด
 * = ตรงคำค้นที่สุด
 */
async function questionBox(page, keywords, { exclude = [] } = {}) {
  const list = Array.isArray(keywords) ? keywords : [keywords];
  const boxes = page.locator('div[role="listitem"]');
  const count = await boxes.count();
  let best = null;

  for (let i = 0; i < count; i++) {
    const box = boxes.nth(i);
    const heading = box.locator('[role="heading"]').first();
    if ((await heading.count()) === 0) continue;
    const title = (await heading.innerText()).replace(/\*/g, '').trim();
    if (!list.some((k) => title.includes(k))) continue;
    if (exclude.some((k) => title.includes(k))) continue;
    if (!best || title.length < best.title.length) best = { box, title };
  }

  return best?.box ?? null;
}

async function fillText(box, value) {
  const input = box.locator('input[type="text"], input[type="email"], input[type="number"], input[type="tel"], input[type="url"], textarea').first();
  if ((await input.count()) === 0) return false;
  await input.fill(value);
  return true;
}

async function fillBirthDate(page, birthDate) {
  const box = await questionBox(page, BIRTH_KEYWORDS);
  if (!box) return 'ไม่พบคำถามวันเกิด';

  const dateInput = box.locator('input[type="date"]').first();
  if ((await dateInput.count()) > 0) {
    await dateInput.fill(birthDate); // input date ของ Chrome รับ YYYY-MM-DD เสมอ ไม่ว่าจะแสดงแบบไหน
    return null;
  }

  // บางเครื่องแสดงช่องวัน/เดือน/ปีแยกกัน 3 ช่อง (ปี ค.ศ.)
  const parts = box.locator('input[type="text"], input[type="number"]');
  if ((await parts.count()) >= 3) {
    const [y, m, d] = birthDate.split('-');
    await parts.nth(0).fill(d);
    await parts.nth(1).fill(m);
    await parts.nth(2).fill(y);
    return 'วันเกิดกรอกแบบ 3 ช่อง (วัน/เดือน/ปี) — ตรวจลำดับช่องอีกครั้ง';
  }

  await fillText(box, birthDate);
  return null;
}

/** ตัวเลือกแบบวงกลม / ช่องติ๊ก / dropdown */
async function pickChoice(page, box, value) {
  for (const role of ['radio', 'checkbox']) {
    const option = box.getByRole(role, { name: value, exact: true });
    if ((await option.count()) > 0) {
      await option.first().click();
      return true;
    }
  }

  const dropdown = box.locator('[role="listbox"]').first();
  if ((await dropdown.count()) > 0) {
    await dropdown.click();
    const option = page.locator(`[role="option"]:visible`).filter({ hasText: new RegExp(`^\\s*${escapeRegex(value)}\\s*$`) });
    if ((await option.count()) > 0) {
      await option.first().click();
      return true;
    }
    await page.keyboard.press('Escape');
  }

  return false;
}

function escapeRegex(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * อัปโหลดผ่านหน้าต่างเลือกไฟล์ของ Google (ใน iframe) — Google เปลี่ยนหน้าตาได้ทุกเมื่อ
 * จึงทำแบบพยายาม ไม่สำเร็จภายใน 20 วินาทีก็ปล่อยให้ลากวางเอง
 */
async function uploadFile(page, box, filePath) {
  const addButton = box.getByRole('button').filter({ hasText: /เพิ่มไฟล์|Add file/i }).first();
  if ((await addButton.count()) === 0) return 'ไม่พบปุ่ม "เพิ่มไฟล์" (ล็อกอิน Google หรือยัง?)';

  await page.bringToFront();
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

async function main() {
  // 1. โหลดข้อมูลจาก data.json
  const baseDir = findBaseDir();
  if (!baseDir) {
    console.log('❌ ไม่พบ data.json — วาง autofill.exe ไว้ในโฟลเดอร์เดียวกับ data.json (โฟลเดอร์ที่แตก ZIP)');
    return;
  }
  const allPeopleData = JSON.parse(fs.readFileSync(path.join(baseDir, 'data.json'), 'utf8'));
  const total = allPeopleData.length;
  if (total === 0) {
    console.log('❌ data.json ไม่มีรายชื่อ');
    return;
  }

  // 2. เชื่อมต่อกับ Chrome ที่เปิดผ่านพอร์ต 9222
  const browser = await chromium.connectOverCDP('http://localhost:9222');
  const context = browser.contexts()[0];

  // เฉพาะแท็บที่เป็นหน้ากรอก (viewform) — แท็บที่กดส่งไปแล้ว (formResponse) ไม่มีช่องให้กรอก
  let pages = context.pages().filter(isFormTab);
  if (pages.length === 0) {
    console.log('❌ กรุณาเปิดหน้า Google Form (หน้ากรอก) รอไว้ใน Chrome อย่างน้อย 1 แท็บก่อนรันสคริปต์!');
    await browser.close();
    return;
  }
  const formUrl = pages[0].url();

  while (pages.length < total) {
    const newPage = await context.newPage();
    await newPage.goto(formUrl);
    pages.push(newPage);
  }
  pages = pages.slice(0, total);

  // รอให้ทุกแท็บโหลดคำถามครบก่อน (domcontentloaded ยังไม่มีคำถามบนจอ)
  await Promise.all(pages.map((p) => p.locator('div[role="listitem"]').first().waitFor({ timeout: 30000 })));

  console.log(`🚀 กำลังกรอกข้อมูล ${total} แท็บพร้อมกัน...`);

  // 3. กรอกพร้อมกันทุกแท็บ — allSettled: แท็บหนึ่งพังไม่ลากแท็บอื่นพังตาม
  const results = await Promise.allSettled(
    pages.map(async (page, index) => {
      const data = allPeopleData[index];
      const warnings = [];
      const name = data.textFields.find((x) => x.keyword === 'ชื่อจริง')?.value || `คนที่ ${index + 1}`;

      // A. ช่องข้อความ
      for (const item of data.textFields) {
        if (!item.value) continue; // ข้อมูลว่าง = ปล่อยให้กรอกเอง
        const box = await questionBox(page, item.keyword);
        if (!box || !(await fillText(box, item.value))) warnings.push(`ไม่พบช่อง "${item.keyword}"`);
      }

      // B. เบอร์โทร — แยกของผู้สมัครกับผู้ติดต่อฉุกเฉินจากคำว่า "ฉุกเฉิน" ในหัวข้อ
      const { applicantPhone, emergencyPhone } = data.phoneFields || {};
      if (applicantPhone) {
        const box = await questionBox(page, PHONE_KEYWORDS, { exclude: ['ฉุกเฉิน'] });
        if (!box || !(await fillText(box, applicantPhone))) warnings.push('ไม่พบช่องเบอร์โทรผู้สมัคร');
      }
      if (emergencyPhone) {
        const box = await questionBox(page, ['ฉุกเฉิน']);
        if (!box || !(await fillText(box, emergencyPhone))) warnings.push('ไม่พบช่องเบอร์ฉุกเฉิน');
      }

      // C. วันเกิด — null = ยังไม่มีในระบบ
      if (data.birthDate) {
        const w = await fillBirthDate(page, data.birthDate);
        if (w) warnings.push(w);
      } else {
        warnings.push('ไม่มีวันเกิดในระบบ — กรอกเอง');
      }

      // D. ตัวเลือก (คำนำหน้า เพศ ...)
      for (const choice of data.choiceFields) {
        if (!choice.value) continue;
        const box = await questionBox(page, choice.keyword);
        if (!box) {
          warnings.push(`ไม่พบคำถาม "${choice.keyword}"`);
        } else if (!(await pickChoice(page, box, choice.value))) {
          warnings.push(`"${choice.keyword}" ไม่มีตัวเลือก "${choice.value}"`);
        }
      }

      return { index, name, warnings };
    })
  );

  // 4. อัปโหลดไฟล์ทีละแท็บ (หน้าต่างเลือกไฟล์ต้องการแท็บที่อยู่หน้าสุด ทำพร้อมกันไม่ได้)
  for (const [index, page] of pages.entries()) {
    const data = allPeopleData[index];
    const result = results[index];
    if (result.status !== 'fulfilled') continue;
    const warnings = result.value.warnings;

    const uploads = [
      ['idCard', data.idCardFilePath, 'รูปบัตรประชาชน'],
      ['permit', data.permitFilePath, 'แบบฟอร์มอุทยาน'],
    ];

    if (data.permitSigned === false) {
      warnings.push('⚠️ ยังไม่ได้เซ็นแบบฟอร์มอุทยาน — อย่าเพิ่งส่ง');
    }

    for (const [kind, rel, label] of uploads) {
      if (!rel) {
        if (kind === 'idCard') warnings.push('ไม่มีรูปบัตรประชาชนในระบบ');
        continue;
      }
      if (kind === 'permit' && data.permitSigned === false) continue;

      const file = path.resolve(baseDir, rel);
      if (!fs.existsSync(file)) {
        warnings.push(`ไม่พบไฟล์ ${rel}`);
        continue;
      }
      const box = await questionBox(page, UPLOAD_KEYWORDS[kind]);
      if (!box) continue; // ฟอร์มนี้ไม่ได้ขอไฟล์นี้
      if (!AUTO_UPLOAD) {
        warnings.push(`ลาก ${path.basename(file)} (${label}) วางเอง`);
        continue;
      }
      const w = await uploadFile(page, box, file);
      if (w) warnings.push(`${label}: ${w} (${path.basename(file)})`);
    }
  }

  console.log('\n========================================');
  results.forEach((r, i) => {
    if (r.status === 'rejected') {
      console.log(`❌ แท็บที่ ${i + 1}: ${r.reason?.message || r.reason}`);
      return;
    }
    const { name, warnings } = r.value;
    console.log(`${warnings.length ? '🟡' : '✅'} แท็บที่ ${i + 1} (${name})${refLabel(allPeopleData[i])}`);
    warnings.forEach((w) => console.log(`     - ${w}`));
  });
  console.log('========================================');
  console.log('💡 ตรวจแต่ละแท็บ แล้วกด "ส่ง" เอง (สคริปต์ไม่กดส่งให้)');

  // ตัดการเชื่อมต่อ ปล่อยแท็บทั้งหมดค้างไว้
  await browser.close();
}

const refLabel = (row) => (row?.bookingRef ? ` · ${row.bookingRef}` : '');

main()
  .catch((e) => {
    console.log(`❌ ${e.message}`);
    if (String(e.message).includes('9222')) {
      console.log('   เปิด Chrome ด้วย --remote-debugging-port=9222 ก่อนหรือยัง?');
    }
  })
  .finally(() => waitEnter());
