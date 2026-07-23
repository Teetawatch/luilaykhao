/**
 * วันที่ภาษาไทย (วัน + เดือนไทย + พ.ศ.) — ฝั่งเว็บใช้ locale th-TH ของเบราว์เซอร์
 * ซึ่งให้ปีพุทธศักราชมาเอง จึงไม่ต้องบวก 543 ซ้ำ
 *
 * ใช้คู่กับ App\Support\ThaiDate ฝั่ง PHP ให้เว็บกับ API พูดวันที่แบบเดียวกัน
 */

function toDate(value) {
  if (!value) return null;

  const date = value instanceof Date ? value : new Date(value);

  return Number.isNaN(date.getTime()) ? null : date;
}

/** 5 ม.ค. 2569 */
export function thaiShort(value) {
  const date = toDate(value);

  return date
    ? date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' })
    : '';
}

/** 5 มกราคม 2569 */
export function thaiLong(value) {
  const date = toDate(value);

  return date
    ? date.toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' })
    : '';
}

/** 5 ม.ค. — ไม่มีปี ใช้เมื่อบริบทบอกปีอยู่แล้ว */
export function thaiDayMonth(value) {
  const date = toDate(value);

  return date
    ? date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' })
    : '';
}

/** ชื่อเดือนไทยแบบย่อทั้ง 12 เดือน เรียงตามลำดับปฏิทิน */
export const THAI_MONTHS_SHORT = [
  'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
  'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
];

/** ชื่อเดือนไทยเต็มทั้ง 12 เดือน */
export const THAI_MONTHS_LONG = [
  'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
  'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
];
