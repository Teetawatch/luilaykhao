/* ทริปที่ถูกใจบนเว็บ — ตรรกะซิงก์ (resources/js/lib/wishlistSync.js)
 * สิ่งที่ต้องถูก: แขกเก็บในเบราว์เซอร์, ล็อกอินครั้งแรกรวมหัวใจของแขกเข้าบัญชี
 * ครั้งเดียว, ออกจากระบบแล้วไม่ทิ้งหัวใจไว้ให้บัญชีถัดไป, ส่งไม่สำเร็จแล้วหัวใจ
 * ไม่โกหก, กดเบิ้ลไม่ยิงซ้ำ, ผลดึงที่เก่ากว่าการกดไม่ทับ
 * รัน: node tests/Web/wishlist-sync.test.mjs */
import assert from 'node:assert/strict';
import { createWishlistSync, LOCAL_KEY, OWNER_KEY, REFRESH_FLOOR_MS } from '../../resources/js/lib/wishlistSync.js';

function memoryStorage(initial = {}) {
  const data = new Map(Object.entries(initial));
  return {
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => data.set(k, String(v)),
    removeItem: (k) => data.delete(k),
    dump: () => Object.fromEntries(data),
  };
}

const deferred = () => {
  let resolve;
  const promise = new Promise((r) => (resolve = r));
  return { promise, resolve };
};

/** หลังบ้านจำลองของบัญชีหนึ่ง */
function fakeServer() {
  const rows = new Map();
  const calls = [];
  const state = { failWrites: false, holdWrites: null, holdReads: null };
  const row = (slug, extra = {}) => ({
    id: slug.length * 100 + slug.charCodeAt(0),
    slug,
    title: `ทริป ${slug}`,
    location: 'เลย',
    cover_image: null,
    thumbnail_image: null,
    price_per_person: 2000,
    min_price: 1800,
    next_departure_date: '2026-12-12',
    alert_on: true,
    ...extra,
  });
  const list = () => [...rows.values()];
  const http = {
    async get(url) {
      calls.push(`GET ${url}`);
      if (state.holdReads) await state.holdReads.promise;
      return list();
    },
    async post(url, body) {
      calls.push(`POST ${url}`);
      if (url === '/wishlist/merge') {
        for (const slug of body.slugs) if (!rows.has(slug)) rows.set(slug, row(slug));
        return list();
      }
      if (state.holdWrites) await state.holdWrites.promise;
      if (state.failWrites) throw { response: { status: 500, data: { message: 'ระบบขัดข้อง' } } };
      const slug = decodeURIComponent(url.split('/')[2]);
      const r = rows.get(slug) ?? row(slug);
      rows.delete(slug);
      const rest = [...rows.entries()];
      rows.clear();
      rows.set(slug, r);
      for (const [k, v] of rest) rows.set(k, v);
      return r;
    },
    async delete(url) {
      calls.push(`DELETE ${url}`);
      if (state.holdWrites) await state.holdWrites.promise;
      if (state.failWrites) throw new Error('Network Error');
      rows.delete(decodeURIComponent(url.split('/')[2]));
      return null;
    },
  };
  return { rows, calls, state, row, http };
}

const trip = (slug, id) => ({ id, slug, title: `ทริป ${slug}`, price_per_person: 2500, schedules: [{ id: 1 }] });
const slugs = (sync) => sync.items.map((t) => t.slug);

let passed = 0;
async function test(name, fn) {
  try {
    await fn();
    passed++;
    console.log(`  ✓ ${name}`);
  } catch (error) {
    console.error(`  ✗ ${name}`);
    throw error;
  }
}

console.log('wishlist sync (web)');

await test('แขก: เก็บในเบราว์เซอร์อย่างเดียว ไม่ยิงหลังบ้าน และเก็บแบบย่อ', async () => {
  const storage = memoryStorage();
  const server = fakeServer();
  const sync = createWishlistSync({ storage, http: server.http });
  await sync.bind(null);
  const result = await sync.toggle(trip('koh-tao', 1));
  assert.deepEqual(result, { favourite: true, changed: true, alertOn: false });
  assert.equal(server.calls.length, 0);
  const saved = JSON.parse(storage.getItem(LOCAL_KEY));
  assert.equal(saved[0].slug, 'koh-tao');
  assert.equal(saved[0].schedules, undefined, 'ไม่เก็บก้อนทริปทั้งก้อน');
  assert.equal(saved[0].price, 2500);
});

await test('ของเดิมใน localStorage (ก้อนทริปเต็ม) ยังอ่านได้', async () => {
  const storage = memoryStorage({ [LOCAL_KEY]: JSON.stringify([{ ...trip('old', 9), cover_image: 'a.jpg' }, 'garbage']) });
  const sync = createWishlistSync({ storage, http: fakeServer().http });
  assert.ok(sync.isFavorite(9));
  assert.equal(sync.items[0].cover_image, 'a.jpg');
});

await test('ล็อกอินครั้งแรก: รวมหัวใจของแขกเข้าบัญชี แล้วใช้แถวจากหลังบ้าน', async () => {
  const storage = memoryStorage();
  const server = fakeServer();
  server.rows.set('phu', server.row('phu'));
  const sync = createWishlistSync({ storage, http: server.http });
  await sync.toggle(trip('koh-tao', 1));
  await sync.bind(7);
  assert.ok(server.calls.includes('POST /wishlist/merge'));
  assert.ok(server.rows.has('koh-tao'));
  assert.deepEqual(new Set(slugs(sync)), new Set(['koh-tao', 'phu']));
  assert.equal(storage.getItem(OWNER_KEY), '7');
  assert.equal(sync.items.find((t) => t.slug === 'phu').price, 1800, 'ราคาโชว์ = ราคารอบที่ถูกสุด');
});

await test('เปิดเว็บครั้งต่อไป: ดึงจากหลังบ้าน ไม่รวมซ้ำ (หัวใจที่เอาออกจากแอปไม่กลับมา)', async () => {
  const storage = memoryStorage();
  const server = fakeServer();
  const first = createWishlistSync({ storage, http: server.http });
  await first.toggle(trip('koh-tao', 1));
  await first.bind(7);
  server.rows.delete('koh-tao'); // เอาออกจากแอป
  server.calls.length = 0;

  const again = createWishlistSync({ storage, http: server.http });
  await again.bind(7);
  assert.deepEqual(server.calls, ['GET /wishlist']);
  assert.deepEqual(slugs(again), []);
});

await test('ออกจากระบบ: ล้างหัวใจ บัญชีถัดไปไม่ได้ของคนก่อน', async () => {
  const storage = memoryStorage();
  const server = fakeServer();
  server.rows.set('phu', server.row('phu'));
  const sync = createWishlistSync({ storage, http: server.http });
  await sync.bind(7);
  assert.equal(sync.items.length, 1);
  await sync.bind(null);
  assert.equal(sync.items.length, 0);
  assert.equal(storage.getItem(OWNER_KEY), null);

  const other = fakeServer();
  const next = createWishlistSync({ storage, http: other.http });
  await next.bind(8);
  assert.equal(other.rows.size, 0);
});

await test('สำเนาในเบราว์เซอร์เป็นของบัญชีอื่น — ทิ้ง ไม่รวมเข้าบัญชีใหม่', async () => {
  const storage = memoryStorage();
  const server = fakeServer();
  const a = createWishlistSync({ storage, http: server.http });
  await a.bind(7);
  await a.toggle(trip('koh-tao', 1));

  const other = fakeServer();
  const b = createWishlistSync({ storage, http: other.http });
  await b.bind(8);
  assert.equal(other.rows.size, 0);
  assert.equal(b.items.length, 0);
});

await test('ล็อกอินอยู่: กดหัวใจส่งไปหลังบ้าน ได้สถานะแจ้งเตือนกลับมา และเอาออกได้', async () => {
  const server = fakeServer();
  const sync = createWishlistSync({ storage: memoryStorage(), http: server.http });
  await sync.bind(7);
  const added = await sync.toggle(trip('doi-luang', 3));
  assert.equal(added.alertOn, true);
  assert.ok(server.calls.includes('POST /trips/doi-luang/wishlist'));
  assert.equal(sync.items[0].next_departure_date, '2026-12-12');

  const id = sync.items[0].id;
  const removed = await sync.toggle({ id, slug: 'doi-luang' });
  assert.equal(removed.favourite, false);
  assert.ok(server.calls.includes('DELETE /trips/doi-luang/wishlist'));
  assert.equal(sync.items.length, 0);
});

await test('ส่งไม่สำเร็จ: หัวใจคืนสถานะเดิมและโยนข้อความภาษาไทย', async () => {
  const server = fakeServer();
  const sync = createWishlistSync({ storage: memoryStorage(), http: server.http });
  await sync.bind(7);
  server.state.failWrites = true;
  await assert.rejects(sync.toggle(trip('doi-luang', 3)), /ระบบขัดข้อง/);
  assert.equal(sync.isFavorite(3), false);

  server.state.failWrites = false;
  await sync.toggle(trip('doi-luang', 3));
  const id = sync.items[0].id;
  server.state.failWrites = true;
  await assert.rejects(sync.toggle({ id, slug: 'doi-luang' }), /ตรวจสอบอินเทอร์เน็ต/);
  assert.equal(sync.isFavorite(id), true, 'เอาออกไม่สำเร็จต้องกลับมา');
});

await test('กดเบิ้ลระหว่างกำลังส่ง: ไม่ยิงซ้ำ ไม่สลับกลับ', async () => {
  const server = fakeServer();
  const sync = createWishlistSync({ storage: memoryStorage(), http: server.http });
  await sync.bind(7);
  server.state.holdWrites = deferred();
  const first = sync.toggle(trip('doi-luang', 3));
  const second = await sync.toggle(trip('doi-luang', 3));
  assert.equal(second.changed, false);
  assert.equal(sync.isFavorite(3), true);
  server.state.holdWrites.resolve();
  await first;
  assert.equal(server.calls.filter((c) => c.includes('/trips/doi-luang/wishlist')).length, 1);
});

await test('ผลดึงที่เริ่มก่อนกดหัวใจ ไม่ทับหัวใจที่เพิ่งกด', async () => {
  const server = fakeServer();
  const sync = createWishlistSync({ storage: memoryStorage(), http: server.http });
  await sync.bind(7);
  server.state.holdReads = deferred();
  const refreshing = sync.refresh({ force: true });
  await sync.toggle(trip('doi-luang', 3));
  const hold = server.state.holdReads;
  server.state.holdReads = null;
  hold.resolve();
  await refreshing;
  assert.equal(sync.items.some((t) => t.slug === 'doi-luang'), true);
});

await test('กลับมาที่แท็บถี่ ๆ ไม่ดึงทุกครั้ง', async () => {
  let clock = 1_000_000;
  const server = fakeServer();
  const sync = createWishlistSync({ storage: memoryStorage(), http: server.http, now: () => clock });
  await sync.bind(7);
  server.calls.length = 0;
  await sync.refresh();
  assert.equal(server.calls.length, 0);
  clock += REFRESH_FLOOR_MS + 1;
  await sync.refresh();
  assert.deepEqual(server.calls, ['GET /wishlist']);
});

await test('ออฟไลน์ตอนผูกบัญชี: ใช้ของในเครื่องไปก่อน กลับมาออนไลน์แล้วยังรวมให้', async () => {
  const storage = memoryStorage();
  const server = fakeServer();
  let online = false;
  const http = {
    get: (u) => (online ? server.http.get(u) : Promise.reject(new Error('Network Error'))),
    post: (u, b) => (online ? server.http.post(u, b) : Promise.reject(new Error('Network Error'))),
    delete: (u) => (online ? server.http.delete(u) : Promise.reject(new Error('Network Error'))),
  };
  const sync = createWishlistSync({ storage, http });
  await sync.toggle(trip('koh-tao', 1));
  await sync.bind(7);
  assert.equal(sync.isFavorite(1), true);
  assert.equal(storage.getItem(OWNER_KEY), null);

  online = true;
  await sync.refresh({ force: true });
  assert.ok(server.rows.has('koh-tao'));
  assert.equal(storage.getItem(OWNER_KEY), '7');
});

await test('ทริปเก่าที่ไม่มี slug (เก็บแต่ id) ยังกดเอาออกในเครื่องได้', async () => {
  const storage = memoryStorage({ [LOCAL_KEY]: JSON.stringify([5]) });
  const sync = createWishlistSync({ storage, http: fakeServer().http });
  assert.equal(sync.isFavorite(5), true);
  await sync.toggle(5);
  assert.equal(sync.isFavorite(5), false);
});

console.log(`\n${passed} passed`);
