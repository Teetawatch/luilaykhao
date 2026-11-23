/** URL เต็มของไฟล์รีวิว — ค่าเก่าบางแถวเป็น path ใต้โดเมนเรา (storage/…) ไม่ใช่ URL */
export function reviewMediaUrl(value) {
  if (!value) return '';
  const raw = String(value);
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  return `${window.location.origin}/${raw.replace(/^\/+/, '')}`;
}

/** รูปก่อน แล้วตามด้วยวิดีโอ: [{ type: 'image'|'video', url }] — รูปแบบที่ ReviewMediaLightbox รับ */
export function reviewMedia(review) {
  return [
    ...(review?.images || []).map((url) => ({ type: 'image', url: reviewMediaUrl(url) })),
    ...(review?.videos || []).map((url) => ({ type: 'video', url: reviewMediaUrl(url) })),
  ].filter((item) => item.url);
}
