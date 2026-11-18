<template>
  <!-- แถบยืนยันตัวตนใต้หัวหน้า: ชื่อบริษัท + เลขใบอนุญาต ทางซ้าย ปุ่มโทร/LINE ทางขวา
       ใช้ทั้งหน้าแรกและทุกหน้าที่มี PageHero ให้ทุกหน้าเปิดมาเจอข้อมูลชุดเดียวกัน -->
  <div class="trust-strip bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 md:gap-6 py-4 md:py-5 border-b border-sand-dark">
        <p class="flex items-start md:items-center gap-2 text-[14px] md:text-[15.5px] font-semibold text-text-dark leading-snug">
          <span class="material-symbols-rounded icon-20 filled-icon text-accent shrink-0">verified_user</span>
          <span>
            ลุยเลเขา ผู้ประกอบธุรกิจนำเที่ยวทั้งในและต่างประเทศ
            <span class="whitespace-nowrap">ใบอนุญาตเลขที่ {{ licence }}</span>
          </span>
        </p>

        <div class="flex items-center gap-2.5 shrink-0">
          <a :href="supportPhoneHref()" class="strip-pill" :aria-label="`โทร ${supportPhone()}`">
            <span class="strip-pill-ico bg-white border border-accent/30 text-accent">
              <span class="material-symbols-rounded icon-18 filled-icon">call</span>
            </span>
            <span class="tabular-nums">{{ supportPhone() }}</span>
          </a>
          <a :href="supportLineUrl()" target="_blank" rel="noopener" class="strip-pill" aria-label="ติดต่อเราผ่าน LINE">
            <span class="strip-pill-ico bg-[#06C755] text-white">
              <i class="fa-brands fa-line" aria-hidden="true"></i>
            </span>
            <span>ติดต่อเราผ่านไลน์</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { licenceNo } from '../lib/licence';
import { supportLineUrl, supportPhone, supportPhoneHref } from '../lib/contact';

const licence = licenceNo();
</script>

<style scoped>
.filled-icon {
  font-variation-settings: 'FILL' 1;
}

.strip-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 1rem 0.3rem 0.3rem;
  border-radius: 9999px;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--color-text-dark);
  white-space: nowrap;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.strip-pill:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  background-color: var(--color-accent-tint);
}
.strip-pill-ico {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.875rem;
  height: 1.875rem;
  border-radius: 9999px;
}
.strip-pill-ico i {
  font-size: 15px;
  line-height: 1;
}
</style>
