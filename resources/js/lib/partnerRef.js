// โค้ดพาร์ทเนอร์ช่วยขายที่ลูกค้าเปิดลิงก์เข้ามา (?partner=CODE)
//
// เก็บไว้ในเครื่อง 30 วัน — ลูกค้ามักเปิดลิงก์วันนี้ แล้วกลับมาจองอีกหลายวันต่อมา
// ลิงก์ล่าสุดชนะ ส่งไปกับ POST /bookings เป็น partner_code (เซิร์ฟเวอร์ตัดสินว่า
// ผูกได้หรือไม่ — โค้ดผิด/หยุดใช้/จองให้ตัวเอง แค่ไม่ผูก การจองไม่ล้ม)

const KEY = 'llk_partner_ref';
const TTL_MS = 30 * 24 * 60 * 60 * 1000;

export function normalizePartnerCode(code) {
  return String(code || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 20);
}

export function rememberPartnerCode(code, source = 'link') {
  const normalized = normalizePartnerCode(code);
  if (!normalized) return;
  try {
    localStorage.setItem(KEY, JSON.stringify({ code: normalized, source, at: Date.now() }));
  } catch {
    // โหมดส่วนตัว/ปิดที่เก็บข้อมูล — ไม่จำก็ไม่เป็นไร
  }
}

/** @returns {{ code: string, source: string } | null} */
export function storedPartnerRef() {
  try {
    const raw = JSON.parse(localStorage.getItem(KEY) || 'null');
    if (!raw?.code) return null;
    if (Date.now() - Number(raw.at || 0) > TTL_MS) {
      localStorage.removeItem(KEY);
      return null;
    }
    return { code: raw.code, source: raw.source === 'code' ? 'code' : 'link' };
  } catch {
    return null;
  }
}

export function forgetPartnerCode() {
  try {
    localStorage.removeItem(KEY);
  } catch {
    // ignore
  }
}
