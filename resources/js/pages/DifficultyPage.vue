<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24">
    <PageHero
      image="/images/khaochangphueak.webp"
      :title="content.title"
      :subtitle="content.intro"
      :loading="loading"
    />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
      <!-- สามระดับ — เรียงจากเบาไปหนัก มีมาตรวัดบอกว่าหนักแค่ไหนเทียบกัน -->
      <section v-if="content.levels.length" class="relative z-10 -mt-10">
        <div v-reveal.stagger class="grid grid-cols-1 gap-5 lg:grid-cols-3">
          <article
            v-for="(level, i) in content.levels"
            :key="level.key"
            class="level-card"
            :style="{ '--tone': toneColor(level.tone), '--tone-tint': toneTint(level.tone) }"
          >
            <div class="flex items-start justify-between gap-4">
              <span class="level-card__emoji">{{ level.emoji }}</span>
              <!-- มาตรวัดความหนัก: 1–3 ขีดตามลำดับระดับ -->
              <span class="level-meter" :aria-label="`ระดับ ${i + 1} จาก ${content.levels.length}`">
                <span
                  v-for="n in content.levels.length"
                  :key="n"
                  class="level-meter__bar"
                  :class="{ 'is-on': n <= i + 1 }"
                  :style="{ height: `${8 + n * 6}px`, '--n': n }"
                ></span>
              </span>
            </div>

            <h2 class="mt-5 text-2xl font-black tracking-tight text-[var(--color-text-dark)]">{{ level.title }}</h2>
            <p class="mt-1 text-[14px] font-bold" :style="{ color: 'var(--tone)' }">{{ level.range }}</p>

            <p class="mt-4 text-[15px] leading-relaxed text-[var(--color-text-mid)]">{{ level.description }}</p>

            <div class="mt-5 rounded-2xl p-4" :style="{ backgroundColor: 'var(--tone-tint)' }">
              <p class="flex items-center gap-1.5 text-[12px] font-extrabold uppercase tracking-wide" :style="{ color: 'var(--tone)' }">
                <span class="material-symbols-rounded icon-16">compare_arrows</span>
                เทียบให้เห็นภาพ
              </p>
              <p class="mt-1.5 text-[14px] leading-relaxed text-[var(--color-text-dark)]">{{ level.reference }}</p>
            </div>

            <p class="mt-auto pt-5 text-[14px] leading-relaxed text-[var(--color-text-mid)]">
              <span class="font-extrabold text-[var(--color-text-dark)]">เหมาะกับ</span> {{ level.suited_for }}
            </p>
          </article>
        </div>
      </section>

      <div v-else-if="loading" class="relative z-10 -mt-10 grid grid-cols-1 gap-5 lg:grid-cols-3" aria-hidden="true">
        <div v-for="n in 3" :key="n" class="h-96 rounded-3xl shimmer"></div>
      </div>

      <!-- สิ่งที่เราใช้ตัดสิน -->
      <section v-if="content.factors.length" class="mt-20 grid grid-cols-1 gap-10 lg:grid-cols-12">
        <header v-reveal class="lg:col-span-4">
          <div class="lg:sticky lg:top-28">
            <p class="inline-flex items-center gap-2 text-[13px] font-bold text-[var(--color-accent)]">
              <span class="h-[2px] w-6 rounded-full bg-[var(--color-accent)]"></span>
              เกณฑ์ของเรา
            </p>
            <h2 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight text-[var(--color-text-dark)] md:text-4xl">
              {{ content.factors_title }}
            </h2>
          </div>
        </header>
        <ol v-reveal.stagger class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-8">
          <li v-for="(factor, i) in content.factors" :key="factor.title" class="factor-card group">
            <div class="flex items-start justify-between">
              <span class="factor-card__icon">
                <span class="material-symbols-rounded">{{ factor.icon }}</span>
              </span>
              <span class="text-sm font-extrabold tabular-nums text-[var(--color-sand-dark)] transition-colors group-hover:text-[var(--color-accent)]">
                {{ String(i + 1).padStart(2, '0') }}
              </span>
            </div>
            <p class="mt-4 text-[17px] font-extrabold leading-snug text-[var(--color-text-dark)]">{{ factor.title }}</p>
            <p class="mt-1.5 text-[14px] leading-relaxed text-[var(--color-text-mid)]">{{ factor.detail }}</p>
          </li>
        </ol>
      </section>

      <!-- เทียบกับตัวเอง -->
      <section v-if="content.self_check_title" v-reveal="0" class="self-check mt-20">
        <img src="/images/hiking.webp" alt="" class="self-check__img" loading="lazy" />
        <div class="relative z-10 max-w-2xl">
          <h2 class="text-2xl font-black leading-tight text-white md:text-4xl">{{ content.self_check_title }}</h2>
          <p class="mt-4 whitespace-pre-line text-[15px] leading-relaxed text-white/80 md:text-base">{{ content.self_check_body }}</p>
          <div class="mt-8 flex flex-wrap gap-3">
            <router-link to="/passport" class="hero-btn hero-btn--solid">
              ดูสถิติของฉัน
              <span class="material-symbols-rounded icon-20">arrow_forward</span>
            </router-link>
            <router-link to="/places" class="hero-btn hero-btn--outline">ดูสถานที่ทั้งหมด</router-link>
          </div>
        </div>
      </section>

      <!-- ข้อควรรู้ -->
      <section v-if="content.caveats.length" v-reveal class="mt-10 rounded-3xl border border-[var(--color-sand-dark)] bg-white p-6 md:p-8">
        <h2 class="flex items-center gap-2 text-[15px] font-extrabold text-[var(--color-text-dark)]">
          <span class="material-symbols-rounded icon-20 text-[var(--color-text-muted)]">info</span>
          {{ content.caveats_title }}
        </h2>
        <ul class="mt-4 grid grid-cols-1 gap-x-8 gap-y-3 md:grid-cols-2">
          <li v-for="(item, i) in content.caveats" :key="i" class="flex gap-3 text-[14px] leading-relaxed text-[var(--color-text-mid)]">
            <span class="mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-text-muted)]"></span>
            {{ item }}
          </li>
        </ul>
      </section>

      <GuideNextLinks current="/difficulty" class="mt-20" />
    </div>
  </div>
</template>

<script setup>
/**
 * เนื้อหาทั้งหน้ามาจาก /content/difficulty (แอดมินแก้ได้ที่ /admin/content/difficulty)
 * ถ้าเกณฑ์ฝั่งหลังบ้านเปลี่ยน ต้องไปแก้ตัวเลขในหน้าแอดมินด้วยเพื่อไม่ให้พูดคนละเรื่อง
 */
import { usePageContent } from '../lib/pageContent';
import PageHero from '../components/PageHero.vue';
import GuideNextLinks from '../components/GuideNextLinks.vue';

/** แอดมินเลือกได้แค่ "โทน" ส่วนสีจริงคุมไว้ที่นี่ จะได้ไม่ต้องรู้จัก CSS */
const TONES = {
  easy: { color: '#047857', tint: '#ECFDF5' },
  medium: { color: '#B45309', tint: '#FFFBEB' },
  hard: { color: '#BE123C', tint: '#FFF1F2' },
};
const toneColor = (tone) => (TONES[tone] || TONES.easy).color;
const toneTint = (tone) => (TONES[tone] || TONES.easy).tint;

const { content, loading } = usePageContent('difficulty', {
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

<style scoped>
.level-card {
  position: relative;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 1.75rem;
  border-radius: 1.75rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
}
/* แถบสีของระดับบนขอบการ์ด */
.level-card::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 4px;
  background-color: var(--tone);
}
.level-card__emoji {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3.5rem;
  height: 3.5rem;
  border-radius: 1.1rem;
  background-color: var(--tone-tint);
  font-size: 28px;
}
.level-meter {
  display: flex;
  align-items: flex-end;
  gap: 4px;
}
.level-meter__bar {
  width: 8px;
  border-radius: 3px;
  background-color: var(--color-sand-dark);
}
.level-meter__bar.is-on { background-color: var(--tone); }
/* ขีดไต่ขึ้นทีละขีดตอนการ์ดโผล่ */
:global(html.motion-ok) .level-card .level-meter__bar.is-on {
  animation: meterRise 0.5s var(--ease-spring) backwards;
  animation-delay: calc(0.35s + var(--n) * 0.1s);
}
@keyframes meterRise {
  from { transform: scaleY(0); transform-origin: bottom; }
  to { transform-origin: bottom; }
}

.factor-card {
  padding: 1.5rem;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  transition: border-color 0.25s ease;
}
.factor-card:hover { border-color: color-mix(in srgb, var(--color-accent) 35%, transparent); }
.factor-card__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: 1rem;
  background-color: var(--color-accent-tint);
  color: var(--color-accent);
  transition: background-color 0.25s ease, color 0.25s ease;
}
.factor-card:hover .factor-card__icon { background-color: var(--color-accent); color: #fff; }

.self-check {
  position: relative;
  overflow: hidden;
  padding: 3rem 1.75rem;
  border-radius: 2rem;
  background-color: var(--color-primary);
}
@media (min-width: 768px) {
  .self-check { padding: 4rem; }
}
.self-check__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0.35;
  mask-image: linear-gradient(to left, #000 20%, transparent 85%);
}

@media (prefers-reduced-motion: reduce) {
  .level-meter__bar.is-on { animation: none !important; }
}
</style>
