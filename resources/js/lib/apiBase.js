/**
 * ที่อยู่ API ของเว็บ — ที่เดียวที่อ่าน VITE_API_URL
 *
 * ค่านี้เคยถูกตีความสามแบบ: axios ใช้ตรง ๆ (ต้องมี /api/v1), ปุ่มล็อกอินโซเชียล
 * ตัด /api/v1 ทิ้งเอง และ .env ตัวอย่างไม่มี /api/v1 — build จาก .env ในเครื่อง
 * แล้ว API ยิงผิดที่ ที่นี่รับได้ทั้งสองรูป ("http://host" หรือ "http://host/api/v1")
 * และว่าง = โดเมนเดียวกับหน้าเว็บ
 */
const raw = String(import.meta.env.VITE_API_URL || '').trim().replace(/\/+$/, '');

/** ต้นทางของ API ไม่มี path — '' คือโดเมนเดียวกับหน้าเว็บ */
export const API_ORIGIN = raw.replace(/\/api\/v1$/, '');

/** base URL ของ /api/v1 สำหรับ axios และการยืนยันช่อง websocket */
export const API_BASE_URL = `${API_ORIGIN}/api/v1`;

/**
 * header ที่บอกเซิร์ฟเวอร์ว่า request มาจากเว็บ — ใช้ตั้งชื่อ token ตอนล็อกอิน
 * (token เว็บหมดอายุเมื่อไม่ได้ใช้) และบันทึกช่องทางของใบจอง
 */
export const CLIENT_HEADERS = Object.freeze({ 'X-Client': 'web' });
