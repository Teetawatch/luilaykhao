/**
 * ข้อมูลเช็คลิสต์อุปกรณ์ — เก็บเป็นข้อมูลแยกจากหน้าจอเพื่อให้แก้เนื้อหาได้โดยไม่แตะ UI
 *
 * แต่ละรายการมีเงื่อนไขว่าจะโผล่เมื่อไหร่:
 *   trips    — ประเภททริปที่ต้องใช้ (ว่าง = ใช้ทุกประเภท)
 *   seasons  — ฤดูที่ต้องใช้ (ว่าง = ใช้ทุกฤดู)
 *   overnight — true = เฉพาะทริปค้างคืน
 *   essential — ของที่ขาดไม่ได้ ไม่ใช่ "มีก็ดี"
 */

export const TRIP_TYPES = [
  { key: 'hiking', label: 'เดินป่า / ขึ้นดอย' },
  { key: 'snorkel', label: 'ทะเล / ดำน้ำตื้น' },
  { key: 'camping', label: 'แคมป์ปิ้ง / กางเต็นท์' },
];

export const SEASONS = [
  { key: 'winter', label: 'หน้าหนาว (พ.ย.–ก.พ.)' },
  { key: 'summer', label: 'หน้าร้อน (มี.ค.–พ.ค.)' },
  { key: 'rainy', label: 'หน้าฝน (มิ.ย.–ต.ค.)' },
];

export const NIGHTS = [
  { key: 'day', label: 'ไป-กลับวันเดียว' },
  { key: 'overnight', label: 'ค้างคืน' },
];

export const CATEGORIES = [
  {
    key: 'documents',
    title: 'เอกสารและของสำคัญ',
    items: [
      { label: 'บัตรประชาชน (ตัวจริง)', essential: true, note: 'อุทยานหลายแห่งขอตรวจก่อนเข้า' },
      { label: 'เงินสดย่อย', essential: true, note: 'บนดอยส่วนใหญ่ไม่มีสัญญาณให้สแกนจ่าย' },
      { label: 'ยาประจำตัว + ใบสั่งยา', essential: true },
      { label: 'เบอร์ติดต่อฉุกเฉินเขียนใส่กระดาษ', note: 'เผื่อโทรศัพท์แบตหมดหรือเปียก' },
      { label: 'ประกันการเดินทาง (ถ้ามี)' },
    ],
  },
  {
    key: 'clothing',
    title: 'เสื้อผ้า',
    items: [
      { label: 'เสื้อแขนยาวกันแดด/กันแมลง', essential: true },
      { label: 'กางเกงขายาวที่เคลื่อนไหวสะดวก', trips: ['hiking', 'camping'], essential: true },
      { label: 'เสื้อผ้าเปลี่ยนต่อวัน', essential: true },
      { label: 'ชุดว่ายน้ำ / เสื้อรัดกล้ามเนื้อกันแดด', trips: ['snorkel'], essential: true },
      { label: 'เสื้อกันหนาวหรือแจ็กเก็ตขนเป็ด', seasons: ['winter'], essential: true, note: 'ยอดดอยหน้าหนาวลงต่ำกว่า 10 องศาได้' },
      { label: 'หมวกไหมพรม + ถุงมือ', seasons: ['winter'] },
      { label: 'เสื้อกันฝน / ปอนโช', seasons: ['rainy'], essential: true },
      { label: 'หมวกกันแดดปีกกว้าง', seasons: ['summer', 'rainy'] },
      { label: 'ชุดนอนอุ่น ๆ แยกจากชุดเดิน', overnight: true, note: 'อย่านอนด้วยชุดที่เดินมาทั้งวัน จะหนาวกว่าเดิม' },
      { label: 'ถุงเท้าเดินป่าสำรอง 1-2 คู่', trips: ['hiking', 'camping'], essential: true },
    ],
  },
  {
    key: 'gear',
    title: 'อุปกรณ์',
    items: [
      { label: 'รองเท้าเดินป่าที่ใส่จนเข้ารูปแล้ว', trips: ['hiking', 'camping'], essential: true, note: 'ห้ามใส่คู่ใหม่เอี่ยมขึ้นดอย รับรองได้แผล' },
      { label: 'รองเท้ารัดส้น / รองเท้าลุยน้ำ', trips: ['snorkel'] },
      { label: 'เป้สะพายขนาดเหมาะกับจำนวนวัน', essential: true },
      { label: 'ถุงกันน้ำ (dry bag) หรือถุงซิปล็อกใส่ของสำคัญ', essential: true },
      { label: 'ไฟฉายคาดหัว + ถ่านสำรอง', trips: ['hiking', 'camping'], essential: true },
      { label: 'ไม้เท้าเดินป่า (trekking pole)', trips: ['hiking'], note: 'ช่วยเข่ามากโดยเฉพาะขาลง' },
      { label: 'พาวเวอร์แบงก์', essential: true },
      { label: 'ขวดน้ำหรือถุงน้ำ ความจุอย่างน้อย 2 ลิตร', trips: ['hiking', 'camping'], essential: true },
      { label: 'ถุงขยะสำหรับเก็บขยะของตัวเองกลับ', essential: true, note: 'กติกาพื้นฐานของทุกอุทยาน' },
      { label: 'หน้ากากดำน้ำ + สน็อกเกิล (ถ้ามีของตัวเอง)', trips: ['snorkel'] },
      { label: 'ถุงนอน / แผ่นรองนอน', overnight: true, trips: ['camping', 'hiking'] },
      { label: 'ผ้าขนหนูแห้งเร็ว', overnight: true },
    ],
  },
  {
    key: 'health',
    title: 'สุขภาพและความปลอดภัย',
    items: [
      { label: 'ยาสามัญ (แก้ปวด ลดไข้ แก้ท้องเสีย)', essential: true },
      { label: 'พลาสเตอร์ปิดแผล + พลาสเตอร์กันรองเท้ากัด', essential: true },
      { label: 'ครีมกันแดด SPF 50', essential: true },
      { label: 'ครีมกันแดดที่ไม่ทำลายปะการัง', trips: ['snorkel'], essential: true },
      { label: 'ยากันยุง / สเปรย์กันทาก', seasons: ['rainy'], trips: ['hiking', 'camping'], essential: true },
      { label: 'เกลือแร่ผง', trips: ['hiking'], note: 'เหนื่อยจนไม่อยากกินข้าว แต่ยังจิบเกลือแร่ได้' },
      { label: 'ยาแก้เมารถ', note: 'ทางขึ้นดอยส่วนใหญ่โค้งเยอะ' },
      { label: 'ลิปมันกันแตก', seasons: ['winter'] },
    ],
  },
  {
    key: 'nice',
    title: 'มีก็ดี',
    items: [
      { label: 'ขนมให้พลังงานสูง', note: 'ถั่ว ช็อกโกแลต หรือกล้วยตาก' },
      { label: 'ผ้าบัฟหรือผ้าอเนกประสงค์' },
      { label: 'กล้อง / ขาตั้งเล็ก' },
      { label: 'ที่อุดหู + ผ้าปิดตา', overnight: true, note: 'ที่พักรวมมักมีคนกรน' },
      { label: 'ถุงผ้าใส่เสื้อผ้าเปียกแยกจากของแห้ง' },
      { label: 'สมุดจดเล็ก ๆ' },
    ],
  },
];

/** รายการที่ต้องใช้จริงตามเงื่อนไขที่ผู้ใช้เลือก */
export function filterItems(items, { tripType, season, nights }) {
  return items.filter((item) => {
    if (item.trips && !item.trips.includes(tripType)) return false;
    if (item.seasons && !item.seasons.includes(season)) return false;
    if (item.overnight && nights !== 'overnight') return false;

    return true;
  });
}
