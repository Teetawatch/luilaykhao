/**
 * ทริปที่ถูกใจ (หัวใจ) — ตรรกะซิงก์ล้วน ๆ ไม่พึ่ง Vue (stores/wishlist.js ห่ออีกชั้น
 * และ tests/Web/wishlist-sync.test.mjs ทดสอบตรง ๆ)
 *
 * - แขก: เก็บใน localStorage อย่างเดียวเหมือนเดิม
 * - ล็อกอินแล้ว: หลังบ้าน (`/wishlist`) เป็นตัวจริง ใช้ร่วมกับแอปและทุกเครื่อง
 *   กดหัวใจ = เปิดแจ้งเตือนรอบใหม่/ราคาลดของทริปนั้นให้ด้วย
 * - หัวใจที่กดไว้ตอนเป็นแขกถูกรวมเข้าบัญชีครั้งเดียว (OWNER_KEY จำว่าสำเนาใน
 *   เบราว์เซอร์เป็นของบัญชีไหน) ออกจากระบบแล้วล้างทิ้ง บัญชีถัดไปบนเครื่อง
 *   เดียวกันจะได้ไม่ได้หัวใจของคนก่อน — เหมือน WishlistProvider ในแอป
 */

export const LOCAL_KEY = 'wishlist';
export const OWNER_KEY = 'wishlist_account';

/** ดึงซ้ำตอนกลับมาที่แท็บได้ไม่ถี่กว่านี้ */
export const REFRESH_FLOOR_MS = 60_000;

/** เก็บเฉพาะที่ต้องใช้วาด — เดิมเก็บก้อนทริปทั้งก้อนจาก API ลง localStorage */
export function compactTrip(trip) {
  if (!trip || typeof trip !== 'object') return { id: trip };
  const minPrice = trip.min_price ?? null;
  const base = trip.price_per_person ?? trip.price ?? null;
  return {
    id: trip.id,
    slug: trip.slug ?? null,
    title: trip.title ?? '',
    location: trip.location ?? '',
    cover_image: trip.cover_image ?? null,
    thumbnail_image: trip.thumbnail_image ?? null,
    price_per_person: base,
    min_price: minPrice,
    // ที่ Navbar โชว์ — ราคาถูกสุดของรอบที่ยังจองได้ ไม่มีก็ราคาตั้งต้น
    price: minPrice ?? base,
    next_departure_date: trip.next_departure_date ?? null,
    alert_on: trip.alert_on === true,
  };
}

const keyOf = (trip) => (trip && typeof trip === 'object' ? trip.id : trip);

function readLocal(storage) {
  try {
    const parsed = JSON.parse(storage.getItem(LOCAL_KEY) || '[]');
    return Array.isArray(parsed) ? parsed.filter((t) => keyOf(t) != null).map(compactTrip) : [];
  } catch {
    return [];
  }
}

function errorMessage(error, fallback) {
  const message = error?.response?.data?.message;
  // ไม่มีคำตอบจากหลังบ้าน (เน็ตหลุด) — ข้อความของ axios เป็นภาษาอังกฤษ ไม่โชว์
  return error?.response && typeof message === 'string' && message ? message : fallback;
}

/**
 * @param {{ storage: Storage, http: { get(url: string): Promise<any>, post(url: string, body?: any): Promise<any>, delete(url: string): Promise<any> }, now?: () => number }} deps
 *   http.* คืน `data` ของคำตอบ (res.data.data) และโยน error ของ axios ตอนล้มเหลว
 */
export function createWishlistSync({ storage, http, now = () => Date.now() }) {
  let items = readLocal(storage);
  let account = null;
  let gen = 0;
  let syncing = null;
  let lastSyncedAt = 0;
  const busy = new Set();
  const listeners = new Set();

  const save = () => {
    try {
      storage.setItem(LOCAL_KEY, JSON.stringify(items));
    } catch {
      // โหมดส่วนตัว/พื้นที่เต็ม — ใช้ในหน้านี้ไปก่อน
    }
  };
  const emit = () => {
    save();
    for (const fn of listeners) fn(items);
  };
  const owner = () => {
    try {
      return storage.getItem(OWNER_KEY);
    } catch {
      return null;
    }
  };
  const setOwner = (value) => {
    try {
      if (value == null) storage.removeItem(OWNER_KEY);
      else storage.setItem(OWNER_KEY, value);
    } catch {
      // ไม่ได้จดก็แค่รวมซ้ำรอบหน้า (เพิ่มอย่างเดียว ไม่เสียหาย)
    }
  };

  async function runSync() {
    const bound = account;
    if (bound == null) return;
    // หัวใจที่กดระหว่างรอทำให้ผลรอบนั้นเก่า — ลองอีกหนเดียวพอ
    for (let attempt = 0; attempt < 2; attempt++) {
      const started = gen;
      const merged = owner() === bound;
      let rows;
      try {
        rows = merged
          ? await http.get('/wishlist')
          : await http.post('/wishlist/merge', {
              slugs: items.map((t) => t.slug).filter(Boolean),
            });
      } catch {
        // ออฟไลน์/หลังบ้านล่ม — ใช้สำเนาในเครื่องไปก่อน รอบหน้าลองใหม่
        return;
      }
      if (account !== bound) return;
      // รวมสำเร็จแล้วจดเลย แม้ผลรอบนี้จะใช้ไม่ได้ — ไม่งั้นรอบหน้ารวมซ้ำแล้วหัวใจ
      // ที่เพิ่งเอาออกจากเครื่องอื่นจะถูกใส่กลับ
      if (!merged) setOwner(bound);
      if (started !== gen || busy.size > 0) continue;
      if (!Array.isArray(rows)) return;
      items = rows.map(compactTrip);
      lastSyncedAt = now();
      emit();
      return;
    }
  }

  function refresh({ force = false } = {}) {
    if (account == null) return Promise.resolve();
    if (!force && lastSyncedAt && now() - lastSyncedAt < REFRESH_FLOOR_MS) {
      return Promise.resolve();
    }
    if (!syncing) {
      syncing = runSync().finally(() => {
        syncing = null;
      });
    }
    return syncing;
  }

  /** ผูก/ปลดบัญชี — เรียกซ้ำได้ ทำงานเฉพาะตอนบัญชีเปลี่ยน (null = ไม่ได้ล็อกอิน) */
  async function bind(accountId) {
    const next = accountId == null ? null : String(accountId);
    if (next === account) return;
    const previous = account;
    account = next;
    lastSyncedAt = 0;

    if (next == null) {
      if (previous != null || owner() != null) {
        items = [];
        busy.clear();
        gen++;
        setOwner(null);
        emit();
      }
      return;
    }

    const holder = owner();
    if (holder != null && holder !== next) {
      // สำเนาของบัญชีอื่น (ออกจากระบบไม่จบ) — ไม่รวมเข้าบัญชีนี้
      items = [];
      gen++;
      setOwner(null);
      emit();
    }
    await refresh({ force: true });
  }

  /**
   * สลับหัวใจ — คืน { favourite, changed, alertOn }
   * ล็อกอินอยู่แล้วส่งไม่สำเร็จ: คืนสถานะเดิมแล้วโยน Error (ข้อความภาษาไทย)
   * กดซ้ำระหว่างกำลังส่ง: ไม่ทำอะไร (changed = false)
   */
  async function toggle(trip) {
    const id = keyOf(trip);
    if (id == null) return { favourite: false, changed: false, alertOn: false };
    if (busy.has(id)) {
      return { favourite: isFavorite(id), changed: false, alertOn: false };
    }

    const index = items.findIndex((t) => keyOf(t) === id);
    const previous = index === -1 ? null : items[index];
    const adding = previous == null;
    items = adding ? [compactTrip(trip), ...items] : items.filter((t) => keyOf(t) !== id);
    gen++;
    emit();

    const slug = (trip && trip.slug) || previous?.slug;
    if (account == null || !slug) {
      return { favourite: adding, changed: true, alertOn: false };
    }

    const bound = account;
    busy.add(id);
    try {
      if (adding) {
        const row = await http.post(`/trips/${encodeURIComponent(slug)}/wishlist`);
        if (row && account === bound && isFavorite(id)) {
          items = items.map((t) => (keyOf(t) === id ? compactTrip(row) : t));
          emit();
        }
        return { favourite: true, changed: true, alertOn: row?.alert_on === true };
      }
      await http.delete(`/trips/${encodeURIComponent(slug)}/wishlist`);
      return { favourite: false, changed: true, alertOn: false };
    } catch (error) {
      if (account === bound) {
        items = adding
          ? items.filter((t) => keyOf(t) !== id)
          : [previous, ...items.filter((t) => keyOf(t) !== id)];
        gen++;
        emit();
      }
      throw new Error(
        errorMessage(
          error,
          adding
            ? 'บันทึกทริปที่ชอบไม่สำเร็จ ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่'
            : 'นำออกไม่สำเร็จ ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่',
        ),
      );
    } finally {
      busy.delete(id);
    }
  }

  function isFavorite(id) {
    return items.some((t) => keyOf(t) === id);
  }

  return {
    get items() {
      return items;
    },
    get bound() {
      return account != null;
    },
    isBusy: (id) => busy.has(id),
    subscribe(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
    bind,
    refresh,
    toggle,
    isFavorite,
  };
}
