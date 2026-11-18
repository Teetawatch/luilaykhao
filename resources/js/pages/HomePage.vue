<template>
  <div class="homepage bg-[var(--color-sand)] font-anuphan selection:bg-[var(--color-accent)] selection:text-white">

    <!-- ══════════════════════════════════════════
         HERO — สไลด์เต็มความกว้าง หัวข้อชิดซ้าย ปุ่มเดียว ลูกศรซ้าย/ขวา จุดบอกหน้าใต้ภาพ
         สไลด์แรกคือแบรนด์ (มี h1) สไลด์ถัดไปคือทริปแนะนำจาก /trips/featured
    ══════════════════════════════════════════ -->
    <section
      class="home-hero relative w-full overflow-hidden bg-[var(--color-primary)] h-[460px] sm:h-[500px] lg:h-[560px] xl:h-[600px]"
      aria-roledescription="carousel"
      aria-label="ทริปแนะนำ"
      @mouseenter="pauseSlider"
      @mouseleave="resumeSlider"
      @focusin="pauseSlider"
      @focusout="resumeSlider"
    >
      <div
        v-for="(slide, index) in heroSlides"
        :key="slide.key"
        class="hero-slide absolute inset-0"
        :class="{ 'hero-slide--active': index === currentSlide }"
        :aria-hidden="index !== currentSlide"
        role="group"
        aria-roledescription="slide"
        :aria-label="`${index + 1} จาก ${heroSlides.length}`"
      >
        <img
          :src="slide.image"
          :alt="slide.alt"
          class="hero-slide-img absolute inset-0 w-full h-full object-cover"
          :loading="index === 0 ? 'eager' : 'lazy'"
          :fetchpriority="index === 0 ? 'high' : 'auto'"
        />
        <!-- เงาไล่จากซ้ายให้ตัวหนังสืออ่านออกบนทุกภาพ -->
        <div class="absolute inset-0 bg-gradient-to-r from-black/75 via-black/45 to-black/5"></div>
        <div class="absolute inset-x-0 bottom-0 h-32 bg-gradient-to-t from-black/35 to-transparent"></div>

        <div class="relative z-10 h-full max-w-7xl mx-auto px-6 sm:px-10 lg:px-16 flex items-center">
          <div class="hero-copy max-w-xl lg:max-w-2xl">
            <p v-if="slide.kicker" class="hero-kicker text-[14px] md:text-[16px] font-semibold text-white/85 mb-3">
              {{ slide.kicker }}
            </p>
            <component
              :is="index === 0 ? 'h1' : 'h2'"
              class="hero-title text-white text-[1.7rem] sm:text-[2.6rem] lg:text-[3.4rem] font-extrabold leading-[1.12] tracking-tight"
            >
              {{ slide.title }}
              <span v-if="slide.accent" class="block text-[var(--color-accent-light)]">{{ slide.accent }}</span>
            </component>
            <p v-if="slide.subtitle" class="hero-subtitle mt-4 text-white/90 text-[15px] sm:text-lg lg:text-xl font-medium leading-relaxed">
              {{ slide.subtitle }}
            </p>
            <div class="hero-actions mt-7 flex flex-wrap items-center gap-3">
              <router-link :to="slide.cta.to" class="hero-cta" :tabindex="index === currentSlide ? 0 : -1">
                {{ slide.cta.label }}
                <span class="material-symbols-rounded icon-22">chevron_right</span>
              </router-link>
              <router-link
                v-if="slide.cta2"
                :to="slide.cta2.to"
                class="hero-cta hero-cta--ghost"
                :tabindex="index === currentSlide ? 0 : -1"
              >{{ slide.cta2.label }}</router-link>
            </div>
          </div>
        </div>
      </div>

      <!-- ลูกศรเปลี่ยนสไลด์ -->
      <template v-if="heroSlides.length > 1">
        <button type="button" class="hero-arrow left-2 sm:left-4 lg:left-6" aria-label="สไลด์ก่อนหน้า" @click="goSlide(currentSlide - 1)">
          <span class="material-symbols-rounded icon-48">chevron_left</span>
        </button>
        <button type="button" class="hero-arrow right-2 sm:right-4 lg:right-6" aria-label="สไลด์ถัดไป" @click="goSlide(currentSlide + 1)">
          <span class="material-symbols-rounded icon-48">chevron_right</span>
        </button>

        <!-- จุดบอกตำแหน่ง -->
        <div class="absolute bottom-20 md:bottom-28 inset-x-0 z-20 flex justify-center gap-2.5">
          <button
            v-for="(slide, index) in heroSlides"
            :key="`dot-${slide.key}`"
            type="button"
            class="hero-dot"
            :class="{ 'hero-dot--active': index === currentSlide }"
            :aria-label="`ไปสไลด์ที่ ${index + 1}`"
            :aria-current="index === currentSlide ? 'true' : undefined"
            @click="goSlide(index)"
          ></button>
        </div>
      </template>
    </section>

    <!-- ══════════════════════════════════════════
         แผงค้นหา + ทางลัดบริการ — ลอยทับขอบล่างของสไลด์
    ══════════════════════════════════════════ -->
    <!-- flow-root กัน margin ติดลบของแผงทะลุขึ้นไปลาก section ทั้งก้อน (พื้นขาวจะไปทับสไลด์)
         section เริ่มที่ขอบล่างสไลด์พอดี แผงยื่นขึ้นไปทับสไลด์ 5rem -->
    <section class="home-panel-wrap relative z-20">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="home-panel -mt-20 rounded-2xl bg-white border border-[var(--color-sand-dark)] p-4 sm:p-5 md:p-7">
          <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 mb-4">
            <h2 class="text-[19px] md:text-[22px] font-bold text-[var(--color-text-dark)]">ค้นหารอบเดินทาง</h2>
            <router-link to="/find" class="inline-flex items-center gap-1 text-[14px] font-bold text-[var(--color-accent)] hover:underline underline-offset-2">
              <span class="material-symbols-rounded icon-18">auto_awesome</span>
              ยังเลือกไม่ถูก? ให้เราช่วยแนะนำ
            </router-link>
          </div>

          <HeroSearchBar class="home-search" :trips="allTrips" :loading="loading" />

          <nav aria-label="ทางลัดบริการ" class="mt-5 md:mt-6 pt-5 md:pt-6 border-t border-[var(--color-sand-dark)] grid grid-cols-4 gap-2 md:gap-3" :class="shortcutColsClass">
            <router-link
              v-for="item in shortcuts"
              :key="item.label"
              :to="item.to"
              class="shortcut group"
            >
              <span class="shortcut-ico">
                <span class="material-symbols-rounded icon-22">{{ item.icon }}</span>
              </span>
              <span class="shortcut-label">{{ item.label }}</span>
            </router-link>
          </nav>
        </div>
      </div>
    </section>

    <!-- แถบใบอนุญาต + ช่องทางติดต่อ (ชุดเดียวกับทุกหน้า) -->
    <TrustStrip />

    <!-- รอบที่ออกเดินทางเร็ว ๆ นี้ -->
    <DepartureBoard :trips="allTrips" :loading="loading" />

    <!-- แพ็กเกจทริปแยกตามประเภท (แท็บ) -->
    <TripShowcase :trips="allTrips" :categories="tripCategories" :loading="loading" />

    <!-- ปลายทางยอดนิยม (โมเสก) -->
    <PopularDestinations :trips="allTrips" />

    <!-- ไปเป็นกลุ่ม / เหมาทริป -->
    <GroupTripBand />

    <!-- รูปจริงจากทริปที่เพิ่งกลับมา -->
    <CommunityFeedStrip />

    <!-- ══════════════════════════════════════════
         ตัวเลขจากการเดินทางจริง — แถบเข้ม
    ══════════════════════════════════════════ -->
    <section
      v-if="visibleStats.length"
      ref="statsSection"
      class="bg-[var(--color-primary)] py-16 md:py-20"
    >
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <SectionTitle dark class="mb-10 md:mb-12" title="ตัวเลขจากการเดินทางจริง" subtitle="คำนวณจากรอบที่เดินทางจบแล้วในระบบ อัปเดตอัตโนมัติทุกชั่วโมง" />
        <div class="grid grid-cols-2 gap-y-10 gap-x-4" :class="statsGridColsClass">
          <div
            v-for="(stat, index) in visibleStats"
            :key="stat.label"
            class="flex flex-col items-center text-center px-2 transition-opacity duration-500"
            :class="[isVisible ? 'opacity-100' : 'opacity-0', index > 0 ? 'lg:border-l lg:border-white/10' : '']"
            :style="{ transitionDelay: `${index * 120}ms` }"
          >
            <span class="material-symbols-rounded icon-32 text-[var(--color-accent-light)] mb-3">{{ stat.icon }}</span>
            <div class="flex items-baseline gap-1 mb-1.5">
              <span class="text-3xl lg:text-[2.6rem] font-extrabold text-white tracking-tight leading-none tabular-nums">
                {{ stat.displayValue }}
              </span>
              <span v-if="stat.suffix" class="text-base lg:text-lg font-bold text-white/60">{{ stat.suffix }}</span>
            </div>
            <div class="text-[14px] lg:text-[15px] font-semibold text-white/90 leading-snug">{{ stat.label }}</div>
            <div v-if="stat.context" class="text-[12px] lg:text-[13px] text-white/55 mt-1 leading-snug">{{ stat.context }}</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         TESTIMONIALS SECTION
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24 bg-white relative overflow-hidden">
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <SectionTitle class="mb-10 md:mb-12" title="รีวิวจากลูกค้า" subtitle="เสียงจริงจากผู้ร่วมทริปที่ออกเดินทางไปกับเรา" />
      </div>

      <!-- Continuous marquee slider (full-bleed) -->
      <div v-if="reviews.length > 0" class="reviews-marquee group">
        <div
          class="reviews-marquee__track"
          :style="{ '--marquee-duration': marqueeDuration + 's' }"
        >
          <div
            v-for="(review, idx) in marqueeReviews"
            :key="review.id + '-' + idx"
            class="reviews-marquee__card bg-white rounded-xl p-7 transition-[transform,box-shadow] duration-500 ease-out relative ring-1 ring-black/[0.05] hover:-translate-y-1.5"
          >
            <!-- Quote accent -->
            <div class="absolute top-6 right-6 text-[var(--color-accent)]/10 pointer-events-none">
              <span class="material-symbols-rounded text-[68px] leading-none" style="font-variation-settings:'FILL' 1">format_quote</span>
            </div>

            <div class="relative z-10 flex flex-col h-full">
              <div class="flex text-[#FFB020] gap-0.5 mb-5">
                <span v-for="n in 5" :key="n" class="material-symbols-rounded text-[19px]"
                  :class="n <= review.rating ? 'text-[#FFB020]' : 'text-gray-200'"
                  :style="n <= review.rating ? 'font-variation-settings:\'FILL\' 1' : ''">
                  star
                </span>
              </div>

              <p class="text-[var(--color-text-mid)] text-[15px] font-medium leading-relaxed mb-7 line-clamp-4">“{{ review.comment || 'ไม่มีความคิดเห็น' }}”</p>

              <div class="mt-auto flex items-center gap-3.5 pt-5 border-t border-[var(--color-sand-dark)]">
                <div class="relative w-12 h-12 bg-[var(--color-sand)] rounded-full flex items-center justify-center text-[var(--color-accent)] font-black text-lg overflow-hidden border-2 border-white ring-2 ring-[var(--color-accent)]/15 shrink-0">
                  <span>{{ review.user_name?.charAt(0) }}</span>
                  <img
                    v-if="review.user_avatar || review.user?.avatar_url || review.user?.avatar"
                    :src="review.user_avatar || review.user?.avatar_url || review.user?.avatar"
                    class="absolute inset-0 w-full h-full object-cover"
                    @error="(e) => e.target.style.display='none'"
                  />
                </div>
                <div class="min-w-0">
                  <div class="flex items-center gap-1">
                    <span class="font-extrabold text-[var(--color-text-dark)] text-base truncate">{{ review.user_name }}</span>
                    <span class="material-symbols-rounded text-[15px] text-[var(--color-accent)] shrink-0" style="font-variation-settings:'FILL' 1" title="ยืนยันการเดินทางจริง">verified</span>
                  </div>
                  <div class="text-[var(--color-text-muted)] text-sm font-medium truncate">{{ review.trip_title }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="text-center py-12 bg-white/50 rounded-xl border-2 border-dashed border-gray-200">
          <p class="text-[var(--color-text-muted)] font-bold">ยังไม่มีรีวิวจากนักเดินทาง</p>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         WHY US SECTION
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24 bg-[var(--color-sand)] relative overflow-hidden">

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <SectionTitle
          class="mb-6"
          title="ทำไมต้องเดินทางกับลุยเลเขา"
          subtitle="บริษัทนำเที่ยวที่จดทะเบียนถูกต้อง ดูแลตั้งแต่ก่อนออกเดินทางจนกลับถึงบ้าน"
        />
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 md:gap-10 mt-16 md:mt-12 items-stretch">
          <div
            v-for="(trust, idx) in trustItems"
            :key="trust.title"
            class="bg-white p-6 md:p-7 pt-28 md:pt-32 rounded-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)]/30 cursor-default group relative mt-24 text-center flex flex-col justify-between"
          >
            <!-- Image Wrapper (Out-of-bound) -->
            <div class="absolute -top-24 md:-top-28 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-4">
              <img 
                :src="trust.image" 
                :alt="trust.title" 
                class="w-48 h-48 md:w-56 md:h-56 max-w-none object-contain"
              />
            </div>

            <div class="relative z-10 flex flex-col items-center h-full">
              <h3 class="text-xl md:text-2xl font-bold text-[var(--color-text-dark)] mb-3 group-hover:text-[var(--color-accent)] transition-colors">{{ trust.title }}</h3>
              <p class="text-[var(--color-text-mid)] text-base font-medium leading-relaxed mx-auto max-w-xs">{{ trust.desc }}</p>
              <!-- Decorative Line mimicking AboutPage -->
              <div class="mt-auto pt-8">
                <div class="mx-auto w-12 h-1.5 bg-[var(--color-accent)]/20 group-hover:bg-[var(--color-accent)] transition-all duration-500 rounded-full"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         LATEST ARTICLES — เนื้อหาความรู้ ไม่ใช่สินค้า
    ══════════════════════════════════════════ -->
    <LatestArticles />

    <!-- ══════════════════════════════════════════
         ปิดท้าย — หาไม่เจอก็มีทางไปต่อ (พื้นสว่าง ไม่ชนแถบติดต่อสีเข้มของ footer)
    ══════════════════════════════════════════ -->
    <section class="bg-white py-16 md:py-20 border-t border-[var(--color-sand-dark)]">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <SectionTitle
          class="mb-10"
          title="ยังหาทริปที่ใช่ไม่เจอ?"
          subtitle="เลือกทางที่สะดวก ให้ระบบแนะนำ ถามผู้ช่วย หรือคุยกับทีมงานโดยตรง"
        />
        <div class="grid gap-4 md:grid-cols-3">
          <component
            :is="item.external ? 'a' : 'router-link'"
            v-for="item in helpOptions"
            :key="item.title"
            v-bind="item.external ? { href: item.to, target: '_blank', rel: 'noopener' } : { to: item.to }"
            class="help-card group"
          >
            <span class="help-ico" :class="item.line ? 'help-ico--line' : ''">
              <i v-if="item.line" class="fa-brands fa-line" aria-hidden="true"></i>
              <span v-else class="material-symbols-rounded icon-28">{{ item.icon }}</span>
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-[18px] font-bold text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors">{{ item.title }}</span>
              <span class="mt-1 block text-[14px] leading-relaxed text-[var(--color-text-muted)]">{{ item.desc }}</span>
            </span>
            <span class="material-symbols-rounded icon-22 text-[var(--color-text-muted)] group-hover:text-[var(--color-accent)] transition-colors">chevron_right</span>
          </component>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         FLOATING LINE OA CONTACT BUTTON
    ══════════════════════════════════════════ -->
    <a
      :href="supportLineUrl()"
      target="_blank"
      rel="noopener"
      aria-label="ติดต่อเราผ่าน LINE"
      class="fixed bottom-5 right-5 z-50 inline-flex items-center gap-2 rounded-full bg-[#06C755] text-white font-bold text-[0.95rem] px-5 py-3.5 hover:bg-[#05b34c] transition-colors"
    >
      <span class="material-symbols-rounded text-[22px]">chat</span>
      <span>แชทผ่าน LINE</span>
    </a>

  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import api from '../lib/axios';
import { tripCardImage } from '../lib/tripImage';
import CommunityFeedStrip from '../components/CommunityFeedStrip.vue';
import LatestArticles from '../components/LatestArticles.vue';
import HeroSearchBar from '../components/HeroSearchBar.vue';
import TrustStrip from '../components/TrustStrip.vue';
import SectionTitle from '../components/SectionTitle.vue';
import DepartureBoard from '../components/home/DepartureBoard.vue';
import TripShowcase from '../components/home/TripShowcase.vue';
import PopularDestinations from '../components/home/PopularDestinations.vue';
import GroupTripBand from '../components/home/GroupTripBand.vue';
import { supportHours, supportLineUrl } from '../lib/contact';


const heroImages = ref([
  '/images/phusoidao.webp',
  '/images/snorkel.webp',
  '/images/phukradueng.webp',
  '/images/landscape.webp',
  '/images/khaochangphueak.webp',
  '/images/hiking.webp',
]);
const HERO_SLIDE_INTERVAL_MS = 7000;
const currentSlide = ref(0);
const prefersReducedMotion = ref(false);
let sliderInterval = null;
let sliderPaused = false;

const featuredTrips = ref([]);
const loading = ref(true);

const helpOptions = [
  { to: '/find', icon: 'travel_explore', title: 'ช่วยเลือกทริป', desc: 'ตอบคำถามสั้น ๆ ไม่กี่ข้อ แล้วให้ระบบแนะนำทริปที่เหมาะ' },
  { to: '/assistant', icon: 'auto_awesome', title: 'ถามผู้ช่วย', desc: 'พิมพ์บอกงบ วันที่ว่าง และสิ่งที่อยากเจอ ผู้ช่วยหาให้' },
  { to: supportLineUrl(), external: true, line: true, title: 'คุยกับทีมงาน', desc: `ทักไลน์ได้เลย ทีมงานตอบ${supportHours()}` },
];

// หมวดทริปจากแอดมิน — ใช้ทั้งแท็บแพ็กเกจและทางลัดในแผงค้นหา
const tripCategories = ref([]);

// ทางลัดบริการในแผงค้นหา: หมวดทริป (ไม่เกิน 4) แล้วตามด้วยบริการอื่นของเว็บ
const shortcuts = computed(() => [
  ...tripCategories.value.slice(0, 4).map((c) => ({
    to: { path: '/trips', query: { type: c.slug } },
    icon: c.icon || 'explore',
    label: c.name,
  })),
  { to: '/calendar', icon: 'event', label: 'ปฏิทินทริป' },
  { to: '/explore', icon: 'map', label: 'แผนที่ทริป' },
  { to: '/stays', icon: 'cottage', label: 'ที่พักชุมชน' },
  { to: '/how-to-book', icon: 'menu_book', label: 'วิธีการจอง' },
]);

// สตริงเต็มให้ Tailwind เห็น — 8 ช่องพอดีหนึ่งแถวบนจอใหญ่
const shortcutColsClass = computed(() => {
  const n = shortcuts.value.length;
  if (n >= 8) return 'lg:grid-cols-8';
  if (n === 7) return 'lg:grid-cols-7';
  if (n === 6) return 'lg:grid-cols-6';
  return 'lg:grid-cols-5';
});




/*
 * สไลด์ฮีโร่: สไลด์แรกเป็นแบรนด์เสมอ (ใช้ภาพแรกจาก /hero-slides และถือ h1 ของหน้า)
 * ตามด้วยทริปแนะนำไม่เกิน 5 ทริป — แต่ละสไลด์บอกชื่อ ปลายทาง จำนวนวัน และราคาเริ่มต้นจริง
 * ถ้ายังไม่มีทริปแนะนำ ใช้ภาพที่เหลือจาก /hero-slides เป็นสไลด์แบรนด์แทน
 */
const BRAND_COPY = {
  title: 'เดินป่า ดำน้ำ ขึ้นดอย',
  accent: 'รู้ทุกอย่างก่อนออกเดินทาง',
  subtitle: 'ทุกรอบบอกระยะทาง ความสูงสะสม จุดรับ และเวลาออกไว้ล่วงหน้า ระหว่างทางที่บ้านตามดูตำแหน่งรถได้ตลอด',
  cta: { to: '/trips', label: 'ดูทริปที่เปิดจอง' },
  cta2: { to: '/calendar', label: 'ปฏิทินทริป' },
};

function tripSlideSubtitle(trip) {
  const parts = [];
  const days = Number(trip.duration_days) || 1;
  parts.push(days > 1 ? `${days} วัน ${days - 1} คืน` : 'ไปเช้าเย็นกลับ');
  const price = Number(trip.min_price);
  if (price > 0) parts.push(`เริ่มต้น ${price.toLocaleString('th-TH')} บาท`);
  return parts.join(' · ');
}

const heroSlides = computed(() => {
  const brand = {
    key: 'brand',
    image: heroImages.value[0],
    alt: 'ลุยเลเขา',
    ...BRAND_COPY,
  };
  const tripSlides = featuredTrips.value
    .filter((t) => t.slug && (t.cover_image || tripCardImage(t)))
    .slice(0, 5)
    .map((t) => ({
      key: `trip-${t.id}`,
      image: t.cover_image || tripCardImage(t),
      alt: t.title,
      kicker: t.location ? `ทริปแนะนำ · ${t.location}` : 'ทริปแนะนำ',
      title: t.title,
      subtitle: tripSlideSubtitle(t),
      cta: { to: `/trips/${t.slug}`, label: 'ดูรายละเอียด' },
    }));
  if (tripSlides.length) return [brand, ...tripSlides];
  return heroImages.value.map((img, i) => ({ ...brand, key: `brand-${i}`, image: img }));
});

function goSlide(index) {
  const n = heroSlides.value.length;
  if (!n) return;
  currentSlide.value = (index + n) % n;
  restartSlider();
}

function restartSlider() {
  clearInterval(sliderInterval);
  sliderInterval = null;
  if (unmounted || sliderPaused || prefersReducedMotion.value || heroSlides.value.length < 2) return;
  sliderInterval = setInterval(() => {
    currentSlide.value = (currentSlide.value + 1) % heroSlides.value.length;
  }, HERO_SLIDE_INTERVAL_MS);
}

function pauseSlider() {
  sliderPaused = true;
  clearInterval(sliderInterval);
  sliderInterval = null;
}

function resumeSlider() {
  sliderPaused = false;
  restartSlider();
}

// ทริปทั้งหมดที่ส่งให้แถบค้นหาบนฮีโร่ (แถบนั้นดูแลรอบเดินทางของตัวเอง)
const allTrips = ref([]);

const statsSection = ref(null);
const isVisible = ref(false);

// ตัวเลขทั้งหมดนี้คำนวณจริงจากรอบที่เดินทางจบแล้ว (ดู CommunityStatsService)
// ไม่ใช่ตัวเลขโฆษณา — ค่า target เริ่มต้นเป็น 0 แล้วเติมจาก /stats
const statItems = ref([
  {
    icon: 'footprint',
    target: 0,
    displayValue: '0',
    suffix: 'กม.',
    label: 'ระยะทางที่ชุมชนเราเดินสะสม',
    context: '',
  },
  {
    icon: 'landscape',
    target: 0,
    displayValue: '0',
    suffix: 'ม.',
    label: 'ความสูงที่ไต่ขึ้นไปรวมกัน',
    context: '',
  },
  {
    icon: 'groups',
    target: 0,
    displayValue: '0',
    suffix: 'คน',
    label: 'นักเดินทางที่ไปกับเราจริง',
    context: '',
  },
  {
    icon: 'star',
    target: 0,
    displayValue: '0.0',
    suffix: '/5',
    label: 'คะแนนจากคนที่เดินทางแล้ว',
    context: '',
  },
]);

/**
 * โชว์เฉพาะสถิติที่มีข้อมูลจริง — ถ้ายังไม่มีรอบที่เดินทางจบ หรือทริปยังไม่ได้กรอก
 * ระยะทาง/ความสูง การ์ดนั้นจะหายไปแทนที่จะขึ้นเลข 0 ให้ดูเหมือนเว็บพัง
 */
const visibleStats = computed(() => statItems.value.filter((s) => s.target > 0));

const statsGridColsClass = computed(() => {
  const n = visibleStats.value.length;
  if (n <= 2) return 'lg:grid-cols-2 max-w-3xl mx-auto';
  if (n === 3) return 'lg:grid-cols-3';
  return 'lg:grid-cols-4';
});

const animateStats = () => {
  visibleStats.value.forEach((stat) => {
    const duration = 2000; // ms
    const startTime = performance.now();
    const frame = (now) => {
      const progress = Math.min((now - startTime) / duration, 1);
      const easeProgress = 1 - Math.pow(1 - progress, 3); // easeOutCubic
      
      const current = easeProgress * stat.target;
      
      if (typeof stat.target === 'number' && stat.target % 1 !== 0) {
        stat.displayValue = current.toFixed(1);
      } else {
        stat.displayValue = Math.floor(current).toLocaleString();
      }

      if (progress < 1) {
        requestAnimationFrame(frame);
      } else {
        // Final values for perfection
        stat.displayValue = stat.target % 1 !== 0 
          ? stat.target.toFixed(1) 
          : stat.target.toLocaleString();
      }
    };
    requestAnimationFrame(frame);
  });
};

const reviews = ref([]);

// Duplicate the list so the marquee can loop seamlessly; with very few
// reviews we repeat more times to keep the track wide enough to fill the screen.
const marqueeReviews = computed(() => {
  const list = reviews.value;
  if (!list.length) return [];
  const repeats = list.length < 4 ? 4 : 2;
  return Array.from({ length: repeats }, () => list).flat();
});

// Scale the scroll duration to the number of cards so speed stays consistent.
const marqueeDuration = computed(() => Math.max(30, reviews.value.length * 8));

const trustItems = [
  {
    image: '/images/travel_safety.webp',
    title: 'ความปลอดภัยมาก่อน',
    desc: 'ตรวจสภาพรถและอุปกรณ์ก่อนออกเดินทางทุกรอบ พร้อมปุ่มแจ้งเหตุฉุกเฉินที่ถึงทีมงานทันที',
  },
  {
    // เคยเขียนว่า 24/7 ทั้งที่หน้าติดต่อกับหน้าชำระเงินบอกเวลาทำการไว้ชัดเจน —
    // คำโฆษณาที่เว็บตัวเองแย้งสองหน้าถัดมา ทำให้ที่เหลือพลอยไม่น่าเชื่อไปด้วย
    image: '/images/247_support.webp',
    title: 'มีทีมงานอยู่ด้วยตลอดทริป',
    desc: `ทีมงานดูแลตั้งแต่ก่อนออกเดินทางจนกลับถึงบ้าน สอบถามก่อนเดินทางได้${supportHours()} ส่วนวันเดินทางติดต่อทีมงานได้ตลอดทริป`,
  },
  {
    image: '/images/nature_travel.webp',
    title: 'ท่องเที่ยวสายอนุรักษ์',
    desc: 'ทุกทริปของเรามุ่งเน้นความยั่งยืน สนับสนุนชุมชนท้องถิ่นและอนุรักษ์ธรรมชาติอย่างจริงจัง',
  },
  {
    // "จ่ายครั้งเดียวจบ" ขัดกับตัวสินค้าเอง — มีทั้งจุดรับที่ราคาต่างกัน
    // ประเภทรถ อุปกรณ์เช่า และการผ่อน/แบ่งจ่าย สิ่งที่เรารับปากได้จริงคือ
    // เห็นยอดสุทธิครบก่อนกดจ่าย ไม่ใช่ว่ามีค่าใช้จ่ายก้อนเดียว
    image: '/images/nohidden_show.webp',
    title: 'เห็นยอดสุทธิก่อนจ่าย',
    desc: 'ค่าจุดขึ้นรถ ประเภทรถ และของเช่า คิดแยกให้เห็นทีละรายการ แล้วสรุปยอดสุทธิให้ดูก่อนกดชำระเงินเสมอ',
  },
];

let unmounted = false;

onUnmounted(() => {
  unmounted = true;
  clearInterval(sliderInterval);
});

onMounted(async () => {
  if (typeof window !== 'undefined') {
    prefersReducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  // Hero slides load alongside everything else (they used to hold the whole page
  // back by one round trip); keep the static fallback if the API returns empty.
  (async () => {
    try {
      const heroRes = await api.get('/hero-slides');
      const apiSlides = (heroRes.data.data ?? heroRes.data) || [];
      if (apiSlides.length) {
        heroImages.value = apiSlides.map((s) => s.image_url);
      }
    } catch {
      // keep static fallback
    }

    // ออกจากหน้าไปก่อนรูปมา — restartSlider เช็ค unmounted ให้แล้ว
    restartSlider();
  })();

  try {
    const [featuredRes, reviewsRes, statsRes, allTripsRes, categoriesRes] = await Promise.all([
      api.get('/trips/featured'),
      api.get('/reviews', { params: { per_page: 30 } }),
      api.get('/stats'),
      api.get('/trips', { params: { per_page: 100 } }),
      api.get('/categories'),
    ]);
    // ชุดเดียว 100 ทริป (เรียงตามความนิยม) ป้อนทุก section: ตารางรอบ แท็บแพ็กเกจ ปลายทาง และช่องค้นหา
    allTrips.value = allTripsRes.data.data || [];
    featuredTrips.value = featuredRes.data.data || [];
    restartSlider();
    reviews.value = reviewsRes.data.data || [];
    tripCategories.value = categoriesRes.data.data || [];
    
    // Update stats — ใช้สถิติจริงของชุมชน (ระยะทาง/ความสูงสะสม) แทนตัวเลขโฆษณา
    if (statsRes.data?.data) {
      const s = statsRes.data.data;
      const c = s.community || {};

      statItems.value[0].target = Math.round(c.total_distance_km || 0);
      statItems.value[0].context = c.rounds_completed
        ? `จาก ${c.rounds_completed.toLocaleString()} รอบที่เดินทางจบแล้ว`
        : '';

      statItems.value[1].target = c.total_elevation_gain_m || 0;
      statItems.value[1].context = c.highlights?.inthanon_multiple
        ? `เท่ากับปีนดอยอินทนนท์ ${c.highlights.inthanon_multiple.toLocaleString()} รอบ`
        : '';

      statItems.value[2].target = c.travellers_count || 0;
      statItems.value[2].context = c.regions_count
        ? `ไปมาแล้ว ${c.regions_count} ภูมิภาคทั่วไทย`
        : '';

      // avg_rating เป็น 5.0 by default แม้ยังไม่มีรีวิว — อย่าโชว์คะแนนที่ไม่มีใครให้
      statItems.value[3].target = c.reviews_count ? s.avg_rating : 0;
      statItems.value[3].context = c.reviews_count
        ? `จาก ${c.reviews_count.toLocaleString()} รีวิวของคนที่เดินทางจริง`
        : '';
    }

    // รอให้การ์ดสถิติ render ตาม v-if ก่อนค่อยผูก observer
    await nextTick();

    // Set up IntersectionObserver
    const observer = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting && !isVisible.value) {
        isVisible.value = true;
        animateStats();
      }
    }, { threshold: 0.2 });

    if (statsSection.value) {
      observer.observe(statsSection.value);
    }

    // Reveal animation observer
    const revealObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible')
            revealObserver.unobserve(entry.target)
          }
        })
      },
      { threshold: 0.1, rootMargin: "0px 0px -50px 0px" }
    )
    document.querySelectorAll('.reveal-section').forEach((el) => revealObserver.observe(el))
  } catch (e) {
    console.error('Failed to load home data', e);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
/* Hero carousel */
.hero-slide {
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.9s ease, visibility 0s linear 0.9s;
  z-index: 0;
}
.hero-slide--active {
  opacity: 1;
  visibility: visible;
  transition: opacity 0.9s ease, visibility 0s;
  z-index: 1;
}
.hero-slide-img {
  transform: scale(1.04);
  transition: transform 7s ease-out;
}
.hero-slide--active .hero-slide-img {
  transform: scale(1);
}

.hero-title {
  text-shadow: 0 2px 18px rgba(0, 0, 0, 0.35);
}
.hero-subtitle,
.hero-kicker {
  text-shadow: 0 1px 10px rgba(0, 0, 0, 0.35);
}

/* ข้อความในสไลด์ที่เพิ่งขึ้นมาไล่เข้าทีละบรรทัด */
.hero-slide--active .hero-kicker,
.hero-slide--active .hero-title,
.hero-slide--active .hero-subtitle,
.hero-slide--active .hero-actions {
  animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.hero-slide--active .hero-title { animation-delay: 0.08s; }
.hero-slide--active .hero-subtitle { animation-delay: 0.16s; }
.hero-slide--active .hero-actions { animation-delay: 0.24s; }

.hero-cta {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.8rem 1.25rem 0.8rem 1.75rem;
  border-radius: 9999px;
  background-color: var(--color-accent);
  color: #fff;
  font-size: 17px;
  font-weight: 700;
  transition: background-color 0.2s ease;
}
.hero-cta:hover {
  background-color: var(--color-accent-mid);
}
.hero-cta--ghost {
  padding: 0.8rem 1.75rem;
  background-color: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.55);
}
.hero-cta--ghost:hover {
  background-color: rgba(255, 255, 255, 0.22);
}
.hero-cta:focus-visible,
.hero-arrow:focus-visible,
.hero-dot:focus-visible {
  outline: 3px solid rgba(255, 255, 255, 0.7);
  outline-offset: 2px;
}

.hero-arrow {
  position: absolute;
  top: 50%;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 4rem;
  transform: translateY(-50%);
  color: rgba(255, 255, 255, 0.85);
  transition: color 0.2s ease, transform 0.2s ease;
}
.hero-arrow:hover {
  color: #fff;
}
@media (max-width: 639px) {
  .hero-arrow { display: none; }
}

.hero-dot {
  width: 0.875rem;
  height: 0.875rem;
  border-radius: 9999px;
  border: 2px solid #fff;
  background-color: transparent;
  transition: background-color 0.2s ease, transform 0.2s ease;
}
.hero-dot:hover {
  transform: scale(1.15);
}
.hero-dot--active {
  background-color: #fff;
}

@media (prefers-reduced-motion: reduce) {
  .hero-slide,
  .hero-slide--active,
  .hero-slide-img {
    transition: none;
  }
  .hero-slide--active .hero-kicker,
  .hero-slide--active .hero-title,
  .hero-slide--active .hero-subtitle,
  .hero-slide--active .hero-actions {
    animation: none;
  }
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(40px); }
  to   { opacity: 1; transform: translateY(0); }
}

@keyframes subtle-zoom {
  from { transform: scale(1); }
  to   { transform: scale(1.1); }
}

/* Reveal on scroll */
.reveal-section {
  opacity: 0;
  transform: translateY(40px);
  transition: opacity 1s cubic-bezier(0.16, 1, 0.3, 1), transform 1s cubic-bezier(0.16, 1, 0.3, 1);
}

.reveal-section.is-visible {
  opacity: 1;
  transform: translateY(0);
}

/* Scroll indicator dot */
.scroll-dot {
  animation: scrollBounce 2s ease-in-out infinite;
}

@keyframes scrollBounce {
  0%, 100% { transform: translateY(0); opacity: 1; }
  50%      { transform: translateY(12px); opacity: 0.3; }
}

/* Reviews marquee */
.reviews-marquee {
  position: relative;
  width: 100%;
  overflow: hidden;
  -webkit-mask-image: linear-gradient(to right, transparent, #000 6%, #000 94%, transparent);
  mask-image: linear-gradient(to right, transparent, #000 6%, #000 94%, transparent);
}

.reviews-marquee__track {
  display: flex;
  gap: 2rem;
  width: max-content;
  padding: 1rem 1rem;
  animation: reviewsScroll var(--marquee-duration, 40s) linear infinite;
  will-change: transform;
}

/* Loop seamlessly: the list is rendered twice, so shifting by half the
   track width lands exactly on the duplicate's start. */
@keyframes reviewsScroll {
  from { transform: translateX(0); }
  to   { transform: translateX(-50%); }
}

/* Pause when the user hovers, so they can read a card */
.reviews-marquee.group:hover .reviews-marquee__track {
  animation-play-state: paused;
}

.reviews-marquee__card {
  flex: 0 0 auto;
  width: 360px;
  max-width: 85vw;
}

@media (prefers-reduced-motion: reduce) {
  .reviews-marquee__track {
    animation: none;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
  }
  .reviews-marquee {
    -webkit-mask-image: none;
    mask-image: none;
  }
}

/* Category rail (slider when > 4 categories) */
.cat-rail {
  scrollbar-width: none;
  -ms-overflow-style: none;
  scroll-padding: 0 0.25rem;
}
.cat-rail::-webkit-scrollbar {
  display: none;
}

.cat-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 20;
  display: none;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 9999px;
  background: white;
  color: var(--color-text-dark);
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
  border: 1px solid rgba(0, 0, 0, 0.05);
  cursor: pointer;
  transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
}
.cat-nav:hover {
  background: var(--color-accent);
  color: white;
  box-shadow: 0 16px 36px rgba(45, 122, 79, 0.35);
}
.cat-nav:active {
  transform: translateY(-50%) scale(0.92);
}
@media (min-width: 768px) {
  .cat-nav {
    display: flex;
  }
}

/* Line clamp */
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Date picker customization */
input[type="date"]::-webkit-calendar-picker-indicator {
  opacity: 0;
  position: absolute;
  width: 100%;
  height: 100%;
  cursor: pointer;
}
input[type="date"] {
  position: relative;
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .hero-slide,
  .hero-slide-img,
  .hero-content,
  .hero-trust,
  .search-bar,
  .scroll-dot,
  .animate-ping,
  .animate-spin {
    animation: none !important;
    transition: none !important;
    opacity: 1 !important;
    transform: none !important;
  }
}

/* ── แผงค้นหา + ทางลัด ───────────────────────────────────────── */
.home-search {
  max-width: none;
}
.shortcut {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.25rem;
  border-radius: 0.75rem;
  text-align: center;
  transition: background-color 0.2s ease;
}
.shortcut:hover {
  background-color: var(--color-sand);
}
.shortcut-ico {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: 9999px;
  border: 1.5px solid color-mix(in srgb, var(--color-accent) 30%, transparent);
  background-color: var(--color-accent-tint);
  color: var(--color-accent);
  transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
}
.shortcut:hover .shortcut-ico {
  background-color: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
}
.shortcut-label {
  font-size: 13px;
  font-weight: 700;
  line-height: 1.25;
  color: var(--color-text-dark);
}
@media (min-width: 768px) {
  .shortcut-ico { width: 3.5rem; height: 3.5rem; }
  .shortcut-label { font-size: 14.5px; }
}

.home-panel-wrap {
  display: flow-root;
  background-color: #fff;
}

/* ── การ์ดทางเลือกปิดท้าย ───────────────────────────────────── */
.help-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem;
  border-radius: 0.875rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.help-card:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  background-color: color-mix(in srgb, var(--color-accent-tint) 50%, #fff);
}
.help-ico {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3.25rem;
  height: 3.25rem;
  flex-shrink: 0;
  border-radius: 9999px;
  background-color: var(--color-accent-tint);
  color: var(--color-accent);
}
.help-ico--line {
  background-color: #06c755;
  color: #fff;
}
.help-ico i {
  font-size: 24px;
  line-height: 1;
}
</style>
