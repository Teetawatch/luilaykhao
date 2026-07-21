/**
 * วันที่ตามเวลาไทย สำหรับเทียบกับคอลัมน์วันที่ในฐานข้อมูล
 *
 * `new Date().toISOString().split('T')[0]` เป็นวันที่แบบ UTC — ช่วง 00:00–07:00 น.
 * ตามเวลาไทยจะได้ "เมื่อวาน" ซึ่งทำให้ตรรกะที่เทียบกับ departure_date (ซึ่งเก็บเป็น
 * เวลาไทย) ตัดสินผิดพอดีในช่วงเช้ามืดของวันเดินทาง
 */
export function bangkokToday() {
  // en-CA ให้รูปแบบ YYYY-MM-DD ตรงกับที่ API ส่งกลับมา
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Bangkok' });
}

/**
 * จำนวนวันเต็มจากวันนี้ (เวลาไทย) ถึงวันที่ที่ระบุ — ติดลบแปลว่าเลยมาแล้ว
 * คืน null เมื่อค่าว่างหรือแปลงไม่ได้
 *
 * เทียบจากสตริง YYYY-MM-DD ทั้งคู่โดยตีความเป็นเที่ยงคืน UTC เหมือนกัน ผลต่างจึงเป็น
 * จำนวนวันเต็มเสมอ ไม่มีเศษจาก timezone ของเบราว์เซอร์มาปน
 */
export function daysUntil(dateStr) {
  const target = toBangkokDate(dateStr);

  if (!target) return null;

  const oneDay = 24 * 60 * 60 * 1000;

  return Math.round((Date.parse(`${target}T00:00:00Z`) - Date.parse(`${bangkokToday()}T00:00:00Z`)) / oneDay);
}

/** วันที่ไทยของ Date/สตริงที่ระบุ — คืน '' เมื่อค่าว่างหรือแปลงไม่ได้ */
export function toBangkokDate(value) {
  if (!value) return '';

  const date = value instanceof Date ? value : new Date(value);

  return Number.isNaN(date.getTime())
    ? ''
    : date.toLocaleDateString('en-CA', { timeZone: 'Asia/Bangkok' });
}
