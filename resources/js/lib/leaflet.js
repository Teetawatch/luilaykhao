/**
 * โหลด Leaflet แบบ on-demand — แผนที่ใช้แค่ไม่กี่หน้า จึงไม่ควรอยู่ใน bundle หลัก
 *
 * เรียกซ้ำได้ปลอดภัย: ครั้งแรกแทรก <link>/<script> แล้วจำ promise ไว้ ครั้งต่อไป
 * คืน promise เดิม สองหน้าที่ mount พร้อมกันจึงไม่โหลดสคริปต์ซ้อนกัน
 */

const LEAFLET_VERSION = '1.9.4';

let loader = null;

export function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  if (loader) return loader;

  loader = new Promise((resolve, reject) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = `https://unpkg.com/leaflet@${LEAFLET_VERSION}/dist/leaflet.css`;
    document.head.appendChild(link);

    const script = document.createElement('script');
    script.src = `https://unpkg.com/leaflet@${LEAFLET_VERSION}/dist/leaflet.js`;
    script.onload = () => resolve(window.L);
    script.onerror = () => {
      // ปล่อยให้ลองใหม่ได้ ไม่งั้นเน็ตสะดุดครั้งเดียวแผนที่ตายทั้ง session
      loader = null;
      reject(new Error('โหลดแผนที่ไม่สำเร็จ'));
    };
    document.head.appendChild(script);
  });

  return loader;
}
