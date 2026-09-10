/**
 * เงื่อนไขที่ผูกพันลูกค้า — แหล่งเดียวของทั้งเว็บ
 *
 * เคยเขียนกระจายอยู่สามที่แล้วขัดกันเอง: หน้า /terms บอกว่ายกเลิกล่วงหน้า
 * 30 วันคืนเต็มจำนวน ส่วนกล่องยืนยันก่อนจองกับหน้าชำระเงินบอกไม่คืนทุกกรณี
 * ลูกค้าแคปสองหน้ามาเทียบเมื่อไหร่คือเถียงไม่ขึ้น เพราะเงื่อนไขที่ประกาศ
 * บนเว็บผูกพันเราตามกฎหมายผู้บริโภค
 *
 * ตัวเลขชุดนี้ต้องตรงกับ config/legal.php เสมอ — LegalPolicySyncTest
 * (ฝั่ง PHP) อ่านไฟล์นี้แล้วเทียบทีละค่า ถ้าแก้ที่เดียวเทสต์จะแดง
 */

import { thaiLong } from './thaiDate';

export const POLICY = {
  // ลูกค้าขอยกเลิกเอง: เงินที่ชำระแล้วไม่คืน
  customerCancelRefundable: false,
  // เลื่อนวันเดินทางได้กี่ครั้ง และต้องแจ้งล่วงหน้ากี่วัน
  postponeTimes: 1,
  postponeNoticeDays: 30,
  // เปลี่ยนตัวผู้เดินทางต้องแจ้งล่วงหน้ากี่วัน
  substituteNoticeDays: 15,
  // ใบจองแบบมัดจำ ต้องชำระยอดคงเหลือก่อนเดินทางกี่วัน
  balanceDueDays: 15,
  // จองแล้วมีเวลาชำระเงินกี่นาทีก่อนที่นั่งถูกคืนเข้าระบบ
  paymentWindowMinutes: 10,
  // เราเป็นฝ่ายยกเลิกรอบเอง คืนกี่เปอร์เซ็นต์
  operatorCancelRefundPercent: 100,
  // ขอเปลี่ยนวันเดินทางเองผ่านระบบได้ก่อนออกเดินทางกี่วัน
  rescheduleLeadDays: 20,
};

function readMeta(name) {
  return document.querySelector(`meta[name="${name}"]`)?.content?.trim() || '';
}

/** เวอร์ชันเงื่อนไขที่ประกาศใช้อยู่ (YYYY-MM-DD) — ใบจองบันทึกเลขนี้ไว้ */
export function termsVersion() {
  return readMeta('llk:terms-version');
}

export function privacyVersion() {
  return readMeta('llk:privacy-version');
}

/** วันที่ประกาศใช้ในรูปแบบไทย — คืนค่าว่างเมื่ออ่าน meta ไม่ได้ */
export function termsEffectiveLabel() {
  const version = termsVersion();

  return version ? thaiLong(version) : '';
}

export function privacyEffectiveLabel() {
  const version = privacyVersion();

  return version ? thaiLong(version) : '';
}

/**
 * ข้อตกลงหลักก่อนยืนยันการจอง — ข้อความชุดเดียวกับที่หน้า /terms แสดง
 *
 * ใช้ทั้งในกล่องยืนยันก่อนจองและหน้าเงื่อนไข เพื่อไม่ให้สองที่พูดคนละอย่าง
 * อีก HTML ในนี้จำกัดไว้แค่ <strong> เพราะกล่องยืนยันเรนเดอร์ด้วย innerHTML
 */
export const BOOKING_TERMS = [
  `เมื่อยืนยันสิทธิ์การเดินทางและชำระเงินแล้ว ทีมงานขอสงวนสิทธิ์<strong>ไม่คืนเงินมัดจำและค่าทริปทุกกรณี</strong> เนื่องจากถูกนำไปสำรองจ่ายค่าอุทยาน ที่พัก และยานพาหนะล่วงหน้าไปแล้ว`,
  `หากไม่สะดวกในวันดังกล่าว แจ้งเลื่อนได้ <strong>${POLICY.postponeTimes} ครั้ง</strong> โดยแจ้งล่วงหน้าอย่างน้อย <strong>${POLICY.postponeNoticeDays} วัน</strong> ก่อนวันเดินทางเดิม`,
  `เปลี่ยนตัวผู้เดินทางได้โดยหาผู้เดินทางแทน แจ้งรายละเอียดให้ทีมงานล่วงหน้าอย่างน้อย <strong>${POLICY.substituteNoticeDays} วัน</strong>`,
  `ใบจองแบบมัดจำ ต้องชำระยอดคงเหลือก่อนเดินทางอย่างน้อย <strong>${POLICY.balanceDueDays} วัน</strong> มิฉะนั้นถือว่าสละสิทธิ์การเดินทาง`,
  `หากทีมงานเป็นฝ่ายยกเลิกรอบเดินทาง คืนเงิน <strong>${POLICY.operatorCancelRefundPercent}%</strong> เต็มจำนวนทุกกรณี`,
];

/** ข้อความเดียวกันแบบไม่มีแท็ก สำหรับที่ที่เรนเดอร์เป็นข้อความล้วน */
export function bookingTermsPlain() {
  return BOOKING_TERMS.map((line) => line.replace(/<\/?strong>/g, ''));
}
