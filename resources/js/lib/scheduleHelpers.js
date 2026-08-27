// Pure, framework-agnostic helpers for trip-schedule availability and display.
// Shared between TripDetailPage.vue and ScheduleCalendar.vue.

// ที่นั่งที่จองได้จริง — API หัก `bookable_seats` ที่กันไว้ให้คนในคิวรอที่ได้รับ
// สิทธิ์แล้วออกไปให้ ถ้า payload ยังไม่มีค่านี้ก็ตกกลับไปใช้ available_seats
export function bookableSeats(schedule) {
  const held = schedule?.bookable_seats;
  return Number((held ?? schedule?.available_seats) || 0);
}

export function hasAvailableSeats(schedule) {
  return bookableSeats(schedule) > 0;
}

export function isScheduleBookable(schedule) {
  if (schedule?.is_charter) return false;
  if (hasAvailableSeats(schedule)) return true;
  // ที่นั่งบนรถหมดแล้ว แต่ยังจอยทริปได้ถ้าโควตาจอยยังเหลือ
  return Boolean(schedule?.join_trip_enabled) && !joinTripFull(schedule);
}

// ─── จอยทริป: โควตาแยกจากที่นั่งบนรถ ───
// join_trip_seats = null คือแอดมินไม่ได้กำหนดเพดาน = รับไม่จำกัด
// ซึ่งแปลว่าบอกได้แค่ "จอยแล้วกี่คน" ไม่มี "ว่างกี่ที่"

export function joinTripSeats(schedule) {
  const n = schedule?.join_trip_seats;
  return n === null || n === undefined ? null : Number(n);
}

export function joinTripBookedSeats(schedule) {
  return Number(schedule?.join_trip_booked_seats || 0);
}

export function joinTripAvailableSeats(schedule) {
  const total = joinTripSeats(schedule);
  if (total === null) return null;
  const fromApi = schedule?.join_trip_available_seats;
  return fromApi === null || fromApi === undefined
    ? Math.max(0, total - joinTripBookedSeats(schedule))
    : Number(fromApi);
}

export function joinTripFull(schedule) {
  if (!schedule?.join_trip_enabled) return false;
  const left = joinTripAvailableSeats(schedule);
  return left !== null && left <= 0;
}

export function joinTripSeatLabel(schedule) {
  const booked = joinTripBookedSeats(schedule);
  const total = joinTripSeats(schedule);
  if (total === null) return `จอยแล้ว ${booked} ท่าน`;
  const left = joinTripAvailableSeats(schedule);
  return left > 0
    ? `จอยแล้ว ${booked}/${total} ท่าน · ว่าง ${left} ที่`
    : 'จอยทริปเต็มแล้ว';
}

export function scheduleAvailabilityBadgeClass(schedule) {
  if (schedule?.is_charter) return 'bg-violet-50 text-violet-700 border-violet-200';
  if (!hasAvailableSeats(schedule)) {
    return 'bg-red-500 text-white border-red-600';
  }
  if (bookableSeats(schedule) <= 3) {
    return 'bg-red-50 text-red-600 border-red-200';
  }
  if (bookableSeats(schedule) <= 5) {
    return 'bg-amber-50 text-amber-600 border-amber-200';
  }
  return 'bg-[#E8F5EC] text-[#2D7A4F] border-[#2D7A4F]/20';
}

export function scheduleAvailabilityTextClass(schedule) {
  if (bookableSeats(schedule) < 3) return 'text-red-500';
  return isScheduleBookable(schedule) ? 'text-[var(--color-accent)]' : 'text-red-500';
}

export function scheduleAvailabilityDotClass(schedule) {
  if (bookableSeats(schedule) < 3) return 'bg-red-500 animate-pulse';
  return isScheduleBookable(schedule) ? 'bg-green-500 animate-pulse' : 'bg-red-500';
}

export function scheduleAvailabilityLabel(schedule) {
  if (schedule?.is_charter) return 'รอบเหมา';
  if (hasAvailableSeats(schedule)) return `ว่าง ${bookableSeats(schedule)} ที่`;
  // ที่นั่งบนรถเต็ม แต่ถ้าจอยทริปยังเปิดและยังมีโควตาก็ยังจองได้อยู่
  if (schedule?.join_trip_enabled && !joinTripFull(schedule)) {
    const left = joinTripAvailableSeats(schedule);
    return left === null ? 'จอยทริปได้' : `จอยทริปว่าง ${left} ที่`;
  }
  return 'เต็มแล้ว';
}

// ─── Trip-level scarcity (uses TripResource `seats_left`) ───
// seats_left = lowest available among the trip's OPEN upcoming rounds.
// null = no open/upcoming round, or all such rounds are full.

export function tripSeatsLeft(trip) {
  const n = trip?.seats_left;
  return typeof n === 'number' ? n : null;
}

// 'last' (≤2) | 'soon' (≤5) | null
// การ์ดทริปในหน้ารวมใช้เฉพาะระดับ 'last' และแสดงเป็นตัวหนังสือเฉย ๆ ไม่กะพริบ
// ส่วน 'soon' เหลือไว้ให้หน้าที่พูดถึงรอบเดียวเจาะจง (รายละเอียดทริป/ป๊อปอัพ)
export function tripScarcityLevel(trip) {
  const n = tripSeatsLeft(trip);
  if (n === null || n <= 0) return null;
  if (n <= 2) return 'last';
  if (n <= 5) return 'soon';
  return null;
}

export function tripScarcityLabel(trip) {
  const n = tripSeatsLeft(trip);
  const level = tripScarcityLevel(trip);
  if (!level) return null;
  return level === 'last' ? `เหลือ ${n} ที่นั่งสุดท้าย` : `ใกล้เต็ม · เหลือ ${n} ที่`;
}

export function formatDate(d) {
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function getSortedPickupPoints(points) {
  if (!points || !Array.isArray(points)) return [];

  const extractTimeValue = (pt) => {
    // Try to find time in notes or location (HH:mm or HH.mm)
    const text = `${pt.pickup_location || ''} ${pt.notes || ''}`;
    const timeMatch = text.match(/([01]?[0-9]|2[0-3])[:.]([0-5][0-9])/);
    if (timeMatch) {
      const hours = parseInt(timeMatch[1], 10);
      const minutes = parseInt(timeMatch[2], 10);
      return hours * 60 + minutes;
    }
    return 9999; // Fallback for items without time
  };

  return [...points].sort((a, b) => {
    // 1. Sort by time extracted from text
    const timeA = extractTimeValue(a);
    const timeB = extractTimeValue(b);
    if (timeA !== timeB) return timeA - timeB;

    // 2. Sort by sort_order (if provided)
    const orderA = a.sort_order ?? 999;
    const orderB = b.sort_order ?? 999;
    if (orderA !== orderB) return orderA - orderB;

    // 3. Sort by price
    return Number(a.price || 0) - Number(b.price || 0);
  });
}
