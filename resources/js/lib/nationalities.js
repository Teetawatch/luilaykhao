import { DATE_LOCALE } from '../i18n/index.js';

/**
 * ตัวเลือกสัญชาติของผู้เดินทาง — ครบทุกประเทศ ไม่ใช่แค่ปลายทางที่เราพาไป
 *
 * /countries ของ API คือทะเบียน "ปลายทาง" (วีซ่า เบอร์ฉุกเฉิน) มีไม่กี่สิบประเทศ
 * ลูกค้าต่างชาติที่จองทริปในไทยมาจากที่ไหนก็ได้ ส่วน backend รับรหัส ISO สองตัว
 * อักษรใดก็ได้ (CreateBookingRequest: size:2) จึงทำรายการเต็มที่นี่ ชื่อประเทศให้
 * เบราว์เซอร์แปลเองตามภาษาของหน้า (Intl.DisplayNames) ไม่ต้องดูแลชื่อสองภาษาเอง
 */
const CODES = (
  'AD AE AF AG AI AL AM AO AR AT AU AW AZ BA BB BD BE BF BG BH BI BJ BM BN BO BR BS BT BW BY BZ '
  + 'CA CD CF CG CH CI CK CL CM CN CO CR CU CV CY CZ DE DJ DK DM DO DZ EC EE EG ER ES ET FI FJ FM FO FR '
  + 'GA GB GD GE GH GI GL GM GN GQ GR GT GU GW GY HK HN HR HT HU ID IE IL IN IQ IR IS IT JM JO JP '
  + 'KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MG MH MK ML MM MN MO '
  + 'MR MT MU MV MW MX MY MZ NA NC NE NG NI NL NO NP NR NZ OM PA PE PF PG PH PK PL PR PS PT PW PY QA '
  + 'RO RS RU RW SA SB SC SD SE SG SI SK SL SM SN SO SR SS ST SV SY SZ TD TG TH TJ TL TM TN TO TR TT TV TW TZ '
  + 'UA UG US UY UZ VA VC VE VN VU WS XK YE ZA ZM ZW'
).split(' ');

/** 'JP' → 🇯🇵 (regional indicator symbols) */
export function flagOf(code) {
  return String(code)
    .toUpperCase()
    .replace(/[A-Z]/g, (c) => String.fromCodePoint(0x1f1e6 + c.charCodeAt(0) - 65));
}

let cached = null;

/** [{ code, name, flag }] — ไทยขึ้นก่อน ที่เหลือเรียงตามชื่อในภาษาของหน้า */
export function nationalityOptions() {
  if (cached) return cached;

  let display = null;
  try {
    display = new Intl.DisplayNames([DATE_LOCALE], { type: 'region' });
  } catch {
    // เบราว์เซอร์เก่ามาก — แสดงรหัสประเทศแทนชื่อ ยังเลือกได้ถูกต้อง
  }

  const options = CODES.map((code) => ({
    code,
    name: display?.of(code) || code,
    flag: flagOf(code),
  }));

  const home = options.find((o) => o.code === 'TH');
  const rest = options
    .filter((o) => o.code !== 'TH')
    .sort((a, b) => a.name.localeCompare(b.name, DATE_LOCALE));

  cached = home ? [home, ...rest] : rest;

  return cached;
}
