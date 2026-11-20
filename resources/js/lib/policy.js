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
import { isEnglish } from '../i18n/index.js';

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
  // รอบถูกยกเลิก/เลื่อนเพราะเหตุสุดวิสัย เลือกรอบใหม่ได้ภายในกี่เดือน
  forceMajeurePostponeMonths: 6,
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
  `หากผู้เดินทางขอยกเลิกเอง หลังยืนยันสิทธิ์การเดินทางและชำระเงินแล้ว ทีมงานขอสงวนสิทธิ์<strong>ไม่คืนเงินมัดจำและค่าทริป</strong> เนื่องจากถูกนำไปสำรองจ่ายค่าอุทยาน ที่พัก และยานพาหนะล่วงหน้าไปแล้ว`,
  `หากไม่สะดวกในวันดังกล่าว แจ้งเลื่อนได้ <strong>${POLICY.postponeTimes} ครั้ง</strong> โดยแจ้งล่วงหน้าอย่างน้อย <strong>${POLICY.postponeNoticeDays} วัน</strong> ก่อนวันเดินทางเดิม`,
  `เปลี่ยนตัวผู้เดินทางได้โดยหาผู้เดินทางแทน แจ้งรายละเอียดให้ทีมงานล่วงหน้าอย่างน้อย <strong>${POLICY.substituteNoticeDays} วัน</strong>`,
  `ใบจองแบบมัดจำ ต้องชำระยอดคงเหลือก่อนเดินทางอย่างน้อย <strong>${POLICY.balanceDueDays} วัน</strong> มิฉะนั้นถือว่าสละสิทธิ์การเดินทาง`,
  `หากทีมงานเป็นฝ่ายยกเลิกรอบเดินทางด้วยเหตุของทีมงานเอง เช่น ผู้เดินทางไม่ถึงจำนวนขั้นต่ำ คืนเงิน <strong>${POLICY.operatorCancelRefundPercent}%</strong> เต็มจำนวน`,
  `หากรอบเดินทางต้องยกเลิกหรือเลื่อนเพราะ<strong>เหตุสุดวิสัยที่ไม่ได้เกิดจากทีมงาน</strong> เช่น ภัยธรรมชาติ น้ำป่า พายุ หรือหน่วยงานราชการ/อุทยานสั่งปิดพื้นที่หรือประกาศเตือนภัย ทีมงานจะ<strong>เลื่อนวันเดินทางให้โดยไม่มีค่าธรรมเนียม</strong> เลือกรอบใหม่ของทริปเดิมได้ภายใน <strong>${POLICY.forceMajeurePostponeMonths} เดือน</strong>นับจากวันเดินทางเดิม และไม่นับรวมกับสิทธิ์เลื่อนตามข้อ 2 กรณีนี้<strong>ไม่คืนเงินค่าทริป</strong> เว้นแต่ทีมงานไม่มีรอบเดินทางให้เลือกภายในระยะเวลาดังกล่าว จะคืนเงินหลังหักค่าใช้จ่ายที่จ่ายไปแล้วจริงและเรียกคืนไม่ได้ พร้อมแสดงหลักฐานค่าใช้จ่าย`,
  `ระหว่างทริป ทีมงานจะถ่ายรูปผู้เดินทางทุกคนและมอบรูปให้ผู้เดินทางเป็นที่ระลึก โดยผู้เดินทาง<strong>ยินยอมให้ทีมงานนำรูปดังกล่าวไปเผยแพร่บนช่องทางของลุยเลเขา</strong> เช่น Instagram, Facebook และ TikTok รวมถึง<strong>ใช้ประชาสัมพันธ์และโฆษณาขายทริป</strong>ได้โดยไม่มีค่าตอบแทน หากไม่ประสงค์ให้เผยแพร่รูปของตนเอง แจ้งทีมงานได้ก่อนหรือระหว่างทริป และขอให้ลบรูปของตนเองออกจากโพสต์ที่เผยแพร่แล้วได้`,
];

/**
 * BOOKING_TERMS ฉบับภาษาอังกฤษสำหรับหน้า /en — ใช้ตัวเลขจาก POLICY ชุดเดียวกัน
 * ต้องเรียงข้อและพูดเหมือนฉบับไทยทุกข้อ (ฉบับไทยคือฉบับที่ผูกพัน
 * LegalPolicySyncTest เทียบเฉพาะฉบับไทย) แก้ข้อไหนฝั่งไทย ต้องแก้ข้อเดียวกันที่นี่ด้วย
 */
export const BOOKING_TERMS_EN = [
  `If a traveller cancels after their place is confirmed and paid for, <strong>the deposit and trip price are non-refundable</strong>, because they have already been paid out in advance for park fees, accommodation and transport.`,
  `If the date no longer suits you, you may postpone <strong>${POLICY.postponeTimes} time</strong>, with at least <strong>${POLICY.postponeNoticeDays} days</strong> notice before the original departure date.`,
  `You may hand your place to a substitute traveller; please send their details to our team at least <strong>${POLICY.substituteNoticeDays} days</strong> in advance.`,
  `For deposit bookings, the remaining balance must be paid at least <strong>${POLICY.balanceDueDays} days</strong> before departure, otherwise the place is forfeited.`,
  `If our team cancels a departure for reasons on our side, such as too few travellers, you receive a <strong>${POLICY.operatorCancelRefundPercent}%</strong> full refund.`,
  `If a departure must be cancelled or postponed due to <strong>force majeure not caused by our team</strong>, such as natural disasters, flash floods, storms, or a government/national park order closing the area or issuing a warning, we will <strong>move your trip to another date free of charge</strong>. You may choose a new round of the same trip within <strong>${POLICY.forceMajeurePostponeMonths} months</strong> of the original date, and this does not count towards the postponement in item 2. In this case <strong>the trip price is not refunded</strong>, unless we have no round to offer within that period, in which case we refund the amount after deducting costs actually paid that cannot be recovered, with evidence of those costs.`,
  `During the trip our team photographs every traveller and shares the photos with you as a keepsake. Travellers <strong>agree that our team may publish these photos on Luilaykhao's channels</strong>, such as Instagram, Facebook and TikTok, and <strong>use them to promote and advertise trips</strong> without compensation. If you do not want your photos published, tell our team before or during the trip, and you may ask us to remove your photos from posts already published.`,
];

/** เงื่อนไขในภาษาของหน้า (HTML มีแค่ <strong>) */
export function bookingTerms() {
  return isEnglish ? BOOKING_TERMS_EN : BOOKING_TERMS;
}

/** ข้อความเดียวกันแบบไม่มีแท็ก สำหรับที่ที่เรนเดอร์เป็นข้อความล้วน */
export function bookingTermsPlain() {
  return bookingTerms().map((line) => line.replace(/<\/?strong>/g, ''));
}
