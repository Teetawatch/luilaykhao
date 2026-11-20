/**
 * ตัวแปลข้อความของเว็บ — ส่วนที่ไม่แตะเบราว์เซอร์ (ทดสอบด้วย node ได้)
 *
 * ภาษาไทยคือต้นฉบับ: โค้ดเขียนข้อความไทยตรง ๆ แล้วครอบด้วย $t('…') ส่วนคำแปล
 * อยู่ใน i18n/en.json ที่ใช้ข้อความไทยเป็นคีย์ — แบบเดียวกับ lang/en.json ฝั่ง
 * Laravel ข้อความที่ยังไม่มีคำแปลจึงออกมาเป็นไทยเหมือนเดิม ไม่มีวันว่าง
 *
 * ตัวแปรเขียนเป็น {ชื่อ}:  $t('เหลือ {n} ที่', { n: 3 })
 * คำแปลที่มีรูปเอกพจน์/พหูพจน์ เขียนคั่นด้วย " || " แล้วเลือกจาก n:
 *   "เหลือ {n} ที่": "{n} seat left || {n} seats left"
 * (ไม่ใช่ " | " ตัวเดียว เพราะหัวเรื่องหน้าเว็บใช้ "ชื่อ | ลุยเลเขา" อยู่แล้ว)
 */

const PLACEHOLDER = /\{(\w+)\}/g;

export function interpolate(text, params) {
  if (!params) return text;

  return text.replace(PLACEHOLDER, (whole, name) =>
    params[name] === undefined || params[name] === null ? whole : String(params[name]),
  );
}

/** ตัวเลขที่ใช้เลือกรูปพหูพจน์: n หรือ count ก่อน ไม่มีก็ใช้ตัวแปรตัวเลขตัวแรก */
function pluralCount(params) {
  if (!params) return NaN;
  if (params.n !== undefined) return Number(params.n);
  if (params.count !== undefined) return Number(params.count);
  const first = Object.values(params).find((value) => typeof value === 'number' || /^-?\d+$/.test(String(value)));

  return first === undefined ? NaN : Number(first);
}

export const PLURAL_SEPARATOR = ' || ';

/** เลือกรูปเอกพจน์/พหูพจน์ (ไม่มี " || " = ใช้ทั้งข้อความ) */
export function pickPlural(text, params) {
  if (!text.includes(PLURAL_SEPARATOR)) return text;

  const forms = text.split(PLURAL_SEPARATOR);
  const n = pluralCount(params);
  if (!Number.isFinite(n)) return forms[forms.length - 1];

  return Math.abs(n) === 1 ? forms[0] : forms[forms.length - 1];
}

export function createTranslator(messages = {}) {
  return function translate(text, params) {
    if (text === null || text === undefined || text === '') return '';
    const source = String(text);
    const found = Object.prototype.hasOwnProperty.call(messages, source) ? messages[source] : null;
    const chosen = typeof found === 'string' && found !== '' ? pickPlural(found, params) : source;

    return interpolate(chosen, params);
  };
}
