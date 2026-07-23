<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">ระดับความยากหมายถึงอะไร</h1>
        <p class="text-[#505E5E] text-sm max-w-2xl leading-relaxed">
          คำว่า "ง่าย" ของแต่ละคนไม่เท่ากัน หน้านี้อธิบายว่าเราใช้เกณฑ์อะไรตัดสิน
          และคุณจะเทียบกับตัวเองยังไง — ส่งให้เพื่อนที่กำลังลังเลอ่านได้เลย
        </p>
      </section>

      <!-- สามระดับ -->
      <section class="space-y-3 mb-6">
        <article
          v-for="level in levels"
          :key="level.key"
          class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6"
        >
          <div class="flex items-start gap-4">
            <span
              class="w-11 h-11 rounded-[14px] flex items-center justify-center text-[22px] shrink-0"
              :class="level.badgeClass"
            >{{ level.emoji }}</span>

            <div class="min-w-0 flex-1">
              <h2 class="text-lg font-extrabold text-[#1a1c1c]">{{ level.title }}</h2>
              <p class="text-[13px] text-[#8A9A9A] font-bold mt-0.5">{{ level.range }}</p>

              <p class="text-sm text-[#1a1c1c] leading-relaxed mt-3">{{ level.description }}</p>

              <div class="mt-4 rounded-[14px] bg-[#FAFBFB] border border-[#EDF1F1] p-3.5">
                <p class="text-[12px] font-bold text-[#505E5E] mb-1.5">เทียบให้เห็นภาพ</p>
                <p class="text-[13px] text-[#505E5E] leading-relaxed">{{ level.reference }}</p>
              </div>

              <p class="text-[13px] text-[#505E5E] leading-relaxed mt-3">
                <span class="font-bold text-[#1a1c1c]">เหมาะกับ:</span> {{ level.suitedFor }}
              </p>
            </div>
          </div>
        </article>
      </section>

      <!-- สิ่งที่เราใช้ตัดสิน -->
      <section class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-6">
        <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">เราดูอะไรบ้างในการจัดระดับ</h2>
        <ul class="space-y-4">
          <li v-for="factor in factors" :key="factor.title" class="flex gap-3">
            <span class="material-symbols-rounded text-[20px] text-[#006565] shrink-0 mt-0.5">{{ factor.icon }}</span>
            <div>
              <p class="text-sm font-bold text-[#1a1c1c]">{{ factor.title }}</p>
              <p class="text-[13px] text-[#505E5E] leading-relaxed mt-0.5">{{ factor.detail }}</p>
            </div>
          </li>
        </ul>
      </section>

      <!-- เทียบกับตัวเอง -->
      <section class="rounded-[20px] bg-[#0F3D3E] text-white p-5 sm:p-6 mb-6">
        <h2 class="text-lg font-extrabold mb-2">แล้วจะรู้ได้ยังไงว่าเราไหว</h2>
        <p class="text-white/75 text-sm leading-relaxed mb-4">
          ระดับความยากเป็นค่ากลาง ไม่ได้บอกว่า <span class="font-bold text-white">คุณ</span> ไหวไหม
          ในหน้าทริปเราจะเทียบความหนักของทริปนั้นกับสิ่งที่คุณเคยเดินมาจริง แล้วบอกตรง ๆ
          ว่าสบาย ท้าทาย หรือเกินตัว — และถ้าข้อมูลไม่พอ เราจะบอกว่าไม่พอ ไม่เดาให้
        </p>
        <div class="flex flex-wrap gap-2.5">
          <router-link to="/passport" class="rounded-[14px] bg-white text-[#0F3D3E] text-sm font-bold px-5 py-3">
            ดูสถิติของฉัน
          </router-link>
          <router-link to="/places" class="rounded-[14px] border border-white/25 text-white text-sm font-bold px-5 py-3">
            ดูสถานที่ทั้งหมด
          </router-link>
        </div>
      </section>

      <!-- ข้อควรรู้ -->
      <section class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6">
        <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">ที่ระดับความยากไม่ได้บอก</h2>
        <ul class="space-y-2.5">
          <li v-for="(item, i) in caveats" :key="i" class="flex gap-2.5 text-[13px] text-[#505E5E] leading-relaxed">
            <span class="material-symbols-rounded text-[18px] text-[#B4C4C4] shrink-0 mt-0.5">info</span>
            {{ item }}
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<script setup>
/**
 * หน้าอธิบายเกณฑ์ — ตัวเลขที่ใช้ตรงกับที่ระบบใช้จัดระดับทริป (easy/medium/hard)
 * ถ้าเกณฑ์ฝั่งหลังบ้านเปลี่ยน ต้องมาแก้ที่นี่ด้วยเพื่อไม่ให้พูดคนละเรื่อง
 */
const levels = [
  {
    key: 'easy',
    emoji: '🌿',
    title: 'สายชิล',
    range: 'เดินรวมไม่เกิน ~8 กม. · ไต่ขึ้นไม่เกิน ~400 ม.',
    badgeClass: 'bg-emerald-50 text-emerald-700',
    description:
      'ทางเดินชัดเจน ความชันไม่ต่อเนื่อง พักได้บ่อยตามจังหวะตัวเอง จบวันแล้วยังเหลือแรงเที่ยวต่อ',
    reference: 'ประมาณเดินเล่นรอบสวนลุมฯ 3-4 รอบ แต่มีทางขึ้นเนินคั่นเป็นช่วง ๆ',
    suitedFor: 'คนที่ไม่เคยเดินป่ามาก่อน ครอบครัวที่มีเด็กโต หรือคนที่อยากลองก่อนตัดสินใจไปหนักกว่านี้',
  },
  {
    key: 'medium',
    emoji: '⛰️',
    title: 'ปานกลาง',
    range: 'เดินรวม ~8-18 กม. · ไต่ขึ้น ~400-1,000 ม.',
    badgeClass: 'bg-amber-50 text-amber-700',
    description:
      'มีช่วงชันต่อเนื่องที่ต้องใช้แรงขาจริงจัง อาจต้องแบกของค้างคืนเอง และเดินติดต่อกันหลายชั่วโมงต่อวัน',
    reference: 'เทียบได้กับขึ้นบันไดตึก 100-300 ชั้น กระจายทั้งวัน โดยมีเป้าอยู่บนหลัง',
    suitedFor: 'คนที่ออกกำลังกายสม่ำเสมอ หรือเคยจบทริประดับสายชิลมาแล้วอย่างน้อยหนึ่งครั้ง',
  },
  {
    key: 'hard',
    emoji: '🔥',
    title: 'สายโหด',
    range: 'เดินรวมเกิน ~18 กม. · ไต่ขึ้นเกิน ~1,000 ม.',
    badgeClass: 'bg-rose-50 text-rose-700',
    description:
      'ชันยาวและต่อเนื่อง บางช่วงต้องใช้มือช่วยปีน อากาศและเวลาบังคับให้เดินต่อแม้เหนื่อย จุดถอยกลางทางมีน้อย',
    reference: 'เทียบได้กับปีนตึกใบหยก 2 สามรอบขึ้นไป ต่อเนื่องภายในหนึ่งถึงสองวัน',
    suitedFor: 'คนที่เคยจบทริประดับปานกลางมาแล้ว และซ้อมเดินระยะไกลมาก่อนล่วงหน้าอย่างน้อยหนึ่งเดือน',
  },
];

const factors = [
  {
    icon: 'trending_up',
    title: 'ความสูงที่ต้องไต่ (elevation gain)',
    detail: 'ตัวชี้วัดที่หนักที่สุด — ระยะทางเท่ากันแต่ไต่ต่างกันสองเท่า คือคนละทริปเลย',
  },
  {
    icon: 'straighten',
    title: 'ระยะทางรวมตลอดทริป',
    detail: 'นับทั้งขาขึ้นและขาลง เพราะขาลงกินแรงเข่ามากกว่าที่หลายคนคิด',
  },
  {
    icon: 'schedule',
    title: 'จำนวนวันและชั่วโมงเดินต่อวัน',
    detail: 'เดิน 15 กม. ในสองวันกับในวันเดียว ต่างกันมาก เราคิดเวลาพักฟื้นระหว่างวันด้วย',
  },
  {
    icon: 'backpack',
    title: 'ต้องแบกของเองไหม',
    detail: 'ทริปที่มีลูกหาบขนสัมภาระให้ จะเบากว่าทริปที่ต้องแบกเต็มเป้าเองอย่างชัดเจน',
  },
  {
    icon: 'terrain',
    title: 'สภาพทาง',
    detail: 'ทางดินอัดแน่นกับทางหินลื่นหรือลุยน้ำ ให้ความรู้สึกเหนื่อยไม่เท่ากันแม้ตัวเลขจะเท่ากัน',
  },
];

const caveats = [
  'สภาพอากาศเปลี่ยนทุกอย่าง — เส้นทางระดับปานกลางกลายเป็นโหดได้ทันทีเมื่อฝนตกหนัก',
  'ระดับนี้ไม่ได้ประเมินความสูงจากระดับน้ำทะเล คนที่แพ้ความสูงควรดูตัวเลข "ม. เหนือระดับน้ำทะเล" ในหน้าสถานที่ประกอบ',
  'โรคประจำตัวและอาการบาดเจ็บเก่าสำคัญกว่าระดับความยาก แจ้งทีมงานไว้ก่อนเสมอ',
  'ความเร็วของกลุ่มคือความเร็วของคนที่ช้าที่สุด — เลือกระดับที่ทุกคนในกลุ่มไหว ไม่ใช่ที่คนแข็งแรงที่สุดไหว',
];
</script>
