/**
 * แผนที่พื้นหลังของทุกแผนที่ Leaflet บนเว็บ — CARTO Voyager ตัวเดียวกับแอปมือถือ
 * (ApiConfig.mapTileUrl ฝั่ง Flutter) หน้าตาเลยเหมือนกันทั้งเว็บและแอป
 *
 * เดิมทุกหน้าดึง tile.openstreetmap.org ตรง ๆ ซึ่งกติกาของ OSM ไม่ให้เว็บเชิง
 * พาณิชย์ที่มีคนเข้าเยอะใช้ — โดนบล็อกเมื่อไหร่แผนที่หายทั้งเว็บพร้อมกัน
 *
 * CARTO ต้องมีคีย์ (ไม่มีคีย์ = รูปลายน้ำ "API KEY REQUIRED" ไม่ error) และคีย์เรา
 * จำกัดโดเมน luilaykhao.com — เบราว์เซอร์ส่ง Referer ให้เอง. ตั้ง
 * `CARTO_API_KEY` ใน .env แล้ว build ใหม่ (`VITE_CARTO_API_KEY` อ้างถึงมัน).
 * ไม่มีคีย์ (เครื่อง dev) = ถอยไปใช้ OSM แบบเดิม — localhost ใช้คีย์จริงไม่ได้อยู่ดี
 * (CARTO ตอบ 403 เพราะ Referer ไม่ใช่โดเมนเรา)
 *
 * เงื่อนไขฟรีของ CARTO บังคับให้โชว์เครดิต "© OpenStreetMap contributors © CARTO"
 * — ห้ามปิด attributionControl บนแผนที่ที่ใช้ชั้นนี้
 */

const CARTO_KEY = (import.meta.env.VITE_CARTO_API_KEY || '').trim();

const ATTRIBUTION_OSM = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';
const ATTRIBUTION_CARTO = '&copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>';

export const usingCarto = CARTO_KEY !== '';

/** URL template ของ tile ({r} = "@2x" บนจอ retina — จำนวนคำขอเท่าเดิม) */
export function baseTileUrl() {
  if (!usingCarto) return 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  return `https://basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png?key=${encodeURIComponent(CARTO_KEY)}`;
}

export function baseTileAttribution() {
  return usingCarto ? `${ATTRIBUTION_OSM} ${ATTRIBUTION_CARTO}` : ATTRIBUTION_OSM;
}

/**
 * ใส่ชั้นแผนที่พื้นหลังให้แผนที่ Leaflet ที่สร้างแล้ว
 *
 * @param {typeof import('leaflet')} L
 * @param {import('leaflet').Map} map
 * @param {{ maxZoom?: number }} [options]
 */
export function addBaseTiles(L, map, { maxZoom = 19 } = {}) {
  return L.tileLayer(baseTileUrl(), {
    attribution: baseTileAttribution(),
    maxZoom,
  }).addTo(map);
}
