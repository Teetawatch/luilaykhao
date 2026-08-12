/**
 * ใบอนุญาตนำเที่ยว — เลขที่และรูปใบจริง
 *
 * ค่ามาจาก <meta> ที่ Blade ใส่มากับ shell ตอนเสิร์ฟหน้าเว็บ ไม่ได้ยิง API แยก
 * เพราะแถบบนสุดของ Navbar แสดงเลขนี้ทุกหน้า ถ้ารอ API จะเห็นแถบว่างวาบหนึ่ง
 * ก่อนทุกครั้งที่เปลี่ยนหน้า
 *
 * แอดมินแก้ได้ที่ /admin/settings แล้วมีผลทันทีที่โหลดหน้าถัดไป ไม่ต้อง deploy
 */

/** ค่าสำรองเมื่ออ่าน meta ไม่ได้ (เช่น หน้าที่เรนเดอร์นอก shell ปกติ) */
const FALLBACK_LICENCE_NO = '11/13855';

function readMeta(name) {
  return document.querySelector(`meta[name="${name}"]`)?.content?.trim() || '';
}

export function licenceNo() {
  return readMeta('llk:licence-no') || FALLBACK_LICENCE_NO;
}

export function licenceImageUrl() {
  return readMeta('llk:licence-image');
}

/** แทน `:licence` ในข้อความ SEO — รูปแบบเดียวกับที่ SeoMeta ทำฝั่ง PHP */
export function withLicence(text) {
  return typeof text === 'string' && text.includes(':licence')
    ? text.replace(/:licence/g, licenceNo())
    : text;
}
