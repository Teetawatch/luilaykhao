/* ตัวช่วยรันแอป LIFF จริงบน jsdom
 *
 * โหลดไฟล์ใน public/liff/ ก้อนเดียวเหมือนตอนเบราว์เซอร์โหลดหลาย <script> (สคริปต์
 * classic ใช้ global lexical scope ร่วมกัน — eval ทีละไฟล์ไม่ใช่แบบนั้น) แล้ว mock
 * ทั้ง LIFF SDK และ fetch เพื่อให้ยิง API จริงไม่ได้
 */
import { JSDOM } from 'jsdom';
import fs from 'fs';

const ROOT = new URL('../../public/liff/', import.meta.url).pathname;
const FILES = ['app.js', 'booking.js', 'payment.js', 'bookings.js'];

export function makeWorld(routes, opts = {}) {
  const dom = new JSDOM(fs.readFileSync(ROOT + 'index.html', 'utf8'), {
    url: 'https://example.com/liff/' + (opts.search || ''),
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const w = dom.window;
  const calls = [];
  const bodies = [];
  const alerts = [];

  w.fetch = async (url, o = {}) => {
    const path = String(url).replace('https://example.com/api/v1', '');
    const key = `${o.method || 'GET'} ${path.split('?')[0]}`;
    calls.push(key);
    if (o.body) bodies.push({ key, body: o.body, query: path.split('?')[1] || '' });
    const hit = routes[key];
    if (!hit) {
      if (!opts.quiet) console.log('  ⚠ ไม่มี route จำลอง:', key);
      return { ok: true, status: 200, json: async () => ({ data: null }) };
    }
    if (hit.__status && hit.__status >= 400) {
      return { ok: false, status: hit.__status, json: async () => hit };
    }
    return { ok: true, status: 200, json: async () => hit };
  };

  w.liff = {
    init: async () => {},
    isLoggedIn: () => true,
    getAccessToken: () => 'line-token',
    getProfile: async () => ({ displayName: 'คุณลูกค้า', pictureUrl: '' }),
    getOS: () => opts.os || 'ios',
    isApiAvailable: () => !!opts.shareTargetPicker,
    shareTargetPicker: async () => ({ status: 'success' }),
    openWindow: (o) => { opened.push(o.url); },
  };
  const opened = [];

  w.alert = (m) => { alerts.push(String(m)); if (!opts.quiet) console.log('  [alert]', String(m).slice(0, 80)); };
  w.confirm = () => opts.confirm ?? true;
  w.scrollTo = () => {};
  w.URL.createObjectURL = () => 'blob:x';
  w.LIFF_CONFIG = { liffId: '1234-abcd', apiBaseUrl: 'https://example.com/api/v1' };
  w.Element.prototype.scrollIntoView = function () {};

  w.eval(FILES.map((f) => fs.readFileSync(ROOT + f, 'utf8')).join('\n;\n'));

  return {
    w, calls, bodies, alerts, opened,
    $: (sel) => w.document.querySelector(sel),
    $$: (sel) => [...w.document.querySelectorAll(sel)],
    click: (elOrSel) => {
      const target = typeof elOrSel === 'string' ? w.document.querySelector(elOrSel) : elOrSel;
      if (!target) throw new Error('ไม่พบปุ่ม: ' + elOrSel);
      target.dispatchEvent(new w.Event('click'));
    },
    text: () => w.document.getElementById('app').textContent.replace(/\s+/g, ' ').trim(),
    sheetText: () => (w.document.querySelector('.sheet-overlay')?.textContent || '').replace(/\s+/g, ' ').trim(),
  };
}

export const wait = (ms = 60) => new Promise((r) => setTimeout(r, ms));

let failures = 0;
export const step = (name, fn) => {
  try { fn(); console.log('✓', name); } catch (e) { failures++; console.log('✗', name, '—', e.message); }
};
export const assert = (cond, message) => { if (!cond) throw new Error(message); };
export const finish = () => {
  console.log(failures ? `\n❌ ล้มเหลว ${failures} ข้อ` : '\n✅ ผ่านทั้งหมด');
  process.exit(failures ? 1 : 0);
};
