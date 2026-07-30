import { onMounted, ref } from 'vue';
import api from './axios';

/**
 * ดึงเนื้อหาหน้าที่แอดมินแก้ได้จาก /content/{key}
 *
 * หลังบ้าน merge ค่าที่แอดมินแก้ทับค่าเริ่มต้นให้แล้ว ฝั่งนี้จึงไม่ต้องเก็บสำเนาเนื้อหาไว้อีกชุด
 * fallback มีไว้กันหน้าพังระหว่างโหลดหรือตอน API ล่มเท่านั้น จึงเป็นแค่โครงว่าง
 *
 * @param {string} key คีย์หน้าใน PageContent.php
 * @param {object} fallback โครงว่างของฟิลด์ที่ template วนลูป
 */
export function usePageContent(key, fallback = {}) {
  const content = ref({ ...fallback });
  const loading = ref(true);

  onMounted(async () => {
    try {
      const res = await api.get(`/content/${key}`);
      content.value = { ...fallback, ...(res.data?.data || {}) };
    } catch {
      // คงค่า fallback ไว้ — ดีกว่าหน้าขาว
    } finally {
      loading.value = false;
    }
  });

  return { content, loading };
}
