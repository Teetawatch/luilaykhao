<template>
  <!-- ไปเป็นกลุ่ม / เหมาทริป — หลังบ้านมีระบบเหมาทริปอยู่แล้ว (ขอ → ทีมงานเสนอราคา →
       เปิดรอบเฉพาะกลุ่ม) หน้าเว็บจึงชวนคุยผ่าน LINE/โทร ซึ่งเป็นทางที่ลูกค้าใช้จริง -->
  <section class="bg-[var(--color-accent-tint)] py-16 md:py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
        <div class="relative overflow-hidden rounded-2xl aspect-[4/3] lg:aspect-[5/4] bg-[var(--color-sand-dark)]">
          <img :src="image" :alt="$t('ทริปกลุ่มกับลุยเลเขา')" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
          <span class="absolute left-4 bottom-4 inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-[14px] font-bold text-[var(--color-text-dark)]">
            <span class="material-symbols-rounded icon-20 text-[var(--color-accent)]">groups</span>
            {{ $t('กลุ่มเพื่อน · ครอบครัว · องค์กร') }}
          </span>
        </div>

        <div>
          <p class="text-[14px] font-bold text-[var(--color-accent)]">{{ $t('เหมาทริปส่วนตัว') }}</p>
          <h2 class="mt-2 text-[1.75rem] sm:text-3xl md:text-[2.4rem] font-bold leading-tight tracking-tight text-[var(--color-text-dark)]">
            {{ $t('ไปเป็นกลุ่ม เลือกวันเองได้') }}
          </h2>
          <p class="mt-4 text-base md:text-[1.1rem] leading-relaxed text-[var(--color-text-mid)]">
            {{ $t('ทริปเพื่อนสนิท ทริปครอบครัว หรือทริปบริษัทประจำปี บอกจำนวนคน วันที่สะดวก และปลายทางที่สนใจ ทีมงานจะเสนอราคาให้ก่อนตัดสินใจ') }}
          </p>

          <ul class="mt-6 space-y-3">
            <li v-for="item in points" :key="item.title" class="flex items-start gap-3">
              <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-[var(--color-accent)] border border-[var(--color-accent)]/20">
                <span class="material-symbols-rounded icon-20">{{ item.icon }}</span>
              </span>
              <span>
                <span class="block text-[16px] font-bold text-[var(--color-text-dark)]">{{ item.title }}</span>
                <span class="block text-[14px] text-[var(--color-text-muted)]">{{ item.desc }}</span>
              </span>
            </li>
          </ul>

          <div class="mt-8 flex flex-wrap items-center gap-3">
            <a :href="supportLineUrl()" target="_blank" rel="noopener" class="group-btn group-btn--line">
              <i class="fa-brands fa-line" aria-hidden="true"></i>
              {{ $t('ขอใบเสนอราคาทางไลน์') }}
            </a>
            <a :href="supportPhoneHref()" class="group-btn group-btn--outline">
              <span class="material-symbols-rounded icon-20">call</span>
              {{ $t('โทร {supportPhone}', { supportPhone: supportPhone() }) }}
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { $t } from '@/i18n';
import { supportLineUrl, supportPhone, supportPhoneHref } from '../../lib/contact';

defineProps({
  image: { type: String, default: '/images/abouts_luilaykhao.JPG' },
});

const points = [
  { icon: 'event_available', title: $t('เลือกวันและจุดรับเอง'), desc: $t('เปิดรอบเฉพาะกลุ่มของคุณ ไม่ต้องรอรอบปกติเต็ม') },
  { icon: 'request_quote', title: $t('เสนอราคาต่อคนตามขนาดกลุ่ม'), desc: $t('เห็นยอดรวมก่อนตอบรับ จองและจ่ายผ่านระบบเดียวกับทริปปกติ') },
  { icon: 'support_agent', title: $t('ทีมงานชุดเดียวกับทริปปกติ'), desc: $t('รถ คนขับ และสตาฟที่พาทริปของเราอยู่ทุกสัปดาห์') },
];
</script>

<style scoped>
.group-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.8rem 1.5rem;
  border-radius: 9999px;
  font-size: 16px;
  font-weight: 700;
  white-space: nowrap;
  transition: background-color 0.2s ease, border-color 0.2s ease;
}
.group-btn i {
  font-size: 19px;
  line-height: 1;
}
.group-btn--line {
  background-color: #06c755;
  color: #fff;
}
.group-btn--line:hover {
  background-color: #05b34c;
}
.group-btn--outline {
  border: 1px solid color-mix(in srgb, var(--color-accent) 35%, transparent);
  background-color: #fff;
  color: var(--color-text-dark);
}
.group-btn--outline:hover {
  border-color: var(--color-accent);
}
</style>
