/**
 * ลิงก์ร้านแอป — มาจาก <meta> ที่ Blade ใส่มากับ shell แบบเดียวกับข้อมูลติดต่อ
 * (ดู App\Support\AppLinks และ resources/views/app.blade.php)
 *
 * ไม่ยิง API แยกเพราะเป็นค่าคงที่ที่ไม่เคยเปลี่ยนระหว่างเซสชัน และการ์ดชวนโหลด
 * ไม่ควรกะพริบขึ้นมาทีหลังตอนหน้าโหลดเสร็จไปแล้ว
 *
 * ค่าสำรองคือลิงก์จริงของแอปทั้งสองร้าน เผื่อหน้าไหนเรนเดอร์นอก shell ปกติ —
 * ปล่อยให้ปุ่มพาไปหน้าว่างแย่กว่าค่าที่อาจเก่าไปหนึ่งเวอร์ชัน
 */

const FALLBACK = {
  ios: 'https://apps.apple.com/th/app/luilaykhao/id6770391928?l=th',
  android: 'https://play.google.com/store/apps/details?id=com.luilaykhao.app',
};

function readMeta(name) {
  return document.querySelector(`meta[name="${name}"]`)?.content?.trim() || '';
}

export function appStoreUrl() {
  return readMeta('llk:app-ios-url') || FALLBACK.ios;
}

export function playStoreUrl() {
  return readMeta('llk:app-android-url') || FALLBACK.android;
}

/**
 * ร้านที่ตรงกับเครื่องที่กำลังเปิดอยู่ — คืน null บนเดสก์ท็อป เพื่อให้หน้าที่เรียก
 * เลือกได้เองว่าจะโชว์สองปุ่มหรือไม่โชว์เลย
 */
export function storeUrlForThisDevice() {
  const ua = navigator.userAgent || '';

  if (/iPhone|iPad|iPod/i.test(ua)) return appStoreUrl();
  if (/Android/i.test(ua)) return playStoreUrl();

  return null;
}
