// Pure, framework-agnostic helpers for trip-schedule availability and display.
// Shared between TripDetailPage.vue and ScheduleCalendar.vue.

export function hasAvailableSeats(schedule) {
  return Number(schedule?.available_seats || 0) > 0;
}

export function isScheduleBookable(schedule) {
  if (schedule?.is_charter) return false;
  return Boolean(schedule?.join_trip_enabled) || hasAvailableSeats(schedule);
}

export function scheduleAvailabilityBadgeClass(schedule) {
  if (schedule?.is_charter) return 'bg-violet-50 text-violet-700 border-violet-200';
  if (!hasAvailableSeats(schedule)) {
    return 'bg-red-500 text-white border-red-600';
  }
  if (Number(schedule?.available_seats || 0) <= 3) {
    return 'bg-red-50 text-red-600 border-red-200';
  }
  if (Number(schedule?.available_seats || 0) <= 5) {
    return 'bg-amber-50 text-amber-600 border-amber-200';
  }
  return 'bg-[#E8F5EC] text-[#2D7A4F] border-[#2D7A4F]/20';
}

export function scheduleAvailabilityTextClass(schedule) {
  if (Number(schedule?.available_seats || 0) < 3) return 'text-red-500';
  return isScheduleBookable(schedule) ? 'text-[var(--color-accent)]' : 'text-red-500';
}

export function scheduleAvailabilityDotClass(schedule) {
  if (Number(schedule?.available_seats || 0) < 3) return 'bg-red-500 animate-pulse';
  return isScheduleBookable(schedule) ? 'bg-green-500 animate-pulse' : 'bg-red-500';
}

export function scheduleAvailabilityLabel(schedule) {
  if (schedule?.is_charter) return 'รอบเหมา';
  if (!hasAvailableSeats(schedule)) return 'เต็มแล้ว';
  if (schedule?.join_trip_enabled) return `ว่าง ${schedule.available_seats} ที่`;
  return hasAvailableSeats(schedule)
    ? `ว่าง ${schedule.available_seats} ที่`
    : 'เต็มแล้ว';
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
