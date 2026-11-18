<template>
  <!-- แถบความมั่นใจใต้หัวหน้า: 4 เรื่องที่ระบบทำได้จริง (ไม่ใช่คำโฆษณา)
       ใช้ทั้งหน้าแรกและทุกหน้าที่มี PageHero — เบอร์โทร/LINE อยู่บนแถบบนของหัวเว็บแล้ว -->
  <div class="trust-strip bg-white border-y border-[var(--color-sand-dark)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <!-- มือถือ: ตาราง 2×2 เห็นครบไม่ต้องเลื่อน ใช้ชื่อสั้นและไม่มีคำอธิบายย่อย · จอใหญ่: 4 ช่องคั่นด้วยเส้น -->
      <ul class="trust-list py-3.5 lg:py-6">
        <li v-for="item in items" :key="item.title" class="trust-item">
          <span class="trust-ico">
            <span class="material-symbols-rounded icon-22" :class="{ 'filled-icon': item.filled }">{{ item.icon }}</span>
          </span>
          <span class="min-w-0">
            <span class="block text-[13px] sm:text-[14px] lg:text-[15px] font-bold leading-tight text-[var(--color-text-dark)]">
              <span class="lg:hidden">{{ item.short }}</span>
              <span class="hidden lg:inline">{{ item.title }}</span>
            </span>
            <span class="mt-0.5 hidden lg:block text-[13px] leading-snug text-[var(--color-text-muted)]">{{ item.desc }}</span>
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { licenceNo } from '../lib/licence';
import { supportHours } from '../lib/contact';

const items = [
  // short: ชื่อบนจอเล็ก (2 คอลัมน์) — สั้นพอให้ไม่ตัดกลางคำ
  { icon: 'verified_user', filled: true, title: `ใบอนุญาตเลขที่ ${licenceNo()}`, short: `ใบอนุญาต ${licenceNo()}`, desc: 'นำเที่ยวได้ทั้งในและต่างประเทศ' },
  { icon: 'near_me', title: 'ดูตำแหน่งรถได้ตลอดทาง', short: 'ดูตำแหน่งรถได้', desc: 'คนที่บ้านตามดูได้ระหว่างเดินทาง' },
  { icon: 'receipt_long', title: 'ตรวจสลิปอัตโนมัติ', short: 'ตรวจสลิปอัตโนมัติ', desc: 'โอนแล้วไม่ต้องรอแอดมินตอบ' },
  { icon: 'support_agent', title: 'ทีมงานดูแลตลอดทริป', short: 'ทีมงานดูแลตลอดทริป', desc: `สอบถามได้${supportHours()}` },
];
</script>

<style scoped>
.filled-icon {
  font-variation-settings: 'FILL' 1;
}
.trust-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem 0.75rem;
}
.trust-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
}
.trust-ico {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  border-radius: 0.75rem;
  background-color: var(--color-accent-tint);
  color: var(--color-accent);
}
@media (min-width: 1024px) {
  .trust-list {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
  }
  .trust-item {
    gap: 0.75rem;
  }
  .trust-item + .trust-item {
    padding-left: 1.25rem;
    border-left: 1px solid var(--color-sand-dark);
  }
  .trust-ico {
    width: 2.75rem;
    height: 2.75rem;
  }
}
</style>
