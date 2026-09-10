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
  hours: 'ทุกวัน 09:00 - 20:00 น.',
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

/**
 * เวลาทำการที่ประกาศไว้ เช่น `ทุกวัน 09:00 - 20:00 น.`
 *
 * เคยเขียนต่างกันสี่ที่ (หน้าแรกบอก 24/7 หน้าติดต่อบอก 09:00-20:00 หน้าชำระ
 * เงินบอก 8:00-20:00 structured data บอก 08:00-22:00) — สัญญาที่เว็บตัวเอง
 * แย้งกันสองหน้าถัดมา อ่านแล้วเชื่ออะไรไม่ได้สักอย่าง
 */
export function supportHours() {
  return readMeta('llk:support-hours') || FALLBACK.hours;
}

/**
 * ชื่อและที่อยู่ผู้ประกอบการตามใบอนุญาต — คืนค่าว่างได้
 *
 * เป็นข้อมูลตามเอกสารราชการ เดาแทนไม่ได้ ที่แสดงผลจึงต้องซ่อนบรรทัดที่ว่าง
 * แทนที่จะเติมข้อความหลอก ๆ ลงไป แอดมินกรอกเองที่ /admin/settings
 */
export function operatorName() {
  return readMeta('llk:operator-name');
}

export function operatorAddress() {
  return readMeta('llk:operator-address');
}

/**
 * โปรไฟล์ทางการของแบรนด์ — Navbar กับ Footer เคยมีคนละชุด (Footer ตก TikTok)
 *
 * ต้องตรงกับ config('company.social') ฝั่ง PHP ที่ใช้เป็น sameAs ใน
 * structured data — มี SocialLinksSyncTest คอยจับไม่ให้หลุดจากกัน
 */
export const SOCIAL_LINKS = [
  { key: 'facebook', label: 'Facebook', icon: 'fa-brands fa-facebook-f', href: 'https://www.facebook.com/profile.php?id=61572124170207' },
  { key: 'instagram', label: 'Instagram', icon: 'fa-brands fa-instagram', href: 'https://instagram.com/luilaykhao' },
  { key: 'tiktok', label: 'TikTok', icon: 'fa-brands fa-tiktok', href: 'https://www.tiktok.com/@luilaykhao' },
];
