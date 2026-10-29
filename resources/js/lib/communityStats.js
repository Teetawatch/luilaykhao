import api from './axios';

/**
 * ตัวเลขชุมชนจาก /stats สำหรับหน้าเกี่ยวกับเรา / จุดมุ่งหมาย — นับเฉพาะของที่นับได้จริง
 * (รอบที่เดินทางจบ คนที่เคยไปจริง ภูมิภาคที่ไปแล้ว คะแนนรีวิวที่อนุมัติแล้ว)
 *
 * ตัวที่เป็นศูนย์ถูกตัดทิ้ง และคะแนนรีวิวจะโชว์ก็ต่อเมื่อมีรีวิวจริงอย่างน้อยหนึ่งชิ้น —
 * API คืน avg_rating = 5.0 เมื่อยังไม่มีรีวิวเลย ซึ่งถ้าเอามาโชว์ตรง ๆ คือการอวดคะแนนที่ไม่มีใครให้
 *
 * เรียกไม่สำเร็จคืน [] ให้หน้าซ่อนแถบไปเลย ดีกว่าโชว์ตัวเลขที่ไม่มีที่มา
 */
export async function fetchCommunityStats() {
  try {
    const res = await api.get('/stats');
    const c = res.data?.data?.community;
    if (!c) return [];

    const stats = [
      { key: 'rounds', icon: 'hiking', value: Number(c.rounds_completed) || 0, label: 'รอบที่เดินทางจบแล้ว' },
      { key: 'travellers', icon: 'group', value: Number(c.travellers_count) || 0, label: 'นักเดินทางที่ไปกับเรา' },
      { key: 'regions', icon: 'map', value: Number(c.regions_count) || 0, label: 'ภูมิภาคที่ไปมาแล้ว' },
    ]
      .filter((s) => s.value > 0)
      .map((s) => ({ ...s, display: s.value.toLocaleString() }));

    const reviews = Number(c.reviews_count) || 0;
    if (reviews > 0) {
      stats.push({
        key: 'rating',
        icon: 'star',
        value: Number(c.avg_rating) || 0,
        display: (Number(c.avg_rating) || 0).toFixed(1),
        label: `คะแนนเฉลี่ยจาก ${reviews.toLocaleString()} รีวิว`,
      });
    }

    return stats;
  } catch {
    return [];
  }
}
