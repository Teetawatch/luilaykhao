<template>
  <div class="trip-detail-page bg-[var(--color-sand)] min-h-screen font-anuphan selection:bg-[var(--color-accent)] selection:text-white pb-20">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center min-h-[70vh]">
      <div class="flex flex-col items-center gap-5">
        <div class="w-16 h-16 border-4 border-gray-200 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
        <span class="text-[var(--color-text-dark)] font-bold text-lg tracking-wide">กำลังเตรียมข้อมูลทริป...</span>
      </div>
    </div><div v-else-if="trip" class="animate-fade-in">
      <!-- Hero Section -->
      <section class="relative flex w-full flex-col overflow-hidden h-[70vh] min-h-[560px] md:h-[74vh] md:min-h-[620px]">
        <!-- Media -->
        <img
          v-if="trip.cover_image && !coverFailed"
          :src="trip.cover_image"
          :alt="trip.title"
          fetchpriority="high"
          class="hero-img absolute inset-0 h-full w-full object-cover"
          @error="coverFailed = true"
        />
        <!-- pb keeps the glyph above the heavy end of the bottom scrim -->
        <div v-else class="hero-fallback absolute inset-0 flex items-center justify-center pb-44">
          <span class="hero-fallback-icon material-symbols-rounded text-white/[0.16]">{{ typeIcon }}</span>
        </div>

        <!-- Scrims: darken only where the text sits, keep the photo itself vivid -->
        <div class="hero-scrim-bottom absolute inset-0"></div>
        <div class="hero-scrim-top absolute inset-x-0 top-0 h-40"></div>

        <!-- Top bar: breadcrumb + actions -->
        <div class="relative z-10 mx-auto flex w-full max-w-screen-2xl items-center gap-4 px-5 pt-6 md:px-12 lg:px-16">
          <nav aria-label="เส้นทางนำทาง" class="flex min-w-0 items-center gap-1 text-[13px] font-semibold text-white/75">
            <router-link to="/" class="shrink-0 transition-colors hover:text-white">หน้าแรก</router-link>
            <span class="material-symbols-rounded shrink-0 text-[16px] opacity-50">chevron_right</span>
            <router-link to="/trips" class="shrink-0 transition-colors hover:text-white">กิจกรรม</router-link>
            <span class="material-symbols-rounded shrink-0 text-[16px] opacity-50">chevron_right</span>
            <span class="truncate text-white" :title="trip.title">{{ trip.title }}</span>
          </nav>

          <div class="ml-auto flex shrink-0 items-center gap-2">
            <button
              type="button"
              class="hero-action"
              :class="{ 'is-favorite': wishlistStore.isFavorite(trip.id) }"
              :aria-pressed="wishlistStore.isFavorite(trip.id)"
              :title="wishlistStore.isFavorite(trip.id) ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด'"
              @click="wishlistStore.toggleFavorite(trip)"
            >
              <span class="material-symbols-rounded text-[21px]">favorite</span>
            </button>
            <button type="button" class="hero-action" :title="shareCopied ? 'คัดลอกลิงก์แล้ว' : 'แชร์ทริปนี้'" @click="shareTrip">
              <span class="material-symbols-rounded text-[21px]">{{ shareCopied ? 'check' : 'ios_share' }}</span>
            </button>
          </div>
        </div>

        <div class="flex-1"></div>

        <!-- Title block -->
        <div class="relative z-10 mx-auto w-full max-w-screen-2xl px-5 pb-8 md:px-12 md:pb-10 lg:px-16">
          <div class="hero-content max-w-4xl">
            <div class="mb-5 flex flex-wrap items-center gap-2.5">
              <span class="rounded-full px-4 py-1.5 text-[12px] font-extrabold uppercase tracking-widest text-white shadow-lg" :class="typeBadgeClass">
                {{ typeLabel }}
              </span>
              <span v-if="hasRating" class="hero-chip">
                <span class="material-symbols-rounded text-[16px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                {{ Number(trip.rating || 0).toFixed(1) }} ({{ trip.review_count }} รีวิว)
              </span>
              <span v-if="trip.booked_passengers_count > 0" class="hero-chip">
                <span class="material-symbols-rounded text-[17px]">group</span>
                {{ trip.booked_passengers_count }} คนจองแล้ว
              </span>
              <span v-if="trip.is_women_only" class="hero-chip hero-chip--pink">
                <span class="material-symbols-rounded text-[17px]">female</span>
                ทริปสำหรับผู้หญิงเท่านั้น
              </span>
            </div>

            <h1 class="hero-title text-3xl font-black leading-[1.15] tracking-tight text-white md:text-5xl lg:text-[3.5rem]" :title="trip.title">
              {{ trip.title }}
            </h1>

            <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 text-[14px] font-semibold text-white/85 md:text-[15px]">
              <span class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[19px]">location_on</span>{{ trip.location }}
              </span>
              <span class="hidden h-4 w-px bg-white/25 sm:block"></span>
              <span class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[19px]">schedule</span>{{ trip.duration_days }} วัน
              </span>
              <span class="hidden h-4 w-px bg-white/25 sm:block"></span>
              <span class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[19px]">terrain</span>{{ diffLabel }}
              </span>
              <template v-if="trip.views_count > 0">
                <span class="hidden h-4 w-px bg-white/25 sm:block"></span>
                <span class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[19px]">visibility</span>{{ Number(trip.views_count).toLocaleString() }} ผู้ชม
                </span>
              </template>
            </div>
          </div>
        </div>

        <!-- Glass booking bar. Hidden on mobile — the sticky bottom bar already covers it. -->
        <div class="relative z-10 hidden border-t border-white/15 bg-black/30 backdrop-blur-xl md:block">
          <div class="mx-auto flex w-full max-w-screen-2xl items-center gap-6 px-12 py-4 lg:px-16">
            <div>
              <p class="text-[11px] font-bold uppercase tracking-wider text-white/60">เริ่มต้น / ท่าน</p>
              <div class="mt-0.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-white lg:text-[28px]">฿{{ displayPrice.toLocaleString() }}</span>
                <span v-if="flashSchedule && flashSchedule.flash_sale.price <= displayPrice" class="text-[15px] font-bold text-white/45 line-through decoration-2">
                  ฿{{ Number(flashSchedule.original_price).toLocaleString() }}
                </span>
              </div>
            </div>

            <template v-if="nextSchedule">
              <span class="h-10 w-px bg-white/20"></span>
              <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-white/60">รอบถัดไป</p>
                <p class="mt-0.5 flex items-center gap-1.5 text-[15px] font-bold text-white">
                  <span class="material-symbols-rounded text-[18px]">event</span>
                  {{ formatDate(nextSchedule.departure_date) }}
                </p>
              </div>
            </template>

            <button type="button" class="hero-cta ml-auto" @click="scrollToBooking">
              <span class="material-symbols-rounded text-[20px]">event_available</span>
              เลือกวันเดินทาง
            </button>
          </div>
        </div>
      </section>

      <!-- In-page section nav (editorial jump bar, scroll-spy) -->
      <div class="page-nav sticky top-16 z-40 border-b border-gray-100 bg-white/85 supports-[backdrop-filter]:backdrop-blur-xl">
        <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 md:px-8">
          <nav class="flex gap-1 overflow-x-auto no-scrollbar" aria-label="ส่วนต่าง ๆ ของหน้า">
            <button
              v-for="s in pageSections"
              :key="s.id"
              type="button"
              class="page-nav__link"
              :class="{ 'is-active': activePageSection === s.id }"
              @click="scrollToPageSection(s.id)"
            >
              {{ s.label }}
            </button>
          </nav>
        </div>
      </div>

      <!-- Content Grid -->
      <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 md:px-8 py-16">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14">

          <!-- Left Column: Details -->
          <div class="lg:col-span-8 space-y-20">

            <!-- Gallery Bento Grid -->
            <section v-if="trip.gallery && trip.gallery.length > 0" class="gallery-section stagger-in">
              <div class="grid grid-cols-1 md:grid-cols-4 grid-rows-1 md:grid-rows-2 gap-4 h-auto md:h-[500px]">
                <!-- Main Large Image -->
                <div 
                  @click="openGallery(0)"
                  class="md:col-span-2 md:row-span-2 h-[300px] md:h-full overflow-hidden rounded-[2rem] md:rounded-[3rem] group relative cursor-pointer shadow-2xl shadow-black/5"
                >
                  <img :src="trip.gallery[0]" :alt="trip.title" class="w-full h-full object-cover transition-transform duration-[1.5s] ease-out group-hover:scale-110" />
                  <div class="absolute inset-0 bg-black/10 group-hover:bg-transparent transition-colors duration-500"></div>
                  <div class="absolute bottom-6 left-6 bg-white/20 backdrop-blur-md px-4 py-2 rounded-full border border-white/20 text-white text-[10px] font-bold opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm">zoom_in</span>
                    คลิกเพื่อขยาย
                  </div>
                </div>

                <!-- Secondary Image (Top Right) -->
                <div v-if="trip.gallery[1]" 
                  @click="openGallery(1)"
                  class="md:col-span-2 md:row-span-1 h-[200px] md:h-full overflow-hidden rounded-[1.5rem] md:rounded-[2.5rem] group relative cursor-pointer shadow-xl shadow-black/5"
                >
                  <img :src="trip.gallery[1]" :alt="trip.title" class="w-full h-full object-cover transition-transform duration-[1.5s] ease-out group-hover:scale-110" />
                  <div class="absolute inset-0 bg-black/10 group-hover:bg-transparent transition-colors duration-500"></div>
                </div>

                <!-- Third Image + Overlay (Bottom Right) -->
                <div v-if="trip.gallery[2]" 
                  @click="openGallery(2)"
                  class="md:col-span-2 md:row-span-1 h-[200px] md:h-full overflow-hidden rounded-[1.5rem] md:rounded-[2.5rem] relative group cursor-pointer shadow-xl shadow-black/5"
                >
                  <img :src="trip.gallery[2]" :alt="trip.title" class="w-full h-full object-cover transition-transform duration-[1.5s] ease-out group-hover:scale-110" />
                  
                  <!-- Overlay for more images -->
                  <div v-if="trip.gallery.length > 3" class="absolute inset-0 bg-black/40 flex items-center justify-center text-white backdrop-blur-sm transition-all duration-500 group-hover:bg-black/60 group-hover:backdrop-blur-[2px]">
                    <div class="text-center transform transition-transform duration-500 group-hover:scale-110">
                      <div class="w-14 h-14 rounded-3xl bg-white/20 flex items-center justify-center mx-auto mb-3 border border-white/30 shadow-lg">
                        <span class="material-symbols-rounded text-3xl">photo_library</span>
                      </div>
                      <div class="font-black text-xl tracking-tight uppercase">+{{ trip.gallery.length - 3 }} รูปภาพ</div>
                      <p class="text-[10px] font-bold text-white/70 mt-1 uppercase tracking-widest">ดูทั้งหมด</p>
                    </div>
                  </div>
                  <div v-else class="absolute inset-0 bg-black/10 group-hover:bg-transparent transition-colors duration-500"></div>
                </div>
              </div>
            </section>

            <!-- Women-Only Benefits Section -->
            <section v-if="trip.is_women_only" class="women-only-benefits bg-gradient-to-br from-pink-50 to-white px-8 md:px-12 py-10 rounded-[2.5rem] border border-pink-100 shadow-[0_20px_50px_rgba(219,39,119,0.08)] relative overflow-hidden">
              <div class="absolute -right-16 -top-16 w-64 h-64 bg-pink-100/50 rounded-full blur-3xl opacity-30"></div>
              <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-100/50 rounded-full blur-3xl opacity-30"></div>
              <div class="relative z-10">
                <div class="flex flex-col md:flex-row md:items-center gap-6 mb-10">
                  <div class="w-20 h-20 rounded-3xl bg-pink-600 flex items-center justify-center text-white shadow-xl shadow-pink-600/30 shrink-0">
                    <span class="material-symbols-rounded text-5xl" style="font-variation-settings:'FILL' 1">female</span>
                  </div>
                  <div>
                    <h3 class="text-3xl md:text-4xl font-extrabold text-pink-700 tracking-tight mb-2">เพื่อความอุ่นใจและปลอดภัยสูงสุดสำหรับผู้หญิง</h3>
                    <p class="text-pink-600/80 font-bold text-lg">ทริปนี้พิเศษสำหรับคุณ (Women-Only Trip)</p>
                  </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                  <div class="bg-white/60 backdrop-blur-sm p-6 rounded-3xl border border-pink-100/50 shadow-sm transition-all hover:bg-white hover:shadow-md hover:-translate-y-1">
                    <div class="w-12 h-12 rounded-2xl bg-pink-50 flex items-center justify-center text-pink-600 mb-4 border border-pink-100">
                      <span class="material-symbols-rounded text-2xl">group</span>
                    </div>
                    <h4 class="font-extrabold text-gray-900 text-lg mb-2 text-pink-700">เพื่อนร่วมทริปหญิงล้วน</h4>
                    <p class="text-sm text-gray-600 leading-relaxed font-bold">เดินทางร่วมกับกลุ่มผู้หญิงที่มีความชอบเหมือนกัน สบายใจได้ตลอดทั้งทริป</p>
                  </div>
                  <div class="bg-white/60 backdrop-blur-sm p-6 rounded-3xl border border-pink-100/50 shadow-sm transition-all hover:bg-white hover:shadow-md hover:-translate-y-1">
                    <div class="w-12 h-12 rounded-2xl bg-pink-50 flex items-center justify-center text-pink-600 mb-4 border border-pink-100">
                      <span class="material-symbols-rounded text-2xl">verified_user</span>
                    </div>
                    <h4 class="font-extrabold text-gray-900 text-lg mb-2 text-pink-700">พื้นที่ส่วนตัวและปลอดภัย</h4>
                    <p class="text-sm text-gray-600 leading-relaxed font-bold">ทุกรายละเอียดจัดการโดยเน้นความเป็นส่วนตัว (Privacy) สูงสุดสำหรับผู้หญิง</p>
                  </div>
                  <div class="bg-white/60 backdrop-blur-sm p-6 rounded-3xl border border-pink-100/50 shadow-sm transition-all hover:bg-white hover:shadow-md hover:-translate-y-1">
                    <div class="w-12 h-12 rounded-2xl bg-pink-50 flex items-center justify-center text-pink-600 mb-4 border border-pink-100">
                      <span class="material-symbols-rounded text-2xl">favorite</span>
                    </div>
                    <h4 class="font-extrabold text-gray-900 text-lg mb-2 text-pink-700">มิตรภาพและรอยยิ้ม</h4>
                    <p class="text-sm text-gray-600 leading-relaxed font-bold">แบ่งปันช่วงเวลาดีๆ ร่วมกับเพื่อนใหม่ในสังคมที่ดูแลกันอย่างอบอุ่นและใกล้ชิด</p>
                  </div>
                </div>
              </div>
            </section>

            <section id="overview" class="description-section scroll-mt-32 bg-white p-8 md:p-12 rounded-[2rem] border border-gray-100 shadow-[0_10px_40px_rgba(0,0,0,0.03)]">
              <header class="ed-head mb-6">
                <span class="ed-kicker">ภาพรวม</span>
                <h2 class="ed-title">เกี่ยวกับทริปนี้</h2>
              </header>
              <p class="text-[var(--color-text-mid)] leading-loose text-lg md:text-xl whitespace-pre-line font-medium">{{ trip.description }}</p>
            </section>

            <!-- Itinerary (Day by Day) -->
            <!-- Itinerary (Day by Day) -->
            <section v-if="itinerarySectors.length > 0" class="itinerary-section scroll-mt-32" id="itinerary">
              <div class="flex items-end justify-between gap-4 mb-8">
                <header class="ed-head">
                  <span class="ed-kicker">กำหนดการ</span>
                  <h3 class="ed-title">แผนการเดินทาง</h3>
                </header>
                <span class="shrink-0 inline-flex items-center gap-1.5 text-[13px] font-black text-[var(--color-accent)] bg-[var(--color-accent)]/10 px-3.5 py-1.5 rounded-full">
                  <span class="material-symbols-rounded text-[16px]">event</span>{{ totalTripDays }} วัน
                </span>
              </div>

              <!-- Sector Navigation (Sticky Tabs) -->
              <div v-if="itinerarySectors.length > 1" class="sector-tabs-container sticky top-20 z-[30] bg-[var(--color-bg)]/90 backdrop-blur-md -mx-4 px-4 py-3 mb-8 md:mx-0 md:px-0 md:rounded-3xl border-b md:border border-gray-100 shadow-sm transition-all">
                <div class="flex gap-2 overflow-x-auto no-scrollbar scroll-smooth">
                  <button 
                    v-for="(sector, sIdx) in itinerarySectors" 
                    :key="sIdx"
                    @click="scrollToSector(sIdx)"
                    class="sector-tab-btn px-5 py-2.5 rounded-full text-[13px] font-black whitespace-nowrap transition-all flex items-center gap-2"
                    :class="activeSector === sIdx ? 'bg-[var(--color-primary)] text-white shadow-md' : 'bg-white text-gray-500 hover:bg-gray-50 border border-gray-100'"
                  >
                    <span class="material-symbols-rounded text-lg" v-if="activeSector === sIdx">location_on</span>
                    {{ sector.sector || `ช่วงที่ ${sIdx + 1}` }}
                  </button>
                </div>
              </div>

              <div class="sectors-container space-y-16">
                <div 
                  v-for="(sector, sIdx) in itinerarySectors" 
                  :key="sIdx" 
                  :id="`sector-${sIdx}`"
                  class="itinerary-sector scroll-mt-40"
                >
                  <div v-if="itinerarySectors.length > 1" class="sector-header flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 rounded-2xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-accent)] border border-[var(--color-accent)]/10">
                      <span class="material-symbols-rounded text-2xl">map</span>
                    </div>
                    <div>
                      <p class="text-[10px] font-black text-[var(--color-accent)] uppercase tracking-widest mb-0.5">ส่วนที่ {{ sIdx + 1 }}</p>
                      <h4 class="text-xl md:text-2xl font-black text-[var(--color-text-dark)]">{{ sector.sector }}</h4>
                    </div>
                  </div>
                  
                  <div class="timeline relative pl-[4.5rem] md:pl-24">
                    <!-- Continuous rail the day nodes sit on -->
                    <div class="absolute left-[1.75rem] md:left-9 top-4 bottom-4 w-px bg-gradient-to-b from-[var(--color-accent)]/40 via-gray-200 to-transparent"></div>

                    <div
                      v-for="(item, idx) in sector.items"
                      :key="idx"
                      class="timeline-item relative pb-8 last:pb-0"
                    >
                      <!-- Day node on the rail -->
                      <button
                        type="button"
                        @click="toggleDay(sIdx + '-' + idx)"
                        class="timeline-node absolute -left-[4.5rem] md:-left-24 top-0 w-14 h-14 md:w-[4.5rem] md:h-[4.5rem] rounded-2xl flex flex-col items-center justify-center transition-all duration-300"
                        :class="openDays.includes(sIdx + '-' + idx)
                          ? 'bg-[var(--color-accent)] text-white shadow-lg shadow-[var(--color-accent)]/25'
                          : 'bg-white text-[var(--color-text-dark)] border border-gray-200 hover:border-[var(--color-accent)]/50'"
                        :aria-expanded="openDays.includes(sIdx + '-' + idx)"
                      >
                        <span class="text-[9px] font-black uppercase tracking-[0.15em] opacity-70">Day</span>
                        <span class="text-xl md:text-2xl font-black leading-none">{{ item.day }}</span>
                      </button>

                      <!-- Day card -->
                      <div
                        class="itinerary-day-card rounded-[1.5rem] border transition-all duration-300"
                        :class="openDays.includes(sIdx + '-' + idx)
                          ? 'bg-white border-[var(--color-accent)]/20 shadow-[0_18px_45px_rgba(0,0,0,0.06)]'
                          : 'bg-white border-gray-100 shadow-[0_8px_24px_rgba(0,0,0,0.02)] hover:shadow-[0_14px_34px_rgba(0,0,0,0.05)]'"
                      >
                        <div
                          @click="toggleDay(sIdx + '-' + idx)"
                          class="p-5 md:p-6 flex items-center justify-between gap-4 cursor-pointer group"
                        >
                          <div class="min-w-0">
                            <p class="text-[11px] font-black uppercase tracking-widest text-[var(--color-accent)] mb-1">วันที่ {{ item.day }}</p>
                            <h4 class="text-lg md:text-xl font-extrabold text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors leading-snug">{{ item.title }}</h4>
                            <p v-if="!openDays.includes(sIdx + '-' + idx)" class="text-sm text-[var(--color-text-muted)] font-medium mt-1 line-clamp-1">
                              {{ item.description }}
                            </p>
                          </div>
                          <div class="w-9 h-9 shrink-0 rounded-full bg-gray-50 flex items-center justify-center text-gray-400 group-hover:bg-[var(--color-sand)] group-hover:text-[var(--color-accent)] transition-all"
                            :class="{'rotate-180 bg-[var(--color-accent)]/10 !text-[var(--color-accent)]': openDays.includes(sIdx + '-' + idx)}">
                            <span class="material-symbols-rounded">expand_more</span>
                          </div>
                        </div>

                        <div v-show="openDays.includes(sIdx + '-' + idx)" class="px-5 pb-6 md:px-6 md:pb-7 animate-fade-in">
                          <div class="w-full h-px bg-gray-100 mb-5"></div>
                          <p class="text-[var(--color-text-mid)] leading-relaxed text-base md:text-lg font-medium whitespace-pre-line">
                            {{ item.description }}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <!-- Editorial photo spread — a magazine-style rhythm break -->
            <figure v-if="spreadImage" class="spread-band relative overflow-hidden rounded-[2.5rem] aspect-[16/9] sm:aspect-[16/7] shadow-[0_24px_60px_rgba(0,0,0,0.12)]">
              <img :src="spreadImage" :alt="trip.title" loading="lazy" class="absolute inset-0 w-full h-full object-cover" @error="spreadImageFailed = true" />
              <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-black/10"></div>
              <figcaption class="absolute inset-x-0 bottom-0 p-6 md:p-10">
                <p class="flex items-center gap-1.5 text-white/80 text-[13px] font-bold mb-1.5">
                  <span class="material-symbols-rounded text-[17px]">location_on</span>{{ trip.location }}
                </p>
                <p class="text-white text-xl md:text-3xl font-black leading-snug tracking-tight max-w-xl drop-shadow-lg">
                  ทุกเส้นทาง คือความทรงจำที่รอคุณอยู่
                </p>
              </figcaption>
            </figure>

            <!-- Preparations Section -->
            <section v-if="trip.preparations && trip.preparations.length > 0" id="prepare" class="preparations-section scroll-mt-32">
              <header class="ed-head mb-8">
                <span class="ed-kicker">ก่อนออกเดินทาง</span>
                <h3 class="ed-title">สิ่งที่ต้องเตรียม</h3>
              </header>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div v-for="(item, idx) in trip.preparations" :key="idx"
                  class="flex items-start gap-3.5 p-4 rounded-2xl bg-white border border-gray-100 shadow-[0_6px_18px_rgba(0,0,0,0.02)] hover:border-[var(--color-accent)]/25 transition-colors">
                  <span class="w-7 h-7 rounded-full bg-[var(--color-accent)]/10 text-[var(--color-accent)] flex items-center justify-center shrink-0 mt-0.5">
                    <span class="material-symbols-rounded text-[16px]">check</span>
                  </span>
                  <p class="text-[var(--color-text-mid)] font-bold text-base leading-relaxed">{{ item }}</p>
                </div>
              </div>
            </section>

            <!-- Highlights -->
            <section id="highlights" class="scroll-mt-32">
              <div class="flex items-end justify-between mb-8 flex-wrap gap-4">
                <header class="ed-head">
                  <span class="ed-kicker">ไฮไลต์</span>
                  <h3 class="ed-title">จุดเด่นของทริป</h3>
                </header>
                <button
                  @click="showAvailabilityModal = true"
                  class="flex items-center gap-2 text-sm font-black text-[var(--color-accent)] bg-white px-5 py-2.5 rounded-full border border-[var(--color-accent)]/20 hover:bg-[var(--color-accent)] hover:text-white transition-all shadow-sm active:scale-95 group"
                >
                  <span class="material-symbols-rounded text-xl transition-transform group-hover:rotate-12">calendar_month</span>
                  เช็ครอบที่ยังว่าง
                </button>
              </div>

              <!-- Image-led editorial feature when a gallery photo is available -->
              <div v-if="hlImage" class="grid lg:grid-cols-2 gap-8 lg:gap-10 items-stretch">
                <figure class="hl-figure relative overflow-hidden rounded-[2rem] min-h-[320px] lg:min-h-full shadow-[0_20px_50px_rgba(0,0,0,0.08)]">
                  <img :src="hlImage" :alt="trip.title" loading="lazy" class="absolute inset-0 w-full h-full object-cover transition-transform duration-[1.5s] ease-out hover:scale-105" @error="hlImageFailed = true" />
                  <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-transparent to-transparent"></div>
                  <figcaption class="absolute bottom-5 left-5 right-5 flex items-center gap-2 text-white">
                    <span class="material-symbols-rounded text-[19px]">location_on</span>
                    <span class="text-[15px] font-bold drop-shadow">{{ trip.location }}</span>
                  </figcaption>
                </figure>

                <ul class="hl-list flex flex-col justify-center divide-y divide-gray-100">
                  <li v-for="(hi, idx) in highlights" :key="idx" class="flex items-start gap-4 py-5 first:pt-0 last:pb-0 group">
                    <span class="shrink-0 flex items-center gap-2.5">
                      <span class="text-[15px] font-black tabular-nums text-[var(--color-accent)]/40 w-6">{{ String(idx + 1).padStart(2, '0') }}</span>
                      <span class="w-11 h-11 rounded-xl bg-[var(--color-accent)]/10 group-hover:bg-[var(--color-accent)] transition-colors duration-300 flex items-center justify-center">
                        <span class="material-symbols-rounded hl-icon text-[var(--color-accent)] group-hover:text-white transition-colors duration-300">{{ hi.icon || 'star' }}</span>
                      </span>
                    </span>
                    <div class="min-w-0 pt-0.5">
                      <h4 class="text-lg font-black text-[var(--color-text-dark)] mb-1 leading-snug">{{ hi.title }}</h4>
                      <p class="text-sm md:text-[15px] text-[var(--color-text-muted)] font-medium leading-relaxed">{{ hi.desc }}</p>
                    </div>
                  </li>
                </ul>
              </div>

              <!-- Fallback: card grid when there is no gallery imagery -->
              <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <article v-for="(hi, idx) in highlights" :key="idx"
                  class="hl-card group relative overflow-hidden bg-white p-6 md:p-7 rounded-[1.75rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.03)] transition-all duration-300 hover:-translate-y-1 hover:border-[var(--color-accent)]/30 hover:shadow-[0_22px_44px_rgba(0,0,0,0.07)]">
                  <span class="absolute right-5 top-4 text-[54px] font-black leading-none text-[var(--color-accent)]/[0.07] tabular-nums select-none">{{ String(idx + 1).padStart(2, '0') }}</span>
                  <div class="relative">
                    <div class="w-14 h-14 rounded-2xl bg-[var(--color-accent)]/10 group-hover:bg-[var(--color-accent)] transition-colors duration-300 flex items-center justify-center mb-5">
                      <span class="material-symbols-rounded hl-icon text-[var(--color-accent)] group-hover:text-white transition-colors duration-300">{{ hi.icon || 'star' }}</span>
                    </div>
                    <h4 class="text-lg md:text-xl font-black text-[var(--color-text-dark)] mb-2 group-hover:text-[var(--color-accent)] transition-colors leading-snug">{{ hi.title }}</h4>
                    <p class="text-sm md:text-[15px] text-[var(--color-text-muted)] font-medium leading-relaxed">{{ hi.desc }}</p>
                  </div>
                </article>
              </div>
            </section>

            <!-- Inclusions / Exclusions -->
            <section id="included" class="scroll-mt-32">
              <header class="ed-head mb-8">
                <span class="ed-kicker">รายละเอียดราคา</span>
                <h3 class="ed-title">สิ่งที่รวมและไม่รวม</h3>
              </header>

              <div class="grid grid-cols-1 md:grid-cols-2 rounded-[2rem] border border-gray-100 bg-white shadow-[0_12px_40px_rgba(0,0,0,0.04)] overflow-hidden">
                <div class="p-7 md:p-9 md:border-r border-gray-100">
                  <h4 class="text-lg font-black mb-6 flex items-center gap-2.5 text-[#2D7A4F]">
                    <span class="material-symbols-rounded text-[22px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                    รวมในราคาแล้ว
                  </h4>
                  <ul class="space-y-3.5 text-[15px] md:text-base font-medium text-[var(--color-text-dark)]">
                    <li v-for="(item, i) in trip.inclusions" :key="i" class="flex items-start gap-3">
                      <span class="w-5 h-5 mt-0.5 shrink-0 rounded-full bg-[#E8F5EC] text-[#2D7A4F] flex items-center justify-center"><span class="material-symbols-rounded text-[14px]">check</span></span>
                      <span>{{ item }}</span>
                    </li>
                  </ul>
                  <p v-if="!trip.inclusions?.length" class="text-sm text-gray-400 italic">ไม่ได้ระบุสิ่งที่รวมในทริป</p>
                </div>
                <div class="p-7 md:p-9 border-t md:border-t-0 border-gray-100 bg-gray-50/40">
                  <h4 class="text-lg font-black mb-6 flex items-center gap-2.5 text-gray-400">
                    <span class="material-symbols-rounded text-[22px]" style="font-variation-settings:'FILL' 1">cancel</span>
                    ไม่รวมในราคา
                  </h4>
                  <ul class="space-y-3.5 text-[15px] md:text-base font-medium text-[var(--color-text-mid)]">
                    <li v-for="(item, i) in trip.exclusions" :key="i" class="flex items-start gap-3">
                      <span class="w-5 h-5 mt-0.5 shrink-0 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center"><span class="material-symbols-rounded text-[14px]">close</span></span>
                      <span>{{ item }}</span>
                    </li>
                  </ul>
                  <p v-if="!trip.exclusions?.length" class="text-sm text-gray-400 italic">ไม่ได้ระบุสิ่งที่ไม่รวมในทริป</p>
                </div>
              </div>
            </section>

            <!-- Cancellation / refund policy -->
            <section v-if="trip.cancellation_policy" class="cancellation-section bg-white p-8 md:p-12 rounded-[2rem] border border-gray-100 shadow-[0_10px_40px_rgba(0,0,0,0.03)]">
              <header class="ed-head mb-2">
                <span class="ed-kicker">ความยืดหยุ่น</span>
                <h3 class="ed-title">นโยบายการยกเลิกและคืนเงิน</h3>
              </header>
              <p class="text-[var(--color-text-muted)] font-medium mb-8">
                เปลี่ยนแผนได้อย่างสบายใจ — เงื่อนไขการคืนเงินคำนวณจากจำนวนวันก่อนออกเดินทาง
              </p>

              <div class="space-y-3">
                <div
                  v-for="(tier, i) in trip.cancellation_policy.tiers"
                  :key="i"
                  class="flex items-center gap-4 p-4 md:p-5 rounded-2xl border"
                  :class="tier.percent >= 100 ? 'bg-[#E8F5EC] border-[#2D7A4F]/20'
                    : tier.percent > 0 ? 'bg-[#FFF8EE] border-[#C8963E]/25'
                    : 'bg-gray-50 border-gray-200'"
                >
                  <div
                    class="shrink-0 w-16 h-16 rounded-2xl flex flex-col items-center justify-center font-black leading-none"
                    :class="tier.percent >= 100 ? 'bg-[#2D7A4F] text-white'
                      : tier.percent > 0 ? 'bg-[#C8963E] text-white'
                      : 'bg-gray-300 text-white'"
                  >
                    <span class="text-lg">{{ tier.percent }}%</span>
                    <span class="text-[9px] font-bold tracking-wide mt-0.5">คืนเงิน</span>
                  </div>
                  <div class="min-w-0">
                    <p class="font-extrabold text-[var(--color-text-dark)] text-base md:text-lg leading-tight">{{ tier.range }}</p>
                    <p class="text-sm font-medium text-[var(--color-text-muted)] mt-0.5">{{ tier.detail }}</p>
                  </div>
                </div>
              </div>

              <p v-if="trip.cancellation_policy.note" class="flex items-start gap-2 text-sm font-medium text-[var(--color-text-muted)] mt-6 pt-6 border-t border-gray-100">
                <span class="material-symbols-rounded text-[18px] text-[var(--color-accent)] shrink-0 mt-0.5">info</span>
                {{ trip.cancellation_policy.note }}
              </p>
            </section>

            <!-- FAQ -->
            <section v-if="trip.faqs && trip.faqs.length" class="faq-section bg-white p-8 md:p-12 rounded-[2rem] border border-gray-100 shadow-[0_10px_40px_rgba(0,0,0,0.03)]">
              <header class="ed-head mb-6">
                <span class="ed-kicker">คำถามที่พบบ่อย</span>
                <h3 class="ed-title">มีข้อสงสัย?</h3>
              </header>
              <div class="divide-y divide-gray-100">
                <div v-for="(faq, i) in trip.faqs" :key="i">
                  <button
                    type="button"
                    @click="openFaq = openFaq === i ? null : i"
                    class="w-full flex items-center justify-between gap-4 py-5 text-left group"
                    :aria-expanded="openFaq === i"
                  >
                    <span class="font-extrabold text-[var(--color-text-dark)] text-base md:text-lg group-hover:text-[var(--color-accent)] transition-colors">{{ faq.question }}</span>
                    <span
                      class="material-symbols-rounded text-[var(--color-text-muted)] shrink-0 transition-transform duration-300"
                      :class="openFaq === i ? 'rotate-180 text-[var(--color-accent)]' : ''"
                    >expand_more</span>
                  </button>
                  <div v-show="openFaq === i" class="pb-5 -mt-1">
                    <p class="text-[var(--color-text-muted)] font-medium leading-relaxed whitespace-pre-line">{{ faq.answer }}</p>
                  </div>
                </div>
              </div>
            </section>
            </div>
            
            <!-- Right Column: Sticky Booking Panel -->
          <aside class="lg:col-span-4">
            <div class="sticky top-28 space-y-6">

              <!-- Price Card -->
              <div id="booking-section" class="bg-white p-8 rounded-[2rem] shadow-[0_20px_50px_rgba(0,0,0,0.08)] border border-gray-100 relative overflow-hidden z-10">
                <!-- Flash Sale banner -->
                <div v-if="flashSchedule" class="mb-4 -mx-3 -mt-3 px-4 py-3 rounded-[1.25rem] bg-gradient-to-r from-[#EA580C] to-[#F97316] text-white shadow-md">
                  <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-1.5 font-black text-sm">
                      <span class="material-symbols-rounded" style="font-size:18px;">bolt</span>
                      Flash Sale
                      <span v-if="flashSchedule.flash_sale.discount_percent > 0" class="ml-1 bg-white/25 rounded-md px-1.5 py-0.5 text-[11px]">
                        -{{ flashSchedule.flash_sale.discount_percent }}%
                      </span>
                    </div>
                    <div v-if="flashSchedule.flash_sale.ends_at" class="font-mono font-black text-sm tabular-nums tracking-tight">
                      {{ flashCountdown(flashSchedule.flash_sale.ends_at) }}
                    </div>
                  </div>
                </div>
                <!-- Trust row -->
                <div v-if="hasRating || trip.booked_passengers_count > 0" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 mb-4">
                  <span v-if="hasRating" class="inline-flex items-center gap-1.5 text-[13px] font-black text-[var(--color-text-dark)]">
                    <span class="material-symbols-rounded text-[18px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                    {{ Number(trip.rating).toFixed(1) }}
                    <span class="text-[var(--color-text-muted)] font-bold">({{ trip.review_count }} รีวิว)</span>
                  </span>
                  <span v-if="hasRating && trip.booked_passengers_count > 0" class="w-1 h-1 rounded-full bg-gray-300"></span>
                  <span v-if="trip.booked_passengers_count > 0" class="inline-flex items-center gap-1.5 text-[13px] font-bold text-[var(--color-text-muted)]">
                    <span class="material-symbols-rounded text-[17px]">group</span>
                    จองแล้ว {{ trip.booked_passengers_count }} คน
                  </span>
                </div>

                <!-- Starting price -->
                <p class="text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest mb-1">เริ่มต้นเพียง</p>
                <div class="flex items-end gap-2 mb-2">
                  <span class="text-4xl md:text-5xl font-black text-[var(--color-primary)] tracking-tight">฿{{ displayPrice.toLocaleString() }}</span>
                  <span v-if="flashSchedule && flashSchedule.flash_sale.price <= displayPrice" class="text-gray-400 text-xl pb-1.5 font-bold line-through decoration-2">
                    ฿{{ Number(flashSchedule.original_price).toLocaleString() }}
                  </span>
                  <span class="text-[var(--color-text-muted)] text-base pb-1.5 font-bold">/ ท่าน</span>
                </div>

                <!-- ผ่อนชำระ: ยอดต่องวดที่ต่ำที่สุดเท่าที่รอบนี้ผ่อนได้จริง -->
                <div v-if="installmentPlan" class="mb-2 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-[#E8F5EC] border border-[#2D7A4F]/20">
                  <span class="material-symbols-rounded text-[#2D7A4F]" style="font-size:18px;">credit_card</span>
                  <span class="text-[13px] font-black text-[#2D7A4F]">
                    ผ่อน 0% ได้ {{ installmentPlan.count }} งวด · งวดละ ฿{{ installmentPlan.perInstallment.toLocaleString() }}
                  </span>
                </div>

                <p v-if="isTrekking && regionOptions.length" class="text-sm font-medium text-gray-400 mb-8">
                  * ราคาขึ้นอยู่กับภูมิภาคที่ขึ้นรถ
                </p>
                <div v-else class="mb-8"></div>

                <div class="mb-8 p-4 rounded-[1.25rem] bg-[var(--color-sand)] border border-gray-100">
                  <p class="text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest mb-3">ขั้นตอนการจอง</p>
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs font-bold">
                    <div class="px-3 py-2 rounded-lg border"
                      :class="!isTrekking || selectedRegion
                        ? 'bg-[#E8F5EC] border-[#2D7A4F]/20 text-[#2D7A4F]'
                        : 'bg-white border-gray-200 text-[var(--color-text-muted)]'">
                      1) {{ isTrekking ? 'เลือกภูมิภาค' : 'เลือกวันเดินทาง' }}
                    </div>
                    <div class="px-3 py-2 rounded-lg border"
                      :class="selectedSchedule
                        ? 'bg-[#E8F5EC] border-[#2D7A4F]/20 text-[#2D7A4F]'
                        : 'bg-white border-gray-200 text-[var(--color-text-muted)]'">
                      2) เลือกรอบเดินทาง
                    </div>
                    <div class="px-3 py-2 rounded-lg border"
                      :class="selectedSchedule && (!isTrekking || selectedPickup || (isJoinTrip && selectedSchedule?.join_trip_enabled))
                        ? 'bg-[#E8F5EC] border-[#2D7A4F]/20 text-[#2D7A4F]'
                        : 'bg-white border-gray-200 text-[var(--color-text-muted)]'">
                      3) ดำเนินการจอง
                    </div>
                  </div>
                </div>

                <hr class="border-gray-100 mb-8" />

                <!-- ── Step 1: Region Selection (Trekking only) ── -->
                <div v-if="isTrekking" class="mb-8">
                  <label class="flex items-center gap-2 text-sm font-extrabold text-[var(--color-text-dark)] uppercase tracking-wider mb-4">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">location_on</span>
                    เลือกภูมิภาคที่จะขึ้นรถ
                  </label>

                  <div v-if="schedulesLoading" class="py-8 text-center bg-[var(--color-sand)] rounded-[1.5rem]">
                    <div class="w-8 h-8 border-4 border-gray-200 border-t-[var(--color-accent)] rounded-full animate-spin mx-auto mb-3"></div>
                    <p class="text-[var(--color-text-dark)] font-bold text-sm">กำลังค้นหารอบเดินทาง...</p>
                  </div>

                  <div v-else-if="schedules.length === 0" class="bg-[var(--color-sand)] rounded-[1.5rem] p-6 text-center border border-gray-100">
                    <span class="material-symbols-rounded text-gray-400 text-4xl mb-3 block">event_busy</span>
                    <p class="text-[var(--color-text-dark)] font-bold text-base">ยังไม่มีรอบเดินทางที่เปิดจอง</p>
                    <p class="text-gray-500 font-medium text-sm mt-1">กรุณาตรวจสอบอีกครั้งในภายหลัง</p>
                  </div>

                  <div v-else-if="regionOptions.length === 0" class="bg-[var(--color-sand)] rounded-[1.5rem] p-4 text-center border border-gray-100 text-sm font-bold text-[var(--color-text-dark)]">
                    <span class="material-symbols-rounded mr-1 align-middle text-[var(--color-accent)]">info</span> จุดรับผู้โดยสารจะแจ้งให้ทราบอีกครั้ง
                  </div>

                  <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <button
                      v-for="r in regionOptions"
                      :key="r.region"
                      @click="selectRegion(r.region)"
                      class="text-left border-2 rounded-[1.25rem] p-4 transition-all duration-200"
                      :class="selectedRegion === r.region
                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5 shadow-md'
                        : 'border-gray-100 bg-white hover:border-[var(--color-accent)]/50 hover:bg-[var(--color-sand)] hover:shadow-sm'"
                    >
                      <div class="flex items-center justify-between gap-1 mb-1.5">
                        <span class="font-black text-sm text-[var(--color-text-dark)] leading-tight">{{ r.region_label }}</span>
                        <span v-if="selectedRegion === r.region" class="material-symbols-rounded text-[var(--color-accent)] text-[18px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                      </div>
                      <p class="text-[11px] font-black text-[var(--color-accent)]">เริ่ม ฿{{ r.min_price.toLocaleString() }}</p>
                      <p class="text-[10px] text-gray-400 font-bold mt-0.5">{{ r.schedule_count }} รอบที่ว่าง</p>
                    </button>
                  </div>

                  <div v-if="selectedRegion" class="mt-4 p-3 rounded-xl bg-[#E8F5EC] border border-[#2D7A4F]/20 flex items-center justify-between gap-3">
                    <p class="text-xs font-bold text-[#2D7A4F] flex items-center gap-1.5">
                      <span class="material-symbols-rounded text-[16px]">check_circle</span>
                      เลือกแล้ว: {{ regionOptions.find(r => r.region === selectedRegion)?.region_label || selectedRegion }}
                    </p>
                    <button
                      @click="selectRegion(null)"
                      class="text-[11px] font-black text-[#2D7A4F] hover:text-[var(--color-accent)] transition-colors"
                    >
                      เปลี่ยนภูมิภาค
                    </button>
                  </div>
                </div>

                <!-- Join Trip Option -->
                <div v-if="selectedSchedule?.join_trip_enabled" class="mb-8 p-6 rounded-[1.5rem] border-2 transition-all duration-300"
                  :class="isJoinTrip ? 'border-emerald-500 bg-emerald-50' : 'border-dashed border-gray-200 bg-white'">
                  <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 shadow-sm"
                      :class="isJoinTrip ? 'bg-emerald-500 text-white' : 'bg-gray-100 text-gray-400'">
                      <span class="material-symbols-rounded text-2xl">confirmation_number</span>
                    </div>
                    <div class="flex-grow">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <h4 class="font-black text-[var(--color-text-dark)] text-lg">Enjoy Trip (Join Trip)</h4>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                          <input type="checkbox" v-model="isJoinTrip" class="sr-only peer">
                          <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                        </label>
                      </div>
                      <p class="text-sm font-bold text-[var(--color-text-muted)] mt-1 leading-relaxed">
                        เลือกเดินทางเอง ไม่ต้องเลือกที่นั่งบนผัง จ่ายเงินแล้วรอรับ QR Code เพื่อเช็คอินได้ทันที
                      </p>
                      <div class="mt-3 flex items-center gap-2">
                        <span class="text-[11px] font-black uppercase text-gray-400">ราคาพิเศษ:</span>
                        <span class="text-xl font-black text-emerald-600">฿{{ Number(selectedSchedule.join_trip_price || selectedSchedule.price || trip.price_per_person).toLocaleString() }}</span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- ── Step 1b: Schedule Selection (non-trekking) ── -->
                <div v-if="!isTrekking" class="mb-8">
                  <label class="flex items-center gap-2 text-sm font-extrabold text-[var(--color-text-dark)] uppercase tracking-wider mb-4">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">calendar_month</span>
                    เลือกวันเดินทาง
                  </label>

                  <div v-if="schedulesLoading" class="py-8 text-center bg-[var(--color-sand)] rounded-[1.5rem]">
                    <div class="w-8 h-8 border-4 border-gray-200 border-t-[var(--color-accent)] rounded-full animate-spin mx-auto mb-3"></div>
                    <p class="text-[var(--color-text-dark)] font-bold text-sm">กำลังค้นหารอบเดินทาง...</p>
                  </div>

                  <div v-else-if="schedules.length === 0" class="bg-[var(--color-sand)] rounded-[1.5rem] p-6 text-center border border-gray-100">
                    <span class="material-symbols-rounded text-gray-400 text-4xl mb-3 block">event_busy</span>
                    <p class="text-[var(--color-text-dark)] font-bold text-base">ยังไม่มีรอบเดินทางที่เปิดจอง</p>
                    <p class="text-gray-500 font-medium text-sm mt-1">กรุณาตรวจสอบอีกครั้งในภายหลัง</p>
                  </div>

                  <ScheduleCalendar
                    v-else
                    :schedules="schedules"
                    :selected-schedule="selectedSchedule"
                    @select="selectSchedule"
                    @preview-seats="openSeatMapPreview"
                  />
                </div>

                <!-- ── Step 2 (Trekking): Date + Pickup for selected region ── -->
                <div v-if="isTrekking && selectedRegion" class="mb-8 animate-fade-in">
                  <label class="flex items-center gap-2 text-sm font-extrabold text-[var(--color-text-dark)] uppercase tracking-wider mb-4">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">calendar_month</span>
                    เลือกวันเดินทาง
                  </label>

                  <div v-if="schedulesForRegion.length === 0" class="bg-[var(--color-sand)] rounded-[1.5rem] p-5 text-center border border-gray-100">
                    <span class="material-symbols-rounded text-gray-400 text-3xl mb-2 block">event_busy</span>
                    <p class="text-[var(--color-text-dark)] font-bold text-sm">ยังไม่มีรอบสำหรับภูมิภาคนี้</p>
                  </div>

                  <ScheduleCalendar
                    v-else
                    :schedules="schedulesForRegion"
                    :selected-schedule="selectedSchedule"
                    :is-trekking="true"
                    :selected-region="selectedRegion"
                    @select="selectSchedule"
                    @preview-seats="openSeatMapPreview"
                  />
                </div>


                <!-- ── Price Summary ── -->
                <div v-if="selectedSchedule" class="p-5 bg-[var(--color-sand)] rounded-[1.25rem] mb-8 space-y-3 stagger-in border border-gray-100">
                  <div v-if="selectedPickup" class="flex justify-between items-center text-sm font-bold text-[var(--color-text-dark)]">
                    <span class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-[var(--color-accent)] text-[18px]">location_on</span>
                      {{ selectedPickup.region_label }}
                    </span>
                    <span class="text-base">฿{{ Number(selectedPickup.price).toLocaleString() }}</span>
                  </div>
                  <div v-else class="flex justify-between items-center text-sm font-bold text-[var(--color-text-dark)]">
                    <span class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-gray-400 text-[18px]">sell</span>
                      ราคาต่อท่าน
                    </span>
                    <span class="text-base">฿{{ Number(selectedSchedule.price ?? trip.price_per_person).toLocaleString() }}</span>
                  </div>
                  <hr class="border-gray-200" />
                  <div class="flex justify-between items-center text-base font-black">
                    <span class="text-[var(--color-text-dark)]">ราคาสุทธิ / ท่าน</span>
                    <span class="text-[var(--color-primary)] text-2xl">฿{{ displayPrice.toLocaleString() }}</span>
                  </div>
                </div>

                <!-- พยากรณ์อากาศวันเดินทาง (แสดงเมื่อ backend แนบมากับรอบที่เลือก) -->
                <WeatherBadge v-if="selectedSchedule?.weather" :weather="selectedSchedule.weather" class="mb-8" />

                <!-- ── Book Now ── -->
                <div v-if="selectedSchedule">
                  <router-link
                    v-if="((!isTrekking) || (isTrekking && selectedPickup) || (isJoinTrip && selectedSchedule?.join_trip_enabled)) && canBookSelectedSchedule()"
                    :to="{ 
                      path: `/booking/${selectedSchedule.id}`, 
                      query: { 
                        ...(selectedRegion && !isJoinTrip ? { region: selectedRegion } : {}),
                        ...(isJoinTrip ? { join_trip: 1 } : {})
                      } 
                    }"
                    class="book-cta"
                  >
                    ดำเนินการจองทริป{{ isJoinTrip ? ' (Enjoy Trip)' : '' }}
                  </router-link>
                  <button v-else disabled
                    class="w-full py-4 rounded-full font-extrabold text-lg bg-gray-100 text-gray-400 cursor-not-allowed text-center border border-gray-200">
                    {{ selectedSchedule?.is_charter ? 'รอบเดินทางนี้เป็นรอบเหมา' : (!isScheduleBookable(selectedSchedule)) ? 'รอบเดินทางนี้เต็มแล้ว' : selectedSchedule?.join_trip_enabled && !hasAvailableSeats(selectedSchedule) && !isJoinTrip ? 'กรุณาเปิด Enjoy Trip' : !selectedRegion && isTrekking ? 'กรุณาเลือกภูมิภาค' : !selectedPickup && isTrekking ? 'กรุณาเลือกจุดขึ้นรถ' : 'กรุณาเลือกวันเดินทาง' }}
                  </button>
                  <p class="text-xs font-medium text-[var(--color-text-muted)] mt-4 text-center flex items-center justify-center gap-1.5">
                    <span class="material-symbols-rounded text-[16px]">verified_user</span>
                    สามารถปรับเปลี่ยนวันหรือเปลี่ยนผู้เข้าใช้สิทธิ์แทนได้ โดยไม่มีค่าใช้จ่าย หากแจ้งล่วงหน้าภายใน 45 วัน
                  </p>

                  <!-- คิวรอที่นั่ง — เฉพาะรอบที่เต็มจริง (ไม่ใช่รอบเหมา/Enjoy Trip) -->
                  <WaitlistJoinCard
                    v-if="!selectedSchedule.is_charter && !hasAvailableSeats(selectedSchedule) && !selectedSchedule.join_trip_enabled"
                    :schedule-id="selectedSchedule.id"
                    class="mt-4" />
                </div>
                <div v-else class="text-center py-4 bg-gray-50 rounded-[1.25rem] border border-dashed border-gray-300">
                  <p class="text-sm font-bold text-gray-500">{{ isTrekking ? (selectedRegion ? 'โปรดเลือกวันเดินทาง' : 'โปรดเลือกภูมิภาคก่อน') : 'โปรดเลือกวันเดินทางเพื่อจอง' }}</p>
                </div>

                <!-- Trust badges -->
                <div class="mt-6 pt-6 border-t border-gray-100 grid grid-cols-3 gap-2 text-center">
                  <div class="flex flex-col items-center gap-1.5">
                    <span class="material-symbols-rounded text-[22px] text-[var(--color-accent)]">bolt</span>
                    <span class="text-[11px] font-black text-[var(--color-text-mid)] leading-tight">ยืนยันทันที</span>
                  </div>
                  <div class="flex flex-col items-center gap-1.5">
                    <span class="material-symbols-rounded text-[22px] text-[var(--color-accent)]">encrypted</span>
                    <span class="text-[11px] font-black text-[var(--color-text-mid)] leading-tight">ชำระปลอดภัย</span>
                  </div>
                  <div class="flex flex-col items-center gap-1.5">
                    <span class="material-symbols-rounded text-[22px] text-[var(--color-accent)]">health_and_safety</span>
                    <span class="text-[11px] font-black text-[var(--color-text-mid)] leading-tight">มีประกันเดินทาง</span>
                  </div>
                </div>
              </div>

              <!-- Urgency Card — แสดงเฉพาะรอบที่ใกล้จะถึงที่สุด -->
              <div
                v-if="urgentSchedule"
                class="relative overflow-hidden bg-[#FFF8EE] p-5 rounded-[1.5rem] border border-[#C8963E]/30 shadow-sm animate-fade-in-up"
              >
                <div class="flex items-center gap-4">
                  <div class="w-12 h-12 rounded-full bg-[#C8963E] flex items-center justify-center text-white shrink-0 shadow-md">
                    <span class="material-symbols-rounded text-[24px]">local_fire_department</span>
                  </div>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-0.5">
                      <p class="font-extrabold text-[var(--color-text-dark)] text-base leading-none">จองด่วน!</p>
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#C8963E]/15 text-[11px] font-bold text-[#A87830]">
                        <span class="material-symbols-rounded text-[12px]">calendar_today</span>
                        {{ formatDate(urgentSchedule.departure_date) }}
                      </span>
                    </div>
                    <p class="text-sm font-bold text-[#A87830]">
                      เหลือเพียง {{ urgentSchedule.available_seats }} ที่นั่งสุดท้าย
                    </p>
                  </div>
                </div>
              </div>

              <!-- Quick Info Card -->
              <div class="bg-white rounded-[1.5rem] p-6 md:p-8 border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.03)] space-y-5">
                <h4 class="font-extrabold text-[var(--color-text-dark)] text-lg mb-2">ข้อมูลเบื้องต้น</h4>
                <div class="flex items-center gap-4 text-base">
                  <div class="w-10 h-10 rounded-xl bg-[var(--color-sand)] flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">schedule</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-[var(--color-text-muted)] uppercase tracking-wider mb-0.5">ระยะเวลา</p>
                    <p class="font-extrabold text-[var(--color-text-dark)]">{{ trip.duration_days }} วัน</p>
                  </div>
                </div>
                <div class="flex items-center gap-4 text-base">
                  <div class="w-10 h-10 rounded-xl bg-[var(--color-sand)] flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">terrain</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-[var(--color-text-muted)] uppercase tracking-wider mb-0.5">ระดับความยาก</p>
                    <p class="font-extrabold text-[var(--color-text-dark)]">{{ diffLabel }}</p>
                  </div>
                </div>
                <div class="flex items-center gap-4 text-base">
                  <div class="w-10 h-10 rounded-xl bg-[var(--color-sand)] flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">group</span>
                  </div>
                  <div>
                    <p class="text-xs font-bold text-[var(--color-text-muted)] uppercase tracking-wider mb-0.5">รับสูงสุด</p>
                    <p class="font-extrabold text-[var(--color-text-dark)]">{{ trip.max_participants }} ท่าน / รอบ</p>
                  </div>
                </div>
              </div>

            </div>
          </aside>

        </div>

        <!-- ฟีดรูปหลังทริป — UGC จากลูกค้าที่เดินทางจริง (ซ่อนตัวเองเมื่อไม่มีโพสต์) -->
        <TripPostsFeed v-if="trip.slug" :slug="trip.slug" />

        <!-- Reviews Section (Moved to bottom for Mobile flow) -->
        <section id="reviews" class="scroll-mt-32 mt-16 pt-16 border-t border-gray-200">
          <div class="flex items-center justify-between mb-10">
            <div>
              <header class="ed-head mb-3">
                <span class="ed-kicker">เสียงจากผู้ร่วมทริป</span>
                <h3 class="ed-title">รีวิวจริงจากนักเดินทาง</h3>
              </header>
              <div class="flex items-center gap-3">
                <div class="flex text-[#FFB020]">
                  <span v-for="star in 5" :key="star" class="material-symbols-rounded text-[24px]"
                    :style="star <= Math.round(trip.rating) ? 'font-variation-settings:\'FILL\' 1' : ''">
                    star
                  </span>
                </div>
                <span class="font-extrabold text-[var(--color-text-dark)] text-xl">{{ Number(trip.rating || 0).toFixed(1) }}</span>
                <span class="text-[var(--color-text-muted)] font-medium text-base">จาก {{ trip.review_count || 0 }} ความคิดเห็น</span>
              </div>
            </div>
          </div>

          <!-- Photo Album — every image from every review, in one gallery -->
          <div
            v-if="albumPhotos.length > 0"
            class="mb-12 bg-white p-6 md:p-8 rounded-[2rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.02)]"
          >
            <div class="flex items-center gap-3.5 mb-6">
              <span class="sec-icon"><span class="material-symbols-rounded">photo_library</span></span>
              <div>
                <p class="font-extrabold text-[var(--color-text-dark)] text-lg leading-tight mb-0.5">อัลบั้มภาพจากผู้ร่วมทริป</p>
                <p class="text-sm font-medium text-[var(--color-text-muted)]">{{ albumTotal }} ภาพจากรีวิวจริง</p>
              </div>
            </div>

            <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2.5 md:gap-3">
              <div
                v-for="(photo, idx) in displayedAlbumPhotos"
                :key="`${photo.review_id}-${idx}`"
                @click="openAlbumImage(idx)"
                class="relative aspect-square rounded-2xl overflow-hidden border border-gray-100 cursor-pointer group"
              >
                <img
                  :src="photo.url"
                  loading="lazy"
                  class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
                />
                <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-end p-2">
                  <p class="text-white text-[11px] font-bold truncate drop-shadow">{{ photo.user_name }}</p>
                </div>
              </div>
            </div>

            <div v-if="albumCanShowMore" class="mt-6 text-center">
              <button
                @click="loadMoreAlbumPhotos"
                :disabled="albumLoadingMore"
                class="inline-flex items-center gap-2 px-6 py-2.5 rounded-full border-2 border-[var(--color-accent)]/25 text-[var(--color-accent)] font-extrabold text-sm hover:bg-[var(--color-accent)] hover:text-white hover:border-[var(--color-accent)] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95"
              >
                <span v-if="albumLoadingMore" class="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin"></span>
                <span v-else class="material-symbols-rounded text-[18px]">expand_more</span>
                <span v-if="albumLoadingMore">กำลังโหลด...</span>
                <span v-else>ดูภาพเพิ่มเติม ({{ albumTotal - displayedAlbumPhotos.length }} ภาพ)</span>
              </button>
            </div>
          </div>

          <div v-if="reviewsLoading" class="flex justify-center py-20">
            <div class="w-12 h-12 border-4 border-gray-200 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
          </div>

          <div v-else-if="reviews.length > 0">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
              <div v-for="review in displayedReviews" :key="review.id" class="bg-white p-6 md:p-8 rounded-[2rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.02)] transition-all hover:shadow-[0_15px_40px_rgba(0,0,0,0.04)] h-full flex flex-col">
                <div class="flex justify-between items-start mb-6">
                  <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-[var(--color-sand)] rounded-full flex items-center justify-center text-[var(--color-accent)] font-black text-lg overflow-hidden border-2 border-white shadow-sm ring-1 ring-gray-100">
                      <img
                        v-if="review.user_avatar || review.user?.avatar_url || review.user?.avatar"
                        :src="review.user_avatar || review.user?.avatar_url || review.user?.avatar"
                        class="w-full h-full object-cover"
                      />
                      <span v-else>{{ review.user_name?.charAt(0) }}</span>
                    </div>
                    <div>
                      <p class="font-extrabold text-[var(--color-text-dark)] text-base mb-1">{{ review.user_name }}</p>
                      <div class="flex gap-0.5">
                        <span v-for="s in 5" :key="s" class="material-symbols-rounded text-[18px]"
                          :class="s <= review.rating ? 'text-[#FFB020]' : 'text-gray-200'"
                          :style="s <= review.rating ? 'font-variation-settings:\'FILL\' 1' : ''">
                          star
                        </span>
                      </div>
                    </div>
                  </div>
                  <span class="text-xs font-bold text-[var(--color-text-muted)] bg-[var(--color-sand)] px-3 py-1.5 rounded-full">
                    {{ formatDate(review.created_at) }}
                  </span>
                </div>

                <p class="text-[var(--color-text-mid)] leading-relaxed text-base font-medium mb-5 whitespace-pre-line flex-grow">
                  {{ review.comment }}
                </p>

                <!-- Review Images -->
                <div v-if="review.images && review.images.length > 0" class="flex flex-wrap gap-3 mb-5">
                  <div
                    v-for="(img, idx) in review.images"
                    :key="idx"
                    @click="openReviewImage(review.images, idx)"
                    class="relative w-20 h-20 md:w-24 md:h-24 rounded-xl overflow-hidden border border-gray-100 cursor-pointer group"
                  >
                    <img :src="img" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" />
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-colors duration-300 flex items-center justify-center">
                      <span class="material-symbols-rounded text-white text-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300 drop-shadow-lg">zoom_in</span>
                    </div>
                  </div>
                </div>

                <!-- Admin Reply -->
                <div v-if="review.admin_reply" class="mt-auto bg-[var(--color-sand)]/50 rounded-2xl p-5 border-l-4 border-[var(--color-accent)] relative overflow-hidden">
                  <div class="absolute -right-4 -bottom-4 opacity-5 pointer-events-none">
                    <span class="material-symbols-rounded text-7xl text-[var(--color-accent)]">forum</span>
                  </div>
                  <p class="text-xs font-black text-[var(--color-accent)] uppercase tracking-widest mb-2 flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-[14px]" style="font-variation-settings:'FILL' 1">verified</span>
                    การตอบกลับจากผู้ดูแล
                  </p>
                  <p class="text-sm font-bold text-[var(--color-text-dark)] leading-relaxed">
                    {{ review.admin_reply }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Load More -->
            <div v-if="displayedReviews.length < reviews.length || reviewsHasMore" class="mt-10 text-center">
              <button
                @click="loadMoreReviews"
                :disabled="reviewsLoadingMore"
                class="inline-flex items-center gap-2.5 px-8 py-3.5 rounded-full border-2 border-[var(--color-accent)]/25 text-[var(--color-accent)] font-extrabold text-base hover:bg-[var(--color-accent)] hover:text-white hover:border-[var(--color-accent)] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95"
              >
                <span v-if="reviewsLoadingMore" class="w-5 h-5 border-2 border-current border-t-transparent rounded-full animate-spin"></span>
                <span v-else class="material-symbols-rounded text-[20px]">expand_more</span>
                <span v-if="reviewsLoadingMore">กำลังโหลด...</span>
                <span v-else-if="trip.review_count > displayedReviews.length">
                  ดูรีวิวเพิ่มเติม ({{ trip.review_count - displayedReviews.length }} รายการ)
                </span>
                <span v-else>ดูรีวิวเพิ่มเติม</span>
              </button>
            </div>
          </div>

          <div v-else class="text-center py-20 bg-white/50 rounded-[2.5rem] border border-dashed border-gray-200">
            <div class="w-16 h-16 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-4">
              <span class="material-symbols-rounded text-gray-300 text-3xl">rate_review</span>
            </div>
            <p class="text-[var(--color-text-muted)] font-extrabold text-lg mb-1">ยังไม่มีการรีวิวสำหรับทริปนี้</p>
            <p class="text-[var(--color-text-muted)] text-sm font-medium">ร่วมแชร์ประสบการณ์การเดินทางของคุณได้ หลังจากจบทริป</p>
          </div>
        </section>

        <!-- Related trips — "you may also like" -->
        <section v-if="relatedTrips.length" class="mt-16 pt-16 border-t border-gray-200">
          <div class="flex items-end justify-between gap-4 mb-8">
            <div>
              <header class="ed-head mb-2">
                <span class="ed-kicker">แนะนำสำหรับคุณ</span>
                <h3 class="ed-title">ทริปที่คุณอาจสนใจ</h3>
              </header>
              <p class="text-[var(--color-text-muted)] font-medium">คัดจากทริปแนวเดียวกันและปลายทางใกล้เคียง</p>
            </div>
            <router-link to="/trips" class="hidden md:inline-flex items-center gap-1.5 shrink-0 text-[var(--color-accent)] font-bold hover:gap-2.5 transition-all">
              ดูทั้งหมด
              <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
            </router-link>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 md:gap-8">
            <TripCard v-for="t in relatedTrips" :key="t.id" :trip="t" />
          </div>
        </section>

      </div>

      <!-- Sticky mobile booking bar (desktop uses the sticky side panel instead) -->
      <div class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-t border-gray-100 shadow-[0_-8px_24px_rgba(0,0,0,0.08)] px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
        <div class="flex items-center gap-3">
          <div class="min-w-0 flex-1">
            <p class="text-[10px] font-bold text-[var(--color-text-muted)] uppercase tracking-wider leading-none mb-1">
              {{ selectedSchedule ? 'ราคาสุทธิ / ท่าน' : 'เริ่มต้น / ท่าน' }}
            </p>
            <div class="flex items-baseline gap-1.5">
              <span class="text-xl font-black text-[var(--color-primary)] tracking-tight">฿{{ displayPrice.toLocaleString() }}</span>
              <span v-if="flashSchedule && flashSchedule.flash_sale.price <= displayPrice" class="text-gray-400 text-sm font-bold line-through decoration-2">
                ฿{{ Number(flashSchedule.original_price).toLocaleString() }}
              </span>
            </div>
            <p v-if="installmentPlan" class="mt-0.5 text-[11px] font-black text-[#2D7A4F] truncate">
              ผ่อน 0% {{ installmentPlan.count }} งวด · งวดละ ฿{{ installmentPlan.perInstallment.toLocaleString() }}
            </p>
          </div>
          <router-link
            v-if="canBookNow"
            :to="{ path: `/booking/${selectedSchedule.id}`, query: bookingQuery }"
            class="shrink-0 inline-flex items-center gap-1.5 bg-[var(--color-primary)] text-white px-6 py-3.5 rounded-full font-extrabold text-base shadow-[0_8px_16px_rgba(13,43,30,0.2)] active:scale-95 transition-transform"
          >
            <span class="material-symbols-rounded text-[20px]">event_available</span>
            จองเลย
          </router-link>
          <button
            v-else
            @click="scrollToBooking"
            class="shrink-0 inline-flex items-center gap-1.5 bg-[var(--color-primary)] text-white px-6 py-3.5 rounded-full font-extrabold text-base shadow-[0_8px_16px_rgba(13,43,30,0.2)] active:scale-95 transition-transform"
          >
            {{ selectedSchedule ? 'เลือกให้ครบ' : 'เลือกวันเดินทาง' }}
            <span class="material-symbols-rounded text-[20px]">arrow_upward</span>
          </button>
        </div>
      </div>
    </div>
    <div v-else class="text-center py-32 bg-white m-8 rounded-[2rem] border border-gray-100 shadow-sm max-w-3xl mx-auto">
      <div class="w-24 h-24 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-6">
        <span class="material-symbols-rounded text-gray-300 text-5xl">explore_off</span>
      </div>
      <h3 class="text-[var(--color-text-dark)] text-2xl font-extrabold mb-3">ไม่พบข้อมูลทริป</h3>
      <p class="text-[var(--color-text-muted)] text-base font-medium mb-8">ทริปที่คุณค้นหาอาจถูกลบหรือไม่มีอยู่ในระบบ</p>
      <router-link to="/trips" class="inline-flex items-center gap-2 bg-[var(--color-primary)] text-white px-8 py-3.5 rounded-full text-base font-extrabold hover:bg-[var(--color-accent)] transition-all duration-300 shadow-lg hover:-translate-y-1">
        <span class="material-symbols-rounded text-[20px]">arrow_back</span>
        กลับไปหน้ากิจกรรมทั้งหมด
      </router-link>
    </div>

    <!-- Must Know Modal Popup -->
    <Teleport to="body">
      <div v-if="showMustKnowModal && hasMustKnowContent" class="fixed inset-0 z-[100] flex items-center justify-center p-3 sm:p-6">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/65 backdrop-blur-md transition-opacity" @click="showMustKnowModal = false"></div>
        
        <!-- Modal Content -->
        <div class="bg-white rounded-[1.5rem] sm:rounded-[2rem] w-full max-w-3xl max-h-[calc(100vh-1.5rem)] sm:max-h-[calc(100vh-3rem)] relative z-10 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-300 flex flex-col">
          <!-- Close Button -->
          <button @click="showMustKnowModal = false" class="absolute top-3 right-3 sm:top-5 sm:right-5 w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/95 hover:bg-white flex items-center justify-center transition-all active:scale-95 z-20 shadow-lg border border-white/60" aria-label="ปิดหน้าต่างข้อควรรู้">
            <span class="material-symbols-rounded text-gray-600 text-[21px] sm:text-2xl">close</span>
          </button>

          <!-- Top Banner -->
          <div class="bg-gradient-to-br from-amber-500 to-[#D78A16] px-5 py-6 sm:p-8 text-white relative overflow-hidden shrink-0">
            <div class="absolute -right-10 -bottom-12 w-36 h-36 bg-white/10 rounded-full blur-2xl"></div>
            <div class="flex items-start gap-4 pr-10 sm:pr-12 relative z-10">
              <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-white/20 flex items-center justify-center border border-white/20 shadow-lg shrink-0">
                <span class="material-symbols-rounded text-3xl sm:text-4xl" style="font-variation-settings:'FILL' 1">campaign</span>
              </div>
              <div>
                <p class="text-[11px] sm:text-xs font-black uppercase tracking-[0.18em] text-white/75 mb-1">Important trip details</p>
                <h3 class="text-2xl sm:text-3xl font-black tracking-tight leading-tight">ข้อควรรู้สำหรับทริปนี้</h3>
                <p class="mt-2 text-sm sm:text-base font-bold text-white/90 leading-relaxed">ตรวจสอบรายการเสริมและหมายเหตุก่อนเริ่มจอง เพื่อให้เตรียมตัวได้ครบถ้วน</p>
              </div>
            </div>
          </div>

          <div class="p-5 sm:p-7 space-y-5 sm:space-y-6 overflow-y-auto custom-scrollbar">
            <!-- Items Selection / Info -->
            <section v-if="mustKnowItems.length" class="space-y-3">
              <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-1">
                <div>
                  <p class="text-[11px] font-black text-amber-600 uppercase tracking-[0.16em]">ตัวเลือกเพิ่มเติม</p>
                  <h4 class="text-lg sm:text-xl font-black text-[var(--color-text-dark)] leading-tight">รายการที่สามารถเลือกเพิ่มได้</h4>
                </div>
                <span class="text-xs font-extrabold text-gray-500 bg-gray-50 border border-gray-100 px-3 py-1.5 rounded-full w-fit">
                  {{ mustKnowItems.length }} รายการ
                </span>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div v-for="(item, idx) in mustKnowItems" :key="idx" 
                  class="rounded-2xl bg-white border border-gray-100 shadow-[0_8px_24px_rgba(0,0,0,0.04)] p-4 sm:p-5 transition-all hover:border-amber-200 hover:shadow-[0_12px_32px_rgba(0,0,0,0.07)]">
                  <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center border border-amber-100 shrink-0">
                      <span class="material-symbols-rounded text-xl text-amber-600">tips_and_updates</span>
                    </div>
                    <div class="min-w-0 flex-1">
                      <p class="font-black text-gray-900 text-sm sm:text-base leading-snug break-words">{{ item.name }}</p>
                      <p class="mt-1 text-xs sm:text-sm font-bold text-gray-500">คิดราคา{{ item.priceTypeLabel }}</p>
                    </div>
                    <div class="text-right shrink-0">
                      <div class="font-black text-amber-700 bg-amber-50 px-3 py-1.5 rounded-xl border border-amber-100 text-sm sm:text-base">
                        {{ item.priceLabel }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <!-- Notes / Remarks -->
            <section v-if="mustKnowRemarks" class="rounded-2xl bg-amber-50 border border-amber-100 p-4 sm:p-5 relative overflow-hidden">
              <div class="absolute -right-10 -bottom-10 w-24 h-24 bg-amber-200/30 rounded-full blur-2xl"></div>
              <div class="flex items-start gap-3 relative z-10">
                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center border border-amber-100 shrink-0">
                  <span class="material-symbols-rounded text-amber-600 text-xl" style="font-variation-settings:'FILL' 1">info</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-[11px] font-black text-amber-700 uppercase tracking-[0.16em] mb-1">หมายเหตุเพิ่มเติม</p>
                  <p class="text-sm sm:text-base text-gray-800 leading-relaxed font-bold whitespace-pre-line break-words">{{ mustKnowRemarks }}</p>
                </div>
              </div>
            </section>

            <button @click="showMustKnowModal = false" class="w-full bg-[var(--color-primary)] text-white font-extrabold py-3.5 sm:py-4 rounded-xl hover:bg-[var(--color-accent)] active:scale-[0.98] transition-all shadow-lg shadow-[var(--color-primary)]/20 text-sm sm:text-base">
              เข้าใจแล้ว เริ่มจองทริปกันเลยครับ
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Gallery Lightbox Modal -->
    <Teleport to="body">
      <Transition 
        enter-active-class="transition duration-400 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-300 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="showGalleryModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/95 backdrop-blur-2xl">
          <!-- Close Button -->
          <button @click="closeGallery" class="absolute top-6 right-6 z-[210] w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-90 shadow-2xl border border-white/10">
            <span class="material-symbols-rounded text-3xl">close</span>
          </button>

          <!-- Navigation Buttons -->
          <button v-if="trip.gallery.length > 1" @click="prevGalleryImage" class="absolute left-6 z-[210] w-16 h-16 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-90 hidden md:flex border border-white/10">
            <span class="material-symbols-rounded text-4xl">chevron_left</span>
          </button>
          <button v-if="trip.gallery.length > 1" @click="nextGalleryImage" class="absolute right-6 z-[210] w-16 h-16 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-90 hidden md:flex border border-white/10">
            <span class="material-symbols-rounded text-4xl">chevron_right</span>
          </button>

          <!-- Main Image Container -->
          <div class="relative w-full h-full flex flex-col items-center justify-center p-4 md:p-12 lg:p-20 overflow-hidden">
            <div class="relative max-w-6xl w-full h-full flex items-center justify-center">
              <Transition 
                mode="out-in"
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-250 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-105"
              >
                <img 
                  :key="activeGalleryIndex"
                  :src="trip.gallery[activeGalleryIndex]" 
                  class="max-w-full max-h-full object-contain shadow-[0_0_50px_rgba(0,0,0,0.5)] rounded-2xl"
                />
              </Transition>
            </div>
            
            <!-- Caption / Counter / Thumbnails Container -->
            <div class="mt-8 w-full max-w-4xl animate-fade-in-up">
              <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 px-4">
                <div class="text-white">
                  <p class="font-black text-2xl md:text-3xl mb-1 tracking-tight">{{ trip.title }}</p>
                  <p class="text-white/50 font-bold uppercase tracking-[0.2em] text-[10px] md:text-xs flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm">photo_camera</span>
                    ภาพที่ {{ activeGalleryIndex + 1 }} จาก {{ trip.gallery.length }}
                  </p>
                </div>

                <!-- Thumbnails -->
                <div class="flex gap-3 overflow-x-auto pb-4 custom-scrollbar max-w-full md:max-w-md">
                  <div 
                    v-for="(img, idx) in trip.gallery" 
                    :key="idx"
                    @click="activeGalleryIndex = idx"
                    class="w-16 h-16 md:w-20 md:h-20 rounded-2xl overflow-hidden cursor-pointer border-2 transition-all duration-300 shrink-0 shadow-lg"
                    :class="activeGalleryIndex === idx ? 'border-[var(--color-accent)] scale-110 shadow-[0_0_20px_rgba(45,122,79,0.4)]' : 'border-white/10 opacity-30 hover:opacity-100 hover:border-white/30'"
                  >
                    <img :src="img" class="w-full h-full object-cover" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Availability Modal -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="showAvailabilityModal && trip" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
          <!-- Backdrop -->
          <div class="fixed inset-0 bg-black/60 backdrop-blur-md transition-opacity" @click="showAvailabilityModal = false"></div>
          
          <!-- Modal Content -->
          <div class="bg-white rounded-[2.5rem] w-full max-w-2xl relative z-10 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-300 flex flex-col max-h-[85vh]">
            <!-- Header -->
            <div class="bg-[var(--color-primary)] p-6 md:p-8 text-white relative shrink-0">
              <div class="absolute -right-12 -top-12 w-48 h-48 bg-white/10 rounded-full blur-3xl"></div>
              <button @click="showAvailabilityModal = false" class="absolute top-4 right-4 md:top-6 md:right-6 w-10 h-10 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-all active:scale-95 z-20">
                <span class="material-symbols-rounded">close</span>
              </button>
              <div class="flex items-center gap-4 mb-3">
                <div class="w-12 h-12 rounded-2xl bg-white/20 flex items-center justify-center border border-white/20">
                  <span class="material-symbols-rounded text-3xl">calendar_month</span>
                </div>
                <h3 class="text-2xl font-black tracking-tight">เช็ครอบการเดินทาง</h3>
              </div>
              <p class="text-white/80 font-bold flex items-center gap-2 text-sm md:text-base">
                <span class="material-symbols-rounded text-sm">info</span>
                {{ trip.title }}
              </p>
            </div>

            <!-- Body -->
            <div class="p-4 md:p-8 overflow-y-auto custom-scrollbar bg-gray-50/50 flex-grow">
              <!-- Region Tabs for Trekking Trips -->
              <div v-if="isTrekking && regionOptions.length > 0" class="mb-6">
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">เลือกภาค/ภูมิภาคที่ต้องการเดินทาง</p>
                <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
                  <button 
                    @click="selectedModalRegion = null"
                    class="px-5 py-2.5 rounded-full text-[13px] font-black whitespace-nowrap transition-all border"
                    :class="!selectedModalRegion ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)] shadow-md' : 'bg-white text-gray-500 border-gray-100 hover:bg-gray-50'"
                  >
                    ทุกภาค
                  </button>
                  <button 
                    v-for="reg in regionOptions" 
                    :key="reg.region"
                    @click="selectedModalRegion = reg.region"
                    class="px-5 py-2.5 rounded-full text-[13px] font-black whitespace-nowrap transition-all border flex items-center gap-2"
                    :class="selectedModalRegion === reg.region ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)] shadow-md' : 'bg-white text-gray-500 border-gray-100 hover:bg-gray-50'"
                  >
                    {{ reg.region_label }}
                    <span class="text-[10px] opacity-60 bg-black/10 px-1.5 py-0.5 rounded-full">{{ reg.schedule_count }}</span>
                  </button>
                </div>
              </div>

              <div v-if="modalSchedules.length > 0" class="space-y-4">
                <div v-for="s in modalSchedules" :key="s.id" 
                  class="bg-white p-4 md:p-5 rounded-2xl border border-gray-100 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4 transition-all hover:border-[var(--color-accent)]/30 hover:shadow-md"
                  :class="{'opacity-60': !isScheduleBookable(s)}">
                  <div class="flex items-center gap-4">
                    <div class="w-12 h-12 md:w-14 md:h-14 rounded-2xl bg-[var(--color-sand)] flex flex-col items-center justify-center shrink-0 border border-gray-100">
                      <span class="text-[9px] font-black uppercase text-[var(--color-text-muted)] leading-none mb-1">{{ new Date(s.departure_date).toLocaleDateString('th-TH', {month: 'short'}) }}</span>
                      <span class="text-xl font-black text-[var(--color-text-dark)] leading-none">{{ new Date(s.departure_date).getDate() }}</span>
                    </div>
                    <div>
                      <p class="font-extrabold text-[var(--color-text-dark)] text-sm md:text-base">
                        {{ formatDate(s.departure_date) }} 
                        <span v-if="s.return_date && s.return_date !== s.departure_date" class="text-gray-400 font-bold mx-1">/</span>
                        <span v-if="s.return_date && s.return_date !== s.departure_date">{{ formatDate(s.return_date) }}</span>
                      </p>
                      <div class="flex items-center gap-3 mt-1">
                        <div class="flex items-center gap-1">
                          <span class="w-2 h-2 rounded-full" :class="scheduleAvailabilityDotClass(s)"></span>
                          <span v-if="s.join_trip_enabled" class="text-xs md:text-sm font-bold" :class="scheduleAvailabilityTextClass(s)">
                            {{ scheduleAvailabilityLabel(s) }}
                          </span>
                          <span v-else class="text-xs md:text-sm font-bold" :class="scheduleAvailabilityTextClass(s)">
                            {{ scheduleAvailabilityLabel(s) }}
                          </span>
                        </div>
                        <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                        <span class="text-xs md:text-sm font-black" :class="s.flash_sale?.active ? 'text-[#EA580C]' : 'text-[var(--color-text-dark)]'">฿{{ Number(s.price || trip.price_per_person).toLocaleString() }}</span>
                        <span v-if="s.flash_sale?.active" class="text-[11px] font-bold text-gray-400 line-through">฿{{ Number(s.original_price).toLocaleString() }}</span>
                        <span v-if="s.flash_sale?.active" class="inline-flex items-center gap-0.5 text-[9px] font-black bg-[#FFF7ED] text-[#EA580C] border border-[#FED7AA] px-1.5 py-0.5 rounded-md uppercase">
                          <span class="material-symbols-rounded" style="font-size:11px;">bolt</span> Flash
                        </span>
                      </div>

                      <!-- Region Badges -->
                      <div v-if="isTrekking && s.pickup_points?.length" class="flex flex-wrap gap-1 mt-2">
                        <span v-for="reg in [...new Set(s.pickup_points.map(p => p.region_label))]" :key="reg" class="text-[9px] font-black bg-gray-100 text-gray-500 px-2 py-0.5 rounded-md uppercase tracking-tighter">
                          {{ reg }}
                        </span>
                      </div>
                    </div>
                  </div>
                  
                  <router-link 
                    v-if="isScheduleBookable(s)"
                    :to="{ path: `/booking/${s.id}`, query: s.join_trip_enabled && !hasAvailableSeats(s) ? { join_trip: 1 } : {} }"
                    @click="showAvailabilityModal = false"
                    class="bg-[var(--color-primary)] hover:bg-[var(--color-accent)] text-white px-6 py-2.5 rounded-xl text-sm font-black transition-all shadow-md hover:-translate-y-0.5 text-center active:scale-95"
                  >
                    จองรอบนี้
                  </router-link>
                  <div v-else class="px-6 py-2.5 rounded-xl text-sm font-black bg-gray-100 text-gray-400 border border-gray-200 text-center">
                    จองเต็มแล้ว
                  </div>
                </div>
              </div>
              
              <div v-else class="text-center py-16 bg-white rounded-3xl border border-dashed border-gray-200">
                <div class="w-20 h-20 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-4">
                  <span class="material-symbols-rounded text-gray-300 text-5xl">event_busy</span>
                </div>
                <p class="text-[var(--color-text-muted)] font-extrabold text-lg">ยังไม่มีรอบการเดินทางที่เปิดจอง</p>
                <p class="text-gray-400 text-sm font-medium mt-1">กรุณาติดตามอัปเดตรอบเดินทางใหม่เร็วๆ นี้</p>
              </div>
            </div>
            
            <!-- Footer -->
            <div class="p-5 md:p-6 bg-white border-t border-gray-100 text-center shrink-0">
               <p class="text-[10px] md:text-xs font-bold text-[var(--color-text-muted)] leading-relaxed uppercase tracking-widest">
                  * หมายเหตุ: จำนวนที่นั่งอาจมีการเปลี่ยนแปลงแบบเรียลไทม์ตามการชำระเงินของลูกค้าท่านอื่น
               </p>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Seat Map Preview Modal ── -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="showSeatMapModal"
          class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center p-0 sm:p-4"
        >
          <!-- Backdrop -->
          <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showSeatMapModal = false"></div>

          <!-- Modal panel with slide-up animation -->
          <Transition
            appear
            enter-active-class="transition duration-400 ease-[cubic-bezier(0.16,1,0.3,1)]"
            enter-from-class="translate-y-full sm:translate-y-4 sm:opacity-0 sm:scale-95"
            enter-to-class="translate-y-0 sm:opacity-100 sm:scale-100"
            leave-active-class="transition duration-250 ease-in"
            leave-from-class="translate-y-0 sm:opacity-100"
            leave-to-class="translate-y-full sm:translate-y-4 sm:opacity-0"
          >
            <div
              v-if="showSeatMapModal"
              class="relative w-full sm:max-w-md bg-white rounded-t-[2.5rem] sm:rounded-[2rem] shadow-2xl flex flex-col"
              style="max-height: min(90vh, 720px)"
            >
              <!-- Drag handle (mobile) -->
              <div class="flex justify-center pt-3 pb-1 sm:hidden shrink-0">
                <div class="w-10 h-1 rounded-full bg-gray-200"></div>
              </div>

              <!-- Header -->
              <div class="px-6 pt-4 pb-5 shrink-0">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span class="material-symbols-rounded text-[var(--color-accent)] text-[18px]" style="font-variation-settings:'FILL' 1">airline_seat_recline_normal</span>
                      <h3 class="font-black text-[var(--color-text-dark)] text-base">ผังที่นั่งรถ</h3>
                    </div>
                    <p v-if="seatMapPreviewSchedule" class="text-xs font-bold text-[var(--color-text-muted)] flex items-center gap-1.5">
                      <span class="material-symbols-rounded text-[13px]">calendar_today</span>
                      {{ formatDate(seatMapPreviewSchedule.departure_date) }}
                      <template v-if="seatMapPreviewSchedule.return_date !== seatMapPreviewSchedule.departure_date">
                        <span class="text-gray-300">–</span>
                        {{ formatDate(seatMapPreviewSchedule.return_date) }}
                      </template>
                    </p>
                  </div>
                  <button
                    @click="showSeatMapModal = false"
                    class="shrink-0 w-8 h-8 rounded-xl flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-400 hover:text-gray-600 transition-colors"
                  >
                    <span class="material-symbols-rounded text-[18px]">close</span>
                  </button>
                </div>

                <!-- Available seats bar -->
                <div v-if="seatMapPreviewData && !seatMapPreviewLoading" class="mt-4 p-3.5 rounded-2xl bg-[var(--color-sand)] border border-gray-100">
                  <div class="flex items-center justify-between mb-2.5">
                    <span class="text-xs font-bold text-[var(--color-text-muted)]">ที่นั่งว่าง</span>
                    <span class="text-sm font-black" :class="seatMapPreviewData.available_seats === 0 ? 'text-red-500' : seatMapPreviewData.available_seats <= 3 ? 'text-amber-500' : 'text-[var(--color-accent)]'">
                      {{ seatMapPreviewData.available_seats }}
                      <span class="text-xs font-bold text-[var(--color-text-muted)]"> / {{ seatMapPreviewData.total_seats }} ที่นั่ง</span>
                    </span>
                  </div>
                  <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all duration-700"
                      :class="seatMapPreviewData.available_seats === 0 ? 'bg-red-400' : seatMapPreviewData.available_seats <= 3 ? 'bg-amber-400' : 'bg-[var(--color-accent)]'"
                      :style="{ width: `${Math.round((seatMapPreviewData.booked_seats / seatMapPreviewData.total_seats) * 100)}%` }"
                    ></div>
                  </div>
                  <div class="flex justify-between mt-1.5">
                    <span class="text-[10px] font-bold text-gray-400">จองแล้ว {{ seatMapPreviewData.booked_seats }} ที่</span>
                    <span v-if="seatMapPreviewData.available_seats === 0" class="text-[10px] font-black text-red-500">เต็มแล้ว</span>
                    <span v-else-if="seatMapPreviewData.available_seats <= 3" class="text-[10px] font-black text-amber-500">ใกล้เต็มแล้ว!</span>
                  </div>
                </div>
              </div>

              <!-- Divider -->
              <div class="h-px bg-gray-100 shrink-0 mx-6"></div>

              <!-- Body -->
              <div class="overflow-y-auto px-6 py-5 custom-scrollbar flex-1">
                <!-- Loading -->
                <div v-if="seatMapPreviewLoading" class="py-16 flex flex-col items-center gap-4">
                  <div class="w-10 h-10 border-4 border-gray-100 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
                  <p class="text-sm font-bold text-[var(--color-text-muted)]">กำลังโหลดผังที่นั่ง...</p>
                </div>

                <!-- Seat map -->
                <SeatMap
                  v-else-if="seatMapPreviewData"
                  :seat-map="seatMapPreviewData"
                  :is-women-only="trip?.is_women_only"
                  :readonly="true"
                />

                <!-- Error / no seat map -->
                <div v-else class="py-16 flex flex-col items-center gap-3 text-center">
                  <span class="material-symbols-rounded text-5xl text-gray-200" style="font-variation-settings:'FILL' 0,'wght' 200">airline_seat_recline_normal</span>
                  <p class="text-sm font-bold text-gray-400">ไม่มีข้อมูลผังที่นั่ง</p>
                </div>
              </div>

              <!-- Footer CTA -->
              <div class="px-6 py-4 bg-white border-t border-gray-100 shrink-0">
                <router-link
                  v-if="seatMapPreviewSchedule && isScheduleBookable(seatMapPreviewSchedule)"
                  :to="{ path: `/booking/${seatMapPreviewSchedule.id}`, query: seatMapPreviewSchedule.join_trip_enabled && !hasAvailableSeats(seatMapPreviewSchedule) ? { join_trip: 1 } : {} }"
                  @click="showSeatMapModal = false"
                  class="flex items-center justify-center gap-2 w-full py-3.5 rounded-2xl bg-[var(--color-primary)] hover:bg-[var(--color-accent)] text-white font-black text-sm transition-all shadow-lg shadow-[var(--color-primary)]/20 active:scale-95"
                >
                  <span class="material-symbols-rounded text-[18px]">shopping_bag</span>
                  จองรอบนี้
                </router-link>
                <div v-else-if="seatMapPreviewSchedule" class="text-center py-2">
                  <span class="text-sm font-black text-gray-400">รอบนี้เต็มแล้ว</span>
                </div>
                <p class="text-[10px] font-bold text-gray-400 text-center mt-2">ผังแสดงสถานะแบบเรียลไทม์ · การเลือกที่นั่งจะทำได้ตอนจอง</p>
              </div>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <!-- Review Image Lightbox Modal -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="showReviewImageModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/95 backdrop-blur-2xl" @click.self="closeReviewImage">
          <!-- Close Button -->
          <button @click="closeReviewImage" class="absolute top-6 right-6 z-[210] w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-90 shadow-2xl border border-white/10">
            <span class="material-symbols-rounded text-3xl">close</span>
          </button>

          <!-- Navigation Buttons -->
          <button v-if="reviewImageModalImages.length > 1" @click="prevReviewImage" class="absolute left-4 md:left-6 z-[210] w-12 h-12 md:w-16 md:h-16 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-90 border border-white/10">
            <span class="material-symbols-rounded text-3xl md:text-4xl">chevron_left</span>
          </button>
          <button v-if="reviewImageModalImages.length > 1" @click="nextReviewImage" class="absolute right-4 md:right-6 z-[210] w-12 h-12 md:w-16 md:h-16 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-90 border border-white/10">
            <span class="material-symbols-rounded text-3xl md:text-4xl">chevron_right</span>
          </button>

          <!-- Main Image Container -->
          <div class="relative w-full h-full flex flex-col items-center justify-center p-4 md:p-16 lg:p-20 overflow-hidden">
            <div class="relative max-w-5xl w-full flex items-center justify-center" style="max-height: calc(100vh - 160px)">
              <Transition
                mode="out-in"
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-105"
              >
                <img
                  :key="reviewImageModalIndex"
                  :src="reviewImageModalImages[reviewImageModalIndex]"
                  class="max-w-full max-h-full object-contain shadow-[0_0_50px_rgba(0,0,0,0.5)] rounded-2xl"
                  style="max-height: calc(100vh - 160px)"
                />
              </Transition>
            </div>

            <!-- Counter and Thumbnails -->
            <div class="mt-6 w-full max-w-4xl shrink-0">
              <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 px-4">
                <p class="text-white/50 font-bold uppercase tracking-[0.2em] text-[10px] md:text-xs flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm">photo_camera</span>
                  ภาพที่ {{ reviewImageModalIndex + 1 }} จาก {{ reviewImageModalImages.length }}
                </p>

                <!-- Thumbnails (only if multiple images) -->
                <div v-if="reviewImageModalImages.length > 1" class="flex gap-2 overflow-x-auto pb-2 custom-scrollbar">
                  <div
                    v-for="(img, idx) in reviewImageModalImages"
                    :key="idx"
                    @click="reviewImageModalIndex = idx"
                    class="w-14 h-14 md:w-16 md:h-16 rounded-xl overflow-hidden cursor-pointer border-2 transition-all duration-300 shrink-0"
                    :class="reviewImageModalIndex === idx ? 'border-[var(--color-accent)] scale-110 opacity-100' : 'border-white/10 opacity-40 hover:opacity-80 hover:border-white/30'"
                  >
                    <img :src="img" class="w-full h-full object-cover" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import { useHead } from '@unhead/vue';
import SeatMap from '../components/SeatMap.vue';
import ScheduleCalendar from '../components/ScheduleCalendar.vue';
import TripPostsFeed from '../components/TripPostsFeed.vue';
import TripCard from '../components/TripCard.vue';
import WeatherBadge from '../components/WeatherBadge.vue';
import WaitlistJoinCard from '../components/WaitlistJoinCard.vue';
import { useWishlistStore } from '../stores/wishlist';
import {
  hasAvailableSeats,
  isScheduleBookable,
  scheduleAvailabilityBadgeClass,
  scheduleAvailabilityTextClass,
  scheduleAvailabilityDotClass,
  scheduleAvailabilityLabel,
  formatDate,
  getSortedPickupPoints,
} from '../lib/scheduleHelpers';

const route = useRoute();
const trip = ref(null);
const relatedTrips = ref([]);

useHead({
  title: computed(() => trip.value ? `${trip.value.title} - ทริป${trip.value.type === 'trekking' ? 'เดินป่า' : trip.value.type === 'snorkeling' ? 'ดำน้ำตื้น' : 'เช่ารถตู้'}` : 'รายละเอียดทริป'),
  link: [
    { rel: 'canonical', href: computed(() => `${window.location.origin}/trips/${route.params.slug}`) }
  ],
  meta: [
    // SEO Meta
    { name: 'description', content: computed(() => {
      if (!trip.value) return '';
      const desc = trip.value.description || '';
      const location = trip.value.location ? ` สถานที่: ${trip.value.location}` : '';
      const price = trip.value.price_per_person ? ` ราคาเริ่มต้น ฿${Number(trip.value.price_per_person).toLocaleString()}` : '';
      const truncated = desc.length > 120 ? desc.substring(0, 120) + '...' : desc;
      return `${trip.value.title} - ลุยเลเขา${location}${price} ${truncated}`;
    })},
    { name: 'robots', content: 'index, follow, max-image-preview:large' },

    // Open Graph
    { property: 'og:type', content: 'product' },
    { property: 'og:site_name', content: 'ลุยเลเขา Luilaykhao' },
    { property: 'og:locale', content: 'th_TH' },
    { property: 'og:url', content: computed(() => `${window.location.origin}/trips/${route.params.slug}`) },
    { property: 'og:title', content: computed(() => trip.value ? `${trip.value.title} | ลุยเลเขา` : '') },
    { property: 'og:description', content: computed(() => trip.value ? (trip.value.description || '').substring(0, 200) : '') },
    { property: 'og:image', content: computed(() => trip.value?.cover_image ? (trip.value.cover_image.startsWith('http') ? trip.value.cover_image : `${window.location.origin}${trip.value.cover_image}`) : `${window.location.origin}/images/logo.png?v=2`) },
    { property: 'og:image:alt', content: computed(() => trip.value ? `${trip.value.title} - ลุยเลเขา` : '') },
    { property: 'og:image:width', content: '1200' },
    { property: 'og:image:height', content: '630' },
    { property: 'product:price:amount', content: computed(() => trip.value?.price_per_person?.toString() || '') },
    { property: 'product:price:currency', content: 'THB' },

    // Twitter Card
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: computed(() => trip.value ? `${trip.value.title} | ลุยเลเขา` : '') },
    { name: 'twitter:description', content: computed(() => trip.value ? (trip.value.description || '').substring(0, 200) : '') },
    { name: 'twitter:image', content: computed(() => trip.value?.cover_image ? (trip.value.cover_image.startsWith('http') ? trip.value.cover_image : `${window.location.origin}${trip.value.cover_image}`) : `${window.location.origin}/images/logo.png?v=2`) },
    { name: 'twitter:image:alt', content: computed(() => trip.value ? trip.value.title : 'ลุยเลเขา') }
  ],
  // JSON-LD Structured Data for Trip (Product + TouristTrip)
  script: [
    {
      type: 'application/ld+json',
      innerHTML: computed(() => {
        if (!trip.value) return '{}';
        const t = trip.value;
        const imageUrl = t.cover_image ? (t.cover_image.startsWith('http') ? t.cover_image : `${window.location.origin}${t.cover_image}`) : `${window.location.origin}/images/logo.png?v=2`;
        const data = {
          '@context': 'https://schema.org',
          '@type': 'TouristTrip',
          name: t.title,
          description: t.description || '',
          url: `${window.location.origin}/trips/${route.params.slug}`,
          image: imageUrl,
          touristType: t.type === 'trekking' ? 'เดินป่า' : t.type === 'snorkeling' ? 'ดำน้ำตื้น' : 'เช่ารถตู้นำเที่ยว',
          provider: {
            '@type': 'TravelAgency',
            name: 'ลุยเลเขา Luilaykhao',
            url: window.location.origin,
            telephone: '+66-62-612-6006'
          },
          offers: {
            '@type': 'Offer',
            price: t.price_per_person,
            priceCurrency: 'THB',
            availability: 'https://schema.org/InStock',
            url: `${window.location.origin}/trips/${route.params.slug}`,
            validFrom: new Date().toISOString().split('T')[0]
          }
        };
        if (t.location) data.itinerary = { '@type': 'Place', name: t.location };
        if (t.duration_days) data.duration = `P${t.duration_days}D`;
        if (t.rating && Number(t.review_count) > 0) {
          data.aggregateRating = {
            '@type': 'AggregateRating',
            ratingValue: Number(t.rating).toFixed(1),
            reviewCount: t.review_count,
            bestRating: '5',
            worstRating: '1'
          };
        }
        return JSON.stringify(data);
      })
    },
    // JSON-LD FAQPage — earns FAQ rich snippets on Google when the trip has FAQs
    {
      type: 'application/ld+json',
      innerHTML: computed(() => {
        const faqs = trip.value?.faqs || [];
        if (!faqs.length) return '{}';
        return JSON.stringify({
          '@context': 'https://schema.org',
          '@type': 'FAQPage',
          mainEntity: faqs.map((f) => ({
            '@type': 'Question',
            name: f.question,
            acceptedAnswer: { '@type': 'Answer', text: f.answer },
          })),
        });
      })
    }
  ]
});

const schedules = ref([]);
const selectedSchedule = ref(null);
const activeIconPicker = ref(null);
const openDays = ref(['0-0']); // Default open first item of first sector
const openFaq = ref(null);
const activeSector = ref(0);
let sectorObserver = null;

const toggleDay = (key) => {
  if (openDays.value.includes(key)) {
    openDays.value = openDays.value.filter(k => k !== key);
  } else {
    openDays.value.push(key);
  }
};

const scrollToSector = (idx) => {
  activeSector.value = idx;
  const el = document.getElementById(`sector-${idx}`);
  if (el) {
    el.scrollIntoView({ behavior: 'smooth' });
  }
};

const setupSectorObserver = () => {
  if (sectorObserver) sectorObserver.disconnect();
  
  sectorObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const id = entry.target.id;
        const idx = parseInt(id.split('-')[1]);
        activeSector.value = idx;
      }
    });
  }, { threshold: 0.2, rootMargin: '-100px 0px -50% 0px' });

  itinerarySectors.value.forEach((_, idx) => {
    const el = document.getElementById(`sector-${idx}`);
    if (el) sectorObserver.observe(el);
  });
};
const selectedPickup = ref(null);
const selectedRegion = ref(null);
const isJoinTrip = ref(false);
const onlyAvailableSchedules = ref(false);
const loading = ref(true);
const schedulesLoading = ref(false);
const reviews = ref([]);
const reviewsLoading = ref(false);
const reviewsPage = ref(1);
const reviewsHasMore = ref(false);
const reviewsLoadingMore = ref(false);
const REVIEWS_PER_PAGE = 6;
const visibleReviewsCount = ref(REVIEWS_PER_PAGE);
const albumPhotos = ref([]);
const albumTotal = ref(0);
const albumPage = ref(1);
const albumHasMore = ref(false);
const albumLoadingMore = ref(false);
const ALBUM_PER_PAGE = 24;
const ALBUM_INITIAL_VISIBLE = 12;
const visibleAlbumCount = ref(ALBUM_INITIAL_VISIBLE);
const showReviewImageModal = ref(false);
const reviewImageModalImages = ref([]);
const reviewImageModalIndex = ref(0);
const showMustKnowModal = ref(false);
const distanceLoading = ref(false);
const distanceData = ref([]);
const showGalleryModal = ref(false);
const activeGalleryIndex = ref(0);
const showAvailabilityModal = ref(false);
const showSeatMapModal = ref(false);
const seatMapPreviewSchedule = ref(null);
const seatMapPreviewData = ref(null);
const seatMapPreviewLoading = ref(false);

const isTrekking = computed(() => trip.value?.type === 'trekking');
const selectedModalRegion = ref(null);

const modalSchedules = computed(() => {
  let list = schedules.value;
  if (onlyAvailableSchedules.value) {
    list = list.filter(isScheduleBookable);
  }
  
  if (isTrekking.value && selectedModalRegion.value) {
    list = list.filter(s => (s.pickup_points || []).some(pt => pt.region === selectedModalRegion.value));
  }
  
  return list;
});

const mustKnowItems = computed(() => {
  const items = trip.value?.must_know?.items || [];
  return items
    .map((item) => {
      const name = String(item?.name || '').trim();
      const price = Number(item?.price || 0);
      const priceType = item?.price_type === 'per_person' ? 'per_person' : 'per_booking';

      return {
        name,
        price,
        priceType,
        priceTypeLabel: priceType === 'per_person' ? 'ต่อคน' : 'ครั้งเดียว',
        priceLabel: `฿${price.toLocaleString('th-TH')}`,
      };
    })
    .filter((item) => item.name);
});

const mustKnowRemarks = computed(() => String(trip.value?.must_know?.remarks || '').trim());
const hasMustKnowContent = computed(() => mustKnowItems.value.length > 0 || Boolean(mustKnowRemarks.value));

const itinerarySectors = computed(() => {
  const raw = trip.value?.itinerary || [];
  if (raw.length === 0) return [];
  
  // Check if it's the new format (array of objects with sector field)
  if (raw[0].hasOwnProperty('sector')) {
    return raw;
  }
  
  // Transform old format to new format (flat array of items)
  return [{
    sector: 'กำหนดการเดินทาง',
    items: raw
  }];
});

const totalTripDays = computed(() => {
  let count = 0;
  itinerarySectors.value.forEach(s => {
    count += (s.items?.length || 0);
  });
  return count || trip.value?.duration_days || 0;
});

const allPickupPoints = computed(() => {
  if (!isTrekking.value) return [];
  const pts = selectedSchedule.value?.pickup_points
    || schedules.value[0]?.pickup_points
    || [];
  return pts;
});

const regionOrder = ['bangkok', 'central', 'north', 'northeast', 'east', 'west', 'south'];
const regionOrderMap = new Map(regionOrder.map((name, index) => [name, index]));

const compareRegions = (a, b) => {
  const aOrder = regionOrderMap.has(a.region) ? regionOrderMap.get(a.region) : Number.MAX_SAFE_INTEGER;
  const bOrder = regionOrderMap.has(b.region) ? regionOrderMap.get(b.region) : Number.MAX_SAFE_INTEGER;
  if (aOrder !== bOrder) return aOrder - bOrder;
  return (a.region_label || '').localeCompare(b.region_label || '', 'th');
};

const groupedPickupPointsByRegion = computed(() => {
  if (!allPickupPoints.value.length) return [];

  const grouped = new Map();

  allPickupPoints.value.forEach((pt) => {
    const regionKey = pt.region || 'other';
    const regionLabel = pt.region_label || 'อื่นๆ';
    const price = Number(pt.price || 0);

    if (!grouped.has(regionKey)) {
      grouped.set(regionKey, {
        region: regionKey,
        region_label: regionLabel,
        points: [],
        min_price: price,
        max_price: price,
      });
    }

    const bucket = grouped.get(regionKey);
    bucket.points.push(pt);
    bucket.min_price = Math.min(bucket.min_price, price);
    bucket.max_price = Math.max(bucket.max_price, price);
  });

  return [...grouped.values()]
    .map((group) => ({
      ...group,
      points: getSortedPickupPoints(group.points),
    }))
    .sort(compareRegions);
});

const regionOptions = computed(() => {
  if (!isTrekking.value) return [];
  const map = new Map();
  schedules.value.forEach(s => {
    if (!hasAvailableSeats(s)) return;
    const countedRegions = new Set();
    (s.pickup_points || []).forEach(pt => {
      const region = pt.region || 'other';
      const regionLabel = pt.region_label || 'อื่นๆ';
      const price = Number(pt.price || 0);

      if (!map.has(region)) {
        map.set(region, {
          region,
          region_label: regionLabel,
          min_price: price,
          schedule_count: 0,
        });
      } else {
        const existing = map.get(region);
        if (price < existing.min_price) existing.min_price = price;
      }

      if (!countedRegions.has(region)) {
        map.get(region).schedule_count++;
        countedRegions.add(region);
      }
    });
  });
  return [...map.values()].sort(compareRegions);
});

const schedulesForRegion = computed(() => {
  if (!selectedRegion.value) return [];
  return schedules.value.filter(s =>
    (s.pickup_points || []).some(pt => pt.region === selectedRegion.value)
  );
});

const pickupForSelection = computed(() => {
  if (!selectedSchedule.value || !selectedRegion.value) return null;
  return (selectedSchedule.value.pickup_points || []).find(
    pt => pt.region === selectedRegion.value
  ) || null;
});

const typeMap = {
  trekking:   { label: 'เดินป่า',    class: 'bg-[#2D7A4F]' },
  diving:     { label: 'ดำน้ำ',      class: 'bg-[#1A5F8A]' },
  snorkeling: { label: 'ดำน้ำตื้น', class: 'bg-[#3B9DD4]' },
  climbing:   { label: 'รถตู้',      class: 'bg-[#C8963E]' },
};
const diffMap = { easy: 'ระดับเริ่มต้น', medium: 'ระดับปานกลาง', hard: 'ระดับท้าทาย' };

const typeLabel = ref('');
const typeBadgeClass = ref('');
const diffLabel = ref('');

// ─── Hero ──────────────────────────────────────────────────
const wishlistStore = useWishlistStore();
const coverFailed = ref(false);
const shareCopied = ref(false);
let shareCopiedTimer = null;

const typeIconMap = {
  trekking: 'forest',
  diving: 'scuba_diving',
  snorkeling: 'pool',
  climbing: 'airport_shuttle',
};
const typeIcon = computed(() => typeIconMap[trip.value?.type] || 'landscape');

// A rating of 0.0 with no reviews reads as "bad", not "new" — so hide it.
const hasRating = computed(() => Number(trip.value?.review_count || 0) > 0);

// ─── Editorial section imagery (drawn from the trip gallery) ──
const galleryList = computed(() =>
  Array.isArray(trip.value?.gallery) ? trip.value.gallery.filter(Boolean) : []
);
// Pick a gallery photo for a section; clamp so small galleries still resolve.
function sectionImage(idx) {
  const g = galleryList.value;
  return g.length ? g[Math.min(idx, g.length - 1)] : null;
}
// A broken section image would leave an ugly empty band, so hide the figure.
const hlImageFailed = ref(false);
const spreadImageFailed = ref(false);
const hlImage = computed(() => (hlImageFailed.value ? null : sectionImage(3)));
const spreadImage = computed(() => (spreadImageFailed.value ? null : sectionImage(4)));

const nextSchedule = computed(() => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return schedules.value
    .filter(s => hasAvailableSeats(s) && new Date(s.departure_date) >= today)
    .sort((a, b) => new Date(a.departure_date) - new Date(b.departure_date))[0] || null;
});

async function shareTrip() {
  const url = window.location.href;
  if (navigator.share) {
    try {
      await navigator.share({ title: trip.value?.title, url });
      return;
    } catch {
      return; // user dismissed the share sheet
    }
  }
  try {
    await navigator.clipboard.writeText(url);
    shareCopied.value = true;
    clearTimeout(shareCopiedTimer);
    shareCopiedTimer = setTimeout(() => { shareCopied.value = false; }, 2000);
  } catch {}
}

// ─── In-page section nav (editorial jump bar + scroll-spy) ──
const activePageSection = ref('overview');
let pageNavObserver = null;

// Only surface links whose section actually renders.
const pageSections = computed(() => {
  // Order must follow DOM order so the scroll-spy highlight reads intuitively.
  const out = [{ id: 'overview', label: 'ภาพรวม' }];
  if (itinerarySectors.value.length > 0) out.push({ id: 'itinerary', label: 'กำหนดการ' });
  if (trip.value?.preparations?.length) out.push({ id: 'prepare', label: 'เตรียมตัว' });
  out.push({ id: 'highlights', label: 'จุดเด่น' });
  if (trip.value?.inclusions?.length || trip.value?.exclusions?.length) out.push({ id: 'included', label: 'ราคารวม' });
  out.push({ id: 'reviews', label: 'รีวิว' });
  return out;
});

function scrollToPageSection(id) {
  const el = document.getElementById(id);
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function setupPageNavObserver() {
  if (pageNavObserver) pageNavObserver.disconnect();
  // Trigger when a section crosses the band just below the sticky nav.
  pageNavObserver = new IntersectionObserver(
    (entries) => {
      for (const e of entries) {
        if (e.isIntersecting) activePageSection.value = e.target.id;
      }
    },
    { rootMargin: '-140px 0px -70% 0px', threshold: 0 }
  );
  pageSections.value.forEach(s => {
    const el = document.getElementById(s.id);
    if (el) pageNavObserver.observe(el);
  });
}

const highlights = computed(() => {
  if (trip.value?.highlights && trip.value.highlights.length > 0) {
    return trip.value.highlights;
  }
  
  // Custom fallback defaults based on trip type
  const base = [
    { icon: 'shield_person', title: 'ประกันภัยการเดินทาง', desc: 'คุ้มครองอุบัติเหตุตลอดการเดินทางด้วยวงเงินสูงสุด 1 ล้านบาท' },
    { icon: 'restaurant', title: 'บริการอาหารและเครื่องดื่ม', desc: 'คัดสรรเมนูคุณภาพ พร้อมของว่างและเครื่องดื่มตลอดทริป' },
  ];
  const t = trip.value?.type;
  if (t === 'diving' || t === 'snorkeling') {
    return [
      { icon: 'scuba_diving', title: 'อุปกรณ์ดำน้ำมาตรฐาน', desc: 'หน้ากาก ท่อหายใจ เสื้อชูชีพ คุณภาพดีและผ่านการฆ่าเชื้อ' },
      { icon: 'directions_boat', title: 'เดินทางด้วยสปีดโบ๊ท', desc: 'สะดวกรวดเร็ว ปลอดภัย พร้อมกัปตันผู้เชี่ยวชาญเส้นทาง' },
      ...base,
      { icon: 'photo_camera', title: 'บริการถ่ายภาพใต้น้ำ', desc: 'ฟรี! รูปถ่ายใต้น้ำสวยๆ จากช่างภาพมืออาชีพ' },
    ];
  } else if (t === 'trekking') {
    return [
      { icon: 'hiking', title: 'ไกด์ท้องถิ่นผู้เชี่ยวชาญ', desc: 'มัคคุเทศก์ที่รู้ลึกเรื่องเส้นทางและพรรณไม้ ดูแลอย่างใกล้ชิด' },
      { icon: 'camping', title: 'อุปกรณ์แคมป์ปิ้งครบชุด', desc: 'เต็นท์กันฝน ถุงนอน แผ่นรองนอน สะอาดและได้มาตรฐาน' },
      ...base,
    ];
  }
  return [
    { icon: 'airport_shuttle', title: 'รถตู้ VIP ระดับพรีเมียม', desc: 'เบาะกว้าง นั่งสบาย แอร์เย็นฉ่ำ พร้อมสิ่งอำนวยความสะดวก' },
    { icon: 'badge', title: 'พนักงานขับรถมืออาชีพ', desc: 'ชำนาญเส้นทาง สุภาพ และผ่านการฝึกอบรมการขับขี่ปลอดภัย' },
    ...base,
  ];
});

/* Inclusions/Exclusions are now directly from trip data */

const urgentSchedule = computed(() => {
  if (!schedules.value.length) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return schedules.value
    .filter(s =>
      !s.join_trip_enabled &&
      hasAvailableSeats(s) &&
      s.available_seats <= 5 &&
      new Date(s.departure_date) >= today
    )
    .sort((a, b) => new Date(a.departure_date) - new Date(b.departure_date))[0] || null;
});

async function openSeatMapPreview(schedule) {
  seatMapPreviewSchedule.value = schedule;
  seatMapPreviewData.value = null;
  showSeatMapModal.value = true;
  seatMapPreviewLoading.value = true;
  try {
    const res = await api.get(`/schedules/${schedule.id}/seats`);
    seatMapPreviewData.value = res.data.data;
  } catch {
    // silent fail
  } finally {
    seatMapPreviewLoading.value = false;
  }
}

function canBookSelectedSchedule() {
  if (!selectedSchedule.value) return false;
  if (selectedSchedule.value.is_charter) return false;
  return hasAvailableSeats(selectedSchedule.value)
    || (isJoinTrip.value && Boolean(selectedSchedule.value.join_trip_enabled));
}

function getPickupForRegion(schedule, region) {
  return (schedule?.pickup_points || []).find(pt => pt.region === region) || null;
}

// Whether the current selection is ready to book — mirrors the desktop
// book-now button condition, reused by the sticky mobile bar.
const canBookNow = computed(() => {
  if (!selectedSchedule.value) return false;
  const stepDone = !isTrekking.value
    || (isTrekking.value && selectedPickup.value)
    || (isJoinTrip.value && selectedSchedule.value?.join_trip_enabled);
  return Boolean(stepDone) && canBookSelectedSchedule();
});

const bookingQuery = computed(() => ({
  ...(selectedRegion.value && !isJoinTrip.value ? { region: selectedRegion.value } : {}),
  ...(isJoinTrip.value ? { join_trip: 1 } : {}),
}));

const scrollToBooking = () => {
  const el = document.getElementById('booking-section');
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const displayPrice = computed(() => {
  if (isJoinTrip.value && selectedSchedule.value?.join_trip_enabled) {
    return Number(selectedSchedule.value.join_trip_price || selectedSchedule.value.price || trip.value?.price_per_person || 0);
  }
  if (selectedPickup.value) return Number(selectedPickup.value.price);
  
  // หากมีการเลือกภูมิภาคแต่ยังไม่เลือกวันเดินทาง ให้แสดงราคาของภูมิภาคนั้น (สำหรับทริปเดินป่า)
  if (selectedRegion.value && regionOptions.value.length) {
    const region = regionOptions.value.find(r => r.region === selectedRegion.value);
    if (region) return region.min_price;
  }
  
  if (selectedSchedule.value?.price) return Number(selectedSchedule.value.price);
  return Number(trip.value?.price_per_person || 0);
});

// ─── ผ่อนชำระ ──────────────────────────────────────────────
// เงื่อนไขต้องตรงกับหน้าชำระเงิน (PaymentPage): เพดาน 6 งวด, ขั้นต่ำ 2 งวด และ
// งวดสุดท้ายต้องครบก่อนวันเดินทาง → (n-1) * interval <= วันที่เหลือ
const INSTALLMENT_CAP = 6;

function feasibleInstallmentCount(s) {
  if (!s?.installment_enabled || !s.departure_date) return 0;
  const interval = s.installment_interval_days || 30;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const departure = new Date(s.departure_date);
  departure.setHours(0, 0, 0, 0);
  const days = Math.floor((departure - today) / 86400000);
  if (days <= 0) return 0;
  const count = Math.min(
    s.installment_count || 3,
    INSTALLMENT_CAP,
    Math.floor(days / interval) + 1
  );
  return count >= 2 ? count : 0;
}

// จำนวนงวดสูงสุดที่รอบนี้ (หรือรอบที่ยังเลือกได้) ผ่อนได้จริง — งวดยิ่งมาก ยอดต่องวดยิ่งต่ำ
const installmentPlan = computed(() => {
  if (isJoinTrip.value) return null; // จอยทริปผ่อนไม่ได้
  const price = displayPrice.value;
  if (!price) return null;

  const pool = selectedSchedule.value
    ? [selectedSchedule.value]
    : (isTrekking.value && selectedRegion.value ? schedulesForRegion.value : schedules.value);

  const count = pool.reduce((best, s) => Math.max(best, feasibleInstallmentCount(s)), 0);
  if (!count) return null;

  return { count, perInstallment: Math.ceil(price / count) };
});

// ─── Flash Sale ────────────────────────────────────────────
// Ticks every second so countdowns stay live without per-component timers.
const nowTick = ref(Date.now());
let flashTimer = null;

// The active flash sale to headline: the selected round if it's on sale, else
// the soonest-ending active flash sale across this trip's rounds.
const flashSchedule = computed(() => {
  if (selectedSchedule.value?.flash_sale?.active) return selectedSchedule.value;
  return schedules.value
    .filter(s => s.flash_sale?.active)
    .sort((a, b) => new Date(a.flash_sale.ends_at || '9999') - new Date(b.flash_sale.ends_at || '9999'))[0] || null;
});

const flashCountdown = (endsAt) => {
  if (!endsAt) return '';
  const diff = new Date(endsAt).getTime() - nowTick.value;
  if (diff <= 0) return '00:00:00';
  const pad = (n) => String(n).padStart(2, '0');
  const totalHours = Math.floor(diff / 3600000);
  return `${pad(totalHours)}:${pad(Math.floor((diff % 3600000) / 60000))}:${pad(Math.floor((diff % 60000) / 1000))}`;
};

function selectRegion(region) {
  selectedRegion.value = region;
  selectedSchedule.value = null;
  selectedPickup.value = null;
}

function selectSchedule(s) {
  if (!s) {
    selectedSchedule.value = null;
    selectedPickup.value = null;
    isJoinTrip.value = false;
    return;
  }
  selectedSchedule.value = s;
  if (!s.join_trip_enabled) {
    isJoinTrip.value = false;
  } else if (!hasAvailableSeats(s)) {
    isJoinTrip.value = true;
  }
  if (selectedRegion.value) {
    selectedPickup.value = (s.pickup_points || []).find(
      pt => pt.region === selectedRegion.value
    ) || null;
  } else {
    selectedPickup.value = null;
  }
}

function selectPickup(pt) {
  selectedPickup.value = selectedPickup.value?.id === pt.id ? null : pt;
}

function openGallery(index) {
  activeGalleryIndex.value = index;
  showGalleryModal.value = true;
  document.body.style.overflow = 'hidden';
}

function closeGallery() {
  showGalleryModal.value = false;
  document.body.style.overflow = '';
}

function nextGalleryImage() {
  if (!trip.value?.gallery?.length) return;
  activeGalleryIndex.value = (activeGalleryIndex.value + 1) % trip.value.gallery.length;
}

function prevGalleryImage() {
  if (!trip.value?.gallery?.length) return;
  activeGalleryIndex.value = (activeGalleryIndex.value - 1 + trip.value.gallery.length) % trip.value.gallery.length;
}

const handleKeyDown = (e) => {
  if (!showGalleryModal.value) return;
  if (e.key === 'Escape') closeGallery();
  if (e.key === 'ArrowRight') nextGalleryImage();
  if (e.key === 'ArrowLeft') prevGalleryImage();
};

// Lightweight polling so "ที่นั่งสุดท้าย" reflects bookings made by others while
// the page is open. Pauses when the tab is hidden; keeps the selected round fresh.
let schedulePoll = null;
const SCHEDULE_POLL_MS = 25000;

async function refreshSchedules() {
  if (typeof document !== 'undefined' && document.hidden) return;
  try {
    const sRes = await api.get(`/trips/${route.params.slug}/schedules`);
    schedules.value = sRes.data.data;
    if (selectedSchedule.value) {
      const match = schedules.value.find(s => s.id === selectedSchedule.value.id);
      if (match) selectedSchedule.value = match;
    }
  } catch (e) {
    // transient — keep current data
  }
}

onMounted(async () => {
  try {
    const res = await api.get(`/trips/${route.params.slug}`);
    trip.value = res.data.data;
    typeLabel.value = typeMap[trip.value.type]?.label || trip.value.type;

    // Related trips — non-blocking, never breaks the page if it fails
    api.get(`/trips/${route.params.slug}/related`)
      .then((r) => { relatedTrips.value = r.data.data || []; })
      .catch(() => {});

    typeBadgeClass.value = typeMap[trip.value.type]?.class || 'bg-[#6B8F7A] text-white';
    diffLabel.value = diffMap[trip.value.difficulty] || trip.value.difficulty;

    schedulesLoading.value = true;
    const sRes = await api.get(`/trips/${route.params.slug}/schedules`);
    schedules.value = sRes.data.data;
    schedulePoll = setInterval(refreshSchedules, SCHEDULE_POLL_MS);
    flashTimer = setInterval(() => { nowTick.value = Date.now(); }, 1000);

    // Auto-select schedule and region from query params
    if (route.query.schedule) {
      const scheduleId = Number(route.query.schedule);
      const found = schedules.value.find(s => s.id === scheduleId);
      if (found) {
        selectSchedule(found);
        
        // If region is also provided, select it
        if (route.query.region) {
          selectedRegion.value = route.query.region;
        }

        // Scroll to schedule selection section
        setTimeout(() => {
          const el = document.getElementById('booking-section');
          if (el) el.scrollIntoView({ behavior: 'smooth' });
        }, 800);
      }
    } else if (route.query.region) {
      // If only region is provided
      selectedRegion.value = route.query.region;
    }

    // Show must know modal if exists
    if (hasMustKnowContent.value) {
      setTimeout(() => {
        showMustKnowModal.value = true;
      }, 500);
    }

    await Promise.all([fetchReviews(), fetchAlbumPhotos()]);
    window.addEventListener('keydown', handleKeyDown);
    
    // Setup observers for itinerary sectors + the in-page nav scroll-spy
    setTimeout(() => {
      setupSectorObserver();
      setupPageNavObserver();
    }, 1000);
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
    schedulesLoading.value = false;
  }
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown);
  if (sectorObserver) sectorObserver.disconnect();
  if (pageNavObserver) pageNavObserver.disconnect();
  if (schedulePoll) clearInterval(schedulePoll);
  if (flashTimer) clearInterval(flashTimer);
  clearTimeout(shareCopiedTimer);
  document.body.style.overflow = '';
});

function getDistanceForPickup(pickupId) {
  return distanceData.value.find(d => d.pickup_point_id === pickupId) || null;
}

async function calculateDistances() {
  if (!selectedSchedule.value && !schedules.value.length) return;
  const scheduleId = selectedSchedule.value?.id || schedules.value[0]?.id;
  if (!scheduleId) return;

  distanceLoading.value = true;
  try {
    const pos = await new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('เบราว์เซอร์ไม่รองรับ Geolocation'));
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
        (err) => reject(new Error('ไม่สามารถเข้าถึงตำแหน่งของคุณได้ กรุณาอนุญาตการเข้าถึงตำแหน่ง')),
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });

    const res = await api.get(`/schedules/${scheduleId}/pickup-distances`, {
      params: { lat: pos.lat, lng: pos.lng },
    });
    distanceData.value = res.data.data || [];
  } catch (e) {
    alert(e.message || 'ไม่สามารถคำนวณระยะทางได้');
  } finally {
    distanceLoading.value = false;
  }
}

const displayedReviews = computed(() => reviews.value.slice(0, visibleReviewsCount.value));

const displayedAlbumPhotos = computed(() => albumPhotos.value.slice(0, visibleAlbumCount.value));
const albumCanShowMore = computed(
  () => displayedAlbumPhotos.value.length < albumPhotos.value.length || albumHasMore.value,
);

function openAlbumImage(index) {
  openReviewImage(albumPhotos.value.map((p) => p.url), index);
}

function openReviewImage(images, index) {
  reviewImageModalImages.value = images;
  reviewImageModalIndex.value = index;
  showReviewImageModal.value = true;
  document.body.style.overflow = 'hidden';
}

function closeReviewImage() {
  showReviewImageModal.value = false;
  document.body.style.overflow = '';
}

function prevReviewImage() {
  const len = reviewImageModalImages.value.length;
  reviewImageModalIndex.value = (reviewImageModalIndex.value - 1 + len) % len;
}

function nextReviewImage() {
  const len = reviewImageModalImages.value.length;
  reviewImageModalIndex.value = (reviewImageModalIndex.value + 1) % len;
}

async function loadMoreReviews() {
  if (reviewsLoadingMore.value) return;
  if (visibleReviewsCount.value < reviews.value.length) {
    visibleReviewsCount.value = Math.min(visibleReviewsCount.value + REVIEWS_PER_PAGE, reviews.value.length);
    return;
  }
  if (!reviewsHasMore.value) return;
  reviewsLoadingMore.value = true;
  try {
    const nextPage = reviewsPage.value + 1;
    const res = await api.get('/reviews', { params: { trip_id: trip.value.id, per_page: REVIEWS_PER_PAGE, page: nextPage } });
    const newReviews = res.data.data || [];
    reviews.value = [...reviews.value, ...newReviews];
    reviewsPage.value = nextPage;
    reviewsHasMore.value = newReviews.length === REVIEWS_PER_PAGE;
    visibleReviewsCount.value += newReviews.length;
  } catch (error) {
    console.error('Failed to load more reviews:', error);
  } finally {
    reviewsLoadingMore.value = false;
  }
}

async function fetchReviews() {
  if (!trip.value?.id) return;
  reviewsLoading.value = true;
  reviewsPage.value = 1;
  visibleReviewsCount.value = REVIEWS_PER_PAGE;
  try {
    const res = await api.get('/reviews', { params: { trip_id: trip.value.id, per_page: REVIEWS_PER_PAGE, page: 1 } });
    reviews.value = res.data.data || [];
    reviewsHasMore.value = reviews.value.length === REVIEWS_PER_PAGE;
  } catch (error) {
    console.error('Failed to fetch reviews:', error);
  } finally {
    reviewsLoading.value = false;
  }
}

async function loadMoreAlbumPhotos() {
  if (albumLoadingMore.value) return;
  if (visibleAlbumCount.value < albumPhotos.value.length) {
    visibleAlbumCount.value = Math.min(visibleAlbumCount.value + ALBUM_INITIAL_VISIBLE, albumPhotos.value.length);
    return;
  }
  if (!albumHasMore.value) return;
  albumLoadingMore.value = true;
  try {
    const nextPage = albumPage.value + 1;
    const res = await api.get('/reviews/photos', {
      params: { trip_id: trip.value.id, per_page: ALBUM_PER_PAGE, page: nextPage },
    });
    const payload = res.data.data || {};
    albumPhotos.value = [...albumPhotos.value, ...(payload.photos || [])];
    albumPage.value = nextPage;
    albumHasMore.value = Boolean(payload.has_more);
    visibleAlbumCount.value = albumPhotos.value.length;
  } catch (error) {
    console.error('Failed to load more review photos:', error);
  } finally {
    albumLoadingMore.value = false;
  }
}

async function fetchAlbumPhotos() {
  if (!trip.value?.id) return;
  albumPage.value = 1;
  visibleAlbumCount.value = ALBUM_INITIAL_VISIBLE;
  try {
    const res = await api.get('/reviews/photos', {
      params: { trip_id: trip.value.id, per_page: ALBUM_PER_PAGE, page: 1 },
    });
    const payload = res.data.data || {};
    albumPhotos.value = payload.photos || [];
    albumTotal.value = payload.total || 0;
    albumHasMore.value = Boolean(payload.has_more);
  } catch (error) {
    console.error('Failed to fetch review photos:', error);
    albumPhotos.value = [];
    albumTotal.value = 0;
    albumHasMore.value = false;
  }
}
</script>

<style scoped>
/* ── Editorial section headers ───────────────────────────── */
.ed-head {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.ed-kicker {
  display: inline-flex;
  align-items: center;
  align-self: flex-start;
  gap: 0.5rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--color-accent);
}
.ed-kicker::before {
  content: '';
  width: 1.6rem;
  height: 2px;
  border-radius: 2px;
  background-color: var(--color-accent);
}
.ed-title {
  font-size: 1.75rem;
  line-height: 1.1;
  font-weight: 900;
  letter-spacing: -0.02em;
  color: var(--color-text-dark);
}
@media (min-width: 768px) {
  .ed-title { font-size: 2.5rem; }
}

/* Album sub-card header chip (kept from prior pass) */
.sec-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 1rem;
  background-color: color-mix(in srgb, var(--color-accent) 12%, transparent);
  color: var(--color-accent);
  flex-shrink: 0;
}
.sec-icon .material-symbols-rounded { font-size: 26px; }

/* ── In-page section nav ─────────────────────────────────── */
.page-nav__link {
  position: relative;
  white-space: nowrap;
  padding: 1rem 1.1rem;
  font-size: 0.9rem;
  font-weight: 800;
  color: var(--color-text-muted);
  transition: color 0.2s ease;
}
.page-nav__link:hover { color: var(--color-text-dark); }
.page-nav__link.is-active { color: var(--color-primary); }
.page-nav__link.is-active::after {
  content: '';
  position: absolute;
  left: 1.1rem;
  right: 1.1rem;
  bottom: -1px;
  height: 2.5px;
  border-radius: 3px;
  background-color: var(--color-accent);
}

/* Highlight card icon must beat the unlayered 24px icon rule */
.hl-card .hl-icon { font-size: 28px; }

/* Book-now: solid single-colour fill for a cleaner, more professional look */
.book-cta {
  display: block;
  text-align: center;
  padding: 1rem;
  border-radius: 9999px;
  font-size: 1.125rem;
  font-weight: 800;
  color: #fff;
  background-color: var(--color-primary);
  box-shadow: 0 12px 24px -10px color-mix(in srgb, var(--color-primary) 60%, transparent);
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.book-cta:hover {
  transform: translateY(-2px);
  box-shadow: 0 18px 34px -12px color-mix(in srgb, var(--color-primary) 75%, transparent);
}
.book-cta:active {
  transform: translateY(0) scale(0.99);
}

/* Book-now: solid single-colour fill for a cleaner, more professional look */
.book-cta {
  display: block;
  text-align: center;
  padding: 1rem;
  border-radius: 9999px;
  font-size: 1.125rem;
  font-weight: 800;
  color: #fff;
  background-color: var(--color-primary);
  box-shadow: 0 12px 24px -10px color-mix(in srgb, var(--color-primary) 60%, transparent);
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.book-cta:hover {
  transform: translateY(-2px);
  box-shadow: 0 18px 34px -12px color-mix(in srgb, var(--color-primary) 75%, transparent);
}
.book-cta:active {
  transform: translateY(0) scale(0.99);
}

/* ── Hero ───────────────────────────────────────────────── */
/* One-shot cinematic settle, not a hover-triggered zoom. */
.hero-img {
  animation: heroSettle 14s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes heroSettle {
  from { transform: scale(1.12); }
  to   { transform: scale(1); }
}

/* Hand-tuned stops: hard enough under the title to carry white text over a
   bright photo, gone by 70% so the image itself stays saturated. */
.hero-scrim-bottom {
  background: linear-gradient(
    to top,
    rgba(0, 0, 0, 0.92) 0%,
    rgba(0, 0, 0, 0.74) 16%,
    rgba(0, 0, 0, 0.42) 34%,
    rgba(0, 0, 0, 0.12) 54%,
    transparent 72%
  );
}

.hero-scrim-top {
  background: linear-gradient(to bottom, rgba(0, 0, 0, 0.55), transparent);
}

/* Shown when there is no cover image *or* the cover fails to load. */
.hero-fallback {
  background:
    radial-gradient(110% 70% at 50% 8%, rgba(76, 175, 125, 0.42), transparent 62%),
    linear-gradient(160deg, var(--color-primary-light) 0%, var(--color-primary-mid) 55%, var(--color-primary) 100%);
}

/* app.css declares `.material-symbols-rounded { font-size: 24px }` outside any
   cascade layer, so it beats every Tailwind `text-[..]` utility. Two classes
   here out-specify it. */
.hero-fallback .hero-fallback-icon {
  font-size: 220px;
  line-height: 1;
}

.hero-title {
  text-shadow: 0 2px 28px rgba(0, 0, 0, 0.5);
  text-wrap: balance;
}

.hero-content {
  animation: heroContentIn 0.7s cubic-bezier(0.16, 1, 0.3, 1) backwards;
  animation-delay: 0.1s;
}

@keyframes heroContentIn {
  from { opacity: 0; transform: translateY(18px); }
  to   { opacity: 1; transform: translateY(0); }
}

.hero-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem 0.875rem;
  border-radius: 9999px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  background-color: rgba(255, 255, 255, 0.14);
  backdrop-filter: blur(12px);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
}
.hero-chip--pink {
  border-color: rgba(244, 114, 182, 0.45);
  background-color: rgba(236, 72, 153, 0.55);
}

.hero-action {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 9999px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  background-color: rgba(0, 0, 0, 0.3);
  backdrop-filter: blur(12px);
  color: #fff;
  transition: background-color 0.2s ease, transform 0.15s ease;
}
.hero-action:hover {
  background-color: rgba(0, 0, 0, 0.5);
}
.hero-action:active {
  transform: scale(0.94);
}
.hero-action:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.55);
}
.hero-action.is-favorite {
  color: #f43f5e;
}
.hero-action.is-favorite .material-symbols-rounded {
  font-variation-settings: 'FILL' 1;
}

/* White pill reads as the primary action against a photo far better than
   the brand green does. */
.hero-cta {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1.5rem;
  border-radius: 9999px;
  background-color: #fff;
  color: var(--color-primary);
  font-size: 15px;
  font-weight: 800;
  box-shadow: 0 12px 28px -10px rgba(0, 0, 0, 0.6);
  transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}
.hero-cta:hover {
  transform: translateY(-1px);
  background-color: var(--color-accent);
  color: #fff;
  box-shadow: 0 18px 34px -12px rgba(0, 0, 0, 0.7);
}
.hero-cta:active {
  transform: translateY(0) scale(0.98);
}

/* Custom scrollbar for schedule list */
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: var(--color-sand);
  border-radius: 8px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 8px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.sector-tabs-container {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Animations */
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
.animate-fade-in {
  animation: fadeIn 0.8s ease-out forwards;
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(30px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in-up {
  animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.stagger-in {
  animation: fadeUp 0.5s ease-out forwards;
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  line-clamp: 2;
  overflow: hidden;
}

@media (prefers-reduced-motion: reduce) {
  .animate-fade-in,
  .animate-fade-in-up,
  .stagger-in,
  .hero-content,
  img {
    animation: none !important;
    transition: none !important;
    opacity: 1 !important;
    transform: none !important;
  }
}
</style>
