import { ref, computed, onBeforeUnmount } from 'vue';
import api from '../lib/axios';

/**
 * ออกใบชำระเงินผ่าน Beam แล้วเฝ้าดูจนกว่าเงินจะเข้า
 *
 * ทุกหน้าที่รับเงินต้องทำสามอย่างเหมือนกันเป๊ะ: ขอ QR, นับถอยหลังอายุ QR, และถาม
 * เซิร์ฟเวอร์เป็นระยะว่าจ่ายยัง ถ้าให้แต่ละหน้าเขียนเอง จะมีหน้าที่ลืม clearInterval
 * แล้ว poll ค้างไว้ทั้งวัน หรือลืมกันกดซ้ำจนออก QR ซ้อนกันสามใบ
 *
 * ที่นี่ไม่มีการตัดสินว่า "จ่ายสำเร็จ" เอง — ถามเซิร์ฟเวอร์อย่างเดียว เพราะคนยืนยัน
 * จริงคือ webhook ของ Beam ที่เข้าหลังบ้าน
 *
 * @param {(payment: object) => void} onPaid เรียกครั้งเดียวเมื่อเงินเข้าแล้ว
 */
export function useBeamCharge(onPaid) {
  const payment = ref(null);
  const loading = ref(false);
  const error = ref('');
  const secondsLeft = ref(0);

  let pollTimer = null;
  let expiryTimer = null;

  const qrSrc = computed(() =>
    payment.value?.qr_image_base64 ? `data:image/png;base64,${payment.value.qr_image_base64}` : null
  );
  const expired = computed(() => !!payment.value && secondsLeft.value <= 0);
  const countdownText = computed(() => {
    const s = Math.max(0, secondsLeft.value);
    return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
  });

  function stop() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (expiryTimer) { clearInterval(expiryTimer); expiryTimer = null; }
  }

  function watchPayment() {
    const expiresAt = payment.value?.expires_at ? new Date(payment.value.expires_at).getTime() : 0;

    const tick = () => {
      secondsLeft.value = expiresAt ? Math.max(0, Math.round((expiresAt - Date.now()) / 1000)) : 0;
      // QR หมดอายุแล้วก็ไม่ต้องถามต่อ ลูกค้าต้องกดออกใบใหม่อยู่ดี
      if (secondsLeft.value <= 0) stop();
    };
    tick();
    expiryTimer = setInterval(tick, 1000);

    pollTimer = setInterval(async () => {
      const id = payment.value?.payment_id;
      if (!id) return;

      try {
        const res = await api.get(`/payments/beam/${id}`);
        const fresh = res.data?.data;

        if (fresh?.status === 'succeeded' || fresh?.booking_status === 'confirmed') {
          stop();
          onPaid?.(fresh);
        } else if (fresh?.status === 'failed' || fresh?.status === 'expired') {
          stop();
          payment.value = { ...payment.value, status: fresh.status };
        }
      } catch {
        // เน็ตสะดุดรอบเดียวไม่ใช่เรื่องต้องแจ้งลูกค้า รอบหน้าอีก 3 วิค่อยถามใหม่
      }
    }, 3000);
  }

  /**
   * @param {object} payload body ของ POST /payments/beam/charge
   * @returns {Promise<object|null>} ใบที่ออกได้ หรือ null เมื่อล้มเหลว
   */
  async function create(payload) {
    if (loading.value) return null;

    stop();
    loading.value = true;
    error.value = '';
    payment.value = null;

    try {
      const res = await api.post('/payments/beam/charge', payload);
      payment.value = res.data?.data;
      watchPayment();

      // แอปธนาคารตอบเป็นลิงก์ ไม่ใช่ QR — พาไปเลย แล้วกลับมาผ่าน returnUrl
      if (payment.value?.redirect_url) {
        window.location.href = payment.value.redirect_url;
      }

      return payment.value;
    } catch (e) {
      error.value = e?.response?.data?.message || 'สร้างรายการชำระเงินไม่สำเร็จ กรุณาลองใหม่';
      return null;
    } finally {
      loading.value = false;
    }
  }

  onBeforeUnmount(stop);

  return { payment, loading, error, secondsLeft, qrSrc, expired, countdownText, create, stop };
}
