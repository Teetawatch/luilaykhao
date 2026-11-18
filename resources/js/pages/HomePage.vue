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
        <div class="absolute bottom-5 md:bottom-7 inset-x-0 z-20 flex justify-center gap-2.5">
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

    <!-- แถบใบอนุญาต + ช่องทางติดต่อ ใต้ภาพหลัก (ชุดเดียวกับทุกหน้า) -->
    <TrustStrip />

    <!-- ══════════════════════════════════════════
         ค้นหารอบเดินทาง — เลือกทริป → เลือกรอบ
    ══════════════════════════════════════════ -->
    <section class="bg-white pt-8 pb-10 md:pt-10 md:pb-14">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <p class="text-center text-[15px] md:text-base font-semibold text-[var(--color-text-mid)] mb-4">
          ค้นหารอบเดินทางที่ว่าง เลือกทริปแล้วเลือกวันได้เลย
        </p>
        <HeroSearchBar :trips="allTrips" :loading="loading" />
        <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 mt-6 text-[var(--color-text-muted)] text-[13px] md:text-sm font-medium">
          <span class="inline-flex items-center gap-1.5">
            <span class="material-symbols-rounded icon-18 text-[var(--color-accent)]">near_me</span>
            ติดตามตำแหน่งรถได้ระหว่างเดินทาง
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="material-symbols-rounded icon-18 text-[var(--color-accent)]">receipt_long</span>
            โอนแล้วระบบตรวจสลิปให้อัตโนมัติ
          </span>
          <router-link to="/find" class="inline-flex items-center gap-1.5 font-bold text-[var(--color-accent)] hover:underline underline-offset-2">
            <span class="material-symbols-rounded icon-18">auto_awesome</span>
            ยังเลือกไม่ถูก? ให้เราช่วยหาทริปที่ใช่
          </router-link>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         SOCIAL PROOF & TRUST SECTION (Redesigned)
    ══════════════════════════════════════════ -->
    <section
      v-if="visibleStats.length"
      ref="statsSection"
      class="bg-[var(--color-sand)] relative z-10 py-16 md:py-20"
    >
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <SectionTitle class="mb-10 md:mb-12" title="ตัวเลขจากการเดินทางจริง" subtitle="คำนวณจากรอบที่เดินทางจบแล้วในระบบ อัปเดตอัตโนมัติทุกชั่วโมง" />
        <div class="grid grid-cols-2 gap-4 md:gap-6 relative z-20" :class="statsGridColsClass">
          <div
            v-for="(stat, index) in visibleStats"
            :key="stat.label"
            class="bg-white rounded-xl p-6 lg:p-7 flex flex-col items-center text-center border border-[var(--color-sand-dark)] transition-opacity duration-500"
            :class="[isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6']"
            :style="{ transitionDelay: `${index * 120}ms` }"
          >
            <!-- Icon -->
            <div class="w-11 h-11 rounded-xl bg-[var(--color-primary)]/[0.06] flex items-center justify-center text-[var(--color-primary)] mb-4">
              <span class="material-symbols-rounded text-[24px]">{{ stat.icon }}</span>
            </div>

            <!-- Statistical Value with Count-up -->
            <div class="flex items-baseline gap-1 mb-1.5">
              <span class="text-3xl lg:text-[2.6rem] font-extrabold text-[var(--color-text-dark)] tracking-tight leading-none tabular-nums">
                {{ stat.displayValue }}
              </span>
              <span v-if="stat.suffix" class="text-base lg:text-lg font-bold text-[var(--color-text-muted)]">{{ stat.suffix }}</span>
            </div>

            <!-- Label -->
            <div class="text-[13px] lg:text-sm font-bold text-[var(--color-text-dark)] leading-snug">
              {{ stat.label }}
            </div>

            <!-- ที่มาของตัวเลข — สิ่งที่ทำให้ต่างจากตัวเลขโฆษณา -->
            <div v-if="stat.context" class="text-[11px] lg:text-xs text-[var(--color-text-muted)] mt-1.5 leading-snug">
              {{ stat.context }}
            </div>
          </div>
        </div>

      </div>
    </section>

    <!-- ══════════════════════════════════════════
         COMMUNITY FEED — รูปจริงจากคนที่ไปมาแล้ว
         วางไว้ก่อนแถวขายทริป: เล่าเรื่องคนก่อน แล้วค่อยขาย
    ══════════════════════════════════════════ -->
    <CommunityFeedStrip />

    <!-- ══════════════════════════════════════════
         ALMOST-FULL RAIL — รีบจองก่อนเต็ม
    ══════════════════════════════════════════ -->
    <section v-if="almostFullTrips.length" class="bg-white py-16 md:py-20">
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <SectionTitle class="mb-10" title="รอบที่ใกล้เต็ม" subtitle="รอบที่ที่นั่งเหลือน้อย ถ้าสนใจควรรีบจองก่อนเต็ม" />
        <div class="flex gap-5 overflow-x-auto pb-4 snap-x snap-mandatory">
          <div v-for="t in almostFullTrips" :key="t.id" class="snap-start shrink-0 w-72">
            <TripCard :trip="t" />
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         CATEGORIES SECTION
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24 bg-[var(--color-sand)] relative overflow-hidden">
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <SectionTitle
          class="mb-10 md:mb-14"
          title="บริการของเรา"
          subtitle="แต่ละแบบใช้แรงและความพร้อมต่างกัน เลือกจากสิ่งที่คุณอยากเจอ"
        />

        <div class="relative">
        <div
          ref="categoryRail"
          :class="isCategoryRail
            ? 'flex gap-6 lg:gap-8 overflow-x-auto snap-x snap-mandatory cat-rail py-6 -my-6 px-1'
            : ['grid grid-cols-1 sm:grid-cols-2 gap-6 lg:gap-8', categoryGridColsClass]"
        >
          <router-link
            v-for="(cat, index) in categories"
            :key="cat.type"
            :to="`/trips?type=${cat.type}`"
            :class="[
              'group relative rounded-2xl overflow-hidden block isolate h-[380px] lg:h-[440px] transition-transform duration-500 ease-out hover:-translate-y-1',
              isCategoryRail ? 'snap-start shrink-0 w-[80vw] sm:w-[360px] lg:w-[300px]' : '',
            ]"
            :style="`--cat-color: ${cat.color}; background-color: ${cat.bgColor}`"
          >
            <!-- Premium Background Image with Dynamic Zoom -->
            <div class="absolute inset-0 z-0 overflow-hidden rounded-2xl">
              <img
                :src="cat.image"
                :alt="cat.label"
                loading="lazy"
                class="w-full h-full object-cover object-center scale-105 transition-transform duration-[2s] ease-out group-hover:scale-125"
              />
              <!-- Legibility gradient -->
              <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/35 to-black/10"></div>
              <!-- Brand-color wash on hover -->
              <div
                class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-700"
                :style="`background: linear-gradient(to top, ${cat.color}cc, transparent 60%)`"
              ></div>
              <!-- Inner edge highlight -->
              <div class="absolute inset-0 ring-1 ring-inset ring-white/10 rounded-2xl pointer-events-none"></div>
            </div>

            <div class="p-8 lg:p-10 flex flex-col h-full relative z-10">
              <!-- Top Row -->
              <div class="flex justify-between items-start">
                <div class="flex items-center gap-3">
                  <span class="text-white/40 font-black text-lg tabular-nums tracking-widest">0{{ index + 1 }}</span>
                  <div
                    v-if="cat.isPopular"
                    class="flex items-center gap-1.5 bg-[var(--color-gold)]/95 text-[var(--color-text-dark)] text-[10px] font-black uppercase tracking-[0.15em] px-3 py-1.5 rounded-full"
                  >
                    <span class="material-symbols-rounded text-[14px]">star</span>
                    ยอดนิยม
                  </div>
                </div>
                <!-- Icon Badge -->
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center backdrop-blur-md border border-white/20 bg-white/10 text-white transition-all duration-500 group-hover:scale-110 group-hover:bg-[var(--cat-color)] group-hover:border-white/40">
                  <span class="material-symbols-rounded text-[26px]">{{ cat.icon }}</span>
                </div>
              </div>

              <!-- Content Area -->
              <div class="mt-auto">
                <div class="transform transition-transform duration-500 group-hover:-translate-y-1">
                  <h3 class="text-3xl lg:text-[2.25rem] font-bold text-white tracking-tight mb-3 leading-none">
                    {{ cat.label }}
                  </h3>
                  <p class="text-white/75 text-[15px] font-medium leading-relaxed max-w-[92%] mb-6 transition-colors duration-500 group-hover:text-white/90">
                    {{ cat.subtext }}
                  </p>
                </div>

                <!-- Actionable CTA -->
                <div class="inline-flex items-center gap-3 text-sm font-bold text-white tracking-wide bg-white/10 backdrop-blur-xl pl-6 pr-5 py-3 rounded-full border border-white/25 transition-all duration-500 group-hover:bg-white group-hover:text-[var(--cat-color)] group-hover:border-white">
                  <span>{{ cat.ctaText }}</span>
                  <span class="material-symbols-rounded text-[20px] transition-transform duration-500 group-hover:translate-x-1">arrow_forward</span>
                </div>
              </div>
            </div>

            <!-- Top sheen on hover -->
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/40 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-700 z-10"></div>
          </router-link>
        </div>

        <!-- Slider nav (only when categories overflow into a rail) -->
        <template v-if="isCategoryRail">
          <button
            type="button"
            @click="scrollCategoryRail(-1)"
            class="cat-nav -left-4 lg:-left-5"
            aria-label="ก่อนหน้า"
          >
            <span class="material-symbols-rounded text-[24px]">chevron_left</span>
          </button>
          <button
            type="button"
            @click="scrollCategoryRail(1)"
            class="cat-nav -right-4 lg:-right-5"
            aria-label="ถัดไป"
          >
            <span class="material-symbols-rounded text-[24px]">chevron_right</span>
          </button>
        </template>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         POPULAR EXPERIENCES SECTION
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24 relative overflow-hidden bg-white">
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <SectionTitle
          class="mb-10 md:mb-14"
          title="ทริปยอดนิยม"
          subtitle="เรียงจากยอดจองจริงในระบบ อัปเดตรอบเดินทางล่าสุด"
        />

        <!-- Loading -->
        <div v-if="loading" class="flex justify-center py-20">
          <div class="flex flex-col items-center gap-5">
            <div class="w-12 h-12 border-4 border-[var(--color-sand)] border-t-[var(--color-accent)] rounded-full animate-spin"></div>
            <span class="text-[var(--color-text-muted)] font-bold tracking-wide">กำลังโหลดทริป...</span>
          </div>
        </div>

        <!-- Cards Grid -->
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 md:gap-7">
          <router-link
            v-for="trip in trips"
            :key="trip.id"
            :to="`/trips/${trip.slug}`"
            class="group relative flex flex-col bg-white rounded-xl overflow-hidden border border-[var(--color-sand-dark)] transition-[transform,border-color] duration-300 ease-out hover:-translate-y-1 hover:border-[var(--color-accent)]/40"
          >
            <!-- Image -->
            <div class="relative overflow-hidden aspect-[4/3]">
              <img
                v-if="tripCardImage(trip)"
                :src="tripCardImage(trip)"
                :alt="trip.title"
                class="w-full h-full object-cover transition-transform duration-[900ms] ease-out group-hover:scale-[1.09]"
                @error="(e) => e.target.style.display='none'"
              />
              <div v-else class="w-full h-full bg-[var(--color-sand)] flex items-center justify-center">
                <span class="material-symbols-rounded text-gray-300 text-5xl">image</span>
              </div>

              <!-- Gradient Overlay -->
              <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent"></div>

              <!-- Top row: type badge + wishlist -->
              <div class="absolute top-3.5 left-3.5 right-3.5 flex items-start justify-between gap-2">
                <span
                  class="px-3 py-1.5 rounded-full text-[11px] font-black tracking-wide backdrop-blur-md"
                  :class="typeBadgeClass(trip.type)"
                >
                  {{ typeLabel(trip.type) }}
                </span>
                <button
                  class="w-9 h-9 flex items-center justify-center transition-all duration-300 rounded-full border cursor-pointer active:scale-75 backdrop-blur-md z-20"
                  :class="wishlistStore.isFavorite(trip.id)
                    ? 'border-red-400/80 text-red-500 bg-white/90'
                    : 'border-white/50 text-white bg-black/15 hover:bg-black/30'"
                  @click.prevent="wishlistStore.toggleFavorite(trip)"
                  aria-label="บันทึกรายการโปรด"
                >
                  <span
                    class="material-symbols-rounded text-[19px]"
                    :style="wishlistStore.isFavorite(trip.id) ? 'font-variation-settings:\'FILL\' 1' : ''"
                  >
                    favorite
                  </span>
                </button>
              </div>

              <!-- Bottom chips: location + duration -->
              <div class="absolute bottom-3.5 left-3.5 right-3.5 flex items-center gap-2 text-white">
                <div v-if="trip.location" class="min-w-0 flex items-center gap-1.5 bg-white/15 backdrop-blur-md px-2.5 py-1.5 rounded-full">
                  <span class="material-symbols-rounded text-[14px] shrink-0">location_on</span>
                  <span class="text-xs font-bold truncate">{{ trip.location }}</span>
                </div>
                <div class="ml-auto shrink-0 flex items-center gap-1.5 bg-white/15 backdrop-blur-md px-2.5 py-1.5 rounded-full">
                  <span class="material-symbols-rounded text-[14px]">schedule</span>
                  <span class="text-xs font-bold">{{ trip.duration_days || 1 }} วัน</span>
                </div>
              </div>
            </div>

            <!-- Info -->
            <div class="p-5 flex-1 flex flex-col">
              <div class="flex items-center gap-1.5 mb-2.5 min-h-[22px]">
                <span class="material-symbols-rounded text-[16px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                <template v-if="trip.review_count > 0">
                  <span class="text-[var(--color-text-dark)] font-bold text-sm">{{ Number(trip.rating).toFixed(1) }}</span>
                  <span class="text-gray-400 text-xs font-medium">({{ trip.review_count }} รีวิว)</span>
                </template>
                <span v-else class="text-gray-400 text-xs font-medium">ทริปใหม่</span>
                <div v-if="trip.confirmed_passengers_count > 0" class="ml-auto flex items-center gap-1 text-[var(--color-accent)] font-bold text-xs bg-[var(--color-accent)]/10 px-2 py-0.5 rounded-full">
                  <span class="material-symbols-rounded text-[14px]">group</span>
                  <span>{{ trip.confirmed_passengers_count }} คน</span>
                </div>
              </div>

              <h3 class="text-[1.1rem] font-bold text-[var(--color-text-dark)] mb-4 group-hover:text-[var(--color-accent)] transition-colors duration-300 leading-snug line-clamp-2">
                {{ trip.title }}
              </h3>

              <div class="mt-auto pt-4 flex justify-between items-end border-t border-[var(--color-sand-dark)]">
                <div class="flex flex-col min-w-0">
                  <span class="text-[11px] text-[var(--color-text-muted)] font-bold uppercase tracking-wider mb-0.5">
                    {{ Number(trip.min_price) != Number(trip.max_price) ? 'ช่วงราคา' : 'เริ่มต้น' }}
                  </span>
                  <span class="text-xl font-black text-[var(--color-text-dark)] truncate">
                    <template v-if="Number(trip.min_price) != Number(trip.max_price)">฿{{ Number(trip.min_price).toLocaleString() }} - {{ Number(trip.max_price).toLocaleString() }}</template>
                    <template v-else>฿{{ Number(trip.min_price).toLocaleString() }}</template>
                  </span>
                </div>
                <div class="shrink-0 w-10 h-10 rounded-full bg-[var(--color-sand)] flex items-center justify-center group-hover:bg-[var(--color-accent)] group-hover:text-white transition-all duration-300">
                  <span class="material-symbols-rounded text-[20px] transition-transform duration-300 group-hover:translate-x-0.5">arrow_forward</span>
                </div>
              </div>
            </div>
          </router-link>
        </div>

        <!-- View all CTA -->
        <div class="text-center mt-12">
          <router-link
            to="/trips"
            class="inline-flex items-center gap-2 bg-[var(--color-accent)] hover:bg-[var(--color-accent-mid)] text-white px-9 py-3.5 rounded-full font-bold text-base transition-colors duration-200 cursor-pointer"
          >
            ดูทริปทั้งหมด
            <span class="material-symbols-rounded icon-22">chevron_right</span>
          </router-link>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         FEATURED / ASYMMETRIC SECTION
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24 bg-[var(--color-primary)] relative overflow-hidden" v-if="featuredTrips.length > 0">
      <!-- Decorative Background Image -->
      <div class="absolute inset-0 z-0 pointer-events-none opacity-20">
        <img 
          src="/images/recommend_overlay.webp" 
          alt="" 
          class="w-full h-full object-cover"
        />
      </div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <SectionTitle
          class="mb-10 md:mb-14"
          dark
          title="ทริปแนะนำ"
          subtitle="เส้นทางที่ทีมเราไปมาเองแล้วคิดว่าคุ้มค่าที่จะไป"
        />

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
          <!-- Main Hero Featured Card (Large) -->
          <router-link
            :to="`/trips/${featuredTrips[0].slug}`"
            class="lg:col-span-8 group relative overflow-hidden rounded-2xl h-[550px] lg:h-[650px] cursor-pointer block isolate"
          >
            <!-- Premium Image Background with Zoom -->
            <img
              :src="featuredTrips[0].cover_image || '/images/placeholder.jpg'"
              :alt="featuredTrips[0].title"
              class="absolute inset-0 w-full h-full object-cover transition-transform duration-[2s] ease-out group-hover:scale-110"
            />
            <!-- Multiple Overlay Layers for Depth & Contrast -->
            <div class="absolute inset-0 bg-gradient-to-t from-black/95 via-black/30 to-transparent opacity-80 group-hover:opacity-90 transition-opacity duration-700"></div>
            <div class="absolute inset-0 ring-1 ring-inset ring-white/10 rounded-2xl"></div>
            
            <div class="absolute inset-0 p-10 md:p-14 flex flex-col justify-end">
              <div class="flex flex-wrap items-center gap-2.5 mb-7 transform transition-transform duration-500 group-hover:-translate-y-2">
                <span class="bg-[var(--color-accent-light)] text-white px-4 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-[0.18em]">{{ typeLabel(featuredTrips[0].type) }}</span>

                <!-- Smart Rating / New Badge -->
                <span v-if="Number(featuredTrips[0].rating) > 0" class="bg-white/10 backdrop-blur-md text-white px-4 py-1.5 rounded-full text-[11px] font-bold flex items-center gap-1.5 border border-white/15">
                  <span class="material-symbols-rounded text-[15px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                  {{ Number(featuredTrips[0].rating).toFixed(1) }}
                </span>
                <span v-else class="bg-white text-[var(--color-primary)] px-4 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-[0.18em]">
                  Trip ใหม่ ✨
                </span>
              </div>
              <div class="max-w-2xl mb-9 transform transition-transform duration-700 group-hover:-translate-y-2">
                <h3 class="font-anuphan text-white text-3xl md:text-5xl font-extrabold mb-4 leading-[1.14] tracking-tight">
                  {{ featuredTrips[0].title }}
                </h3>
                <p class="text-white/70 text-base md:text-lg font-medium leading-relaxed line-clamp-2 max-w-xl group-hover:text-white/90 transition-colors">
                  {{ featuredTrips[0].description || featuredTrips[0].location }}
                </p>
              </div>

              <div class="flex items-center justify-between flex-wrap gap-6 mt-auto border-t border-white/10 pt-8">
                <div class="flex flex-col">
                  <div class="flex items-baseline gap-2">
                    <span class="text-white/60 text-xs md:text-sm font-semibold">
                      {{ Number(featuredTrips[0].min_price) != Number(featuredTrips[0].max_price) ? 'ช่วงราคา' : 'เริ่มต้นเพียง' }}
                    </span>
                    <span v-if="Number(featuredTrips[0].min_price) != Number(featuredTrips[0].max_price)" class="text-white text-2xl md:text-4xl font-extrabold tracking-tight">
                      ฿{{ Number(featuredTrips[0].min_price).toLocaleString('th-TH') }} - {{ Number(featuredTrips[0].max_price).toLocaleString('th-TH') }}
                    </span>
                    <span v-else class="text-white text-3xl md:text-4xl font-extrabold tracking-tight">
                      ฿{{ Number(featuredTrips[0].min_price).toLocaleString('th-TH') }}
                    </span>
                  </div>
                </div>

                <div class="bg-white text-[var(--color-primary)] hover:bg-[var(--color-accent-light)] hover:text-white px-8 py-4 rounded-2xl font-bold text-base transition-all duration-300 flex items-center gap-2.5 group/btn hover:-translate-y-0.5">
                  <span>สำรวจทริปนี้</span>
                  <div class="w-7 h-7 rounded-full bg-[var(--color-primary)]/10 flex items-center justify-center group-hover/btn:bg-white/20 transition-colors">
                    <span class="material-symbols-rounded text-[20px] transform group-hover/btn:translate-x-0.5 transition-transform">arrow_forward</span>
                  </div>
                </div>
              </div>
            </div>
          </router-link>

          <!-- Secondary Side Cards -->
          <div class="lg:col-span-4 flex flex-col gap-8">
            <router-link
              v-for="(trip, idx) in featuredTrips.slice(1, 3)"
              :key="trip.id"
              :to="`/trips/${trip.slug}`"
              class="flex-1 group relative overflow-hidden rounded-2xl bg-gray-900 cursor-pointer block p-8 md:p-10 flex flex-col justify-between transition-all duration-500 transform hover:-translate-y-2 isolate"
            >
              <!-- Background Image -->
               <img
                :src="tripCardImage(trip) || '/images/placeholder.jpg'"
                :alt="trip.title"
                class="absolute inset-0 w-full h-full object-cover transition-transform duration-[2s] group-hover:scale-110 opacity-60"
              />
              <!-- Consistent Premium Overlay -->
              <div class="absolute inset-0 bg-gradient-to-t from-black/95 via-black/20 to-transparent group-hover:from-black transition-all duration-700"></div>
              
              <div class="relative z-10 transform transition-transform duration-500 group-hover:-translate-y-2">
                <div class="flex justify-between items-start mb-7">
                  <div class="flex gap-2">
                    <span class="px-3.5 py-1.5 rounded-full text-[9px] font-bold uppercase tracking-[0.15em] backdrop-blur-md border border-white/20 bg-white/10 text-white">
                      {{ typeLabel(trip.type) }}
                    </span>
                    <span v-if="idx === 0" class="px-3.5 py-1.5 rounded-full text-[9px] font-bold uppercase tracking-[0.15em] bg-[var(--color-accent)] text-white">ยอดนิยม</span>
                  </div>
                  <div class="w-11 h-11 rounded-2xl flex items-center justify-center backdrop-blur-md border border-white/20 bg-white/10 text-white transition-transform duration-500 group-hover:rotate-12">
                    <span class="material-symbols-rounded text-[22px]">{{ typeFeaturedIcon(trip.type) }}</span>
                  </div>
                </div>

                <h4 class="text-xl md:text-2xl font-extrabold text-white mb-3 group-hover:text-[var(--color-accent-light)] transition-colors leading-snug">
                  {{ trip.title }}
                </h4>
                <div v-if="Number(trip.rating) > 0" class="flex items-center gap-1.5 mb-2">
                  <span class="material-symbols-rounded text-[14px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                  <span class="text-white/80 text-xs font-semibold">{{ Number(trip.rating).toFixed(1) }} Rating</span>
                </div>
              </div>

              <div class="relative z-10 pt-7 mt-4 border-t border-white/10 flex justify-between items-end">
                <div class="flex flex-col">
                  <span class="text-[10px] text-white/45 font-bold uppercase tracking-[0.18em] mb-1">
                    {{ Number(trip.min_price) != Number(trip.max_price) ? 'ช่วงราคา' : 'เริ่มต้นที่' }}
                  </span>
                  <span v-if="Number(trip.min_price) != Number(trip.max_price)" class="text-lg font-extrabold text-white tracking-tight">
                    ฿{{ Number(trip.min_price).toLocaleString('th-TH') }} - {{ Number(trip.max_price).toLocaleString('th-TH') }}
                  </span>
                  <span v-else class="text-xl font-extrabold text-white tracking-tight">
                    ฿{{ Number(trip.min_price).toLocaleString('th-TH') }}
                  </span>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center group-hover:bg-white group-hover:text-[var(--color-primary)] transition-all duration-500">
                  <span class="material-symbols-rounded text-[28px]">arrow_outward</span>
                </div>
              </div>
            </router-link>

            <!-- Sophisticated Empty State -->
            <router-link
              v-if="featuredTrips.length === 1"
              to="/trips"
              class="flex-1 group cursor-pointer bg-white/5 backdrop-blur-md rounded-2xl p-10 flex flex-col items-center justify-center hover:bg-white/10 transition-all duration-500 border-2 border-dashed border-white/20 text-center gap-6"
            >
              <div class="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center text-white group-hover:scale-110 group-hover:bg-[var(--color-accent-light)] transition-all duration-500">
                <span class="material-symbols-rounded text-[40px]">explore</span>
              </div>
              <div>
                <p class="text-white text-lg font-extrabold tracking-tight mb-2">ดูทริปแนะนำทั้งหมด</p>
                <p class="text-white/50 text-sm font-medium">ดูรอบเดินทางทั้งหมดที่เปิดอยู่</p>
              </div>
            </router-link>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         TESTIMONIALS SECTION
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24 bg-[var(--color-sand)] relative overflow-hidden">
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
    <section class="py-16 md:py-24 bg-white relative overflow-hidden">

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
            class="bg-white p-8 md:p-10 pt-28 md:pt-32 rounded-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)]/30 cursor-default group relative mt-24 text-center flex flex-col justify-between"
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
         FINAL CTA
    ══════════════════════════════════════════ -->
    <section class="relative py-20 md:py-32 w-full overflow-hidden bg-[#0D2B1E]">
      <!-- Full-Width Background Wrapper -->
      <div class="absolute inset-0 z-0">
        <img
          src="/images/landscape.webp"
          alt="Ready to explore"
          class="w-full h-full object-cover scale-105 motion-safe:animate-[subtle-zoom_60s_infinite_alternate]"
        />
        <div class="absolute inset-0 bg-gradient-to-br from-[#0D2B1E]/95 via-[#1A3A2E]/80 to-transparent"></div>
      </div>
      

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="reveal-section max-w-3xl">
          <h2 class="text-[1.75rem] sm:text-3xl md:text-4xl lg:text-5xl font-extrabold text-white leading-[1.14] mb-6 tracking-tight">
            รอบต่อไปยังมีที่ว่างอยู่
          </h2>

          <p class="text-[0.95rem] md:text-lg text-white/75 leading-relaxed font-medium mb-10 max-w-xl border-l-2 border-[var(--color-gold)]/80 pl-5 md:pl-6">
            ทุกรอบบอกระยะทาง ความสูงสะสม จุดรับ และเวลาออกไว้ล่วงหน้า ดูให้ครบก่อนตัดสินใจได้เลย
          </p>

          <div class="flex flex-col sm:flex-row gap-3.5">
            <router-link
              to="/trips"
              class="inline-flex items-center justify-center gap-2.5 bg-[var(--color-accent)] text-white px-7 md:px-8 py-3.5 rounded-2xl text-[0.95rem] md:text-base font-bold hover:bg-[#3D8F66] transition-all duration-300 group/btn"
            >
              <span>ดูรอบที่เปิดอยู่</span>
              <span class="material-symbols-rounded text-xl group-hover/btn:translate-x-1 transition-transform">explore</span>
            </router-link>
            <router-link
              to="/about"
              class="inline-flex items-center justify-center gap-2.5 bg-white/10 backdrop-blur-md border border-white/20 text-white px-7 md:px-8 py-3.5 rounded-2xl text-[0.95rem] md:text-base font-bold hover:bg-white/20 hover:border-white/30 transition-all duration-300"
            >
              รู้จักเรามากขึ้น
            </router-link>
          </div>

          <!-- Quick Status -->
          <div class="mt-11 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-3.5">
            <div class="inline-flex items-center gap-2 rounded-full bg-white/[0.07] border border-white/10 px-4 py-2 backdrop-blur-sm">
              <span class="material-symbols-rounded text-[var(--color-gold)] text-[18px]">verified_user</span>
              <span class="font-semibold text-white/90 text-[0.8rem] md:text-sm">ใบอนุญาตนำเที่ยว {{ licence }}</span>
            </div>
            <div class="inline-flex items-center gap-2 rounded-full bg-white/[0.07] border border-white/10 px-4 py-2 backdrop-blur-sm">
              <span class="material-symbols-rounded text-[var(--color-gold)] text-[18px]">thumb_up</span>
              <span class="font-semibold text-white/90 text-[0.8rem] md:text-sm">พาร์ทเนอร์ที่ผ่านการตรวจสอบ</span>
            </div>
          </div>
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
import { useWishlistStore } from '../stores/wishlist';
import TripCard from '../components/TripCard.vue';
import CommunityFeedStrip from '../components/CommunityFeedStrip.vue';
import LatestArticles from '../components/LatestArticles.vue';
import HeroSearchBar from '../components/HeroSearchBar.vue';
import TrustStrip from '../components/TrustStrip.vue';
import SectionTitle from '../components/SectionTitle.vue';
import { supportHours, supportLineUrl } from '../lib/contact';
import { licenceNo } from '../lib/licence';

const wishlistStore = useWishlistStore();

const licence = licenceNo();

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

const trips = ref([]);
const featuredTrips = ref([]);
const almostFullTrips = ref([]);
const loading = ref(true);




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

// Homepage showcase cards are driven by real categories (managed in admin).
// The static list below is only a fallback for first paint / API failure.
const FALLBACK_CATEGORIES = [
  {
    type: 'snorkeling',
    label: 'ดำน้ำตื้น',
    subtext: 'ลงน้ำดูปะการังและฝูงปลา เหมาะกับคนว่ายน้ำไม่แข็ง',
    ctaText: 'ดูรอบดำน้ำ',
    image: '/images/diving_show.webp',
    icon: 'scuba_diving',
    color: '#3B9DD4',
    bgColor: '#E8F4FA',
    isPopular: true,
  },
  {
    type: 'trekking',
    label: 'เดินป่า',
    subtext: 'เส้นทางเดินป่าค้างคืน มีระยะทางและความสูงบอกไว้ทุกเส้น',
    ctaText: 'ดูเส้นทางเดินป่า',
    image: '/images/hiking_show.webp',
    icon: 'hiking',
    color: '#047857',
    bgColor: '#ECFDF5',
    isPopular: false,
  },
  {
    type: 'van-service',
    label: 'รถตู้รับส่ง',
    subtext: 'รถตู้พร้อมคนขับ ติดตามตำแหน่งได้ตลอดทาง',
    ctaText: 'ดูรอบรถตู้',
    image: '/images/van_show.webp',
    icon: 'airport_shuttle',
    color: '#C8963E',
    bgColor: '#FFF8EE',
    isPopular: false,
  },
];

const categories = ref(FALLBACK_CATEGORIES);
const categoryRail = ref(null);

// Up to 4 categories render as a full row; beyond that it becomes a swipeable rail.
const isCategoryRail = computed(() => categories.value.length > 4);

// Static class strings (kept whole so Tailwind doesn't purge them).
const categoryGridColsClass = computed(() => {
  const n = categories.value.length;
  if (n <= 1) return 'lg:grid-cols-1 max-w-md mx-auto';
  if (n === 2) return 'lg:grid-cols-2 max-w-3xl mx-auto';
  if (n === 3) return 'lg:grid-cols-3';
  return 'lg:grid-cols-4';
});

function scrollCategoryRail(dir) {
  const el = categoryRail.value;
  if (!el) return;
  // Scroll by roughly one card (incl. gap) so each click advances cleanly.
  const amount = Math.min(el.clientWidth * 0.85, 360);
  el.scrollBy({ left: dir * amount, behavior: 'smooth' });
}

// Map a Category API record to the card shape the template renders.
// Only categories that have a cover image are featured in this showcase.
function mapCategoryToCard(c) {
  return {
    type: c.slug,
    label: c.display_title || c.name,
    subtext: c.subtitle || '',
    ctaText: c.cta_text || 'ดูทริปทั้งหมด',
    image: c.image_url,
    icon: c.icon || 'explore',
    color: c.color || '#047857',
    bgColor: c.bg_color || '#ECFDF5',
    isPopular: !!c.is_popular,
  };
}

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

/* Category color mapping */
const typeMap = {
  trekking:   { label: 'เดินป่า',    class: 'bg-[var(--color-accent)] text-white' },
  diving:     { label: 'ดำน้ำ',      class: 'bg-[#1A5F8A] text-white' },
  snorkeling: { label: 'ดำน้ำตื้น', class: 'bg-[#3B9DD4] text-white' },
  climbing:   { label: 'รถตู้',      class: 'bg-[#C8963E] text-white' },
};

function typeLabel(type) {
  return typeMap[type]?.label || type;
}
function typeBadgeClass(type) {
  return typeMap[type]?.class || 'bg-[#6B8F7A] text-white';
}

const typeFeaturedIcon = (type) => {
  const icons = { trekking: 'hiking', diving: 'scuba_diving', snorkeling: 'pool', climbing: 'airport_shuttle' };
  return icons[type] || 'explore';
};

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
    const [featuredRes, reviewsRes, statsRes, allTripsRes, almostFullRes, categoriesRes] = await Promise.all([
      api.get('/trips/featured'),
      api.get('/reviews', { params: { per_page: 30 } }),
      api.get('/stats'),
      api.get('/trips', { params: { per_page: 100 } }),
      api.get('/trips/almost-full'),
      api.get('/categories'),
    ]);
    allTrips.value = allTripsRes.data.data || [];
    // แถวทริปยอดนิยม 8 ใบแรกคือหน้าแรกของรายการเดียวกัน (เรียงแบบเดียวกัน) — ตัดจาก
    // ชุด 100 ใบที่ได้มาแล้ว ไม่ต้องยิง /trips?per_page=8 ซ้ำ
    trips.value = allTrips.value.slice(0, 8);
    featuredTrips.value = featuredRes.data.data || [];
    restartSlider();
    almostFullTrips.value = almostFullRes.data.data || [];
    reviews.value = reviewsRes.data.data || [];

    // Feature only categories that have a cover image; keep the static fallback otherwise.
    const apiCategories = (categoriesRes.data.data || []).filter((c) => c.image_url);
    if (apiCategories.length) {
      categories.value = apiCategories.map(mapCategoryToCard);
    }
    
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
</style>
