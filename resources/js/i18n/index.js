/**
 * ภาษาของเว็บ (ไทย / อังกฤษ)
 *
 * ภาษามาจาก URL อย่างเดียว: หน้าอังกฤษอยู่ใต้ /en/… ส่วนหน้าไทยใช้ URL เดิมทั้งหมด
 * router ตั้ง base เป็น /en เมื่อเปิดหน้าอังกฤษ ลิงก์ภายในทุกตัว (router-link,
 * router.push('/trips')) จึงอยู่ในภาษาเดิมต่อเองโดยไม่ต้องแก้ทีละที่ การสลับภาษา
 * เป็นการโหลดหน้าใหม่ — ภาษาจึงคงที่ตลอดอายุของหน้า และข้อความที่ประกอบตอนโหลด
 * โมดูล (ค่าคงที่ ตัวเลือกในฟอร์ม) ไม่ต้องคอยฟังการเปลี่ยนภาษา
 *
 * Google เก็บหน้าแต่ละภาษาแยกกันได้ (hreflang ฝั่ง Laravel ดู App\Support\SiteLocale)
 * และลิงก์ที่ส่งต่อกันเปิดเป็นภาษาเดิมเสมอ
 *
 * คำแปลอยู่ใน en.json (คีย์ = ข้อความไทย) โหลดเฉพาะคนที่เปิดหน้าอังกฤษ — ลูกค้าไทย
 * ไม่ต้องดาวน์โหลดอะไรเพิ่ม
 */
import { createTranslator } from './translate.js';

export const THAI = 'th';
export const ENGLISH = 'en';
export const LOCALES = [THAI, ENGLISH];

/** คีย์ใน localStorage ของภาษาที่ผู้ใช้เลือกเอง (กดสลับหรือปิดแถบชวน) */
export const PREF_KEY = 'llk_locale';

const EN_PREFIX = /^\/en(?=\/|$)/;

/** path ไม่มี /en นำหน้า: "/en/trips/x" → "/trips/x", "/en" → "/" */
export function stripLocale(path) {
  const stripped = String(path || '/').replace(EN_PREFIX, '');

  return stripped === '' ? '/' : stripped;
}

/** path ของภาษาที่ระบุ: localePath('/trips', 'en') → "/en/trips" */
export function localePath(path, lang = LOCALE) {
  const clean = stripLocale(path);
  if (lang !== ENGLISH) return clean;

  return clean === '/' ? '/en' : `/en${clean}`;
}

function detectLocale() {
  if (typeof window === 'undefined') return THAI;

  return EN_PREFIX.test(window.location.pathname) ? ENGLISH : THAI;
}

/** ภาษาของหน้านี้ — คงที่จนกว่าจะโหลดหน้าใหม่ */
export const LOCALE = detectLocale();
export const isEnglish = LOCALE === ENGLISH;

/** base ของ vue-router */
export const ROUTER_BASE = isEnglish ? '/en/' : '/';

/**
 * locale สำหรับ toLocaleDateString / toLocaleString / Intl
 * en-GB ได้ "5 Jan 2026" (วันก่อนเดือน แบบที่คนไทยกับยุโรปคุ้น) และปี ค.ศ.
 */
export const DATE_LOCALE = isEnglish ? 'en-GB' : 'th-TH';

let translate = createTranslator({});

/** แปลข้อความไทยเป็นภาษาของหน้า (ไม่มีคำแปล = ข้อความไทยเดิม) */
export function $t(text, params) {
  return translate(text, params);
}

/** โหลดคำแปลก่อน mount — หน้าไทยคืนทันที */
export async function loadMessages() {
  if (!isEnglish) return;

  try {
    const messages = (await import('./en.json')).default;
    translate = createTranslator(messages);
  } catch (error) {
    // โหลดคำแปลไม่ได้ก็ยังใช้เว็บได้ เป็นภาษาไทย ดีกว่าหน้าขาว
    console.error('Failed to load English messages', error);
  }
}

function readPref() {
  try {
    const value = window.localStorage.getItem(PREF_KEY);

    return LOCALES.includes(value) ? value : null;
  } catch {
    return null;
  }
}

function writePref(lang) {
  try {
    window.localStorage.setItem(PREF_KEY, lang);
  } catch {
    // private mode — ไม่จำก็ไม่เป็นไร URL ยังบอกภาษาอยู่
  }
}

/** ผู้ใช้เคยเลือกภาษาเองแล้วหรือยัง (null = ยังไม่เคย) */
export function savedLocale() {
  return typeof window === 'undefined' ? null : readPref();
}

export function rememberLocale(lang) {
  if (LOCALES.includes(lang)) writePref(lang);
}

/** สลับภาษา — โหลดหน้าเดิมในภาษาใหม่ (query/hash ไปด้วย) */
export function switchLocale(lang) {
  if (!LOCALES.includes(lang)) return;
  rememberLocale(lang);
  if (lang === LOCALE) return;

  const { pathname, search, hash } = window.location;
  window.location.assign(`${localePath(pathname, lang)}${search}${hash}`);
}

/**
 * คนที่เคยเลือกภาษาอังกฤษไว้ แล้วเปิดลิงก์ภาษาไทย (เพื่อนส่งมา, กดจากอีเมล)
 * พาไปหน้าเดียวกันในภาษาอังกฤษ — คืน true เมื่อกำลังเปลี่ยนหน้า (ไม่ต้อง mount)
 * หลังบ้าน (/admin) เป็นภาษาไทยเสมอ ไม่พาไป
 */
export function applySavedLocale() {
  if (typeof window === 'undefined' || isEnglish) return false;
  if (readPref() !== ENGLISH) return false;

  const { pathname, search, hash } = window.location;
  if (pathname.startsWith('/admin')) return false;

  window.location.replace(`${localePath(pathname, ENGLISH)}${search}${hash}`);

  return true;
}

/** URL เต็มของหน้านี้ในภาษาที่ระบุ — canonical / og:url / hreflang */
export function absoluteUrl(path, lang = LOCALE) {
  return `${window.location.origin}${localePath(path, lang)}`;
}

/** ใส่ $t, $locale, $isEnglish ให้ทุก template */
export function installI18n(app) {
  app.config.globalProperties.$t = $t;
  app.config.globalProperties.$locale = LOCALE;
  app.config.globalProperties.$isEnglish = isEnglish;
  app.config.globalProperties.$dateLocale = DATE_LOCALE;

  if (typeof document !== 'undefined') {
    document.documentElement.lang = LOCALE;
  }
}
