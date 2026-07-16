// Pure display helpers for vehicles — colour names are stored as free Thai text
// on vehicles.color, so the swatch has to map them back to something paintable.
// Shared between VehiclesPage.vue and DriversPage.vue.

const COLOR_HEX = {
  'ขาว': '#ffffff', 'ดำ': '#1f2937', 'เทา': '#9ca3af', 'แดง': '#ef4444',
  'น้ำเงิน': '#3b82f6', 'เขียว': '#22c55e', 'เหลือง': '#eab308',
  'ส้ม': '#f97316', 'ม่วง': '#a855f7', 'ชมพู': '#ec4899',
};

const TYPE_LABELS = { van: 'รถตู้', boat: 'เรือ' };

export function colorHex(colorName) {
  return COLOR_HEX[colorName] || '#9ca3af';
}

export function vehicleTypeLabel(type) {
  return TYPE_LABELS[type] || type || '-';
}
