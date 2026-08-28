/**
 * ข้อมูลติดต่อที่แสดงให้ลูกค้า — เบอร์โทร LINE และอีเมล
 *
 * ค่ามาจาก <meta> ที่ Blade ใส่มากับ shell แบบเดียวกับเลขใบอนุญาต ไม่ได้ยิง API
 * แยก เพราะเบอร์โทรอยู่บน Navbar ทุกหน้า ถ้ารอ API จะเห็นช่องว่างวาบหนึ่งก่อน
 * ทุกครั้งที่เปลี่ยนหน้า
 *
 * แอดมินแก้ได้ที่ /admin/settings แล้วมีผลทันทีที่โหลดหน้าถัดไป ไม่ต้อง deploy
 */

/** ค่าสำรองเมื่ออ่าน meta ไม่ได้ (เช่น หน้าที่เรนเดอร์นอก shell ปกติ) */
const FALLBACK = {
  phone: '062-612-6006',
  line: '@luilaykhao',
  lineUrl: 'https://line.me/R/ti/p/@luilaykhao',
  email: 'luilaykhao.info@gmail.com',
};

function readMeta(name) {
  return document.querySelector(`meta[name="${name}"]`)?.content?.trim() || '';
}

/**
 * เบอร์สำหรับ "อ่าน" — แอดมินอาจกรอกมาแบบมีขีดหรือไม่มีก็ได้ (ค่าเริ่มต้นใน
 * config ไม่มีขีด) เบอร์บ้าน/มือถือไทย 9-10 หลักจึงจัดรูปให้เองเพื่อให้
 * หน้าเว็บหน้าตาเหมือนเดิมไม่ว่าจะกรอกมาแบบไหน
 */
export function supportPhone() {
  const raw = readMeta('llk:support-phone') || FALLBACK.phone;
  if (raw.includes('-') || raw.includes(' ')) return raw;

  const digits = raw.replace(/\D/g, '');
  if (digits.length === 10) return `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6)}`;
  if (digits.length === 9) return `${digits.slice(0, 2)}-${digits.slice(2, 5)}-${digits.slice(5)}`;
  return raw;
}

/** ค่าสำหรับ href="tel:" — ตัวเลขล้วน เครื่องโทรศัพท์บางรุ่นสะดุดกับขีด */
export function supportPhoneHref() {
  return `tel:${(readMeta('llk:support-phone') || FALLBACK.phone).replace(/[^\d+]/g, '')}`;
}

export function supportLine() {
  return readMeta('llk:support-line') || FALLBACK.line;
}

export function supportLineUrl() {
  return readMeta('llk:support-line-url') || FALLBACK.lineUrl;
}

export function supportEmail() {
  return readMeta('llk:support-email') || FALLBACK.email;
}

export function supportEmailHref() {
  return `mailto:${supportEmail()}`;
}
