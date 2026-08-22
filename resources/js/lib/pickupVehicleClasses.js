import api from './axios';

/**
 * ไกด์ประเภทรถรับ-ส่งจุดรับต่างภูมิภาค
 *
 * หน้าเดียววางการ์ดนี้ได้หลายจุด (ขั้นตอนเลือกจุดรับ + ขั้นตอนกรอกผู้เดินทาง)
 * จึงแคชคำขอไว้ระดับโมดูล ให้ทุกจุดใช้ผลลัพธ์เดียวกันโดยยิงครั้งเดียว
 *
 * ล้มเหลว = คืนลิสต์ว่างและเปิดให้ลองใหม่รอบหน้า เพราะเป็นข้อมูลประกอบ
 * ไม่ควรทำให้หน้าจองพัง
 */
let pending = null;

export function loadPickupVehicleClasses() {
  if (!pending) {
    pending = api
      .get('/pickup-vehicle-classes')
      .then((res) => res.data?.data ?? [])
      .catch(() => {
        pending = null;
        return [];
      });
  }

  return pending;
}

/** ประเภทรถที่ครอบคลุมผู้เดินทาง `pax` คน (max_pax = null คือ "ขึ้นไป") */
export function pickupVehicleClassFor(classes, pax) {
  const count = Number(pax) || 0;
  if (!count) return null;

  return (
    classes.find(
      (c) => count >= c.min_pax && (c.max_pax === null || count <= c.max_pax),
    ) ?? null
  );
}
