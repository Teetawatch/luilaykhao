/**
 * รูปบนการ์ดทริป — `card_image` ที่เซิร์ฟเวอร์ย่อไว้ (ด้านยาว 1200px) ก่อน ถ้ายังไม่มี
 * (รูปอยู่นอก storage, ระหว่างรอย่อ, หรือข้อมูลทริปที่ไม่ได้มาจาก TripResource)
 * ค่อยใช้ thumbnail/รูปปกขนาดเต็มแบบเดิม — เดิมการ์ดโหลดรูปเต็มทีละหลาย MB
 */
export function tripCardImage(trip) {
  if (!trip || typeof trip !== 'object') return null;
  return trip.card_image || trip.thumbnail_image || trip.cover_image || null;
}
