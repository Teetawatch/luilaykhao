/* ภาษาของเว็บ (resources/js/i18n) — ตัวแปลข้อความ + คำแปลภาษาอังกฤษครบทุกคีย์
 *
 * สิ่งที่ต้องถูก: ไม่มีคำแปล = ข้อความไทยเดิม (ไม่มีวันว่าง), ตัวแปร {x} แทนค่าได้,
 * รูปเอกพจน์/พหูพจน์เลือกจากตัวเลข, path ของหน้าอังกฤษอยู่ใต้ /en
 * และทุกข้อความที่ครอบ $t('…') ไว้ในโค้ดต้องมีคำแปลใน en.json ที่ใช้ตัวแปรชุดเดียวกัน
 * — ครอบแล้วลืมแปล หน้าอังกฤษจะโผล่ภาษาไทยกลางประโยค
 * รัน: node tests/Web/i18n.test.mjs */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createTranslator, interpolate, pickPlural } from '../../resources/js/i18n/translate.js';
import { localePath, stripLocale } from '../../resources/js/i18n/index.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
const en = JSON.parse(readFileSync(join(root, 'resources/js/i18n/en.json'), 'utf8'));

let passed = 0;
function test(name, fn) {
  try {
    fn();
    passed++;
  } catch (error) {
    console.error(`✗ ${name}`);
    throw error;
  }
}

// ── ตัวแปล ─────────────────────────────────────────────────────────────
test('ไม่มีคำแปล = ข้อความไทยเดิม', () => {
  const t = createTranslator({});
  assert.equal(t('จองเลย'), 'จองเลย');
  assert.equal(t('เหลือ {n} ที่', { n: 3 }), 'เหลือ 3 ที่');
  assert.equal(t(''), '');
  assert.equal(t(null), '');
});

test('แทนตัวแปร และเก็บ {x} ที่ไม่ได้ส่งค่ามาไว้ตามเดิม', () => {
  assert.equal(interpolate('{a} และ {b}', { a: 1 }), '1 และ {b}');
  const t = createTranslator({ 'จองแล้ว {n} คน': '{n} booked' });
  assert.equal(t('จองแล้ว {n} คน', { n: 12 }), '12 booked');
});

test('เลือกเอกพจน์/พหูพจน์จาก n, count หรือตัวเลขตัวแรก', () => {
  assert.equal(pickPlural('{n} seat || {n} seats', { n: 1 }), '{n} seat');
  assert.equal(pickPlural('{n} seat || {n} seats', { n: 4 }), '{n} seats');
  assert.equal(pickPlural('{x} day || {x} days', { x: 1 }), '{x} day');
  assert.equal(pickPlural('{x} day || {x} days', { x: '2' }), '{x} days');
  const t = createTranslator({ 'เหลือ {n} ที่': '{n} seat left || {n} seats left' });
  assert.equal(t('เหลือ {n} ที่', { n: 1 }), '1 seat left');
  assert.equal(t('เหลือ {n} ที่', { n: 5 }), '5 seats left');
});

test('" | " ตัวเดียวเป็นข้อความธรรมดา ไม่ใช่พหูพจน์', () => {
  const t = createTranslator({ '{title} | ลุยเลเขา': '{title} | Luilaykhao' });
  assert.equal(t('{title} | ลุยเลเขา', { title: 'Doi Luang' }), 'Doi Luang | Luilaykhao');
});

test('คำแปลว่างถือว่ายังไม่ได้แปล', () => {
  const t = createTranslator({ 'ปิด': '' });
  assert.equal(t('ปิด'), 'ปิด');
});

// ── URL ของแต่ละภาษา ──────────────────────────────────────────────────
test('path ของหน้าอังกฤษอยู่ใต้ /en และตัดกลับได้', () => {
  assert.equal(localePath('/trips/doi-luang', 'en'), '/en/trips/doi-luang');
  assert.equal(localePath('/', 'en'), '/en');
  assert.equal(localePath('/en/trips', 'en'), '/en/trips');
  assert.equal(localePath('/en/trips', 'th'), '/trips');
  assert.equal(localePath('/en', 'th'), '/');
  assert.equal(stripLocale('/en'), '/');
  assert.equal(stripLocale('/en/booking/5'), '/booking/5');
  // /enjoy ไม่ใช่หน้าอังกฤษ
  assert.equal(stripLocale('/enjoy'), '/enjoy');
  assert.equal(localePath('/enjoy', 'en'), '/en/enjoy');
});

// ── คำแปลครบ ─────────────────────────────────────────────────────────
// มีตัวอักษรไทยจริง (฿ ไม่นับ — "฿{x}" แปลแล้วก็หน้าตาเดิม)
const THAI = /[ก-฾เ-๿]/;

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (name !== 'admin') walk(path, out);
    } else if (/\.(vue|js)$/.test(name)) {
      out.push(path);
    }
  }
  return out;
}

function unescape(raw) {
  return raw.replace(/\\(.)/g, (_, c) => ({ n: '\n', t: '\t' }[c] ?? c));
}

const placeholders = (text) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

const used = new Map();
for (const file of walk(join(root, 'resources/js'))) {
  const source = readFileSync(file, 'utf8');
  for (const match of source.matchAll(/\$t\(\s*'((?:[^'\\]|\\.)*)'/g)) {
    const key = unescape(match[1]);
    if (THAI.test(key) && !used.has(key)) used.set(key, relative(root, file));
  }
}

// หัวเรื่อง/คำโปรยของหน้าใน router แปลตอนแสดง (App.vue ส่ง route.meta เข้า $t)
const router = readFileSync(join(root, 'resources/js/router/index.js'), 'utf8');
for (const match of router.matchAll(/(?:title|description):\s*'((?:[^'\\]|\\.)*)'/g)) {
  const key = unescape(match[1]);
  if (THAI.test(key) && !used.has(key)) used.set(key, 'resources/js/router/index.js');
}

test('ทุกข้อความที่ครอบ $t() มีคำแปลภาษาอังกฤษ', () => {
  const missing = [...used].filter(([key]) => !(key in en)).map(([key, file]) => `${file}: ${key}`);
  assert.deepEqual(missing, [], `ยังไม่มีคำแปลใน resources/js/i18n/en.json:\n${missing.join('\n')}`);
});

test('คำแปลใช้ตัวแปรชุดเดียวกับต้นฉบับ และไม่มีภาษาไทยหลงมา', () => {
  const broken = [];
  for (const [key, value] of Object.entries(en)) {
    const forms = value.split(' || ');
    for (const form of forms) {
      if (placeholders(form).join() !== placeholders(key).join()) broken.push(`ตัวแปรไม่ตรง: ${key} → ${form}`);
    }
    if (THAI.test(value)) broken.push(`มีภาษาไทยในคำแปล: ${key} → ${value}`);
    if (key.includes(':licence') && !value.includes(':licence')) broken.push(`ทำ :licence หาย: ${key}`);
  }
  assert.deepEqual(broken, []);
});

console.log(`i18n: ${passed} passed (${used.size} keys, ${Object.keys(en).length} translations)`);
