/**
 * ปุ่ม "Translate" ใต้รีวิวบนหน้าอังกฤษ — แปลเมื่อกดเท่านั้น (รีวิวคือเสียงของ
 * ลูกค้า ไม่แปลล่วงหน้า) เซิร์ฟเวอร์เก็บคำแปลไว้ คนถัดไปที่กดได้ทันที
 * กดอีกครั้งกลับไปดูต้นฉบับ หน้าไทยไม่มีปุ่มนี้
 */
import { reactive } from 'vue';
import api from '../lib/axios';
import { isEnglish } from '../i18n/index.js';

const THAI = /[฀-๿]/;

export function useReviewTranslation() {
  /** review id → { text, showing, loading, failed } */
  const state = reactive({});

  const canTranslate = (review) => isEnglish && !!review?.id && THAI.test(review?.comment || '');

  const commentOf = (review) => {
    const entry = state[review?.id];

    return entry?.showing && entry.text ? entry.text : review?.comment;
  };

  const isShowingTranslation = (review) => !!state[review?.id]?.showing;
  const isLoading = (review) => !!state[review?.id]?.loading;
  const hasFailed = (review) => !!state[review?.id]?.failed;

  async function toggle(review) {
    const entry = (state[review.id] ||= { text: '', showing: false, loading: false, failed: false });
    if (entry.loading) return;

    if (entry.showing) {
      entry.showing = false;

      return;
    }

    if (entry.text) {
      entry.showing = true;

      return;
    }

    entry.loading = true;
    entry.failed = false;
    try {
      const res = await api.post(`/reviews/${review.id}/translate`);
      entry.text = res.data?.data?.text || '';
      entry.showing = !!entry.text;
    } catch {
      entry.failed = true;
    } finally {
      entry.loading = false;
    }
  }

  return { canTranslate, commentOf, toggle, isShowingTranslation, isLoading, hasFailed };
}
