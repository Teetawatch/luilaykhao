<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">{{ content.title }}</h1>
        <p class="text-[#505E5E] text-sm max-w-2xl leading-relaxed">{{ content.intro }}</p>
      </section>

      <!-- สามระดับ -->
      <section class="space-y-3 mb-6">
        <article
          v-for="level in content.levels"
          :key="level.key"
          class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6"
        >
          <div class="flex items-start gap-4">
            <span
              class="w-11 h-11 rounded-[14px] flex items-center justify-center text-[22px] shrink-0"
              :class="TONE_CLASSES[level.tone] || TONE_CLASSES.easy"
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
                <span class="font-bold text-[#1a1c1c]">เหมาะกับ:</span> {{ level.suited_for }}
              </p>
            </div>
          </div>
        </article>
      </section>

      <!-- สิ่งที่เราใช้ตัดสิน -->
      <section class="bg-white rounded-[20px] border border-[#E8EEEF] p-5 sm:p-6 mb-6">
        <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">{{ content.factors_title }}</h2>
        <ul class="space-y-4">
          <li v-for="factor in content.factors" :key="factor.title" class="flex gap-3">
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
        <h2 class="text-lg font-extrabold mb-2">{{ content.self_check_title }}</h2>
        <p class="text-white/75 text-sm leading-relaxed mb-4 whitespace-pre-line">{{ content.self_check_body }}</p>
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
        <h2 class="text-[13px] font-bold text-[#505E5E] mb-4">{{ content.caveats_title }}</h2>
        <ul class="space-y-2.5">
          <li v-for="(item, i) in content.caveats" :key="i" class="flex gap-2.5 text-[13px] text-[#505E5E] leading-relaxed">
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
 * เนื้อหาทั้งหน้ามาจาก /content/difficulty (แอดมินแก้ได้ที่ /admin/content/difficulty)
 * ถ้าเกณฑ์ฝั่งหลังบ้านเปลี่ยน ต้องไปแก้ตัวเลขในหน้าแอดมินด้วยเพื่อไม่ให้พูดคนละเรื่อง
 */
import { usePageContent } from '../lib/pageContent';

/** แอดมินเลือกได้แค่ "โทน" ส่วนคลาสจริงคุมไว้ที่นี่ จะได้ไม่ต้องรู้จัก Tailwind */
const TONE_CLASSES = {
  easy: 'bg-emerald-50 text-emerald-700',
  medium: 'bg-amber-50 text-amber-700',
  hard: 'bg-rose-50 text-rose-700',
};

const { content } = usePageContent('difficulty', {
  title: '',
  intro: '',
  levels: [],
  factors_title: '',
  factors: [],
  self_check_title: '',
  self_check_body: '',
  caveats_title: '',
  caveats: [],
});
</script>
