import { ref, computed, onBeforeUnmount } from 'vue';
import api from '../lib/axios';

/** ถามถี่แค่ไหนตอนลูกค้ายังไม่ได้จ่าย / ตอนจ่ายแล้วและนั่งรออยู่หน้าจอ */
const POLL_IDLE_MS = 3000;
const POLL_SETTLING_MS = 2000;

/** รอเกินกี่วินาทีถึงจะเริ่มบอกลูกค้าว่า "ช้ากว่าปกติ" แทนที่จะปล่อยให้เดาเอง */
const SLOW_AFTER_SECONDS = 45;

/**
 * ออกจากหน้านี้ไปนานแค่ไหนถึงจะนับว่า "ไปจ่ายมา" ตอนกลับเข้ามา
 *
 * บนมือถือ การเปิดแอปธนาคาร/แอปกล้องเพื่อสแกน ทำให้แท็บนี้ถูกซ่อนเสมอ ขากลับจึงเป็น
 * สัญญาณที่ดีที่สุดที่เรามีว่าลูกค้าเพิ่งจ่ายมา ตั้งเกณฑ์ไว้ 3 วินาทีเพื่อไม่ให้การ
 * ปัดดู notification แวบเดียวไปสลับหน้าจอเขา
 */
const AWAY_LONG_ENOUGH_MS = 3000;

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
 * สถานะ "settling" คือช่วงที่ลูกค้าจ่ายไปแล้วแต่ webhook ยังไม่มาถึง — เป็นช่วงที่
 * หน้าจอเคยนิ่งสนิทจนลูกค้าไม่รู้ว่าต้องจ่ายซ้ำไหม จับแยกไว้เพื่อให้หน้าจอมีอะไร
 * ให้ดูระหว่างนั้น และเพื่อให้ poll เปลี่ยนพฤติกรรมสองอย่าง: ถี่ขึ้น และแนบ sync=1
 * ให้เซิร์ฟเวอร์ถาม Beam ตรงๆ แทนที่จะรอ webhook อย่างเดียว
 *
 * @param {(payment: object) => void} onPaid เรียกครั้งเดียวเมื่อเงินเข้าแล้ว
 */
export function useBeamCharge(onPaid) {
  const payment = ref(null);
  const loading = ref(false);
  const error = ref('');
  const secondsLeft = ref(0);
  const settling = ref(false);
  const settlingSeconds = ref(0);

  let pollTimer = null;
  let expiryTimer = null;
  let awayAt = 0;

  const qrSrc = computed(() =>
    payment.value?.qr_image_base64 ? `data:image/png;base64,${payment.value.qr_image_base64}` : null
  );
  // ระหว่าง settling ไม่ถือว่าหมดอายุ — เงินที่จ่ายวินาทีสุดท้ายก็ยังเข้าได้ ถ้าสลับ
  // หน้าจอไปเป็น "QR หมดอายุแล้ว" ตอนนั้น ลูกค้าที่จ่ายไปแล้วจะกดจ่ายซ้ำอีกใบ
  const expired = computed(() => !!payment.value && secondsLeft.value <= 0 && !settling.value);
  const failed = computed(() => ['failed', 'expired'].includes(payment.value?.status));
  const slow = computed(() => settling.value && settlingSeconds.value >= SLOW_AFTER_SECONDS);
  const countdownText = computed(() => {
    const s = Math.max(0, secondsLeft.value);
    return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
  });

  function stop() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (expiryTimer) { clearInterval(expiryTimer); expiryTimer = null; }
  }

  async function pollOnce() {
    const id = payment.value?.payment_id;
    if (!id) return;

    try {
      const res = await api.get(`/payments/beam/${id}`, {
        params: settling.value ? { sync: 1 } : {},
      });
      const fresh = res.data?.data;

      // สถานะของใบชำระเงินใบนี้เท่านั้น — สถานะการจองใช้ไม่ได้ เพราะยอดคงเหลือ/งวด
      // ที่ 2+/ส่วนแบ่งกลุ่ม จ่ายบนการจองที่ confirmed อยู่ก่อนแล้ว
      if (fresh?.status === 'succeeded') {
        stop();
        settling.value = false;
        payment.value = { ...payment.value, ...fresh };
        onPaid?.(fresh);
      } else if (fresh?.status === 'failed' || fresh?.status === 'expired') {
        stop();
        settling.value = false;
        payment.value = { ...payment.value, status: fresh.status };
      }
    } catch {
      // เน็ตสะดุดรอบเดียวไม่ใช่เรื่องต้องแจ้งลูกค้า รอบหน้าค่อยถามใหม่
    }
  }

  function restartPolling(intervalMs) {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollOnce, intervalMs);
  }

  function watchPayment() {
    const expiresAt = payment.value?.expires_at ? new Date(payment.value.expires_at).getTime() : 0;

    const tick = () => {
      secondsLeft.value = expiresAt ? Math.max(0, Math.round((expiresAt - Date.now()) / 1000)) : 0;
      if (settling.value) settlingSeconds.value += 1;
      // QR หมดอายุแล้วก็ไม่ต้องถามต่อ ลูกค้าต้องกดออกใบใหม่อยู่ดี — ยกเว้นตอนที่
      // ลูกค้าบอกว่าจ่ายไปแล้ว ตอนนั้นยังต้องรอคำตอบว่าเงินใบนั้นเข้าหรือไม่
      if (secondsLeft.value <= 0 && !settling.value) stop();
    };
    tick();
    expiryTimer = setInterval(tick, 1000);

    restartPolling(settling.value ? POLL_SETTLING_MS : POLL_IDLE_MS);
  }

  /**
   * ลูกค้าจ่ายแล้ว (กลับเข้ามาจากแอปธนาคาร) — เปลี่ยนหน้าจอเป็นโหมดรอผล แล้วเร่ง
   * จังหวะถาม
   */
  function markSettling() {
    if (settling.value || !payment.value) return;

    settling.value = true;
    settlingSeconds.value = 0;
    restartPolling(POLL_SETTLING_MS);
    pollOnce();
  }

  /**
   * ออกไปนานพอที่จะเป็นการไปจ่ายเงินมา — สลับเป็นโหมดรอผลให้เอง
   *
   * นี่คือเหตุผลที่หน้าจอไม่มีปุ่ม "จ่ายเงินแล้ว" ให้กด: เราอ่านเอาเองได้จากการที่
   * ลูกค้าออกจากหน้านี้ไปแล้วกลับมา ส่วนคนที่สแกนด้วยมือถืออีกเครื่อง (ไม่เคยออกจาก
   * หน้านี้เลย) ก็ยังจบด้วย webhook ตามปกติ แค่ไม่เห็นจอ "กำลังตรวจสอบ" ระหว่างทาง
   */
  function onVisibilityChange() {
    if (!payment.value || settling.value || expired.value) return;

    if (document.hidden) {
      awayAt = Date.now();
      return;
    }

    const wasAway = awayAt && Date.now() - awayAt >= AWAY_LONG_ENOUGH_MS;
    awayAt = 0;
    if (wasAway) markSettling();
  }

  /**
   * "ยังไม่ได้จ่าย" — เราเดาผิด (ลูกค้าออกไปทำอย่างอื่นแล้วกลับมา) พากลับไปหน้า QR
   *
   * ถ้า QR หมดอายุไประหว่างที่รออยู่ tick รอบถัดไปจะพาไปหน้า "สร้าง QR ใหม่" เอง
   */
  function resumeWaiting() {
    if (!settling.value) return;

    settling.value = false;
    settlingSeconds.value = 0;
    restartPolling(POLL_IDLE_MS);
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
    settling.value = false;
    settlingSeconds.value = 0;

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

  document.addEventListener('visibilitychange', onVisibilityChange);

  onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibilityChange);
    stop();
  });

  return {
    payment,
    loading,
    error,
    secondsLeft,
    qrSrc,
    expired,
    failed,
    settling,
    settlingSeconds,
    slow,
    countdownText,
    create,
    markSettling,
    resumeWaiting,
    stop,
  };
}
